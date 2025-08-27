-- Legal Compliance and CMS Database Schema Extension
-- Extends the existing Bazar Marketplace database with legal pages and CMS functionality

USE bazar_marketplace;

-- CMS Pages table for legal and general content management
CREATE TABLE cms_pages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    slug VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    meta_description TEXT,
    meta_keywords VARCHAR(500),
    page_type ENUM('legal', 'general', 'help', 'faq') DEFAULT 'general',
    language CHAR(2) DEFAULT 'de',
    is_published BOOLEAN DEFAULT FALSE,
    is_required BOOLEAN DEFAULT FALSE, -- For mandatory legal pages
    template VARCHAR(100) DEFAULT 'default',
    legal_version VARCHAR(20) DEFAULT '1.0', -- Version tracking for legal pages
    last_reviewed_at TIMESTAMP NULL, -- Last legal review date
    review_required BOOLEAN DEFAULT FALSE, -- Needs legal review
    seo_title VARCHAR(60),
    canonical_url VARCHAR(500),
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_slug (slug),
    INDEX idx_page_type (page_type),
    INDEX idx_language (language),
    INDEX idx_published (is_published),
    INDEX idx_required (is_required),
    FULLTEXT KEY ft_content_search (title, content, meta_description)
);

-- CMS Page versions for content history and rollback capability
CREATE TABLE cms_page_versions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    page_id INT NOT NULL,
    version_number INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    meta_description TEXT,
    meta_keywords VARCHAR(500),
    legal_version VARCHAR(20),
    change_summary TEXT, -- Summary of changes made
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (page_id) REFERENCES cms_pages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_page_version (page_id, version_number),
    INDEX idx_page_id (page_id),
    INDEX idx_created_at (created_at)
);

-- Enhanced cookie consents table (replacing the basic one from main schema)
DROP TABLE IF EXISTS cookie_consents;
CREATE TABLE cookie_consents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    session_id VARCHAR(128) NULL, -- For anonymous users
    ip_address VARCHAR(45) NOT NULL,
    necessary_cookies BOOLEAN DEFAULT TRUE,
    functional_cookies BOOLEAN DEFAULT FALSE,
    analytics_cookies BOOLEAN DEFAULT FALSE,
    marketing_cookies BOOLEAN DEFAULT FALSE,
    social_cookies BOOLEAN DEFAULT FALSE,
    consent_version VARCHAR(10) DEFAULT '1.0', -- Track consent policy version
    consent_method ENUM('banner', 'preferences', 'api') DEFAULT 'banner',
    user_agent TEXT,
    browser_fingerprint VARCHAR(64), -- For tracking consent across sessions
    consent_withdrawn BOOLEAN DEFAULT FALSE,
    withdrawn_at TIMESTAMP NULL,
    consent_data JSON, -- Detailed consent information
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_browser_fingerprint (browser_fingerprint),
    INDEX idx_created_at (created_at),
    INDEX idx_expires_at (expires_at)
);

-- Support tickets system for customer service
CREATE TABLE support_tickets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ticket_number VARCHAR(20) UNIQUE NOT NULL, -- e.g., "BZ-2024-001234"
    user_id INT NULL, -- NULL for anonymous submissions
    email VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    category ENUM('general', 'technical', 'billing', 'account', 'content', 'legal', 'bug_report', 'feature_request') NOT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    status ENUM('open', 'assigned', 'pending', 'resolved', 'closed') DEFAULT 'open',
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    resolution_notes TEXT NULL,
    assigned_to INT NULL, -- Admin user handling the ticket
    related_article_id INT NULL, -- If ticket is about specific article
    related_user_id INT NULL, -- If ticket is about specific user
    attachment_urls JSON, -- Array of uploaded file URLs
    internal_notes TEXT, -- Admin-only notes
    customer_satisfaction INT NULL CHECK (customer_satisfaction BETWEEN 1 AND 5),
    satisfaction_comment TEXT NULL,
    first_response_at TIMESTAMP NULL, -- SLA tracking
    resolved_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (related_article_id) REFERENCES articles(id) ON DELETE SET NULL,
    FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ticket_number (ticket_number),
    INDEX idx_user_id (user_id),
    INDEX idx_email (email),
    INDEX idx_category (category),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_created_at (created_at),
    FULLTEXT KEY ft_ticket_search (subject, description)
);

