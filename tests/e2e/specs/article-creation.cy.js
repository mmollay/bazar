describe('Article Creation Flow', () => {
  beforeEach(() => {
    cy.clearTestData();
    cy.loginAsTestUser();
  });
  
  context('Manual Article Creation', () => {
    it('should create an article with complete information', () => {
      const articleData = {
        title: 'iPhone 12 Pro Max - Like New',
        description: 'Excellent condition iPhone 12 Pro Max with original box, charger, and screen protector already applied. No scratches or dents.',
        price: 699.99,
        category: 'Electronics',
        condition: 'like_new',
        location: 'New York, NY',
        images: ['tests/e2e/fixtures/images/iphone1.jpg', 'tests/e2e/fixtures/images/iphone2.jpg']
      };
      
      cy.intercept('POST', '/api/articles').as('createArticle');
      cy.intercept('POST', '/api/upload/images').as('uploadImages');
      
      cy.createArticle(articleData);
      
      cy.waitForApi('@uploadImages');
      cy.waitForApi('@createArticle');
      
      // Verify article details on article page
      cy.get('[data-cy="article-title"]').should('contain', articleData.title);
      cy.get('[data-cy="article-description"]').should('contain', articleData.description);
      cy.get('[data-cy="article-price"]').should('contain', '$699.99');
      cy.get('[data-cy="article-condition"]').should('contain', 'Like New');
      cy.get('[data-cy="article-location"]').should('contain', 'New York, NY');
      
      // Verify images are displayed
      cy.get('[data-cy="article-images"]').find('img').should('have.length', 2);
      
      // Verify article appears in user's listings
      cy.navigateToMyArticles();
      cy.get('[data-cy="article-card"]').should('contain', articleData.title);
    });
    
    it('should create an article with minimum required fields', () => {
      const minimalArticle = {
        title: 'Basic Item for Sale',
        description: 'Simple item with minimal information provided.',
        price: 25.00,
        category: 'Other',
        condition: 'used'
      };
      
      cy.createArticle(minimalArticle);
      
      cy.get('[data-cy="article-title"]').should('contain', minimalArticle.title);
      cy.get('[data-cy="article-price"]').should('contain', '$25.00');
    });
    
    it('should handle multiple image uploads', () => {
      cy.navigateToCreateArticle();
      
      const imageFiles = [
        'tests/e2e/fixtures/images/image1.jpg',
        'tests/e2e/fixtures/images/image2.jpg',
        'tests/e2e/fixtures/images/image3.jpg',
        'tests/e2e/fixtures/images/image4.jpg',
        'tests/e2e/fixtures/images/image5.jpg'
      ];
      
      cy.intercept('POST', '/api/upload/images').as('uploadImages');
      
      cy.uploadImages(imageFiles);
      
      cy.waitForApi('@uploadImages');
      
      // Should show all uploaded images
      cy.get('[data-cy="uploaded-image"]').should('have.length', 5);
      
      // Should be able to reorder images
      cy.get('[data-cy="uploaded-image-0"]').drag('[data-cy="uploaded-image-2"]');
      
      // Should be able to delete images
      cy.get('[data-cy="delete-image-4"]').click();
      cy.get('[data-cy="uploaded-image"]').should('have.length', 4);
      
      // Should set primary image
      cy.get('[data-cy="set-primary-1"]').click();
      cy.get('[data-cy="uploaded-image-1"]').should('have.class', 'primary-image');
    });
  });
  
  context('AI Auto-Fill Feature', () => {
    it('should use AI to auto-fill article details from images', () => {
      const imageFiles = ['tests/e2e/fixtures/images/smartphone.jpg'];
      
      cy.intercept('POST', '/api/ai/analyze-images', { 
        fixture: 'ai/smartphone-analysis.json' 
      }).as('aiAnalysis');
      
      cy.useAIAutoFill(imageFiles);
      
      cy.waitForApi('@aiAnalysis');
      
      // Verify AI suggestions are populated
      cy.get('[data-cy="title-input"]').should('contain.value', 'iPhone 12 Pro');
      cy.get('[data-cy="description-textarea"]').should('contain.value', 'smartphone');
      cy.get('[data-cy="category-select"]').should('have.value', '1'); // Electronics category
      cy.get('[data-cy="suggested-price"]').should('contain', '$400 - $800');
      
      // Should show confidence score
      cy.get('[data-cy="ai-confidence"]').should('contain', '87%');
      
      // Should allow editing of suggestions
      cy.get('[data-cy="title-input"]').clear().type('iPhone 12 Pro - Excellent Condition');
      cy.get('[data-cy="price-input"]').type('650');
      
      cy.get('[data-cy="create-article-submit"]').click();
      
      cy.url().should('match', /\/articles\/\d+/);
      cy.get('[data-cy="article-title"]').should('contain', 'iPhone 12 Pro - Excellent Condition');
    });
    
    it('should handle low confidence AI suggestions', () => {
      cy.intercept('POST', '/api/ai/analyze-images', { 
        fixture: 'ai/low-confidence-analysis.json' 
      }).as('lowConfidenceAnalysis');
      
      const imageFiles = ['tests/e2e/fixtures/images/unclear-object.jpg'];
      cy.useAIAutoFill(imageFiles);
      
      cy.waitForApi('@lowConfidenceAnalysis');
      
      // Should show warning about low confidence
      cy.get('[data-cy="low-confidence-warning"]')
        .should('be.visible')
        .and('contain', 'AI analysis has low confidence');
      
      // Should suggest manual input
      cy.get('[data-cy="manual-input-suggestion"]')
        .should('be.visible')
        .and('contain', 'Please review and adjust the suggestions');
      
      // Confidence should be shown
      cy.get('[data-cy="ai-confidence"]').should('contain', '32%');
    });
    
    it('should handle AI analysis errors', () => {
      cy.intercept('POST', '/api/ai/analyze-images', {
        statusCode: 500,
        body: { error: 'AI service temporarily unavailable' }
      }).as('aiError');
      
      const imageFiles = ['tests/e2e/fixtures/images/test-image.jpg'];
      
      cy.navigateToCreateArticle();
      cy.uploadImages(imageFiles);
      cy.get('[data-cy="ai-autofill-button"]').click();
      
      cy.wait('@aiError');
      
      // Should show error message
      cy.get('[data-cy="ai-error-message"]')
        .should('be.visible')
        .and('contain', 'AI analysis is temporarily unavailable');
      
      // Should allow manual form completion
      cy.get('[data-cy="manual-form"]').should('be.visible');
      cy.get('[data-cy="title-input"]').should('be.enabled');
    });
  });
  
  context('Form Validation', () => {
    beforeEach(() => {
      cy.navigateToCreateArticle();
    });
    
    it('should validate required fields', () => {
      cy.get('[data-cy="create-article-submit"]').click();
      
      cy.expectValidationError('title', 'Title is required');
      cy.expectValidationError('description', 'Description is required');
      cy.expectValidationError('price', 'Price is required');
      cy.expectValidationError('category', 'Category is required');
    });
    
    it('should validate title length', () => {
      const shortTitle = 'ab';
      const longTitle = 'a'.repeat(256);
      
      cy.get('[data-cy="title-input"]').type(shortTitle);
      cy.get('[data-cy="title-input"]').blur();
      cy.expectValidationError('title', 'Title must be at least 3 characters');
      
      cy.get('[data-cy="title-input"]').clear().type(longTitle);
      cy.get('[data-cy="title-input"]').blur();
      cy.expectValidationError('title', 'Title must be less than 255 characters');
    });
    
    it('should validate description length', () => {
      const shortDescription = 'short';
      const longDescription = 'a'.repeat(5001);
      
      cy.get('[data-cy="description-textarea"]').type(shortDescription);
      cy.get('[data-cy="description-textarea"]').blur();
      cy.expectValidationError('description', 'Description must be at least 10 characters');
      
      cy.get('[data-cy="description-textarea"]').clear().type(longDescription);
      cy.get('[data-cy="description-textarea"]').blur();
      cy.expectValidationError('description', 'Description must be less than 5000 characters');
    });
    
    it('should validate price format', () => {
      const invalidPrices = [
        'abc',
        '-100',
        '0',
        '999999999'
      ];
      
      invalidPrices.forEach(price => {
        cy.get('[data-cy="price-input"]').clear().type(price);
        cy.get('[data-cy="price-input"]').blur();
        
        if (price === 'abc') {
          cy.expectValidationError('price', 'Price must be a valid number');
        } else if (price === '-100') {
          cy.expectValidationError('price', 'Price must be positive');
        } else if (price === '0') {
          cy.expectValidationError('price', 'Price must be greater than 0');
        } else if (price === '999999999') {
          cy.expectValidationError('price', 'Price is too high');
        }
      });
    });
    
    it('should validate image uploads', () => {
      // Test file size limit
      cy.intercept('POST', '/api/upload/images', {
        statusCode: 413,
        body: { error: 'File too large' }
      }).as('uploadTooLarge');
      
      cy.get('[data-cy="image-upload"]').selectFile(['tests/e2e/fixtures/images/large-image.jpg']);
      
      cy.wait('@uploadTooLarge');
      cy.get('[data-cy="upload-error"]').should('contain', 'File is too large');
      
      // Test invalid file type
      cy.get('[data-cy="image-upload"]').selectFile(['tests/e2e/fixtures/files/document.pdf']);
      cy.get('[data-cy="upload-error"]').should('contain', 'Only image files are allowed');
    });
  });
  
  context('Draft Functionality', () => {
    it('should save draft automatically', () => {
      cy.navigateToCreateArticle();
      
      const draftData = {
        title: 'Draft Article Title',
        description: 'This is a draft article that should be saved automatically.',
        price: '150'
      };
      
      cy.intercept('POST', '/api/articles/drafts', { 
        body: { id: 'draft-123', success: true }
      }).as('saveDraft');
      
      cy.get('[data-cy="title-input"]').type(draftData.title);
      cy.get('[data-cy="description-textarea"]').type(draftData.description);
      cy.get('[data-cy="price-input"]').type(draftData.price);
      
      // Should auto-save after user stops typing
      cy.wait(3000); // Wait for debounced save
      cy.waitForApi('@saveDraft');
      
      cy.get('[data-cy="draft-saved-indicator"]').should('be.visible');
    });
    
    it('should restore draft when returning', () => {
      cy.intercept('GET', '/api/articles/drafts', { 
        fixture: 'articles/draft-article.json' 
      }).as('getDrafts');
      
      cy.navigateToCreateArticle();
      
      cy.waitForApi('@getDrafts');
      
      // Should show draft restoration prompt
      cy.get('[data-cy="restore-draft-prompt"]').should('be.visible');
      cy.get('[data-cy="restore-draft-button"]').click();
      
      // Should populate form with draft data
      cy.get('[data-cy="title-input"]').should('have.value', 'Draft Article Title');
      cy.get('[data-cy="description-textarea"]').should('contain.value', 'This is a draft');
      cy.get('[data-cy="price-input"]').should('have.value', '150');
    });
    
    it('should be able to publish draft', () => {
      cy.intercept('PUT', '/api/articles/drafts/draft-123/publish').as('publishDraft');
      
      cy.navigateToCreateArticle();
      cy.get('[data-cy="restore-draft-button"]').click();
      
      // Complete the form
      cy.get('[data-cy="category-select"]').select('Electronics');
      cy.get('[data-cy="condition-select"]').select('used');
      
      cy.get('[data-cy="create-article-submit"]').click();
      
      cy.waitForApi('@publishDraft');
      
      cy.url().should('match', /\/articles\/\d+/);
      cy.get('[data-cy="article-status"]').should('contain', 'Published');
    });
  });
  
  context('Location Features', () => {
    it('should detect user location automatically', () => {
      cy.navigateToCreateArticle();
      
      // Mock geolocation
      cy.window().then((win) => {
        cy.stub(win.navigator.geolocation, 'getCurrentPosition').callsFake((success) => {
          success({
            coords: {
              latitude: 40.7128,
              longitude: -74.0060
            }
          });
        });
      });
      
      cy.get('[data-cy="detect-location-button"]').click();
      
      // Should populate location field
      cy.get('[data-cy="location-input"]').should('contain.value', 'New York');
      cy.get('[data-cy="location-detected"]').should('be.visible');
    });
    
    it('should allow manual location input', () => {
      cy.navigateToCreateArticle();
      
      cy.get('[data-cy="location-input"]').type('San Francisco, CA');
      
      // Should show location suggestions
      cy.get('[data-cy="location-suggestions"]').should('be.visible');
      cy.get('[data-cy="location-suggestion"]').first().click();
      
      cy.get('[data-cy="location-input"]').should('have.value', 'San Francisco, CA');
    });
    
    it('should handle location detection errors', () => {
      cy.navigateToCreateArticle();
      
      cy.window().then((win) => {
        cy.stub(win.navigator.geolocation, 'getCurrentPosition').callsFake((success, error) => {
          error({ code: 1, message: 'Permission denied' });
        });
      });
      
      cy.get('[data-cy="detect-location-button"]').click();
      
      cy.get('[data-cy="location-error"]')
        .should('be.visible')
        .and('contain', 'Location access denied');
    });
  });
  
  context('Advanced Features', () => {
    it('should handle negotiable pricing', () => {
      const articleData = {
        title: 'Negotiable Price Item',
        description: 'Item with negotiable pricing enabled.',
        price: 100,
        category: 'Other',
        condition: 'used'
      };
      
      cy.navigateToCreateArticle();
      
      cy.get('[data-cy="title-input"]').type(articleData.title);
      cy.get('[data-cy="description-textarea"]').type(articleData.description);
      cy.get('[data-cy="price-input"]').type(articleData.price.toString());
      cy.get('[data-cy="category-select"]').select(articleData.category);
      cy.get('[data-cy="condition-select"]').select(articleData.condition);
      
      // Enable negotiable pricing
      cy.get('[data-cy="negotiable-checkbox"]').check();
      
      cy.get('[data-cy="create-article-submit"]').click();
      
      cy.url().should('match', /\/articles\/\d+/);
      cy.get('[data-cy="negotiable-indicator"]').should('contain', 'Price negotiable');
    });
    
    it('should set article expiration', () => {
      cy.navigateToCreateArticle();
      
      // Enable auto-expiration
      cy.get('[data-cy="auto-expire-checkbox"]').check();
      
      // Set expiration to 30 days
      cy.get('[data-cy="expiration-select"]').select('30');
      
      cy.get('[data-cy="expiration-date"]').should('contain', 
        new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toLocaleDateString()
      );
    });
    
    it('should handle shipping options', () => {
      cy.navigateToCreateArticle();
      
      // Enable shipping
      cy.get('[data-cy="shipping-available-checkbox"]').check();
      
      // Set shipping cost
      cy.get('[data-cy="shipping-cost-input"]').type('15.99');
      
      // Select shipping methods
      cy.get('[data-cy="standard-shipping"]').check();
      cy.get('[data-cy="express-shipping"]').check();
      
      cy.get('[data-cy="shipping-summary"]').should('contain', 'Shipping available from $15.99');
    });
  });
  
  context('Performance and UX', () => {
    it('should show progress during form completion', () => {
      cy.navigateToCreateArticle();
      
      // Should start at 0% progress
      cy.get('[data-cy="form-progress"]').should('contain', '0%');
      
      cy.get('[data-cy="title-input"]').type('Test Title');
      cy.get('[data-cy="form-progress"]').should('contain', '20%');
      
      cy.get('[data-cy="description-textarea"]').type('Test description for the article');
      cy.get('[data-cy="form-progress"]').should('contain', '40%');
      
      cy.get('[data-cy="price-input"]').type('100');
      cy.get('[data-cy="form-progress"]').should('contain', '60%');
      
      cy.get('[data-cy="category-select"]').select('Electronics');
      cy.get('[data-cy="form-progress"]').should('contain', '80%');
      
      cy.get('[data-cy="condition-select"]').select('used');
      cy.get('[data-cy="form-progress"]').should('contain', '100%');
      
      cy.get('[data-cy="form-complete-indicator"]').should('be.visible');
    });
    
    it('should handle large image uploads efficiently', () => {
      cy.navigateToCreateArticle();
      
      const largeImages = [
        'tests/e2e/fixtures/images/large1.jpg',
        'tests/e2e/fixtures/images/large2.jpg',
        'tests/e2e/fixtures/images/large3.jpg'
      ];
      
      cy.intercept('POST', '/api/upload/images').as('uploadImages');
      
      const start = Date.now();
      
      cy.uploadImages(largeImages);
      
      cy.waitForApi('@uploadImages').then(() => {
        const uploadTime = Date.now() - start;
        expect(uploadTime).to.be.lessThan(10000); // Should complete within 10 seconds
      });
      
      // Should show upload progress
      cy.get('[data-cy="upload-progress"]').should('have.been.visible');
      cy.get('[data-cy="upload-complete"]').should('be.visible');
    });
    
    it('should provide real-time character counting', () => {
      cy.navigateToCreateArticle();
      
      const longTitle = 'a'.repeat(200);
      
      cy.get('[data-cy="title-input"]').type(longTitle);
      
      cy.get('[data-cy="title-counter"]').should('contain', '200/255');
      
      // Should warn when approaching limit
      const nearLimitTitle = 'a'.repeat(240);
      cy.get('[data-cy="title-input"]').clear().type(nearLimitTitle);
      
      cy.get('[data-cy="title-counter"]')
        .should('contain', '240/255')
        .and('have.class', 'warning');
    });
  });
  
  context('Error Recovery', () => {
    it('should recover from network interruption', () => {
      cy.navigateToCreateArticle();
      
      const articleData = {
        title: 'Network Test Article',
        description: 'Testing network interruption recovery.',
        price: '50',
        category: 'Other',
        condition: 'used'
      };
      
      cy.get('[data-cy="title-input"]').type(articleData.title);
      cy.get('[data-cy="description-textarea"]').type(articleData.description);
      cy.get('[data-cy="price-input"]').type(articleData.price);
      cy.get('[data-cy="category-select"]').select(articleData.category);
      cy.get('[data-cy="condition-select"]').select(articleData.condition);
      
      // Simulate network error
      cy.intercept('POST', '/api/articles', { forceNetworkError: true }).as('networkError');
      
      cy.get('[data-cy="create-article-submit"]').click();
      
      // Should show network error
      cy.get('[data-cy="network-error"]').should('be.visible');
      
      // Should offer retry option
      cy.get('[data-cy="retry-button"]').should('be.visible');
      
      // Fix network and retry
      cy.intercept('POST', '/api/articles', { fixture: 'articles/created-article.json' }).as('createSuccess');
      
      cy.get('[data-cy="retry-button"]').click();
      
      cy.waitForApi('@createSuccess');
      cy.url().should('match', /\/articles\/\d+/);
    });
    
    it('should preserve form data during errors', () => {
      cy.navigateToCreateArticle();
      
      const articleData = {
        title: 'Error Recovery Test',
        description: 'Testing form data preservation during errors.',
        price: '75'
      };
      
      cy.get('[data-cy="title-input"]').type(articleData.title);
      cy.get('[data-cy="description-textarea"]').type(articleData.description);
      cy.get('[data-cy="price-input"]').type(articleData.price);
      
      // Simulate server error
      cy.intercept('POST', '/api/articles', {
        statusCode: 500,
        body: { error: 'Server error' }
      }).as('serverError');
      
      cy.get('[data-cy="create-article-submit"]').click();
      
      cy.wait('@serverError');
      
      // Form data should be preserved
      cy.get('[data-cy="title-input"]').should('have.value', articleData.title);
      cy.get('[data-cy="description-textarea"]').should('contain.value', articleData.description);
      cy.get('[data-cy="price-input"]').should('have.value', articleData.price);
      
      cy.get('[data-cy="error-message"]').should('be.visible');
    });
  });
});