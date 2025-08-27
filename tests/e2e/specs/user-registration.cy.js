describe('User Registration Flow', () => {
  beforeEach(() => {
    cy.clearTestData();
    cy.visit('/register');
  });
  
  context('Happy Path', () => {
    it('should successfully register a new user', () => {
      const userData = {
        firstName: 'John',
        lastName: 'Doe',
        username: 'johndoe',
        email: 'john.doe@example.com',
        password: 'SecurePassword123!',
        confirmPassword: 'SecurePassword123!',
        phone: '+1234567890'
      };
      
      cy.fillRegistrationForm(userData);
      cy.get('[data-cy="register-button"]').click();
      
      // Should redirect to dashboard after successful registration
      cy.url().should('include', '/dashboard');
      cy.get('[data-cy="welcome-message"]').should('contain', 'Welcome, John!');
      
      // Should have auth token
      cy.window().then((win) => {
        expect(win.localStorage.getItem('auth_token')).to.exist;
      });
    });
    
    it('should send email verification after registration', () => {
      const userData = {
        firstName: 'Jane',
        lastName: 'Smith',
        username: 'janesmith',
        email: 'jane.smith@example.com',
        password: 'SecurePassword123!',
        confirmPassword: 'SecurePassword123!'
      };
      
      cy.intercept('POST', '/api/auth/register', { fixture: 'auth/register-success.json' }).as('register');
      cy.intercept('POST', '/api/auth/send-verification', { fixture: 'auth/verification-sent.json' }).as('sendVerification');
      
      cy.fillRegistrationForm(userData);
      cy.get('[data-cy="register-button"]').click();
      
      cy.waitForApi('@register');
      cy.waitForApi('@sendVerification');
      
      cy.get('[data-cy="verification-notice"]').should('be.visible')
        .and('contain', 'Please check your email to verify your account');
    });
  });
  
  context('Form Validation', () => {
    it('should show validation errors for missing required fields', () => {
      cy.get('[data-cy="register-button"]').click();
      
      cy.expectValidationError('first-name', 'First name is required');
      cy.expectValidationError('last-name', 'Last name is required');
      cy.expectValidationError('username', 'Username is required');
      cy.expectValidationError('email', 'Email is required');
      cy.expectValidationError('password', 'Password is required');
      cy.expectValidationError('terms', 'You must accept the terms and conditions');
    });
    
    it('should validate email format', () => {
      cy.get('[data-cy="email-input"]').type('invalid-email');
      cy.get('[data-cy="email-input"]').blur();
      
      cy.expectValidationError('email', 'Please enter a valid email address');
    });
    
    it('should validate password strength', () => {
      const weakPasswords = [
        '123',
        'password',
        'PASSWORD',
        '12345678'
      ];
      
      weakPasswords.forEach(password => {
        cy.get('[data-cy="password-input"]').clear().type(password);
        cy.get('[data-cy="password-input"]').blur();
        
        cy.get('[data-cy="password-strength"]').should('contain', 'Weak');
        cy.expectValidationError('password', 'Password must be stronger');
      });
    });
    
    it('should validate password confirmation', () => {
      cy.get('[data-cy="password-input"]').type('SecurePassword123!');
      cy.get('[data-cy="confirm-password-input"]').type('DifferentPassword123!');
      cy.get('[data-cy="confirm-password-input"]').blur();
      
      cy.expectValidationError('confirm-password', 'Passwords do not match');
    });
    
    it('should validate username availability', () => {
      cy.intercept('GET', '/api/auth/check-username?username=existinguser', {
        body: { available: false }
      }).as('checkUsername');
      
      cy.get('[data-cy="username-input"]').type('existinguser');
      cy.get('[data-cy="username-input"]').blur();
      
      cy.waitForApi('@checkUsername');
      cy.expectValidationError('username', 'Username is already taken');
    });
    
    it('should validate email availability', () => {
      cy.intercept('GET', '/api/auth/check-email?email=existing@example.com', {
        body: { available: false }
      }).as('checkEmail');
      
      cy.get('[data-cy="email-input"]').type('existing@example.com');
      cy.get('[data-cy="email-input"]').blur();
      
      cy.waitForApi('@checkEmail');
      cy.expectValidationError('email', 'Email is already registered');
    });
    
    it('should validate phone number format', () => {
      const invalidPhones = [
        '123',
        'abc',
        '123-456',
        '12345678901234567890'
      ];
      
      invalidPhones.forEach(phone => {
        cy.get('[data-cy="phone-input"]').clear().type(phone);
        cy.get('[data-cy="phone-input"]').blur();
        
        cy.expectValidationError('phone', 'Please enter a valid phone number');
      });
    });
  });
  
  context('Real-time Validation', () => {
    it('should show password strength indicator in real-time', () => {
      const passwordTests = [
        { password: '123', strength: 'Very Weak', color: 'red' },
        { password: 'password', strength: 'Weak', color: 'orange' },
        { password: 'Password123', strength: 'Good', color: 'yellow' },
        { password: 'SecurePassword123!', strength: 'Strong', color: 'green' }
      ];
      
      passwordTests.forEach(test => {
        cy.get('[data-cy="password-input"]').clear().type(test.password);
        
        cy.get('[data-cy="password-strength"]')
          .should('contain', test.strength)
          .and('have.class', `strength-${test.color}`);
      });
    });
    
    it('should show username availability check in real-time', () => {
      cy.intercept('GET', '/api/auth/check-username?username=availableuser', {
        body: { available: true }
      }).as('checkAvailableUsername');
      
      cy.get('[data-cy="username-input"]').type('availableuser');
      
      cy.waitForApi('@checkAvailableUsername');
      cy.get('[data-cy="username-availability"]')
        .should('contain', 'Username is available')
        .and('have.class', 'available');
    });
    
    it('should show character count for fields with limits', () => {
      const longText = 'a'.repeat(100);
      
      cy.get('[data-cy="username-input"]').type(longText);
      cy.get('[data-cy="username-counter"]').should('contain', '100/50');
      cy.get('[data-cy="username-counter"]').should('have.class', 'over-limit');
    });
  });
  
  context('Error Handling', () => {
    it('should handle server errors gracefully', () => {
      cy.intercept('POST', '/api/auth/register', {
        statusCode: 500,
        body: { message: 'Internal server error' }
      }).as('registerError');
      
      const userData = {
        firstName: 'Test',
        lastName: 'User',
        username: 'testuser',
        email: 'test@example.com',
        password: 'SecurePassword123!',
        confirmPassword: 'SecurePassword123!'
      };
      
      cy.fillRegistrationForm(userData);
      cy.get('[data-cy="register-button"]').click();
      
      cy.waitForApi('@registerError');
      
      cy.get('[data-cy="error-message"]')
        .should('be.visible')
        .and('contain', 'Something went wrong. Please try again.');
    });
    
    it('should handle network errors', () => {
      cy.intercept('POST', '/api/auth/register', { forceNetworkError: true }).as('networkError');
      
      const userData = {
        firstName: 'Test',
        lastName: 'User',
        username: 'testuser',
        email: 'test@example.com',
        password: 'SecurePassword123!',
        confirmPassword: 'SecurePassword123!'
      };
      
      cy.fillRegistrationForm(userData);
      cy.get('[data-cy="register-button"]').click();
      
      cy.get('[data-cy="error-message"]')
        .should('be.visible')
        .and('contain', 'Network error. Please check your connection.');
    });
    
    it('should retry failed requests', () => {
      let attempts = 0;
      cy.intercept('POST', '/api/auth/register', (req) => {
        attempts++;
        if (attempts < 3) {
          req.reply({ statusCode: 500, body: { message: 'Server error' } });
        } else {
          req.reply({ fixture: 'auth/register-success.json' });
        }
      }).as('registerRetry');
      
      const userData = {
        firstName: 'Test',
        lastName: 'User',
        username: 'testuser',
        email: 'test@example.com',
        password: 'SecurePassword123!',
        confirmPassword: 'SecurePassword123!'
      };
      
      cy.fillRegistrationForm(userData);
      cy.get('[data-cy="register-button"]').click();
      
      // Should show retry button after initial failure
      cy.get('[data-cy="retry-button"]').should('be.visible');
      cy.get('[data-cy="retry-button"]').click();
      
      // Should eventually succeed
      cy.url().should('include', '/dashboard');
    });
  });
  
  context('Accessibility', () => {
    it('should be accessible', () => {
      cy.shouldBeAccessible();
    });
    
    it('should have proper focus management', () => {
      // Tab through form fields
      cy.get('[data-cy="first-name-input"]').focus();
      cy.realPress('Tab');
      cy.get('[data-cy="last-name-input"]').should('be.focused');
      
      cy.realPress('Tab');
      cy.get('[data-cy="username-input"]').should('be.focused');
      
      cy.realPress('Tab');
      cy.get('[data-cy="email-input"]').should('be.focused');
      
      cy.realPress('Tab');
      cy.get('[data-cy="password-input"]').should('be.focused');
      
      cy.realPress('Tab');
      cy.get('[data-cy="confirm-password-input"]').should('be.focused');
    });
    
    it('should have proper ARIA labels and descriptions', () => {
      cy.get('[data-cy="password-input"]')
        .should('have.attr', 'aria-describedby')
        .then((describedBy) => {
          cy.get(`#${describedBy}`).should('contain', 'Password must be at least 8 characters');
        });
      
      cy.get('[data-cy="terms-checkbox"]')
        .should('have.attr', 'aria-describedby')
        .then((describedBy) => {
          cy.get(`#${describedBy}`).should('contain', 'You must accept our terms and conditions');
        });
    });
    
    it('should announce errors to screen readers', () => {
      cy.get('[data-cy="register-button"]').click();
      
      cy.get('[role="alert"]').should('exist');
      cy.get('[aria-live="polite"]').should('contain', 'Please fix the errors below');
    });
  });
  
  context('Security', () => {
    it('should not expose sensitive data in network requests', () => {
      const userData = {
        firstName: 'Test',
        lastName: 'User',
        username: 'testuser',
        email: 'test@example.com',
        password: 'SecurePassword123!',
        confirmPassword: 'SecurePassword123!'
      };
      
      cy.intercept('POST', '/api/auth/register').as('register');
      
      cy.fillRegistrationForm(userData);
      cy.get('[data-cy="register-button"]').click();
      
      cy.wait('@register').then((interception) => {
        // Password should be present in request (will be hashed server-side)
        expect(interception.request.body).to.have.property('password');
        // But confirm password should not be sent
        expect(interception.request.body).to.not.have.property('confirmPassword');
      });
    });
    
    it('should prevent CSRF attacks', () => {
      cy.get('meta[name="csrf-token"]').should('exist');
      
      cy.window().then((win) => {
        const csrfToken = win.document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        expect(csrfToken).to.exist;
        expect(csrfToken).to.have.length.greaterThan(10);
      });
    });
    
    it('should implement rate limiting', () => {
      const userData = {
        firstName: 'Test',
        lastName: 'User',
        username: 'testuser',
        email: 'test@example.com',
        password: 'SecurePassword123!',
        confirmPassword: 'SecurePassword123!'
      };
      
      // Simulate multiple rapid registration attempts
      for (let i = 0; i < 5; i++) {
        cy.fillRegistrationForm({
          ...userData,
          email: `test${i}@example.com`,
          username: `testuser${i}`
        });
        cy.get('[data-cy="register-button"]').click();
        
        if (i > 2) {
          cy.get('[data-cy="rate-limit-message"]').should('be.visible');
          break;
        }
        
        cy.visit('/register');
      }
    });
  });
  
  context('Mobile Responsiveness', () => {
    beforeEach(() => {
      cy.setMobileViewport();
    });
    
    it('should be usable on mobile devices', () => {
      const userData = {
        firstName: 'Mobile',
        lastName: 'User',
        username: 'mobileuser',
        email: 'mobile@example.com',
        password: 'SecurePassword123!',
        confirmPassword: 'SecurePassword123!'
      };
      
      cy.fillRegistrationForm(userData);
      cy.get('[data-cy="register-button"]').should('be.visible').click();
      
      cy.url().should('include', '/dashboard');
    });
    
    it('should have touch-friendly form elements', () => {
      cy.get('[data-cy="first-name-input"]').should('have.css', 'min-height', '44px');
      cy.get('[data-cy="register-button"]').should('have.css', 'min-height', '44px');
    });
    
    it('should show mobile-optimized keyboard', () => {
      cy.get('[data-cy="email-input"]').should('have.attr', 'inputmode', 'email');
      cy.get('[data-cy="phone-input"]').should('have.attr', 'inputmode', 'tel');
    });
  });
  
  context('Performance', () => {
    it('should load registration page quickly', () => {
      cy.visit('/register');
      cy.shouldLoadFast(2000); // Should load within 2 seconds
    });
    
    it('should validate forms efficiently', () => {
      const start = Date.now();
      
      cy.get('[data-cy="email-input"]').type('test@example.com');
      cy.get('[data-cy="email-input"]').blur();
      
      cy.get('[data-cy="email-validation"]').should('exist').then(() => {
        const end = Date.now();
        expect(end - start).to.be.lessThan(500); // Validation should be fast
      });
    });
    
    it('should handle large amounts of input efficiently', () => {
      const longText = 'a'.repeat(1000);
      
      const start = Date.now();
      cy.get('[data-cy="first-name-input"]').type(longText);
      const end = Date.now();
      
      expect(end - start).to.be.lessThan(2000); // Should handle large input quickly
    });
  });
});