/**
 * Admin Authentication Handler
 * Manages login, 2FA, and session management
 */

class AdminAuth {
    constructor() {
        this.currentUser = null;
        this.init();
    }

    /**
     * Initialize authentication
     */
    init() {
        this.checkAuthStatus();
        this.bindEvents();
    }

    /**
     * Check if user is authenticated
     */
    async checkAuthStatus() {
        const token = localStorage.getItem('admin_token');
        
        if (!token) {
            this.showLogin();
            return;
        }

        try {
            const response = await adminAPI.getMe();
            this.currentUser = response.data.user;
            this.showAdminInterface();
        } catch (error) {
            console.error('Auth check failed:', error);
            this.showLogin();
        }
    }

    /**
     * Bind authentication events
     */
    bindEvents() {
        // Login form
        const loginForm = document.getElementById('login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', this.handleLogin.bind(this));
        }

        // 2FA form
        const twofaForm = document.getElementById('twofa-form');
        if (twofaForm) {
            twofaForm.addEventListener('submit', this.handle2FA.bind(this));
        }

        // Back to login button
        const backToLogin = document.getElementById('back-to-login');
        if (backToLogin) {
            backToLogin.addEventListener('click', (e) => {
                e.preventDefault();
                this.showLogin();
            });
        }

        // Logout button
        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', this.logout.bind(this));
        }

        // Auto-focus on 2FA code input
        const twofaCodeInput = document.getElementById('twofa-code');
        if (twofaCodeInput) {
            twofaCodeInput.addEventListener('input', (e) => {
                // Auto-submit when 6 digits entered
                if (e.target.value.length === 6 && /^\d{6}$/.test(e.target.value)) {
                    setTimeout(() => {
                        twofaForm.dispatchEvent(new Event('submit'));
                    }, 500);
                }
            });
        }
    }

    /**
     * Handle login form submission
     */
    async handleLogin(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const email = formData.get('email');
        const password = formData.get('password');

        if (!email || !password) {
            AdminUI.showToast('Please fill in all fields', 'error');
            return;
        }

        try {
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';

            const response = await adminAPI.login(email, password);

            if (response.data.requires_2fa) {
                // Show 2FA form
                document.getElementById('temp-token').value = response.data.temp_token;
                this.show2FA();
                AdminUI.showToast('Please enter your 2FA code', 'info');
            } else {
                // Login successful
                adminAPI.setToken(response.data.session_token);
                this.currentUser = response.data.user;
                this.showAdminInterface();
                AdminUI.showToast('Login successful', 'success');
            }

        } catch (error) {
            console.error('Login error:', error);
            AdminUI.showToast(error.message || 'Login failed', 'error');
        } finally {
            const submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In';
        }
    }

    /**
     * Handle 2FA form submission
     */
    async handle2FA(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const tempToken = formData.get('temp_token') || document.getElementById('temp-token').value;
        const code = formData.get('code');

        if (!code || code.length !== 6) {
            AdminUI.showToast('Please enter a valid 6-digit code', 'error');
            return;
        }

        try {
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

            const response = await adminAPI.verify2FA(tempToken, code);
            
            adminAPI.setToken(response.data.session_token);
            this.currentUser = response.data.user;
            this.showAdminInterface();
            AdminUI.showToast('2FA verification successful', 'success');

        } catch (error) {
            console.error('2FA error:', error);
            AdminUI.showToast(error.message || '2FA verification failed', 'error');
            
            // Clear the code input
            document.getElementById('twofa-code').value = '';
            document.getElementById('twofa-code').focus();
        } finally {
            const submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Verify';
        }
    }

    /**
     * Handle logout
     */
    async logout() {
        try {
            await adminAPI.logout();
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            adminAPI.clearToken();
            this.currentUser = null;
            this.showLogin();
            AdminUI.showToast('Logged out successfully', 'success');
        }
    }

    /**
     * Show login screen
     */
    showLogin() {
        document.getElementById('login-screen').style.display = 'flex';
        document.getElementById('twofa-screen').style.display = 'none';
        document.getElementById('admin-interface').style.display = 'none';
        
        // Clear form data
        const loginForm = document.getElementById('login-form');
        if (loginForm) {
            loginForm.reset();
            // Focus on email input
            setTimeout(() => {
                const emailInput = document.getElementById('email');
                if (emailInput) emailInput.focus();
            }, 100);
        }
    }

    /**
     * Show 2FA screen
     */
    show2FA() {
        document.getElementById('login-screen').style.display = 'none';
        document.getElementById('twofa-screen').style.display = 'flex';
        document.getElementById('admin-interface').style.display = 'none';
        
        // Focus on 2FA code input
        setTimeout(() => {
            const codeInput = document.getElementById('twofa-code');
            if (codeInput) codeInput.focus();
        }, 100);
    }

    /**
     * Show admin interface
     */
    showAdminInterface() {
        document.getElementById('login-screen').style.display = 'none';
        document.getElementById('twofa-screen').style.display = 'none';
        document.getElementById('admin-interface').style.display = 'flex';
        
        // Update user info in sidebar
        if (this.currentUser) {
            const adminName = document.getElementById('admin-name');
            const adminRole = document.getElementById('admin-role');
            
            if (adminName) {
                adminName.textContent = `${this.currentUser.first_name || ''} ${this.currentUser.last_name || ''}`.trim() || this.currentUser.username;
            }
            
            if (adminRole) {
                adminRole.textContent = this.currentUser.admin_role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
            }
        }

        // Initialize admin interface
        if (window.AdminMain && typeof AdminMain.init === 'function') {
            AdminMain.init();
        }
    }

    /**
     * Get current user
     */
    getCurrentUser() {
        return this.currentUser;
    }

    /**
     * Check if user has specific role
     */
    hasRole(requiredRole) {
        if (!this.currentUser) return false;
        
        const roleHierarchy = ['support', 'moderator', 'admin', 'super_admin'];
        const userRoleLevel = roleHierarchy.indexOf(this.currentUser.admin_role);
        const requiredRoleLevel = roleHierarchy.indexOf(requiredRole);
        
        return userRoleLevel >= requiredRoleLevel;
    }

    /**
     * Check if user can perform action
     */
    canPerform(action) {
        if (!this.currentUser) return false;

        const permissions = {
            'delete_user': ['super_admin'],
            'modify_admin': ['super_admin'],
            'system_settings': ['super_admin', 'admin'],
            'user_management': ['super_admin', 'admin', 'moderator'],
            'content_moderation': ['super_admin', 'admin', 'moderator'],
            'view_analytics': ['super_admin', 'admin'],
            'handle_reports': ['super_admin', 'admin', 'moderator', 'support']
        };

        const allowedRoles = permissions[action] || [];
        return allowedRoles.includes(this.currentUser.admin_role);
    }

    /**
     * Setup 2FA for current user
     */
    async setup2FA() {
        try {
            const response = await adminAPI.setup2FA();
            return response.data;
        } catch (error) {
            console.error('2FA setup error:', error);
            throw error;
        }
    }

    /**
     * Enable 2FA for current user
     */
    async enable2FA(code) {
        try {
            const response = await adminAPI.enable2FA(code);
            AdminUI.showToast('2FA enabled successfully', 'success');
            return response;
        } catch (error) {
            console.error('2FA enable error:', error);
            throw error;
        }
    }

    /**
     * Disable 2FA for current user
     */
    async disable2FA(password, code) {
        try {
            const response = await adminAPI.disable2FA(password, code);
            AdminUI.showToast('2FA disabled successfully', 'success');
            return response;
        } catch (error) {
            console.error('2FA disable error:', error);
            throw error;
        }
    }
}

// Create global instance
window.AdminAuth = new AdminAuth();