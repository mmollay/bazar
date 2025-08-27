/**
 * Admin Users Controller
 * Handles user management, filtering, and bulk operations
 */

class AdminUsers {
    constructor() {
        this.currentPage = 1;
        this.limit = 20;
        this.searchTerm = '';
        this.statusFilter = '';
        this.sortBy = 'created_at';
        this.sortOrder = 'DESC';
        this.users = [];
        this.totalPages = 1;
    }

    /**
     * Load users page
     */
    async load() {
        console.log('Loading users page...');
        
        this.bindEvents();
        await this.loadUsers();
        
        console.log('Users page loaded successfully');
    }

    /**
     * Bind page events
     */
    bindEvents() {
        // Search input
        const searchInput = document.getElementById('users-search');
        if (searchInput) {
            searchInput.addEventListener('input', AdminUI.debounce((e) => {
                this.searchTerm = e.target.value;
                this.currentPage = 1;
                this.loadUsers();
            }, 300));
        }

        // Status filter
        const statusFilter = document.getElementById('users-filter');
        if (statusFilter) {
            statusFilter.addEventListener('change', (e) => {
                this.statusFilter = e.target.value;
                this.currentPage = 1;
                this.loadUsers();
            });
        }

        // Select all checkbox
        const selectAllCheckbox = document.getElementById('users-select-all');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.toggleAllUsers(e.target.checked);
            });
        }

        // Bulk actions
        const bulkActionSelect = document.getElementById('users-bulk-action');
        const applyBulkBtn = document.getElementById('users-apply-bulk');
        
        if (applyBulkBtn) {
            applyBulkBtn.addEventListener('click', () => {
                const action = bulkActionSelect?.value;
                if (action) {
                    this.handleBulkAction(action);
                }
            });
        }
    }

    /**
     * Load users data
     */
    async loadUsers() {
        try {
            AdminUI.showLoading();

            const params = {
                limit: this.limit,
                offset: (this.currentPage - 1) * this.limit,
                search: this.searchTerm,
                status: this.statusFilter,
                sort_by: this.sortBy,
                sort_order: this.sortOrder
            };

            const response = await adminAPI.getUsers(params);
            this.users = response.data.users;
            const total = response.data.total;
            this.totalPages = Math.ceil(total / this.limit);

            this.renderUsersTable();
            this.renderPagination();

        } catch (error) {
            console.error('Error loading users:', error);
            AdminUI.showToast('Error loading users', 'error');
        } finally {
            AdminUI.hideLoading();
        }
    }

    /**
     * Render users table
     */
    renderUsersTable() {
        const tbody = document.getElementById('users-table-body');
        if (!tbody) return;

        if (this.users.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-muted">No users found</td>
                </tr>
            `;
            return;
        }

        const usersHTML = this.users.map(user => `
            <tr data-user-id="${user.id}">
                <td>
                    <input type="checkbox" class="user-checkbox" value="${user.id}">
                </td>
                <td>
                    <div class="user-info">
                        <div class="user-avatar">
                            ${user.avatar_url ? 
                                `<img src="${user.avatar_url}" alt="${user.username}">` : 
                                `<i class="fas fa-user"></i>`
                            }
                        </div>
                        <div class="user-details">
                            <div class="user-name">${user.first_name || ''} ${user.last_name || ''}</div>
                            <div class="user-username">@${user.username}</div>
                        </div>
                    </div>
                </td>
                <td>${user.email}</td>
                <td>${AdminUI.getStatusBadge(user.status)}</td>
                <td class="text-center">${user.article_count || 0}</td>
                <td class="text-center">
                    ${user.rating > 0 ? 
                        `<span class="rating">${user.rating}/5 (${user.rating_count})</span>` : 
                        '<span class="text-muted">No ratings</span>'
                    }
                </td>
                <td>${AdminUI.formatDate(user.created_at)}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-sm btn-secondary" onclick="AdminUsers.viewUser(${user.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn-sm btn-secondary" onclick="AdminUsers.editUser(${user.id})" title="Edit User">
                            <i class="fas fa-edit"></i>
                        </button>
                        ${user.status === 'active' ? 
                            `<button class="btn-sm btn-warning" onclick="AdminUsers.suspendUser(${user.id})" title="Suspend User">
                                <i class="fas fa-pause"></i>
                            </button>` :
                            `<button class="btn-sm btn-success" onclick="AdminUsers.activateUser(${user.id})" title="Activate User">
                                <i class="fas fa-play"></i>
                            </button>`
                        }
                        ${AdminAuth.canPerform('delete_user') ? 
                            `<button class="btn-sm btn-danger" onclick="AdminUsers.deleteUser(${user.id})" title="Delete User">
                                <i class="fas fa-trash"></i>
                            </button>` : ''
                        }
                    </div>
                </td>
            </tr>
        `).join('');

        tbody.innerHTML = usersHTML;

        // Bind checkbox events
        tbody.querySelectorAll('.user-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                this.updateSelectAllState();
            });
        });
    }

    /**
     * Render pagination
     */
    renderPagination() {
        AdminUI.createPagination('users-pagination', this.currentPage, this.totalPages, (page) => {
            this.currentPage = page;
            this.loadUsers();
        });
    }

    /**
     * Toggle all user checkboxes
     */
    toggleAllUsers(checked) {
        const checkboxes = document.querySelectorAll('.user-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = checked;
        });
    }

    /**
     * Update select all checkbox state
     */
    updateSelectAllState() {
        const checkboxes = document.querySelectorAll('.user-checkbox');
        const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        const selectAllCheckbox = document.getElementById('users-select-all');

        if (selectAllCheckbox) {
            selectAllCheckbox.checked = checkedCount === checkboxes.length;
            selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }
    }

    /**
     * Get selected user IDs
     */
    getSelectedUsers() {
        const checkboxes = document.querySelectorAll('.user-checkbox:checked');
        return Array.from(checkboxes).map(cb => parseInt(cb.value));
    }

    /**
     * Handle bulk actions
     */
    handleBulkAction(action) {
        const selectedUsers = this.getSelectedUsers();
        
        if (selectedUsers.length === 0) {
            AdminUI.showToast('Please select users first', 'warning');
            return;
        }

        const actionNames = {
            activate: 'activate',
            suspend: 'suspend', 
            delete: 'delete'
        };

        const actionName = actionNames[action];
        if (!actionName) return;

        AdminUI.showConfirm(
            `Are you sure you want to ${actionName} ${selectedUsers.length} selected user(s)?`,
            `AdminUsers.confirmBulkAction('${action}', [${selectedUsers.join(',')}])`
        );
    }

    /**
     * Confirm and execute bulk action
     */
    async confirmBulkAction(action, userIds) {
        try {
            const reason = prompt(`Please provide a reason for this ${action} action:`);
            if (!reason) return;

            AdminUI.showLoading();

            await adminAPI.bulkUserOperations(userIds, action, reason);
            
            AdminUI.showToast(`Bulk ${action} completed successfully`, 'success');
            AdminUI.clearTableSelection('users-table');
            await this.loadUsers();

        } catch (error) {
            console.error('Bulk action error:', error);
            AdminUI.showToast(error.message || `Bulk ${action} failed`, 'error');
        } finally {
            AdminUI.hideLoading();
        }
    }

    /**
     * View user details
     */
    async viewUser(userId) {
        try {
            AdminUI.showLoading();

            const response = await adminAPI.getUserDetails(userId);
            const user = response.data.user;
            const stats = response.data.statistics;
            const recentArticles = response.data.recent_articles;
            const reports = response.data.reports;

            const modalBody = `
                <div class="user-detail-modal">
                    <div class="user-header">
                        <div class="user-avatar-large">
                            ${user.avatar_url ? 
                                `<img src="${user.avatar_url}" alt="${user.username}">` : 
                                `<i class="fas fa-user"></i>`
                            }
                        </div>
                        <div class="user-info">
                            <h3>${user.first_name || ''} ${user.last_name || ''}</h3>
                            <p>@${user.username}</p>
                            <p>${AdminUI.getStatusBadge(user.status)}</p>
                        </div>
                    </div>
                    
                    <div class="user-details-grid">
                        <div class="detail-section">
                            <h4>Contact Information</h4>
                            <p><strong>Email:</strong> ${user.email}</p>
                            <p><strong>Phone:</strong> ${user.phone || 'Not provided'}</p>
                            <p><strong>Verified:</strong> ${user.is_verified ? 'Yes' : 'No'}</p>
                            <p><strong>Last Login:</strong> ${AdminUI.formatDate(user.last_login_at) || 'Never'}</p>
                        </div>
                        
                        <div class="detail-section">
                            <h4>Statistics</h4>
                            <p><strong>Articles:</strong> ${stats.articles?.total_articles || 0}</p>
                            <p><strong>Active Articles:</strong> ${stats.articles?.active_articles || 0}</p>
                            <p><strong>Messages Sent:</strong> ${stats.messages?.sent_messages || 0}</p>
                            <p><strong>Average Rating:</strong> ${stats.ratings?.average_rating ? parseFloat(stats.ratings.average_rating).toFixed(1) + '/5' : 'No ratings'}</p>
                        </div>
                    </div>
                    
                    ${reports.length > 0 ? `
                        <div class="detail-section">
                            <h4>Recent Reports</h4>
                            <div class="reports-list">
                                ${reports.map(report => `
                                    <div class="report-item">
                                        <span class="report-type">${report.report_type}</span>
                                        <span class="report-status">${AdminUI.getStatusBadge(report.status)}</span>
                                        <span class="report-date">${AdminUI.formatDate(report.created_at)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;

            const modalFooter = `
                <button type="button" class="btn-secondary" onclick="AdminUI.closeModal()">Close</button>
                <button type="button" class="btn-primary" onclick="AdminUsers.editUser(${userId}); AdminUI.closeModal();">Edit User</button>
            `;

            AdminUI.showModal(`User Details - ${user.username}`, modalBody, modalFooter);

        } catch (error) {
            console.error('Error loading user details:', error);
            AdminUI.showToast('Error loading user details', 'error');
        } finally {
            AdminUI.hideLoading();
        }
    }

    /**
     * Edit user
     */
    async editUser(userId) {
        try {
            AdminUI.showLoading();

            const response = await adminAPI.getUserDetails(userId);
            const user = response.data.user;

            const modalBody = `
                <form id="edit-user-form">
                    <input type="hidden" name="user_id" value="${user.id}">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="${user.first_name || ''}" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="${user.last_name || ''}" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="${user.email}" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" value="${user.phone || ''}">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_verified" ${user.is_verified ? 'checked' : ''}> Email Verified
                        </label>
                    </div>
                    
                    ${AdminAuth.hasRole('super_admin') ? `
                        <div class="form-group">
                            <label for="admin_role">Admin Role</label>
                            <select id="admin_role" name="admin_role">
                                <option value="">No Admin Access</option>
                                <option value="support" ${user.admin_role === 'support' ? 'selected' : ''}>Support</option>
                                <option value="moderator" ${user.admin_role === 'moderator' ? 'selected' : ''}>Moderator</option>
                                <option value="admin" ${user.admin_role === 'admin' ? 'selected' : ''}>Admin</option>
                                <option value="super_admin" ${user.admin_role === 'super_admin' ? 'selected' : ''}>Super Admin</option>
                            </select>
                        </div>
                    ` : ''}
                </form>
            `;

            const modalFooter = `
                <button type="button" class="btn-secondary" onclick="AdminUI.closeModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="AdminUsers.saveUser()">Save Changes</button>
            `;

            AdminUI.showModal(`Edit User - ${user.username}`, modalBody, modalFooter);

        } catch (error) {
            console.error('Error loading user for edit:', error);
            AdminUI.showToast('Error loading user details', 'error');
        } finally {
            AdminUI.hideLoading();
        }
    }

    /**
     * Save user changes
     */
    async saveUser() {
        const form = document.getElementById('edit-user-form');
        if (!form) return;

        try {
            const formData = new FormData(form);
            const userId = parseInt(formData.get('user_id'));
            
            const userData = {
                first_name: formData.get('first_name'),
                last_name: formData.get('last_name'),
                email: formData.get('email'),
                phone: formData.get('phone') || null,
                is_verified: formData.has('is_verified')
            };

            if (AdminAuth.hasRole('super_admin')) {
                userData.admin_role = formData.get('admin_role') || null;
            }

            AdminUI.showLoading();

            await adminAPI.updateUser(userId, userData);
            
            AdminUI.showToast('User updated successfully', 'success');
            AdminUI.closeModal();
            await this.loadUsers();

        } catch (error) {
            console.error('Error saving user:', error);
            AdminUI.showToast(error.message || 'Error saving user', 'error');
        } finally {
            AdminUI.hideLoading();
        }
    }

    /**
     * Suspend user
     */
    suspendUser(userId) {
        const reason = prompt('Please provide a reason for suspending this user:');
        if (!reason) return;

        AdminUI.showConfirm(
            'Are you sure you want to suspend this user?',
            `AdminUsers.confirmStatusChange(${userId}, 'suspended', '${reason}')`
        );
    }

    /**
     * Activate user
     */
    activateUser(userId) {
        AdminUI.showConfirm(
            'Are you sure you want to activate this user?',
            `AdminUsers.confirmStatusChange(${userId}, 'active', 'Account reactivated by admin')`
        );
    }

    /**
     * Delete user
     */
    deleteUser(userId) {
        if (!AdminAuth.canPerform('delete_user')) {
            AdminUI.showToast('You do not have permission to delete users', 'error');
            return;
        }

        const reason = prompt('Please provide a reason for deleting this user:');
        if (!reason) return;

        AdminUI.showConfirm(
            'Are you sure you want to delete this user? This action cannot be undone.',
            `AdminUsers.confirmDelete(${userId}, '${reason}')`
        );
    }

    /**
     * Confirm status change
     */
    async confirmStatusChange(userId, status, reason) {
        try {
            AdminUI.showLoading();

            await adminAPI.updateUserStatus(userId, status, reason);
            
            AdminUI.showToast(`User ${status} successfully`, 'success');
            await this.loadUsers();

        } catch (error) {
            console.error('Error updating user status:', error);
            AdminUI.showToast(error.message || 'Error updating user status', 'error');
        } finally {
            AdminUI.hideLoading();
        }
    }

    /**
     * Confirm user deletion
     */
    async confirmDelete(userId, reason) {
        try {
            AdminUI.showLoading();

            await adminAPI.deleteUser(userId, reason);
            
            AdminUI.showToast('User deleted successfully', 'success');
            await this.loadUsers();

        } catch (error) {
            console.error('Error deleting user:', error);
            AdminUI.showToast(error.message || 'Error deleting user', 'error');
        } finally {
            AdminUI.hideLoading();
        }
    }
}

// Add user-specific styles
const userStyles = `
    <style>
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .user-avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            overflow: hidden;
            background-color: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
        }
        
        .user-name {
            font-weight: 500;
            color: var(--gray-900);
        }
        
        .user-username {
            font-size: var(--font-size-sm);
            color: var(--gray-500);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }
        
        .btn-sm {
            padding: 0.375rem 0.5rem;
            font-size: var(--font-size-xs);
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.15s ease-in-out;
        }
        
        .rating {
            color: var(--warning-color);
        }
        
        .user-detail-modal {
            max-width: 600px;
        }
        
        .user-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .user-avatar-large {
            width: 4rem;
            height: 4rem;
            border-radius: 50%;
            overflow: hidden;
            background-color: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            font-size: 1.5rem;
        }
        
        .user-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .detail-section h4 {
            margin-bottom: 1rem;
            color: var(--gray-900);
            font-size: var(--font-size-lg);
        }
        
        .detail-section p {
            margin-bottom: 0.5rem;
            font-size: var(--font-size-sm);
        }
        
        .reports-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .report-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            background-color: var(--gray-50);
            border-radius: var(--radius-sm);
            font-size: var(--font-size-sm);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        @media (max-width: 768px) {
            .user-details-grid,
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
        }
    </style>
`;

document.head.insertAdjacentHTML('beforeend', userStyles);

// Create global instance
window.AdminUsers = new AdminUsers();