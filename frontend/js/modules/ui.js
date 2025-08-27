// Bazar - UI Module

/**
 * UI utilities and components for Bazar application
 */
class BazarUI {
    constructor() {
        this.modals = new Map();
        this.currentModal = null;
        this.notifications = [];
        this.loadingStates = new Set();
        
        // Initialize UI
        this.init();
    }
    
    /**
     * Initialize UI functionality
     */
    init() {
        this.setupGlobalListeners();
        this.setupFAB();
        this.setupBottomNavigation();
        this.setupModalHandlers();
        this.setupDropdowns();
        this.setupTooltips();
    }
    
    /**
     * Show error message
     * @param {string} message - Error message to display
     */
    showError(message) {
        if (typeof BazarUtils !== 'undefined' && BazarUtils.showToast) {
            BazarUtils.showToast(message, 'error');
        } else {
            // Fallback to console
            console.error(message);
            // Try to show in UI
            const errorContainer = document.getElementById('error-message');
            if (errorContainer) {
                errorContainer.textContent = message;
                errorContainer.style.display = 'block';
            }
        }
    }
    
    /**
     * Show success message
     * @param {string} message - Success message to display
     */
    showSuccess(message) {
        if (typeof BazarUtils !== 'undefined' && BazarUtils.showToast) {
            BazarUtils.showToast(message, 'success');
        } else {
            console.log(message);
        }
    }
    
