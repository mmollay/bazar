<?php
/**
 * Article Model
 */

class Article extends BaseModel {
    protected $table = 'articles';
    
    /**
     * Create new article with AI suggestions
     */
    public function createWithAI($data, $images = [], $userId = null) {
        $this->db->beginTransaction();
        
        try {
            // Create article
            $articleData = [
                'user_id' => $userId ?: AuthMiddleware::getCurrentUserId(),
                'category_id' => $data['category_id'] ?? null,
                'title' => $data['title'] ?? 'New Article',
                'description' => $data['description'] ?? '',
                'price' => $data['price'] ?? 0,
                'currency' => $data['currency'] ?? 'EUR',
                'condition_type' => $data['condition_type'] ?? 'good',
                'location' => $data['location'] ?? '',
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'is_negotiable' => $data['is_negotiable'] ?? true,
                'ai_generated' => $data['ai_generated'] ?? false,
                'ai_confidence_score' => $data['ai_confidence_score'] ?? null,
                'status' => $data['status'] ?? 'draft'
            ];
            
            $articleId = $this->create($articleData);
            if (!$articleId) {
                throw new Exception('Failed to create article');
            }
            
            // Process images if provided
            $imageIds = [];
            if (!empty($images)) {
                $imageProcessingService = new ImageProcessingService();
                $articleImageModel = new ArticleImage();
                
                foreach ($images as $index => $image) {
                    $processedImage = $imageProcessingService->processArticleImage($image);
                    
                    $imageData = [
                        'article_id' => $articleId,
                        'filename' => basename($processedImage['optimized']),
                        'original_filename' => $image['name'],
                        'file_path' => $processedImage['optimized'],
                        'file_size' => $image['size'],
                        'mime_type' => $image['type'],
                        'width' => $processedImage['metadata']['width'],
                        'height' => $processedImage['metadata']['height'],
                        'is_primary' => $index === 0,
                        'sort_order' => $index
                    ];
                    
                    $imageId = $articleImageModel->create($imageData);
                    if ($imageId) {
                        $imageIds[] = $imageId;
                    }
                }
            }
            
            $this->db->commit();
            
            return [
                'article_id' => $articleId,
                'image_ids' => $imageIds
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Auto-fill article data using AI analysis
     */
    public function autoFillFromAnalysis($articleId, $analysis) {
        try {
            $updateData = [];
            $suggestions = [];
            
            // Extract suggestions from analysis
            if (!empty($analysis['suggested_title'])) {
                $updateData['title'] = $analysis['suggested_title'];
                $suggestions[] = [
                    'suggestion_type' => 'title',
                    'suggested_value' => $analysis['suggested_title'],
                    'confidence_score' => $analysis['confidence_scores']['title'] ?? 0.5
                ];
            }
            
            if (!empty($analysis['suggested_description'])) {
                $updateData['description'] = $analysis['suggested_description'];
                $suggestions[] = [
                    'suggestion_type' => 'description',
                    'suggested_value' => $analysis['suggested_description'],
                    'confidence_score' => $analysis['confidence_scores']['description'] ?? 0.5
                ];
            }
            
            if (!empty($analysis['suggested_category'])) {
                $updateData['category_id'] = $analysis['suggested_category'];
                $suggestions[] = [
                    'suggestion_type' => 'category',
                    'suggested_value' => $analysis['suggested_category'],
                    'confidence_score' => $analysis['confidence_scores']['category'] ?? 0.5
                ];
            }
            
            if (!empty($analysis['suggested_price'])) {
                $updateData['price'] = $analysis['suggested_price'];
                $suggestions[] = [
                    'suggestion_type' => 'price',
                    'suggested_value' => $analysis['suggested_price'],
                    'confidence_score' => $analysis['confidence_scores']['price'] ?? 0.5
                ];
            }
            
            if (!empty($analysis['suggested_condition'])) {
                $updateData['condition_type'] = $analysis['suggested_condition'];
                $suggestions[] = [
                    'suggestion_type' => 'condition',
                    'suggested_value' => $analysis['suggested_condition'],
                    'confidence_score' => $analysis['confidence_scores']['condition'] ?? 0.5
                ];
            }
            
            // Calculate overall AI confidence
            $confidenceScores = array_column($suggestions, 'confidence_score');
            $overallConfidence = !empty($confidenceScores) ? array_sum($confidenceScores) / count($confidenceScores) : 0;
            
            $updateData['ai_generated'] = true;
            $updateData['ai_confidence_score'] = $overallConfidence;
            
            $this->db->beginTransaction();
            
            // Update article
            $this->update($articleId, $updateData);
            
            // Save AI suggestions
            $aiSuggestionModel = new AISuggestion();
            foreach ($suggestions as $suggestion) {
                $suggestion['article_id'] = $articleId;
                $aiSuggestionModel->create($suggestion);
            }
            
            $this->db->commit();
            
            return $updateData;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get article with images and AI suggestions
     */
    public function getWithDetails($articleId, $userId = null) {
        // Get article
        $article = $this->find($articleId);
        if (!$article) {
            return null;
        }
        
        // Check access if userId provided
        if ($userId !== null && $article['user_id'] != $userId) {
            return null;
        }
        
        // Get images
        $imageModel = new ArticleImage();
        $images = $imageModel->where(['article_id' => $articleId], 'sort_order ASC');
        
        // Get AI suggestions
        $suggestionModel = new AISuggestion();
        $suggestions = $suggestionModel->where(['article_id' => $articleId], 'confidence_score DESC');
        
        // Group suggestions by type
        $groupedSuggestions = [];
        foreach ($suggestions as $suggestion) {
            $type = $suggestion['suggestion_type'];
            if (!isset($groupedSuggestions[$type])) {
                $groupedSuggestions[$type] = [];
            }
            $groupedSuggestions[$type][] = $suggestion;
        }
        
        // Get category
        $category = null;
        if ($article['category_id']) {
            $categoryModel = new Category();
            $category = $categoryModel->find($article['category_id']);
        }
        
        // Get user
        $userModel = new User();
        $user = $userModel->find($article['user_id']);
        
        return [
            'article' => $article,
            'images' => $images,
            'suggestions' => $groupedSuggestions,
            'category' => $category,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'rating' => $user['rating'],
                'rating_count' => $user['rating_count']
            ]
        ];
    }
    
    /**
     * Search articles with AI-enhanced relevance
     */
    public function searchWithAI($query, $filters = [], $page = 1, $perPage = 20) {
        $sql = "SELECT a.*, c.name as category_name, u.username, u.rating as user_rating";
        $params = [];
        
        // Add AI relevance scoring
        if (!empty($query)) {
            $sql .= ", MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE) as relevance_score";
            $params[] = $query;
        } else {
            $sql .= ", 0 as relevance_score";
        }
        
        $sql .= " FROM articles a
                  LEFT JOIN categories c ON a.category_id = c.id
                  LEFT JOIN users u ON a.user_id = u.id
                  WHERE a.status = 'active'";
        
        // Full-text search
        if (!empty($query)) {
            $sql .= " AND MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE)";
            $params[] = $query;
        }
        
        // Category filter
        if (!empty($filters['category_id'])) {
            $sql .= " AND a.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        // Price range
        if (!empty($filters['min_price'])) {
            $sql .= " AND a.price >= ?";
            $params[] = $filters['min_price'];
        }
        
        if (!empty($filters['max_price'])) {
            $sql .= " AND a.price <= ?";
            $params[] = $filters['max_price'];
        }
        
        // Condition filter
        if (!empty($filters['condition'])) {
            $conditions = is_array($filters['condition']) ? $filters['condition'] : [$filters['condition']];
            $conditionPlaceholders = str_repeat('?,', count($conditions) - 1) . '?';
            $sql .= " AND a.condition_type IN ({$conditionPlaceholders})";
            $params = array_merge($params, $conditions);
        }
        
        // Location filter (if provided)
        if (!empty($filters['latitude']) && !empty($filters['longitude']) && !empty($filters['radius'])) {
            $sql .= " AND (6371 * acos(cos(radians(?)) * cos(radians(a.latitude)) * cos(radians(a.longitude) - radians(?)) + sin(radians(?)) * sin(radians(a.latitude)))) <= ?";
            $params[] = $filters['latitude'];
            $params[] = $filters['longitude'];
            $params[] = $filters['latitude'];
            $params[] = $filters['radius'];
        }
        
        // Order by relevance if search query, otherwise by date
        if (!empty($query)) {
            $sql .= " ORDER BY relevance_score DESC, a.created_at DESC";
        } else {
            $sql .= " ORDER BY a.created_at DESC";
        }
        
        // Pagination
        $offset = ($page - 1) * $perPage;
        $sql .= " LIMIT {$perPage} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        // Get total count for pagination
        $countSql = str_replace("SELECT a.*, c.name as category_name, u.username, u.rating as user_rating" . (!empty($query) ? ", MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE) as relevance_score" : ", 0 as relevance_score"), "SELECT COUNT(*)", $sql);
        $countSql = preg_replace('/ORDER BY.*?(?=LIMIT|$)/s', '', $countSql);
        $countSql = preg_replace('/LIMIT.*$/', '', $countSql);
        
        $countParams = $params;
        if (!empty($query)) {
            array_shift($countParams); // Remove first query param used for SELECT
        }
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $totalCount = $countStmt->fetchColumn();
        
        return [
            'data' => $results,
            'total' => $totalCount,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($totalCount / $perPage),
            'has_search' => !empty($query)
        ];
    }
    
    /**
     * Get similar articles using AI analysis
     */
    public function getSimilarArticles($articleId, $limit = 5) {
        // Get article details
        $article = $this->find($articleId);
        if (!$article) {
            return [];
        }
        
        // Get article images with AI analysis
        $sql = "SELECT ai.ai_objects, ai.ai_labels
                FROM article_images ai 
                WHERE ai.article_id = ? AND ai.ai_analyzed = 1
                ORDER BY ai.is_primary DESC, ai.sort_order ASC
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$articleId]);
        $imageAnalysis = $stmt->fetch();
        
        $similarArticles = [];
        
        if ($imageAnalysis) {
            $objects = json_decode($imageAnalysis['ai_objects'], true) ?: [];
            $labels = json_decode($imageAnalysis['ai_labels'], true) ?: [];
            
            // Extract top objects and labels
            $searchTerms = [];
            foreach (array_slice($objects, 0, 3) as $object) {
                if ($object['confidence'] > 0.5) {
                    $searchTerms[] = $object['name'];
                }
            }
            foreach (array_slice($labels, 0, 5) as $label) {
                if ($label['confidence'] > 0.3) {
                    $searchTerms[] = $label['name'];
                }
            }
            
            if (!empty($searchTerms)) {
                $searchQuery = implode(' ', $searchTerms);
                
                $sql = "SELECT a.*, MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE) as relevance
                        FROM articles a
                        WHERE a.id != ? 
                        AND a.status = 'active'
                        AND a.category_id = ?
                        AND MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE)
                        ORDER BY relevance DESC
                        LIMIT ?";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$searchQuery, $articleId, $article['category_id'], $searchQuery, $limit]);
                $similarArticles = $stmt->fetchAll();
            }
        }
        
        // If not enough similar articles found, get by category
        if (count($similarArticles) < $limit) {
            $remaining = $limit - count($similarArticles);
            $excludeIds = array_column($similarArticles, 'id');
            $excludeIds[] = $articleId;
            
            $excludePlaceholders = str_repeat('?,', count($excludeIds) - 1) . '?';
            
            $sql = "SELECT a.*
                    FROM articles a
                    WHERE a.category_id = ?
                    AND a.id NOT IN ({$excludePlaceholders})
                    AND a.status = 'active'
                    ORDER BY a.created_at DESC
                    LIMIT ?";
            
            $params = array_merge([$article['category_id']], $excludeIds, [$remaining]);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $categoryArticles = $stmt->fetchAll();
            
            $similarArticles = array_merge($similarArticles, $categoryArticles);
        }
        
        return array_slice($similarArticles, 0, $limit);
    }
}