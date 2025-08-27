// Bazar - Authentication Module

/**
 * Authentication manager for Bazar application
 */
class BazarAuth {
    constructor() {
        this.currentUser = null;
        this.loginCallbacks = [];
        this.logoutCallbacks = [];
        this.tokenRefreshInterval = null;
        
        // Initialize auth
        this.init();
    }
    
    /**
     * Initialize authentication
     */
    async init() {
        // Check for existing token
        const token = this.getToken();
        if (token) {
            try {
                // Verify token and get user profile
                await this.loadUserProfile();
                this.startTokenRefresh();
            } catch (error) {
                console.warn('Failed to load user profile:', error);
                this.logout();
            }
        }
        
        // Setup auth UI
        this.setupAuthUI();
    }
    
    /**
     * Get authentication token
     * @returns {string|null} Auth token
     */
    getToken() {
        return BazarUtils.getLocalStorage('authToken');
    }
    
    /**
     * Set authentication token
     * @param {string} token - Auth token
     */
    setToken(token) {
        BazarUtils.setLocalStorage('authToken', token);
        api.setAuthToken(token);
    }
    
    /**
     * Remove authentication token
     */
    removeToken() {
        localStorage.removeItem('authToken');
        api.removeAuthToken();
    }
    
    /**
     * Check if user is authenticated
     * @returns {boolean} True if authenticated
     */
    isAuthenticated() {
        return !!this.currentUser && !!this.getToken();
    }
    
    /**
     * Login user
     * @param {Object} credentials - User credentials
     * @returns {Promise} Login result
     */
    async login(credentials) {
        try {
            const response = await api.login(credentials);
            
            if (response.token && response.user) {
                this.setToken(response.token);
                this.currentUser = response.user;
                
                // Store user data
                BazarUtils.setLocalStorage('currentUser', response.user);
                
                // Start token refresh
                this.startTokenRefresh();
                
                // Update UI
                this.updateAuthUI();
                
                // Trigger login callbacks
                this.triggerCallbacks(this.loginCallbacks, response.user);
                
                BazarUtils.showToast('Erfolgreich angemeldet!', 'success');
                
                return response;
            } else {
                throw new Error('Invalid login response');
            }
            
        } catch (error) {
            console.error('Login failed:', error);
            const message = error.data?.message || error.message || 'Anmeldung fehlgeschlagen';
            BazarUtils.showToast(message, 'error');
            throw error;
        }
    }
    
    /**
     * Register new user
     * @param {Object} userData - User registration data
     * @returns {Promise} Registration result
     */
    async register(userData) {
        try {
            const response = await api.register(userData);
            
            BazarUtils.showToast('Registrierung erfolgreich! Bitte melden Sie sich an.', 'success');
            
            return response;
            
        } catch (error) {
            console.error('Registration failed:', error);
            const message = error.data?.message || error.message || 'Registrierung fehlgeschlagen';
            BazarUtils.showToast(message, 'error');
            throw error;
        }
    }
    
    /**
     * Logout user
     * @returns {Promise} Logout result
     */
    async logout() {
        try {
            // Try to logout on server
            if (this.isAuthenticated()) {
                await api.logout();
            }
        } catch (error) {
            console.warn('Logout request failed:', error);
        } finally {
            // Clear local data
            this.currentUser = null;
            this.removeToken();
            localStorage.removeItem('currentUser');
            
            // Stop token refresh
            this.stopTokenRefresh();
            
            // Update UI
            this.updateAuthUI();
            
            // Trigger logout callbacks
            this.triggerCallbacks(this.logoutCallbacks);
            
            BazarUtils.showToast('Erfolgreich abgemeldet', 'info');
        }
    }
    
    /**
     * Load user profile from server
     * @returns {Promise} User profile
     */
    async loadUserProfile() {
        try {
            const user = await api.getProfile();
            this.currentUser = user;
            BazarUtils.setLocalStorage('currentUser', user);
            return user;
        } catch (error) {
            console.error('Failed to load user profile:', error);
            throw error;
        }
    }
    
