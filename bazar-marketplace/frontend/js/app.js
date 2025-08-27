/**
 * Bazar Marketplace - Main Application JavaScript
 * This file handles the core functionality of the frontend application
 */

class BazarApp {
    constructor() {
        this.apiBaseUrl = '/api';
        this.currentUser = null;
        this.favorites = new Set();
        this.init();
    }

    /**
     * Initialize the application
     */
    async init() {
        this.bindEventListeners();
        this.setupLoadingSpinner();
        this.setupCookieBanner();
        this.setupBackToTop();
        this.setupToasts();
        
        // Load initial data
        await this.loadCategories();
        await this.loadFeaturedArticles();
        await this.loadLatestArticles();
        
        // Hide loading spinner
        this.hideLoadingSpinner();
        
        console.log('Bazar Marketplace initialized successfully');
    }

    /**
     * Bind event listeners
     */
    bindEventListeners() {
        // Navigation
        document.addEventListener('click', this.handleNavigation.bind(this));
        
        // Search form
        const searchForm = document.getElementById('quick-search');
        if (searchForm) {
            searchForm.addEventListener('submit', this.handleQuickSearch.bind(this));
        }

        // Favorite buttons
        document.addEventListener('click', this.handleFavoriteClick.bind(this));

        // Newsletter form
        const newsletterForm = document.querySelector('.newsletter-form');
        if (newsletterForm) {
            newsletterForm.addEventListener('submit', this.handleNewsletterSubscription.bind(this));
        }

        // Scroll events
        window.addEventListener('scroll', this.handleScroll.bind(this));

        // Cookie banner
        document.getElementById('accept-cookies')?.addEventListener('click', () => this.handleCookieConsent(true));
        document.getElementById('decline-cookies')?.addEventListener('click', () => this.handleCookieConsent(false));
    }

    /**
     * Handle navigation clicks
     */
    handleNavigation(event) {
        const link = event.target.closest('a[href]');
        if (!link || link.getAttribute('href').startsWith('http') || link.getAttribute('href').startsWith('#')) {
            return;
        }

        event.preventDefault();
        const href = link.getAttribute('href');
        this.navigateTo(href);
    }

    /**
     * Navigate to a route
     */
    navigateTo(route) {
        // For now, just show a toast message
        // This would be replaced with proper routing logic
        this.showToast(`Navigation to ${route} - Coming Soon!`, 'info');
    }

    /**
     * Handle quick search form submission
     */
    handleQuickSearch(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const query = formData.get('query') || event.target.querySelector('input[type="text"]').value;
        
        if (!query.trim()) {
            this.showToast('Please enter a search term', 'warning');
            return;
        }

        // For now, show a toast message
        this.showToast(`Searching for "${query}" - Coming Soon!`, 'info');
    }

    /**
     * Handle favorite button clicks
     */
    async handleFavoriteClick(event) {
        if (!event.target.closest('.favorite-btn')) return;
        
        event.preventDefault();
        event.stopPropagation();

        const button = event.target.closest('.favorite-btn');
        const articleId = button.dataset.articleId;

        if (!articleId) return;

        // Check if user is logged in (mock check)
        if (!this.currentUser) {
            this.showToast('Please login to add favorites', 'warning');
            return;
        }

        try {
            if (this.favorites.has(articleId)) {
                await this.removeFavorite(articleId);
            } else {
                await this.addFavorite(articleId);
            }
        } catch (error) {
            console.error('Error handling favorite:', error);
            this.showToast('Error updating favorite', 'danger');
        }
    }

    /**
     * Add article to favorites
     */
    async addFavorite(articleId) {
        // Mock API call
        await this.delay(500);
        
        this.favorites.add(articleId);
        this.updateFavoriteButton(articleId, true);
        this.showToast('Added to favorites', 'success');
    }

    /**
     * Remove article from favorites
     */
    async removeFavorite(articleId) {
        // Mock API call
        await this.delay(500);
        
        this.favorites.delete(articleId);
        this.updateFavoriteButton(articleId, false);
        this.showToast('Removed from favorites', 'info');
    }

    /**
     * Update favorite button state
     */
    updateFavoriteButton(articleId, isFavorite) {
        const button = document.querySelector(`[data-article-id="${articleId}"]`);
        if (!button) return;

        const icon = button.querySelector('i');
        if (isFavorite) {
            button.classList.add('active');
            icon.classList.remove('far');
            icon.classList.add('fas');
        } else {
            button.classList.remove('active');
            icon.classList.remove('fas');
            icon.classList.add('far');
        }
    }

