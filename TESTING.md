# Bazar Marketplace - Comprehensive Testing Framework

## Overview

This document provides a complete guide to the testing framework implemented for the Bazar Marketplace. Our testing strategy ensures production-ready quality through comprehensive coverage of functionality, performance, security, and user experience.

## Table of Contents

1. [Testing Strategy](#testing-strategy)
2. [Test Types](#test-types)
3. [Test Structure](#test-structure)
4. [Running Tests](#running-tests)
5. [CI/CD Integration](#cicd-integration)
6. [Performance Requirements](#performance-requirements)
7. [Security Testing](#security-testing)
8. [Coverage Requirements](#coverage-requirements)
9. [Best Practices](#best-practices)
10. [Troubleshooting](#troubleshooting)

## Testing Strategy

Our testing approach follows the **Test Pyramid** methodology:

```
     /\      E2E Tests (Cypress)           - User workflows, integration
    /  \     --------------------------------
   /    \    Integration Tests (PHPUnit)    - API endpoints, database
  /      \   --------------------------------
 /        \  Unit Tests (PHPUnit + Jest)    - Individual components
/__________\ --------------------------------
             Static Analysis & Security     - Code quality, vulnerabilities
```

### Test Categories

- **Unit Tests (70%)**: Individual functions, classes, and components
- **Integration Tests (20%)**: API endpoints, database interactions
- **End-to-End Tests (10%)**: Complete user workflows
- **Performance Tests**: Lighthouse automation, load testing
- **Security Tests**: XSS, SQL injection, CSRF protection

## Test Types

### 1. Backend Tests (PHPUnit)

#### Unit Tests
- **Location**: `/tests/Unit/`
- **Framework**: PHPUnit 10+
- **Coverage**: Models, Services, Controllers

```bash
# Run backend unit tests
vendor/bin/phpunit tests/Unit
```

**Key Test Files:**
- `tests/Unit/Models/UserTest.php` - User model functionality
- `tests/Unit/Models/ArticleTest.php` - Article CRUD operations
- `tests/Unit/Services/AIServiceTest.php` - AI image recognition
- `tests/Unit/Controllers/AuthControllerTest.php` - Authentication flows

#### Integration Tests
- **Location**: `/tests/Integration/`
- **Coverage**: API endpoints, database interactions

```bash
# Run integration tests
vendor/bin/phpunit tests/Integration
```

#### Feature Tests
- **Location**: `/tests/Feature/`
- **Coverage**: Complete workflows, business logic

```bash
# Run feature tests
vendor/bin/phpunit tests/Feature
```

### 2. Frontend Tests (Jest)

#### Component Tests
- **Location**: `/tests/frontend/components/`
- **Framework**: Jest + Testing Library
- **Coverage**: React components, UI interactions

```bash
# Run frontend tests
cd bazar-marketplace
npm test
```

#### PWA Tests
- **Location**: `/tests/frontend/pwa/`
- **Coverage**: Service worker, offline functionality, push notifications

### 3. End-to-End Tests (Cypress)

#### User Workflow Tests
- **Location**: `/tests/e2e/specs/`
- **Framework**: Cypress 13+
- **Coverage**: Complete user journeys

```bash
# Run E2E tests
npx cypress run

# Interactive mode
npx cypress open
```

**Key E2E Test Files:**
- `tests/e2e/specs/user-registration.cy.js` - User registration flow
- `tests/e2e/specs/article-creation.cy.js` - Article creation with AI
- `tests/e2e/specs/messaging-flow.cy.js` - Real-time messaging
- `tests/e2e/specs/mobile-responsive.cy.js` - Mobile responsiveness

### 4. Performance Tests (Lighthouse)

#### Automated Performance Testing
- **Location**: `/tests/performance/`
- **Framework**: Lighthouse CI
- **Thresholds**: 
  - Performance: >90
  - Accessibility: >95
  - Best Practices: >90
  - SEO: >90
  - PWA: >80

```bash
# Run performance tests
node tests/performance/lighthouse-runner.js performance
```

### 5. Security Tests

#### Vulnerability Testing
- **Location**: `/tests/Security/`
- **Coverage**: SQL injection, XSS, CSRF, authentication

```bash
# Run security tests
vendor/bin/phpunit tests/Security
```

**Security Test Coverage:**
- SQL Injection prevention
- Cross-Site Scripting (XSS) protection
- CSRF token validation
- Authentication bypass attempts
- Input sanitization
- Output encoding

## Test Structure

### Directory Organization

```
/Applications/XAMPP/xamppfiles/htdocs/bazar/tests/
├── Unit/                          # Backend unit tests
│   ├── Controllers/              # Controller tests
│   ├── Models/                   # Model tests
│   └── Services/                 # Service tests
├── Integration/                   # API integration tests
│   ├── API/                      # API endpoint tests
│   └── Database/                 # Database integration
├── Feature/                       # Feature workflow tests
│   ├── Articles/                 # Article management
│   ├── Auth/                     # Authentication flows
│   └── Messages/                 # Messaging system
├── Security/                      # Security vulnerability tests
│   ├── SQLInjectionTest.php      # SQL injection prevention
│   ├── XSSTest.php               # XSS protection
│   └── CSRFTest.php              # CSRF validation
├── frontend/                      # Frontend tests
│   ├── components/               # Component tests
│   ├── pwa/                      # PWA functionality
│   └── utils/                    # Utility functions
├── e2e/                          # End-to-end tests
│   ├── specs/                    # Test specifications
│   ├── support/                  # Support files
│   └── fixtures/                 # Test data
└── performance/                   # Performance tests
    ├── lighthouse-config.js      # Lighthouse configuration
    └── lighthouse-runner.js      # Test runner
```

### Configuration Files

- `phpunit.xml` - PHPUnit configuration
- `jest.config.js` - Jest configuration
- `cypress.config.js` - Cypress configuration
- `.github/workflows/ci.yml` - CI/CD pipeline

## Running Tests

### Prerequisites

1. **PHP 8.1+** with extensions:
   - mbstring, xml, ctype, iconv, intl
   - pdo_mysql, gd, redis

2. **Node.js 18+** and npm

3. **Database Setup**:
   ```bash
   mysql -u root -p
   CREATE DATABASE bazar_test;
   mysql -u root -p bazar_test < backend/config/database.sql
   ```

4. **Environment Configuration**:
   ```bash
   cp .env.testing.example .env.testing
   ```

### Local Testing

#### Complete Test Suite
```bash
# Install dependencies
composer install
cd bazar-marketplace && npm install

# Run all backend tests
vendor/bin/phpunit

# Run all frontend tests
cd bazar-marketplace && npm test

# Run E2E tests (requires running application)
php -S localhost:8000 -t . &
npx cypress run
```

#### Individual Test Categories
```bash
# Unit tests only
vendor/bin/phpunit tests/Unit

# Integration tests
vendor/bin/phpunit tests/Integration

# Security tests
vendor/bin/phpunit tests/Security

# Performance tests
node tests/performance/lighthouse-runner.js performance

# E2E tests for specific feature
npx cypress run --spec "tests/e2e/specs/user-registration.cy.js"
```

### Test Data Management

#### Database Seeding
```bash
# Seed test data
php tests/database/seeders/TestDataSeeder.php
```

#### Cleanup
```bash
# Clean test database
php tests/database/seeders/CleanupSeeder.php
```

## CI/CD Integration

### GitHub Actions Workflow

Our CI/CD pipeline runs on every push and pull request:

1. **Code Quality**: PHP_CodeSniffer, ESLint, PHPStan
2. **Unit Tests**: Backend and frontend unit tests
3. **Integration Tests**: API and database tests
4. **Security Audit**: Dependency scanning, vulnerability checks
5. **E2E Tests**: Complete user workflow validation
6. **Performance Tests**: Lighthouse automation
7. **Build Verification**: Docker container builds

### Test Stages

```yaml
# Test execution order in CI
jobs:
  code-quality:        # Static analysis and linting
  backend-tests:       # PHPUnit tests
  frontend-tests:      # Jest tests
  e2e-tests:          # Cypress tests
  performance-tests:   # Lighthouse tests
  security-audit:     # Vulnerability scans
  deploy-staging:     # Staging deployment
  deploy-production:  # Production deployment
```

### Environment-Specific Testing

- **Development**: All tests, including experimental
- **Staging**: Production-like tests, performance validation
- **Production**: Health checks, smoke tests

## Performance Requirements

### Target Metrics

- **Initial Load Time**: < 2 seconds
- **Time to Interactive**: < 3 seconds
- **Lighthouse Performance Score**: > 90
- **Largest Contentful Paint**: < 2.5 seconds
- **Cumulative Layout Shift**: < 0.1
- **First Input Delay**: < 100ms

### Performance Test Coverage

1. **Core Pages**:
   - Homepage (`/`)
   - Article listing (`/articles`)
   - Article creation (`/articles/create`)
   - User authentication (`/login`, `/register`)

2. **Mobile Performance**:
   - 3G network simulation
   - Mobile device emulation
   - Touch interaction testing

3. **PWA Features**:
   - Service worker functionality
   - Offline capability
   - Push notifications
   - App installation

### Performance Monitoring

```bash
# Run comprehensive performance audit
node tests/performance/lighthouse-runner.js full

# Monitor specific pages
node tests/performance/lighthouse-runner.js performance /articles /login

# Mobile performance testing
node tests/performance/lighthouse-runner.js mobile
```

## Security Testing

### Security Test Coverage

#### 1. Input Validation
- SQL injection prevention
- XSS protection (stored and reflected)
- Command injection
- Path traversal
- File upload validation

#### 2. Authentication & Authorization
- Password strength requirements
- Session management
- JWT token security
- Role-based access control
- Two-factor authentication

#### 3. Data Protection
- Sensitive data exposure
- Cryptographic practices
- HTTPS enforcement
- Secure cookie settings

#### 4. Business Logic
- Rate limiting
- CSRF protection
- Security headers
- API endpoint security

### Running Security Tests

```bash
# Complete security audit
vendor/bin/phpunit tests/Security

# SQL injection tests
vendor/bin/phpunit tests/Security/SQLInjectionTest.php

# XSS protection tests
vendor/bin/phpunit tests/Security/XSSTest.php

# Authentication security
vendor/bin/phpunit tests/Security/AuthSecurityTest.php
```

### Security Scanning Integration

```bash
# Dependency vulnerability scanning
composer audit

# Node.js security audit
npm audit

# OWASP dependency check
./scripts/security-scan.sh
```

## Coverage Requirements

### Code Coverage Targets

- **Backend (PHP)**: ≥ 85% line coverage
- **Frontend (JavaScript)**: ≥ 80% line coverage
- **Critical Business Logic**: ≥ 95% coverage
- **Security-Related Code**: 100% coverage

### Coverage Reporting

```bash
# Generate coverage reports
vendor/bin/phpunit --coverage-html coverage/backend
cd bazar-marketplace && npm run test:coverage

# View coverage reports
open coverage/backend/index.html
open bazar-marketplace/coverage/lcov-report/index.html
```

### Coverage Analysis

1. **Critical Paths**: User registration, authentication, payment processing
2. **Security Functions**: Input validation, authorization checks
3. **Business Logic**: Article creation, messaging, search functionality
4. **Error Handling**: Exception handling, graceful degradation

## Best Practices

### Test Writing Guidelines

#### 1. Test Structure (AAA Pattern)
```php
public function testUserCanCreateArticle()
{
    // Arrange
    $user = $this->createTestUser();
    $articleData = ['title' => 'Test Article', ...];
    
    // Act
    $result = $this->articleService->create($articleData, $user->id);
    
    // Assert
    $this->assertTrue($result['success']);
    $this->assertDatabaseHas('articles', ['title' => 'Test Article']);
}
```

#### 2. Test Naming
- Use descriptive names: `testUserCannotDeleteOtherUsersArticle`
- Follow convention: `test[What]_[Scenario]_[ExpectedResult]`
- Group related tests in test classes

#### 3. Test Data Management
```php
// Use factories for consistent test data
$user = factory(User::class)->create();

// Clean up after each test
protected function tearDown(): void
{
    $this->cleanDatabase();
    parent::tearDown();
}
```

#### 4. Mock External Dependencies
```javascript
// Mock API calls
const mockApi = {
  get: jest.fn(() => Promise.resolve({ data: [] })),
  post: jest.fn(() => Promise.resolve({ success: true }))
};
```

### Performance Testing Best Practices

1. **Baseline Measurements**: Establish performance baselines
2. **Regression Testing**: Compare against previous results
3. **Real-World Conditions**: Test with realistic data volumes
4. **Mobile-First**: Prioritize mobile performance
5. **Progressive Enhancement**: Test with and without JavaScript

### Security Testing Best Practices

1. **Threat Modeling**: Map potential attack vectors
2. **Defense in Depth**: Test multiple security layers
3. **Input Validation**: Test all input boundaries
4. **Authentication**: Test all auth mechanisms
5. **Authorization**: Verify access controls

## Troubleshooting

### Common Issues

#### Database Connection Issues
```bash
# Check MySQL service
sudo systemctl status mysql

# Verify test database exists
mysql -u root -p -e "SHOW DATABASES;"

# Reset test database
mysql -u root -p bazar_test < backend/config/database.sql
```

#### Node.js/npm Issues
```bash
# Clear npm cache
npm cache clean --force

# Remove node_modules and reinstall
rm -rf node_modules package-lock.json
npm install
```

#### Cypress Issues
```bash
# Clear Cypress cache
npx cypress cache clear

# Verify Cypress installation
npx cypress verify

# Run in debug mode
DEBUG=cypress:* npx cypress run
```

#### Performance Test Issues
```bash
# Install Chrome dependencies
sudo apt-get install -y chromium-browser

# Update Lighthouse
npm install -g lighthouse@latest
```

### Test Debugging

#### PHPUnit Debugging
```bash
# Run single test with verbose output
vendor/bin/phpunit tests/Unit/UserTest.php --testdox

# Debug with Xdebug
export XDEBUG_CONFIG="idekey=PHPSTORM"
vendor/bin/phpunit tests/Unit/UserTest.php
```

#### Jest Debugging
```bash
# Run specific test
npm test -- UserService.test.js

# Debug mode
node --inspect-brk node_modules/.bin/jest --runInBand
```

#### Cypress Debugging
```bash
# Interactive mode
npx cypress open

# Record video and screenshots
npx cypress run --record --key <your-key>
```

### Performance Debugging

```bash
# Analyze bundle size
cd bazar-marketplace
npm run analyze

# Profile JavaScript performance
node --prof app.js

# Database query profiling
mysql -u root -p -e "SET profiling = 1; [YOUR QUERY]; SHOW PROFILES;"
```

## Continuous Improvement

### Metrics Tracking

1. **Test Coverage Trends**: Monitor coverage improvements
2. **Test Execution Time**: Optimize slow tests
3. **Flaky Test Detection**: Identify and fix unstable tests
4. **Performance Regression**: Track performance metrics over time

### Regular Maintenance

1. **Dependency Updates**: Keep testing frameworks updated
2. **Test Data Refresh**: Update test scenarios regularly
3. **Performance Baseline Updates**: Adjust targets as needed
4. **Security Test Updates**: Add new vulnerability checks

### Team Practices

1. **Test-Driven Development**: Write tests before implementation
2. **Code Reviews**: Include test review in PR process
3. **Testing Training**: Regular team updates on testing practices
4. **Documentation**: Keep testing docs current

## Resources

### Documentation Links
- [PHPUnit Documentation](https://phpunit.de/)
- [Jest Documentation](https://jestjs.io/)
- [Cypress Documentation](https://docs.cypress.io/)
- [Lighthouse Documentation](https://developers.google.com/web/tools/lighthouse)

### Testing Tools
- **Backend**: PHPUnit, Mockery, Faker
- **Frontend**: Jest, Testing Library, Cypress
- **Performance**: Lighthouse, WebPageTest
- **Security**: OWASP ZAP, Snyk, SonarQube

### Support

For testing-related questions or issues:
1. Check this documentation
2. Review existing tests for examples
3. Consult team testing guidelines
4. Create issue in project repository

---

**Last Updated**: December 2024
**Version**: 1.0.0
**Maintained By**: Bazar Development Team