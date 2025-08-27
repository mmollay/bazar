// Custom Cypress Commands for Bazar Marketplace

// Authentication Commands
Cypress.Commands.add('login', (email, password) => {
  cy.session([email, password], () => {
    cy.visit('/login');
    cy.get('[data-cy="email-input"]').type(email);
    cy.get('[data-cy="password-input"]').type(password);
    cy.get('[data-cy="login-button"]').click();
    
    // Wait for successful login
    cy.url().should('not.include', '/login');
    cy.get('[data-cy="user-menu"]').should('be.visible');
    
    // Verify token is stored
    cy.window().then((win) => {
      expect(win.localStorage.getItem('auth_token')).to.exist;
    });
  });
});

Cypress.Commands.add('loginAsTestUser', () => {
  const { email, password } = Cypress.env('testUser');
  cy.login(email, password);
});

Cypress.Commands.add('loginAsAdmin', () => {
  const { email, password } = Cypress.env('adminUser');
  cy.login(email, password);
});

Cypress.Commands.add('logout', () => {
  cy.get('[data-cy="user-menu"]').click();
  cy.get('[data-cy="logout-button"]').click();
  cy.url().should('include', '/');
  cy.get('[data-cy="login-link"]').should('be.visible');
});

// Navigation Commands
Cypress.Commands.add('navigateToHome', () => {
  cy.get('[data-cy="logo"]').click();
  cy.url().should('eq', Cypress.config().baseUrl + '/');
});

Cypress.Commands.add('navigateToArticles', () => {
  cy.get('[data-cy="articles-link"]').click();
  cy.url().should('include', '/articles');
  cy.get('[data-cy="articles-grid"]').should('be.visible');
});

Cypress.Commands.add('navigateToMyArticles', () => {
  cy.get('[data-cy="user-menu"]').click();
  cy.get('[data-cy="my-articles-link"]').click();
  cy.url().should('include', '/my-articles');
});

Cypress.Commands.add('navigateToCreateArticle', () => {
  cy.get('[data-cy="create-article-button"]').click();
  cy.url().should('include', '/articles/create');
  cy.get('[data-cy="article-form"]').should('be.visible');
});

Cypress.Commands.add('navigateToMessages', () => {
  cy.get('[data-cy="messages-link"]').click();
  cy.url().should('include', '/messages');
  cy.get('[data-cy="message-list"]').should('be.visible');
});

// Article Management Commands
Cypress.Commands.add('createArticle', (articleData) => {
  cy.navigateToCreateArticle();
  
  // Fill form
  cy.get('[data-cy="title-input"]').type(articleData.title);
  cy.get('[data-cy="description-textarea"]').type(articleData.description);
  cy.get('[data-cy="price-input"]').type(articleData.price.toString());
  
  if (articleData.category) {
    cy.get('[data-cy="category-select"]').select(articleData.category);
  }
  
  if (articleData.condition) {
    cy.get('[data-cy="condition-select"]').select(articleData.condition);
  }
  
  if (articleData.images && articleData.images.length > 0) {
    cy.get('[data-cy="image-upload"]').selectFile(articleData.images, { force: true });
  }
  
  if (articleData.location) {
    cy.get('[data-cy="location-input"]').type(articleData.location);
  }
  
  // Submit form
  cy.get('[data-cy="create-article-submit"]').click();
  
  // Verify creation
  cy.get('[data-cy="success-message"]').should('contain', 'Article created successfully');
  cy.url().should('match', /\/articles\/\d+/);
});

Cypress.Commands.add('editArticle', (articleId, updates) => {
  cy.visit(`/articles/${articleId}/edit`);
  
  Object.keys(updates).forEach(field => {
    if (field === 'title') {
      cy.get('[data-cy="title-input"]').clear().type(updates[field]);
    } else if (field === 'description') {
      cy.get('[data-cy="description-textarea"]').clear().type(updates[field]);
    } else if (field === 'price') {
      cy.get('[data-cy="price-input"]').clear().type(updates[field].toString());
    }
  });
  
  cy.get('[data-cy="update-article-submit"]').click();
  cy.get('[data-cy="success-message"]').should('contain', 'Article updated successfully');
});

Cypress.Commands.add('deleteArticle', (articleId) => {
  cy.visit(`/articles/${articleId}`);
  cy.get('[data-cy="article-actions"]').click();
  cy.get('[data-cy="delete-article-button"]').click();
  
  // Confirm deletion
  cy.get('[data-cy="confirm-delete-button"]').click();
  cy.get('[data-cy="success-message"]').should('contain', 'Article deleted successfully');
});

// Search Commands
Cypress.Commands.add('searchArticles', (query, filters = {}) => {
  cy.get('[data-cy="search-input"]').type(query);
  
  if (filters.category) {
    cy.get('[data-cy="category-filter"]').select(filters.category);
  }
  
  if (filters.minPrice) {
    cy.get('[data-cy="min-price-filter"]').type(filters.minPrice.toString());
  }
  
  if (filters.maxPrice) {
    cy.get('[data-cy="max-price-filter"]').type(filters.maxPrice.toString());
  }
  
  if (filters.condition) {
    cy.get('[data-cy="condition-filter"]').select(filters.condition);
  }
  
  cy.get('[data-cy="search-button"]').click();
  
  // Wait for results
  cy.get('[data-cy="search-results"]').should('be.visible');
});

Cypress.Commands.add('saveSearch', (searchName) => {
  cy.get('[data-cy="save-search-button"]').click();
  cy.get('[data-cy="search-name-input"]').type(searchName);
  cy.get('[data-cy="confirm-save-search"]').click();
  cy.get('[data-cy="success-message"]').should('contain', 'Search saved successfully');
});

