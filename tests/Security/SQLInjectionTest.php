<?php

/**
 * SQL Injection Security Tests
 * Tests various SQL injection attack vectors to ensure proper parameterized queries
 */
class SQLInjectionTest extends TestCase
{
    private $baseUrl;
    private $client;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->baseUrl = 'http://localhost:8000/api';
        
        // Initialize HTTP client
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
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true
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
                    'data' => json_decode($response, true) ?? []
                ];
            }
        };
    }
    
    public function testLoginSQLInjection()
    {
        $sqlInjectionPayloads = [
            // Basic SQL injection attempts
            "admin' OR '1'='1",
            "admin' OR '1'='1' --",
            "admin' OR '1'='1' /*",
            "admin'; DROP TABLE users; --",
            "admin' UNION SELECT * FROM users --",
            
            // Advanced SQL injection
            "admin' OR 1=1 LIMIT 1 --",
            "admin' OR 'x'='x",
            "admin' AND (SELECT COUNT(*) FROM users) > 0 --",
            "admin' OR EXISTS(SELECT * FROM users WHERE username='admin') --",
            
            // Time-based blind SQL injection
            "admin' OR (SELECT COUNT(*) FROM users WHERE username='admin' AND SLEEP(5)) --",
            "admin' OR BENCHMARK(1000000,MD5(1)) --",
            
            // Boolean-based blind SQL injection
            "admin' AND LENGTH(username)>0 --",
            "admin' AND SUBSTRING(username,1,1)='a' --",
            
            // UNION-based SQL injection
            "admin' UNION SELECT 1,2,3,4,5 --",
            "admin' UNION SELECT username,password_hash FROM users --",
            
            // Error-based SQL injection
            "admin' AND EXTRACTVALUE(1,CONCAT(0x7e,(SELECT database()),0x7e)) --",
            "admin' AND (SELECT * FROM (SELECT COUNT(*),CONCAT(version(),FLOOR(RAND(0)*2))x FROM information_schema.tables GROUP BY x)a) --"
        ];
        
        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->client->request('POST', $this->baseUrl . '/auth/login', [
                'json' => [
                    'email' => $payload,
                    'password' => 'anypassword'
                ],
                'headers' => ['Content-Type: application/json']
            ]);
            
            // Should not return successful login
            $this->assertNotEquals(200, $response['status'], 
                "SQL injection payload succeeded: {$payload}");
            
            // Should not contain SQL error messages
            $this->assertSQLErrorsNotExposed($response['body'], $payload);
            
            // Should return proper error response
            if (isset($response['data']['success'])) {
                $this->assertFalse($response['data']['success'], 
                    "Login should not succeed with SQL injection: {$payload}");
            }
        }
    }
    
    public function testArticleSearchSQLInjection()
    {
        $sqlInjectionPayloads = [
            // Basic search injection
            "' OR '1'='1",
            "' UNION SELECT * FROM users --",
            "' OR 1=1 --",
            
            // Advanced search injection
            "' OR (SELECT COUNT(*) FROM users) > 0 --",
            "' UNION SELECT 1,username,email,password_hash FROM users --",
            "' AND (SELECT SUBSTRING(password_hash,1,1) FROM users WHERE username='admin')='a' --",
            
            // Blind SQL injection in search
            "' OR IF(1=1,SLEEP(5),0) --",
            "' OR LENGTH((SELECT password_hash FROM users LIMIT 1))>10 --"
        ];
        
        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->client->request('GET', $this->baseUrl . '/articles/search', [
                'headers' => [
                    'Content-Type: application/json'
                ]
            ]);
            
            // Should not expose database structure or data
            $this->assertDatabaseDataNotExposed($response['body'], $payload);
            
            // Should not contain SQL errors
            $this->assertSQLErrorsNotExposed($response['body'], $payload);
            
            // Should return valid response structure
            if ($response['status'] === 200 && isset($response['data'])) {
                $this->assertArrayHasKey('success', $response['data'], 
                    "Response should have proper structure for payload: {$payload}");
            }
        }
    }
    
    public function testUserRegistrationSQLInjection()
    {
        $sqlInjectionFields = [
            'username' => [
                "admin'; DROP TABLE users; --",
                "admin' OR '1'='1",
                "admin' UNION SELECT * FROM information_schema.tables --"
            ],
            'email' => [
                "test@example.com'; INSERT INTO users VALUES('hacker','hack@hack.com','hash'); --",
                "test' OR '1'='1' @example.com",
                "test@example.com' UNION SELECT 1,2,3 --"
            ],
            'first_name' => [
                "John'; UPDATE users SET password_hash='hacked' WHERE username='admin'; --",
                "John' OR 1=1 --"
            ],
            'last_name' => [
                "Doe'; DELETE FROM articles; --",
                "Doe' UNION SELECT password_hash FROM users --"
            ]
        ];
        
        foreach ($sqlInjectionFields as $field => $payloads) {
            foreach ($payloads as $payload) {
                $userData = [
                    'username' => 'testuser',
                    'email' => 'test@example.com',
                    'password' => 'SecurePassword123!',
                    'first_name' => 'Test',
                    'last_name' => 'User'
                ];
                
                $userData[$field] = $payload;
                
                $response = $this->client->request('POST', $this->baseUrl . '/auth/register', [
                    'json' => $userData,
                    'headers' => ['Content-Type: application/json']
                ]);
                
                // Should not succeed with malicious data
                if ($response['status'] === 201) {
                    // If registration "succeeds", the data should be properly escaped
                    $this->assertNotContains('DROP TABLE', $response['body'], 
                        "Malicious SQL should not be executed: {$payload}");
                }
                
                // Should not expose SQL errors
                $this->assertSQLErrorsNotExposed($response['body'], $payload);
            }
        }
    }
    
    public function testArticleCreationSQLInjection()
    {
        // First, create a user and get auth token
        $userData = [
            'username' => 'testuser_' . uniqid(),
            'email' => 'test_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'Test',
            'last_name' => 'User'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        if ($registerResponse['status'] !== 201) {
            $this->markTestSkipped('Could not create test user for article creation test');
            return;
        }
        
        $token = $registerResponse['data']['token'];
        
        $sqlInjectionArticles = [
            [
                'title' => "Article'; DROP TABLE articles; --",
                'description' => 'Normal description',
                'price' => 100,
                'category_id' => 1
            ],
            [
                'title' => 'Normal title',
                'description' => "Description'; INSERT INTO users VALUES('hacker','hack@hack.com','hash'); --",
                'price' => 100,
                'category_id' => 1
            ],
            [
                'title' => 'Normal title',
                'description' => 'Normal description',
                'price' => 100,
                'category_id' => "1'; UPDATE articles SET price=0; --"
            ]
        ];
        
        foreach ($sqlInjectionArticles as $articleData) {
            $response = $this->client->request('POST', $this->baseUrl . '/articles', [
                'json' => $articleData,
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token
                ]
            ]);
            
            // Should not expose SQL errors
            $this->assertSQLErrorsNotExposed($response['body'], 
                json_encode($articleData));
            
            // If article creation succeeds, malicious code should be escaped
            if ($response['status'] === 201) {
                $this->assertNotContains('DROP TABLE', $response['body']);
                $this->assertNotContains('INSERT INTO users', $response['body']);
                $this->assertNotContains('UPDATE articles', $response['body']);
            }
        }
    }
    
    public function testOrderBySQLInjection()
    {
        $orderByPayloads = [
            // Column-based injection
            "title'; DROP TABLE articles; --",
            "price, (SELECT password_hash FROM users LIMIT 1)",
            "title UNION SELECT username FROM users",
            
            // Subquery injection
            "(SELECT CASE WHEN (1=1) THEN title ELSE price END)",
            "IF(1=1,title,price)",
            
            // Time-based injection
            "title, SLEEP(5)",
            "BENCHMARK(1000000,MD5(1))",
            
            // Error-based injection
            "EXTRACTVALUE(1,CONCAT(0x7e,(SELECT database()),0x7e))"
        ];
        
        foreach ($orderByPayloads as $payload) {
            $response = $this->client->request('GET', 
                $this->baseUrl . '/articles?sort=' . urlencode($payload), [
                'headers' => ['Content-Type: application/json']
            ]);
            
            // Should not expose SQL errors or database information
            $this->assertSQLErrorsNotExposed($response['body'], $payload);
            $this->assertDatabaseDataNotExposed($response['body'], $payload);
            
            // Should return proper error or ignore malicious sort parameter
            if ($response['status'] !== 200) {
                $this->assertContains($response['status'], [400, 422], 
                    "Invalid sort parameter should return 400 or 422: {$payload}");
            }
        }
    }
    
    public function testLimitOffsetSQLInjection()
    {
        $limitOffsetPayloads = [
            ['limit' => "10; DROP TABLE users; --"],
            ['offset' => "0; INSERT INTO users VALUES('hacker','hack@hack.com','hash'); --"],
            ['limit' => "(SELECT COUNT(*) FROM users)"],
            ['offset' => "IF(1=1,0,1000)"],
            ['limit' => "UNION SELECT 1"],
            ['offset' => "0 UNION SELECT password_hash FROM users LIMIT 1"]
        ];
        
        foreach ($limitOffsetPayloads as $params) {
            $query = http_build_query($params);
            $response = $this->client->request('GET', 
                $this->baseUrl . '/articles?' . $query, [
                'headers' => ['Content-Type: application/json']
            ]);
            
            // Should not expose SQL errors
            $this->assertSQLErrorsNotExposed($response['body'], 
                json_encode($params));
            
            // Should not expose sensitive data
            $this->assertDatabaseDataNotExposed($response['body'], 
                json_encode($params));
            
            // Should handle invalid parameters gracefully
            if ($response['status'] !== 200) {
                $this->assertContains($response['status'], [400, 422], 
                    "Invalid pagination parameter should return 400 or 422");
            }
        }
    }
    
    public function testSecondOrderSQLInjection()
    {
        // Create a user with potentially malicious data that might be stored
        $maliciousUserData = [
            'username' => 'normaluser',
            'email' => 'normal@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => "John'; UPDATE articles SET price=0 WHERE id=1; --",
            'last_name' => 'Doe'
        ];
        
        $registerResponse = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $maliciousUserData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        if ($registerResponse['status'] !== 201) {
            $this->markTestSkipped('Could not create test user for second-order injection test');
            return;
        }
        
        $token = $registerResponse['data']['token'];
        
        // Create an article to see if stored malicious data gets executed
        $articleData = [
            'title' => 'Test Article',
            'description' => 'Test description',
            'price' => 100,
            'category_id' => 1
        ];
        
        $articleResponse = $this->client->request('POST', $this->baseUrl . '/articles', [
            'json' => $articleData,
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);
        
        // Should not expose SQL errors even if malicious data was stored
        $this->assertSQLErrorsNotExposed($articleResponse['body'], 
            'Second-order SQL injection test');
        
        // Retrieve articles to see if malicious stored data affects queries
        $listResponse = $this->client->request('GET', $this->baseUrl . '/articles', [
            'headers' => ['Content-Type: application/json']
        ]);
        
        $this->assertSQLErrorsNotExposed($listResponse['body'], 
            'Article listing with stored malicious data');
    }
    
    public function testParameterPollutionSQLInjection()
    {
        // Test HTTP parameter pollution
        $pollutionTests = [
            'category=1&category=2; DROP TABLE users; --',
            'price=100&price=200; INSERT INTO admin_users VALUES(1); --',
            'limit=10&limit=20; UPDATE articles SET price=0; --'
        ];
        
        foreach ($pollutionTests as $queryString) {
            $response = $this->client->request('GET', 
                $this->baseUrl . '/articles?' . $queryString, [
                'headers' => ['Content-Type: application/json']
            ]);
            
            $this->assertSQLErrorsNotExposed($response['body'], $queryString);
            $this->assertDatabaseDataNotExposed($response['body'], $queryString);
        }
    }
    
    private function assertSQLErrorsNotExposed($responseBody, $payload)
    {
        $sqlErrorPatterns = [
            '/mysql_error/i',
            '/sql syntax/i',
            '/mysql_fetch/i',
            '/mysql_num_rows/i',
            '/mysql_query/i',
            '/mysql_connect/i',
            '/column .* doesn\'t exist/i',
            '/table .* doesn\'t exist/i',
            '/you have an error in your sql syntax/i',
            '/unknown column/i',
            '/mysql server has gone away/i',
            '/access denied for user/i',
            '/duplicate entry/i',
            '/foreign key constraint/i',
            '/cannot add or update a child row/i',
            '/duplicate key name/i',
            '/table .* already exists/i',
            '/duplicate column name/i',
            '/incorrect .* value/i',
            '/data too long for column/i',
            '/out of range value for column/i'
        ];
        
        foreach ($sqlErrorPatterns as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $responseBody, 
                "SQL error exposed in response for payload: {$payload}");
        }
    }
    
    private function assertDatabaseDataNotExposed($responseBody, $payload)
    {
        $sensitiveDataPatterns = [
            '/password_hash/i',
            '/\\$2[ayb]\\$[0-9]{2}\\$/i', // Bcrypt hash pattern
            '/[a-f0-9]{32}/i', // MD5 hash pattern
            '/[a-f0-9]{40}/i', // SHA1 hash pattern
            '/[a-f0-9]{64}/i', // SHA256 hash pattern
            '/information_schema/i',
            '/mysql\\./i',
            '/sys\\./i',
            '/performance_schema/i',
            '/version\\(\\)/i',
            '/@@version/i',
            '/user\\(\\)/i',
            '/database\\(\\)/i',
            '/connection_id/i'
        ];
        
        foreach ($sensitiveDataPatterns as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $responseBody, 
                "Sensitive database information exposed for payload: {$payload}");
        }
    }
    
    public function testNoSQLInjection()
    {
        // Test NoSQL injection patterns (if the app uses any NoSQL features)
        $noSqlPayloads = [
            ['$ne' => ''],
            ['$gt' => ''],
            ['$regex' => '.*'],
            ['$where' => 'function() { return true; }'],
            ['$or' => [['username' => 'admin'], ['role' => 'admin']]],
            ['$and' => [['$gt' => 0], ['$lt' => 999999]]]
        ];
        
        foreach ($noSqlPayloads as $payload) {
            $response = $this->client->request('POST', $this->baseUrl . '/auth/login', [
                'json' => [
                    'email' => $payload,
                    'password' => 'anypassword'
                ],
                'headers' => ['Content-Type: application/json']
            ]);
            
            // Should not allow NoSQL injection
            $this->assertNotEquals(200, $response['status'], 
                "NoSQL injection should not succeed: " . json_encode($payload));
            
            if (isset($response['data']['success'])) {
                $this->assertFalse($response['data']['success'], 
                    "Login should not succeed with NoSQL injection");
            }
        }
    }
    
    public function testBlindSQLInjectionTimingAttack()
    {
        // Test time-based blind SQL injection
        $baselineStart = microtime(true);
        
        $normalResponse = $this->client->request('POST', $this->baseUrl . '/auth/login', [
            'json' => [
                'email' => 'normal@example.com',
                'password' => 'normalpassword'
            ],
            'headers' => ['Content-Type: application/json']
        ]);
        
        $baselineTime = microtime(true) - $baselineStart;
        
        // Test with time-delay injection
        $injectionStart = microtime(true);
        
        $injectionResponse = $this->client->request('POST', $this->baseUrl . '/auth/login', [
            'json' => [
                'email' => "test' OR SLEEP(3) --",
                'password' => 'anypassword'
            ],
            'headers' => ['Content-Type: application/json']
        ]);
        
        $injectionTime = microtime(true) - $injectionStart;
        
        // The injection should not cause a significant delay
        // (indicating that SLEEP() was not executed)
        $this->assertLessThan($baselineTime + 2, $injectionTime, 
            "Time-based SQL injection appears to be working (response took too long)");
    }
}