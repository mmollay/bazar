<?php
/**
 * Confidence Calculator Service
 * Calculates and manages AI confidence scores with learning capabilities
 */

class ConfidenceCalculator {
    private $db;
    private $baseConfidenceWeights;
    private $userFeedbackWeights;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->initializeWeights();
    }
    
    private function initializeWeights() {
        // Base confidence weights for different AI features
        $this->baseConfidenceWeights = [
            'object_detection' => 0.3,
            'label_detection' => 0.2,
            'text_recognition' => 0.1,
            'color_analysis' => 0.1,
            'category_matching' => 0.2,
            'price_estimation' => 0.1
        ];
        
        // User feedback weights (learned from historical data)
        $this->loadUserFeedbackWeights();
    }
    
    /**
     * Calculate confidence score for AI suggestions
     */
    public function calculateSuggestionConfidence($analysisData, $suggestionType, $categoryId = null) {
        $confidenceFactors = [];
        
        switch ($suggestionType) {
            case 'title':
                $confidenceFactors = $this->calculateTitleConfidence($analysisData);
                break;
            case 'description':
                $confidenceFactors = $this->calculateDescriptionConfidence($analysisData);
                break;
            case 'category':
                $confidenceFactors = $this->calculateCategoryConfidence($analysisData, $categoryId);
                break;
            case 'price':
                $confidenceFactors = $this->calculatePriceConfidence($analysisData, $categoryId);
                break;
            case 'condition':
                $confidenceFactors = $this->calculateConditionConfidence($analysisData);
                break;
        }
        
        return $this->aggregateConfidence($confidenceFactors, $suggestionType);
    }
    
    /**
     * Calculate title confidence based on object detection
     */
    private function calculateTitleConfidence($analysisData) {
        $factors = [
            'object_clarity' => 0,
            'object_count' => 0,
            'detection_consistency' => 0
        ];
        
        if (!empty($analysisData['objects'])) {
            $objects = $analysisData['objects'];
            
            // Object clarity - average confidence of top 3 objects
            $topObjects = array_slice($objects, 0, 3);
            $avgConfidence = array_sum(array_column($topObjects, 'confidence')) / count($topObjects);
            $factors['object_clarity'] = min($avgConfidence * 1.2, 1.0); // Boost slightly
            
            // Object count - more objects generally means more context
            $objectCount = count($objects);
            $factors['object_count'] = min($objectCount / 10, 1.0); // Normalize to max 10 objects
            
            // Detection consistency - check if same objects appear multiple times
            $objectNames = array_column($objects, 'name');
            $uniqueObjects = array_unique($objectNames);
            $consistency = count($uniqueObjects) / count($objectNames);
            $factors['detection_consistency'] = 1 - $consistency; // Lower is better (more repetition)
        }
        
        return $factors;
    }
    
    /**
     * Calculate description confidence
     */
    private function calculateDescriptionConfidence($analysisData) {
        $factors = [
            'object_diversity' => 0,
            'label_accuracy' => 0,
            'text_presence' => 0,
            'color_information' => 0
        ];
        
        // Object diversity
        if (!empty($analysisData['objects'])) {
            $objectCount = count($analysisData['objects']);
            $factors['object_diversity'] = min($objectCount / 5, 1.0);
        }
        
        // Label accuracy
        if (!empty($analysisData['labels'])) {
            $avgLabelConfidence = array_sum(array_column($analysisData['labels'], 'confidence')) / count($analysisData['labels']);
            $factors['label_accuracy'] = $avgLabelConfidence;
        }
        
        // Text presence (OCR results)
        if (!empty($analysisData['text'])) {
            $factors['text_presence'] = 0.8; // High value if text is detected
        }
        
        // Color information
        if (!empty($analysisData['colors'])) {
            $factors['color_information'] = 0.6;
        }
        
        return $factors;
    }
    
    /**
     * Calculate category confidence
     */
    private function calculateCategoryConfidence($analysisData, $categoryId) {
        $factors = [
            'keyword_matching' => 0,
            'object_relevance' => 0,
            'historical_accuracy' => 0
        ];
        
        if ($categoryId) {
            // Get category keywords
            $stmt = $this->db->prepare("SELECT ai_keywords FROM categories WHERE id = ?");
            $stmt->execute([$categoryId]);
            $category = $stmt->fetch();
            
            if ($category && $category['ai_keywords']) {
                $keywords = json_decode($category['ai_keywords'], true) ?: [];
                
                // Keyword matching against objects and labels
                $matchScore = 0;
                $totalItems = 0;
                
                foreach (['objects', 'labels'] as $type) {
                    if (!empty($analysisData[$type])) {
                        foreach ($analysisData[$type] as $item) {
                            $totalItems++;
                            foreach ($keywords as $keyword) {
                                if (stripos($item['name'], $keyword) !== false) {
                                    $matchScore += $item['confidence'];
                                    break;
                                }
                            }
                        }
                    }
                }
                
                if ($totalItems > 0) {
                    $factors['keyword_matching'] = $matchScore / $totalItems;
                }
                
                // Object relevance
                $factors['object_relevance'] = $this->calculateObjectRelevance($analysisData['objects'] ?? [], $keywords);
                
                // Historical accuracy
                $factors['historical_accuracy'] = $this->getHistoricalAccuracy('category', $categoryId);
            }
        }
        
        return $factors;
    }
    
    /**
     * Calculate price confidence
     */
    private function calculatePriceConfidence($analysisData, $categoryId) {
        $factors = [
            'category_data_availability' => 0,
            'condition_clarity' => 0,
            'brand_recognition' => 0,
            'historical_accuracy' => 0
        ];
        
        if ($categoryId) {
            // Check if we have enough historical price data
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM price_history 
                WHERE category_id = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 3 MONTH)
            ");
            $stmt->execute([$categoryId]);
            $result = $stmt->fetch();
            
            $dataCount = $result['count'] ?? 0;
            $factors['category_data_availability'] = min($dataCount / 50, 1.0); // Normalize to 50 samples
            
            // Condition clarity (if we can assess condition reliably)
            $factors['condition_clarity'] = $this->assessConditionClarity($analysisData);
            
            // Brand recognition (if text is detected that might be a brand)
            $factors['brand_recognition'] = $this->detectBrandInformation($analysisData);
            
            // Historical accuracy
            $factors['historical_accuracy'] = $this->getHistoricalAccuracy('price', $categoryId);
        }
        
        return $factors;
    }
    
    /**
     * Calculate condition confidence
     */
    private function calculateConditionConfidence($analysisData) {
        $factors = [
            'image_quality' => 0,
            'object_clarity' => 0,
            'damage_detection' => 0,
            'newness_indicators' => 0
        ];
        
        // Image quality assessment (based on object detection confidence)
        if (!empty($analysisData['objects'])) {
            $avgConfidence = array_sum(array_column($analysisData['objects'], 'confidence')) / count($analysisData['objects']);
            $factors['image_quality'] = $avgConfidence;
        }
        
        // Object clarity
        $factors['object_clarity'] = $factors['image_quality']; // Similar for now
        
        // Damage detection (look for keywords in labels)
        $damageKeywords = ['damage', 'broken', 'crack', 'scratch', 'worn', 'tear', 'stain'];
        $damageScore = 0;
        
        if (!empty($analysisData['labels'])) {
            foreach ($analysisData['labels'] as $label) {
                foreach ($damageKeywords as $keyword) {
                    if (stripos($label['name'], $keyword) !== false) {
                        $damageScore += $label['confidence'];
                    }
                }
            }
        }
        
        $factors['damage_detection'] = min($damageScore, 1.0);
        
        // Newness indicators
        $newnessKeywords = ['new', 'pristine', 'unused', 'mint', 'perfect'];
        $newnessScore = 0;
        
        if (!empty($analysisData['labels'])) {
            foreach ($analysisData['labels'] as $label) {
                foreach ($newnessKeywords as $keyword) {
                    if (stripos($label['name'], $keyword) !== false) {
                        $newnessScore += $label['confidence'];
                    }
                }
            }
        }
        
        $factors['newness_indicators'] = min($newnessScore, 1.0);
        
        return $factors;
    }
    
    /**
     * Aggregate confidence factors into final score
     */
    private function aggregateConfidence($factors, $suggestionType) {
        if (empty($factors)) {
            return 0.3; // Default low confidence
        }
        
        // Apply user feedback weights if available
        $feedbackWeight = $this->userFeedbackWeights[$suggestionType] ?? 1.0;
        
        // Calculate weighted average
        $totalWeight = 0;
        $weightedSum = 0;
        
        foreach ($factors as $factor => $value) {
            $weight = $this->getFactorWeight($suggestionType, $factor);
            $weightedSum += $value * $weight;
            $totalWeight += $weight;
        }
        
        if ($totalWeight === 0) {
            return 0.3;
        }
        
        $baseConfidence = $weightedSum / $totalWeight;
        
        // Apply feedback adjustment
        $adjustedConfidence = $baseConfidence * $feedbackWeight;
        
        // Ensure confidence is between 0.1 and 0.95
        return max(0.1, min(0.95, $adjustedConfidence));
    }
    
    /**
     * Get factor weight for specific suggestion type
     */
    private function getFactorWeight($suggestionType, $factor) {
        $weights = [
            'title' => [
                'object_clarity' => 0.5,
                'object_count' => 0.3,
                'detection_consistency' => 0.2
            ],
            'description' => [
                'object_diversity' => 0.3,
                'label_accuracy' => 0.3,
                'text_presence' => 0.2,
                'color_information' => 0.2
            ],
            'category' => [
                'keyword_matching' => 0.4,
                'object_relevance' => 0.3,
                'historical_accuracy' => 0.3
            ],
            'price' => [
                'category_data_availability' => 0.4,
                'condition_clarity' => 0.2,
                'brand_recognition' => 0.2,
                'historical_accuracy' => 0.2
            ],
            'condition' => [
                'image_quality' => 0.3,
                'object_clarity' => 0.2,
                'damage_detection' => 0.3,
                'newness_indicators' => 0.2
            ]
        ];
        
        return $weights[$suggestionType][$factor] ?? 0.1;
    }
    
    /**
     * Load user feedback weights from historical data
     */
    private function loadUserFeedbackWeights() {
        $sql = "
            SELECT 
                suggestion_type,
                AVG(CASE WHEN user_feedback = 'accepted' THEN 1.2 
                         WHEN user_feedback = 'rejected' THEN 0.8 
                         ELSE 1.0 END) as feedback_weight
            FROM ai_suggestions 
            WHERE user_feedback IS NOT NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL 3 MONTH)
            GROUP BY suggestion_type
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        $this->userFeedbackWeights = [];
        foreach ($results as $result) {
            $this->userFeedbackWeights[$result['suggestion_type']] = $result['feedback_weight'];
        }
    }
    
    /**
     * Get historical accuracy for suggestion type
     */
    private function getHistoricalAccuracy($type, $categoryId = null) {
        $sql = "
            SELECT 
                AVG(CASE WHEN user_feedback = 'accepted' THEN 1.0 ELSE 0.0 END) as accuracy
            FROM ai_suggestions s
        ";
        
        $params = [$type];
        
        if ($categoryId) {
            $sql .= " JOIN articles a ON s.article_id = a.id WHERE s.suggestion_type = ? AND a.category_id = ?";
            $params[] = $categoryId;
        } else {
            $sql .= " WHERE s.suggestion_type = ?";
        }
        
        $sql .= " AND s.user_feedback IS NOT NULL AND s.created_at > DATE_SUB(NOW(), INTERVAL 6 MONTH)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        return $result['accuracy'] ?? 0.5; // Default 50% if no data
    }
    
    /**
     * Calculate object relevance to category keywords
     */
    private function calculateObjectRelevance($objects, $keywords) {
        if (empty($objects) || empty($keywords)) {
            return 0;
        }
        
        $relevanceScore = 0;
        $totalConfidence = 0;
        
        foreach ($objects as $object) {
            foreach ($keywords as $keyword) {
                $similarity = $this->calculateStringSimilarity($object['name'], $keyword);
                if ($similarity > 0.3) { // Threshold for relevance
                    $relevanceScore += $object['confidence'] * $similarity;
                }
            }
            $totalConfidence += $object['confidence'];
        }
        
        return $totalConfidence > 0 ? $relevanceScore / $totalConfidence : 0;
    }
    
    /**
     * Assess condition clarity from image analysis
     */
    private function assessConditionClarity($analysisData) {
        $clarityScore = 0;
        
        // High object confidence suggests clear image
        if (!empty($analysisData['objects'])) {
            $avgConfidence = array_sum(array_column($analysisData['objects'], 'confidence')) / count($analysisData['objects']);
            $clarityScore += $avgConfidence * 0.5;
        }
        
        // Good color analysis suggests good image quality
        if (!empty($analysisData['colors']) && count($analysisData['colors']) >= 3) {
            $clarityScore += 0.3;
        }
        
        // Text recognition suggests high resolution
        if (!empty($analysisData['text'])) {
            $clarityScore += 0.2;
        }
        
        return min($clarityScore, 1.0);
    }
    
    /**
     * Detect brand information from text recognition
     */
    private function detectBrandInformation($analysisData) {
        if (empty($analysisData['text'])) {
            return 0;
        }
        
        $brandKeywords = [
            'apple', 'samsung', 'nike', 'adidas', 'sony', 'lg', 'hp', 'dell',
            'canon', 'nikon', 'bmw', 'mercedes', 'audi', 'volkswagen'
        ];
        
        $brandScore = 0;
        
        foreach ($analysisData['text'] as $textItem) {
            $text = strtolower($textItem['text']);
            foreach ($brandKeywords as $brand) {
                if (strpos($text, $brand) !== false) {
                    $brandScore = 0.8; // High confidence if brand detected
                    break 2;
                }
            }
        }
        
        return $brandScore;
    }
    
    /**
     * Calculate string similarity using Levenshtein distance
     */
    private function calculateStringSimilarity($str1, $str2) {
        $str1 = strtolower($str1);
        $str2 = strtolower($str2);
        
        $len1 = strlen($str1);
        $len2 = strlen($str2);
        
        if ($len1 === 0) return $len2 === 0 ? 1 : 0;
        if ($len2 === 0) return 0;
        
        $distance = levenshtein($str1, $str2);
        $maxLen = max($len1, $len2);
        
        return 1 - ($distance / $maxLen);
    }
    
    /**
     * Update confidence weights based on user feedback
     */
    public function updateWeightsFromFeedback($suggestionId, $feedback) {
        // Get suggestion details
        $stmt = $this->db->prepare("
            SELECT suggestion_type, confidence_score 
            FROM ai_suggestions 
            WHERE id = ?
        ");
        $stmt->execute([$suggestionId]);
        $suggestion = $stmt->fetch();
        
        if (!$suggestion) return;
        
        // Update running averages (simplified implementation)
        $type = $suggestion['suggestion_type'];
        $currentWeight = $this->userFeedbackWeights[$type] ?? 1.0;
        
        $adjustment = 0;
        switch ($feedback) {
            case 'accepted':
                $adjustment = 0.05; // Slight positive adjustment
                break;
            case 'rejected':
                $adjustment = -0.05; // Slight negative adjustment
                break;
            case 'modified':
                $adjustment = -0.02; // Small negative adjustment
                break;
        }
        
        $newWeight = max(0.3, min(1.5, $currentWeight + $adjustment));
        $this->userFeedbackWeights[$type] = $newWeight;
        
        // Persist to cache or database as needed
        Logger::debug('Updated confidence weight', [
            'type' => $type,
            'old_weight' => $currentWeight,
            'new_weight' => $newWeight,
            'feedback' => $feedback
        ]);
    }
    
    /**
     * Get confidence explanation for debugging/transparency
     */
    public function explainConfidence($analysisData, $suggestionType, $categoryId = null) {
        $factors = [];
        
        switch ($suggestionType) {
            case 'title':
                $factors = $this->calculateTitleConfidence($analysisData);
                break;
            case 'description':
                $factors = $this->calculateDescriptionConfidence($analysisData);
                break;
            case 'category':
                $factors = $this->calculateCategoryConfidence($analysisData, $categoryId);
                break;
            case 'price':
                $factors = $this->calculatePriceConfidence($analysisData, $categoryId);
                break;
            case 'condition':
                $factors = $this->calculateConditionConfidence($analysisData);
                break;
        }
        
        $finalConfidence = $this->aggregateConfidence($factors, $suggestionType);
        
        return [
            'final_confidence' => $finalConfidence,
            'factors' => $factors,
            'suggestion_type' => $suggestionType,
            'explanation' => $this->generateExplanation($suggestionType, $factors, $finalConfidence)
        ];
    }
    
    /**
     * Generate human-readable explanation of confidence score
     */
    private function generateExplanation($type, $factors, $confidence) {
        $level = $confidence > 0.8 ? 'very high' : 
                ($confidence > 0.6 ? 'high' : 
                ($confidence > 0.4 ? 'medium' : 'low'));
        
        $reasons = [];
        
        foreach ($factors as $factor => $value) {
            if ($value > 0.6) {
                $reasons[] = str_replace('_', ' ', $factor) . " is strong";
            } elseif ($value < 0.3) {
                $reasons[] = str_replace('_', ' ', $factor) . " is weak";
            }
        }
        
        $explanation = "Confidence for {$type} suggestion is {$level} ({$confidence:.0%})";
        
        if (!empty($reasons)) {
            $explanation .= " because " . implode(', ', $reasons);
        }
        
        return $explanation;
    }
}