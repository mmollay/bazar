/**
 * GDPR Cookie Consent Banner
 * Provides granular cookie consent management with German compliance
 */

class CookieBanner {
    constructor() {
        this.apiEndpoint = '/bazar/backend/api/v1/cookies/consent';
        this.isVisible = false;
        this.preferences = {
            necessary: true,    // Always true - required for site functionality
            functional: false,
            analytics: false,
            marketing: false,
            social: false
        };
        
        this.init();
    }
    
    async init() {
        try {
            await this.checkConsentStatus();
        } catch (error) {
            console.error('Error checking consent status:', error);
            this.showBanner();
        }
    }
    
    async checkConsentStatus() {
        const response = await fetch(this.apiEndpoint);
        const data = await response.json();
        
        if (data.showBanner) {
            this.showBanner();
        } else {
            this.preferences = data.preferences || this.preferences;
            this.applyConsent();
        }
    }
    
    showBanner() {
        if (this.isVisible) return;
        
        this.isVisible = true;
        this.createBannerHTML();
        this.bindEvents();
        
        // Animate banner in
        setTimeout(() => {
            const banner = document.getElementById('cookie-banner');
            if (banner) {
                banner.classList.add('show');
            }
        }, 100);
    }
    
    createBannerHTML() {
        const bannerHTML = `
            <div id="cookie-banner" class="cookie-banner">
                <div class="cookie-banner-content">
                    <div class="cookie-banner-header">
                        <h3><i class="fas fa-cookie-bite"></i> Cookie-Einstellungen</h3>
                        <p>
                            Wir verwenden Cookies, um Ihnen die bestmögliche Erfahrung auf unserer Website zu bieten. 
                            Einige sind für das Funktionieren der Website unerlässlich, während andere uns helfen, 
                            diese Website und Ihre Erfahrung zu verbessern.
                        </p>
                    </div>
                    
                    <div class="cookie-categories">
                        <div class="cookie-category essential">
                            <div class="category-header">
                                <div class="category-toggle">
                                    <input type="checkbox" id="cookies-necessary" checked disabled>
                                    <label for="cookies-necessary">
                                        <span class="category-name">Notwendige Cookies</span>
                                        <span class="category-required">Erforderlich</span>
                                    </label>
                                </div>
                            </div>
                            <div class="category-description">
                                Diese Cookies sind für das Funktionieren der Website erforderlich und können nicht deaktiviert werden. 
                                Sie werden normalerweise nur als Reaktion auf Ihre Aktionen gesetzt, wie z.B. Spracheinstellungen oder Anmeldedaten.
                            </div>
                        </div>
                        
                        <div class="cookie-category">
                            <div class="category-header">
                                <div class="category-toggle">
                                    <input type="checkbox" id="cookies-functional">
                                    <label for="cookies-functional">
                                        <span class="category-name">Funktionale Cookies</span>
                                    </label>
                                </div>
                                <button class="category-details" data-category="functional">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            </div>
                            <div class="category-description" id="desc-functional" style="display: none;">
                                Diese Cookies ermöglichen erweiterte Funktionalität und Personalisierung. Sie können von uns oder 
                                von Drittanbietern gesetzt werden, deren Dienste wir auf unseren Seiten verwenden.
                                <br><strong>Beispiele:</strong> Bevorzugte Ansichtseinstellungen, Chatbot-Funktionen
                            </div>
                        </div>
                        
                        <div class="cookie-category">
                            <div class="category-header">
                                <div class="category-toggle">
                                    <input type="checkbox" id="cookies-analytics">
                                    <label for="cookies-analytics">
                                        <span class="category-name">Analyse-Cookies</span>
                                    </label>
                                </div>
                                <button class="category-details" data-category="analytics">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            </div>
                            <div class="category-description" id="desc-analytics" style="display: none;">
                                Diese Cookies helfen uns zu verstehen, wie Besucher mit der Website interagieren, indem sie 
                                Informationen anonym sammeln und melden. Alle gesammelten Daten werden aggregiert und sind daher anonym.
                                <br><strong>Anbieter:</strong> Google Analytics (anonymisiert)
                            </div>
                        </div>
                        
                        <div class="cookie-category">
                            <div class="category-header">
                                <div class="category-toggle">
                                    <input type="checkbox" id="cookies-marketing">
                                    <label for="cookies-marketing">
                                        <span class="category-name">Marketing-Cookies</span>
                                    </label>
                                </div>
                                <button class="category-details" data-category="marketing">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            </div>
                            <div class="category-description" id="desc-marketing" style="display: none;">
                                Diese Cookies werden verwendet, um Ihnen relevante Werbung zu zeigen. Sie können auch dazu 
                                verwendet werden, die Anzahl der Anzeigen zu begrenzen und die Wirksamkeit von Werbekampagnen zu messen.
                                <br><strong>Anbieter:</strong> Google Ads, Facebook Pixel
                            </div>
                        </div>
                        
                        <div class="cookie-category">
                            <div class="category-header">
                                <div class="category-toggle">
                                    <input type="checkbox" id="cookies-social">
                                    <label for="cookies-social">
                                        <span class="category-name">Social Media Cookies</span>
                                    </label>
                                </div>
                                <button class="category-details" data-category="social">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            </div>
                            <div class="category-description" id="desc-social" style="display: none;">
                                Diese Cookies ermöglichen es Ihnen, Inhalte der Website in sozialen Netzwerken zu teilen 
                                und Social Media Funktionen zu nutzen. Sie können von Social Media Anbietern gesetzt werden.
                                <br><strong>Anbieter:</strong> Facebook, Twitter, LinkedIn
                            </div>
                        </div>
                    </div>
                    
                    <div class="cookie-banner-actions">
                        <button class="cookie-btn cookie-btn-secondary" id="cookie-decline-all">
                            Alle ablehnen
                        </button>
                        <button class="cookie-btn cookie-btn-primary" id="cookie-accept-selected">
                            Auswahl speichern
                        </button>
                        <button class="cookie-btn cookie-btn-success" id="cookie-accept-all">
                            Alle akzeptieren
                        </button>
                    </div>
                    
                    <div class="cookie-banner-footer">
                        <p>
                            Weitere Informationen finden Sie in unserer 
                            <a href="/bazar/backend/api/v1/legal/privacy" target="_blank">Datenschutzerklärung</a> und 
                            <a href="/bazar/backend/api/v1/legal/terms" target="_blank">Nutzungsbedingungen</a>.
                            Sie können Ihre Einstellungen jederzeit in den 
                            <button class="cookie-settings-link" id="cookie-preferences">Cookie-Einstellungen</button> ändern.
                        </p>
                    </div>
                </div>
                
                <div class="cookie-banner-overlay"></div>
            </div>
        `;
        
        // Add CSS styles
        this.addStyles();
        
        // Insert banner into DOM
        document.body.insertAdjacentHTML('beforeend', bannerHTML);
    }
    
