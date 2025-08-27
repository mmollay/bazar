<?php
/**
 * Admin Report Management Controller
 * Handles user reports, complaints, and content moderation
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class AdminReportController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get reports with filtering and pagination
     */
    public function getReports() {
        try {
            AdminMiddleware::handle();
            
            $limit = Request::get('limit', 20);
            $offset = Request::get('offset', 0);
            $status = Request::get('status', '');
            $type = Request::get('report_type', '');
            $sortBy = Request::get('sort_by', 'created_at');
            $sortOrder = Request::get('sort_order', 'DESC');
            
            // Build WHERE clause
            $whereConditions = [];
            $params = [];
            
            if (!empty($status)) {
                $whereConditions[] = "ur.status = ?";
                $params[] = $status;
            }
            
            if (!empty($type)) {
                $whereConditions[] = "ur.report_type = ?";
                $params[] = $type;
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            // Validate sort parameters
            $allowedSorts = ['id', 'created_at', 'status', 'report_type'];
            $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'created_at';
            $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            
            $stmt = $this->db->prepare("
                SELECT 
                    ur.*,
                    reporter.username as reporter_username,
                    reporter.first_name as reporter_first_name,
                    reported_user.username as reported_username,
                    reported_user.first_name as reported_user_first_name,
                    a.title as article_title,
                    handler.username as handler_username
                FROM user_reports ur
                LEFT JOIN users reporter ON ur.reporter_id = reporter.id
                LEFT JOIN users reported_user ON ur.reported_user_id = reported_user.id
                LEFT JOIN articles a ON ur.reported_article_id = a.id
                LEFT JOIN users handler ON ur.handled_by = handler.id
                {$whereClause}
                ORDER BY ur.{$sortBy} {$sortOrder}
                LIMIT ? OFFSET ?
            ");
            
            $params = array_merge($params, [$limit, $offset]);
            $stmt->execute($params);
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM user_reports ur {$whereClause}");
            $countStmt->execute(array_slice($params, 0, -2));
            $total = $countStmt->fetchColumn();
            
            Response::success([
                'reports' => $reports,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting reports: ' . $e->getMessage());
            Response::serverError('Failed to load reports');
        }
    }
    
    /**
     * Get report details
     */
    public function getReportDetails() {
        try {
            AdminMiddleware::handle();
            
            $reportId = Request::get('id');
            if (!$reportId) {
                Response::error('Report ID is required');
            }
            
            // Get report details
            $stmt = $this->db->prepare("
                SELECT 
                    ur.*,
                    reporter.username as reporter_username,
                    reporter.first_name as reporter_first_name,
                    reporter.email as reporter_email,
                    reported_user.username as reported_username,
                    reported_user.first_name as reported_user_first_name,
                    reported_user.email as reported_user_email,
                    a.title as article_title,
                    a.description as article_description,
                    a.price as article_price,
                    handler.username as handler_username,
                    handler.first_name as handler_first_name
                FROM user_reports ur
                LEFT JOIN users reporter ON ur.reporter_id = reporter.id
                LEFT JOIN users reported_user ON ur.reported_user_id = reported_user.id
                LEFT JOIN articles a ON ur.reported_article_id = a.id
                LEFT JOIN users handler ON ur.handled_by = handler.id
                WHERE ur.id = ?
            ");
            $stmt->execute([$reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$report) {
                Response::notFound('Report not found');
            }
            
            // Get related reports (same reported user or article)
            $relatedReports = [];
            if ($report['reported_user_id']) {
                $stmt = $this->db->prepare("
                    SELECT id, report_type, status, created_at, description
                    FROM user_reports 
                    WHERE reported_user_id = ? AND id != ?
                    ORDER BY created_at DESC
                    LIMIT 10
                ");
                $stmt->execute([$report['reported_user_id'], $reportId]);
                $relatedReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Get reporter's history
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_reports,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_reports,
                    SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed_reports
                FROM user_reports 
                WHERE reporter_id = ?
            ");
            $stmt->execute([$report['reporter_id']]);
            $reporterStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            Response::success([
                'report' => $report,
                'related_reports' => $relatedReports,
                'reporter_stats' => $reporterStats
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting report details: ' . $e->getMessage());
            Response::serverError('Failed to load report details');
        }
    }
    
    /**
     * Update report status and handle it
     */
    public function handleReport() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'report_id' => 'required',
                'action' => 'required|in:investigating,resolve,dismiss',
                'admin_notes' => '',
                'user_action' => 'in:none,warn,suspend,delete_content'
            ]);
            
            $reportId = $data['report_id'];
            $action = $data['action'];
            $adminNotes = $data['admin_notes'] ?? '';
            $userAction = $data['user_action'] ?? 'none';
            
            // Get report
            $stmt = $this->db->prepare("
                SELECT ur.*, a.user_id as article_owner_id
                FROM user_reports ur
                LEFT JOIN articles a ON ur.reported_article_id = a.id
                WHERE ur.id = ?
            ");
            $stmt->execute([$reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$report) {
                Response::notFound('Report not found');
            }
            
            if ($report['status'] !== 'pending' && $report['status'] !== 'investigating') {
                Response::error('Report has already been handled');
            }
            
            $this->db->beginTransaction();
            
            try {
                // Update report status
                $newStatus = $action === 'investigating' ? 'investigating' : ($action === 'resolve' ? 'resolved' : 'dismissed');
                
                $stmt = $this->db->prepare("
                    UPDATE user_reports 
                    SET status = ?, admin_notes = ?, handled_by = ?, handled_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newStatus, $adminNotes, $currentUser['id'], $reportId]);
                
                // Take action on reported content/user if resolved
                if ($action === 'resolve' && $userAction !== 'none') {
                    $this->takeUserAction($report, $userAction, $adminNotes, $currentUser['id']);
                }
                
                $this->db->commit();
                
                // Log the action
                $this->logAdminAction(
                    $currentUser['id'],
                    'handle_report',
                    'user_reports',
                    $reportId,
                    "Report {$action}: {$adminNotes}"
                );
                
                // Create notification for resolved/dismissed reports
                if (in_array($action, ['resolve', 'dismiss'])) {
                    $this->createAdminNotification(
                        'user_report',
                        "Report {$action}d",
                        "Report #{$reportId} has been {$action}d",
                        ['report_id' => $reportId, 'action' => $action],
                        'low'
                    );
                }
                
                Response::success(['message' => "Report {$action}d successfully"]);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            Logger::error('Error handling report: ' . $e->getMessage());
            Response::serverError('Failed to handle report');
        }
    }
    
    /**
     * Get report statistics
     */
    public function getReportStatistics() {
        try {
            AdminMiddleware::handle();
            
            // Basic statistics
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_reports,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_reports,
                    SUM(CASE WHEN status = 'investigating' THEN 1 ELSE 0 END) as investigating_reports,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_reports,
                    SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed_reports
                FROM user_reports
            ");
            $stmt->execute();
            $basicStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Report type distribution
            $stmt = $this->db->prepare("
                SELECT 
                    report_type,
                    COUNT(*) as count,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count
                FROM user_reports 
                GROUP BY report_type
                ORDER BY count DESC
            ");
            $stmt->execute();
            $typeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Reports by date (last 30 days)
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count
                FROM user_reports 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->execute();
            $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top reporters
            $stmt = $this->db->prepare("
                SELECT 
                    u.username,
                    u.first_name,
                    COUNT(ur.id) as report_count,
                    SUM(CASE WHEN ur.status = 'resolved' THEN 1 ELSE 0 END) as valid_reports
                FROM user_reports ur
                LEFT JOIN users u ON ur.reporter_id = u.id
                GROUP BY ur.reporter_id
                HAVING report_count > 1
                ORDER BY report_count DESC
                LIMIT 10
            ");
            $stmt->execute();
            $topReporters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Most reported users
            $stmt = $this->db->prepare("
                SELECT 
                    u.username,
                    u.first_name,
                    COUNT(ur.id) as report_count,
                    SUM(CASE WHEN ur.status = 'resolved' THEN 1 ELSE 0 END) as valid_reports_against
                FROM user_reports ur
                LEFT JOIN users u ON ur.reported_user_id = u.id
                WHERE ur.reported_user_id IS NOT NULL
                GROUP BY ur.reported_user_id
                HAVING report_count > 1
                ORDER BY report_count DESC
                LIMIT 10
            ");
            $stmt->execute();
            $mostReported = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success([
                'basic_stats' => $basicStats,
                'type_distribution' => $typeStats,
                'trends' => $trends,
                'top_reporters' => $topReporters,
                'most_reported' => $mostReported
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting report statistics: ' . $e->getMessage());
            Response::serverError('Failed to load report statistics');
        }
    }
    
    /**
     * Bulk handle reports
     */
    public function bulkHandleReports() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'report_ids' => 'required',
                'action' => 'required|in:resolve,dismiss',
                'admin_notes' => ''
            ]);
            
            if (!is_array($data['report_ids']) || empty($data['report_ids'])) {
                Response::error('Report IDs must be a non-empty array');
            }
            
            $successCount = 0;
            $errors = [];
            
            $this->db->beginTransaction();
            
            try {
                foreach ($data['report_ids'] as $reportId) {
                    try {
                        // Get report
                        $stmt = $this->db->prepare("SELECT * FROM user_reports WHERE id = ?");
                        $stmt->execute([$reportId]);
                        $report = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$report) {
                            $errors[] = "Report ID {$reportId} not found";
                            continue;
                        }
                        
                        if (!in_array($report['status'], ['pending', 'investigating'])) {
                            $errors[] = "Report ID {$reportId} has already been handled";
                            continue;
                        }
                        
                        // Update report status
                        $newStatus = $data['action'] === 'resolve' ? 'resolved' : 'dismissed';
                        
                        $stmt = $this->db->prepare("
                            UPDATE user_reports 
                            SET status = ?, admin_notes = ?, handled_by = ?, handled_at = NOW(), updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$newStatus, $data['admin_notes'], $currentUser['id'], $reportId]);
                        
                        // Log the action
                        $this->logAdminAction(
                            $currentUser['id'],
                            'bulk_handle_report',
                            'user_reports',
                            $reportId,
                            "Bulk {$data['action']} report: {$data['admin_notes']}"
                        );
                        
                        $successCount++;
                        
                    } catch (Exception $e) {
                        $errors[] = "Error processing report ID {$reportId}: " . $e->getMessage();
                    }
                }
                
                $this->db->commit();
                
                Response::success([
                    'message' => "Bulk operation completed. {$successCount} reports processed successfully.",
                    'success_count' => $successCount,
                    'errors' => $errors
                ]);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            Logger::error('Error in bulk handle reports: ' . $e->getMessage());
            Response::serverError('Failed to perform bulk operations');
        }
    }
    
    /**
     * Take action on reported user/content
     */
    private function takeUserAction($report, $action, $reason, $adminId) {
        switch ($action) {
            case 'warn':
                // In a real implementation, you would send a warning email/notification
                Logger::info('User warned', [
                    'user_id' => $report['reported_user_id'],
                    'reason' => $reason,
                    'admin_id' => $adminId
                ]);
                break;
                
            case 'suspend':
                if ($report['reported_user_id']) {
                    $stmt = $this->db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
                    $stmt->execute([$report['reported_user_id']]);
                }
                break;
                
            case 'delete_content':
                if ($report['reported_article_id']) {
                    $stmt = $this->db->prepare("UPDATE articles SET status = 'archived' WHERE id = ?");
                    $stmt->execute([$report['reported_article_id']]);
                }
                if ($report['reported_message_id']) {
                    // In a real implementation, you might soft-delete the message
                    Logger::info('Message deleted', [
                        'message_id' => $report['reported_message_id'],
                        'admin_id' => $adminId
                    ]);
                }
                break;
        }
        
        // Log the user action
        $this->logAdminAction(
            $adminId,
            "user_action_{$action}",
            $report['reported_user_id'] ? 'users' : 'articles',
            $report['reported_user_id'] ?: $report['reported_article_id'],
            "User action taken: {$action}. Reason: {$reason}"
        );
    }
    
    /**
     * Create admin notification
     */
    private function createAdminNotification($type, $title, $message, $data = [], $severity = 'medium') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO admin_notifications (type, title, message, data, severity)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $type,
                $title,
                $message,
                json_encode($data),
                $severity
            ]);
            
        } catch (Exception $e) {
            Logger::error('Failed to create admin notification: ' . $e->getMessage());
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