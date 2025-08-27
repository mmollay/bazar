-- Search and Analytics Schema Extensions
-- Add these tables to the existing database schema

-- Search analytics table
CREATE TABLE search_analytics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    query VARCHAR(500) NOT NULL DEFAULT '',
    filters JSON,
    results_count INT DEFAULT 0,
    search_time_ms DECIMAL(8,2) NULL,
    user_agent VARCHAR(255),
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_query (query),
    INDEX idx_created_at (created_at),
    INDEX idx_results_count (results_count),
    INDEX idx_search_time (search_time_ms)
);

-- Popular searches cache table
CREATE TABLE popular_searches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    query VARCHAR(500) NOT NULL,
    search_count INT DEFAULT 1,
    avg_results DECIMAL(8,2) DEFAULT 0,
    last_searched TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_query_period (query, period_start),
    INDEX idx_search_count (search_count),
    INDEX idx_last_searched (last_searched),
    INDEX idx_period (period_start, period_end)
);

-- Search suggestions cache table
CREATE TABLE search_suggestions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    query VARCHAR(500) NOT NULL,
    suggestion VARCHAR(500) NOT NULL,
    source ENUM('articles', 'categories', 'popular', 'ai') DEFAULT 'articles',
    confidence_score DECIMAL(5,4) DEFAULT 0,
    usage_count INT DEFAULT 0,
    last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_query (query),
    INDEX idx_suggestion (suggestion),
    INDEX idx_source (source),
    INDEX idx_confidence (confidence_score),
    INDEX idx_usage (usage_count),
    INDEX idx_last_used (last_used)
);

-- Search filters analytics
CREATE TABLE search_filter_analytics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    filter_name VARCHAR(100) NOT NULL,
    filter_value VARCHAR(255) NOT NULL,
    usage_count INT DEFAULT 1,
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_filter_date (filter_name, filter_value, date),
    INDEX idx_filter_name (filter_name),
    INDEX idx_usage_count (usage_count),
    INDEX idx_date (date)
);

-- Email alert queue for saved searches
CREATE TABLE search_alert_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    saved_search_id INT NOT NULL,
    article_ids JSON NOT NULL, -- Array of new matching article IDs
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    error_message TEXT NULL,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (saved_search_id) REFERENCES saved_searches(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_saved_search_id (saved_search_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_at (scheduled_at)
);

-- Search performance metrics
CREATE TABLE search_performance_metrics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date DATE NOT NULL,
    total_searches INT DEFAULT 0,
    unique_queries INT DEFAULT 0,
    avg_results_per_search DECIMAL(8,2) DEFAULT 0,
    avg_search_time_ms DECIMAL(8,2) DEFAULT 0,
    zero_result_searches INT DEFAULT 0,
    successful_searches INT DEFAULT 0,
    peak_hour TINYINT DEFAULT 0, -- 0-23 hour of day with most searches
    peak_hour_searches INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_date (date),
    INDEX idx_date (date),
    INDEX idx_total_searches (total_searches),
    INDEX idx_zero_results (zero_result_searches)
);

-- Search query expansions (for query suggestion improvements)
CREATE TABLE search_query_expansions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    original_query VARCHAR(500) NOT NULL,
    expanded_query VARCHAR(500) NOT NULL,
    expansion_type ENUM('synonym', 'related', 'autocomplete', 'spell_check') DEFAULT 'related',
    confidence_score DECIMAL(5,4) DEFAULT 0,
    usage_count INT DEFAULT 0,
    success_rate DECIMAL(5,4) DEFAULT 0, -- Rate of successful searches using this expansion
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_original_query (original_query),
    INDEX idx_expanded_query (expanded_query),
    INDEX idx_expansion_type (expansion_type),
    INDEX idx_confidence (confidence_score),
    INDEX idx_success_rate (success_rate)
);

-- Add new indexes to existing tables for better search performance

-- Additional indexes for articles table
ALTER TABLE articles 
    ADD INDEX idx_location_coords (latitude, longitude),
    ADD INDEX idx_price_condition (price, condition_type),
    ADD INDEX idx_featured_active (is_featured, status),
    ADD INDEX idx_view_favorite_count (view_count, favorite_count),
    ADD INDEX idx_created_featured (created_at, is_featured);

-- Additional indexes for categories table  
ALTER TABLE categories 
    ADD FULLTEXT KEY ft_category_search (name, description);

-- Additional indexes for saved_searches table
ALTER TABLE saved_searches 
    ADD INDEX idx_user_active_updated (user_id, is_active, updated_at),
    ADD INDEX idx_last_notified (last_notified_at);