// Messaging Commands
Cypress.Commands.add('sendMessage', (recipientId, message) => {
  cy.visit(`/messages/new?to=${recipientId}`);
  cy.get('[data-cy="message-textarea"]').type(message);
  cy.get('[data-cy="send-message-button"]').click();
  
  cy.get('[data-cy="success-message"]').should('contain', 'Message sent successfully');
});

Cypress.Commands.add('replyToMessage', (conversationId, reply) => {
  cy.visit(`/messages/${conversationId}`);
  cy.get('[data-cy="reply-textarea"]').type(reply);
  cy.get('[data-cy="send-reply-button"]').click();
  
  cy.get('[data-cy="message-list"]').should('contain', reply);
});

// Upload Commands
Cypress.Commands.add('uploadImages', (files) => {
  cy.get('[data-cy="image-upload"]').selectFile(files, { force: true });
  
  // Wait for upload to complete
  files.forEach((file, index) => {
    cy.get(`[data-cy="uploaded-image-${index}"]`).should('be.visible');
  });
});

// AI Auto-fill Commands
Cypress.Commands.add('useAIAutoFill', (imageFiles) => {
  cy.navigateToCreateArticle();
  
  // Upload images
  cy.uploadImages(imageFiles);
  
  // Click AI auto-fill button
  cy.get('[data-cy="ai-autofill-button"]').click();
  
  // Wait for AI analysis
  cy.get('[data-cy="ai-loading"]').should('be.visible');
  cy.get('[data-cy="ai-loading"]').should('not.exist', { timeout: 10000 });
  
  // Verify suggestions are populated
  cy.get('[data-cy="title-input"]').should('not.be.empty');
  cy.get('[data-cy="description-textarea"]').should('not.be.empty');
  
  // Accept suggestions
  cy.get('[data-cy="accept-ai-suggestions"]').click();
});

// Favorites Commands
Cypress.Commands.add('addToFavorites', (articleId) => {
  cy.visit(`/articles/${articleId}`);
  cy.get('[data-cy="favorite-button"]').click();
  cy.get('[data-cy="favorite-button"]').should('have.class', 'favorited');
});

Cypress.Commands.add('removeFromFavorites', (articleId) => {
  cy.visit(`/articles/${articleId}`);
  cy.get('[data-cy="favorite-button"]').click();
  cy.get('[data-cy="favorite-button"]').should('not.have.class', 'favorited');
});

Cypress.Commands.add('viewFavorites', () => {
  cy.get('[data-cy="user-menu"]').click();
  cy.get('[data-cy="favorites-link"]').click();
  cy.url().should('include', '/favorites');
  cy.get('[data-cy="favorites-grid"]').should('be.visible');
});

// Form Validation Commands
Cypress.Commands.add('expectValidationError', (field, message) => {
  cy.get(`[data-cy="${field}-error"]`).should('be.visible').and('contain', message);
});

Cypress.Commands.add('fillRegistrationForm', (userData) => {
  cy.get('[data-cy="first-name-input"]').type(userData.firstName);
  cy.get('[data-cy="last-name-input"]').type(userData.lastName);
  cy.get('[data-cy="username-input"]').type(userData.username);
  cy.get('[data-cy="email-input"]').type(userData.email);
  cy.get('[data-cy="password-input"]').type(userData.password);
  cy.get('[data-cy="confirm-password-input"]').type(userData.confirmPassword);
  
  if (userData.phone) {
    cy.get('[data-cy="phone-input"]').type(userData.phone);
  }
  
  // Accept terms and conditions
  cy.get('[data-cy="terms-checkbox"]').check();
});

// Admin Commands
Cypress.Commands.add('approveArticle', (articleId) => {
  cy.loginAsAdmin();
  cy.visit(`/admin/articles/${articleId}`);
  cy.get('[data-cy="approve-article-button"]').click();
  cy.get('[data-cy="success-message"]').should('contain', 'Article approved');
});

Cypress.Commands.add('banUser', (userId) => {
  cy.loginAsAdmin();
  cy.visit(`/admin/users/${userId}`);
  cy.get('[data-cy="ban-user-button"]').click();
  cy.get('[data-cy="ban-reason-textarea"]').type('Violation of terms');
  cy.get('[data-cy="confirm-ban-button"]').click();
  cy.get('[data-cy="success-message"]').should('contain', 'User banned');
});

// Wait for API calls
Cypress.Commands.add('waitForApi', (alias) => {
  cy.wait(alias).then((interception) => {
    expect(interception.response.statusCode).to.be.oneOf([200, 201]);
  });
});

// Custom assertions
Cypress.Commands.add('shouldBeAccessible', () => {
  cy.checkA11y();
});

Cypress.Commands.add('shouldHaveValidLinks', () => {
  cy.get('a[href]').each(($link) => {
    const href = $link.prop('href');
    if (href && !href.startsWith('mailto:') && !href.startsWith('tel:')) {
      cy.request('HEAD', href).then((response) => {
        expect(response.status).to.be.oneOf([200, 301, 302]);
      });
    }
  });
});

// Performance testing
Cypress.Commands.add('shouldLoadFast', (maxTime = 3000) => {
  cy.window().then((win) => {
    const performance = win.performance;
    const navigationTiming = performance.getEntriesByType('navigation')[0];
    
    if (navigationTiming) {
      const loadTime = navigationTiming.loadEventEnd - navigationTiming.loadEventStart;
      expect(loadTime).to.be.lessThan(maxTime);
    }
  });
});