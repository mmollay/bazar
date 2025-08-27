<?php
/**
 * Message Model
 * Handles individual messages within conversations
 */

class Message extends BaseModel {
    protected $table = 'messages';
    
    /**
     * Create a new message with proper validation
     */
    public function createMessage($data) {
        // Validate required fields
        if (empty($data['conversation_id']) || empty($data['sender_id']) || empty($data['content'])) {
            return false;
        }
        
        // Set default values
        $data['message_type'] = $data['message_type'] ?? 'text';
        $data['is_read'] = false;
        
        // Sanitize content based on message type
        if ($data['message_type'] === 'text') {
            $data['content'] = $this->sanitizeTextContent($data['content']);
        }
        
        try {
            $messageId = $this->create($data);
            if ($messageId) {
                return $this->getMessageWithSender($messageId);
            }
            return false;
            
        } catch (Exception $e) {
            Logger::error("Failed to create message", [
                'conversation_id' => $data['conversation_id'],
                'sender_id' => $data['sender_id'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get messages for a conversation with pagination
     */
    public function getConversationMessages($conversationId, $page = 1, $limit = 50, $beforeMessageId = null) {
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT m.*, mws.sender_username, mws.sender_avatar, 
                       mws.sender_first_name, mws.sender_last_name,
                       rm.content as reply_content,
                       ru.username as reply_sender_username,
                       COUNT(mr.id) as reaction_count
                FROM messages_with_sender mws
                JOIN messages m ON m.id = mws.id
                LEFT JOIN messages rm ON m.reply_to_message_id = rm.id
                LEFT JOIN users ru ON rm.sender_id = ru.id
                LEFT JOIN message_reactions mr ON m.id = mr.message_id
                WHERE m.conversation_id = ?";
        
        $params = [$conversationId];
        
        if ($beforeMessageId) {
            $sql .= " AND m.id < ?";
            $params[] = $beforeMessageId;
        }
        
        $sql .= " GROUP BY m.id
                  ORDER BY m.created_at DESC
                  LIMIT ? OFFSET ?";
        
        $params = array_merge($params, [$limit, $offset]);
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $messages = $stmt->fetchAll();
        
        // Get attachments for messages that have them
        foreach ($messages as &$message) {
            if (in_array($message['message_type'], ['image', 'file'])) {
                $message['attachments'] = $this->getMessageAttachments($message['id']);
            }
            
            // Get reactions for the message
            $message['reactions'] = $this->getMessageReactions($message['id']);
        }
        
        return array_reverse($messages); // Return in chronological order
    }
    
    /**
     * Get a single message with sender information
     */
    public function getMessageWithSender($messageId) {
        $sql = "SELECT m.*, mws.sender_username, mws.sender_avatar, 
                       mws.sender_first_name, mws.sender_last_name
                FROM messages m
                JOIN messages_with_sender mws ON m.id = mws.id
                WHERE m.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$messageId]);
        
        $message = $stmt->fetch();
        
        if ($message && in_array($message['message_type'], ['image', 'file'])) {
            $message['attachments'] = $this->getMessageAttachments($messageId);
        }
        
        return $message;
    }
    
    /**
     * Mark message as read
     */
    public function markAsRead($messageId, $userId) {
        // First check if the message exists and user is not the sender
        $message = $this->find($messageId);
        if (!$message || $message['sender_id'] == $userId) {
            return false;
        }
        
        // Update message read status
        $result = $this->update($messageId, [
            'is_read' => true,
            'read_at' => date('Y-m-d H:i:s')
        ]);
        
        // The trigger will automatically update conversation unread count
        return $result;
    }
    
    /**
     * Mark multiple messages as read
     */
    public function markMultipleAsRead($messageIds, $userId) {
        if (empty($messageIds)) {
            return false;
        }
        
        $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
        
        $sql = "UPDATE messages 
                SET is_read = TRUE, read_at = CURRENT_TIMESTAMP 
                WHERE id IN ({$placeholders}) 
                AND sender_id != ? 
                AND is_read = FALSE";
        
        $params = array_merge($messageIds, [$userId]);
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }
    
    /**
     * Edit message content (only by sender)
     */
    public function editMessage($messageId, $senderId, $newContent) {
        // Verify sender owns the message
        $message = $this->find($messageId);
        if (!$message || $message['sender_id'] != $senderId) {
            return false;
        }
        
        // Don't allow editing non-text messages or system messages
        if ($message['message_type'] !== 'text' || !empty($message['system_message_type'])) {
            return false;
        }
        
        $sanitizedContent = $this->sanitizeTextContent($newContent);
        
        return $this->update($messageId, [
            'content' => $sanitizedContent,
            'is_edited' => true,
            'edited_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Delete message (soft delete - mark as deleted)
     */
    public function deleteMessage($messageId, $userId) {
        $message = $this->find($messageId);
        if (!$message || $message['sender_id'] != $userId) {
            return false;
        }
        
        return $this->update($messageId, [
            'content' => '[Message deleted]',
            'message_type' => 'system',
            'system_message_type' => 'deleted',
            'is_edited' => true,
            'edited_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Search messages within conversations
     */
    public function searchMessages($userId, $query, $conversationId = null, $limit = 50) {
        $searchTerm = '%' . $query . '%';
        
        $sql = "SELECT m.*, mws.sender_username, mws.sender_avatar,
                       c.article_id, a.title as article_title
                FROM messages m
                JOIN messages_with_sender mws ON m.id = mws.id
                JOIN conversations c ON m.conversation_id = c.id
                JOIN articles a ON c.article_id = a.id
                WHERE (c.buyer_id = ? OR c.seller_id = ?)
                AND MATCH(m.content) AGAINST(? IN NATURAL LANGUAGE MODE)";
        
        $params = [$userId, $userId, $query];
        
        if ($conversationId) {
            $sql .= " AND m.conversation_id = ?";
            $params[] = $conversationId;
        }
        
        $sql .= " ORDER BY m.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Advanced message search with filters and pagination
     */
    public function searchMessagesAdvanced($userId, $query, $filters = [], $page = 1, $limit = 50) {
        $offset = ($page - 1) * $limit;
        
        // Build base query
        $sql = "SELECT SQL_CALC_FOUND_ROWS m.*, mws.sender_username, mws.sender_avatar,
                       c.article_id, a.title as article_title, a.price as article_price,
                       c.buyer_id, c.seller_id
                FROM messages m
                JOIN messages_with_sender mws ON m.id = mws.id
                JOIN conversations c ON m.conversation_id = c.id
                JOIN articles a ON c.article_id = a.id
                WHERE (c.buyer_id = ? OR c.seller_id = ?)";
        
        $params = [$userId, $userId];
        $conditions = [];
        
        // Add search condition
        if (!empty($query)) {
            $conditions[] = "MATCH(m.content) AGAINST(? IN NATURAL LANGUAGE MODE)";
            $params[] = $query;
        }
        
        // Add filters
        if (!empty($filters['conversation_id'])) {
            $conditions[] = "m.conversation_id = ?";
            $params[] = $filters['conversation_id'];
        }
        
        if (!empty($filters['message_type'])) {
            $conditions[] = "m.message_type = ?";
            $params[] = $filters['message_type'];
        }
        
        if (!empty($filters['sender_id'])) {
            $conditions[] = "m.sender_id = ?";
            $params[] = $filters['sender_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $conditions[] = "m.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $conditions[] = "m.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        // Add conditions to query
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        // Execute query
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();
        
        // Get total count
        $totalStmt = $this->db->query("SELECT FOUND_ROWS() as total");
        $total = $totalStmt->fetch()['total'];
        
        return [
            'messages' => $messages,
            'total' => $total
        ];
    }
    
    /**
     * Get message filters for search UI
     */
    public function getMessageFilters($userId) {
        try {
            // Get available message types
            $typesStmt = $this->db->prepare("
                SELECT DISTINCT m.message_type, COUNT(*) as count
                FROM messages m
                JOIN conversations c ON m.conversation_id = c.id
                WHERE c.buyer_id = ? OR c.seller_id = ?
                GROUP BY m.message_type
                ORDER BY count DESC
            ");
            $typesStmt->execute([$userId, $userId]);
            $messageTypes = $typesStmt->fetchAll();
            
            // Get conversation participants (senders)
            $sendersStmt = $this->db->prepare("
                SELECT DISTINCT u.id, u.username, u.first_name, u.last_name, COUNT(*) as message_count
                FROM messages m
                JOIN conversations c ON m.conversation_id = c.id
                JOIN users u ON m.sender_id = u.id
                WHERE (c.buyer_id = ? OR c.seller_id = ?) AND m.sender_id != ?
                GROUP BY u.id, u.username, u.first_name, u.last_name
                ORDER BY message_count DESC
                LIMIT 20
            ");
            $sendersStmt->execute([$userId, $userId, $userId]);
            $senders = $sendersStmt->fetchAll();
            
            // Get date range
            $dateStmt = $this->db->prepare("
                SELECT 
                    MIN(m.created_at) as earliest_message,
                    MAX(m.created_at) as latest_message
                FROM messages m
                JOIN conversations c ON m.conversation_id = c.id
                WHERE c.buyer_id = ? OR c.seller_id = ?
            ");
            $dateStmt->execute([$userId, $userId]);
            $dateRange = $dateStmt->fetch();
            
            // Get conversation list for filtering
            $conversationsStmt = $this->db->prepare("
                SELECT c.id, a.title, COUNT(m.id) as message_count
                FROM conversations c
                JOIN articles a ON c.article_id = a.id
                LEFT JOIN messages m ON c.id = m.conversation_id
                WHERE c.buyer_id = ? OR c.seller_id = ?
                GROUP BY c.id, a.title
                HAVING message_count > 0
                ORDER BY message_count DESC
                LIMIT 50
            ");
            $conversationsStmt->execute([$userId, $userId]);
            $conversations = $conversationsStmt->fetchAll();
            
            return [
                'message_types' => $messageTypes,
                'senders' => $senders,
                'date_range' => $dateRange,
                'conversations' => $conversations
            ];
            
        } catch (Exception $e) {
            Logger::error("Failed to get message filters", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Get all messages for a conversation (for export)
     */
    public function getAllConversationMessages($conversationId) {
        $sql = "SELECT m.*, mws.sender_username, mws.sender_avatar, 
                       mws.sender_first_name, mws.sender_last_name
                FROM messages m
                JOIN messages_with_sender mws ON m.id = mws.id
                WHERE m.conversation_id = ?
                ORDER BY m.created_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$conversationId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get message statistics for analytics
     */
    public function getMessageStatistics($userId) {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_messages,
                        COUNT(CASE WHEN m.sender_id = ? THEN 1 END) as sent_messages,
                        COUNT(CASE WHEN m.sender_id != ? THEN 1 END) as received_messages,
                        COUNT(CASE WHEN m.message_type = 'text' THEN 1 END) as text_messages,
                        COUNT(CASE WHEN m.message_type = 'image' THEN 1 END) as image_messages,
                        COUNT(CASE WHEN m.message_type = 'file' THEN 1 END) as file_messages,
                        COUNT(CASE WHEN m.message_type = 'offer' THEN 1 END) as offer_messages,
                        MIN(m.created_at) as first_message_date,
                        MAX(m.created_at) as last_message_date
                    FROM messages m
                    JOIN conversations c ON m.conversation_id = c.id
                    WHERE c.buyer_id = ? OR c.seller_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $userId, $userId, $userId]);
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            Logger::error("Failed to get message statistics", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Search messages by content similarity
     */
    public function findSimilarMessages($userId, $content, $limit = 10) {
        try {
            $sql = "SELECT m.*, mws.sender_username, mws.sender_avatar,
                           MATCH(m.content) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance
                    FROM messages m
                    JOIN messages_with_sender mws ON m.id = mws.id
                    JOIN conversations c ON m.conversation_id = c.id
                    WHERE (c.buyer_id = ? OR c.seller_id = ?)
                    AND MATCH(m.content) AGAINST(? IN NATURAL LANGUAGE MODE)
                    AND m.message_type = 'text'
                    ORDER BY relevance DESC
                    LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$content, $userId, $userId, $content, $limit]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            Logger::error("Failed to find similar messages", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Create system message (price change, status update, etc.)
     */
    public function createSystemMessage($conversationId, $systemType, $content, $metadata = null) {
        $data = [
            'conversation_id' => $conversationId,
            'sender_id' => 0, // System messages have sender_id = 0
            'content' => $content,
            'message_type' => 'system',
            'system_message_type' => $systemType,
            'is_read' => false
        ];
        
        if ($metadata) {
            $data['metadata'] = json_encode($metadata);
        }
        
        return $this->create($data);
    }
    
    /**
     * Create offer message
     */
    public function createOfferMessage($conversationId, $senderId, $offerAmount, $message = null) {
        $content = $message ?: "Made an offer of €{$offerAmount}";
        
        $metadata = [
            'offer_amount' => $offerAmount,
            'offer_type' => 'price_offer',
            'offer_status' => 'pending'
        ];
        
        $data = [
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'content' => $content,
            'message_type' => 'offer',
            'metadata' => json_encode($metadata),
            'is_read' => false
        ];
        
        return $this->createMessage($data);
    }
    
    /**
     * Get message attachments
     */
    public function getMessageAttachments($messageId) {
        $sql = "SELECT * FROM message_attachments WHERE message_id = ? ORDER BY id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$messageId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Add attachment to message
     */
    public function addAttachment($messageId, $attachmentData) {
        $attachmentData['message_id'] = $messageId;
        
        $sql = "INSERT INTO message_attachments 
                (message_id, filename, original_filename, file_path, file_size, 
                 mime_type, width, height, thumbnail_path, is_image) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $attachmentData['message_id'],
            $attachmentData['filename'],
            $attachmentData['original_filename'],
            $attachmentData['file_path'],
            $attachmentData['file_size'],
            $attachmentData['mime_type'],
            $attachmentData['width'] ?? null,
            $attachmentData['height'] ?? null,
            $attachmentData['thumbnail_path'] ?? null,
            $attachmentData['is_image'] ?? false
        ]);
    }
    
    /**
     * Get message reactions
     */
    public function getMessageReactions($messageId) {
        $sql = "SELECT emoji, COUNT(*) as count, 
                       GROUP_CONCAT(u.username) as users
                FROM message_reactions mr
                JOIN users u ON mr.user_id = u.id
                WHERE mr.message_id = ?
                GROUP BY emoji";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$messageId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Add reaction to message
     */
    public function addReaction($messageId, $userId, $emoji) {
        $sql = "INSERT INTO message_reactions (message_id, user_id, emoji) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$messageId, $userId, $emoji]);
    }
    
    /**
     * Remove reaction from message
     */
    public function removeReaction($messageId, $userId, $emoji) {
        $sql = "DELETE FROM message_reactions 
                WHERE message_id = ? AND user_id = ? AND emoji = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$messageId, $userId, $emoji]);
    }
    
    /**
     * Get unread messages count for user in conversation
     */
    public function getUnreadCount($conversationId, $userId) {
        $sql = "SELECT COUNT(*) as count FROM messages 
                WHERE conversation_id = ? AND sender_id != ? AND is_read = FALSE";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$conversationId, $userId]);
        $result = $stmt->fetch();
        
        return (int)($result['count'] ?? 0);
    }
    
    /**
     * Get recent messages for conversation preview
     */
    public function getRecentMessages($conversationId, $limit = 3) {
        $sql = "SELECT m.*, mws.sender_username, mws.sender_avatar
                FROM messages m
                JOIN messages_with_sender mws ON m.id = mws.id
                WHERE m.conversation_id = ?
                ORDER BY m.created_at DESC
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$conversationId, $limit]);
        
        return array_reverse($stmt->fetchAll());
    }
    
    /**
     * Sanitize text content to prevent XSS
     */
    private function sanitizeTextContent($content) {
        // Remove dangerous HTML tags but keep basic formatting
        $allowedTags = '<p><br><b><i><u><strong><em>';
        $sanitized = strip_tags($content, $allowedTags);
        
        // Convert URLs to clickable links
        $sanitized = preg_replace(
            '/(https?:\/\/[^\s]+)/',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $sanitized
        );
        
        return trim($sanitized);
    }
    
    /**
     * Get message statistics for a conversation
     */
    public function getConversationStats($conversationId) {
        $sql = "SELECT 
                    COUNT(*) as total_messages,
                    COUNT(CASE WHEN message_type = 'text' THEN 1 END) as text_messages,
                    COUNT(CASE WHEN message_type = 'image' THEN 1 END) as image_messages,
                    COUNT(CASE WHEN message_type = 'file' THEN 1 END) as file_messages,
                    COUNT(CASE WHEN message_type = 'offer' THEN 1 END) as offer_messages,
                    MIN(created_at) as first_message_at,
                    MAX(created_at) as last_message_at
                FROM messages 
                WHERE conversation_id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$conversationId]);
        
        return $stmt->fetch();
    }
}