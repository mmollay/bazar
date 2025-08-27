<?php
/**
 * Image Processing Service
 * Handles image optimization, resizing, format conversion, and WebP generation
 */

class ImageProcessingService {
    private $uploadsDir;
    private $tempDir;
    private $maxWidth = 1920;
    private $maxHeight = 1080;
    private $thumbnailWidth = 300;
    private $thumbnailHeight = 300;
    private $quality = [
        'jpeg' => 85,
        'webp' => 80,
        'png' => 9 // PNG compression level (0-9)
    ];
    
    public function __construct() {
        $this->uploadsDir = __DIR__ . '/../../uploads/articles/';
        $this->tempDir = __DIR__ . '/../../uploads/temp/';
        
        // Ensure directories exist
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }
    
    /**
     * Process uploaded image for article
     */
    public function processArticleImage($uploadedFile, $options = []) {
        if (!extension_loaded('gd')) {
            throw new Exception('GD extension is required for image processing');
        }
        
        $result = [
            'original' => null,
            'optimized' => null,
            'thumbnail' => null,
            'webp' => null,
            'webp_thumbnail' => null,
            'metadata' => []
        ];
        
        try {
            // Validate uploaded file
            $this->validateUploadedFile($uploadedFile);
            
            // Generate unique filename
            $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            $baseFilename = uniqid('img_' . time() . '_');
            $originalFilename = $baseFilename . '.' . $fileExtension;
            
            // Create year/month directory structure
            $dateDir = date('Y/m/');
            $targetDir = $this->uploadsDir . $dateDir;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            
            $originalPath = $targetDir . $originalFilename;
            
            // Move uploaded file
            if (!move_uploaded_file($uploadedFile['tmp_name'], $originalPath)) {
                throw new Exception('Failed to save uploaded file');
            }
            
            // Get image info
            $imageInfo = getimagesize($originalPath);
            if (!$imageInfo) {
                unlink($originalPath);
                throw new Exception('Invalid image file');
            }
            
            [$width, $height, $type] = $imageInfo;
            
            // Store metadata
            $result['metadata'] = [
                'original_filename' => $uploadedFile['name'],
                'file_size' => $uploadedFile['size'],
                'mime_type' => $imageInfo['mime'],
                'width' => $width,
                'height' => $height,
                'type' => $type
            ];
            
            // Load source image
            $sourceImage = $this->createImageFromType($originalPath, $type);
            if (!$sourceImage) {
                unlink($originalPath);
                throw new Exception('Failed to load image');
            }
            
            // Process different versions
            $result['original'] = $dateDir . $originalFilename;
            
            // Create optimized version (resize if needed)
            if ($width > $this->maxWidth || $height > $this->maxHeight) {
                $optimizedImage = $this->resizeImage($sourceImage, $width, $height, $this->maxWidth, $this->maxHeight);
                $optimizedFilename = $baseFilename . '_optimized.' . $fileExtension;
                $optimizedPath = $targetDir . $optimizedFilename;
                
                if ($this->saveImage($optimizedImage, $optimizedPath, $type)) {
                    $result['optimized'] = $dateDir . $optimizedFilename;
                }
                
                imagedestroy($optimizedImage);
            } else {
                $result['optimized'] = $result['original'];
            }
            
            // Create thumbnail
            $thumbnailImage = $this->createThumbnail($sourceImage, $width, $height);
            $thumbnailFilename = $baseFilename . '_thumb.' . $fileExtension;
            $thumbnailPath = $targetDir . $thumbnailFilename;
            
            if ($this->saveImage($thumbnailImage, $thumbnailPath, $type)) {
                $result['thumbnail'] = $dateDir . $thumbnailFilename;
            }
            
            imagedestroy($thumbnailImage);
            
            // Create WebP versions if supported
            if (function_exists('imagewebp')) {
                // WebP optimized
                $webpFilename = $baseFilename . '_optimized.webp';
                $webpPath = $targetDir . $webpFilename;
                
                $webpImage = $result['optimized'] === $result['original'] ? 
                    $sourceImage : 
                    $this->createImageFromFile($targetDir . basename($result['optimized']));
                    
                if ($webpImage && imagewebp($webpImage, $webpPath, $this->quality['webp'])) {
                    $result['webp'] = $dateDir . $webpFilename;
                }
                
                if ($webpImage !== $sourceImage) {
                    imagedestroy($webpImage);
                }
                
                // WebP thumbnail
                $webpThumbFilename = $baseFilename . '_thumb.webp';
                $webpThumbPath = $targetDir . $webpThumbFilename;
                
                $thumbImage = $this->createImageFromFile($thumbnailPath);
                if ($thumbImage && imagewebp($thumbImage, $webpThumbPath, $this->quality['webp'])) {
                    $result['webp_thumbnail'] = $dateDir . $webpThumbFilename;
                }
                
                if ($thumbImage) {
                    imagedestroy($thumbImage);
                }
            }
            
            imagedestroy($sourceImage);
            
            // Update file sizes
            foreach (['original', 'optimized', 'thumbnail', 'webp', 'webp_thumbnail'] as $version) {
                if ($result[$version]) {
                    $fullPath = $this->uploadsDir . $result[$version];
                    if (file_exists($fullPath)) {
                        $result['metadata'][$version . '_size'] = filesize($fullPath);
                    }
                }
            }
            
            Logger::info('Image processed successfully', [
                'original_size' => $result['metadata']['file_size'],
                'optimized_size' => $result['metadata']['optimized_size'] ?? 0,
                'versions_created' => array_filter([$result['original'], $result['optimized'], $result['thumbnail'], $result['webp'], $result['webp_thumbnail']])
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            // Cleanup on error
            $this->cleanupFiles($result);
            throw $e;
        }
    }
    
    /**
     * Create multiple sizes for an image
     */
    public function createMultipleSizes($sourcePath, $sizes = []) {
        if (!file_exists($sourcePath)) {
            throw new Exception('Source image not found');
        }
        
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            throw new Exception('Invalid image file');
        }
        
        [$originalWidth, $originalHeight, $type] = $imageInfo;
        $sourceImage = $this->createImageFromType($sourcePath, $type);
        
        if (!$sourceImage) {
            throw new Exception('Failed to load source image');
        }
        
        $results = [];
        $pathInfo = pathinfo($sourcePath);
        
        foreach ($sizes as $sizeName => $dimensions) {
            try {
                $targetWidth = $dimensions['width'];
                $targetHeight = $dimensions['height'] ?? null;
                $crop = $dimensions['crop'] ?? false;
                
                if ($crop) {
                    $resizedImage = $this->cropImage($sourceImage, $originalWidth, $originalHeight, $targetWidth, $targetHeight);
                } else {
                    $resizedImage = $this->resizeImage($sourceImage, $originalWidth, $originalHeight, $targetWidth, $targetHeight);
                }
                
                $outputFilename = $pathInfo['filename'] . '_' . $sizeName . '.' . $pathInfo['extension'];
                $outputPath = $pathInfo['dirname'] . '/' . $outputFilename;
                
                if ($this->saveImage($resizedImage, $outputPath, $type)) {
                    $results[$sizeName] = $outputPath;
                }
                
                imagedestroy($resizedImage);
                
            } catch (Exception $e) {
                Logger::warning("Failed to create size {$sizeName}", ['error' => $e->getMessage()]);
            }
        }
        
        imagedestroy($sourceImage);
        return $results;
    }
    
    /**
     * Optimize existing image
     */
    public function optimizeImage($imagePath, $options = []) {
        if (!file_exists($imagePath)) {
            throw new Exception('Image file not found');
        }
        
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            throw new Exception('Invalid image file');
        }
        
        [$width, $height, $type] = $imageInfo;
        
        // Load image
        $image = $this->createImageFromType($imagePath, $type);
        if (!$image) {
            throw new Exception('Failed to load image');
        }
        
        $optimized = false;
        
        // Resize if too large
        $maxWidth = $options['max_width'] ?? $this->maxWidth;
        $maxHeight = $options['max_height'] ?? $this->maxHeight;
        
        if ($width > $maxWidth || $height > $maxHeight) {
            $resizedImage = $this->resizeImage($image, $width, $height, $maxWidth, $maxHeight);
            imagedestroy($image);
            $image = $resizedImage;
            $optimized = true;
        }
        
        // Save optimized version
        $backupPath = $imagePath . '.backup';
        copy($imagePath, $backupPath);
        
        if ($this->saveImage($image, $imagePath, $type)) {
            unlink($backupPath);
        } else {
            copy($backupPath, $imagePath);
            unlink($backupPath);
            throw new Exception('Failed to save optimized image');
        }
        
        imagedestroy($image);
        
        return [
            'optimized' => $optimized,
            'original_size' => filesize($backupPath ?? $imagePath),
            'new_size' => filesize($imagePath)
        ];
    }
    
