// Bazar - Utility Helper Functions

/**
 * Utility helper functions for the Bazar application
 */
const BazarUtils = {
    
    /**
     * Debounce function to limit function calls
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in milliseconds
     * @param {boolean} immediate - Execute immediately
     * @returns {Function} Debounced function
     */
    debounce(func, wait, immediate) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                timeout = null;
                if (!immediate) func.apply(this, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(this, args);
        };
    },
    
    /**
     * Throttle function to limit function calls
     * @param {Function} func - Function to throttle
     * @param {number} limit - Time limit in milliseconds
     * @returns {Function} Throttled function
     */
    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },
    
    /**
     * Format currency
     * @param {number} amount - Amount to format
     * @param {string} currency - Currency code (default: EUR)
     * @returns {string} Formatted currency string
     */
    formatCurrency(amount, currency = 'EUR') {
        return new Intl.NumberFormat('de-AT', {
            style: 'currency',
            currency: currency,
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(amount);
    },
    
    /**
     * Format date
     * @param {Date|string} date - Date to format
     * @param {string} format - Format type ('short', 'long', 'relative')
     * @returns {string} Formatted date string
     */
    formatDate(date, format = 'short') {
        const dateObj = typeof date === 'string' ? new Date(date) : date;
        
        switch (format) {
            case 'relative':
                return this.getRelativeTime(dateObj);
            case 'long':
                return new Intl.DateTimeFormat('de-AT', {
                    dateStyle: 'full',
                    timeStyle: 'short'
                }).format(dateObj);
            case 'short':
            default:
                return new Intl.DateTimeFormat('de-AT', {
                    dateStyle: 'short',
                    timeStyle: 'short'
                }).format(dateObj);
        }
    },
    
    /**
     * Get relative time (e.g., "vor 2 Stunden")
     * @param {Date} date - Date to compare
     * @returns {string} Relative time string
     */
    getRelativeTime(date) {
        const now = new Date();
        const diff = now.getTime() - date.getTime();
        
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        const weeks = Math.floor(days / 7);
        const months = Math.floor(days / 30);
        const years = Math.floor(days / 365);
        
        if (years > 0) return `vor ${years} Jahr${years !== 1 ? 'en' : ''}`;
        if (months > 0) return `vor ${months} Monat${months !== 1 ? 'en' : ''}`;
        if (weeks > 0) return `vor ${weeks} Woche${weeks !== 1 ? 'n' : ''}`;
        if (days > 0) return `vor ${days} Tag${days !== 1 ? 'en' : ''}`;
        if (hours > 0) return `vor ${hours} Stunde${hours !== 1 ? 'n' : ''}`;
        if (minutes > 0) return `vor ${minutes} Minute${minutes !== 1 ? 'n' : ''}`;
        return 'gerade eben';
    },
    
    /**
     * Sanitize HTML content
     * @param {string} html - HTML string to sanitize
     * @returns {string} Sanitized HTML
     */
    sanitizeHTML(html) {
        const temp = document.createElement('div');
        temp.textContent = html;
        return temp.innerHTML;
    },
    
    /**
     * Generate unique ID
     * @returns {string} Unique ID
     */
    generateId() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
    },
    
    /**
     * Store data in localStorage with error handling
     * @param {string} key - Storage key
     * @param {*} value - Value to store
     * @returns {boolean} Success status
     */
    setLocalStorage(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (error) {
            console.error('Failed to save to localStorage:', error);
            return false;
        }
    },
    
    /**
     * Get data from localStorage with error handling
     * @param {string} key - Storage key
     * @param {*} defaultValue - Default value if key not found
     * @returns {*} Retrieved value or default
     */
    getLocalStorage(key, defaultValue = null) {
        try {
            const item = localStorage.getItem(key);
            if (!item) return defaultValue;
            
            // Try to parse as JSON first
            try {
                return JSON.parse(item);
            } catch {
                // If not JSON, return as string
                return item;
            }
        } catch (error) {
            console.error('Failed to read from localStorage:', error);
            return defaultValue;
        }
    },
    
    /**
     * Check if device is mobile
     * @returns {boolean} True if mobile device
     */
    isMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
               window.innerWidth <= 768;
    },
    
    /**
     * Check if device supports touch
     * @returns {boolean} True if touch supported
     */
    isTouchDevice() {
        return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    },
    
    /**
     * Get device viewport dimensions
     * @returns {Object} Viewport dimensions
     */
    getViewport() {
        return {
            width: Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0),
            height: Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0)
        };
    },
    
    /**
     * Scroll to element smoothly
     * @param {string|Element} target - Target element or selector
     * @param {number} offset - Offset from top (default: 0)
     */
    scrollToElement(target, offset = 0) {
        const element = typeof target === 'string' ? document.querySelector(target) : target;
        if (element) {
            const elementTop = element.getBoundingClientRect().top + window.pageYOffset;
            const offsetPosition = elementTop - offset;
            
            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        }
    },
    
    /**
     * Copy text to clipboard
     * @param {string} text - Text to copy
     * @returns {Promise<boolean>} Success status
     */
    async copyToClipboard(text) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                const success = document.execCommand('copy');
                textArea.remove();
                return success;
            }
        } catch (error) {
            console.error('Failed to copy to clipboard:', error);
            return false;
        }
    },
    
    /**
     * Validate email format
     * @param {string} email - Email to validate
     * @returns {boolean} True if valid email
     */
    isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    },
    
    /**
     * Validate phone number format (Austrian)
     * @param {string} phone - Phone number to validate
     * @returns {boolean} True if valid phone number
     */
    isValidPhone(phone) {
        const phoneRegex = /^(\+43|0)[1-9]\d{8,12}$/;
        return phoneRegex.test(phone.replace(/\s+/g, ''));
    },
    
    /**
     * Format file size
     * @param {number} bytes - File size in bytes
     * @returns {string} Formatted file size
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },
    
    /**
     * Create loading spinner element
     * @param {string} size - Size class ('mini', 'small', 'medium', 'large')
     * @returns {HTMLElement} Spinner element
     */
    createSpinner(size = 'medium') {
        const spinner = document.createElement('div');
        spinner.className = `ui active inline loader ${size}`;
        return spinner;
    },
    
    /**
     * Show toast notification
     * @param {string} message - Message to show
     * @param {string} type - Type ('success', 'error', 'warning', 'info')
     * @param {number} duration - Duration in milliseconds (default: 3000)
     */
    showToast(message, type = 'info', duration = 3000) {
        const container = document.getElementById('toast-container') || this.createToastContainer();
        
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="icon ${this.getToastIcon(type)}"></i>
                <span class="toast-message">${this.sanitizeHTML(message)}</span>
                <button class="toast-close" onclick="this.parentElement.parentElement.remove()">
                    <i class="times icon"></i>
                </button>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Auto remove after duration
        setTimeout(() => {
            if (toast.parentNode) {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }
        }, duration);
    },
    
    /**
     * Create toast container if it doesn't exist
     * @returns {HTMLElement} Toast container
     */
    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
        return container;
    },
    
    /**
     * Get icon for toast type
     * @param {string} type - Toast type
     * @returns {string} Icon class
     */
    getToastIcon(type) {
        const icons = {
            success: 'check circle',
            error: 'exclamation circle',
            warning: 'warning circle',
            info: 'info circle'
        };
        return icons[type] || icons.info;
    },
    
    /**
     * Preload images
     * @param {Array<string>} imageUrls - Array of image URLs
     * @returns {Promise<Array>} Promise resolving when all images are loaded
     */
    preloadImages(imageUrls) {
        return Promise.all(imageUrls.map(url => {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(url);
                img.onerror = () => reject(new Error(`Failed to load image: ${url}`));
                img.src = url;
            });
        }));
    }
};

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = BazarUtils;
} else {
    window.BazarUtils = BazarUtils;
}