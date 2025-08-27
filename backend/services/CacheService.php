<?php
/**
 * Cache Service for AI operations
 * Implements Redis-based caching with fallback to file cache
 */

class CacheService {
    private $redis = null;
    private $isRedisAvailable = false;
    private $fileCache = true;
    private $cacheDir;
    private $defaultTtl = 3600; // 1 hour
    
    public function __construct() {
        $this->cacheDir = __DIR__ . '/../../cache/';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        $this->initializeRedis();
    }
    
    private function initializeRedis() {
        if (!class_exists('Redis')) {
            Logger::info('Redis extension not available, using file cache');
            return;
        }
        
        try {
            $this->redis = new Redis();
            $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
            $port = $_ENV['REDIS_PORT'] ?? 6379;
            
            $this->redis->connect($host, $port, 2); // 2 second timeout
            
            if (!empty($_ENV['REDIS_PASSWORD'])) {
                $this->redis->auth($_ENV['REDIS_PASSWORD']);
            }
            
            // Test connection
            $this->redis->ping();
            $this->isRedisAvailable = true;
            
            Logger::info('Redis cache initialized successfully');
            
        } catch (Exception $e) {
            Logger::warning('Redis cache initialization failed', ['error' => $e->getMessage()]);
            $this->redis = null;
            $this->isRedisAvailable = false;
        }
    }
    
    /**
     * Cache AI analysis result
     */
    public function cacheAnalysis($imageHash, $analysisResult, $ttl = null) {
        $key = "ai_analysis:" . $imageHash;
        $ttl = $ttl ?: $this->defaultTtl;
        
        $data = [
            'result' => $analysisResult,
            'cached_at' => time(),
            'ttl' => $ttl
        ];
        
        return $this->set($key, $data, $ttl);
    }
    
    /**
     * Get cached AI analysis
     */
    public function getCachedAnalysis($imageHash) {
        $key = "ai_analysis:" . $imageHash;
        $cached = $this->get($key);
        
        if ($cached && isset($cached['result'])) {
            Logger::debug('AI analysis cache hit', ['hash' => $imageHash]);
            return $cached['result'];
        }
        
        Logger::debug('AI analysis cache miss', ['hash' => $imageHash]);
        return null;
    }
    
    /**
     * Cache image processing result
     */
    public function cacheImageProcessing($originalPath, $processedResult, $ttl = null) {
        $key = "image_processing:" . md5($originalPath);
        $ttl = $ttl ?: ($this->defaultTtl * 24); // 24 hours for processed images
        
        return $this->set($key, $processedResult, $ttl);
    }
    
    /**
     * Get cached image processing result
     */
    public function getCachedImageProcessing($originalPath) {
        $key = "image_processing:" . md5($originalPath);
        return $this->get($key);
    }
    
    /**
     * Cache price estimation
     */
    public function cachePriceEstimation($categoryId, $condition, $factors, $estimate, $ttl = null) {
        $factorsHash = md5(serialize($factors));
        $key = "price_estimate:{$categoryId}:{$condition}:{$factorsHash}";
        $ttl = $ttl ?: 7200; // 2 hours
        
        $data = [
            'estimate' => $estimate,
            'factors' => $factors,
            'category_id' => $categoryId,
            'condition' => $condition,
            'cached_at' => time()
        ];
        
        return $this->set($key, $data, $ttl);
    }
    
    /**
     * Get cached price estimation
     */
    public function getCachedPriceEstimation($categoryId, $condition, $factors) {
        $factorsHash = md5(serialize($factors));
        $key = "price_estimate:{$categoryId}:{$condition}:{$factorsHash}";
        
        return $this->get($key);
    }
    
    /**
     * Cache similar images
     */
    public function cacheSimilarImages($imageHash, $similarImages, $ttl = null) {
        $key = "similar_images:" . $imageHash;
        $ttl = $ttl ?: 86400; // 24 hours
        
        return $this->set($key, $similarImages, $ttl);
    }
    
