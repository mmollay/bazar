# Bazar Marketplace

A modern, feature-rich marketplace application built with PHP and MySQL, featuring advanced search capabilities, real-time messaging, AI-powered suggestions, and comprehensive user management.

## Features

### Core Features
- **User Management**: Registration, login, OAuth integration (Google, Facebook), 2FA support
- **Article Management**: Create, edit, delete listings with multiple images
- **Advanced Search**: Full-text search with Elasticsearch integration, filters, saved searches
- **Real-time Messaging**: Chat system between buyers and sellers
- **Favorites System**: Save and manage favorite listings
- **Rating System**: User ratings and reviews
- **Location-based Search**: Find items near you with map integration

### Advanced Features
- **AI-powered Suggestions**: Smart pricing, title, and description recommendations
- **Multi-language Support**: Internationalization ready
- **Mobile-responsive Design**: Optimized for all devices
- **Admin Dashboard**: Comprehensive administration panel
- **Analytics**: Detailed insights and reporting
- **Cookie Consent Management**: GDPR compliant
- **SEO Optimized**: Meta tags, structured data, sitemap
- **Security**: Input validation, CSRF protection, rate limiting

## Tech Stack

### Backend
- **PHP 8.2+** with modern OOP practices
- **MySQL 8.0** with optimized schemas and indexes
- **Composer** for dependency management
- **JWT** for authentication
- **PHPMailer** for email functionality
- **Elasticsearch** for advanced search
- **Redis** for caching and sessions

### Frontend
- **HTML5** with semantic markup
- **Bootstrap 5** for responsive design
- **JavaScript ES6+** with modern features
- **Webpack** for asset building
- **SASS** for enhanced styling

### DevOps
- **Docker** with Docker Compose for local development
- **Nginx** reverse proxy
- **Apache** web server
- **PHPMyAdmin** for database management
- **MailHog** for email testing

## Installation

### Prerequisites
- Docker and Docker Compose
- Git
- Node.js 16+ (for frontend development)

### Quick Start with Docker

1. **Clone the repository**
```bash
git clone <repository-url>
cd bazar-marketplace
```

2. **Copy environment file**
```bash
cp .env.example .env
```

3. **Start the application**
```bash
docker-compose up -d
```

4. **Install dependencies**
```bash
# PHP dependencies
docker-compose exec web composer install

# Frontend dependencies
docker-compose exec web npm install
```

5. **Initialize the database**
```bash
# Import database schema
docker-compose exec mysql mysql -u root -p bazar_marketplace < database_schema.sql
```

6. **Build frontend assets**
```bash
docker-compose exec web npm run build
```

The application will be available at:
- **Main site**: http://localhost
- **API**: http://localhost/api
- **PHPMyAdmin**: http://localhost:8080
- **MailHog**: http://localhost:8025

### Manual Installation

1. **Requirements**
   - PHP 8.2 or higher
   - MySQL 8.0 or higher
   - Composer
   - Node.js and NPM

2. **Clone and setup**
```bash
git clone <repository-url>
cd bazar-marketplace
composer install
npm install
cp .env.example .env
```

3. **Configure environment**
   - Edit `.env` file with your database and API credentials
   - Set up your web server to point to the `public` directory

4. **Database setup**
```bash
mysql -u root -p < database_schema.sql
```

5. **Build assets**
```bash
npm run build
```

## Development

### Project Structure
```
bazar-marketplace/
├── backend/                 # PHP backend code
│   ├── api/                # API endpoints
│   ├── models/             # Data models
│   ├── controllers/        # Business logic
│   ├── services/           # Service classes
│   ├── middleware/         # Middleware components
│   └── config/             # Configuration files
├── frontend/               # Frontend assets
│   ├── assets/             # Static assets (CSS, images)
│   ├── components/         # Reusable components
│   ├── pages/              # Page templates
│   └── js/                 # JavaScript modules
├── admin/                  # Admin panel
├── tests/                  # Test files
├── uploads/                # User uploaded files
├── docker/                 # Docker configuration
└── docs/                   # Documentation
```

### API Endpoints

The API follows RESTful conventions:

- `GET /api/health` - Health check
- `POST /api/auth/login` - User login
- `POST /api/auth/register` - User registration
- `GET /api/articles` - List articles
- `POST /api/articles` - Create article
- `GET /api/articles/{id}` - Get article details
- `PUT /api/articles/{id}` - Update article
- `DELETE /api/articles/{id}` - Delete article
- `GET /api/categories` - List categories
- `GET /api/search` - Search articles
- `POST /api/favorites` - Add to favorites
- `DELETE /api/favorites/{id}` - Remove favorite

### Database Schema

Key tables:
- **users**: User accounts with OAuth and 2FA support
- **articles**: Marketplace listings with full-text search
- **categories**: Hierarchical category system
- **messages**: Real-time messaging system
- **favorites**: User favorites
- **ratings**: User rating system
- **ai_suggestions**: AI-powered recommendations

## Testing

```bash
# Run PHP tests
composer test

# Run JavaScript tests  
npm test

# Run with coverage
composer test-coverage
npm run test:coverage
```

## Deployment

### Production Setup

1. **Environment Configuration**
   - Set `APP_ENV=production` in `.env`
   - Configure production database credentials
   - Set up SSL certificates
   - Configure email service

2. **Security Considerations**
   - Enable HTTPS
   - Set secure session cookies
   - Configure rate limiting
   - Set up monitoring and logging

3. **Performance Optimization**
   - Enable OPcache
   - Configure Redis caching
   - Optimize database indexes
   - Set up CDN for static assets

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

For support and questions:
- Create an issue on GitHub
- Email: support@bazar.com
- Documentation: [docs/](docs/)

## Acknowledgments

- Bootstrap team for the excellent CSS framework
- PHP community for amazing packages
- Contributors and testers