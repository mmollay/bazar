// Bazar - Client-Side Router Module

/**
 * Simple client-side router for single-page application
 */
class BazarRouter {
    constructor() {
        this.routes = new Map();
        this.currentRoute = null;
        this.params = {};
        this.query = {};
        this.beforeHooks = [];
        this.afterHooks = [];
        this.notFoundHandler = null;
        
        // Bind event handlers
        this.handlePopState = this.handlePopState.bind(this);
        this.handleLinkClick = this.handleLinkClick.bind(this);
        
        // Initialize router
        this.init();
    }
    
    /**
     * Initialize the router
     */
    init() {
        // Handle browser back/forward
        window.addEventListener('popstate', this.handlePopState);
        
        // Handle link clicks
        document.addEventListener('click', this.handleLinkClick);
        
        // Handle initial page load
        this.navigate(window.location.pathname + window.location.search, false);
    }
    
    /**
     * Define a route
     * @param {string} path - Route path (can include parameters like :id)
     * @param {Function|Object} handler - Route handler function or options object
     * @param {Object} options - Additional options
     */
    route(path, handler, options = {}) {
        const routePattern = this.pathToRegex(path);
        const paramNames = this.getParamNames(path);
        
        this.routes.set(path, {
            pattern: routePattern,
            handler: typeof handler === 'function' ? handler : handler.component,
            paramNames,
            options: typeof handler === 'object' ? { ...handler, ...options } : options,
            meta: options.meta || {}
        });
        
        return this;
    }
    
    /**
     * Navigate to a route
     * @param {string} path - Path to navigate to
     * @param {boolean} pushState - Whether to push to history (default: true)
     * @param {Object} data - Additional data to pass
     */
    async navigate(path, pushState = true, data = {}) {
        try {
            // Parse path and query
            const [pathname, search] = path.split('?');
            this.query = this.parseQuery(search || '');
            
            // Run before hooks
            for (const hook of this.beforeHooks) {
                const result = await hook(pathname, this.query, data);
                if (result === false) {
                    return; // Navigation cancelled
                }
            }
            
            // Find matching route
            const route = this.findRoute(pathname);
            
            if (route) {
                // Extract parameters
                this.params = this.extractParams(route, pathname);
                
                // Update browser history
                if (pushState) {
                    const fullPath = pathname + (search ? '?' + search : '');
                    window.history.pushState({ path: fullPath, data }, '', fullPath);
                }
                
                // Execute route handler
                await this.executeRoute(route, pathname, data);
                
            } else {
                // Handle 404
                await this.handle404(pathname);
            }
            
            // Run after hooks
            for (const hook of this.afterHooks) {
                await hook(pathname, this.query, this.params, data);
            }
            
        } catch (error) {
            console.error('Navigation error:', error);
            this.handleError(error, path);
        }
    }
    
    /**
     * Go back in history
     */
    back() {
        window.history.back();
    }
    
    /**
     * Go forward in history
     */
    forward() {
        window.history.forward();
    }
    
    /**
     * Replace current route
     * @param {string} path - New path
     * @param {Object} data - Additional data
     */
    replace(path, data = {}) {
        const [pathname, search] = path.split('?');
        this.query = this.parseQuery(search || '');
        
        const fullPath = pathname + (search ? '?' + search : '');
        window.history.replaceState({ path: fullPath, data }, '', fullPath);
        
        this.navigate(path, false, data);
    }
    
    /**
     * Add before navigation hook
     * @param {Function} hook - Hook function
     */
    beforeEach(hook) {
        this.beforeHooks.push(hook);
    }
    
    /**
     * Add after navigation hook
     * @param {Function} hook - Hook function
     */
    afterEach(hook) {
        this.afterHooks.push(hook);
    }
    
    /**
     * Set 404 handler
     * @param {Function} handler - 404 handler function
     */
    notFound(handler) {
        this.notFoundHandler = handler;
    }
    
