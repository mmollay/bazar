<?php
/**
 * Support Controller for Ticket Management System
 * Handles support tickets, contact forms, and customer service functionality
 */

class SupportController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Create a new support ticket
     */
    public function createTicket() {
        try {
            $data = Request::getJson();
            $currentUser = AuthMiddleware::getCurrentUser();
            
            // Validate required fields
            $required = ['name', 'email', 'category', 'subject', 'description'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    Response::badRequest("Field '$field' is required");
                }
            }
            
            // Validate category
            $validCategories = ['general', 'technical', 'billing', 'account', 'content', 'legal', 'bug_report', 'feature_request'];
            if (!in_array($data['category'], $validCategories)) {
                Response::badRequest("Invalid category");
            }
            
            // Generate unique ticket number
            $ticketNumber = $this->generateTicketNumber();
            
            // Prepare ticket data
            $ticketData = [
                'ticket_number' => $ticketNumber,
                'user_id' => $currentUser ? $currentUser['id'] : null,
                'email' => filter_var($data['email'], FILTER_VALIDATE_EMAIL),
                'name' => trim($data['name']),
                'category' => $data['category'],
                'priority' => $data['priority'] ?? 'normal',
                'subject' => trim($data['subject']),
                'description' => trim($data['description']),
                'related_article_id' => isset($data['article_id']) ? (int)$data['article_id'] : null,
                'related_user_id' => isset($data['related_user_id']) ? (int)$data['related_user_id'] : null,
                'attachment_urls' => isset($data['attachments']) ? json_encode($data['attachments']) : null
            ];
            
            if (!$ticketData['email']) {
                Response::badRequest("Invalid email address");
            }
            
            // Insert ticket
            $sql = "INSERT INTO support_tickets (" . implode(',', array_keys($ticketData)) . ") 
                    VALUES (" . str_repeat('?,', count($ticketData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($ticketData));
            $ticketId = $this->db->lastInsertId();
            
            // Create initial system message
            $this->addTicketMessage($ticketId, 'system', null, 'System', 'system@bazar.com', 
                'Ticket created successfully. We will respond within 24 hours.', 'message');
            
            // Send confirmation email
            $this->sendTicketConfirmationEmail($ticketData, $ticketId);
            
            // Auto-assign based on category if rules exist
            $this->autoAssignTicket($ticketId, $data['category']);
            
            Logger::info("Support ticket created", [
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'category' => $data['category'],
                'user_id' => $currentUser ? $currentUser['id'] : null
            ]);
            
            Response::success([
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'message' => 'Ticket created successfully'
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error creating support ticket: " . $e->getMessage());
            Response::serverError("Failed to create ticket");
        }
    }
    
    /**
     * Get support tickets (with different views for users vs admins)
     */
    public function getTickets() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            $page = (int)Request::get('page', 1);
            $limit = (int)Request::get('limit', 20);
            $offset = ($page - 1) * $limit;
            $status = Request::get('status');
            $category = Request::get('category');
            $priority = Request::get('priority');
            $search = Request::get('search');
            
            $where = [];
            $values = [];
            
            // User can only see their own tickets, admins can see all
            if (!$currentUser || !$currentUser['is_admin']) {
                if (!$currentUser) {
                    Response::unauthorized("Authentication required");
                }
                $where[] = 'user_id = ?';
                $values[] = $currentUser['id'];
            }
            
            // Apply filters
            if ($status) {
                $where[] = 'status = ?';
                $values[] = $status;
            }
            
            if ($category) {
                $where[] = 'category = ?';
                $values[] = $category;
            }
            
            if ($priority) {
                $where[] = 'priority = ?';
                $values[] = $priority;
            }
            
            if ($search) {
                $where[] = '(subject LIKE ? OR description LIKE ? OR ticket_number LIKE ?)';
                $searchTerm = "%$search%";
                $values[] = $searchTerm;
                $values[] = $searchTerm;
                $values[] = $searchTerm;
            }
            
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM support_tickets $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($values);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get tickets
            $sql = "SELECT t.*, 
                           u.username as assigned_to_username,
                           COUNT(m.id) as message_count,
                           MAX(m.created_at) as last_message_at
                    FROM support_tickets t 
                    LEFT JOIN users u ON t.assigned_to = u.id
                    LEFT JOIN support_ticket_messages m ON t.id = m.ticket_id
                    $whereClause 
                    GROUP BY t.id
                    ORDER BY t.priority DESC, t.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$values, $limit, $offset]);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse JSON fields
            foreach ($tickets as &$ticket) {
                if ($ticket['attachment_urls']) {
                    $ticket['attachment_urls'] = json_decode($ticket['attachment_urls'], true);
                }
            }
            
            Response::success([
                'tickets' => $tickets,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error getting support tickets: " . $e->getMessage());
            Response::serverError("Failed to retrieve tickets");
        }
    }
    
    /**
     * Get a specific ticket with messages
     */
    public function getTicket($params) {
        try {
            $ticketId = $params['id'];
            $currentUser = AuthMiddleware::getCurrentUser();
            
            // Get ticket details
            $ticketSql = "SELECT t.*, 
                                 u1.username as assigned_to_username,
                                 u1.email as assigned_to_email,
                                 u2.username as customer_username
                          FROM support_tickets t 
                          LEFT JOIN users u1 ON t.assigned_to = u1.id
                          LEFT JOIN users u2 ON t.user_id = u2.id
                          WHERE t.id = ?";
            
            $ticketStmt = $this->db->prepare($ticketSql);
            $ticketStmt->execute([$ticketId]);
            $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ticket) {
                Response::notFound("Ticket not found");
            }
            
            // Check permissions
            if (!$currentUser) {
                Response::unauthorized("Authentication required");
            }
            
            if (!$currentUser['is_admin'] && $ticket['user_id'] !== $currentUser['id']) {
                Response::forbidden("Access denied");
            }
            
            // Get ticket messages
            $messagesSql = "SELECT m.*, u.username as sender_username, u.avatar_url as sender_avatar
                           FROM support_ticket_messages m
                           LEFT JOIN users u ON m.sender_id = u.id
                           WHERE m.ticket_id = ? 
                           " . (!$currentUser['is_admin'] ? "AND m.is_internal = 0" : "") . "
                           ORDER BY m.created_at ASC";
            
            $messagesStmt = $this->db->prepare($messagesSql);
            $messagesStmt->execute([$ticketId]);
            $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse JSON fields
            if ($ticket['attachment_urls']) {
                $ticket['attachment_urls'] = json_decode($ticket['attachment_urls'], true);
            }
            
            foreach ($messages as &$message) {
                if ($message['attachment_urls']) {
                    $message['attachment_urls'] = json_decode($message['attachment_urls'], true);
                }
            }
            
            $ticket['messages'] = $messages;
            
            Response::success($ticket);
            
        } catch (Exception $e) {
            Logger::error("Error getting support ticket: " . $e->getMessage());
            Response::serverError("Failed to retrieve ticket");
        }
    }
    
    /**
     * Add a message to a ticket
     */
    public function addTicketMessage($params) {
        try {
            $ticketId = is_array($params) ? $params['id'] : $params;
            $data = Request::getJson();
            $currentUser = AuthMiddleware::getCurrentUser();
            
            if (!$currentUser) {
                Response::unauthorized("Authentication required");
            }
            
            // Get ticket to check permissions
            $ticketSql = "SELECT * FROM support_tickets WHERE id = ?";
            $ticketStmt = $this->db->prepare($ticketSql);
            $ticketStmt->execute([$ticketId]);
            $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ticket) {
                Response::notFound("Ticket not found");
            }
            
            // Check permissions
            $isAdmin = $currentUser['is_admin'];
            $isCustomer = $ticket['user_id'] === $currentUser['id'];
            
            if (!$isAdmin && !$isCustomer) {
                Response::forbidden("Access denied");
            }
            
            // Validate message
            if (empty($data['message'])) {
                Response::badRequest("Message content is required");
            }
            
            // Determine sender type and visibility
            $senderType = $isAdmin ? 'admin' : 'customer';
            $isInternal = isset($data['internal']) && $data['internal'] && $isAdmin;
            
            $messageId = $this->addTicketMessage(
                $ticketId,
                $senderType,
                $currentUser['id'],
                $currentUser['username'] ?? $currentUser['first_name'] . ' ' . $currentUser['last_name'],
                $currentUser['email'],
                $data['message'],
                'message',
                $isInternal,
                $data['attachments'] ?? null
            );
            
            // Update ticket status if needed
            if ($ticket['status'] === 'pending' && !$isAdmin) {
                // Customer replied, set back to open
                $this->updateTicketStatus($ticketId, 'open');
            } elseif ($isAdmin && $ticket['status'] === 'open') {
                // Admin replied, set to pending customer response
                $this->updateTicketStatus($ticketId, 'pending');
            }
            
            // Set first response time if this is the first admin response
            if ($isAdmin && !$ticket['first_response_at']) {
                $updateSql = "UPDATE support_tickets SET first_response_at = NOW() WHERE id = ?";
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->execute([$ticketId]);
            }
            
            // Send notification email
            if (!$isInternal) {
                $this->sendTicketReplyNotification($ticket, $data['message'], $isAdmin);
            }
            
            Response::success(['message_id' => $messageId, 'message' => 'Message added successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error adding ticket message: " . $e->getMessage());
            Response::serverError("Failed to add message");
        }
    }
    
    /**
     * Update ticket status (admin only)
     */
    public function updateTicketStatus($params) {
        try {
            $ticketId = is_array($params) ? $params['id'] : $params;
            $data = Request::getJson();
            $currentUser = AuthMiddleware::getCurrentUser();
            
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $status = $data['status'] ?? null;
            $validStatuses = ['open', 'assigned', 'pending', 'resolved', 'closed'];
            
            if (!in_array($status, $validStatuses)) {
                Response::badRequest("Invalid status");
            }
            
            // Get current ticket
            $ticketSql = "SELECT * FROM support_tickets WHERE id = ?";
            $ticketStmt = $this->db->prepare($ticketSql);
            $ticketStmt->execute([$ticketId]);
            $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ticket) {
                Response::notFound("Ticket not found");
            }
            
            // Update ticket
            $updateData = ['status' => $status];
            
            if ($status === 'resolved') {
                $updateData['resolved_at'] = date('Y-m-d H:i:s');
            } elseif ($status === 'closed') {
                $updateData['closed_at'] = date('Y-m-d H:i:s');
                if (!$ticket['resolved_at']) {
                    $updateData['resolved_at'] = date('Y-m-d H:i:s');
                }
            }
            
            $setParts = [];
            foreach ($updateData as $field => $value) {
                $setParts[] = "$field = ?";
            }
            
            $updateSql = "UPDATE support_tickets SET " . implode(', ', $setParts) . " WHERE id = ?";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([...array_values($updateData), $ticketId]);
            
            // Add system message
            $statusMessage = "Ticket status changed to: " . ucfirst($status);
            $this->addTicketMessage($ticketId, 'system', null, 'System', 'system@bazar.com', 
                $statusMessage, 'status_change');
            
            // Log admin action
            AdminAuditLogger::log($currentUser['id'], 'update_ticket_status', 'support_tickets', $ticketId, 
                "Changed status to: $status");
            
            Response::success(['message' => 'Ticket status updated successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error updating ticket status: " . $e->getMessage());
            Response::serverError("Failed to update ticket status");
        }
    }
    
    /**
     * Assign ticket to admin user (admin only)
     */
    public function assignTicket($params) {
        try {
            $ticketId = $params['id'];
            $data = Request::getJson();
            $currentUser = AuthMiddleware::getCurrentUser();
            
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $assignedTo = isset($data['assigned_to']) ? (int)$data['assigned_to'] : null;
            
            // Validate assigned user is admin
            if ($assignedTo) {
                $userSql = "SELECT id, username FROM users WHERE id = ? AND is_admin = 1";
                $userStmt = $this->db->prepare($userSql);
                $userStmt->execute([$assignedTo]);
                $assignedUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$assignedUser) {
                    Response::badRequest("Invalid admin user for assignment");
                }
            }
            
            // Update ticket
            $updateSql = "UPDATE support_tickets SET assigned_to = ?, status = ? WHERE id = ?";
            $newStatus = $assignedTo ? 'assigned' : 'open';
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([$assignedTo, $newStatus, $ticketId]);
            
            // Add system message
            $assignmentMessage = $assignedTo 
                ? "Ticket assigned to: " . $assignedUser['username']
                : "Ticket unassigned";
            
            $this->addTicketMessage($ticketId, 'system', null, 'System', 'system@bazar.com', 
                $assignmentMessage, 'assignment');
            
            // Log admin action
            AdminAuditLogger::log($currentUser['id'], 'assign_ticket', 'support_tickets', $ticketId, 
                $assignmentMessage);
            
            Response::success(['message' => 'Ticket assignment updated successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error assigning ticket: " . $e->getMessage());
            Response::serverError("Failed to assign ticket");
        }
    }
    
    /**
     * Submit contact form
     */
    public function submitContactForm() {
        try {
            $data = Request::getJson();
            
            // Validate required fields
            $required = ['name', 'email', 'subject', 'message'];
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
            
            // Rate limiting by IP
            $ipAddress = Request::getClientIp();
            $rateLimitSql = "SELECT COUNT(*) as count FROM contact_submissions 
                            WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            $rateLimitStmt = $this->db->prepare($rateLimitSql);
            $rateLimitStmt->execute([$ipAddress]);
            $recentSubmissions = $rateLimitStmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($recentSubmissions >= 3) {
                Response::tooManyRequests("Too many submissions. Please try again later.");
            }
            
            // Insert contact submission
            $submissionData = [
                'name' => trim($data['name']),
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'subject' => trim($data['subject']),
                'message' => trim($data['message']),
                'contact_reason' => $data['reason'] ?? 'general',
                'ip_address' => $ipAddress,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ];
            
            $sql = "INSERT INTO contact_submissions (" . implode(',', array_keys($submissionData)) . ") 
                    VALUES (" . str_repeat('?,', count($submissionData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($submissionData));
            $submissionId = $this->db->lastInsertId();
            
            // Send confirmation email
            $this->sendContactConfirmationEmail($submissionData);
            
            // Notify admin
            $this->notifyAdminNewContact($submissionData, $submissionId);
            
            Logger::info("Contact form submitted", [
                'submission_id' => $submissionId,
                'email' => $email,
                'reason' => $submissionData['contact_reason']
            ]);
            
            Response::success(['message' => 'Thank you for your message. We will get back to you soon.']);
            
        } catch (Exception $e) {
            Logger::error("Error submitting contact form: " . $e->getMessage());
            Response::serverError("Failed to submit contact form");
        }
    }
    
    /**
     * Get support statistics (admin only)
     */
    public function getSupportStats() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $period = Request::get('period', '30'); // days
            $startDate = date('Y-m-d', strtotime("-$period days"));
            
            // Ticket statistics
            $ticketStatsSql = "SELECT 
                                 COUNT(*) as total_tickets,
                                 COUNT(CASE WHEN status = 'open' THEN 1 END) as open_tickets,
                                 COUNT(CASE WHEN status = 'assigned' THEN 1 END) as assigned_tickets,
                                 COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_tickets,
                                 COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_tickets,
                                 COUNT(CASE WHEN status = 'closed' THEN 1 END) as closed_tickets,
                                 AVG(CASE WHEN first_response_at IS NOT NULL 
                                          THEN TIMESTAMPDIFF(HOUR, created_at, first_response_at) 
                                          ELSE NULL END) as avg_first_response_hours,
                                 AVG(CASE WHEN resolved_at IS NOT NULL 
                                          THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) 
                                          ELSE NULL END) as avg_resolution_hours
                               FROM support_tickets 
                               WHERE created_at >= ?";
            
            $ticketStatsStmt = $this->db->prepare($ticketStatsSql);
            $ticketStatsStmt->execute([$startDate]);
            $ticketStats = $ticketStatsStmt->fetch(PDO::FETCH_ASSOC);
            
            // Category breakdown
            $categorySql = "SELECT category, COUNT(*) as count 
                           FROM support_tickets 
                           WHERE created_at >= ? 
                           GROUP BY category 
                           ORDER BY count DESC";
            
            $categoryStmt = $this->db->prepare($categorySql);
            $categoryStmt->execute([$startDate]);
            $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Daily ticket trends
            $trendSql = "SELECT 
                           DATE(created_at) as date,
                           COUNT(*) as tickets_created,
                           COUNT(CASE WHEN resolved_at IS NOT NULL AND DATE(resolved_at) = DATE(created_at) THEN 1 END) as tickets_resolved
                         FROM support_tickets 
                         WHERE created_at >= ? 
                         GROUP BY DATE(created_at) 
                         ORDER BY date";
            
            $trendStmt = $this->db->prepare($trendSql);
            $trendStmt->execute([$startDate]);
            $trends = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success([
                'period_days' => (int)$period,
                'ticket_statistics' => $ticketStats,
                'category_breakdown' => $categories,
                'daily_trends' => $trends
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error getting support stats: " . $e->getMessage());
            Response::serverError("Failed to retrieve support statistics");
        }
    }
    
    /**
     * Helper function to add a ticket message
     */
    private function addTicketMessage($ticketId, $senderType, $senderId, $senderName, $senderEmail, $message, $messageType = 'message', $isInternal = false, $attachments = null) {
        $messageData = [
            'ticket_id' => $ticketId,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'message' => $message,
            'message_type' => $messageType,
            'is_internal' => $isInternal,
            'attachment_urls' => $attachments ? json_encode($attachments) : null
        ];
        
        $sql = "INSERT INTO support_ticket_messages (" . implode(',', array_keys($messageData)) . ") 
                VALUES (" . str_repeat('?,', count($messageData) - 1) . "?)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($messageData));
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Generate unique ticket number
     */
    private function generateTicketNumber() {
        $prefix = SystemSettings::get('support_ticket_prefix', 'BZ');
        $year = date('Y');
        
        // Get next number for this year
        $countSql = "SELECT COUNT(*) + 1 as next_number FROM support_tickets 
                     WHERE ticket_number LIKE ? AND YEAR(created_at) = ?";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute(["{$prefix}-{$year}-%", $year]);
        $nextNumber = $countStmt->fetch(PDO::FETCH_ASSOC)['next_number'];
        
        return sprintf("%s-%d-%06d", $prefix, $year, $nextNumber);
    }
    
    /**
     * Auto-assign ticket based on category rules
     */
    private function autoAssignTicket($ticketId, $category) {
        // Implementation depends on assignment rules
        // Could assign based on category, workload, expertise, etc.
        // For now, this is a placeholder
        
        // Example: Technical tickets go to specific admin
        if ($category === 'technical') {
            $techAdminSql = "SELECT id FROM users 
                            WHERE is_admin = 1 AND admin_role IN ('admin', 'support') 
                            ORDER BY RAND() LIMIT 1";
            $techAdminStmt = $this->db->prepare($techAdminSql);
            $techAdminStmt->execute();
            $techAdmin = $techAdminStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($techAdmin) {
                $assignSql = "UPDATE support_tickets SET assigned_to = ?, status = 'assigned' WHERE id = ?";
                $assignStmt = $this->db->prepare($assignSql);
                $assignStmt->execute([$techAdmin['id'], $ticketId]);
            }
        }
    }
    
    /**
     * Send ticket confirmation email
     */
    private function sendTicketConfirmationEmail($ticketData, $ticketId) {
        // Implementation would use email service
        // This is a placeholder for the email sending logic
        Logger::info("Ticket confirmation email sent", [
            'ticket_id' => $ticketId,
            'email' => $ticketData['email']
        ]);
    }
    
    /**
     * Send ticket reply notification
     */
    private function sendTicketReplyNotification($ticket, $message, $isAdminReply) {
        // Implementation would send notification to customer or admin
        Logger::info("Ticket reply notification sent", [
            'ticket_id' => $ticket['id'],
            'is_admin_reply' => $isAdminReply
        ]);
    }
    
    /**
     * Send contact form confirmation
     */
    private function sendContactConfirmationEmail($submissionData) {
        Logger::info("Contact form confirmation sent", [
            'email' => $submissionData['email']
        ]);
    }
    
    /**
     * Notify admin of new contact
     */
    private function notifyAdminNewContact($submissionData, $submissionId) {
        Logger::info("Admin notified of new contact", [
            'submission_id' => $submissionId,
            'reason' => $submissionData['contact_reason']
        ]);
    }
}