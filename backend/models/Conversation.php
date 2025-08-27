<?php
/**
 * Conversation Model
 * Handles conversations between buyers and sellers for specific articles
 */

class Conversation extends BaseModel {
    protected $table = 'conversations';
    
    /**
     * Create or get existing conversation between buyer and seller for an article
     */
    public function findOrCreate($articleId, $buyerId, $sellerId) {
        // First try to find existing conversation
        $existing = $this->findConversation($articleId, $buyerId, $sellerId);
        if ($existing) {
            return $existing;
        }
        
        // Create new conversation if it doesn't exist
        $conversationData = [
            'article_id' => $articleId,
            'buyer_id' => $buyerId,
            'seller_id' => $sellerId,
            'status' => 'active'
        ];
        
        $conversationId = $this->create($conversationData);
        if ($conversationId) {
            return $this->find($conversationId);
        }
        
        return false;
    }
    
    /**
     * Find conversation by article, buyer, and seller
     */
    public function findConversation($articleId, $buyerId, $sellerId) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE article_id = ? AND buyer_id = ? AND seller_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$articleId, $buyerId, $sellerId]);
        return $stmt->fetch();
    }
    
    /**
     * Get all conversations for a user (as buyer or seller)
     */
    public function getUserConversations($userId, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT c.*, 
                       cwp.buyer_username, cwp.buyer_avatar,
                       cwp.seller_username, cwp.seller_avatar,
                       cwp.article_title, cwp.article_price, cwp.article_status,
                       cwp.last_message_content, cwp.last_message_type,
                       cwp.last_message_sender_id,
                       CASE 
                           WHEN c.buyer_id = ? THEN c.buyer_unread_count
                           ELSE c.seller_unread_count
                       END as unread_count,
                       CASE 
                           WHEN c.buyer_id = ? THEN c.seller_id
                           ELSE c.buyer_id
                       END as other_user_id,
                       CASE 
                           WHEN c.buyer_id = ? THEN cwp.seller_username
                           ELSE cwp.buyer_username
                       END as other_user_name,
                       CASE 
                           WHEN c.buyer_id = ? THEN cwp.seller_avatar
                           ELSE cwp.buyer_avatar
                       END as other_user_avatar
                FROM conversations c
                JOIN conversation_with_participants cwp ON c.id = cwp.id
                WHERE c.buyer_id = ? OR c.seller_id = ?
                ORDER BY c.last_message_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $limit, $offset]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get conversation with full details including participants
     */
    public function getConversationDetails($conversationId, $userId = null) {
        $sql = "SELECT c.*, 
                       cwp.buyer_username, cwp.buyer_avatar,
                       cwp.seller_username, cwp.seller_avatar,
                       cwp.article_title, cwp.article_price, cwp.article_status,
                       cwp.last_message_content, cwp.last_message_type,
                       cwp.last_message_sender_id";
        
        if ($userId) {
            $sql .= ", CASE 
                        WHEN c.buyer_id = ? THEN c.buyer_unread_count
                        ELSE c.seller_unread_count
                     END as unread_count,
                     CASE 
                        WHEN c.buyer_id = ? THEN 'buyer'
                        ELSE 'seller'
                     END as user_role";
        }
        
        $sql .= " FROM conversations c
                  JOIN conversation_with_participants cwp ON c.id = cwp.id
                  WHERE c.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $params = $userId ? [$userId, $userId, $conversationId] : [$conversationId];
        $stmt->execute($params);
        
        return $stmt->fetch();
    }
    
    /**
     * Mark messages as read for a user in a conversation
     */
    public function markMessagesAsRead($conversationId, $userId) {
        try {
            $this->db->beginTransaction();
            
            // Update messages as read
            $sql = "UPDATE messages m
                    JOIN conversations c ON m.conversation_id = c.id
                    SET m.is_read = TRUE, m.read_at = CURRENT_TIMESTAMP
                    WHERE c.id = ? 
                    AND m.sender_id != ? 
                    AND m.is_read = FALSE";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$conversationId, $userId]);
            
            // Reset unread count for the user
            $conversation = $this->find($conversationId);
            if ($conversation) {
                if ($conversation['buyer_id'] == $userId) {
                    $this->update($conversationId, ['buyer_unread_count' => 0]);
                } elseif ($conversation['seller_id'] == $userId) {
                    $this->update($conversationId, ['seller_unread_count' => 0]);
                }
            }
            
            // Update last seen timestamp
            $this->updateLastSeen($conversationId, $userId);
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            Logger::error("Failed to mark messages as read", [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Update last seen timestamp for user in conversation
     */
    public function updateLastSeen($conversationId, $userId) {
        $conversation = $this->find($conversationId);
        if (!$conversation) {
            return false;
        }
        
        $updateData = [];
        if ($conversation['buyer_id'] == $userId) {
            $updateData['buyer_last_seen'] = date('Y-m-d H:i:s');
        } elseif ($conversation['seller_id'] == $userId) {
            $updateData['seller_last_seen'] = date('Y-m-d H:i:s');
        }
        
        if (!empty($updateData)) {
            return $this->update($conversationId, $updateData);
        }
        
        return false;
    }
    
    /**
     * Update typing status for user in conversation
     */
    public function updateTypingStatus($conversationId, $userId, $isTyping) {
        $conversation = $this->find($conversationId);
        if (!$conversation) {
            return false;
        }
        
        $updateData = [];
        if ($conversation['buyer_id'] == $userId) {
            $updateData['is_buyer_typing'] = $isTyping;
        } elseif ($conversation['seller_id'] == $userId) {
            $updateData['is_seller_typing'] = $isTyping;
        }
        
        if (!empty($updateData)) {
            return $this->update($conversationId, $updateData);
        }
        
        return false;
    }
    
    /**
     * Archive conversation
     */
    public function archive($conversationId) {
        return $this->update($conversationId, ['status' => 'archived']);
    }
    
    /**
     * Block conversation
     */
    public function block($conversationId, $blockerId, $reason = null) {
        try {
            $this->db->beginTransaction();
            
            // Update conversation status
            $this->update($conversationId, ['status' => 'blocked']);
            
            // Get conversation details to create block record
            $conversation = $this->find($conversationId);
            if ($conversation) {
                $blockedId = ($conversation['buyer_id'] == $blockerId) 
                    ? $conversation['seller_id'] 
                    : $conversation['buyer_id'];
                
                // Create block record
                $blockData = [
                    'blocker_id' => $blockerId,
                    'blocked_id' => $blockedId,
                    'conversation_id' => $conversationId,
                    'reason' => $reason
                ];
                
                $sql = "INSERT INTO message_blocks (blocker_id, blocked_id, conversation_id, reason) 
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        conversation_id = VALUES(conversation_id),
                        reason = VALUES(reason),
                        created_at = CURRENT_TIMESTAMP";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    $blockData['blocker_id'],
                    $blockData['blocked_id'],
                    $blockData['conversation_id'],
                    $blockData['reason']
                ]);
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            Logger::error("Failed to block conversation", [
                'conversation_id' => $conversationId,
                'blocker_id' => $blockerId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get user's total unread message count
     */
    public function getUserUnreadCount($userId) {
        $sql = "SELECT 
                    SUM(CASE WHEN buyer_id = ? THEN buyer_unread_count ELSE 0 END) +
                    SUM(CASE WHEN seller_id = ? THEN seller_unread_count ELSE 0 END) as total_unread
                FROM conversations 
                WHERE (buyer_id = ? OR seller_id = ?) AND status = 'active'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId]);
        $result = $stmt->fetch();
        
        return (int)($result['total_unread'] ?? 0);
    }
    
    /**
     * Search conversations by article title or participant name
     */
    public function searchConversations($userId, $query, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        $searchTerm = '%' . $query . '%';
        
        $sql = "SELECT c.*, 
                       cwp.buyer_username, cwp.buyer_avatar,
                       cwp.seller_username, cwp.seller_avatar,
                       cwp.article_title, cwp.article_price, cwp.article_status,
                       cwp.last_message_content, cwp.last_message_type,
                       cwp.last_message_sender_id,
                       CASE 
                           WHEN c.buyer_id = ? THEN c.buyer_unread_count
                           ELSE c.seller_unread_count
                       END as unread_count,
                       CASE 
                           WHEN c.buyer_id = ? THEN cwp.seller_username
                           ELSE cwp.buyer_username
                       END as other_user_name
                FROM conversations c
                JOIN conversation_with_participants cwp ON c.id = cwp.id
                WHERE (c.buyer_id = ? OR c.seller_id = ?)
                AND (cwp.article_title LIKE ? 
                     OR cwp.buyer_username LIKE ? 
                     OR cwp.seller_username LIKE ?)
                ORDER BY c.last_message_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $userId, $userId, $userId, $userId, 
            $searchTerm, $searchTerm, $searchTerm,
            $limit, $offset
        ]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Check if user has access to conversation
     */
    public function hasAccess($conversationId, $userId) {
        $sql = "SELECT COUNT(*) as count FROM conversations 
                WHERE id = ? AND (buyer_id = ? OR seller_id = ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$conversationId, $userId, $userId]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }
    
    /**
     * Check if user is blocked by another user
     */
    public function isBlocked($userId, $otherUserId) {
        $sql = "SELECT COUNT(*) as count FROM message_blocks 
                WHERE (blocker_id = ? AND blocked_id = ?) 
                OR (blocker_id = ? AND blocked_id = ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $otherUserId, $otherUserId, $userId]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }
}