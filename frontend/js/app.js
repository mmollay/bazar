// Bazar - Main Application
console.log('🏪 Bazar Marketplace - Loading...');

// Global namespace
window.Bazar = window.Bazar || {};

// Wait for all dependencies
document.addEventListener('DOMContentLoaded', function() {
    
    // Initialize modules in the global namespace
    if (typeof BazarUtils !== 'undefined') {
        window.Bazar.utils = BazarUtils;
    }
    
    if (typeof BazarRouter !== 'undefined') {
        window.Bazar.router = new BazarRouter();
    }
    
    if (typeof BazarAPI !== 'undefined') {
        window.Bazar.api = new BazarAPI();
    }
    
    if (typeof BazarAuth !== 'undefined') {
        window.Bazar.auth = new BazarAuth();
    }
    
    if (typeof BazarSearch !== 'undefined') {
        window.Bazar.search = new BazarSearch();
    }
    
    if (typeof BazarUI !== 'undefined') {
        window.Bazar.ui = new BazarUI();
    }
    
    // Main Application
    class BazarApp {
        constructor() {
            this.version = '1.0.0';
            this.isInitialized = false;
            this.currentView = 'home';
            this.modules = window.Bazar;
        }
        
        /**
         * Initialize the application
         */
        async init() {
            try {
                console.log(`🏪 Bazar v${this.version} starting...`);
                
                // Check modules
                this.checkModules();
                
                // Setup routes
                this.setupRoutes();
                
                // Initialize UI
                this.initializeUI();
                
                // Check authentication
                await this.checkAuth();
                
                // Load initial data
                await this.loadInitialData();
                
                // Start router
                if (this.modules.router) {
                    this.modules.router.start();
                }
                
                // Hide loading screen
                this.hideLoading();
                
                this.isInitialized = true;
                console.log('✨ Bazar initialized successfully!');
                
            } catch (error) {
                console.error('❌ Failed to initialize Bazar:', error);
                this.showError('Failed to initialize application');
            }
        }
        
        /**
         * Check if all required modules are loaded
         */
        checkModules() {
            const requiredModules = ['utils', 'router', 'api', 'auth', 'search', 'ui'];
            const missingModules = [];
            
            for (const module of requiredModules) {
                if (!this.modules[module]) {
                    missingModules.push(module);
                }
            }
            
            if (missingModules.length > 0) {
                console.warn('⚠️ Missing modules:', missingModules);
            } else {
                console.log('✅ All modules loaded successfully');
            }
        }
        
        /**
         * Setup application routes
         */
        setupRoutes() {
            if (!this.modules.router) {
                console.warn('Router module not available');
                return;
            }
            
            const router = this.modules.router;
            
            // Home page
            router.route('/', this.renderHomePage.bind(this));
            router.route('/home', this.renderHomePage.bind(this));
            
            // Search
            router.route('/search', this.renderSearchPage.bind(this));
            
            // Article routes
            router.route('/article/:id', this.renderArticlePage.bind(this));
            router.route('/create-article', this.renderCreateArticlePage.bind(this));
            
            // Auth routes
            router.route('/login', this.renderLoginPage.bind(this));
            router.route('/register', this.renderRegisterPage.bind(this));
            router.route('/profile', this.renderProfilePage.bind(this));
            
            // Messages
            router.route('/messages', this.renderMessagesPage.bind(this));
            
            // 404 handler
            router.route('*', this.render404Page.bind(this));
        }
        
        /**
         * Initialize UI components
         */
        initializeUI() {
            // Setup FAB
            this.setupFAB();
            
            // Setup search
            this.setupSearch();
            
            // Setup mobile menu
            this.setupMobileMenu();
            
            // Setup notifications
            this.setupNotifications();
        }
        
        /**
         * Setup Floating Action Button
         */
        setupFAB() {
            const fab = document.getElementById('fab');
            if (fab) {
                fab.addEventListener('click', () => {
                    if (this.modules.auth && this.modules.auth.isAuthenticated()) {
                        if (this.modules.router) {
                            this.modules.router.navigate('/create-article');
                        }
                    } else {
                        if (this.modules.ui) {
                            this.modules.ui.showModal('Please login to create an article', 'warning');
                        }
                        if (this.modules.router) {
                            this.modules.router.navigate('/login');
                        }
                    }
                });
            }
        }
        
        /**
         * Setup search functionality
         */
        setupSearch() {
            const searchBox = document.getElementById('search-box');
            const searchButton = document.getElementById('search-button');
            
            if (searchBox) {
                searchBox.addEventListener('input', (e) => {
                    if (this.modules.search && this.modules.search.debouncedSuggestions) {
                        this.modules.search.debouncedSuggestions(e.target.value);
                    }
                });
                
                searchBox.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        this.performSearch(e.target.value);
                    }
                });
            }
            
            if (searchButton) {
                searchButton.addEventListener('click', () => {
                    if (searchBox) {
                        this.performSearch(searchBox.value);
                    }
                });
            }
        }
        
        /**
         * Perform search
         */
        performSearch(query) {
            if (query && query.trim()) {
                if (this.modules.router) {
                    this.modules.router.navigate(`/search?q=${encodeURIComponent(query)}`);
                }
            }
        }
        
        /**
         * Setup mobile menu
         */
        setupMobileMenu() {
            const menuToggle = document.getElementById('mobile-menu-toggle');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (menuToggle && mobileMenu) {
                menuToggle.addEventListener('click', () => {
                    mobileMenu.classList.toggle('active');
                });
                
                // Close menu on outside click
                document.addEventListener('click', (e) => {
                    if (!menuToggle.contains(e.target) && !mobileMenu.contains(e.target)) {
                        mobileMenu.classList.remove('active');
                    }
                });
            }
        }
        
        /**
         * Setup notifications
         */
        setupNotifications() {
            // Request notification permission
            if ('Notification' in window && Notification.permission === 'default') {
                // We'll ask for permission when needed
            }
        }
        
        /**
         * Check authentication status
         */
        async checkAuth() {
            if (this.modules.auth) {
                await this.modules.auth.checkAuthStatus();
                this.updateAuthUI();
            }
        }
        
        /**
         * Update UI based on auth status
         */
        updateAuthUI() {
            const authButtons = document.getElementById('auth-buttons');
            const userMenu = document.getElementById('user-menu');
            
            if (this.modules.auth && this.modules.auth.isAuthenticated()) {
                if (authButtons) authButtons.style.display = 'none';
                if (userMenu) userMenu.style.display = 'flex';
                
                // Update user info
                const user = this.modules.auth.getUser();
                if (user) {
                    const userName = document.getElementById('user-name');
                    if (userName) {
                        userName.textContent = user.username || user.email;
                    }
                }
            } else {
                if (authButtons) authButtons.style.display = 'flex';
                if (userMenu) userMenu.style.display = 'none';
            }
        }
        
        /**
         * Load initial data
         */
        async loadInitialData() {
            try {
                // Load categories
                await this.loadCategories();
                
                // Load featured articles
                await this.loadFeaturedArticles();
                
            } catch (error) {
                console.error('Failed to load initial data:', error);
            }
        }
        
        /**
         * Load categories
         */
        async loadCategories() {
            if (!this.modules.api) return;
            
            try {
                const categories = await this.modules.api.getCategories();
                this.renderCategories(categories);
            } catch (error) {
                console.error('Failed to load categories:', error);
            }
        }
        
        /**
         * Render categories
         */
        renderCategories(categories) {
            const container = document.getElementById('categories-grid');
            if (!container || !categories) return;
            
            container.innerHTML = categories.map(category => `
                <div class="category-card" data-category="${category.slug}">
                    <i class="${category.icon} category-icon"></i>
                    <div class="category-name">${category.name}</div>
                </div>
            `).join('');
            
            // Add click handlers
            container.querySelectorAll('.category-card').forEach(card => {
                card.addEventListener('click', () => {
                    const categorySlug = card.dataset.category;
                    if (this.modules.router) {
                        this.modules.router.navigate(`/search?category=${categorySlug}`);
                    }
                });
            });
        }
        
        /**
         * Load featured articles
         */
        async loadFeaturedArticles() {
            if (!this.modules.api) return;
            
            try {
                const articles = await this.modules.api.getFeaturedArticles();
                this.renderFeaturedArticles(articles);
            } catch (error) {
                console.error('Failed to load featured articles:', error);
            }
        }
        
        /**
         * Render featured articles
         */
        renderFeaturedArticles(articles) {
            const container = document.getElementById('featured-articles');
            if (!container || !articles) return;
            
            container.innerHTML = articles.map(article => `
                <div class="article-card" data-article-id="${article.id}">
                    <img src="${article.image || '/bazar/frontend/assets/images/placeholder.jpg'}" 
                         alt="${article.title}" class="article-image">
                    <div class="article-content">
                        <h3 class="article-title">${article.title}</h3>
                        <div class="article-price">€${article.price}</div>
                        <div class="article-location">
                            <i class="map marker alternate icon"></i>
                            ${article.location}
                        </div>
                    </div>
                </div>
            `).join('');
            
            // Add click handlers
            container.querySelectorAll('.article-card').forEach(card => {
                card.addEventListener('click', () => {
                    const articleId = card.dataset.articleId;
                    if (this.modules.router) {
                        this.modules.router.navigate(`/article/${articleId}`);
                    }
                });
            });
        }
        
        // Page Renderers
        renderHomePage() {
            this.currentView = 'home';
            console.log('Rendering home page');
            // Home page is already in the HTML
            this.showContent('home-content');
        }
        
        renderSearchPage(params) {
            this.currentView = 'search';
            console.log('Rendering search page', params);
            // Load search results
            if (this.modules.search && params.q) {
                this.modules.search.performSearch(params.q, this.modules.search.filters);
            }
        }
        
        renderArticlePage(params) {
            this.currentView = 'article';
            console.log('Rendering article page', params);
            // Load article details
        }
        
        renderCreateArticlePage() {
            this.currentView = 'create-article';
            console.log('Rendering create article page');
            // Check auth first
            if (this.modules.auth && !this.modules.auth.isAuthenticated()) {
                if (this.modules.router) {
                    this.modules.router.navigate('/login');
                }
                return;
            }
            // Load create article form
        }
        
        renderLoginPage() {
            this.currentView = 'login';
            console.log('Rendering login page');
            // Load login form
        }
        
        renderRegisterPage() {
            this.currentView = 'register';
            console.log('Rendering register page');
            // Load register form
        }
        
        renderProfilePage() {
            this.currentView = 'profile';
            console.log('Rendering profile page');
            // Check auth first
            if (this.modules.auth && !this.modules.auth.isAuthenticated()) {
                if (this.modules.router) {
                    this.modules.router.navigate('/login');
                }
                return;
            }
            // Load profile
        }
        
        renderMessagesPage() {
            this.currentView = 'messages';
            console.log('Rendering messages page');
            // Check auth first
            if (this.modules.auth && !this.modules.auth.isAuthenticated()) {
                if (this.modules.router) {
                    this.modules.router.navigate('/login');
                }
                return;
            }
            // Load messages
        }
        
        render404Page() {
            this.currentView = '404';
            console.log('Rendering 404 page');
            if (this.modules.ui) {
                this.modules.ui.showError('Page not found');
            }
        }
        
        /**
         * Show specific content section
         */
        showContent(contentId) {
            // Hide all content sections
            document.querySelectorAll('.content-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Show specific content
            const content = document.getElementById(contentId);
            if (content) {
                content.style.display = 'block';
            }
        }
        
        /**
         * Hide loading screen
         */
        hideLoading() {
            const loadingScreen = document.getElementById('loading-screen');
            if (loadingScreen) {
                loadingScreen.style.opacity = '0';
                setTimeout(() => {
                    loadingScreen.style.display = 'none';
                }, 300);
            }
        }
        
        /**
         * Show error message
         */
        showError(message) {
            if (this.modules.ui) {
                this.modules.ui.showError(message);
            } else {
                console.error(message);
            }
        }
    }
    
    // Initialize the app
    const app = new BazarApp();
    window.Bazar.app = app;
    
    // Start the application
    app.init();
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.Bazar;
}