-- Support ticket messages for conversation tracking
CREATE TABLE support_ticket_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ticket_id INT NOT NULL,
    sender_type ENUM('customer', 'admin', 'system') NOT NULL,
    sender_id INT NULL, -- User ID for customer/admin, NULL for system
    sender_name VARCHAR(100) NOT NULL,
    sender_email VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_internal BOOLEAN DEFAULT FALSE, -- Internal admin notes
    attachment_urls JSON, -- Array of file URLs
    message_type ENUM('message', 'status_change', 'assignment', 'auto_reply') DEFAULT 'message',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_sender_id (sender_id),
    INDEX idx_created_at (created_at),
    INDEX idx_internal (is_internal)
);

-- FAQ management system
CREATE TABLE faq_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    icon VARCHAR(100), -- Icon class or file name
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_slug (slug),
    INDEX idx_sort_order (sort_order),
    INDEX idx_active (is_active)
);

CREATE TABLE faq_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    keywords VARCHAR(500), -- Search keywords
    is_featured BOOLEAN DEFAULT FALSE,
    view_count INT DEFAULT 0,
    helpful_count INT DEFAULT 0,
    not_helpful_count INT DEFAULT 0,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (category_id) REFERENCES faq_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_category_id (category_id),
    INDEX idx_featured (is_featured),
    INDEX idx_sort_order (sort_order),
    INDEX idx_active (is_active),
    FULLTEXT KEY ft_faq_search (question, answer, keywords)
);

-- FAQ feedback tracking
CREATE TABLE faq_feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    faq_id INT NOT NULL,
    user_id INT NULL,
    ip_address VARCHAR(45) NOT NULL,
    is_helpful BOOLEAN NOT NULL,
    feedback_comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (faq_id) REFERENCES faq_items(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_faq_id (faq_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- News and announcements management
CREATE TABLE news_announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    content TEXT NOT NULL,
    excerpt TEXT,
    featured_image_url VARCHAR(500),
    category ENUM('news', 'announcement', 'maintenance', 'feature', 'legal') NOT NULL,
    priority ENUM('low', 'normal', 'high') DEFAULT 'normal',
    is_published BOOLEAN DEFAULT FALSE,
    is_featured BOOLEAN DEFAULT FALSE,
    show_banner BOOLEAN DEFAULT FALSE, -- Show in site banner
    banner_text VARCHAR(200), -- Short banner text
    banner_type ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
    target_audience ENUM('all', 'users', 'sellers', 'buyers', 'admins') DEFAULT 'all',
    publish_at TIMESTAMP NULL, -- Scheduled publishing
    expires_at TIMESTAMP NULL, -- Auto-unpublish date
    view_count INT DEFAULT 0,
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_slug (slug),
    INDEX idx_category (category),
    INDEX idx_published (is_published),
    INDEX idx_featured (is_featured),
    INDEX idx_show_banner (show_banner),
    INDEX idx_publish_at (publish_at),
    INDEX idx_created_at (created_at),
    FULLTEXT KEY ft_news_search (title, content, excerpt)
);

-- Contact form submissions (for general inquiries)
CREATE TABLE contact_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    contact_reason ENUM('general', 'partnership', 'press', 'feedback', 'complaint', 'other') DEFAULT 'general',
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    status ENUM('new', 'read', 'replied', 'resolved') DEFAULT 'new',
    admin_notes TEXT,
    replied_by INT NULL,
    replied_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_contact_reason (contact_reason),
    INDEX idx_created_at (created_at)
);

