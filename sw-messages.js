/**
 * Service Worker for Push Notifications
 * Handles push notifications and background sync for messages
 */

const CACHE_NAME = 'bazar-messages-v1';
const urlsToCache = [
    '/bazar/frontend/assets/css/messages.css',
    '/bazar/frontend/js/modules/messages.js',
    '/bazar/frontend/js/modules/websocket.js',
    '/bazar/frontend/assets/images/placeholder.jpg',
    '/bazar/frontend/assets/icons/message-icon.png'
];

// Install event - cache resources
self.addEventListener('install', event => {
    console.log('Service Worker installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Opened cache');
                return cache.addAll(urlsToCache);
            })
            .then(() => {
                console.log('Service Worker installed successfully');
                return self.skipWaiting();
            })
            .catch(error => {
                console.error('Service Worker installation failed:', error);
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
    console.log('Service Worker activating...');
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME && cacheName.startsWith('bazar-messages-')) {
                        console.log('Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            console.log('Service Worker activated successfully');
            return self.clients.claim();
        })
    );
});

// Push event - handle incoming push notifications
self.addEventListener('push', event => {
    console.log('Push event received:', event);
    
    if (!event.data) {
        console.warn('Push event has no data');
        return;
    }
    
    let data;
    try {
        data = event.data.json();
    } catch (error) {
        console.error('Failed to parse push data:', error);
        return;
    }
    
    const options = {
        body: data.body || 'You have a new message',
        icon: data.icon || '/bazar/frontend/assets/icons/message-icon.png',
        badge: data.badge || '/bazar/frontend/assets/icons/badge.png',
        tag: data.tag || 'message-notification',
        data: data.data || {},
        actions: data.actions || [
            {
                action: 'view',
                title: 'View Message',
                icon: '/bazar/frontend/assets/icons/view.png'
            },
            {
                action: 'reply',
                title: 'Quick Reply',
                icon: '/bazar/frontend/assets/icons/reply.png'
            }
        ],
        requireInteraction: data.requireInteraction || false,
        silent: data.silent || false,
        vibrate: data.vibrate || [200, 100, 200],
        timestamp: data.timestamp || Date.now()
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title || 'New Message', options)
            .then(() => {
                console.log('Push notification displayed successfully');
                
                // Track notification
                trackNotificationEvent('displayed', data);
                
                // Badge update
                updateBadgeCount(data.badge_count);
            })
            .catch(error => {
                console.error('Failed to show notification:', error);
            })
    );
});

// Notification click event
self.addEventListener('notificationclick', event => {
    console.log('Notification clicked:', event);
    
    const notification = event.notification;
    const action = event.action;
    const data = notification.data || {};
    
    notification.close();
    
    event.waitUntil(
        handleNotificationClick(action, data)
            .then(() => {
                // Track notification interaction
                trackNotificationEvent('clicked', { action, data });
            })
            .catch(error => {
                console.error('Failed to handle notification click:', error);
            })
    );
});

// Notification close event
self.addEventListener('notificationclose', event => {
    console.log('Notification closed:', event);
    
    const notification = event.notification;
    const data = notification.data || {};
    
    // Track notification dismissal
    trackNotificationEvent('dismissed', data);
});

/**
 * Handle notification click actions
 */
async function handleNotificationClick(action, data) {
    const conversationId = data.conversation_id;
    const messageId = data.message_id;
    
    switch (action) {
        case 'view':
            return openConversation(conversationId);
            
        case 'reply':
            return openQuickReply(conversationId, messageId);
            
        default:
            // Default action (click on notification body)
            return openConversation(conversationId);
    }
}

/**
 * Open conversation in main window
 */
async function openConversation(conversationId) {
    const url = `/bazar/frontend/pages/messages.html?conversation=${conversationId}`;
    
    // Try to focus existing window first
    const clients = await self.clients.matchAll({ 
        type: 'window',
        includeUncontrolled: true 
    });
    
    for (const client of clients) {
        if (client.url.includes('/messages.html')) {
            // Found messages page, focus and navigate
            client.focus();
            client.postMessage({
                type: 'navigate_to_conversation',
                conversation_id: conversationId
            });
            return;
        }
    }
    
    // No existing window found, open new one
    return self.clients.openWindow(url);
}

/**
 * Open quick reply interface
 */
async function openQuickReply(conversationId, messageId) {
    // For now, just open the conversation
    // In a full implementation, this could show a quick reply popup
    return openConversation(conversationId);
}

/**
 * Update badge count
 */
