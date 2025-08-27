<?php
/**
 * AISuggestion Model
 */

class AISuggestion extends BaseModel {
    protected $table = 'ai_suggestions';
    
    /**
     * Create AI suggestion
     */
    public function createSuggestion($articleId, $imageId, $type, $suggestedValue, $confidence, $originalValue = null) {
        return $this->create([
            'article_id' => $articleId,
            'image_id' => $imageId,
            'suggestion_type' => $type,
            'original_value' => $originalValue,
            'suggested_value' => $suggestedValue,
            'confidence_score' => $confidence
        ]);
    }
    
    /**
     * Get suggestions for article grouped by type
     */
    public function getForArticleGrouped($articleId) {
        $suggestions = $this->where(['article_id' => $articleId], 'confidence_score DESC');
        
        $grouped = [];
        foreach ($suggestions as $suggestion) {
            $type = $suggestion['suggestion_type'];
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $suggestion;
        }
        
        return $grouped;
    }
    
    /**
     * Accept suggestion
     */
    public function acceptSuggestion($suggestionId, $userId) {
        $suggestion = $this->find($suggestionId);
        if (!$suggestion) {
            return false;
        }
        
        // Verify user owns the article
        $articleModel = new Article();
        $article = $articleModel->find($suggestion['article_id']);
        if (!$article || $article['user_id'] != $userId) {
            return false;
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update suggestion
            $this->update($suggestionId, [
                'is_accepted' => 1,
                'user_feedback' => 'accepted'
            ]);
            
            // Apply suggestion to article
            $updateField = $this->getSuggestionField($suggestion['suggestion_type']);
            if ($updateField) {
                $articleModel->update($suggestion['article_id'], [
                    $updateField => $suggestion['suggested_value']
                ]);
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            Logger::error('Failed to accept AI suggestion', ['suggestion_id' => $suggestionId, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Reject suggestion
     */
    public function rejectSuggestion($suggestionId, $userId) {
        $suggestion = $this->find($suggestionId);
        if (!$suggestion) {
            return false;
        }
        
        // Verify user owns the article
        $articleModel = new Article();
        $article = $articleModel->find($suggestion['article_id']);
        if (!$article || $article['user_id'] != $userId) {
            return false;
        }
        
        return $this->update($suggestionId, [
            'user_feedback' => 'rejected'
        ]);
    }
    
    /**
     * Get field name for suggestion type
     */
    private function getSuggestionField($type) {
        $mapping = [
            'title' => 'title',
            'description' => 'description',
            'category' => 'category_id',
            'price' => 'price',
            'condition' => 'condition_type'
        ];
        
        return $mapping[$type] ?? null;
    }
    
    /**
     * Get suggestion statistics
     */
    public function getStatistics($dateFrom = null, $dateTo = null) {
        $sql = "SELECT 
                    suggestion_type,
                    COUNT(*) as total_suggestions,
                    SUM(CASE WHEN user_feedback = 'accepted' THEN 1 ELSE 0 END) as accepted,
                    SUM(CASE WHEN user_feedback = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    AVG(confidence_score) as avg_confidence
                FROM {$this->table}";
        
        $params = [];
        
        if ($dateFrom || $dateTo) {
            $sql .= " WHERE";
            $conditions = [];
            
            if ($dateFrom) {
                $conditions[] = "created_at >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $conditions[] = "created_at <= ?";
                $params[] = $dateTo;
            }
            
            $sql .= " " . implode(" AND ", $conditions);
        }
        
        $sql .= " GROUP BY suggestion_type";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get user feedback patterns for ML improvement
     */
    public function getFeedbackPatterns() {
        $sql = "SELECT 
                    suggestion_type,
                    user_feedback,
                    confidence_score,
                    COUNT(*) as count
                FROM {$this->table}
                WHERE user_feedback IS NOT NULL
                GROUP BY suggestion_type, user_feedback, ROUND(confidence_score, 1)
                ORDER BY suggestion_type, confidence_score DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}