    addStyles() {
        if (document.getElementById('cookie-banner-styles')) return;
        
        const styles = `
            <style id="cookie-banner-styles">
                .cookie-banner {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    opacity: 0;
                    visibility: hidden;
                    transition: all 0.3s ease;
                }
                
                .cookie-banner.show {
                    opacity: 1;
                    visibility: visible;
                }
                
                .cookie-banner-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.7);
                    backdrop-filter: blur(2px);
                }
                
                .cookie-banner-content {
                    position: relative;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    background: #fff;
                    border-radius: 12px;
                    padding: 30px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    overflow-y: auto;
                    z-index: 1;
                }
                
                .cookie-banner-header h3 {
                    margin: 0 0 15px 0;
                    color: #333;
                    font-size: 22px;
                    font-weight: 600;
                }
                
                .cookie-banner-header h3 i {
                    color: #f39c12;
                    margin-right: 10px;
                }
                
                .cookie-banner-header p {
                    margin: 0 0 25px 0;
                    color: #666;
                    line-height: 1.5;
                    font-size: 14px;
                }
                
                .cookie-categories {
                    margin-bottom: 25px;
                }
                
                .cookie-category {
                    border: 1px solid #e0e0e0;
                    border-radius: 8px;
                    margin-bottom: 12px;
                    overflow: hidden;
                }
                
                .cookie-category.essential {
                    border-color: #28a745;
                    background: rgba(40, 167, 69, 0.05);
                }
                
                .category-header {
                    padding: 15px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    background: #fafafa;
                }
                
                .category-toggle {
                    display: flex;
                    align-items: center;
                    flex: 1;
                }
                
                .category-toggle input[type="checkbox"] {
                    margin: 0 12px 0 0;
                    transform: scale(1.2);
                    accent-color: #007bff;
                }
                
                .category-toggle input[type="checkbox"]:disabled {
                    opacity: 0.7;
                }
                
                .category-toggle label {
                    margin: 0;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    flex: 1;
                }
                
                .category-name {
                    font-weight: 600;
                    color: #333;
                    font-size: 14px;
                }
                
                .category-required {
                    background: #28a745;
                    color: white;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 10px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                
                .category-details {
                    background: none;
                    border: none;
                    color: #007bff;
                    cursor: pointer;
                    padding: 5px;
                    border-radius: 3px;
                    transition: all 0.2s;
                }
                
                .category-details:hover {
                    background: rgba(0, 123, 255, 0.1);
                }
                
                .category-description {
                    padding: 15px;
                    font-size: 13px;
                    color: #666;
                    line-height: 1.4;
                    border-top: 1px solid #e0e0e0;
                }
                
                .cookie-banner-actions {
                    display: flex;
                    gap: 12px;
                    flex-wrap: wrap;
                    justify-content: center;
                    margin-bottom: 20px;
                }
                
                .cookie-btn {
                    padding: 12px 24px;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-weight: 600;
                    font-size: 14px;
                    transition: all 0.3s;
                    flex: 1;
                    min-width: 120px;
                }
                
                .cookie-btn-primary {
                    background: #007bff;
                    color: white;
                }
                
                .cookie-btn-primary:hover {
                    background: #0056b3;
                    transform: translateY(-1px);
                }
                
                .cookie-btn-success {
                    background: #28a745;
                    color: white;
                }
                
                .cookie-btn-success:hover {
                    background: #1e7e34;
                    transform: translateY(-1px);
                }
                
                .cookie-btn-secondary {
                    background: #6c757d;
                    color: white;
                }
                
                .cookie-btn-secondary:hover {
                    background: #545b62;
                    transform: translateY(-1px);
                }
                
                .cookie-banner-footer {
                    text-align: center;
                    padding-top: 20px;
                    border-top: 1px solid #e0e0e0;
                }
                
                .cookie-banner-footer p {
                    margin: 0;
                    font-size: 12px;
                    color: #666;
                    line-height: 1.4;
                }
                
                .cookie-banner-footer a,
                .cookie-settings-link {
                    color: #007bff;
                    text-decoration: none;
                    background: none;
                    border: none;
                    cursor: pointer;
                    font-size: 12px;
                }
                
                .cookie-banner-footer a:hover,
                .cookie-settings-link:hover {
                    text-decoration: underline;
                }
                
                /* Cookie preferences float button */
                .cookie-preferences-btn {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    background: #007bff;
                    color: white;
                    border: none;
                    border-radius: 50px;
                    padding: 12px 18px;
                    cursor: pointer;
                    font-size: 12px;
                    font-weight: 600;
                    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
                    transition: all 0.3s;
                    z-index: 1000;
                }
                
                .cookie-preferences-btn:hover {
                    background: #0056b3;
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
                }
                
                /* Mobile responsiveness */
                @media (max-width: 768px) {
                    .cookie-banner-content {
                        width: 95%;
                        padding: 20px;
                        max-height: 90vh;
                    }
                    
                    .cookie-banner-actions {
                        flex-direction: column;
                    }
                    
                    .cookie-btn {
                        width: 100%;
                        margin-bottom: 8px;
                    }
                    
                    .category-header {
                        padding: 12px;
                    }
                    
                    .category-description {
                        padding: 12px;
                    }
                }
                
                /* Animation for category descriptions */
                .category-description.show {
                    display: block !important;
                    animation: slideDown 0.3s ease;
                }
                
                @keyframes slideDown {
                    from {
                        opacity: 0;
                        transform: translateY(-10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            </style>
        `;
        
        document.head.insertAdjacentHTML('beforeend', styles);
    }
    
