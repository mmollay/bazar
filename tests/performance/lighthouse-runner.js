const lighthouse = require('lighthouse');
const chromeLauncher = require('chrome-launcher');
const fs = require('fs');
const path = require('path');

class LighthouseRunner {
  constructor(options = {}) {
    this.baseUrl = options.baseUrl || 'http://localhost:3000';
    this.outputDir = options.outputDir || path.join(__dirname, 'reports');
    this.config = require('./lighthouse-config.js');
    this.thresholds = {
      performance: 90,
      accessibility: 95,
      bestPractices: 90,
      seo: 90,
      pwa: 80
    };
    
    // Ensure output directory exists
    if (!fs.existsSync(this.outputDir)) {
      fs.mkdirSync(this.outputDir, { recursive: true });
    }
  }
  
  async runSingle(url, options = {}) {
    const chrome = await chromeLauncher.launch({
      chromeFlags: [
        '--headless',
        '--disable-gpu',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-background-timer-throttling',
        '--disable-backgrounding-occluded-windows',
        '--disable-renderer-backgrounding'
      ]
    });
    
    try {
      const opts = {
        logLevel: 'info',
        output: ['json', 'html'],
        onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo', 'pwa'],
        port: chrome.port,
        ...options
      };
      
      const runnerResult = await lighthouse(url, opts, this.config);
      
      // Save reports
      const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
      const urlSlug = url.replace(/https?:\/\//, '').replace(/[\/:.]/g, '-');
      const baseFilename = `lighthouse-${urlSlug}-${timestamp}`;
      
      // Save JSON report
      const jsonPath = path.join(this.outputDir, `${baseFilename}.json`);
      fs.writeFileSync(jsonPath, runnerResult.report[0]);
      
      // Save HTML report
      const htmlPath = path.join(this.outputDir, `${baseFilename}.html`);
      fs.writeFileSync(htmlPath, runnerResult.report[1]);
      
      console.log(`Reports saved:`);
      console.log(`- JSON: ${jsonPath}`);
      console.log(`- HTML: ${htmlPath}`);
      
      return {
        lhr: runnerResult.lhr,
        artifacts: runnerResult.artifacts,
        reports: {
          json: jsonPath,
          html: htmlPath
        }
      };
    } finally {
      await chrome.kill();
    }
  }
  
  async runMultiple(urls, options = {}) {
    const results = [];
    
    for (const url of urls) {
      console.log(`Running Lighthouse for: ${url}`);
      const result = await this.runSingle(url, options);
      results.push({
        url,
        ...result
      });
      
      // Wait between tests to avoid overwhelming the server
      await this.sleep(2000);
    }
    
    return results;
  }
  
  async runPerformanceTest(pages = []) {
    const defaultPages = [
      '/',
      '/articles',
      '/articles/create',
      '/login',
      '/register',
      '/search',
      '/messages'
    ];
    
    const testPages = pages.length > 0 ? pages : defaultPages;
    const urls = testPages.map(page => `${this.baseUrl}${page}`);
    
    console.log('Starting comprehensive performance test...');
    console.log(`Testing ${urls.length} pages with Lighthouse`);
    
    const results = await this.runMultiple(urls, {
      onlyCategories: ['performance']
    });
    
    return this.analyzePerformanceResults(results);
  }
  
  async runAccessibilityTest(pages = []) {
    const defaultPages = [
      '/',
      '/articles',
      '/login',
      '/register'
    ];
    
    const testPages = pages.length > 0 ? pages : defaultPages;
    const urls = testPages.map(page => `${this.baseUrl}${page}`);
    
    console.log('Starting comprehensive accessibility test...');
    
    const results = await this.runMultiple(urls, {
      onlyCategories: ['accessibility']
    });
    
    return this.analyzeAccessibilityResults(results);
  }
  
  async runPWATest(pages = ['/']) {
    const urls = pages.map(page => `${this.baseUrl}${page}`);
    
    console.log('Starting PWA compliance test...');
    
    const results = await this.runMultiple(urls, {
      onlyCategories: ['pwa']
    });
    
    return this.analyzePWAResults(results);
  }
  
  async runFullAudit(pages = []) {
    const defaultPages = [
      '/',
      '/articles',
      '/login'
    ];
    
    const testPages = pages.length > 0 ? pages : defaultPages;
    const urls = testPages.map(page => `${this.baseUrl}${page}`);
    
    console.log('Starting full Lighthouse audit...');
    
    const results = await this.runMultiple(urls);
    
    return this.analyzeFullResults(results);
  }
  
  analyzePerformanceResults(results) {
    const analysis = {
      summary: {
        totalPages: results.length,
        averageScore: 0,
        passingPages: 0,
        failingPages: 0
      },
      metrics: {
        firstContentfulPaint: [],
        largestContentfulPaint: [],
        totalBlockingTime: [],
        cumulativeLayoutShift: [],
        speedIndex: [],
        interactive: []
      },
      pages: [],
      recommendations: []
    };
    
    let totalScore = 0;
    
    results.forEach(result => {
      const lhr = result.lhr;
      const performanceScore = lhr.categories.performance.score * 100;
      
      totalScore += performanceScore;
      
      if (performanceScore >= this.thresholds.performance) {
        analysis.summary.passingPages++;
      } else {
        analysis.summary.failingPages++;
      }
      
      // Extract key metrics
      const audits = lhr.audits;
      const pageAnalysis = {
        url: result.url,
        score: performanceScore,
        metrics: {
          firstContentfulPaint: audits['first-contentful-paint'].displayValue,
          largestContentfulPaint: audits['largest-contentful-paint'].displayValue,
          totalBlockingTime: audits['total-blocking-time'].displayValue,
          cumulativeLayoutShift: audits['cumulative-layout-shift'].displayValue,
          speedIndex: audits['speed-index'].displayValue,
          interactive: audits.interactive.displayValue
        },
        opportunities: [],
        diagnostics: []
      };
      
      // Collect performance opportunities
      Object.keys(audits).forEach(auditId => {
        const audit = audits[auditId];
        if (audit.scoreDisplayMode === 'numeric' && audit.score < 0.9 && audit.details) {
          if (audit.details.type === 'opportunity') {
            pageAnalysis.opportunities.push({
              id: auditId,
              title: audit.title,
              description: audit.description,
              score: audit.score,
              displayValue: audit.displayValue
            });
          }
        }
      });
      
      analysis.metrics.firstContentfulPaint.push(audits['first-contentful-paint'].numericValue);
      analysis.metrics.largestContentfulPaint.push(audits['largest-contentful-paint'].numericValue);
      analysis.metrics.totalBlockingTime.push(audits['total-blocking-time'].numericValue);
      analysis.metrics.cumulativeLayoutShift.push(audits['cumulative-layout-shift'].numericValue);
      analysis.metrics.speedIndex.push(audits['speed-index'].numericValue);
      analysis.metrics.interactive.push(audits.interactive.numericValue);
      
      analysis.pages.push(pageAnalysis);
    });
    
    analysis.summary.averageScore = Math.round(totalScore / results.length);
    
    // Generate recommendations
    if (analysis.summary.averageScore < this.thresholds.performance) {
      analysis.recommendations.push('Overall performance is below target. Focus on the most impactful optimizations.');
    }
    
    const avgFCP = analysis.metrics.firstContentfulPaint.reduce((a, b) => a + b, 0) / analysis.metrics.firstContentfulPaint.length;
    if (avgFCP > 2000) {
      analysis.recommendations.push('First Contentful Paint is slow. Optimize critical rendering path and reduce server response time.');
    }
    
    const avgLCP = analysis.metrics.largestContentfulPaint.reduce((a, b) => a + b, 0) / analysis.metrics.largestContentfulPaint.length;
    if (avgLCP > 2500) {
      analysis.recommendations.push('Largest Contentful Paint is slow. Optimize largest content elements and preload critical resources.');
    }
    
    const avgTBT = analysis.metrics.totalBlockingTime.reduce((a, b) => a + b, 0) / analysis.metrics.totalBlockingTime.length;
    if (avgTBT > 300) {
      analysis.recommendations.push('Total Blocking Time is high. Reduce JavaScript execution time and split large tasks.');
    }
    
    const avgCLS = analysis.metrics.cumulativeLayoutShift.reduce((a, b) => a + b, 0) / analysis.metrics.cumulativeLayoutShift.length;
    if (avgCLS > 0.1) {
      analysis.recommendations.push('Cumulative Layout Shift is high. Reserve space for dynamic content and avoid layout shifts.');
    }
    
    return analysis;
  }
  
  analyzeAccessibilityResults(results) {
    const analysis = {
      summary: {
        totalPages: results.length,
        averageScore: 0,
        passingPages: 0,
        failingPages: 0
      },
      issues: [],
      pages: [],
      commonIssues: {}
    };
    
    let totalScore = 0;
    
    results.forEach(result => {
      const lhr = result.lhr;
      const a11yScore = lhr.categories.accessibility.score * 100;
      
      totalScore += a11yScore;
      
      if (a11yScore >= this.thresholds.accessibility) {
        analysis.summary.passingPages++;
      } else {
        analysis.summary.failingPages++;
      }
      
      const pageAnalysis = {
        url: result.url,
        score: a11yScore,
        issues: []
      };
      
      // Collect accessibility issues
      Object.keys(lhr.audits).forEach(auditId => {
        const audit = lhr.audits[auditId];
        if (audit.score !== null && audit.score < 1) {
          const issue = {
            id: auditId,
            title: audit.title,
            description: audit.description,
            impact: this.getAccessibilityImpact(auditId),
            score: audit.score
          };
          
          pageAnalysis.issues.push(issue);
          
          // Track common issues
          if (!analysis.commonIssues[auditId]) {
            analysis.commonIssues[auditId] = {
              ...issue,
              occurrences: 0
            };
          }
          analysis.commonIssues[auditId].occurrences++;
        }
      });
      
      analysis.pages.push(pageAnalysis);
    });
    
    analysis.summary.averageScore = Math.round(totalScore / results.length);
    
    return analysis;
  }
  
  analyzePWAResults(results) {
    const analysis = {
      summary: {
        totalPages: results.length,
        averageScore: 0,
        passingPages: 0,
        failingPages: 0
      },
      pwaFeatures: {
        manifest: false,
        serviceWorker: false,
        installable: false,
        offlineSupport: false,
        httpsRequired: false
      },
      pages: []
    };
    
    let totalScore = 0;
    
    results.forEach(result => {
      const lhr = result.lhr;
      const pwaScore = lhr.categories.pwa.score * 100;
      
      totalScore += pwaScore;
      
      if (pwaScore >= this.thresholds.pwa) {
        analysis.summary.passingPages++;
      } else {
        analysis.summary.failingPages++;
      }
      
      const pageAnalysis = {
        url: result.url,
        score: pwaScore,
        features: {}
      };
      
      // Check PWA features
      const audits = lhr.audits;
      pageAnalysis.features.manifest = audits['installable-manifest'].score === 1;
      pageAnalysis.features.serviceWorker = audits['service-worker'].score === 1;
      pageAnalysis.features.offlineSupport = audits['offline-start-url'].score === 1;
      pageAnalysis.features.appleIcon = audits['apple-touch-icon'].score === 1;
      pageAnalysis.features.viewport = audits.viewport.score === 1;
      
      // Update summary features (if any page has the feature, mark as available)
      analysis.pwaFeatures.manifest = analysis.pwaFeatures.manifest || pageAnalysis.features.manifest;
      analysis.pwaFeatures.serviceWorker = analysis.pwaFeatures.serviceWorker || pageAnalysis.features.serviceWorker;
      analysis.pwaFeatures.offlineSupport = analysis.pwaFeatures.offlineSupport || pageAnalysis.features.offlineSupport;
      
      analysis.pages.push(pageAnalysis);
    });
    
    analysis.summary.averageScore = Math.round(totalScore / results.length);
    
    return analysis;
  }
  
  analyzeFullResults(results) {
    return {
      performance: this.analyzePerformanceResults(results),
      accessibility: this.analyzeAccessibilityResults(results),
      pwa: this.analyzePWAResults(results),
      summary: {
        totalPages: results.length,
        overallHealth: this.calculateOverallHealth(results)
      }
    };
  }
  
  calculateOverallHealth(results) {
    let totalPerformance = 0;
    let totalAccessibility = 0;
    let totalBestPractices = 0;
    let totalSeo = 0;
    let totalPwa = 0;
    
    results.forEach(result => {
      const categories = result.lhr.categories;
      totalPerformance += categories.performance ? categories.performance.score * 100 : 0;
      totalAccessibility += categories.accessibility ? categories.accessibility.score * 100 : 0;
      totalBestPractices += categories['best-practices'] ? categories['best-practices'].score * 100 : 0;
      totalSeo += categories.seo ? categories.seo.score * 100 : 0;
      totalPwa += categories.pwa ? categories.pwa.score * 100 : 0;
    });
    
    const count = results.length;
    
    return {
      performance: Math.round(totalPerformance / count),
      accessibility: Math.round(totalAccessibility / count),
      bestPractices: Math.round(totalBestPractices / count),
      seo: Math.round(totalSeo / count),
      pwa: Math.round(totalPwa / count),
      overall: Math.round((totalPerformance + totalAccessibility + totalBestPractices + totalSeo + totalPwa) / (count * 5))
    };
  }
  
  getAccessibilityImpact(auditId) {
    const highImpactAudits = [
      'image-alt',
      'button-name',
      'document-title',
      'html-has-lang',
      'color-contrast'
    ];
    
    const mediumImpactAudits = [
      'link-name',
      'heading-order',
      'label',
      'bypass'
    ];
    
    if (highImpactAudits.includes(auditId)) {
      return 'high';
    } else if (mediumImpactAudits.includes(auditId)) {
      return 'medium';
    } else {
      return 'low';
    }
  }
  
  generateReport(analysis, reportType = 'performance') {
    const timestamp = new Date().toISOString();
    const reportPath = path.join(this.outputDir, `${reportType}-analysis-${timestamp.replace(/[:.]/g, '-')}.json`);
    
    fs.writeFileSync(reportPath, JSON.stringify(analysis, null, 2));
    
    console.log(`\n=== ${reportType.toUpperCase()} ANALYSIS REPORT ===`);
    console.log(`Generated at: ${timestamp}`);
    console.log(`Report saved: ${reportPath}`);
    
    if (reportType === 'performance') {
      this.printPerformanceSummary(analysis);
    } else if (reportType === 'accessibility') {
      this.printAccessibilitySummary(analysis);
    } else if (reportType === 'pwa') {
      this.printPWASummary(analysis);
    }
    
    return reportPath;
  }
  
  printPerformanceSummary(analysis) {
    console.log(`\nPERFORMANCE SUMMARY:`);
    console.log(`- Average Score: ${analysis.summary.averageScore}/100`);
    console.log(`- Passing Pages: ${analysis.summary.passingPages}/${analysis.summary.totalPages}`);
    console.log(`- Failing Pages: ${analysis.summary.failingPages}/${analysis.summary.totalPages}`);
    
    if (analysis.recommendations.length > 0) {
      console.log(`\nRECOMMENDATIONS:`);
      analysis.recommendations.forEach(rec => {
        console.log(`- ${rec}`);
      });
    }
    
    console.log(`\nPAGE SCORES:`);
    analysis.pages.forEach(page => {
      const status = page.score >= this.thresholds.performance ? '✅' : '❌';
      console.log(`${status} ${page.url}: ${page.score}/100`);
    });
  }
  
  printAccessibilitySummary(analysis) {
    console.log(`\nACCESSIBILITY SUMMARY:`);
    console.log(`- Average Score: ${analysis.summary.averageScore}/100`);
    console.log(`- Passing Pages: ${analysis.summary.passingPages}/${analysis.summary.totalPages}`);
    console.log(`- Failing Pages: ${analysis.summary.failingPages}/${analysis.summary.totalPages}`);
    
    if (Object.keys(analysis.commonIssues).length > 0) {
      console.log(`\nCOMMON ISSUES:`);
      Object.entries(analysis.commonIssues)
        .sort((a, b) => b[1].occurrences - a[1].occurrences)
        .slice(0, 5)
        .forEach(([id, issue]) => {
          console.log(`- ${issue.title} (${issue.occurrences} pages)`);
        });
    }
  }
  
  printPWASummary(analysis) {
    console.log(`\nPWA SUMMARY:`);
    console.log(`- Average Score: ${analysis.summary.averageScore}/100`);
    console.log(`- Passing Pages: ${analysis.summary.passingPages}/${analysis.summary.totalPages}`);
    
    console.log(`\nPWA FEATURES:`);
    console.log(`- Manifest: ${analysis.pwaFeatures.manifest ? '✅' : '❌'}`);
    console.log(`- Service Worker: ${analysis.pwaFeatures.serviceWorker ? '✅' : '❌'}`);
    console.log(`- Offline Support: ${analysis.pwaFeatures.offlineSupport ? '✅' : '❌'}`);
  }
  
  async sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }
}

