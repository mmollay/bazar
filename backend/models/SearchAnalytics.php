<?php
/**
 * SearchAnalytics Model - Track search queries and performance
 */

class SearchAnalytics extends BaseModel {
    protected $table = 'search_analytics';
    
    /**
     * Track a search query
     */
    public function track($data) {
        $trackData = [
            'query' => $data['query'] ?? '',
            'filters' => json_encode($data['filters'] ?? []),
            'results_count' => $data['results_count'] ?? 0,
            'search_time_ms' => $data['search_time_ms'] ?? null,
            'user_agent' => substr($data['user_agent'] ?? '', 0, 255),
            'ip_address' => $data['ip_address'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->create($trackData);
    }
    
    /**
     * Get popular searches
     */
    public function getPopularSearches($limit = 10, $days = 7) {
        $sql = "
            SELECT 
                query,
                COUNT(*) as search_count,
                AVG(results_count) as avg_results,
                MAX(created_at) as last_searched
            FROM {$this->table}
            WHERE 
                query != '' 
                AND query IS NOT NULL
                AND LENGTH(query) >= 2
                AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                AND results_count > 0
            GROUP BY query
            ORDER BY search_count DESC, last_searched DESC
            LIMIT ?
        ";
        
        return $this->raw($sql, [$days, $limit]);
    }
    
    /**
     * Get searches with no results
     */
    public function getNoResultsSearches($limit = 20, $days = 7) {
        $sql = "
            SELECT 
                query,
                COUNT(*) as search_count,
                MAX(created_at) as last_searched
            FROM {$this->table}
            WHERE 
                results_count = 0
                AND query != ''
                AND query IS NOT NULL
                AND LENGTH(query) >= 2
                AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY query
            ORDER BY search_count DESC, last_searched DESC
            LIMIT ?
        ";
        
        return $this->raw($sql, [$days, $limit]);
    }
    
    /**
     * Get search trends
     */
    public function getSearchTrends($days = 30) {
        $sql = "
            SELECT 
                DATE(created_at) as search_date,
                COUNT(*) as total_searches,
                COUNT(DISTINCT query) as unique_queries,
                AVG(results_count) as avg_results,
                AVG(search_time_ms) as avg_search_time
            FROM {$this->table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY search_date DESC
        ";
        
        return $this->raw($sql, [$days]);
    }
    
    /**
     * Get top performing searches (high result count)
     */
    public function getTopPerformingSearches($limit = 10, $days = 7) {
        $sql = "
            SELECT 
                query,
                COUNT(*) as search_count,
                AVG(results_count) as avg_results,
                MAX(results_count) as max_results,
                MAX(created_at) as last_searched
            FROM {$this->table}
            WHERE 
                query != '' 
                AND query IS NOT NULL
                AND LENGTH(query) >= 2
                AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                AND results_count > 0
            GROUP BY query
            ORDER BY avg_results DESC, search_count DESC
            LIMIT ?
        ";
        
        return $this->raw($sql, [$days, $limit]);
    }
    
    /**
     * Get search performance stats
     */
    public function getPerformanceStats($days = 7) {
        $sql = "
            SELECT 
                COUNT(*) as total_searches,
                COUNT(DISTINCT query) as unique_queries,
                AVG(results_count) as avg_results_per_search,
                AVG(search_time_ms) as avg_search_time_ms,
                SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) as zero_result_searches,
                SUM(CASE WHEN results_count > 0 THEN 1 ELSE 0 END) as successful_searches,
                MIN(created_at) as period_start,
                MAX(created_at) as period_end
            FROM {$this->table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        
        $result = $this->raw($sql, [$days]);
        return $result[0] ?? [];
    }
    
    /**
     * Get search suggestions based on partial query
     */
    public function getSuggestions($partial, $limit = 5) {
        $sql = "
            SELECT DISTINCT query, COUNT(*) as frequency
            FROM {$this->table}
            WHERE 
                query LIKE ?
                AND results_count > 0
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND LENGTH(query) >= 2
            GROUP BY query
            ORDER BY frequency DESC, MAX(created_at) DESC
            LIMIT ?
        ";
        
        return $this->raw($sql, [$partial . '%', $limit]);
    }
    
    /**
     * Clean old analytics data
     */
    public function cleanOldData($days = 90) {
        $sql = "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$days]);
    }
    
    /**
     * Get hourly search distribution
     */
    public function getHourlyDistribution($days = 7) {
        $sql = "
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as search_count,
                AVG(results_count) as avg_results
            FROM {$this->table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ";
        
        return $this->raw($sql, [$days]);
    }
    
    /**
     * Get most used filters
     */
    public function getPopularFilters($days = 7) {
        $sql = "
            SELECT filters, COUNT(*) as usage_count
            FROM {$this->table}
            WHERE 
                filters != '[]'
                AND filters IS NOT NULL
                AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY filters
            ORDER BY usage_count DESC
            LIMIT 20
        ";
        
        $results = $this->raw($sql, [$days]);
        
        // Parse and aggregate filters
        $filterStats = [];
        foreach ($results as $row) {
            $filters = json_decode($row['filters'], true);
            if (is_array($filters)) {
                foreach ($filters as $key => $value) {
                    if (!isset($filterStats[$key])) {
                        $filterStats[$key] = [];
                    }
                    if (!isset($filterStats[$key][$value])) {
                        $filterStats[$key][$value] = 0;
                    }
                    $filterStats[$key][$value] += $row['usage_count'];
                }
            }
        }
        
        return $filterStats;
    }
}