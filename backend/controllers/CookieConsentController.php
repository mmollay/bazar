<?php
/**
 * Cookie Consent Controller for GDPR Compliance
 * Handles cookie consent management, preference centers, and audit logging
 */

class CookieConsentController {
    private $db;
    private $consentVersion = '1.0'; // Current consent policy version
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get current consent status for user/session
     */
    public function getConsentStatus() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            $sessionId = Request::getSessionId();
            $ipAddress = Request::getClientIp();
            $browserFingerprint = $this->generateBrowserFingerprint();
            
            $consent = null;
            
            // Try to find existing consent
            if ($currentUser) {
                // For logged-in users
                $sql = "SELECT * FROM cookie_consents 
                        WHERE user_id = ? AND consent_withdrawn = 0 
                        AND expires_at > NOW() 
                        ORDER BY created_at DESC LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$currentUser['id']]);
                $consent = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                // For anonymous users - check by session, browser fingerprint, or IP
                $sql = "SELECT * FROM cookie_consents 
                        WHERE (session_id = ? OR browser_fingerprint = ? OR ip_address = ?) 
                        AND user_id IS NULL AND consent_withdrawn = 0 
                        AND expires_at > NOW() 
                        ORDER BY created_at DESC LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$sessionId, $browserFingerprint, $ipAddress]);
                $consent = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Check if consent version is current
            $consentValid = $consent && $consent['consent_version'] === $this->consentVersion;
            
            $response = [
                'hasConsent' => (bool)$consent,
                'consentValid' => $consentValid,
                'showBanner' => !$consentValid,
                'consentVersion' => $this->consentVersion,
                'preferences' => []
            ];
            
            if ($consent) {
                $response['preferences'] = [
                    'necessary' => (bool)$consent['necessary_cookies'],
                    'functional' => (bool)$consent['functional_cookies'],
                    'analytics' => (bool)$consent['analytics_cookies'],
                    'marketing' => (bool)$consent['marketing_cookies'],
                    'social' => (bool)$consent['social_cookies']
                ];
                $response['consentDate'] = $consent['created_at'];
                $response['expiryDate'] = $consent['expires_at'];
            }
            
            Response::success($response);
            
        } catch (Exception $e) {
            Logger::error("Error getting consent status: " . $e->getMessage());
            Response::serverError("Failed to retrieve consent status");
        }
    }
    
    /**
     * Save cookie consent preferences
     */
    public function saveConsent() {
        try {
            $data = Request::getJson();
            $currentUser = AuthMiddleware::getCurrentUser();
            $sessionId = Request::getSessionId();
            $ipAddress = Request::getClientIp();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $browserFingerprint = $this->generateBrowserFingerprint();
            
            // Validate consent data
            $consentData = [
                'necessary_cookies' => true, // Always true - required for functionality
                'functional_cookies' => isset($data['functional']) ? (bool)$data['functional'] : false,
                'analytics_cookies' => isset($data['analytics']) ? (bool)$data['analytics'] : false,
                'marketing_cookies' => isset($data['marketing']) ? (bool)$data['marketing'] : false,
                'social_cookies' => isset($data['social']) ? (bool)$data['social'] : false
            ];
            
            $consentMethod = $data['method'] ?? 'banner'; // banner, preferences, api
            
            // Set expiry date (13 months as per GDPR recommendations)
            $expiryDate = date('Y-m-d H:i:s', strtotime('+13 months'));
            
            // Withdraw any existing consent for this user/session
            if ($currentUser) {
                $withdrawSql = "UPDATE cookie_consents SET consent_withdrawn = 1, withdrawn_at = NOW() 
                               WHERE user_id = ? AND consent_withdrawn = 0";
                $withdrawStmt = $this->db->prepare($withdrawSql);
                $withdrawStmt->execute([$currentUser['id']]);
            } else {
                $withdrawSql = "UPDATE cookie_consents 
                               SET consent_withdrawn = 1, withdrawn_at = NOW() 
                               WHERE (session_id = ? OR browser_fingerprint = ? OR ip_address = ?) 
                               AND user_id IS NULL AND consent_withdrawn = 0";
                $withdrawStmt = $this->db->prepare($withdrawSql);
                $withdrawStmt->execute([$sessionId, $browserFingerprint, $ipAddress]);
            }
            
            // Insert new consent record
            $insertData = array_merge($consentData, [
                'user_id' => $currentUser ? $currentUser['id'] : null,
                'session_id' => $sessionId,
                'ip_address' => $ipAddress,
                'consent_version' => $this->consentVersion,
                'consent_method' => $consentMethod,
                'user_agent' => $userAgent,
                'browser_fingerprint' => $browserFingerprint,
                'consent_data' => json_encode([
                    'preferences' => $data,
                    'timestamp' => time(),
                    'user_agent' => $userAgent,
                    'referrer' => $_SERVER['HTTP_REFERER'] ?? null
                ]),
                'expires_at' => $expiryDate
            ]);
            
            $sql = "INSERT INTO cookie_consents (" . implode(',', array_keys($insertData)) . ") 
                    VALUES (" . str_repeat('?,', count($insertData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($insertData));
            
            $consentId = $this->db->lastInsertId();
            
            // Log consent for audit purposes
            Logger::info("Cookie consent saved", [
                'consent_id' => $consentId,
                'user_id' => $currentUser ? $currentUser['id'] : null,
                'ip_address' => $ipAddress,
                'preferences' => $consentData,
                'method' => $consentMethod
            ]);
            
            // Set response cookies based on preferences
            $this->setConsentCookies($consentData);
            
            Response::success([
                'message' => 'Consent preferences saved',
                'consentId' => $consentId,
                'expiryDate' => $expiryDate
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error saving consent: " . $e->getMessage());
            Response::serverError("Failed to save consent preferences");
        }
    }
    
    /**
     * Update consent preferences (for preference center)
     */
    public function updateConsent() {
        try {
            $data = Request::getJson();
            $currentUser = AuthMiddleware::getCurrentUser();
            $sessionId = Request::getSessionId();
            $ipAddress = Request::getClientIp();
            $browserFingerprint = $this->generateBrowserFingerprint();
            
            // Find existing consent
            $consent = null;
            if ($currentUser) {
                $sql = "SELECT * FROM cookie_consents 
                        WHERE user_id = ? AND consent_withdrawn = 0 
                        ORDER BY created_at DESC LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$currentUser['id']]);
                $consent = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $sql = "SELECT * FROM cookie_consents 
                        WHERE (session_id = ? OR browser_fingerprint = ?) 
                        AND user_id IS NULL AND consent_withdrawn = 0 
                        ORDER BY created_at DESC LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$sessionId, $browserFingerprint]);
                $consent = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$consent) {
                Response::notFound("No consent record found");
            }
            
            // Update consent preferences
            $updateData = [
                'functional_cookies' => isset($data['functional']) ? (bool)$data['functional'] : $consent['functional_cookies'],
                'analytics_cookies' => isset($data['analytics']) ? (bool)$data['analytics'] : $consent['analytics_cookies'],
                'marketing_cookies' => isset($data['marketing']) ? (bool)$data['marketing'] : $consent['marketing_cookies'],
                'social_cookies' => isset($data['social']) ? (bool)$data['social'] : $consent['social_cookies'],
                'consent_method' => 'preferences'
            ];
            
            $sql = "UPDATE cookie_consents SET " . 
                   implode(' = ?, ', array_keys($updateData)) . " = ? " .
                   "WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...array_values($updateData), $consent['id']]);
            
            // Update response cookies
            $allPreferences = array_merge(['necessary_cookies' => true], $updateData);
            $this->setConsentCookies($allPreferences);
            
            Logger::info("Cookie consent updated", [
                'consent_id' => $consent['id'],
                'user_id' => $currentUser ? $currentUser['id'] : null,
                'preferences' => $updateData
            ]);
            
            Response::success(['message' => 'Consent preferences updated']);
            
        } catch (Exception $e) {
            Logger::error("Error updating consent: " . $e->getMessage());
            Response::serverError("Failed to update consent preferences");
        }
    }
    
    /**
     * Withdraw consent (GDPR right to withdraw)
     */
    public function withdrawConsent() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            $sessionId = Request::getSessionId();
            $browserFingerprint = $this->generateBrowserFingerprint();
            
            if ($currentUser) {
                $sql = "UPDATE cookie_consents 
                        SET consent_withdrawn = 1, withdrawn_at = NOW() 
                        WHERE user_id = ? AND consent_withdrawn = 0";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$currentUser['id']]);
            } else {
                $sql = "UPDATE cookie_consents 
                        SET consent_withdrawn = 1, withdrawn_at = NOW() 
                        WHERE (session_id = ? OR browser_fingerprint = ?) 
                        AND user_id IS NULL AND consent_withdrawn = 0";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$sessionId, $browserFingerprint]);
            }
            
            // Clear consent cookies
            $this->clearConsentCookies();
            
            Logger::info("Cookie consent withdrawn", [
                'user_id' => $currentUser ? $currentUser['id'] : null,
                'session_id' => $sessionId
            ]);
            
            Response::success(['message' => 'Consent withdrawn successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error withdrawing consent: " . $e->getMessage());
            Response::serverError("Failed to withdraw consent");
        }
    }
    
    /**
     * Get consent audit log (admin only)
     */
    public function getConsentAudit() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $page = (int)Request::get('page', 1);
            $limit = (int)Request::get('limit', 50);
            $offset = ($page - 1) * $limit;
            $userId = Request::get('user_id');
            $startDate = Request::get('start_date');
            $endDate = Request::get('end_date');
            
            $where = [];
            $values = [];
            
            if ($userId) {
                $where[] = 'user_id = ?';
                $values[] = $userId;
            }
            
            if ($startDate) {
                $where[] = 'created_at >= ?';
                $values[] = $startDate . ' 00:00:00';
            }
            
            if ($endDate) {
                $where[] = 'created_at <= ?';
                $values[] = $endDate . ' 23:59:59';
            }
            
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM cookie_consents $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($values);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get consent records
            $sql = "SELECT c.*, u.email, u.username 
                    FROM cookie_consents c 
                    LEFT JOIN users u ON c.user_id = u.id 
                    $whereClause 
                    ORDER BY c.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$values, $limit, $offset]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse JSON data for better readability
            foreach ($records as &$record) {
                if ($record['consent_data']) {
                    $record['consent_data'] = json_decode($record['consent_data'], true);
                }
            }
            
            Response::success([
                'records' => $records,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error getting consent audit: " . $e->getMessage());
            Response::serverError("Failed to retrieve consent audit");
        }
    }
    
    /**
     * Get consent statistics (admin only)
     */
    public function getConsentStats() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $period = Request::get('period', '30'); // days
            $startDate = date('Y-m-d', strtotime("-$period days"));
            
            // Overall statistics
            $statsSql = "SELECT 
                           COUNT(*) as total_consents,
                           COUNT(CASE WHEN consent_withdrawn = 0 THEN 1 END) as active_consents,
                           COUNT(CASE WHEN consent_withdrawn = 1 THEN 1 END) as withdrawn_consents,
                           AVG(CASE WHEN functional_cookies = 1 THEN 100 ELSE 0 END) as functional_acceptance_rate,
                           AVG(CASE WHEN analytics_cookies = 1 THEN 100 ELSE 0 END) as analytics_acceptance_rate,
                           AVG(CASE WHEN marketing_cookies = 1 THEN 100 ELSE 0 END) as marketing_acceptance_rate,
                           AVG(CASE WHEN social_cookies = 1 THEN 100 ELSE 0 END) as social_acceptance_rate
                         FROM cookie_consents 
                         WHERE created_at >= ?";
            
            $statsStmt = $this->db->prepare($statsSql);
            $statsStmt->execute([$startDate]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
            
            // Daily consent trends
            $trendSql = "SELECT 
                           DATE(created_at) as date,
                           COUNT(*) as consents,
                           COUNT(CASE WHEN consent_withdrawn = 1 THEN 1 END) as withdrawals
                         FROM cookie_consents 
                         WHERE created_at >= ? 
                         GROUP BY DATE(created_at) 
                         ORDER BY date";
            
            $trendStmt = $this->db->prepare($trendSql);
            $trendStmt->execute([$startDate]);
            $trends = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Consent method breakdown
            $methodSql = "SELECT 
                            consent_method,
                            COUNT(*) as count,
                            ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM cookie_consents WHERE created_at >= ?), 2) as percentage
                          FROM cookie_consents 
                          WHERE created_at >= ? 
                          GROUP BY consent_method";
            
            $methodStmt = $this->db->prepare($methodSql);
            $methodStmt->execute([$startDate, $startDate]);
            $methods = $methodStmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success([
                'period_days' => (int)$period,
                'statistics' => $stats,
                'daily_trends' => $trends,
                'consent_methods' => $methods
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error getting consent stats: " . $e->getMessage());
            Response::serverError("Failed to retrieve consent statistics");
        }
    }
    
    /**
     * Generate browser fingerprint for anonymous users
     */
    private function generateBrowserFingerprint() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        
        $fingerprint = hash('sha256', $userAgent . $acceptLanguage . $acceptEncoding);
        return substr($fingerprint, 0, 64);
    }
    
    /**
     * Set consent cookies in browser
     */
    private function setConsentCookies($preferences) {
        $cookieOptions = [
            'expires' => time() + (365 * 24 * 60 * 60), // 1 year
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => false, // Needs to be accessible via JavaScript
            'samesite' => 'Lax'
        ];
        
        // Set individual preference cookies
        foreach ($preferences as $type => $enabled) {
            $cookieName = 'bazar_consent_' . str_replace('_cookies', '', $type);
            setcookie($cookieName, $enabled ? '1' : '0', $cookieOptions);
        }
        
        // Set consent status cookie
        setcookie('bazar_consent_status', 'given', $cookieOptions);
        setcookie('bazar_consent_version', $this->consentVersion, $cookieOptions);
    }
    
    /**
     * Clear all consent cookies
     */
    private function clearConsentCookies() {
        $cookieTypes = ['necessary', 'functional', 'analytics', 'marketing', 'social'];
        
        $clearOptions = [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => ''
        ];
        
        foreach ($cookieTypes as $type) {
            setcookie('bazar_consent_' . $type, '', $clearOptions);
        }
        
        setcookie('bazar_consent_status', '', $clearOptions);
        setcookie('bazar_consent_version', '', $clearOptions);
    }
}