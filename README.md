# Bazar AI-Powered Marketplace

A modern, AI-integrated marketplace platform similar to willhaben.at, featuring intelligent article creation through image recognition and machine learning.

## 🤖 AI Integration Features

### Image Recognition System
- **Object Detection & Classification**: Automatically identifies items in uploaded images
- **Category Suggestion**: AI suggests the most appropriate category based on image content
- **Title Generation**: Creates relevant titles from detected objects
- **Description Creation**: Generates detailed descriptions with key features
- **Price Estimation**: Estimates market value based on similar items and condition
- **Condition Assessment**: Evaluates item condition (new, used, damaged) from image quality

### AI Service Integration
- **Primary**: Google Vision API integration for high-accuracy analysis
- **Fallback**: Self-hosted pattern recognition using GD library
- **Hybrid Approach**: Combines multiple AI sources for best results
- **Preprocessing Pipeline**: Image optimization before AI analysis
- **Batch Processing**: Background processing for improved performance

### Auto-Fill Workflow (2-3 Click Article Creation)
1. **Step 1**: User uploads 1-5 images via drag-and-drop
2. **Step 2**: AI analyzes images in real-time and suggests article details
3. **Step 3**: User reviews and publishes with minimal manual input

### Performance & Accuracy
- **Image Optimization**: Automatic resizing and WebP conversion
- **Caching System**: Redis + file-based caching for similar images
- **Confidence Scoring**: AI provides confidence levels for all suggestions
- **Learning Capability**: System improves from user feedback
- **A/B Testing Framework**: Built-in performance tracking

## 🏗️ Architecture

### Backend Structure
```
backend/
├── api/              # RESTful API endpoints
│   └── index.php     # Main router with AI endpoints
├── controllers/      # Request handlers
│   ├── AIController.php       # AI analysis endpoints
│   └── ArticleController.php  # Article CRUD with AI auto-fill
├── services/         # Business logic
│   ├── AIService.php              # Core AI integration
│   ├── ImageProcessingService.php # Image optimization
│   ├── ConfidenceCalculator.php   # AI confidence scoring
│   ├── CacheService.php           # Caching layer
│   └── BatchProcessingService.php # Background processing
├── models/           # Data models
│   ├── Article.php        # Article model with AI features
│   ├── ArticleImage.php   # Image model with AI analysis
│   └── AISuggestion.php   # AI suggestions model
├── middleware/       # Request middleware
└── config/          # Configuration files
```

### Database Schema (AI-Enhanced)
- **articles**: Core article data with AI confidence scores
- **article_images**: Image storage with AI analysis results
- **ai_suggestions**: AI-generated suggestions with user feedback
- **ai_processing_queue**: Background processing queue
- **ai_models**: Configuration for different AI providers
- **price_history**: Historical data for ML price estimation

### Frontend Features
- **Drag-and-Drop Upload**: Intuitive image upload interface
- **Real-time Analysis**: Live AI feedback during upload
- **Suggestion Interface**: Visual confidence indicators
- **Manual Override**: Easy editing of AI suggestions
- **Progress Indicators**: Clear workflow steps

## 🚀 API Endpoints

### AI Analysis
```http
POST /api/v1/ai/analyze-image
POST /api/v1/ai/analyze-images-batch
GET  /api/v1/ai/suggestions/{articleId}
POST /api/v1/ai/suggestions/{suggestionId}/feedback
POST /api/v1/ai/categorize-text
POST /api/v1/ai/estimate-price
```

### Articles with AI Auto-fill
```http
POST /api/v1/articles (with auto_fill=true)
PUT  /api/v1/articles/{id}
GET  /api/v1/articles/{id}
GET  /api/v1/articles (with AI-enhanced search)
```

## ⚙️ Configuration

### Environment Setup
1. Copy `.env.example` to `.env`
2. Configure database connection
3. Add Google Vision API key (optional)
4. Set up Redis for caching (optional)

### AI Configuration
```env
# Google Vision API
GOOGLE_VISION_API_KEY=your-api-key-here

# AI Processing
AI_BATCH_SIZE=10
AI_PROCESSING_TIMEOUT=300
AI_CACHE_TTL=3600

# Performance
ENABLE_REDIS_CACHE=true
ENABLE_FILE_CACHE=true
```

