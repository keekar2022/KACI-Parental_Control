<?php
/*
 * parental_control_health.php
 *
 * Health check endpoint for KACI Parental Control
 * Copyright (c) 2026 Mukesh Kesharwani
 * Built with Passion
 * All rights reserved.
 *
 * Licensed under the GNU General Public License v3.0 or later (GPL-3.0-or-later)
 * See LICENSE file for details.
 */

##|+PRIV
##|*IDENT=page-services-parentalcontrol-health
##|*NAME=Services: Parental Control: Health
##|*DESCR=Health check endpoint for monitoring
##|*MATCH=parental_control_health.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Set JSON header
header('Content-Type: application/json');

/**
 * Health Check Endpoint
 * 
 * Provides service health status for monitoring tools, load balancers,
 * and diagnostic purposes. Returns 200 OK if healthy, 503 if degraded.
 * 
 * @author Mukesh Kesharwani
 * @since 0.1.3
 * 
 * Response Format:
 * {
 *   "status": "ok" | "degraded",
 *   "timestamp": "2025-12-25T10:30:00Z",
 *   "service": "KACI-Parental_Control",
 *   "version": "0.1.2",
 *   "uptime": 123456,
 *   "checks": {
 *     "config_file": true/false,
 *     "state_file": true/false,
 *     "log_file_writable": true/false,
 *     "service_enabled": true/false,
 *     "profiles_configured": true/false
 *   }
 * }
 */

try {
    $health = array(
        'status' => 'ok',
        'timestamp' => gmdate('c'),
        'service' => 'KACI-Parental_Control',
        'version' => defined('PC_VERSION') ? PC_VERSION : 'unknown',
        'build_date' => defined('PC_BUILD_DATE') ? PC_BUILD_DATE : 'unknown',
        'author' => defined('PC_AUTHOR') ? PC_AUTHOR : 'Mukesh Kesharwani',
        'uptime' => pc_get_service_uptime()
    );
    
    // Perform health checks
    $checks = array();
    
    // Check 1: Configuration file exists and is readable
    $checks['config_file'] = file_exists('/cf/conf/config.xml') && is_readable('/cf/conf/config.xml');
    
    // Check 2: State file exists or directory is writable
    if (file_exists(PC_STATE_FILE)) {
        $checks['state_file'] = is_readable(PC_STATE_FILE) && is_writable(PC_STATE_FILE);
    } else {
        $checks['state_file'] = is_writable(dirname(PC_STATE_FILE));
    }
    
    // Check 3: Log directory is writable
    $log_dir = dirname(PC_LOG_FILE);
    $checks['log_file_writable'] = file_exists($log_dir) && is_writable($log_dir);
    
    // Check 4: Service is enabled
    $checks['service_enabled'] = (config_get_path('installedpackages/parentalcontrol/config/0/enable') === 'on');
    
    // Check 5: At least one profile configured
    $profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
    $checks['profiles_configured'] = is_array($profiles) && count($profiles) > 0;
    
    // Check 6: Cron job is installed
    $checks['cron_job_exists'] = pc_check_cron_installed();
    
    $health['checks'] = $checks;
    
    // Determine overall health status
    // Critical checks: config_file, state_file, log_file_writable
    $critical_checks = array('config_file', 'state_file', 'log_file_writable');
    $failed_critical = array();
    
    foreach ($critical_checks as $check) {
        if (!$checks[$check]) {
            $failed_critical[] = $check;
        }
    }
    
    // If any critical check failed, mark as degraded
    if (!empty($failed_critical)) {
        $health['status'] = 'degraded';
        $health['failed_checks'] = $failed_critical;
        http_response_code(503);
    } else {
        // All critical checks passed
        http_response_code(200);
    }
    
    // Add statistics if service is enabled
    if ($checks['service_enabled']) {
        $state = pc_load_state();
        $health['statistics'] = array(
            'profiles_count' => count($profiles),
            'devices_tracked' => isset($state['devices']) ? count($state['devices']) : 0,
            'last_check' => isset($state['last_check']) ? date('c', $state['last_check']) : null,
            'last_reset' => isset($state['last_reset']) ? date('c', $state['last_reset']) : null
        );
    }
    
    echo json_encode($health, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // If health check itself fails, return error status
    http_response_code(503);
    echo json_encode(array(
        'status' => 'error',
        'timestamp' => gmdate('c'),
        'service' => 'KACI-Parental_Control',
        'error' => $e->getMessage()
    ), JSON_PRETTY_PRINT);
}

/**
 * Get service uptime in seconds
 * 
 * Calculates uptime based on state file creation time.
 * If state file doesn't exist, returns 0.
 * 
 * @return int Uptime in seconds
 */
function pc_get_service_uptime() {
    if (file_exists(PC_STATE_FILE)) {
        $created = filectime(PC_STATE_FILE);
        return time() - $created;
    }
    return 0;
}

/**
 * Check if cron job is installed
 * 
 * Searches crontab for parental control cron entry.
 * 
 * @return bool True if cron job exists, false otherwise
 */
function pc_check_cron_installed() {
    $crontab = shell_exec('crontab -l 2>/dev/null');
    if ($crontab === null) {
        return false;
    }
    return strpos($crontab, 'parental_control') !== false;
}

?>