    /**
     * Get cached similar images
     */
    public function getCachedSimilarImages($imageHash) {
        $key = "similar_images:" . $imageHash;
        return $this->get($key);
    }
    
    /**
     * Cache category suggestions
     */
    public function cacheCategorySuggestions($objects, $labels, $suggestions, $ttl = null) {
        $contentHash = md5(serialize(['objects' => $objects, 'labels' => $labels]));
        $key = "category_suggestions:" . $contentHash;
        $ttl = $ttl ?: 3600; // 1 hour
        
        return $this->set($key, $suggestions, $ttl);
    }
    
    /**
     * Get cached category suggestions
     */
    public function getCachedCategorySuggestions($objects, $labels) {
        $contentHash = md5(serialize(['objects' => $objects, 'labels' => $labels]));
        $key = "category_suggestions:" . $contentHash;
        
        return $this->get($key);
    }
    
    /**
     * Generic set method
     */
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?: $this->defaultTtl;
        
        if ($this->isRedisAvailable) {
            try {
                return $this->redis->setex($key, $ttl, serialize($value));
            } catch (Exception $e) {
                Logger::warning('Redis set failed, falling back to file cache', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        if ($this->fileCache) {
            return $this->setFileCache($key, $value, $ttl);
        }
        
        return false;
    }
    
    /**
     * Generic get method
     */
    public function get($key) {
        if ($this->isRedisAvailable) {
            try {
                $value = $this->redis->get($key);
                if ($value !== false) {
                    return unserialize($value);
                }
            } catch (Exception $e) {
                Logger::warning('Redis get failed, falling back to file cache', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        if ($this->fileCache) {
            return $this->getFileCache($key);
        }
        
        return null;
    }
    
    /**
     * Delete cache entry
     */
    public function delete($key) {
        $deleted = false;
        
        if ($this->isRedisAvailable) {
            try {
                $deleted = $this->redis->del($key) > 0;
            } catch (Exception $e) {
                Logger::warning('Redis delete failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }
        
        if ($this->fileCache) {
            $deleted = $this->deleteFileCache($key) || $deleted;
        }
        
        return $deleted;
    }
    
    /**
     * Clear cache by pattern
     */
    public function clear($pattern = '*') {
        $cleared = 0;
        
        if ($this->isRedisAvailable) {
            try {
                $keys = $this->redis->keys($pattern);
                if (!empty($keys)) {
                    $cleared = $this->redis->del($keys);
                }
            } catch (Exception $e) {
                Logger::warning('Redis clear failed', ['pattern' => $pattern, 'error' => $e->getMessage()]);
            }
        }
        
        if ($this->fileCache) {
            $cleared += $this->clearFileCache($pattern);
        }
        
        return $cleared;
    }
    
    /**
     * Get cache statistics
     */
    public function getStats() {
        $stats = [
            'redis_available' => $this->isRedisAvailable,
            'file_cache_enabled' => $this->fileCache,
            'cache_dir' => $this->cacheDir
        ];
        
        if ($this->isRedisAvailable) {
            try {
                $info = $this->redis->info();
                $stats['redis_info'] = [
                    'used_memory' => $info['used_memory_human'] ?? 'unknown',
                    'connected_clients' => $info['connected_clients'] ?? 'unknown',
                    'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                    'keyspace_misses' => $info['keyspace_misses'] ?? 0
                ];
                
                if ($stats['redis_info']['keyspace_hits'] + $stats['redis_info']['keyspace_misses'] > 0) {
                    $stats['redis_info']['hit_rate'] = round(
                        ($stats['redis_info']['keyspace_hits'] / 
                         ($stats['redis_info']['keyspace_hits'] + $stats['redis_info']['keyspace_misses'])) * 100, 2
                    ) . '%';
                }
            } catch (Exception $e) {
                $stats['redis_error'] = $e->getMessage();
            }
        }
        
        if ($this->fileCache) {
            $stats['file_cache_stats'] = $this->getFileCacheStats();
        }
        
        return $stats;
    }
    
    /**
     * File cache implementation
     */
    private function setFileCache($key, $value, $ttl) {
        $filename = $this->getCacheFilename($key);
        $data = [
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time()
        ];
        
        $success = file_put_contents($filename, serialize($data), LOCK_EX) !== false;
        
        if ($success) {
            // Clean up expired files occasionally (1% chance)
            if (rand(1, 100) === 1) {
                $this->cleanupExpiredFiles();
            }
        }
        
        return $success;
    }
    
    private function getFileCache($key) {
        $filename = $this->getCacheFilename($key);
        
        if (!file_exists($filename)) {
            return null;
        }
        
        $content = file_get_contents($filename);
        if ($content === false) {
            return null;
        }
        
        $data = unserialize($content);
        if (!$data || !isset($data['expires_at'])) {
            unlink($filename);
            return null;
        }
        
        // Check if expired
        if (time() > $data['expires_at']) {
            unlink($filename);
            return null;
        }
        
        return $data['value'];
    }
    
    private function deleteFileCache($key) {
        $filename = $this->getCacheFilename($key);
        
        if (file_exists($filename)) {
            return unlink($filename);
        }
        
        return false;
    }
    
    private function clearFileCache($pattern) {
        $deleted = 0;
        $files = glob($this->cacheDir . '*.cache');
        
        foreach ($files as $file) {
            if ($pattern === '*' || fnmatch($this->convertPatternToFilename($pattern), basename($file))) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
    
    private function getCacheFilename($key) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return $this->cacheDir . $safeKey . '.cache';
    }
    
    private function convertPatternToFilename($pattern) {
        return str_replace([':', '*'], ['_', '*'], $pattern) . '.cache';
    }
    
    private function cleanupExpiredFiles() {
        $files = glob($this->cacheDir . '*.cache');
        $cleaned = 0;
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $data = unserialize($content);
                if ($data && isset($data['expires_at']) && time() > $data['expires_at']) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
        }
        
        if ($cleaned > 0) {
            Logger::debug("Cleaned up {$cleaned} expired cache files");
        }
    }
    
    private function getFileCacheStats() {
        $files = glob($this->cacheDir . '*.cache');
        $totalSize = 0;
        $expiredFiles = 0;
        $validFiles = 0;
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
            
            $content = file_get_contents($file);
            if ($content !== false) {
                $data = unserialize($content);
                if ($data && isset($data['expires_at'])) {
                    if (time() > $data['expires_at']) {
                        $expiredFiles++;
                    } else {
                        $validFiles++;
                    }
                }
            }
        }
        
        return [
            'total_files' => count($files),
            'valid_files' => $validFiles,
            'expired_files' => $expiredFiles,
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize)
        ];
    }
    
    private function formatBytes($size, $precision = 2) {
        $base = log($size, 1024);
        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }
    
    /**
     * Generate hash for image content
     */
    public function generateImageHash($imagePath) {
        if (!file_exists($imagePath)) {
            return null;
        }
        
        // Use file content hash for uniqueness
        $content = file_get_contents($imagePath);
        return md5($content);
    }
    
    /**
     * Invalidate related caches
     */
    public function invalidateRelated($type, $identifier) {
        $patterns = [];
        
        switch ($type) {
            case 'category':
                $patterns[] = "category_suggestions:*";
                $patterns[] = "price_estimate:{$identifier}:*";
                break;
            case 'image':
                $patterns[] = "ai_analysis:{$identifier}";
                $patterns[] = "similar_images:{$identifier}";
                $patterns[] = "image_processing:*{$identifier}*";
                break;
            case 'user':
                $patterns[] = "user_preferences:{$identifier}:*";
                break;
        }
        
        $totalCleared = 0;
        foreach ($patterns as $pattern) {
            $totalCleared += $this->clear($pattern);
        }
        
        return $totalCleared;
    }
}