<?php
/**
 * CLI script for processing search alerts
 * Run this via cron job: php process_search_alerts.php
 */

// Set up environment
require_once __DIR__ . '/../config/app.php';

// CLI only
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

/**
 * Display usage information
 */
function showUsage() {
    echo "Bazar Search Alerts Processor\n";
    echo "=============================\n\n";
    echo "Usage: php process_search_alerts.php [options]\n\n";
    echo "Options:\n";
    echo "  --help, -h           Show this help message\n";
    echo "  --alerts             Process pending search alerts (default)\n";
    echo "  --emails             Process email queue only\n";
    echo "  --cleanup            Clean up old processed alerts\n";
    echo "  --stats              Show alert statistics\n";
    echo "  --limit=N            Limit processing to N items (default: no limit)\n";
    echo "  --dry-run            Show what would be processed without actually processing\n\n";
    echo "Examples:\n";
    echo "  php process_search_alerts.php\n";
    echo "  php process_search_alerts.php --emails --limit=50\n";
    echo "  php process_search_alerts.php --cleanup\n";
    echo "  php process_search_alerts.php --stats\n";
}

/**
 * Parse command line arguments
 */
function parseArgs($argv) {
    $options = [
        'action' => 'alerts',
        'limit' => null,
        'dry_run' => false
    ];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--help' || $arg === '-h') {
            showUsage();
            exit(0);
        } elseif ($arg === '--alerts') {
            $options['action'] = 'alerts';
        } elseif ($arg === '--emails') {
            $options['action'] = 'emails';
        } elseif ($arg === '--cleanup') {
            $options['action'] = 'cleanup';
        } elseif ($arg === '--stats') {
            $options['action'] = 'stats';
        } elseif ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif (strpos($arg, '--limit=') === 0) {
            $options['limit'] = (int)substr($arg, 8);
        } else {
            echo "Unknown option: $arg\n";
            echo "Use --help for usage information\n";
            exit(1);
        }
    }
    
    return $options;
}

/**
 * Display statistics
 */
function showStats() {
    echo "Search Alert Statistics\n";
    echo "======================\n\n";
    
    try {
        // Active saved searches with alerts
        $activeSql = "
            SELECT COUNT(*) as count
            FROM saved_searches ss
            JOIN users u ON ss.user_id = u.id
            WHERE ss.is_active = 1 
                AND ss.email_alerts = 1
                AND u.status = 'active'
        ";
        $activeResult = Database::query($activeSql);
        $activeCount = $activeResult[0]['count'] ?? 0;
        
        // Pending alerts
        $pendingSql = "SELECT COUNT(*) as count FROM search_alert_queue WHERE status = 'pending'";
        $pendingResult = Database::query($pendingSql);
        $pendingCount = $pendingResult[0]['count'] ?? 0;
        
        // Processing stats (last 24 hours)
        $statsSql = "
            SELECT 
                status,
                COUNT(*) as count,
                MAX(created_at) as last_created
            FROM search_alert_queue 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY status
            ORDER BY status
        ";
        $statsResult = Database::query($statsSql);
        
        // Failed alerts
        $failedSql = "
            SELECT COUNT(*) as count
            FROM search_alert_queue 
            WHERE status = 'failed'
                AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ";
        $failedResult = Database::query($failedSql);
        $failedCount = $failedResult[0]['count'] ?? 0;
        
        echo "Active searches with alerts: $activeCount\n";
        echo "Pending alerts in queue: $pendingCount\n";
        echo "Failed alerts (last 7 days): $failedCount\n\n";
        
        echo "Alert processing (last 24 hours):\n";
        foreach ($statsResult as $stat) {
            echo "  {$stat['status']}: {$stat['count']}\n";
        }
        echo "\n";
        
        // Top saved searches
        $topSql = "
            SELECT 
                ss.name,
                ss.query,
                COUNT(saq.id) as alert_count,
                u.username
            FROM saved_searches ss
            JOIN users u ON ss.user_id = u.id
            LEFT JOIN search_alert_queue saq ON ss.id = saq.saved_search_id
                AND saq.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE ss.is_active = 1 AND ss.email_alerts = 1
            GROUP BY ss.id, ss.name, ss.query, u.username
            ORDER BY alert_count DESC
            LIMIT 5
        ";
        
        $topResult = Database::query($topSql);
        
        if (!empty($topResult)) {
            echo "Most active searches (last 30 days):\n";
            foreach ($topResult as $search) {
                echo "  \"{$search['name']}\" by {$search['username']}: {$search['alert_count']} alerts\n";
            }
        }
        
    } catch (Exception $e) {
        echo "Error retrieving statistics: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Main execution
 */
function main($argv) {
    $startTime = microtime(true);
    $options = parseArgs($argv);
    
    echo "Bazar Search Alerts Processor\n";
    echo "============================\n";
    echo "Started at: " . date('Y-m-d H:i:s') . "\n";
    
    if ($options['dry_run']) {
        echo "DRY RUN MODE - No changes will be made\n";
    }
    
    echo "\n";
    
    try {
        $service = new SearchAlertService();
        $processed = 0;
        
        switch ($options['action']) {
            case 'alerts':
                echo "Processing search alerts...\n";
                if (!$options['dry_run']) {
                    $processed = $service->processPendingAlerts();
                } else {
                    // For dry run, just show what would be processed
                    $sql = "
                        SELECT COUNT(*) as count
                        FROM saved_searches ss
                        JOIN users u ON ss.user_id = u.id
                        WHERE ss.is_active = 1 
                            AND ss.email_alerts = 1
                            AND u.status = 'active'
                            AND (ss.last_notified_at IS NULL 
                                 OR ss.last_notified_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                    ";
                    $result = Database::query($sql);
                    $count = $result[0]['count'] ?? 0;
                    echo "Would process $count saved searches\n";
                }
                break;
                
            case 'emails':
                echo "Processing email queue...\n";
                if (!$options['dry_run']) {
                    $processed = $service->processEmailQueue($options['limit'] ?: 50);
                } else {
                    $sql = "SELECT COUNT(*) as count FROM search_alert_queue WHERE status = 'pending'";
                    $result = Database::query($sql);
                    $count = $result[0]['count'] ?? 0;
                    $limit = $options['limit'] ?: $count;
                    echo "Would process " . min($count, $limit) . " emails\n";
                }
                break;
                
            case 'cleanup':
                echo "Cleaning up old alerts...\n";
                if (!$options['dry_run']) {
                    $processed = $service->cleanupOldAlerts();
                } else {
                    $sql = "SELECT COUNT(*) as count FROM search_alert_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    $result = Database::query($sql);
                    $count = $result[0]['count'] ?? 0;
                    echo "Would delete $count old alert records\n";
                }
                break;
                
            case 'stats':
                showStats();
                return;
                
            default:
                echo "Unknown action: {$options['action']}\n";
                exit(1);
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        echo "\nCompleted successfully!\n";
        echo "Processed: $processed items\n";
        echo "Duration: {$duration}ms\n";
        echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
        
    } catch (Exception $e) {
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        echo "\nError occurred!\n";
        echo "Error: " . $e->getMessage() . "\n";
        echo "Duration: {$duration}ms\n";
        echo "Failed at: " . date('Y-m-d H:i:s') . "\n";
        
        // Log the error
        Logger::error('Search alerts processing failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'options' => $options
        ]);
        
        exit(1);
    }
}

// Run the script
main($argv);