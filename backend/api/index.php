<?php
/**
 * Main API Router for Bazar Marketplace
 * Handles all API requests and routes them to appropriate controllers
 */

require_once __DIR__ . '/../config/app.php';

/**
 * Simple router class
 */
class Router {
    private $routes = [];
    private $middleware = [];
    
    public function addRoute($method, $pattern, $handler, $middleware = []) {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }
    
    public function get($pattern, $handler, $middleware = []) {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }
    
    public function post($pattern, $handler, $middleware = []) {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }
    
    public function put($pattern, $handler, $middleware = []) {
        $this->addRoute('PUT', $pattern, $handler, $middleware);
    }
    
    public function delete($pattern, $handler, $middleware = []) {
        $this->addRoute('DELETE', $pattern, $handler, $middleware);
    }
    
    public function dispatch() {
        $method = Request::method();
        $uri = Request::uri();
        
        // Remove API prefix if present
        $uri = preg_replace('#^/bazar/backend/api#', '', $uri);
        $uri = preg_replace('#^/backend/api#', '', $uri);
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            $pattern = str_replace('/', '\/', $route['pattern']);
            $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^\/]+)', $pattern);
            $pattern = '/^' . $pattern . '$/';
            
            if (preg_match($pattern, $uri, $matches)) {
                // Extract route parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                // Apply middleware
                foreach ($route['middleware'] as $middlewareClass) {
                    if (class_exists($middlewareClass)) {
                        $middleware = new $middlewareClass();
                        $middleware->handle();
                    }
                }
                
                // Call handler
                if (is_array($route['handler'])) {
                    [$controllerClass, $method] = $route['handler'];
                    if (class_exists($controllerClass)) {
                        $controller = new $controllerClass();
                        if (method_exists($controller, $method)) {
                            return $controller->$method($params);
                        }
                    }
                } elseif (is_callable($route['handler'])) {
                    return call_user_func($route['handler'], $params);
                }
                
                Response::serverError('Handler not found');
            }
        }
        
        Response::notFound('API endpoint not found');
    }
}

// Initialize router
$router = new Router();

// Apply global rate limiting
RateLimit::middleware(100, 60); // 100 requests per minute

// Health check endpoint
$router->get('/health', function() {
    Response::success([
        'status' => 'healthy',
        'timestamp' => time(),
        'version' => '1.0.0'
    ]);
});

// Authentication routes
$router->post('/v1/auth/register', [UserController::class, 'register']);
$router->post('/v1/auth/login', [UserController::class, 'login']);
$router->post('/v1/auth/refresh', [UserController::class, 'refresh']);
$router->post('/v1/auth/logout', [UserController::class, 'logout'], ['AuthMiddleware']);
$router->post('/v1/auth/forgot-password', [UserController::class, 'forgotPassword']);
$router->post('/v1/auth/reset-password', [UserController::class, 'resetPassword']);
$router->post('/v1/auth/verify-email', [UserController::class, 'verifyEmail']);
$router->post('/v1/auth/resend-verification', [UserController::class, 'resendVerification']);

// OAuth routes
$router->get('/v1/auth/google', [OAuthController::class, 'googleRedirect']);
$router->post('/v1/auth/google/callback', [OAuthController::class, 'googleCallback']);

// User profile routes
$router->get('/v1/user/profile', [UserController::class, 'profile'], ['AuthMiddleware']);
$router->put('/v1/user/profile', [UserController::class, 'updateProfile'], ['AuthMiddleware']);
$router->post('/v1/user/avatar', [UserController::class, 'uploadAvatar'], ['AuthMiddleware']);
$router->delete('/v1/user/avatar', [UserController::class, 'deleteAvatar'], ['AuthMiddleware']);

