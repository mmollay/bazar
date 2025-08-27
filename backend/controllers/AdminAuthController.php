<?php
/**
 * Admin Authentication Controller
 * Handles admin login, 2FA, and session management
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class AdminAuthController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Admin login
     */
    public function login() {
        try {
            $data = Request::validate([
                'email' => 'required|email',
                'password' => 'required|min:6'
            ]);
            
            // Find admin user
            $stmt = $this->db->prepare("
                SELECT id, email, password_hash, username, first_name, last_name, 
                       is_admin, admin_role, admin_two_factor_enabled, status
                FROM users 
                WHERE email = ? AND is_admin = TRUE AND status = 'active'
            ");
            $stmt->execute([$data['email']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($data['password'], $user['password_hash'])) {
                $this->logFailedLogin($data['email'], 'Invalid credentials');
                Response::unauthorized('Invalid email or password');
            }
            
            // Check if account is suspended
            if ($user['status'] !== 'active') {
                $this->logFailedLogin($data['email'], 'Account suspended');
                Response::forbidden('Account is suspended');
            }
            
            // Update last login
            $stmt = $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            // If 2FA is enabled, require verification
            if ($user['admin_two_factor_enabled']) {
                $tempToken = $this->generateTempToken();
                $this->storeTempSession($user['id'], $tempToken);
                
                Response::success([
                    'requires_2fa' => true,
                    'temp_token' => $tempToken,
                    'user' => [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'username' => $user['username'],
                        'full_name' => trim($user['first_name'] . ' ' . $user['last_name'])
                    ]
                ]);
            }
            
            // Create admin session
            $sessionToken = $this->createAdminSession($user['id']);
            $this->logAdminAction($user['id'], 'login', null, null, 'Successful admin login');
            
            Response::success([
                'requires_2fa' => false,
                'session_token' => $sessionToken,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'full_name' => trim($user['first_name'] . ' ' . $user['last_name']),
                    'admin_role' => $user['admin_role']
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error('Admin login error: ' . $e->getMessage());
            Response::serverError('Login failed');
        }
    }
    
    /**
     * Verify 2FA code and complete login
     */
    public function verify2FA() {
        try {
            $data = Request::validate([
                'temp_token' => 'required',
                'code' => 'required|min:6|max:6'
            ]);
            
            // Get temporary session
            $tempSession = $this->getTempSession($data['temp_token']);
            if (!$tempSession) {
                Response::unauthorized('Invalid or expired temporary token');
            }
            
            // Get user with 2FA secret
            $stmt = $this->db->prepare("
                SELECT id, email, username, first_name, last_name, admin_role,
                       admin_two_factor_secret, admin_two_factor_enabled
                FROM users 
                WHERE id = ? AND is_admin = TRUE AND status = 'active'
            ");
            $stmt->execute([$tempSession['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !$user['admin_two_factor_enabled']) {
                Response::unauthorized('2FA not enabled for this account');
            }
            
            // Verify TOTP code
            if (!$this->verifyTOTPCode($user['admin_two_factor_secret'], $data['code'])) {
                $this->logFailedLogin($user['email'], '2FA verification failed');
                Response::unauthorized('Invalid 2FA code');
            }
            
            // Clean up temp session
            $this->cleanupTempSession($data['temp_token']);
            
            // Create admin session
            $sessionToken = $this->createAdminSession($user['id'], true);
            $this->logAdminAction($user['id'], 'login_2fa', null, null, 'Successful admin login with 2FA');
            
            Response::success([
                'session_token' => $sessionToken,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'full_name' => trim($user['first_name'] . ' ' . $user['last_name']),
                    'admin_role' => $user['admin_role']
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error('2FA verification error: ' . $e->getMessage());
            Response::serverError('2FA verification failed');
        }
    }
    
    /**
     * Setup 2FA for admin account
     */
    public function setup2FA() {
        try {
            AdminMiddleware::handle();
            $user = AdminMiddleware::getCurrentUser();
            
            if ($user['admin_two_factor_enabled']) {
                Response::error('2FA is already enabled');
            }
            
            // Generate secret
            $secret = $this->generateTOTPSecret();
            
            // Store secret temporarily
            $stmt = $this->db->prepare("
                UPDATE users 
                SET admin_two_factor_secret = ? 
                WHERE id = ?
            ");
            $stmt->execute([$secret, $user['id']]);
            
            // Generate QR code URL
            $appName = 'Bazar Admin';
            $qrCodeUrl = $this->generateQRCodeURL($secret, $user['email'], $appName);
            
            Response::success([
                'secret' => $secret,
                'qr_code_url' => $qrCodeUrl,
                'backup_codes' => $this->generateBackupCodes($user['id'])
            ]);
            
        } catch (Exception $e) {
            Logger::error('2FA setup error: ' . $e->getMessage());
            Response::serverError('Failed to setup 2FA');
        }
    }
    
    /**
     * Enable 2FA after verification
     */
    public function enable2FA() {
        try {
            AdminMiddleware::handle();
            $user = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'code' => 'required|min:6|max:6'
            ]);
            
            if ($user['admin_two_factor_enabled']) {
                Response::error('2FA is already enabled');
            }
            
            // Get the secret that was set during setup
            $stmt = $this->db->prepare("
                SELECT admin_two_factor_secret 
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
            $secret = $stmt->fetchColumn();
            
            if (!$secret) {
                Response::error('No 2FA setup found. Please run setup first.');
            }
            
            // Verify code
            if (!$this->verifyTOTPCode($secret, $data['code'])) {
                Response::unauthorized('Invalid 2FA code');
            }
            
            // Enable 2FA
            $stmt = $this->db->prepare("
                UPDATE users 
                SET admin_two_factor_enabled = TRUE 
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
            
            $this->logAdminAction($user['id'], 'enable_2fa', 'users', $user['id'], '2FA enabled for admin account');
            
            Response::success(['message' => '2FA has been enabled successfully']);
            
        } catch (Exception $e) {
            Logger::error('2FA enable error: ' . $e->getMessage());
            Response::serverError('Failed to enable 2FA');
        }
    }
    
    /**
     * Disable 2FA
     */
    public function disable2FA() {
        try {
            AdminMiddleware::handle();
            $user = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'password' => 'required',
                'code' => 'required|min:6|max:6'
            ]);
            
            // Verify current password
            $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $passwordHash = $stmt->fetchColumn();
            
            if (!password_verify($data['password'], $passwordHash)) {
                Response::unauthorized('Invalid password');
            }
            
            // Verify 2FA code
            if (!$this->verifyTOTPCode($user['admin_two_factor_secret'], $data['code'])) {
                Response::unauthorized('Invalid 2FA code');
            }
            
            // Disable 2FA
            $stmt = $this->db->prepare("
                UPDATE users 
                SET admin_two_factor_enabled = FALSE,
                    admin_two_factor_secret = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
            
            $this->logAdminAction($user['id'], 'disable_2fa', 'users', $user['id'], '2FA disabled for admin account');
            
            Response::success(['message' => '2FA has been disabled successfully']);
            
        } catch (Exception $e) {
            Logger::error('2FA disable error: ' . $e->getMessage());
            Response::serverError('Failed to disable 2FA');
        }
    }
    
    /**
     * Logout admin user
     */
    public function logout() {
        try {
            $sessionToken = Request::bearerToken();
            if ($sessionToken) {
                // Invalidate session
                $stmt = $this->db->prepare("DELETE FROM admin_sessions WHERE session_token = ?");
                $stmt->execute([$sessionToken]);
                
                // Log logout
                $user = AdminMiddleware::getCurrentUser();
                if ($user) {
                    $this->logAdminAction($user['id'], 'logout', null, null, 'Admin logout');
                }
            }
            
            Response::success(['message' => 'Logged out successfully']);
            
        } catch (Exception $e) {
            Logger::error('Logout error: ' . $e->getMessage());
            Response::success(['message' => 'Logged out successfully']);
        }
    }
    
    /**
     * Get current admin session info
     */
    public function me() {
        try {
            AdminMiddleware::handle();
            $user = AdminMiddleware::getCurrentUser();
            
            // Get session info
            $sessionToken = Request::bearerToken();
            $stmt = $this->db->prepare("
                SELECT session_token, ip_address, last_activity, two_factor_verified, created_at
                FROM admin_sessions 
                WHERE session_token = ? AND user_id = ?
            ");
            $stmt->execute([$sessionToken, $user['id']]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            Response::success([
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'full_name' => trim($user['first_name'] . ' ' . $user['last_name']),
                    'admin_role' => $user['admin_role'],
                    'two_factor_enabled' => (bool)$user['admin_two_factor_enabled']
                ],
                'session' => $session
            ]);
            
        } catch (Exception $e) {
            Logger::error('Get current user error: ' . $e->getMessage());
            Response::serverError('Failed to get user info');
        }
    }
    
    /**
     * Create admin session
     */
    private function createAdminSession($userId, $twoFactorVerified = false) {
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours
        
        $stmt = $this->db->prepare("
            INSERT INTO admin_sessions (user_id, session_token, ip_address, user_agent, two_factor_verified, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $sessionToken,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $twoFactorVerified,
            $expiresAt
        ]);
        
        return $sessionToken;
    }
    
    /**
     * Generate temporary token for 2FA flow
     */
    private function generateTempToken() {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Store temporary session for 2FA
     */
    private function storeTempSession($userId, $tempToken) {
        $cacheKey = "temp_session:" . $tempToken;
        $data = [
            'user_id' => $userId,
            'created_at' => time(),
            'expires_at' => time() + (5 * 60) // 5 minutes
        ];
        
        // Use file cache if Redis is not available
        CacheService::set($cacheKey, $data, 300);
    }
    
    /**
     * Get temporary session
     */
    private function getTempSession($tempToken) {
        $cacheKey = "temp_session:" . $tempToken;
        $data = CacheService::get($cacheKey);
        
        if (!$data || $data['expires_at'] < time()) {
            return null;
        }
        
        return $data;
    }
    
    /**
     * Cleanup temporary session
     */
    private function cleanupTempSession($tempToken) {
        $cacheKey = "temp_session:" . $tempToken;
        CacheService::delete($cacheKey);
    }
    
    /**
     * Generate TOTP secret
     */
    private function generateTOTPSecret() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }
    
    /**
     * Generate QR code URL for Google Authenticator
     */
    private function generateQRCodeURL($secret, $email, $appName) {
        $url = "otpauth://totp/" . urlencode($appName . ":" . $email) . "?secret=" . $secret . "&issuer=" . urlencode($appName);
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($url);
    }
    
    /**
     * Verify TOTP code
     */
    private function verifyTOTPCode($secret, $code) {
        $timeStep = 30;
        $currentTime = floor(time() / $timeStep);
        
        // Check current time step and one step before/after for clock drift
        for ($i = -1; $i <= 1; $i++) {
            $calculatedCode = $this->generateTOTPCode($secret, $currentTime + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate TOTP code
     */
    private function generateTOTPCode($secret, $timeStep) {
        $secretBytes = '';
        foreach (str_split($secret) as $char) {
            $secretBytes .= chr(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char));
        }
        
        $time = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $time, $secretBytes, true);
        $offset = ord($hash[19]) & 0xf;
        
        $code = (
            ((ord($hash[$offset+0]) & 0x7f) << 24) |
            ((ord($hash[$offset+1]) & 0xff) << 16) |
            ((ord($hash[$offset+2]) & 0xff) << 8) |
            (ord($hash[$offset+3]) & 0xff)
        ) % 1000000;
        
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate backup codes
     */
    private function generateBackupCodes($userId) {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(substr(md5(random_bytes(16)), 0, 8));
        }
        
        // Store backup codes (hashed)
        $hashedCodes = array_map('password_hash', $codes, array_fill(0, count($codes), PASSWORD_DEFAULT));
        
        // In a real implementation, you would store these in a separate table
        // For now, we'll just return them
        
        return $codes;
    }
    
    /**
     * Log failed login attempt
     */
    private function logFailedLogin($email, $reason) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO admin_logs (admin_id, action, description, ip_address, user_agent)
                VALUES (NULL, 'failed_login', ?, ?, ?)
            ");
            
            $stmt->execute([
                "Failed admin login attempt for {$email}: {$reason}",
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            Logger::error('Failed to log failed login: ' . $e->getMessage());
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