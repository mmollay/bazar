// Bazar - API Module

/**
 * API client for Bazar application
 */
class BazarAPI {
    constructor() {
        this.baseURL = '/bazar/backend/api/v1';
        this.timeout = 10000;
        this.defaultHeaders = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };
        
        // Request interceptors
        this.requestInterceptors = [];
        this.responseInterceptors = [];
        
        // Initialize API
        this.init();
    }
    
    /**
     * Initialize API client
     */
    init() {
        // Add default request interceptor for auth
        this.addRequestInterceptor(this.addAuthHeader.bind(this));
        
        // Add default response interceptor for error handling
        this.addResponseInterceptor(this.handleResponse.bind(this));
    }
    
    /**
     * Add request interceptor
     * @param {Function} interceptor - Interceptor function
     */
    addRequestInterceptor(interceptor) {
        this.requestInterceptors.push(interceptor);
    }
    
    /**
     * Add response interceptor
     * @param {Function} interceptor - Interceptor function
     */
    addResponseInterceptor(interceptor) {
        this.responseInterceptors.push(interceptor);
    }
    
    /**
     * Add authorization header
     * @param {Object} config - Request configuration
     * @returns {Object} Modified configuration
     */
    addAuthHeader(config) {
        const token = this.getAuthToken();
        if (token) {
            config.headers = {
                ...config.headers,
                'Authorization': `Bearer ${token}`
            };
        }
        return config;
    }
    
    /**
     * Get authentication token from storage
     * @returns {string|null} Auth token
     */
    getAuthToken() {
        return BazarUtils.getLocalStorage('authToken');
    }
    
    /**
     * Set authentication token
     * @param {string} token - Auth token
     */
    setAuthToken(token) {
        BazarUtils.setLocalStorage('authToken', token);
    }
    
    /**
     * Remove authentication token
     */
    removeAuthToken() {
        localStorage.removeItem('authToken');
    }
    
    /**
     * Handle response and errors
     * @param {Response} response - Fetch response
     * @param {Object} config - Request configuration
     * @returns {Promise} Processed response
     */
    async handleResponse(response, config) {
        if (!response.ok) {
            // Handle different error types
            if (response.status === 401) {
                this.handleUnauthorized();
            } else if (response.status === 403) {
                this.handleForbidden();
            } else if (response.status >= 500) {
                this.handleServerError(response);
            }
            
            // Try to parse error message
            let errorData;
            try {
                errorData = await response.json();
            } catch {
                errorData = { message: response.statusText };
            }
            
            throw new APIError(errorData.message || 'API request failed', response.status, errorData);
        }
        
        // Parse response based on content type
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return await response.json();
        } else {
            return await response.text();
        }
    }
    
    /**
     * Handle unauthorized errors (401)
     */
    handleUnauthorized() {
        console.warn('Unauthorized request - redirecting to login');
        this.removeAuthToken();
        
        // Redirect to login page if not already there
        if (window.location.pathname !== '/login') {
            router.navigate('/login');
        }
    }
    
    /**
     * Handle forbidden errors (403)
     */
    handleForbidden() {
        console.warn('Forbidden request');
        BazarUtils.showToast('Sie haben keine Berechtigung für diese Aktion', 'error');
    }
    
    /**
     * Handle server errors (5xx)
     * @param {Response} response - Error response
     */
    handleServerError(response) {
        console.error('Server error:', response.status);
        BazarUtils.showToast('Serverfehler. Bitte versuchen Sie es später erneut.', 'error');
    }
    
    /**
     * Make HTTP request
     * @param {string} url - Request URL
     * @param {Object} options - Request options
     * @returns {Promise} Request promise
     */
    async request(url, options = {}) {
        // Build full URL
        const fullURL = url.startsWith('http') ? url : `${this.baseURL}${url}`;
        
        // Prepare configuration
        let config = {
            method: 'GET',
            headers: { ...this.defaultHeaders },
            ...options
        };
        
        // Apply request interceptors
        for (const interceptor of this.requestInterceptors) {
            config = await interceptor(config) || config;
        }
        
        try {
            // Create abort controller for timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.timeout);
            
            config.signal = controller.signal;
            
            // Make request
            const response = await fetch(fullURL, config);
            clearTimeout(timeoutId);
            
            // Apply response interceptors
            let result = response;
            for (const interceptor of this.responseInterceptors) {
                result = await interceptor(result, config) || result;
            }
            
            return result;
            
        } catch (error) {
            if (error.name === 'AbortError') {
                throw new APIError('Request timeout', 408);
            }
            
            // Network error
            if (!navigator.onLine) {
                throw new APIError('No internet connection', 0);
            }
            
            throw error;
        }
    }
    
    /**
     * GET request
     * @param {string} url - Request URL
     * @param {Object} options - Request options
     * @returns {Promise} Request promise
     */
    get(url, options = {}) {
        return this.request(url, { ...options, method: 'GET' });
    }
    
    /**
     * POST request
     * @param {string} url - Request URL
     * @param {*} data - Request data
     * @param {Object} options - Request options
     * @returns {Promise} Request promise
     */
    post(url, data, options = {}) {
        return this.request(url, {
            ...options,
            method: 'POST',
            body: data ? JSON.stringify(data) : undefined
        });
    }
    
    /**
     * PUT request
     * @param {string} url - Request URL
     * @param {*} data - Request data
     * @param {Object} options - Request options
     * @returns {Promise} Request promise
     */
    put(url, data, options = {}) {
        return this.request(url, {
            ...options,
            method: 'PUT',
            body: data ? JSON.stringify(data) : undefined
        });
    }
    
    /**
     * PATCH request
     * @param {string} url - Request URL
     * @param {*} data - Request data
     * @param {Object} options - Request options
     * @returns {Promise} Request promise
     */
    patch(url, data, options = {}) {
        return this.request(url, {
            ...options,
            method: 'PATCH',
            body: data ? JSON.stringify(data) : undefined
        });
    }
    
    /**
     * DELETE request
     * @param {string} url - Request URL
     * @param {Object} options - Request options
     * @returns {Promise} Request promise
     */
    delete(url, options = {}) {
        return this.request(url, { ...options, method: 'DELETE' });
    }
    
    /**
     * Upload file
     * @param {string} url - Upload URL
     * @param {FormData} formData - Form data with files
     * @param {Object} options - Request options
     * @returns {Promise} Upload promise
     */
    upload(url, formData, options = {}) {
        const uploadOptions = {
            ...options,
            method: 'POST',
            body: formData,
            headers: {
                // Don't set Content-Type for FormData - browser will set it with boundary
                ...options.headers
            }
        };
        
        // Remove Content-Type header for file uploads
        if (uploadOptions.headers) {
            delete uploadOptions.headers['Content-Type'];
        }
        
        return this.request(url, uploadOptions);
    }
    
    // === AUTHENTICATION ENDPOINTS ===
    
    /**
     * Login user
     * @param {Object} credentials - User credentials
     * @returns {Promise} Login response
     */
    async login(credentials) {
        const response = await this.post('/auth/login', credentials);
        if (response.token) {
            this.setAuthToken(response.token);
        }
        return response;
    }
    
    /**
     * Register user
     * @param {Object} userData - User registration data
     * @returns {Promise} Registration response
     */
    register(userData) {
        return this.post('/auth/register', userData);
    }
    
    /**
     * Logout user
     * @returns {Promise} Logout response
     */
    async logout() {
        try {
            await this.post('/auth/logout');
        } finally {
            this.removeAuthToken();
        }
    }
    
    /**
     * Refresh authentication token
     * @returns {Promise} Refresh response
     */
    async refreshToken() {
        const response = await this.post('/auth/refresh');
        if (response.token) {
            this.setAuthToken(response.token);
        }
        return response;
    }
    
    /**
     * Get current user profile
     * @returns {Promise} User profile
     */
    getProfile() {
        return this.get('/auth/profile');
    }
    
    // === ARTICLE ENDPOINTS ===
    
    /**
     * Search articles
     * @param {Object} params - Search parameters
     * @returns {Promise} Search results
     */
    searchArticles(params) {
        const query = new URLSearchParams(params).toString();
        return this.get(`/search?${query}`);
    }

    /**
     * Get search suggestions
     * @param {Object} params - Search parameters
     * @returns {Promise} Search suggestions
     */
    getSearchSuggestions(params) {
        const query = new URLSearchParams(params).toString();
        return this.get(`/search/suggestions?${query}`);
    }

    /**
     * Get search filter options
     * @returns {Promise} Filter options
     */
    getSearchFilterOptions() {
        return this.get('/search/filters');
    }

    /**
     * Save search
     * @param {Object} searchData - Search data to save
     * @returns {Promise} Save response
     */
    saveSearch(searchData) {
        return this.post('/search/save', searchData);
    }

    /**
     * Get saved searches
     * @returns {Promise} Saved searches
     */
    getSavedSearches() {
        return this.get('/search/saved');
    }

    /**
     * Delete saved search
     * @param {string} searchId - Search ID
     * @returns {Promise} Delete response
     */
    deleteSavedSearch(searchId) {
        return this.delete(`/search/saved/${searchId}`);
    }
    
    /**
     * Get article by ID
     * @param {string} id - Article ID
     * @returns {Promise} Article data
     */
    getArticle(id) {
        return this.get(`/articles/${id}`);
    }
    
    /**
     * Create new article
     * @param {Object} articleData - Article data
     * @returns {Promise} Created article
     */
    createArticle(articleData) {
        return this.post('/articles', articleData);
    }
    
    /**
     * Update article
     * @param {string} id - Article ID
     * @param {Object} articleData - Updated article data
     * @returns {Promise} Updated article
     */
    updateArticle(id, articleData) {
        return this.put(`/articles/${id}`, articleData);
    }
    
    /**
     * Delete article
     * @param {string} id - Article ID
     * @returns {Promise} Deletion response
     */
    deleteArticle(id) {
        return this.delete(`/articles/${id}`);
    }
    
    /**
     * Get featured articles
     * @returns {Promise} Featured articles
     */
    async getFeaturedArticles() {
        try {
            const response = await this.get('/articles/featured');
            return response.data || [];
        } catch (error) {
            console.warn('Failed to load featured articles:', error);
            return [];
        }
    }
    
    /**
     * Get user's articles
     * @param {Object} params - Query parameters
     * @returns {Promise} User articles
     */
    getUserArticles(params = {}) {
        const query = new URLSearchParams(params).toString();
        return this.get(`/articles/my?${query}`);
    }
    
    // === CATEGORY ENDPOINTS ===
    
    /**
     * Get all categories
     * @returns {Promise} Categories list
     */
    getCategories() {
        return this.get('/categories');
    }
    
    /**
     * Get category by ID
     * @param {string} id - Category ID
     * @returns {Promise} Category data
     */
    getCategory(id) {
        return this.get(`/categories/${id}`);
    }
    
    // === MESSAGE ENDPOINTS ===
    
    /**
     * Get conversations
     * @returns {Promise} Conversations list
     */
    getConversations() {
        return this.get('/messages/conversations');
    }
    
    /**
     * Get messages in conversation
     * @param {string} conversationId - Conversation ID
     * @returns {Promise} Messages list
     */
    getMessages(conversationId) {
        return this.get(`/messages/conversations/${conversationId}`);
    }
    
    /**
     * Send message
     * @param {Object} messageData - Message data
     * @returns {Promise} Sent message
     */
    sendMessage(messageData) {
        return this.post('/messages', messageData);
    }
    
    // === AI ENDPOINTS ===
    
    /**
     * Analyze image with AI
     * @param {FormData} imageData - Image form data
     * @returns {Promise} Analysis results
     */
    analyzeImage(imageData) {
        return this.upload('/ai/analyze-image', imageData);
    }
}

/**
 * Custom API Error class
 */
class APIError extends Error {
    constructor(message, status, data = null) {
        super(message);
        this.name = 'APIError';
        this.status = status;
        this.data = data;
    }
}

// Create global API instance
const api = new BazarAPI();

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { BazarAPI, APIError, api };
} else {
    window.BazarAPI = BazarAPI;
    window.APIError = APIError;
    window.api = api;
}