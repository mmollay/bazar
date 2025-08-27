/**
 * Admin Dashboard Controller
 * Handles dashboard statistics, charts, and real-time updates
 */

class AdminDashboard {
    constructor() {
        this.charts = {};
        this.refreshInterval = null;
    }

    /**
     * Load dashboard content
     */
    async load() {
        console.log('Loading dashboard...');
        
        try {
            await this.loadStatistics();
            await this.loadCharts();
            await this.loadRecentActivity();
            await this.loadSystemHealth();
            
            console.log('Dashboard loaded successfully');
        } catch (error) {
            console.error('Error loading dashboard:', error);
            AdminUI.showToast('Error loading dashboard data', 'error');
        }
    }

    /**
     * Load dashboard statistics
     */
    async loadStatistics() {
        try {
            const response = await adminAPI.getDashboardStats();
            const data = response.data;

            // Update stat cards
            this.updateStatCard('total-users', data.daily_stats?.total_users || 0, data.weekly_trends?.users_growth || 0);
            this.updateStatCard('total-articles', data.daily_stats?.total_articles || 0, data.weekly_trends?.articles_growth || 0);
            this.updateStatCard('total-revenue', data.daily_stats?.revenue_today || 0, 0, true);
            this.updateStatCard('pending-reports', data.daily_stats?.pending_reports || 0, 0);

            // Update change indicators
            document.getElementById('users-change').textContent = `+${data.daily_stats?.new_users_today || 0} today`;
            document.getElementById('articles-change').textContent = `+${data.daily_stats?.new_articles_today || 0} today`;
            document.getElementById('revenue-change').textContent = `${data.realtime?.online_admins || 0} admins online`;
            document.getElementById('reports-change').textContent = `${data.daily_stats?.pending_reports || 0} pending`;

        } catch (error) {
            console.error('Error loading statistics:', error);
        }
    }

    /**
     * Update a stat card
     */
    updateStatCard(elementId, value, change, isCurrency = false) {
        const element = document.getElementById(elementId);
        if (!element) return;

        if (isCurrency) {
            element.textContent = AdminUI.formatCurrency(value);
        } else {
            element.textContent = AdminUI.formatNumber(value);
        }

        // Update change indicator
        const changeElement = document.getElementById(elementId.replace('total-', '') + '-change');
        if (changeElement && typeof change === 'number') {
            changeElement.textContent = `${change >= 0 ? '+' : ''}${change}%`;
            changeElement.className = `stat-change ${change >= 0 ? 'positive' : 'negative'}`;
        }
    }

    /**
     * Load and render charts
     */
    async loadCharts() {
        try {
            const response = await adminAPI.getDashboardStats();
            const data = response.data;

            // Trends chart
            this.renderTrendsChart(data.weekly_trends || []);
            
            // Category chart
            this.renderCategoryChart(data.top_categories || []);

        } catch (error) {
            console.error('Error loading charts:', error);
        }
    }

    /**
     * Render trends chart
     */
    renderTrendsChart(trendsData) {
        const canvas = document.getElementById('trends-chart');
        if (!canvas) return;

        // Destroy existing chart
        if (this.charts.trends) {
            this.charts.trends.destroy();
        }

        const ctx = canvas.getContext('2d');

        // Prepare data
        const labels = trendsData.map(item => AdminUI.formatDate(item.stat_date, false));
        const usersData = trendsData.map(item => item.new_users_today || 0);
        const articlesData = trendsData.map(item => item.new_articles_today || 0);

        this.charts.trends = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'New Users',
                        data: usersData,
                        borderColor: 'rgb(37, 99, 235)',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'New Articles',
                        data: articlesData,
                        borderColor: 'rgb(5, 150, 105)',
                        backgroundColor: 'rgba(5, 150, 105, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
    }

