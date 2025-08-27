<?php
/**
 * Admin API Router
 * Handles all admin panel API endpoints
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../controllers/AdminController.php';
require_once __DIR__ . '/../controllers/AdminAuthController.php';
require_once __DIR__ . '/../controllers/AdminUserController.php';
require_once __DIR__ . '/../controllers/AdminArticleController.php';
require_once __DIR__ . '/../controllers/AdminReportController.php';
require_once __DIR__ . '/../controllers/AdminSettingsController.php';

// Initialize rate limiting for admin endpoints
RateLimit::middleware(120, 60); // 120 requests per minute

// Parse the request URI
$uri = Request::uri();
$method = Request::method();

// Remove /backend/api/admin prefix
$path = str_replace('/backend/api/admin', '', $uri);
$path = rtrim($path, '/');

// Admin API Routes
try {
    switch (true) {
        // Authentication routes (no auth required)
        case $path === '/auth/login' && $method === 'POST':
            $controller = new AdminAuthController();
            $controller->login();
            break;
            
        case $path === '/auth/2fa/verify' && $method === 'POST':
            $controller = new AdminAuthController();
            $controller->verify2FA();
            break;
            
        case $path === '/auth/logout' && $method === 'POST':
            $controller = new AdminAuthController();
            $controller->logout();
            break;
            
        // Authenticated admin routes
        case $path === '/auth/me' && $method === 'GET':
            $controller = new AdminAuthController();
            $controller->me();
            break;
            
        case $path === '/auth/2fa/setup' && $method === 'POST':
            $controller = new AdminAuthController();
            $controller->setup2FA();
            break;
            
        case $path === '/auth/2fa/enable' && $method === 'POST':
            $controller = new AdminAuthController();
            $controller->enable2FA();
            break;
            
        case $path === '/auth/2fa/disable' && $method === 'POST':
            $controller = new AdminAuthController();
            $controller->disable2FA();
            break;
            
        // Dashboard routes
        case $path === '/dashboard/stats' && $method === 'GET':
            $controller = new AdminController();
            $controller->getDashboardStats();
            break;
            
        case $path === '/notifications' && $method === 'GET':
            $controller = new AdminController();
            $controller->getNotifications();
            break;
            
        case $path === '/notifications/read' && $method === 'POST':
            $controller = new AdminController();
            $controller->markNotificationRead();
            break;
            
        // User management routes
        case $path === '/users' && $method === 'GET':
            $controller = new AdminUserController();
            $controller->getUsers();
            break;
            
        case $path === '/users/details' && $method === 'GET':
            $controller = new AdminUserController();
            $controller->getUserDetails();
            break;
            
        case $path === '/users/update' && $method === 'PUT':
            $controller = new AdminUserController();
            $controller->updateUser();
            break;
            
        case $path === '/users/status' && $method === 'PUT':
            $controller = new AdminUserController();
            $controller->updateUserStatus();
            break;
            
        case $path === '/users/delete' && $method === 'DELETE':
            $controller = new AdminUserController();
            $controller->deleteUser();
            break;
            
        case $path === '/users/bulk' && $method === 'POST':
            $controller = new AdminUserController();
            $controller->bulkOperations();
            break;
            
        // Article management routes
        case $path === '/articles' && $method === 'GET':
            $controller = new AdminArticleController();
            $controller->getArticles();
            break;
            
        case $path === '/articles/details' && $method === 'GET':
            $controller = new AdminArticleController();
            $controller->getArticleDetails();
            break;
            
        case $path === '/articles/moderate' && $method === 'POST':
            $controller = new AdminArticleController();
            $controller->moderateArticle();
            break;
            
        case $path === '/articles/bulk' && $method === 'POST':
            $controller = new AdminArticleController();
            $controller->bulkOperations();
            break;
            
        case $path === '/articles/queue' && $method === 'GET':
            $controller = new AdminArticleController();
            $controller->getModerationQueue();
            break;
            
        case $path === '/articles/statistics' && $method === 'GET':
            $controller = new AdminArticleController();
            $controller->getArticleStatistics();
            break;
            
        // Report management routes
        case $path === '/reports' && $method === 'GET':
            $controller = new AdminReportController();
            $controller->getReports();
            break;
            
        case $path === '/reports/details' && $method === 'GET':
            $controller = new AdminReportController();
            $controller->getReportDetails();
            break;
            
        case $path === '/reports/handle' && $method === 'POST':
            $controller = new AdminReportController();
            $controller->handleReport();
            break;
            
        case $path === '/reports/bulk' && $method === 'POST':
            $controller = new AdminReportController();
            $controller->bulkHandleReports();
            break;
            
        case $path === '/reports/statistics' && $method === 'GET':
            $controller = new AdminReportController();
            $controller->getReportStatistics();
            break;
            
        // Settings management routes
        case $path === '/settings' && $method === 'GET':
            $controller = new AdminSettingsController();
            $controller->getSettings();
            break;
            
        case $path === '/settings/update' && $method === 'PUT':
            $controller = new AdminSettingsController();
            $controller->updateSetting();
            break;
            
        case $path === '/settings/create' && $method === 'POST':
            $controller = new AdminSettingsController();
            $controller->createSetting();
            break;
            
        case $path === '/settings/delete' && $method === 'DELETE':
            $controller = new AdminSettingsController();
            $controller->deleteSetting();
            break;
            
        case $path === '/settings/system-info' && $method === 'GET':
            $controller = new AdminSettingsController();
            $controller->getSystemInfo();
            break;
            
        // Email template routes
        case $path === '/email-templates' && $method === 'GET':
            $controller = new AdminSettingsController();
            $controller->getEmailTemplates();
            break;
            
        case $path === '/email-templates/update' && $method === 'PUT':
            $controller = new AdminSettingsController();
            $controller->updateEmailTemplate();
            break;
            
        case $path === '/email-templates/create' && $method === 'POST':
            $controller = new AdminSettingsController();
            $controller->createEmailTemplate();
            break;
            
        case $path === '/email-templates/test' && $method === 'POST':
            $controller = new AdminSettingsController();
            $controller->testEmailTemplate();
            break;
            
        // Analytics and reports routes
        case $path === '/analytics/overview' && $method === 'GET':
            $controller = new AdminAnalyticsController();
            $controller->getOverview();
            break;
            
        case $path === '/analytics/users' && $method === 'GET':
            $controller = new AdminAnalyticsController();
            $controller->getUserAnalytics();
            break;
            
        case $path === '/analytics/articles' && $method === 'GET':
            $controller = new AdminAnalyticsController();
            $controller->getArticleAnalytics();
            break;
            
        case $path === '/analytics/financial' && $method === 'GET':
            $controller = new AdminAnalyticsController();
            $controller->getFinancialAnalytics();
            break;
            
        case $path === '/analytics/export' && $method === 'POST':
            $controller = new AdminAnalyticsController();
            $controller->exportData();
            break;
            
        // Audit logs routes
        case $path === '/logs/admin' && $method === 'GET':
            $controller = new AdminAuditController();
            $controller->getAdminLogs();
            break;
            
        case $path === '/logs/system' && $method === 'GET':
            $controller = new AdminAuditController();
            $controller->getSystemLogs();
            break;
            
        case $path === '/logs/security' && $method === 'GET':
            $controller = new AdminAuditController();
            $controller->getSecurityLogs();
            break;
            
        // Backup and maintenance routes
        case $path === '/maintenance/backup' && $method === 'POST':
            $controller = new AdminMaintenanceController();
            $controller->createBackup();
            break;
            
        case $path === '/maintenance/backups' && $method === 'GET':
            $controller = new AdminMaintenanceController();
            $controller->getBackups();
            break;
            
        case $path === '/maintenance/cleanup' && $method === 'POST':
            $controller = new AdminMaintenanceController();
            $controller->cleanupFiles();
            break;
            
        case $path === '/maintenance/cache/clear' && $method === 'POST':
            $controller = new AdminMaintenanceController();
            $controller->clearCache();
            break;
            
        // File management routes
        case $path === '/files/upload' && $method === 'POST':
            $controller = new AdminFileController();
            $controller->uploadFile();
            break;
            
        case $path === '/files/list' && $method === 'GET':
            $controller = new AdminFileController();
            $controller->listFiles();
            break;
            
        case $path === '/files/delete' && $method === 'DELETE':
            $controller = new AdminFileController();
            $controller->deleteFile();
            break;
            
        // AI and content management
        case $path === '/ai/queue' && $method === 'GET':
            $controller = new AdminAIController();
            $controller->getProcessingQueue();
            break;
            
        case $path === '/ai/reprocess' && $method === 'POST':
            $controller = new AdminAIController();
            $controller->reprocessContent();
            break;
            
        case $path === '/ai/models' && $method === 'GET':
            $controller = new AdminAIController();
            $controller->getAIModels();
            break;
            
        case $path === '/ai/models/update' && $method === 'PUT':
            $controller = new AdminAIController();
            $controller->updateAIModel();
            break;
            
        // Default: 404 Not Found
        default:
            Response::notFound('Admin API endpoint not found');
            break;
    }
    
} catch (Exception $e) {
    Logger::error('Admin API Error', [
        'path' => $path,
        'method' => $method,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Don't expose internal errors in production
    if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
        Response::serverError('An error occurred processing your request');
    } else {
        Response::serverError($e->getMessage());
    }
}