-- Legal consent records for audit trail
CREATE TABLE legal_consents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    consent_type ENUM('terms', 'privacy', 'cookies', 'marketing', 'data_processing') NOT NULL,
    consent_version VARCHAR(20) NOT NULL, -- Version of terms/policy agreed to
    is_consented BOOLEAN NOT NULL,
    consent_method ENUM('registration', 'update', 'banner', 'form') NOT NULL,
    consent_data JSON, -- Additional consent details
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_consent_type (consent_type),
    INDEX idx_consent_version (consent_version),
    INDEX idx_created_at (created_at)
);

-- GDPR data requests (Article 15 - Right of Access, Article 17 - Right to Erasure)
CREATE TABLE gdpr_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    request_type ENUM('data_export', 'data_deletion', 'data_correction', 'data_portability', 'processing_restriction') NOT NULL,
    email VARCHAR(255) NOT NULL, -- For verification
    status ENUM('pending', 'verified', 'processing', 'completed', 'rejected') DEFAULT 'pending',
    verification_token VARCHAR(64) UNIQUE NOT NULL,
    verification_expires_at TIMESTAMP NOT NULL,
    request_details JSON, -- Additional request information
    processing_notes TEXT, -- Admin notes
    data_export_url VARCHAR(500) NULL, -- Download link for data export
    processed_by INT NULL,
    processed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_email (email),
    INDEX idx_request_type (request_type),
    INDEX idx_status (status),
    INDEX idx_verification_token (verification_token),
    INDEX idx_created_at (created_at)
);

-- Insert default legal pages (German law compliance)
INSERT INTO cms_pages (slug, title, content, page_type, is_published, is_required, legal_version, meta_description) VALUES 
('datenschutz', 'Datenschutzerklärung', 
'<h1>Datenschutzerklärung</h1>
<p>Diese Datenschutzerklärung klärt Sie über die Art, den Umfang und Zweck der Verarbeitung von personenbezogenen Daten auf unserer Website auf.</p>

<h2>1. Verantwortliche Stelle</h2>
<p>Verantwortliche Stelle für die Datenverarbeitung auf dieser Website ist:</p>
<p>[Firmenname]<br>
[Adresse]<br>
[PLZ Ort]<br>
[Telefon]<br>
[E-Mail]</p>

<h2>2. Erhebung und Speicherung personenbezogener Daten</h2>
<p>Beim Besuch unserer Website werden automatisch Informationen allgemeiner Natur erfasst. Diese Informationen (Server-Logfiles) beinhalten etwa die Art des Webbrowsers, das verwendete Betriebssystem, den Domainnamen Ihres Internet-Service-Providers und ähnliches.</p>

<h2>3. Verwendung von Cookies</h2>
<p>Unsere Website verwendet Cookies. Das sind kleine Textdateien, die es möglich machen, auf dem Endgerät des Nutzers spezifische, auf den Nutzer bezogene Informationen zu speichern.</p>

<h2>4. Ihre Rechte</h2>
<p>Sie haben gegenüber uns folgende Rechte hinsichtlich der Sie betreffenden personenbezogenen Daten:</p>
<ul>
<li>Recht auf Auskunft</li>
<li>Recht auf Berichtigung oder Löschung</li>
<li>Recht auf Einschränkung der Verarbeitung</li>
<li>Recht auf Widerspruch gegen die Verarbeitung</li>
<li>Recht auf Datenübertragbarkeit</li>
</ul>

<p>Diese Datenschutzerklärung wurde zuletzt am [Datum] aktualisiert.</p>', 
'legal', TRUE, TRUE, '1.0', 'Datenschutzerklärung gemäß DSGVO für den Bazar Marketplace'),

