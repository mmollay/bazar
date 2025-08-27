<?php
/**
 * Admin Article Management Controller
 * Handles article moderation, approval, and content management
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class AdminArticleController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get articles with filtering and moderation status
     */
    public function getArticles() {
        try {
            AdminMiddleware::handle();
            
            $limit = Request::get('limit', 20);
            $offset = Request::get('offset', 0);
            $search = Request::get('search', '');
            $status = Request::get('status', '');
            $category = Request::get('category', '');
            $sortBy = Request::get('sort_by', 'created_at');
            $sortOrder = Request::get('sort_order', 'DESC');
            $flagged = Request::get('flagged', '');
            $aiGenerated = Request::get('ai_generated', '');
            
            // Build WHERE clause
            $whereConditions = [];
            $params = [];
            
            if (!empty($search)) {
                $whereConditions[] = "(a.title LIKE ? OR a.description LIKE ?)";
                $searchTerm = "%{$search}%";
                $params = array_merge($params, [$searchTerm, $searchTerm]);
            }
            
            if (!empty($status)) {
                $whereConditions[] = "a.status = ?";
                $params[] = $status;
            }
            
            if (!empty($category)) {
                $whereConditions[] = "a.category_id = ?";
                $params[] = $category;
            }
            
            if ($flagged !== '') {
                if ($flagged) {
                    $whereConditions[] = "a.id IN (SELECT DISTINCT reported_article_id FROM user_reports WHERE status = 'pending')";
                } else {
                    $whereConditions[] = "a.id NOT IN (SELECT DISTINCT reported_article_id FROM user_reports WHERE status = 'pending')";
                }
            }
            
            if ($aiGenerated !== '') {
                $whereConditions[] = "a.ai_generated = ?";
                $params[] = $aiGenerated ? 1 : 0;
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            // Validate sort parameters
            $allowedSorts = ['id', 'title', 'price', 'created_at', 'updated_at', 'view_count', 'status'];
            $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'created_at';
            $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            
            // Get articles
            $stmt = $this->db->prepare("
                SELECT 
                    a.*,
                    u.username,
                    u.first_name,
                    u.last_name,
                    c.name as category_name,
                    (SELECT COUNT(*) FROM user_reports WHERE reported_article_id = a.id AND status = 'pending') as pending_reports,
                    (SELECT filename FROM article_images WHERE article_id = a.id AND is_primary = TRUE LIMIT 1) as primary_image
                FROM articles a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN categories c ON a.category_id = c.id
                {$whereClause}
                ORDER BY a.{$sortBy} {$sortOrder}
                LIMIT ? OFFSET ?
            ");
            
            $params = array_merge($params, [$limit, $offset]);
            $stmt->execute($params);
            $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) FROM articles a {$whereClause}
            ");
            $countStmt->execute(array_slice($params, 0, -2));
            $total = $countStmt->fetchColumn();
            
            Response::success([
                'articles' => $articles,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting articles: ' . $e->getMessage());
            Response::serverError('Failed to load articles');
        }
    }
    
    /**
     * Get article details with full information
     */
    public function getArticleDetails() {
        try {
            AdminMiddleware::handle();
            
            $articleId = Request::get('id');
            if (!$articleId) {
                Response::error('Article ID is required');
            }
            
            // Get article details
            $stmt = $this->db->prepare("
                SELECT 
                    a.*,
                    u.username,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.rating,
                    c.name as category_name
                FROM articles a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN categories c ON a.category_id = c.id
                WHERE a.id = ?
            ");
            $stmt->execute([$articleId]);
            $article = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$article) {
                Response::notFound('Article not found');
            }
            
            // Get article images
            $stmt = $this->db->prepare("
                SELECT 
                    filename, file_path, is_primary, sort_order,
                    ai_analyzed, ai_objects, ai_labels, ai_text,
                    ai_explicit_content
                FROM article_images 
                WHERE article_id = ?
                ORDER BY sort_order ASC
            ");
            $stmt->execute([$articleId]);
            $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get AI suggestions
            $stmt = $this->db->prepare("
                SELECT 
                    suggestion_type, original_value, suggested_value,
                    confidence_score, is_accepted
                FROM ai_suggestions 
                WHERE article_id = ?
                ORDER BY confidence_score DESC
            ");
            $stmt->execute([$articleId]);
            $aiSuggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get reports for this article
            $stmt = $this->db->prepare("
                SELECT 
                    ur.*,
                    u.username as reporter_username
                FROM user_reports ur
                LEFT JOIN users u ON ur.reporter_id = u.id
                WHERE ur.reported_article_id = ?
                ORDER BY ur.created_at DESC
            ");
            $stmt->execute([$articleId]);
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get similar articles (by category and price range)
            $priceMin = $article['price'] * 0.8;
            $priceMax = $article['price'] * 1.2;
            
            $stmt = $this->db->prepare("
                SELECT id, title, price, status, created_at
                FROM articles 
                WHERE category_id = ? 
                AND price BETWEEN ? AND ?
                AND id != ?
                AND status = 'active'
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$article['category_id'], $priceMin, $priceMax, $articleId]);
            $similarArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success([
                'article' => $article,
                'images' => $images,
                'ai_suggestions' => $aiSuggestions,
                'reports' => $reports,
                'similar_articles' => $similarArticles
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting article details: ' . $e->getMessage());
            Response::serverError('Failed to load article details');
        }
    }
    
    /**
     * Moderate article (approve, reject, feature)
     */
    public function moderateArticle() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'article_id' => 'required',
                'action' => 'required|in:approve,reject,feature,unfeature,archive',
                'reason' => ''
            ]);
            
            $articleId = $data['article_id'];
            $action = $data['action'];
            $reason = $data['reason'] ?? '';
            
            // Get article
            $stmt = $this->db->prepare("
                SELECT a.*, u.email, u.first_name 
                FROM articles a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.id = ?
            ");
            $stmt->execute([$articleId]);
            $article = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$article) {
                Response::notFound('Article not found');
            }
            
            // Perform moderation action
            switch ($action) {
                case 'approve':
                    $stmt = $this->db->prepare("UPDATE articles SET status = 'active' WHERE id = ?");
                    $stmt->execute([$articleId]);
                    
                    // Send approval email
                    $this->sendModerationEmail($article, 'approved', $reason);
                    break;
                    
                case 'reject':
                    $stmt = $this->db->prepare("UPDATE articles SET status = 'moderated' WHERE id = ?");
                    $stmt->execute([$articleId]);
                    
                    // Send rejection email
                    $this->sendModerationEmail($article, 'rejected', $reason);
                    break;
                    
                case 'feature':
                    $stmt = $this->db->prepare("UPDATE articles SET is_featured = TRUE WHERE id = ?");
                    $stmt->execute([$articleId]);
                    break;
                    
                case 'unfeature':
                    $stmt = $this->db->prepare("UPDATE articles SET is_featured = FALSE WHERE id = ?");
                    $stmt->execute([$articleId]);
                    break;
                    
                case 'archive':
                    $stmt = $this->db->prepare("UPDATE articles SET status = 'archived' WHERE id = ?");
                    $stmt->execute([$articleId]);
                    break;
            }
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                "article_{$action}",
                'articles',
                $articleId,
                "Article {$action}: {$article['title']}" . ($reason ? ". Reason: {$reason}" : '')
            );
            
            Response::success(['message' => "Article {$action} successfully"]);
            
        } catch (Exception $e) {
            Logger::error('Error moderating article: ' . $e->getMessage());
            Response::serverError('Failed to moderate article');
        }
    }
    
    /**
     * Bulk operations on articles
     */
    public function bulkOperations() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'article_ids' => 'required',
                'operation' => 'required|in:approve,reject,feature,unfeature,archive',
                'reason' => ''
            ]);
            
            if (!is_array($data['article_ids']) || empty($data['article_ids'])) {
                Response::error('Article IDs must be a non-empty array');
            }
            
            $successCount = 0;
            $errors = [];
            
            foreach ($data['article_ids'] as $articleId) {
                try {
                    // Get article
                    $stmt = $this->db->prepare("SELECT * FROM articles WHERE id = ?");
                    $stmt->execute([$articleId]);
                    $article = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$article) {
                        $errors[] = "Article ID {$articleId} not found";
                        continue;
                    }
                    
                    // Perform operation
                    switch ($data['operation']) {
                        case 'approve':
                            $stmt = $this->db->prepare("UPDATE articles SET status = 'active' WHERE id = ?");
                            $stmt->execute([$articleId]);
                            break;
                            
                        case 'reject':
                            $stmt = $this->db->prepare("UPDATE articles SET status = 'moderated' WHERE id = ?");
                            $stmt->execute([$articleId]);
                            break;
                            
                        case 'feature':
                            $stmt = $this->db->prepare("UPDATE articles SET is_featured = TRUE WHERE id = ?");
                            $stmt->execute([$articleId]);
                            break;
                            
                        case 'unfeature':
                            $stmt = $this->db->prepare("UPDATE articles SET is_featured = FALSE WHERE id = ?");
                            $stmt->execute([$articleId]);
                            break;
                            
                        case 'archive':
                            $stmt = $this->db->prepare("UPDATE articles SET status = 'archived' WHERE id = ?");
                            $stmt->execute([$articleId]);
                            break;
                    }
                    
                    // Log the action
                    $this->logAdminAction(
                        $currentUser['id'],
                        "bulk_{$data['operation']}_article",
                        'articles',
                        $articleId,
                        "Bulk {$data['operation']} operation" . ($data['reason'] ? ". Reason: {$data['reason']}" : '')
                    );
                    
                    $successCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "Error processing article ID {$articleId}: " . $e->getMessage();
                }
            }
            
            Response::success([
                'message' => "Bulk operation completed. {$successCount} articles processed successfully.",
                'success_count' => $successCount,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error in bulk operations: ' . $e->getMessage());
            Response::serverError('Failed to perform bulk operations');
        }
    }
    
    /**
     * Get moderation queue (articles needing review)
     */
    public function getModerationQueue() {
        try {
            AdminMiddleware::handle();
            
            $limit = Request::get('limit', 20);
            $offset = Request::get('offset', 0);
            $priority = Request::get('priority', 'all'); // all, high, medium, low
            
            $whereConditions = ["a.status = 'draft'"];
            $params = [];
            
            // Add priority filtering
            if ($priority !== 'all') {
                switch ($priority) {
                    case 'high':
                        $whereConditions[] = "(reports.report_count > 2 OR a.ai_confidence_score < 0.7)";
                        break;
                    case 'medium':
                        $whereConditions[] = "(reports.report_count = 1 OR reports.report_count = 2)";
                        break;
                    case 'low':
                        $whereConditions[] = "(reports.report_count = 0 OR reports.report_count IS NULL)";
                        break;
                }
            }
            
            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
            
            $stmt = $this->db->prepare("
                SELECT 
                    a.*,
                    u.username,
                    u.first_name,
                    u.last_name,
                    c.name as category_name,
                    COALESCE(reports.report_count, 0) as report_count,
                    (SELECT filename FROM article_images WHERE article_id = a.id AND is_primary = TRUE LIMIT 1) as primary_image,
                    CASE 
                        WHEN reports.report_count > 2 THEN 'high'
                        WHEN reports.report_count > 0 THEN 'medium'
                        WHEN a.ai_confidence_score < 0.7 THEN 'high'
                        ELSE 'low'
                    END as priority
                FROM articles a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN categories c ON a.category_id = c.id
                LEFT JOIN (
                    SELECT reported_article_id, COUNT(*) as report_count
                    FROM user_reports 
                    WHERE status = 'pending'
                    GROUP BY reported_article_id
                ) reports ON a.id = reports.reported_article_id
                {$whereClause}
                ORDER BY 
                    CASE 
                        WHEN reports.report_count > 2 THEN 1
                        WHEN a.ai_confidence_score < 0.7 THEN 2
                        WHEN reports.report_count > 0 THEN 3
                        ELSE 4
                    END,
                    a.created_at ASC
                LIMIT ? OFFSET ?
            ");
            
            $params = array_merge($params, [$limit, $offset]);
            $stmt->execute($params);
            $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) FROM articles a
                LEFT JOIN (
                    SELECT reported_article_id, COUNT(*) as report_count
                    FROM user_reports 
                    WHERE status = 'pending'
                    GROUP BY reported_article_id
                ) reports ON a.id = reports.reported_article_id
                {$whereClause}
            ");
            $countStmt->execute(array_slice($params, 0, -2));
            $total = $countStmt->fetchColumn();
            
            Response::success([
                'articles' => $articles,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting moderation queue: ' . $e->getMessage());
            Response::serverError('Failed to load moderation queue');
        }
    }
    
    /**
     * Get article statistics
     */
    public function getArticleStatistics() {
        try {
            AdminMiddleware::handle();
            
            // Basic statistics
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_articles,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_articles,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as pending_articles,
                    SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_articles,
                    SUM(CASE WHEN is_featured = TRUE THEN 1 ELSE 0 END) as featured_articles,
                    SUM(CASE WHEN ai_generated = TRUE THEN 1 ELSE 0 END) as ai_generated_articles
                FROM articles
            ");
            $stmt->execute();
            $basicStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Category distribution
            $stmt = $this->db->prepare("
                SELECT 
                    c.name,
                    COUNT(a.id) as article_count
                FROM categories c
                LEFT JOIN articles a ON c.id = a.category_id AND a.status != 'archived'
                GROUP BY c.id, c.name
                ORDER BY article_count DESC
            ");
            $stmt->execute();
            $categoryStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Price distribution
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(CASE WHEN price <= 10 THEN 1 END) as under_10,
                    COUNT(CASE WHEN price BETWEEN 10.01 AND 50 THEN 1 END) as between_10_50,
                    COUNT(CASE WHEN price BETWEEN 50.01 AND 100 THEN 1 END) as between_50_100,
                    COUNT(CASE WHEN price BETWEEN 100.01 AND 500 THEN 1 END) as between_100_500,
                    COUNT(CASE WHEN price > 500 THEN 1 END) as over_500
                FROM articles 
                WHERE status = 'active'
            ");
            $stmt->execute();
            $priceStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Recent trends (last 30 days)
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count
                FROM articles 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->execute();
            $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success([
                'basic_stats' => $basicStats,
                'category_distribution' => $categoryStats,
                'price_distribution' => $priceStats,
                'trends' => $trends
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting article statistics: ' . $e->getMessage());
            Response::serverError('Failed to load article statistics');
        }
    }
    
    /**
     * Send moderation email to user
     */
    private function sendModerationEmail($article, $action, $reason) {
        try {
            $templateName = $action === 'approved' ? 'article_approved' : 'article_rejected';
            
            // Get email template
            $stmt = $this->db->prepare("SELECT * FROM email_templates WHERE name = ? AND is_active = TRUE");
            $stmt->execute([$templateName]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                Logger::warning("Email template '{$templateName}' not found");
                return;
            }
            
            // Replace template variables
            $variables = [
                'first_name' => $article['first_name'],
                'article_title' => $article['title'],
                'article_url' => "http://example.com/article/{$article['id']}",
                'rejection_reason' => $reason
            ];
            
            $subject = $template['subject'];
            $body = $template['body'];
            
            foreach ($variables as $key => $value) {
                $subject = str_replace("{{{$key}}}", $value, $subject);
                $body = str_replace("{{{$key}}}", $value, $body);
            }
            
            // In a real implementation, you would send the email here
            Logger::info("Moderation email", [
                'to' => $article['email'],
                'subject' => $subject,
                'action' => $action
            ]);
            
        } catch (Exception $e) {
            Logger::error('Failed to send moderation email: ' . $e->getMessage());
        }
    }
    
    /**
     * Log admin action
     */
    private function logAdminAction($adminId, $action, $targetType = null, $targetId = null, $description = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO admin_logs (admin_id, action, target_type, target_id, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $adminId,
                $action,
                $targetType,
                $targetId,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
        } catch (Exception $e) {
            Logger::error('Failed to log admin action: ' . $e->getMessage());
        }
    }
}