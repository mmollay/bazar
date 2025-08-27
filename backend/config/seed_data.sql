-- Seed data for Bazar Marketplace
-- Creates default admin user and sample data

-- Create default admin user (password: admin123)
-- Note: Password hash for 'admin123' = $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
INSERT INTO users (email, username, password_hash, is_verified, is_admin, admin_role, created_at) VALUES 
('admin@bazar.com', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 1, 'super_admin', NOW()),
('demo@bazar.com', 'demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 0, NULL, NOW());

-- Insert sample categories
INSERT INTO categories (name, slug, description, parent_id, icon, sort_order, is_active) VALUES
('Elektronik', 'elektronik', 'Elektronische Geräte und Zubehör', NULL, 'fa-laptop', 1, 1),
('Smartphones', 'smartphones', 'Handys und Zubehör', 1, 'fa-mobile', 1, 1),
('Computer', 'computer', 'PCs, Laptops und Zubehör', 1, 'fa-desktop', 2, 1),
('Fahrzeuge', 'fahrzeuge', 'Autos, Motorräder und mehr', NULL, 'fa-car', 2, 1),
('Autos', 'autos', 'PKW und Transporter', 4, 'fa-car', 1, 1),
('Motorräder', 'motorrader', 'Motorräder und Roller', 4, 'fa-motorcycle', 2, 1),
('Immobilien', 'immobilien', 'Wohnungen und Häuser', NULL, 'fa-home', 3, 1),
('Wohnungen', 'wohnungen', 'Mietwohnungen und Eigentum', 7, 'fa-building', 1, 1),
('Häuser', 'hauser', 'Einfamilienhäuser und mehr', 7, 'fa-home', 2, 1),
('Mode & Accessoires', 'mode', 'Kleidung und Accessoires', NULL, 'fa-tshirt', 4, 1),
('Möbel', 'mobel', 'Möbel und Einrichtung', NULL, 'fa-couch', 5, 1),
('Freizeit & Sport', 'freizeit', 'Sport und Hobby', NULL, 'fa-football-ball', 6, 1);

-- Insert system settings
INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES
('site_name', 'Bazar Marketplace', 'general'),
('site_email', 'info@bazar.com', 'general'),
('items_per_page', '20', 'display'),
('max_images_per_article', '10', 'articles'),
('search_radius_default', '50', 'search'),
('enable_ai_suggestions', 'true', 'features'),
('enable_messaging', 'true', 'features'),
('enable_ratings', 'true', 'features'),
('maintenance_mode', 'false', 'system'),
('google_vision_api_key', '', 'api'),
('smtp_host', 'localhost', 'email'),
('smtp_port', '587', 'email'),
('smtp_encryption', 'tls', 'email'),
('currency', 'EUR', 'general'),
('date_format', 'd.m.Y', 'display'),
('time_format', 'H:i', 'display');

-- Insert email templates
INSERT INTO email_templates (template_key, subject, body, variables) VALUES
('welcome', 'Willkommen bei Bazar!', '<h1>Willkommen bei Bazar!</h1><p>Hallo {{username}},</p><p>Vielen Dank für Ihre Registrierung.</p>', '["username", "email"]'),
('password_reset', 'Passwort zurücksetzen', '<h1>Passwort zurücksetzen</h1><p>Klicken Sie hier: {{reset_link}}</p>', '["reset_link", "username"]'),
('article_approved', 'Ihr Artikel wurde genehmigt', '<h1>Artikel genehmigt!</h1><p>Ihr Artikel "{{title}}" wurde genehmigt.</p>', '["title", "article_link"]'),
('new_message', 'Neue Nachricht erhalten', '<h1>Sie haben eine neue Nachricht</h1><p>{{sender}} hat Ihnen eine Nachricht geschickt.</p>', '["sender", "message", "article_title"]');

-- Insert FAQ categories and items
INSERT INTO faq_categories (name, slug, sort_order) VALUES
('Allgemein', 'allgemein', 1),
('Verkaufen', 'verkaufen', 2),
('Kaufen', 'kaufen', 3),
('Sicherheit', 'sicherheit', 4);

INSERT INTO faq_items (category_id, question, answer, sort_order) VALUES
(1, 'Was ist Bazar?', 'Bazar ist ein moderner Online-Marktplatz für den Kauf und Verkauf von Artikeln aller Art.', 1),
(2, 'Wie erstelle ich einen Artikel?', 'Klicken Sie auf den Plus-Button, laden Sie Bilder hoch und lassen Sie unsere KI die Details automatisch ausfüllen.', 1),
(3, 'Wie kontaktiere ich einen Verkäufer?', 'Klicken Sie auf "Nachricht senden" in der Artikelansicht, um mit dem Verkäufer zu chatten.', 1),
(4, 'Ist Bazar sicher?', 'Ja, wir verwenden moderne Sicherheitsstandards und überprüfen alle Nutzer.', 1);

-- Insert CMS pages
INSERT INTO cms_pages (page_key, title, slug, content, meta_title, meta_description, status) VALUES
('privacy', 'Datenschutzerklärung', 'datenschutz', '<h1>Datenschutzerklärung</h1><p>Ihre Privatsphäre ist uns wichtig...</p>', 'Datenschutz - Bazar', 'Datenschutzerklärung von Bazar Marketplace', 'published'),
('terms', 'Allgemeine Geschäftsbedingungen', 'agb', '<h1>AGB</h1><p>Nutzungsbedingungen für Bazar...</p>', 'AGB - Bazar', 'Allgemeine Geschäftsbedingungen', 'published'),
('imprint', 'Impressum', 'impressum', '<h1>Impressum</h1><p>Bazar GmbH...</p>', 'Impressum - Bazar', 'Impressum und Kontaktinformationen', 'published'),
('about', 'Über uns', 'ueber-uns', '<h1>Über Bazar</h1><p>Wir sind ein moderner Marktplatz...</p>', 'Über uns - Bazar', 'Erfahren Sie mehr über Bazar', 'published');

-- Insert sample AI models configuration
INSERT INTO ai_models (provider, model_name, api_endpoint, is_active, capabilities) VALUES
('google_vision', 'Google Vision API', 'https://vision.googleapis.com/v1/', 1, '["object_detection", "text_detection", "label_detection", "safe_search"]'),
('local_fallback', 'Local Analysis', 'local', 1, '["basic_analysis", "color_extraction"]');

-- Create indexes for better performance
CREATE INDEX idx_articles_search ON articles(title, description);
CREATE INDEX idx_articles_location ON articles(latitude, longitude);
CREATE INDEX idx_articles_price ON articles(price);
CREATE INDEX idx_messages_conversation ON messages(sender_id, receiver_id);
CREATE INDEX idx_search_analytics_query ON search_analytics(search_query);

-- Success message
SELECT 'Database seeded successfully!' as message;