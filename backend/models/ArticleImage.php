<?php
/**
 * ArticleImage Model
 */

class ArticleImage extends BaseModel {
    protected $table = 'article_images';
    
    /**
     * Save AI analysis results
     */
    public function saveAIAnalysis($imageId, $analysis) {
        $updateData = [
            'ai_analyzed' => 1,
            'ai_objects' => json_encode($analysis['objects'] ?? []),
            'ai_labels' => json_encode($analysis['labels'] ?? []),
            'ai_text' => json_encode($analysis['text'] ?? []),
            'ai_colors' => json_encode($analysis['colors'] ?? []),
            'ai_landmarks' => json_encode($analysis['landmarks'] ?? []),
            'ai_faces' => !empty($analysis['faces']) ? 1 : 0,
            'ai_explicit_content' => json_encode($analysis['explicit_content'] ?? []),
            'ai_analysis_timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $this->update($imageId, $updateData);
    }
    
    /**
     * Get images with AI analysis for an article
     */
    public function getWithAnalysisForArticle($articleId) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE article_id = ? 
                ORDER BY is_primary DESC, sort_order ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$articleId]);
        $images = $stmt->fetchAll();
        
        // Parse JSON fields
        foreach ($images as &$image) {
            if ($image['ai_analyzed']) {
                $image['ai_objects'] = json_decode($image['ai_objects'], true) ?: [];
                $image['ai_labels'] = json_decode($image['ai_labels'], true) ?: [];
                $image['ai_text'] = json_decode($image['ai_text'], true) ?: [];
                $image['ai_colors'] = json_decode($image['ai_colors'], true) ?: [];
                $image['ai_landmarks'] = json_decode($image['ai_landmarks'], true) ?: [];
                $image['ai_explicit_content'] = json_decode($image['ai_explicit_content'], true) ?: [];
            }
        }
        
        return $images;
    }
    
    /**
     * Get unanalyzed images for batch processing
     */
    public function getUnanalyzed($limit = 10) {
        return $this->where(['ai_analyzed' => 0], 'created_at ASC', $limit);
    }
    
    /**
     * Set primary image
     */
    public function setPrimary($imageId, $articleId) {
        $this->db->beginTransaction();
        
        try {
            // Remove primary from all images in this article
            $stmt = $this->db->prepare("UPDATE {$this->table} SET is_primary = 0 WHERE article_id = ?");
            $stmt->execute([$articleId]);
            
            // Set new primary
            $this->update($imageId, ['is_primary' => 1]);
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }
    
    /**
     * Delete image and its files
     */
    public function deleteWithFiles($imageId) {
        $image = $this->find($imageId);
        if (!$image) {
            return false;
        }
        
        $this->db->beginTransaction();
        
        try {
            // Delete from database
            $this->delete($imageId);
            
            // Delete physical files
            $imageProcessingService = new ImageProcessingService();
            $filesToDelete = [
                $image['file_path'],
                // Add other versions if stored separately
            ];
            
            $imageProcessingService->deleteImageFiles($filesToDelete);
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            Logger::error('Failed to delete image', ['image_id' => $imageId, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Reorder images
     */
    public function reorder($articleId, $imageOrders) {
        $this->db->beginTransaction();
        
        try {
            foreach ($imageOrders as $imageId => $order) {
                $this->update($imageId, ['sort_order' => $order]);
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }
}