// Article routes
$router->get('/v1/articles', [ArticleController::class, 'index']);
$router->get('/v1/articles/{id}', [ArticleController::class, 'show']);
$router->post('/v1/articles', [ArticleController::class, 'create'], ['AuthMiddleware']);
$router->put('/v1/articles/{id}', [ArticleController::class, 'update'], ['AuthMiddleware']);
$router->delete('/v1/articles/{id}', [ArticleController::class, 'delete'], ['AuthMiddleware']);
$router->post('/v1/articles/{id}/images', [ArticleController::class, 'uploadImages'], ['AuthMiddleware']);
$router->delete('/v1/articles/{id}/images/{imageId}', [ArticleController::class, 'deleteImage'], ['AuthMiddleware']);

// Search routes
$router->get('/v1/search', [SearchController::class, 'search']);
$router->get('/v1/search/suggestions', [SearchController::class, 'suggestions']);
$router->post('/v1/search/save', [SearchController::class, 'saveSearch'], ['AuthMiddleware']);
$router->get('/v1/search/saved', [SearchController::class, 'getSavedSearches'], ['AuthMiddleware']);
$router->delete('/v1/search/saved/{id}', [SearchController::class, 'deleteSavedSearch'], ['AuthMiddleware']);

// AI Analysis routes
$router->post('/v1/ai/analyze-image', [AIController::class, 'analyzeImage'], ['AuthMiddleware']);
$router->post('/v1/ai/analyze-images-batch', [AIController::class, 'analyzeImagesBatch'], ['AuthMiddleware']);
$router->get('/v1/ai/suggestions/{articleId}', [AIController::class, 'getSuggestions'], ['AuthMiddleware']);
$router->post('/v1/ai/suggestions/{suggestionId}/feedback', [AIController::class, 'submitFeedback'], ['AuthMiddleware']);
$router->post('/v1/ai/categorize-text', [AIController::class, 'categorizeText'], ['AuthMiddleware']);
$router->post('/v1/ai/estimate-price', [AIController::class, 'estimatePrice'], ['AuthMiddleware']);

// Category routes
$router->get('/v1/categories', [CategoryController::class, 'index']);
$router->get('/v1/categories/{id}', [CategoryController::class, 'show']);
$router->get('/v1/categories/{id}/articles', [CategoryController::class, 'getArticles']);

// Messaging routes
$router->get('/v1/conversations', [MessageController::class, 'index'], ['AuthMiddleware']);
$router->get('/v1/conversations/{id}', [MessageController::class, 'getConversation'], ['AuthMiddleware']);
$router->post('/v1/conversations/{id}/messages', [MessageController::class, 'sendMessage'], ['AuthMiddleware']);
$router->post('/v1/messages', [MessageController::class, 'sendMessage'], ['AuthMiddleware']);
$router->put('/v1/messages/{id}/read', [MessageController::class, 'markAsRead'], ['AuthMiddleware']);
$router->put('/v1/messages/{id}', [MessageController::class, 'editMessage'], ['AuthMiddleware']);
$router->delete('/v1/messages/{id}', [MessageController::class, 'deleteMessage'], ['AuthMiddleware']);
$router->get('/v1/conversations/search', [MessageController::class, 'searchMessages'], ['AuthMiddleware']);
$router->get('/v1/messages/filters', [MessageController::class, 'getMessageFilters'], ['AuthMiddleware']);
$router->get('/v1/conversations/{id}/export', [MessageController::class, 'exportMessages'], ['AuthMiddleware']);
$router->post('/v1/conversations/{id}/typing', [MessageController::class, 'updateTypingStatus'], ['AuthMiddleware']);
$router->post('/v1/conversations/{id}/block', [MessageController::class, 'blockConversation'], ['AuthMiddleware']);
$router->post('/v1/conversations/{id}/archive', [MessageController::class, 'archiveConversation'], ['AuthMiddleware']);
$router->get('/v1/conversations/{id}/stats', [MessageController::class, 'getConversationStats'], ['AuthMiddleware']);
$router->post('/v1/messages/{id}/reactions', [MessageController::class, 'addReaction'], ['AuthMiddleware']);
$router->delete('/v1/messages/{id}/reactions', [MessageController::class, 'removeReaction'], ['AuthMiddleware']);

