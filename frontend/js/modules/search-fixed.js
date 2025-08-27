// Bazar - Search Module (Fixed)

/**
 * Search functionality for Bazar application
 */
class BazarSearch {
    constructor() {
        this.searchHistory = [];
        this.suggestions = [];
        this.currentQuery = '';
        this.filters = {};
        this.results = [];
        this.isSearching = false;
        this.searchCache = new Map();
        this.cacheTimeout = 5 * 60 * 1000; // 5 minutes
        
        // These will be initialized after utils are loaded
        this.debouncedSearch = null;
        this.debouncedSuggestions = null;
        
        // Initialize search
        this.init();
    }
    
    /**
     * Initialize search functionality
     */
    init() {
        // Initialize debounced functions safely
        setTimeout(() => {
            if (typeof BazarUtils !== 'undefined' && BazarUtils.debounce) {
                this.debouncedSearch = BazarUtils.debounce(this.performSearch.bind(this), 300);
                this.debouncedSuggestions = BazarUtils.debounce(this.fetchSuggestions.bind(this), 200);
            } else {
                // Fallback without debouncing
                this.debouncedSearch = this.performSearch.bind(this);
                this.debouncedSuggestions = this.fetchSuggestions.bind(this);
            }
        }, 100);
        
        this.loadSearchHistory();
        this.setupSearchUI();
    }

    /**
     * Perform search with given query and filters
     * @param {string} query - Search query
     * @param {Object} options - Search options
     */
    async performSearch(query, options = {}) {
        if (!query && Object.keys(this.filters).length === 0) {
            return;
        }
        
        this.currentQuery = query;
        this.isSearching = true;
        
        // Check cache first
        const cacheKey = this.getCacheKey(query, this.filters);
        if (!options.force && this.searchCache.has(cacheKey)) {
            const cached = this.searchCache.get(cacheKey);
            if (cached.timestamp + this.cacheTimeout > Date.now()) {
                this.results = cached.results;
                this.renderResults();
                this.isSearching = false;
                return;
            }
        }
        
        try {
            // Show loading state
            this.showSearchLoading();
            
            // Prepare search parameters
            const params = {
                q: query,
                ...this.filters
            };
            
            // Make API request
            const response = await this.searchAPI(params);
            
            // Store results
            this.results = response.data || [];
            
            // Cache results
            this.searchCache.set(cacheKey, {
                results: this.results,
                timestamp: Date.now()
            });
            
            // Update search history
            if (query) {
                this.addToSearchHistory(query);
            }
            
            // Render results
            this.renderResults();
            
        } catch (error) {
            console.error('Search failed:', error);
            this.showSearchError();
        } finally {
            this.isSearching = false;
            this.hideSearchLoading();
        }
    }
    
    /**
     * Make search API request
     * @param {Object} params - Search parameters
     */
    async searchAPI(params) {
        if (!window.Bazar || !window.Bazar.api) {
            throw new Error('API module not available');
        }
        
        return await window.Bazar.api.search(params);
    }
    
    /**
     * Fetch search suggestions
     * @param {string} query - Search query
     */
    async fetchSuggestions(query) {
        if (!query || query.length < 2) {
            this.hideSuggestions();
            return;
        }
        
        try {
            if (!window.Bazar || !window.Bazar.api) {
                return;
            }
            
            const response = await window.Bazar.api.getSearchSuggestions(query);
            this.suggestions = response.suggestions || [];
            this.renderSuggestions();
        } catch (error) {
            console.error('Failed to fetch suggestions:', error);
        }
    }
    
