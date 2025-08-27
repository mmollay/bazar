/**
 * Main Admin Application
 * Entry point and navigation controller
 */

class AdminMain {
    constructor() {
        this.currentPage = 'dashboard';
        this.refreshInterval = null;
    }

    /**
     * Initialize admin application
     */
    init() {
        console.log('Initializing Admin Panel...');
        
        this.bindNavigationEvents();
        this.bindNotificationEvents();
        this.startPeriodicRefresh();
        
        // Load initial page
        this.navigateToPage('dashboard');
        
        console.log('Admin Panel initialized successfully');
    }

    /**
     * Bind navigation events
     */
    bindNavigationEvents() {
        const menuItems = document.querySelectorAll('.menu-item');
        
        menuItems.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const page = item.getAttribute('data-page');
                this.navigateToPage(page);
            });
        });

        // Handle browser back/forward
        window.addEventListener('popstate', (e) => {
            const page = e.state?.page || 'dashboard';
            this.navigateToPage(page, false);
        });
    }

    /**
     * Bind notification events
     */
    bindNotificationEvents() {
        const notificationBtn = document.getElementById('notification-btn');
        const notificationDropdown = document.getElementById('notification-dropdown');
        const markAllReadBtn = document.getElementById('mark-all-read');

        if (notificationBtn) {
            notificationBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleNotifications();
            });
        }

        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', () => {
                this.markAllNotificationsRead();
            });
        }

        // Load initial notifications
        this.loadNotifications();
    }

    /**
     * Navigate to a specific page
     */
    async navigateToPage(page, updateHistory = true) {
        if (page === this.currentPage) return;

        console.log(`Navigating to: ${page}`);

        // Update active menu item
        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.classList.remove('active');
            if (item.getAttribute('data-page') === page) {
                item.classList.add('active');
            }
        });

        // Update page title
        const pageTitle = document.getElementById('page-title');
        if (pageTitle) {
            pageTitle.textContent = this.getPageTitle(page);
        }

        // Hide all page contents
        const pageContents = document.querySelectorAll('.page-content');
        pageContents.forEach(content => {
            content.classList.remove('active');
        });

        // Show target page
        const targetPage = document.getElementById(`${page}-page`);
        if (targetPage) {
            targetPage.classList.add('active');
        }

        // Close mobile sidebar
        const sidebar = document.querySelector('.sidebar');
        if (sidebar && window.innerWidth <= 768) {
            sidebar.classList.remove('open');
        }

        // Update URL without reload
        if (updateHistory) {
            const url = page === 'dashboard' ? '/admin/' : `/admin/#${page}`;
            window.history.pushState({ page }, '', url);
        }

        this.currentPage = page;

        // Load page-specific content
        await this.loadPageContent(page);
    }

    /**
     * Load content for specific page
     */
    async loadPageContent(page) {
        try {
            switch (page) {
                case 'dashboard':
                    if (window.AdminDashboard) {
                        await AdminDashboard.load();
                    }
                    break;
                    
                case 'users':
                    if (window.AdminUsers) {
                        await AdminUsers.load();
                    }
                    break;
                    
                case 'articles':
                    if (window.AdminArticles) {
                        await AdminArticles.load();
                    }
                    break;
                    
                case 'moderation':
                    if (window.AdminModeration) {
                        await AdminModeration.load();
                    }
                    break;
                    
                case 'reports':
                    if (window.AdminReports) {
                        await AdminReports.load();
                    }
                    break;
                    
                case 'analytics':
                    if (window.AdminAnalytics) {
                        await AdminAnalytics.load();
                    }
                    break;
                    
                case 'settings':
                    if (window.AdminSettings) {
                        await AdminSettings.load();
                    }
                    break;
                    
                case 'logs':
                    if (window.AdminLogs) {
                        await AdminLogs.load();
                    }
                    break;
                    
                default:
                    console.warn(`No loader found for page: ${page}`);
            }
        } catch (error) {
            console.error(`Error loading page ${page}:`, error);
            AdminUI.showToast(`Error loading ${page} page`, 'error');
        }
    }

    /**
     * Get page title
     */
    getPageTitle(page) {
        const titles = {
            dashboard: 'Dashboard',
            users: 'User Management',
            articles: 'Article Management',
            moderation: 'Content Moderation',
            reports: 'User Reports',
            analytics: 'Analytics & Reports',
            settings: 'System Settings',
            logs: 'Audit Logs'
        };

        return titles[page] || 'Admin Panel';
    }

    /**
     * Toggle notifications dropdown
     */
    toggleNotifications() {
        const dropdown = document.getElementById('notification-dropdown');
        if (dropdown) {
            dropdown.classList.toggle('show');
            
            if (dropdown.classList.contains('show')) {
                this.loadNotifications();
            }
        }
    }

    /**
     * Load notifications
     */
    async loadNotifications() {
        try {
            const response = await adminAPI.getNotifications({ limit: 10 });
            const notifications = response.data.notifications;

            // Update notification count
            const countBadge = document.getElementById('notification-count');
            if (countBadge) {
                const unreadCount = notifications.filter(n => !n.is_read).length;
                countBadge.textContent = unreadCount;
                countBadge.style.display = unreadCount > 0 ? 'block' : 'none';
            }

            // Update notification list
            this.renderNotifications(notifications);

        } catch (error) {
            console.error('Error loading notifications:', error);
        }
    }

    /**
     * Render notifications in dropdown
     */
    renderNotifications(notifications) {
        const notificationList = document.getElementById('notification-list');
        if (!notificationList) return;

        if (notifications.length === 0) {
            notificationList.innerHTML = '<div class="notification-empty">No notifications</div>';
            return;
        }

        const notificationHTML = notifications.map(notification => `
            <div class="notification-item ${notification.is_read ? 'read' : 'unread'}" data-id="${notification.id}">
                <div class="notification-content">
                    <div class="notification-title">${notification.title}</div>
                    <div class="notification-message">${notification.message}</div>
                    <div class="notification-time">${AdminUI.formatDate(notification.created_at)}</div>
                </div>
                ${!notification.is_read ? '<div class="notification-dot"></div>' : ''}
            </div>
        `).join('');

        notificationList.innerHTML = notificationHTML;

        // Bind click events
        notificationList.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', () => {
                const notificationId = item.getAttribute('data-id');
                this.markNotificationRead(notificationId);
            });
        });
    }

    /**
     * Mark single notification as read
     */
    async markNotificationRead(notificationId) {
        try {
            await adminAPI.markNotificationRead(notificationId);
            
            // Update UI
            const notificationItem = document.querySelector(`[data-id="${notificationId}"]`);
            if (notificationItem) {
                notificationItem.classList.remove('unread');
                notificationItem.classList.add('read');
                
                const dot = notificationItem.querySelector('.notification-dot');
                if (dot) dot.remove();
            }

            // Update count
            this.updateNotificationCount();

        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }

    /**
     * Mark all notifications as read
     */
    async markAllNotificationsRead() {
        try {
            const unreadItems = document.querySelectorAll('.notification-item.unread');
            
            for (const item of unreadItems) {
                const notificationId = item.getAttribute('data-id');
                await adminAPI.markNotificationRead(notificationId);
                
                item.classList.remove('unread');
                item.classList.add('read');
                
                const dot = item.querySelector('.notification-dot');
                if (dot) dot.remove();
            }

            this.updateNotificationCount();
            AdminUI.showToast('All notifications marked as read', 'success');

        } catch (error) {
            console.error('Error marking all notifications as read:', error);
            AdminUI.showToast('Error updating notifications', 'error');
        }
    }

    /**
     * Update notification count
     */
    updateNotificationCount() {
        const unreadItems = document.querySelectorAll('.notification-item.unread');
        const countBadge = document.getElementById('notification-count');
        
        if (countBadge) {
            const count = unreadItems.length;
            countBadge.textContent = count;
            countBadge.style.display = count > 0 ? 'block' : 'none';
        }
    }

    /**
     * Update moderation and report counts in sidebar
     */
    async updateSidebarCounts() {
        try {
            // Get moderation queue count
            const moderationResponse = await adminAPI.getModerationQueue({ limit: 1 });
            const moderationCount = moderationResponse.data.total;
            
            const moderationBadge = document.getElementById('moderation-count');
            if (moderationBadge) {
                moderationBadge.textContent = moderationCount;
                moderationBadge.style.display = moderationCount > 0 ? 'block' : 'none';
            }

            // Get pending reports count
            const reportsResponse = await adminAPI.getReports({ 
                status: 'pending',
                limit: 1 
            });
            const reportsCount = reportsResponse.data.total;
            
            const reportsBadge = document.getElementById('reports-count');
            if (reportsBadge) {
                reportsBadge.textContent = reportsCount;
                reportsBadge.style.display = reportsCount > 0 ? 'block' : 'none';
            }

        } catch (error) {
            console.error('Error updating sidebar counts:', error);
        }
    }

    /**
     * Start periodic refresh of data
     */
    startPeriodicRefresh() {
        // Refresh every 5 minutes
        this.refreshInterval = setInterval(() => {
            this.loadNotifications();
            this.updateSidebarCounts();
            
            // Refresh current page if it's dashboard
            if (this.currentPage === 'dashboard' && window.AdminDashboard) {
                AdminDashboard.refreshStats();
            }
        }, 5 * 60 * 1000);

        // Initial load of sidebar counts
        this.updateSidebarCounts();
    }

    /**
     * Stop periodic refresh
     */
    stopPeriodicRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }

    /**
     * Handle page visibility change
     */
    handleVisibilityChange() {
        if (document.hidden) {
            this.stopPeriodicRefresh();
        } else {
            this.startPeriodicRefresh();
            this.loadNotifications();
        }
    }

    /**
     * Cleanup on page unload
     */
    cleanup() {
        this.stopPeriodicRefresh();
    }
}

