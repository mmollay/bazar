-- Bazar Marketplace Database Schema
-- Created for AI-powered marketplace with image recognition

SET FOREIGN_KEY_CHECKS = 0;
DROP DATABASE IF EXISTS bazar_marketplace;
CREATE DATABASE bazar_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bazar_marketplace;

-- Users table with OAuth and 2FA support
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    username VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    phone VARCHAR(20),
    avatar_url VARCHAR(500),
    is_verified BOOLEAN DEFAULT FALSE,
    email_verified_at TIMESTAMP NULL,
    google_id VARCHAR(100) NULL,
    two_factor_secret VARCHAR(255) NULL,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    last_login_at TIMESTAMP NULL,
    rating DECIMAL(3,2) DEFAULT 0.00,
    rating_count INT DEFAULT 0,
    status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
    is_admin BOOLEAN DEFAULT FALSE,
    admin_role ENUM('super_admin', 'admin', 'moderator', 'support') NULL,
    admin_two_factor_secret VARCHAR(255) NULL,
    admin_two_factor_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_username (username),
    INDEX idx_google_id (google_id),
    INDEX idx_status (status),
    INDEX idx_admin (is_admin),
    INDEX idx_admin_role (admin_role)
);

-- Categories table (hierarchical structure)
CREATE TABLE categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parent_id INT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    icon VARCHAR(100),
    ai_keywords JSON, -- Keywords for AI categorization
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_parent_id (parent_id),
    INDEX idx_slug (slug),
    INDEX idx_active (is_active)
);

-- Articles table with full-text search
CREATE TABLE articles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    currency CHAR(3) DEFAULT 'EUR',
    condition_type ENUM('new', 'like_new', 'good', 'fair', 'poor') DEFAULT 'good',
    location VARCHAR(255),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    is_negotiable BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    is_featured BOOLEAN DEFAULT FALSE,
    view_count INT DEFAULT 0,
    favorite_count INT DEFAULT 0,
    ai_generated BOOLEAN DEFAULT FALSE, -- Marks AI-generated content
    ai_confidence_score DECIMAL(5,4) DEFAULT NULL, -- AI confidence for categorization
    status ENUM('draft', 'active', 'sold', 'archived', 'moderated') DEFAULT 'draft',
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    
    FULLTEXT KEY ft_search (title, description),
    INDEX idx_user_id (user_id),
    INDEX idx_category_id (category_id),
    INDEX idx_price (price),
    INDEX idx_location (latitude, longitude),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_ai_generated (ai_generated)
);

-- Article images with AI analysis results
CREATE TABLE article_images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    article_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255),
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    mime_type VARCHAR(100),
    width INT,
    height INT,
    is_primary BOOLEAN DEFAULT FALSE,
    sort_order INT DEFAULT 0,
    alt_text VARCHAR(255),
    
    -- AI Analysis Results
    ai_analyzed BOOLEAN DEFAULT FALSE,
    ai_objects JSON, -- Detected objects with confidence scores
    ai_labels JSON, -- Image labels from AI
    ai_text JSON, -- OCR text extraction
    ai_colors JSON, -- Dominant colors
    ai_landmarks JSON, -- Detected landmarks
    ai_faces BOOLEAN DEFAULT FALSE, -- Face detection result
    ai_explicit_content JSON, -- Safe search results
    ai_analysis_timestamp TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    INDEX idx_article_id (article_id),
    INDEX idx_primary (is_primary),
    INDEX idx_ai_analyzed (ai_analyzed)
);

-- AI suggestions for article creation
CREATE TABLE ai_suggestions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    article_id INT,
    image_id INT,
    suggestion_type ENUM('title', 'description', 'category', 'price', 'condition') NOT NULL,
    original_value TEXT,
    suggested_value TEXT NOT NULL,
    confidence_score DECIMAL(5,4) NOT NULL,
    is_accepted BOOLEAN DEFAULT FALSE,
    user_feedback ENUM('accepted', 'rejected', 'modified') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY (image_id) REFERENCES article_images(id) ON DELETE CASCADE,
    INDEX idx_article_id (article_id),
    INDEX idx_image_id (image_id),
    INDEX idx_type (suggestion_type),
    INDEX idx_confidence (confidence_score)
);

