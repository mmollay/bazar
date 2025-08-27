<?php
/**
 * Admin User Management Controller
 * Handles user administration, moderation, and management
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class AdminUserController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get users with filtering, sorting and pagination
     */
    public function getUsers() {
        try {
            AdminMiddleware::handle();
            
            $limit = Request::get('limit', 20);
            $offset = Request::get('offset', 0);
            $search = Request::get('search', '');
            $status = Request::get('status', '');
            $sortBy = Request::get('sort_by', 'created_at');
            $sortOrder = Request::get('sort_order', 'DESC');
            $isAdmin = Request::get('is_admin', '');
            
            // Build WHERE clause
            $whereConditions = [];
            $params = [];
            
            if (!empty($search)) {
                $whereConditions[] = "(username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
                $searchTerm = "%{$search}%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }
            
            if (!empty($status)) {
                $whereConditions[] = "status = ?";
                $params[] = $status;
            }
            
            if ($isAdmin !== '') {
                $whereConditions[] = "is_admin = ?";
                $params[] = $isAdmin ? 1 : 0;
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            // Validate sort parameters
            $allowedSorts = ['id', 'username', 'email', 'created_at', 'last_login_at', 'status', 'rating'];
            $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'created_at';
            $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            
            // Get users
            $stmt = $this->db->prepare("
                SELECT 
                    id, username, email, first_name, last_name,
                    is_verified, last_login_at, rating, rating_count,
                    status, is_admin, admin_role, created_at,
                    (SELECT COUNT(*) FROM articles WHERE user_id = users.id AND status != 'archived') as article_count,
                    (SELECT COUNT(*) FROM messages WHERE sender_id = users.id OR receiver_id = users.id) as message_count
                FROM users 
                {$whereClause}
                ORDER BY {$sortBy} {$sortOrder}
                LIMIT ? OFFSET ?
            ");
            
            $params = array_merge($params, [$limit, $offset]);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users {$whereClause}");
            $countStmt->execute(array_slice($params, 0, -2));
            $total = $countStmt->fetchColumn();
            
            Response::success([
                'users' => $users,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting users: ' . $e->getMessage());
            Response::serverError('Failed to load users');
        }
    }
    
    /**
     * Get user details with full profile and activity
     */
    public function getUserDetails() {
        try {
            AdminMiddleware::handle();
            
            $userId = Request::get('id');
            if (!$userId) {
                Response::error('User ID is required');
            }
            
            // Get user details
            $stmt = $this->db->prepare("
                SELECT * FROM users WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                Response::notFound('User not found');
            }
            
            // Get user statistics
            $stats = $this->getUserStats($userId);
            
            // Get recent articles
            $stmt = $this->db->prepare("
                SELECT id, title, price, status, created_at
                FROM articles 
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            $recentArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get recent activity
            $recentActivity = $this->getUserActivity($userId);
            
            // Get reports about this user
            $stmt = $this->db->prepare("
                SELECT 
                    ur.*,
                    u.username as reporter_username
                FROM user_reports ur
                LEFT JOIN users u ON ur.reporter_id = u.id
                WHERE ur.reported_user_id = ?
                ORDER BY ur.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Remove sensitive data
            unset($user['password_hash'], $user['two_factor_secret'], $user['admin_two_factor_secret']);
            
            Response::success([
                'user' => $user,
                'statistics' => $stats,
                'recent_articles' => $recentArticles,
                'recent_activity' => $recentActivity,
                'reports' => $reports
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting user details: ' . $e->getMessage());
            Response::serverError('Failed to load user details');
        }
    }
    
    /**
     * Update user status (activate, suspend, delete)
     */
    public function updateUserStatus() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'user_id' => 'required',
                'status' => 'required|in:active,suspended,deleted',
                'reason' => 'required'
            ]);
            
            // Prevent self-suspension
            if ($data['user_id'] == $currentUser['id']) {
                Response::error('Cannot modify your own account status');
            }
            
            // Get target user
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                Response::notFound('User not found');
            }
            
            // Check permissions for admin users
            if ($targetUser['is_admin'] && $currentUser['admin_role'] !== 'super_admin') {
                Response::forbidden('Only super admins can modify admin accounts');
            }
            
            // Update user status
            $stmt = $this->db->prepare("
                UPDATE users 
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$data['status'], $data['user_id']]);
            
            // If suspended or deleted, deactivate all user's articles
            if (in_array($data['status'], ['suspended', 'deleted'])) {
                $stmt = $this->db->prepare("
                    UPDATE articles 
                    SET status = 'archived', updated_at = NOW()
                    WHERE user_id = ? AND status = 'active'
                ");
                $stmt->execute([$data['user_id']]);
            }
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                'update_user_status',
                'users',
                $data['user_id'],
                "Changed user status to {$data['status']}. Reason: {$data['reason']}"
            );
            
            // Create notification for user (if not deleted)
            if ($data['status'] !== 'deleted') {
                $this->createUserNotification($data['user_id'], $data['status'], $data['reason']);
            }
            
            Response::success(['message' => 'User status updated successfully']);
            
        } catch (Exception $e) {
            Logger::error('Error updating user status: ' . $e->getMessage());
            Response::serverError('Failed to update user status');
        }
    }
    
    /**
     * Update user details
     */
    public function updateUser() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'user_id' => 'required',
                'first_name' => 'required|max:100',
                'last_name' => 'required|max:100',
                'email' => 'required|email',
                'phone' => 'max:20',
                'is_verified' => '',
                'admin_role' => 'in:super_admin,admin,moderator,support'
            ]);
            
            $userId = $data['user_id'];
            unset($data['user_id']);
            
            // Get target user
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                Response::notFound('User not found');
            }
            
            // Check permissions for admin role changes
            if (isset($data['admin_role'])) {
                if ($currentUser['admin_role'] !== 'super_admin') {
                    Response::forbidden('Only super admins can modify admin roles');
                }
                
                if (!empty($data['admin_role'])) {
                    $data['is_admin'] = true;
                } else {
                    $data['is_admin'] = false;
                    $data['admin_role'] = null;
                }
            }
            
            // Check email uniqueness
            if ($data['email'] !== $targetUser['email']) {
                $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$data['email'], $userId]);
                if ($stmt->fetch()) {
                    Response::error('Email address is already in use');
                }
            }
            
            // Build update query
            $updateFields = [];
            $params = [];
            
            foreach ($data as $field => $value) {
                if ($value !== null && $value !== '') {
                    $updateFields[] = "{$field} = ?";
                    $params[] = $value;
                }
            }
            
            if (empty($updateFields)) {
                Response::error('No fields to update');
            }
            
            $updateFields[] = 'updated_at = NOW()';
            $params[] = $userId;
            
            $stmt = $this->db->prepare("
                UPDATE users 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $stmt->execute($params);
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                'update_user',
                'users',
                $userId,
                'Updated user profile: ' . implode(', ', array_keys($data))
            );
            
            Response::success(['message' => 'User updated successfully']);
            
        } catch (Exception $e) {
            Logger::error('Error updating user: ' . $e->getMessage());
            Response::serverError('Failed to update user');
        }
    }
    
    /**
     * Delete user permanently
     */
    public function deleteUser() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'user_id' => 'required',
                'reason' => 'required'
            ]);
            
            // Prevent self-deletion
            if ($data['user_id'] == $currentUser['id']) {
                Response::error('Cannot delete your own account');
            }
            
            // Get target user
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                Response::notFound('User not found');
            }
            
            // Check permissions for admin users
            if ($targetUser['is_admin'] && $currentUser['admin_role'] !== 'super_admin') {
                Response::forbidden('Only super admins can delete admin accounts');
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Archive all user's articles instead of deleting
                $stmt = $this->db->prepare("
                    UPDATE articles 
                    SET status = 'archived', updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$data['user_id']]);
                
                // Mark user as deleted
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET status = 'deleted',
                        email = CONCAT('deleted_', id, '_', email),
                        username = CONCAT('deleted_', id, '_', username),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$data['user_id']]);
                
                $this->db->commit();
                
                // Log the action
                $this->logAdminAction(
                    $currentUser['id'],
                    'delete_user',
                    'users',
                    $data['user_id'],
                    "Deleted user account. Reason: {$data['reason']}"
                );
                
                Response::success(['message' => 'User deleted successfully']);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            Logger::error('Error deleting user: ' . $e->getMessage());
            Response::serverError('Failed to delete user');
        }
    }
    
    /**
     * Bulk operations on users
     */
    public function bulkOperations() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'user_ids' => 'required',
                'operation' => 'required|in:suspend,activate,delete',
                'reason' => 'required'
            ]);
            
            if (!is_array($data['user_ids']) || empty($data['user_ids'])) {
                Response::error('User IDs must be a non-empty array');
            }
            
            // Prevent operations on self
            if (in_array($currentUser['id'], $data['user_ids'])) {
                Response::error('Cannot perform bulk operations on your own account');
            }
            
            $successCount = 0;
            $errors = [];
            
            foreach ($data['user_ids'] as $userId) {
                try {
                    // Get target user
                    $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$targetUser) {
                        $errors[] = "User ID {$userId} not found";
                        continue;
                    }
                    
                    // Check permissions for admin users
                    if ($targetUser['is_admin'] && $currentUser['admin_role'] !== 'super_admin') {
                        $errors[] = "Insufficient permissions for user ID {$userId}";
                        continue;
                    }
                    
                    // Perform operation
                    switch ($data['operation']) {
                        case 'suspend':
                            $stmt = $this->db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
                            $stmt->execute([$userId]);
                            break;
                            
                        case 'activate':
                            $stmt = $this->db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                            $stmt->execute([$userId]);
                            break;
                            
                        case 'delete':
                            $stmt = $this->db->prepare("
                                UPDATE users 
                                SET status = 'deleted',
                                    email = CONCAT('deleted_', id, '_', email),
                                    username = CONCAT('deleted_', id, '_', username)
                                WHERE id = ?
                            ");
                            $stmt->execute([$userId]);
                            break;
                    }
                    
                    // Log the action
                    $this->logAdminAction(
                        $currentUser['id'],
                        "bulk_{$data['operation']}_user",
                        'users',
                        $userId,
                        "Bulk {$data['operation']} operation. Reason: {$data['reason']}"
                    );
                    
                    $successCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "Error processing user ID {$userId}: " . $e->getMessage();
                }
            }
            
            Response::success([
                'message' => "Bulk operation completed. {$successCount} users processed successfully.",
                'success_count' => $successCount,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error in bulk operations: ' . $e->getMessage());
            Response::serverError('Failed to perform bulk operations');
        }
    }
    
    /**
     * Get user statistics
     */
    private function getUserStats($userId) {
        $stats = [];
        
        // Article statistics
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_articles,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_articles,
                SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_articles,
                AVG(CASE WHEN status = 'active' THEN view_count ELSE NULL END) as avg_views
            FROM articles 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $articleStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['articles'] = $articleStats;
        
        // Message statistics
        $stmt = $this->db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM messages WHERE sender_id = ?) as sent_messages,
                (SELECT COUNT(*) FROM messages WHERE receiver_id = ?) as received_messages
        ");
        $stmt->execute([$userId, $userId]);
        $messageStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['messages'] = $messageStats;
        
        // Rating statistics
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_ratings,
                AVG(rating) as average_rating
            FROM ratings 
            WHERE rated_id = ?
        ");
        $stmt->execute([$userId]);
        $ratingStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['ratings'] = $ratingStats;
        
        return $stats;
    }
    
    /**
     * Get user activity timeline
     */
    private function getUserActivity($userId, $limit = 20) {
        $activities = [];
        
        // Recent articles
        $stmt = $this->db->prepare("
            SELECT 
                'article' as type,
                'created' as action,
                title as description,
                created_at
            FROM articles 
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        $activities = array_merge($activities, $stmt->fetchAll(PDO::FETCH_ASSOC));
        
        // Recent messages (sent)
        $stmt = $this->db->prepare("
            SELECT 
                'message' as type,
                'sent' as action,
                CONCAT('Message to article: ', a.title) as description,
                m.created_at
            FROM messages m
            JOIN articles a ON m.article_id = a.id
            WHERE m.sender_id = ?
            ORDER BY m.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        $activities = array_merge($activities, $stmt->fetchAll(PDO::FETCH_ASSOC));
        
        // Sort by date
        usort($activities, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return array_slice($activities, 0, $limit);
    }
    
    /**
     * Create user notification
     */
    private function createUserNotification($userId, $status, $reason) {
        $messages = [
            'suspended' => 'Your account has been suspended',
            'active' => 'Your account has been reactivated'
        ];
        
        if (!isset($messages[$status])) return;
        
        // In a real application, you would store this in a notifications table
        // or send an email. For now, we'll just log it.
        Logger::info("User notification", [
            'user_id' => $userId,
            'message' => $messages[$status],
            'reason' => $reason
        ]);
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