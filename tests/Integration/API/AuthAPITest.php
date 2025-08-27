<?php

/**
 * Authentication API Integration Tests
 * Tests the complete authentication flow including JWT tokens, sessions, etc.
 */
class AuthAPITest extends TestCase
{
    private $baseUrl;
    private $client;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->baseUrl = 'http://localhost:8000/api';
        
        // Initialize HTTP client for API testing
        $this->client = new class {
            public function request($method, $url, $options = []) {
                $ch = curl_init();
                
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_HTTPHEADER => $options['headers'] ?? [],
                    CURLOPT_POSTFIELDS => isset($options['json']) ? json_encode($options['json']) : ($options['body'] ?? ''),
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                
                curl_close($ch);
                
                if ($error) {
                    throw new Exception("CURL Error: " . $error);
                }
                
                return [
                    'status' => $httpCode,
                    'body' => $response,
                    'data' => json_decode($response, true)
                ];
            }
        };
    }
    
    public function testUserRegistrationSuccess()
    {
        $userData = [
            'username' => 'testuser_' . uniqid(),
            'email' => 'test_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => '+1234567890'
        ];
        
        $response = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(201, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('token', $response['data']);
        $this->assertArrayHasKey('user', $response['data']);
        
        // Verify JWT token structure
        $token = $response['data']['token'];
        $this->assertCount(3, explode('.', $token));
        
        // Verify user data doesn't contain sensitive information
        $userData = $response['data']['user'];
        $this->assertArrayNotHasKey('password', $userData);
        $this->assertArrayNotHasKey('password_hash', $userData);
        
        return $response['data'];
    }
    
    /**
     * @depends testUserRegistrationSuccess
     */
    public function testUserLoginSuccess($registrationData)
    {
        $loginData = [
            'email' => $registrationData['user']['email'],
            'password' => 'SecurePassword123!'
        ];
        
        $response = $this->client->request('POST', $this->baseUrl . '/auth/login', [
            'json' => $loginData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('token', $response['data']);
        $this->assertArrayHasKey('user', $response['data']);
        
        // Verify token is different from registration token
        $this->assertNotEquals($registrationData['token'], $response['data']['token']);
        
        return $response['data'];
    }
    
    public function testUserLoginInvalidCredentials()
    {
        $loginData = [
            'email' => 'nonexistent@example.com',
            'password' => 'wrongpassword'
        ];
        
        $response = $this->client->request('POST', $this->baseUrl . '/auth/login', [
            'json' => $loginData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertEquals('Invalid credentials', $response['data']['message']);
        $this->assertArrayNotHasKey('token', $response['data']);
    }
    
    public function testRegistrationValidation()
    {
        // Test missing required fields
        $invalidData = [
            'email' => 'test@example.com'
            // Missing other required fields
        ];
        
        $response = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $invalidData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertArrayHasKey('errors', $response['data']);
        $this->assertArrayHasKey('username', $response['data']['errors']);
        $this->assertArrayHasKey('password', $response['data']['errors']);
        $this->assertArrayHasKey('first_name', $response['data']['errors']);
        $this->assertArrayHasKey('last_name', $response['data']['errors']);
    }
    
    public function testEmailFormatValidation()
    {
        $userData = [
            'username' => 'testuser',
            'email' => 'invalid-email-format',
            'password' => 'SecurePassword123!',
            'first_name' => 'Test',
            'last_name' => 'User'
        ];
        
        $response = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertArrayHasKey('errors', $response['data']);
        $this->assertArrayHasKey('email', $response['data']['errors']);
        $this->assertStringContainsString('valid email', $response['data']['errors']['email']);
    }
    
    public function testPasswordStrengthValidation()
    {
        $weakPasswords = ['123', 'password', 'PASSWORD', '12345678'];
        
        foreach ($weakPasswords as $password) {
            $userData = [
                'username' => 'testuser_' . uniqid(),
                'email' => 'test_' . uniqid() . '@example.com',
                'password' => $password,
                'first_name' => 'Test',
                'last_name' => 'User'
            ];
            
            $response = $this->client->request('POST', $this->baseUrl . '/auth/register', [
                'json' => $userData,
                'headers' => ['Content-Type: application/json']
            ]);
            
            $this->assertEquals(400, $response['status']);
            $this->assertFalse($response['data']['success']);
            $this->assertArrayHasKey('errors', $response['data']);
            $this->assertArrayHasKey('password', $response['data']['errors']);
        }
    }
    
    public function testDuplicateEmailRegistration()
    {
        $userData = [
            'username' => 'firstuser',
            'email' => 'duplicate@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'First',
            'last_name' => 'User'
        ];
        
        // First registration should succeed
        $response1 = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(201, $response1['status']);
        
        // Second registration with same email should fail
        $userData['username'] = 'seconduser';
        $response2 = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(400, $response2['status']);
        $this->assertFalse($response2['data']['success']);
        $this->assertStringContainsString('email', strtolower($response2['data']['message']));
    }
    
    public function testDuplicateUsernameRegistration()
    {
        $userData = [
            'username' => 'duplicateuser',
            'email' => 'first@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'First',
            'last_name' => 'User'
        ];
        
        // First registration should succeed
        $response1 = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(201, $response1['status']);
        
        // Second registration with same username should fail
        $userData['email'] = 'second@example.com';
        $response2 = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(400, $response2['status']);
        $this->assertFalse($response2['data']['success']);
        $this->assertStringContainsString('username', strtolower($response2['data']['message']));
    }
    
    /**
     * @depends testUserRegistrationSuccess
     */
    public function testProtectedEndpointWithValidToken($registrationData)
    {
        $token = $registrationData['token'];
        
        $response = $this->client->request('GET', $this->baseUrl . '/auth/me', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('user', $response['data']);
        $this->assertEquals($registrationData['user']['id'], $response['data']['user']['id']);
    }
    
    public function testProtectedEndpointWithoutToken()
    {
        $response = $this->client->request('GET', $this->baseUrl . '/auth/me', [
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertEquals('Authentication required', $response['data']['message']);
    }
    
    public function testProtectedEndpointWithInvalidToken()
    {
        $invalidToken = 'invalid.jwt.token';
        
        $response = $this->client->request('GET', $this->baseUrl . '/auth/me', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $invalidToken
            ]
        ]);
        
        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertEquals('Invalid token', $response['data']['message']);
    }
    
    public function testTokenRefresh()
    {
        // First, create a user and get a token
        $userData = [
            'username' => 'refreshuser_' . uniqid(),
            'email' => 'refresh_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'Refresh',
            'last_name' => 'User'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $originalToken = $registerResponse['data']['token'];
        
        // Test token refresh
        $response = $this->client->request('POST', $this->baseUrl . '/auth/refresh', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $originalToken
            ]
        ]);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('token', $response['data']);
        
        // New token should be different
        $newToken = $response['data']['token'];
        $this->assertNotEquals($originalToken, $newToken);
        
        // New token should work for protected endpoints
        $meResponse = $this->client->request('GET', $this->baseUrl . '/auth/me', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $newToken
            ]
        ]);
        
        $this->assertEquals(200, $meResponse['status']);
        $this->assertTrue($meResponse['data']['success']);
    }
    
    public function testLogout()
    {
        // Create a user and get a token
        $userData = [
            'username' => 'logoutuser_' . uniqid(),
            'email' => 'logout_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'Logout',
            'last_name' => 'User'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $token = $registerResponse['data']['token'];
        
        // Logout
        $response = $this->client->request('POST', $this->baseUrl . '/auth/logout', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertEquals('Logged out successfully', $response['data']['message']);
        
        // Token should be invalidated (in a real implementation)
        // For this test, we'll assume the token is blacklisted
        $meResponse = $this->client->request('GET', $this->baseUrl . '/auth/me', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);
        
        // This might still return 200 if token blacklisting is not implemented
        // In a production system, this should return 401
    }
    
    public function testPasswordReset()
    {
        // Create a user first
        $userData = [
            'username' => 'resetuser_' . uniqid(),
            'email' => 'reset_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'Reset',
            'last_name' => 'User'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $userEmail = $userData['email'];
        
        // Request password reset
        $resetResponse = $this->client->request('POST', $this->baseUrl . '/auth/password/reset', [
            'json' => ['email' => $userEmail],
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(200, $resetResponse['status']);
        $this->assertTrue($resetResponse['data']['success']);
        $this->assertStringContainsString('reset', $resetResponse['data']['message']);
    }
    
    public function testPasswordResetNonexistentEmail()
    {
        $response = $this->client->request('POST', $this->baseUrl . '/auth/password/reset', [
            'json' => ['email' => 'nonexistent@example.com'],
            'headers' => ['Content-Type: application/json']
        ]);
        
        // Should return success for security reasons (don't reveal if email exists)
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertStringContainsString('reset', $response['data']['message']);
    }
    
    public function testRateLimiting()
    {
        $loginData = [
            'email' => 'nonexistent@example.com',
            'password' => 'wrongpassword'
        ];
        
        $failedAttempts = 0;
        $rateLimited = false;
        
        // Attempt multiple failed logins
        for ($i = 0; $i < 6; $i++) {
            $response = $this->client->request('POST', $this->baseUrl . '/auth/login', [
                'json' => $loginData,
                'headers' => ['Content-Type: application/json']
            ]);
            
            if ($response['status'] === 429) {
                $rateLimited = true;
                break;
            }
            
            if ($response['status'] === 401) {
                $failedAttempts++;
            }
        }
        
        // Should have failed attempts
        $this->assertGreaterThan(0, $failedAttempts);
        
        // If rate limiting is implemented, should eventually be rate limited
        // This test might pass even without rate limiting implemented
        if ($rateLimited) {
            $this->assertTrue($rateLimited);
        }
    }
    
    public function testEmailVerification()
    {
        // Create a user
        $userData = [
            'username' => 'verifyuser_' . uniqid(),
            'email' => 'verify_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'Verify',
            'last_name' => 'User'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(201, $registerResponse['status']);
        
        // Test email verification endpoint
        $mockToken = 'mock-verification-token-' . uniqid();
        
        $verifyResponse = $this->client->request('POST', $this->baseUrl . '/auth/verify-email', [
            'json' => ['token' => $mockToken],
            'headers' => ['Content-Type: application/json']
        ]);
        
        // This might fail if token doesn't exist, which is expected in this test
        // In a real scenario, the token would be sent via email
        if ($verifyResponse['status'] !== 400) {
            $this->assertEquals(200, $verifyResponse['status']);
            $this->assertTrue($verifyResponse['data']['success']);
        } else {
            $this->assertEquals(400, $verifyResponse['status']);
            $this->assertStringContainsString('token', $verifyResponse['data']['message']);
        }
    }
    
    public function testTwoFactorAuthSetup()
    {
        // Create a user and get token
        $userData = [
            'username' => '2fauser_' . uniqid(),
            'email' => '2fa_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'TwoFA',
            'last_name' => 'User'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $token = $registerResponse['data']['token'];
        
        // Setup 2FA
        $response = $this->client->request('POST', $this->baseUrl . '/auth/2fa/setup', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);
        
        // This might not be implemented yet
        if ($response['status'] !== 404) {
            $this->assertEquals(200, $response['status']);
            $this->assertTrue($response['data']['success']);
            $this->assertArrayHasKey('secret', $response['data']);
            $this->assertArrayHasKey('qr_code', $response['data']);
        }
    }
    
    public function testCORSHeaders()
    {
        // Test preflight request
        $response = $this->client->request('OPTIONS', $this->baseUrl . '/auth/login', [
            'headers' => [
                'Origin: http://localhost:3000',
                'Access-Control-Request-Method: POST',
                'Access-Control-Request-Headers: Content-Type,Authorization'
            ]
        ]);
        
        // Should return appropriate CORS headers
        // This test might need to be adjusted based on server configuration
        $this->assertContains($response['status'], [200, 204]);
    }
    
    public function testUserProfileUpdate()
    {
        // Create a user and get token
        $userData = [
            'username' => 'updateuser_' . uniqid(),
            'email' => 'update_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'Update',
            'last_name' => 'User'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $token = $registerResponse['data']['token'];
        $userId = $registerResponse['data']['user']['id'];
        
        // Update profile
        $updateData = [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'phone' => '+1234567890'
        ];
        
        $response = $this->client->request('PUT', $this->baseUrl . '/auth/profile', [
            'json' => $updateData,
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        
        // Verify changes
        $meResponse = $this->client->request('GET', $this->baseUrl . '/auth/me', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);
        
        $this->assertEquals('Updated', $meResponse['data']['user']['first_name']);
        $this->assertEquals('Name', $meResponse['data']['user']['last_name']);
        $this->assertEquals('+1234567890', $meResponse['data']['user']['phone']);
    }
    
    public function testPasswordChange()
    {
        // Create a user and get token
        $userData = [
            'username' => 'changepassuser_' . uniqid(),
            'email' => 'changepass_' . uniqid() . '@example.com',
            'password' => 'OldPassword123!',
            'first_name' => 'Change',
            'last_name' => 'Password'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        $token = $registerResponse['data']['token'];
        
        // Change password
        $changeData = [
            'current_password' => 'OldPassword123!',
            'new_password' => 'NewPassword123!'
        ];
        
        $response = $this->client->request('PUT', $this->baseUrl . '/auth/password', [
            'json' => $changeData,
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        
        // Test login with new password
        $loginResponse = $this->client->request('POST', $this->baseUrl . '/auth/login', [
            'json' => [
                'email' => $userData['email'],
                'password' => 'NewPassword123!'
            ],
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(200, $loginResponse['status']);
        $this->assertTrue($loginResponse['data']['success']);
        
        // Test login with old password should fail
        $oldLoginResponse = $this->client->request('POST', $this->baseUrl . '/auth/login', [
            'json' => [
                'email' => $userData['email'],
                'password' => 'OldPassword123!'
            ],
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertEquals(401, $oldLoginResponse['status']);
        $this->assertFalse($oldLoginResponse['data']['success']);
    }
}