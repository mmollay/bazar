/**
 * Admin API Handler
 * Centralized API communication for the admin panel
 */

class AdminAPI {
    constructor() {
        this.baseURL = '/backend/api/admin';
        this.token = localStorage.getItem('admin_token');
        this.setupInterceptors();
    }

    /**
     * Setup request/response interceptors
     */
    setupInterceptors() {
        // Set up default headers
        this.defaultHeaders = {
            'Content-Type': 'application/json',
        };

        if (this.token) {
            this.defaultHeaders['Authorization'] = `Bearer ${this.token}`;
        }
    }

    /**
     * Make HTTP request
     */
    async request(method, endpoint, data = null) {
        const url = `${this.baseURL}${endpoint}`;
        
        const config = {
            method: method.toUpperCase(),
            headers: { ...this.defaultHeaders },
        };

        if (data && ['POST', 'PUT', 'PATCH'].includes(config.method)) {
            if (data instanceof FormData) {
                // Remove content-type for FormData - browser will set it
                delete config.headers['Content-Type'];
                config.body = data;
            } else {
                config.body = JSON.stringify(data);
            }
        }

        // Add query parameters for GET requests
        if (data && config.method === 'GET') {
            const params = new URLSearchParams(data);
            const separator = url.includes('?') ? '&' : '?';
            config.url = `${url}${separator}${params}`;
        }

        try {
            AdminUI.showLoading();
            const response = await fetch(url, config);
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'Request failed');
            }

