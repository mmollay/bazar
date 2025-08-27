#!/usr/bin/env php
<?php
/**
 * CLI Batch Processor for AI operations
 * Usage: php batch_processor.php [command] [options]
 */

// Include application bootstrap
require_once __DIR__ . '/../config/app.php';

class BatchProcessorCLI {
    private $batchService;
    
    public function __construct() {
        $this->batchService = new BatchProcessingService();
    }
    
    public function run($argv) {
        $command = $argv[1] ?? 'help';
        
        switch ($command) {
            case 'process':
                $this->processQueue($argv);
                break;
            case 'daemon':
                $this->runDaemon($argv);
                break;
            case 'stats':
                $this->showStats();
                break;
            case 'cleanup':
                $this->cleanup($argv);
                break;
            case 'retry':
                $this->retryFailed();
                break;
            case 'estimate':
                $this->estimateTime();
                break;
            default:
                $this->showHelp();
        }
    }
    
    private function processQueue($argv) {
        $batchSize = isset($argv[2]) ? (int)$argv[2] : 10;
        
        $this->output("Processing batch of {$batchSize} items...");
        
        $result = $this->batchService->processPendingQueue($batchSize);
        
        $this->output("Batch processing completed:");
        $this->output("- Processed: {$result['processed']}");
        $this->output("- Errors: {$result['errors']}");
        
        if (!empty($result['details'])) {
            $this->output("\nDetails:");
            foreach ($result['details'] as $detail) {
                $status = $detail['success'] ? 'SUCCESS' : 'ERROR';
                $error = $detail['error'] ? " - {$detail['error']}" : '';
                $this->output("  Image {$detail['image_id']} ({$detail['type']}): {$status}{$error}");
            }
        }
    }
    
    private function runDaemon($argv) {
        $interval = isset($argv[2]) ? (int)$argv[2] : 30;
        $maxRuntime = isset($argv[3]) ? (int)$argv[3] : 3600;
        
        $this->output("Starting batch processing daemon...");
        $this->output("Interval: {$interval} seconds");
        $this->output("Max runtime: {$maxRuntime} seconds");
        $this->output("Press Ctrl+C to stop\n");
        
        // Handle graceful shutdown
        $running = true;
        pcntl_signal(SIGTERM, function() use (&$running) {
            $this->output("\nReceived SIGTERM, shutting down gracefully...");
            $running = false;
        });
        
        pcntl_signal(SIGINT, function() use (&$running) {
            $this->output("\nReceived SIGINT, shutting down gracefully...");
            $running = false;
        });
        
        $startTime = time();
        
        while ($running && (time() - $startTime) < $maxRuntime) {
            pcntl_signal_dispatch();
            
            try {
                $result = $this->batchService->processPendingQueue();
                
                if ($result['processed'] > 0 || $result['errors'] > 0) {
                    $timestamp = date('Y-m-d H:i:s');
                    $this->output("[{$timestamp}] Processed: {$result['processed']}, Errors: {$result['errors']}");
                }
                
                sleep($interval);
                
            } catch (Exception $e) {
                $this->error("Daemon error: " . $e->getMessage());
                sleep($interval * 2);
            }
        }
        
        $this->output("Daemon stopped.");
    }
    
    private function showStats() {
        $stats = $this->batchService->getQueueStats();
        
        $this->output("Queue Statistics (last 24 hours):");
        $this->output("- Pending: {$stats['pending']}");
        $this->output("- Processing: {$stats['processing']}");
        $this->output("- Completed: {$stats['completed']}");
        $this->output("- Failed: {$stats['failed']}");
        $this->output("- Success Rate: {$stats['success_rate']}%");
        $this->output("- Failure Rate: {$stats['failure_rate']}%");
        $this->output("- Avg Processing Time: {$stats['avg_processing_time']} seconds");
        
        // Cache stats
        $cacheService = new CacheService();
        $cacheStats = $cacheService->getStats();
        
        $this->output("\nCache Statistics:");
        $this->output("- Redis Available: " . ($cacheStats['redis_available'] ? 'Yes' : 'No'));
        $this->output("- File Cache Enabled: " . ($cacheStats['file_cache_enabled'] ? 'Yes' : 'No'));
        
        if (isset($cacheStats['redis_info'])) {
            $redis = $cacheStats['redis_info'];
            $this->output("- Redis Memory: {$redis['used_memory']}");
            $this->output("- Redis Hit Rate: " . ($redis['hit_rate'] ?? 'N/A'));
        }
        
        if (isset($cacheStats['file_cache_stats'])) {
            $file = $cacheStats['file_cache_stats'];
            $this->output("- File Cache Size: {$file['total_size_human']}");
            $this->output("- File Cache Files: {$file['valid_files']} valid, {$file['expired_files']} expired");
        }
    }
    
    private function cleanup($argv) {
        $days = isset($argv[2]) ? (int)$argv[2] : 7;
        
        $this->output("Cleaning up queue items older than {$days} days...");
        
        $cleaned = $this->batchService->cleanup($days);
        $this->output("Cleaned up {$cleaned} old queue items.");
    }
    
    private function retryFailed() {
        $this->output("Retrying failed queue items...");
        
        $retried = $this->batchService->retryFailed();
        $this->output("Retried {$retried} failed items.");
    }
    
    private function estimateTime() {
        $estimate = $this->batchService->estimateProcessingTime();
        
        $this->output("Processing Time Estimate:");
        $this->output("- Pending Items: {$estimate['pending_items']}");
        $this->output("- Estimated Time: {$estimate['estimated_minutes']} minutes");
        $this->output("- Estimated Completion: {$estimate['estimated_completion']}");
    }
    
    private function showHelp() {
        $this->output("Bazar AI Batch Processor");
        $this->output("Usage: php batch_processor.php [command] [options]");
        $this->output("");
        $this->output("Commands:");
        $this->output("  process [batch_size]     Process pending queue items (default batch size: 10)");
        $this->output("  daemon [interval] [max]  Run as daemon (default interval: 30s, max runtime: 1h)");
        $this->output("  stats                    Show queue and cache statistics");
        $this->output("  cleanup [days]           Clean up old queue items (default: 7 days)");
        $this->output("  retry                    Retry failed queue items");
        $this->output("  estimate                 Estimate processing time for pending items");
        $this->output("  help                     Show this help message");
        $this->output("");
        $this->output("Examples:");
        $this->output("  php batch_processor.php process 20");
        $this->output("  php batch_processor.php daemon 60 7200");
        $this->output("  php batch_processor.php cleanup 14");
    }
    
    private function output($message) {
        echo $message . "\n";
    }
    
    private function error($message) {
        fwrite(STDERR, "ERROR: " . $message . "\n");
    }
}

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Run the CLI application
$cli = new BatchProcessorCLI();
$cli->run($argv);