    bindEvents() {
        // Accept all cookies
        document.getElementById('cookie-accept-all')?.addEventListener('click', () => {
            this.acceptAll();
        });
        
        // Decline all cookies
        document.getElementById('cookie-decline-all')?.addEventListener('click', () => {
            this.declineAll();
        });
        
        // Accept selected cookies
        document.getElementById('cookie-accept-selected')?.addEventListener('click', () => {
            this.acceptSelected();
        });
        
        // Cookie preferences link
        document.getElementById('cookie-preferences')?.addEventListener('click', () => {
            this.showPreferences();
        });
        
        // Category details toggles
        document.querySelectorAll('.category-details').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const category = e.target.closest('button').dataset.category;
                this.toggleCategoryDescription(category);
            });
        });
        
        // Checkbox change events
        document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const category = e.target.id.replace('cookies-', '');
                if (category !== 'necessary') {
                    this.preferences[category] = e.target.checked;
                }
            });
        });
        
        // Close banner on overlay click
        document.querySelector('.cookie-banner-overlay')?.addEventListener('click', () => {
            // Don't close on overlay click for accessibility - require explicit choice
        });
        
        // ESC key to close (but require explicit choice)
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isVisible) {
                // Don't close with ESC - require explicit choice for GDPR compliance
            }
        });
    }
    
    toggleCategoryDescription(category) {
        const desc = document.getElementById(`desc-${category}`);
        if (desc) {
            if (desc.style.display === 'none') {
                desc.style.display = 'block';
                desc.classList.add('show');
            } else {
                desc.style.display = 'none';
                desc.classList.remove('show');
            }
        }
    }
    
    acceptAll() {
        this.preferences = {
            necessary: true,
            functional: true,
            analytics: true,
            marketing: true,
            social: true
        };
        
        this.saveConsent('banner');
    }
    
    declineAll() {
        this.preferences = {
            necessary: true,
            functional: false,
            analytics: false,
            marketing: false,
            social: false
        };
        
        this.saveConsent('banner');
    }
    
    acceptSelected() {
        // Get current checkbox states
        this.preferences = {
            necessary: true,
            functional: document.getElementById('cookies-functional')?.checked || false,
            analytics: document.getElementById('cookies-analytics')?.checked || false,
            marketing: document.getElementById('cookies-marketing')?.checked || false,
            social: document.getElementById('cookies-social')?.checked || false
        };
        
        this.saveConsent('banner');
    }
    
    async saveConsent(method = 'banner') {
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    necessary: this.preferences.necessary,
                    functional: this.preferences.functional,
                    analytics: this.preferences.analytics,
                    marketing: this.preferences.marketing,
                    social: this.preferences.social,
                    method: method
                })
            });
            
            if (response.ok) {
                this.hideBanner();
                this.applyConsent();
                this.addPreferencesButton();
                
                // Show success message briefly
                this.showSuccessMessage();
            } else {
                console.error('Error saving consent:', await response.text());
                alert('Fehler beim Speichern der Cookie-Einstellungen. Bitte versuchen Sie es erneut.');
            }
        } catch (error) {
            console.error('Error saving consent:', error);
            alert('Fehler beim Speichern der Cookie-Einstellungen. Bitte versuchen Sie es erneut.');
        }
    }
    
    hideBanner() {
        const banner = document.getElementById('cookie-banner');
        if (banner) {
            banner.classList.remove('show');
            setTimeout(() => {
                banner.remove();
                this.isVisible = false;
            }, 300);
        }
    }
    
    showSuccessMessage() {
        const message = document.createElement('div');
        message.className = 'cookie-success-message';
        message.innerHTML = `
            <i class="fas fa-check-circle"></i>
            Cookie-Einstellungen gespeichert
        `;
        
        const styles = `
            .cookie-success-message {
                position: fixed;
                top: 20px;
                right: 20px;
                background: #28a745;
                color: white;
                padding: 15px 20px;
                border-radius: 6px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                z-index: 10001;
                font-weight: 600;
                font-size: 14px;
                opacity: 0;
                transform: translateX(100px);
                transition: all 0.3s ease;
            }
            
            .cookie-success-message.show {
                opacity: 1;
                transform: translateX(0);
            }
        `;
        
        if (!document.getElementById('cookie-success-styles')) {
            document.head.insertAdjacentHTML('beforeend', `<style id="cookie-success-styles">${styles}</style>`);
        }
        
        document.body.appendChild(message);
        
        setTimeout(() => {
            message.classList.add('show');
        }, 100);
        
        setTimeout(() => {
            message.classList.remove('show');
            setTimeout(() => message.remove(), 300);
        }, 3000);
    }
    
    addPreferencesButton() {
        // Remove existing button if present
        const existingBtn = document.getElementById('cookie-preferences-float-btn');
        if (existingBtn) existingBtn.remove();
        
        const button = document.createElement('button');
        button.id = 'cookie-preferences-float-btn';
        button.className = 'cookie-preferences-btn';
        button.innerHTML = '<i class="fas fa-cookie-bite"></i> Cookie-Einstellungen';
        
        button.addEventListener('click', () => {
            this.showPreferences();
        });
        
        document.body.appendChild(button);
    }
    
    showPreferences() {
        // Remove existing banner if present
        const existingBanner = document.getElementById('cookie-banner');
        if (existingBanner) existingBanner.remove();
        
        this.isVisible = true;
        this.createBannerHTML();
        
        // Pre-select current preferences
        setTimeout(() => {
            document.getElementById('cookies-functional').checked = this.preferences.functional;
            document.getElementById('cookies-analytics').checked = this.preferences.analytics;
            document.getElementById('cookies-marketing').checked = this.preferences.marketing;
            document.getElementById('cookies-social').checked = this.preferences.social;
            
            this.bindEvents();
            
            const banner = document.getElementById('cookie-banner');
            if (banner) {
                banner.classList.add('show');
            }
        }, 100);
    }
    
    applyConsent() {
        // Apply/remove tracking scripts based on consent
        this.manageAnalyticsScripts();
        this.manageMarketingScripts();
        this.manageSocialScripts();
        this.manageFunctionalFeatures();
        
        // Store preferences for other scripts to check
        window.cookieConsent = this.preferences;
        
        // Dispatch custom event for other parts of the application
        window.dispatchEvent(new CustomEvent('cookieConsentUpdate', {
            detail: this.preferences
        }));
    }
    
    manageAnalyticsScripts() {
        if (this.preferences.analytics) {
            // Enable Google Analytics (anonymized)
            this.loadGoogleAnalytics();
        } else {
            // Disable/remove Google Analytics
            this.removeGoogleAnalytics();
        }
    }
    
    manageMarketingScripts() {
        if (this.preferences.marketing) {
            // Enable marketing pixels
            this.loadMarketingPixels();
        } else {
            // Remove marketing pixels
            this.removeMarketingPixels();
        }
    }
    
    manageSocialScripts() {
        if (this.preferences.social) {
            // Enable social media widgets
            this.loadSocialWidgets();
        } else {
            // Remove social media widgets
            this.removeSocialWidgets();
        }
    }
    
    manageFunctionalFeatures() {
        if (this.preferences.functional) {
            // Enable functional features
            this.enableFunctionalFeatures();
        } else {
            // Disable functional features
            this.disableFunctionalFeatures();
        }
    }
    
    loadGoogleAnalytics() {
        if (document.querySelector('script[src*="googletagmanager"]')) return;
        
        // Add Google Analytics with anonymization
        const script1 = document.createElement('script');
        script1.async = true;
        script1.src = 'https://www.googletagmanager.com/gtag/js?id=GA_MEASUREMENT_ID';
        document.head.appendChild(script1);
        
        const script2 = document.createElement('script');
        script2.innerHTML = `
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'GA_MEASUREMENT_ID', {
                'anonymize_ip': true,
                'cookie_flags': 'SameSite=None;Secure'
            });
        `;
        document.head.appendChild(script2);
    }
    
    removeGoogleAnalytics() {
        // Remove Google Analytics scripts
        document.querySelectorAll('script[src*="googletagmanager"], script[src*="google-analytics"]').forEach(script => {
            script.remove();
        });
        
        // Clear Google Analytics cookies
        this.deleteCookie('_ga');
        this.deleteCookie('_gid');
        this.deleteCookie('_gat');
    }
    
    loadMarketingPixels() {
        // Facebook Pixel
        if (!document.querySelector('script[src*="facebook"]')) {
            const fbScript = document.createElement('script');
            fbScript.innerHTML = `
                !function(f,b,e,v,n,t,s)
                {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                n.queue=[];t=b.createElement(e);t.async=!0;
                t.src=v;s=b.getElementsByTagName(e)[0];
                s.parentNode.insertBefore(t,s)}(window, document,'script',
                'https://connect.facebook.net/en_US/fbevents.js');
                fbq('init', 'YOUR_PIXEL_ID');
                fbq('track', 'PageView');
            `;
            document.head.appendChild(fbScript);
        }
    }
    
    removeMarketingPixels() {
        // Remove Facebook Pixel
        document.querySelectorAll('script[src*="facebook"]').forEach(script => {
            script.remove();
        });
        
        // Clear marketing cookies
        this.deleteCookie('_fbp');
        this.deleteCookie('_fbc');
    }
    
    loadSocialWidgets() {
        // Enable social media share buttons and widgets
        document.querySelectorAll('.social-widget-placeholder').forEach(placeholder => {
            placeholder.style.display = 'block';
        });
    }
    
    removeSocialWidgets() {
        // Hide social media widgets
        document.querySelectorAll('.social-widget-placeholder').forEach(placeholder => {
            placeholder.style.display = 'none';
        });
    }
    
    enableFunctionalFeatures() {
        // Enable chatbot, preferences storage, etc.
        document.body.classList.add('functional-cookies-enabled');
    }
    
    disableFunctionalFeatures() {
        // Disable functional features
        document.body.classList.remove('functional-cookies-enabled');
    }
    
    deleteCookie(name) {
        document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=${window.location.hostname}`;
        document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
    
    // Static method to check if specific consent is given
    static hasConsent(type) {
        return window.cookieConsent && window.cookieConsent[type] === true;
    }
    
    // Static method to get all consents
    static getConsents() {
        return window.cookieConsent || {
            necessary: true,
            functional: false,
            analytics: false,
            marketing: false,
            social: false
        };
    }
}

// Initialize cookie banner when DOM is loaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.cookieBanner = new CookieBanner();
    });
} else {
    window.cookieBanner = new CookieBanner();
}

// Export for module use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CookieBanner;
}