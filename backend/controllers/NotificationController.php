<?php
/**
 * Notification Controller
 * Handles notification settings and push notification subscriptions
 */

class NotificationController {
    private $notificationSettingsModel;
    private $pushSubscriptionModel;
    private $webSocketService;
    
    public function __construct() {
        $this->notificationSettingsModel = new MessageNotificationSettings();
        $this->pushSubscriptionModel = new PushSubscription();
        $this->webSocketService = new WebSocketService();
    }
    
    /**
     * Get user notification settings
     * GET /v1/notifications/settings
     */
    public function getSettings($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        try {
            $settings = $this->notificationSettingsModel->getUserSettings($userId);
            
            if (!$settings) {
                // Create default settings if none exist
                $settings = $this->notificationSettingsModel->createDefaultSettings($userId);
            }
            
            // Remove sensitive data
            unset($settings['id'], $settings['user_id']);
            
            Response::success(['settings' => $settings]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get notification settings", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to get notification settings');
        }
    }
    
    /**
     * Update user notification settings
     * PUT /v1/notifications/settings
     */
    public function updateSettings($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        $data = Request::validate([
            'email_notifications' => '',
            'push_notifications' => '',
            'in_app_notifications' => '',
            'sound_notifications' => '',
            'notification_frequency' => 'in:instant,hourly,daily,never',
            'quiet_hours_start' => '',
            'quiet_hours_end' => ''
        ]);
        
        // Convert boolean strings to actual booleans
        foreach (['email_notifications', 'push_notifications', 'in_app_notifications', 'sound_notifications'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
        
        // Validate time format for quiet hours
        if (!empty($data['quiet_hours_start']) && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $data['quiet_hours_start'])) {
            Response::error('Invalid quiet_hours_start format. Use HH:MM:SS');
        }
        
        if (!empty($data['quiet_hours_end']) && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $data['quiet_hours_end'])) {
            Response::error('Invalid quiet_hours_end format. Use HH:MM:SS');
        }
        
        try {
            $result = $this->notificationSettingsModel->updateUserSettings($userId, $data);
            
            if ($result) {
                $updatedSettings = $this->notificationSettingsModel->getUserSettings($userId);
                unset($updatedSettings['id'], $updatedSettings['user_id']);
                
                Response::success([
                    'settings' => $updatedSettings
                ], 'Notification settings updated');
            } else {
                Response::error('Failed to update notification settings');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to update notification settings", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to update notification settings');
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
            'endpoint' => 'required|max:500',
            'p256dh_key' => 'required|max:255',
            'auth_key' => 'required|max:255'
        ]);
        
        try {
            $userAgent = Request::header('User-Agent');
            
            $subscriptionId = $this->pushSubscriptionModel->addSubscription(
                $userId,
                $data['endpoint'],
                $data['p256dh_key'],
                $data['auth_key'],
                $userAgent
            );
            
            if ($subscriptionId) {
                Response::success([
                    'subscription_id' => $subscriptionId
                ], 'Push notification subscription added');
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
            'endpoint' => 'required|max:500'
        ]);
        
        try {
            $result = $this->pushSubscriptionModel->removeUserSubscription($userId, $data['endpoint']);
            
            if ($result) {
                Response::success([], 'Push notification subscription removed');
            } else {
                Response::error('Failed to remove push subscription or subscription not found');
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
     * Get user's push subscriptions
     * GET /v1/push/subscriptions
     */
    public function getPushSubscriptions($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        try {
            $subscriptions = $this->pushSubscriptionModel->getUserSubscriptions($userId);
            
            // Remove sensitive data
            foreach ($subscriptions as &$subscription) {
                unset($subscription['p256dh_key'], $subscription['auth_key']);
            }
            
            Response::success(['subscriptions' => $subscriptions]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get push subscriptions", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to get push subscriptions');
        }
    }
    
    /**
     * Test push notification
     * POST /v1/push/test
     */
    public function testPushNotification($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        try {
            $notificationService = new NotificationService();
            
            $result = $notificationService->sendSystemNotification(
                $userId,
                'test',
                'Test Notification',
                'This is a test push notification from Bazar.',
                ['test' => true, 'timestamp' => time()]
            );
            
            if ($result) {
                Response::success([], 'Test notification sent');
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
     * Get in-app notifications
     * GET /v1/notifications
     */
    public function getNotifications($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        $page = (int)(Request::get('page', 1));
        $limit = min((int)(Request::get('limit', 20)), 50);
        $unreadOnly = Request::get('unread_only', false);
        
        try {
            // For now, we'll return sample data as we haven't implemented the notifications table
            // In a full implementation, you would query the notifications table
            
            $notifications = $this->getSampleNotifications($userId, $page, $limit, $unreadOnly);
            
            Response::success([
                'notifications' => $notifications,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => count($notifications) == $limit
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get notifications", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to get notifications');
        }
    }
    
    /**
     * Mark notification as read
     * PUT /v1/notifications/{id}/read
     */
    public function markNotificationAsRead($params) {
        $userId = $this->getCurrentUserId();
        $notificationId = $params['id'];
        
        if (!$userId || !$notificationId) {
            Response::unauthorized();
        }
        
        try {
            // In a full implementation, you would update the notifications table
            // For now, we'll just return success
            
            Response::success([], 'Notification marked as read');
            
        } catch (Exception $e) {
            Logger::error("Failed to mark notification as read", [
                'notification_id' => $notificationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to mark notification as read');
        }
    }
    
    /**
     * Mark all notifications as read
     * PUT /v1/notifications/read-all
     */
    public function markAllNotificationsAsRead($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        try {
            // In a full implementation, you would update all unread notifications for the user
            // For now, we'll just return success
            
            Response::success([], 'All notifications marked as read');
            
        } catch (Exception $e) {
            Logger::error("Failed to mark all notifications as read", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to mark all notifications as read');
        }
    }
    
    /**
     * Delete notification
     * DELETE /v1/notifications/{id}
     */
    public function deleteNotification($params) {
        $userId = $this->getCurrentUserId();
        $notificationId = $params['id'];
        
        if (!$userId || !$notificationId) {
            Response::unauthorized();
        }
        
        try {
            // In a full implementation, you would delete from notifications table
            // For now, we'll just return success
            
            Response::success([], 'Notification deleted');
            
        } catch (Exception $e) {
            Logger::error("Failed to delete notification", [
                'notification_id' => $notificationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to delete notification');
        }
    }
    
    /**
     * Get notification statistics
     * GET /v1/notifications/stats
     */
    public function getNotificationStats($params = []) {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Response::unauthorized();
        }
        
        try {
            // In a full implementation, you would query the database
            $stats = [
                'total_notifications' => 15,
                'unread_notifications' => 3,
                'unread_messages' => 2,
                'unread_system' => 1
            ];
            
            Response::success(['stats' => $stats]);
            
        } catch (Exception $e) {
            Logger::error("Failed to get notification stats", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to get notification statistics');
        }
    }
    
    /**
     * Handle unsubscribe from email notifications
     * GET /v1/notifications/unsubscribe
     */
    public function unsubscribeEmail($params = []) {
        $token = Request::get('token');
        
        if (!$token) {
            Response::error('Invalid unsubscribe token');
        }
        
        try {
            // Decode token
            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);
            
            if (count($parts) !== 3 || $parts[0] !== 'unsubscribe') {
                Response::error('Invalid unsubscribe token');
            }
            
            $userId = (int)$parts[1];
            $timestamp = (int)$parts[2];
            
            // Check if token is not too old (30 days)
            if (time() - $timestamp > 30 * 24 * 60 * 60) {
                Response::error('Unsubscribe token has expired');
            }
            
            // Update settings to disable email notifications
            $result = $this->notificationSettingsModel->updateUserSettings($userId, [
                'email_notifications' => false
            ]);
            
            if ($result) {
                Response::success([], 'You have been unsubscribed from email notifications');
            } else {
                Response::error('Failed to unsubscribe');
            }
            
        } catch (Exception $e) {
            Logger::error("Failed to process email unsubscribe", [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            Response::serverError('Failed to process unsubscribe request');
        }
    }
    
    /**
     * Get sample notifications (placeholder implementation)
     */
    private function getSampleNotifications($userId, $page, $limit, $unreadOnly) {
        // This would normally query the notifications table
        // For now, return sample data
        
        $notifications = [
            [
                'id' => 1,
                'type' => 'new_message',
                'title' => 'New message from John Doe',
                'message' => 'Hi, is this item still available?',
                'is_read' => false,
                'created_at' => date('c', time() - 3600),
                'data' => [
                    'conversation_id' => 1,
                    'sender_name' => 'John Doe'
                ]
            ],
            [
                'id' => 2,
                'type' => 'price_change',
                'title' => 'Price reduced on your watched item',
                'message' => 'iPhone 12 Pro is now €650 (was €750)',
                'is_read' => true,
                'created_at' => date('c', time() - 7200),
                'data' => [
                    'article_id' => 123,
                    'old_price' => 750,
                    'new_price' => 650
                ]
            ],
            [
                'id' => 3,
                'type' => 'new_message',
                'title' => 'New message from Sarah Wilson',
                'message' => 'Would you consider €500?',
                'is_read' => false,
                'created_at' => date('c', time() - 10800),
                'data' => [
                    'conversation_id' => 2,
                    'sender_name' => 'Sarah Wilson'
                ]
            ]
        ];
        
        if ($unreadOnly) {
            $notifications = array_filter($notifications, function($n) { 
                return !$n['is_read']; 
            });
        }
        
        // Apply pagination
        $offset = ($page - 1) * $limit;
        return array_slice($notifications, $offset, $limit);
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