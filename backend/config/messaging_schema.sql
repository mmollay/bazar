-- Bazar Marketplace - Enhanced Messaging System Schema
-- Extends the existing database schema with comprehensive messaging capabilities

USE bazar_marketplace;

-- Drop existing basic messages table to replace with enhanced version
DROP TABLE IF EXISTS messages;

-- Conversations table for organizing messages between users about articles
CREATE TABLE conversations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    article_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    status ENUM('active', 'archived', 'blocked') DEFAULT 'active',
    last_message_id INT NULL,
    last_message_at TIMESTAMP NULL,
    buyer_unread_count INT DEFAULT 0,
    seller_unread_count INT DEFAULT 0,
    is_buyer_typing BOOLEAN DEFAULT FALSE,
    is_seller_typing BOOLEAN DEFAULT FALSE,
    buyer_last_seen TIMESTAMP NULL,
    seller_last_seen TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_conversation (article_id, buyer_id, seller_id),
    INDEX idx_article_id (article_id),
    INDEX idx_buyer_id (buyer_id),
    INDEX idx_seller_id (seller_id),
    INDEX idx_last_message_at (last_message_at),
    INDEX idx_status (status)
);

-- Messages table with enhanced features
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    content TEXT NOT NULL,
    message_type ENUM('text', 'image', 'file', 'system', 'offer') DEFAULT 'text',
    is_read BOOLEAN DEFAULT FALSE,
    is_edited BOOLEAN DEFAULT FALSE,
    edited_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    reply_to_message_id INT NULL,
    system_message_type VARCHAR(50) NULL, -- For system messages like 'price_change', 'status_update', etc.
    metadata JSON NULL, -- For storing offer amounts, file info, etc.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reply_to_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_sender_id (sender_id),
    INDEX idx_created_at (created_at),
    INDEX idx_is_read (is_read),
    INDEX idx_message_type (message_type),
    FULLTEXT KEY ft_content (content)
);

-- Message attachments for file/image sharing
CREATE TABLE message_attachments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    width INT NULL, -- For images
    height INT NULL, -- For images
    thumbnail_path VARCHAR(500) NULL, -- For images
    is_image BOOLEAN DEFAULT FALSE,
    upload_progress TINYINT DEFAULT 100, -- For tracking upload progress
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    INDEX idx_message_id (message_id),
    INDEX idx_is_image (is_image)
);

-- Message reactions (like emoji reactions)
CREATE TABLE message_reactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    emoji VARCHAR(10) NOT NULL, -- Unicode emoji
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_reaction (message_id, user_id, emoji),
    INDEX idx_message_id (message_id)
);

-- Notification preferences for messaging
CREATE TABLE message_notification_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    email_notifications BOOLEAN DEFAULT TRUE,
    push_notifications BOOLEAN DEFAULT TRUE,
    in_app_notifications BOOLEAN DEFAULT TRUE,
    sound_notifications BOOLEAN DEFAULT TRUE,
    notification_frequency ENUM('instant', 'hourly', 'daily', 'never') DEFAULT 'instant',
    quiet_hours_start TIME NULL,
    quiet_hours_end TIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_settings (user_id)
);

-- Push notification subscriptions for web push
CREATE TABLE push_subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    user_agent TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_subscription (user_id, endpoint),
    INDEX idx_user_id (user_id),
    INDEX idx_active (is_active)
);

-- Message delivery tracking (for read receipts, etc.)
CREATE TABLE message_delivery_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('sent', 'delivered', 'read') NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_delivery (message_id, user_id),
    INDEX idx_message_id (message_id),
    INDEX idx_status (status)
);

-- Conversation participants (for future group messaging)
CREATE TABLE conversation_participants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('buyer', 'seller', 'admin', 'participant') DEFAULT 'participant',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    left_at TIMESTAMP NULL,
    is_muted BOOLEAN DEFAULT FALSE,
    last_read_message_id INT NULL,
    
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (last_read_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    UNIQUE KEY unique_participant (conversation_id, user_id),
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_user_id (user_id)
);

-- Blocked conversations/users
CREATE TABLE message_blocks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    blocker_id INT NOT NULL,
    blocked_id INT NOT NULL,
    conversation_id INT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    UNIQUE KEY unique_block (blocker_id, blocked_id),
    INDEX idx_blocker_id (blocker_id),
    INDEX idx_blocked_id (blocked_id)
);

