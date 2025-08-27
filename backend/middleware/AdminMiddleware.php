<?php
/**
 * Admin middleware
 * Ensures the authenticated user has admin privileges and valid session
 */

require_once __DIR__ . '/../config/database.php';

class AdminMiddleware {
    private static $db;
    
    private static function getDB() {
        if (!self::$db) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }
    
    public static function handle() {
        $sessionToken = Request::bearerToken();
        
        if (!$sessionToken) {
            Response::unauthorized('Admin session token required');
        }
        
        // Validate admin session
        $db = self::getDB();
        $stmt = $db->prepare("
            SELECT 
                s.user_id, s.two_factor_verified, s.ip_address, s.expires_at,
                u.id, u.email, u.username, u.first_name, u.last_name,
                u.is_admin, u.admin_role, u.admin_two_factor_enabled, u.status
            FROM admin_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.session_token = ? AND s.expires_at > NOW()
        ");
        $stmt->execute([$sessionToken]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session) {
            Response::unauthorized('Invalid or expired admin session');
        }
        
        // Check if user is still admin and active
        if (!$session['is_admin'] || $session['status'] !== 'active') {
            Response::forbidden('Admin privileges revoked');
        }
        
        // Check 2FA requirement
        if ($session['admin_two_factor_enabled'] && !$session['two_factor_verified']) {
            Response::unauthorized('Two-factor authentication required');
        }
        
        // Check IP consistency (optional security measure)
        $currentIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if ($session['ip_address'] !== $currentIP && $currentIP !== 'unknown') {
            // Log potential security issue
            Logger::warning('Admin session IP mismatch', [
                'user_id' => $session['user_id'],
                'session_ip' => $session['ip_address'],
                'current_ip' => $currentIP
            ]);
        }
        
        // Update session activity
        $stmt = $db->prepare("UPDATE admin_sessions SET last_activity = NOW() WHERE session_token = ?");
        $stmt->execute([$sessionToken]);
        
        // Store user data for controllers to use
        $GLOBALS['current_admin_user'] = [
            'id' => $session['id'],
            'email' => $session['email'],
            'username' => $session['username'],
            'first_name' => $session['first_name'],
            'last_name' => $session['last_name'],
            'is_admin' => $session['is_admin'],
            'admin_role' => $session['admin_role'],
            'admin_two_factor_enabled' => $session['admin_two_factor_enabled']
        ];
        
        Logger::debug('Admin access granted', [
            'user_id' => $session['id'],
            'username' => $session['username'],
            'admin_role' => $session['admin_role']
        ]);
    }
    
    /**
     * Check specific admin role permission
     */
    public static function requireRole($requiredRole) {
        self::handle();
        
        $user = $GLOBALS['current_admin_user'];
        $roleHierarchy = ['support', 'moderator', 'admin', 'super_admin'];
        
        $userRoleLevel = array_search($user['admin_role'], $roleHierarchy);
        $requiredRoleLevel = array_search($requiredRole, $roleHierarchy);
        
        if ($userRoleLevel === false || $requiredRoleLevel === false || $userRoleLevel < $requiredRoleLevel) {
            Response::forbidden("Admin role '{$requiredRole}' or higher required");
        }
    }
    
    /**
     * Get current admin user (after middleware has run)
     */
    public static function getCurrentUser() {
        return $GLOBALS['current_admin_user'] ?? null;
    }
}