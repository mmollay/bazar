<?php
/**
 * Admin Controller
 * Handles admin dashboard and general admin operations
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class AdminController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats() {
        try {
            // Get today's date
            $today = date('Y-m-d');
            
            // Get or create today's statistics
            $stmt = $this->db->prepare("
                SELECT * FROM system_statistics 
                WHERE stat_date = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$today]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no stats for today, calculate them
            if (!$stats) {
                $this->calculateDailyStats($today);
                $stmt->execute([$today]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Get additional real-time data
            $realTimeStats = $this->getRealTimeStats();
            
            // Get weekly trends
            $weeklyTrends = $this->getWeeklyTrends();
            
            // Get recent activity
            $recentActivity = $this->getRecentActivity();
            
            // Get system health metrics
            $systemHealth = $this->getSystemHealth();
            
            Response::success([
                'daily_stats' => $stats,
                'realtime' => $realTimeStats,
                'weekly_trends' => $weeklyTrends,
                'recent_activity' => $recentActivity,
                'system_health' => $systemHealth
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting dashboard stats: ' . $e->getMessage());
            Response::serverError('Failed to load dashboard statistics');
        }
    }
    
    /**
     * Calculate and cache daily statistics
     */
    private function calculateDailyStats($date) {
        $startOfDay = $date . ' 00:00:00';
        $endOfDay = $date . ' 23:59:59';
        
        // Total users
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE status != 'deleted'");
        $stmt->execute();
        $totalUsers = $stmt->fetchColumn();
        
        // Active users (logged in within last 30 days)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM users 
            WHERE last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
            AND status = 'active'
        ");
        $stmt->execute();
        $activeUsers = $stmt->fetchColumn();
        
        // New users today
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM users 
            WHERE created_at BETWEEN ? AND ?
        ");
        $stmt->execute([$startOfDay, $endOfDay]);
        $newUsersToday = $stmt->fetchColumn();
        
        // Total articles
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM articles WHERE status != 'archived'");
        $stmt->execute();
        $totalArticles = $stmt->fetchColumn();
        
        // Active articles
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM articles WHERE status = 'active'");
        $stmt->execute();
        $activeArticles = $stmt->fetchColumn();
        
        // New articles today
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM articles 
            WHERE created_at BETWEEN ? AND ?
        ");
        $stmt->execute([$startOfDay, $endOfDay]);
        $newArticlesToday = $stmt->fetchColumn();
        
        // Total messages
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM messages");
        $stmt->execute();
        $totalMessages = $stmt->fetchColumn();
        
        // New messages today
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM messages 
            WHERE created_at BETWEEN ? AND ?
        ");
        $stmt->execute([$startOfDay, $endOfDay]);
        $newMessagesToday = $stmt->fetchColumn();
        
        // Pending reports
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_reports WHERE status = 'pending'");
        $stmt->execute();
        $pendingReports = $stmt->fetchColumn();
        
        // Insert or update statistics
        $stmt = $this->db->prepare("
            INSERT INTO system_statistics (
                stat_date, total_users, active_users, new_users_today,
                total_articles, active_articles, new_articles_today,
                total_messages, new_messages_today, pending_reports
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_users = VALUES(total_users),
                active_users = VALUES(active_users),
                new_users_today = VALUES(new_users_today),
                total_articles = VALUES(total_articles),
                active_articles = VALUES(active_articles),
                new_articles_today = VALUES(new_articles_today),
                total_messages = VALUES(total_messages),
                new_messages_today = VALUES(new_messages_today),
                pending_reports = VALUES(pending_reports)
        ");
        
        $stmt->execute([
            $date, $totalUsers, $activeUsers, $newUsersToday,
            $totalArticles, $activeArticles, $newArticlesToday,
            $totalMessages, $newMessagesToday, $pendingReports
        ]);
    }
    
    /**
     * Get real-time statistics
     */
    private function getRealTimeStats() {
        $stats = [];
        
        // Online users (active in last 15 minutes)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM admin_sessions 
            WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            AND expires_at > NOW()
        ");
        $stmt->execute();
        $stats['online_admins'] = $stmt->fetchColumn();
        
        // Processing queue status
        $stmt = $this->db->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM ai_processing_queue 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY status
        ");
        $stmt->execute();
        $queueStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['ai_queue'] = array_column($queueStatus, 'count', 'status');
        
        return $stats;
    }
    
    /**
     * Get weekly trends
     */
    private function getWeeklyTrends() {
        $stmt = $this->db->prepare("
            SELECT 
                stat_date,
                new_users_today,
                new_articles_today,
                new_messages_today
            FROM system_statistics 
            WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ORDER BY stat_date ASC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get recent admin activity
     */
    private function getRecentActivity() {
        $stmt = $this->db->prepare("
            SELECT 
                al.*,
                u.username,
                u.first_name,
                u.last_name
            FROM admin_logs al
            JOIN users u ON al.admin_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get system health metrics
     */
    private function getSystemHealth() {
        $health = [];
        
        // Database size
        $stmt = $this->db->prepare("
            SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS db_size_mb
            FROM information_schema.tables 
            WHERE table_schema = ?
        ");
        $stmt->execute([Database::getInstance()->getDatabaseName()]);
        $health['database_size_mb'] = $stmt->fetchColumn() ?: 0;
        
        // Upload directory size
        $uploadPath = __DIR__ . '/../../uploads';
        $health['uploads_size_mb'] = $this->getDirectorySize($uploadPath);
        
        // Failed login attempts (last 24 hours)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM admin_logs 
            WHERE action = 'failed_login' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $health['failed_logins_24h'] = $stmt->fetchColumn();
        
        // Error log size
        $logPath = __DIR__ . '/../../logs';
        $health['logs_size_mb'] = $this->getDirectorySize($logPath);
        
        return $health;
    }
    
    /**
     * Calculate directory size in MB
     */
    private function getDirectorySize($path) {
        if (!is_dir($path)) {
            return 0;
        }
        
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return round($size / 1024 / 1024, 1);
    }
    
    /**
     * Get admin notifications
     */
    public function getNotifications() {
        try {
            $limit = Request::get('limit', 20);
            $offset = Request::get('offset', 0);
            $unreadOnly = Request::get('unread_only', false);
            
            $whereClause = '';
            $params = [];
            
            if ($unreadOnly) {
                $whereClause = 'WHERE is_read = FALSE';
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    id,
                    type,
                    title,
                    message,
                    data,
                    severity,
                    is_read,
                    created_at
                FROM admin_notifications 
                {$whereClause}
                ORDER BY 
                    CASE severity 
                        WHEN 'critical' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        WHEN 'low' THEN 4
                    END,
                    created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            array_push($params, $limit, $offset);
            $stmt->execute($params);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) FROM admin_notifications {$whereClause}
            ");
            $countStmt->execute();
            $total = $countStmt->fetchColumn();
            
            Response::success([
                'notifications' => $notifications,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting notifications: ' . $e->getMessage());
            Response::serverError('Failed to load notifications');
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markNotificationRead() {
        try {
            $data = Request::validate([
                'notification_id' => 'required'
            ]);
            
            $stmt = $this->db->prepare("
                UPDATE admin_notifications 
                SET is_read = TRUE, read_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$data['notification_id']]);
            
            $currentUser = AdminMiddleware::getCurrentUser();
            $this->logAdminAction($currentUser['id'], 'mark_notification_read', 'admin_notifications', $data['notification_id']);
            
            Response::success();
            
        } catch (Exception $e) {
            Logger::error('Error marking notification as read: ' . $e->getMessage());
            Response::serverError('Failed to mark notification as read');
        }
    }
    
    /**
     * Log admin action
     */
    private function logAdminAction($action, $targetType = null, $targetId = null, $description = null) {
        try {
            $user = AuthMiddleware::getCurrentUser();
            if (!$user) return;
            
            $stmt = $this->db->prepare("
                INSERT INTO admin_logs (admin_id, action, target_type, target_id, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $user['id'],
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