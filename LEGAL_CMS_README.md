# Legal Compliance & CMS System - Bazar Marketplace

## Overview

This system provides comprehensive legal compliance and content management capabilities for the Bazar marketplace, ensuring GDPR compliance and meeting German legal requirements.

## 🏗️ System Architecture

### Backend Components

1. **Database Schema** (`/backend/config/legal_cms_schema.sql`)
   - CMS pages with versioning
   - Cookie consent tracking
   - Support ticket system
   - FAQ management
   - GDPR data requests
   - Legal consent audit trail

2. **Controllers**
   - `CMSController.php` - Content management operations
   - `CookieConsentController.php` - GDPR cookie compliance
   - `SupportController.php` - Ticket and contact system
   - `GDPRController.php` - Data protection rights

3. **API Endpoints** (added to `/backend/api/index.php`)
   - `/v1/cms/*` - Content management
   - `/v1/legal/*` - Public legal pages
   - `/v1/cookies/*` - Cookie consent management
   - `/v1/support/*` - Support system
   - `/v1/gdpr/*` - Data protection requests

### Frontend Components

1. **Admin CMS Interface** (`/admin/pages/cms.html`)
   - Rich text editor (Quill.js)
   - Page management with versioning
   - Legal page templates
   - Content workflow management

2. **Cookie Banner** (`/frontend/components/cookie-banner.js`)
   - GDPR-compliant consent management
   - Granular cookie categories
   - Preference center
   - Consent audit trail

## 📋 Legal Pages (German Compliance)

### Mandatory Legal Pages
All required legal pages are pre-populated in German:

1. **Datenschutzerklärung** (Privacy Policy)
   - GDPR Article 13/14 compliance
   - Data processing information
   - User rights explanation
   - Contact information for DPO

2. **AGB** (Terms of Service)
   - Marketplace terms and conditions
   - User obligations and rights
   - Liability limitations
   - Contract formation rules

3. **Impressum** (Legal Imprint)
   - TMG § 5 compliance
   - Company information
   - Contact details
   - Regulatory information

4. **Widerrufsbelehrung** (Cancellation Rights)
   - Consumer withdrawal rights
   - 14-day cancellation period
   - Return procedures
   - Template cancellation form

### API Endpoints for Legal Pages
```
GET /api/v1/legal/privacy     - Privacy Policy
GET /api/v1/legal/terms       - Terms of Service  
GET /api/v1/legal/imprint     - Legal Imprint
GET /api/v1/legal/cancellation - Withdrawal Rights
```

## 🍪 GDPR Cookie Compliance

### Cookie Categories
The system implements granular cookie consent with these categories:

1. **Necessary Cookies** (Always enabled)
   - Essential for site functionality
   - Cannot be disabled by users

2. **Functional Cookies** (Optional)
   - Enhanced functionality
   - User preferences
   - Chat widgets

3. **Analytics Cookies** (Optional)
   - Google Analytics (anonymized)
   - Performance monitoring
   - Usage statistics

4. **Marketing Cookies** (Optional)
   - Advertising pixels
   - Retargeting
   - Campaign tracking

5. **Social Media Cookies** (Optional)
   - Social sharing
   - Social login
   - Social widgets

### Cookie Banner Features
- **GDPR Compliant**: Requires explicit consent
- **Granular Control**: Individual category selection
- **Preference Center**: Users can modify choices
- **Audit Trail**: All consents are logged
- **Withdrawal**: Easy consent withdrawal
- **Mobile Responsive**: Works on all devices

### Cookie Management API
```
GET    /api/v1/cookies/consent     - Get consent status
POST   /api/v1/cookies/consent     - Save consent preferences
PUT    /api/v1/cookies/consent     - Update preferences
DELETE /api/v1/cookies/consent     - Withdraw consent
GET    /api/v1/cookies/audit       - Admin: consent audit
GET    /api/v1/cookies/stats       - Admin: statistics
```

## 🎫 Support & Contact System