-- Messages for in-app communication
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    article_id INT NOT NULL,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    content TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    is_system_message BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_article_id (article_id),
    INDEX idx_sender_id (sender_id),
    INDEX idx_receiver_id (receiver_id),
    INDEX idx_created_at (created_at)
);

-- User ratings and reviews
CREATE TABLE ratings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rater_id INT NOT NULL,
    rated_id INT NOT NULL,
    article_id INT,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (rater_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (rated_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE SET NULL,
    UNIQUE KEY unique_rating (rater_id, rated_id, article_id),
    INDEX idx_rated_id (rated_id),
    INDEX idx_rating (rating)
);

-- User favorites
CREATE TABLE favorites (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    article_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_favorite (user_id, article_id),
    INDEX idx_user_id (user_id),
    INDEX idx_article_id (article_id)
);

-- Saved searches
CREATE TABLE saved_searches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    query VARCHAR(500),
    filters JSON, -- Search filters as JSON
    location VARCHAR(255),
    radius INT, -- Search radius in km
    min_price DECIMAL(10,2),
    max_price DECIMAL(10,2),
    is_active BOOLEAN DEFAULT TRUE,
    last_notified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_active (is_active)
);

-- Admin activity logs
CREATE TABLE admin_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50), -- users, articles, categories, etc.
    target_id INT,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_id (admin_id),
    INDEX idx_action (action),
    INDEX idx_target (target_type, target_id),
    INDEX idx_created_at (created_at)
);

-- Cookie consents for GDPR compliance
CREATE TABLE cookie_consents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    ip_address VARCHAR(45) NOT NULL,
    consent_types JSON NOT NULL, -- Array of consented cookie types
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_created_at (created_at)
);

-- AI processing queue for batch operations
CREATE TABLE ai_processing_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    image_id INT NOT NULL,
    processing_type ENUM('analysis', 'categorization', 'text_extraction', 'similarity') NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'retrying') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    error_message TEXT,
    processing_data JSON, -- Additional data for processing
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (image_id) REFERENCES article_images(id) ON DELETE CASCADE,
    INDEX idx_image_id (image_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- AI model configurations
CREATE TABLE ai_models (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('vision', 'nlp', 'classification', 'pricing') NOT NULL,
    provider VARCHAR(50) NOT NULL, -- 'google_vision', 'tensorflow', 'custom'
    model_version VARCHAR(50),
    endpoint_url VARCHAR(500),
    api_key_encrypted TEXT,
    configuration JSON,
    is_active BOOLEAN DEFAULT TRUE,
    performance_metrics JSON, -- Accuracy, response time, etc.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_type (type),
    INDEX idx_provider (provider),
    INDEX idx_active (is_active)
);

-- Price estimation history for ML training
CREATE TABLE price_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    article_id INT NOT NULL,
    category_id INT NOT NULL,
    condition_type ENUM('new', 'like_new', 'good', 'fair', 'poor') NOT NULL,
    original_price DECIMAL(10,2) NOT NULL,
    final_price DECIMAL(10,2), -- Final selling price if sold
    ai_suggested_price DECIMAL(10,2),
    price_factors JSON, -- Factors that influenced pricing
    location VARCHAR(255),
    sold_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    INDEX idx_article_id (article_id),
    INDEX idx_category_id (category_id),
    INDEX idx_condition (condition_type),
    INDEX idx_created_at (created_at)
);

