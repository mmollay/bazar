// Bazar - Main Application

/**
 * Main Bazar Application Class
 */
class BazarApp {
    constructor() {
        this.isInitialized = false;
        this.version = '1.0.0';
        
        // App state
        this.state = {
            isOnline: navigator.onLine,
            currentUser: null,
            currentRoute: null,
            theme: 'light'
        };
        
        // Performance monitoring
        this.performanceMetrics = {
            startTime: performance.now(),
            loadTime: null,
            interactive: false
        };
    }
    
    /**
     * Initialize the application
     */
    async init() {
        try {
            console.log(`🏪 Bazar v${this.version} starting...`);
            
            // Show loading screen
            ui.showLoadingScreen();
            
            // Initialize core modules
            await this.initializeModules();
            
            // Setup routes
            this.setupRoutes();
            
            // Setup global event handlers
            this.setupEventHandlers();
            
            // Setup performance monitoring
            this.setupPerformanceMonitoring();
            
            // Initialize theme
            this.initializeTheme();
            
            // Mark as initialized
            this.isInitialized = true;
            this.performanceMetrics.interactive = true;
            
            console.log(`✅ Bazar initialized in ${(performance.now() - this.performanceMetrics.startTime).toFixed(2)}ms`);
            
            // Hide loading screen
            setTimeout(() => ui.hideLoadingScreen(), 500);
            
        } catch (error) {
            console.error('❌ Failed to initialize Bazar:', error);
            this.handleInitializationError(error);
        }
    }
    
    /**
     * Initialize core modules
     */
    async initializeModules() {
        // Modules are already initialized when their scripts load
        // We just need to ensure they're ready
        
        if (typeof BazarUtils === 'undefined') {
            throw new Error('BazarUtils not loaded');
        }
        
        if (typeof router === 'undefined') {
            throw new Error('Router not loaded');
        }
        
        if (typeof api === 'undefined') {
            throw new Error('API module not loaded');
        }
        
        if (typeof auth === 'undefined') {
            throw new Error('Auth module not loaded');
        }
        
        if (typeof search === 'undefined') {
            throw new Error('Search module not loaded');
        }
        
        if (typeof ui === 'undefined') {
            throw new Error('UI module not loaded');
        }
        
        console.log('✅ All modules loaded successfully');
    }
    
    /**
     * Setup application routes
     */
    setupRoutes() {
        // Home page
        router.route('/', this.renderHomePage.bind(this));
        router.route('/home', this.renderHomePage.bind(this));
        
        // Search
        router.route('/search', this.renderSearchPage.bind(this));
        
        // Articles
        router.route('/articles/:id', this.renderArticlePage.bind(this));
        router.route('/create', this.renderCreateArticlePage.bind(this));
        router.route('/articles/my', this.renderMyArticlesPage.bind(this));
        
        // User pages
        router.route('/login', this.renderLoginPage.bind(this));
        router.route('/register', this.renderRegisterPage.bind(this));
        router.route('/profile', this.renderProfilePage.bind(this));
        router.route('/messages', this.renderMessagesPage.bind(this));
        router.route('/messages/:id', this.renderConversationPage.bind(this));
        
        // Legal pages
        router.route('/terms', this.renderTermsPage.bind(this));
        router.route('/privacy', this.renderPrivacyPage.bind(this));
        router.route('/imprint', this.renderImprintPage.bind(this));
        
        // 404 handler
        router.notFound(this.render404Page.bind(this));
        
        // Route guards
        router.beforeEach(this.authGuard.bind(this));
        router.afterEach(this.trackPageView.bind(this));
        
        console.log('✅ Routes configured');
    }
    
    /**
     * Authentication guard for protected routes
     * @param {string} path - Route path
     * @param {Object} query - Query parameters
     * @param {Object} data - Additional data
     * @returns {boolean} Continue navigation
     */
    async authGuard(path, query, data) {
        const protectedRoutes = ['/create', '/profile', '/messages', '/articles/my'];
        const guestOnlyRoutes = ['/login', '/register'];
        
        const isProtected = protectedRoutes.some(route => path.startsWith(route));
        const isGuestOnly = guestOnlyRoutes.some(route => path.startsWith(route));
        
        if (isProtected && !auth.isAuthenticated()) {
            const loginPath = `/login?redirect=${encodeURIComponent(path)}`;
            router.navigate(loginPath, false);
            return false;
        }
        
        if (isGuestOnly && auth.isAuthenticated()) {
            router.navigate('/', false);
            return false;
        }
        
        return true;
    }
    
