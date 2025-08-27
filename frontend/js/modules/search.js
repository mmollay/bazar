// Bazar - Search Module

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
        // Initialize debounced functions if BazarUtils is available
        if (typeof BazarUtils !== 'undefined' && BazarUtils.debounce) {
            this.debouncedSearch = BazarUtils.debounce(this.performSearch.bind(this), 300);
            this.debouncedSuggestions = BazarUtils.debounce(this.fetchSuggestions.bind(this), 200);
        } else {
            // Fallback without debouncing
            this.debouncedSearch = this.performSearch.bind(this);
            this.debouncedSuggestions = this.fetchSuggestions.bind(this);
        }
        
        this.loadSearchHistory();
        this.setupSearchUI();
        this.setupLocationSearch();
        this.loadFilterOptions();
        this.loadSavedSearches();
        this.setupAdvancedFilters();
    }

    /**
     * Setup advanced filters UI
     */
    setupAdvancedFilters() {
        // Price range inputs
        const minPriceInput = document.getElementById('filter-min-price');
        const maxPriceInput = document.getElementById('filter-max-price');
        
        if (minPriceInput) {
            minPriceInput.addEventListener('input', BazarUtils.debounce(() => {
                this.setPriceRangeFilter(minPriceInput.value, this.filters.max_price);
            }, 500));
        }
        
        if (maxPriceInput) {
            maxPriceInput.addEventListener('input', BazarUtils.debounce(() => {
                this.setPriceRangeFilter(this.filters.min_price, maxPriceInput.value);
            }, 500));
        }
        
        // Category dropdown
        const categorySelect = document.getElementById('filter-category');
        if (categorySelect) {
            categorySelect.addEventListener('change', (e) => {
                if (e.target.value) {
                    this.searchByCategory(e.target.value);
                } else {
                    delete this.filters.category;
                    this.updateFilters();
                }
            });
        }
        
        // Condition checkboxes
        document.addEventListener('change', (e) => {
            if (e.target.name === 'condition') {
                const checkedConditions = Array.from(
                    document.querySelectorAll('input[name="condition"]:checked')
                ).map(input => input.value);
                
                this.setConditionFilter(checkedConditions);
            }
        });
        
        // Sort dropdown
        const sortSelect = document.getElementById('sort-select');
        if (sortSelect) {
            sortSelect.addEventListener('change', (e) => {
                this.setSortFilter(e.target.value);
            });
        }
        
        // Featured toggle
        const featuredToggle = document.getElementById('filter-featured');
        if (featuredToggle) {
            featuredToggle.addEventListener('change', () => {
                this.toggleFeaturedFilter();
            });
        }
        
        // Clear filters button
        const clearFiltersBtn = document.getElementById('clear-filters');
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', () => {
                this.clearFilters();
            });
        }
        
        // Save search button
        const saveSearchBtn = document.getElementById('save-search-btn');
        if (saveSearchBtn) {
            saveSearchBtn.addEventListener('click', () => {
                this.showSaveSearchModal();
            });
        }
        
        // Advanced filters toggle
        const advancedToggle = document.getElementById('advanced-filters-toggle');
        const advancedPanel = document.getElementById('advanced-filters-panel');
        
        if (advancedToggle && advancedPanel) {
            advancedToggle.addEventListener('click', () => {
                const isVisible = advancedPanel.style.display !== 'none';
                advancedPanel.style.display = isVisible ? 'none' : 'block';
                advancedToggle.textContent = isVisible ? 'Show Advanced Filters' : 'Hide Advanced Filters';
            });
        }
    }

    /**
     * Show save search modal
     */
    showSaveSearchModal() {
        const modal = document.getElementById('save-search-modal');
        const nameInput = document.getElementById('save-search-name');
        const alertsCheckbox = document.getElementById('save-search-alerts');
        
        if (modal) {
            modal.classList.add('active');
            if (nameInput) {
                nameInput.value = this.currentQuery || 'My Search';
                nameInput.focus();
            }
        }
        
        // Handle save button
        const saveBtn = document.getElementById('confirm-save-search');
        if (saveBtn) {
            saveBtn.onclick = () => {
                const name = nameInput?.value.trim();
                const alerts = alertsCheckbox?.checked || false;
                
                if (name) {
                    this.saveCurrentSearch(name, alerts);
                    modal.classList.remove('active');
                }
            };
        }
        
        // Handle cancel button
        const cancelBtn = document.getElementById('cancel-save-search');
        if (cancelBtn) {
            cancelBtn.onclick = () => {
                modal.classList.remove('active');
            };
        }
    }
    
    /**
     * Setup search UI elements and event listeners
     */
    setupSearchUI() {
        // Main search input
        const mainSearch = document.getElementById('main-search');
        if (mainSearch) {
            this.setupSearchInput(mainSearch);
        }
        
        // Search form submission
        document.addEventListener('submit', (e) => {
            if (e.target.matches('.search-form') || e.target.closest('.search-form')) {
                e.preventDefault();
                const input = e.target.querySelector('input[type="text"], input[type="search"]');
                if (input) {
                    this.search(input.value.trim());
                }
            }
        });
        
        // Category card clicks
        document.addEventListener('click', (e) => {
            const categoryCard = e.target.closest('.category-card');
            if (categoryCard) {
                const category = categoryCard.getAttribute('data-category');
                if (category) {
                    this.searchByCategory(category);
                }
            }
        });
        
        // Filter changes
        document.addEventListener('change', (e) => {
            if (e.target.matches('.search-filter')) {
                this.updateFilters();
            }
        });
    }
    
    /**
     * Setup individual search input
     * @param {HTMLElement} input - Search input element
     */
    setupSearchInput(input) {
        let suggestionsContainer = input.parentElement.querySelector('.search-suggestions');
        
        if (!suggestionsContainer) {
            suggestionsContainer = document.createElement('div');
            suggestionsContainer.className = 'search-suggestions';
            suggestionsContainer.style.display = 'none';
            input.parentElement.appendChild(suggestionsContainer);
        }
        
        // Input event for suggestions
        input.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            this.currentQuery = query;
            
            if (query.length >= 2) {
                this.debouncedSuggestions(query, suggestionsContainer);
            } else {
                this.hideSuggestions(suggestionsContainer);
            }
        });
        
        // Enter key for search
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.search(input.value.trim());
                this.hideSuggestions(suggestionsContainer);
            } else if (e.key === 'Escape') {
                this.hideSuggestions(suggestionsContainer);
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                this.navigateSuggestions(e, suggestionsContainer);
            }
        });
        
        // Click outside to hide suggestions
        document.addEventListener('click', (e) => {
            if (!input.contains(e.target) && !suggestionsContainer.contains(e.target)) {
                this.hideSuggestions(suggestionsContainer);
            }
        });
        
        // Focus to show recent searches
        input.addEventListener('focus', () => {
            if (input.value.length === 0) {
                this.showRecentSearches(suggestionsContainer);
            }
        });
    }
    
    /**
     * Perform search
     * @param {string} query - Search query
     * @param {Object} options - Search options
     * @returns {Promise} Search results
     */
    async search(query, options = {}) {
        if (!query.trim()) {
            return this.clearResults();
        }
        
        this.currentQuery = query.trim();
        this.isSearching = true;
        
        try {
            // Add to search history
            this.addToHistory(this.currentQuery);
            
            // Check cache first
            const cacheKey = this.getCacheKey(this.currentQuery, this.filters);
            const cachedResult = this.getFromCache(cacheKey);
            
            if (cachedResult && !options.force) {
                this.displayResults(cachedResult);
                return cachedResult;
            }
            
            // Show loading state
            this.showSearchLoading();
            
            // Build search parameters
            const searchParams = {
                q: this.currentQuery,
                ...this.filters,
                ...options
            };
            
            // Perform API search
            const results = await api.searchArticles(searchParams);
            
            // Cache results
            this.addToCache(cacheKey, results);
            
            // Display results
            this.displayResults(results);
            
            // Update URL
            this.updateSearchURL(this.currentQuery, this.filters);
            
            return results;
            
        } catch (error) {
            console.error('Search failed:', error);
            this.showSearchError(error);
            throw error;
        } finally {
            this.isSearching = false;
            this.hideSearchLoading();
        }
    }
    
    /**
     * Search by category
     * @param {string} category - Category to search
     */
    searchByCategory(category) {
        this.filters.category = category;
        this.updateFilters();
        
        // Navigate to search results page
        const query = this.currentQuery || '';
        router.navigate(`/search?q=${encodeURIComponent(query)}&category=${encodeURIComponent(category)}`);
    }

    /**
     * Set location filter
     * @param {Object} location - Location object with lat, lng, address
     * @param {number} radius - Search radius in km
     */
    setLocationFilter(location, radius = 10) {
        if (location && location.lat && location.lng) {
            this.filters.latitude = location.lat;
            this.filters.longitude = location.lng;
            this.filters.location = location.address;
            this.filters.radius = radius;
        } else {
            delete this.filters.latitude;
            delete this.filters.longitude;
            delete this.filters.location;
            delete this.filters.radius;
        }
        this.updateFilters();
    }

    /**
     * Set price range filter
     * @param {number} minPrice - Minimum price
     * @param {number} maxPrice - Maximum price
     */
    setPriceRangeFilter(minPrice, maxPrice) {
        if (minPrice !== null && minPrice !== '') {
            this.filters.min_price = parseFloat(minPrice);
        } else {
            delete this.filters.min_price;
        }
        
        if (maxPrice !== null && maxPrice !== '') {
            this.filters.max_price = parseFloat(maxPrice);
        } else {
            delete this.filters.max_price;
        }
        
        this.updateFilters();
    }

    /**
     * Set condition filter
     * @param {string|Array} conditions - Single condition or array of conditions
     */
    setConditionFilter(conditions) {
        if (conditions && conditions.length > 0) {
            this.filters.condition = Array.isArray(conditions) ? conditions : [conditions];
        } else {
            delete this.filters.condition;
        }
        this.updateFilters();
    }

    /**
     * Set sort option
     * @param {string} sort - Sort option
     */
    setSortFilter(sort) {
        this.filters.sort = sort;
        this.performSearch(this.currentQuery, { force: true });
    }

    /**
     * Toggle featured filter
     */
    toggleFeaturedFilter() {
        if (this.filters.featured) {
            delete this.filters.featured;
        } else {
            this.filters.featured = true;
        }
        this.updateFilters();
    }

    /**
     * Clear all filters
     */
    clearFilters() {
        this.filters = {};
        this.updateFilters();
        this.updateFilterUI();
    }

    /**
     * Get available filter options from API
     */
    async loadFilterOptions() {
        try {
            const options = await api.get('/search/filters');
            this.filterOptions = options;
            this.updateFilterOptionsUI();
            return options;
        } catch (error) {
            console.error('Failed to load filter options:', error);
            return null;
        }
    }

    /**
     * Update filter options in UI
     */
    updateFilterOptionsUI() {
        if (!this.filterOptions) return;

        // Update category dropdown
        this.updateCategoryOptions();
        
        // Update price range sliders
        this.updatePriceRangeOptions();
        
        // Update condition checkboxes
        this.updateConditionOptions();
        
        // Update location suggestions
        this.updateLocationOptions();
    }

    /**
     * Update category options
     */
    updateCategoryOptions() {
        const categorySelect = document.getElementById('filter-category');
        if (!categorySelect || !this.filterOptions.categories) return;

        let html = '<option value="">All Categories</option>';
        
        this.filterOptions.categories.forEach(category => {
            const selected = this.filters.category === category.slug ? 'selected' : '';
            const articleCount = category.article_count ? ` (${category.article_count})` : '';
            html += `<option value="${category.slug}" ${selected}>${BazarUtils.sanitizeHTML(category.name)}${articleCount}</option>`;
        });
        
        categorySelect.innerHTML = html;
    }

    /**
     * Update price range options
     */
    updatePriceRangeOptions() {
        const minPriceInput = document.getElementById('filter-min-price');
        const maxPriceInput = document.getElementById('filter-max-price');
        const priceRangeInfo = document.getElementById('price-range-info');
        
        if (!this.filterOptions.price_range) return;

        const { min_price, max_price, avg_price } = this.filterOptions.price_range;
        
        if (minPriceInput) {
            minPriceInput.setAttribute('min', min_price || 0);
            minPriceInput.setAttribute('max', max_price || 10000);
            minPriceInput.value = this.filters.min_price || '';
        }
        
        if (maxPriceInput) {
            maxPriceInput.setAttribute('min', min_price || 0);
            maxPriceInput.setAttribute('max', max_price || 10000);
            maxPriceInput.value = this.filters.max_price || '';
        }
        
        if (priceRangeInfo) {
            priceRangeInfo.textContent = `Range: €${min_price || 0} - €${max_price || 0} (Avg: €${Math.round(avg_price || 0)})`;
        }
    }

    /**
     * Update condition options
     */
    updateConditionOptions() {
        const conditionContainer = document.getElementById('filter-conditions');
        if (!conditionContainer || !this.filterOptions.conditions) return;

        let html = '';
        const conditionLabels = {
            'new': 'New',
            'like_new': 'Like New',
            'good': 'Good',
            'fair': 'Fair',
            'poor': 'Poor'
        };

        this.filterOptions.conditions.forEach(condition => {
            const checked = this.filters.condition && this.filters.condition.includes(condition.condition_type) ? 'checked' : '';
            const label = conditionLabels[condition.condition_type] || condition.condition_type;
            
            html += `
                <label class="ui checkbox">
                    <input type="checkbox" name="condition" value="${condition.condition_type}" ${checked}>
                    <span class="checkmark"></span>
                    ${label} (${condition.count})
                </label>
            `;
        });
        
        conditionContainer.innerHTML = html;
    }

    /**
     * Update location options
     */
    updateLocationOptions() {
        const locationDatalist = document.getElementById('location-suggestions');
        if (!locationDatalist || !this.filterOptions.popular_locations) return;

        let html = '';
        this.filterOptions.popular_locations.forEach(location => {
            html += `<option value="${BazarUtils.sanitizeHTML(location.location)}">`;
        });
        
        locationDatalist.innerHTML = html;
    }
    
    /**
     * Fetch search suggestions
     * @param {string} query - Search query
     * @param {HTMLElement} container - Suggestions container
     */
    async fetchSuggestions(query, container) {
        if (!query || query.length < 2) {
            return this.hideSuggestions(container);
        }
        
        try {
            // Get suggestions from API
            const response = await api.get('/search/suggestions', { q: query, limit: 8 });
            const suggestions = response.suggestions || [];
            const popular = response.popular_searches || [];
            
            // Combine API suggestions with local ones
            const allSuggestions = [
                ...suggestions.map(s => ({ text: s, type: 'api' })),
                ...this.getLocalSuggestions(query).filter(local => 
                    !suggestions.includes(local.text)
                ).slice(0, 3)
            ];
            
            // Add popular searches if not enough suggestions
            if (allSuggestions.length < 5) {
                popular.forEach(pop => {
                    if (pop.query.toLowerCase().includes(query.toLowerCase()) && 
                        !allSuggestions.find(s => s.text === pop.query)) {
                        allSuggestions.push({ text: pop.query, type: 'popular' });
                    }
                });
            }
            
            this.showSuggestions(allSuggestions.slice(0, 8), container);
            
        } catch (error) {
            console.warn('Failed to fetch suggestions from API:', error);
            // Fallback to local suggestions
            const suggestions = this.getLocalSuggestions(query);
            this.showSuggestions(suggestions, container);
        }
    }

    /**
     * Get user's current location
     */
    async getCurrentLocation() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation is not supported'));
                return;
            }
            
            const options = {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 300000 // 5 minutes
            };
            
            navigator.geolocation.getCurrentPosition(
                position => {
                    resolve({
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                        accuracy: position.coords.accuracy
                    });
                },
                error => {
                    reject(error);
                },
                options
            );
        });
    }

    /**
     * Geocode address to coordinates
     * @param {string} address - Address to geocode
     */
    async geocodeAddress(address) {
        try {
            // Using a simple geocoding service (you can replace with Google Maps API)
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}&limit=1`);
            const results = await response.json();
            
            if (results && results.length > 0) {
                return {
                    lat: parseFloat(results[0].lat),
                    lng: parseFloat(results[0].lon),
                    address: results[0].display_name
                };
            }
            
            return null;
        } catch (error) {
            console.error('Geocoding failed:', error);
            return null;
        }
    }

    /**
     * Setup location-based search
     */
    setupLocationSearch() {
        const locationInput = document.getElementById('location-input');
        const locationBtn = document.getElementById('location-btn');
        const radiusSlider = document.getElementById('radius-slider');
        
        if (locationInput) {
            locationInput.addEventListener('input', BazarUtils.debounce(async (e) => {
                const address = e.target.value.trim();
                if (address.length > 3) {
                    const location = await this.geocodeAddress(address);
                    if (location) {
                        this.setLocationFilter(location);
                    }
                }
            }, 500));
        }
        
        if (locationBtn) {
            locationBtn.addEventListener('click', async () => {
                locationBtn.classList.add('loading');
                try {
                    const location = await this.getCurrentLocation();
                    // Reverse geocode to get address
                    const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${location.lat}&lon=${location.lng}`);
                    const result = await response.json();
                    
                    location.address = result.display_name || `${location.lat}, ${location.lng}`;
                    
                    if (locationInput) {
                        locationInput.value = location.address;
                    }
                    
                    this.setLocationFilter(location);
                    BazarUtils.showToast('Location set successfully', 'success');
                } catch (error) {
                    console.error('Failed to get location:', error);
                    BazarUtils.showToast('Could not get your location', 'error');
                } finally {
                    locationBtn.classList.remove('loading');
                }
            });
        }
        
        if (radiusSlider) {
            radiusSlider.addEventListener('input', (e) => {
                const radius = parseInt(e.target.value);
                document.getElementById('radius-value').textContent = `${radius}km`;
                
                if (this.filters.latitude && this.filters.longitude) {
                    this.filters.radius = radius;
                    this.debouncedSearch(this.currentQuery, { force: true });
                }
            });
        }
    }

    /**
     * Enhanced saved searches
     */
    async loadSavedSearches() {
        try {
            const response = await api.get('/search/saved');
            this.savedSearches = response.saved_searches || [];
            this.updateSavedSearchesUI();
        } catch (error) {
            console.warn('Failed to load saved searches:', error);
        }
    }

    /**
     * Save current search
     */
    async saveCurrentSearch(name, emailAlerts = false) {
        try {
            await api.post('/search/save', {
                name: name,
                query: this.currentQuery,
                filters: this.filters,
                email_alerts: emailAlerts
            });
            
            BazarUtils.showToast('Search saved successfully', 'success');
            this.loadSavedSearches(); // Reload saved searches
            
        } catch (error) {
            console.error('Failed to save search:', error);
            BazarUtils.showToast('Failed to save search', 'error');
        }
    }

    /**
     * Update saved searches UI
     */
    updateSavedSearchesUI() {
        const container = document.getElementById('saved-searches-list');
        if (!container || !this.savedSearches) return;
        
        if (this.savedSearches.length === 0) {
            container.innerHTML = '<p class="no-saved-searches">No saved searches yet</p>';
            return;
        }
        
        const html = this.savedSearches.map(search => `
            <div class="saved-search-item" data-id="${search.id}">
                <div class="search-info">
                    <h4>${BazarUtils.sanitizeHTML(search.name)}</h4>
                    <p class="search-query">${BazarUtils.sanitizeHTML(search.query || 'Browse all')}</p>
                    <p class="search-meta">
                        ${search.current_results_count} results
                        ${search.email_alerts ? ' • Alerts ON' : ''}
                        ${search.has_new_results ? ' • New results!' : ''}
                    </p>
                </div>
                <div class="search-actions">
                    <button class="btn btn-small btn-primary load-search">Load</button>
                    <button class="btn btn-small btn-danger delete-search">Delete</button>
                </div>
            </div>
        `).join('');
        
        container.innerHTML = html;
        
        // Add event listeners
        container.addEventListener('click', (e) => {
            const searchItem = e.target.closest('.saved-search-item');
            if (!searchItem) return;
            
            const searchId = parseInt(searchItem.getAttribute('data-id'));
            const search = this.savedSearches.find(s => s.id === searchId);
            
            if (e.target.classList.contains('load-search')) {
                this.loadSavedSearch(search);
            } else if (e.target.classList.contains('delete-search')) {
                this.deleteSavedSearch(searchId);
            }
        });
    }

    /**
     * Load saved search
     */
    loadSavedSearch(search) {
        if (!search) return;
        
        // Set filters
        this.filters = JSON.parse(search.filters || '{}');
        
        // Perform search
        this.search(search.query || '');
        
        // Close saved searches modal if open
        const modal = document.getElementById('saved-searches-modal');
        if (modal && modal.classList.contains('active')) {
            modal.classList.remove('active');
        }
    }

    /**
     * Delete saved search
     */
    async deleteSavedSearch(searchId) {
        if (!confirm('Are you sure you want to delete this saved search?')) {
            return;
        }
        
        try {
            await api.delete(`/search/saved/${searchId}`);
            BazarUtils.showToast('Saved search deleted', 'success');
            this.loadSavedSearches(); // Reload list
        } catch (error) {
            console.error('Failed to delete saved search:', error);
            BazarUtils.showToast('Failed to delete saved search', 'error');
        }
    }
    
    /**
     * Get local suggestions based on search history
     * @param {string} query - Search query
     * @returns {Array} Suggestions array
     */
    getLocalSuggestions(query) {
        const lowerQuery = query.toLowerCase();
        
        // Filter search history
        const historySuggestions = this.searchHistory
            .filter(item => item.toLowerCase().includes(lowerQuery))
            .slice(0, 5)
            .map(item => ({ text: item, type: 'history' }));
        
        // Add popular searches (hardcoded for now)
        const popularSearches = [
            'iPhone', 'Samsung', 'Auto', 'Wohnung', 'Fahrrad',
            'Kleidung', 'Möbel', 'Laptop', 'Handy', 'Spielzeug'
        ];
        
        const popularSuggestions = popularSearches
            .filter(item => item.toLowerCase().includes(lowerQuery))
            .slice(0, 3)
            .map(item => ({ text: item, type: 'popular' }));
        
        return [...historySuggestions, ...popularSuggestions].slice(0, 8);
    }
    
    /**
     * Show search suggestions
     * @param {Array} suggestions - Suggestions array
     * @param {HTMLElement} container - Suggestions container
     */
    showSuggestions(suggestions, container) {
        if (!suggestions || suggestions.length === 0) {
            return this.hideSuggestions(container);
        }
        
        const html = suggestions.map((suggestion, index) => {
            const icon = suggestion.type === 'history' ? 'history' : 'search';
            return `
                <div class="suggestion-item" data-index="${index}" data-text="${BazarUtils.sanitizeHTML(suggestion.text)}">
                    <i class="icon ${icon}"></i>
                    <span>${BazarUtils.sanitizeHTML(suggestion.text)}</span>
                    ${suggestion.type === 'history' ? '<i class="times icon remove-suggestion" data-action="remove"></i>' : ''}
                </div>
            `;
        }).join('');
        
        container.innerHTML = html;
        container.style.display = 'block';
        
        // Add click handlers
        container.addEventListener('click', this.handleSuggestionClick.bind(this));
    }
    
    /**
     * Show recent searches
     * @param {HTMLElement} container - Suggestions container
     */
    showRecentSearches(container) {
        if (this.searchHistory.length === 0) {
            return this.hideSuggestions(container);
        }
        
        const recentSearches = this.searchHistory
            .slice(-5)
            .reverse()
            .map(item => ({ text: item, type: 'history' }));
        
        this.showSuggestions(recentSearches, container);
    }
    
    /**
     * Hide search suggestions
     * @param {HTMLElement} container - Suggestions container
     */
    hideSuggestions(container) {
        container.style.display = 'none';
        container.innerHTML = '';
    }
    
    /**
     * Handle suggestion click
     * @param {Event} event - Click event
     */
    handleSuggestionClick(event) {
        event.preventDefault();
        
        const suggestionItem = event.target.closest('.suggestion-item');
        if (!suggestionItem) return;
        
        const action = event.target.getAttribute('data-action');
        
        if (action === 'remove') {
            // Remove from history
            const text = suggestionItem.getAttribute('data-text');
            this.removeFromHistory(text);
            suggestionItem.remove();
        } else {
            // Select suggestion
            const text = suggestionItem.getAttribute('data-text');
            const searchInput = document.getElementById('main-search');
            if (searchInput) {
                searchInput.value = text;
            }
            this.search(text);
            this.hideSuggestions(suggestionItem.parentElement);
        }
    }
    
    /**
     * Navigate suggestions with keyboard
     * @param {KeyboardEvent} event - Keyboard event
     * @param {HTMLElement} container - Suggestions container
     */
    navigateSuggestions(event, container) {
        event.preventDefault();
        
        const suggestions = container.querySelectorAll('.suggestion-item');
        if (suggestions.length === 0) return;
        
        const current = container.querySelector('.suggestion-item.highlighted');
        let index = current ? parseInt(current.getAttribute('data-index')) : -1;
        
        if (event.key === 'ArrowDown') {
            index = Math.min(index + 1, suggestions.length - 1);
        } else if (event.key === 'ArrowUp') {
            index = Math.max(index - 1, 0);
        }
        
        // Update highlighting
        suggestions.forEach((item, i) => {
            item.classList.toggle('highlighted', i === index);
        });
        
        // Update search input
        const searchInput = event.target;
        const selectedSuggestion = suggestions[index];
        if (selectedSuggestion) {
            searchInput.value = selectedSuggestion.getAttribute('data-text');
        }
    }
    
    /**
     * Display search results
     * @param {Object} results - Search results
     */
    displayResults(results) {
        this.results = results;
        this.currentPage = results.page || 1;
        this.totalPages = results.total_pages || 1;
        
        // Navigate to search results page if not already there
        if (!window.location.pathname.startsWith('/search')) {
            const query = encodeURIComponent(this.currentQuery);
            const filterParams = new URLSearchParams(this.filters).toString();
            const url = `/search?q=${query}${filterParams ? '&' + filterParams : ''}`;
            router.navigate(url);
            return;
        }
        
        // Update search info
        this.updateSearchInfo(results);
        
        // Update results container
        const resultsContainer = document.getElementById('search-results');
        if (resultsContainer) {
            this.renderResults(results, resultsContainer);
        }
        
        // Update pagination
        this.updatePagination(results);
        
        // Update filter summary
        this.updateFilterSummary();
    }

    /**
     * Update search info display
     */
    updateSearchInfo(results) {
        const searchInfo = document.getElementById('search-info');
        const searchMeta = document.getElementById('search-meta');
        
        if (searchInfo) {
            const title = this.currentQuery ? 
                `Search results for "${this.currentQuery}"` : 
                'Browse all items';
            searchInfo.querySelector('h1').textContent = title;
        }
        
        if (searchMeta) {
            const { total, search_time_ms } = results.meta || {};
            let metaText = `${total || 0} results found`;
            
            if (search_time_ms) {
                metaText += ` in ${search_time_ms}ms`;
            }
            
            if (results.page && results.total_pages > 1) {
                metaText += ` • Page ${results.page} of ${results.total_pages}`;
            }
            
            searchMeta.textContent = metaText;
        }
    }

    /**
     * Update pagination controls
     */
    updatePagination(results) {
        const pagination = document.getElementById('pagination');
        const prevBtn = document.getElementById('prev-page');
        const nextBtn = document.getElementById('next-page');
        const pageNumbers = document.getElementById('page-numbers');
        
        if (!pagination) return;
        
        const { page = 1, total_pages = 1 } = results;
        
        if (total_pages <= 1) {
            pagination.style.display = 'none';
            return;
        }
        
        pagination.style.display = 'flex';
        
        // Update prev/next buttons
        if (prevBtn) {
            prevBtn.disabled = page <= 1;
        }
        if (nextBtn) {
            nextBtn.disabled = page >= total_pages;
        }
        
        // Update page numbers
        if (pageNumbers) {
            const pageNumbersHtml = this.generatePageNumbers(page, total_pages);
            pageNumbers.innerHTML = pageNumbersHtml;
            
            // Add click handlers for page numbers
            pageNumbers.addEventListener('click', (e) => {
                if (e.target.classList.contains('page-number')) {
                    const targetPage = parseInt(e.target.getAttribute('data-page'));
                    if (targetPage !== page) {
                        this.goToPage(targetPage);
                    }
                }
            });
        }
    }

    /**
     * Generate page numbers HTML
     */
    generatePageNumbers(currentPage, totalPages) {
        const pages = [];
        const maxVisible = 7;
        
        if (totalPages <= maxVisible) {
            // Show all pages
            for (let i = 1; i <= totalPages; i++) {
                pages.push(i);
            }
        } else {
            // Show smart pagination
            if (currentPage <= 4) {
                for (let i = 1; i <= 5; i++) pages.push(i);
                pages.push('...');
                pages.push(totalPages);
            } else if (currentPage >= totalPages - 3) {
                pages.push(1);
                pages.push('...');
                for (let i = totalPages - 4; i <= totalPages; i++) pages.push(i);
            } else {
                pages.push(1);
                pages.push('...');
                for (let i = currentPage - 1; i <= currentPage + 1; i++) pages.push(i);
                pages.push('...');
                pages.push(totalPages);
            }
        }
        
        return pages.map(page => {
            if (page === '...') {
                return '<span class="page-ellipsis">...</span>';
            }
            
            const isActive = page === currentPage ? 'active' : '';
            return `<button class="page-number ${isActive}" data-page="${page}">${page}</button>`;
        }).join('');
    }

    /**
     * Go to specific page
     */
    async goToPage(page) {
        if (page === this.currentPage) return;
        
        try {
            const results = await this.search(this.currentQuery, { 
                page, 
                force: true,
                ...this.filters 
            });
            
            // Scroll to top of results
            const resultsContainer = document.getElementById('search-results');
            if (resultsContainer) {
                resultsContainer.scrollIntoView({ behavior: 'smooth' });
            }
            
        } catch (error) {
            console.error('Failed to load page:', error);
            BazarUtils.showToast('Failed to load page', 'error');
        }
    }

    /**
     * Update filter summary
     */
    updateFilterSummary() {
        const filterSummary = document.getElementById('filter-summary');
        const activeFilters = document.getElementById('active-filters');
        
        if (!filterSummary || !activeFilters) return;
        
        const filters = Object.entries(this.filters).filter(([key, value]) => {
            return value !== null && value !== '' && key !== 'sort';
        });
        
        if (filters.length === 0) {
            filterSummary.style.display = 'none';
            return;
        }
        
        filterSummary.style.display = 'block';
        
        const filterTags = filters.map(([key, value]) => {
            const displayName = this.getFilterDisplayName(key, value);
            return `
                <span class="filter-tag" data-filter="${key}">
                    ${displayName}
                    <button class="remove-filter" data-filter="${key}">&times;</button>
                </span>
            `;
        }).join('');
        
        activeFilters.innerHTML = filterTags;
        
        // Add remove filter handlers
        activeFilters.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-filter')) {
                const filterKey = e.target.getAttribute('data-filter');
                this.removeFilter(filterKey);
            }
        });
    }

    /**
     * Get display name for filter
     */
    getFilterDisplayName(key, value) {
        const filterLabels = {
            category: 'Category',
            min_price: 'Min Price',
            max_price: 'Max Price',
            condition: 'Condition',
            location: 'Location',
            radius: 'Within',
            featured: 'Featured'
        };
        
        const label = filterLabels[key] || key;
        
        switch (key) {
            case 'condition':
                return `${label}: ${Array.isArray(value) ? value.join(', ') : value}`;
            case 'min_price':
                return `${label}: €${value}+`;
            case 'max_price':
                return `${label}: €${value}-`;
            case 'radius':
                return `${label}: ${value}km`;
            case 'featured':
                return 'Featured only';
            default:
                return `${label}: ${value}`;
        }
    }

    /**
     * Remove specific filter
     */
    removeFilter(filterKey) {
        delete this.filters[filterKey];
        
        // Update UI elements
        const filterElement = document.getElementById(`filter-${filterKey.replace('_', '-')}`);
        if (filterElement) {
            if (filterElement.type === 'checkbox') {
                filterElement.checked = false;
            } else {
                filterElement.value = '';
            }
        }
        
        // Handle special cases
        if (filterKey === 'condition') {
            const conditionCheckboxes = document.querySelectorAll('input[name="condition"]');
            conditionCheckboxes.forEach(cb => cb.checked = false);
        }
        
        // Re-run search
        this.updateFilters();
    }

    /**
     * Load popular searches for empty state
     */
    async loadPopularSearches() {
        try {
            const response = await api.get('/search/suggestions', { q: '', limit: 10 });
            const popular = response.popular_searches || [];
            
            const container = document.getElementById('popular-searches-container');
            if (container && popular.length > 0) {
                container.innerHTML = `
                    <h4>Popular Searches</h4>
                    <div class="popular-search-tags">
                        ${popular.map(item => 
                            `<button class="popular-search-tag" data-query="${BazarUtils.sanitizeHTML(item.query)}">
                                ${BazarUtils.sanitizeHTML(item.query)}
                            </button>`
                        ).join('')}
                    </div>
                `;
                
                // Add click handlers
                container.addEventListener('click', (e) => {
                    if (e.target.classList.contains('popular-search-tag')) {
                        const query = e.target.getAttribute('data-query');
                        this.search(query);
                    }
                });
            }
        } catch (error) {
            console.warn('Failed to load popular searches:', error);
        }
    }
    
    /**
     * Render search results
     * @param {Object} results - Search results
     * @param {HTMLElement} container - Results container
     */
    renderResults(results, container) {
        if (!results.articles || results.articles.length === 0) {
            container.innerHTML = `
                <div class="ui segment placeholder">
                    <div class="ui icon header">
                        <i class="search icon"></i>
                        Keine Ergebnisse gefunden
                    </div>
                    <p>Versuchen Sie andere Suchbegriffe oder erweitern Sie Ihre Suchkriterien.</p>
                </div>
            `;
            return;
        }
        
        const articlesHTML = results.articles.map(article => this.renderArticleCard(article)).join('');
        
        container.innerHTML = `
            <div class="search-summary">
                <h3>${results.total} Ergebnisse für "${BazarUtils.sanitizeHTML(this.currentQuery)}"</h3>
            </div>
            <div class="ui stackable four column grid">
                ${articlesHTML}
            </div>
        `;
        
        // Add pagination if needed
        if (results.total > results.articles.length) {
            this.renderPagination(results, container);
        }
    }
    
    /**
     * Render article card
     * @param {Object} article - Article data
     * @returns {string} Article card HTML
     */
    renderArticleCard(article) {
        const price = BazarUtils.formatCurrency(article.price);
        const date = BazarUtils.formatDate(article.createdAt, 'relative');
        const location = article.location || 'Unbekannt';
        
        return `
            <div class="column">
                <div class="ui card article-card" data-id="${article.id}">
                    <div class="image">
                        <img src="${article.image || '/frontend/assets/images/placeholder.svg'}" 
                             alt="${BazarUtils.sanitizeHTML(article.title)}"
                             loading="lazy">
                    </div>
                    <div class="content">
                        <div class="header">${BazarUtils.sanitizeHTML(article.title)}</div>
                        <div class="meta">
                            <span class="price">${price}</span>
                            <span class="location">${BazarUtils.sanitizeHTML(location)}</span>
                        </div>
                        <div class="description">
                            ${BazarUtils.sanitizeHTML(article.description?.substring(0, 100) || '')}...
                        </div>
                    </div>
                    <div class="extra content">
                        <span class="date">${date}</span>
                        <a href="/articles/${article.id}" class="ui right floated primary button mini">
                            Ansehen
                        </a>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Update search filters
     */
    updateFilters() {
        const filterElements = document.querySelectorAll('.search-filter');
        this.filters = {};
        
        filterElements.forEach(element => {
            const name = element.name;
            const value = element.type === 'checkbox' ? 
                (element.checked ? element.value : null) : 
                element.value;
            
            if (value && value !== '') {
                this.filters[name] = value;
            }
        });
        
        // Re-search with new filters
        if (this.currentQuery) {
            this.debouncedSearch(this.currentQuery, { force: true });
        }
    }
    
    /**
     * Clear search results
     */
    clearResults() {
        this.results = [];
        this.currentQuery = '';
        
        const resultsContainer = document.getElementById('search-results');
        if (resultsContainer) {
            resultsContainer.innerHTML = '';
        }
    }
    
    /**
     * Add query to search history
     * @param {string} query - Search query
     */
    addToHistory(query) {
        if (!query || query.length < 2) return;
        
        // Remove if already exists
        this.removeFromHistory(query);
        
        // Add to beginning
        this.searchHistory.unshift(query);
        
        // Keep only last 20 searches
        this.searchHistory = this.searchHistory.slice(0, 20);
        
        // Save to localStorage
        this.saveSearchHistory();
    }
    
    /**
     * Remove query from search history
     * @param {string} query - Search query to remove
     */
    removeFromHistory(query) {
        const index = this.searchHistory.indexOf(query);
        if (index > -1) {
            this.searchHistory.splice(index, 1);
            this.saveSearchHistory();
        }
    }
    
    /**
     * Load search history from localStorage
     */
    loadSearchHistory() {
        this.searchHistory = BazarUtils.getLocalStorage('searchHistory', []);
    }
    
    /**
     * Save search history to localStorage
     */
    saveSearchHistory() {
        BazarUtils.setLocalStorage('searchHistory', this.searchHistory);
    }
    
    /**
     * Get cache key for search results
     * @param {string} query - Search query
     * @param {Object} filters - Search filters
     * @returns {string} Cache key
     */
    getCacheKey(query, filters) {
        const filterStr = Object.keys(filters).sort().map(key => `${key}:${filters[key]}`).join('|');
        return `search:${query}:${filterStr}`;
    }
    
    /**
     * Get search results from cache
     * @param {string} key - Cache key
     * @returns {Object|null} Cached results or null
     */
    getFromCache(key) {
        const cached = this.searchCache.get(key);
        if (cached && (Date.now() - cached.timestamp) < this.cacheTimeout) {
            return cached.data;
        }
        this.searchCache.delete(key);
        return null;
    }
    
    /**
     * Add search results to cache
     * @param {string} key - Cache key
     * @param {Object} data - Search results
     */
    addToCache(key, data) {
        this.searchCache.set(key, {
            data,
            timestamp: Date.now()
        });
        
        // Clean old cache entries
        if (this.searchCache.size > 100) {
            const oldestKeys = Array.from(this.searchCache.keys()).slice(0, 50);
            oldestKeys.forEach(key => this.searchCache.delete(key));
        }
    }
    
    /**
     * Show search loading state
     */
    showSearchLoading() {
        const searchIcon = document.querySelector('#main-search + .icon');
        if (searchIcon) {
            searchIcon.className = 'loading icon';
        }
    }
    
    /**
     * Hide search loading state
     */
    hideSearchLoading() {
        const searchIcon = document.querySelector('#main-search + .icon');
        if (searchIcon) {
            searchIcon.className = 'search icon';
        }
    }
    
    /**
     * Show search error
     * @param {Error} error - Error object
     */
    showSearchError(error) {
        const message = error.message || 'Suche fehlgeschlagen';
        BazarUtils.showToast(message, 'error');
    }
    
    /**
     * Update search URL
     * @param {string} query - Search query
     * @param {Object} filters - Search filters
     */
    updateSearchURL(query, filters) {
        const params = new URLSearchParams();
        if (query) params.set('q', query);
        
        Object.keys(filters).forEach(key => {
            if (filters[key]) {
                params.set(key, filters[key]);
            }
        });
        
        const newURL = `/search${params.toString() ? '?' + params.toString() : ''}`;
        window.history.replaceState(null, '', newURL);
    }
    
    /**
     * Load saved searches (if user is authenticated)
     */
    loadSavedSearches() {
        // Implementation would load user's saved searches from API
        // For now, just a placeholder
    }
}

// Create global search instance
const search = new BazarSearch();

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { BazarSearch, search };
} else {
    window.BazarSearch = BazarSearch;
    window.search = search;
}