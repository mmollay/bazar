<?php
/**
 * Article Controller with AI Auto-fill workflow
 */

class ArticleController {
    private $articleModel;
    private $aiService;
    private $imageProcessingService;
    
    public function __construct() {
        $this->articleModel = new Article();
        $this->aiService = new AIService();
        $this->imageProcessingService = new ImageProcessingService();
    }
    
    /**
     * Create article with AI auto-fill (2-3 click workflow)
     * POST /api/v1/articles
     */
    public function create($params = []) {
        $user = AuthMiddleware::requireUser();
        
        // Check if this is an auto-fill request with images
        $isAutoFill = Request::input('auto_fill', false);
        
        if ($isAutoFill && !empty($_FILES['images'])) {
            return $this->createWithAutoFill($user);
        }
        
        // Regular article creation
        $data = Request::validate([
            'title' => 'required|min:3|max:255',
            'description' => 'required|min:10',
            'category_id' => 'required',
            'price' => 'required',
            'condition_type' => 'required|in:new,like_new,good,fair,poor',
            'location' => '',
            'latitude' => '',
            'longitude' => '',
            'is_negotiable' => ''
        ]);
        
        try {
            $data['user_id'] = $user['id'];
            $data['is_negotiable'] = $data['is_negotiable'] ? true : false;
            $data['status'] = 'active';
            
            $result = $this->articleModel->createWithAI($data, $_FILES['images'] ?? [], $user['id']);
            
            Logger::info('Article created', [
                'user_id' => $user['id'],
                'article_id' => $result['article_id']
            ]);
            
            Response::success($result, 'Article created successfully');
            
        } catch (Exception $e) {
            Logger::error('Article creation failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to create article: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Create article with AI auto-fill workflow
     * This implements the 2-3 click article creation process
     */
    private function createWithAutoFill($user) {
        try {
            // Step 1: Process and analyze images
            $analysisResults = [];
            $processedImages = [];
            
            $uploadedFiles = $_FILES['images'];
            $fileCount = is_array($uploadedFiles['name']) ? count($uploadedFiles['name']) : 1;
            
            // Limit to 5 images for auto-fill
            if ($fileCount > 5) {
                Response::error('Maximum 5 images allowed for auto-fill', 400);
            }
            
            $tempDir = __DIR__ . '/../../uploads/temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            // Process each image
            for ($i = 0; $i < $fileCount; $i++) {
                $filename = is_array($uploadedFiles['name']) ? $uploadedFiles['name'][$i] : $uploadedFiles['name'];
                $tmpName = is_array($uploadedFiles['tmp_name']) ? $uploadedFiles['tmp_name'][$i] : $uploadedFiles['tmp_name'];
                $fileSize = is_array($uploadedFiles['size']) ? $uploadedFiles['size'][$i] : $uploadedFiles['size'];
                $fileType = is_array($uploadedFiles['type']) ? $uploadedFiles['type'][$i] : $uploadedFiles['type'];
                $error = is_array($uploadedFiles['error']) ? $uploadedFiles['error'][$i] : $uploadedFiles['error'];
                
                if ($error !== UPLOAD_ERR_OK) {
                    continue;
                }
                
                // Save temp file
                $tempFilename = uniqid('autofill_') . '_' . $filename;
                $tempPath = $tempDir . $tempFilename;
                
                if (move_uploaded_file($tmpName, $tempPath)) {
                    // Analyze with AI
                    $analysis = $this->aiService->analyzeImage($tempPath, [
                        'user_id' => $user['id'],
                        'auto_fill' => true
                    ]);
                    
                    $analysisResults[] = $analysis;
                    $processedImages[] = [
                        'name' => $filename,
                        'tmp_name' => $tempPath,
                        'size' => $fileSize,
                        'type' => $fileType,
                        'error' => UPLOAD_ERR_OK
                    ];
                }
            }
            
            if (empty($analysisResults)) {
                Response::error('No images could be processed', 400);
            }
            
            // Step 2: Aggregate AI suggestions from all images
            $aggregatedSuggestions = $this->aggregateAnalysisResults($analysisResults);
            
            // Step 3: Create draft article with AI suggestions
            $articleData = [
                'title' => $aggregatedSuggestions['suggested_title'] ?: 'Auto-generated Article',
                'description' => $aggregatedSuggestions['suggested_description'] ?: 'Description generated from image analysis',
                'category_id' => $aggregatedSuggestions['suggested_category'],
                'price' => $aggregatedSuggestions['suggested_price'] ?: 0,
                'condition_type' => $aggregatedSuggestions['suggested_condition'] ?: 'good',
                'location' => Request::input('location', ''),
                'ai_generated' => true,
                'ai_confidence_score' => $aggregatedSuggestions['overall_confidence'],
                'status' => 'draft'
            ];
            
            $result = $this->articleModel->createWithAI($articleData, $processedImages, $user['id']);
            
            // Step 4: Save individual AI suggestions for manual override
            $aiSuggestionModel = new AISuggestion();
            foreach ($analysisResults as $index => $analysis) {
                if (isset($result['image_ids'][$index])) {
                    $imageId = $result['image_ids'][$index];
                    
                    // Save suggestions from this image
                    $suggestions = [
                        ['type' => 'title', 'value' => $analysis['suggested_title'], 'confidence' => $analysis['confidence_scores']['title'] ?? 0],
                        ['type' => 'description', 'value' => $analysis['suggested_description'], 'confidence' => $analysis['confidence_scores']['description'] ?? 0],
                        ['type' => 'category', 'value' => $analysis['suggested_category'], 'confidence' => $analysis['confidence_scores']['category'] ?? 0],
                        ['type' => 'price', 'value' => $analysis['suggested_price'], 'confidence' => $analysis['confidence_scores']['price'] ?? 0],
                        ['type' => 'condition', 'value' => $analysis['suggested_condition'], 'confidence' => $analysis['confidence_scores']['condition'] ?? 0]
                    ];
                    
                    foreach ($suggestions as $suggestion) {
                        if (!empty($suggestion['value']) && $suggestion['confidence'] > 0) {
                            $aiSuggestionModel->createSuggestion(
                                $result['article_id'],
                                $imageId,
                                $suggestion['type'],
                                $suggestion['value'],
                                $suggestion['confidence']
                            );
                        }
                    }
                    
                    // Save AI analysis to image
                    $articleImageModel = new ArticleImage();
                    $articleImageModel->saveAIAnalysis($imageId, $analysis);
                }
            }
            
            // Cleanup temp files
            foreach ($processedImages as $image) {
                if (file_exists($image['tmp_name'])) {
                    unlink($image['tmp_name']);
                }
            }
            
            // Get full article details with suggestions
            $articleDetails = $this->articleModel->getWithDetails($result['article_id'], $user['id']);
            
            Logger::info('Article auto-filled successfully', [
                'user_id' => $user['id'],
                'article_id' => $result['article_id'],
                'images_processed' => count($processedImages),
                'overall_confidence' => $aggregatedSuggestions['overall_confidence']
            ]);
            
            Response::success([
                'article' => $articleDetails,
                'auto_fill_data' => $aggregatedSuggestions,
                'workflow_step' => 'review' // Next step for user
            ], 'Article auto-filled successfully. Please review and publish.');
            
        } catch (Exception $e) {
            Logger::error('Auto-fill failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Response::error('Auto-fill failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Aggregate analysis results from multiple images
     */
    private function aggregateAnalysisResults($analysisResults) {
        $aggregated = [
            'suggested_title' => '',
            'suggested_description' => '',
            'suggested_category' => null,
            'suggested_price' => null,
            'suggested_condition' => 'good',
            'overall_confidence' => 0,
            'all_objects' => [],
            'all_labels' => [],
            'dominant_colors' => []
        ];
        
        $allObjects = [];
        $allLabels = [];
        $categoryScores = [];
        $conditionVotes = [];
        $priceEstimates = [];
        $confidenceScores = [];
        
        // Collect data from all images
        foreach ($analysisResults as $analysis) {
            // Collect objects
            foreach ($analysis['objects'] as $object) {
                $key = strtolower($object['name']);
                $allObjects[$key] = ($allObjects[$key] ?? 0) + $object['confidence'];
            }
            
            // Collect labels
            foreach ($analysis['labels'] as $label) {
                $key = strtolower($label['name']);
                $allLabels[$key] = ($allLabels[$key] ?? 0) + $label['confidence'];
            }
            
            // Category suggestions
            if ($analysis['suggested_category']) {
                $categoryScores[$analysis['suggested_category']] = 
                    ($categoryScores[$analysis['suggested_category']] ?? 0) + 
                    ($analysis['confidence_scores']['category'] ?? 0.5);
            }
            
            // Condition votes
            if ($analysis['suggested_condition']) {
                $conditionVotes[$analysis['suggested_condition']] = 
                    ($conditionVotes[$analysis['suggested_condition']] ?? 0) + 1;
            }
            
            // Price estimates
            if ($analysis['suggested_price']) {
                $priceEstimates[] = [
                    'price' => $analysis['suggested_price'],
                    'confidence' => $analysis['confidence_scores']['price'] ?? 0.3
                ];
            }
            
            // Overall confidence
            $avgConfidence = 0;
            if (!empty($analysis['confidence_scores'])) {
                $avgConfidence = array_sum($analysis['confidence_scores']) / count($analysis['confidence_scores']);
            }
            $confidenceScores[] = $avgConfidence;
        }
        
        // Generate aggregated suggestions
        
        // Title: Use top 2-3 objects
        arsort($allObjects);
        $topObjects = array_slice(array_keys($allObjects), 0, 3);
        $aggregated['suggested_title'] = implode(' ', array_map('ucfirst', $topObjects));
        
        // Description: Combine top objects and labels
        arsort($allLabels);
        $topLabels = array_slice(array_keys($allLabels), 0, 5);
        $descriptors = array_merge($topObjects, $topLabels);
        $aggregated['suggested_description'] = 'This item appears to be ' . implode(', ', array_unique($descriptors)) . '.';
        
        // Category: Highest scoring category
        if (!empty($categoryScores)) {
            arsort($categoryScores);
            $aggregated['suggested_category'] = array_key_first($categoryScores);
        }
        
        // Price: Weighted average of estimates
        if (!empty($priceEstimates)) {
            $totalWeight = 0;
            $weightedSum = 0;
            
            foreach ($priceEstimates as $estimate) {
                $weight = $estimate['confidence'];
                $weightedSum += $estimate['price'] * $weight;
                $totalWeight += $weight;
            }
            
            if ($totalWeight > 0) {
                $aggregated['suggested_price'] = round($weightedSum / $totalWeight, 2);
            }
        }
        
        // Condition: Most frequent vote
        if (!empty($conditionVotes)) {
            arsort($conditionVotes);
            $aggregated['suggested_condition'] = array_key_first($conditionVotes);
        }
        
        // Overall confidence: Average of all image confidences
        $aggregated['overall_confidence'] = !empty($confidenceScores) ? 
            array_sum($confidenceScores) / count($confidenceScores) : 0;
        
        // Store aggregated data for reference
        $aggregated['all_objects'] = $allObjects;
        $aggregated['all_labels'] = $allLabels;
        
        return $aggregated;
    }
    
    /**
     * Update article (with AI suggestion acceptance)
     * PUT /api/v1/articles/{id}
     */
    public function update($params = []) {
        $user = AuthMiddleware::requireUser();
        $articleId = $params['id'] ?? null;
        
        if (!$articleId) {
            Response::error('Article ID is required', 400);
        }
        
        // Check ownership
        $article = $this->articleModel->find($articleId);
        if (!$article || $article['user_id'] != $user['id']) {
            Response::notFound('Article not found or access denied');
        }
        
        $data = Request::validate([
            'title' => 'min:3|max:255',
            'description' => 'min:10',
            'category_id' => '',
            'price' => '',
            'condition_type' => 'in:new,like_new,good,fair,poor',
            'location' => '',
            'latitude' => '',
            'longitude' => '',
            'is_negotiable' => '',
            'status' => 'in:draft,active,sold,archived'
        ]);
        
        try {
            // Process accepted AI suggestions if provided
            $acceptedSuggestions = Request::input('accepted_suggestions', []);
            if (!empty($acceptedSuggestions)) {
                $aiSuggestionModel = new AISuggestion();
                foreach ($acceptedSuggestions as $suggestionId) {
                    $aiSuggestionModel->acceptSuggestion($suggestionId, $user['id']);
                }
            }
            
            // Filter out empty values
            $updateData = array_filter($data, function($value) {
                return $value !== null && $value !== '';
            });
            
            if (!empty($updateData)) {
                $this->articleModel->update($articleId, $updateData);
            }
            
            Logger::info('Article updated', [
                'user_id' => $user['id'],
                'article_id' => $articleId,
                'accepted_suggestions' => count($acceptedSuggestions)
            ]);
            
            // Return updated article with details
            $articleDetails = $this->articleModel->getWithDetails($articleId, $user['id']);
            Response::success($articleDetails, 'Article updated successfully');
            
        } catch (Exception $e) {
            Logger::error('Article update failed', [
                'user_id' => $user['id'],
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to update article', 500);
        }
    }
    
    /**
     * Get article details
     * GET /api/v1/articles/{id}
     */
    public function show($params = []) {
        $articleId = $params['id'] ?? null;
        
        if (!$articleId) {
            Response::error('Article ID is required', 400);
        }
        
        try {
            $user = AuthMiddleware::getCurrentUser();
            $userId = $user ? $user['id'] : null;
            
            $articleDetails = $this->articleModel->getWithDetails($articleId, $userId);
            if (!$articleDetails) {
                Response::notFound('Article not found');
            }
            
            // Only show suggestions to the owner
            if (!$userId || $articleDetails['article']['user_id'] != $userId) {
                unset($articleDetails['suggestions']);
            }
            
            // Increment view count
            $this->articleModel->update($articleId, [
                'view_count' => ($articleDetails['article']['view_count'] ?? 0) + 1
            ]);
            
            // Get similar articles
            $similarArticles = $this->articleModel->getSimilarArticles($articleId, 4);
            $articleDetails['similar_articles'] = $similarArticles;
            
            Response::success($articleDetails);
            
        } catch (Exception $e) {
            Logger::error('Failed to get article', [
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to retrieve article', 500);
        }
    }
    
    /**
     * List articles with search
     * GET /api/v1/articles
     */
    public function index($params = []) {
        try {
            $query = Request::get('q', '');
            $page = (int)Request::get('page', 1);
            $perPage = min((int)Request::get('per_page', 20), 50);
            
            $filters = [
                'category_id' => Request::get('category_id'),
                'min_price' => Request::get('min_price'),
                'max_price' => Request::get('max_price'),
                'condition' => Request::get('condition'),
                'latitude' => Request::get('latitude'),
                'longitude' => Request::get('longitude'),
                'radius' => Request::get('radius', 10)
            ];
            
            // Filter out empty values
            $filters = array_filter($filters, function($value) {
                return $value !== null && $value !== '';
            });
            
            $results = $this->articleModel->searchWithAI($query, $filters, $page, $perPage);
            
            Response::success($results);
            
        } catch (Exception $e) {
            Logger::error('Article search failed', [
                'error' => $e->getMessage()
            ]);
            
            Response::error('Search failed', 500);
        }
    }
    
    /**
     * Delete article
     * DELETE /api/v1/articles/{id}
     */
    public function delete($params = []) {
        $user = AuthMiddleware::requireUser();
        $articleId = $params['id'] ?? null;
        
        if (!$articleId) {
            Response::error('Article ID is required', 400);
        }
        
        try {
            // Check ownership
            $article = $this->articleModel->find($articleId);
            if (!$article || $article['user_id'] != $user['id']) {
                Response::notFound('Article not found or access denied');
            }
            
            // Delete images
            $articleImageModel = new ArticleImage();
            $images = $articleImageModel->where(['article_id' => $articleId]);
            
            foreach ($images as $image) {
                $articleImageModel->deleteWithFiles($image['id']);
            }
            
            // Delete article
            $this->articleModel->delete($articleId);
            
            Logger::info('Article deleted', [
                'user_id' => $user['id'],
                'article_id' => $articleId
            ]);
            
            Response::success(['deleted' => true], 'Article deleted successfully');
            
        } catch (Exception $e) {
            Logger::error('Article deletion failed', [
                'user_id' => $user['id'],
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to delete article', 500);
        }
    }
}