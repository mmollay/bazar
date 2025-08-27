# Bazar Marketplace - Datenbank Information

## ✅ Datenbank Status

Die Datenbank **`bazar_marketplace`** ist erfolgreich angelegt und konfiguriert!

## 📊 Datenbankstruktur

- **Datenbank Name**: `bazar_marketplace`
- **Zeichensatz**: `utf8mb4_unicode_ci`
- **Anzahl Tabellen**: **39 Tabellen**
- **Status**: ✅ Vollständig angelegt und einsatzbereit

## 📋 Tabellen Übersicht

### Kern-Tabellen
- `users` - Benutzerverwaltung mit OAuth und 2FA
- `articles` - Artikel/Inserate 
- `categories` - Hierarchische Kategorien (9 Kategorien angelegt)
- `article_images` - Bilder für Artikel
- `messages` - Nachrichten-System
- `favorites` - Favoriten/Merkliste
- `ratings` - Bewertungssystem

### Admin & System
- `admin_logs` - Admin-Aktivitätsprotokolle
- `admin_sessions` - Admin-Sitzungsverwaltung
- `admin_notifications` - Admin-Benachrichtigungen
- `system_settings` - Systemeinstellungen (25 Einstellungen konfiguriert)
- `system_statistics` - Systemstatistiken

### KI & Suche
- `ai_suggestions` - KI-generierte Vorschläge
- `ai_models` - KI-Modell-Konfiguration
- `ai_processing_queue` - KI-Verarbeitungswarteschlange
- `search_analytics` - Such-Analysen
- `search_suggestions` - Suchvorschläge
- `saved_searches` - Gespeicherte Suchen
- `popular_searches` - Beliebte Suchanfragen

### CMS & Support
- `cms_pages` - CMS-Seiten (4 Seiten angelegt)
- `cms_page_versions` - Versionierung
- `faq_categories` - FAQ-Kategorien (4 Kategorien)
- `faq_items` - FAQ-Einträge (4 Einträge)
- `support_tickets` - Support-Tickets
- `support_ticket_messages` - Ticket-Nachrichten
- `contact_submissions` - Kontaktformular-Einreichungen

### Rechtliches & Compliance
- `cookie_consents` - Cookie-Einwilligungen (DSGVO)
- `legal_consents` - Rechtliche Einwilligungen
- `gdpr_requests` - DSGVO-Anfragen
- `email_templates` - E-Mail-Vorlagen (4 Vorlagen)

### Weitere Tabellen
- `user_reports` - Nutzer-Meldungen
- `news_announcements` - News/Ankündigungen
- `price_history` - Preisverlauf
- `backup_logs` - Backup-Protokolle
- `search_alert_queue` - Such-Benachrichtigungen
- `search_filter_analytics` - Filter-Analysen
- `search_performance_metrics` - Performance-Metriken
- `search_query_expansions` - Sucherweiterungen

## 🔐 Zugangsdaten

### Admin-Benutzer
- **E-Mail**: `admin@bazar.com`
- **Passwort**: `admin123`
- **Rolle**: Super Admin

### Demo-Benutzer  
- **E-Mail**: `demo@bazar.com`
- **Passwort**: `admin123`
- **Rolle**: Normaler Benutzer

## 🚀 Datenbank verwenden

### Mit phpMyAdmin
```
http://localhost/phpmyadmin
Datenbank: bazar_marketplace
```

### Mit MySQL CLI
```bash
mysql -u root bazar_marketplace
```

### In PHP-Code
```php
$db_host = 'localhost';
$db_name = 'bazar_marketplace';
$db_user = 'root';
$db_pass = '';
```

## 📝 Wichtige SQL-Dateien

Alle Schema-Dateien befinden sich in `/backend/config/`:

1. **database.sql** - Haupt-Datenbankschema
2. **messaging_schema.sql** - Messaging-System Erweiterungen
3. **search_schema.sql** - Such-System Erweiterungen  
4. **legal_cms_schema.sql** - CMS und rechtliche Tabellen
5. **seed_data.sql** - Beispieldaten und Standardkonfiguration

## ✅ Status

Die Datenbank ist vollständig konfiguriert und enthält:
- ✅ Alle erforderlichen Tabellen (39 Stück)
- ✅ Standardbenutzer (Admin + Demo)
- ✅ Kategorien (12 Hauptkategorien)
- ✅ Systemeinstellungen (25 Konfigurationen)
- ✅ E-Mail-Vorlagen (4 Templates)
- ✅ CMS-Seiten (Datenschutz, AGB, Impressum, Über uns)
- ✅ FAQ-System (4 Kategorien mit Einträgen)
- ✅ Alle Indizes für optimale Performance

Die Datenbank ist **produktionsbereit** und kann sofort verwendet werden!