            return result;
        } catch (error) {
            console.error('API Request Error:', error);
            
            if (error.message.includes('unauthorized') || error.message.includes('Invalid or expired')) {
                this.handleUnauthorized();
            }
            
            throw error;
        } finally {
            AdminUI.hideLoading();
        }
    }

    /**
     * Handle unauthorized responses
     */
    handleUnauthorized() {
        this.clearToken();
        AdminAuth.logout();
        AdminUI.showToast('Session expired. Please login again.', 'error');
    }

    /**
     * Set authentication token
     */
    setToken(token) {
        this.token = token;
        localStorage.setItem('admin_token', token);
        this.defaultHeaders['Authorization'] = `Bearer ${token}`;
    }

    /**
     * Clear authentication token
     */
    clearToken() {
        this.token = null;
        localStorage.removeItem('admin_token');
        delete this.defaultHeaders['Authorization'];
    }

    // ===== AUTHENTICATION ENDPOINTS =====
    
    async login(email, password) {
        return this.request('POST', '/auth/login', { email, password });
    }

    async verify2FA(tempToken, code) {
        return this.request('POST', '/auth/2fa/verify', { 
            temp_token: tempToken, 
            code 
        });
    }

    async logout() {
        return this.request('POST', '/auth/logout');
    }

    async getMe() {
        return this.request('GET', '/auth/me');
    }

    async setup2FA() {
        return this.request('POST', '/auth/2fa/setup');
    }

    async enable2FA(code) {
        return this.request('POST', '/auth/2fa/enable', { code });
    }

    async disable2FA(password, code) {
        return this.request('POST', '/auth/2fa/disable', { password, code });
    }

    // ===== DASHBOARD ENDPOINTS =====
    
    async getDashboardStats() {
        return this.request('GET', '/dashboard/stats');
    }

    async getNotifications(params = {}) {
        return this.request('GET', '/notifications', params);
    }

    async markNotificationRead(notificationId) {
        return this.request('POST', '/notifications/read', { 
            notification_id: notificationId 
        });
    }

    // ===== USER MANAGEMENT ENDPOINTS =====
    
    async getUsers(params = {}) {
        return this.request('GET', '/users', params);
    }

    async getUserDetails(userId) {
        return this.request('GET', '/users/details', { id: userId });
    }

    async updateUser(userId, userData) {
        return this.request('PUT', '/users/update', { 
            user_id: userId, 
            ...userData 
        });
    }

    async updateUserStatus(userId, status, reason) {
        return this.request('PUT', '/users/status', { 
            user_id: userId, 
            status, 
            reason 
        });
    }

    async deleteUser(userId, reason) {
        return this.request('DELETE', '/users/delete', { 
            user_id: userId, 
            reason 
        });
    }

    async bulkUserOperations(userIds, operation, reason) {
        return this.request('POST', '/users/bulk', { 
            user_ids: userIds, 
            operation, 
            reason 
        });
    }

    // ===== ARTICLE MANAGEMENT ENDPOINTS =====
    
    async getArticles(params = {}) {
        return this.request('GET', '/articles', params);
    }

    async getArticleDetails(articleId) {
        return this.request('GET', '/articles/details', { id: articleId });
    }

    async moderateArticle(articleId, action, reason = '') {
        return this.request('POST', '/articles/moderate', { 
            article_id: articleId, 
            action, 
            reason 
        });
    }

    async bulkArticleOperations(articleIds, operation, reason = '') {
        return this.request('POST', '/articles/bulk', { 
            article_ids: articleIds, 
            operation, 
            reason 
        });
    }

    async getModerationQueue(params = {}) {
        return this.request('GET', '/articles/queue', params);
    }

    async getArticleStatistics() {
        return this.request('GET', '/articles/statistics');
    }

    // ===== REPORT MANAGEMENT ENDPOINTS =====
    
    async getReports(params = {}) {
        return this.request('GET', '/reports', params);
    }

    async getReportDetails(reportId) {
        return this.request('GET', '/reports/details', { id: reportId });
    }

    async handleReport(reportId, action, adminNotes = '', userAction = 'none') {
        return this.request('POST', '/reports/handle', { 
            report_id: reportId, 
            action, 
            admin_notes: adminNotes,
            user_action: userAction
        });
    }

    async bulkHandleReports(reportIds, action, adminNotes = '') {
        return this.request('POST', '/reports/bulk', { 
            report_ids: reportIds, 
            action, 
            admin_notes: adminNotes 
        });
    }

    async getReportStatistics() {
        return this.request('GET', '/reports/statistics');
    }

    // ===== SETTINGS ENDPOINTS =====
    
    async getSettings(group = 'all') {
        return this.request('GET', '/settings', { group });
    }

    async updateSetting(settingKey, settingValue, settingType) {
        return this.request('PUT', '/settings/update', { 
            setting_key: settingKey, 
            setting_value: settingValue,
            setting_type: settingType
        });
    }

    async createSetting(settingData) {
        return this.request('POST', '/settings/create', settingData);
    }

    async deleteSetting(settingKey) {
        return this.request('DELETE', '/settings/delete', { setting_key: settingKey });
    }

    async getSystemInfo() {
        return this.request('GET', '/settings/system-info');
    }

    // ===== EMAIL TEMPLATE ENDPOINTS =====
    
    async getEmailTemplates() {
        return this.request('GET', '/email-templates');
    }

    async updateEmailTemplate(templateId, templateData) {
        return this.request('PUT', '/email-templates/update', { 
            template_id: templateId, 
            ...templateData 
        });
    }

    async createEmailTemplate(templateData) {
        return this.request('POST', '/email-templates/create', templateData);
    }

    async testEmailTemplate(templateId, testEmail) {
        return this.request('POST', '/email-templates/test', { 
            template_id: templateId, 
            test_email: testEmail 
        });
    }

    // ===== ANALYTICS ENDPOINTS =====
    
    async getAnalyticsOverview(period = '30d') {
        return this.request('GET', '/analytics/overview', { period });
    }

    async getUserAnalytics(period = '30d') {
        return this.request('GET', '/analytics/users', { period });
    }

    async getArticleAnalytics(period = '30d') {
        return this.request('GET', '/analytics/articles', { period });
    }

    async getFinancialAnalytics(period = '30d') {
        return this.request('GET', '/analytics/financial', { period });
    }

    async exportData(type, format, period = '30d') {
        return this.request('POST', '/analytics/export', { 
            type, 
            format, 
            period 
        });
    }

    // ===== AUDIT LOG ENDPOINTS =====
    
    async getAdminLogs(params = {}) {
        return this.request('GET', '/logs/admin', params);
    }

    async getSystemLogs(params = {}) {
        return this.request('GET', '/logs/system', params);
    }

    async getSecurityLogs(params = {}) {
        return this.request('GET', '/logs/security', params);
    }

    async createSecurityAlert(alertData) {
        return this.request('POST', '/logs/security-alert', alertData);
    }

    async getEntityAuditTrail(targetType, targetId) {
        return this.request('GET', '/logs/audit-trail', { 
            target_type: targetType, 
            target_id: targetId 
        });
    }
}

// Create global instance
window.adminAPI = new AdminAPI();