// Message attachment routes
$router->post('/v1/conversations/{id}/attachments', [MessageAttachmentController::class, 'upload'], ['AuthMiddleware']);
$router->post('/v1/messages/attachments', [MessageAttachmentController::class, 'upload'], ['AuthMiddleware']);
$router->delete('/v1/attachments/{id}', [MessageAttachmentController::class, 'delete'], ['AuthMiddleware']);

// Real-time messaging routes
$router->get('/v1/messages/stream', [MessageController::class, 'streamEvents'], ['AuthMiddleware']);
$router->post('/v1/push/subscribe', [MessageController::class, 'subscribePush'], ['AuthMiddleware']);
$router->delete('/v1/push/subscribe', [MessageController::class, 'unsubscribePush'], ['AuthMiddleware']);
$router->post('/v1/push/test', [MessageController::class, 'testPushNotification'], ['AuthMiddleware']);

// Notification settings routes  
$router->get('/v1/notifications/settings', [NotificationController::class, 'getSettings'], ['AuthMiddleware']);
$router->put('/v1/notifications/settings', [NotificationController::class, 'updateSettings'], ['AuthMiddleware']);

// Favorites routes
$router->get('/v1/favorites', [FavoriteController::class, 'index'], ['AuthMiddleware']);
$router->post('/v1/favorites', [FavoriteController::class, 'add'], ['AuthMiddleware']);
$router->delete('/v1/favorites/{articleId}', [FavoriteController::class, 'remove'], ['AuthMiddleware']);

// Rating routes
$router->post('/v1/ratings', [RatingController::class, 'create'], ['AuthMiddleware']);
$router->get('/v1/ratings/user/{userId}', [RatingController::class, 'getUserRatings']);