module.exports = LighthouseRunner;

// CLI usage
if (require.main === module) {
  const runner = new LighthouseRunner();
  
  async function main() {
    const args = process.argv.slice(2);
    const testType = args[0] || 'performance';
    const pages = args.slice(1);
    
    try {
      let analysis;
      
      switch (testType) {
        case 'performance':
          analysis = await runner.runPerformanceTest(pages);
          runner.generateReport(analysis, 'performance');
          break;
          
        case 'accessibility':
          analysis = await runner.runAccessibilityTest(pages);
          runner.generateReport(analysis, 'accessibility');
          break;
          
        case 'pwa':
          analysis = await runner.runPWATest(pages);
          runner.generateReport(analysis, 'pwa');
          break;
          
        case 'full':
          analysis = await runner.runFullAudit(pages);
          runner.generateReport(analysis, 'full');
          break;
          
        default:
          console.log('Usage: node lighthouse-runner.js [performance|accessibility|pwa|full] [pages...]');
          console.log('Example: node lighthouse-runner.js performance /articles /login');
          process.exit(1);
      }
      
      // Exit with error code if thresholds are not met
      const avgScore = analysis.summary ? analysis.summary.averageScore : 0;
      const threshold = runner.thresholds[testType] || 90;
      
      if (avgScore < threshold) {
        console.log(`\n❌ Tests failed: Average score ${avgScore} is below threshold ${threshold}`);
        process.exit(1);
      } else {
        console.log(`\n✅ Tests passed: Average score ${avgScore} meets threshold ${threshold}`);
        process.exit(0);
      }
      
    } catch (error) {
      console.error('Error running Lighthouse tests:', error);
      process.exit(1);
    }
  }
  
  main();
}