<?php
/**
 * Category Model
 */

class Category extends BaseModel {
    protected $table = 'categories';
    
    /**
     * Get all categories with hierarchy
     */
    public function getHierarchy() {
        $categories = $this->where(['is_active' => 1], 'sort_order ASC');
        
        // Build hierarchy
        $hierarchy = [];
        $categoryMap = [];
        
        // First pass - create map
        foreach ($categories as $category) {
            $category['children'] = [];
            $categoryMap[$category['id']] = $category;
        }
        
        // Second pass - build hierarchy
        foreach ($categoryMap as $id => $category) {
            if ($category['parent_id'] && isset($categoryMap[$category['parent_id']])) {
                $categoryMap[$category['parent_id']]['children'][] = &$categoryMap[$id];
            } else {
                $hierarchy[] = &$categoryMap[$id];
            }
        }
        
        return $hierarchy;
    }
    
    /**
     * Get category with AI keywords
     */
    public function findWithKeywords($id) {
        $category = $this->find($id);
        if ($category && $category['ai_keywords']) {
            $category['ai_keywords'] = json_decode($category['ai_keywords'], true);
        }
        return $category;
    }
}