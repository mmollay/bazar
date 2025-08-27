<?php
/**
 * PHPUnit Bootstrap File
 * Sets up testing environment
 */

// Load Composer autoloader
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "Please run 'composer install' to install dependencies.\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables for testing
if (file_exists(__DIR__ . '/../.env.testing')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../', '.env.testing');
    $dotenv->load();
} else {
    // Set default test environment variables
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_HOST'] = 'localhost';
    $_ENV['DB_NAME'] = 'bazar_test';
    $_ENV['DB_USER'] = 'root';
    $_ENV['DB_PASS'] = '';
    $_ENV['JWT_SECRET'] = 'test-jwt-secret-key';
    $_ENV['REDIS_HOST'] = 'localhost';
    $_ENV['REDIS_PORT'] = '6379';
}

// Include necessary backend files
require_once __DIR__ . '/../backend/config/database.php';

// Create test database connection
try {
    $testPdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']}", 
        $_ENV['DB_USER'], 
        $_ENV['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // Create test database if it doesn't exist
    $testPdo->exec("CREATE DATABASE IF NOT EXISTS {$_ENV['DB_NAME']}");
    $testPdo->exec("USE {$_ENV['DB_NAME']}");
    
    // Load test schema
    if (file_exists(__DIR__ . '/../backend/config/database.sql')) {
        $schema = file_get_contents(__DIR__ . '/../backend/config/database.sql');
        $testPdo->exec($schema);
    }
    
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Helper function for test database cleanup
function cleanTestDatabase() {
    global $testPdo;
    
    $tables = ['users', 'articles', 'categories', 'messages', 'conversations', 'article_images', 'ai_suggestions', 'search_analytics'];
    
    foreach ($tables as $table) {
        try {
            $testPdo->exec("DELETE FROM `{$table}`");
            $testPdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
    }
}

// Base test case class
abstract class TestCase extends PHPUnit\Framework\TestCase
{
    protected $pdo;
    
    protected function setUp(): void
    {
        global $testPdo;
        $this->pdo = $testPdo;
        cleanTestDatabase();
    }
    
    protected function tearDown(): void
    {
        cleanTestDatabase();
    }
    
    /**
     * Create a test user
     */
    protected function createTestUser($data = [])
    {
        $defaults = [
            'username' => 'testuser_' . uniqid(),
            'email' => 'test_' . uniqid() . '@example.com',
            'password_hash' => password_hash('password', PASSWORD_DEFAULT),
            'first_name' => 'Test',
            'last_name' => 'User',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $userData = array_merge($defaults, $data);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash, first_name, last_name, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userData['username'],
            $userData['email'],
            $userData['password_hash'],
            $userData['first_name'],
            $userData['last_name'],
            $userData['created_at'],
            $userData['updated_at']
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Create a test article
     */
    protected function createTestArticle($userId, $data = [])
    {
        $defaults = [
            'title' => 'Test Article ' . uniqid(),
            'description' => 'Test description',
            'price' => 100.00,
            'category_id' => 1,
            'condition' => 'new',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $articleData = array_merge($defaults, $data);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO articles (user_id, title, description, price, category_id, condition_type, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $articleData['title'],
            $articleData['description'],
            $articleData['price'],
            $articleData['category_id'],
            $articleData['condition'],
            $articleData['status'],
            $articleData['created_at'],
            $articleData['updated_at']
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Assert JSON response structure
     */
    protected function assertJsonStructure($json, $structure)
    {
        $data = json_decode($json, true);
        $this->assertNotNull($data, 'Invalid JSON response');
        
        foreach ($structure as $key => $value) {
            if (is_array($value)) {
                $this->assertArrayHasKey($key, $data);
                $this->assertJsonStructure(json_encode($data[$key]), $value);
            } else {
                $this->assertArrayHasKey($value, $data);
            }
        }
    }
    
    /**
     * Mock HTTP request
     */
    protected function mockRequest($method, $uri, $data = [], $headers = [])
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        
        foreach ($headers as $key => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }
        
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            file_put_contents('php://input', json_encode($data));
        }
    }
}