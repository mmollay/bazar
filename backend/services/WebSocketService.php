<?php
/**
 * WebSocket Service
 * Handles real-time messaging using WebSockets or Server-Sent Events
 * Provides fallback to Server-Sent Events for broader browser compatibility
 */

class WebSocketService {
    private $redis;
    private $connectionModel;
    
    public function __construct() {
        $this->redis = $this->getRedisConnection();
        $this->connectionModel = new WebSocketConnection();
    }
    
    /**
     * Get Redis connection for pub/sub
     */
    private function getRedisConnection() {
        if (class_exists('Redis')) {
            try {
                $redis = new Redis();
                $redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', $_ENV['REDIS_PORT'] ?? 6379);
                if ($_ENV['REDIS_PASSWORD'] ?? false) {
                    $redis->auth($_ENV['REDIS_PASSWORD']);
                }
                return $redis;
            } catch (Exception $e) {
                Logger::warning("Redis connection failed, WebSocket features will be limited", [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }
        return null;
    }
    
    /**
     * Register WebSocket connection
     */
    public function registerConnection($userId, $connectionId, $socketId) {
        if (!$this->connectionModel) {
            return false;
        }
        
        $data = [
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'socket_id' => $socketId,
            'is_active' => true,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        
        try {
            // Remove old connections for this user
            $this->cleanupOldConnections($userId);
            
            // Register new connection
            $result = $this->connectionModel->create($data);
            
            if ($result && $this->redis) {
                // Subscribe to user's channel
                $this->redis->sadd("user_connections:{$userId}", $connectionId);
                
                // Set user online status
                $this->redis->setex("user_online:{$userId}", 300, time()); // 5 minutes TTL
            }
            
            return $result;
            
        } catch (Exception $e) {
            Logger::error("Failed to register WebSocket connection", [
                'user_id' => $userId,
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Unregister WebSocket connection
     */
    public function unregisterConnection($connectionId) {
        if (!$this->connectionModel) {
            return false;
        }
        
        try {
            $connection = $this->connectionModel->findByConnectionId($connectionId);
            if ($connection) {
                // Mark as inactive
                $this->connectionModel->update($connection['id'], ['is_active' => false]);
                
                if ($this->redis) {
                    // Remove from user connections
                    $this->redis->srem("user_connections:{$connection['user_id']}", $connectionId);
                    
                    // Check if user has other active connections
                    $activeConnections = $this->redis->scard("user_connections:{$connection['user_id']}");
                    if ($activeConnections == 0) {
                        // Set user offline
                        $this->redis->del("user_online:{$connection['user_id']}");
                    }
                }
                
                return true;
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to unregister WebSocket connection", [
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }
    
    /**
     * Broadcast new message to conversation participants
     */
    public function broadcastMessage($message, $conversationId) {
        if (!$this->redis) {
            return false;
        }
        
        $eventData = [
            'type' => 'new_message',
            'conversation_id' => $conversationId,
            'message' => $message,
            'timestamp' => time()
        ];
        
        // Publish to conversation channel
        $this->redis->publish("conversation:{$conversationId}", json_encode($eventData));
        
        // Also publish to individual user channels for push notifications
        $conversationModel = new Conversation();
        $conversation = $conversationModel->find($conversationId);
        
        if ($conversation) {
            $buyerData = array_merge($eventData, ['recipient_type' => 'buyer']);
            $sellerData = array_merge($eventData, ['recipient_type' => 'seller']);
            
            $this->redis->publish("user:{$conversation['buyer_id']}", json_encode($buyerData));
            $this->redis->publish("user:{$conversation['seller_id']}", json_encode($sellerData));
        }
        
        return true;
    }
    
    /**
     * Broadcast typing status
     */
    public function broadcastTypingStatus($conversationId, $userId, $isTyping) {
        if (!$this->redis) {
            return false;
        }
        
        $eventData = [
            'type' => 'typing_status',
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'is_typing' => $isTyping,
            'timestamp' => time()
        ];
        
        $this->redis->publish("conversation:{$conversationId}", json_encode($eventData));
        
        // Set typing status with TTL
        if ($isTyping) {
            $this->redis->setex("typing:{$conversationId}:{$userId}", 10, 1);
        } else {
            $this->redis->del("typing:{$conversationId}:{$userId}");
        }
        
        return true;
    }
    
    /**
     * Broadcast read receipt
     */
    public function broadcastReadReceipt($messageId, $userId, $conversationId) {
        if (!$this->redis) {
            return false;
        }
        
        $eventData = [
            'type' => 'read_receipt',
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'timestamp' => time()
        ];
        
        $this->redis->publish("conversation:{$conversationId}", json_encode($eventData));
        return true;
    }
    
    /**
     * Broadcast message update (edit/delete)
     */
    public function broadcastMessageUpdate($message) {
        if (!$this->redis) {
            return false;
        }
        
        $eventData = [
            'type' => 'message_update',
            'conversation_id' => $message['conversation_id'],
            'message' => $message,
            'timestamp' => time()
        ];
        
        $this->redis->publish("conversation:{$message['conversation_id']}", json_encode($eventData));
        return true;
    }
    
    /**
     * Broadcast reaction update
     */
    public function broadcastReactionUpdate($messageId, $reactions, $conversationId) {
        if (!$this->redis) {
            return false;
        }
        
        $eventData = [
            'type' => 'reaction_update',
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'reactions' => $reactions,
            'timestamp' => time()
        ];
        
        $this->redis->publish("conversation:{$conversationId}", json_encode($eventData));
        return true;
    }
    
    /**
     * Broadcast user online status
     */
    public function broadcastUserStatus($userId, $isOnline) {
        if (!$this->redis) {
            return false;
        }
        
        $eventData = [
            'type' => 'user_status',
            'user_id' => $userId,
            'is_online' => $isOnline,
            'last_seen' => time(),
            'timestamp' => time()
        ];
        
        // Get user's conversations to broadcast status
        $conversationModel = new Conversation();
        $conversations = $conversationModel->getUserConversations($userId, 1, 100);
        
        foreach ($conversations as $conversation) {
            $this->redis->publish("conversation:{$conversation['id']}", json_encode($eventData));
        }
        
        return true;
    }
    
    /**
     * Get user online status
     */
    public function getUserOnlineStatus($userId) {
        if (!$this->redis) {
            return ['online' => false, 'last_seen' => null];
        }
        
        $isOnline = $this->redis->exists("user_online:{$userId}");
        $lastSeen = null;
        
        if (!$isOnline) {
            // Try to get last seen from connection records
            $lastConnection = $this->connectionModel->getLastUserConnection($userId);
            if ($lastConnection) {
                $lastSeen = $lastConnection['last_ping'];
            }
        }
        
        return [
            'online' => (bool)$isOnline,
            'last_seen' => $lastSeen
        ];
    }
    
    /**
     * Get typing users in conversation
     */
    public function getTypingUsers($conversationId) {
        if (!$this->redis) {
            return [];
        }
        
        $pattern = "typing:{$conversationId}:*";
        $keys = $this->redis->keys($pattern);
        
        $typingUsers = [];
        foreach ($keys as $key) {
            if ($this->redis->exists($key)) {
                // Extract user ID from key
                $parts = explode(':', $key);
                if (count($parts) >= 3) {
                    $typingUsers[] = (int)$parts[2];
                }
            }
        }
        
        return $typingUsers;
    }
    
    /**
     * Send Server-Sent Events (SSE) for browsers that don't support WebSockets
     */
    public function handleSSEConnection($userId) {
        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        
        // Prevent script timeout
        set_time_limit(0);
        ignore_user_abort(false);
        
        if (!$this->redis) {
            echo "data: " . json_encode(['error' => 'Real-time features not available']) . "\n\n";
            flush();
            return;
        }
        
        $connectionId = uniqid('sse_', true);
        $this->registerConnection($userId, $connectionId, 'sse');
        
        try {
            // Subscribe to user's channels
            $pubsub = $this->redis->duplicate();
            $pubsub->subscribe(["user:{$userId}"]);
            
            $lastPing = time();
            
            while (!connection_aborted()) {
                $message = $pubsub->rawCommand('BLPOP', "user:{$userId}", 1);
                
                if ($message) {
                    echo "data: " . $message[1] . "\n\n";
                    flush();
                }
                
                // Send periodic ping to keep connection alive
                if (time() - $lastPing > 30) {
                    echo "data: " . json_encode(['type' => 'ping', 'timestamp' => time()]) . "\n\n";
                    flush();
                    $lastPing = time();
                }
                
                // Update last ping in database
                if ($this->connectionModel) {
                    $connection = $this->connectionModel->findByConnectionId($connectionId);
                    if ($connection) {
                        $this->connectionModel->update($connection['id'], ['last_ping' => date('Y-m-d H:i:s')]);
                    }
                }
            }
            
        } catch (Exception $e) {
            Logger::error("SSE connection error", [
                'user_id' => $userId,
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
        } finally {
            $this->unregisterConnection($connectionId);
        }
    }
    
    /**
     * Clean up old connections for user
     */
    private function cleanupOldConnections($userId) {
        if (!$this->connectionModel) {
            return;
        }
        
        // Mark old connections as inactive
        $sql = "UPDATE websocket_connections 
                SET is_active = FALSE 
                WHERE user_id = ? AND is_active = TRUE 
                AND last_ping < DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
        
        try {
            $stmt = $this->connectionModel->db->prepare($sql);
            $stmt->execute([$userId]);
            
            // Clean up Redis references
            if ($this->redis) {
                $oldConnections = $this->redis->smembers("user_connections:{$userId}");
                foreach ($oldConnections as $connId) {
                    $this->redis->srem("user_connections:{$userId}", $connId);
                }
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to cleanup old connections", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Send push notification through WebSocket
     */
    public function sendNotification($userId, $notification) {
        if (!$this->redis) {
            return false;
        }
        
        $eventData = [
            'type' => 'notification',
            'notification' => $notification,
            'timestamp' => time()
        ];
        
        $this->redis->publish("user:{$userId}", json_encode($eventData));
        return true;
    }
    
    /**
     * Broadcast to all connections in conversation
     */
    public function broadcastToConversation($conversationId, $eventData, $excludeUserId = null) {
        if (!$this->redis) {
            return false;
        }
        
        if ($excludeUserId) {
            $eventData['exclude_user_id'] = $excludeUserId;
        }
        
        $this->redis->publish("conversation:{$conversationId}", json_encode($eventData));
        return true;
    }
    
    /**
     * Get connection statistics
     */
    public function getConnectionStats() {
        if (!$this->connectionModel || !$this->redis) {
            return ['active_connections' => 0, 'online_users' => 0];
        }
        
        try {
            $activeConnections = $this->connectionModel->count(['is_active' => true]);
            
            $onlineUsers = 0;
            if ($this->redis) {
                $pattern = "user_online:*";
                $keys = $this->redis->keys($pattern);
                $onlineUsers = count($keys);
            }
            
            return [
                'active_connections' => $activeConnections,
                'online_users' => $onlineUsers,
                'redis_available' => $this->redis !== null
            ];
            
        } catch (Exception $e) {
            Logger::error("Failed to get connection stats", [
                'error' => $e->getMessage()
            ]);
            return ['active_connections' => 0, 'online_users' => 0];
        }
    }
}

/**
 * WebSocket Connection Model
 */
class WebSocketConnection extends BaseModel {
    protected $table = 'websocket_connections';
    
    public function findByConnectionId($connectionId) {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE connection_id = ?");
        $stmt->execute([$connectionId]);
        return $stmt->fetch();
    }
    
    public function getLastUserConnection($userId) {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} 
             WHERE user_id = ? 
             ORDER BY last_ping DESC 
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    public function getUserActiveConnections($userId) {
        return $this->where(['user_id' => $userId, 'is_active' => true]);
    }
    
    public function cleanupStaleConnections($maxAge = 300) {
        $sql = "UPDATE {$this->table} 
                SET is_active = FALSE 
                WHERE is_active = TRUE 
                AND last_ping < DATE_SUB(NOW(), INTERVAL ? SECOND)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$maxAge]);
    }
}