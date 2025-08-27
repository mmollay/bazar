<?php
/**
 * Batch Processing Service for AI operations
 * Handles background processing of images and AI analysis
 */

class BatchProcessingService {
    private $db;
    private $aiService;
    private $cacheService;
    private $imageProcessingService;
    private $maxBatchSize = 10;
    private $processingTimeout = 300; // 5 minutes
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->aiService = new AIService();
        $this->cacheService = new CacheService();
        $this->imageProcessingService = new ImageProcessingService();
    }
    
    /**
     * Add images to processing queue
     */
    public function addToQueue($imageIds, $processingType = 'analysis', $priority = 'normal') {
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_processing_queue (image_id, processing_type, status, created_at)
                VALUES (?, ?, 'pending', NOW())
            ");
            
            $added = 0;
            foreach ($imageIds as $imageId) {
                if ($stmt->execute([$imageId, $processingType])) {
                    $added++;
                }
            }
            
            $this->db->commit();
            
            Logger::info("Added {$added} images to processing queue", [
                'type' => $processingType,
                'priority' => $priority
            ]);
            
            return $added;
            
        } catch (Exception $e) {
            $this->db->rollback();
            Logger::error('Failed to add images to queue', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Process pending queue items
     */
    public function processPendingQueue($batchSize = null) {
        $batchSize = $batchSize ?: $this->maxBatchSize;
        
        // Get pending items
        $stmt = $this->db->prepare("
            SELECT pq.*, ai.file_path, ai.article_id
            FROM ai_processing_queue pq
            JOIN article_images ai ON pq.image_id = ai.id
            WHERE pq.status = 'pending' 
            AND pq.attempts < pq.max_attempts
            ORDER BY pq.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$batchSize]);
        $queueItems = $stmt->fetchAll();
        
        if (empty($queueItems)) {
            return [
                'processed' => 0,
                'errors' => 0,
                'message' => 'No pending items in queue'
            ];
        }
        
        $results = [
            'processed' => 0,
            'errors' => 0,
            'details' => []
        ];
        
        foreach ($queueItems as $item) {
            try {
                $this->markAsProcessing($item['id']);
                
                $result = $this->processQueueItem($item);
                
                if ($result['success']) {
                    $this->markAsCompleted($item['id']);
                    $results['processed']++;
                } else {
                    $this->markAsFailed($item['id'], $result['error']);
                    $results['errors']++;
                }
                
                $results['details'][] = [
                    'queue_id' => $item['id'],
                    'image_id' => $item['image_id'],
                    'type' => $item['processing_type'],
                    'success' => $result['success'],
                    'error' => $result['error'] ?? null
                ];
                
            } catch (Exception $e) {
                $this->markAsFailed($item['id'], $e->getMessage());
                $results['errors']++;
                
                Logger::error('Queue item processing failed', [
                    'queue_id' => $item['id'],
                    'image_id' => $item['image_id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Logger::info('Batch processing completed', [
            'processed' => $results['processed'],
            'errors' => $results['errors'],
            'total_items' => count($queueItems)
        ]);
        
        return $results;
    }
    
    /**
     * Process individual queue item
     */
    private function processQueueItem($item) {
        $imagePath = __DIR__ . '/../../uploads/' . $item['file_path'];
        
        if (!file_exists($imagePath)) {
            return [
                'success' => false,
                'error' => 'Image file not found: ' . $item['file_path']
            ];
        }
        
        try {
            switch ($item['processing_type']) {
                case 'analysis':
                    return $this->processImageAnalysis($item, $imagePath);
                case 'similarity':
                    return $this->processSimilarityCalculation($item, $imagePath);
                case 'categorization':
                    return $this->processCategorization($item, $imagePath);
                case 'text_extraction':
                    return $this->processTextExtraction($item, $imagePath);
                default:
                    return [
                        'success' => false,
                        'error' => 'Unknown processing type: ' . $item['processing_type']
                    ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process image analysis
     */
    private function processImageAnalysis($item, $imagePath) {
        // Check cache first
        $imageHash = $this->cacheService->generateImageHash($imagePath);
        $cachedAnalysis = $this->cacheService->getCachedAnalysis($imageHash);
        
        if ($cachedAnalysis) {
            $this->saveAnalysisToDatabase($item['image_id'], $cachedAnalysis);
            return ['success' => true, 'cached' => true];
        }
        
        // Perform AI analysis
        $analysis = $this->aiService->analyzeImage($imagePath, [
            'batch_processing' => true,
            'image_id' => $item['image_id']
        ]);
        
        // Cache result
        $this->cacheService->cacheAnalysis($imageHash, $analysis, 86400); // 24 hours
        
        // Save to database
        $this->saveAnalysisToDatabase($item['image_id'], $analysis);
        
        return ['success' => true, 'analysis' => $analysis];
    }
    
    /**
     * Process similarity calculation
     */
    private function processSimilarityCalculation($item, $imagePath) {
        // Get image analysis
        $stmt = $this->db->prepare("
            SELECT ai_objects, ai_labels, ai_colors
            FROM article_images 
            WHERE id = ? AND ai_analyzed = 1
        ");
        $stmt->execute([$item['image_id']]);
        $imageData = $stmt->fetch();
        
        if (!$imageData) {
            return [
                'success' => false,
                'error' => 'Image must be analyzed first'
            ];
        }
        
        $objects = json_decode($imageData['ai_objects'], true) ?: [];
        $labels = json_decode($imageData['ai_labels'], true) ?: [];
        $colors = json_decode($imageData['ai_colors'], true) ?: [];
        
        // Find similar images
        $similarImages = $this->findSimilarImages($objects, $labels, $colors, $item['image_id']);
        
        // Cache results
        $imageHash = $this->cacheService->generateImageHash($imagePath);
        $this->cacheService->cacheSimilarImages($imageHash, $similarImages, 3600);
        
        return ['success' => true, 'similar_count' => count($similarImages)];
    }
    
    /**
     * Process categorization
     */
    private function processCategorization($item, $imagePath) {
        // Get analysis data
        $stmt = $this->db->prepare("
            SELECT ai_objects, ai_labels
            FROM article_images 
            WHERE id = ? AND ai_analyzed = 1
        ");
        $stmt->execute([$item['image_id']]);
        $imageData = $stmt->fetch();
        
        if (!$imageData) {
            return [
                'success' => false,
                'error' => 'Image must be analyzed first'
            ];
        }
        
        $objects = json_decode($imageData['ai_objects'], true) ?: [];
        $labels = json_decode($imageData['ai_labels'], true) ?: [];
        
        // Generate category suggestions
        $suggestions = $this->generateCategorySuggestions($objects, $labels);
        
        // Save suggestions
        if (!empty($suggestions)) {
            $this->saveCategorySuggestions($item['article_id'], $item['image_id'], $suggestions);
        }
        
        return ['success' => true, 'suggestions_count' => count($suggestions)];
    }
    
    /**
     * Process text extraction
     */
    private function processTextExtraction($item, $imagePath) {
        // Use OCR to extract text (simplified implementation)
        $textData = $this->extractTextFromImage($imagePath);
        
        // Update image record
        $stmt = $this->db->prepare("
            UPDATE article_images 
            SET ai_text = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([json_encode($textData), $item['image_id']]);
        
        return ['success' => true, 'text_items' => count($textData)];
    }
    
    /**
     * Save analysis to database
     */
    private function saveAnalysisToDatabase($imageId, $analysis) {
        $articleImageModel = new ArticleImage();
        return $articleImageModel->saveAIAnalysis($imageId, $analysis);
    }
    
    /**
     * Find similar images based on analysis
     */
    private function findSimilarImages($objects, $labels, $colors, $excludeImageId) {
        // Simplified similarity calculation
        $sql = "
            SELECT ai.id, ai.article_id, ai.file_path,
                   MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE) as text_relevance
            FROM article_images ai
            JOIN articles a ON ai.article_id = a.id
            WHERE ai.id != ? 
            AND ai.ai_analyzed = 1
            AND a.status = 'active'
            ORDER BY text_relevance DESC
            LIMIT 20
        ";
        
        // Create search string from objects and labels
        $searchTerms = array_merge(
            array_column($objects, 'name'),
            array_column($labels, 'name')
        );
        $searchString = implode(' ', array_slice($searchTerms, 0, 10));
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$searchString, $excludeImageId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Generate category suggestions
     */
    private function generateCategorySuggestions($objects, $labels) {
        // Check cache first
        $cached = $this->cacheService->getCachedCategorySuggestions($objects, $labels);
        if ($cached) {
            return $cached;
        }
        
        $categoryScores = [];
        
        // Get all categories with keywords
        $stmt = $this->db->prepare("SELECT id, name, ai_keywords FROM categories WHERE is_active = 1");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        foreach ($categories as $category) {
            $keywords = json_decode($category['ai_keywords'], true) ?: [];
            $score = 0;
            
            // Score based on objects
            foreach ($objects as $object) {
                foreach ($keywords as $keyword) {
                    if (stripos($object['name'], $keyword) !== false) {
                        $score += $object['confidence'] * 2;
                    }
                }
            }
            
            // Score based on labels
            foreach ($labels as $label) {
                foreach ($keywords as $keyword) {
                    if (stripos($label['name'], $keyword) !== false) {
                        $score += $label['confidence'];
                    }
                }
            }
            
            if ($score > 0) {
                $categoryScores[] = [
                    'category_id' => $category['id'],
                    'category_name' => $category['name'],
                    'score' => $score,
                    'confidence' => min($score / 3, 1.0) // Normalize
                ];
            }
        }
        
        // Sort by score
        usort($categoryScores, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        $suggestions = array_slice($categoryScores, 0, 3); // Top 3 suggestions
        
        // Cache results
        $this->cacheService->cacheCategorySuggestions($objects, $labels, $suggestions, 3600);
        
        return $suggestions;
    }
    
    /**
     * Save category suggestions
     */
    private function saveCategorySuggestions($articleId, $imageId, $suggestions) {
        $aiSuggestionModel = new AISuggestion();
        
        foreach ($suggestions as $suggestion) {
            $aiSuggestionModel->createSuggestion(
                $articleId,
                $imageId,
                'category',
                $suggestion['category_id'],
                $suggestion['confidence']
            );
        }
    }
    
    /**
     * Extract text from image (OCR simulation)
     */
    private function extractTextFromImage($imagePath) {
        // This would integrate with actual OCR service
        // For now, return empty array
        return [];
    }
    
    /**
     * Mark queue item as processing
     */
    private function markAsProcessing($queueId) {
        $stmt = $this->db->prepare("
            UPDATE ai_processing_queue 
            SET status = 'processing', started_at = NOW(), attempts = attempts + 1
            WHERE id = ?
        ");
        return $stmt->execute([$queueId]);
    }
    
    /**
     * Mark queue item as completed
     */
    private function markAsCompleted($queueId) {
        $stmt = $this->db->prepare("
            UPDATE ai_processing_queue 
            SET status = 'completed', completed_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$queueId]);
    }
    
    /**
     * Mark queue item as failed
     */
    private function markAsFailed($queueId, $error) {
        $stmt = $this->db->prepare("
            UPDATE ai_processing_queue 
            SET status = 'failed', error_message = ?, completed_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$error, $queueId]);
    }
    
    /**
     * Retry failed queue items
     */
    public function retryFailed($maxRetries = 3) {
        $stmt = $this->db->prepare("
            UPDATE ai_processing_queue 
            SET status = 'pending', error_message = NULL, started_at = NULL
            WHERE status = 'failed' 
            AND attempts < ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $retried = $stmt->execute([$maxRetries]) ? $stmt->rowCount() : 0;
        
        Logger::info("Retried {$retried} failed queue items");
        
        return $retried;
    }
    
    /**
     * Clean up old queue items
     */
    public function cleanup($olderThanDays = 7) {
        $stmt = $this->db->prepare("
            DELETE FROM ai_processing_queue 
            WHERE status IN ('completed', 'failed')
            AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        
        $cleaned = $stmt->execute([$olderThanDays]) ? $stmt->rowCount() : 0;
        
        Logger::info("Cleaned up {$cleaned} old queue items");
        
        return $cleaned;
    }
    
    /**
     * Get queue statistics
     */
    public function getQueueStats() {
        $stmt = $this->db->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_processing_time
            FROM ai_processing_queue
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY status
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'avg_processing_time' => 0
        ];
        
        foreach ($results as $result) {
            $stats[$result['status']] = (int)$result['count'];
            if ($result['status'] === 'completed' && $result['avg_processing_time']) {
                $stats['avg_processing_time'] = round($result['avg_processing_time'], 2);
            }
        }
        
        // Get overall queue health
        $total = array_sum([$stats['pending'], $stats['processing'], $stats['completed'], $stats['failed']]);
        if ($total > 0) {
            $stats['success_rate'] = round(($stats['completed'] / $total) * 100, 2);
            $stats['failure_rate'] = round(($stats['failed'] / $total) * 100, 2);
        } else {
            $stats['success_rate'] = 0;
            $stats['failure_rate'] = 0;
        }
        
        return $stats;
    }
    
    /**
     * Process queue in background (for CLI)
     */
    public function runDaemon($interval = 30, $maxRuntime = 3600) {
        $startTime = time();
        
        Logger::info('Starting batch processing daemon', [
            'interval' => $interval,
            'max_runtime' => $maxRuntime
        ]);
        
        while (time() - $startTime < $maxRuntime) {
            try {
                $result = $this->processPendingQueue();
                
                if ($result['processed'] > 0 || $result['errors'] > 0) {
                    Logger::info('Daemon batch processed', $result);
                }
                
                // Clean up occasionally
                if (rand(1, 10) === 1) {
                    $this->cleanup();
                    $this->retryFailed();
                }
                
                sleep($interval);
                
            } catch (Exception $e) {
                Logger::error('Daemon error', ['error' => $e->getMessage()]);
                sleep($interval * 2); // Wait longer on error
            }
        }
        
        Logger::info('Batch processing daemon stopped');
    }
    
    /**
     * Estimate processing time for queue
     */
    public function estimateProcessingTime() {
        $stats = $this->getQueueStats();
        
        $pendingItems = $stats['pending'];
        $avgProcessingTime = $stats['avg_processing_time'] ?: 30; // Default 30 seconds
        
        $estimatedSeconds = $pendingItems * $avgProcessingTime;
        
        return [
            'pending_items' => $pendingItems,
            'estimated_seconds' => $estimatedSeconds,
            'estimated_minutes' => round($estimatedSeconds / 60, 1),
            'estimated_completion' => date('Y-m-d H:i:s', time() + $estimatedSeconds)
        ];
    }
}