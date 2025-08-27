-- Bazar Marketplace Database Schema
-- MySQL 8.0+ Compatible

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `bazar_marketplace` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `bazar_marketplace`;

-- =============================================
-- USERS TABLE
-- =============================================
CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `bio` text,
  `location` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_active` boolean NOT NULL DEFAULT TRUE,
  `is_verified` boolean NOT NULL DEFAULT FALSE,
  `is_premium` boolean NOT NULL DEFAULT FALSE,
  `premium_expires_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `login_attempts` int NOT NULL DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `two_factor_enabled` boolean NOT NULL DEFAULT FALSE,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `recovery_codes` json DEFAULT NULL,
  `preferences` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_uuid_unique` (`uuid`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `idx_users_location` (`latitude`, `longitude`),
  KEY `idx_users_active` (`is_active`),
  KEY `idx_users_premium` (`is_premium`),
  KEY `idx_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- OAUTH PROVIDERS TABLE
-- =============================================
CREATE TABLE `oauth_providers` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `provider` enum('google','facebook','apple','twitter') NOT NULL,
  `provider_id` varchar(100) NOT NULL,
  `provider_email` varchar(100) DEFAULT NULL,
  `provider_name` varchar(100) DEFAULT NULL,
  `provider_avatar` varchar(255) DEFAULT NULL,
  `access_token` text,
  `refresh_token` text,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oauth_provider_unique` (`provider`, `provider_id`),
  KEY `oauth_user_id_foreign` (`user_id`),
  CONSTRAINT `oauth_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CATEGORIES TABLE (Hierarchical)
