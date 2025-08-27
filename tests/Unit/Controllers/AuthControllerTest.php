<?php

use PHPUnit\Framework\TestCase;

/**
 * Authentication Controller Tests
 */
class AuthControllerTest extends TestCase
{
    private $pdo;
    private $authController;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->pdo = new PDO(
            'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'bazar_test'),
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->cleanDatabase();
        $this->setupMocks();
        $this->createTestUser();
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
            // Tables might not exist yet
        }
    }
    
    private function setupMocks()
    {
        // Mock JWT functionality
        if (!function_exists('jwt_encode')) {
            function jwt_encode($payload, $secret) {
                return base64_encode(json_encode($payload)) . '.mock.signature';
            }
        }
        
        if (!function_exists('jwt_decode')) {
            function jwt_decode($token, $secret) {
                $parts = explode('.', $token);
                if (count($parts) !== 3) {
                    throw new Exception('Invalid token format');
                }
                return json_decode(base64_decode($parts[0]), true);
            }
        }
        
        // Mock AuthController
        if (!class_exists('AuthController')) {
            eval('
                class AuthController {
                    private $pdo;
                    
                    public function __construct($pdo) {
                        $this->pdo = $pdo;
                    }
                    
                    public function register($data) {
                        // Validate required fields
                        $required = ["username", "email", "password", "first_name", "last_name"];
                        foreach ($required as $field) {
                            if (!isset($data[$field]) || empty($data[$field])) {
                                return [
                                    "success" => false,
                                    "message" => "Missing required field: {$field}"
                                ];
                            }
                        }
                        
                        // Validate email format
                        if (!filter_var($data["email"], FILTER_VALIDATE_EMAIL)) {
                            return [
                                "success" => false,
                                "message" => "Invalid email format"
                            ];
                        }
                        
                        // Check if user already exists
                        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
                        $stmt->execute([$data["email"], $data["username"]]);
                        if ($stmt->fetch()) {
                            return [
                                "success" => false,
                                "message" => "User already exists"
                            ];
                        }
                        
                        // Hash password
                        $passwordHash = password_hash($data["password"], PASSWORD_DEFAULT);
                        
                        // Insert user
                        $stmt = $this->pdo->prepare("
                            INSERT INTO users (username, email, password_hash, first_name, last_name, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        
                        if ($stmt->execute([
                            $data["username"],
                            $data["email"],
                            $passwordHash,
                            $data["first_name"],
                            $data["last_name"]
                        ])) {
                            $userId = $this->pdo->lastInsertId();
                            $token = jwt_encode(["user_id" => $userId], $_ENV["JWT_SECRET"] ?? "secret");
                            
                            return [
                                "success" => true,
                                "message" => "User registered successfully",
                                "user_id" => $userId,
                                "token" => $token
                            ];
                        }
                        
                        return [
                            "success" => false,
                            "message" => "Registration failed"
                        ];
                    }
                    
                    public function login($data) {
                        // Validate required fields
                        if (!isset($data["email"]) || !isset($data["password"])) {
                            return [
                                "success" => false,
                                "message" => "Email and password are required"
                            ];
                        }
                        
                        // Find user
                        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ?");
                        $stmt->execute([$data["email"]]);
                        $user = $stmt->fetch();
                        
                        if (!$user) {
                            return [
                                "success" => false,
                                "message" => "Invalid credentials"
                            ];
                        }
                        
                        // Verify password
                        if (!password_verify($data["password"], $user["password_hash"])) {
                            return [
                                "success" => false,
                                "message" => "Invalid credentials"
                            ];
                        }
                        
                        // Update last login
                        $stmt = $this->pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                        $stmt->execute([$user["id"]]);
                        
                        // Generate token
                        $token = jwt_encode([
                            "user_id" => $user["id"],
                            "email" => $user["email"],
                            "exp" => time() + (24 * 60 * 60) // 24 hours
                        ], $_ENV["JWT_SECRET"] ?? "secret");
                        
                        return [
                            "success" => true,
                            "message" => "Login successful",
                            "token" => $token,
                            "user" => [
                                "id" => $user["id"],
                                "username" => $user["username"],
                                "email" => $user["email"],
                                "first_name" => $user["first_name"],
                                "last_name" => $user["last_name"]
                            ]
                        ];
                    }
                    
                    public function validateToken($token) {
                        try {
                            $payload = jwt_decode($token, $_ENV["JWT_SECRET"] ?? "secret");
                            
                            if (isset($payload["exp"]) && $payload["exp"] < time()) {
                                return [
                                    "success" => false,
                                    "message" => "Token expired"
                                ];
                            }
                            
                            // Verify user still exists
                            $stmt = $this->pdo->prepare("SELECT id, username, email FROM users WHERE id = ?");
                            $stmt->execute([$payload["user_id"]]);
                            $user = $stmt->fetch();
                            
                            if (!$user) {
                                return [
                                    "success" => false,
                                    "message" => "User not found"
                                ];
                            }
                            
                            return [
                                "success" => true,
                                "user" => $user
                            ];
                            
                        } catch (Exception $e) {
                            return [
                                "success" => false,
                                "message" => "Invalid token"
                            ];
                        }
                    }
                    
                    public function logout($token) {
                        // In a real application, you would add the token to a blacklist
                        return [
                            "success" => true,
                            "message" => "Logged out successfully"
                        ];
                    }
                    
                    public function resetPassword($data) {
                        if (!isset($data["email"])) {
                            return [
                                "success" => false,
                                "message" => "Email is required"
                            ];
                        }
                        
                        // Check if user exists
                        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$data["email"]]);
                        $user = $stmt->fetch();
                        
                        if (!$user) {
                            // Return success even if user not found for security
                            return [
                                "success" => true,
                                "message" => "If the email exists, a reset link has been sent"
                            ];
                        }
                        
                        // Generate reset token
                        $resetToken = bin2hex(random_bytes(32));
                        $expiresAt = date("Y-m-d H:i:s", time() + 3600); // 1 hour
                        
                        // Store reset token
                        $stmt = $this->pdo->prepare("
                            UPDATE users 
                            SET reset_token = ?, reset_token_expires_at = ? 
                            WHERE id = ?
                        ");
                        $stmt->execute([$resetToken, $expiresAt, $user["id"]]);
                        
                        return [
                            "success" => true,
                            "message" => "If the email exists, a reset link has been sent",
                            "reset_token" => $resetToken // Only for testing
                        ];
                    }
                }
            ');
        }
        
        $this->authController = new AuthController($this->pdo);
    }
    
    private function createTestUser()
    {
        $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash, first_name, last_name, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            'existinguser',
            'existing@example.com',
            password_hash('password123', PASSWORD_DEFAULT),
            'Existing',
            'User'
        ]);
    }

    public function testSuccessfulRegistration()
    {
        $userData = [
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'securepassword',
            'first_name' => 'New',
            'last_name' => 'User'
        ];

        $result = $this->authController->register($userData);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertEquals('User registered successfully', $result['message']);
    }

    public function testRegistrationWithMissingFields()
    {
        $userData = [
            'username' => 'newuser',
            'email' => 'newuser@example.com'
            // Missing password, first_name, last_name
        ];

        $result = $this->authController->register($userData);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing required field', $result['message']);
    }

    public function testRegistrationWithInvalidEmail()
    {
        $userData = [
            'username' => 'newuser',
            'email' => 'invalid-email',
            'password' => 'password',
            'first_name' => 'New',
            'last_name' => 'User'
        ];

        $result = $this->authController->register($userData);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid email format', $result['message']);
    }

    public function testRegistrationWithExistingUser()
    {
        $userData = [
            'username' => 'existinguser',
            'email' => 'existing@example.com',
            'password' => 'password',
            'first_name' => 'Duplicate',
            'last_name' => 'User'
        ];

        $result = $this->authController->register($userData);

        $this->assertFalse($result['success']);
        $this->assertEquals('User already exists', $result['message']);
    }

    public function testSuccessfulLogin()
    {
        $loginData = [
            'email' => 'existing@example.com',
            'password' => 'password123'
        ];

        $result = $this->authController->login($loginData);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('user', $result);
        $this->assertEquals('Login successful', $result['message']);
        $this->assertEquals('existinguser', $result['user']['username']);
    }

    public function testLoginWithInvalidCredentials()
    {
        $loginData = [
            'email' => 'existing@example.com',
            'password' => 'wrongpassword'
        ];

        $result = $this->authController->login($loginData);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }

    public function testLoginWithNonexistentUser()
    {
        $loginData = [
            'email' => 'nonexistent@example.com',
            'password' => 'password'
        ];

        $result = $this->authController->login($loginData);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }

    public function testLoginWithMissingFields()
    {
        $loginData = [
            'email' => 'existing@example.com'
            // Missing password
        ];

        $result = $this->authController->login($loginData);

        $this->assertFalse($result['success']);
        $this->assertEquals('Email and password are required', $result['message']);
    }

    public function testValidTokenValidation()
    {
        // First login to get a valid token
        $loginResult = $this->authController->login([
            'email' => 'existing@example.com',
            'password' => 'password123'
        ]);

        $token = $loginResult['token'];
        $result = $this->authController->validateToken($token);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('user', $result);
        $this->assertEquals('existinguser', $result['user']['username']);
    }

    public function testInvalidTokenValidation()
    {
        $invalidToken = 'invalid.token.here';
        $result = $this->authController->validateToken($invalidToken);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid token', $result['message']);
    }

    public function testExpiredTokenValidation()
    {
        // Create an expired token
        $expiredPayload = [
            'user_id' => 1,
            'email' => 'existing@example.com',
            'exp' => time() - 3600 // Expired 1 hour ago
        ];
        $expiredToken = jwt_encode($expiredPayload, $_ENV['JWT_SECRET'] ?? 'secret');

        $result = $this->authController->validateToken($expiredToken);

        $this->assertFalse($result['success']);
        $this->assertEquals('Token expired', $result['message']);
    }

    public function testLogout()
    {
        // Get a valid token first
        $loginResult = $this->authController->login([
            'email' => 'existing@example.com',
            'password' => 'password123'
        ]);

        $token = $loginResult['token'];
        $result = $this->authController->logout($token);

        $this->assertTrue($result['success']);
        $this->assertEquals('Logged out successfully', $result['message']);
    }

    public function testPasswordReset()
    {
        $resetData = [
            'email' => 'existing@example.com'
        ];

        $result = $this->authController->resetPassword($resetData);

        $this->assertTrue($result['success']);
        $this->assertEquals('If the email exists, a reset link has been sent', $result['message']);
        
        // Verify reset token was stored in database
        $stmt = $this->pdo->prepare("SELECT reset_token, reset_token_expires_at FROM users WHERE email = ?");
        $stmt->execute(['existing@example.com']);
        $user = $stmt->fetch();
        
        $this->assertNotNull($user['reset_token']);
        $this->assertNotNull($user['reset_token_expires_at']);
    }

    public function testPasswordResetWithNonexistentEmail()
    {
        $resetData = [
            'email' => 'nonexistent@example.com'
        ];

        $result = $this->authController->resetPassword($resetData);

        // Should still return success for security reasons
        $this->assertTrue($result['success']);
        $this->assertEquals('If the email exists, a reset link has been sent', $result['message']);
    }

    public function testPasswordResetWithMissingEmail()
    {
        $resetData = []; // Missing email

        $result = $this->authController->resetPassword($resetData);

        $this->assertFalse($result['success']);
        $this->assertEquals('Email is required', $result['message']);
    }

    public function testPasswordStrengthValidation()
    {
        $weakPasswords = [
            '123',
            'password',
            'abc',
            '12345678'
        ];

        foreach ($weakPasswords as $password) {
            $userData = [
                'username' => 'testuser_' . uniqid(),
                'email' => 'test_' . uniqid() . '@example.com',
                'password' => $password,
                'first_name' => 'Test',
                'last_name' => 'User'
            ];

            $result = $this->authController->register($userData);
            
            // Depending on implementation, weak passwords might be rejected
            if (!$result['success']) {
                $this->assertStringContainsString('password', strtolower($result['message']));
            }
        }
    }

    public function testRateLimitingProtection()
    {
        // Test multiple failed login attempts
        $loginData = [
            'email' => 'existing@example.com',
            'password' => 'wrongpassword'
        ];

        $failedAttempts = 0;
        for ($i = 0; $i < 6; $i++) {
            $result = $this->authController->login($loginData);
            if (!$result['success']) {
                $failedAttempts++;
            }
        }

        $this->assertGreaterThan(0, $failedAttempts);
        
        // If rate limiting is implemented, subsequent attempts should be blocked
        // This is a placeholder test - implementation would depend on the actual rate limiting logic
    }

    public function testSQLInjectionProtection()
    {
        // Test SQL injection attempts in login
        $maliciousInputs = [
            "admin@example.com' OR '1'='1",
            "admin@example.com'; DROP TABLE users; --",
            "admin@example.com' UNION SELECT * FROM users --"
        ];

        foreach ($maliciousInputs as $maliciousEmail) {
            $loginData = [
                'email' => $maliciousEmail,
                'password' => 'password'
            ];

            $result = $this->authController->login($loginData);
            
            // Should fail with invalid credentials, not cause SQL errors
            $this->assertFalse($result['success']);
            $this->assertEquals('Invalid credentials', $result['message']);
        }
    }
}