    /**
     * Handle newsletter subscription
     */
    async handleNewsletterSubscription(event) {
        event.preventDefault();
        
        const email = event.target.querySelector('input[type="email"]').value;
        
        if (!this.isValidEmail(email)) {
            this.showToast('Please enter a valid email address', 'warning');
            return;
        }

        try {
            // Mock API call
            await this.delay(1000);
            
            this.showToast('Thank you for subscribing!', 'success');
            event.target.reset();
        } catch (error) {
            this.showToast('Error subscribing to newsletter', 'danger');
        }
    }

    /**
     * Handle scroll events
     */
    handleScroll() {
        const backToTopBtn = document.getElementById('back-to-top');
        if (window.pageYOffset > 300) {
            backToTopBtn.classList.remove('d-none');
        } else {
            backToTopBtn.classList.add('d-none');
        }
    }

    /**
     * Load categories
     */
    async loadCategories() {
        try {
            // Mock data - replace with API call
            const categories = [
                { id: 1, name: 'Electronics', icon: 'fas fa-laptop', slug: 'electronics', count: 1234 },
                { id: 2, name: 'Vehicles', icon: 'fas fa-car', slug: 'vehicles', count: 567 },
                { id: 3, name: 'Fashion', icon: 'fas fa-tshirt', slug: 'fashion', count: 890 },
                { id: 4, name: 'Home & Garden', icon: 'fas fa-home', slug: 'home-garden', count: 456 },
                { id: 5, name: 'Sports', icon: 'fas fa-football-ball', slug: 'sports', count: 234 },
                { id: 6, name: 'Books & Media', icon: 'fas fa-book', slug: 'books-media', count: 123 },
                { id: 7, name: 'Services', icon: 'fas fa-tools', slug: 'services', count: 345 },
                { id: 8, name: 'Real Estate', icon: 'fas fa-building', slug: 'real-estate', count: 78 }
            ];

            const grid = document.getElementById('categories-grid');
            if (!grid) return;

            grid.innerHTML = categories.map(category => `
                <div class="col-md-3 col-sm-6">
                    <a href="/category/${category.slug}" class="category-card">
                        <div class="card shadow-hover">
                            <div class="card-body text-center">
                                <i class="${category.icon} card-icon"></i>
                                <h5 class="card-title">${category.name}</h5>
                                <p class="card-text text-muted">${category.count} items</p>
                            </div>
                        </div>
                    </a>
                </div>
            `).join('');

        } catch (error) {
            console.error('Error loading categories:', error);
        }
    }

    /**
     * Load featured articles
     */
    async loadFeaturedArticles() {
        try {
            // Mock data - replace with API call
            const articles = [
                {
                    id: '1',
                    title: 'MacBook Pro 13" 2023',
                    price: '1299.00',
                    currency: 'EUR',
                    location: 'Paris, France',
                    image: 'https://via.placeholder.com/300x200?text=MacBook',
                    isFeatured: true
                },
                {
                    id: '2',
                    title: 'Vintage Bicycle',
                    price: '250.00',
                    currency: 'EUR',
                    location: 'Lyon, France',
                    image: 'https://via.placeholder.com/300x200?text=Bicycle',
                    isFeatured: true
                },
                {
                    id: '3',
                    title: 'Designer Sofa',
                    price: '800.00',
                    currency: 'EUR',
                    location: 'Marseille, France',
                    image: 'https://via.placeholder.com/300x200?text=Sofa',
                    isFeatured: true
                },
                {
                    id: '4',
                    title: 'Gaming Setup',
                    price: '1500.00',
                    currency: 'EUR',
                    location: 'Nice, France',
                    image: 'https://via.placeholder.com/300x200?text=Gaming',
                    isFeatured: true
                }
            ];

            this.renderArticles(articles, 'featured-articles');

        } catch (error) {
            console.error('Error loading featured articles:', error);
        }
    }

    /**
     * Load latest articles
     */
    async loadLatestArticles() {
        try {
            // Mock data - replace with API call
            const articles = [
                {
                    id: '5',
                    title: 'iPhone 15 Pro',
                    price: '999.00',
                    currency: 'EUR',
                    location: 'Toulouse, France',
                    image: 'https://via.placeholder.com/300x200?text=iPhone',
                    isFeatured: false
                },
                {
                    id: '6',
                    title: 'Dining Table Set',
                    price: '350.00',
                    currency: 'EUR',
                    location: 'Strasbourg, France',
                    image: 'https://via.placeholder.com/300x200?text=Table',
                    isFeatured: false
                },
                {
                    id: '7',
                    title: 'Mountain Bike',
                    price: '450.00',
                    currency: 'EUR',
                    location: 'Bordeaux, France',
                    image: 'https://via.placeholder.com/300x200?text=Bike',
                    isFeatured: false
                },
                {
                    id: '8',
                    title: 'Leather Jacket',
                    price: '120.00',
                    currency: 'EUR',
                    location: 'Lille, France',
                    image: 'https://via.placeholder.com/300x200?text=Jacket',
                    isFeatured: false
                }
            ];

            this.renderArticles(articles, 'latest-articles');

        } catch (error) {
            console.error('Error loading latest articles:', error);
        }
    }