    /**
     * Refresh authentication token
     * @returns {Promise} Refresh result
     */
    async refreshToken() {
        try {
            const response = await api.refreshToken();
            if (response.token) {
                this.setToken(response.token);
                return response;
            }
        } catch (error) {
            console.error('Token refresh failed:', error);
            // If refresh fails, logout user
            this.logout();
            throw error;
        }
    }
    
    /**
     * Start automatic token refresh
     */
    startTokenRefresh() {
        this.stopTokenRefresh();
        
        // Refresh token every 50 minutes (assuming 1 hour expiry)
        this.tokenRefreshInterval = setInterval(() => {
            if (this.isAuthenticated()) {
                this.refreshToken().catch(error => {
                    console.error('Automatic token refresh failed:', error);
                });
            }
        }, 50 * 60 * 1000);
    }
    
    /**
     * Stop automatic token refresh
     */
    stopTokenRefresh() {
        if (this.tokenRefreshInterval) {
            clearInterval(this.tokenRefreshInterval);
            this.tokenRefreshInterval = null;
        }
    }
    
    /**
     * Add login callback
     * @param {Function} callback - Callback function
     */
    onLogin(callback) {
        this.loginCallbacks.push(callback);
    }
    
    /**
     * Add logout callback
     * @param {Function} callback - Callback function
     */
    onLogout(callback) {
        this.logoutCallbacks.push(callback);
    }
    
    /**
     * Remove login callback
     * @param {Function} callback - Callback function to remove
     */
    offLogin(callback) {
        const index = this.loginCallbacks.indexOf(callback);
        if (index > -1) {
            this.loginCallbacks.splice(index, 1);
        }
    }
    
    /**
     * Remove logout callback
     * @param {Function} callback - Callback function to remove
     */
    offLogout(callback) {
        const index = this.logoutCallbacks.indexOf(callback);
        if (index > -1) {
            this.logoutCallbacks.splice(index, 1);
        }
    }
    
    /**
     * Trigger callbacks
     * @param {Array} callbacks - Array of callback functions
     * @param {...*} args - Arguments to pass to callbacks
     */
    triggerCallbacks(callbacks, ...args) {
        callbacks.forEach(callback => {
            try {
                callback(...args);
            } catch (error) {
                console.error('Auth callback error:', error);
            }
        });
    }
    
    /**
     * Setup authentication UI elements
     */
    setupAuthUI() {
        // Setup login form if exists
        this.setupLoginForm();
        
        // Setup register form if exists
        this.setupRegisterForm();
        
        // Setup logout buttons
        this.setupLogoutButtons();
        
        // Initial UI update
        this.updateAuthUI();
    }
    
