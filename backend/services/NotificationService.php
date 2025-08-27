<?php
/**
 * Notification Service
 * Handles email notifications, push notifications, and in-app notifications for messaging
 */

class NotificationService {
    private $userModel;
    private $notificationSettingsModel;
    private $pushSubscriptionModel;
    private $emailService;
    
    public function __construct() {
        $this->userModel = new User();
        $this->notificationSettingsModel = new MessageNotificationSettings();
        $this->pushSubscriptionModel = new PushSubscription();
        $this->emailService = new EmailService();
    }
    
    /**
     * Send message notification to user
     */
    public function sendMessageNotification($message, $recipientId) {
        try {
            // Get recipient user info
            $recipient = $this->userModel->find($recipientId);
            if (!$recipient) {
                return false;
            }
            
            // Get sender info
            $sender = $this->userModel->find($message['sender_id']);
            if (!$sender) {
                return false;
            }
            
            // Get notification settings
            $settings = $this->notificationSettingsModel->getUserSettings($recipientId);
            if (!$settings) {
                // Use default settings if none exist
                $settings = $this->notificationSettingsModel->createDefaultSettings($recipientId);
            }
            
            // Check if user wants notifications
            if (!$this->shouldSendNotification($settings)) {
                return true; // Not an error, just user preference
            }
            
            // Get conversation info
            $conversationModel = new Conversation();
            $conversation = $conversationModel->getConversationDetails($message['conversation_id']);
            
            $notificationData = [
                'message' => $message,
                'sender' => $sender,
                'recipient' => $recipient,
                'conversation' => $conversation,
                'settings' => $settings
            ];
            
            // Send different types of notifications based on user preferences
            $results = [];
            
            if ($settings['email_notifications']) {
                $results['email'] = $this->sendEmailNotification($notificationData);
            }
            
            if ($settings['push_notifications']) {
                $results['push'] = $this->sendPushNotification($notificationData);
            }
            
            // In-app notifications are always sent (they're just stored in database)
            if ($settings['in_app_notifications']) {
                $results['in_app'] = $this->createInAppNotification($notificationData);
            }
            
            return $results;
            
        } catch (Exception $e) {
            Logger::error("Failed to send message notification", [
                'message_id' => $message['id'] ?? null,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Send email notification for new message
     */
    private function sendEmailNotification($data) {
        $message = $data['message'];
        $sender = $data['sender'];
        $recipient = $data['recipient'];
        $conversation = $data['conversation'];
        
        try {
            $subject = "New message from {$sender['first_name']} {$sender['last_name']} - Bazar";
            
            $preview = $this->getMessagePreview($message);
            $articleTitle = $conversation['article_title'] ?? 'Article';
            
            $emailData = [
                'recipient_name' => $recipient['first_name'] . ' ' . $recipient['last_name'],
                'sender_name' => $sender['first_name'] . ' ' . $sender['last_name'],
                'sender_username' => $sender['username'],
                'message_preview' => $preview,
                'article_title' => $articleTitle,
                'conversation_url' => $this->buildConversationUrl($conversation['id']),
                'unsubscribe_url' => $this->buildUnsubscribeUrl($recipient['id']),
                'timestamp' => date('F j, Y \a\t g:i A', strtotime($message['created_at']))
            ];
            
            $htmlBody = $this->buildMessageEmailTemplate($emailData);
            $textBody = $this->buildMessageEmailTextTemplate($emailData);
            
            return $this->emailService->send(
                $recipient['email'],
                $subject,
                $htmlBody,
                $textBody,
                ['reply_to' => $_ENV['NOREPLY_EMAIL'] ?? 'noreply@bazar.com']
            );
            
        } catch (Exception $e) {
            Logger::error("Failed to send email notification", [
                'recipient_id' => $recipient['id'],
                'sender_id' => $sender['id'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Send push notification for new message
     */
    private function sendPushNotification($data) {
        $message = $data['message'];
        $sender = $data['sender'];
        $recipient = $data['recipient'];
        $conversation = $data['conversation'];
        
        try {
            // Get user's push subscriptions
            $subscriptions = $this->pushSubscriptionModel->getUserSubscriptions($recipient['id']);
            if (empty($subscriptions)) {
                return true; // No subscriptions, but not an error
            }
            
            $preview = $this->getMessagePreview($message);
            $senderName = $sender['first_name'] . ' ' . $sender['last_name'];
            $articleTitle = $conversation['article_title'] ?? 'Article';
            
            $pushData = [
                'title' => "New message from {$senderName}",
                'body' => "Re: {$articleTitle} - {$preview}",
                'icon' => '/assets/icons/message-icon.png',
                'badge' => '/assets/icons/badge.png',
                'tag' => "conversation_{$conversation['id']}",
                'data' => [
                    'conversation_id' => $conversation['id'],
                    'message_id' => $message['id'],
                    'sender_id' => $sender['id'],
                    'type' => 'new_message',
                    'url' => "/messages/{$conversation['id']}"
                ],
                'actions' => [
                    [
                        'action' => 'reply',
                        'title' => 'Reply',
                        'icon' => '/assets/icons/reply.png'
                    ],
                    [
                        'action' => 'view',
                        'title' => 'View',
                        'icon' => '/assets/icons/view.png'
                    ]
                ],
                'requireInteraction' => true,
                'silent' => !$data['settings']['sound_notifications']
            ];
            
            $results = [];
            foreach ($subscriptions as $subscription) {
                $result = $this->sendWebPushNotification($subscription, $pushData);
                $results[] = $result;
                
                // Remove invalid subscriptions
                if ($result === 'invalid_subscription') {
                    $this->pushSubscriptionModel->markAsInactive($subscription['id']);
                }
            }
            
            return in_array(true, $results); // Return true if at least one succeeded
            
        } catch (Exception $e) {
            Logger::error("Failed to send push notification", [
                'recipient_id' => $recipient['id'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Create in-app notification record
     */
    private function createInAppNotification($data) {
        $message = $data['message'];
        $sender = $data['sender'];
        $recipient = $data['recipient'];
        $conversation = $data['conversation'];
        
        try {
            $notificationData = [
                'user_id' => $recipient['id'],
                'type' => 'new_message',
                'title' => "New message from {$sender['first_name']} {$sender['last_name']}",
                'message' => $this->getMessagePreview($message),
                'data' => json_encode([
                    'conversation_id' => $conversation['id'],
                    'message_id' => $message['id'],
                    'sender_id' => $sender['id'],
                    'article_title' => $conversation['article_title'] ?? null
                ]),
                'is_read' => false
            ];
            
            return $this->createNotificationRecord($notificationData);
            
        } catch (Exception $e) {
            Logger::error("Failed to create in-app notification", [
                'recipient_id' => $recipient['id'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Send typing notification
     */
    public function sendTypingNotification($conversationId, $senderId, $isTyping) {
        // This is handled by WebSocketService in real-time
        // This method can be used for additional logging or processing
        
        Logger::debug("Typing notification", [
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'is_typing' => $isTyping
        ]);
        
        return true;
    }
    
    /**
     * Send system notification (article sold, price changed, etc.)
     */
    public function sendSystemNotification($userId, $type, $title, $message, $data = []) {
        try {
            $notificationData = [
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => json_encode($data),
                'is_read' => false
            ];
            
            $result = $this->createNotificationRecord($notificationData);
            
            // Also send via WebSocket if user is online
            $webSocketService = new WebSocketService();
            $webSocketService->sendNotification($userId, [
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'created_at' => date('c')
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            Logger::error("Failed to send system notification", [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Send bulk notifications (e.g., for promotional messages)
     */
    public function sendBulkNotification($userIds, $title, $message, $type = 'announcement') {
        $results = [];
        $batchSize = 100;
        
        foreach (array_chunk($userIds, $batchSize) as $batch) {
            foreach ($batch as $userId) {
                $results[$userId] = $this->sendSystemNotification($userId, $type, $title, $message);
            }
            
            // Small delay between batches to avoid overwhelming the system
            usleep(100000); // 100ms
        }
        
        return $results;
    }
    
    /**
     * Get message preview for notifications
     */
    private function getMessagePreview($message, $maxLength = 100) {
        $content = $message['content'];
        
        // Handle different message types
        switch ($message['message_type']) {
            case 'image':
                return '📷 Sent a photo';
            case 'file':
                return '📄 Sent a file';
            case 'offer':
                $metadata = json_decode($message['metadata'] ?? '{}', true);
                $amount = $metadata['offer_amount'] ?? 'unknown';
                return "💰 Made an offer of €{$amount}";
            case 'system':
                return $content;
            default:
                // Text message
                $stripped = strip_tags($content);
                return strlen($stripped) > $maxLength 
                    ? substr($stripped, 0, $maxLength) . '...' 
                    : $stripped;
        }
    }
    
    /**
     * Check if notification should be sent based on user settings
     */
    private function shouldSendNotification($settings) {
        // Check quiet hours
        if ($settings['quiet_hours_start'] && $settings['quiet_hours_end']) {
            $now = date('H:i:s');
            $start = $settings['quiet_hours_start'];
            $end = $settings['quiet_hours_end'];
            
            // Handle quiet hours that span midnight
            if ($start <= $end) {
                if ($now >= $start && $now <= $end) {
                    return false;
                }
            } else {
                if ($now >= $start || $now <= $end) {
                    return false;
                }
            }
        }
        
        // Check notification frequency
        if ($settings['notification_frequency'] === 'never') {
            return false;
        }
        
        // For hourly/daily, implement batching logic here if needed
        // For now, we'll send instant notifications
        
        return true;
    }
    
    /**
     * Send web push notification using VAPID
     */
    private function sendWebPushNotification($subscription, $pushData) {
        try {
            // This would typically use a library like web-push-php
            // For now, we'll simulate the API call
            
            $payload = json_encode($pushData);
            $endpoint = $subscription['endpoint'];
            $p256dh = $subscription['p256dh_key'];
            $auth = $subscription['auth_key'];
            
            // In a real implementation, you would use:
            // - web-push-php library
            // - VAPID keys for authentication
            // - Proper encryption for the payload
            
            // Simulate API call
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->getVapidToken(),
                'TTL: 3600'
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                return true;
            } elseif ($httpCode === 410 || $httpCode === 404) {
                return 'invalid_subscription';
            }
            
            return false;
            
        } catch (Exception $e) {
            Logger::error("Push notification failed", [
                'endpoint' => $subscription['endpoint'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get VAPID token (placeholder implementation)
     */
    private function getVapidToken() {
        // In a real implementation, this would generate a proper VAPID JWT token
        return $_ENV['VAPID_TOKEN'] ?? 'fake_vapid_token_for_development';
    }
    
    /**
     * Build conversation URL for email notifications
     */
    private function buildConversationUrl($conversationId) {
        $baseUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
        return "{$baseUrl}/messages/{$conversationId}";
    }
    
    /**
     * Build unsubscribe URL for email notifications
     */
    private function buildUnsubscribeUrl($userId) {
        $baseUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
        $token = base64_encode("unsubscribe:{$userId}:" . time());
        return "{$baseUrl}/unsubscribe?token={$token}";
    }
    
    /**
     * Create notification record in database
     */
    private function createNotificationRecord($data) {
        // Assuming we have a notifications table
        // For now, we'll use a simple file-based storage
        
        $logFile = __DIR__ . '/../../logs/notifications.json';
        $logData = [
            'id' => uniqid(),
            'timestamp' => date('c'),
            'data' => $data
        ];
        
        if (file_exists($logFile)) {
            $existingData = json_decode(file_get_contents($logFile), true) ?: [];
        } else {
            $existingData = [];
        }
        
        $existingData[] = $logData;
        
        // Keep only last 1000 notifications
        if (count($existingData) > 1000) {
            $existingData = array_slice($existingData, -1000);
        }
        
        return file_put_contents($logFile, json_encode($existingData, JSON_PRETTY_PRINT));
    }
    
    /**
     * Build HTML email template for message notifications
     */
    private function buildMessageEmailTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>New Message - Bazar</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2563eb; color: white; padding: 20px; text-align: center; }
                .content { background: #f8f9fa; padding: 20px; }
                .message-preview { background: white; padding: 15px; border-left: 4px solid #2563eb; margin: 15px 0; }
                .button { display: inline-block; background: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; }
                .footer { text-align: center; font-size: 12px; color: #666; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>💬 New Message</h1>
                </div>
                <div class='content'>
                    <h2>Hi {$data['recipient_name']},</h2>
                    <p>You have a new message from <strong>{$data['sender_name']}</strong> about your article <strong>{$data['article_title']}</strong>.</p>
                    
                    <div class='message-preview'>
                        <strong>{$data['sender_name']} ({$data['sender_username']}):</strong><br>
                        {$data['message_preview']}
                    </div>
                    
                    <p><a href='{$data['conversation_url']}' class='button'>View Conversation</a></p>
                    
                    <p><small>Sent on {$data['timestamp']}</small></p>
                </div>
                <div class='footer'>
                    <p>You're receiving this because you have message notifications enabled.</p>
                    <p><a href='{$data['unsubscribe_url']}'>Unsubscribe from message notifications</a></p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Build text email template for message notifications
     */
    private function buildMessageEmailTextTemplate($data) {
        return "
        New Message - Bazar
        
        Hi {$data['recipient_name']},
        
        You have a new message from {$data['sender_name']} about your article {$data['article_title']}.
        
        Message:
        {$data['sender_name']} ({$data['sender_username']}): {$data['message_preview']}
        
        View conversation: {$data['conversation_url']}
        
        Sent on {$data['timestamp']}
        
        ---
        You're receiving this because you have message notifications enabled.
        To unsubscribe: {$data['unsubscribe_url']}
        ";
    }
}

/**
 * Message Notification Settings Model
 */
class MessageNotificationSettings extends BaseModel {
    protected $table = 'message_notification_settings';
    
    public function getUserSettings($userId) {
        return $this->where(['user_id' => $userId])[0] ?? null;
    }
    
    public function createDefaultSettings($userId) {
        $data = [
            'user_id' => $userId,
            'email_notifications' => true,
            'push_notifications' => true,
            'in_app_notifications' => true,
            'sound_notifications' => true,
            'notification_frequency' => 'instant'
        ];
        
        $id = $this->create($data);
        return $id ? $this->find($id) : null;
    }
    
    public function updateUserSettings($userId, $settings) {
        $existing = $this->getUserSettings($userId);
        
        if ($existing) {
            return $this->update($existing['id'], $settings);
        } else {
            $settings['user_id'] = $userId;
            return $this->create($settings);
        }
    }
}

/**
 * Push Subscription Model
 */
class PushSubscription extends BaseModel {
    protected $table = 'push_subscriptions';
    
    public function getUserSubscriptions($userId) {
        return $this->where(['user_id' => $userId, 'is_active' => true]);
    }
    
    public function addSubscription($userId, $endpoint, $p256dhKey, $authKey, $userAgent = null) {
        $data = [
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'p256dh_key' => $p256dhKey,
            'auth_key' => $authKey,
            'user_agent' => $userAgent,
            'is_active' => true
        ];
        
        // Remove existing subscription with same endpoint
        $existing = $this->where(['user_id' => $userId, 'endpoint' => $endpoint]);
        if ($existing) {
            $this->update($existing[0]['id'], ['is_active' => false]);
        }
        
        return $this->create($data);
    }
    
    public function markAsInactive($subscriptionId) {
        return $this->update($subscriptionId, ['is_active' => false]);
    }
    
    public function removeUserSubscription($userId, $endpoint) {
        $subscription = $this->where(['user_id' => $userId, 'endpoint' => $endpoint]);
        if ($subscription) {
            return $this->update($subscription[0]['id'], ['is_active' => false]);
        }
        return false;
    }
}

/**
 * Email Service (placeholder implementation)
 */
class EmailService {
    public function send($to, $subject, $htmlBody, $textBody = null, $options = []) {
        // In a real implementation, this would use a service like:
        // - SendGrid
        // - Mailgun  
        // - Amazon SES
        // - PHPMailer with SMTP
        
        try {
            // For development, just log the email
            Logger::info("Email sent", [
                'to' => $to,
                'subject' => $subject,
                'html_length' => strlen($htmlBody),
                'text_length' => strlen($textBody ?? ''),
                'options' => $options
            ]);
            
            // Simulate successful send
            return true;
            
        } catch (Exception $e) {
            Logger::error("Failed to send email", [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}