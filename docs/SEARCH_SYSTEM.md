# Bazar Search & Filter System

## Overview

The Bazar marketplace features a comprehensive search and filtering system with advanced capabilities including:

- Full-text search with relevance scoring
- Real-time search suggestions and autocomplete
- Advanced filtering (location, price, condition, category, etc.)
- Saved searches with email alerts
- Search analytics and performance tracking
- Responsive search interface with pagination

## Architecture

### Backend Components

#### 1. SearchController (`/backend/controllers/SearchController.php`)
Main search API controller handling:
- `/api/v1/search` - Main search with filtering
- `/api/v1/search/suggestions` - Autocomplete suggestions
- `/api/v1/search/filters` - Available filter options
- `/api/v1/search/save` - Save search functionality
- `/api/v1/search/saved` - User's saved searches

**Key Features:**
- MySQL full-text search with `MATCH() AGAINST()`
- Relevance scoring with boost factors
- Location-based radius search using Haversine formula
- Advanced filtering and sorting options
- Search result caching for performance
- Search analytics tracking

#### 2. SearchAnalytics Model (`/backend/models/SearchAnalytics.php`)
Tracks search queries and performance:
- Query analytics and popular searches
- No-result searches for improvement
- Search performance metrics
- Hourly usage patterns
- Filter usage statistics

#### 3. SearchAlertService (`/backend/services/SearchAlertService.php`)
Handles email alerts for saved searches:
- Processes saved searches for new matching items
- Queues and sends email notifications
- Manages alert frequency and user preferences
- HTML email templates with unsubscribe functionality

#### 4. Database Schema Extensions (`/backend/config/search_schema.sql`)
Additional tables for search functionality:
- `search_analytics` - Query tracking
- `popular_searches` - Cached popular queries
- `search_suggestions` - Autocomplete cache
- `search_alert_queue` - Email alert processing
- `search_performance_metrics` - Daily performance stats

### Frontend Components

#### 1. Enhanced Search Module (`/frontend/js/modules/search.js`)
Comprehensive search functionality:
- Real-time search suggestions
- Advanced filter management
- Location-based search with geolocation
- Saved search management
- Search result rendering with pagination

**Key Features:**
- Debounced search for performance
- Local and API-based suggestions
- Filter state management
- Search history tracking
- Responsive design support

#### 2. Search Results Page (`/frontend/pages/search.html`)
Complete search interface with:
- Advanced filter sidebar
- Grid/list view toggles
- Sorting options
- Pagination controls
- Save search modals
- No-results state with suggestions

#### 3. Search Styling (`/frontend/assets/css/search.css`)
Comprehensive styling for:
- Responsive search layout
- Filter UI components
- Search result cards
- Modal interfaces
- Mobile-friendly design
- Dark mode support

## API Endpoints

### Search Endpoints

#### Main Search
```
GET /api/v1/search
Parameters:
- q: Search query
- page: Page number (default: 1)
- per_page: Results per page (default: 20, max: 50)
- sort: Sort order (relevance, newest, price_asc, price_desc, distance, popular)
- category_id: Category filter
- category: Category slug
- min_price/max_price: Price range
- condition: Condition filter (array supported)
- location: Location text
- lat/lng/radius: Geographic search
- featured: Featured items only
- date_from/date_to: Date range
```

Response:
```json
{
  "success": true,
  "data": {
    "articles": [...],
    "total": 150,
    "page": 1,
    "per_page": 20,
    "total_pages": 8,
    "meta": {
      "query": "iPhone",
      "search_time_ms": 45.2,
      "filters_applied": ["category", "price"],
      "has_next_page": true
    }
  }
}
```

#### Search Suggestions
```
GET /api/v1/search/suggestions?q=iph&limit=8
```

Response:
```json
{
  "success": true,
  "data": {
    "suggestions": ["iPhone 13", "iPhone 12", "iPad"],
    "popular_searches": [
      {"query": "iPhone", "count": 245},
      {"query": "Samsung", "count": 189}
    ]
  }
}
```

#### Filter Options
```
GET /api/v1/search/filters
```

Response:
```json
{
  "success": true,
  "data": {
    "categories": [...],
    "price_range": {"min_price": 0, "max_price": 5000, "avg_price": 245},
    "conditions": [...],
    "popular_locations": [...],
    "sort_options": [...]
  }
}
```

### Saved Searches

#### Save Search
```
POST /api/v1/search/save
{
  "name": "iPhone in Vienna",
  "query": "iPhone",
  "filters": {"location": "Vienna", "max_price": 800},
  "email_alerts": true
}
```

#### Get Saved Searches
```
GET /api/v1/search/saved
```

#### Delete Saved Search
```
DELETE /api/v1/search/saved/{id}
```

## Search Features

### 1. Full-Text Search
- MySQL `FULLTEXT` indexes on `title` and `description`
- Relevance scoring with `MATCH() AGAINST()`
- Query sanitization and wildcard support
- Boost factors for recent, featured, and high-rated items

### 2. Advanced Filtering
- **Category**: Hierarchical category filtering
- **Price Range**: Min/max price with histogram data
- **Condition**: Multiple condition selection
- **Location**: Address-based with radius search
- **Date Range**: Filter by listing date
- **Featured**: Premium listings only

### 3. Geographic Search
- Haversine formula for distance calculation
- Radius search (1-100km)
- Geolocation API integration
- Address geocoding with OpenStreetMap