-- WebSocket connections tracking
CREATE TABLE websocket_connections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    connection_id VARCHAR(255) NOT NULL,
    socket_id VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_ping TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_agent TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_connection (connection_id),
    INDEX idx_user_id (user_id),
    INDEX idx_active (is_active),
    INDEX idx_socket_id (socket_id)
);

-- Add foreign key constraint to conversations for last_message_id
ALTER TABLE conversations ADD FOREIGN KEY (last_message_id) REFERENCES messages(id) ON DELETE SET NULL;

-- Create indexes for better performance
CREATE INDEX idx_conversations_updated_at ON conversations(updated_at DESC);
CREATE INDEX idx_messages_conversation_created ON messages(conversation_id, created_at DESC);
CREATE INDEX idx_unread_messages ON messages(conversation_id, is_read, created_at);
CREATE INDEX idx_user_conversations ON conversations(buyer_id, seller_id, last_message_at DESC);

-- Create triggers for updating conversation metadata
DELIMITER $$

-- Trigger to update conversation when new message is added
CREATE TRIGGER update_conversation_on_new_message
    AFTER INSERT ON messages
    FOR EACH ROW
BEGIN
    DECLARE buyer_id INT;
    DECLARE seller_id INT;
    
    -- Get buyer and seller IDs from conversation
    SELECT c.buyer_id, c.seller_id INTO buyer_id, seller_id 
    FROM conversations c WHERE c.id = NEW.conversation_id;
    
    -- Update conversation metadata
    UPDATE conversations SET 
        last_message_id = NEW.id,
        last_message_at = NEW.created_at,
        buyer_unread_count = CASE 
            WHEN NEW.sender_id != buyer_id THEN buyer_unread_count + 1 
            ELSE buyer_unread_count 
        END,
        seller_unread_count = CASE 
            WHEN NEW.sender_id != seller_id THEN seller_unread_count + 1 
            ELSE seller_unread_count 
        END,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.conversation_id;
END$$

-- Trigger to update unread count when message is marked as read
CREATE TRIGGER update_unread_count_on_read
    AFTER UPDATE ON messages
    FOR EACH ROW
BEGIN
    DECLARE buyer_id INT;
    DECLARE seller_id INT;
    
    -- Only proceed if is_read changed from FALSE to TRUE
    IF OLD.is_read = FALSE AND NEW.is_read = TRUE THEN
        -- Get buyer and seller IDs from conversation
        SELECT c.buyer_id, c.seller_id INTO buyer_id, seller_id 
        FROM conversations c WHERE c.id = NEW.conversation_id;
        
        -- Decrease unread count for the recipient
        UPDATE conversations SET 
            buyer_unread_count = CASE 
                WHEN NEW.sender_id != buyer_id AND buyer_unread_count > 0 
                THEN buyer_unread_count - 1 
                ELSE buyer_unread_count 
            END,
            seller_unread_count = CASE 
                WHEN NEW.sender_id != seller_id AND seller_unread_count > 0 
                THEN seller_unread_count - 1 
                ELSE seller_unread_count 
            END
        WHERE id = NEW.conversation_id;
    END IF;
END$$

DELIMITER ;

-- Insert default notification settings for existing users
INSERT IGNORE INTO message_notification_settings (user_id) 
SELECT id FROM users;

-- Create views for commonly used queries
CREATE VIEW conversation_with_participants AS
SELECT 
    c.*,
    buyer.username as buyer_username,
    buyer.avatar_url as buyer_avatar,
    seller.username as seller_username,
    seller.avatar_url as seller_avatar,
    a.title as article_title,
    a.price as article_price,
    a.status as article_status,
    lm.content as last_message_content,
    lm.message_type as last_message_type,
    lm.sender_id as last_message_sender_id
FROM conversations c
JOIN users buyer ON c.buyer_id = buyer.id
JOIN users seller ON c.seller_id = seller.id
JOIN articles a ON c.article_id = a.id
LEFT JOIN messages lm ON c.last_message_id = lm.id;

-- View for message threads with sender info
CREATE VIEW messages_with_sender AS
SELECT 
    m.*,
    u.username as sender_username,
    u.avatar_url as sender_avatar,
    u.first_name as sender_first_name,
    u.last_name as sender_last_name
FROM messages m
JOIN users u ON m.sender_id = u.id;