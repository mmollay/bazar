<?php

namespace Bazar\Config;

class Config
{
    private static $config = [];
    
    public static function load(): void
    {
        // Load environment variables
        if (file_exists(__DIR__ . '/../../.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
            $dotenv->load();
        }
        
        self::$config = [
            'app' => [
                'name' => $_ENV['APP_NAME'] ?? 'Bazar Marketplace',
                'url' => $_ENV['APP_URL'] ?? 'http://localhost:8000',
                'env' => $_ENV['APP_ENV'] ?? 'development',
                'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'secret_key' => $_ENV['APP_SECRET_KEY'] ?? 'your-secret-key-here',
                'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Europe/Paris'
            ],
            
            'database' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => $_ENV['DB_PORT'] ?? '3306',
                'name' => $_ENV['DB_NAME'] ?? 'bazar_marketplace',
                'username' => $_ENV['DB_USERNAME'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
                'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4'
            ],
            
            'security' => [
                'jwt_secret' => $_ENV['JWT_SECRET'] ?? 'your-jwt-secret-here',
                'bcrypt_rounds' => (int)($_ENV['BCRYPT_ROUNDS'] ?? 12),
                'session_lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 7200)
            ],
            
            'oauth' => [
                'google' => [
                    'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                    'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? ''
                ],
                'facebook' => [
                    'app_id' => $_ENV['FACEBOOK_APP_ID'] ?? '',
                    'app_secret' => $_ENV['FACEBOOK_APP_SECRET'] ?? ''
                ]
            ],
            
            'twilio' => [
                'sid' => $_ENV['TWILIO_SID'] ?? '',
                'auth_token' => $_ENV['TWILIO_AUTH_TOKEN'] ?? '',
                'phone_number' => $_ENV['TWILIO_PHONE_NUMBER'] ?? ''
            ],
            
            'mail' => [
                'driver' => $_ENV['MAIL_DRIVER'] ?? 'smtp',
                'host' => $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com',
                'port' => (int)($_ENV['MAIL_PORT'] ?? 587),
                'username' => $_ENV['MAIL_USERNAME'] ?? '',
                'password' => $_ENV['MAIL_PASSWORD'] ?? '',
                'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
                'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@bazar.com',
                'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'Bazar Marketplace'
            ],
            
            'upload' => [
                'max_file_size' => (int)($_ENV['MAX_FILE_SIZE'] ?? 10485760),
                'allowed_extensions' => explode(',', $_ENV['ALLOWED_EXTENSIONS'] ?? 'jpg,jpeg,png,gif,webp'),
                'upload_path' => $_ENV['UPLOAD_PATH'] ?? 'uploads/'
            ],
            
            'ai' => [
                'openai_api_key' => $_ENV['OPENAI_API_KEY'] ?? '',
                'suggestions_enabled' => filter_var($_ENV['AI_SUGGESTIONS_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN)
            ],
            
            'payment' => [
                'stripe' => [
                    'public_key' => $_ENV['STRIPE_PUBLIC_KEY'] ?? '',
                    'secret_key' => $_ENV['STRIPE_SECRET_KEY'] ?? ''
                ],
                'paypal' => [
                    'client_id' => $_ENV['PAYPAL_CLIENT_ID'] ?? '',
                    'client_secret' => $_ENV['PAYPAL_CLIENT_SECRET'] ?? ''
                ]
            ],
            
            'search' => [
                'elasticsearch' => [
                    'host' => $_ENV['ELASTICSEARCH_HOST'] ?? 'localhost',
                    'port' => (int)($_ENV['ELASTICSEARCH_PORT'] ?? 9200)
                ]
            ],
            
            'rate_limiting' => [
                'requests' => (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 60),
                'window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 3600)
            ],
            
            'logging' => [
                'level' => $_ENV['LOG_LEVEL'] ?? 'info',
                'file' => $_ENV['LOG_FILE'] ?? 'logs/application.log'
            ],
            
            'cache' => [
                'driver' => $_ENV['CACHE_DRIVER'] ?? 'redis',
                'redis' => [
                    'host' => $_ENV['REDIS_HOST'] ?? 'localhost',
                    'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
                    'password' => $_ENV['REDIS_PASSWORD'] ?? ''
                ]
            ],
            
            'cors' => [
                'allowed_origins' => explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:3000,http://localhost:8000'),
                'allowed_methods' => explode(',', $_ENV['CORS_ALLOWED_METHODS'] ?? 'GET,POST,PUT,DELETE,OPTIONS'),
                'allowed_headers' => explode(',', $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type,Authorization,X-Requested-With')
            ],
            
            'cookie' => [
                'secure' => filter_var($_ENV['COOKIE_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'httponly' => filter_var($_ENV['COOKIE_HTTPONLY'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'samesite' => $_ENV['COOKIE_SAMESITE'] ?? 'lax'
            ]
        ];
    }
    
    public static function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }
    
    public static function all(): array
    {
        return self::$config;
    }
    
    public static function isDevelopment(): bool
    {
        return self::get('app.env') === 'development';
    }
    
    public static function isProduction(): bool
    {
        return self::get('app.env') === 'production';
    }
    
    public static function isDebug(): bool
    {
        return self::get('app.debug', false);
    }
}