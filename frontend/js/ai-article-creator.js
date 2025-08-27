/**
 * AI Article Creator
 * Handles the 2-3 click article creation workflow with real-time AI analysis
 */

class AIArticleCreator {
    constructor() {
        this.selectedFiles = [];
        this.analysisResults = null;
        this.currentStep = 1;
        this.acceptedSuggestions = [];
        this.articleId = null;
        this.categories = [];
        
        this.initializeComponents();
        this.bindEvents();
        this.loadCategories();
    }
    
    initializeComponents() {
        // Initialize Fomantic UI components
        $('.ui.dropdown').dropdown();
        $('.ui.checkbox').checkbox();
        $('.ui.modal').modal();
        
        // Get DOM elements
        this.dropZone = document.getElementById('drop-zone');
        this.fileInput = document.getElementById('file-input');
        this.previewContainer = document.getElementById('preview-container');
        this.analyzeBtn = document.getElementById('analyze-btn');
        this.analysisSection = document.getElementById('analysis-section');
        this.suggestionsContainer = document.getElementById('suggestions-container');
        this.processingOverlay = document.getElementById('processing-overlay');
        this.processingStatus = document.getElementById('processing-status');
        this.formSection = document.getElementById('form-section');
        this.articleForm = document.getElementById('article-form');
        
        this.setupDropZone();
    }
    
    bindEvents() {
        // File input change
        this.fileInput.addEventListener('change', (e) => this.handleFileSelection(e));
        
        // Analyze button
        this.analyzeBtn.addEventListener('click', () => this.analyzeImages());
        
        // Form submission
        this.articleForm.addEventListener('submit', (e) => this.submitArticle(e));
        
        // Save draft button
        document.getElementById('save-draft-btn').addEventListener('click', () => this.saveDraft());
        
        // Success modal buttons
        document.getElementById('view-article-btn').addEventListener('click', () => this.viewArticle());
        document.getElementById('create-another-btn').addEventListener('click', () => this.createAnother());
    }
    