    /**
     * Setup search UI elements
     */
    setupSearchUI() {
        // Main search box
        const searchBox = document.getElementById('search-box');
        if (searchBox) {
            searchBox.addEventListener('input', (e) => {
                if (this.debouncedSuggestions) {
                    this.debouncedSuggestions(e.target.value);
                }
            });
            
            searchBox.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && this.debouncedSearch) {
                    this.debouncedSearch(e.target.value);
                }
            });
        }
        
        // Hero search box (on homepage)
        const heroSearchBox = document.getElementById('hero-search-box');
        if (heroSearchBox) {
            heroSearchBox.addEventListener('input', (e) => {
                if (this.debouncedSuggestions) {
                    this.debouncedSuggestions(e.target.value);
                }
            });
            
            heroSearchBox.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && this.debouncedSearch) {
                    this.debouncedSearch(e.target.value);
                }
            });
        }
        
        // Search button
        const searchButton = document.getElementById('search-button');
        if (searchButton) {
            searchButton.addEventListener('click', () => {
                const query = searchBox ? searchBox.value : '';
                if (query && this.debouncedSearch) {
                    this.debouncedSearch(query);
                }
            });
        }
    }
    
    /**
     * Render search results
     */
    renderResults() {
        const resultsContainer = document.getElementById('search-results');
        if (!resultsContainer) return;
        
        if (this.results.length === 0) {
            resultsContainer.innerHTML = `
                <div class="no-results">
                    <i class="search icon huge"></i>
                    <h3>No results found</h3>
                    <p>Try adjusting your search or filters</p>
                </div>
            `;
            return;
        }
        
        const resultsHTML = this.results.map(article => `
            <div class="article-card" data-article-id="${article.id}">
                <img src="${article.image || '/bazar/frontend/assets/images/placeholder.jpg'}" 
                     alt="${article.title}" class="article-image">
                <div class="article-content">
                    <h3 class="article-title">${article.title}</h3>
                    <div class="article-price">€${article.price}</div>
                    <div class="article-location">
                        <i class="map marker alternate icon"></i>
                        ${article.location || 'Unknown'}
                    </div>
                </div>
            </div>
        `).join('');
        
        resultsContainer.innerHTML = resultsHTML;
        
        // Add click handlers
        resultsContainer.querySelectorAll('.article-card').forEach(card => {
            card.addEventListener('click', () => {
                const articleId = card.dataset.articleId;
                if (window.Bazar && window.Bazar.router) {
                    window.Bazar.router.navigate(`/article/${articleId}`);
                }
            });
        });
    }
    
    /**
     * Render search suggestions
     */
    renderSuggestions() {
        const suggestionsContainer = document.getElementById('search-suggestions');
        if (!suggestionsContainer || this.suggestions.length === 0) {
            this.hideSuggestions();
            return;
        }
        
        const suggestionsHTML = this.suggestions.map(suggestion => `
            <div class="suggestion-item" data-query="${suggestion}">
                <i class="search icon"></i>
                ${suggestion}
            </div>
        `).join('');
        
        suggestionsContainer.innerHTML = suggestionsHTML;
        suggestionsContainer.style.display = 'block';
        
        // Add click handlers
        suggestionsContainer.querySelectorAll('.suggestion-item').forEach(item => {
            item.addEventListener('click', () => {
                const query = item.dataset.query;
                const searchBox = document.getElementById('search-box');
                if (searchBox) {
                    searchBox.value = query;
                }
                if (this.debouncedSearch) {
                    this.debouncedSearch(query);
                }
                this.hideSuggestions();
            });
        });
    }
    
    /**
     * Hide search suggestions
     */
    hideSuggestions() {
        const suggestionsContainer = document.getElementById('search-suggestions');
        if (suggestionsContainer) {
            suggestionsContainer.style.display = 'none';
        }
    }
    
    /**
     * Show search loading state
     */
    showSearchLoading() {
        const resultsContainer = document.getElementById('search-results');
        if (resultsContainer) {
            resultsContainer.innerHTML = '<div class="spinner"></div>';
        }
    }
    
    /**
     * Hide search loading state
     */
    hideSearchLoading() {
        // Loading is replaced by results
    }
    
    /**
     * Show search error
     */
    showSearchError() {
        const resultsContainer = document.getElementById('search-results');
        if (resultsContainer) {
            resultsContainer.innerHTML = `
                <div class="search-error">
                    <i class="exclamation triangle icon huge"></i>
                    <h3>Search failed</h3>
                    <p>Please try again later</p>
                </div>
            `;
        }
    }
    
    /**
     * Load search history from localStorage
     */
    loadSearchHistory() {
        try {
            const history = localStorage.getItem('searchHistory');
            this.searchHistory = history ? JSON.parse(history) : [];
        } catch (error) {
            this.searchHistory = [];
        }
    }
    
    /**
     * Add query to search history
     */
    addToSearchHistory(query) {
        if (!query) return;
        
        // Remove if already exists
        this.searchHistory = this.searchHistory.filter(q => q !== query);
        
        // Add to beginning
        this.searchHistory.unshift(query);
        
        // Keep only last 10
        this.searchHistory = this.searchHistory.slice(0, 10);
        
        // Save to localStorage
        try {
            localStorage.setItem('searchHistory', JSON.stringify(this.searchHistory));
        } catch (error) {
            console.error('Failed to save search history:', error);
        }
    }
    
    /**
     * Get cache key for search
     */
    getCacheKey(query, filters) {
        return JSON.stringify({ query, filters });
    }
    
    /**
     * Update filters
     */
    updateFilters() {
        if (this.debouncedSearch) {
            this.debouncedSearch(this.currentQuery, { force: true });
        }
    }
    
    /**
     * Set price range filter
     */
    setPriceRangeFilter(minPrice, maxPrice) {
        if (minPrice) {
            this.filters.min_price = minPrice;
        } else {
            delete this.filters.min_price;
        }
        
        if (maxPrice) {
            this.filters.max_price = maxPrice;
        } else {
            delete this.filters.max_price;
        }
        
        this.updateFilters();
    }
    
    /**
     * Set category filter
     */
    setCategoryFilter(category) {
        if (category) {
            this.filters.category = category;
        } else {
            delete this.filters.category;
        }
        
        this.updateFilters();
    }
    
    /**
     * Set location filter
     */
    setLocationFilter(location, radius) {
        if (location) {
            this.filters.location = location;
            if (radius) {
                this.filters.radius = radius;
            }
        } else {
            delete this.filters.location;
            delete this.filters.radius;
        }
        
        this.updateFilters();
    }
}

// Export or create global instance
if (typeof module !== 'undefined' && module.exports) {
    module.exports = BazarSearch;
} else {
    window.BazarSearch = BazarSearch;
}