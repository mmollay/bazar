<?php
/**
 * Search Alert Service - Handle email alerts for saved searches
 */

class SearchAlertService {
    private $db;
    private $articleModel;
    private $searchController;
    private $cacheService;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->articleModel = new Article();
        $this->searchController = new SearchController();
        $this->cacheService = new CacheService();
    }
    
    /**
     * Process all pending search alerts
     * This should be run via cron job (e.g., every hour)
     */
    public function processPendingAlerts() {
        try {
            // Get active saved searches with email alerts enabled
            $sql = "
                SELECT ss.*, u.email, u.username
                FROM saved_searches ss
                JOIN users u ON ss.user_id = u.id
                WHERE ss.is_active = 1 
                    AND ss.email_alerts = 1
                    AND u.status = 'active'
                    AND (ss.last_notified_at IS NULL 
                         OR ss.last_notified_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                ORDER BY ss.last_notified_at ASC
                LIMIT 100
            ";
            
            $savedSearches = Database::query($sql);
            $alertsProcessed = 0;
            
            foreach ($savedSearches as $savedSearch) {
                $newArticles = $this->findNewMatchingArticles($savedSearch);
                
                if (!empty($newArticles)) {
                    $this->queueEmailAlert($savedSearch, $newArticles);
                    $alertsProcessed++;
                }
                
                // Update last checked time
                Database::update('saved_searches', [
                    'last_notified_at' => date('Y-m-d H:i:s')
                ], ['id' => $savedSearch['id']]);
            }
            
            Logger::info('Search alerts processed', [
                'total_searches' => count($savedSearches),
                'alerts_queued' => $alertsProcessed
            ]);
            
            // Process email queue
            $this->processEmailQueue();
            
            return $alertsProcessed;
            
        } catch (Exception $e) {
            Logger::error('Failed to process search alerts', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Find new articles matching a saved search
     */
    private function findNewMatchingArticles($savedSearch) {
        try {
            // Parse search filters
            $filters = json_decode($savedSearch['filters'], true) ?: [];
            
            // Add time filter to get only new articles (since last check)
            $lastCheck = $savedSearch['last_notified_at'] ?: date('Y-m-d H:i:s', strtotime('-24 hours'));
            $filters['date_from'] = $lastCheck;
            
            // Simulate API request for search
            $searchParams = [
                'q' => $savedSearch['query'] ?: '',
                'per_page' => 50, // Limit results for email
                'sort' => 'newest',
                ...$filters
            ];
            
            // Use the same search logic as the SearchController
            if (!empty($savedSearch['query'])) {
                $results = $this->performFullTextSearchForAlerts($savedSearch['query'], $filters);
            } else {
                $results = $this->performFilteredBrowseForAlerts($filters);
            }
            
            return $results['articles'] ?? [];
            
        } catch (Exception $e) {
            Logger::error('Failed to find matching articles for saved search', [
                'search_id' => $savedSearch['id'],
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Simplified full-text search for alerts
     */
    private function performFullTextSearchForAlerts($query, $filters) {
        $searchQuery = $this->sanitizeSearchQuery($query);
        
        $sql = "
            SELECT 
                a.*,
                u.username as user_username,
                c.name as category_name,
                ai.file_path as primary_image
            FROM articles a
            JOIN users u ON a.user_id = u.id
            JOIN categories c ON a.category_id = c.id
            LEFT JOIN article_images ai ON a.id = ai.article_id AND ai.is_primary = 1
            WHERE a.status = 'active' 
                AND MATCH(a.title, a.description) AGAINST (? IN NATURAL LANGUAGE MODE)
        ";
        
        $params = [$searchQuery];
        
        // Apply filters
        list($sql, $params) = $this->applyFiltersForAlerts($sql, $params, $filters);
        
        $sql .= " ORDER BY a.created_at DESC LIMIT 50";
        
        $articles = Database::query($sql, $params);
        
        return [
            'articles' => $this->processArticlesForEmail($articles),
            'total' => count($articles)
        ];
    }
    
    /**
     * Simplified filtered browse for alerts
     */
    private function performFilteredBrowseForAlerts($filters) {
        $sql = "
            SELECT 
                a.*,
                u.username as user_username,
                c.name as category_name,
                ai.file_path as primary_image
            FROM articles a
            JOIN users u ON a.user_id = u.id
            JOIN categories c ON a.category_id = c.id
            LEFT JOIN article_images ai ON a.id = ai.article_id AND ai.is_primary = 1
            WHERE a.status = 'active'
        ";
        
        $params = [];
        
        // Apply filters
        list($sql, $params) = $this->applyFiltersForAlerts($sql, $params, $filters);
        
        $sql .= " ORDER BY a.created_at DESC LIMIT 50";
        
        $articles = Database::query($sql, $params);
        
        return [
            'articles' => $this->processArticlesForEmail($articles),
            'total' => count($articles)
        ];
    }
    
    /**
     * Apply filters for alert searches
     */
    private function applyFiltersForAlerts($sql, $params, $filters) {
        // Category filter
        if (isset($filters['category_id'])) {
            $sql .= " AND a.category_id = ?";
            $params[] = $filters['category_id'];
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
        }
        
        // Date filter (for new articles)
        if (isset($filters['date_from'])) {
            $sql .= " AND a.created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        return [$sql, $params];
    }
    
    /**
     * Process articles for email display
     */
    private function processArticlesForEmail($articles) {
        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost';
        
        foreach ($articles as &$article) {
            // Format price
            $article['formatted_price'] = number_format($article['price'], 2) . ' EUR';
            
            // Format date
            $article['created_at_human'] = $this->timeAgo($article['created_at']);
            
            // Build URLs
            $article['url'] = $baseUrl . '/articles/' . $article['id'];
            $article['image_url'] = $article['primary_image'] ? 
                $baseUrl . '/uploads/' . $article['primary_image'] : 
                $baseUrl . '/frontend/assets/images/placeholder.svg';
            
            // Truncate description for email
            if (strlen($article['description']) > 150) {
                $article['description'] = substr($article['description'], 0, 147) . '...';
            }
        }
        
        return $articles;
    }
    
    /**
     * Queue email alert for sending
     */
    private function queueEmailAlert($savedSearch, $articles) {
        $alertData = [
            'user_id' => $savedSearch['user_id'],
            'saved_search_id' => $savedSearch['id'],
            'article_ids' => json_encode(array_column($articles, 'id')),
            'status' => 'pending',
            'scheduled_at' => date('Y-m-d H:i:s')
        ];
        
        Database::insert('search_alert_queue', $alertData);
        
        Logger::info('Email alert queued', [
            'user_id' => $savedSearch['user_id'],
            'search_id' => $savedSearch['id'],
            'articles_count' => count($articles)
        ]);
    }
    
    /**
     * Process email queue
     */
    public function processEmailQueue($limit = 50) {
        // Get pending emails
        $sql = "
            SELECT 
                saq.*,
                ss.name as search_name,
                ss.query as search_query,
                u.email,
                u.username,
                u.first_name,
                u.last_name
            FROM search_alert_queue saq
            JOIN saved_searches ss ON saq.saved_search_id = ss.id
            JOIN users u ON saq.user_id = u.id
            WHERE saq.status = 'pending'
                AND saq.attempts < saq.max_attempts
                AND saq.scheduled_at <= NOW()
            ORDER BY saq.created_at ASC
            LIMIT ?
        ";
        
        $queueItems = Database::query($sql, [$limit]);
        $emailsSent = 0;
        
        foreach ($queueItems as $item) {
            try {
                // Mark as processing
                Database::update('search_alert_queue', [
                    'status' => 'processing',
                    'attempts' => $item['attempts'] + 1
                ], ['id' => $item['id']]);
                
                // Get article details
                $articleIds = json_decode($item['article_ids'], true);
                $articles = $this->getArticlesForEmail($articleIds);
                
                if (!empty($articles)) {
                    $this->sendSearchAlertEmail($item, $articles);
                    
                    // Mark as sent
                    Database::update('search_alert_queue', [
                        'status' => 'sent',
                        'sent_at' => date('Y-m-d H:i:s')
                    ], ['id' => $item['id']]);
                    
                    $emailsSent++;
                } else {
                    // No articles found, mark as completed
                    Database::update('search_alert_queue', [
                        'status' => 'sent'
                    ], ['id' => $item['id']]);
                }
                
            } catch (Exception $e) {
                Logger::error('Failed to send search alert email', [
                    'alert_id' => $item['id'],
                    'user_id' => $item['user_id'],
                    'error' => $e->getMessage()
                ]);
                
                // Check if max attempts reached
                if ($item['attempts'] + 1 >= $item['max_attempts']) {
                    Database::update('search_alert_queue', [
                        'status' => 'failed',
                        'error_message' => $e->getMessage()
                    ], ['id' => $item['id']]);
                } else {
                    // Reset status for retry
                    Database::update('search_alert_queue', [
                        'status' => 'pending',
                        'error_message' => $e->getMessage()
                    ], ['id' => $item['id']]);
                }
            }
        }
        
        Logger::info('Email queue processed', [
            'total_items' => count($queueItems),
            'emails_sent' => $emailsSent
        ]);
        
        return $emailsSent;
    }
    
    /**
     * Get article details for email
     */
    private function getArticlesForEmail($articleIds) {
        if (empty($articleIds)) {
            return [];
        }
        
        $placeholders = str_repeat('?,', count($articleIds) - 1) . '?';
        $sql = "
            SELECT 
                a.*,
                u.username as user_username,
                c.name as category_name,
                ai.file_path as primary_image
            FROM articles a
            JOIN users u ON a.user_id = u.id
            JOIN categories c ON a.category_id = c.id
            LEFT JOIN article_images ai ON a.id = ai.article_id AND ai.is_primary = 1
            WHERE a.id IN ($placeholders)
                AND a.status = 'active'
            ORDER BY a.created_at DESC
        ";
        
        $articles = Database::query($sql, $articleIds);
        return $this->processArticlesForEmail($articles);
    }
    
    /**
     * Send search alert email
     */
    private function sendSearchAlertEmail($alertItem, $articles) {
        $userName = trim($alertItem['first_name'] . ' ' . $alertItem['last_name']) ?: $alertItem['username'];
        $searchName = $alertItem['search_name'];
        $searchQuery = $alertItem['search_query'] ?: 'your saved search';
        $articlesCount = count($articles);
        
        $subject = "New items found for \"$searchName\" - Bazar Marketplace";
        
        // Build email HTML
        $emailHtml = $this->buildSearchAlertEmailHtml([
            'user_name' => $userName,
            'search_name' => $searchName,
            'search_query' => $searchQuery,
            'articles' => $articles,
            'articles_count' => $articlesCount,
            'unsubscribe_url' => $this->getUnsubscribeUrl($alertItem['saved_search_id'])
        ]);
        
        // Send email (you'll need to implement your email sending service)
        $this->sendEmail($alertItem['email'], $subject, $emailHtml);
        
        Logger::info('Search alert email sent', [
            'user_id' => $alertItem['user_id'],
            'search_name' => $searchName,
            'articles_count' => $articlesCount
        ]);
    }
    
    /**
     * Build search alert email HTML
     */
    private function buildSearchAlertEmailHtml($data) {
        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost';
        $articlesHtml = '';
        
        foreach (array_slice($data['articles'], 0, 10) as $article) {
            $articlesHtml .= "
                <div style='border: 1px solid #e1e5e9; border-radius: 8px; margin-bottom: 16px; overflow: hidden;'>
                    <div style='display: flex;'>
                        <img src='{$article['image_url']}' alt='{$article['title']}' 
                             style='width: 120px; height: 120px; object-fit: cover;' />
                        <div style='padding: 16px; flex: 1;'>
                            <h3 style='margin: 0 0 8px 0; font-size: 16px;'>
                                <a href='{$article['url']}' style='color: #007bff; text-decoration: none;'>
                                    {$article['title']}
                                </a>
                            </h3>
                            <p style='margin: 0 0 8px 0; color: #666; font-size: 14px;'>
                                {$article['description']}
                            </p>
                            <div style='display: flex; justify-content: space-between; align-items: center;'>
                                <strong style='color: #007bff; font-size: 18px;'>
                                    {$article['formatted_price']}
                                </strong>
                                <span style='color: #666; font-size: 12px;'>
                                    {$article['created_at_human']}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            ";
        }
        
        $moreCount = count($data['articles']) - 10;
        $moreHtml = $moreCount > 0 ? 
            "<p style='text-align: center; margin: 20px 0;'>
                <a href='{$baseUrl}/search?q=" . urlencode($data['search_query']) . "' 
                   style='color: #007bff; text-decoration: none;'>
                    View {$moreCount} more results →
                </a>
            </p>" : '';
        
        return "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>{$subject}</title>
            </head>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <header style='text-align: center; margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px;'>
                    <h1 style='color: #007bff; margin: 0;'>Bazar Marketplace</h1>
                </header>
                
                <div style='margin-bottom: 30px;'>
                    <h2 style='color: #333; margin-bottom: 16px;'>
                        Hi {$data['user_name']},
                    </h2>
                    <p style='margin-bottom: 16px;'>
                        We found <strong>{$data['articles_count']} new items</strong> matching your saved search 
                        <strong>\"{$data['search_name']}\"</strong>.
                    </p>
                </div>
                
                <div style='margin-bottom: 30px;'>
                    <h3 style='color: #333; margin-bottom: 20px;'>New Listings:</h3>
                    {$articlesHtml}
                    {$moreHtml}
                </div>
                
                <footer style='border-top: 1px solid #eee; padding-top: 20px; text-align: center; color: #666; font-size: 12px;'>
                    <p>
                        <a href='{$baseUrl}/search/saved' style='color: #007bff;'>Manage your saved searches</a> |
                        <a href='{$data['unsubscribe_url']}' style='color: #666;'>Unsubscribe from this alert</a>
                    </p>
                    <p>© " . date('Y') . " Bazar Marketplace. All rights reserved.</p>
                </footer>
            </body>
            </html>
        ";
    }
    
    /**
     * Get unsubscribe URL
     */
    private function getUnsubscribeUrl($savedSearchId) {
        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost';
        $token = md5($savedSearchId . $_ENV['JWT_SECRET']);
        return "{$baseUrl}/unsubscribe?search={$savedSearchId}&token={$token}";
    }
    
    /**
     * Send email (implement with your preferred email service)
     */
    private function sendEmail($to, $subject, $htmlBody) {
        // This is a placeholder - implement with your email service
        // Examples: PHPMailer, SendGrid, AWS SES, etc.
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: Bazar Marketplace <noreply@bazar.com>',
            'Reply-To: support@bazar.com',
            'X-Mailer: PHP/' . phpversion()
        ];
        
        return mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    }
    
    /**
     * Sanitize search query for fulltext search
     */
    private function sanitizeSearchQuery($query) {
        // Remove special characters that can break fulltext search
        $query = preg_replace('/[+\-><\(\)~*\"@]/', ' ', $query);
        
        // Remove extra spaces
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        return $query;
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
        
        return 'vor ' . floor($time / 86400) . ' Tagen';
    }
    
    /**
     * Clean up old processed alerts
     */
    public function cleanupOldAlerts($days = 30) {
        $sql = "DELETE FROM search_alert_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $stmt = $this->db->prepare($sql);
        $deleted = $stmt->execute([$days]);
        
        Logger::info('Old search alerts cleaned up', ['deleted' => $deleted]);
        
        return $deleted;
    }
}