-- Insert default categories with AI keywords
INSERT INTO categories (name, slug, ai_keywords, is_active, sort_order) VALUES 
('Electronics', 'electronics', '["smartphone", "laptop", "computer", "tablet", "headphones", "camera", "gaming", "console", "tv", "monitor"]', TRUE, 1),
('Fashion & Beauty', 'fashion-beauty', '["clothing", "shoes", "bag", "watch", "jewelry", "cosmetics", "dress", "shirt", "pants", "jacket"]', TRUE, 2),
('Home & Garden', 'home-garden', '["furniture", "decor", "kitchen", "garden", "tools", "lighting", "bed", "sofa", "table", "chair"]', TRUE, 3),
('Sports & Leisure', 'sports-leisure', '["bicycle", "fitness", "sports", "outdoor", "camping", "swimming", "running", "gym", "ball", "racket"]', TRUE, 4),
('Vehicles', 'vehicles', '["car", "motorcycle", "truck", "bicycle", "scooter", "boat", "caravan", "trailer", "parts", "accessories"]', TRUE, 5),
('Baby & Kids', 'baby-kids', '["stroller", "crib", "toys", "clothes", "books", "games", "baby", "child", "educational", "safety"]', TRUE, 6),
('Books & Media', 'books-media', '["book", "dvd", "cd", "vinyl", "magazine", "comics", "textbook", "novel", "music", "movie"]', TRUE, 7),
('Collectibles & Art', 'collectibles-art', '["antique", "art", "painting", "sculpture", "coins", "stamps", "vintage", "collectible", "rare", "handmade"]', TRUE, 8),
('Other', 'other', '["misc", "various", "other", "unknown", "mixed", "general", "different", "assorted"]', TRUE, 9);

-- Insert default AI models configuration
INSERT INTO ai_models (name, type, provider, model_version, configuration, is_active) VALUES 
('Google Vision API', 'vision', 'google_vision', 'v1', '{"features": ["OBJECT_LOCALIZATION", "LABEL_DETECTION", "TEXT_DETECTION", "SAFE_SEARCH_DETECTION"], "max_results": 10}', TRUE),
('Local TensorFlow Model', 'vision', 'tensorflow', 'mobilenet_v2', '{"model_path": "/models/object_detection.pb", "confidence_threshold": 0.5}', FALSE),
('Price Estimation Model', 'pricing', 'custom', 'v1.0', '{"algorithm": "random_forest", "features": ["category", "condition", "location", "season"]}', TRUE),
('Text Classification', 'nlp', 'custom', 'v1.0', '{"model_type": "bert", "max_tokens": 512}', TRUE);

-- Admin sessions with enhanced security
CREATE TABLE admin_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    two_factor_verified BOOLEAN DEFAULT FALSE,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_token (session_token),
    INDEX idx_expires (expires_at)
);

-- User reports and complaints
CREATE TABLE user_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reporter_id INT NOT NULL,
    reported_user_id INT,
    reported_article_id INT,
    reported_message_id INT,
    report_type ENUM('spam', 'inappropriate', 'fraud', 'fake', 'harassment', 'copyright', 'other') NOT NULL,
    description TEXT NOT NULL,
    evidence_urls JSON, -- Screenshots or other evidence
    status ENUM('pending', 'investigating', 'resolved', 'dismissed') DEFAULT 'pending',
    admin_notes TEXT,
    handled_by INT,
    handled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_reporter (reporter_id),
    INDEX idx_reported_user (reported_user_id),
    INDEX idx_reported_article (reported_article_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Email templates for system communications
CREATE TABLE email_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    variables JSON, -- Available template variables
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_name (name),
    INDEX idx_active (is_active)
);

-- System settings and configuration
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE, -- If setting can be accessed by frontend
    group_name VARCHAR(50) DEFAULT 'general',
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_key (setting_key),
    INDEX idx_group (group_name),
    INDEX idx_public (is_public)
);

-- Admin notifications and alerts
CREATE TABLE admin_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type ENUM('user_report', 'system_alert', 'security_incident', 'performance_issue', 'content_flag') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    data JSON, -- Additional notification data
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    is_read BOOLEAN DEFAULT FALSE,
    assigned_to INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (type),
    INDEX idx_severity (severity),
    INDEX idx_assigned (assigned_to),
    INDEX idx_read (is_read),
    INDEX idx_created (created_at)
);

