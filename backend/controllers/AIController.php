<?php
/**
 * AI Controller for handling image analysis and article suggestions
 */

class AIController {
    private $aiService;
    private $db;
    
    public function __construct() {
        $this->aiService = new AIService();
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Analyze single image and return suggestions
     * POST /api/v1/ai/analyze-image
     */
    public function analyzeImage($params = []) {
        $user = AuthMiddleware::requireUser();
        
        // Check if image file was uploaded
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            Response::error('No image file uploaded or upload error occurred', 400);
        }
        
        $uploadedFile = $_FILES['image'];
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($uploadedFile['type'], $allowedTypes)) {
            Response::error('Invalid file type. Only JPEG, PNG, WebP, and GIF images are allowed.', 400);
        }
        
        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($uploadedFile['size'] > $maxSize) {
            Response::error('File size exceeds maximum limit of 10MB.', 400);
        }
        
        try {
            // Create temporary file
            $tempDir = __DIR__ . '/../../uploads/temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $tempFilename = uniqid('ai_analysis_') . '_' . $uploadedFile['name'];
            $tempPath = $tempDir . $tempFilename;
            
            if (!move_uploaded_file($uploadedFile['tmp_name'], $tempPath)) {
                throw new Exception('Failed to save uploaded file');
            }
            
            // Process image
            $processedPath = $this->preprocessImage($tempPath);
            
            // Analyze with AI
            $startTime = microtime(true);
            $analysis = $this->aiService->analyzeImage($processedPath, [
                'user_id' => $user['id'],
                'original_filename' => $uploadedFile['name']
            ]);
            $processingTime = round((microtime(true) - $startTime) * 1000, 2); // ms
            
            // Add metadata
            $analysis['metadata'] = [
                'processing_time_ms' => $processingTime,
                'file_size' => $uploadedFile['size'],
                'original_filename' => $uploadedFile['name'],
                'analysis_timestamp' => date('c')
            ];
            
            // Log analysis for monitoring
            Logger::info('Image analyzed successfully', [
                'user_id' => $user['id'],
                'processing_time_ms' => $processingTime,
                'objects_detected' => count($analysis['objects']),
                'labels_detected' => count($analysis['labels'])
            ]);
            
            // Cleanup temporary files
            if (file_exists($tempPath)) unlink($tempPath);
            if (file_exists($processedPath) && $processedPath !== $tempPath) unlink($processedPath);
            
            Response::success($analysis, 'Image analyzed successfully');
            
        } catch (Exception $e) {
            Logger::error('Image analysis failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            // Cleanup on error
            if (isset($tempPath) && file_exists($tempPath)) unlink($tempPath);
            if (isset($processedPath) && file_exists($processedPath) && $processedPath !== $tempPath) {
                unlink($processedPath);
            }
            
            Response::error('Failed to analyze image: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Analyze multiple images in batch
     * POST /api/v1/ai/analyze-images-batch
     */
    public function analyzeImagesBatch($params = []) {
        $user = AuthMiddleware::requireUser();
        
        // Check if multiple files were uploaded
        if (!isset($_FILES['images']) || empty($_FILES['images']['name'])) {
            Response::error('No image files uploaded', 400);
        }
        
        $uploadedFiles = $_FILES['images'];
        $fileCount = is_array($uploadedFiles['name']) ? count($uploadedFiles['name']) : 1;
        
        // Limit batch size
        $maxBatchSize = 5;
        if ($fileCount > $maxBatchSize) {
            Response::error("Maximum {$maxBatchSize} images allowed per batch", 400);
        }
        
        $results = [];
        $errors = [];
        $tempPaths = [];
        
        try {
            $tempDir = __DIR__ . '/../../uploads/temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            // Process each uploaded file
            for ($i = 0; $i < $fileCount; $i++) {
                $filename = is_array($uploadedFiles['name']) ? $uploadedFiles['name'][$i] : $uploadedFiles['name'];
                $tmpName = is_array($uploadedFiles['tmp_name']) ? $uploadedFiles['tmp_name'][$i] : $uploadedFiles['tmp_name'];
                $fileSize = is_array($uploadedFiles['size']) ? $uploadedFiles['size'][$i] : $uploadedFiles['size'];
                $fileType = is_array($uploadedFiles['type']) ? $uploadedFiles['type'][$i] : $uploadedFiles['type'];
                $error = is_array($uploadedFiles['error']) ? $uploadedFiles['error'][$i] : $uploadedFiles['error'];
                
                if ($error !== UPLOAD_ERR_OK) {
                    $errors[$i] = "Upload error for file {$filename}";
                    continue;
                }
                
                // Validate file
                $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                if (!in_array($fileType, $allowedTypes)) {
                    $errors[$i] = "Invalid file type for {$filename}";
                    continue;
                }
                
                $maxSize = 10 * 1024 * 1024; // 10MB
                if ($fileSize > $maxSize) {
                    $errors[$i] = "File size exceeds limit for {$filename}";
                    continue;
                }
                
                // Save temp file
                $tempFilename = uniqid('batch_' . $i . '_') . '_' . $filename;
                $tempPath = $tempDir . $tempFilename;
                $tempPaths[$i] = $tempPath;
                
                if (!move_uploaded_file($tmpName, $tempPath)) {
                    $errors[$i] = "Failed to save {$filename}";
                    continue;
                }
                
                // Preprocess and analyze
                try {
                    $processedPath = $this->preprocessImage($tempPath);
                    $analysis = $this->aiService->analyzeImage($processedPath, [
                        'user_id' => $user['id'],
                        'original_filename' => $filename,
                        'batch_index' => $i
                    ]);
                    
                    $results[$i] = [
                        'filename' => $filename,
                        'analysis' => $analysis,
                        'success' => true
                    ];
                    
                    if (file_exists($processedPath) && $processedPath !== $tempPath) {
                        unlink($processedPath);
                    }
                    
                } catch (Exception $e) {
                    $errors[$i] = "Analysis failed for {$filename}: " . $e->getMessage();
                }
            }
            
            // Cleanup temp files
            foreach ($tempPaths as $path) {
                if (file_exists($path)) unlink($path);
            }
            
            Logger::info('Batch image analysis completed', [
                'user_id' => $user['id'],
                'total_files' => $fileCount,
                'successful' => count($results),
                'errors' => count($errors)
            ]);
            
            Response::success([
                'results' => $results,
                'errors' => $errors,
                'summary' => [
                    'total' => $fileCount,
                    'successful' => count($results),
                    'failed' => count($errors)
                ]
            ], 'Batch analysis completed');
            
        } catch (Exception $e) {
            // Cleanup on error
            foreach ($tempPaths as $path) {
                if (file_exists($path)) unlink($path);
            }
            
            Logger::error('Batch image analysis failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Response::error('Batch analysis failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get AI suggestions for an article
     * GET /api/v1/ai/suggestions/{articleId}
     */
    public function getSuggestions($params = []) {
        $user = AuthMiddleware::requireUser();
        $articleId = $params['articleId'] ?? null;
        
        if (!$articleId) {
            Response::error('Article ID is required', 400);
        }
        
        try {
            // Get article and verify ownership
            $stmt = $this->db->prepare("SELECT * FROM articles WHERE id = ? AND user_id = ?");
            $stmt->execute([$articleId, $user['id']]);
            $article = $stmt->fetch();
            
            if (!$article) {
                Response::notFound('Article not found or access denied');
            }
            
            // Get AI suggestions
            $stmt = $this->db->prepare("
                SELECT s.*, ai.filename, ai.file_path
                FROM ai_suggestions s
                LEFT JOIN article_images ai ON s.image_id = ai.id
                WHERE s.article_id = ?
                ORDER BY s.confidence_score DESC, s.created_at DESC
            ");
            $stmt->execute([$articleId]);
            $suggestions = $stmt->fetchAll();
            
            // Group suggestions by type
            $groupedSuggestions = [];
            foreach ($suggestions as $suggestion) {
                $type = $suggestion['suggestion_type'];
                if (!isset($groupedSuggestions[$type])) {
                    $groupedSuggestions[$type] = [];
                }
                $groupedSuggestions[$type][] = $suggestion;
            }
            
            Response::success([
                'article_id' => $articleId,
                'suggestions' => $groupedSuggestions,
                'total_suggestions' => count($suggestions)
            ]);
            
        } catch (Exception $e) {
            Logger::error('Failed to get AI suggestions', [
                'user_id' => $user['id'],
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to retrieve suggestions', 500);
        }
    }
    
    /**
     * Submit feedback on AI suggestion
     * POST /api/v1/ai/suggestions/{suggestionId}/feedback
     */
    public function submitFeedback($params = []) {
        $user = AuthMiddleware::requireUser();
        $suggestionId = $params['suggestionId'] ?? null;
        
        $data = Request::validate([
            'feedback' => 'required|in:accepted,rejected,modified',
            'modified_value' => ''
        ]);
        
        try {
            // Verify suggestion exists and user has access
            $stmt = $this->db->prepare("
                SELECT s.*, a.user_id 
                FROM ai_suggestions s
                JOIN articles a ON s.article_id = a.id
                WHERE s.id = ?
            ");
            $stmt->execute([$suggestionId]);
            $suggestion = $stmt->fetch();
            
            if (!$suggestion || $suggestion['user_id'] != $user['id']) {
                Response::notFound('Suggestion not found or access denied');
            }
            
            // Update suggestion with feedback
            $stmt = $this->db->prepare("
                UPDATE ai_suggestions 
                SET user_feedback = ?, 
                    is_accepted = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $isAccepted = $data['feedback'] === 'accepted';
            $stmt->execute([$data['feedback'], $isAccepted, $suggestionId]);
            
            // If modified, store the modified value
            if ($data['feedback'] === 'modified' && !empty($data['modified_value'])) {
                // You might want to store this in a separate table or field
                Logger::info('AI suggestion modified', [
                    'suggestion_id' => $suggestionId,
                    'original' => $suggestion['suggested_value'],
                    'modified' => $data['modified_value']
                ]);
            }
            
            Logger::info('AI suggestion feedback submitted', [
                'user_id' => $user['id'],
                'suggestion_id' => $suggestionId,
                'feedback' => $data['feedback']
            ]);
            
            Response::success(['suggestion_id' => $suggestionId], 'Feedback submitted successfully');
            
        } catch (Exception $e) {
            Logger::error('Failed to submit AI feedback', [
                'user_id' => $user['id'],
                'suggestion_id' => $suggestionId,
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to submit feedback', 500);
        }
    }
    
    /**
     * Categorize text using NLP
     * POST /api/v1/ai/categorize-text
     */
    public function categorizeText($params = []) {
        $user = AuthMiddleware::requireUser();
        
        $data = Request::validate([
            'text' => 'required|min:5|max:1000'
        ]);
        
        try {
            // Simple text categorization based on keywords
            $categories = $this->categorizeTextByKeywords($data['text']);
            
            Logger::info('Text categorized', [
                'user_id' => $user['id'],
                'text_length' => strlen($data['text']),
                'categories_found' => count($categories)
            ]);
            
            Response::success([
                'text' => $data['text'],
                'categories' => $categories
            ], 'Text categorized successfully');
            
        } catch (Exception $e) {
            Logger::error('Text categorization failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to categorize text', 500);
        }
    }
    
    /**
     * Estimate price for item
     * POST /api/v1/ai/estimate-price
     */
    public function estimatePrice($params = []) {
        $user = AuthMiddleware::requireUser();
        
        $data = Request::validate([
            'category_id' => 'required',
            'condition' => 'required|in:new,like_new,good,fair,poor',
            'description' => '',
            'location' => ''
        ]);
        
        try {
            // Get category
            $stmt = $this->db->prepare("SELECT * FROM categories WHERE id = ?");
            $stmt->execute([$data['category_id']]);
            $category = $stmt->fetch();
            
            if (!$category) {
                Response::error('Invalid category ID', 400);
            }
            
            // Get price statistics
            $stmt = $this->db->prepare("
                SELECT 
                    AVG(original_price) as avg_price,
                    MIN(original_price) as min_price,
                    MAX(original_price) as max_price,
                    COUNT(*) as sample_size
                FROM price_history 
                WHERE category_id = ? 
                AND condition_type = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 6 MONTH)
            ");
            $stmt->execute([$data['category_id'], $data['condition']]);
            $priceStats = $stmt->fetch();
            
            $estimation = [
                'estimated_price' => null,
                'price_range' => [
                    'min' => null,
                    'max' => null
                ],
                'confidence' => 0,
                'sample_size' => $priceStats['sample_size'] ?? 0,
                'factors' => []
            ];
            
            if ($priceStats && $priceStats['avg_price']) {
                $basePrice = $priceStats['avg_price'];
                
                // Apply condition multipliers
                $conditionMultipliers = [
                    'new' => 1.0,
                    'like_new' => 0.85,
                    'good' => 0.7,
                    'fair' => 0.55,
                    'poor' => 0.4
                ];
                
                $multiplier = $conditionMultipliers[$data['condition']] ?? 0.7;
                $estimatedPrice = $basePrice * $multiplier;
                
                $estimation['estimated_price'] = round($estimatedPrice, 2);
                $estimation['price_range'] = [
                    'min' => round($estimatedPrice * 0.8, 2),
                    'max' => round($estimatedPrice * 1.2, 2)
                ];
                $estimation['confidence'] = min($priceStats['sample_size'] / 10, 1.0);
                $estimation['factors'] = [
                    'category' => $category['name'],
                    'condition' => $data['condition'],
                    'base_price' => round($basePrice, 2),
                    'condition_multiplier' => $multiplier
                ];
            }
            
            Logger::info('Price estimated', [
                'user_id' => $user['id'],
                'category_id' => $data['category_id'],
                'condition' => $data['condition'],
                'estimated_price' => $estimation['estimated_price']
            ]);
            
            Response::success($estimation, 'Price estimated successfully');
            
        } catch (Exception $e) {
            Logger::error('Price estimation failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to estimate price', 500);
        }
    }
    
    /**
     * Preprocess image for better AI analysis
     */
    private function preprocessImage($imagePath) {
        try {
            // Check if GD extension is loaded
            if (!extension_loaded('gd')) {
                return $imagePath; // Return original if GD not available
            }
            
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                throw new Exception('Invalid image file');
            }
            
            [$width, $height, $type] = $imageInfo;
            
            // Load image based on type
            $image = null;
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($imagePath);
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($imagePath);
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif($imagePath);
                    break;
                case IMAGETYPE_WEBP:
                    $image = imagecreatefromwebp($imagePath);
                    break;
                default:
                    return $imagePath; // Unsupported type
            }
            
            if (!$image) {
                return $imagePath;
            }
            
            // Resize if too large (max 1920x1080 for better processing speed)
            $maxWidth = 1920;
            $maxHeight = 1080;
            
            if ($width > $maxWidth || $height > $maxHeight) {
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                $newWidth = round($width * $ratio);
                $newHeight = round($height * $ratio);
                
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                
                imagedestroy($image);
                $image = $resized;
            }
            
            // Save processed image
            $processedPath = $imagePath . '_processed.jpg';
            imagejpeg($image, $processedPath, 90);
            imagedestroy($image);
            
            return $processedPath;
            
        } catch (Exception $e) {
            Logger::warning('Image preprocessing failed', ['error' => $e->getMessage()]);
            return $imagePath; // Return original on error
        }
    }
    
    /**
     * Simple text categorization based on keywords
     */
    private function categorizeTextByKeywords($text) {
        $text = strtolower($text);
        $categories = [];
        
        // Get categories with keywords
        $stmt = $this->db->prepare("SELECT id, name, ai_keywords FROM categories WHERE is_active = 1");
        $stmt->execute();
        $categoryData = $stmt->fetchAll();
        
        foreach ($categoryData as $category) {
            $keywords = json_decode($category['ai_keywords'], true) ?: [];
            $score = 0;
            $matchedKeywords = [];
            
            foreach ($keywords as $keyword) {
                if (strpos($text, strtolower($keyword)) !== false) {
                    $score += 1;
                    $matchedKeywords[] = $keyword;
                }
            }
            
            if ($score > 0) {
                $categories[] = [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'score' => $score,
                    'confidence' => min($score / count($keywords), 1.0),
                    'matched_keywords' => $matchedKeywords
                ];
            }
        }
        
        // Sort by score
        usort($categories, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        return array_slice($categories, 0, 5); // Return top 5 matches
    }
}