    /**
     * Show modal dialog
     * @param {string} content - Modal content
     * @param {string} type - Modal type (info, warning, error)
     */
    showModal(content, type = 'info') {
        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-content ${type}">
                <div class="modal-body">${content}</div>
                <button class="modal-close">OK</button>
            </div>
        `;
        document.body.appendChild(modal);
        
        modal.querySelector('.modal-close').addEventListener('click', () => {
            document.body.removeChild(modal);
        });
    }
    
    /**
     * Setup global event listeners
     */
    setupGlobalListeners() {
        // Handle escape key for modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.currentModal) {
                this.closeModal();
            }
        });
        
        // Handle loading screen fade out
        window.addEventListener('load', () => {
            this.hideLoadingScreen();
        });
        
        // Handle network status changes
        window.addEventListener('online', () => {
            BazarUtils.showToast('Internetverbindung wiederhergestellt', 'success');
        });
        
        window.addEventListener('offline', () => {
            BazarUtils.showToast('Keine Internetverbindung', 'warning', 5000);
        });
    }
    
    /**
     * Setup Floating Action Button
     */
    setupFAB() {
        const fab = document.getElementById('fab');
        if (fab) {
            fab.addEventListener('click', () => {
                if (auth.isAuthenticated()) {
                    router.navigate('/create');
                } else {
                    this.showLoginPrompt();
                }
            });
        }
    }
    
    /**
     * Setup bottom navigation
     */
    setupBottomNavigation() {
        document.addEventListener('click', (e) => {
            const navItem = e.target.closest('.nav-item');
            if (navItem) {
                const page = navItem.getAttribute('data-page');
                this.navigateToPage(page);
            }
        });
    }
    
    /**
     * Navigate to page based on bottom nav selection
     * @param {string} page - Page identifier
     */
    navigateToPage(page) {
        const routes = {
            'home': '/',
            'search': '/search',
            'create': '/create',
            'messages': '/messages',
            'profile': '/profile'
        };
        
        const route = routes[page];
        if (route) {
            if ((page === 'create' || page === 'messages' || page === 'profile') && !auth.isAuthenticated()) {
                this.showLoginPrompt();
                return;
            }
            
            router.navigate(route);
        }
    }
    
    /**
     * Setup modal handlers
     */
    setupModalHandlers() {
        // Modal close buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('.modal-close') || e.target.closest('.modal-close')) {
                this.closeModal();
            }
        });
        
        // Modal backdrop clicks
        document.addEventListener('click', (e) => {
            if (e.target.matches('.ui.modal.active')) {
                this.closeModal();
            }
        });
    }
    
    /**
     * Setup dropdowns
     */
    setupDropdowns() {
        // Initialize Fomantic UI dropdowns
        if (typeof $ !== 'undefined') {
            $('.ui.dropdown').dropdown();
        }
    }
    
    /**
     * Setup tooltips
     */
    setupTooltips() {
        // Initialize Fomantic UI tooltips
        if (typeof $ !== 'undefined') {
            $('[data-tooltip]').popup();
        }
    }
    
    /**
     * Show loading screen
     */
    showLoadingScreen() {
        const loadingScreen = document.getElementById('loading-screen');
        if (loadingScreen) {
            loadingScreen.classList.remove('fade-out');
            loadingScreen.style.display = 'flex';
        }
    }
    
    /**
     * Hide loading screen
     */
    hideLoadingScreen() {
        const loadingScreen = document.getElementById('loading-screen');
        if (loadingScreen) {
            loadingScreen.classList.add('fade-out');
            setTimeout(() => {
                loadingScreen.style.display = 'none';
            }, 300);
        }
    }
    
    /**
     * Show modal
     * @param {string} content - Modal content HTML
     * @param {Object} options - Modal options
     * @returns {string} Modal ID
     */
    showModal(content, options = {}) {
        const modalId = options.id || BazarUtils.generateId();
        const modal = this.createModal(modalId, content, options);
        
        document.body.appendChild(modal);
        this.currentModal = modalId;
        
        // Show modal with animation
        setTimeout(() => {
            modal.classList.add('active');
            if (options.onShow) {
                options.onShow(modal);
            }
        }, 10);
        
        this.modals.set(modalId, { element: modal, options });
        
        return modalId;
    }
    
    /**
     * Create modal element
     * @param {string} id - Modal ID
     * @param {string} content - Modal content
     * @param {Object} options - Modal options
     * @returns {HTMLElement} Modal element
     */
    createModal(id, content, options = {}) {
        const modal = document.createElement('div');
        modal.className = `ui modal ${options.size || 'small'}`;
        modal.id = id;
        
        const closeButton = options.closable !== false ? `
            <button class="ui button icon modal-close" style="position: absolute; top: 1rem; right: 1rem; z-index: 1;">
                <i class="times icon"></i>
            </button>
        ` : '';
        
        modal.innerHTML = `
            ${closeButton}
            <div class="content">
                ${content}
            </div>
        `;
        
        return modal;
    }
    
    /**
     * Close current modal
     * @param {string} modalId - Specific modal ID to close (optional)
     */
    closeModal(modalId = null) {
        const id = modalId || this.currentModal;
        if (!id) return;
        
        const modalData = this.modals.get(id);
        if (!modalData) return;
        
        const modal = modalData.element;
        const options = modalData.options;
        
        // Hide modal with animation
        modal.classList.remove('active');
        
        setTimeout(() => {
            if (modal.parentNode) {
                modal.parentNode.removeChild(modal);
            }
            this.modals.delete(id);
            
            if (this.currentModal === id) {
                this.currentModal = null;
            }
            
            if (options.onClose) {
                options.onClose();
            }
        }, 300);
    }
    
    /**
     * Show confirmation dialog
     * @param {string} message - Confirmation message
     * @param {Object} options - Dialog options
     * @returns {Promise<boolean>} User confirmation
     */
    showConfirmDialog(message, options = {}) {
        return new Promise((resolve) => {
            const content = `
                <div class="ui segment basic" style="text-align: center;">
                    <div style="font-size: 2rem; margin-bottom: 1rem;">
                        ${options.icon || '❓'}
                    </div>
                    <h3>${options.title || 'Bestätigung'}</h3>
                    <p>${BazarUtils.sanitizeHTML(message)}</p>
                    <div class="ui buttons" style="margin-top: 2rem;">
                        <button class="ui button" onclick="window.ui.closeModal(); resolve(false);">
                            ${options.cancelText || 'Abbrechen'}
                        </button>
                        <div class="or" data-text="oder"></div>
                        <button class="ui primary button" onclick="window.ui.closeModal(); resolve(true);">
                            ${options.confirmText || 'Bestätigen'}
                        </button>
                    </div>
                </div>
            `;
            
            // Make resolve available globally for button clicks
            window._confirmResolve = resolve;
            
            this.showModal(content, {
                size: 'mini',
                closable: false,
                onClose: () => {
                    if (window._confirmResolve) {
                        window._confirmResolve(false);
                        delete window._confirmResolve;
                    }
                }
            });
        });
    }
    
    /**
     * Show login prompt for unauthenticated users
     */
    showLoginPrompt() {
        const content = `
            <div class="ui segment basic" style="text-align: center;">
                <div style="font-size: 2rem; margin-bottom: 1rem;">🔐</div>
                <h3>Anmeldung erforderlich</h3>
                <p>Sie müssen sich anmelden, um diese Funktion zu nutzen.</p>
                <div class="ui buttons" style="margin-top: 2rem;">
                    <button class="ui button" onclick="window.ui.closeModal();">
                        Abbrechen
                    </button>
                    <div class="or" data-text="oder"></div>
                    <button class="ui primary button" onclick="window.ui.closeModal(); router.navigate('/login');">
                        Anmelden
                    </button>
                </div>
            </div>
        `;
        
        this.showModal(content, { size: 'mini' });
    }
    
    /**
     * Show image preview modal
     * @param {string} imageUrl - Image URL
     * @param {string} title - Image title
     */
    showImagePreview(imageUrl, title = '') {
        const content = `
            <div style="text-align: center;">
                ${title ? `<h3>${BazarUtils.sanitizeHTML(title)}</h3>` : ''}
                <img src="${imageUrl}" alt="${BazarUtils.sanitizeHTML(title)}" 
                     style="max-width: 100%; max-height: 80vh; object-fit: contain;">
            </div>
        `;
        
        this.showModal(content, { size: 'large' });
    }
    
    /**
     * Show loading overlay on element
     * @param {HTMLElement|string} element - Element or selector
     * @param {string} message - Loading message
     */
    showLoading(element, message = 'Lädt...') {
        const el = typeof element === 'string' ? document.querySelector(element) : element;
        if (!el) return;
        
        const loadingId = BazarUtils.generateId();
        this.loadingStates.add(loadingId);
        
        const overlay = document.createElement('div');
        overlay.className = 'ui active dimmer';
        overlay.setAttribute('data-loading-id', loadingId);
        overlay.innerHTML = `
            <div class="ui text loader">${BazarUtils.sanitizeHTML(message)}</div>
        `;
        
        el.style.position = 'relative';
        el.appendChild(overlay);
        
        return loadingId;
    }
    
    /**
     * Hide loading overlay
     * @param {HTMLElement|string} element - Element or selector
     * @param {string} loadingId - Loading ID (optional)
     */
    hideLoading(element, loadingId = null) {
        const el = typeof element === 'string' ? document.querySelector(element) : element;
        if (!el) return;
        
        const selector = loadingId ? `[data-loading-id="${loadingId}"]` : '.ui.dimmer';
        const overlay = el.querySelector(selector);
        
        if (overlay) {
            overlay.remove();
            if (loadingId) {
                this.loadingStates.delete(loadingId);
            }
        }
    }
    
    /**
     * Create infinite scroll for element
     * @param {HTMLElement|string} element - Container element
     * @param {Function} loadMore - Function to load more content
     * @param {Object} options - Scroll options
     */
    setupInfiniteScroll(element, loadMore, options = {}) {
        const el = typeof element === 'string' ? document.querySelector(element) : element;
        if (!el) return;
        
        const threshold = options.threshold || 100;
        let isLoading = false;
        
        const scrollHandler = BazarUtils.throttle(async () => {
            if (isLoading) return;
            
            const scrollTop = el.scrollTop;
            const scrollHeight = el.scrollHeight;
            const clientHeight = el.clientHeight;
            
            if (scrollTop + clientHeight >= scrollHeight - threshold) {
                isLoading = true;
                
                try {
                    const hasMore = await loadMore();
                    if (!hasMore && options.onComplete) {
                        options.onComplete();
                        el.removeEventListener('scroll', scrollHandler);
                    }
                } catch (error) {
                    console.error('Infinite scroll error:', error);
                } finally {
                    isLoading = false;
                }
            }
        }, 250);
        
        el.addEventListener('scroll', scrollHandler);
        
        return scrollHandler; // Return for cleanup if needed
    }
    
    /**
     * Setup drag and drop for element
     * @param {HTMLElement|string} element - Drop zone element
     * @param {Function} onDrop - Drop handler function
     * @param {Object} options - Drop options
     */
    setupDragDrop(element, onDrop, options = {}) {
        const el = typeof element === 'string' ? document.querySelector(element) : element;
        if (!el) return;
        
        const allowedTypes = options.accept || ['image/*'];
        let dragCounter = 0;
        
        const preventDefault = (e) => {
            e.preventDefault();
            e.stopPropagation();
        };
        
        const handleDragEnter = (e) => {
            preventDefault(e);
            dragCounter++;
            el.classList.add('drag-over');
        };
        
        const handleDragLeave = (e) => {
            preventDefault(e);
            dragCounter--;
            if (dragCounter === 0) {
                el.classList.remove('drag-over');
            }
        };
        
        const handleDrop = async (e) => {
            preventDefault(e);
            dragCounter = 0;
            el.classList.remove('drag-over');
            
            const files = Array.from(e.dataTransfer.files);
            const validFiles = files.filter(file => {
                return allowedTypes.some(type => {
                    if (type.endsWith('/*')) {
                        return file.type.startsWith(type.slice(0, -1));
                    }
                    return file.type === type;
                });
            });
            
            if (validFiles.length > 0) {
                await onDrop(validFiles, e);
            } else if (options.onInvalidType) {
                options.onInvalidType(files);
            }
        };
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            el.addEventListener(eventName, preventDefault);
        });
        
        el.addEventListener('dragenter', handleDragEnter);
        el.addEventListener('dragover', handleDragEnter);
        el.addEventListener('dragleave', handleDragLeave);
        el.addEventListener('drop', handleDrop);
        
        // Add visual indicator
        el.classList.add('drop-zone');
    }
    
    /**
     * Create image carousel
     * @param {Array} images - Array of image URLs
     * @param {Object} options - Carousel options
     * @returns {HTMLElement} Carousel element
     */
    createImageCarousel(images, options = {}) {
        const carousel = document.createElement('div');
        carousel.className = 'ui image carousel';
        
        if (images.length === 0) {
            carousel.innerHTML = '<div class="ui placeholder image"></div>';
            return carousel;
        }
        
        if (images.length === 1) {
            carousel.innerHTML = `<img src="${images[0]}" alt="Image" class="ui fluid image">`;
            return carousel;
        }
        
        const slidesHTML = images.map((img, index) => `
            <div class="carousel-slide ${index === 0 ? 'active' : ''}">
                <img src="${img}" alt="Image ${index + 1}" class="ui fluid image">
            </div>
        `).join('');
        
        const indicatorsHTML = images.map((_, index) => `
            <button class="carousel-indicator ${index === 0 ? 'active' : ''}" data-slide="${index}"></button>
        `).join('');
        
        carousel.innerHTML = `
            <div class="carousel-slides">
                ${slidesHTML}
            </div>
            <div class="carousel-controls">
                <button class="carousel-prev"><i class="chevron left icon"></i></button>
                <button class="carousel-next"><i class="chevron right icon"></i></button>
            </div>
            <div class="carousel-indicators">
                ${indicatorsHTML}
            </div>
        `;
        
        // Add carousel functionality
        this.setupCarousel(carousel);
        
        return carousel;
    }
    
    /**
     * Setup carousel functionality
     * @param {HTMLElement} carousel - Carousel element
     */
    setupCarousel(carousel) {
        let currentSlide = 0;
        const slides = carousel.querySelectorAll('.carousel-slide');
        const indicators = carousel.querySelectorAll('.carousel-indicator');
        const prevBtn = carousel.querySelector('.carousel-prev');
        const nextBtn = carousel.querySelector('.carousel-next');
        
        const showSlide = (index) => {
            slides.forEach((slide, i) => {
                slide.classList.toggle('active', i === index);
            });
            indicators.forEach((indicator, i) => {
                indicator.classList.toggle('active', i === index);
            });
            currentSlide = index;
        };
        
        const nextSlide = () => {
            const next = (currentSlide + 1) % slides.length;
            showSlide(next);
        };
        
        const prevSlide = () => {
            const prev = (currentSlide - 1 + slides.length) % slides.length;
            showSlide(prev);
        };
        
        // Event listeners
        nextBtn?.addEventListener('click', nextSlide);
        prevBtn?.addEventListener('click', prevSlide);
        
        indicators.forEach((indicator, index) => {
            indicator.addEventListener('click', () => showSlide(index));
        });
        
        // Auto-play if enabled
        if (carousel.hasAttribute('data-autoplay')) {
            const interval = parseInt(carousel.getAttribute('data-autoplay')) || 5000;
            setInterval(nextSlide, interval);
        }
    }
    
    /**
     * Smooth scroll to element
     * @param {HTMLElement|string} element - Target element
     * @param {number} offset - Offset from top
     * @param {number} duration - Animation duration
     */
    scrollTo(element, offset = 0, duration = 800) {
        const el = typeof element === 'string' ? document.querySelector(element) : element;
        if (!el) return;
        
        const targetTop = el.getBoundingClientRect().top + window.pageYOffset - offset;
        const startTop = window.pageYOffset;
        const distance = targetTop - startTop;
        let startTime = null;
        
        const animateScroll = (currentTime) => {
            if (startTime === null) startTime = currentTime;
            const timeElapsed = currentTime - startTime;
            const progress = Math.min(timeElapsed / duration, 1);
            
            // Easing function
            const easeInOutQuad = progress < 0.5 ? 
                2 * progress * progress : 
                -1 + (4 - 2 * progress) * progress;
            
            window.scrollTo(0, startTop + distance * easeInOutQuad);
            
            if (timeElapsed < duration) {
                requestAnimationFrame(animateScroll);
            }
        };
        
        requestAnimationFrame(animateScroll);
    }
}

// Create global UI instance
const ui = new BazarUI();

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { BazarUI, ui };
} else {
    window.BazarUI = BazarUI;
    window.ui = ui;
}