<?php

use PHPUnit\Framework\TestCase;

/**
 * User Model Tests
 */
class UserTest extends TestCase
{
    private $pdo;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test database connection
        $this->pdo = new PDO(
            'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'bazar_test'),
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Clean database
        $this->cleanDatabase();
        
        // Include User model
        require_once __DIR__ . '/../../../backend/models/User.php';
        
        // Mock BaseModel
        if (!class_exists('BaseModel')) {
            eval('
                class BaseModel {
                    protected $db;
                    protected $table;
                    
                    public function __construct($db = null) {
                        global $pdo;
                        $this->db = $db ?: $pdo;
                    }
                    
                    public function create($data) {
                        $columns = array_keys($data);
                        $placeholders = array_fill(0, count($columns), "?");
                        
                        $sql = "INSERT INTO {$this->table} (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
                        $stmt = $this->db->prepare($sql);
                        $stmt->execute(array_values($data));
                        
                        return $this->db->lastInsertId();
                    }
                    
                    public function update($id, $data) {
                        $columns = array_keys($data);
                        $setClause = implode(", ", array_map(fn($col) => "$col = ?", $columns));
                        
                        $sql = "UPDATE {$this->table} SET $setClause WHERE id = ?";
                        $stmt = $this->db->prepare($sql);
                        $values = array_values($data);
                        $values[] = $id;
                        
                        return $stmt->execute($values);
                    }
                }
            ');
        }
        
        $this->user = new User($this->pdo);
    }
    
    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }
    
    private function cleanDatabase()
    {
        try {
            $this->pdo->exec("DELETE FROM users");
            $this->pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
        } catch (PDOException $e) {
            // Table might not exist yet
        }
    }

    public function testCreateUserHashesPassword()
    {
        $userData = [
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'plaintext_password',
            'first_name' => 'Test',
            'last_name' => 'User'
        ];

        $userId = $this->user->createUser($userData);

        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);

        // Verify password was hashed
        $stmt = $this->pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();

        $this->assertNotNull($result);
        $this->assertNotEquals('plaintext_password', $result['password_hash']);
        $this->assertTrue(password_verify('plaintext_password', $result['password_hash']));
    }

    public function testFindByEmail()
    {
        // Create test user
        $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash, first_name, last_name) 
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            'testuser',
            'test@example.com',
            password_hash('password', PASSWORD_DEFAULT),
            'Test',
            'User'
        ]);

        $user = $this->user->findByEmail('test@example.com');

        $this->assertNotNull($user);
        $this->assertEquals('testuser', $user['username']);
        $this->assertEquals('test@example.com', $user['email']);
    }

    public function testFindByEmailReturnsNullForNonexistentUser()
    {
        $user = $this->user->findByEmail('nonexistent@example.com');
        $this->assertNull($user);
    }

    public function testFindByUsername()
    {
        // Create test user
        $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash, first_name, last_name) 
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            'testuser',
            'test@example.com',
            password_hash('password', PASSWORD_DEFAULT),
            'Test',
            'User'
        ]);

        $user = $this->user->findByUsername('testuser');

        $this->assertNotNull($user);
        $this->assertEquals('testuser', $user['username']);
        $this->assertEquals('test@example.com', $user['email']);
    }

    public function testVerifyPasswordWithCorrectPassword()
    {
        $password = 'correct_password';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $user = [
            'id' => 1,
            'username' => 'testuser',
            'password_hash' => $hash
        ];

        $result = $this->user->verifyPassword($user, $password);
        $this->assertTrue($result);
    }

    public function testVerifyPasswordWithIncorrectPassword()
    {
        $password = 'correct_password';
        $wrongPassword = 'wrong_password';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $user = [
            'id' => 1,
            'username' => 'testuser',
            'password_hash' => $hash
        ];

        $result = $this->user->verifyPassword($user, $wrongPassword);
        $this->assertFalse($result);
    }

    public function testUpdateLastLogin()
    {
        // Create test user
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash, first_name, last_name, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            'testuser',
            'test@example.com',
            password_hash('password', PASSWORD_DEFAULT),
            'Test',
            'User'
        ]);
        $userId = $this->pdo->lastInsertId();

        $result = $this->user->updateLastLogin($userId);
        $this->assertTrue($result);

        // Verify last_login_at was updated
        $stmt = $this->pdo->prepare("SELECT last_login_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $this->assertNotNull($user['last_login_at']);
    }

    public function testCreateUserWithDuplicateEmailFails()
    {
        $this->expectException(PDOException::class);

        // Create first user
        $userData1 = [
            'username' => 'user1',
            'email' => 'duplicate@example.com',
            'password' => 'password1',
            'first_name' => 'User',
            'last_name' => 'One'
        ];
        $this->user->createUser($userData1);

        // Try to create second user with same email
        $userData2 = [
            'username' => 'user2',
            'email' => 'duplicate@example.com',
            'password' => 'password2',
            'first_name' => 'User',
            'last_name' => 'Two'
        ];
        $this->user->createUser($userData2);
    }

    public function testPasswordHashingIsSecure()
    {
        $password = 'test_password_123';
        
        $userData = [
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => $password,
            'first_name' => 'Test',
            'last_name' => 'User'
        ];

        $userId = $this->user->createUser($userData);

        // Get the stored hash
        $stmt = $this->pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();

        $hash = $result['password_hash'];

        // Verify it's a proper bcrypt hash
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertEquals(60, strlen($hash)); // bcrypt hashes are 60 characters
        
        // Verify password_verify works
        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(password_verify('wrong_password', $hash));
    }
}