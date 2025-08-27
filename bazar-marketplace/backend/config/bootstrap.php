<?php

/**
 * Bootstrap file for Bazar Marketplace
 * This file initializes the application and loads all necessary configurations
 */

// Start output buffering
ob_start();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set default timezone
date_default_timezone_set('Europe/Paris');

// Define application constants
define('APP_ROOT', dirname(__DIR__, 2));
define('BACKEND_ROOT', dirname(__DIR__));
define('PUBLIC_ROOT', APP_ROOT . '/public');
define('UPLOAD_ROOT', APP_ROOT . '/uploads');
define('LOG_ROOT', APP_ROOT . '/logs');

// Create necessary directories if they don't exist
$directories = [
    PUBLIC_ROOT,
    UPLOAD_ROOT,
    LOG_ROOT,
    UPLOAD_ROOT . '/articles',
    UPLOAD_ROOT . '/avatars',
    UPLOAD_ROOT . '/temp'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Load Composer autoloader
$autoloader = APP_ROOT . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    die('Composer autoloader not found. Please run: composer install');
}
require_once $autoloader;

// Load configuration
use Bazar\Config\Config;
Config::load();

// Set timezone from config
date_default_timezone_set(Config::get('app.timezone', 'Europe/Paris'));

// Configure error reporting based on environment
if (Config::isDevelopment()) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', LOG_ROOT . '/php_errors.log');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

// Set up session configuration
ini_set('session.cookie_httponly', Config::get('cookie.httponly') ? 1 : 0);
ini_set('session.cookie_secure', Config::get('cookie.secure') ? 1 : 0);
ini_set('session.cookie_samesite', Config::get('cookie.samesite', 'lax'));
ini_set('session.gc_maxlifetime', Config::get('security.session_lifetime', 7200));

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set up error and exception handlers
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    $error = [
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
        'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
    ];
    
    error_log(json_encode($error), 3, LOG_ROOT . '/errors.log');
    
    if (Config::isDevelopment()) {
        echo "<pre>Error: {$message} in {$file} on line {$line}</pre>";
    }
    
    return true;
});

set_exception_handler(function ($exception) {
    $error = [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ];
    
    error_log(json_encode($error), 3, LOG_ROOT . '/exceptions.log');
    
    if (Config::isDevelopment()) {
        echo "<pre>Uncaught Exception: " . $exception->getMessage() . "\n";
        echo "File: " . $exception->getFile() . " Line: " . $exception->getLine() . "\n";
        echo "Trace:\n" . $exception->getTraceAsString() . "</pre>";
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
});

// Set up CORS headers if needed
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $allowedOrigins = Config::get('cors.allowed_origins', []);
    if (in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins) || in_array('*', $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        header('Access-Control-Allow-Credentials: true');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: ' . implode(', ', Config::get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'])));
    header('Access-Control-Allow-Headers: ' . implode(', ', Config::get('cors.allowed_headers', ['Content-Type', 'Authorization', 'X-Requested-With'])));
    header('Access-Control-Max-Age: 86400');
    exit(0);
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

if (Config::get('app.env') === 'production') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Initialize database connection (lazy loading)
try {
    $db = \Bazar\Config\Database::getInstance();
} catch (Exception $e) {
    if (Config::isDevelopment()) {
        die('Database connection failed: ' . $e->getMessage());
    } else {
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(503);
        die(json_encode(['error' => 'Service temporarily unavailable']));
    }
}

// Helper functions
function config($key, $default = null) {
    return Config::get($key, $default);
}

function app_path($path = '') {
    return APP_ROOT . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
}

function public_path($path = '') {
    return PUBLIC_ROOT . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
}

function upload_path($path = '') {
    return UPLOAD_ROOT . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
}

function log_path($path = '') {
    return LOG_ROOT . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
}

function response_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function response_error($message, $status = 400, $details = null) {
    $response = ['error' => $message];
    if ($details && Config::isDevelopment()) {
        $response['details'] = $details;
    }
    response_json($response, $status);
}

function generate_uuid() {
    return \Ramsey\Uuid\Uuid::uuid4()->toString();
}

function validate_uuid($uuid) {
    return \Ramsey\Uuid\Uuid::isValid($uuid);
}

function sanitize_filename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return trim($filename, '._-');
}

function format_bytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $base = log($size, 1024);
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $units[floor($base)];
}

function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => Config::get('security.bcrypt_rounds', 12)]);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Log application start
error_log('Application initialized successfully', 3, LOG_ROOT . '/app.log');