### 4. Search Suggestions
- Real-time autocomplete (2+ characters)
- Multiple suggestion sources:
  - Article titles and descriptions
  - Popular search queries
  - Category names and keywords
  - Search history
- Caching for performance

### 5. Saved Searches & Alerts
- User-defined search names
- Filter persistence
- Email notifications for new matches
- Alert frequency management
- Unsubscribe functionality

### 6. Search Analytics
- Query tracking and statistics
- Popular searches identification
- No-result query analysis
- Performance monitoring
- Usage pattern analysis

## Performance Optimizations

### Database Indexes
```sql
-- Articles table
FULLTEXT KEY ft_search (title, description)
INDEX idx_price (price)
INDEX idx_location (latitude, longitude)
INDEX idx_created_featured (created_at, is_featured)
INDEX idx_category_status (category_id, status)

-- Search analytics
INDEX idx_query (query)
INDEX idx_created_at (created_at)
INDEX idx_results_count (results_count)
```

### Caching Strategy
1. **Search Results**: 5-minute cache for identical queries
2. **Filter Options**: 1-hour cache for category/condition counts
3. **Suggestions**: 10-minute cache for autocomplete
4. **Popular Searches**: Daily aggregation cache

### Query Optimization
- Prepared statements for all database queries
- Limited result sets (max 50 per page)
- Efficient JOIN operations
- Optimized distance calculations

## Email Alert System

### Processing Flow
1. **Cron Job**: Run `process_search_alerts.php` hourly
2. **Alert Detection**: Find new articles matching saved searches
3. **Queue Management**: Add alerts to processing queue
4. **Email Generation**: Create HTML emails with article previews
5. **Delivery**: Send emails with retry logic
6. **Cleanup**: Remove old processed alerts

### Email Features
- HTML templates with article previews
- Unsubscribe links
- Mobile-responsive design
- Rate limiting and failure handling
- Delivery statistics

### CLI Commands
```bash
# Process all pending alerts
php backend/cli/process_search_alerts.php

# Process emails only
php backend/cli/process_search_alerts.php --emails

# Show statistics
php backend/cli/process_search_alerts.php --stats

# Clean up old data
php backend/cli/process_search_alerts.php --cleanup
```

## Frontend Integration

### Search Interface
```javascript
// Initialize search
const search = new BazarSearch();

// Set filters programmatically
search.setPriceRangeFilter(100, 500);
search.setLocationFilter({lat: 48.2082, lng: 16.3738, address: "Vienna"});
search.setConditionFilter(['new', 'like_new']);

// Perform search
search.search('iPhone');

// Save search
search.saveCurrentSearch('My iPhone Search', true); // with email alerts
```

### Event Handling
```javascript
// Listen for search results
document.addEventListener('searchResults', (event) => {
  console.log('Search completed:', event.detail);
});

// Handle filter changes
document.addEventListener('filterChanged', (event) => {
  console.log('Filters updated:', event.detail);
});
```

## Mobile Responsiveness

### Responsive Design Features
- Collapsible filter sidebar on mobile
- Touch-friendly interface elements
- Grid/list view adaptation
- Optimized pagination controls
- Swipe gestures for filters

### Performance on Mobile
- Lazy loading for search results
- Optimized image sizes
- Minimal JavaScript execution
- Progressive enhancement

## Analytics and Monitoring

### Tracked Metrics
- Search volume and trends
- Popular queries and categories
- No-result queries for improvement
- Search performance times
- User engagement metrics

### Performance Monitoring
- Search response times
- Database query performance
- Cache hit rates
- Email delivery success rates

## Security Considerations

### Input Validation
- SQL injection prevention
- XSS protection in search results
- Rate limiting on search API
- Input sanitization

### Data Privacy
- Search history anonymization options
- GDPR compliance for saved searches
- Secure unsubscribe tokens
- Data retention policies

## Deployment and Maintenance

### Required Setup
1. **Database**: Run `search_schema.sql` migrations
2. **Indexes**: Ensure FULLTEXT indexes are created
3. **Cron Jobs**: Set up hourly alert processing
4. **Email**: Configure SMTP settings
5. **Caching**: Optional Redis for better performance

### Cron Job Configuration
```bash
# Process search alerts every hour
0 * * * * php /path/to/backend/cli/process_search_alerts.php

# Daily performance metrics (3 AM)
0 3 * * * php /path/to/backend/cli/process_search_alerts.php --stats

# Weekly cleanup (Sunday 4 AM)
0 4 * * 0 php /path/to/backend/cli/process_search_alerts.php --cleanup
```

### Monitoring
- Search API response times
- Database performance metrics
- Email delivery rates
- Cache performance
- User engagement analytics

## Future Enhancements

### Elasticsearch Integration
- Advanced full-text search capabilities
- Faceted search and aggregations
- Better relevance scoring
- Scalability for large datasets

### Machine Learning
- Personalized search results
- Smart query expansion
- Recommendation engine
- Price prediction based on search patterns

### Advanced Features
- Visual search with image similarity
- Voice search integration
- Real-time notifications
- Advanced analytics dashboard

---

This search system provides a comprehensive solution for the Bazar marketplace with enterprise-level features including analytics, email alerts, and performance optimization. The modular architecture allows for easy maintenance and future enhancements.