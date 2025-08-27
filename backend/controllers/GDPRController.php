<?php
/**
 * GDPR Controller for Data Protection Rights
 * Handles GDPR data requests, legal consents, and user data management
 */

class GDPRController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Submit a GDPR data request
     */
    public function submitDataRequest() {
        try {
            $data = Request::getJson();
            
            // Validate required fields
            $required = ['email', 'request_type'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    Response::badRequest("Field '$field' is required");
                }
            }
            
            // Validate email
            $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
            if (!$email) {
                Response::badRequest("Invalid email address");
            }
            
            // Validate request type
            $validTypes = ['data_export', 'data_deletion', 'data_correction', 'data_portability', 'processing_restriction'];
            if (!in_array($data['request_type'], $validTypes)) {
                Response::badRequest("Invalid request type");
            }
            
            // Check for existing user
            $userSql = "SELECT id FROM users WHERE email = ?";
            $userStmt = $this->db->prepare($userSql);
            $userStmt->execute([$email]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            // Rate limiting - max 3 requests per email per day
            $rateLimitSql = "SELECT COUNT(*) as count FROM gdpr_requests 
                            WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)";
            $rateLimitStmt = $this->db->prepare($rateLimitSql);
            $rateLimitStmt->execute([$email]);
            $recentRequests = $rateLimitStmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($recentRequests >= 3) {
                Response::tooManyRequests("Too many requests. Please try again tomorrow.");
            }
            
            // Generate verification token
            $verificationToken = bin2hex(random_bytes(32));
            $verificationExpiry = date('Y-m-d H:i:s', strtotime('+48 hours'));
            
            // Insert GDPR request
            $requestData = [
                'user_id' => $user ? $user['id'] : null,
                'request_type' => $data['request_type'],
                'email' => $email,
                'verification_token' => $verificationToken,
                'verification_expires_at' => $verificationExpiry,
                'request_details' => json_encode($data['details'] ?? [])
            ];
            
            $sql = "INSERT INTO gdpr_requests (" . implode(',', array_keys($requestData)) . ") 
                    VALUES (" . str_repeat('?,', count($requestData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($requestData));
            $requestId = $this->db->lastInsertId();
            
            // Send verification email
            $this->sendGDPRVerificationEmail($email, $verificationToken, $data['request_type']);
            
            Logger::info("GDPR data request submitted", [
                'request_id' => $requestId,
                'email' => $email,
                'type' => $data['request_type'],
                'user_id' => $user ? $user['id'] : null
            ]);
            
            Response::success([
                'message' => 'Your GDPR request has been submitted. Please check your email to verify the request.',
                'request_id' => $requestId
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error submitting GDPR request: " . $e->getMessage());
            Response::serverError("Failed to submit GDPR request");
        }
    }
    
    /**
     * Verify GDPR data request
     */
    public function verifyDataRequest($params) {
        try {
            $token = $params['token'];
            
            // Find request by token
            $requestSql = "SELECT * FROM gdpr_requests 
                          WHERE verification_token = ? AND status = 'pending' 
                          AND verification_expires_at > NOW()";
            $requestStmt = $this->db->prepare($requestSql);
            $requestStmt->execute([$token]);
            $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                Response::notFound("Invalid or expired verification token");
            }
            
            // Update request status
            $updateSql = "UPDATE gdpr_requests SET status = 'verified', updated_at = NOW() WHERE id = ?";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([$request['id']]);
            
            // Create admin notification
            $this->createAdminNotification($request);
            
            Logger::info("GDPR request verified", [
                'request_id' => $request['id'],
                'email' => $request['email'],
                'type' => $request['request_type']
            ]);
            
            Response::success(['message' => 'Your GDPR request has been verified and will be processed within 30 days.']);
            
        } catch (Exception $e) {
            Logger::error("Error verifying GDPR request: " . $e->getMessage());
            Response::serverError("Failed to verify GDPR request");
        }
    }
    
    /**
     * Get GDPR requests (admin only)
     */
    public function getDataRequests() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $page = (int)Request::get('page', 1);
            $limit = (int)Request::get('limit', 20);
            $offset = ($page - 1) * $limit;
            $status = Request::get('status');
            $type = Request::get('type');
            
            $where = [];
            $values = [];
            
            if ($status) {
                $where[] = 'status = ?';
                $values[] = $status;
            }
            
            if ($type) {
                $where[] = 'request_type = ?';
                $values[] = $type;
            }
            
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM gdpr_requests $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($values);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get requests
            $sql = "SELECT g.*, 
                           u1.username as user_username,
                           u2.username as processed_by_username
                    FROM gdpr_requests g 
                    LEFT JOIN users u1 ON g.user_id = u1.id
                    LEFT JOIN users u2 ON g.processed_by = u2.id
                    $whereClause 
                    ORDER BY g.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$values, $limit, $offset]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse JSON fields
            foreach ($requests as &$request) {
                if ($request['request_details']) {
                    $request['request_details'] = json_decode($request['request_details'], true);
                }
            }
            
            Response::success([
                'requests' => $requests,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error getting GDPR requests: " . $e->getMessage());
            Response::serverError("Failed to retrieve GDPR requests");
        }
    }
    
    /**
     * Process GDPR data request (admin only)
     */
    public function processDataRequest($params) {
        try {
            $requestId = $params['id'];
            $data = Request::getJson();
            $currentUser = AuthMiddleware::getCurrentUser();
            
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            // Get request
            $requestSql = "SELECT * FROM gdpr_requests WHERE id = ?";
            $requestStmt = $this->db->prepare($requestSql);
            $requestStmt->execute([$requestId]);
            $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                Response::notFound("GDPR request not found");
            }
            
            if ($request['status'] === 'completed') {
                Response::badRequest("Request already completed");
            }
            
            $action = $data['action']; // 'approve', 'reject', 'process'
            $notes = $data['notes'] ?? '';
            
            switch ($action) {
                case 'approve':
                    $this->approveGDPRRequest($request, $currentUser, $notes);
                    break;
                
                case 'reject':
                    $this->rejectGDPRRequest($request, $currentUser, $notes);
                    break;
                
                case 'process':
                    $this->processGDPRRequestData($request, $currentUser, $notes);
                    break;
                
                default:
                    Response::badRequest("Invalid action");
            }
            
            Response::success(['message' => 'GDPR request processed successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error processing GDPR request: " . $e->getMessage());
            Response::serverError("Failed to process GDPR request");
        }
    }
    
    /**
     * Get user's personal data (for data export)
     */
    public function getUserData($params) {
        try {
            $requestId = $params['id'];
            $currentUser = AuthMiddleware::getCurrentUser();
            
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            // Get request
            $requestSql = "SELECT * FROM gdpr_requests WHERE id = ? AND request_type = 'data_export'";
            $requestStmt = $this->db->prepare($requestSql);
            $requestStmt->execute([$requestId]);
            $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request || !$request['user_id']) {
                Response::notFound("Data export request not found or user not identified");
            }
            
            $userId = $request['user_id'];
            $userData = $this->collectUserData($userId);
            
            Response::success($userData);
            
        } catch (Exception $e) {
            Logger::error("Error getting user data: " . $e->getMessage());
            Response::serverError("Failed to retrieve user data");
        }
    }
    
    /**
     * Record legal consent
     */
    public function recordConsent() {
        try {
            $data = Request::getJson();
            $currentUser = AuthMiddleware::getCurrentUser();
            
            $required = ['consent_type', 'consent_version', 'is_consented'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    Response::badRequest("Field '$field' is required");
                }
            }
            
            $validTypes = ['terms', 'privacy', 'cookies', 'marketing', 'data_processing'];
            if (!in_array($data['consent_type'], $validTypes)) {
                Response::badRequest("Invalid consent type");
            }
            
            $consentData = [
                'user_id' => $currentUser ? $currentUser['id'] : null,
                'consent_type' => $data['consent_type'],
                'consent_version' => $data['consent_version'],
                'is_consented' => (bool)$data['is_consented'],
                'consent_method' => $data['method'] ?? 'form',
                'consent_data' => json_encode($data['additional_data'] ?? []),
                'ip_address' => Request::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ];
            
            $sql = "INSERT INTO legal_consents (" . implode(',', array_keys($consentData)) . ") 
                    VALUES (" . str_repeat('?,', count($consentData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($consentData));
            $consentId = $this->db->lastInsertId();
            
            Logger::info("Legal consent recorded", [
                'consent_id' => $consentId,
                'user_id' => $currentUser ? $currentUser['id'] : null,
                'type' => $data['consent_type'],
                'consented' => $data['is_consented']
            ]);
            
            Response::success(['consent_id' => $consentId, 'message' => 'Consent recorded successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error recording consent: " . $e->getMessage());
            Response::serverError("Failed to record consent");
        }
    }
    
    /**
     * Get consent history for user
     */
    public function getConsentHistory() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            if (!$currentUser) {
                Response::unauthorized("Authentication required");
            }
            
            $sql = "SELECT consent_type, consent_version, is_consented, consent_method, created_at, revoked_at
                    FROM legal_consents 
                    WHERE user_id = ? 
                    ORDER BY consent_type, created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$currentUser['id']]);
            $consents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group by consent type to show current status
            $grouped = [];
            foreach ($consents as $consent) {
                $type = $consent['consent_type'];
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [
                        'current_status' => $consent,
                        'history' => []
                    ];
                }
                $grouped[$type]['history'][] = $consent;
            }
            
            Response::success($grouped);
            
        } catch (Exception $e) {
            Logger::error("Error getting consent history: " . $e->getMessage());
            Response::serverError("Failed to retrieve consent history");
        }
    }
    
    /**
     * Approve GDPR request
     */
    private function approveGDPRRequest($request, $admin, $notes) {
        $updateSql = "UPDATE gdpr_requests 
                     SET status = 'processing', 
                         processed_by = ?, 
                         processed_at = NOW(),
                         processing_notes = ?
                     WHERE id = ?";
        
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->execute([$admin['id'], $notes, $request['id']]);
        
        // Send notification email to user
        $this->sendGDPRStatusEmail($request['email'], 'approved', $request['request_type']);
        
        AdminAuditLogger::log($admin['id'], 'approve_gdpr_request', 'gdpr_requests', $request['id'], 
            "Approved GDPR {$request['request_type']} request");
    }
    
    /**
     * Reject GDPR request
     */
    private function rejectGDPRRequest($request, $admin, $notes) {
        $updateSql = "UPDATE gdpr_requests 
                     SET status = 'rejected', 
                         processed_by = ?, 
                         processed_at = NOW(),
                         processing_notes = ?
                     WHERE id = ?";
        
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->execute([$admin['id'], $notes, $request['id']]);
        
        // Send notification email to user
        $this->sendGDPRStatusEmail($request['email'], 'rejected', $request['request_type'], $notes);
        
        AdminAuditLogger::log($admin['id'], 'reject_gdpr_request', 'gdpr_requests', $request['id'], 
            "Rejected GDPR {$request['request_type']} request: $notes");
    }
    
    /**
     * Process GDPR request data
     */
    private function processGDPRRequestData($request, $admin, $notes) {
        switch ($request['request_type']) {
            case 'data_export':
                $this->generateDataExport($request, $admin);
                break;
                
            case 'data_deletion':
                $this->processDataDeletion($request, $admin);
                break;
                
            case 'data_correction':
                // Implementation depends on specific requirements
                break;
                
            default:
                throw new Exception("Unhandled request type: " . $request['request_type']);
        }
        
        $updateSql = "UPDATE gdpr_requests 
                     SET status = 'completed', 
                         completed_at = NOW(),
                         processing_notes = ?
                     WHERE id = ?";
        
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->execute([$notes, $request['id']]);
    }
    
    /**
     * Collect all user data for export
     */
    private function collectUserData($userId) {
        $userData = [
            'user_info' => [],
            'articles' => [],
            'messages' => [],
            'ratings' => [],
            'favorites' => [],
            'searches' => [],
            'support_tickets' => [],
            'consents' => []
        ];
        
        // User basic info
        $userSql = "SELECT * FROM users WHERE id = ?";
        $userStmt = $this->db->prepare($userSql);
        $userStmt->execute([$userId]);
        $userData['user_info'] = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        // User articles
        $articlesSql = "SELECT * FROM articles WHERE user_id = ?";
        $articlesStmt = $this->db->prepare($articlesSql);
        $articlesStmt->execute([$userId]);
        $userData['articles'] = $articlesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Messages (sent and received)
        $messagesSql = "SELECT * FROM messages WHERE sender_id = ? OR receiver_id = ?";
        $messagesStmt = $this->db->prepare($messagesSql);
        $messagesStmt->execute([$userId, $userId]);
        $userData['messages'] = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Continue with other data...
        
        return $userData;
    }
    
    /**
     * Generate data export file
     */
    private function generateDataExport($request, $admin) {
        if (!$request['user_id']) {
            throw new Exception("Cannot export data for unidentified user");
        }
        
        $userData = $this->collectUserData($request['user_id']);
        
        // Generate JSON export file
        $exportData = [
            'export_date' => date('Y-m-d H:i:s'),
            'user_id' => $request['user_id'],
            'request_id' => $request['id'],
            'data' => $userData
        ];
        
        $filename = "user_data_export_{$request['user_id']}_" . date('Y-m-d_His') . ".json";
        $filepath = "/tmp/gdpr_exports/$filename";
        
        // Ensure directory exists
        if (!is_dir('/tmp/gdpr_exports')) {
            mkdir('/tmp/gdpr_exports', 0750, true);
        }
        
        file_put_contents($filepath, json_encode($exportData, JSON_PRETTY_PRINT));
        
        // Update request with download URL
        $downloadUrl = "/api/v1/gdpr/download-export/" . basename($filename);
        
        $updateSql = "UPDATE gdpr_requests SET data_export_url = ? WHERE id = ?";
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->execute([$downloadUrl, $request['id']]);
    }
    
    /**
     * Process data deletion
     */
    private function processDataDeletion($request, $admin) {
        if (!$request['user_id']) {
            throw new Exception("Cannot delete data for unidentified user");
        }
        
        $userId = $request['user_id'];
        
        // This is a critical operation - implement carefully
        // May need to anonymize rather than delete to maintain data integrity
        
        // Example implementation:
        // 1. Deactivate user account
        // 2. Anonymize personal data
        // 3. Remove or anonymize associated content
        
        $this->db->beginTransaction();
        
        try {
            // Anonymize user data
            $anonymizeUserSql = "UPDATE users 
                               SET email = CONCAT('deleted_', id, '@deleted.local'),
                                   username = CONCAT('deleted_user_', id),
                                   first_name = 'Deleted',
                                   last_name = 'User',
                                   phone = NULL,
                                   avatar_url = NULL,
                                   status = 'deleted'
                               WHERE id = ?";
            
            $stmt = $this->db->prepare($anonymizeUserSql);
            $stmt->execute([$userId]);
            
            // Handle articles - might anonymize rather than delete
            $anonymizeArticlesSql = "UPDATE articles 
                                   SET title = 'Deleted Article',
                                       description = 'This content has been removed.',
                                       status = 'archived'
                                   WHERE user_id = ?";
            
            $stmt = $this->db->prepare($anonymizeArticlesSql);
            $stmt->execute([$userId]);
            
            $this->db->commit();
            
            Logger::info("User data anonymized for GDPR deletion", [
                'user_id' => $userId,
                'request_id' => $request['id']
            ]);
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Create admin notification for new GDPR request
     */
    private function createAdminNotification($request) {
        $notificationSql = "INSERT INTO admin_notifications 
                           (type, title, message, data, severity, created_at) 
                           VALUES (?, ?, ?, ?, ?, NOW())";
        
        $title = "New GDPR " . ucfirst(str_replace('_', ' ', $request['request_type'])) . " Request";
        $message = "A new GDPR {$request['request_type']} request has been submitted by {$request['email']}";
        $data = json_encode(['request_id' => $request['id'], 'email' => $request['email']]);
        
        $stmt = $this->db->prepare($notificationSql);
        $stmt->execute(['user_report', $title, $message, $data, 'medium']);
    }
    
    /**
     * Send GDPR verification email
     */
    private function sendGDPRVerificationEmail($email, $token, $requestType) {
        // Email implementation would go here
        Logger::info("GDPR verification email sent", [
            'email' => $email,
            'type' => $requestType
        ]);
    }
    
    /**
     * Send GDPR status update email
     */
    private function sendGDPRStatusEmail($email, $status, $requestType, $notes = null) {
        Logger::info("GDPR status email sent", [
            'email' => $email,
            'status' => $status,
            'type' => $requestType
        ]);
    }
}