-- System statistics cache
CREATE TABLE system_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    stat_date DATE NOT NULL,
    total_users INT DEFAULT 0,
    active_users INT DEFAULT 0,
    new_users_today INT DEFAULT 0,
    total_articles INT DEFAULT 0,
    active_articles INT DEFAULT 0,
    new_articles_today INT DEFAULT 0,
    total_messages INT DEFAULT 0,
    new_messages_today INT DEFAULT 0,
    pending_reports INT DEFAULT 0,
    revenue_today DECIMAL(10,2) DEFAULT 0.00,
    page_views INT DEFAULT 0,
    search_queries INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_date (stat_date),
    INDEX idx_date (stat_date)
);

-- Database backup logs
CREATE TABLE backup_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    backup_type ENUM('full', 'incremental', 'structure_only') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT,
    status ENUM('started', 'completed', 'failed') NOT NULL,
    initiated_by INT,
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    
    FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (backup_type),
    INDEX idx_status (status),
    INDEX idx_started (started_at)
);

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value, setting_type, description, is_public, group_name) VALUES
('site_name', 'Bazar Marketplace', 'string', 'The name of the marketplace', TRUE, 'general'),
('site_description', 'AI-powered marketplace for buying and selling items', 'string', 'Site description for SEO', TRUE, 'general'),
('admin_email', 'admin@bazar.com', 'string', 'Main admin email for system notifications', FALSE, 'general'),
('max_upload_size', '10485760', 'number', 'Maximum file upload size in bytes (10MB)', FALSE, 'uploads'),
('allowed_file_types', '["jpg", "jpeg", "png", "gif", "webp"]', 'json', 'Allowed image file extensions', FALSE, 'uploads'),
('article_expiry_days', '30', 'number', 'Default number of days before articles expire', TRUE, 'articles'),
('featured_article_price', '9.99', 'number', 'Price to feature an article in EUR', TRUE, 'pricing'),
('enable_ai_suggestions', 'true', 'boolean', 'Enable AI-powered article suggestions', TRUE, 'ai'),
('maintenance_mode', 'false', 'boolean', 'Enable maintenance mode', TRUE, 'system'),
('registration_enabled', 'true', 'boolean', 'Allow new user registrations', TRUE, 'users'),
('require_email_verification', 'true', 'boolean', 'Require email verification for new users', FALSE, 'users'),
('max_images_per_article', '10', 'number', 'Maximum number of images per article', TRUE, 'articles'),
('search_results_per_page', '20', 'number', 'Number of search results per page', TRUE, 'search'),
('enable_geolocation', 'true', 'boolean', 'Enable location-based features', TRUE, 'location'),
('default_search_radius', '25', 'number', 'Default search radius in kilometers', TRUE, 'location');

-- Insert default email templates
INSERT INTO email_templates (name, subject, body, variables) VALUES
('welcome', 'Welcome to Bazar Marketplace!', 
 'Hello {{first_name}},\n\nWelcome to Bazar Marketplace! Your account has been successfully created.\n\nYou can now start browsing and posting items.\n\nBest regards,\nThe Bazar Team',
 '["first_name", "username", "email"]'),
('article_approved', 'Your article has been approved', 
 'Hello {{first_name}},\n\nYour article "{{article_title}}" has been approved and is now live on the marketplace.\n\nView your article: {{article_url}}\n\nBest regards,\nThe Bazar Team',
 '["first_name", "article_title", "article_url"]'),
('article_rejected', 'Your article needs attention', 
 'Hello {{first_name}},\n\nYour article "{{article_title}}" could not be approved for the following reason:\n\n{{rejection_reason}}\n\nPlease make the necessary changes and resubmit.\n\nBest regards,\nThe Bazar Team',
 '["first_name", "article_title", "rejection_reason"]'),
('password_reset', 'Password Reset Request', 
 'Hello {{first_name}},\n\nYou have requested a password reset. Click the link below to reset your password:\n\n{{reset_url}}\n\nThis link will expire in 24 hours.\n\nIf you did not request this, please ignore this email.\n\nBest regards,\nThe Bazar Team',
 '["first_name", "reset_url"]');

-- Insert default admin user (password: admin123)
INSERT INTO users (email, password_hash, username, first_name, last_name, is_verified, is_admin, admin_role, status) VALUES
('admin@bazar.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System', 'Administrator', TRUE, TRUE, 'super_admin', 'active');

SET FOREIGN_KEY_CHECKS = 1;