### Support Ticket System
- **Multi-channel**: Email, web form, in-app
- **Categorization**: Technical, billing, legal, etc.
- **Priority Levels**: Low, normal, high, urgent
- **Status Tracking**: Open, assigned, pending, resolved
- **SLA Tracking**: Response time monitoring
- **File Attachments**: Support for evidence/screenshots

### Contact Forms
- **General Inquiries**: Basic contact form
- **Rate Limiting**: Prevents spam/abuse  
- **Auto-response**: Immediate confirmation
- **Admin Notifications**: Real-time alerts

### Support API Endpoints
```
POST /api/v1/support/tickets          - Create ticket
GET  /api/v1/support/tickets          - List tickets
GET  /api/v1/support/tickets/{id}     - Get ticket details
POST /api/v1/support/tickets/{id}/messages - Add message
PUT  /api/v1/support/tickets/{id}/status   - Update status (admin)
PUT  /api/v1/support/tickets/{id}/assign   - Assign ticket (admin)
POST /api/v1/contact                   - Submit contact form
```

## 🛡️ GDPR Data Protection

### User Rights Implementation
The system implements all GDPR rights:

1. **Right of Access** (Article 15)
   - Complete data export
   - JSON format with all user data
   - Includes articles, messages, ratings, etc.

2. **Right to Rectification** (Article 16)
   - Data correction requests
   - Admin interface for updates

3. **Right to Erasure** (Article 17)
   - Data anonymization process
   - Maintains data integrity
   - Preserves legal obligations

4. **Right to Data Portability** (Article 20)
   - Structured data export
   - Machine-readable format

5. **Right to Restrict Processing** (Article 18)
   - Processing limitation flags
   - Temporary data holds

### GDPR Request Process
1. User submits request via web form
2. Email verification required
3. Admin receives notification
4. Admin processes request
5. User receives confirmation
6. Data export/deletion executed
7. Completion notification sent

### GDPR API Endpoints
```
POST /api/v1/gdpr/data-request         - Submit data request
POST /api/v1/gdpr/verify/{token}      - Verify request
GET  /api/v1/gdpr/requests            - Admin: list requests
POST /api/v1/gdpr/requests/{id}/process - Admin: process request
GET  /api/v1/gdpr/requests/{id}/data   - Admin: get user data
POST /api/v1/gdpr/consent             - Record legal consent
GET  /api/v1/gdpr/consent/history     - Get consent history
```

## 📊 CMS Content Management

### Page Management
- **WYSIWYG Editor**: Rich text editing with Quill.js
- **SEO Features**: Meta descriptions, keywords, canonical URLs
- **Version Control**: Full page history and rollback
- **Publishing Workflow**: Draft/published states
- **Multi-language**: German/English support
- **Template System**: Customizable page templates

### Content Types
1. **Legal Pages**: Special handling with review requirements
2. **General Pages**: Standard content pages  
3. **Help Pages**: Documentation and tutorials
4. **FAQ Pages**: Frequently asked questions

### CMS Features
- **Search**: Full-text search across all content
- **Filtering**: By type, status, language
- **Bulk Operations**: Mass updates and publishing
- **Access Control**: Admin-only editing
- **Audit Trail**: Change tracking and history

### CMS API Endpoints
```
GET    /api/v1/cms/pages              - List pages
GET    /api/v1/cms/pages/{id}         - Get page
POST   /api/v1/cms/pages              - Create page (admin)
PUT    /api/v1/cms/pages/{id}         - Update page (admin)
DELETE /api/v1/cms/pages/{id}         - Delete page (admin)
GET    /api/v1/faq                    - Get FAQ
POST   /api/v1/faq/{id}/feedback      - Submit FAQ feedback
GET    /api/v1/news                   - Get news/announcements
```

## 🔧 Setup Instructions

### 1. Database Setup
```sql
-- Run the legal CMS schema
mysql -u root -p < backend/config/legal_cms_schema.sql
```