    /**
     * Convert path pattern to regex
     * @param {string} path - Path pattern
     * @returns {RegExp} Regular expression
     */
    pathToRegex(path) {
        const escapedPath = path
            .replace(/\//g, '\\/')
            .replace(/:([^\/]+)/g, '([^/]+)')
            .replace(/\*/g, '(.*)');
        
        return new RegExp(`^${escapedPath}$`);
    }
    
    /**
     * Extract parameter names from path
     * @param {string} path - Path pattern
     * @returns {Array} Parameter names
     */
    getParamNames(path) {
        const matches = path.match(/:([^\/]+)/g);
        return matches ? matches.map(match => match.substring(1)) : [];
    }
    
    /**
     * Find matching route for path
     * @param {string} pathname - Current pathname
     * @returns {Object|null} Matching route or null
     */
    findRoute(pathname) {
        for (const [path, route] of this.routes) {
            if (route.pattern.test(pathname)) {
                return { path, ...route };
            }
        }
        return null;
    }
    
    /**
     * Extract parameters from path
     * @param {Object} route - Route object
     * @param {string} pathname - Current pathname
     * @returns {Object} Parameters object
     */
    extractParams(route, pathname) {
        const matches = pathname.match(route.pattern);
        const params = {};
        
        if (matches && route.paramNames) {
            route.paramNames.forEach((name, index) => {
                params[name] = matches[index + 1];
            });
        }
        
        return params;
    }
    
    /**
     * Parse query string
     * @param {string} queryString - Query string
     * @returns {Object} Parsed query parameters
     */
    parseQuery(queryString) {
        const params = {};
        if (!queryString) return params;
        
        queryString.split('&').forEach(param => {
            const [key, value] = param.split('=');
            if (key) {
                params[decodeURIComponent(key)] = value ? decodeURIComponent(value) : '';
            }
        });
        
        return params;
    }
    
    /**
     * Execute route handler
     * @param {Object} route - Route object
     * @param {string} pathname - Current pathname
     * @param {Object} data - Additional data
     */
    async executeRoute(route, pathname, data) {
        try {
            this.currentRoute = route;
            
            // Show loading if needed
            if (route.options.loading !== false) {
                this.showLoading();
            }
            
            // Execute handler
            const result = await route.handler({
                params: this.params,
                query: this.query,
                path: pathname,
                data,
                meta: route.meta
            });
            
            // Handle result if it's a component/HTML
            if (typeof result === 'string') {
                this.renderContent(result);
            }
            
            // Update active navigation
            this.updateActiveNavigation(pathname);
            
        } catch (error) {
            console.error('Route execution error:', error);
            this.handleError(error, pathname);
        } finally {
            this.hideLoading();
        }
    }
    
    /**
     * Handle 404 errors
     * @param {string} pathname - Path that wasn't found
     */
    async handle404(pathname) {
        console.warn(`Route not found: ${pathname}`);
        
        if (this.notFoundHandler) {
            await this.notFoundHandler(pathname);
        } else {
            this.renderContent(`
                <div class="ui container" style="text-align: center; padding: 3rem;">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">🔍</div>
                    <h2>Seite nicht gefunden</h2>
                    <p>Die angeforderte Seite konnte nicht gefunden werden.</p>
                    <button class="ui primary button" onclick="history.back()">Zurück</button>
                </div>
            `);
        }
    }
    
    /**
     * Handle routing errors
     * @param {Error} error - Error object
     * @param {string} path - Current path
     */
    handleError(error, path) {
        console.error('Router error:', error);
        
        this.renderContent(`
            <div class="ui container" style="text-align: center; padding: 3rem;">
                <div style="font-size: 4rem; margin-bottom: 1rem;">⚠️</div>
                <h2>Fehler beim Laden der Seite</h2>
                <p>Es ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.</p>
                <button class="ui primary button" onclick="window.location.reload()">Seite neu laden</button>
            </div>
        `);
    }
    
    /**
     * Show loading indicator
     */
    showLoading() {
        const appContent = document.getElementById('app-content');
        if (appContent && !document.querySelector('.route-loading')) {
            const loader = document.createElement('div');
            loader.className = 'route-loading';
            loader.style.cssText = `
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                display: flex;
                justify-content: center;
                align-items: center;
                background: rgba(255,255,255,0.8);
                z-index: 100;
            `;
            loader.innerHTML = '<div class="ui active centered inline loader"></div>';
            appContent.appendChild(loader);
        }
    }
    
    /**
     * Hide loading indicator
     */
    hideLoading() {
        const loader = document.querySelector('.route-loading');
        if (loader) {
            loader.remove();
        }
    }
    
    /**
     * Render content to app container
     * @param {string} html - HTML content to render
     */
    renderContent(html) {
        const appContent = document.getElementById('app-content');
        if (appContent) {
            appContent.innerHTML = html;
        }
    }
    
    /**
     * Update active navigation items
     * @param {string} pathname - Current path
     */
    updateActiveNavigation(pathname) {
        // Update bottom navigation
        const navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(item => {
            item.classList.remove('active');
            const page = item.getAttribute('data-page');
            if (this.isActivePage(page, pathname)) {
                item.classList.add('active');
            }
        });
        
        // Update top navigation if exists
        const topNavItems = document.querySelectorAll('#top-nav .item');
        topNavItems.forEach(item => {
            item.classList.remove('active');
            if (item.getAttribute('href') === pathname) {
                item.classList.add('active');
            }
        });
    }
    
    /**
     * Check if page is active based on pathname
     * @param {string} page - Page identifier
     * @param {string} pathname - Current pathname
     * @returns {boolean} True if page is active
     */
    isActivePage(page, pathname) {
        if (page === 'home' && (pathname === '/' || pathname === '/home')) return true;
        if (page === 'search' && pathname.startsWith('/search')) return true;
        if (page === 'create' && pathname.startsWith('/create')) return true;
        if (page === 'messages' && pathname.startsWith('/messages')) return true;
        if (page === 'profile' && pathname.startsWith('/profile')) return true;
        return false;
    }
    
    /**
     * Handle popstate events (back/forward)
     * @param {PopStateEvent} event - Popstate event
     */
    handlePopState(event) {
        const path = window.location.pathname + window.location.search;
        this.navigate(path, false, event.state?.data);
    }
    
    /**
     * Handle link clicks for SPA navigation
     * @param {Event} event - Click event
     */
    handleLinkClick(event) {
        const link = event.target.closest('a');
        
        if (link && 
            link.hostname === window.location.hostname &&
            !link.hasAttribute('download') &&
            !link.hasAttribute('target') &&
            !event.ctrlKey &&
            !event.metaKey &&
            !event.shiftKey &&
            event.button === 0) {
            
            event.preventDefault();
            const path = link.pathname + link.search;
            this.navigate(path);
        }
    }
}

// Create global router instance
const router = new BazarRouter();

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { BazarRouter, router };
} else {
    window.BazarRouter = BazarRouter;
    window.router = router;
}