    /**
     * Convert image to WebP format
     */
    public function convertToWebP($sourcePath, $outputPath = null, $quality = null) {
        if (!function_exists('imagewebp')) {
            throw new Exception('WebP support not available');
        }
        
        if (!file_exists($sourcePath)) {
            throw new Exception('Source image not found');
        }
        
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            throw new Exception('Invalid image file');
        }
        
        [$width, $height, $type] = $imageInfo;
        $image = $this->createImageFromType($sourcePath, $type);
        
        if (!$image) {
            throw new Exception('Failed to load source image');
        }
        
        // Generate output path if not provided
        if ($outputPath === null) {
            $pathInfo = pathinfo($sourcePath);
            $outputPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';
        }
        
        $quality = $quality ?? $this->quality['webp'];
        $success = imagewebp($image, $outputPath, $quality);
        
        imagedestroy($image);
        
        if (!$success) {
            throw new Exception('Failed to convert to WebP');
        }
        
        return $outputPath;
    }
    
    /**
     * Extract EXIF data from image
     */
    public function extractExifData($imagePath) {
        if (!function_exists('exif_read_data')) {
            return [];
        }
        
        try {
            $exifData = exif_read_data($imagePath);
            if (!$exifData) {
                return [];
            }
            
            // Extract useful information
            $extracted = [];
            
            if (isset($exifData['Make'])) $extracted['camera_make'] = $exifData['Make'];
            if (isset($exifData['Model'])) $extracted['camera_model'] = $exifData['Model'];
            if (isset($exifData['DateTime'])) $extracted['date_taken'] = $exifData['DateTime'];
            if (isset($exifData['COMPUTED']['Width'])) $extracted['width'] = $exifData['COMPUTED']['Width'];
            if (isset($exifData['COMPUTED']['Height'])) $extracted['height'] = $exifData['COMPUTED']['Height'];
            if (isset($exifData['Flash'])) $extracted['flash_used'] = $exifData['Flash'];
            if (isset($exifData['FocalLength'])) $extracted['focal_length'] = $exifData['FocalLength'];
            if (isset($exifData['ISOSpeedRatings'])) $extracted['iso'] = $exifData['ISOSpeedRatings'];
            
            // GPS data
            if (isset($exifData['GPS'])) {
                $gps = $this->extractGPSCoordinates($exifData['GPS']);
                if ($gps) {
                    $extracted['gps'] = $gps;
                }
            }
            
            return $extracted;
            
        } catch (Exception $e) {
            Logger::warning('Failed to extract EXIF data', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Validate uploaded file
     */
    private function validateUploadedFile($uploadedFile) {
        if (!isset($uploadedFile['error']) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error');
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $mimeType = mime_content_type($uploadedFile['tmp_name']);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Invalid file type. Only JPEG, PNG, WebP, and GIF images are allowed.');
        }
        
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($uploadedFile['size'] > $maxSize) {
            throw new Exception('File size exceeds maximum limit of 10MB.');
        }
        
        // Additional security check
        $imageInfo = getimagesize($uploadedFile['tmp_name']);
        if (!$imageInfo) {
            throw new Exception('File is not a valid image.');
        }
    }
    
    /**
     * Create image resource from file type
     */
    private function createImageFromType($path, $type) {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false;
            default:
                return false;
        }
    }
    
    /**
     * Create image resource from file
     */
    private function createImageFromFile($path) {
        $imageInfo = getimagesize($path);
        if (!$imageInfo) {
            return false;
        }
        
        return $this->createImageFromType($path, $imageInfo[2]);
    }
    
    /**
     * Resize image maintaining aspect ratio
     */
    private function resizeImage($sourceImage, $sourceWidth, $sourceHeight, $maxWidth, $maxHeight) {
        $ratio = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $newWidth = round($sourceWidth * $ratio);
        $newHeight = round($sourceHeight * $ratio);
        
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG and GIF
        $this->preserveTransparency($resizedImage, $sourceImage);
        
        imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
        
        return $resizedImage;
    }
    
    /**
     * Crop image to exact dimensions
     */
    private function cropImage($sourceImage, $sourceWidth, $sourceHeight, $targetWidth, $targetHeight) {
        $targetHeight = $targetHeight ?? $targetWidth; // Square if height not specified
        
        $ratio = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
        $newWidth = round($sourceWidth * $ratio);
        $newHeight = round($sourceHeight * $ratio);
        
        // Create temporary resized image
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
        $this->preserveTransparency($resizedImage, $sourceImage);
        imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
        
        // Crop to target size
        $croppedImage = imagecreatetruecolor($targetWidth, $targetHeight);
        $this->preserveTransparency($croppedImage, $resizedImage);
        
        $cropX = round(($newWidth - $targetWidth) / 2);
        $cropY = round(($newHeight - $targetHeight) / 2);
        
        imagecopy($croppedImage, $resizedImage, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight);
        
        imagedestroy($resizedImage);
        
        return $croppedImage;
    }
    
    /**
     * Create thumbnail
     */
    private function createThumbnail($sourceImage, $sourceWidth, $sourceHeight) {
        return $this->cropImage($sourceImage, $sourceWidth, $sourceHeight, $this->thumbnailWidth, $this->thumbnailHeight);
    }
    
    /**
     * Save image with appropriate format and quality
     */
    private function saveImage($image, $path, $type) {
        $pathInfo = pathinfo($path);
        $extension = strtolower($pathInfo['extension']);
        
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagejpeg($image, $path, $this->quality['jpeg']);
            case IMAGETYPE_PNG:
                return imagepng($image, $path, $this->quality['png']);
            case IMAGETYPE_GIF:
                return imagegif($image, $path);
            case IMAGETYPE_WEBP:
                return function_exists('imagewebp') ? imagewebp($image, $path, $this->quality['webp']) : false;
            default:
                return false;
        }
    }
    
    /**
     * Preserve transparency for PNG and GIF images
     */
    private function preserveTransparency($newImage, $sourceImage) {
        $transparentIndex = imagecolortransparent($sourceImage);
        
        if ($transparentIndex >= 0) {
            $transparentColor = imagecolorsforindex($sourceImage, $transparentIndex);
            $transparentIndex = imagecolorallocate($newImage, $transparentColor['red'], $transparentColor['green'], $transparentColor['blue']);
            imagefill($newImage, 0, 0, $transparentIndex);
            imagecolortransparent($newImage, $transparentIndex);
        } else {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefill($newImage, 0, 0, $transparent);
        }
    }
    
    /**
     * Extract GPS coordinates from EXIF data
     */
    private function extractGPSCoordinates($gpsData) {
        if (!isset($gpsData['GPSLatitude'], $gpsData['GPSLongitude'], $gpsData['GPSLatitudeRef'], $gpsData['GPSLongitudeRef'])) {
            return null;
        }
        
        $latitude = $this->convertGPSCoordinate($gpsData['GPSLatitude'], $gpsData['GPSLatitudeRef']);
        $longitude = $this->convertGPSCoordinate($gpsData['GPSLongitude'], $gpsData['GPSLongitudeRef']);
        
        return [
            'latitude' => $latitude,
            'longitude' => $longitude
        ];
    }
    
    /**
     * Convert GPS coordinate to decimal degrees
     */
    private function convertGPSCoordinate($coordinate, $hemisphere) {
        if (is_string($coordinate)) {
            $coordinate = explode(',', $coordinate);
        }
        
        if (count($coordinate) !== 3) {
            return null;
        }
        
        for ($i = 0; $i < 3; $i++) {
            $part = explode('/', $coordinate[$i]);
            if (count($part) == 1) {
                $coordinate[$i] = $part[0];
            } elseif (count($part) == 2) {
                $coordinate[$i] = floatval($part[0]) / floatval($part[1]);
            } else {
                return null;
            }
        }
        
        $decimal = $coordinate[0] + $coordinate[1] / 60 + $coordinate[2] / 3600;
        
        if ($hemisphere == 'S' || $hemisphere == 'W') {
            $decimal *= -1;
        }
        
        return $decimal;
    }
    
    /**
     * Cleanup files on error
     */
    private function cleanupFiles($result) {
        foreach (['original', 'optimized', 'thumbnail', 'webp', 'webp_thumbnail'] as $version) {
            if (isset($result[$version]) && $result[$version]) {
                $fullPath = $this->uploadsDir . $result[$version];
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }
        }
    }
    
    /**
     * Delete image files
     */
    public function deleteImageFiles($imagePaths) {
        $deleted = [];
        
        foreach ((array)$imagePaths as $path) {
            $fullPath = $this->uploadsDir . $path;
            if (file_exists($fullPath)) {
                if (unlink($fullPath)) {
                    $deleted[] = $path;
                }
            }
        }
        
        return $deleted;
    }
    
    /**
     * Get image information
     */
    public function getImageInfo($imagePath) {
        $fullPath = $this->uploadsDir . $imagePath;
        
        if (!file_exists($fullPath)) {
            return null;
        }
        
        $imageInfo = getimagesize($fullPath);
        if (!$imageInfo) {
            return null;
        }
        
        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'type' => $imageInfo[2],
            'mime' => $imageInfo['mime'],
            'file_size' => filesize($fullPath),
            'exif' => $this->extractExifData($fullPath)
        ];
    }
}