    setupDropZone() {
        // Drop zone events
        this.dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            this.dropZone.classList.add('dragover');
        });
        
        this.dropZone.addEventListener('dragleave', () => {
            this.dropZone.classList.remove('dragover');
        });
        
        this.dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            this.dropZone.classList.remove('dragover');
            const files = Array.from(e.dataTransfer.files).filter(file => file.type.startsWith('image/'));
            this.addFiles(files);
        });
        
        // Click to browse
        this.dropZone.addEventListener('click', () => {
            this.fileInput.click();
        });
    }
    
    handleFileSelection(e) {
        const files = Array.from(e.target.files);
        this.addFiles(files);
    }
    
    addFiles(files) {
        // Limit to 5 images total
        const remainingSlots = 5 - this.selectedFiles.length;
        const filesToAdd = files.slice(0, remainingSlots);
        
        filesToAdd.forEach(file => {
            // Validate file
            if (!this.validateFile(file)) return;
            
            this.selectedFiles.push(file);
            this.createImagePreview(file);
        });
        
        this.updateAnalyzeButton();
        
        if (this.selectedFiles.length >= 5) {
            this.showMessage('Maximum 5 images allowed', 'warning');
        }
    }
    
    validateFile(file) {
        // Check file type
        if (!file.type.startsWith('image/')) {
            this.showMessage('Only image files are allowed', 'error');
            return false;
        }
        
        // Check file size (10MB limit)
        if (file.size > 10 * 1024 * 1024) {
            this.showMessage('File size must be less than 10MB', 'error');
            return false;
        }
        
        return true;
    }
    
    createImagePreview(file) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const previewDiv = document.createElement('div');
            previewDiv.className = 'image-preview';
            previewDiv.innerHTML = `
                <img src="${e.target.result}" alt="Preview">
                <button class="remove-btn" data-filename="${file.name}">×</button>
            `;
            
            // Add remove functionality
            const removeBtn = previewDiv.querySelector('.remove-btn');
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.removeFile(file.name);
                previewDiv.remove();
            });
            
            this.previewContainer.appendChild(previewDiv);
        };
        
        reader.readAsDataURL(file);
    }
    
    removeFile(filename) {
        this.selectedFiles = this.selectedFiles.filter(file => file.name !== filename);
        this.updateAnalyzeButton();
    }
    
    updateAnalyzeButton() {
        this.analyzeBtn.disabled = this.selectedFiles.length === 0;
        
        if (this.selectedFiles.length > 0) {
            this.analyzeBtn.textContent = `Analyze ${this.selectedFiles.length} Image${this.selectedFiles.length > 1 ? 's' : ''} with AI`;
        } else {
            this.analyzeBtn.textContent = 'Analyze with AI';
        }
    }
    
    async analyzeImages() {
        this.showProcessing('Analyzing images with AI...');
        this.setStep(2);
        
        try {
            // Create FormData
            const formData = new FormData();
            this.selectedFiles.forEach((file, index) => {
                formData.append('images[]', file);
            });
            formData.append('auto_fill', 'true');
            
            const response = await this.apiRequest('POST', '/api/v1/articles', formData);
            
            if (response.success) {
                this.analysisResults = response.data;
                this.articleId = response.data.article.article.id;
                this.displayAnalysisResults(response.data);
                this.prefillForm(response.data);
                this.setStep(3);
                this.hideProcessing();
                this.showFormSection();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error('Analysis failed:', error);
            this.hideProcessing();
            this.showMessage('Analysis failed: ' + error.message, 'error');
            this.setStep(1);
        }
    }
    
    displayAnalysisResults(data) {
        const autoFillData = data.auto_fill_data;
        const suggestions = data.article.suggestions;
        
        let html = '<div class="ui grid">';
        
        // Title suggestion
        if (autoFillData.suggested_title) {
            html += this.createSuggestionCard(
                'title',
                'Title Suggestion',
                autoFillData.suggested_title,
                autoFillData.overall_confidence,
                'write'
            );
        }
        
        // Description suggestion
        if (autoFillData.suggested_description) {
            html += this.createSuggestionCard(
                'description',
                'Description Suggestion',
                autoFillData.suggested_description,
                autoFillData.overall_confidence,
                'align left'
            );
        }
        
        // Category suggestion
        if (autoFillData.suggested_category) {
            const category = this.categories.find(c => c.id == autoFillData.suggested_category);
            const categoryName = category ? category.name : 'Unknown Category';
            
            html += this.createSuggestionCard(
                'category',
                'Category Suggestion',
                categoryName,
                autoFillData.overall_confidence,
                'tags'
            );
        }
        
        // Price suggestion
        if (autoFillData.suggested_price) {
            html += this.createSuggestionCard(
                'price',
                'Price Estimate',
                `€${autoFillData.suggested_price}`,
                autoFillData.overall_confidence,
                'euro sign'
            );
        }
        
        // Condition suggestion
        if (autoFillData.suggested_condition) {
            html += this.createSuggestionCard(
                'condition',
                'Condition Assessment',
                this.formatCondition(autoFillData.suggested_condition),
                autoFillData.overall_confidence,
                'star'
            );
        }
        
        html += '</div>';
        
        // Add detected objects and labels
        if (autoFillData.all_objects && Object.keys(autoFillData.all_objects).length > 0) {
            html += '<div style="margin-top: 2rem;"><h4>Detected Objects:</h4>';
            const topObjects = Object.entries(autoFillData.all_objects)
                .sort(([,a], [,b]) => b - a)
                .slice(0, 10);
            
            html += '<div class="ui labels">';
            topObjects.forEach(([object, confidence]) => {
                const opacity = Math.max(0.3, confidence);
                html += `<div class="ui label" style="opacity: ${opacity}">${object}</div>`;
            });
            html += '</div></div>';
        }
        
        this.suggestionsContainer.innerHTML = html;
        this.analysisSection.classList.add('visible');
        
        // Bind suggestion action events
        this.bindSuggestionEvents();
    }
    
    createSuggestionCard(type, title, value, confidence, icon) {
        const confidencePercent = Math.round(confidence * 100);
        const confidenceColor = confidence > 0.7 ? '#4caf50' : confidence > 0.4 ? '#ff9800' : '#f44336';
        
        return `
            <div class="eight wide column">
                <div class="ai-suggestion-card" data-type="${type}">
                    <h4><i class="${icon} icon"></i> ${title}</h4>
                    <p><strong>${value}</strong></p>
                    <div class="confidence-bar">
                        <div class="confidence-fill" style="width: ${confidencePercent}%; background: ${confidenceColor}"></div>
                    </div>
                    <small>Confidence: ${confidencePercent}%</small>
                    <div class="suggestion-actions">
                        <button class="btn-accept" data-type="${type}">
                            <i class="checkmark icon"></i> Accept
                        </button>
                        <button class="btn-reject" data-type="${type}">
                            <i class="close icon"></i> Reject
                        </button>
                        <button class="btn-modify" data-type="${type}">
                            <i class="edit icon"></i> Modify
                        </button>
                    </div>
                    <div class="manual-override" id="override-${type}">
                        <div class="ui input">
                            <input type="text" placeholder="Enter custom value" id="custom-${type}">
                        </div>
                        <button class="ui mini button" onclick="aiCreator.applyCustomValue('${type}')">Apply</button>
                    </div>
                </div>
            </div>
        `;
    }
    
    bindSuggestionEvents() {
        // Accept buttons
        document.querySelectorAll('.btn-accept').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const type = e.target.closest('[data-type]').dataset.type;
                this.acceptSuggestion(type);
            });
        });
        
        // Reject buttons
        document.querySelectorAll('.btn-reject').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const type = e.target.closest('[data-type]').dataset.type;
                this.rejectSuggestion(type);
            });
        });
        
        // Modify buttons
        document.querySelectorAll('.btn-modify').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const type = e.target.closest('[data-type]').dataset.type;
                this.showManualOverride(type);
            });
        });
    }
    
    acceptSuggestion(type) {
        const card = document.querySelector(`[data-type="${type}"]`);
        card.style.background = 'rgba(76, 175, 80, 0.2)';
        card.style.border = '2px solid #4caf50';
        
        // Update form field with suggested value
        this.applySuggestionToForm(type);
        
        this.showMessage(`${this.capitalize(type)} suggestion accepted`, 'success');
        
        // Add to accepted suggestions
        if (!this.acceptedSuggestions.includes(type)) {
            this.acceptedSuggestions.push(type);
        }
    }
    
    rejectSuggestion(type) {
        const card = document.querySelector(`[data-type="${type}"]`);
        card.style.background = 'rgba(244, 67, 54, 0.2)';
        card.style.border = '2px solid #f44336';
        
        this.showMessage(`${this.capitalize(type)} suggestion rejected`, 'info');
        
        // Remove from accepted suggestions
        this.acceptedSuggestions = this.acceptedSuggestions.filter(s => s !== type);
    }
    
    showManualOverride(type) {
        const override = document.getElementById(`override-${type}`);
        override.classList.add('visible');
        
        const input = document.getElementById(`custom-${type}`);
        input.focus();
    }
    
    applyCustomValue(type) {
        const input = document.getElementById(`custom-${type}`);
        const customValue = input.value.trim();
        
        if (!customValue) {
            this.showMessage('Please enter a custom value', 'warning');
            return;
        }
        
        // Apply to form
        this.applyValueToForm(type, customValue);
        
        // Update suggestion card
        const card = document.querySelector(`[data-type="${type}"]`);
        card.style.background = 'rgba(255, 193, 7, 0.2)';
        card.style.border = '2px solid #ffc107';
        
        // Hide override section
        const override = document.getElementById(`override-${type}`);
        override.classList.remove('visible');
        
        this.showMessage(`Custom ${type} applied`, 'success');
    }
    
    applySuggestionToForm(type) {
        const autoFillData = this.analysisResults.auto_fill_data;
        
        switch (type) {
            case 'title':
                document.getElementById('title-input').value = autoFillData.suggested_title;
                document.getElementById('title-ai-badge').style.display = 'inline-block';
                break;
            case 'description':
                document.getElementById('description-input').value = autoFillData.suggested_description;
                document.getElementById('description-ai-badge').style.display = 'inline-block';
                break;
            case 'category':
                $('#category-select').dropdown('set selected', autoFillData.suggested_category);
                document.getElementById('category-ai-badge').style.display = 'inline-block';
                break;
            case 'price':
                document.getElementById('price-input').value = autoFillData.suggested_price;
                document.getElementById('price-ai-badge').style.display = 'inline-block';
                break;
            case 'condition':
                $('#condition-select').dropdown('set selected', autoFillData.suggested_condition);
                document.getElementById('condition-ai-badge').style.display = 'inline-block';
                break;
        }
    }
    
    applyValueToForm(type, value) {
        switch (type) {
            case 'title':
                document.getElementById('title-input').value = value;
                break;
            case 'description':
                document.getElementById('description-input').value = value;
                break;
            case 'category':
                $('#category-select').dropdown('set selected', value);
                break;
            case 'price':
                document.getElementById('price-input').value = value;
                break;
            case 'condition':
                $('#condition-select').dropdown('set selected', value);
                break;
        }
    }
    
    prefillForm(data) {
        const article = data.article.article;
        const autoFillData = data.auto_fill_data;
        
        // Pre-fill form with AI suggestions
        if (autoFillData.suggested_title) {
            document.getElementById('title-input').value = autoFillData.suggested_title;
            document.getElementById('title-ai-badge').style.display = 'inline-block';
        }
        
        if (autoFillData.suggested_description) {
            document.getElementById('description-input').value = autoFillData.suggested_description;
            document.getElementById('description-ai-badge').style.display = 'inline-block';
        }
        
        if (autoFillData.suggested_category) {
            $('#category-select').dropdown('set selected', autoFillData.suggested_category);
            document.getElementById('category-ai-badge').style.display = 'inline-block';
        }
        
        if (autoFillData.suggested_price) {
            document.getElementById('price-input').value = autoFillData.suggested_price;
            document.getElementById('price-ai-badge').style.display = 'inline-block';
        }
        
        if (autoFillData.suggested_condition) {
            $('#condition-select').dropdown('set selected', autoFillData.suggested_condition);
            document.getElementById('condition-ai-badge').style.display = 'inline-block';
        }
    }
    
    showFormSection() {
        this.formSection.style.display = 'block';
        this.formSection.scrollIntoView({ behavior: 'smooth' });
        
        // Show publish button in header
        document.getElementById('publish-btn').style.display = 'block';
    }
    
    async submitArticle(e) {
        e.preventDefault();
        
        if (!this.validateForm()) return;
        
        this.showProcessing('Publishing article...');
        
        try {
            const formData = new FormData(this.articleForm);
            formData.append('status', 'active');
            
            const response = await this.apiRequest('PUT', `/api/v1/articles/${this.articleId}`, formData);
            
            if (response.success) {
                this.hideProcessing();
                $('#success-modal').modal('show');
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.hideProcessing();
            this.showMessage('Failed to publish article: ' + error.message, 'error');
        }
    }
    
    async saveDraft() {
        if (!this.articleId) {
            this.showMessage('No article to save', 'warning');
            return;
        }
        
        this.showProcessing('Saving draft...');
        
        try {
            const formData = new FormData(this.articleForm);
            formData.append('status', 'draft');
            
            const response = await this.apiRequest('PUT', `/api/v1/articles/${this.articleId}`, formData);
            
            if (response.success) {
                this.hideProcessing();
                this.showMessage('Draft saved successfully', 'success');
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            this.hideProcessing();
            this.showMessage('Failed to save draft: ' + error.message, 'error');
        }
    }
    
    validateForm() {
        const title = document.getElementById('title-input').value.trim();
        const description = document.getElementById('description-input').value.trim();
        const categoryId = $('#category-select').dropdown('get value');
        const price = document.getElementById('price-input').value;
        
        if (!title) {
            this.showMessage('Title is required', 'error');
            return false;
        }
        
        if (!description) {
            this.showMessage('Description is required', 'error');
            return false;
        }
        
        if (!categoryId) {
            this.showMessage('Category is required', 'error');
            return false;
        }
        
        if (!price || price < 0) {
            this.showMessage('Valid price is required', 'error');
            return false;
        }
        
        return true;
    }
    
    async loadCategories() {
        try {
            const response = await this.apiRequest('GET', '/api/v1/categories');
            if (response.success) {
                this.categories = response.data;
                this.populateCategoryDropdown(response.data);
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }
    
    populateCategoryDropdown(categories) {
        const select = document.getElementById('category-select');
        categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = category.name;
            select.appendChild(option);
        });
        
        // Refresh dropdown
        $('#category-select').dropdown('refresh');
    }
    
    setStep(step) {
        // Update step indicators
        for (let i = 1; i <= 3; i++) {
            const stepElement = document.getElementById(`step-${i}`);
            stepElement.classList.remove('active', 'completed');
            
            if (i < step) {
                stepElement.classList.add('completed');
            } else if (i === step) {
                stepElement.classList.add('active');
            }
        }
        
        this.currentStep = step;
    }
    
    showProcessing(message) {
        this.processingStatus.textContent = message;
        this.processingOverlay.classList.add('active');
    }
    
    hideProcessing() {
        this.processingOverlay.classList.remove('active');
    }
    
    showMessage(message, type) {
        // Create toast message
        const toast = document.createElement('div');
        toast.className = `ui ${type} message`;
        toast.innerHTML = `
            <i class="close icon"></i>
            <div class="header">${this.capitalize(type)}</div>
            <p>${message}</p>
        `;
        
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.right = '20px';
        toast.style.zIndex = '1001';
        toast.style.minWidth = '300px';
        
        document.body.appendChild(toast);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 5000);
        
        // Close button
        const closeBtn = toast.querySelector('.close.icon');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            });
        }
    }
    
    formatCondition(condition) {
        const conditions = {
            'new': 'New',
            'like_new': 'Like New',
            'good': 'Good',
            'fair': 'Fair',
            'poor': 'Poor'
        };
        return conditions[condition] || condition;
    }
    
    capitalize(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }
    
    viewArticle() {
        if (this.articleId) {
            window.location.href = `article.html?id=${this.articleId}`;
        }
    }
    
    createAnother() {
        window.location.reload();
    }
    
    async apiRequest(method, endpoint, data = null) {
        const config = {
            method: method,
            headers: {}
        };
        
        // Add auth token if available
        const token = localStorage.getItem('auth_token');
        if (token) {
            config.headers['Authorization'] = `Bearer ${token}`;
        }
        
        // Handle different data types
        if (data) {
            if (data instanceof FormData) {
                config.body = data;
            } else {
                config.headers['Content-Type'] = 'application/json';
                config.body = JSON.stringify(data);
            }
        }
        
        const response = await fetch(`/bazar/backend/api${endpoint}`, config);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.aiCreator = new AIArticleCreator();
});

// Make it available globally for inline event handlers
window.aiCreator = null;