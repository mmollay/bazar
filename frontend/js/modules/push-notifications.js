/**
 * Push Notifications Module
 * Handles push notification subscription, permissions, and integration with service worker
 */

window.PushNotificationsModule = (function() {
    'use strict';
    
    // Private variables
    let serviceWorkerRegistration = null;
    let currentSubscription = null;
    let isSupported = false;
    let permissionStatus = 'default';
    
    // Configuration
    const config = {
        applicationServerKey: 'BEl62iUYgUivxIkv69yViEuiBIa40HgMcLIJVmgKGg4kSxHgaVGddKm6dSq4aGCCl2lWRGfTPJL2FZUOzJdDrto', // VAPID public key
        apiBaseUrl: '/bazar/backend/api/v1',
        serviceWorkerPath: '/bazar/sw-messages.js',
        iconUrl: '/bazar/frontend/assets/icons/message-icon.png',
        badgeUrl: '/bazar/frontend/assets/icons/badge.png'
    };
    
    /**
     * Initialize push notifications
     */
    async function init() {
        try {
            // Check browser support
            isSupported = checkSupport();
            if (!isSupported) {
                console.warn('Push notifications are not supported in this browser');
                return false;
            }
            
            // Register service worker
            serviceWorkerRegistration = await registerServiceWorker();
            if (!serviceWorkerRegistration) {
                console.error('Failed to register service worker');
                return false;
            }
            
            // Check current permission status
            permissionStatus = Notification.permission;
            
            // Get current subscription if exists
            currentSubscription = await serviceWorkerRegistration.pushManager.getSubscription();
            
            // Set up event listeners
            setupEventListeners();
            
            console.log('Push notifications module initialized successfully');
            return true;
            
        } catch (error) {
            console.error('Failed to initialize push notifications:', error);
            return false;
        }
    }
    
    /**
     * Check if push notifications are supported
     */
    function checkSupport() {
        if (!('serviceWorker' in navigator)) {
            console.warn('Service workers are not supported');
            return false;
        }
        
        if (!('PushManager' in window)) {
            console.warn('Push messaging is not supported');
            return false;
        }
        
        if (!('Notification' in window)) {
            console.warn('Notifications are not supported');
            return false;
        }
        
        return true;
    }
    
    /**
     * Register service worker
     */
    async function registerServiceWorker() {
        try {
            const registration = await navigator.serviceWorker.register(config.serviceWorkerPath, {
                scope: '/bazar/'
            });
            
            console.log('Service worker registered successfully:', registration.scope);
            
            // Handle service worker updates
            registration.addEventListener('updatefound', () => {
                console.log('New service worker available');
                handleServiceWorkerUpdate(registration);
            });
            
            return registration;
            
        } catch (error) {
            console.error('Service worker registration failed:', error);
            return null;
        }
    }
    
    /**
     * Handle service worker updates
     */
    function handleServiceWorkerUpdate(registration) {
        const newWorker = registration.installing;
        
        newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                // New service worker is available
                showUpdateAvailableNotification();
            }
        });
    }
    
    /**
     * Show notification about available update
     */
    function showUpdateAvailableNotification() {
        if (window.ToastModule) {
            window.ToastModule.showInfo(
                'A new version is available. Refresh to update.',
                {
                    duration: 10000,
                    action: {
                        text: 'Refresh',
                        callback: () => window.location.reload()
                    }
                }
            );
        }
    }
    
    /**
     * Request notification permission
     */
    async function requestPermission() {
        if (!isSupported) {
            throw new Error('Push notifications are not supported');
        }
        
        if (permissionStatus === 'granted') {
            return true;
        }
        
        try {
            const permission = await Notification.requestPermission();
            permissionStatus = permission;
            
            console.log('Notification permission:', permission);
            
            // Trigger permission change event
            triggerEvent('permissionchange', { permission });
            
            return permission === 'granted';
            
        } catch (error) {
            console.error('Failed to request notification permission:', error);
            throw error;
        }
    }
    
    /**
     * Subscribe to push notifications
     */
    async function subscribe() {
        try {
            // Request permission first
            const hasPermission = await requestPermission();
            if (!hasPermission) {
                throw new Error('Notification permission denied');
            }
            
            if (!serviceWorkerRegistration) {
                throw new Error('Service worker not registered');
            }
            
            // Check if already subscribed
            if (currentSubscription) {
                console.log('Already subscribed to push notifications');
                return currentSubscription;
            }
            
            // Subscribe to push manager
            const subscription = await serviceWorkerRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(config.applicationServerKey)
            });
            
            console.log('Push subscription created:', subscription);
            
            // Send subscription to server
            const success = await sendSubscriptionToServer(subscription);
            if (!success) {
                // Unsubscribe if server registration failed
                await subscription.unsubscribe();
                throw new Error('Failed to register subscription with server');
            }
            
            currentSubscription = subscription;
            
            // Trigger subscription event
            triggerEvent('subscribed', { subscription });
            
            return subscription;
            
        } catch (error) {
            console.error('Failed to subscribe to push notifications:', error);
            throw error;
        }
    }
    
    /**
     * Unsubscribe from push notifications
     */
    async function unsubscribe() {
        try {
            if (!currentSubscription) {
                console.log('Not subscribed to push notifications');
                return true;
            }
            
            // Remove subscription from server
            await removeSubscriptionFromServer(currentSubscription);
            
            // Unsubscribe from push manager
            const success = await currentSubscription.unsubscribe();
            
            if (success) {
                currentSubscription = null;
                console.log('Unsubscribed from push notifications');
                
                // Trigger unsubscription event
                triggerEvent('unsubscribed');
                
                return true;
            } else {
                throw new Error('Failed to unsubscribe');
            }
            
        } catch (error) {
            console.error('Failed to unsubscribe from push notifications:', error);
            throw error;
        }
    }
    
    /**
     * Send subscription to server
     */
    async function sendSubscriptionToServer(subscription) {
        try {
            const subscriptionData = {
                endpoint: subscription.endpoint,
                p256dh_key: arrayBufferToBase64(subscription.getKey('p256dh')),
                auth_key: arrayBufferToBase64(subscription.getKey('auth'))
            };
            
            const response = await window.ApiModule.post(`${config.apiBaseUrl}/push/subscribe`, subscriptionData);
            
            if (response.success) {
                console.log('Subscription registered with server');
                return true;
            } else {
                console.error('Failed to register subscription:', response.message);
                return false;
            }
            
        } catch (error) {
            console.error('Failed to send subscription to server:', error);
            return false;
        }
    }
    
    /**
     * Remove subscription from server
     */
    async function removeSubscriptionFromServer(subscription) {
        try {
            const response = await window.ApiModule.delete(`${config.apiBaseUrl}/push/subscribe`, {
                endpoint: subscription.endpoint
            });
            
            if (response.success) {
                console.log('Subscription removed from server');
            } else {
                console.error('Failed to remove subscription:', response.message);
            }
            
        } catch (error) {
            console.error('Failed to remove subscription from server:', error);
        }
    }
    
    /**
     * Show local notification (fallback for when push is not available)
     */
    async function showLocalNotification(title, options = {}) {
        try {
            const hasPermission = await requestPermission();
            if (!hasPermission) {
                return false;
            }
            
            const notificationOptions = {
                body: options.body || '',
                icon: options.icon || config.iconUrl,
                badge: options.badge || config.badgeUrl,
                tag: options.tag || 'local-notification',
                data: options.data || {},
                requireInteraction: options.requireInteraction || false,
                silent: options.silent || false,
                vibrate: options.vibrate || [200, 100, 200],
                timestamp: options.timestamp || Date.now(),
                ...options
            };
            
            const notification = new Notification(title, notificationOptions);
            
            // Handle notification click
            notification.onclick = function(event) {
                event.preventDefault();
                
                if (options.onClick) {
                    options.onClick(event);
                }
                
                // Focus window
                window.focus();
                notification.close();
            };
            
            // Auto close after delay
            if (options.autoClose) {
                setTimeout(() => {
                    notification.close();
                }, options.autoClose);
            }
            
            return notification;
            
        } catch (error) {
            console.error('Failed to show local notification:', error);
            return false;
        }
    }
    
    /**
     * Test push notification
     */
    async function testNotification() {
        try {
            if (!currentSubscription) {
                await subscribe();
            }
            
            const response = await window.ApiModule.post(`${config.apiBaseUrl}/push/test`);
            
            if (response.success) {
                console.log('Test notification sent');
                return true;
            } else {
                throw new Error(response.message || 'Failed to send test notification');
            }
            
        } catch (error) {
            console.error('Failed to send test notification:', error);
            
            // Fallback to local notification
            return await showLocalNotification('Test Notification', {
                body: 'This is a test push notification from Bazar.',
                tag: 'test-notification',
                autoClose: 5000
            });
        }
    }
    
    /**
     * Update badge count
     */
    async function updateBadgeCount(count) {
        if (serviceWorkerRegistration) {
            serviceWorkerRegistration.active.postMessage({
                type: 'update_badge',
                count: count
            });
        }
        
        // Also update app badge if supported
        if ('setAppBadge' in navigator) {
            try {
                if (count > 0) {
                    await navigator.setAppBadge(count);
                } else {
                    await navigator.clearAppBadge();
                }
            } catch (error) {
                console.error('Failed to update app badge:', error);
            }
        }
    }
    
    /**
     * Clear notifications by tag
     */
    function clearNotifications(tag = null) {
        if (serviceWorkerRegistration) {
            serviceWorkerRegistration.active.postMessage({
                type: 'clear_notifications',
                tag: tag
            });
        }
    }
    
    /**
     * Get subscription status
     */
    function getStatus() {
        return {
            supported: isSupported,
            permission: permissionStatus,
            subscribed: currentSubscription !== null,
            subscription: currentSubscription
        };
    }
    
    /**
     * Setup event listeners
     */
    function setupEventListeners() {
        // Listen for messages from service worker
        navigator.serviceWorker.addEventListener('message', event => {
            const data = event.data;
            
            switch (data.type) {
                case 'navigate_to_conversation':
                    if (window.MessagesModule) {
                        // Find and select conversation
                        const conversationId = data.conversation_id;
                        // Implementation depends on how conversations are loaded
                        console.log('Navigate to conversation:', conversationId);
                    }
                    break;
            }
        });
        
        // Listen for permission changes
        if ('permissions' in navigator) {
            navigator.permissions.query({ name: 'notifications' })
                .then(permissionStatus => {
                    permissionStatus.addEventListener('change', () => {
                        const newStatus = permissionStatus.state;
                        console.log('Notification permission changed:', newStatus);
                        
                        permissionStatus = newStatus;
                        triggerEvent('permissionchange', { permission: newStatus });
                        
                        // If permission was revoked, unsubscribe
                        if (newStatus === 'denied' && currentSubscription) {
                            unsubscribe().catch(console.error);
                        }
                    });
                })
                .catch(console.error);
        }
        
        // Handle visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                // Clear notifications when app becomes visible
                clearNotifications('message-notification');
            }
        });
    }
    
    /**
     * Utility functions
     */
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        
        return outputArray;
    }
    
    function arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        
        bytes.forEach(byte => {
            binary += String.fromCharCode(byte);
        });
        
        return window.btoa(binary);
    }
    
    // Event system
    const eventListeners = {};
    
    function addEventListener(event, callback) {
        if (!eventListeners[event]) {
            eventListeners[event] = [];
        }
        eventListeners[event].push(callback);
    }
    
    function removeEventListener(event, callback) {
        if (!eventListeners[event]) return;
        
        const index = eventListeners[event].indexOf(callback);
        if (index !== -1) {
            eventListeners[event].splice(index, 1);
        }
    }
    
    function triggerEvent(event, data) {
        if (!eventListeners[event]) return;
        
        eventListeners[event].forEach(callback => {
            try {
                callback(data);
            } catch (error) {
                console.error('Error in event listener:', error);
            }
        });
    }
    
    /**
     * Create notification settings UI
     */
    function createSettingsUI() {
        const container = document.createElement('div');
        container.className = 'push-notification-settings';
        container.innerHTML = `
            <div class="setting-item">
                <label class="setting-label">
                    <input type="checkbox" id="push-notifications-toggle" ${currentSubscription ? 'checked' : ''}>
                    <span class="setting-text">Enable push notifications</span>
                    <span class="setting-description">Get notified about new messages even when the app is closed</span>
                </label>
            </div>
            <div class="setting-item">
                <button id="test-notification-btn" class="btn btn-secondary" ${!currentSubscription ? 'disabled' : ''}>
                    Test Notification
                </button>
            </div>
            <div class="setting-status">
                <span class="status-text">Status: ${getStatusText()}</span>
            </div>
        `;
        
        // Add event listeners
        const toggle = container.querySelector('#push-notifications-toggle');
        const testBtn = container.querySelector('#test-notification-btn');
        
        toggle.addEventListener('change', async (e) => {
            try {
                if (e.target.checked) {
                    await subscribe();
                    testBtn.disabled = false;
                } else {
                    await unsubscribe();
                    testBtn.disabled = true;
                }
                updateStatusText(container);
            } catch (error) {
                e.target.checked = !e.target.checked; // Revert toggle
                if (window.ToastModule) {
                    window.ToastModule.showError('Failed to update notification settings');
                }
            }
        });
        
        testBtn.addEventListener('click', async () => {
            try {
                await testNotification();
                if (window.ToastModule) {
                    window.ToastModule.showSuccess('Test notification sent');
                }
            } catch (error) {
                if (window.ToastModule) {
                    window.ToastModule.showError('Failed to send test notification');
                }
            }
        });
        
        return container;
    }
    
    function getStatusText() {
        if (!isSupported) return 'Not supported';
        if (permissionStatus === 'denied') return 'Blocked';
        if (currentSubscription) return 'Enabled';
        return 'Disabled';
    }
    
    function updateStatusText(container) {
        const statusText = container.querySelector('.status-text');
        if (statusText) {
            statusText.textContent = `Status: ${getStatusText()}`;
        }
    }
    
    // Export public methods
    return {
        init,
        subscribe,
        unsubscribe,
        requestPermission,
        showLocalNotification,
        testNotification,
        updateBadgeCount,
        clearNotifications,
        getStatus,
        addEventListener,
        removeEventListener,
        createSettingsUI,
        
        // Properties
        get isSupported() { return isSupported; },
        get permission() { return permissionStatus; },
        get isSubscribed() { return currentSubscription !== null; }
    };
})();