<?php
/**
 * Message Attachment Service
 * Handles file uploads, image processing, and attachment management for messages
 */

class MessageAttachmentService {
    private $messageModel;
    private $uploadPath;
    private $allowedImageTypes;
    private $allowedFileTypes;
    private $maxFileSize;
    private $maxImageSize;
    
    public function __construct() {
        $this->messageModel = new Message();
        $this->uploadPath = __DIR__ . '/../../uploads/messages';
        $this->allowedImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $this->allowedFileTypes = ['pdf', 'doc', 'docx', 'txt', 'zip', 'rar'];
        $this->maxFileSize = 10 * 1024 * 1024; // 10MB
        $this->maxImageSize = 5 * 1024 * 1024; // 5MB
        
        // Create upload directory if it doesn't exist
        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }
        
        // Create subdirectories
        $subdirs = ['images', 'files', 'thumbnails'];
        foreach ($subdirs as $dir) {
            $fullPath = $this->uploadPath . '/' . $dir;
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
        }
    }
    
    /**
     * Upload attachment for message
     */
    public function uploadAttachment($conversationId, $senderId, $file, $messageContent = null) {
        try {
            // Validate file
            $validation = $this->validateFile($file);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }
            
            // Process the file
            $fileData = $this->processFile($file);
            if (!$fileData) {
                return ['success' => false, 'error' => 'Failed to process file'];
            }
            
            // Create message with attachment
            $messageData = [
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'content' => $messageContent ?: $this->generateAttachmentMessage($fileData),
                'message_type' => $fileData['is_image'] ? 'image' : 'file'
            ];
            
            $message = $this->messageModel->createMessage($messageData);
            if (!$message) {
                // Clean up uploaded file
                $this->cleanupFile($fileData['file_path']);
                return ['success' => false, 'error' => 'Failed to create message'];
            }
            
            // Add attachment record
            $attachmentData = [
                'message_id' => $message['id'],
                'filename' => $fileData['filename'],
                'original_filename' => $fileData['original_filename'],
                'file_path' => $fileData['file_path'],
                'file_size' => $fileData['file_size'],
                'mime_type' => $fileData['mime_type'],
                'width' => $fileData['width'] ?? null,
                'height' => $fileData['height'] ?? null,
                'thumbnail_path' => $fileData['thumbnail_path'] ?? null,
                'is_image' => $fileData['is_image']
            ];
            
            $attachmentResult = $this->messageModel->addAttachment($message['id'], $attachmentData);
            if (!$attachmentResult) {
                // Clean up uploaded file
                $this->cleanupFile($fileData['file_path']);
                return ['success' => false, 'error' => 'Failed to save attachment record'];
            }
            
            // Get complete message with attachment data
            $completeMessage = $this->messageModel->getMessageWithSender($message['id']);
            
            return [
                'success' => true,
                'message' => $completeMessage,
                'attachment' => $attachmentData
            ];
            
        } catch (Exception $e) {
            Logger::error("Failed to upload attachment", [
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => 'Upload failed'];
        }
    }
    
    /**
     * Upload multiple attachments at once
     */
    public function uploadMultipleAttachments($conversationId, $senderId, $files, $messageContent = null) {
        $results = [];
        $successCount = 0;
        
        foreach ($files as $index => $file) {
            if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
                $results[$index] = ['success' => false, 'error' => 'Invalid file'];
                continue;
            }
            
            $result = $this->uploadAttachment($conversationId, $senderId, $file, $messageContent);
            $results[$index] = $result;
            
            if ($result['success']) {
                $successCount++;
            }
        }
        
        return [
            'success' => $successCount > 0,
            'total' => count($files),
            'successful' => $successCount,
            'results' => $results
        ];
    }
    
    /**
     * Validate uploaded file
     */
    private function validateFile($file) {
        if (!is_array($file) || !isset($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Invalid file data'];
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => $this->getUploadErrorMessage($file['error'])];
        }
        
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'File upload security check failed'];
        }
        
        $fileSize = $file['size'];
        $fileName = $file['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Check file extension
        $isImage = in_array($fileExtension, $this->allowedImageTypes);
        $isFile = in_array($fileExtension, $this->allowedFileTypes);
        
        if (!$isImage && !$isFile) {
            $allowed = array_merge($this->allowedImageTypes, $this->allowedFileTypes);
            return ['valid' => false, 'error' => 'File type not allowed. Allowed: ' . implode(', ', $allowed)];
        }
        
        // Check file size
        $maxSize = $isImage ? $this->maxImageSize : $this->maxFileSize;
        if ($fileSize > $maxSize) {
            $maxSizeMB = round($maxSize / 1024 / 1024, 1);
            return ['valid' => false, 'error' => "File too large. Maximum size: {$maxSizeMB}MB"];
        }
        
        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $validMimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed'
        ];
        
        if (!isset($validMimeTypes[$fileExtension]) || 
            !in_array($mimeType, [$validMimeTypes[$fileExtension], 'text/plain'])) {
            return ['valid' => false, 'error' => 'Invalid file type'];
        }
        
        // Additional security checks for images
        if ($isImage) {
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                return ['valid' => false, 'error' => 'Invalid image file'];
            }
        }
        
        return ['valid' => true];
    }
    
    /**
     * Process uploaded file
     */
    private function processFile($file) {
        try {
            $originalName = $file['name'];
            $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $isImage = in_array($fileExtension, $this->allowedImageTypes);
            
            // Generate unique filename
            $filename = $this->generateUniqueFilename($fileExtension);
            $subdirectory = $isImage ? 'images' : 'files';
            $filePath = $this->uploadPath . '/' . $subdirectory . '/' . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                return false;
            }
            
            // Get file info
            $fileData = [
                'filename' => $filename,
                'original_filename' => $originalName,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
                'mime_type' => $file['type'],
                'is_image' => $isImage
            ];
            
            // Process images
            if ($isImage) {
                $imageInfo = $this->processImage($filePath);
                $fileData = array_merge($fileData, $imageInfo);
            }
            
            return $fileData;
            
        } catch (Exception $e) {
            Logger::error("Failed to process file", [
                'original_name' => $file['name'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Process image file (resize, create thumbnail)
     */
    private function processImage($imagePath) {
        try {
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                return ['width' => null, 'height' => null];
            }
            
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $mimeType = $imageInfo['mime'];
            
            $result = [
                'width' => $width,
                'height' => $height
            ];
            
            // Create thumbnail
            $thumbnailPath = $this->createThumbnail($imagePath, $mimeType);
            if ($thumbnailPath) {
                $result['thumbnail_path'] = $thumbnailPath;
            }
            
            // Resize if image is too large
            if ($width > 1920 || $height > 1920) {
                $this->resizeImage($imagePath, $mimeType, 1920, 1920);
                
                // Update dimensions after resize
                $newImageInfo = getimagesize($imagePath);
                if ($newImageInfo) {
                    $result['width'] = $newImageInfo[0];
                    $result['height'] = $newImageInfo[1];
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            Logger::error("Failed to process image", [
                'image_path' => $imagePath,
                'error' => $e->getMessage()
            ]);
            return ['width' => null, 'height' => null];
        }
    }
    
    /**
     * Create thumbnail for image
     */
    private function createThumbnail($imagePath, $mimeType, $maxWidth = 200, $maxHeight = 200) {
        try {
            // Create image resource
            switch ($mimeType) {
                case 'image/jpeg':
                    $source = imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $source = imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $source = imagecreatefromgif($imagePath);
                    break;
                case 'image/webp':
                    $source = imagecreatefromwebp($imagePath);
                    break;
                default:
                    return null;
            }
            
            if (!$source) {
                return null;
            }
            
            // Get original dimensions
            $originalWidth = imagesx($source);
            $originalHeight = imagesy($source);
            
            // Calculate new dimensions
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            $newWidth = intval($originalWidth * $ratio);
            $newHeight = intval($originalHeight * $ratio);
            
            // Create thumbnail
            $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG and GIF
            if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
                $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
                imagefilledrectangle($thumbnail, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            // Resize
            imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, 
                             $newWidth, $newHeight, $originalWidth, $originalHeight);
            
            // Save thumbnail
            $pathInfo = pathinfo($imagePath);
            $thumbnailFilename = $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];
            $thumbnailPath = $this->uploadPath . '/thumbnails/' . $thumbnailFilename;
            
            $saved = false;
            switch ($mimeType) {
                case 'image/jpeg':
                    $saved = imagejpeg($thumbnail, $thumbnailPath, 85);
                    break;
                case 'image/png':
                    $saved = imagepng($thumbnail, $thumbnailPath, 8);
                    break;
                case 'image/gif':
                    $saved = imagegif($thumbnail, $thumbnailPath);
                    break;
                case 'image/webp':
                    $saved = imagewebp($thumbnail, $thumbnailPath, 85);
                    break;
            }
            
            // Clean up
            imagedestroy($source);
            imagedestroy($thumbnail);
            
            return $saved ? $thumbnailPath : null;
            
        } catch (Exception $e) {
            Logger::error("Failed to create thumbnail", [
                'image_path' => $imagePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Resize image if it's too large
     */
    private function resizeImage($imagePath, $mimeType, $maxWidth, $maxHeight) {
        try {
            // Create image resource
            switch ($mimeType) {
                case 'image/jpeg':
                    $source = imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $source = imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $source = imagecreatefromgif($imagePath);
                    break;
                case 'image/webp':
                    $source = imagecreatefromwebp($imagePath);
                    break;
                default:
                    return false;
            }
            
            if (!$source) {
                return false;
            }
            
            // Get original dimensions
            $originalWidth = imagesx($source);
            $originalHeight = imagesy($source);
            
            // Check if resize is needed
            if ($originalWidth <= $maxWidth && $originalHeight <= $maxHeight) {
                imagedestroy($source);
                return true;
            }
            
            // Calculate new dimensions
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            $newWidth = intval($originalWidth * $ratio);
            $newHeight = intval($originalHeight * $ratio);
            
            // Create resized image
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency
            if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            // Resize
            imagecopyresampled($resized, $source, 0, 0, 0, 0,
                             $newWidth, $newHeight, $originalWidth, $originalHeight);
            
            // Save resized image
            $saved = false;
            switch ($mimeType) {
                case 'image/jpeg':
                    $saved = imagejpeg($resized, $imagePath, 90);
                    break;
                case 'image/png':
                    $saved = imagepng($resized, $imagePath, 8);
                    break;
                case 'image/gif':
                    $saved = imagegif($resized, $imagePath);
                    break;
                case 'image/webp':
                    $saved = imagewebp($resized, $imagePath, 90);
                    break;
            }
            
            // Clean up
            imagedestroy($source);
            imagedestroy($resized);
            
            return $saved;
            
        } catch (Exception $e) {
            Logger::error("Failed to resize image", [
                'image_path' => $imagePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Generate unique filename
     */
    private function generateUniqueFilename($extension) {
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        return "{$timestamp}_{$random}.{$extension}";
    }
    
    /**
     * Generate message content for attachment
     */
    private function generateAttachmentMessage($fileData) {
        if ($fileData['is_image']) {
            return "📷 Sent a photo: " . $fileData['original_filename'];
        } else {
            $sizeKB = round($fileData['file_size'] / 1024, 1);
            return "📄 Sent a file: " . $fileData['original_filename'] . " ({$sizeKB} KB)";
        }
    }
    
    /**
     * Get upload error message
     */
    private function getUploadErrorMessage($errorCode) {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'File too large (server limit)';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File too large (form limit)';
            case UPLOAD_ERR_PARTIAL:
                return 'File upload was interrupted';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server error: no temporary directory';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server error: failed to write file';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload blocked by server extension';
            default:
                return 'Unknown upload error';
        }
    }
    
    /**
     * Clean up uploaded file
     */
    private function cleanupFile($filePath) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Also clean up thumbnail if it exists
        $pathInfo = pathinfo($filePath);
        $thumbnailPath = $this->uploadPath . '/thumbnails/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];
        if (file_exists($thumbnailPath)) {
            unlink($thumbnailPath);
        }
    }
    
    /**
     * Delete attachment
     */
    public function deleteAttachment($attachmentId, $userId) {
        try {
            // Get attachment info
            $sql = "SELECT ma.*, m.sender_id, m.conversation_id
                    FROM message_attachments ma
                    JOIN messages m ON ma.message_id = m.id
                    WHERE ma.id = ?";
            
            $stmt = $this->messageModel->db->prepare($sql);
            $stmt->execute([$attachmentId]);
            $attachment = $stmt->fetch();
            
            if (!$attachment) {
                return ['success' => false, 'error' => 'Attachment not found'];
            }
            
            // Check if user can delete (must be sender or have access to conversation)
            $conversationModel = new Conversation();
            if ($attachment['sender_id'] != $userId && 
                !$conversationModel->hasAccess($attachment['conversation_id'], $userId)) {
                return ['success' => false, 'error' => 'Access denied'];
            }
            
            // Delete files
            $this->cleanupFile($attachment['file_path']);
            
            // Delete database record
            $sql = "DELETE FROM message_attachments WHERE id = ?";
            $stmt = $this->messageModel->db->prepare($sql);
            $result = $stmt->execute([$attachmentId]);
            
            return ['success' => $result];
            
        } catch (Exception $e) {
            Logger::error("Failed to delete attachment", [
                'attachment_id' => $attachmentId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => 'Failed to delete attachment'];
        }
    }
    
    /**
     * Get attachment URL for frontend
     */
    public function getAttachmentUrl($attachment, $type = 'full') {
        $baseUrl = $_ENV['BACKEND_URL'] ?? 'http://localhost/bazar/backend';
        
        if ($type === 'thumbnail' && !empty($attachment['thumbnail_path'])) {
            $relativePath = str_replace($this->uploadPath, '', $attachment['thumbnail_path']);
            return "{$baseUrl}/uploads/messages{$relativePath}";
        }
        
        $relativePath = str_replace($this->uploadPath, '', $attachment['file_path']);
        return "{$baseUrl}/uploads/messages{$relativePath}";
    }
    
    /**
     * Get storage statistics
     */
    public function getStorageStats() {
        try {
            $stats = [
                'total_attachments' => 0,
                'total_size' => 0,
                'image_count' => 0,
                'file_count' => 0,
                'storage_used_mb' => 0
            ];
            
            $sql = "SELECT 
                        COUNT(*) as total_attachments,
                        SUM(file_size) as total_size,
                        COUNT(CASE WHEN is_image = 1 THEN 1 END) as image_count,
                        COUNT(CASE WHEN is_image = 0 THEN 1 END) as file_count
                    FROM message_attachments";
            
            $stmt = $this->messageModel->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            
            if ($result) {
                $stats = array_merge($stats, $result);
                $stats['storage_used_mb'] = round($stats['total_size'] / 1024 / 1024, 2);
            }
            
            return $stats;
            
        } catch (Exception $e) {
            Logger::error("Failed to get storage stats", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}