### 2. Include Cookie Banner
Add to your main HTML template:
```html
<script src="/frontend/components/cookie-banner.js"></script>
```

### 3. Admin Access
- Navigate to `/admin/pages/cms.html`
- Login with admin credentials
- Manage content through the interface

### 4. Legal Page Integration
The legal pages are automatically available at:
- `/api/v1/legal/privacy`
- `/api/v1/legal/terms`
- `/api/v1/legal/imprint`
- `/api/v1/legal/cancellation`

## 📈 Admin Features

### CMS Dashboard
- Content overview and statistics
- Recent changes and activity
- Publishing workflow management
- Version control and rollback

### Support Dashboard  
- Ticket queue management
- Response time tracking
- Customer satisfaction metrics
- Workload distribution

### GDPR Compliance Dashboard
- Data request tracking
- Consent audit logs
- Processing statistics
- Compliance reporting

### Cookie Analytics
- Consent acceptance rates
- Category preferences
- Trend analysis
- Withdrawal patterns

## 🔒 Security Features

### Data Protection
- **Encryption**: Sensitive data encrypted at rest
- **Access Control**: Role-based permissions
- **Audit Logging**: All administrative actions logged
- **Rate Limiting**: Protection against abuse
- **Input Validation**: XSS and injection prevention

### Cookie Security
- **SameSite**: Secure cookie settings
- **HttpOnly**: Server-only cookies where appropriate
- **Secure**: HTTPS-only cookies
- **Expiration**: Proper cookie lifetime management

### GDPR Compliance
- **Consent Logging**: Immutable audit trail
- **Data Minimization**: Only collect necessary data
- **Purpose Limitation**: Clear data usage purposes
- **Retention Policies**: Automatic data cleanup

## 📱 Mobile Support

All components are fully responsive and mobile-optimized:
- **Cookie Banner**: Touch-friendly interface
- **CMS Interface**: Mobile admin capabilities  
- **Support Forms**: Mobile-first design
- **Legal Pages**: Readable on all devices

## 🌐 Internationalization

Current language support:
- **German**: Primary language for legal compliance
- **English**: Secondary language support
- **Extensible**: Easy to add more languages

## 🚀 Performance Considerations

### Caching Strategy
- **Page Caching**: Static legal pages cached
- **API Response Caching**: Reduced database load
- **CDN Integration**: Fast global content delivery
- **Browser Caching**: Optimized cache headers

### Database Optimization
- **Indexed Queries**: All search operations optimized
- **Partitioning**: Large tables partitioned by date
- **Archive Strategy**: Old data moved to archive tables
- **Query Optimization**: Efficient data retrieval

## 📋 Compliance Checklist

### GDPR Requirements ✅
- [x] Lawful basis for processing
- [x] Consent management
- [x] Data subject rights implementation
- [x] Privacy by design
- [x] Data protection impact assessment
- [x] Breach notification procedures
- [x] Data protection officer contact

### German Legal Requirements ✅
- [x] Impressum (TMG § 5)
- [x] Privacy policy (DSGVO)
- [x] Terms of service
- [x] Withdrawal rights (BGB)
- [x] Cookie compliance (TTDSG)
- [x] Consumer protection laws

## 🔄 Maintenance & Updates

### Regular Tasks
- **Legal Review**: Quarterly legal page reviews
- **Consent Audit**: Monthly consent log analysis
- **Security Updates**: Regular dependency updates
- **Backup Verification**: Daily backup integrity checks
- **Performance Monitoring**: Continuous system monitoring

### Version Updates
- Legal pages are versioned
- API versioning for backward compatibility
- Database migration scripts provided
- Rollback procedures documented

---

## Support

For technical support or legal compliance questions:
- **Technical**: Submit ticket via support system
- **Legal**: Contact legal@bazar.com  
- **GDPR**: Contact datenschutz@bazar.com
- **Emergency**: Use priority support channels

This system provides a solid foundation for legal compliance while maintaining excellent user experience and administrative efficiency.