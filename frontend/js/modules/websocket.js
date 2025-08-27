/**
 * WebSocket Module
 * Handles real-time communication using WebSockets with fallback to Server-Sent Events
 */

window.WebSocketModule = (function() {
    'use strict';
    
    // Private variables
    let connection = null;
    let connectionType = null;
    let isConnected = false;
    let reconnectAttempts = 0;
    let reconnectTimer = null;
    let heartbeatTimer = null;
    let messageQueue = [];
    let eventListeners = {};
    
    // Configuration
    const config = {
        websocketUrl: 'ws://localhost:8080/websocket',
        sseUrl: '/bazar/backend/api/v1/messages/stream',
        maxReconnectAttempts: 10,
        reconnectDelay: 3000,
        heartbeatInterval: 30000,
        connectionTimeout: 10000
    };
    
    /**
     * Connect to real-time messaging
     */
    function connect() {
        if (isConnected) {
            return Promise.resolve();
        }
        
        return new Promise((resolve, reject) => {
            // Try WebSocket first, fallback to SSE
            connectWebSocket()
                .then(() => {
                    connectionType = 'websocket';
                    resolve();
                })
                .catch(() => {
                    console.log('WebSocket failed, trying Server-Sent Events...');
                    connectSSE()
                        .then(() => {
                            connectionType = 'sse';
                            resolve();
                        })
                        .catch(reject);
                });
        });
    }
    
    /**
     * Connect via WebSocket
     */
    function connectWebSocket() {
        return new Promise((resolve, reject) => {
            try {
                const token = window.AuthModule?.getToken();
                if (!token) {
                    reject(new Error('Authentication required'));
                    return;
                }
                
                const wsUrl = `${config.websocketUrl}?token=${encodeURIComponent(token)}`;
                connection = new WebSocket(wsUrl);
                
                const timeout = setTimeout(() => {
                    connection.close();
                    reject(new Error('WebSocket connection timeout'));
                }, config.connectionTimeout);
                
                connection.onopen = (event) => {
                    clearTimeout(timeout);
                    isConnected = true;
                    reconnectAttempts = 0;
                    
                    console.log('WebSocket connected');
                    triggerEvent('connect', event);
                    
                    // Start heartbeat
                    startHeartbeat();
                    
                    // Process queued messages
                    processMessageQueue();
                    
                    resolve();
                };
                
                connection.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        handleMessage(data);
                    } catch (error) {
                        console.error('Failed to parse WebSocket message:', error);
                    }
                };
                
                connection.onclose = (event) => {
                    clearTimeout(timeout);
                    isConnected = false;
                    stopHeartbeat();
                    
                    console.log('WebSocket connection closed:', event.code, event.reason);
                    triggerEvent('disconnect', event);
                    
                    // Attempt to reconnect unless explicitly closed
                    if (event.code !== 1000 && reconnectAttempts < config.maxReconnectAttempts) {
                        scheduleReconnect();
                    }
                };
                
                connection.onerror = (event) => {
                    clearTimeout(timeout);
                    console.error('WebSocket error:', event);
                    triggerEvent('error', event);
                    reject(new Error('WebSocket connection failed'));
                };
                
            } catch (error) {
                reject(error);
            }
        });
    }
    
    /**
     * Connect via Server-Sent Events
     */
    function connectSSE() {
        return new Promise((resolve, reject) => {
            try {
                const token = window.AuthModule?.getToken();
                if (!token) {
                    reject(new Error('Authentication required'));
                    return;
                }
                
                connection = new EventSource(`${config.sseUrl}?token=${encodeURIComponent(token)}`);
                
                const timeout = setTimeout(() => {
                    connection.close();
                    reject(new Error('SSE connection timeout'));
                }, config.connectionTimeout);
                
                connection.onopen = (event) => {
                    clearTimeout(timeout);
                    isConnected = true;
                    reconnectAttempts = 0;
                    
                    console.log('SSE connected');
                    triggerEvent('connect', event);
                    
                    resolve();
                };
                
                connection.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        
                        // Handle ping messages
                        if (data.type === 'ping') {
                            return;
                        }
                        
                        handleMessage(data);
                    } catch (error) {
                        console.error('Failed to parse SSE message:', error);
                    }
                };
                
                connection.onerror = (event) => {
                    clearTimeout(timeout);
                    console.error('SSE error:', event);
                    triggerEvent('error', event);
                    
                    isConnected = false;
                    
                    // Attempt to reconnect
                    if (reconnectAttempts < config.maxReconnectAttempts) {
                        scheduleReconnect();
                    } else {
                        reject(new Error('SSE connection failed'));
                    }
                };
                
            } catch (error) {
                reject(error);
            }
        });
    }
    
    /**
     * Schedule reconnection attempt
     */
    function scheduleReconnect() {
        if (reconnectTimer) {
            clearTimeout(reconnectTimer);
        }
        
        reconnectAttempts++;
        const delay = config.reconnectDelay * Math.pow(2, Math.min(reconnectAttempts - 1, 5)); // Exponential backoff
        
        console.log(`Attempting to reconnect in ${delay}ms (attempt ${reconnectAttempts}/${config.maxReconnectAttempts})`);
        
        reconnectTimer = setTimeout(() => {
            if (!isConnected) {
                connect().catch(error => {
                    console.error('Reconnection failed:', error);
                    
                    if (reconnectAttempts >= config.maxReconnectAttempts) {
                        triggerEvent('maxReconnectAttemptsReached');
                    }
                });
            }
        }, delay);
    }
    
    /**
     * Start heartbeat for WebSocket connections
     */
    function startHeartbeat() {
        if (connectionType !== 'websocket') {
            return;
        }
        
        heartbeatTimer = setInterval(() => {
            if (isConnected && connection.readyState === WebSocket.OPEN) {
                send({ type: 'ping', timestamp: Date.now() });
            }
        }, config.heartbeatInterval);
    }
    
    /**
     * Stop heartbeat
     */
    function stopHeartbeat() {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }
    
    /**
     * Send message through connection
     */
    function send(data) {
        if (!isConnected || !connection) {
            // Queue message for later sending
            messageQueue.push(data);
            return false;
        }
        
        try {
            if (connectionType === 'websocket') {
                connection.send(JSON.stringify(data));
            } else {
                // SSE is read-only, messages are sent via HTTP API
                console.warn('Cannot send message through SSE, use HTTP API instead');
                return false;
            }
            
            return true;
        } catch (error) {
            console.error('Failed to send message:', error);
            messageQueue.push(data);
            return false;
        }
    }
    
    /**
     * Process queued messages
     */
    function processMessageQueue() {
        while (messageQueue.length > 0 && isConnected) {
            const message = messageQueue.shift();
            send(message);
        }
    }
    
    /**
     * Handle incoming message
     */
    function handleMessage(data) {
        // Route message to appropriate handler
        switch (data.type) {
            case 'new_message':
                triggerEvent('message', data);
                triggerEvent('newMessage', data);
                break;
                
            case 'message_update':
                triggerEvent('message', data);
                triggerEvent('messageUpdate', data);
                break;
                
            case 'typing_status':
                triggerEvent('message', data);
                triggerEvent('typingStatus', data);
                break;
                
            case 'read_receipt':
                triggerEvent('message', data);
                triggerEvent('readReceipt', data);
                break;
                
            case 'user_status':
                triggerEvent('message', data);
                triggerEvent('userStatus', data);
                break;
                
            case 'reaction_update':
                triggerEvent('message', data);
                triggerEvent('reactionUpdate', data);
                break;
                
            case 'notification':
                triggerEvent('notification', data);
                break;
                
            case 'pong':
                // Heartbeat response
                console.debug('Received pong');
                break;
                
            default:
                console.warn('Unknown message type:', data.type);
                triggerEvent('message', data);
        }
    }
    
    /**
     * Disconnect from real-time messaging
     */
    function disconnect() {
        if (reconnectTimer) {
            clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
        
        stopHeartbeat();
        
        if (connection) {
            if (connectionType === 'websocket') {
                connection.close(1000, 'Client disconnect');
            } else if (connectionType === 'sse') {
                connection.close();
            }
        }
        
        connection = null;
        connectionType = null;
        isConnected = false;
        reconnectAttempts = 0;
        
        console.log('Disconnected from real-time messaging');
    }
    
    /**
     * Add event listener
     */
    function addEventListener(event, callback) {
        if (!eventListeners[event]) {
            eventListeners[event] = [];
        }
        eventListeners[event].push(callback);
    }
    
    /**
     * Remove event listener
     */
    function removeEventListener(event, callback) {
        if (!eventListeners[event]) {
            return;
        }
        
        const index = eventListeners[event].indexOf(callback);
        if (index !== -1) {
            eventListeners[event].splice(index, 1);
        }
    }
    
    /**
     * Trigger event
     */
    function triggerEvent(event, data) {
        if (!eventListeners[event]) {
            return;
        }
        
        eventListeners[event].forEach(callback => {
            try {
                callback(data);
            } catch (error) {
                console.error('Error in event listener:', error);
            }
        });
    }
    
    /**
     * Subscribe to conversation updates
     */
    function subscribeToConversation(conversationId) {
        if (!isConnected) {
            console.warn('Cannot subscribe to conversation: not connected');
            return;
        }
        
        send({
            type: 'subscribe',
            channel: `conversation:${conversationId}`,
            timestamp: Date.now()
        });
        
        console.log(`Subscribed to conversation ${conversationId}`);
    }
    
    /**
     * Unsubscribe from conversation updates
     */
    function unsubscribeFromConversation(conversationId) {
        if (!isConnected) {
            return;
        }
        
        send({
            type: 'unsubscribe',
            channel: `conversation:${conversationId}`,
            timestamp: Date.now()
        });
        
        console.log(`Unsubscribed from conversation ${conversationId}`);
    }
    
    /**
     * Subscribe to user updates
     */
    function subscribeToUser(userId) {
        if (!isConnected) {
            console.warn('Cannot subscribe to user: not connected');
            return;
        }
        
        send({
            type: 'subscribe',
            channel: `user:${userId}`,
            timestamp: Date.now()
        });
        
        console.log(`Subscribed to user ${userId}`);
    }
    
    /**
     * Send typing status
     */
    function sendTypingStatus(conversationId, isTyping) {
        send({
            type: 'typing_status',
            conversation_id: conversationId,
            is_typing: isTyping,
            timestamp: Date.now()
        });
    }
    
    /**
     * Send read receipt
     */
    function sendReadReceipt(messageId, conversationId) {
        send({
            type: 'read_receipt',
            message_id: messageId,
            conversation_id: conversationId,
            timestamp: Date.now()
        });
    }
    
    /**
     * Get connection status
     */
    function getStatus() {
        return {
            connected: isConnected,
            connectionType: connectionType,
            reconnectAttempts: reconnectAttempts,
            queuedMessages: messageQueue.length
        };
    }
    
    /**
     * Initialize WebSocket module
     */
    function init() {
        // Handle page visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Page is hidden, can reduce activity
                console.debug('Page hidden, reducing WebSocket activity');
            } else {
                // Page is visible, ensure connection
                console.debug('Page visible, ensuring WebSocket connection');
                if (!isConnected) {
                    connect().catch(error => {
                        console.error('Failed to reconnect on page focus:', error);
                    });
                }
            }
        });
        
        // Handle network status changes
        window.addEventListener('online', () => {
            console.log('Network online, attempting to reconnect');
            if (!isConnected) {
                connect().catch(error => {
                    console.error('Failed to reconnect on network online:', error);
                });
            }
        });
        
        window.addEventListener('offline', () => {
            console.log('Network offline');
            if (isConnected) {
                disconnect();
            }
        });
        
        // Handle page unload
        window.addEventListener('beforeunload', () => {
            disconnect();
        });
        
        console.log('WebSocket module initialized');
    }
    
    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Export public methods
    return {
        connect,
        disconnect,
        send,
        addEventListener,
        removeEventListener,
        subscribeToConversation,
        unsubscribeFromConversation,
        subscribeToUser,
        sendTypingStatus,
        sendReadReceipt,
        getStatus,
        
        // Convenience properties for event handling
        get onConnect() { return null; },
        set onConnect(callback) { addEventListener('connect', callback); },
        
        get onDisconnect() { return null; },
        set onDisconnect(callback) { addEventListener('disconnect', callback); },
        
        get onMessage() { return null; },
        set onMessage(callback) { addEventListener('message', callback); },
        
        get onError() { return null; },
        set onError(callback) { addEventListener('error', callback); }
    };
})();