    /**
     * Track page views for analytics
     * @param {string} path - Route path
     */
    trackPageView(path) {
        // Implementation for analytics tracking
        console.log(`📊 Page view: ${path}`);
        
        // Update page title
        this.updatePageTitle(path);
    }
    
    /**
     * Update page title based on route
     * @param {string} path - Route path
     */
    updatePageTitle(path) {
        const titles = {
            '/': 'Bazar - Lokaler Marktplatz',
            '/search': 'Suchen - Bazar',
            '/create': 'Artikel erstellen - Bazar',
            '/profile': 'Profil - Bazar',
            '/messages': 'Nachrichten - Bazar',
            '/login': 'Anmelden - Bazar',
            '/register': 'Registrieren - Bazar'
        };
        
        const title = titles[path] || 'Bazar - Lokaler Marktplatz';
        document.title = title;
    }
    
    /**
     * Setup global event handlers
     */
    setupEventHandlers() {
        // Network status changes
        window.addEventListener('online', () => {
            this.state.isOnline = true;
            this.handleOnlineStatusChange(true);
        });
        
        window.addEventListener('offline', () => {
            this.state.isOnline = false;
            this.handleOnlineStatusChange(false);
        });
        
        // App install prompt
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.showInstallPrompt(e);
        });
        
        // Theme preference changes
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (this.state.theme === 'auto') {
                    this.applyTheme(e.matches ? 'dark' : 'light');
                }
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', this.handleKeyboardShortcuts.bind(this));
        
        console.log('✅ Event handlers configured');
    }
    
    /**
     * Handle keyboard shortcuts
     * @param {KeyboardEvent} e - Keyboard event
     */
    handleKeyboardShortcuts(e) {
        // Ctrl/Cmd + K for search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('main-search');
            if (searchInput) {
                searchInput.focus();
            } else {
                router.navigate('/search');
            }
        }
        
        // Escape to close modals or go back
        if (e.key === 'Escape') {
            if (ui.currentModal) {
                ui.closeModal();
            }
        }
    }
    
    /**
     * Handle online/offline status changes
     * @param {boolean} isOnline - Online status
     */
    handleOnlineStatusChange(isOnline) {
        if (isOnline) {
            // Sync any pending data
            this.syncPendingData();
        } else {
            // Switch to offline mode
            this.enableOfflineMode();
        }
    }
    
    /**
     * Show PWA install prompt
     * @param {Event} e - Install prompt event
     */
    showInstallPrompt(e) {
        // Show custom install prompt after a delay
        setTimeout(() => {
            const content = `
                <div style="text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📱</div>
                    <h3>App installieren</h3>
                    <p>Installieren Sie Bazar für ein besseres Erlebnis mit Offline-Funktionen und Push-Benachrichtigungen.</p>
                    <div class="ui buttons" style="margin-top: 2rem;">
                        <button class="ui button" onclick="ui.closeModal()">Später</button>
                        <div class="or" data-text="oder"></div>
                        <button class="ui primary button" onclick="app.installApp()">Installieren</button>
                    </div>
                </div>
            `;
            
            ui.showModal(content, { size: 'mini' });
            this.installPromptEvent = e;
        }, 30000); // Show after 30 seconds
    }
    
    /**
     * Install PWA
     */
    async installApp() {
        if (this.installPromptEvent) {
            this.installPromptEvent.prompt();
            const result = await this.installPromptEvent.userChoice;
            
            if (result.outcome === 'accepted') {
                BazarUtils.showToast('App wird installiert...', 'success');
            }
            
            this.installPromptEvent = null;
        }
        
        ui.closeModal();
    }
    
    /**
     * Setup performance monitoring
     */
    setupPerformanceMonitoring() {
        // Measure load time
        window.addEventListener('load', () => {
            this.performanceMetrics.loadTime = performance.now() - this.performanceMetrics.startTime;
            
            // Report performance metrics
            this.reportPerformanceMetrics();
        });
        
        // Monitor largest contentful paint
        if ('PerformanceObserver' in window) {
            const observer = new PerformanceObserver((list) => {
                const entries = list.getEntries();
                const lastEntry = entries[entries.length - 1];
                this.performanceMetrics.lcp = lastEntry.startTime;
            });
            
            observer.observe({ entryTypes: ['largest-contentful-paint'] });
        }
    }
    
    /**
     * Report performance metrics
     */
    reportPerformanceMetrics() {
        const metrics = this.performanceMetrics;
        console.log('📊 Performance Metrics:', {
            loadTime: `${metrics.loadTime?.toFixed(2)}ms`,
            lcp: `${metrics.lcp?.toFixed(2)}ms`,
            interactive: metrics.interactive
        });
        
        // Send to analytics service if available
        // analytics.track('performance', metrics);
    }
    
    /**
     * Initialize theme system
     */
    initializeTheme() {
        const savedTheme = BazarUtils.getLocalStorage('theme', 'auto');
        this.setTheme(savedTheme);
    }
    
    /**
     * Set application theme
     * @param {string} theme - Theme name ('light', 'dark', 'auto')
     */
    setTheme(theme) {
        this.state.theme = theme;
        BazarUtils.setLocalStorage('theme', theme);
        
        let actualTheme = theme;
        
        if (theme === 'auto') {
            actualTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        
        this.applyTheme(actualTheme);
    }
    
    /**
     * Apply theme to DOM
     * @param {string} theme - Theme to apply ('light' or 'dark')
     */
    applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.body.className = theme === 'dark' ? 'dark-theme' : '';
        
        // Update meta theme-color
        const metaThemeColor = document.querySelector('meta[name="theme-color"]');
        if (metaThemeColor) {
            metaThemeColor.content = theme === 'dark' ? '#202124' : '#ffffff';
        }
    }
    
    /**
     * Sync pending data when coming back online
     */
    async syncPendingData() {
        // Implementation for syncing offline data
        console.log('🔄 Syncing pending data...');
        
        // This would sync any offline actions stored in IndexedDB
        // For now, just a placeholder
    }
    
    /**
     * Enable offline mode
     */
    enableOfflineMode() {
        console.log('📴 Offline mode enabled');
        // Implementation for offline mode
    }
    
    /**
     * Handle initialization errors
     * @param {Error} error - Initialization error
     */
    handleInitializationError(error) {
        ui.hideLoadingScreen();
        
        const errorContent = `
            <div class="ui segment basic" style="text-align: center;">
                <div style="font-size: 3rem; margin-bottom: 1rem; color: #ea4335;">⚠️</div>
                <h2>Fehler beim Laden der Anwendung</h2>
                <p>Es ist ein Fehler aufgetreten. Bitte laden Sie die Seite neu.</p>
                <p style="color: #5f6368; font-size: 0.9rem;">Fehler: ${error.message}</p>
                <button class="ui primary button" onclick="window.location.reload()">
                    Seite neu laden
                </button>
            </div>
        `;
        
        document.getElementById('app-content').innerHTML = errorContent;
    }
    
    // === ROUTE HANDLERS ===
    
    /**
     * Render home page
     */
    async renderHomePage() {
        // Home page content is already in the HTML
        // We just need to update any dynamic content
        
        try {
            // Load featured categories (when API is ready)
            // const categories = await api.getCategories();
            // this.updateCategoryCards(categories);
            
        } catch (error) {
            console.warn('Failed to load home page data:', error);
        }
        
        return `
            <div class="homepage-container">
                <div class="logo-container">
                    <img src="/frontend/assets/images/logo.svg" alt="Bazar" class="main-logo">
                </div>
                
                <div class="search-container">
                    <div class="ui massive fluid icon input search-input">
                        <input type="text" 
                               placeholder="Was suchen Sie?" 
                               id="main-search"
                               autocomplete="off">
                        <i class="search icon"></i>
                    </div>
                    <div id="search-suggestions" class="search-suggestions" style="display: none;"></div>
                </div>
                
                <div class="categories-grid">
                    <div class="ui stackable four column grid">
                        <div class="column">
                            <div class="ui card category-card" data-category="electronics">
                                <div class="content">
                                    <i class="mobile alternate icon"></i>
                                    <div class="header">Elektronik</div>
                                </div>
                            </div>
                        </div>
                        <div class="column">
                            <div class="ui card category-card" data-category="clothing">
                                <div class="content">
                                    <i class="shirt icon"></i>
                                    <div class="header">Kleidung</div>
                                </div>
                            </div>
                        </div>
                        <div class="column">
                            <div class="ui card category-card" data-category="home">
                                <div class="content">
                                    <i class="home icon"></i>
                                    <div class="header">Haushalt</div>
                                </div>
                            </div>
                        </div>
                        <div class="column">
                            <div class="ui card category-card" data-category="vehicles">
                                <div class="content">
                                    <i class="car icon"></i>
                                    <div class="header">Fahrzeuge</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Render search page
     */
    async renderSearchPage(context) {
        const query = context.query.q || '';
        
        return `
            <div class="ui container" style="padding-top: 2rem;">
                <div class="search-header">
                    <div class="ui massive fluid icon input search-input">
                        <input type="text" 
                               placeholder="Suchen..." 
                               id="search-input"
                               value="${BazarUtils.sanitizeHTML(query)}"
                               autocomplete="off">
                        <i class="search icon"></i>
                    </div>
                </div>
                
                <div class="ui grid" style="margin-top: 2rem;">
                    <div class="four wide column">
                        <div class="ui segment">
                            <h4>Filter</h4>
                            <div class="ui form">
                                <div class="field">
                                    <label>Kategorie</label>
                                    <select class="ui dropdown search-filter" name="category">
                                        <option value="">Alle Kategorien</option>
                                        <option value="electronics">Elektronik</option>
                                        <option value="clothing">Kleidung</option>
                                        <option value="home">Haushalt</option>
                                        <option value="vehicles">Fahrzeuge</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Preis</label>
                                    <input type="range" class="search-filter" name="max_price" min="0" max="10000" step="100">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="twelve wide column">
                        <div id="search-results">
                            ${query ? '<div class="ui active centered inline loader"></div>' : '<p>Geben Sie einen Suchbegriff ein.</p>'}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Render login page
     */
    renderLoginPage() {
        if (auth.isAuthenticated()) {
            router.navigate('/');
            return '';
        }
        
        return `
            <div class="ui middle aligned center aligned grid" style="height: 100vh;">
                <div class="column" style="max-width: 450px;">
                    <h2 class="ui teal image header">
                        <img src="/frontend/assets/images/logo.svg" class="image">
                        <div class="content">Anmelden</div>
                    </h2>
                    <form class="ui large form" id="login-form">
                        <div class="ui stacked segment">
                            <div class="field">
                                <div class="ui left icon input">
                                    <i class="user icon"></i>
                                    <input type="email" name="email" placeholder="E-Mail" required>
                                </div>
                            </div>
                            <div class="field">
                                <div class="ui left icon input">
                                    <i class="lock icon"></i>
                                    <input type="password" name="password" placeholder="Passwort" required>
                                </div>
                            </div>
                            <div class="field">
                                <div class="ui checkbox">
                                    <input type="checkbox" name="remember" id="remember">
                                    <label for="remember">Angemeldet bleiben</label>
                                </div>
                            </div>
                            <button class="ui fluid large teal button" type="submit">Anmelden</button>
                        </div>
                    </form>
                    
                    <div class="ui message">
                        Noch kein Konto? <a href="/register">Registrieren</a>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Render 404 page
     */
    render404Page() {
        return `
            <div class="ui container" style="text-align: center; padding: 4rem 2rem;">
                <div style="font-size: 6rem; margin-bottom: 2rem;">🔍</div>
                <h1>404 - Seite nicht gefunden</h1>
                <p>Die angeforderte Seite konnte nicht gefunden werden.</p>
                <a href="/" class="ui primary button">Zur Startseite</a>
            </div>
        `;
    }
    
    // Additional route handlers would be implemented here...
    // For brevity, I'm including just the essential ones
    
    /**
     * Get app state
     * @returns {Object} Current app state
     */
    getState() {
        return { ...this.state };
    }
    
    /**
     * Update app state
     * @param {Object} updates - State updates
     */
    updateState(updates) {
        this.state = { ...this.state, ...updates };
    }
}

// Create and initialize app
const app = new BazarApp();

// Global app reference
window.App = {
    init: () => app.init(),
    app,
    version: app.version
};

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => app.init());
} else {
    // DOM is already ready
    setTimeout(() => app.init(), 0);
}