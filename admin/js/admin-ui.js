/**
 * Admin UI Utilities
 * Common UI functions and helpers
 */

class AdminUI {
    constructor() {
        this.toastContainer = null;
        this.init();
    }

    /**
     * Initialize UI utilities
     */
    init() {
        this.toastContainer = document.getElementById('toast-container');
        this.bindGlobalEvents();
    }

    /**
     * Bind global UI events
     */
    bindGlobalEvents() {
        // Close modals when clicking overlay
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                this.closeModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        });

        // Close notifications dropdown when clicking outside
        document.addEventListener('click', (e) => {
            const notificationBtn = document.getElementById('notification-btn');
            const notificationDropdown = document.getElementById('notification-dropdown');
            
            if (notificationBtn && notificationDropdown && 
                !notificationBtn.contains(e.target) && 
                !notificationDropdown.contains(e.target)) {
                notificationDropdown.classList.remove('show');
            }
        });

        // Sidebar toggle for mobile
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebar = document.querySelector('.sidebar');
        
        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                const sidebar = document.querySelector('.sidebar');
                const sidebarToggle = document.getElementById('sidebar-toggle');
                
                if (sidebar && sidebar.classList.contains('open') &&
                    !sidebar.contains(e.target) && 
                    !sidebarToggle.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }

    /**
     * Show loading spinner
     */
    static showLoading() {
        const spinner = document.getElementById('loading-spinner');
        if (spinner) {
            spinner.style.display = 'flex';
        }
    }

    /**
     * Hide loading spinner
     */
    static hideLoading() {
        const spinner = document.getElementById('loading-spinner');
        if (spinner) {
            spinner.style.display = 'none';
        }
    }

    /**
     * Show toast notification
     */
    static showToast(message, type = 'info', duration = 5000) {
        const toastContainer = document.getElementById('toast-container');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };

        toast.innerHTML = `
            <div class="toast-icon">
                <i class="${icons[type] || icons.info}"></i>
            </div>
            <div class="toast-content">
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close">
                <i class="fas fa-times"></i>
            </button>
        `;

        // Add close event
        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => {
            AdminUI.removeToast(toast);
        });

        toastContainer.appendChild(toast);

        // Auto-remove after duration
        if (duration > 0) {
            setTimeout(() => {
                AdminUI.removeToast(toast);
            }, duration);
        }
    }

    /**
     * Remove toast notification
     */
    static removeToast(toast) {
        if (toast && toast.parentNode) {
            toast.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }
    }

    /**
     * Show modal
     */
    static showModal(title, body, footer = null) {
        const modalOverlay = document.getElementById('modal-overlay');
        const modalTitle = document.getElementById('modal-title');
        const modalBody = document.getElementById('modal-body');
        const modalFooter = document.getElementById('modal-footer');

        if (!modalOverlay || !modalTitle || !modalBody) return;

        modalTitle.textContent = title;
        modalBody.innerHTML = body;
        
        if (footer) {
            modalFooter.innerHTML = footer;
            modalFooter.style.display = 'flex';
        } else {
            modalFooter.style.display = 'none';
        }

        modalOverlay.classList.add('show');

        // Bind close button
        const closeBtn = modalOverlay.querySelector('.modal-close');
        if (closeBtn) {
            closeBtn.onclick = () => AdminUI.closeModal();
        }
    }

    /**
     * Close modal
     */
    static closeModal() {
        const modalOverlay = document.getElementById('modal-overlay');
        if (modalOverlay) {
            modalOverlay.classList.remove('show');
        }
    }

    /**
     * Show confirmation dialog
     */
    static showConfirm(message, onConfirm, onCancel = null) {
        const footer = `
            <button type="button" class="btn-secondary" onclick="AdminUI.closeModal(); ${onCancel ? onCancel + '()' : ''}">
                Cancel
            </button>
            <button type="button" class="btn-danger" onclick="AdminUI.closeModal(); ${onConfirm}()">
                Confirm
            </button>
        `;

        AdminUI.showModal('Confirm Action', `<p>${message}</p>`, footer);
    }

    /**
     * Format date for display
     */
    static formatDate(dateString, includeTime = true) {
        if (!dateString) return '-';
        
        const date = new Date(dateString);
        const options = {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        };

        if (includeTime) {
            options.hour = '2-digit';
            options.minute = '2-digit';
        }

        return date.toLocaleDateString('en-US', options);
    }

    /**
     * Format number with thousand separators
     */
    static formatNumber(number) {
        if (number === null || number === undefined) return '0';
        return Number(number).toLocaleString();
    }

    /**
     * Format currency
     */
    static formatCurrency(amount, currency = 'EUR') {
        if (amount === null || amount === undefined) return '€0';
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency
        }).format(amount);
    }

    /**
     * Format file size
     */
    static formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    /**
     * Get status badge HTML
     */
    static getStatusBadge(status) {
        const badges = {
            active: '<span class="badge badge-success">Active</span>',
            inactive: '<span class="badge badge-secondary">Inactive</span>',
            suspended: '<span class="badge badge-warning">Suspended</span>',
            deleted: '<span class="badge badge-danger">Deleted</span>',
            pending: '<span class="badge badge-warning">Pending</span>',
            approved: '<span class="badge badge-success">Approved</span>',
            rejected: '<span class="badge badge-danger">Rejected</span>',
            draft: '<span class="badge badge-secondary">Draft</span>',
            sold: '<span class="badge badge-info">Sold</span>',
            archived: '<span class="badge badge-dark">Archived</span>',
            resolved: '<span class="badge badge-success">Resolved</span>',
            investigating: '<span class="badge badge-info">Investigating</span>',
            dismissed: '<span class="badge badge-secondary">Dismissed</span>'
        };

        return badges[status] || `<span class="badge badge-secondary">${status}</span>`;
    }

    /**
     * Create data table
     */
    static createDataTable(containerId, columns, data, options = {}) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const table = document.createElement('table');
        table.className = 'data-table';

        // Create header
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');

        // Add select all checkbox if enabled
        if (options.selectable) {
            const selectCell = document.createElement('th');
            selectCell.innerHTML = '<input type="checkbox" class="select-all-checkbox">';
            headerRow.appendChild(selectCell);
        }

        columns.forEach(column => {
            const th = document.createElement('th');
            th.textContent = column.title;
            if (column.sortable) {
                th.style.cursor = 'pointer';
                th.onclick = () => {
                    if (options.onSort) {
                        options.onSort(column.key);
                    }
                };
            }
            headerRow.appendChild(th);
        });

        thead.appendChild(headerRow);
        table.appendChild(thead);

        // Create body
        const tbody = document.createElement('tbody');
        
        data.forEach(row => {
            const tr = document.createElement('tr');

            // Add select checkbox if enabled
            if (options.selectable) {
                const selectCell = document.createElement('td');
                selectCell.innerHTML = `<input type="checkbox" class="row-checkbox" value="${row.id || row.key}">`;
                tr.appendChild(selectCell);
            }

            columns.forEach(column => {
                const td = document.createElement('td');
                
                if (column.render) {
                    td.innerHTML = column.render(row[column.key], row);
                } else {
                    td.textContent = row[column.key] || '-';
                }
                
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        container.innerHTML = '';
        container.appendChild(table);

        // Bind select all checkbox
        if (options.selectable) {
            const selectAllCheckbox = table.querySelector('.select-all-checkbox');
            const rowCheckboxes = table.querySelectorAll('.row-checkbox');

            selectAllCheckbox.addEventListener('change', (e) => {
                rowCheckboxes.forEach(checkbox => {
                    checkbox.checked = e.target.checked;
                });
            });

            rowCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    const checkedCount = Array.from(rowCheckboxes).filter(cb => cb.checked).length;
                    selectAllCheckbox.checked = checkedCount === rowCheckboxes.length;
                    selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < rowCheckboxes.length;
                });
            });
        }
    }

    /**
     * Create pagination controls
     */
    static createPagination(containerId, currentPage, totalPages, onPageChange) {
        const container = document.getElementById(containerId);
        if (!container || totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        const pagination = document.createElement('div');
        pagination.className = 'pagination';

        // Previous button
        const prevBtn = document.createElement('button');
        prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
        prevBtn.disabled = currentPage <= 1;
        prevBtn.onclick = () => {
            if (currentPage > 1) onPageChange(currentPage - 1);
        };
        pagination.appendChild(prevBtn);

        // Page numbers
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);

        if (startPage > 1) {
            const firstBtn = document.createElement('button');
            firstBtn.textContent = '1';
            firstBtn.onclick = () => onPageChange(1);
            pagination.appendChild(firstBtn);

            if (startPage > 2) {
                const ellipsis = document.createElement('span');
                ellipsis.textContent = '...';
                ellipsis.className = 'pagination-ellipsis';
                pagination.appendChild(ellipsis);
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const pageBtn = document.createElement('button');
            pageBtn.textContent = i;
            pageBtn.className = i === currentPage ? 'active' : '';
            pageBtn.onclick = () => onPageChange(i);
            pagination.appendChild(pageBtn);
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const ellipsis = document.createElement('span');
                ellipsis.textContent = '...';
                ellipsis.className = 'pagination-ellipsis';
                pagination.appendChild(ellipsis);
            }

            const lastBtn = document.createElement('button');
            lastBtn.textContent = totalPages;
            lastBtn.onclick = () => onPageChange(totalPages);
            pagination.appendChild(lastBtn);
        }

        // Next button
        const nextBtn = document.createElement('button');
        nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        nextBtn.disabled = currentPage >= totalPages;
        nextBtn.onclick = () => {
            if (currentPage < totalPages) onPageChange(currentPage + 1);
        };
        pagination.appendChild(nextBtn);

        container.innerHTML = '';
        container.appendChild(pagination);
    }

    /**
     * Debounce function
     */
    static debounce(func, delay) {
        let timeoutId;
        return function (...args) {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => func.apply(this, args), delay);
        };
    }

    /**
     * Validate form data
     */
    static validateForm(formElement, rules) {
        const errors = {};
        const formData = new FormData(formElement);

        for (const [field, ruleString] of Object.entries(rules)) {
            const value = formData.get(field);
            const fieldRules = ruleString.split('|');

            for (const rule of fieldRules) {
                if (rule === 'required' && (!value || value.trim() === '')) {
                    errors[field] = errors[field] || [];
                    errors[field].push(`${field} is required`);
                    break;
                }

                if (rule === 'email' && value && !AdminUI.isValidEmail(value)) {
                    errors[field] = errors[field] || [];
                    errors[field].push(`${field} must be a valid email`);
                }

                if (rule.startsWith('min:') && value) {
                    const min = parseInt(rule.split(':')[1]);
                    if (value.length < min) {
                        errors[field] = errors[field] || [];
                        errors[field].push(`${field} must be at least ${min} characters`);
                    }
                }

                if (rule.startsWith('max:') && value) {
                    const max = parseInt(rule.split(':')[1]);
                    if (value.length > max) {
                        errors[field] = errors[field] || [];
                        errors[field].push(`${field} must not exceed ${max} characters`);
                    }
                }
            }
        }

        return Object.keys(errors).length === 0 ? null : errors;
    }

    /**
     * Validate email format
     */
    static isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    /**
     * Get selected table rows
     */
    static getSelectedRows(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return [];

        const checkboxes = table.querySelectorAll('.row-checkbox:checked');
        return Array.from(checkboxes).map(checkbox => checkbox.value);
    }

    /**
     * Clear table selection
     */
    static clearTableSelection(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;

        const checkboxes = table.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
            checkbox.indeterminate = false;
        });
    }
}

// Add CSS for badges
const badgeStyles = `
    <style>
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 500;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
        }
        .badge-success { background-color: var(--success-color); color: white; }
        .badge-warning { background-color: var(--warning-color); color: white; }
        .badge-danger { background-color: var(--danger-color); color: white; }
        .badge-info { background-color: var(--info-color); color: white; }
        .badge-secondary { background-color: var(--gray-500); color: white; }
        .badge-dark { background-color: var(--gray-800); color: white; }
        
        .pagination-ellipsis {
            padding: 0.5rem;
            color: var(--gray-500);
        }
        
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    </style>
`;

document.head.insertAdjacentHTML('beforeend', badgeStyles);

// Create global instance
window.AdminUI = new AdminUI();