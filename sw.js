// Bazar PWA Service Worker
const CACHE_NAME = 'bazar-v1.0.0';
const OFFLINE_URL = '/offline.html';
const API_CACHE_NAME = 'bazar-api-v1.0.0';

// Assets to cache immediately
const STATIC_CACHE_URLS = [
    '/',
    '/index.html',
    '/offline.html',
    '/manifest.json',
    '/frontend/assets/css/main.css',
    '/frontend/assets/css/mobile.css',
    '/frontend/js/app.js',
    '/frontend/js/utils/helpers.js',
    '/frontend/js/modules/router.js',
    '/frontend/js/modules/api.js',
    '/frontend/js/modules/auth.js',
    '/frontend/js/modules/search.js',
    '/frontend/js/modules/ui.js',
    'https://cdn.jsdelivr.net/npm/fomantic-ui@2.9.3/dist/semantic.min.css',
    'https://cdn.jsdelivr.net/npm/fomantic-ui@2.9.3/dist/semantic.min.js',
    'https://code.jquery.com/jquery-3.6.0.min.js'
];

// API endpoints to cache
const API_CACHE_URLS = [
    '/api/v1/categories',
    '/api/v1/articles/featured'
];

// Install event - cache static assets
self.addEventListener('install', event => {
    console.log('Service Worker: Installing...');
    
    event.waitUntil(
        Promise.all([
            // Cache static assets
            caches.open(CACHE_NAME)
                .then(cache => {
                    console.log('Service Worker: Caching static assets');
                    return cache.addAll(STATIC_CACHE_URLS.map(url => new Request(url, {
                        cache: 'reload'
                    })));
                })
                .catch(err => console.log('Service Worker: Cache failed', err)),
            
            // Skip waiting to activate immediately
            self.skipWaiting()
        ])
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
    console.log('Service Worker: Activating...');
    
    event.waitUntil(
        Promise.all([
            // Clean up old caches
            caches.keys().then(cacheNames => {
                return Promise.all(
                    cacheNames.map(cacheName => {
                        if (cacheName !== CACHE_NAME && cacheName !== API_CACHE_NAME) {
                            console.log('Service Worker: Deleting old cache', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            }),
            
            // Take control of all clients
            self.clients.claim()
        ])
    );
});

// Fetch event - implement caching strategies
self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    
    // Skip non-HTTP requests
    if (!request.url.startsWith('http')) {
        return;
    }
    
    // Handle different types of requests
    if (url.pathname.startsWith('/api/')) {
        // API requests - Network First with cache fallback
        event.respondWith(networkFirstStrategy(request));
    } else if (request.destination === 'image') {
        // Images - Cache First with network fallback
        event.respondWith(cacheFirstStrategy(request));
    } else if (request.mode === 'navigate') {
        // Navigation requests - Network First with offline fallback
        event.respondWith(navigationStrategy(request));
    } else {
        // Static assets - Stale While Revalidate
        event.respondWith(staleWhileRevalidateStrategy(request));
    }
});

// Network First Strategy (for API calls)
async function networkFirstStrategy(request) {
    try {
        // Try network first
        const networkResponse = await fetch(request);
        
        // If successful, cache the response
        if (networkResponse && networkResponse.status === 200) {
            const cache = await caches.open(API_CACHE_NAME);
            cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
    } catch (error) {
        console.log('Service Worker: Network failed, trying cache', error);
        
        // Network failed, try cache
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        
        // Return error response
        return new Response(JSON.stringify({
            error: 'Offline',
            message: 'No network connection and no cached data available'
        }), {
            status: 503,
            statusText: 'Service Unavailable',
            headers: {
                'Content-Type': 'application/json'
            }
        });
    }
}

// Cache First Strategy (for images)
async function cacheFirstStrategy(request) {
    try {
        // Try cache first
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        
        // Cache miss, try network
        const networkResponse = await fetch(request);
        
        // Cache the response for next time
        if (networkResponse && networkResponse.status === 200) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
    } catch (error) {
        console.log('Service Worker: Image fetch failed', error);
        
        // Return placeholder image or error
        return new Response('', {
            status: 404,
            statusText: 'Image not found'
        });
    }
}

// Navigation Strategy (for page requests)
async function navigationStrategy(request) {
    try {
        // Try network first
        const networkResponse = await fetch(request);
        
        // If successful, cache it
        if (networkResponse && networkResponse.status === 200) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
    } catch (error) {
        console.log('Service Worker: Navigation failed, serving offline page', error);
        
        // Try cached version first
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        
        // Return offline page
        return caches.match(OFFLINE_URL);
    }
}

// Stale While Revalidate Strategy (for static assets)
async function staleWhileRevalidateStrategy(request) {
    const cache = await caches.open(CACHE_NAME);
    const cachedResponse = await cache.match(request);
    
    // Fetch from network and update cache in background
    const networkResponsePromise = fetch(request).then(networkResponse => {
        if (networkResponse && networkResponse.status === 200) {
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    }).catch(error => {
        console.log('Service Worker: Network update failed', error);
    });
    
    // Return cached version immediately, or wait for network if no cache
    return cachedResponse || networkResponsePromise;
}

// Background sync for offline actions
self.addEventListener('sync', event => {
    console.log('Service Worker: Background sync', event.tag);
    
    if (event.tag === 'background-sync') {
        event.waitUntil(doBackgroundSync());
    }
});

async function doBackgroundSync() {
    // Handle queued offline actions
    try {
        // Get queued actions from IndexedDB or localStorage
        const queuedActions = await getQueuedActions();
        
        for (const action of queuedActions) {
            try {
                await fetch(action.url, action.options);
                // Remove from queue on success
                await removeQueuedAction(action.id);
            } catch (error) {
                console.log('Service Worker: Background sync action failed', error);
            }
        }
    } catch (error) {
        console.log('Service Worker: Background sync failed', error);
    }
}

// Push notifications
self.addEventListener('push', event => {
    console.log('Service Worker: Push received', event);
    
    const options = {
        body: event.data ? event.data.text() : 'Neue Nachricht von Bazar',
        icon: '/frontend/assets/icons/icon-192x192.png',
        badge: '/frontend/assets/icons/badge-72x72.png',
        vibrate: [100, 50, 100],
        data: {
            dateOfArrival: Date.now(),
            primaryKey: '2'
        },
        actions: [
            {
                action: 'explore',
                title: 'Anzeigen',
                icon: '/frontend/assets/icons/checkmark.png'
            },
            {
                action: 'close',
                title: 'Schließen',
                icon: '/frontend/assets/icons/xmark.png'
            }
        ]
    };
    
    event.waitUntil(
        self.registration.showNotification('Bazar', options)
    );
});

// Notification click handler
self.addEventListener('notificationclick', event => {
    console.log('Service Worker: Notification click received.');
    
    event.notification.close();
    
    if (event.action === 'explore') {
        // Open the app
        event.waitUntil(clients.openWindow('/'));
    } else if (event.action === 'close') {
        // Just close the notification
        return;
    } else {
        // Default action - open app
        event.waitUntil(clients.openWindow('/'));
    }
});

// Utility functions for queue management
async function getQueuedActions() {
    // Implementation would use IndexedDB or similar
    // For now, return empty array
    return [];
}

async function removeQueuedAction(actionId) {
    // Implementation would remove from IndexedDB
    // For now, just log
    console.log('Service Worker: Removing queued action', actionId);
}

// Error handling
self.addEventListener('error', event => {
    console.error('Service Worker: Error occurred', event.error);
});

self.addEventListener('unhandledrejection', event => {
    console.error('Service Worker: Unhandled promise rejection', event.reason);
});

// Update notification
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

console.log('Service Worker: Loaded successfully');