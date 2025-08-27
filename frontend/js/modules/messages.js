/**
 * Messages Module
 * Handles all messaging functionality including real-time chat, file uploads, and UI interactions
 */

window.MessagesModule = (function() {
    'use strict';
    
    // Private variables
    let currentConversationId = null;
    let currentUser = null;
    let conversations = [];
    let messages = [];
    let typingTimeout = null;
    let isTyping = false;
    let selectedFiles = [];
    let websocket = null;
    
    // DOM elements
    let elements = {};
    
    // Configuration
    const config = {
        apiBaseUrl: '/bazar/backend/api/v1',
        maxMessageLength: 2000,
        typingTimeout: 3000,
        messagePollingInterval: 30000,
        maxFileSize: 10 * 1024 * 1024, // 10MB
        maxFiles: 5,
        allowedFileTypes: [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain', 'application/zip'
        ]
    };
    
    /**
     * Initialize the messages module
     */
    function init() {
        if (!window.AuthModule || !window.AuthModule.isAuthenticated()) {
            window.location.href = '/login';
            return;
        }
        
        currentUser = window.AuthModule.getCurrentUser();
        initializeElements();
        attachEventListeners();
        loadConversations();
        initializeWebSocket();
        
        // Handle URL parameters for direct conversation access
        handleUrlParameters();
        
        // Set up periodic updates as fallback
        setInterval(refreshCurrentConversation, config.messagePollingInterval);
        
        console.log('Messages module initialized');
    }
    
    /**
     * Initialize DOM element references
     */
    function initializeElements() {
        elements = {
            // Sidebar elements
            conversationList: document.getElementById('conversation-list'),
            conversationSearch: document.getElementById('conversation-search'),
            
            // Chat area elements
            chatArea: document.getElementById('chat-area'),
            chatPlaceholder: document.querySelector('.chat-placeholder'),
            chatHeader: document.getElementById('chat-header'),
            messagesContainer: document.getElementById('messages-container'),
            messagesList: document.getElementById('messages-list'),
            messageInputArea: document.getElementById('message-input-area'),
            
            // Header elements
            participantName: document.querySelector('.participant-name'),
            participantAvatar: document.querySelector('.participant-avatar img'),
            articleTitle: document.querySelector('.article-title'),
            lastSeen: document.querySelector('.last-seen'),
            onlineStatus: document.querySelector('.online-status'),
            
            // Input elements
            messageInput: document.getElementById('message-input'),
            sendBtn: document.getElementById('send-btn'),
            attachmentBtn: document.getElementById('attachment-btn'),
            emojiBtn: document.getElementById('emoji-btn'),
            fileInput: document.getElementById('file-input'),
            attachmentPreview: document.getElementById('attachment-preview'),
            charCount: document.getElementById('char-count'),
            
            // Mobile elements
            mobileBackBtn: document.getElementById('mobile-back-btn'),
            mobileConversationsBtn: document.getElementById('mobile-conversations-btn'),
            messagesSidebar: document.getElementById('messages-sidebar'),
            
            // Other elements
            typingIndicator: document.getElementById('typing-indicator'),
            conversationMenuBtn: document.getElementById('conversation-menu-btn'),
            searchMessagesBtn: document.getElementById('search-messages-btn')
        };
    }
    
    /**
     * Attach event listeners
     */
    function attachEventListeners() {
        // Message input events
        elements.messageInput.addEventListener('input', handleMessageInput);
        elements.messageInput.addEventListener('keypress', handleKeyPress);
        elements.sendBtn.addEventListener('click', sendMessage);
        
        // File attachment events
        elements.attachmentBtn.addEventListener('click', () => elements.fileInput.click());
        elements.fileInput.addEventListener('change', handleFileSelection);
        
        // Search events
        elements.conversationSearch.addEventListener('input', debounce(handleConversationSearch, 300));
        
        // Mobile events
        elements.mobileBackBtn.addEventListener('click', hideChatOnMobile);
        elements.mobileConversationsBtn.addEventListener('click', showConversationsOnMobile);
        
        // Menu events
        elements.conversationMenuBtn.addEventListener('click', showConversationMenu);
        elements.searchMessagesBtn.addEventListener('click', showMessageSearch);
        
        // Window events
        window.addEventListener('resize', handleResize);
        window.addEventListener('beforeunload', handleBeforeUnload);
        window.addEventListener('focus', handleWindowFocus);
        window.addEventListener('blur', handleWindowBlur);
        
        // Conversation list scroll for infinite loading
        elements.conversationList.addEventListener('scroll', handleConversationListScroll);
        elements.messagesList.addEventListener('scroll', handleMessageListScroll);
        
        // Click outside to close mobile sidebar
        document.addEventListener('click', handleDocumentClick);
    }
    
    /**
     * Load conversations from API
     */
    async function loadConversations(page = 1, search = '') {
        try {
            showLoadingState();
            
            const params = new URLSearchParams({
                page: page,
                limit: 20
            });
            
            if (search) {
                params.append('search', search);
            }
            
            const response = await window.ApiModule.get(`${config.apiBaseUrl}/conversations?${params}`);
            
            if (response.success) {
                if (page === 1) {
                    conversations = response.data.conversations;
                    renderConversationList();
                } else {
                    conversations = [...conversations, ...response.data.conversations];
                    appendConversationList(response.data.conversations);
                }
                
                updateNotificationBadge(response.data.total_unread);
            }
        } catch (error) {
            console.error('Failed to load conversations:', error);
            showErrorMessage('Failed to load conversations');
        } finally {
            hideLoadingState();
        }
    }
    
    /**
     * Render conversation list
     */
    function renderConversationList() {
        if (conversations.length === 0) {
            elements.conversationList.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">💬</div>
                    <h3>No conversations yet</h3>
                    <p>Start messaging by contacting sellers about their items</p>
                </div>
            `;
            return;
        }
        
        const html = conversations.map(conversation => createConversationItem(conversation)).join('');
        elements.conversationList.innerHTML = html;
        
        // Add click listeners
        elements.conversationList.querySelectorAll('.conversation-item').forEach((item, index) => {
            item.addEventListener('click', () => selectConversation(conversations[index]));
        });
    }
    
    /**
     * Create conversation item HTML
     */
    function createConversationItem(conversation) {
        const isOnline = conversation.other_user_online || false;
        const unreadCount = conversation.unread_count || 0;
        const preview = formatMessagePreview(conversation.last_message_content, conversation.last_message_type);
        const time = formatTimeAgo(conversation.last_message_at);
        
        return `
            <div class="conversation-item ${currentConversationId === conversation.id ? 'active' : ''} ${unreadCount > 0 ? 'unread' : ''}" 
                 data-conversation-id="${conversation.id}">
                <div class="conversation-avatar">
                    <img src="${conversation.other_user_avatar || '../assets/images/placeholder.jpg'}" 
                         alt="${conversation.other_user_name}">
                    <div class="online-status ${isOnline ? 'online' : 'offline'}"></div>
                </div>
                <div class="conversation-info">
                    <div class="conversation-name">${escapeHtml(conversation.other_user_name)}</div>
                    <div class="conversation-preview ${unreadCount > 0 ? 'unread' : ''}">${preview}</div>
                    <div class="article-info">${escapeHtml(conversation.article_title)}</div>
                </div>
                <div class="conversation-meta">
                    <div class="conversation-time">${time}</div>
                    ${unreadCount > 0 ? `<div class="unread-badge">${unreadCount}</div>` : ''}
                </div>
            </div>
        `;
    }
    
    /**
     * Select and load a conversation
     */
    async function selectConversation(conversation) {
        try {
            currentConversationId = conversation.id;
            
            // Update active state in sidebar
            elements.conversationList.querySelectorAll('.conversation-item').forEach(item => {
                item.classList.remove('active');
            });
            
            const activeItem = elements.conversationList.querySelector(`[data-conversation-id="${conversation.id}"]`);
            if (activeItem) {
                activeItem.classList.add('active');
                activeItem.classList.remove('unread');
                const unreadBadge = activeItem.querySelector('.unread-badge');
                if (unreadBadge) {
                    unreadBadge.remove();
                }
            }
            
            // Show chat interface
            showChatInterface();
            updateChatHeader(conversation);
            
            // Load messages
            await loadMessages(conversation.id);
            
            // Mark as read
            markConversationAsRead(conversation.id);
            
            // Hide mobile sidebar if visible
            hideChatOnMobile();
            
            // Update URL without reload
            updateUrl(conversation.id);
            
        } catch (error) {
            console.error('Failed to select conversation:', error);
            showErrorMessage('Failed to load conversation');
        }
    }
    
    /**
     * Load messages for conversation
     */
    async function loadMessages(conversationId, page = 1, beforeMessageId = null) {
        try {
            const params = new URLSearchParams({
                page: page,
                limit: 50
            });
            
            if (beforeMessageId) {
                params.append('before', beforeMessageId);
            }
            
            const response = await window.ApiModule.get(`${config.apiBaseUrl}/conversations/${conversationId}?${params}`);
            
            if (response.success) {
                if (page === 1) {
                    messages = response.data.messages;
                    renderMessages();
                    scrollToBottom();
                } else {
                    messages = [...response.data.messages, ...messages];
                    prependMessages(response.data.messages);
                }
                
                return response.data;
            }
        } catch (error) {
            console.error('Failed to load messages:', error);
            showErrorMessage('Failed to load messages');
        }
    }
    
    /**
     * Render messages in the chat
     */
    function renderMessages() {
        if (messages.length === 0) {
            elements.messagesList.innerHTML = `
                <div class="empty-messages">
                    <div class="empty-icon">👋</div>
                    <p>No messages yet. Start the conversation!</p>
                </div>
            `;
            return;
        }
        
        const html = messages.map((message, index) => createMessageBubble(message, index)).join('');
        elements.messagesList.innerHTML = html;
        
        // Add event listeners for message interactions
        attachMessageEventListeners();
    }
    
    /**
     * Create message bubble HTML
     */
    function createMessageBubble(message, index) {
        const isOwn = message.sender_id === currentUser.id;
        const isContinuation = index > 0 && 
                              messages[index - 1].sender_id === message.sender_id &&
                              (new Date(message.created_at) - new Date(messages[index - 1].created_at)) < 300000; // 5 minutes
        
        const messageClass = `message ${isOwn ? 'sent' : 'received'} ${message.message_type}`;
        const showAvatar = !isOwn && !isContinuation;
        const time = formatMessageTime(message.created_at);
        
        let content = '';
        
        switch (message.message_type) {
            case 'text':
                content = formatMessageText(message.content);
                break;
            case 'image':
                content = createImageAttachment(message);
                break;
            case 'file':
                content = createFileAttachment(message);
                break;
            case 'offer':
                content = createOfferMessage(message);
                break;
            case 'system':
                return createSystemMessage(message);
        }
        
        return `
            <div class="${messageClass}" data-message-id="${message.id}">
                ${showAvatar ? `<img src="${message.sender_avatar || '../assets/images/placeholder.jpg'}" alt="${message.sender_username}" class="message-avatar">` : ''}
                <div class="message-content">
                    <div class="message-text">${content}</div>
                    ${message.reactions ? createMessageReactions(message.reactions) : ''}
                    <div class="message-time">
                        ${time}
                        ${isOwn ? createMessageStatus(message) : ''}
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Create system message HTML
     */
    function createSystemMessage(message) {
        return `
            <div class="message system" data-message-id="${message.id}">
                <div class="message-content">
                    <div class="message-text">${escapeHtml(message.content)}</div>
                </div>
            </div>
        `;
    }
    
    /**
     * Create image attachment HTML
     */
    function createImageAttachment(message) {
        if (!message.attachments || message.attachments.length === 0) {
            return escapeHtml(message.content);
        }
        
        const attachment = message.attachments[0];
        const imageUrl = `${config.apiBaseUrl.replace('/v1', '')}/uploads/messages/images/${attachment.filename}`;
        const thumbnailUrl = attachment.thumbnail_path ? 
            `${config.apiBaseUrl.replace('/v1', '')}/uploads/messages/thumbnails/${attachment.thumbnail_path}` : imageUrl;
        
        return `
            <div class="message-text">${escapeHtml(message.content)}</div>
            <div class="message-attachment">
                <img src="${thumbnailUrl}" alt="${attachment.original_filename}" 
                     class="attachment-image" 
                     data-full-url="${imageUrl}"
                     onclick="showImagePreview('${imageUrl}', '${attachment.original_filename}')">
            </div>
        `;
    }
    
    /**
     * Create file attachment HTML
     */
    function createFileAttachment(message) {
        if (!message.attachments || message.attachments.length === 0) {
            return escapeHtml(message.content);
        }
        
        const attachment = message.attachments[0];
        const fileUrl = `${config.apiBaseUrl}/attachments/${attachment.id}/download`;
        const fileExtension = attachment.original_filename.split('.').pop().toUpperCase();
        const fileSize = formatFileSize(attachment.file_size);
        
        return `
            <div class="message-text">${escapeHtml(message.content)}</div>
            <div class="message-attachment">
                <div class="attachment-file" onclick="downloadFile('${fileUrl}', '${attachment.original_filename}')">
                    <div class="attachment-icon">${fileExtension}</div>
                    <div class="attachment-info">
                        <div class="attachment-name">${escapeHtml(attachment.original_filename)}</div>
                        <div class="attachment-size">${fileSize}</div>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Create offer message HTML
     */
    function createOfferMessage(message) {
        const metadata = message.metadata ? JSON.parse(message.metadata) : {};
        const offerAmount = metadata.offer_amount || 0;
        const offerStatus = metadata.offer_status || 'pending';
        const isOwn = message.sender_id === currentUser.id;
        
        let actionsHtml = '';
        if (!isOwn && offerStatus === 'pending') {
            actionsHtml = `
                <div class="offer-actions">
                    <button class="offer-btn accept" onclick="respondToOffer('${message.id}', 'accept')">Accept</button>
                    <button class="offer-btn decline" onclick="respondToOffer('${message.id}', 'decline')">Decline</button>
                    <button class="offer-btn counter" onclick="showCounterOfferDialog('${message.id}')">Counter</button>
                </div>
            `;
        }
        
        return `
            <div class="offer-content">
                <span class="offer-amount">€${offerAmount}</span>
                <span>${escapeHtml(message.content)}</span>
            </div>
            ${actionsHtml}
        `;
    }
    
    /**
     * Create message status indicators
     */
    function createMessageStatus(message) {
        let statusIcon = '';
        
        if (message.is_read) {
            statusIcon = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z"/></svg>`;
        } else {
            statusIcon = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>`;
        }
        
        return `<span class="message-status">${statusIcon}</span>`;
    }
    
    /**
     * Send a message
     */
    async function sendMessage() {
        const content = elements.messageInput.value.trim();
        
        if (!content && selectedFiles.length === 0) {
            return;
        }
        
        if (!currentConversationId) {
            showErrorMessage('Please select a conversation first');
            return;
        }
        
        try {
            elements.sendBtn.disabled = true;
            
            if (selectedFiles.length > 0) {
                await sendMessageWithAttachments(content);
            } else {
                await sendTextMessage(content);
            }
            
            // Clear input
            elements.messageInput.value = '';
            clearSelectedFiles();
            updateSendButton();
            updateCharCount();
            
            // Stop typing indicator
            stopTyping();
            
        } catch (error) {
            console.error('Failed to send message:', error);
            showErrorMessage('Failed to send message');
        } finally {
            elements.sendBtn.disabled = false;
        }
    }
    
    /**
     * Send text message
     */
    async function sendTextMessage(content) {
        const response = await window.ApiModule.post(`${config.apiBaseUrl}/conversations/${currentConversationId}/messages`, {
            content: content,
            message_type: 'text'
        });
        
        if (response.success) {
            addMessageToChat(response.data.message);
            scrollToBottom();
        } else {
            throw new Error(response.message || 'Failed to send message');
        }
    }
    
    /**
     * Send message with attachments
     */
    async function sendMessageWithAttachments(content) {
        const formData = new FormData();
        formData.append('conversation_id', currentConversationId);
        
        if (content) {
            formData.append('message_content', content);
        }
        
        selectedFiles.forEach((file, index) => {
            formData.append(`file_${index}`, file);
        });
        
        const response = await window.ApiModule.postFormData(`${config.apiBaseUrl}/messages/attachments`, formData);
        
        if (response.success) {
            if (response.data.message) {
                // Single file
                addMessageToChat(response.data.message);
            } else if (response.data.results) {
                // Multiple files
                response.data.results.forEach(result => {
                    if (result.success && result.message) {
                        addMessageToChat(result.message);
                    }
                });
            }
            scrollToBottom();
        } else {
            throw new Error(response.message || 'Failed to send attachments');
        }
    }
    
    /**
     * Add message to chat interface
     */
    function addMessageToChat(message) {
        messages.push(message);
        const messageHtml = createMessageBubble(message, messages.length - 1);
        elements.messagesList.insertAdjacentHTML('beforeend', messageHtml);
        
        // Attach event listeners to new message
        const newMessageElement = elements.messagesList.lastElementChild;
        attachMessageEventListeners(newMessageElement);
    }
    
    /**
     * Handle file selection
     */
    function handleFileSelection(event) {
        const files = Array.from(event.target.files);
        
        // Validate files
        const validFiles = files.filter(file => validateFile(file));
        
        if (validFiles.length === 0) {
            return;
        }
        
        // Limit number of files
        const remainingSlots = config.maxFiles - selectedFiles.length;
        const filesToAdd = validFiles.slice(0, remainingSlots);
        
        selectedFiles = [...selectedFiles, ...filesToAdd];
        updateAttachmentPreview();
        updateSendButton();
        
        // Clear file input
        event.target.value = '';
    }
    
    /**
     * Validate file before upload
     */
    function validateFile(file) {
        if (file.size > config.maxFileSize) {
            showErrorMessage(`File "${file.name}" is too large. Maximum size is ${formatFileSize(config.maxFileSize)}.`);
            return false;
        }
        
        if (!config.allowedFileTypes.includes(file.type)) {
            showErrorMessage(`File type "${file.type}" is not allowed.`);
            return false;
        }
        
        return true;
    }
    
    /**
     * Update attachment preview
     */
    function updateAttachmentPreview() {
        if (selectedFiles.length === 0) {
            elements.attachmentPreview.style.display = 'none';
            return;
        }
        
        elements.attachmentPreview.style.display = 'block';
        const html = selectedFiles.map((file, index) => createFilePreview(file, index)).join('');
        elements.attachmentPreview.innerHTML = html;
        
        // Add remove listeners
        elements.attachmentPreview.querySelectorAll('.remove-preview').forEach((btn, index) => {
            btn.addEventListener('click', () => removeSelectedFile(index));
        });
    }
    
    /**
     * Create file preview HTML
     */
    function createFilePreview(file, index) {
        const isImage = file.type.startsWith('image/');
        const fileSize = formatFileSize(file.size);
        
        if (isImage) {
            const objectUrl = URL.createObjectURL(file);
            return `
                <div class="preview-item">
                    <img src="${objectUrl}" alt="${file.name}" class="preview-image">
                    <div class="preview-info">
                        <div class="preview-name">${escapeHtml(file.name)}</div>
                        <div class="preview-size">${fileSize}</div>
                    </div>
                    <button class="remove-preview" data-index="${index}" title="Remove file">&times;</button>
                </div>
            `;
        } else {
            const extension = file.name.split('.').pop().toUpperCase();
            return `
                <div class="preview-item">
                    <div class="preview-file-icon">${extension}</div>
                    <div class="preview-info">
                        <div class="preview-name">${escapeHtml(file.name)}</div>
                        <div class="preview-size">${fileSize}</div>
                    </div>
                    <button class="remove-preview" data-index="${index}" title="Remove file">&times;</button>
                </div>
            `;
        }
    }
    
    /**
     * Remove selected file
     */
    function removeSelectedFile(index) {
        selectedFiles.splice(index, 1);
        updateAttachmentPreview();
        updateSendButton();
    }
    
    /**
     * Clear selected files
     */
    function clearSelectedFiles() {
        selectedFiles = [];
        updateAttachmentPreview();
    }
    
    /**
     * Handle typing indicators
     */
    function handleMessageInput() {
        const content = elements.messageInput.value;
        
        // Update character count
        updateCharCount();
        
        // Update send button state
        updateSendButton();
        
        // Handle typing indicator
        if (content.trim() && !isTyping) {
            startTyping();
        } else if (!content.trim() && isTyping) {
            stopTyping();
        }
        
        // Reset typing timeout
        if (typingTimeout) {
            clearTimeout(typingTimeout);
        }
        
        typingTimeout = setTimeout(() => {
            if (isTyping) {
                stopTyping();
            }
        }, config.typingTimeout);
    }
    
    /**
     * Start typing indicator
     */
    async function startTyping() {
        if (!currentConversationId || isTyping) return;
        
        isTyping = true;
        
        try {
            await window.ApiModule.post(`${config.apiBaseUrl}/conversations/${currentConversationId}/typing`, {
                is_typing: true
            });
        } catch (error) {
            console.error('Failed to send typing status:', error);
        }
    }
    
    /**
     * Stop typing indicator
     */
    async function stopTyping() {
        if (!currentConversationId || !isTyping) return;
        
        isTyping = false;
        
        try {
            await window.ApiModule.post(`${config.apiBaseUrl}/conversations/${currentConversationId}/typing`, {
                is_typing: false
            });
        } catch (error) {
            console.error('Failed to send typing status:', error);
        }
    }
    
    /**
     * Update character count display
     */
    function updateCharCount() {
        const count = elements.messageInput.value.length;
        elements.charCount.textContent = count;
        
        elements.charCount.className = 'char-count';
        if (count > config.maxMessageLength * 0.9) {
            elements.charCount.classList.add('warning');
        }
        if (count > config.maxMessageLength * 0.95) {
            elements.charCount.classList.add('danger');
        }
    }
    
    /**
     * Update send button state
     */
    function updateSendButton() {
        const hasContent = elements.messageInput.value.trim().length > 0;
        const hasFiles = selectedFiles.length > 0;
        elements.sendBtn.disabled = !hasContent && !hasFiles;
    }
    
    /**
     * Initialize WebSocket connection
     */
    function initializeWebSocket() {
        if (!window.WebSocketModule) {
            console.warn('WebSocket module not available, falling back to polling');
            return;
        }
        
        websocket = window.WebSocketModule.connect();
        
        websocket.onMessage = handleWebSocketMessage;
        websocket.onConnect = () => console.log('WebSocket connected');
        websocket.onDisconnect = () => console.log('WebSocket disconnected');
        websocket.onError = (error) => console.error('WebSocket error:', error);
    }
    
    /**
     * Handle WebSocket messages
     */
    function handleWebSocketMessage(data) {
        switch (data.type) {
            case 'new_message':
                handleNewMessage(data);
                break;
            case 'message_update':
                handleMessageUpdate(data);
                break;
            case 'typing_status':
                handleTypingStatus(data);
                break;
            case 'read_receipt':
                handleReadReceipt(data);
                break;
            case 'user_status':
                handleUserStatusUpdate(data);
                break;
            case 'reaction_update':
                handleReactionUpdate(data);
                break;
        }
    }
    
    /**
     * Handle new message from WebSocket
     */
    function handleNewMessage(data) {
        if (data.conversation_id === currentConversationId) {
            addMessageToChat(data.message);
            scrollToBottom();
            
            // Mark as read if window is focused
            if (document.hasFocus()) {
                markMessageAsRead(data.message.id);
            }
        } else {
            // Update conversation list
            updateConversationInList(data.conversation_id);
        }
        
        // Show notification if window is not focused
        if (!document.hasFocus() && 'Notification' in window && Notification.permission === 'granted') {
            showDesktopNotification(data.message);
        }
        
        // Play notification sound
        playNotificationSound();
    }
    
    /**
     * Utility functions
     */
    function formatMessagePreview(content, type) {
        switch (type) {
            case 'image':
                return '📷 Image';
            case 'file':
                return '📄 File';
            case 'offer':
                return '💰 Offer';
            default:
                return content ? escapeHtml(content.substring(0, 50) + (content.length > 50 ? '...' : '')) : '';
        }
    }
    
    function formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffTime = Math.abs(now - date);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffTime < 60000) { // Less than 1 minute
            return 'Just now';
        } else if (diffTime < 3600000) { // Less than 1 hour
            return `${Math.floor(diffTime / 60000)}m ago`;
        } else if (diffTime < 86400000) { // Less than 1 day
            return `${Math.floor(diffTime / 3600000)}h ago`;
        } else if (diffDays === 1) {
            return 'Yesterday';
        } else if (diffDays < 7) {
            return `${diffDays} days ago`;
        } else {
            return date.toLocaleDateString();
        }
    }
    
    function formatMessageTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function formatMessageText(text) {
        return escapeHtml(text).replace(/\n/g, '<br>');
    }
    
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    /**
     * Handle typing status from WebSocket
     */
    function handleTypingStatus(data) {
        if (data.conversation_id !== currentConversationId) {
            return;
        }
        
        const isOtherUser = data.user_id !== currentUser.id;
        if (!isOtherUser) {
            return;
        }
        
        if (data.is_typing) {
            showTypingIndicator(data.user_id);
        } else {
            hideTypingIndicator(data.user_id);
        }
    }
    
    /**
     * Handle read receipt from WebSocket
     */
    function handleReadReceipt(data) {
        if (data.conversation_id !== currentConversationId) {
            return;
        }
        
        updateMessageReadStatus(data.message_id, true);
    }
    
    /**
     * Handle user status update
     */
    function handleUserStatusUpdate(data) {
        updateUserOnlineStatus(data.user_id, data.is_online, data.last_seen);
    }
    
    /**
     * Handle reaction update
     */
    function handleReactionUpdate(data) {
        if (data.conversation_id !== currentConversationId) {
            return;
        }
        
        updateMessageReactions(data.message_id, data.reactions);
    }
    
    /**
     * Show typing indicator
     */
    function showTypingIndicator(userId) {
        if (!elements.typingIndicator) return;
        
        const currentConversation = conversations.find(c => c.id === currentConversationId);
        const userName = currentConversation ? currentConversation.other_user_name : 'Someone';
        
        elements.typingIndicator.querySelector('.typing-text').textContent = `${userName} is typing`;
        elements.typingIndicator.style.display = 'block';
        
        // Scroll to bottom if needed
        if (isScrolledToBottom()) {
            scrollToBottom();
        }
    }
    
    /**
     * Hide typing indicator
     */
    function hideTypingIndicator(userId) {
        if (!elements.typingIndicator) return;
        
        elements.typingIndicator.style.display = 'none';
    }
    
    /**
     * Update message read status
     */
    function updateMessageReadStatus(messageId, isRead) {
        const messageElement = elements.messagesList.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageElement) return;
        
        const statusElement = messageElement.querySelector('.message-status svg');
        if (!statusElement) return;
        
        if (isRead) {
            statusElement.innerHTML = '<path d="M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z"/>';
        }
        
        // Update message data
        const message = messages.find(m => m.id == messageId);
        if (message) {
            message.is_read = isRead;
            message.read_at = new Date().toISOString();
        }
    }
    
    /**
     * Update user online status
     */
    function updateUserOnlineStatus(userId, isOnline, lastSeen) {
        // Update in conversation list
        const conversationItems = elements.conversationList.querySelectorAll('.conversation-item');
        conversationItems.forEach(item => {
            const conversationData = conversations.find(c => c.other_user_id == userId);
            if (conversationData) {
                const statusElement = item.querySelector('.online-status');
                if (statusElement) {
                    statusElement.className = `online-status ${isOnline ? 'online' : 'offline'}`;
                }
            }
        });
        
        // Update in chat header if this is the current conversation
        const currentConversation = conversations.find(c => c.id === currentConversationId);
        if (currentConversation && currentConversation.other_user_id == userId) {
            if (elements.onlineStatus) {
                elements.onlineStatus.className = `online-status ${isOnline ? 'online' : 'offline'}`;
            }
            
            if (elements.lastSeen) {
                if (isOnline) {
                    elements.lastSeen.textContent = 'Online';
                } else if (lastSeen) {
                    elements.lastSeen.textContent = `Last seen ${formatTimeAgo(lastSeen)}`;
                }
            }
        }
    }
    
    /**
     * Update message reactions
     */
    function updateMessageReactions(messageId, reactions) {
        const messageElement = elements.messagesList.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageElement) return;
        
        const reactionsContainer = messageElement.querySelector('.message-reactions');
        if (reactionsContainer) {
            reactionsContainer.innerHTML = createMessageReactions(reactions, messageId);
        } else if (reactions && reactions.length > 0) {
            // Create reactions container
            const messageContent = messageElement.querySelector('.message-content');
            const reactionsHtml = createMessageReactions(reactions, messageId);
            messageContent.insertAdjacentHTML('beforeend', `<div class="message-reactions">${reactionsHtml}</div>`);
        }
        
        // Update message data
        const message = messages.find(m => m.id == messageId);
        if (message) {
            message.reactions = reactions;
        }
    }
    
    /**
     * Create message reactions HTML
     */
    function createMessageReactions(reactions, messageId) {
        if (!reactions || reactions.length === 0) {
            return '';
        }
        
        return reactions.map(reaction => {
            const isActive = reaction.users && reaction.users.includes(currentUser.username);
            return `
                <span class="reaction ${isActive ? 'active' : ''}" 
                      onclick="MessagesModule.toggleReaction('${messageId}', '${reaction.emoji}')">
                    <span class="reaction-emoji">${reaction.emoji}</span>
                    <span class="reaction-count">${reaction.count}</span>
                </span>
            `;
        }).join('');
    }
    
    /**
     * Toggle message reaction
     */
    async function toggleReaction(messageId, emoji) {
        try {
            const message = messages.find(m => m.id == messageId);
            if (!message) return;
            
            const hasReaction = message.reactions && 
                message.reactions.some(r => r.emoji === emoji && r.users.includes(currentUser.username));
            
            if (hasReaction) {
                await window.ApiModule.delete(`${config.apiBaseUrl}/messages/${messageId}/reactions`, {
                    emoji: emoji
                });
            } else {
                await window.ApiModule.post(`${config.apiBaseUrl}/messages/${messageId}/reactions`, {
                    emoji: emoji
                });
            }
        } catch (error) {
            console.error('Failed to toggle reaction:', error);
            showErrorMessage('Failed to add reaction');
        }
    }
    
    /**
     * Mark message as read
     */
    async function markMessageAsRead(messageId) {
        try {
            await window.ApiModule.put(`${config.apiBaseUrl}/messages/${messageId}/read`);
            updateMessageReadStatus(messageId, true);
        } catch (error) {
            console.error('Failed to mark message as read:', error);
        }
    }
    
    /**
     * Mark conversation as read
     */
    async function markConversationAsRead(conversationId) {
        try {
            // Mark all unread messages in the conversation as read
            const unreadMessages = messages.filter(m => m.sender_id !== currentUser.id && !m.is_read);
            
            for (const message of unreadMessages) {
                await markMessageAsRead(message.id);
            }
            
            // Update conversation in sidebar
            const conversationItem = elements.conversationList.querySelector(`[data-conversation-id="${conversationId}"]`);
            if (conversationItem) {
                conversationItem.classList.remove('unread');
                const unreadBadge = conversationItem.querySelector('.unread-badge');
                if (unreadBadge) {
                    unreadBadge.remove();
                }
            }
            
            // Update total unread count
            updateNotificationBadge();
            
        } catch (error) {
            console.error('Failed to mark conversation as read:', error);
        }
    }
    
    /**
     * Additional helper functions
     */
    function isScrolledToBottom() {
        const threshold = 100;
        return elements.messagesList.scrollHeight - elements.messagesList.scrollTop <= 
               elements.messagesList.clientHeight + threshold;
    }
    
    function scrollToBottom() {
        if (elements.messagesList) {
            elements.messagesList.scrollTop = elements.messagesList.scrollHeight;
        }
    }
    
    function showChatInterface() {
        elements.chatPlaceholder.style.display = 'none';
        elements.chatHeader.style.display = 'flex';
        elements.messagesContainer.style.display = 'flex';
        elements.messageInputArea.style.display = 'block';
    }
    
    function updateChatHeader(conversation) {
        if (elements.participantName) {
            elements.participantName.textContent = conversation.other_user_name;
        }
        if (elements.participantAvatar) {
            elements.participantAvatar.src = conversation.other_user_avatar || '../assets/images/placeholder.jpg';
        }
        if (elements.articleTitle) {
            elements.articleTitle.textContent = conversation.article_title;
        }
        if (elements.onlineStatus) {
            elements.onlineStatus.className = `online-status ${conversation.other_user_online ? 'online' : 'offline'}`;
        }
        if (elements.lastSeen) {
            elements.lastSeen.textContent = conversation.other_user_online ? 
                'Online' : `Last seen ${formatTimeAgo(conversation.other_user_last_seen)}`;
        }
    }
    
    function updateNotificationBadge(count) {
        const badge = document.getElementById('notification-badge');
        const badgeCount = badge.querySelector('.badge-count');
        
        if (typeof count === 'undefined') {
            // Calculate from conversations
            count = conversations.reduce((total, conv) => total + (conv.unread_count || 0), 0);
        }
        
        if (count > 0) {
            badge.style.display = 'block';
            badgeCount.textContent = count > 99 ? '99+' : count.toString();
        } else {
            badge.style.display = 'none';
        }
    }
    
    function showErrorMessage(message) {
        if (window.ToastModule) {
            window.ToastModule.showError(message);
        } else {
            alert(message);
        }
    }
    
    function showLoadingState() {
        // Implementation for loading state
    }
    
    function hideLoadingState() {
        // Implementation for hiding loading state
    }
    
    function handleKeyPress(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    }
    
    function handleConversationSearch(event) {
        const query = event.target.value.trim();
        loadConversations(1, query);
    }
    
    function hideChatOnMobile() {
        if (window.innerWidth <= 768) {
            elements.messagesSidebar.classList.add('active');
        }
    }
    
    function showConversationsOnMobile() {
        if (window.innerWidth <= 768) {
            elements.messagesSidebar.classList.toggle('active');
        }
    }
    
    function attachMessageEventListeners(container) {
        // Add event listeners for message-specific interactions
        const messageContainer = container || elements.messagesList;
        
        // Image preview clicks
        messageContainer.querySelectorAll('.attachment-image').forEach(img => {
            img.addEventListener('click', (e) => {
                const fullUrl = e.target.dataset.fullUrl;
                if (fullUrl && window.ImagePreviewModule) {
                    window.ImagePreviewModule.show(fullUrl);
                }
            });
        });
        
        // File download clicks
        messageContainer.querySelectorAll('.attachment-file').forEach(file => {
            file.addEventListener('click', (e) => {
                const downloadUrl = e.currentTarget.dataset.downloadUrl;
                if (downloadUrl) {
                    window.open(downloadUrl, '_blank');
                }
            });
        });
    }
    
    // Additional event handlers
    function handleResize() {
        // Handle window resize for mobile layout
    }
    
    function handleBeforeUnload() {
        if (websocket) {
            websocket.disconnect();
        }
    }
    
    function handleWindowFocus() {
        if (currentConversationId) {
            markConversationAsRead(currentConversationId);
        }
    }
    
    function handleWindowBlur() {
        // Handle when window loses focus
    }
    
    function handleConversationListScroll() {
        // Handle infinite scrolling for conversations
    }
    
    function handleMessageListScroll() {
        // Handle infinite scrolling for messages
    }
    
    function handleDocumentClick(event) {
        // Handle clicks outside mobile sidebar
        if (window.innerWidth <= 768) {
            if (!elements.messagesSidebar.contains(event.target) && 
                !elements.mobileConversationsBtn.contains(event.target)) {
                elements.messagesSidebar.classList.remove('active');
            }
        }
    }
    
    function showConversationMenu() {
        // Implementation for conversation menu
    }
    
    function showMessageSearch() {
        // Implementation for message search
    }
    
    function appendConversationList(newConversations) {
        // Append new conversations to the list for infinite scroll
    }
    
    function prependMessages(newMessages) {
        // Prepend new messages for infinite scroll
    }
    
    function refreshCurrentConversation() {
        // Refresh current conversation as fallback
        if (currentConversationId) {
            loadMessages(currentConversationId, 1).catch(console.error);
        }
    }
    
    function handleUrlParameters() {
        // Handle direct conversation access from URL
        const params = new URLSearchParams(window.location.search);
        const conversationId = params.get('conversation');
        if (conversationId) {
            // Find and select conversation
            setTimeout(() => {
                const conversation = conversations.find(c => c.id == conversationId);
                if (conversation) {
                    selectConversation(conversation);
                }
            }, 1000);
        }
    }
    
    function updateUrl(conversationId) {
        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('conversation', conversationId);
        window.history.pushState({}, '', url);
    }
    
    function updateConversationInList(conversationId) {
        // Update specific conversation in the list
        loadConversations(1).catch(console.error);
    }
    
    function showDesktopNotification(message) {
        // Show desktop notification
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(`New message from ${message.sender_username}`, {
                body: message.content,
                icon: '../assets/icons/message-icon.png'
            });
        }
    }
    
    function playNotificationSound() {
        // Play notification sound
        try {
            const audio = new Audio('../assets/sounds/notification.mp3');
            audio.volume = 0.3;
            audio.play().catch(() => {
                // Ignore audio play errors
            });
        } catch (error) {
            // Ignore audio errors
        }
    }

    // Export public methods
    return {
        init,
        selectConversation,
        sendMessage,
        markMessageAsRead,
        toggleReaction,
        getCurrentConversationId: () => currentConversationId
    };
})();