('agb', 'Allgemeine Geschäftsbedingungen', 
'<h1>Allgemeine Geschäftsbedingungen</h1>

<h2>§ 1 Geltungsbereich</h2>
<p>Diese Allgemeinen Geschäftsbedingungen (nachfolgend "AGB") gelten für die Nutzung des Online-Marktplatzes "Bazar".</p>

<h2>§ 2 Vertragspartner</h2>
<p>Der Vertrag kommt zustande zwischen dem Nutzer und dem jeweiligen Anbieter der Waren oder Dienstleistungen.</p>

<h2>§ 3 Nutzung des Marktplatzes</h2>
<p>Die Nutzung unseres Marktplatzes ist kostenlos. Für bestimmte Zusatzleistungen können Gebühren anfallen.</p>

<h2>§ 4 Registrierung und Nutzerkonto</h2>
<p>Für die Nutzung bestimmter Funktionen ist eine Registrierung erforderlich. Bei der Registrierung sind wahrheitsgemäße Angaben zu machen.</p>

<h2>§ 5 Pflichten der Nutzer</h2>
<p>Nutzer verpflichten sich, keine rechtswidrigen Inhalte zu veröffentlichen und geltendes Recht zu beachten.</p>

<h2>§ 6 Haftung</h2>
<p>Die Haftung des Marktplatzbetreibers ist auf Vorsatz und grobe Fahrlässigkeit beschränkt.</p>

<p>Diese AGB treten am [Datum] in Kraft.</p>', 
'legal', TRUE, TRUE, '1.0', 'Allgemeine Geschäftsbedingungen für die Nutzung des Bazar Marketplace'),

('impressum', 'Impressum', 
'<h1>Impressum</h1>

<h2>Angaben gemäß § 5 TMG:</h2>
<p>[Firmenname]<br>
[Adresse]<br>
[PLZ Ort]</p>

<h2>Kontakt:</h2>
<p>Telefon: [Telefonnummer]<br>
E-Mail: [E-Mail-Adresse]</p>

<h2>Umsatzsteuer-ID:</h2>
<p>Umsatzsteuer-Identifikationsnummer gemäß §27 a Umsatzsteuergesetz:<br>
[USt-IdNr.]</p>

<h2>Wirtschafts-ID:</h2>
<p>[Wirtschafts-ID oder Handelsregisternummer]</p>

<h2>Aufsichtsbehörde:</h2>
<p>[Name der zuständigen Aufsichtsbehörde]</p>

<h2>Verantwortlich für den Inhalt nach § 55 Abs. 2 RStV:</h2>
<p>[Name]<br>
[Adresse]<br>
[PLZ Ort]</p>

<h2>Streitschlichtung</h2>
<p>Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit: https://ec.europa.eu/consumers/odr.</p>', 
'legal', TRUE, TRUE, '1.0', 'Impressum mit allen rechtlich erforderlichen Angaben gemäß TMG'),

('widerrufsrecht', 'Widerrufsbelehrung', 
'<h1>Widerrufsbelehrung</h1>

<h2>Widerrufsrecht</h2>
<p>Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.</p>

<p>Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag, an dem Sie oder ein von Ihnen benannter Dritter, der nicht der Beförderer ist, die Waren in Besitz genommen haben bzw. hat.</p>

<p>Um Ihr Widerrufsrecht auszuüben, müssen Sie uns mittels einer eindeutigen Erklärung über Ihren Entschluss, diesen Vertrag zu widerrufen, informieren.</p>

<h2>Folgen des Widerrufs</h2>
<p>Wenn Sie diesen Vertrag widerrufen, haben wir Ihnen alle Zahlungen, die wir von Ihnen erhalten haben, unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag zurückzuzahlen, an dem die Mitteilung über Ihren Widerruf dieses Vertrags bei uns eingegangen ist.</p>

<h2>Muster-Widerrufsformular</h2>
<p>Wenn Sie den Vertrag widerrufen wollen, dann füllen Sie bitte dieses Formular aus und senden Sie es zurück:</p>

<div style="border: 1px solid #ccc; padding: 20px; margin: 20px 0;">
<p>An [Firmenname, Adresse, E-Mail]:</p>
<p>Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über den Kauf der folgenden Waren (*):</p>
<p>Bestellt am (*)/erhalten am (*):</p>
<p>Name des/der Verbraucher(s):</p>
<p>Anschrift des/der Verbraucher(s):</p>
<p>Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier):</p>
<p>Datum:</p>
<p>(*) Unzutreffendes streichen.</p>
</div>', 
'legal', TRUE, TRUE, '1.0', 'Widerrufsbelehrung für Verbraucher gemäß BGB');