// Admin routes
$router->get('/v1/admin/dashboard', [AdminController::class, 'dashboard'], ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/v1/admin/users', [AdminController::class, 'getUsers'], ['AuthMiddleware', 'AdminMiddleware']);
$router->put('/v1/admin/users/{id}', [AdminController::class, 'updateUser'], ['AuthMiddleware', 'AdminMiddleware']);
$router->delete('/v1/admin/users/{id}', [AdminController::class, 'deleteUser'], ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/v1/admin/articles', [AdminController::class, 'getArticles'], ['AuthMiddleware', 'AdminMiddleware']);
$router->put('/v1/admin/articles/{id}', [AdminController::class, 'moderateArticle'], ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/v1/admin/ai/performance', [AdminController::class, 'getAIPerformance'], ['AuthMiddleware', 'AdminMiddleware']);
$router->put('/v1/admin/ai/models/{id}', [AdminController::class, 'updateAIModel'], ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/v1/admin/logs', [AdminController::class, 'getLogs'], ['AuthMiddleware', 'AdminMiddleware']);

// File upload routes
$router->post('/v1/upload/image', [UploadController::class, 'uploadImage'], ['AuthMiddleware']);
$router->post('/v1/upload/images', [UploadController::class, 'uploadImages'], ['AuthMiddleware']);
$router->delete('/v1/upload/{filename}', [UploadController::class, 'deleteFile'], ['AuthMiddleware']);

// CMS and Legal Pages Routes
$router->get('/v1/cms/pages', [CMSController::class, 'getPages']);
$router->get('/v1/cms/pages/{id}', [CMSController::class, 'getPage']);
$router->post('/v1/cms/pages', [CMSController::class, 'createPage'], ['AuthMiddleware', 'AdminMiddleware']);
$router->put('/v1/cms/pages/{id}', [CMSController::class, 'updatePage'], ['AuthMiddleware', 'AdminMiddleware']);
$router->delete('/v1/cms/pages/{id}', [CMSController::class, 'deletePage'], ['AuthMiddleware', 'AdminMiddleware']);

// Public legal pages (no auth required)
$router->get('/v1/legal/privacy', function() {
    $controller = new CMSController();
    return $controller->getPage(['slug' => 'datenschutz']);
});
$router->get('/v1/legal/terms', function() {
    $controller = new CMSController();
    return $controller->getPage(['slug' => 'agb']);
});
$router->get('/v1/legal/imprint', function() {
    $controller = new CMSController();
    return $controller->getPage(['slug' => 'impressum']);
});
$router->get('/v1/legal/cancellation', function() {
    $controller = new CMSController();
    return $controller->getPage(['slug' => 'widerrufsrecht']);
});

// FAQ Routes
$router->get('/v1/faq', [CMSController::class, 'getFAQ']);
$router->get('/v1/faq/categories/{id}', [CMSController::class, 'getFAQ']);
$router->post('/v1/faq/{id}/feedback', [CMSController::class, 'submitFAQFeedback']);

// News and Announcements Routes
$router->get('/v1/news', [CMSController::class, 'getNews']);
$router->get('/v1/news/{slug}', [CMSController::class, 'getNewsArticle']);

// Cookie Consent Routes (GDPR Compliance)
$router->get('/v1/cookies/consent', [CookieConsentController::class, 'getConsentStatus']);
$router->post('/v1/cookies/consent', [CookieConsentController::class, 'saveConsent']);
$router->put('/v1/cookies/consent', [CookieConsentController::class, 'updateConsent']);
$router->delete('/v1/cookies/consent', [CookieConsentController::class, 'withdrawConsent']);
$router->get('/v1/cookies/audit', [CookieConsentController::class, 'getConsentAudit'], ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/v1/cookies/stats', [CookieConsentController::class, 'getConsentStats'], ['AuthMiddleware', 'AdminMiddleware']);

// Support Ticket System Routes
$router->post('/v1/support/tickets', [SupportController::class, 'createTicket']);
$router->get('/v1/support/tickets', [SupportController::class, 'getTickets'], ['AuthMiddleware']);
$router->get('/v1/support/tickets/{id}', [SupportController::class, 'getTicket'], ['AuthMiddleware']);
$router->post('/v1/support/tickets/{id}/messages', [SupportController::class, 'addTicketMessage'], ['AuthMiddleware']);
$router->put('/v1/support/tickets/{id}/status', [SupportController::class, 'updateTicketStatus'], ['AuthMiddleware', 'AdminMiddleware']);
$router->put('/v1/support/tickets/{id}/assign', [SupportController::class, 'assignTicket'], ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/v1/support/stats', [SupportController::class, 'getSupportStats'], ['AuthMiddleware', 'AdminMiddleware']);

// Contact Form Routes
$router->post('/v1/contact', [SupportController::class, 'submitContactForm']);

// GDPR Data Protection Routes
$router->post('/v1/gdpr/data-request', [GDPRController::class, 'submitDataRequest']);
$router->post('/v1/gdpr/verify/{token}', [GDPRController::class, 'verifyDataRequest']);
$router->get('/v1/gdpr/requests', [GDPRController::class, 'getDataRequests'], ['AuthMiddleware', 'AdminMiddleware']);
$router->post('/v1/gdpr/requests/{id}/process', [GDPRController::class, 'processDataRequest'], ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/v1/gdpr/requests/{id}/data', [GDPRController::class, 'getUserData'], ['AuthMiddleware', 'AdminMiddleware']);
$router->post('/v1/gdpr/consent', [GDPRController::class, 'recordConsent']);
$router->get('/v1/gdpr/consent/history', [GDPRController::class, 'getConsentHistory'], ['AuthMiddleware']);

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    Logger::error("PHP Error: $message", [
        'severity' => $severity,
        'file' => $file,
        'line' => $line
    ]);
    
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
});

set_exception_handler(function($exception) {
    Logger::error("Unhandled exception: " . $exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    if (($_ENV['APP_ENV'] ?? 'development') === 'development') {
        Response::serverError($exception->getMessage());
    } else {
        Response::serverError('An unexpected error occurred');
    }
});

// Dispatch the request
try {
    $router->dispatch();
} catch (Exception $e) {
    Logger::error("Router exception: " . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    Response::serverError('An error occurred processing your request');
}