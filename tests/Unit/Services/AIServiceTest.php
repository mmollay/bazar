<?php

require_once __DIR__ . '/../../../backend/services/AIService.php';

class AIServiceTest extends TestCase
{
    private $aiService;
    private $testImagePath;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        global $testPdo;
        
        // Mock Google Vision API
        if (!class_exists('AIService')) {
            $this->createAIServiceMock();
        }
        
        $this->aiService = new AIService();
        
        // Create test image
        $this->testImagePath = sys_get_temp_dir() . '/test_image_' . uniqid() . '.jpg';
        $this->createTestImage($this->testImagePath);
    }
    
    protected function tearDown(): void
    {
        // Clean up test image
        if (file_exists($this->testImagePath)) {
            unlink($this->testImagePath);
        }
        parent::tearDown();
    }
    
    private function createAIServiceMock()
    {
        eval('
            class AIService {
                private $visionClient;
                private $confidenceCalculator;
                
                public function __construct() {
                    // Mock initialization
                }
                
                public function analyzeImage($imagePath) {
                    if (!file_exists($imagePath)) {
                        return [
                            "success" => false,
                            "message" => "Image file not found"
                        ];
                    }
                    
                    // Mock Google Vision API response
                    $mockResponse = [
                        "labels" => [
                            ["description" => "Smartphone", "score" => 0.95],
                            ["description" => "Mobile phone", "score" => 0.88],
                            ["description" => "Electronics", "score" => 0.92],
                            ["description" => "Communication Device", "score" => 0.85]
                        ],
                        "text" => [
                            "iPhone 12",
                            "128GB",
                            "Unlocked"
                        ],
                        "objects" => [
                            ["name" => "Mobile phone", "score" => 0.94]
                        ]
                    ];
                    
                    return [
                        "success" => true,
                        "analysis" => $mockResponse,
                        "confidence" => $this->calculateOverallConfidence($mockResponse)
                    ];
                }
                
                public function generateSuggestions($analysis, $context = []) {
                    if (!$analysis || !isset($analysis["labels"])) {
                        return [
                            "success" => false,
                            "message" => "Invalid analysis data"
                        ];
                    }
                    
                    $suggestions = [
                        "title" => $this->suggestTitle($analysis),
                        "description" => $this->suggestDescription($analysis),
                        "category" => $this->suggestCategory($analysis),
                        "price_range" => $this->suggestPriceRange($analysis),
                        "condition" => $this->suggestCondition($analysis),
                        "tags" => $this->suggestTags($analysis)
                    ];
                    
                    return [
                        "success" => true,
                        "suggestions" => $suggestions,
                        "confidence" => $this->calculateSuggestionConfidence($suggestions)
                    ];
                }
                
                public function processMultipleImages($imagePaths) {
                    $results = [];
                    $overallConfidence = 0;
                    $processedCount = 0;
                    
                    foreach ($imagePaths as $imagePath) {
                        $analysis = $this->analyzeImage($imagePath);
                        if ($analysis["success"]) {
                            $results[] = $analysis;
                            $overallConfidence += $analysis["confidence"];
                            $processedCount++;
                        }
                    }
                    
                    if ($processedCount === 0) {
                        return [
                            "success" => false,
                            "message" => "No images could be processed"
                        ];
                    }
                    
                    // Aggregate results
                    $aggregatedAnalysis = $this->aggregateAnalysis($results);
                    
                    return [
                        "success" => true,
                        "analysis" => $aggregatedAnalysis,
                        "individual_results" => $results,
                        "confidence" => $overallConfidence / $processedCount
                    ];
                }
                
                public function validateImageQuality($imagePath) {
                    if (!file_exists($imagePath)) {
                        return [
                            "valid" => false,
                            "issues" => ["File not found"]
                        ];
                    }
                    
                    $imageInfo = getimagesize($imagePath);
                    if (!$imageInfo) {
                        return [
                            "valid" => false,
                            "issues" => ["Invalid image format"]
                        ];
                    }
                    
                    $issues = [];
                    
                    // Check minimum resolution
                    if ($imageInfo[0] < 800 || $imageInfo[1] < 600) {
                        $issues[] = "Image resolution too low (minimum 800x600)";
                    }
                    
                    // Check file size
                    $fileSize = filesize($imagePath);
                    if ($fileSize > 5 * 1024 * 1024) { // 5MB
                        $issues[] = "File size too large (maximum 5MB)";
                    }
                    
                    if ($fileSize < 10 * 1024) { // 10KB
                        $issues[] = "File size too small (minimum 10KB)";
                    }
                    
                    // Check aspect ratio
                    $aspectRatio = $imageInfo[0] / $imageInfo[1];
                    if ($aspectRatio < 0.5 || $aspectRatio > 2.0) {
                        $issues[] = "Unusual aspect ratio (should be between 0.5 and 2.0)";
                    }
                    
                    return [
                        "valid" => empty($issues),
                        "issues" => $issues,
                        "metadata" => [
                            "width" => $imageInfo[0],
                            "height" => $imageInfo[1],
                            "type" => $imageInfo["mime"],
                            "size" => $fileSize
                        ]
                    ];
                }
                
                public function createAISuggestion($articleData, $analysis, $userId) {
                    global $testPdo;
                    
                    $stmt = $testPdo->prepare("
                        INSERT INTO ai_suggestions (user_id, article_data, analysis_data, confidence_score, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    
                    if ($stmt->execute([
                        $userId,
                        json_encode($articleData),
                        json_encode($analysis),
                        $analysis["confidence"] ?? 0.5
                    ])) {
                        return [
                            "success" => true,
                            "suggestion_id" => $testPdo->lastInsertId()
                        ];
                    }
                    
                    return [
                        "success" => false,
                        "message" => "Failed to save AI suggestion"
                    ];
                }
                
                private function suggestTitle($analysis) {
                    $labels = $analysis["labels"];
                    $text = $analysis["text"] ?? [];
                    
                    // Try to use extracted text first
                    if (!empty($text)) {
                        $brandModel = array_filter($text, function($t) {
                            return preg_match("/^[A-Za-z0-9\s]{3,30}$/", $t);
                        });
                        if (!empty($brandModel)) {
                            return array_values($brandModel)[0];
                        }
                    }
                    
                    // Fallback to labels
                    if (!empty($labels)) {
                        return $labels[0]["description"];
                    }
                    
                    return "Item for Sale";
                }
                
                private function suggestDescription($analysis) {
                    $labels = $analysis["labels"];
                    $text = $analysis["text"] ?? [];
                    
                    $description = "";
                    
                    if (!empty($labels)) {
                        $primaryLabel = $labels[0]["description"];
                        $description .= "This is a {$primaryLabel}";
                        
                        if (count($labels) > 1) {
                            $features = array_slice($labels, 1, 2);
                            $featureNames = array_map(function($f) { return $f["description"]; }, $features);
                            $description .= " featuring " . implode(" and ", $featureNames);
                        }
                    }
                    
                    if (!empty($text)) {
                        $specs = array_filter($text, function($t) {
                            return preg_match("/\d+(GB|TB|MP|inch|Hz)/i", $t);
                        });
                        if (!empty($specs)) {
                            $description .= ". Specifications: " . implode(", ", $specs);
                        }
                    }
                    
                    return $description ?: "Quality item in good condition.";
                }
                
                private function suggestCategory($analysis) {
                    $labels = $analysis["labels"];
                    
                    $categoryMap = [
                        "smartphone" => "Electronics",
                        "mobile phone" => "Electronics",
                        "laptop" => "Electronics",
                        "computer" => "Electronics",
                        "clothing" => "Fashion",
                        "shirt" => "Fashion",
                        "shoes" => "Fashion",
                        "book" => "Books",
                        "furniture" => "Home & Garden",
                        "car" => "Vehicles",
                        "bicycle" => "Sports"
                    ];
                    
                    foreach ($labels as $label) {
                        $labelLower = strtolower($label["description"]);
                        foreach ($categoryMap as $keyword => $category) {
                            if (strpos($labelLower, $keyword) !== false) {
                                return $category;
                            }
                        }
                    }
                    
                    return "Other";
                }
                
                private function suggestPriceRange($analysis) {
                    $labels = $analysis["labels"];
                    
                    // Basic price estimation based on detected items
                    $priceRanges = [
                        "smartphone" => ["min" => 100, "max" => 1000],
                        "laptop" => ["min" => 300, "max" => 2000],
                        "book" => ["min" => 5, "max" => 50],
                        "clothing" => ["min" => 10, "max" => 100],
                        "furniture" => ["min" => 50, "max" => 500]
                    ];
                    
                    foreach ($labels as $label) {
                        $labelLower = strtolower($label["description"]);
                        foreach ($priceRanges as $item => $range) {
                            if (strpos($labelLower, $item) !== false) {
                                return $range;
                            }
                        }
                    }
                    
                    return ["min" => 10, "max" => 100];
                }
                
                private function suggestCondition($analysis) {
                    // This would analyze image quality, wear signs, etc.
                    // For now, return a default suggestion
                    return "good";
                }
                
                private function suggestTags($analysis) {
                    $labels = $analysis["labels"];
                    $tags = [];
                    
                    foreach (array_slice($labels, 0, 5) as $label) {
                        $tags[] = strtolower($label["description"]);
                    }
                    
                    return $tags;
                }
                
                private function calculateOverallConfidence($analysis) {
                    if (empty($analysis["labels"])) {
                        return 0.0;
                    }
                    
                    $totalScore = 0;
                    foreach ($analysis["labels"] as $label) {
                        $totalScore += $label["score"];
                    }
                    
                    return $totalScore / count($analysis["labels"]);
                }
                
                private function calculateSuggestionConfidence($suggestions) {
                    // Calculate confidence based on completeness and quality of suggestions
                    $score = 0.5; // Base score
                    
                    if (strlen($suggestions["title"]) > 5) $score += 0.1;
                    if (strlen($suggestions["description"]) > 20) $score += 0.1;
                    if ($suggestions["category"] !== "Other") $score += 0.2;
                    if (!empty($suggestions["tags"])) $score += 0.1;
                    
                    return min($score, 1.0);
                }
                
                private function aggregateAnalysis($results) {
                    $aggregated = [
                        "labels" => [],
                        "text" => [],
                        "objects" => []
                    ];
                    
                    $labelCounts = [];
                    
                    foreach ($results as $result) {
                        $analysis = $result["analysis"];
                        
                        // Aggregate labels
                        foreach ($analysis["labels"] as $label) {
                            $desc = $label["description"];
                            if (!isset($labelCounts[$desc])) {
                                $labelCounts[$desc] = ["count" => 0, "totalScore" => 0];
                            }
                            $labelCounts[$desc]["count"]++;
                            $labelCounts[$desc]["totalScore"] += $label["score"];
                        }
                        
                        // Aggregate text
                        if (!empty($analysis["text"])) {
                            $aggregated["text"] = array_merge($aggregated["text"], $analysis["text"]);
                        }
                        
                        // Aggregate objects
                        if (!empty($analysis["objects"])) {
                            $aggregated["objects"] = array_merge($aggregated["objects"], $analysis["objects"]);
                        }
                    }
                    
                    // Convert label counts to final labels
                    foreach ($labelCounts as $desc => $data) {
                        $aggregated["labels"][] = [
                            "description" => $desc,
                            "score" => $data["totalScore"] / $data["count"],
                            "frequency" => $data["count"]
                        ];
                    }
                    
                    // Sort by score
                    usort($aggregated["labels"], function($a, $b) {
                        return $b["score"] <=> $a["score"];
                    });
                    
                    // Remove duplicates from text
                    $aggregated["text"] = array_unique($aggregated["text"]);
                    
                    return $aggregated;
                }
            }
        ');
    }
    
    private function createTestImage($path)
    {
        // Create a simple test image
        $image = imagecreate(800, 600);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        
        imagefill($image, 0, 0, $white);
        imagestring($image, 5, 350, 290, 'Test Image', $black);
        
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }
    
    public function testAnalyzeImageSuccess()
    {
        $result = $this->aiService->analyzeImage($this->testImagePath);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('analysis', $result);
        $this->assertArrayHasKey('confidence', $result);
        
        $analysis = $result['analysis'];
        $this->assertArrayHasKey('labels', $analysis);
        $this->assertIsArray($analysis['labels']);
        $this->assertGreaterThan(0, count($analysis['labels']));
        
        // Verify label structure
        foreach ($analysis['labels'] as $label) {
            $this->assertArrayHasKey('description', $label);
            $this->assertArrayHasKey('score', $label);
            $this->assertIsFloat($label['score']);
            $this->assertGreaterThan(0, $label['score']);
            $this->assertLessThanOrEqual(1, $label['score']);
        }
    }
    
    public function testAnalyzeImageFileNotFound()
    {
        $result = $this->aiService->analyzeImage('/nonexistent/path/image.jpg');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Image file not found', $result['message']);
    }
    
    public function testGenerateSuggestionsSuccess()
    {
        $analysis = [
            'labels' => [
                ['description' => 'Smartphone', 'score' => 0.95],
                ['description' => 'Electronics', 'score' => 0.88]
            ],
            'text' => ['iPhone 12', '128GB']
        ];
        
        $result = $this->aiService->generateSuggestions($analysis);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('suggestions', $result);
        $this->assertArrayHasKey('confidence', $result);
        
        $suggestions = $result['suggestions'];
        $this->assertArrayHasKey('title', $suggestions);
        $this->assertArrayHasKey('description', $suggestions);
        $this->assertArrayHasKey('category', $suggestions);
        $this->assertArrayHasKey('price_range', $suggestions);
        $this->assertArrayHasKey('condition', $suggestions);
        $this->assertArrayHasKey('tags', $suggestions);
        
        // Verify suggestions are reasonable
        $this->assertNotEmpty($suggestions['title']);
        $this->assertNotEmpty($suggestions['description']);
        $this->assertEquals('Electronics', $suggestions['category']);
        $this->assertIsArray($suggestions['tags']);
    }
    
    public function testGenerateSuggestionsInvalidAnalysis()
    {
        $result = $this->aiService->generateSuggestions(null);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid analysis data', $result['message']);
    }
    
    public function testProcessMultipleImages()
    {
        // Create additional test images
        $imagePaths = [$this->testImagePath];
        for ($i = 0; $i < 2; $i++) {
            $additionalImagePath = sys_get_temp_dir() . '/test_image_' . uniqid() . '.jpg';
            $this->createTestImage($additionalImagePath);
            $imagePaths[] = $additionalImagePath;
        }
        
        $result = $this->aiService->processMultipleImages($imagePaths);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('analysis', $result);
        $this->assertArrayHasKey('individual_results', $result);
        $this->assertArrayHasKey('confidence', $result);
        
        $this->assertEquals(3, count($result['individual_results']));
        $this->assertIsFloat($result['confidence']);
        
        // Clean up additional test images
        foreach (array_slice($imagePaths, 1) as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
    
    public function testProcessMultipleImagesNoValidImages()
    {
        $invalidPaths = ['/nonexistent1.jpg', '/nonexistent2.jpg'];
        
        $result = $this->aiService->processMultipleImages($invalidPaths);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('No images could be processed', $result['message']);
    }
    
    public function testValidateImageQualityValid()
    {
        $result = $this->aiService->validateImageQuality($this->testImagePath);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['issues']);
        $this->assertArrayHasKey('metadata', $result);
        
        $metadata = $result['metadata'];
        $this->assertEquals(800, $metadata['width']);
        $this->assertEquals(600, $metadata['height']);
        $this->assertEquals('image/jpeg', $metadata['type']);
        $this->assertGreaterThan(0, $metadata['size']);
    }
    
    public function testValidateImageQualityInvalid()
    {
        $result = $this->aiService->validateImageQuality('/nonexistent.jpg');
        
        $this->assertFalse($result['valid']);
        $this->assertContains('File not found', $result['issues']);
    }
    
    public function testValidateImageQualityLowResolution()
    {
        // Create a low resolution test image
        $lowResPath = sys_get_temp_dir() . '/test_low_res_' . uniqid() . '.jpg';
        $image = imagecreate(400, 300); // Below minimum 800x600
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        imagejpeg($image, $lowResPath, 90);
        imagedestroy($image);
        
        $result = $this->aiService->validateImageQuality($lowResPath);
        
        $this->assertFalse($result['valid']);
        $this->assertContains('Image resolution too low (minimum 800x600)', $result['issues']);
        
        unlink($lowResPath);
    }
    
    public function testCreateAISuggestion()
    {
        global $testPdo;
        
        // Create a test user first
        $stmt = $testPdo->prepare("
            INSERT INTO users (username, email, password_hash, first_name, last_name, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute(['testuser', 'test@example.com', 'hash', 'Test', 'User']);
        $userId = $testPdo->lastInsertId();
        
        $articleData = [
            'title' => 'iPhone 12',
            'description' => 'Great condition iPhone',
            'price' => 500,
            'category' => 'Electronics'
        ];
        
        $analysis = [
            'labels' => [['description' => 'Smartphone', 'score' => 0.95]],
            'confidence' => 0.85
        ];
        
        $result = $this->aiService->createAISuggestion($articleData, $analysis, $userId);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('suggestion_id', $result);
        
        // Verify in database
        $stmt = $testPdo->prepare("SELECT * FROM ai_suggestions WHERE id = ?");
        $stmt->execute([$result['suggestion_id']]);
        $suggestion = $stmt->fetch();
        
        $this->assertNotNull($suggestion);
        $this->assertEquals($userId, $suggestion['user_id']);
        $this->assertEquals(0.85, (float)$suggestion['confidence_score']);
    }
    
    public function testTitleSuggestionFromText()
    {
        $analysis = [
            'labels' => [['description' => 'Electronics', 'score' => 0.8]],
            'text' => ['iPhone 12 Pro', '256GB', 'Gold']
        ];
        
        $result = $this->aiService->generateSuggestions($analysis);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('iPhone 12 Pro', $result['suggestions']['title']);
    }
    
    public function testCategorySuggestionMapping()
    {
        $testCases = [
            ['smartphone'] => 'Electronics',
            ['laptop', 'computer'] => 'Electronics',
            ['shirt', 'clothing'] => 'Fashion',
            ['book'] => 'Books',
            ['furniture'] => 'Home & Garden',
            ['car', 'vehicle'] => 'Vehicles',
            ['unknown item'] => 'Other'
        ];
        
        foreach ($testCases as $labels => $expectedCategory) {
            $analysis = [
                'labels' => array_map(function($label) {
                    return ['description' => $label, 'score' => 0.9];
                }, $labels)
            ];
            
            $result = $this->aiService->generateSuggestions($analysis);
            $this->assertEquals($expectedCategory, $result['suggestions']['category']);
        }
    }
    
    public function testPriceRangeSuggestion()
    {
        $analysis = [
            'labels' => [['description' => 'Smartphone', 'score' => 0.95]]
        ];
        
        $result = $this->aiService->generateSuggestions($analysis);
        
        $this->assertTrue($result['success']);
        $priceRange = $result['suggestions']['price_range'];
        
        $this->assertArrayHasKey('min', $priceRange);
        $this->assertArrayHasKey('max', $priceRange);
        $this->assertGreaterThan(0, $priceRange['min']);
        $this->assertGreaterThan($priceRange['min'], $priceRange['max']);
    }
    
    public function testConfidenceCalculation()
    {
        $highConfidenceAnalysis = [
            'labels' => [
                ['description' => 'Smartphone', 'score' => 0.98],
                ['description' => 'iPhone', 'score' => 0.96],
                ['description' => 'Electronics', 'score' => 0.94]
            ]
        ];
        
        $result = $this->aiService->analyzeImage($this->testImagePath);
        $this->assertIsFloat($result['confidence']);
        $this->assertGreaterThan(0, $result['confidence']);
        $this->assertLessThanOrEqual(1, $result['confidence']);
    }
    
    public function testAnalysisAggregation()
    {
        $imagePaths = [$this->testImagePath];
        
        // Create additional test images
        for ($i = 0; $i < 2; $i++) {
            $additionalImagePath = sys_get_temp_dir() . '/test_image_' . uniqid() . '.jpg';
            $this->createTestImage($additionalImagePath);
            $imagePaths[] = $additionalImagePath;
        }
        
        $result = $this->aiService->processMultipleImages($imagePaths);
        
        $this->assertTrue($result['success']);
        
        $aggregatedAnalysis = $result['analysis'];
        $this->assertArrayHasKey('labels', $aggregatedAnalysis);
        
        // Check that labels have frequency information
        foreach ($aggregatedAnalysis['labels'] as $label) {
            $this->assertArrayHasKey('frequency', $label);
            $this->assertGreaterThan(0, $label['frequency']);
        }
        
        // Clean up
        foreach (array_slice($imagePaths, 1) as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}