    /**
     * Render articles to a container
     */
    renderArticles(articles, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        container.innerHTML = articles.map(article => `
            <div class="col-lg-3 col-md-6">
                <div class="card article-card shadow-hover position-relative">
                    <img src="${article.image}" class="card-img-top" alt="${article.title}">
                    <button class="favorite-btn" data-article-id="${article.id}">
                        <i class="far fa-heart"></i>
                    </button>
                    <div class="card-body">
                        <h5 class="card-title">${article.title}</h5>
                        <p class="price">${article.price} ${article.currency}</p>
                        <p class="location">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            ${article.location}
                        </p>
                    </div>
                </div>
            </div>
        `).join('');

        // Add fade-in animation
        container.querySelectorAll('.card').forEach((card, index) => {
            setTimeout(() => {
                card.classList.add('fade-in');
            }, index * 100);
        });
    }

    /**
     * Setup loading spinner
     */
    setupLoadingSpinner() {
        const spinner = document.getElementById('loading-spinner');
        if (spinner) {
            // Hide spinner after a delay to show the loading effect
            setTimeout(() => {
                this.hideLoadingSpinner();
            }, 1000);
        }
    }

    /**
     * Hide loading spinner
     */
    hideLoadingSpinner() {
        const spinner = document.getElementById('loading-spinner');
        if (spinner) {
            spinner.style.opacity = '0';
            setTimeout(() => {
                spinner.style.display = 'none';
            }, 300);
        }
    }

    /**
     * Setup cookie banner
     */
    setupCookieBanner() {
        const banner = document.getElementById('cookie-banner');
        const accepted = localStorage.getItem('cookiesAccepted');
        
        if (!accepted && banner) {
            setTimeout(() => {
                banner.classList.remove('d-none');
                banner.style.animation = 'slideUp 0.5s ease-out';
            }, 2000);
        }
    }

    /**
     * Handle cookie consent
     */
    handleCookieConsent(accepted) {
        const banner = document.getElementById('cookie-banner');
        
        localStorage.setItem('cookiesAccepted', accepted ? 'true' : 'false');
        
        if (banner) {
            banner.style.animation = 'slideDown 0.5s ease-in';
            setTimeout(() => {
                banner.classList.add('d-none');
            }, 500);
        }

        this.showToast(
            accepted ? 'Thank you! Cookies accepted.' : 'Cookies declined.',
            accepted ? 'success' : 'info'
        );
    }

    /**
     * Setup back to top button
     */
    setupBackToTop() {
        const btn = document.getElementById('back-to-top');
        if (btn) {
            btn.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }
    }

    /**
     * Setup toast system
     */
    setupToasts() {
        // Create toast container if it doesn't exist
        if (!document.getElementById('toast-container')) {
            const container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            document.body.appendChild(container);
        }
    }

    /**
     * Show toast message
     */
    showToast(message, type = 'info', duration = 5000) {
        const container = document.getElementById('toast-container');
        const toastId = 'toast-' + Date.now();
        
        const icons = {
            success: 'fas fa-check-circle text-success',
            danger: 'fas fa-exclamation-circle text-danger',
            warning: 'fas fa-exclamation-triangle text-warning',
            info: 'fas fa-info-circle text-info'
        };

        const toast = document.createElement('div');
        toast.id = toastId;
        toast.className = 'toast';
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="toast-header">
                <i class="${icons[type]} me-2"></i>
                <strong class="me-auto">Bazar</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        `;

        container.appendChild(toast);

        const bsToast = new bootstrap.Toast(toast, {
            delay: duration
        });
        
        bsToast.show();

        // Remove toast element after it's hidden
        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }

    /**
     * API helper methods
     */
    async apiRequest(endpoint, options = {}) {
        const url = `${this.apiBaseUrl}${endpoint}`;
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
            },
        };

        try {
            const response = await fetch(url, { ...defaultOptions, ...options });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'API request failed');
            }

            return data;
        } catch (error) {
            console.error('API request error:', error);
            throw error;
        }
    }

    /**
     * Utility methods
     */
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    isValidEmail(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }

    formatPrice(price, currency = 'EUR') {
        return new Intl.NumberFormat('fr-FR', {
            style: 'currency',
            currency: currency
        }).format(price);
    }

    formatDate(date) {
        return new Intl.DateTimeFormat('fr-FR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        }).format(new Date(date));
    }

    truncateText(text, maxLength = 100) {
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }
}

// Initialize the application when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.bazarApp = new BazarApp();
});

// Add CSS animation keyframes dynamically
const style = document.createElement('style');
style.textContent = `
    @keyframes slideDown {
        from {
            transform: translateY(0);
            opacity: 1;
        }
        to {
            transform: translateY(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);