-- =============================================
CREATE TABLE `categories` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text,
  `icon` varchar(100) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `color` varchar(7) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` boolean NOT NULL DEFAULT TRUE,
  `meta_title` varchar(200) DEFAULT NULL,
  `meta_description` varchar(300) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_slug_unique` (`slug`),
  KEY `categories_parent_id_foreign` (`parent_id`),
  KEY `idx_categories_active` (`is_active`),
  KEY `idx_categories_sort` (`sort_order`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ARTICLES TABLE (with Full-text Search)
-- =============================================
CREATE TABLE `articles` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `category_id` bigint UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `content` longtext,
  `price` decimal(12,2) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `condition_type` enum('new','like_new','good','fair','poor') NOT NULL,
  `status` enum('draft','active','sold','expired','suspended') NOT NULL DEFAULT 'draft',
  `is_featured` boolean NOT NULL DEFAULT FALSE,
  `is_urgent` boolean NOT NULL DEFAULT FALSE,
  `is_negotiable` boolean NOT NULL DEFAULT TRUE,
  `views_count` bigint UNSIGNED NOT NULL DEFAULT 0,
  `favorites_count` bigint UNSIGNED NOT NULL DEFAULT 0,
  `messages_count` bigint UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` timestamp NULL DEFAULT NULL,
  `sold_at` timestamp NULL DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `attributes` json DEFAULT NULL,
  `seo_title` varchar(200) DEFAULT NULL,
  `seo_description` varchar(300) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `articles_uuid_unique` (`uuid`),
  UNIQUE KEY `articles_slug_unique` (`slug`),
  KEY `articles_user_id_foreign` (`user_id`),
  KEY `articles_category_id_foreign` (`category_id`),
  KEY `idx_articles_status` (`status`),
  KEY `idx_articles_location` (`latitude`, `longitude`),
  KEY `idx_articles_price` (`price`),
  KEY `idx_articles_created_at` (`created_at`),
  KEY `idx_articles_featured` (`is_featured`),
  FULLTEXT KEY `idx_articles_search` (`title`, `description`),
  CONSTRAINT `articles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `articles_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ARTICLE IMAGES TABLE
-- =============================================
CREATE TABLE `article_images` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` bigint UNSIGNED NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` bigint UNSIGNED NOT NULL,
  `width` int DEFAULT NULL,
  `height` int DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_primary` boolean NOT NULL DEFAULT FALSE,
  `alt_text` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `article_images_article_id_foreign` (`article_id`),
  KEY `idx_article_images_sort` (`sort_order`),
  KEY `idx_article_images_primary` (`is_primary`),
  CONSTRAINT `article_images_article_id_foreign` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CONVERSATIONS TABLE
-- =============================================
CREATE TABLE `conversations` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` bigint UNSIGNED NOT NULL,
  `buyer_id` bigint UNSIGNED NOT NULL,
  `seller_id` bigint UNSIGNED NOT NULL,
  `status` enum('active','archived','blocked') NOT NULL DEFAULT 'active',
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversations_unique` (`article_id`, `buyer_id`),
  KEY `conversations_buyer_id_foreign` (`buyer_id`),
  KEY `conversations_seller_id_foreign` (`seller_id`),
  KEY `idx_conversations_last_message` (`last_message_at`),
  CONSTRAINT `conversations_article_id_foreign` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversations_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversations_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- MESSAGES TABLE
-- =============================================
CREATE TABLE `messages` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint UNSIGNED NOT NULL,
  `sender_id` bigint UNSIGNED NOT NULL,
  `receiver_id` bigint UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `message_type` enum('text','image','offer','system') NOT NULL DEFAULT 'text',
  `attachments` json DEFAULT NULL,
  `offer_amount` decimal(12,2) DEFAULT NULL,
  `offer_status` enum('pending','accepted','declined','expired') DEFAULT NULL,
  `is_read` boolean NOT NULL DEFAULT FALSE,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `messages_conversation_id_foreign` (`conversation_id`),
  KEY `messages_sender_id_foreign` (`sender_id`),
  KEY `messages_receiver_id_foreign` (`receiver_id`),
  KEY `idx_messages_is_read` (`is_read`),
  KEY `idx_messages_created_at` (`created_at`),
  CONSTRAINT `messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_receiver_id_foreign` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- FAVORITES TABLE
-- =============================================
CREATE TABLE `favorites` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `article_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `favorites_unique` (`user_id`, `article_id`),
  KEY `favorites_article_id_foreign` (`article_id`),
  CONSTRAINT `favorites_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `favorites_article_id_foreign` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- RATINGS TABLE
-- =============================================
CREATE TABLE `ratings` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `rater_id` bigint UNSIGNED NOT NULL,
  `rated_id` bigint UNSIGNED NOT NULL,
  `article_id` bigint UNSIGNED DEFAULT NULL,
  `rating` tinyint UNSIGNED NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
  `comment` text,
  `is_visible` boolean NOT NULL DEFAULT TRUE,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ratings_unique` (`rater_id`, `rated_id`, `article_id`),
  KEY `ratings_rated_id_foreign` (`rated_id`),
  KEY `ratings_article_id_foreign` (`article_id`),
  KEY `idx_ratings_rating` (`rating`),
  CONSTRAINT `ratings_rater_id_foreign` FOREIGN KEY (`rater_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ratings_rated_id_foreign` FOREIGN KEY (`rated_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ratings_article_id_foreign` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- AI SUGGESTIONS TABLE
-- =============================================
CREATE TABLE `ai_suggestions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `suggestion_type` enum('pricing','title','description','category','tags') NOT NULL,
  `original_data` json NOT NULL,
  `suggested_data` json NOT NULL,
  `confidence_score` decimal(3,2) DEFAULT NULL,
  `is_applied` boolean NOT NULL DEFAULT FALSE,
  `applied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ai_suggestions_user_id_foreign` (`user_id`),
  KEY `idx_ai_suggestions_type` (`suggestion_type`),
  KEY `idx_ai_suggestions_applied` (`is_applied`),
  CONSTRAINT `ai_suggestions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SAVED SEARCHES TABLE
-- =============================================
CREATE TABLE `saved_searches` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `search_params` json NOT NULL,
  `notification_enabled` boolean NOT NULL DEFAULT TRUE,
  `last_notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `saved_searches_user_id_foreign` (`user_id`),
  KEY `idx_saved_searches_notifications` (`notification_enabled`),
  CONSTRAINT `saved_searches_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ADMIN LOGS TABLE
-- =============================================
CREATE TABLE `admin_logs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` bigint UNSIGNED NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` bigint UNSIGNED DEFAULT NULL,
  `old_data` json DEFAULT NULL,
  `new_data` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `admin_logs_admin_id_foreign` (`admin_id`),
  KEY `idx_admin_logs_action` (`action`),
  KEY `idx_admin_logs_target` (`target_type`, `target_id`),
  KEY `idx_admin_logs_created_at` (`created_at`),
  CONSTRAINT `admin_logs_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- COOKIE CONSENTS TABLE
-- =============================================
CREATE TABLE `cookie_consents` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `consent_data` json NOT NULL,
  `user_agent` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cookie_consents_user_id_foreign` (`user_id`),
  KEY `idx_cookie_consents_session` (`session_id`),
  KEY `idx_cookie_consents_ip` (`ip_address`),
  CONSTRAINT `cookie_consents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- REPORTS TABLE
-- =============================================
CREATE TABLE `reports` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `reporter_id` bigint UNSIGNED NOT NULL,
  `reported_type` enum('user','article','message') NOT NULL,
  `reported_id` bigint UNSIGNED NOT NULL,
  `reason` varchar(100) NOT NULL,
  `description` text,
  `status` enum('pending','reviewed','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `admin_notes` text,
  `resolved_by` bigint UNSIGNED DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `reports_reporter_id_foreign` (`reporter_id`),
  KEY `reports_resolved_by_foreign` (`resolved_by`),
  KEY `idx_reports_status` (`status`),
  KEY `idx_reports_type_id` (`reported_type`, `reported_id`),
  CONSTRAINT `reports_reporter_id_foreign` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reports_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- NOTIFICATIONS TABLE
-- =============================================
CREATE TABLE `notifications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `data` json DEFAULT NULL,
  `is_read` boolean NOT NULL DEFAULT FALSE,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `notifications_user_id_foreign` (`user_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  KEY `idx_notifications_type` (`type`),
  KEY `idx_notifications_created_at` (`created_at`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SESSION MANAGEMENT TABLE
-- =============================================
CREATE TABLE `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` timestamp NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_foreign` (`user_id`),
  KEY `idx_sessions_last_activity` (`last_activity`),
  CONSTRAINT `sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CREATE VIEWS FOR BETTER PERFORMANCE
-- =============================================

-- User Statistics View
CREATE VIEW `user_stats` AS
SELECT 
    u.id,
    u.username,
    u.email,
    u.first_name,
    u.last_name,
    u.is_premium,
    COUNT(DISTINCT a.id) as articles_count,
    COUNT(DISTINCT CASE WHEN a.status = 'active' THEN a.id END) as active_articles_count,
    COUNT(DISTINCT CASE WHEN a.status = 'sold' THEN a.id END) as sold_articles_count,
    AVG(r.rating) as average_rating,
    COUNT(DISTINCT r.id) as ratings_count,
    u.created_at
FROM users u
LEFT JOIN articles a ON u.id = a.user_id
LEFT JOIN ratings r ON u.id = r.rated_id
GROUP BY u.id;

-- Article Details View
CREATE VIEW `article_details` AS
SELECT 
    a.*,
    u.username as seller_username,
    u.first_name as seller_first_name,
    u.last_name as seller_last_name,
    u.avatar as seller_avatar,
    u.is_premium as seller_is_premium,
    c.name as category_name,
    c.slug as category_slug,
    COUNT(DISTINCT f.id) as favorites_count_actual,
    COUNT(DISTINCT ai.id) as images_count,
    ai_primary.filename as primary_image
FROM articles a
JOIN users u ON a.user_id = u.id
JOIN categories c ON a.category_id = c.id
LEFT JOIN favorites f ON a.id = f.article_id
LEFT JOIN article_images ai ON a.id = ai.article_id
LEFT JOIN article_images ai_primary ON a.id = ai_primary.article_id AND ai_primary.is_primary = TRUE
GROUP BY a.id;

-- =============================================
-- INSERT SAMPLE DATA
-- =============================================

-- Insert sample categories
INSERT INTO `categories` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Electronics', 'electronics', 'Electronic devices and accessories', 'fas fa-laptop', 1, TRUE),
('Vehicles', 'vehicles', 'Cars, motorcycles, and other vehicles', 'fas fa-car', 2, TRUE),
('Fashion', 'fashion', 'Clothing, shoes, and accessories', 'fas fa-tshirt', 3, TRUE),
('Home & Garden', 'home-garden', 'Furniture, appliances, and garden items', 'fas fa-home', 4, TRUE),
('Sports', 'sports', 'Sports equipment and accessories', 'fas fa-football-ball', 5, TRUE),
('Books & Media', 'books-media', 'Books, movies, music, and games', 'fas fa-book', 6, TRUE),
('Services', 'services', 'Professional and personal services', 'fas fa-tools', 7, TRUE),
('Real Estate', 'real-estate', 'Property rentals and sales', 'fas fa-building', 8, TRUE);

-- Insert subcategories for Electronics
INSERT INTO `categories` (`parent_id`, `name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
(1, 'Smartphones', 'smartphones', 'Mobile phones and accessories', 'fas fa-mobile-alt', 1, TRUE),
(1, 'Laptops', 'laptops', 'Laptops and notebooks', 'fas fa-laptop', 2, TRUE),
(1, 'Gaming', 'gaming', 'Gaming consoles and accessories', 'fas fa-gamepad', 3, TRUE),
(1, 'Audio', 'audio', 'Headphones, speakers, and audio equipment', 'fas fa-headphones', 4, TRUE);

-- =============================================
-- CREATE TRIGGERS FOR AUTO-UPDATES
-- =============================================

-- Trigger to update article favorites count
DELIMITER //
CREATE TRIGGER update_article_favorites_count_insert
AFTER INSERT ON favorites
FOR EACH ROW
BEGIN
    UPDATE articles 
    SET favorites_count = (
        SELECT COUNT(*) FROM favorites WHERE article_id = NEW.article_id
    )
    WHERE id = NEW.article_id;
END//

CREATE TRIGGER update_article_favorites_count_delete
AFTER DELETE ON favorites
FOR EACH ROW
BEGIN
    UPDATE articles 
    SET favorites_count = (
        SELECT COUNT(*) FROM favorites WHERE article_id = OLD.article_id
    )
    WHERE id = OLD.article_id;
END//

-- Trigger to update conversation last_message_at
CREATE TRIGGER update_conversation_last_message
AFTER INSERT ON messages
FOR EACH ROW
BEGIN
    UPDATE conversations 
    SET last_message_at = NEW.created_at,
        updated_at = NEW.created_at
    WHERE id = NEW.conversation_id;
    
    UPDATE articles 
    SET messages_count = (
        SELECT COUNT(DISTINCT conversation_id) 
        FROM messages m
        JOIN conversations c ON m.conversation_id = c.id
        WHERE c.article_id = (
            SELECT article_id FROM conversations WHERE id = NEW.conversation_id
        )
    )
    WHERE id = (
        SELECT article_id FROM conversations WHERE id = NEW.conversation_id
    );
END//

DELIMITER ;

-- =============================================
-- CREATE INDEXES FOR BETTER PERFORMANCE
-- =============================================

-- Additional composite indexes for common queries
CREATE INDEX idx_articles_user_status ON articles(user_id, status);
CREATE INDEX idx_articles_category_status_created ON articles(category_id, status, created_at);
CREATE INDEX idx_articles_status_featured_created ON articles(status, is_featured, created_at);
CREATE INDEX idx_messages_conversation_created ON messages(conversation_id, created_at);
CREATE INDEX idx_notifications_user_read_created ON notifications(user_id, is_read, created_at);

COMMIT;