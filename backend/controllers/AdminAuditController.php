<?php
/**
 * Admin Audit Controller
 * Handles audit logs, security monitoring, and system activity tracking
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class AdminAuditController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get admin activity logs
     */
    public function getAdminLogs() {
        try {
            AdminMiddleware::handle();
            
            $limit = Request::get('limit', 50);
            $offset = Request::get('offset', 0);
            $adminId = Request::get('admin_id', '');
            $action = Request::get('action', '');
            $startDate = Request::get('start_date', '');
            $endDate = Request::get('end_date', '');
            
            // Build WHERE clause
            $whereConditions = [];
            $params = [];
            
            if (!empty($adminId)) {
                $whereConditions[] = "al.admin_id = ?";
                $params[] = $adminId;
            }
            
            if (!empty($action)) {
                $whereConditions[] = "al.action LIKE ?";
                $params[] = "%{$action}%";
            }
            
            if (!empty($startDate)) {
                $whereConditions[] = "al.created_at >= ?";
                $params[] = $startDate . ' 00:00:00';
            }
            
            if (!empty($endDate)) {
                $whereConditions[] = "al.created_at <= ?";
                $params[] = $endDate . ' 23:59:59';
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            // Get logs
            $stmt = $this->db->prepare("
                SELECT 
                    al.*,
                    u.username,
                    u.first_name,
                    u.last_name,
                    u.admin_role
                FROM admin_logs al
                LEFT JOIN users u ON al.admin_id = u.id
                {$whereClause}
                ORDER BY al.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $params = array_merge($params, [$limit, $offset]);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM admin_logs al {$whereClause}");
            $countStmt->execute(array_slice($params, 0, -2));
            $total = $countStmt->fetchColumn();
            
            // Get activity summary
            $summary = $this->getActivitySummary($whereClause, array_slice($params, 0, -2));
            
            Response::success([
                'logs' => $logs,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total,
                'summary' => $summary
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting admin logs: ' . $e->getMessage());
            Response::serverError('Failed to load admin logs');
        }
    }
    
    /**
     * Get system logs
     */
    public function getSystemLogs() {
        try {
            AdminMiddleware::handle();
            
            $limit = Request::get('limit', 50);
            $offset = Request::get('offset', 0);
            $level = Request::get('level', ''); // error, warning, info
            $date = Request::get('date', date('Y-m-d'));
            
            $logFile = __DIR__ . '/../../logs/' . $date . '.log';
            
            if (!file_exists($logFile)) {
                Response::success([
                    'logs' => [],
                    'total' => 0,
                    'has_more' => false,
                    'message' => 'No log file found for the specified date'
                ]);
                return;
            }
            
            $logs = $this->parseLogFile($logFile, $level, $limit, $offset);
            
            Response::success($logs);
            
        } catch (Exception $e) {
            Logger::error('Error getting system logs: ' . $e->getMessage());
            Response::serverError('Failed to load system logs');
        }
    }
    
    /**
     * Get security logs
     */
    public function getSecurityLogs() {
        try {
            AdminMiddleware::handle();
            
            $limit = Request::get('limit', 50);
            $offset = Request::get('offset', 0);
            $startDate = Request::get('start_date', date('Y-m-d', strtotime('-7 days')));
            $endDate = Request::get('end_date', date('Y-m-d'));
            
            // Get failed login attempts
            $stmt = $this->db->prepare("
                SELECT 
                    'failed_login' as event_type,
                    description as event_description,
                    ip_address,
                    user_agent,
                    created_at
                FROM admin_logs
                WHERE action = 'failed_login'
                    AND created_at BETWEEN ? AND ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            $failedLogins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get suspicious activity
            $suspiciousActivity = $this->detectSuspiciousActivity($startDate, $endDate);
            
            // Get admin session security events
            $stmt = $this->db->prepare("
                SELECT 
                    'session_created' as event_type,
                    CONCAT('Admin session created for user ID: ', user_id) as event_description,
                    ip_address,
                    user_agent,
                    created_at
                FROM admin_sessions
                WHERE created_at BETWEEN ? AND ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59', $limit]);
            $sessionEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Combine and sort all security events
            $allEvents = array_merge($failedLogins, $suspiciousActivity, $sessionEvents);
            usort($allEvents, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            // Apply pagination
            $total = count($allEvents);
            $pagedEvents = array_slice($allEvents, $offset, $limit);
            
            // Security metrics
            $metrics = [
                'failed_logins_24h' => $this->getFailedLoginsCount(24),
                'failed_logins_7d' => $this->getFailedLoginsCount(7 * 24),
                'unique_ips_24h' => $this->getUniqueIPsCount(24),
                'suspicious_activity_count' => count($suspiciousActivity)
            ];
            
            Response::success([
                'events' => $pagedEvents,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total,
                'metrics' => $metrics
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting security logs: ' . $e->getMessage());
            Response::serverError('Failed to load security logs');
        }
    }
    
    /**
     * Get activity summary for admin logs
     */
    private function getActivitySummary($whereClause, $params) {
        // Most active admins
        $stmt = $this->db->prepare("
            SELECT 
                u.username,
                u.first_name,
                u.last_name,
                COUNT(al.id) as action_count
            FROM admin_logs al
            LEFT JOIN users u ON al.admin_id = u.id
            {$whereClause}
            GROUP BY al.admin_id
            ORDER BY action_count DESC
            LIMIT 5
        ");
        $stmt->execute($params);
        $activeAdmins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Most common actions
        $stmt = $this->db->prepare("
            SELECT 
                action,
                COUNT(*) as count
            FROM admin_logs al
            {$whereClause}
            GROUP BY action
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $commonActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Activity by hour (last 24h if no date filter)
        if (empty($whereClause)) {
            $stmt = $this->db->prepare("
                SELECT 
                    HOUR(created_at) as hour,
                    COUNT(*) as count
                FROM admin_logs al
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY HOUR(created_at)
                ORDER BY hour ASC
            ");
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("
                SELECT 
                    HOUR(created_at) as hour,
                    COUNT(*) as count
                FROM admin_logs al
                {$whereClause}
                GROUP BY HOUR(created_at)
                ORDER BY hour ASC
            ");
            $stmt->execute($params);
        }
        $hourlyActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'active_admins' => $activeAdmins,
            'common_actions' => $commonActions,
            'hourly_activity' => $hourlyActivity
        ];
    }
    
    /**
     * Parse log file and filter by level
     */
    private function parseLogFile($logFile, $level, $limit, $offset) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $filteredLogs = [];
        
        foreach ($lines as $line) {
            // Parse log line format: [timestamp] LEVEL [IP] message context
            if (preg_match('/^\[(.*?)\] (\w+) \[(.*?)\] (.*?) (.*)$/', $line, $matches)) {
                $logLevel = strtolower($matches[2]);
                
                if (empty($level) || $logLevel === strtolower($level)) {
                    $filteredLogs[] = [
                        'timestamp' => $matches[1],
                        'level' => strtoupper($matches[2]),
                        'ip_address' => $matches[3],
                        'message' => $matches[4],
                        'context' => $matches[5]
                    ];
                }
            }
        }
        
        // Sort by timestamp (newest first)
        usort($filteredLogs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        $total = count($filteredLogs);
        $pagedLogs = array_slice($filteredLogs, $offset, $limit);
        
        return [
            'logs' => $pagedLogs,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total
        ];
    }
    
    /**
     * Detect suspicious activity patterns
     */
    private function detectSuspiciousActivity($startDate, $endDate) {
        $suspicious = [];
        
        // Multiple failed logins from same IP
        $stmt = $this->db->prepare("
            SELECT 
                ip_address,
                COUNT(*) as failed_attempts,
                MIN(created_at) as first_attempt,
                MAX(created_at) as last_attempt
            FROM admin_logs
            WHERE action = 'failed_login'
                AND created_at BETWEEN ? AND ?
            GROUP BY ip_address
            HAVING failed_attempts >= 5
            ORDER BY failed_attempts DESC
        ");
        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $multipleFailures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($multipleFailures as $failure) {
            $suspicious[] = [
                'event_type' => 'multiple_failed_logins',
                'event_description' => "Multiple failed login attempts ({$failure['failed_attempts']}) from IP: {$failure['ip_address']}",
                'ip_address' => $failure['ip_address'],
                'user_agent' => '',
                'created_at' => $failure['last_attempt']
            ];
        }
        
        // Admin login from unusual locations (different from usual IP)
        $stmt = $this->db->prepare("
            SELECT DISTINCT
                als.ip_address,
                als.user_id,
                u.username,
                als.created_at
            FROM admin_sessions als
            LEFT JOIN users u ON als.user_id = u.id
            WHERE als.created_at BETWEEN ? AND ?
                AND als.ip_address NOT IN (
                    SELECT DISTINCT ip_address 
                    FROM admin_sessions 
                    WHERE user_id = als.user_id 
                        AND created_at < ?
                    LIMIT 5
                )
            ORDER BY als.created_at DESC
        ");
        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59', $startDate . ' 00:00:00']);
        $unusualLogins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($unusualLogins as $login) {
            $suspicious[] = [
                'event_type' => 'unusual_location_login',
                'event_description' => "Admin login from unusual IP for user: {$login['username']}",
                'ip_address' => $login['ip_address'],
                'user_agent' => '',
                'created_at' => $login['created_at']
            ];
        }
        
        // High-privilege actions outside business hours
        $stmt = $this->db->prepare("
            SELECT 
                al.*,
                u.username
            FROM admin_logs al
            LEFT JOIN users u ON al.admin_id = u.id
            WHERE al.created_at BETWEEN ? AND ?
                AND al.action IN ('delete_user', 'update_setting', 'delete_setting')
                AND (HOUR(al.created_at) < 8 OR HOUR(al.created_at) > 18)
            ORDER BY al.created_at DESC
        ");
        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $afterHours = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($afterHours as $action) {
            $suspicious[] = [
                'event_type' => 'after_hours_activity',
                'event_description' => "High-privilege action '{$action['action']}' by {$action['username']} outside business hours",
                'ip_address' => $action['ip_address'],
                'user_agent' => $action['user_agent'],
                'created_at' => $action['created_at']
            ];
        }
        
        return $suspicious;
    }
    
    /**
     * Get failed logins count for specified hours
     */
    private function getFailedLoginsCount($hours) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM admin_logs 
            WHERE action = 'failed_login' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Get unique IPs count for specified hours
     */
    private function getUniqueIPsCount($hours) {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT ip_address) 
            FROM admin_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Create security alert
     */
    public function createSecurityAlert() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'type' => 'required|in:security_incident,performance_issue,system_alert',
                'title' => 'required|max:255',
                'message' => 'required',
                'severity' => 'required|in:low,medium,high,critical',
                'data' => ''
            ]);
            
            $alertData = [];
            if (!empty($data['data'])) {
                $alertData = json_decode($data['data'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Response::error('Invalid JSON format for data');
                }
            }
            
            // Create notification
            $stmt = $this->db->prepare("
                INSERT INTO admin_notifications (type, title, message, data, severity)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['type'],
                $data['title'],
                $data['message'],
                json_encode($alertData),
                $data['severity']
            ]);
            
            $notificationId = $this->db->lastInsertId();
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                'create_security_alert',
                'admin_notifications',
                $notificationId,
                "Created {$data['severity']} security alert: {$data['title']}"
            );
            
            Response::success([
                'message' => 'Security alert created successfully',
                'id' => $notificationId
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error creating security alert: ' . $e->getMessage());
            Response::serverError('Failed to create security alert');
        }
    }
    
    /**
     * Get audit trail for specific entity
     */
    public function getEntityAuditTrail() {
        try {
            AdminMiddleware::handle();
            
            $targetType = Request::get('target_type'); // users, articles, etc.
            $targetId = Request::get('target_id');
            
            if (!$targetType || !$targetId) {
                Response::error('Target type and ID are required');
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    al.*,
                    u.username,
                    u.first_name,
                    u.last_name
                FROM admin_logs al
                LEFT JOIN users u ON al.admin_id = u.id
                WHERE al.target_type = ? AND al.target_id = ?
                ORDER BY al.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$targetType, $targetId]);
            $auditTrail = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success([
                'audit_trail' => $auditTrail,
                'target_type' => $targetType,
                'target_id' => $targetId
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting entity audit trail: ' . $e->getMessage());
            Response::serverError('Failed to load audit trail');
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