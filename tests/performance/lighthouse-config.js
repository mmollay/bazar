module.exports = {
  extends: 'lighthouse:default',
  
  settings: {
    // Use desktop configuration for baseline tests
    formFactor: 'desktop',
    screenEmulation: {
      mobile: false,
      width: 1350,
      height: 940,
      deviceScaleFactor: 1,
      disabled: false,
    },
    
    // Network throttling
    throttlingMethod: 'simulate',
    throttling: {
      rttMs: 150,
      throughputKbps: 1638.4,
      cpuSlowdownMultiplier: 1,
      requestLatencyMs: 0,
      downloadThroughputKbps: 1638.4,
      uploadThroughputKbps: 675,
    },
    
    // Audit settings
    onlyAudits: [
      'first-contentful-paint',
      'largest-contentful-paint',
      'cumulative-layout-shift',
      'total-blocking-time',
      'speed-index',
      'interactive',
      'server-response-time',
      'render-blocking-resources',
      'unused-css-rules',
      'unused-javascript',
      'modern-image-formats',
      'efficient-animated-content',
      'preload-lcp-image',
      'uses-text-compression',
      'uses-rel-preconnect',
      'font-display',
      'unminified-css',
      'unminified-javascript',
      'critical-request-chains',
      'user-timings',
      'bootup-time',
      'mainthread-work-breakdown',
      'dom-size',
      'uses-passive-event-listeners',
      'no-document-write',
      'uses-http2',
      'uses-long-cache-ttl',
      'total-byte-weight',
      'offscreen-images',
      'uses-webp-images',
      'uses-optimized-images',
      'uses-responsive-images',
      'preload-fonts',
      'viewport',
      'without-javascript',
      'is-crawlable',
      'robots-txt',
      'hreflang',
      'canonical',
      'structured-data',
      'meta-description',
      'http-status-code',
      'crawlable-anchors',
      'link-text',
      'is-crawable',
      'tap-targets',
      'accessible-names',
      'aria-allowed-attr',
      'aria-hidden-body',
      'aria-hidden-focus',
      'aria-input-field-name',
      'aria-required-attr',
      'aria-required-children',
      'aria-required-parent',
      'aria-roles',
      'aria-toggle-field-name',
      'aria-valid-attr-value',
      'aria-valid-attr',
      'button-name',
      'bypass',
      'color-contrast',
      'definition-list',
      'dlitem',
      'document-title',
      'duplicate-id-active',
      'duplicate-id-aria',
      'form-field-multiple-labels',
      'frame-title',
      'heading-order',
      'html-has-lang',
      'html-lang-valid',
      'image-alt',
      'input-image-alt',
      'label',
      'landmark-one-main',
      'link-name',
      'list',
      'listitem',
      'meta-refresh',
      'meta-viewport',
      'object-alt',
      'tabindex',
      'td-headers-attr',
      'th-has-data-cells',
      'valid-lang',
      'video-caption',
      'installable-manifest',
      'splash-screen',
      'themed-omnibox',
      'content-width',
      'apple-touch-icon',
      'maskable-icon',
      'offline-start-url',
      'service-worker'
    ],
    
    // Performance thresholds
    budgets: [
      {
        resourceType: 'total',
        budget: 1000 // 1000 KB total
      },
      {
        resourceType: 'script',
        budget: 300 // 300 KB for JavaScript
      },
      {
        resourceType: 'stylesheet',
        budget: 100 // 100 KB for CSS
      },
      {
        resourceType: 'image',
        budget: 500 // 500 KB for images
      },
      {
        resourceType: 'font',
        budget: 100 // 100 KB for fonts
      }
    ]
  },
  
  // Categories to test
  categories: {
    performance: {
      title: 'Performance',
      auditRefs: [
        { id: 'first-contentful-paint', weight: 10 },
        { id: 'largest-contentful-paint', weight: 25 },
        { id: 'cumulative-layout-shift', weight: 15 },
        { id: 'total-blocking-time', weight: 25 },
        { id: 'speed-index', weight: 10 },
        { id: 'interactive', weight: 10 },
        { id: 'server-response-time', weight: 5 }
      ]
    },
    
    accessibility: {
      title: 'Accessibility',
      description: 'These checks highlight opportunities to improve the accessibility of your web app.',
      auditRefs: [
        { id: 'accesskeys', weight: 0 },
        { id: 'aria-allowed-attr', weight: 0 },
        { id: 'aria-hidden-body', weight: 0 },
        { id: 'aria-hidden-focus', weight: 0 },
        { id: 'aria-input-field-name', weight: 0 },
        { id: 'aria-required-attr', weight: 0 },
        { id: 'aria-required-children', weight: 0 },
        { id: 'aria-required-parent', weight: 0 },
        { id: 'aria-roles', weight: 0 },
        { id: 'aria-toggle-field-name', weight: 0 },
        { id: 'aria-valid-attr-value', weight: 0 },
        { id: 'aria-valid-attr', weight: 0 },
        { id: 'button-name', weight: 10 },
        { id: 'bypass', weight: 3 },
        { id: 'color-contrast', weight: 3 },
        { id: 'definition-list', weight: 0 },
        { id: 'dlitem', weight: 0 },
        { id: 'document-title', weight: 3 },
        { id: 'duplicate-id-active', weight: 0 },
        { id: 'duplicate-id-aria', weight: 0 },
        { id: 'form-field-multiple-labels', weight: 0 },
        { id: 'frame-title', weight: 0 },
        { id: 'heading-order', weight: 2 },
        { id: 'html-has-lang', weight: 3 },
        { id: 'html-lang-valid', weight: 0 },
        { id: 'image-alt', weight: 10 },
        { id: 'input-image-alt', weight: 0 },
        { id: 'label', weight: 10 },
        { id: 'landmark-one-main', weight: 0 },
        { id: 'link-name', weight: 3 },
        { id: 'list', weight: 0 },
        { id: 'listitem', weight: 0 },
        { id: 'meta-refresh', weight: 0 },
        { id: 'meta-viewport', weight: 0 },
        { id: 'object-alt', weight: 0 },
        { id: 'tabindex', weight: 0 },
        { id: 'td-headers-attr', weight: 0 },
        { id: 'th-has-data-cells', weight: 0 },
        { id: 'valid-lang', weight: 0 },
        { id: 'video-caption', weight: 0 }
      ]
    },
    
    'best-practices': {
      title: 'Best Practices',
      auditRefs: [
        { id: 'is-on-https', weight: 5 },
        { id: 'uses-http2', weight: 0 },
        { id: 'uses-passive-event-listeners', weight: 0 },
        { id: 'no-document-write', weight: 0 },
        { id: 'external-anchors-use-rel-noopener', weight: 0 },
        { id: 'geolocation-on-start', weight: 0 },
        { id: 'doctype', weight: 0 },
        { id: 'no-vulnerable-libraries', weight: 5 },
        { id: 'js-libraries', weight: 0 },
        { id: 'notification-on-start', weight: 0 },
        { id: 'deprecations', weight: 5 },
        { id: 'password-inputs-can-be-pasted-into', weight: 0 },
        { id: 'errors-in-console', weight: 5 },
        { id: 'image-aspect-ratio', weight: 0 },
        { id: 'image-size-responsive', weight: 0 },
        { id: 'preload-fonts', weight: 0 },
        { id: 'charset', weight: 0 }
      ]
    },
    
    seo: {
      title: 'SEO',
      description: 'These checks ensure that your page is following basic search engine optimization advice.',
      auditRefs: [
        { id: 'viewport', weight: 5 },
        { id: 'document-title', weight: 5 },
        { id: 'meta-description', weight: 5 },
        { id: 'http-status-code', weight: 5 },
        { id: 'link-text', weight: 5 },
        { id: 'crawlable-anchors', weight: 5 },
        { id: 'is-crawlable', weight: 5 },
        { id: 'robots-txt', weight: 5 },
        { id: 'image-alt', weight: 5 },
        { id: 'hreflang', weight: 0 },
        { id: 'canonical', weight: 0 },
        { id: 'font-size', weight: 5 },
        { id: 'plugins', weight: 5 },
        { id: 'tap-targets', weight: 10 },
        { id: 'structured-data', weight: 0 }
      ]
    },
    
    pwa: {
      title: 'Progressive Web App',
      auditRefs: [
        { id: 'installable-manifest', weight: 2 },
        { id: 'service-worker', weight: 1 },
        { id: 'offline-start-url', weight: 1 },
        { id: 'apple-touch-icon', weight: 1 },
        { id: 'splash-screen', weight: 1 },
        { id: 'themed-omnibox', weight: 1 },
        { id: 'content-width', weight: 1 },
        { id: 'viewport', weight: 2 },
        { id: 'without-javascript', weight: 1 },
        { id: 'maskable-icon', weight: 1 }
      ]
    }
  },
  
  // Custom scoring
  scoring: {
    performance: {
      // Performance thresholds (in milliseconds)
      firstContentfulPaint: {
        good: 1800,
        average: 3000
      },
      largestContentfulPaint: {
        good: 2500,
        average: 4000
      },
      totalBlockingTime: {
        good: 200,
        average: 600
      },
      cumulativeLayoutShift: {
        good: 0.1,
        average: 0.25
      },
      speedIndex: {
        good: 3400,
        average: 5800
      },
      interactive: {
        good: 3800,
        average: 7300
      }
    }
  }
};