-- Insert default FAQ categories
INSERT INTO faq_categories (name, slug, description, icon, sort_order) VALUES
('Allgemeine Fragen', 'allgemein', 'Grundlegende Fragen zur Nutzung des Marktplatzes', 'fa-question-circle', 1),
('Konto & Anmeldung', 'konto', 'Fragen zu Registrierung, Login und Kontoverwaltung', 'fa-user', 2),
('Verkaufen', 'verkaufen', 'Alles rund um das Verkaufen von Artikeln', 'fa-shopping-cart', 3),
('Kaufen', 'kaufen', 'Fragen zum Kaufprozess und zu Käuferschutz', 'fa-credit-card', 4),
('Sicherheit', 'sicherheit', 'Fragen zu Sicherheit und Datenschutz', 'fa-shield-alt', 5),
('Technische Probleme', 'technik', 'Hilfe bei technischen Schwierigkeiten', 'fa-cog', 6);

-- Insert default FAQ items
INSERT INTO faq_items (category_id, question, answer, keywords, is_featured, sort_order) VALUES
(1, 'Wie funktioniert Bazar?', 'Bazar ist ein Online-Marktplatz, auf dem private Personen und Unternehmen Artikel verkaufen und kaufen können. Sie können kostenlos Artikel einstellen und mit anderen Nutzern kommunizieren.', 'funktionsweise, marktplatz, verkaufen, kaufen', TRUE, 1),
(1, 'Ist die Nutzung von Bazar kostenlos?', 'Die grundlegende Nutzung von Bazar ist kostenlos. Für bestimmte Zusatzleistungen wie das Hervorheben von Anzeigen können Gebühren anfallen.', 'kostenlos, gebühren, preise', TRUE, 2),
(2, 'Wie erstelle ich ein Konto?', 'Klicken Sie auf "Registrieren" und geben Sie Ihre E-Mail-Adresse und ein sicheres Passwort ein. Sie erhalten eine Bestätigungs-E-Mail zur Verifizierung Ihres Kontos.', 'registrierung, konto erstellen, anmeldung', TRUE, 1),
(2, 'Ich habe mein Passwort vergessen. Was kann ich tun?', 'Klicken Sie auf der Anmeldeseite auf "Passwort vergessen" und geben Sie Ihre E-Mail-Adresse ein. Sie erhalten einen Link zum Zurücksetzen Ihres Passworts.', 'passwort vergessen, zurücksetzen', FALSE, 2),
(3, 'Wie erstelle ich eine Anzeige?', 'Nach der Anmeldung klicken Sie auf "Artikel einstellen". Laden Sie Fotos hoch, geben Sie eine Beschreibung ein und wählen Sie die passende Kategorie. Unser KI-System hilft Ihnen beim Ausfüllen.', 'anzeige erstellen, artikel einstellen, verkaufen', TRUE, 1),
(4, 'Wie kann ich einen Artikel kaufen?', 'Kontaktieren Sie den Verkäufer über die Nachrichtenfunktion. Vereinbaren Sie Zahlungsweise und Übergabe direkt mit dem Verkäufer.', 'artikel kaufen, kontakt, nachrichten', TRUE, 1),
(5, 'Wie schütze ich mich vor Betrug?', 'Treffen Sie sich an öffentlichen Orten, prüfen Sie den Artikel vor dem Kauf und seien Sie vorsichtig bei Vorauszahlungen. Melden Sie verdächtige Angebote unserem Support.', 'betrug, sicherheit, schutz', TRUE, 1),
(6, 'Die Website lädt nicht richtig. Was kann ich tun?', 'Versuchen Sie, Ihren Browser-Cache zu leeren oder einen anderen Browser zu verwenden. Bei anhaltenden Problemen kontaktieren Sie unseren technischen Support.', 'technische probleme, browser, laden', FALSE, 1);