-- Create stored procedures for common search operations

DELIMITER $$

-- Update popular searches daily aggregation
CREATE PROCEDURE UpdatePopularSearches()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Clean old popular searches (older than 30 days)
    DELETE FROM popular_searches 
    WHERE period_end < DATE_SUB(CURDATE(), INTERVAL 30 DAY);
    
    -- Update today's popular searches
    INSERT INTO popular_searches (query, search_count, avg_results, last_searched, period_start, period_end)
    SELECT 
        sa.query,
        COUNT(*) as search_count,
        AVG(sa.results_count) as avg_results,
        MAX(sa.created_at) as last_searched,
        CURDATE() as period_start,
        CURDATE() as period_end
    FROM search_analytics sa
    WHERE DATE(sa.created_at) = CURDATE()
        AND sa.query != ''
        AND LENGTH(sa.query) >= 2
        AND sa.results_count > 0
    GROUP BY sa.query
    ON DUPLICATE KEY UPDATE
        search_count = VALUES(search_count),
        avg_results = VALUES(avg_results),
        last_searched = VALUES(last_searched),
        updated_at = CURRENT_TIMESTAMP;
    
    COMMIT;
END$$

-- Update daily performance metrics
CREATE PROCEDURE UpdateSearchPerformanceMetrics()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    INSERT INTO search_performance_metrics (
        date, total_searches, unique_queries, avg_results_per_search, 
        avg_search_time_ms, zero_result_searches, successful_searches,
        peak_hour, peak_hour_searches
    )
    SELECT 
        DATE(sa.created_at) as date,
        COUNT(*) as total_searches,
        COUNT(DISTINCT sa.query) as unique_queries,
        AVG(sa.results_count) as avg_results_per_search,
        AVG(sa.search_time_ms) as avg_search_time_ms,
        SUM(CASE WHEN sa.results_count = 0 THEN 1 ELSE 0 END) as zero_result_searches,
        SUM(CASE WHEN sa.results_count > 0 THEN 1 ELSE 0 END) as successful_searches,
        -- Subquery for peak hour
        (SELECT HOUR(created_at) 
         FROM search_analytics 
         WHERE DATE(created_at) = DATE(sa.created_at)
         GROUP BY HOUR(created_at) 
         ORDER BY COUNT(*) DESC 
         LIMIT 1) as peak_hour,
        (SELECT COUNT(*) 
         FROM search_analytics sa2
         WHERE DATE(sa2.created_at) = DATE(sa.created_at)
         GROUP BY HOUR(sa2.created_at) 
         ORDER BY COUNT(*) DESC 
         LIMIT 1) as peak_hour_searches
    FROM search_analytics sa
    WHERE DATE(sa.created_at) = CURDATE() - INTERVAL 1 DAY
    GROUP BY DATE(sa.created_at)
    ON DUPLICATE KEY UPDATE
        total_searches = VALUES(total_searches),
        unique_queries = VALUES(unique_queries),
        avg_results_per_search = VALUES(avg_results_per_search),
        avg_search_time_ms = VALUES(avg_search_time_ms),
        zero_result_searches = VALUES(zero_result_searches),
        successful_searches = VALUES(successful_searches),
        peak_hour = VALUES(peak_hour),
        peak_hour_searches = VALUES(peak_hour_searches),
        updated_at = CURRENT_TIMESTAMP;
    
    COMMIT;
END$$

DELIMITER ;

-- Create events for automated maintenance (if EVENT_SCHEDULER is enabled)

-- Daily aggregation of popular searches (runs at 2 AM)
CREATE EVENT IF NOT EXISTS ev_update_popular_searches
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '02:00:00')
DO
    CALL UpdatePopularSearches();

-- Daily performance metrics update (runs at 3 AM)  
CREATE EVENT IF NOT EXISTS ev_update_search_metrics
ON SCHEDULE EVERY 1 DAY  
STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '03:00:00')
DO
    CALL UpdateSearchPerformanceMetrics();

-- Weekly cleanup of old search analytics (runs Sunday at 4 AM)
CREATE EVENT IF NOT EXISTS ev_cleanup_search_analytics
ON SCHEDULE EVERY 1 WEEK
STARTS TIMESTAMP(DATE_ADD(CURRENT_DATE, INTERVAL (7 - WEEKDAY(CURRENT_DATE)) DAY) + INTERVAL 1 DAY, '04:00:00')
DO
    DELETE FROM search_analytics 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);