async function updateBadgeCount(count) {
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
 * Track notification events for analytics
 */
function trackNotificationEvent(event, data) {
    // Send analytics data to backend
    const trackingData = {
        event: event,
        timestamp: Date.now(),
        data: data,
        user_agent: navigator.userAgent
    };
    
    // Use background sync if available, otherwise fetch directly
    if ('serviceWorker' in navigator && 'sync' in window.ServiceWorkerRegistration.prototype) {
        // Store data for background sync
        storeForBackgroundSync('notification-tracking', trackingData);
    } else {
        // Direct API call
        fetch('/bazar/backend/api/v1/analytics/notification-events', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(trackingData)
        }).catch(error => {
            console.error('Failed to track notification event:', error);
        });
    }
}

/**
 * Store data for background sync
 */
function storeForBackgroundSync(key, data) {
    if (!('indexedDB' in self)) {
        return;
    }
    
    const request = indexedDB.open('bazar-sync-store', 1);
    
    request.onerror = () => {
        console.error('Failed to open IndexedDB');
    };
    
    request.onupgradeneeded = (event) => {
        const db = event.target.result;
        if (!db.objectStoreNames.contains(key)) {
            db.createObjectStore(key, { keyPath: 'id', autoIncrement: true });
        }
    };
    
    request.onsuccess = (event) => {
        const db = event.target.result;
        const transaction = db.transaction([key], 'readwrite');
        const store = transaction.objectStore(key);
        
        store.add({
            data: data,
            timestamp: Date.now()
        });
    };
}

// Background sync event
self.addEventListener('sync', event => {
    console.log('Background sync event:', event.tag);
    
    if (event.tag === 'notification-tracking') {
        event.waitUntil(syncNotificationTracking());
    }
});

/**
 * Sync notification tracking data
 */
async function syncNotificationTracking() {
    if (!('indexedDB' in self)) {
        return;
    }
    
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('bazar-sync-store', 1);
        
        request.onerror = () => reject(new Error('Failed to open IndexedDB'));
        
        request.onsuccess = async (event) => {
            const db = event.target.result;
            const transaction = db.transaction(['notification-tracking'], 'readwrite');
            const store = transaction.objectStore('notification-tracking');
            
            const getAllRequest = store.getAll();
            
            getAllRequest.onsuccess = async () => {
                const records = getAllRequest.result;
                
                for (const record of records) {
                    try {
                        await fetch('/bazar/backend/api/v1/analytics/notification-events', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(record.data)
                        });
                        
                        // Remove synced record
                        store.delete(record.id);
                        
                    } catch (error) {
                        console.error('Failed to sync tracking data:', error);
                        // Keep record for next sync attempt
                    }
                }
                
                resolve();
            };
            
            getAllRequest.onerror = () => {
                reject(new Error('Failed to get tracking records'));
            };
        };
    });
}

// Message event - handle messages from main thread
self.addEventListener('message', event => {
    console.log('Service Worker received message:', event.data);
    
    const data = event.data;
    
    switch (data.type) {
        case 'skip_waiting':
            self.skipWaiting();
            break;
            
        case 'get_version':
            event.ports[0].postMessage({ version: CACHE_NAME });
            break;
            
        case 'clear_notifications':
            clearNotifications(data.tag);
            break;
            
        case 'update_badge':
            updateBadgeCount(data.count);
            break;
    }
});

/**
 * Clear notifications by tag
 */
async function clearNotifications(tag) {
    try {
        const notifications = await self.registration.getNotifications({ tag });
        notifications.forEach(notification => notification.close());
        console.log(`Cleared ${notifications.length} notifications with tag: ${tag}`);
    } catch (error) {
        console.error('Failed to clear notifications:', error);
    }
}

// Fetch event - serve cached resources when offline
self.addEventListener('fetch', event => {
    // Only handle requests for our domain
    if (!event.request.url.startsWith(self.location.origin)) {
        return;
    }
    
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }
    
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // Return cached version or fetch from network
                return response || fetch(event.request);
            })
            .catch(error => {
                console.error('Fetch failed:', error);
                
                // Return offline page for navigation requests
                if (event.request.mode === 'navigate') {
                    return caches.match('/bazar/offline.html');
                }
                
                throw error;
            })
    );
});

// Error handling
self.addEventListener('error', event => {
    console.error('Service Worker error:', event.error);
});

self.addEventListener('unhandledrejection', event => {
    console.error('Service Worker unhandled rejection:', event.reason);
    event.preventDefault();
});

console.log('Service Worker script loaded successfully');