-- Insert default system settings for CMS and legal compliance
INSERT INTO system_settings (setting_key, setting_value, setting_type, description, is_public, group_name) VALUES
('legal_contact_email', 'legal@bazar.com', 'string', 'E-Mail für rechtliche Anfragen', FALSE, 'legal'),
('cookie_consent_version', '1.0', 'string', 'Version der Cookie-Richtlinie', TRUE, 'legal'),
('gdpr_dpo_email', 'datenschutz@bazar.com', 'string', 'E-Mail des Datenschutzbeauftragten', FALSE, 'legal'),
('legal_pages_require_review', 'true', 'boolean', 'Rechtliche Seiten benötigen Überprüfung vor Veröffentlichung', FALSE, 'legal'),
('cookie_banner_enabled', 'true', 'boolean', 'Cookie-Banner aktivieren', TRUE, 'legal'),
('support_auto_reply_enabled', 'true', 'boolean', 'Automatische Antwort bei Support-Tickets aktivieren', FALSE, 'support'),
('support_ticket_prefix', 'BZ', 'string', 'Prefix für Support-Ticket-Nummern', FALSE, 'support'),
('cms_cache_enabled', 'true', 'boolean', 'Caching für CMS-Seiten aktivieren', FALSE, 'cms'),
('cms_cache_ttl', '3600', 'number', 'Cache-Lebensdauer für CMS-Seiten in Sekunden', FALSE, 'cms'),
('faq_search_enabled', 'true', 'boolean', 'Suchfunktion in FAQ aktivieren', TRUE, 'cms');

-- Insert default email templates for legal and support
INSERT INTO email_templates (name, subject, body, variables) VALUES
('support_ticket_created', 'Ihr Support-Ticket wurde erstellt - {{ticket_number}}',
'Hallo {{name}},\n\nIhr Support-Ticket wurde erfolgreich erstellt:\n\nTicket-Nummer: {{ticket_number}}\nBetreff: {{subject}}\n\nWir werden uns so schnell wie möglich bei Ihnen melden.\n\nMit freundlichen Grüßen\nIhr Bazar Support-Team',
'["name", "ticket_number", "subject"]'),

('support_ticket_reply', 'Antwort zu Ihrem Support-Ticket {{ticket_number}}',
'Hallo {{name}},\n\nwir haben Ihrem Support-Ticket eine Antwort hinzugefügt:\n\nTicket-Nummer: {{ticket_number}}\nBetreff: {{subject}}\n\nNeue Nachricht:\n{{message}}\n\nSie können auf dieses Ticket antworten, indem Sie auf folgenden Link klicken:\n{{ticket_url}}\n\nMit freundlichen Grüßen\nIhr Bazar Support-Team',
'["name", "ticket_number", "subject", "message", "ticket_url"]'),

('gdpr_data_request_confirmation', 'Bestätigung Ihrer DSGVO-Datenanfrage',
'Hallo,\n\nwir haben Ihre Anfrage bezüglich Ihrer personenbezogenen Daten erhalten.\n\nAnfrage-Typ: {{request_type}}\nStatus: Verifizierung erforderlich\n\nBitte klicken Sie auf folgenden Link, um Ihre Anfrage zu bestätigen:\n{{verification_url}}\n\nDieser Link ist 48 Stunden gültig.\n\nMit freundlichen Grüßen\nIhr Bazar Team',
'["request_type", "verification_url"]'),

('cookie_consent_update', 'Aktualisierung unserer Cookie-Richtlinien',
'Hallo {{first_name}},\n\nwir haben unsere Cookie-Richtlinien aktualisiert. Die Änderungen treten am {{effective_date}} in Kraft.\n\nDie wichtigsten Änderungen:\n{{changes_summary}}\n\nSie können Ihre Cookie-Einstellungen jederzeit in Ihrem Konto anpassen.\n\nMit freundlichen Grüßen\nIhr Bazar Team',
'["first_name", "effective_date", "changes_summary"]');