    /**
     * Render category chart
     */
    renderCategoryChart(categoryData) {
        const canvas = document.getElementById('category-chart');
        if (!canvas) return;

        // Destroy existing chart
        if (this.charts.category) {
            this.charts.category.destroy();
        }

        const ctx = canvas.getContext('2d');

        // Prepare data - top 5 categories
        const topCategories = categoryData.slice(0, 5);
        const labels = topCategories.map(item => item.name);
        const data = topCategories.map(item => item.article_count || 0);

        const colors = [
            'rgba(37, 99, 235, 0.8)',
            'rgba(5, 150, 105, 0.8)',
            'rgba(217, 119, 6, 0.8)',
            'rgba(220, 38, 38, 0.8)',
            'rgba(8, 145, 178, 0.8)'
        ];

        this.charts.category = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    /**
     * Load recent activity
     */
    async loadRecentActivity() {
        try {
            const response = await adminAPI.getDashboardStats();
            const activities = response.data.recent_activity || [];

            const activityContainer = document.getElementById('recent-activity');
            if (!activityContainer) return;

            if (activities.length === 0) {
                activityContainer.innerHTML = '<div class="empty-state">No recent activity</div>';
                return;
            }

            const activityHTML = activities.map(activity => `
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-${this.getActivityIcon(activity.action)}"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-description">
                            <strong>${activity.username || 'Unknown'}</strong> ${activity.description}
                        </div>
                        <div class="activity-time">${AdminUI.formatDate(activity.created_at)}</div>
                    </div>
                </div>
            `).join('');

            activityContainer.innerHTML = activityHTML;

        } catch (error) {
            console.error('Error loading recent activity:', error);
        }
    }

    /**
     * Load system health metrics
     */
    async loadSystemHealth() {
        try {
            const response = await adminAPI.getDashboardStats();
            const health = response.data.system_health || {};

            const healthContainer = document.getElementById('system-health');
            if (!healthContainer) return;

            const healthHTML = `
                <div class="health-metric">
                    <span class="health-label">Database Size</span>
                    <span class="health-value">${health.database_size_mb || 0} MB</span>
                </div>
                <div class="health-metric">
                    <span class="health-label">Upload Storage</span>
                    <span class="health-value">${health.uploads_size_mb || 0} MB</span>
                </div>
                <div class="health-metric">
                    <span class="health-label">Failed Logins (24h)</span>
                    <span class="health-value ${health.failed_logins_24h > 10 ? 'health-warning' : ''}">${health.failed_logins_24h || 0}</span>
                </div>
                <div class="health-metric">
                    <span class="health-label">Log Files</span>
                    <span class="health-value">${health.logs_size_mb || 0} MB</span>
                </div>
            `;

            healthContainer.innerHTML = healthHTML;

        } catch (error) {
            console.error('Error loading system health:', error);
        }
    }

    /**
     * Get activity icon based on action
     */
    getActivityIcon(action) {
        const iconMap = {
            'login': 'sign-in-alt',
            'logout': 'sign-out-alt',
            'create_user': 'user-plus',
            'update_user': 'user-edit',
            'delete_user': 'user-times',
            'create_article': 'plus-circle',
            'update_article': 'edit',
            'delete_article': 'trash',
            'handle_report': 'gavel',
            'update_setting': 'cog'
        };

        return iconMap[action] || 'circle';
    }

    /**
     * Refresh dashboard statistics
     */
    async refreshStats() {
        try {
            await this.loadStatistics();
            console.log('Dashboard stats refreshed');
        } catch (error) {
            console.error('Error refreshing stats:', error);
        }
    }

    /**
     * Start auto-refresh
     */
    startAutoRefresh() {
        // Refresh every 2 minutes
        this.refreshInterval = setInterval(() => {
            this.refreshStats();
        }, 2 * 60 * 1000);
    }

    /**
     * Stop auto-refresh
     */
    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }

    /**
     * Cleanup charts and intervals
     */
    cleanup() {
        this.stopAutoRefresh();
        
        Object.values(this.charts).forEach(chart => {
            if (chart) chart.destroy();
        });
        
        this.charts = {};
    }
}

// Add dashboard-specific styles
const dashboardStyles = `
    <style>
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 2rem;
            height: 2rem;
            background-color: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-description {
            font-size: var(--font-size-sm);
            color: var(--gray-700);
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            font-size: var(--font-size-xs);
            color: var(--gray-500);
        }
        
        .health-metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .health-metric:last-child {
            border-bottom: none;
        }
        
        .health-label {
            font-size: var(--font-size-sm);
            color: var(--gray-600);
        }
        
        .health-value {
            font-size: var(--font-size-sm);
            font-weight: 500;
            color: var(--gray-900);
        }
        
        .health-warning {
            color: var(--warning-color);
        }
        
        .empty-state {
            padding: 2rem;
            text-align: center;
            color: var(--gray-500);
            font-size: var(--font-size-sm);
        }
        
        .chart-container canvas {
            height: 300px !important;
        }
    </style>
`;

document.head.insertAdjacentHTML('beforeend', dashboardStyles);

// Create global instance
window.AdminDashboard = new AdminDashboard();