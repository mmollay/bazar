<?php
/**
 * Admin Analytics Controller
 * Handles financial reports, analytics, and data export
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class AdminAnalyticsController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get analytics overview
     */
    public function getOverview() {
        try {
            AdminMiddleware::handle();
            
            $period = Request::get('period', '30d'); // 7d, 30d, 90d, 1y
            $endDate = date('Y-m-d H:i:s');
            $startDate = $this->getStartDate($period);
            
            // Key metrics
            $metrics = [];
            
            // User metrics
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_users,
                    SUM(CASE WHEN last_login_at >= ? THEN 1 ELSE 0 END) as active_users
                FROM users 
                WHERE status != 'deleted'
            ");
            $stmt->execute([$startDate, $startDate]);
            $metrics['users'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Article metrics
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_articles,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_articles,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_articles,
                    SUM(CASE WHEN status = 'sold' AND updated_at >= ? THEN 1 ELSE 0 END) as sold_articles,
                    AVG(CASE WHEN status = 'active' THEN view_count ELSE NULL END) as avg_views
                FROM articles
            ");
            $stmt->execute([$startDate, $startDate]);
            $metrics['articles'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Revenue metrics (if applicable)
            $metrics['revenue'] = [
                'featured_articles' => $this->getFeaturedArticleRevenue($startDate, $endDate),
                'total_gmv' => $this->getGMV($startDate, $endDate) // Gross Merchandise Value
            ];
            
            // Growth trends
            $trends = $this->getGrowthTrends($period);
            
            // Top categories
            $topCategories = $this->getTopCategories($startDate, $endDate);
            
            // Geographic distribution
            $geoData = $this->getGeographicDistribution($startDate, $endDate);
            
            Response::success([
                'metrics' => $metrics,
                'trends' => $trends,
                'top_categories' => $topCategories,
                'geographic_data' => $geoData
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting analytics overview: ' . $e->getMessage());
            Response::serverError('Failed to load analytics overview');
        }
    }
    
    /**
     * Get user analytics
     */
    public function getUserAnalytics() {
        try {
            AdminMiddleware::handle();
            
            $period = Request::get('period', '30d');
            $startDate = $this->getStartDate($period);
            
            // User registration trends
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as registrations
                FROM users 
                WHERE created_at >= ? AND status != 'deleted'
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$startDate]);
            $registrationTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // User activity
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(last_login_at) as date,
                    COUNT(DISTINCT id) as active_users
                FROM users 
                WHERE last_login_at >= ? AND status = 'active'
                GROUP BY DATE(last_login_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$startDate]);
            $activityTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // User engagement metrics
            $stmt = $this->db->prepare("
                SELECT 
                    AVG(article_count) as avg_articles_per_user,
                    AVG(message_count) as avg_messages_per_user
                FROM (
                    SELECT 
                        u.id,
                        COUNT(DISTINCT a.id) as article_count,
                        COUNT(DISTINCT m.id) as message_count
                    FROM users u
                    LEFT JOIN articles a ON u.id = a.user_id AND a.created_at >= ?
                    LEFT JOIN messages m ON u.id = m.sender_id AND m.created_at >= ?
                    WHERE u.status = 'active' AND u.created_at >= ?
                    GROUP BY u.id
                ) as user_engagement
            ");
            $stmt->execute([$startDate, $startDate, $startDate]);
            $engagement = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // User demographics
            $demographics = $this->getUserDemographics($startDate);
            
            // Retention analysis
            $retention = $this->getUserRetention($period);
            
            Response::success([
                'registration_trends' => $registrationTrends,
                'activity_trends' => $activityTrends,
                'engagement' => $engagement,
                'demographics' => $demographics,
                'retention' => $retention
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting user analytics: ' . $e->getMessage());
            Response::serverError('Failed to load user analytics');
        }
    }
    
    /**
     * Get article analytics
     */
    public function getArticleAnalytics() {
        try {
            AdminMiddleware::handle();
            
            $period = Request::get('period', '30d');
            $startDate = $this->getStartDate($period);
            
            // Article creation trends
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as articles_created,
                    COUNT(CASE WHEN ai_generated = TRUE THEN 1 END) as ai_generated
                FROM articles 
                WHERE created_at >= ?
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$startDate]);
            $creationTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Article performance
            $stmt = $this->db->prepare("
                SELECT 
                    AVG(view_count) as avg_views,
                    AVG(favorite_count) as avg_favorites,
                    MAX(view_count) as max_views,
                    COUNT(CASE WHEN view_count > 100 THEN 1 END) as high_view_articles
                FROM articles 
                WHERE created_at >= ? AND status = 'active'
            ");
            $stmt->execute([$startDate]);
            $performance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Category performance
            $stmt = $this->db->prepare("
                SELECT 
                    c.name,
                    COUNT(a.id) as article_count,
                    AVG(a.view_count) as avg_views,
                    AVG(a.price) as avg_price,
                    SUM(CASE WHEN a.status = 'sold' THEN 1 ELSE 0 END) as sold_count
                FROM categories c
                LEFT JOIN articles a ON c.id = a.category_id AND a.created_at >= ?
                GROUP BY c.id, c.name
                HAVING article_count > 0
                ORDER BY article_count DESC
            ");
            $stmt->execute([$startDate]);
            $categoryPerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Price analysis
            $priceAnalysis = $this->getPriceAnalysis($startDate);
            
            // Conversion funnel
            $conversionFunnel = $this->getConversionFunnel($startDate);
            
            Response::success([
                'creation_trends' => $creationTrends,
                'performance' => $performance,
                'category_performance' => $categoryPerformance,
                'price_analysis' => $priceAnalysis,
                'conversion_funnel' => $conversionFunnel
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting article analytics: ' . $e->getMessage());
            Response::serverError('Failed to load article analytics');
        }
    }
    
    /**
     * Get financial analytics
     */
    public function getFinancialAnalytics() {
        try {
            AdminMiddleware::handle();
            
            $period = Request::get('period', '30d');
            $startDate = $this->getStartDate($period);
            
            // Revenue from featured articles
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(updated_at) as date,
                    COUNT(*) as featured_count,
                    (COUNT(*) * (SELECT CAST(setting_value AS DECIMAL(10,2)) FROM system_settings WHERE setting_key = 'featured_article_price')) as revenue
                FROM articles 
                WHERE is_featured = TRUE AND updated_at >= ?
                GROUP BY DATE(updated_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$startDate]);
            $featuredRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // GMV (Gross Merchandise Value) - estimated from sold articles
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(updated_at) as date,
                    COUNT(*) as items_sold,
                    SUM(price) as gmv,
                    AVG(price) as avg_sale_price
                FROM articles 
                WHERE status = 'sold' AND updated_at >= ?
                GROUP BY DATE(updated_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$startDate]);
            $gmvTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Category revenue breakdown
            $stmt = $this->db->prepare("
                SELECT 
                    c.name,
                    COUNT(a.id) as sold_items,
                    SUM(a.price) as category_gmv,
                    AVG(a.price) as avg_price
                FROM categories c
                LEFT JOIN articles a ON c.id = a.category_id 
                WHERE a.status = 'sold' AND a.updated_at >= ?
                GROUP BY c.id, c.name
                HAVING sold_items > 0
                ORDER BY category_gmv DESC
            ");
            $stmt->execute([$startDate]);
            $categoryRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Financial summary
            $totalFeaturedRevenue = array_sum(array_column($featuredRevenue, 'revenue'));
            $totalGMV = array_sum(array_column($gmvTrends, 'gmv'));
            $totalItemsSold = array_sum(array_column($gmvTrends, 'items_sold'));
            
            $financialSummary = [
                'total_featured_revenue' => $totalFeaturedRevenue,
                'total_gmv' => $totalGMV,
                'total_items_sold' => $totalItemsSold,
                'avg_transaction_value' => $totalItemsSold > 0 ? $totalGMV / $totalItemsSold : 0,
                'commission_estimate' => $totalGMV * 0.05 // Assuming 5% commission
            ];
            
            Response::success([
                'summary' => $financialSummary,
                'featured_revenue' => $featuredRevenue,
                'gmv_trends' => $gmvTrends,
                'category_revenue' => $categoryRevenue
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting financial analytics: ' . $e->getMessage());
            Response::serverError('Failed to load financial analytics');
        }
    }
    
    /**
     * Export analytics data
     */
    public function exportData() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'type' => 'required|in:users,articles,reports,financial',
                'format' => 'required|in:csv,json',
                'period' => 'in:7d,30d,90d,1y'
            ]);
            
            $period = $data['period'] ?? '30d';
            $startDate = $this->getStartDate($period);
            $endDate = date('Y-m-d H:i:s');
            
            $exportData = [];
            $filename = '';
            
            switch ($data['type']) {
                case 'users':
                    $exportData = $this->exportUserData($startDate, $endDate);
                    $filename = "users_export_" . date('Y-m-d');
                    break;
                    
                case 'articles':
                    $exportData = $this->exportArticleData($startDate, $endDate);
                    $filename = "articles_export_" . date('Y-m-d');
                    break;
                    
                case 'reports':
                    $exportData = $this->exportReportData($startDate, $endDate);
                    $filename = "reports_export_" . date('Y-m-d');
                    break;
                    
                case 'financial':
                    $exportData = $this->exportFinancialData($startDate, $endDate);
                    $filename = "financial_export_" . date('Y-m-d');
                    break;
            }
            
            // Log the export
            $this->logAdminAction(
                $currentUser['id'],
                'export_data',
                null,
                null,
                "Exported {$data['type']} data in {$data['format']} format for period {$period}"
            );
            
            if ($data['format'] === 'csv') {
                $this->outputCSV($exportData, $filename);
            } else {
                Response::success([
                    'data' => $exportData,
                    'filename' => $filename . '.json',
                    'count' => count($exportData)
                ]);
            }
            
        } catch (Exception $e) {
            Logger::error('Error exporting data: ' . $e->getMessage());
            Response::serverError('Failed to export data');
        }
    }
    
    /**
     * Get start date based on period
     */
    private function getStartDate($period) {
        switch ($period) {
            case '7d':
                return date('Y-m-d H:i:s', strtotime('-7 days'));
            case '90d':
                return date('Y-m-d H:i:s', strtotime('-90 days'));
            case '1y':
                return date('Y-m-d H:i:s', strtotime('-1 year'));
            case '30d':
            default:
                return date('Y-m-d H:i:s', strtotime('-30 days'));
        }
    }
    
    /**
     * Get growth trends
     */
    private function getGrowthTrends($period) {
        $currentStart = $this->getStartDate($period);
        
        // Calculate previous period for comparison
        $daysDiff = (strtotime('now') - strtotime($currentStart)) / 86400;
        $previousStart = date('Y-m-d H:i:s', strtotime($currentStart) - ($daysDiff * 86400));
        
        // Current period metrics
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN u.created_at >= ? THEN u.id END) as new_users,
                COUNT(DISTINCT CASE WHEN a.created_at >= ? THEN a.id END) as new_articles
            FROM users u, articles a
            WHERE u.status != 'deleted'
        ");
        $stmt->execute([$currentStart, $currentStart]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Previous period metrics
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN u.created_at BETWEEN ? AND ? THEN u.id END) as new_users,
                COUNT(DISTINCT CASE WHEN a.created_at BETWEEN ? AND ? THEN a.id END) as new_articles
            FROM users u, articles a
            WHERE u.status != 'deleted'
        ");
        $stmt->execute([$previousStart, $currentStart, $previousStart, $currentStart]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'users_growth' => $this->calculateGrowthRate($current['new_users'], $previous['new_users']),
            'articles_growth' => $this->calculateGrowthRate($current['new_articles'], $previous['new_articles'])
        ];
    }
    
    /**
     * Calculate growth rate percentage
     */
    private function calculateGrowthRate($current, $previous) {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }
    
    /**
     * Get top categories
     */
    private function getTopCategories($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                c.name,
                COUNT(a.id) as article_count,
                AVG(a.view_count) as avg_views
            FROM categories c
            LEFT JOIN articles a ON c.id = a.category_id 
                AND a.created_at BETWEEN ? AND ?
            GROUP BY c.id, c.name
            ORDER BY article_count DESC
            LIMIT 10
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get geographic distribution
     */
    private function getGeographicDistribution($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                LEFT(location, LOCATE(',', location) - 1) as city,
                COUNT(*) as article_count
            FROM articles 
            WHERE location IS NOT NULL 
                AND location != '' 
                AND created_at BETWEEN ? AND ?
            GROUP BY city
            HAVING city != ''
            ORDER BY article_count DESC
            LIMIT 20
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Output CSV file
     */
    private function outputCSV($data, $filename) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        if (!empty($data)) {
            // Write header
            fputcsv($output, array_keys($data[0]));
            
            // Write data
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Export user data
     */
    private function exportUserData($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                id, username, email, first_name, last_name,
                is_verified, rating, status, created_at,
                (SELECT COUNT(*) FROM articles WHERE user_id = users.id) as article_count,
                (SELECT COUNT(*) FROM messages WHERE sender_id = users.id) as messages_sent
            FROM users 
            WHERE created_at BETWEEN ? AND ?
                AND status != 'deleted'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Export article data
     */
    private function exportArticleData($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                a.id, a.title, a.price, a.status, a.view_count,
                a.created_at, u.username, c.name as category
            FROM articles a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.created_at BETWEEN ? AND ?
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Export report data
     */
    private function exportReportData($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                ur.id, ur.report_type, ur.status, ur.created_at,
                reporter.username as reporter, reported.username as reported_user
            FROM user_reports ur
            LEFT JOIN users reporter ON ur.reporter_id = reporter.id
            LEFT JOIN users reported ON ur.reported_user_id = reported.id
            WHERE ur.created_at BETWEEN ? AND ?
            ORDER BY ur.created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Export financial data
     */
    private function exportFinancialData($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                DATE(updated_at) as date,
                'featured_article' as type,
                COUNT(*) as count,
                (COUNT(*) * (SELECT CAST(setting_value AS DECIMAL(10,2)) FROM system_settings WHERE setting_key = 'featured_article_price')) as revenue
            FROM articles 
            WHERE is_featured = TRUE AND updated_at BETWEEN ? AND ?
            GROUP BY DATE(updated_at)
            ORDER BY date DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get featured article revenue
     */
    private function getFeaturedArticleRevenue($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as featured_count,
                (COUNT(*) * (SELECT CAST(setting_value AS DECIMAL(10,2)) FROM system_settings WHERE setting_key = 'featured_article_price')) as revenue
            FROM articles 
            WHERE is_featured = TRUE AND updated_at BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get GMV (Gross Merchandise Value)
     */
    private function getGMV($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as sold_items,
                SUM(price) as gmv
            FROM articles 
            WHERE status = 'sold' AND updated_at BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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