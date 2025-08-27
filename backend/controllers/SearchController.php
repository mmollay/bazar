<?php
/**
 * Search Controller - Comprehensive search and filtering functionality
 * Implements advanced search with MySQL fulltext, relevance scoring, and filtering
 */

class SearchController {
    private $articleModel;
    private $categoryModel;
    private $searchAnalyticsModel;
    private $cacheService;
    
    public function __construct() {
        $this->articleModel = new Article();
        $this->categoryModel = new Category();
        $this->searchAnalyticsModel = new SearchAnalytics();
        $this->cacheService = new CacheService();
    }
    
    /**
     * Main search endpoint with advanced filtering
     * GET /api/v1/search
     */
    public function search($params = []) {
        try {
            // Extract search parameters
            $query = Request::get('q', '');
            $page = max(1, (int)Request::get('page', 1));
            $perPage = min(max(1, (int)Request::get('per_page', 20)), 50);
            $sort = Request::get('sort', 'relevance');
            
            // Filters
            $filters = [
                'category_id' => Request::get('category_id'),
                'category_slug' => Request::get('category'),
                'min_price' => Request::get('min_price'),
                'max_price' => Request::get('max_price'),
                'condition' => Request::get('condition'),
                'location' => Request::get('location'),
                'latitude' => Request::get('lat'),
                'longitude' => Request::get('lng'),
                'radius' => min((int)Request::get('radius', 10), 100), // Max 100km
                'user_id' => Request::get('user_id'),
                'is_featured' => Request::get('featured'),
                'date_from' => Request::get('date_from'),
                'date_to' => Request::get('date_to')
            ];
            
            // Remove empty filters
            $filters = array_filter($filters, function($value) {
                return $value !== null && $value !== '';
            });
            
            // Cache key for results
            $cacheKey = 'search:' . md5(json_encode(compact('query', 'page', 'perPage', 'sort', 'filters')));
            $cachedResult = $this->cacheService->get($cacheKey);
            
            if ($cachedResult && !Request::get('fresh')) {
                // Track search analytics (cached)
                $this->trackSearch($query, $filters, $cachedResult['total']);
                Response::success($cachedResult);
                return;
            }
            
            // Perform search
            $startTime = microtime(true);
            
            if (!empty($query)) {
                $results = $this->performFullTextSearch($query, $filters, $sort, $page, $perPage);
            } else {
                $results = $this->performFilteredBrowse($filters, $sort, $page, $perPage);
            }
            
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Add search metadata
            $results['meta'] = [
                'query' => $query,
                'page' => $page,
                'per_page' => $perPage,
                'sort' => $sort,
                'search_time_ms' => $searchTime,
                'filters_applied' => array_keys($filters),
                'has_next_page' => ($page * $perPage) < $results['total'],
                'has_prev_page' => $page > 1
            ];
            
            // Get search suggestions for "no results"
            if ($results['total'] == 0 && !empty($query)) {
                $results['suggestions'] = $this->getSearchSuggestions($query);
                $results['popular_searches'] = $this->getPopularSearches(5);
            }
            
            // Cache results for 5 minutes
            $this->cacheService->set($cacheKey, $results, 300);
            
            // Track search analytics
            $this->trackSearch($query, $filters, $results['total'], $searchTime);
            
            Response::success($results);
            
        } catch (Exception $e) {
            Logger::error('Search failed', [
                'query' => $query ?? '',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            Response::error('Search failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Perform full-text search with relevance scoring
     */
    private function performFullTextSearch($query, $filters, $sort, $page, $perPage) {
        // Sanitize query for fulltext search
        $searchQuery = $this->sanitizeSearchQuery($query);
        
        // Base query with fulltext search
        $sql = "
            SELECT 
                a.*,
                u.username as user_username,
                u.rating as user_rating,
                c.name as category_name,
                c.slug as category_slug,
                ai.file_path as primary_image,
                ai.alt_text as image_alt,
                COUNT(f.id) as favorite_count,
                MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE) as relevance_score,
                (
                    -- Boost recent items
                    CASE 
                        WHEN a.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 0.3
                        WHEN a.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 0.2
                        ELSE 0.1
                    END +
                    -- Boost featured items
                    CASE WHEN a.is_featured = 1 THEN 0.2 ELSE 0 END +
                    -- Boost items with images
                    CASE WHEN ai.id IS NOT NULL THEN 0.1 ELSE 0 END +
                    -- Boost highly rated sellers
                    CASE 
                        WHEN u.rating >= 4.5 THEN 0.15
                        WHEN u.rating >= 4.0 THEN 0.1
                        WHEN u.rating >= 3.5 THEN 0.05
                        ELSE 0
                    END
                ) as boost_score,
                -- Distance calculation if location provided
                " . $this->getDistanceCalculation($filters) . "
            FROM articles a
            JOIN users u ON a.user_id = u.id
            JOIN categories c ON a.category_id = c.id
            LEFT JOIN article_images ai ON a.id = ai.article_id AND ai.is_primary = 1
            LEFT JOIN favorites f ON a.id = f.article_id
            WHERE a.status = 'active' 
                AND MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE)
        ";
        
        $params = [$searchQuery, $searchQuery];
        
        // Apply filters
        list($sql, $params) = $this->applyFilters($sql, $params, $filters);
        
        // Group by article
        $sql .= " GROUP BY a.id";
        
        // Apply sorting
        $sql = $this->applySorting($sql, $sort, true); // true indicates fulltext search
        
        // Get total count
        $countSql = "SELECT COUNT(DISTINCT a.id) as total FROM ($sql) as search_results";
        $totalResult = Database::query($countSql, $params);
        $total = $totalResult[0]['total'] ?? 0;
        
        // Apply pagination
        $offset = ($page - 1) * $perPage;
        $sql .= " LIMIT $perPage OFFSET $offset";
        
        // Execute search
        $articles = Database::query($sql, $params);
        
        // Process results
        $articles = $this->processSearchResults($articles);
        
        return [
            'articles' => $articles,
            'total' => (int)$total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }
    
    /**
     * Perform filtered browse (no search query)
     */
    private function performFilteredBrowse($filters, $sort, $page, $perPage) {
        $sql = "
            SELECT 
                a.*,
                u.username as user_username,
                u.rating as user_rating,
                c.name as category_name,
                c.slug as category_slug,
                ai.file_path as primary_image,
                ai.alt_text as image_alt,
                COUNT(f.id) as favorite_count,
                0 as relevance_score,
                (
                    CASE 
                        WHEN a.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 0.3
                        WHEN a.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 0.2
                        ELSE 0.1
                    END +
                    CASE WHEN a.is_featured = 1 THEN 0.2 ELSE 0 END +
                    CASE WHEN ai.id IS NOT NULL THEN 0.1 ELSE 0 END +
                    CASE 
                        WHEN u.rating >= 4.5 THEN 0.15
                        WHEN u.rating >= 4.0 THEN 0.1
                        WHEN u.rating >= 3.5 THEN 0.05
                        ELSE 0
                    END
                ) as boost_score,
                " . $this->getDistanceCalculation($filters) . "
            FROM articles a
            JOIN users u ON a.user_id = u.id
            JOIN categories c ON a.category_id = c.id
            LEFT JOIN article_images ai ON a.id = ai.article_id AND ai.is_primary = 1
            LEFT JOIN favorites f ON a.id = f.article_id
            WHERE a.status = 'active'
        ";
        
        $params = [];
        
        // Apply filters
        list($sql, $params) = $this->applyFilters($sql, $params, $filters);
        
        // Group by article
        $sql .= " GROUP BY a.id";
        
        // Apply sorting
        $sql = $this->applySorting($sql, $sort, false); // false indicates browse mode
        
        // Get total count
        $countSql = "SELECT COUNT(DISTINCT a.id) as total FROM ($sql) as browse_results";
        $totalResult = Database::query($countSql, $params);
        $total = $totalResult[0]['total'] ?? 0;
        
        // Apply pagination
        $offset = ($page - 1) * $perPage;
        $sql .= " LIMIT $perPage OFFSET $offset";
        
        // Execute query
        $articles = Database::query($sql, $params);
        
        // Process results
        $articles = $this->processSearchResults($articles);
        
        return [
            'articles' => $articles,
            'total' => (int)$total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }
    
    /**
     * Apply filters to SQL query
     */
    private function applyFilters($sql, $params, $filters) {
        // Category filter
        if (isset($filters['category_id'])) {
            $sql .= " AND a.category_id = ?";
            $params[] = $filters['category_id'];
        } elseif (isset($filters['category_slug'])) {
            $sql .= " AND c.slug = ?";
            $params[] = $filters['category_slug'];
        }
        
        // Price range
        if (isset($filters['min_price'])) {
            $sql .= " AND a.price >= ?";
            $params[] = (float)$filters['min_price'];
        }
        if (isset($filters['max_price'])) {
            $sql .= " AND a.price <= ?";
            $params[] = (float)$filters['max_price'];
        }
        
        // Condition
        if (isset($filters['condition'])) {
            if (is_array($filters['condition'])) {
                $placeholders = str_repeat('?,', count($filters['condition']) - 1) . '?';
                $sql .= " AND a.condition_type IN ($placeholders)";
                $params = array_merge($params, $filters['condition']);
            } else {
                $sql .= " AND a.condition_type = ?";
                $params[] = $filters['condition'];
            }
        }
        
        // Location-based filtering
        if (isset($filters['latitude'], $filters['longitude'], $filters['radius'])) {
            $lat = (float)$filters['latitude'];
            $lng = (float)$filters['longitude'];
            $radius = (float)$filters['radius'];
            
            $sql .= " AND (
                6371 * acos(
                    cos(radians(?)) * cos(radians(a.latitude)) *
                    cos(radians(a.longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(a.latitude))
                )
            ) <= ?";
            $params = array_merge($params, [$lat, $lng, $lat, $radius]);
        } elseif (isset($filters['location'])) {
            $sql .= " AND a.location LIKE ?";
            $params[] = '%' . $filters['location'] . '%';
        }
        
        // User filter
        if (isset($filters['user_id'])) {
            $sql .= " AND a.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        // Featured filter
        if (isset($filters['is_featured'])) {
            $sql .= " AND a.is_featured = ?";
            $params[] = $filters['is_featured'] ? 1 : 0;
        }
        
        // Date range
        if (isset($filters['date_from'])) {
            $sql .= " AND a.created_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $sql .= " AND a.created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        return [$sql, $params];
    }
    
    /**
     * Apply sorting to SQL query
     */
    private function applySorting($sql, $sort, $hasRelevance = false) {
        switch ($sort) {
            case 'relevance':
                if ($hasRelevance) {
                    $sql .= " ORDER BY (relevance_score + boost_score) DESC, a.created_at DESC";
                } else {
                    $sql .= " ORDER BY boost_score DESC, a.created_at DESC";
                }
                break;
            case 'price_asc':
                $sql .= " ORDER BY a.price ASC, a.created_at DESC";
                break;
            case 'price_desc':
                $sql .= " ORDER BY a.price DESC, a.created_at DESC";
                break;
            case 'date_asc':
                $sql .= " ORDER BY a.created_at ASC";
                break;
            case 'date_desc':
            case 'newest':
                $sql .= " ORDER BY a.created_at DESC";
                break;
            case 'distance':
                $sql .= " ORDER BY distance ASC, a.created_at DESC";
                break;
            case 'popular':
                $sql .= " ORDER BY (a.view_count + favorite_count * 2) DESC, a.created_at DESC";
                break;
            default:
                $sql .= " ORDER BY a.created_at DESC";
        }
        
        return $sql;
    }
    
    /**
     * Get distance calculation SQL
     */
    private function getDistanceCalculation($filters) {
        if (isset($filters['latitude'], $filters['longitude'])) {
            $lat = (float)$filters['latitude'];
            $lng = (float)$filters['longitude'];
            return "
                6371 * acos(
                    cos(radians($lat)) * cos(radians(a.latitude)) *
                    cos(radians(a.longitude) - radians($lng)) +
                    sin(radians($lat)) * sin(radians(a.latitude))
                ) as distance
            ";
        }
        
        return "NULL as distance";
    }
    
    /**
     * Process search results
     */
    private function processSearchResults($articles) {
        foreach ($articles as &$article) {
            // Format price
            $article['formatted_price'] = number_format($article['price'], 2) . ' EUR';
            
            // Format distance
            if ($article['distance'] !== null) {
                $article['formatted_distance'] = number_format($article['distance'], 1) . ' km';
            }
            
            // Format created date
            $article['created_at_human'] = $this->timeAgo($article['created_at']);
            
            // Build image URL
            if ($article['primary_image']) {
                $article['image_url'] = '/uploads/' . $article['primary_image'];
            } else {
                $article['image_url'] = '/frontend/assets/images/placeholder.svg';
            }
            
            // Clean up sensitive data
            unset($article['ai_confidence_score'], $article['relevance_score'], $article['boost_score']);
        }
        
        return $articles;
    }
    
    /**
     * Sanitize search query for fulltext search
     */
    private function sanitizeSearchQuery($query) {
        // Remove special characters that can break fulltext search
        $query = preg_replace('/[+\-><\(\)~*\"@]/', ' ', $query);
        
        // Remove extra spaces
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        // Add wildcard to last word for partial matching
        $words = explode(' ', $query);
        if (count($words) > 0) {
            $lastWord = array_pop($words);
            if (strlen($lastWord) >= 3) {
                $words[] = $lastWord . '*';
            } else {
                $words[] = $lastWord;
            }
            $query = implode(' ', $words);
        }
        
        return $query;
    }
    
    /**
     * Get search suggestions for auto-complete
     * GET /api/v1/search/suggestions
     */
    public function suggestions($params = []) {
        try {
            $query = Request::get('q', '');
            $limit = min((int)Request::get('limit', 8), 20);
            
            if (strlen($query) < 2) {
                Response::success([
                    'suggestions' => [],
                    'popular_searches' => $this->getPopularSearches(5)
                ]);
                return;
            }
            
            // Cache key
            $cacheKey = 'suggestions:' . md5($query) . ':' . $limit;
            $cached = $this->cacheService->get($cacheKey);
            
            if ($cached) {
                Response::success($cached);
                return;
            }
            
            // Get suggestions from multiple sources
            $suggestions = [];
            
            // 1. Article titles and descriptions
            $titleSuggestions = $this->getArticleSuggestions($query, $limit);
            
            // 2. Popular search queries
            $popularSuggestions = $this->getPopularSearchSuggestions($query, 3);
            
            // 3. Category names
            $categorySuggestions = $this->getCategorySuggestions($query, 3);
            
            // Combine and rank suggestions
            $suggestions = array_merge($titleSuggestions, $popularSuggestions, $categorySuggestions);
            
            // Remove duplicates and limit
            $suggestions = array_slice(array_unique($suggestions), 0, $limit);
            
            $result = [
                'suggestions' => $suggestions,
                'popular_searches' => $this->getPopularSearches(5)
            ];
            
            // Cache for 10 minutes
            $this->cacheService->set($cacheKey, $result, 600);
            
            Response::success($result);
            
        } catch (Exception $e) {
            Logger::error('Suggestions failed', [
                'query' => $query ?? '',
                'error' => $e->getMessage()
            ]);
            
            Response::success(['suggestions' => [], 'popular_searches' => []]);
        }
    }
    
    /**
     * Get article-based suggestions
     */
    private function getArticleSuggestions($query, $limit) {
        $sql = "
            SELECT DISTINCT a.title
            FROM articles a
            WHERE a.status = 'active' 
                AND (a.title LIKE ? OR a.description LIKE ?)
            ORDER BY 
                CASE WHEN a.title LIKE ? THEN 1 ELSE 2 END,
                a.view_count DESC
            LIMIT ?
        ";
        
        $queryPattern = '%' . $query . '%';
        $titlePattern = $query . '%';
        
        $results = Database::query($sql, [$queryPattern, $queryPattern, $titlePattern, $limit]);
        
        return array_column($results, 'title');
    }
    
    /**
     * Get popular search suggestions
     */
    private function getPopularSearchSuggestions($query, $limit) {
        $sql = "
            SELECT sa.query
            FROM search_analytics sa
            WHERE sa.query LIKE ? 
                AND sa.results_count > 0
                AND sa.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY sa.query
            ORDER BY COUNT(*) DESC, MAX(sa.created_at) DESC
            LIMIT ?
        ";
        
        $results = Database::query($sql, [$query . '%', $limit]);
        
        return array_column($results, 'query');
    }
    
    /**
     * Get category suggestions
     */
    private function getCategorySuggestions($query, $limit) {
        $sql = "
            SELECT c.name
            FROM categories c
            WHERE c.is_active = 1 
                AND (c.name LIKE ? OR JSON_EXTRACT(c.ai_keywords, '$') LIKE ?)
            ORDER BY 
                CASE WHEN c.name LIKE ? THEN 1 ELSE 2 END
            LIMIT ?
        ";
        
        $queryPattern = '%' . $query . '%';
        $titlePattern = $query . '%';
        
        $results = Database::query($sql, [$queryPattern, $queryPattern, $titlePattern, $limit]);
        
        return array_column($results, 'name');
    }
    
    /**
     * Save search for user
     * POST /api/v1/search/save
     */
    public function saveSearch($params = []) {
        $user = AuthMiddleware::requireUser();
        
        $data = Request::validate([
            'name' => 'required|min:1|max:100',
            'query' => 'max:500',
            'filters' => '',
            'email_alerts' => ''
        ]);
        
        try {
            $searchData = [
                'user_id' => $user['id'],
                'name' => $data['name'],
                'query' => $data['query'] ?? '',
                'filters' => json_encode($data['filters'] ?? []),
                'email_alerts' => $data['email_alerts'] ? 1 : 0
            ];
            
            $savedSearchId = Database::insert('saved_searches', $searchData);
            
            Logger::info('Search saved', [
                'user_id' => $user['id'],
                'search_id' => $savedSearchId,
                'name' => $data['name']
            ]);
            
            Response::success([
                'id' => $savedSearchId,
                'message' => 'Search saved successfully'
            ]);
            
        } catch (Exception $e) {
            Logger::error('Save search failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to save search', 500);
        }
    }
    
    /**
     * Get user's saved searches
     * GET /api/v1/search/saved
     */
    public function getSavedSearches($params = []) {
        $user = AuthMiddleware::requireUser();
        
        try {
            $sql = "
                SELECT 
                    ss.*,
                    (
                        SELECT COUNT(*)
                        FROM articles a
                        JOIN categories c ON a.category_id = c.id
                        WHERE a.status = 'active'
                            AND (
                                ss.query = '' 
                                OR MATCH(a.title, a.description) AGAINST (ss.query IN NATURAL LANGUAGE MODE)
                            )
                    ) as current_results_count
                FROM saved_searches ss
                WHERE ss.user_id = ? AND ss.is_active = 1
                ORDER BY ss.created_at DESC
            ";
            
            $savedSearches = Database::query($sql, [$user['id']]);
            
            // Parse filters JSON
            foreach ($savedSearches as &$search) {
                $search['filters'] = json_decode($search['filters'], true) ?? [];
                $search['has_new_results'] = $search['current_results_count'] > 0;
            }
            
            Response::success(['saved_searches' => $savedSearches]);
            
        } catch (Exception $e) {
            Logger::error('Get saved searches failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to get saved searches', 500);
        }
    }
    
    /**
     * Delete saved search
     * DELETE /api/v1/search/saved/{id}
     */
    public function deleteSavedSearch($params = []) {
        $user = AuthMiddleware::requireUser();
        $searchId = $params['id'] ?? null;
        
        if (!$searchId) {
            Response::error('Search ID is required', 400);
        }
        
        try {
            // Check ownership
            $search = Database::selectOne('saved_searches', ['user_id' => $user['id'], 'id' => $searchId]);
            if (!$search) {
                Response::notFound('Saved search not found');
            }
            
            // Soft delete
            Database::update('saved_searches', ['is_active' => 0], ['id' => $searchId]);
            
            Logger::info('Saved search deleted', [
                'user_id' => $user['id'],
                'search_id' => $searchId
            ]);
            
            Response::success(['deleted' => true]);
            
        } catch (Exception $e) {
            Logger::error('Delete saved search failed', [
                'user_id' => $user['id'],
                'search_id' => $searchId,
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to delete saved search', 500);
        }
    }
    
    /**
     * Get available filter options
     * GET /api/v1/search/filters
     */
    public function getFilterOptions($params = []) {
        try {
            // Cache key
            $cacheKey = 'search:filter_options';
            $cached = $this->cacheService->get($cacheKey);
            
            if ($cached) {
                Response::success($cached);
                return;
            }
            
            // Get categories
            $categories = Database::query("
                SELECT c.id, c.name, c.slug, c.parent_id, COUNT(a.id) as article_count
                FROM categories c
                LEFT JOIN articles a ON c.id = a.category_id AND a.status = 'active'
                WHERE c.is_active = 1
                GROUP BY c.id, c.name, c.slug, c.parent_id
                ORDER BY c.sort_order, c.name
            ");
            
            // Get price ranges
            $priceRanges = Database::query("
                SELECT 
                    MIN(price) as min_price,
                    MAX(price) as max_price,
                    AVG(price) as avg_price,
                    COUNT(*) as total_articles
                FROM articles 
                WHERE status = 'active' AND price > 0
            ");
            
            // Get condition counts
            $conditions = Database::query("
                SELECT condition_type, COUNT(*) as count
                FROM articles 
                WHERE status = 'active'
                GROUP BY condition_type
                ORDER BY 
                    CASE condition_type
                        WHEN 'new' THEN 1
                        WHEN 'like_new' THEN 2
                        WHEN 'good' THEN 3
                        WHEN 'fair' THEN 4
                        WHEN 'poor' THEN 5
                    END
            ");
            
            // Get popular locations
            $locations = Database::query("
                SELECT location, COUNT(*) as count
                FROM articles 
                WHERE status = 'active' AND location IS NOT NULL AND location != ''
                GROUP BY location
                HAVING count >= 5
                ORDER BY count DESC
                LIMIT 20
            ");
            
            $result = [
                'categories' => $categories,
                'price_range' => $priceRanges[0] ?? [],
                'conditions' => $conditions,
                'popular_locations' => $locations,
                'sort_options' => [
                    ['value' => 'relevance', 'label' => 'Relevance'],
                    ['value' => 'newest', 'label' => 'Newest first'],
                    ['value' => 'price_asc', 'label' => 'Price: Low to High'],
                    ['value' => 'price_desc', 'label' => 'Price: High to Low'],
                    ['value' => 'distance', 'label' => 'Distance'],
                    ['value' => 'popular', 'label' => 'Most popular']
                ]
            ];
            
            // Cache for 1 hour
            $this->cacheService->set($cacheKey, $result, 3600);
            
            Response::success($result);
            
        } catch (Exception $e) {
            Logger::error('Get filter options failed', [
                'error' => $e->getMessage()
            ]);
            
            Response::error('Failed to get filter options', 500);
        }
    }
    
    /**
     * Get popular searches
     */
    private function getPopularSearches($limit = 10) {
        try {
            $sql = "
                SELECT sa.query, COUNT(*) as search_count, MAX(sa.created_at) as last_searched
                FROM search_analytics sa
                WHERE sa.results_count > 0 
                    AND sa.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND LENGTH(sa.query) >= 2
                GROUP BY sa.query
                ORDER BY search_count DESC, last_searched DESC
                LIMIT ?
            ";
            
            $results = Database::query($sql, [$limit]);
            
            return array_map(function($row) {
                return [
                    'query' => $row['query'],
                    'count' => (int)$row['search_count']
                ];
            }, $results);
            
        } catch (Exception $e) {
            Logger::error('Get popular searches failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Get search suggestions when no results found
     */
    private function getSearchSuggestions($query) {
        try {
            // Simple word-based suggestions
            $words = explode(' ', strtolower($query));
            $suggestions = [];
            
            // Get similar articles by individual words
            foreach ($words as $word) {
                if (strlen($word) >= 3) {
                    $sql = "
                        SELECT DISTINCT a.title
                        FROM articles a
                        WHERE a.status = 'active' 
                            AND (a.title LIKE ? OR a.description LIKE ?)
                        ORDER BY a.view_count DESC
                        LIMIT 3
                    ";
                    
                    $pattern = '%' . $word . '%';
                    $results = Database::query($sql, [$pattern, $pattern]);
                    
                    foreach ($results as $result) {
                        $suggestions[] = $result['title'];
                    }
                }
            }
            
            return array_slice(array_unique($suggestions), 0, 5);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Track search analytics
     */
    private function trackSearch($query, $filters, $resultsCount, $searchTime = null) {
        if (!$query && empty($filters)) {
            return; // Don't track empty searches
        }
        
        try {
            $this->searchAnalyticsModel->track([
                'query' => $query,
                'filters' => $filters,
                'results_count' => $resultsCount,
                'search_time_ms' => $searchTime,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (Exception $e) {
            // Don't fail the search if analytics tracking fails
            Logger::warning('Search analytics tracking failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Time ago helper
     */
    private function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'vor wenigen Sekunden';
        if ($time < 3600) return 'vor ' . floor($time / 60) . ' Minuten';
        if ($time < 86400) return 'vor ' . floor($time / 3600) . ' Stunden';
        if ($time < 2592000) return 'vor ' . floor($time / 86400) . ' Tagen';
        if ($time < 31536000) return 'vor ' . floor($time / 2592000) . ' Monaten';
        
        return 'vor ' . floor($time / 31536000) . ' Jahren';
    }
}