### Database Setup
```bash
# Import database schema
mysql -u root -p < backend/config/database.sql

# Or use phpMyAdmin to import the SQL file
```

## 🔄 Batch Processing

### CLI Processing Tool
```bash
# Process pending items
php backend/cli/batch_processor.php process 20

# Run as daemon
php backend/cli/batch_processor.php daemon 60 7200

# Show statistics
php backend/cli/batch_processor.php stats

# Clean up old items
php backend/cli/batch_processor.php cleanup 14
```

### Background Services
The system includes a robust background processing system for:
- Image analysis queuing
- Batch AI operations
- Cache warming
- Performance optimization

## 📊 Confidence Scoring

### AI Confidence Factors
- **Object Detection**: Clarity and consistency of detected objects
- **Category Matching**: Keyword relevance to category databases
- **Historical Accuracy**: Performance of similar suggestions
- **User Feedback**: Learning from acceptance/rejection patterns

### Confidence Levels
- **90%+**: Very High (green) - High accuracy, minimal review needed
- **70-89%**: High (yellow) - Good accuracy, quick review recommended
- **50-69%**: Medium (orange) - Moderate accuracy, review required
- **<50%**: Low (red) - Low accuracy, manual input recommended

## 🎯 User Experience

### 2-3 Click Workflow
1. **Upload Images**: Drag & drop up to 5 images
2. **AI Analysis**: Automatic processing with real-time feedback
3. **Review & Publish**: Quick review of AI suggestions and publish

### Manual Override Options
- **Accept**: Use AI suggestion as-is
- **Reject**: Ignore AI suggestion
- **Modify**: Edit AI suggestion with custom value
- **Confidence Indicators**: Visual feedback on suggestion quality

## 🔧 Technical Features

### Image Processing Pipeline
1. **Upload Validation**: File type, size, and security checks
2. **Image Optimization**: Resize, compress, format conversion
3. **WebP Generation**: Modern format support
4. **Thumbnail Creation**: Multiple size variants
5. **AI Analysis**: Object detection and classification
6. **Caching**: Store results for similar images

### Caching Strategy
- **Redis Primary**: High-performance in-memory cache
- **File Fallback**: Disk-based cache when Redis unavailable
- **Smart Invalidation**: Context-aware cache clearing
- **Cache Warming**: Preload frequently accessed data

### Security & Performance
- **Rate Limiting**: API request throttling
- **Input Validation**: Comprehensive data sanitization
- **Error Handling**: Graceful degradation
- **Logging**: Detailed operation tracking
- **Performance Monitoring**: Built-in metrics

## 📈 Monitoring & Analytics

### AI Performance Tracking
- Success rates by suggestion type
- User feedback patterns
- Processing time metrics
- Cache hit/miss ratios
- Error rates and recovery

### Business Intelligence
- Category suggestion accuracy
- Price estimation performance
- User adoption of AI features
- Time saved through automation

## 🔮 Future Enhancements

### Planned AI Features
- **Advanced ML Models**: Custom-trained models for specific categories
- **Multi-language Support**: AI suggestions in multiple languages
- **Similarity Search**: Find similar items using AI
- **Trend Analysis**: Market trend prediction
- **Automated Moderation**: AI-powered content moderation

### Scalability Improvements
- **Microservices Architecture**: Separate AI processing services
- **Kubernetes Deployment**: Container orchestration
- **Load Balancing**: Distributed AI processing
- **Edge Computing**: Regional AI processing nodes

## 📝 Development

### Getting Started
1. Clone the repository
2. Set up XAMPP/LAMP environment
3. Import database schema
4. Configure environment variables
5. Access `/bazar/frontend/pages/create-article.html`

### Testing AI Features
1. Upload test images through the interface
2. Review AI suggestions and confidence scores
3. Test manual override functionality
4. Monitor processing through CLI tools

### Contributing
This AI integration system provides a foundation for intelligent marketplace automation. The modular design allows for easy extension and improvement of AI capabilities.

---

**Note**: This is a comprehensive AI integration system designed for production use. Proper API keys, database setup, and environment configuration are required for full functionality.