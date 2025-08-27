// Cypress E2E Support File
import './commands';
import 'cypress-real-events/support';
import '@cypress/code-coverage/support';

// Global error handling
Cypress.on('uncaught:exception', (err, runnable) => {
  // Return false to prevent the test from failing on uncaught exceptions
  // that we don't care about (e.g., third-party library errors)
  if (err.message.includes('Non-Error promise rejection captured')) {
    return false;
  }
  
  if (err.message.includes('Script error')) {
    return false;
  }
  
  return true;
});

// Custom assertions
Cypress.Commands.add('shouldBeVisible', (selector) => {
  cy.get(selector).should('be.visible');
});

Cypress.Commands.add('shouldContainText', (selector, text) => {
  cy.get(selector).should('contain.text', text);
});

Cypress.Commands.add('shouldHaveClass', (selector, className) => {
  cy.get(selector).should('have.class', className);
});

// Accessibility testing
Cypress.Commands.add('checkA11y', (context, options) => {
  cy.injectAxe();
  cy.checkA11y(context, options);
});

// Performance testing
Cypress.Commands.add('measurePerformance', () => {
  cy.window().then((win) => {
    const performance = win.performance;
    const navigationTiming = performance.getEntriesByType('navigation')[0];
    
    if (navigationTiming) {
      const loadTime = navigationTiming.loadEventEnd - navigationTiming.loadEventStart;
      const domContentLoadedTime = navigationTiming.domContentLoadedEventEnd - navigationTiming.domContentLoadedEventStart;
      
      cy.log(`Page load time: ${loadTime}ms`);
      cy.log(`DOM content loaded time: ${domContentLoadedTime}ms`);
      
      // Assert reasonable load times
      expect(loadTime).to.be.lessThan(3000); // Less than 3 seconds
      expect(domContentLoadedTime).to.be.lessThan(2000); // Less than 2 seconds
    }
  });
});

// Mobile testing utilities
Cypress.Commands.add('setMobileViewport', () => {
  cy.viewport(375, 667); // iPhone SE size
});

Cypress.Commands.add('setTabletViewport', () => {
  cy.viewport(768, 1024); // iPad size
});

Cypress.Commands.add('setDesktopViewport', () => {
  cy.viewport(1280, 720); // Desktop size
});

// PWA testing
Cypress.Commands.add('checkServiceWorker', () => {
  cy.window().then((win) => {
    expect(win.navigator.serviceWorker).to.exist;
    
    return win.navigator.serviceWorker.getRegistration().then((registration) => {
      expect(registration).to.exist;
      expect(registration.active).to.exist;
    });
  });
});

Cypress.Commands.add('checkManifest', () => {
  cy.request('/manifest.json').then((response) => {
    expect(response.status).to.equal(200);
    expect(response.body).to.have.property('name');
    expect(response.body).to.have.property('short_name');
    expect(response.body).to.have.property('start_url');
    expect(response.body).to.have.property('display');
    expect(response.body).to.have.property('icons');
  });
});

// Test data utilities
Cypress.Commands.add('seedTestData', () => {
  cy.task('seedDatabase');
});

Cypress.Commands.add('clearTestData', () => {
  cy.task('clearDatabase');
});

// Before each test
beforeEach(() => {
  // Clear local storage
  cy.clearLocalStorage();
  
  // Clear cookies
  cy.clearCookies();
  
  // Clear application state
  cy.window().then((win) => {
    if (win.localStorage) {
      win.localStorage.clear();
    }
    if (win.sessionStorage) {
      win.sessionStorage.clear();
    }
  });
  
  // Set default viewport
  cy.setDesktopViewport();
  
  // Intercept common API calls
  cy.intercept('GET', '/api/categories', { fixture: 'categories.json' }).as('getCategories');
  cy.intercept('GET', '/api/articles*', { fixture: 'articles.json' }).as('getArticles');
});

// After each test
afterEach(() => {
  // Take screenshot on failure
  cy.screenshot({ capture: 'runner', onlyOnFailure: true });
  
  // Log performance metrics
  cy.measurePerformance();
});