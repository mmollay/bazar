<?php
/**
 * AI Service for image analysis and article auto-fill
 * Integrates with Google Vision API and provides fallback mechanisms
 */

class AIService {
    private $db;
    private $googleVisionEnabled;
    private $googleApiKey;
    private $models;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->googleVisionEnabled = !empty($_ENV['GOOGLE_VISION_API_KEY']);
        $this->googleApiKey = $_ENV['GOOGLE_VISION_API_KEY'] ?? '';
        $this->loadModels();
    }
    
    private function loadModels() {
        $stmt = $this->db->prepare("SELECT * FROM ai_models WHERE is_active = 1");
        $stmt->execute();
        $this->models = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    
    /**
     * Analyze image and extract information for article creation
     */
    public function analyzeImage($imagePath, $options = []) {
        $analysis = [
            'objects' => [],
            'labels' => [],
            'text' => [],
            'colors' => [],
            'landmarks' => [],
            'faces' => false,
            'explicit_content' => [],
            'suggested_category' => null,
            'suggested_title' => '',
            'suggested_description' => '',
            'suggested_price' => null,
            'suggested_condition' => 'good',
            'confidence_scores' => []
        ];
        
        try {
            // Primary: Google Vision API
            if ($this->googleVisionEnabled) {
                $visionAnalysis = $this->analyzeWithGoogleVision($imagePath, $options);
                if ($visionAnalysis) {
                    $analysis = array_merge($analysis, $visionAnalysis);
                    Logger::info('Image analyzed with Google Vision', ['path' => $imagePath]);
                } else {
                    throw new Exception('Google Vision API failed');
                }
            } else {
                throw new Exception('Google Vision API not enabled');
            }
        } catch (Exception $e) {
            Logger::warning('Google Vision failed, using fallback', ['error' => $e->getMessage()]);
            
            // Fallback: Local analysis
            $fallbackAnalysis = $this->analyzeWithLocalMethods($imagePath, $options);
            $analysis = array_merge($analysis, $fallbackAnalysis);
        }
        
        // Generate article suggestions based on analysis
        $suggestions = $this->generateArticleSuggestions($analysis, $options);
        $analysis = array_merge($analysis, $suggestions);
        
        return $analysis;
    }
    
    /**
     * Analyze image with Google Vision API
     */
    private function analyzeWithGoogleVision($imagePath, $options) {
        if (!$this->googleVisionEnabled) {
            return false;
        }
        
        $imageData = base64_encode(file_get_contents($imagePath));
        
        $requestData = [
            'requests' => [[
                'image' => ['content' => $imageData],
                'features' => [
                    ['type' => 'OBJECT_LOCALIZATION', 'maxResults' => 10],
                    ['type' => 'LABEL_DETECTION', 'maxResults' => 20],
                    ['type' => 'TEXT_DETECTION', 'maxResults' => 10],
                    ['type' => 'IMAGE_PROPERTIES'],
                    ['type' => 'LANDMARK_DETECTION', 'maxResults' => 5],
                    ['type' => 'FACE_DETECTION', 'maxResults' => 1],
                    ['type' => 'SAFE_SEARCH_DETECTION']
                ]
            ]]
        ];
        
        $url = "https://vision.googleapis.com/v1/images:annotate?key=" . $this->googleApiKey;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            Logger::error('Google Vision API error', ['http_code' => $httpCode, 'response' => $response]);
            return false;
        }
        
        $data = json_decode($response, true);
        if (!isset($data['responses'][0])) {
            return false;
        }
        
        return $this->parseGoogleVisionResponse($data['responses'][0]);
    }
    
    /**
     * Parse Google Vision API response
     */
    private function parseGoogleVisionResponse($response) {
        $analysis = [
            'objects' => [],
            'labels' => [],
            'text' => [],
            'colors' => [],
            'landmarks' => [],
            'faces' => false,
            'explicit_content' => []
        ];
        
        // Object localization
        if (isset($response['localizedObjectAnnotations'])) {
            foreach ($response['localizedObjectAnnotations'] as $object) {
                $analysis['objects'][] = [
                    'name' => $object['name'],
                    'confidence' => $object['score'],
                    'bounds' => $object['boundingPoly'] ?? null
                ];
            }
        }
        
        // Label detection
        if (isset($response['labelAnnotations'])) {
            foreach ($response['labelAnnotations'] as $label) {
                $analysis['labels'][] = [
                    'name' => $label['description'],
                    'confidence' => $label['score']
                ];
            }
        }
        
        // Text detection (OCR)
        if (isset($response['textAnnotations'])) {
            foreach ($response['textAnnotations'] as $text) {
                if (isset($text['description'])) {
                    $analysis['text'][] = [
                        'text' => $text['description'],
                        'confidence' => 1.0,
                        'bounds' => $text['boundingPoly'] ?? null
                    ];
                }
            }
        }
        
        // Color analysis
        if (isset($response['imagePropertiesAnnotation']['dominantColors']['colors'])) {
            foreach ($response['imagePropertiesAnnotation']['dominantColors']['colors'] as $color) {
                $analysis['colors'][] = [
                    'red' => $color['color']['red'] ?? 0,
                    'green' => $color['color']['green'] ?? 0,
                    'blue' => $color['color']['blue'] ?? 0,
                    'score' => $color['score'] ?? 0,
                    'coverage' => $color['pixelFraction'] ?? 0
                ];
            }
        }
        
        // Landmark detection
        if (isset($response['landmarkAnnotations'])) {
            foreach ($response['landmarkAnnotations'] as $landmark) {
                $analysis['landmarks'][] = [
                    'name' => $landmark['description'],
                    'confidence' => $landmark['score']
                ];
            }
        }
        
        // Face detection
        $analysis['faces'] = isset($response['faceAnnotations']) && !empty($response['faceAnnotations']);
        
        // Safe search
        if (isset($response['safeSearchAnnotation'])) {
            $analysis['explicit_content'] = [
                'adult' => $response['safeSearchAnnotation']['adult'] ?? 'UNKNOWN',
                'spoof' => $response['safeSearchAnnotation']['spoof'] ?? 'UNKNOWN',
                'medical' => $response['safeSearchAnnotation']['medical'] ?? 'UNKNOWN',
                'violence' => $response['safeSearchAnnotation']['violence'] ?? 'UNKNOWN',
                'racy' => $response['safeSearchAnnotation']['racy'] ?? 'UNKNOWN'
            ];
        }
        
        return $analysis;
    }
    
    /**
     * Fallback analysis using local methods
     */
    private function analyzeWithLocalMethods($imagePath, $options) {
        $analysis = [
            'objects' => [],
            'labels' => [],
            'text' => [],
            'colors' => [],
            'landmarks' => [],
            'faces' => false,
            'explicit_content' => []
        ];
        
        try {
            // Basic image analysis
            $imageInfo = getimagesize($imagePath);
            if ($imageInfo) {
                $analysis['image_properties'] = [
                    'width' => $imageInfo[0],
                    'height' => $imageInfo[1],
                    'type' => $imageInfo[2],
                    'mime' => $imageInfo['mime']
                ];
            }
            
            // Color analysis using GD library
            if (extension_loaded('gd')) {
                $colors = $this->extractDominantColors($imagePath);
                $analysis['colors'] = $colors;
            }
            
            // Basic pattern recognition (very simplified)
            $labels = $this->basicPatternRecognition($imagePath);
            $analysis['labels'] = $labels;
            
        } catch (Exception $e) {
            Logger::warning('Local image analysis failed', ['error' => $e->getMessage()]);
        }
        
        return $analysis;
    }
    
    /**
     * Extract dominant colors from image
     */
    private function extractDominantColors($imagePath, $numColors = 5) {
        $colors = [];
        
        try {
            $image = null;
            $imageInfo = getimagesize($imagePath);
            
            switch ($imageInfo[2]) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($imagePath);
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($imagePath);
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif($imagePath);
                    break;
            }
            
            if (!$image) {
                return $colors;
            }
            
            $width = imagesx($image);
            $height = imagesy($image);
            
            $colorCounts = [];
            $sampleRate = max(1, min($width, $height) / 100); // Sample every N pixels
            
            for ($x = 0; $x < $width; $x += $sampleRate) {
                for ($y = 0; $y < $height; $y += $sampleRate) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    
                    // Quantize colors to reduce noise
                    $r = round($r / 32) * 32;
                    $g = round($g / 32) * 32;
                    $b = round($b / 32) * 32;
                    
                    $color = sprintf('#%02x%02x%02x', $r, $g, $b);
                    $colorCounts[$color] = ($colorCounts[$color] ?? 0) + 1;
                }
            }
            
            arsort($colorCounts);
            $totalPixels = array_sum($colorCounts);
            
            $i = 0;
            foreach ($colorCounts as $color => $count) {
                if ($i >= $numColors) break;
                
                list($r, $g, $b) = sscanf($color, '#%02x%02x%02x');
                $colors[] = [
                    'red' => $r,
                    'green' => $g,
                    'blue' => $b,
                    'score' => $count / $totalPixels,
                    'coverage' => $count / $totalPixels
                ];
                $i++;
            }
            
            imagedestroy($image);
            
        } catch (Exception $e) {
            Logger::warning('Color extraction failed', ['error' => $e->getMessage()]);
        }
        
        return $colors;
    }
    
    /**
     * Basic pattern recognition (placeholder for more sophisticated methods)
     */
    private function basicPatternRecognition($imagePath) {
        $labels = [];
        
        // This is a very basic implementation
        // In a real system, you would use machine learning models
        
        $filename = strtolower(basename($imagePath));
        
        // Simple keyword detection from filename
        $patterns = [
            'phone' => ['phone', 'smartphone', 'mobile', 'iphone', 'android'],
            'car' => ['car', 'auto', 'vehicle', 'mercedes', 'bmw', 'audi'],
            'furniture' => ['table', 'chair', 'sofa', 'bed', 'desk'],
            'clothing' => ['shirt', 'dress', 'pants', 'jacket', 'shoes'],
            'book' => ['book', 'novel', 'textbook', 'manual'],
            'electronics' => ['laptop', 'computer', 'tablet', 'tv', 'monitor']
        ];
        
        foreach ($patterns as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($filename, $keyword) !== false) {
                    $labels[] = [
                        'name' => ucfirst($category),
                        'confidence' => 0.6 // Low confidence for filename-based detection
                    ];
                    break;
                }
            }
        }
        
        return $labels;
    }
    
    /**
     * Generate article suggestions based on AI analysis
     */
    private function generateArticleSuggestions($analysis, $options = []) {
        $suggestions = [
            'suggested_category' => null,
            'suggested_title' => '',
            'suggested_description' => '',
            'suggested_price' => null,
            'suggested_condition' => 'good',
            'confidence_scores' => []
        ];
        
        // Category suggestion
        $categoryData = $this->suggestCategory($analysis);
        $suggestions['suggested_category'] = $categoryData['category'];
        $suggestions['confidence_scores']['category'] = $categoryData['confidence'];
        
        // Title suggestion
        $titleData = $this->suggestTitle($analysis);
        $suggestions['suggested_title'] = $titleData['title'];
        $suggestions['confidence_scores']['title'] = $titleData['confidence'];
        
        // Description suggestion
        $descriptionData = $this->suggestDescription($analysis);
        $suggestions['suggested_description'] = $descriptionData['description'];
        $suggestions['confidence_scores']['description'] = $descriptionData['confidence'];
        
        // Price estimation
        if ($suggestions['suggested_category']) {
            $priceData = $this->estimatePrice($analysis, $suggestions['suggested_category']);
            $suggestions['suggested_price'] = $priceData['price'];
            $suggestions['confidence_scores']['price'] = $priceData['confidence'];
        }
        
        // Condition assessment
        $conditionData = $this->assessCondition($analysis);
        $suggestions['suggested_condition'] = $conditionData['condition'];
        $suggestions['confidence_scores']['condition'] = $conditionData['confidence'];
        
        return $suggestions;
    }
    
    /**
     * Suggest category based on detected objects and labels
     */
    private function suggestCategory($analysis) {
        $categoryConfidence = [];
        
        // Load categories with AI keywords
        $stmt = $this->db->prepare("SELECT id, name, ai_keywords FROM categories WHERE is_active = 1");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        foreach ($categories as $category) {
            $keywords = json_decode($category['ai_keywords'], true) ?: [];
            $score = 0;
            
            // Check objects
            foreach ($analysis['objects'] as $object) {
                foreach ($keywords as $keyword) {
                    if (stripos($object['name'], $keyword) !== false) {
                        $score += $object['confidence'] * 2; // Objects have higher weight
                    }
                }
            }
            
            // Check labels
            foreach ($analysis['labels'] as $label) {
                foreach ($keywords as $keyword) {
                    if (stripos($label['name'], $keyword) !== false) {
                        $score += $label['confidence'];
                    }
                }
            }
            
            if ($score > 0) {
                $categoryConfidence[$category['id']] = [
                    'name' => $category['name'],
                    'score' => $score
                ];
            }
        }
        
        if (empty($categoryConfidence)) {
            return ['category' => null, 'confidence' => 0];
        }
        
        // Get category with highest score
        uasort($categoryConfidence, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        $topCategory = reset($categoryConfidence);
        return [
            'category' => key($categoryConfidence),
            'confidence' => min($topCategory['score'], 1.0)
        ];
    }
    
    /**
     * Suggest title based on detected objects
     */
    private function suggestTitle($analysis) {
        $titleParts = [];
        $confidence = 0;
        
        // Use top objects for title
        $topObjects = array_slice($analysis['objects'], 0, 3);
        foreach ($topObjects as $object) {
            if ($object['confidence'] > 0.5) {
                $titleParts[] = ucfirst($object['name']);
                $confidence += $object['confidence'];
            }
        }
        
        if (empty($titleParts)) {
            // Fallback to labels
            $topLabels = array_slice($analysis['labels'], 0, 2);
            foreach ($topLabels as $label) {
                if ($label['confidence'] > 0.3) {
                    $titleParts[] = ucfirst($label['name']);
                    $confidence += $label['confidence'];
                }
            }
        }
        
        $title = implode(' ', $titleParts);
        if (empty($title)) {
            $title = 'Item for Sale';
            $confidence = 0.1;
        }
        
        return [
            'title' => $title,
            'confidence' => min($confidence / count($titleParts ?: [1]), 1.0)
        ];
    }
    
    /**
     * Suggest description based on analysis
     */
    private function suggestDescription($analysis) {
        $description = '';
        $confidence = 0;
        
        $descriptors = [];
        
        // Add object information
        foreach (array_slice($analysis['objects'], 0, 5) as $object) {
            if ($object['confidence'] > 0.4) {
                $descriptors[] = $object['name'];
            }
        }
        
        // Add color information
        if (!empty($analysis['colors'])) {
            $dominantColor = $analysis['colors'][0];
            $colorName = $this->getColorName($dominantColor['red'], $dominantColor['green'], $dominantColor['blue']);
            if ($colorName) {
                $descriptors[] = $colorName;
            }
        }
        
        // Add text if found
        if (!empty($analysis['text'])) {
            $text = trim($analysis['text'][0]['text'] ?? '');
            if (strlen($text) > 5 && strlen($text) < 100) {
                $descriptors[] = "with text: \"$text\"";
            }
        }
        
        if (!empty($descriptors)) {
            $description = 'This item appears to be ' . implode(', ', array_unique($descriptors)) . '.';
            $confidence = 0.6;
        } else {
            $description = 'Please add a detailed description of this item.';
            $confidence = 0.1;
        }
        
        return [
            'description' => $description,
            'confidence' => $confidence
        ];
    }
    
    /**
     * Estimate price based on category and analysis
     */
    private function estimatePrice($analysis, $categoryId) {
        // This would ideally use machine learning based on historical data
        // For now, we'll use simple heuristics
        
        $stmt = $this->db->prepare("
            SELECT AVG(original_price) as avg_price, 
                   MIN(original_price) as min_price, 
                   MAX(original_price) as max_price
            FROM price_history 
            WHERE category_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 6 MONTH)
        ");
        $stmt->execute([$categoryId]);
        $priceStats = $stmt->fetch();
        
        if ($priceStats && $priceStats['avg_price']) {
            $basePrice = $priceStats['avg_price'];
            
            // Adjust based on condition (would be more sophisticated in real implementation)
            $conditionMultiplier = 1.0;
            
            $estimatedPrice = $basePrice * $conditionMultiplier;
            
            return [
                'price' => round($estimatedPrice, 2),
                'confidence' => 0.5 // Medium confidence for simple estimation
            ];
        }
        
        return [
            'price' => null,
            'confidence' => 0
        ];
    }
    
    /**
     * Assess condition based on image analysis
     */
    private function assessCondition($analysis) {
        // This is a placeholder implementation
        // Real condition assessment would require more sophisticated image analysis
        
        $condition = 'good';
        $confidence = 0.3;
        
        // Simple heuristics based on image properties
        if (!empty($analysis['objects'])) {
            $avgConfidence = array_sum(array_column($analysis['objects'], 'confidence')) / count($analysis['objects']);
            
            if ($avgConfidence > 0.8) {
                $condition = 'like_new';
                $confidence = 0.6;
            } elseif ($avgConfidence < 0.4) {
                $condition = 'fair';
                $confidence = 0.4;
            }
        }
        
        return [
            'condition' => $condition,
            'confidence' => $confidence
        ];
    }
    
    /**
     * Get color name from RGB values
     */
    private function getColorName($r, $g, $b) {
        $colors = [
            'red' => [255, 0, 0],
            'green' => [0, 255, 0],
            'blue' => [0, 0, 255],
            'yellow' => [255, 255, 0],
            'orange' => [255, 165, 0],
            'purple' => [128, 0, 128],
            'pink' => [255, 192, 203],
            'brown' => [165, 42, 42],
            'black' => [0, 0, 0],
            'white' => [255, 255, 255],
            'gray' => [128, 128, 128],
            'silver' => [192, 192, 192]
        ];
        
        $minDistance = PHP_INT_MAX;
        $closestColor = null;
        
        foreach ($colors as $name => $rgb) {
            $distance = sqrt(pow($r - $rgb[0], 2) + pow($g - $rgb[1], 2) + pow($b - $rgb[2], 2));
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $closestColor = $name;
            }
        }
        
        return $minDistance < 100 ? $closestColor : null;
    }
    
    /**
     * Save analysis results to database
     */
    public function saveAnalysis($imageId, $analysis) {
        try {
            $this->db->beginTransaction();
            
            // Update image with analysis results
            $stmt = $this->db->prepare("
                UPDATE article_images 
                SET ai_analyzed = 1,
                    ai_objects = ?,
                    ai_labels = ?,
                    ai_text = ?,
                    ai_colors = ?,
                    ai_landmarks = ?,
                    ai_faces = ?,
                    ai_explicit_content = ?,
                    ai_analysis_timestamp = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                json_encode($analysis['objects']),
                json_encode($analysis['labels']),
                json_encode($analysis['text']),
                json_encode($analysis['colors']),
                json_encode($analysis['landmarks']),
                $analysis['faces'] ? 1 : 0,
                json_encode($analysis['explicit_content']),
                $imageId
            ]);
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            Logger::error('Failed to save AI analysis', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Batch analyze multiple images
     */
    public function batchAnalyze($imagePaths, $options = []) {
        $results = [];
        
        foreach ($imagePaths as $index => $imagePath) {
            try {
                $analysis = $this->analyzeImage($imagePath, $options);
                $results[$index] = $analysis;
                
                // Add to processing queue if needed
                if (isset($options['queue']) && $options['queue']) {
                    $this->addToProcessingQueue($imagePath, 'analysis');
                }
                
            } catch (Exception $e) {
                Logger::error('Batch analysis failed for image', ['path' => $imagePath, 'error' => $e->getMessage()]);
                $results[$index] = ['error' => $e->getMessage()];
            }
        }
        
        return $results;
    }
    
    /**
     * Add image to processing queue
     */
    private function addToProcessingQueue($imagePath, $type = 'analysis') {
        $stmt = $this->db->prepare("
            INSERT INTO ai_processing_queue (image_id, processing_type, status, created_at)
            VALUES (?, ?, 'pending', NOW())
        ");
        
        // This would need the actual image ID
        // For now, we'll skip the queue implementation
        return true;
    }
}