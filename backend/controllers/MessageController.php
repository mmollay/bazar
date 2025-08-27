<?php
/**
 * Message Controller
 * Handles all messaging API endpoints
 */

class MessageController {
    private $messageModel;
    private $conversationModel;
    private $userModel;
    private $articleModel;
    private $notificationService;
    private $websocketService;
    
    public function __construct() {
        $this->messageModel = new Message();
        $this->conversationModel = new Conversation();
        $this->userModel = new User();
        $this->articleModel = new Article();
        $this->notificationService = new NotificationService();
        $this->websocketService = new WebSocketService();
    }
    
    /**
     * Get user's conversations
     * GET /v1/conversations
     */
    public function index($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized('Authentication required');
        }
        
        $page = (int)(Request::get('page', 1));
        $limit = min((int)(Request::get('limit', 20)), 50);
        $search = Request::get('search');
        
        try {
            if ($search) {
                $conversations = $this->conversationModel->searchConversations(
                    $userId, 
                    $search, 
                    $page, 
                    $limit
                );
            } else {
                $conversations = $this->conversationModel->getUserConversations(
                    $userId, 
                    $page, 
                    $limit
                );
            }
            
            // Get total unread count
            $totalUnread = $this->conversationModel->getUserUnreadCount($userId);
            
            Response::success([
                'conversations' => $conversations,
                'total_unread' => $totalUnread,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => count($conversations) == $limit
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get conversations", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to load conversations');
        }
    }
    
    /**
     * Get specific conversation with messages
     * GET /v1/conversations/{id}
     */
    public function getConversation($params) {
        $userId = $this->getCurrentUserId();
        $conversationId = $params['id'] ?? null;
        
        if (!$userId || !$conversationId) {
            Response::unauthorized();
        }
        
        // Check access
        if (!$this->conversationModel->hasAccess($conversationId, $userId)) {
            Response::forbidden('Access denied to this conversation');
        }
        
        $page = (int)(Request::get('page', 1));
        $limit = min((int)(Request::get('limit', 50)), 100);
        $beforeMessageId = Request::get('before');
        
        try {
            // Get conversation details
            $conversation = $this->conversationModel->getConversationDetails($conversationId, $userId);
            if (!$conversation) {
                Response::notFound('Conversation not found');
            }
            
            // Get messages
            $messages = $this->messageModel->getConversationMessages(
                $conversationId, 
                $page, 
                $limit, 
                $beforeMessageId
            );
            
            // Mark messages as read
            $this->conversationModel->markMessagesAsRead($conversationId, $userId);
            
            Response::success([
                'conversation' => $conversation,
                'messages' => $messages,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => count($messages) == $limit
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get conversation", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to load conversation');
        }
    }
    
    /**
     * Send a new message
     * POST /v1/conversations/{id}/messages
     */
    public function sendMessage($params) {
        $userId = $this->getCurrentUserId();
        $conversationId = $params['id'] ?? Request::input('conversation_id');
        
        if (!$userId) {
            Response::unauthorized();
        }
        
        // Validate input
        $data = Request::validate([
            'content' => 'required|max:2000',
            'message_type' => 'in:text,offer',
            'reply_to_message_id' => '',
            'offer_amount' => ''
        ]);
        
        // If no conversation_id provided, try to create/find one
        if (!$conversationId) {
            $articleId = Request::input('article_id');
            if (!$articleId) {
                Response::error('Conversation ID or Article ID required');
            }
            
            // Get article to find seller
            $article = $this->articleModel->find($articleId);
            if (!$article) {
                Response::notFound('Article not found');
            }
            
            if ($article['user_id'] == $userId) {
                Response::error('Cannot start conversation with yourself');
            }
            
            // Create or find conversation
            $conversation = $this->conversationModel->findOrCreate(
                $articleId, 
                $userId, // buyer
                $article['user_id'] // seller
            );
            
            if (!$conversation) {
                Response::serverError('Failed to create conversation');
            }
            
            $conversationId = $conversation['id'];
        }
        
        // Check access and if users are blocked
        if (!$this->conversationModel->hasAccess($conversationId, $userId)) {
            Response::forbidden('Access denied to this conversation');
        }
        
        $conversation = $this->conversationModel->find($conversationId);
        $otherUserId = ($conversation['buyer_id'] == $userId) 
            ? $conversation['seller_id'] 
            : $conversation['buyer_id'];
            
        if ($this->conversationModel->isBlocked($userId, $otherUserId)) {
            Response::error('Cannot send message to blocked user');
        }
        
        try {
            // Prepare message data
            $messageData = [
                'conversation_id' => $conversationId,
                'sender_id' => $userId,
                'content' => $data['content'],
                'message_type' => $data['message_type'] ?? 'text',
            ];
            
            if (!empty($data['reply_to_message_id'])) {
                $messageData['reply_to_message_id'] = $data['reply_to_message_id'];
            }
            
            // Handle offer messages
            if ($data['message_type'] === 'offer' && !empty($data['offer_amount'])) {
                $offerAmount = floatval($data['offer_amount']);
                $message = $this->messageModel->createOfferMessage(
                    $conversationId, 
                    $userId, 
                    $offerAmount, 
                    $data['content']
                );
            } else {
                $message = $this->messageModel->createMessage($messageData);
            }
            
            if (!$message) {
                Response::serverError('Failed to send message');
            }
            
            // Send real-time notification
            $this->websocketService->broadcastMessage($message, $conversationId);
            
            // Send push notification
            $this->notificationService->sendMessageNotification($message, $otherUserId);
            
            // Update typing status
            $this->conversationModel->updateTypingStatus($conversationId, $userId, false);
            
            Response::success([
                'message' => $message,
                'conversation_id' => $conversationId
            ], 'Message sent successfully');
            
        } catch (Exception $e) {
            Logger::error("Failed to send message", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to send message');
        }
    }
    
    /**
     * Mark message as read
     * PUT /v1/messages/{id}/read
     */
    public function markAsRead($params) {
        $userId = $this->getCurrentUserId();
        $messageId = $params['id'];
        
        if (!$userId || !$messageId) {
            Response::unauthorized();
        }
        
        try {
            $result = $this->messageModel->markAsRead($messageId, $userId);
            
            if ($result) {
                // Get message to broadcast read status
                $message = $this->messageModel->find($messageId);
                if ($message) {
                    $this->websocketService->broadcastReadReceipt($messageId, $userId, $message['conversation_id']);
                }
                
                Response::success([], 'Message marked as read');
            } else {
                Response::error('Failed to mark message as read');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to mark message as read", [
                'message_id' => $messageId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to mark message as read');
        }
    }
    
    /**
     * Edit message
     * PUT /v1/messages/{id}
     */
    public function editMessage($params) {
        $userId = $this->getCurrentUserId();
        $messageId = $params['id'];
        
        if (!$userId || !$messageId) {
            Response::unauthorized();
        }
        
        $data = Request::validate([
            'content' => 'required|max:2000'
        ]);
        
        try {
            $result = $this->messageModel->editMessage($messageId, $userId, $data['content']);
            
            if ($result) {
                $updatedMessage = $this->messageModel->getMessageWithSender($messageId);
                
                // Broadcast message update
                $this->websocketService->broadcastMessageUpdate($updatedMessage);
                
                Response::success([
                    'message' => $updatedMessage
                ], 'Message updated successfully');
            } else {
                Response::error('Failed to edit message or access denied');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to edit message", [
                'message_id' => $messageId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to edit message');
        }
    }
    
    /**
     * Delete message
     * DELETE /v1/messages/{id}
     */
    public function deleteMessage($params) {
        $userId = $this->getCurrentUserId();
        $messageId = $params['id'];
        
        if (!$userId || !$messageId) {
            Response::unauthorized();
        }
        
        try {
            $message = $this->messageModel->find($messageId);
            if (!$message) {
                Response::notFound('Message not found');
            }
            
            $result = $this->messageModel->deleteMessage($messageId, $userId);
            
            if ($result) {
                $updatedMessage = $this->messageModel->getMessageWithSender($messageId);
                
                // Broadcast message deletion
                $this->websocketService->broadcastMessageUpdate($updatedMessage);
                
                Response::success([], 'Message deleted successfully');
            } else {
                Response::error('Failed to delete message or access denied');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to delete message", [
                'message_id' => $messageId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to delete message');
        }
    }
    
    /**
     * Search messages
     * GET /v1/conversations/search
     */
    public function searchMessages($params) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        $query = Request::get('q');
        if (empty($query) || strlen($query) < 2) {
            Response::error('Search query must be at least 2 characters');
        }
        
        $conversationId = Request::get('conversation_id');
        $limit = min((int)(Request::get('limit', 50)), 100);
        
        $messageType = Request::get('type');
        $dateFrom = Request::get('date_from');
        $dateTo = Request::get('date_to');
        $senderId = Request::get('sender_id');
        $page = (int)(Request::get('page', 1));
        
        try {
            $filters = [
                'conversation_id' => $conversationId,
                'message_type' => $messageType,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'sender_id' => $senderId
            ];
            
            $results = $this->messageModel->searchMessagesAdvanced($userId, $query, $filters, $page, $limit);
            
            Response::success([
                'messages' => $results['messages'],
                'query' => $query,
                'filters' => array_filter($filters),
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $results['total'],
                    'pages' => ceil($results['total'] / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Failed to search messages", [
                'user_id' => $userId,
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to search messages');
        }
    }
    
    /**
     * Update typing status
     * POST /v1/conversations/{id}/typing
     */
    public function updateTypingStatus($params) {
        $userId = $this->getCurrentUserId();
        $conversationId = $params['id'];
        
        if (!$userId || !$conversationId) {
            Response::unauthorized();
        }
        
        $isTyping = Request::input('is_typing', false);
        
        try {
            $result = $this->conversationModel->updateTypingStatus($conversationId, $userId, $isTyping);
            
            if ($result) {
                // Broadcast typing status
                $this->websocketService->broadcastTypingStatus($conversationId, $userId, $isTyping);
                
                Response::success([], 'Typing status updated');
            } else {
                Response::error('Failed to update typing status');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to update typing status", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to update typing status');
        }
    }
    
    /**
     * Block user/conversation
     * POST /v1/conversations/{id}/block
     */
    public function blockConversation($params) {
        $userId = $this->getCurrentUserId();
        $conversationId = $params['id'];
        
        if (!$userId || !$conversationId) {
            Response::unauthorized();
        }
        
        $reason = Request::input('reason');
        
        try {
            $result = $this->conversationModel->block($conversationId, $userId, $reason);
            
            if ($result) {
                Response::success([], 'Conversation blocked successfully');
            } else {
                Response::error('Failed to block conversation');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to block conversation", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to block conversation');
        }
    }
    
    /**
     * Archive conversation
     * POST /v1/conversations/{id}/archive
     */
    public function archiveConversation($params) {
        $userId = $this->getCurrentUserId();
        $conversationId = $params['id'];
        
        if (!$userId || !$conversationId) {
            Response::unauthorized();
        }
        
        if (!$this->conversationModel->hasAccess($conversationId, $userId)) {
            Response::forbidden();
        }
        
        try {
            $result = $this->conversationModel->archive($conversationId);
            
            if ($result) {
                Response::success([], 'Conversation archived successfully');
            } else {
                Response::error('Failed to archive conversation');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to archive conversation", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to archive conversation');
        }
    }
    
    /**
     * Add reaction to message
     * POST /v1/messages/{id}/reactions
     */
    public function addReaction($params) {
        $userId = $this->getCurrentUserId();
        $messageId = $params['id'];
        
        if (!$userId || !$messageId) {
            Response::unauthorized();
        }
        
        $data = Request::validate([
            'emoji' => 'required|max:10'
        ]);
        
        try {
            $result = $this->messageModel->addReaction($messageId, $userId, $data['emoji']);
            
            if ($result) {
                // Get updated reactions
                $reactions = $this->messageModel->getMessageReactions($messageId);
                
                // Get message to broadcast
                $message = $this->messageModel->find($messageId);
                if ($message) {
                    $this->websocketService->broadcastReactionUpdate($messageId, $reactions, $message['conversation_id']);
                }
                
                Response::success([
                    'reactions' => $reactions
                ], 'Reaction added');
            } else {
                Response::error('Failed to add reaction');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to add reaction", [
                'message_id' => $messageId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to add reaction');
        }
    }
    
    /**
     * Remove reaction from message
     * DELETE /v1/messages/{id}/reactions
     */
    public function removeReaction($params) {
        $userId = $this->getCurrentUserId();
        $messageId = $params['id'];
        
        if (!$userId || !$messageId) {
            Response::unauthorized();
        }
        
        $data = Request::validate([
            'emoji' => 'required|max:10'
        ]);
        
        try {
            $result = $this->messageModel->removeReaction($messageId, $userId, $data['emoji']);
            
            if ($result) {
                // Get updated reactions
                $reactions = $this->messageModel->getMessageReactions($messageId);
                
                // Get message to broadcast
                $message = $this->messageModel->find($messageId);
                if ($message) {
                    $this->websocketService->broadcastReactionUpdate($messageId, $reactions, $message['conversation_id']);
                }
                
                Response::success([
                    'reactions' => $reactions
                ], 'Reaction removed');
            } else {
                Response::error('Failed to remove reaction');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to remove reaction", [
                'message_id' => $messageId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to remove reaction');
        }
    }
    
    /**
     * Get conversation statistics
     * GET /v1/conversations/{id}/stats
     */
    public function getConversationStats($params) {
        $userId = $this->getCurrentUserId();
        $conversationId = $params['id'];
        
        if (!$userId || !$conversationId) {
            Response::unauthorized();
        }
        
        if (!$this->conversationModel->hasAccess($conversationId, $userId)) {
            Response::forbidden();
        }
        
        try {
            $stats = $this->messageModel->getConversationStats($conversationId);
            Response::success(['stats' => $stats]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get conversation stats", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to get conversation statistics');
        }
    }
    
    /**
     * Get message filters and statistics
     * GET /v1/messages/filters
     */
    public function getMessageFilters($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        try {
            $filters = $this->messageModel->getMessageFilters($userId);
            
            Response::success([
                'filters' => $filters
            ]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get message filters", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to get message filters');
        }
    }
    
    /**
     * Export messages
     * GET /v1/conversations/{id}/export
     */
    public function exportMessages($params) {
        $userId = $this->getCurrentUserId();
        $conversationId = $params['id'];
        
        if (!$userId || !$conversationId) {
            Response::unauthorized();
        }
        
        // Check access
        if (!$this->conversationModel->hasAccess($conversationId, $userId)) {
            Response::forbidden();
        }
        
        $format = Request::get('format', 'json'); // json, csv, txt
        
        try {
            $conversation = $this->conversationModel->getConversationDetails($conversationId, $userId);
            $messages = $this->messageModel->getAllConversationMessages($conversationId);
            
            switch ($format) {
                case 'csv':
                    $this->exportAsCSV($conversation, $messages);
                    break;
                case 'txt':
                    $this->exportAsText($conversation, $messages);
                    break;
                default:
                    $this->exportAsJSON($conversation, $messages);
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to export messages", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to export messages');
        }
    }
    
    /**
     * Export messages as JSON
     */
    private function exportAsJSON($conversation, $messages) {
        $data = [
            'conversation' => $conversation,
            'messages' => $messages,
            'exported_at' => date('c'),
            'total_messages' => count($messages)
        ];
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="conversation_' . $conversation['id'] . '_messages.json"');
        
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Export messages as CSV
     */
    private function exportAsCSV($conversation, $messages) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="conversation_' . $conversation['id'] . '_messages.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, [
            'Date',
            'Time',
            'Sender',
            'Message Type',
            'Content',
            'Read Status'
        ]);
        
        // CSV data
        foreach ($messages as $message) {
            $datetime = new DateTime($message['created_at']);
            
            fputcsv($output, [
                $datetime->format('Y-m-d'),
                $datetime->format('H:i:s'),
                $message['sender_first_name'] . ' ' . $message['sender_last_name'] . ' (' . $message['sender_username'] . ')',
                ucfirst($message['message_type']),
                $this->sanitizeForCSV($message['content']),
                $message['is_read'] ? 'Read' : 'Unread'
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Export messages as text
     */
    private function exportAsText($conversation, $messages) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="conversation_' . $conversation['id'] . '_messages.txt"');
        
        echo "=== Message Export ===\n";
        echo "Conversation: " . $conversation['article_title'] . "\n";
        echo "Participants: " . $conversation['buyer_username'] . " & " . $conversation['seller_username'] . "\n";
        echo "Export Date: " . date('Y-m-d H:i:s') . "\n";
        echo "Total Messages: " . count($messages) . "\n";
        echo str_repeat('=', 50) . "\n\n";
        
        foreach ($messages as $message) {
            $datetime = new DateTime($message['created_at']);
            $senderName = $message['sender_first_name'] . ' ' . $message['sender_last_name'];
            
            echo "[" . $datetime->format('Y-m-d H:i:s') . "] ";
            echo $senderName . " (" . $message['sender_username'] . "):\n";
            
            if ($message['message_type'] === 'text') {
                echo $message['content'] . "\n";
            } else {
                echo "[" . strtoupper($message['message_type']) . "] " . $message['content'] . "\n";
            }
            
            echo "\n";
        }
        
        exit;
    }
    
    /**
     * Sanitize content for CSV export
     */
    private function sanitizeForCSV($content) {
        // Remove HTML tags and normalize whitespace
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        return trim($content);
    }
    
    /**
     * Stream events via Server-Sent Events
     * GET /v1/messages/stream
     */
    public function streamEvents($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        try {
            $this->webSocketService->handleSSEConnection($userId);
        } catch (Exception $e) {
            Logger::error("Failed to handle SSE connection", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to establish real-time connection');
        }
    }
    
    /**
     * Subscribe to push notifications
     * POST /v1/push/subscribe
     */
    public function subscribePush($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        $data = Request::validate([
            'endpoint' => 'required',
            'p256dh_key' => 'required',
            'auth_key' => 'required'
        ]);
        
        try {
            $pushSubscriptionModel = new PushSubscription();
            
            $subscriptionId = $pushSubscriptionModel->addSubscription(
                $userId,
                $data['endpoint'],
                $data['p256dh_key'],
                $data['auth_key'],
                Request::header('User-Agent')
            );
            
            if ($subscriptionId) {
                Response::success([
                    'subscription_id' => $subscriptionId
                ], 'Push subscription added successfully');
            } else {
                Response::error('Failed to add push subscription');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to subscribe to push notifications", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to subscribe to push notifications');
        }
    }
    
    /**
     * Unsubscribe from push notifications
     * DELETE /v1/push/subscribe
     */
    public function unsubscribePush($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        $data = Request::validate([
            'endpoint' => 'required'
        ]);
        
        try {
            $pushSubscriptionModel = new PushSubscription();
            
            $result = $pushSubscriptionModel->removeUserSubscription($userId, $data['endpoint']);
            
            if ($result) {
                Response::success([], 'Push subscription removed successfully');
            } else {
                Response::error('Push subscription not found or already removed');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to unsubscribe from push notifications", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to unsubscribe from push notifications');
        }
    }
    
    /**
     * Send test push notification
     * POST /v1/push/test
     */
    public function testPushNotification($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        try {
            $result = $this->notificationService->sendSystemNotification(
                $userId,
                'test',
                'Test Notification',
                'This is a test push notification from Bazar.',
                ['test' => true, 'timestamp' => time()]
            );
            
            if ($result) {
                Response::success([], 'Test notification sent successfully');
            } else {
                Response::error('Failed to send test notification');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to send test push notification", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to send test notification');
        }
    }
    
    /**
     * Get current authenticated user ID
     */
    private function getCurrentUserId() {
        $token = Request::bearerToken();
        if (!$token) {
            return null;
        }
        
        $decoded = JWT::decode($token);
        return $decoded['user_id'] ?? null;
    }
}