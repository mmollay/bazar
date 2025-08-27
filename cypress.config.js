const { defineConfig } = require('cypress');

module.exports = defineConfig({
  e2e: {
    baseUrl: 'http://localhost:3000',
    supportFile: 'tests/e2e/support/e2e.js',
    specPattern: 'tests/e2e/specs/**/*.cy.{js,jsx,ts,tsx}',
    fixturesFolder: 'tests/e2e/fixtures',
    screenshotsFolder: 'tests/e2e/screenshots',
    videosFolder: 'tests/e2e/videos',
    downloadsFolder: 'tests/e2e/downloads',
    
    setupNodeEvents(on, config) {
      // implement node event listeners here
      on('task', {
        log(message) {
          console.log(message);
          return null;
        },
        
        // Database seeding
        seedDatabase() {
          // This would connect to test database and seed data
          return null;
        },
        
        // Clear database
        clearDatabase() {
          // This would clear test database
          return null;
        },
        
        // Create test user
        createTestUser(userData) {
          // This would create a test user in database
          return { id: 1, ...userData };
        }
      });
      
      // Coverage collection
      require('@cypress/code-coverage/task')(on, config);
      
      return config;
    },
    
    // Test settings
    viewportWidth: 1280,
    viewportHeight: 720,
    video: true,
    screenshotOnRunFailure: true,
    chromeWebSecurity: false,
    
    // Timeouts
    defaultCommandTimeout: 10000,
    requestTimeout: 15000,
    responseTimeout: 15000,
    pageLoadTimeout: 30000,
    
    // Retry configuration
    retries: {
      runMode: 2,
      openMode: 0
    },
    
    // Environment variables
    env: {
      apiUrl: 'http://localhost:8000/api',
      testUser: {
        email: 'test@example.com',
        password: 'testpassword123'
      },
      adminUser: {
        email: 'admin@example.com',
        password: 'adminpassword123'
      }
    }
  },
  
  component: {
    devServer: {
      framework: 'react',
      bundler: 'webpack',
    },
    supportFile: 'tests/component/support/component.js',
    specPattern: 'tests/component/specs/**/*.cy.{js,jsx,ts,tsx}'
  },
  
  // Global configuration
  watchForFileChanges: true,
  numTestsKeptInMemory: 50,
  experimentalStudio: true,
  experimentalWebKitSupport: true,
  
  // Reporter configuration
  reporter: 'mochawesome',
  reporterOptions: {
    reportDir: 'cypress/reports',
    overwrite: false,
    html: true,
    json: true,
    timestamp: 'mmddyyyy_HHMMss'
  }
});