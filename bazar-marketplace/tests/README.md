# Tests Directory

This directory contains all test files for the Bazar Marketplace project.

## Structure

```
tests/
├── Unit/           # Unit tests for individual components
├── Integration/    # Integration tests for API endpoints
├── Feature/        # Feature tests for complete workflows
└── bootstrap.php   # Test bootstrap file
```

## Running Tests

### PHP Tests (PHPUnit)
```bash
# Run all tests
composer test

# Run specific test suite
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration
vendor/bin/phpunit tests/Feature

# Run with coverage
composer test-coverage
```

### JavaScript Tests (Jest)
```bash
# Run all tests
npm test

# Run in watch mode
npm run test:watch

# Run with coverage
npm run test:coverage
```

## Writing Tests

### PHP Test Example
```php
<?php

namespace Bazar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Bazar\Models\User;

class UserTest extends TestCase
{
    public function testUserCreation()
    {
        $user = new User([
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);
        
        $this->assertEquals('testuser', $user->getUsername());
        $this->assertEquals('test@example.com', $user->getEmail());
    }
}
```

### JavaScript Test Example
```javascript
import { BazarApp } from '../frontend/js/app.js';

describe('BazarApp', () => {
    let app;
    
    beforeEach(() => {
        app = new BazarApp();
    });
    
    test('should initialize correctly', () => {
        expect(app.apiBaseUrl).toBe('/api');
        expect(app.favorites).toBeInstanceOf(Set);
    });
    
    test('should validate email correctly', () => {
        expect(app.isValidEmail('test@example.com')).toBe(true);
        expect(app.isValidEmail('invalid-email')).toBe(false);
    });
});
```

## Test Guidelines

1. **Naming Convention**: Test files should end with `Test.php` for PHP or `.test.js` for JavaScript
2. **Test Data**: Use factories or fixtures for test data
3. **Isolation**: Each test should be independent and not rely on other tests
4. **Coverage**: Aim for high code coverage, especially for critical business logic
5. **Performance**: Keep tests fast and avoid unnecessary database operations
6. **Documentation**: Write clear test descriptions that explain what is being tested

## Continuous Integration

Tests are automatically run on:
- Pull requests
- Pushes to main branch
- Scheduled nightly runs

Make sure all tests pass before merging code.