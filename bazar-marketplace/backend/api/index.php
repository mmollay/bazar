<?php

/**
 * Bazar Marketplace API Entry Point
 * This file handles all API requests and routes them to appropriate controllers
 */

require_once dirname(__DIR__) . '/config/bootstrap.php';

use Bazar\Config\Config;

// Set content type for API responses
header('Content-Type: application/json; charset=utf-8');

// Get the request method and URI
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove /api prefix if present
$uri = preg_replace('/^\/api/', '', $uri);
$uri = trim($uri, '/');

// Parse the route
$segments = $uri ? explode('/', $uri) : [];
$resource = $segments[0] ?? 'health';
$action = $segments[1] ?? null;
$id = $segments[2] ?? null;

// Basic routing logic
try {
    switch ($resource) {
        case 'health':
            handleHealthCheck();
            break;
            
        case 'auth':
            handleAuth($action, $method);
            break;
            
        case 'users':
            handleUsers($action, $id, $method);
            break;
            
        case 'articles':
            handleArticles($action, $id, $method);
            break;
            
        case 'categories':
            handleCategories($action, $id, $method);
            break;
            
        case 'messages':
            handleMessages($action, $id, $method);
            break;
            
        case 'favorites':
            handleFavorites($action, $id, $method);
            break;
            
        case 'search':
            handleSearch($action, $method);
            break;
            
        default:
            response_error('Endpoint not found', 404);
    }
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    if (Config::isDevelopment()) {
        response_error('Internal Server Error', 500, [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        response_error('Internal Server Error', 500);
    }
}

/**
 * Handle health check endpoint
 */
function handleHealthCheck() {
    $health = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'version' => '1.0.0',
        'environment' => Config::get('app.env'),
        'services' => []
    ];
    
    // Check database connection
    try {
        $db = \Bazar\Config\Database::getInstance();
        $stmt = $db->query('SELECT 1');
        $health['services']['database'] = 'healthy';
    } catch (Exception $e) {
        $health['services']['database'] = 'unhealthy';
        $health['status'] = 'unhealthy';
    }
    
    // Check uploads directory
    if (is_writable(UPLOAD_ROOT)) {
        $health['services']['storage'] = 'healthy';
    } else {
        $health['services']['storage'] = 'unhealthy';
        $health['status'] = 'unhealthy';
    }
    
    $status = $health['status'] === 'healthy' ? 200 : 503;
    response_json($health, $status);
}

/**
 * Handle authentication endpoints
 */
function handleAuth($action, $method) {
    switch ($action) {
        case 'login':
            if ($method === 'POST') {
                response_json(['message' => 'Login endpoint - to be implemented']);
            }
            break;
            
        case 'register':
            if ($method === 'POST') {
                response_json(['message' => 'Register endpoint - to be implemented']);
            }
            break;
            
        case 'logout':
            if ($method === 'POST') {
                response_json(['message' => 'Logout endpoint - to be implemented']);
            }
            break;
            
        case 'refresh':
            if ($method === 'POST') {
                response_json(['message' => 'Token refresh endpoint - to be implemented']);
            }
            break;
            
        case 'oauth':
            if ($method === 'POST') {
                response_json(['message' => 'OAuth endpoint - to be implemented']);
            }
            break;
            
        default:
            response_error('Authentication endpoint not found', 404);
    }
}

/**
 * Handle user endpoints
 */
function handleUsers($action, $id, $method) {
    switch ($method) {
        case 'GET':
            if ($id) {
                response_json(['message' => "Get user {$id} - to be implemented"]);
            } else {
                response_json(['message' => 'Get users list - to be implemented']);
            }
            break;
            
        case 'POST':
            response_json(['message' => 'Create user - to be implemented']);
            break;
            
        case 'PUT':
        case 'PATCH':
            if ($id) {
                response_json(['message' => "Update user {$id} - to be implemented"]);
            } else {
                response_error('User ID required for update', 400);
            }
            break;
            
        case 'DELETE':
            if ($id) {
                response_json(['message' => "Delete user {$id} - to be implemented"]);
            } else {
                response_error('User ID required for deletion', 400);
            }
            break;
            
        default:
            response_error('Method not allowed', 405);
    }
}

/**
 * Handle article endpoints
 */
function handleArticles($action, $id, $method) {
    switch ($method) {
        case 'GET':
            if ($id) {
                response_json(['message' => "Get article {$id} - to be implemented"]);
            } else {
                response_json(['message' => 'Get articles list - to be implemented']);
            }
            break;
            
        case 'POST':
            response_json(['message' => 'Create article - to be implemented']);
            break;
            
        case 'PUT':
        case 'PATCH':
            if ($id) {
                response_json(['message' => "Update article {$id} - to be implemented"]);
            } else {
                response_error('Article ID required for update', 400);
            }
            break;
            
        case 'DELETE':
            if ($id) {
                response_json(['message' => "Delete article {$id} - to be implemented"]);
            } else {
                response_error('Article ID required for deletion', 400);
            }
            break;
            
        default:
            response_error('Method not allowed', 405);
    }
}

/**
 * Handle category endpoints
 */
function handleCategories($action, $id, $method) {
    switch ($method) {
        case 'GET':
            if ($id) {
                response_json(['message' => "Get category {$id} - to be implemented"]);
            } else {
                response_json(['message' => 'Get categories list - to be implemented']);
            }
            break;
            
        default:
            response_error('Method not allowed', 405);
    }
}

/**
 * Handle message endpoints
 */
function handleMessages($action, $id, $method) {
    switch ($method) {
        case 'GET':
            response_json(['message' => 'Get messages - to be implemented']);
            break;
            
        case 'POST':
            response_json(['message' => 'Send message - to be implemented']);
            break;
            
        default:
            response_error('Method not allowed', 405);
    }
}

/**
 * Handle favorite endpoints
 */
function handleFavorites($action, $id, $method) {
    switch ($method) {
        case 'GET':
            response_json(['message' => 'Get favorites - to be implemented']);
            break;
            
        case 'POST':
            response_json(['message' => 'Add to favorites - to be implemented']);
            break;
            
        case 'DELETE':
            response_json(['message' => 'Remove from favorites - to be implemented']);
            break;
            
        default:
            response_error('Method not allowed', 405);
    }
}

/**
 * Handle search endpoints
 */
function handleSearch($action, $method) {
    if ($method === 'GET') {
        response_json(['message' => 'Search articles - to be implemented']);
    } else {
        response_error('Method not allowed', 405);
    }
}