// Add notification styles
const notificationStyles = `
    <style>
        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            position: relative;
            transition: background-color 0.15s ease-in-out;
        }
        
        .notification-item:hover {
            background-color: var(--gray-50);
        }
        
        .notification-item.unread {
            background-color: #eff6ff;
        }
        
        .notification-title {
            font-weight: 500;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .notification-message {
            font-size: var(--font-size-sm);
            color: var(--gray-600);
            margin-bottom: 0.25rem;
        }
        
        .notification-time {
            font-size: var(--font-size-xs);
            color: var(--gray-400);
        }
        
        .notification-dot {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 0.5rem;
            height: 0.5rem;
            background-color: var(--primary-color);
            border-radius: 50%;
        }
        
        .notification-empty {
            padding: 2rem;
            text-align: center;
            color: var(--gray-500);
            font-size: var(--font-size-sm);
        }
    </style>
`;

document.head.insertAdjacentHTML('beforeend', notificationStyles);

// Handle page visibility change
document.addEventListener('visibilitychange', () => {
    if (window.AdminMain) {
        AdminMain.handleVisibilityChange();
    }
});

// Handle page unload
window.addEventListener('beforeunload', () => {
    if (window.AdminMain) {
        AdminMain.cleanup();
    }
});

// Create global instance
window.AdminMain = new AdminMain();