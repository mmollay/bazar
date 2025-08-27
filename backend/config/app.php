<?php
/**
 * Application configuration and bootstrap
 */

// Load environment variables
if (file_exists(__DIR__ . '/../../.env')) {
    $env = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            putenv($line);
            list($key, $value) = explode('=', $line, 2);
            $_ENV[$key] = $value;
        }
    }
}

// Error reporting based on environment
$environment = $_ENV['APP_ENV'] ?? 'development';
if ($environment === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Vienna');

// CORS settings
function handleCORS() {
    $allowedOrigins = explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:3000');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, $allowedOrigins) || $_ENV['APP_ENV'] === 'development') {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: true");
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// Security headers
function setSecurityHeaders() {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;");
}

/**
 * Response helper class
 */
class Response {
    public static function json($data, $status = 200, $headers = []) {
        http_response_code($status);
        header('Content-Type: application/json');
        
        foreach ($headers as $key => $value) {
            header("{$key}: {$value}");
        }
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
    
    public static function success($data = [], $message = 'Success') {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }
    
    public static function error($message, $status = 400, $errors = []) {
        self::json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $status);
    }
    
    public static function unauthorized($message = 'Unauthorized') {
        self::error($message, 401);
    }
    
    public static function forbidden($message = 'Forbidden') {
        self::error($message, 403);
    }
    
    public static function notFound($message = 'Resource not found') {
        self::error($message, 404);
    }
    
    public static function serverError($message = 'Internal server error') {
        self::error($message, 500);
    }
    
    public static function validationError($errors, $message = 'Validation failed') {
        self::json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], 422);
    }
}

/**
 * Request helper class
 */
class Request {
    private static $input = null;
    
    public static function method() {
        return $_SERVER['REQUEST_METHOD'];
    }
    
    public static function uri() {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }
    
    public static function input($key = null, $default = null) {
        if (self::$input === null) {
            self::$input = json_decode(file_get_contents('php://input'), true) ?: [];
            self::$input = array_merge($_GET, $_POST, self::$input);
        }
        
        if ($key === null) {
            return self::$input;
        }
        
        return self::$input[$key] ?? $default;
    }
    
    public static function get($key = null, $default = null) {
        if ($key === null) {
            return $_GET;
        }
        return $_GET[$key] ?? $default;
    }
    
    public static function post($key = null, $default = null) {
        if ($key === null) {
            return $_POST;
        }
        return $_POST[$key] ?? $default;
    }
    
    public static function file($key) {
        return $_FILES[$key] ?? null;
    }
    
    public static function files($key = null) {
        if ($key === null) {
            return $_FILES;
        }
        return $_FILES[$key] ?? [];
    }
    
    public static function header($key, $default = null) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $_SERVER[$key] ?? $default;
    }
    
    public static function bearerToken() {
        $header = self::header('Authorization');
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    public static function validate($rules) {
        $errors = [];
        $data = self::input();
        
        foreach ($rules as $field => $rule) {
            $ruleArray = explode('|', $rule);
            $value = $data[$field] ?? null;
            
            foreach ($ruleArray as $r) {
                if ($r === 'required' && (empty($value) && $value !== '0')) {
                    $errors[$field][] = "The {$field} field is required.";
                }
                
                if ($r === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "The {$field} field must be a valid email address.";
                }
                
                if (strpos($r, 'min:') === 0 && $value) {
                    $min = (int)substr($r, 4);
                    if (strlen($value) < $min) {
                        $errors[$field][] = "The {$field} field must be at least {$min} characters.";
                    }
                }
                
                if (strpos($r, 'max:') === 0 && $value) {
                    $max = (int)substr($r, 4);
                    if (strlen($value) > $max) {
                        $errors[$field][] = "The {$field} field must not exceed {$max} characters.";
                    }
                }
                
                if (strpos($r, 'in:') === 0 && $value) {
                    $options = explode(',', substr($r, 3));
                    if (!in_array($value, $options)) {
                        $errors[$field][] = "The {$field} field must be one of: " . implode(', ', $options);
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        return $data;
    }
}

/**
 * JWT Token management
 */
class JWT {
    private static $secret;
    
    private static function getSecret() {
        if (self::$secret === null) {
            self::$secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
        }
        return self::$secret;
    }
    
    public static function encode($payload, $expiry = 3600) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiry;
        $payload = json_encode($payload);
        
        $headerEncoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $payloadEncoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::getSecret(), true);
        $signatureEncoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    public static function decode($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        
        $signature = str_replace(['-', '_'], ['+', '/'], $signatureEncoded);
        $signature = base64_decode($signature);
        
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::getSecret(), true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        $payload = str_replace(['-', '_'], ['+', '/'], $payloadEncoded);
        $payload = json_decode(base64_decode($payload), true);
        
        if ($payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }
}

/**
 * Rate limiting
 */
class RateLimit {
    private static $redis = null;
    
    private static function getRedis() {
        if (self::$redis === null && class_exists('Redis')) {
            try {
                self::$redis = new Redis();
                self::$redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', $_ENV['REDIS_PORT'] ?? 6379);
                if ($_ENV['REDIS_PASSWORD'] ?? false) {
                    self::$redis->auth($_ENV['REDIS_PASSWORD']);
                }
            } catch (Exception $e) {
                self::$redis = false;
            }
        }
        return self::$redis;
    }
    
    public static function check($key, $limit = 60, $window = 60) {
        $redis = self::getRedis();
        if (!$redis) {
            return true; // Allow if Redis is not available
        }
        
        $current = $redis->incr($key);
        if ($current === 1) {
            $redis->expire($key, $window);
        }
        
        return $current <= $limit;
    }
    
    public static function middleware($limit = 60, $window = 60) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "rate_limit:" . $clientIp . ":" . date('Y-m-d-H-i');
        
        if (!self::check($key, $limit, $window)) {
            Response::error('Too many requests. Please try again later.', 429);
        }
    }
}

/**
 * Logger utility
 */
class Logger {
    public static function log($level, $message, $context = []) {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $logEntry = sprintf(
            "[%s] %s [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $clientIp,
            $message,
            $context ? json_encode($context) : ''
        );
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    public static function info($message, $context = []) {
        self::log('info', $message, $context);
    }
    
    public static function error($message, $context = []) {
        self::log('error', $message, $context);
    }
    
    public static function warning($message, $context = []) {
        self::log('warning', $message, $context);
    }
    
    public static function debug($message, $context = []) {
        if (($_ENV['APP_ENV'] ?? 'development') === 'development') {
            self::log('debug', $message, $context);
        }
    }
}

// Initialize application
handleCORS();
setSecurityHeaders();

// Autoload classes
spl_autoload_register(function ($className) {
    $paths = [
        __DIR__ . '/../models/',
        __DIR__ . '/../controllers/',
        __DIR__ . '/../services/',
        __DIR__ . '/../middleware/',
        __DIR__ . '/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            break;
        }
    }
});

// Include database configuration
require_once __DIR__ . '/database.php';