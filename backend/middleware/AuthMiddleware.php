<?php
/**
 * Authentication middleware
 * Validates JWT tokens and sets current user context
 */

class AuthMiddleware {
    public function handle() {
        $token = Request::bearerToken();
        
        if (!$token) {
            Response::unauthorized('Access token required');
        }
        
        $payload = JWT::decode($token);
        
        if (!$payload) {
            Response::unauthorized('Invalid or expired token');
        }
        
        // Load user data
        $userModel = new User();
        $user = $userModel->find($payload['user_id']);
        
        if (!$user || $user['status'] !== 'active') {
            Response::unauthorized('User account is not active');
        }
        
        // Set current user in global context
        $_SESSION['current_user'] = $user;
        $GLOBALS['current_user'] = $user;
        
        Logger::debug('User authenticated', ['user_id' => $user['id']]);
    }
    
    public static function getCurrentUser() {
        return $GLOBALS['current_user'] ?? null;
    }
    
    public static function getCurrentUserId() {
        $user = self::getCurrentUser();
        return $user ? $user['id'] : null;
    }
    
    public static function requireUser() {
        $user = self::getCurrentUser();
        if (!$user) {
            Response::unauthorized('Authentication required');
        }
        return $user;
    }
}