    /**
     * Setup login form
     */
    setupLoginForm() {
        const loginForm = document.getElementById('login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const formData = new FormData(loginForm);
                const credentials = {
                    email: formData.get('email'),
                    password: formData.get('password'),
                    remember: formData.get('remember') === 'on'
                };
                
                // Show loading state
                const submitBtn = loginForm.querySelector('button[type="submit"]');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<div class="ui active inline loader mini"></div> Anmelden...';
                
                try {
                    await this.login(credentials);
                    
                    // Redirect after successful login
                    const redirect = new URLSearchParams(window.location.search).get('redirect') || '/';
                    router.navigate(redirect);
                    
                } catch (error) {
                    // Error already handled in login method
                } finally {
                    // Reset button state
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            });
        }
    }
    
    /**
     * Setup register form
     */
    setupRegisterForm() {
        const registerForm = document.getElementById('register-form');
        if (registerForm) {
            registerForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const formData = new FormData(registerForm);
                const userData = {
                    name: formData.get('name'),
                    email: formData.get('email'),
                    password: formData.get('password'),
                    phone: formData.get('phone') || null,
                    acceptTerms: formData.get('terms') === 'on'
                };
                
                // Validate password confirmation
                const passwordConfirm = formData.get('password_confirm');
                if (userData.password !== passwordConfirm) {
                    BazarUtils.showToast('Passwörter stimmen nicht überein', 'error');
                    return;
                }
                
                if (!userData.acceptTerms) {
                    BazarUtils.showToast('Bitte akzeptieren Sie die AGB', 'error');
                    return;
                }
                
                // Show loading state
                const submitBtn = registerForm.querySelector('button[type="submit"]');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<div class="ui active inline loader mini"></div> Registrieren...';
                
                try {
                    await this.register(userData);
                    
                    // Redirect to login page
                    router.navigate('/login?message=registration_success');
                    
                } catch (error) {
                    // Error already handled in register method
                } finally {
                    // Reset button state
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            });
        }
    }
    
    /**
     * Setup logout buttons
     */
    setupLogoutButtons() {
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="logout"]') || 
                e.target.closest('[data-action="logout"]')) {
                e.preventDefault();
                this.logout();
                router.navigate('/');
            }
        });
    }
    
    /**
     * Update authentication UI
     */
    updateAuthUI() {
        const isAuth = this.isAuthenticated();
        
        // Update login/logout buttons
        const loginBtn = document.getElementById('login-btn');
        const registerBtn = document.getElementById('register-btn');
        const userMenu = document.getElementById('user-menu');
        
        if (loginBtn) loginBtn.style.display = isAuth ? 'none' : 'block';
        if (registerBtn) registerBtn.style.display = isAuth ? 'none' : 'block';
        if (userMenu) {
            userMenu.style.display = isAuth ? 'block' : 'none';
            
            // Update user menu content
            if (isAuth && this.currentUser) {
                const userText = userMenu.querySelector('.text');
                if (userText) {
                    userText.textContent = this.currentUser.name || this.currentUser.email;
                }
            }
        }
        
        // Update protected elements
        const protectedElements = document.querySelectorAll('[data-auth-required]');
        protectedElements.forEach(element => {
            element.style.display = isAuth ? 'block' : 'none';
        });
        
        const guestElements = document.querySelectorAll('[data-guest-only]');
        guestElements.forEach(element => {
            element.style.display = isAuth ? 'none' : 'block';
        });
    }
    
    /**
     * Get current user
     * @returns {Object|null} Current user object
     */
    getCurrentUser() {
        return this.currentUser || BazarUtils.getLocalStorage('currentUser');
    }
    
    /**
     * Check if user has specific role
     * @param {string} role - Role to check
     * @returns {boolean} True if user has role
     */
    hasRole(role) {
        const user = this.getCurrentUser();
        return user && user.roles && user.roles.includes(role);
    }
    
    /**
     * Check if user has specific permission
     * @param {string} permission - Permission to check
     * @returns {boolean} True if user has permission
     */
    hasPermission(permission) {
        const user = this.getCurrentUser();
        return user && user.permissions && user.permissions.includes(permission);
    }
    
    /**
     * Require authentication for page access
     * @param {string} redirectPath - Path to redirect if not authenticated
     * @returns {boolean} True if user is authenticated
     */
    requireAuth(redirectPath = '/login') {
        if (!this.isAuthenticated()) {
            const currentPath = window.location.pathname + window.location.search;
            const loginPath = `${redirectPath}?redirect=${encodeURIComponent(currentPath)}`;
            router.navigate(loginPath);
            return false;
        }
        return true;
    }
    
    /**
     * Require guest (non-authenticated) access
     * @param {string} redirectPath - Path to redirect if authenticated
     * @returns {boolean} True if user is not authenticated
     */
    requireGuest(redirectPath = '/') {
        if (this.isAuthenticated()) {
            router.navigate(redirectPath);
            return false;
        }
        return true;
    }
}

// Create global auth instance
const auth = new BazarAuth();

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { BazarAuth, auth };
} else {
    window.BazarAuth = BazarAuth;
    window.auth = auth;
}