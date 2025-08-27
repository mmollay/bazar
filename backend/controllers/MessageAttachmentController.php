<?php
/**
 * Message Attachment Controller
 * Handles file uploads and downloads for messages
 */

class MessageAttachmentController {
    private $attachmentService;
    private $conversationModel;
    private $webSocketService;
    private $notificationService;
    
    public function __construct() {
        $this->attachmentService = new MessageAttachmentService();
        $this->conversationModel = new Conversation();
        $this->webSocketService = new WebSocketService();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * Upload attachment
     * POST /v1/conversations/{id}/attachments
     * POST /v1/messages/attachments
     */
    public function upload($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        $conversationId = $params['id'] ?? Request::input('conversation_id');
        $messageContent = Request::input('message_content');
        
        if (!$conversationId) {
            // Handle case where conversation doesn't exist yet
            $articleId = Request::input('article_id');
            if (!$articleId) {
                Response::error('Conversation ID or Article ID required');
            }
            
            // Get article to find seller
            $articleModel = new Article();
            $article = $articleModel->find($articleId);
            if (!$article) {
                Response::notFound('Article not found');
            }
            
            if ($article['user_id'] == $userId) {
                Response::error('Cannot send message to yourself');
            }
            
            // Create or find conversation
            $conversation = $this->conversationModel->findOrCreate(
                $articleId,
                $userId, // buyer
                $article['user_id'] // seller
            );
            
            if (!$conversation) {
                Response::serverError('Failed to create conversation');
            }
            
            $conversationId = $conversation['id'];
        }
        
        // Check access
        if (!$this->conversationModel->hasAccess($conversationId, $userId)) {
            Response::forbidden('Access denied to this conversation');
        }
        
        // Check if users are blocked
        $conversation = $this->conversationModel->find($conversationId);
        $otherUserId = ($conversation['buyer_id'] == $userId) 
            ? $conversation['seller_id'] 
            : $conversation['buyer_id'];
            
        if ($this->conversationModel->isBlocked($userId, $otherUserId)) {
            Response::error('Cannot send message to blocked user');
        }
        
        // Handle file uploads
        $files = Request::files();
        if (empty($files)) {
            Response::error('No files uploaded');
        }
        
        try {
            if (count($files) === 1) {
                // Single file upload
                $file = reset($files);
                $result = $this->attachmentService->uploadAttachment(
                    $conversationId, 
                    $userId, 
                    $file, 
                    $messageContent
                );
                
                if (!$result['success']) {
                    Response::error($result['error']);
                }
                
                // Send real-time notification
                $this->webSocketService->broadcastMessage($result['message'], $conversationId);
                
                // Send push notification
                $this->notificationService->sendMessageNotification($result['message'], $otherUserId);
                
                Response::success([
                    'message' => $result['message'],
                    'attachment' => $result['attachment']
                ], 'File uploaded successfully');
                
            } else {
                // Multiple file upload
                $result = $this->attachmentService->uploadMultipleAttachments(
                    $conversationId, 
                    $userId, 
                    $files, 
                    $messageContent
                );
                
                if (!$result['success']) {
                    Response::error('Failed to upload files');
                }
                
                // Send notifications for successful uploads
                foreach ($result['results'] as $uploadResult) {
                    if ($uploadResult['success']) {
                        $this->webSocketService->broadcastMessage($uploadResult['message'], $conversationId);
                        $this->notificationService->sendMessageNotification($uploadResult['message'], $otherUserId);
                    }
                }
                
                Response::success($result, 'Files uploaded');
            }
            
        } catch (Exception $e) {
            Logger::error("File upload failed", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('File upload failed');
        }
    }
    
    /**
     * Delete attachment
     * DELETE /v1/attachments/{id}
     */
    public function delete($params) {
        $userId = $this->getCurrentUserId();
        $attachmentId = $params['id'];
        
        if (!$userId || !$attachmentId) {
            Response::unauthorized();
        }
        
        try {
            $result = $this->attachmentService->deleteAttachment($attachmentId, $userId);
            
            if (!$result['success']) {
                Response::error($result['error']);
            }
            
            Response::success([], 'Attachment deleted successfully');
            
        } catch (Exception $e) {
            Logger::error("Failed to delete attachment", [
                'attachment_id' => $attachmentId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to delete attachment');
        }
    }
    
    /**
     * Download attachment
     * GET /v1/attachments/{id}/download
     */
    public function download($params) {
        $userId = $this->getCurrentUserId();
        $attachmentId = $params['id'];
        
        if (!$userId || !$attachmentId) {
            Response::unauthorized();
        }
        
        try {
            // Get attachment info with access check
            $messageModel = new Message();
            $sql = "SELECT ma.*, m.sender_id, m.conversation_id, c.buyer_id, c.seller_id
                    FROM message_attachments ma
                    JOIN messages m ON ma.message_id = m.id
                    JOIN conversations c ON m.conversation_id = c.id
                    WHERE ma.id = ?";
            
            $stmt = $messageModel->db->prepare($sql);
            $stmt->execute([$attachmentId]);
            $attachment = $stmt->fetch();
            
            if (!$attachment) {
                Response::notFound('Attachment not found');
            }
            
            // Check access
            if ($attachment['buyer_id'] != $userId && $attachment['seller_id'] != $userId) {
                Response::forbidden('Access denied');
            }
            
            // Check if file exists
            if (!file_exists($attachment['file_path'])) {
                Response::notFound('File not found');
            }
            
            // Serve file
            $this->serveFile($attachment);
            
        } catch (Exception $e) {
            Logger::error("Failed to download attachment", [
                'attachment_id' => $attachmentId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to download file');
        }
    }
    
    /**
     * Get attachment info
     * GET /v1/attachments/{id}
     */
    public function getAttachmentInfo($params) {
        $userId = $this->getCurrentUserId();
        $attachmentId = $params['id'];
        
        if (!$userId || !$attachmentId) {
            Response::unauthorized();
        }
        
        try {
            $messageModel = new Message();
            $sql = "SELECT ma.*, m.sender_id, m.conversation_id
                    FROM message_attachments ma
                    JOIN messages m ON ma.message_id = m.id
                    WHERE ma.id = ?";
            
            $stmt = $messageModel->db->prepare($sql);
            $stmt->execute([$attachmentId]);
            $attachment = $stmt->fetch();
            
            if (!$attachment) {
                Response::notFound('Attachment not found');
            }
            
            // Check access
            if (!$this->conversationModel->hasAccess($attachment['conversation_id'], $userId)) {
                Response::forbidden('Access denied');
            }
            
            // Add URLs for frontend
            $attachment['full_url'] = $this->attachmentService->getAttachmentUrl($attachment, 'full');
            if ($attachment['is_image'] && $attachment['thumbnail_path']) {
                $attachment['thumbnail_url'] = $this->attachmentService->getAttachmentUrl($attachment, 'thumbnail');
            }
            
            // Remove internal file paths for security
            unset($attachment['file_path'], $attachment['thumbnail_path']);
            
            Response::success(['attachment' => $attachment]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get attachment info", [
                'attachment_id' => $attachmentId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to get attachment info');
        }
    }
    
    /**
     * Get storage statistics (admin only)
     * GET /v1/admin/attachments/stats
     */
    public function getStorageStats($params) {
        // This would be called from AdminController with proper auth check
        try {
            $stats = $this->attachmentService->getStorageStats();
            
            if ($stats === null) {
                Response::serverError('Failed to get storage statistics');
            }
            
            Response::success(['stats' => $stats]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get storage stats", [
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to get storage statistics');
        }
    }
    
    /**
     * Serve file with proper headers
     */
    private function serveFile($attachment) {
        $filePath = $attachment['file_path'];
        $filename = $attachment['original_filename'];
        $mimeType = $attachment['mime_type'];
        $fileSize = $attachment['file_size'];
        
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        
        // Handle range requests for video/audio files
        if (isset($_SERVER['HTTP_RANGE']) && 
            (strpos($mimeType, 'video/') === 0 || strpos($mimeType, 'audio/') === 0)) {
            $this->serveFileRange($filePath, $fileSize, $mimeType);
        } else {
            // Serve entire file
            readfile($filePath);
        }
        
        exit;
    }
    
    /**
     * Serve file with range support for streaming
     */
    private function serveFileRange($filePath, $fileSize, $mimeType) {
        $rangeHeader = $_SERVER['HTTP_RANGE'];
        
        // Parse range header
        if (!preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        
        $start = intval($matches[1]);
        $end = $matches[2] !== '' ? intval($matches[2]) : $fileSize - 1;
        
        // Validate range
        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        
        $contentLength = $end - $start + 1;
        
        // Set range headers
        header('HTTP/1.1 206 Partial Content');
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $contentLength);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        header('Accept-Ranges: bytes');
        
        // Serve file range
        $file = fopen($filePath, 'rb');
        fseek($file, $start);
        
        $bufferSize = 8192;
        $remaining = $contentLength;
        
        while ($remaining > 0 && !feof($file)) {
            $readSize = min($bufferSize, $remaining);
            $data = fread($file, $readSize);
            echo $data;
            $remaining -= strlen($data);
            
            if (connection_aborted()) {
                break;
            }
        }
        
        fclose($file);
        exit;
    }
    
    /**
     * Get current authenticated user ID
     */
    private function getCurrentUserId() {
        $token = Request::bearerToken();
        if (!$token) {
            return null;
        }
        
        $decoded = JWT::decode($token);
        return $decoded['user_id'] ?? null;
    }
}