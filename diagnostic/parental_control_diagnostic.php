<?php
/*
 * parental_control_diagnostic.php
 *
 * Diagnostic script for troubleshooting Parental Control issues
 * Run this on pfSense to check service health and fix common problems
 *
 * Usage: php /usr/local/bin/parental_control_diagnostic.php
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

echo "\n";
echo "================================================================================\n";
echo "  PARENTAL CONTROL DIAGNOSTIC TOOL v0.1.4\n";
echo "================================================================================\n";
echo "\n";

$issues = array();
$warnings = array();
$success = array();

// ============================================================================
// CHECK 1: Service Enabled Status
// ============================================================================
echo "[1/10] Checking service status...\n";
$service_enabled = config_get_path('installedpackages/parentalcontrol/config/0/enable');
if ($service_enabled === 'on') {
	echo "   ✓ Service is ENABLED\n";
	$success[] = "Service enabled";
} else {
	echo "   ✗ Service is DISABLED\n";
	$issues[] = "Service is disabled - enable it in Services → Parental Control";
}

// ============================================================================
// CHECK 2: Logging Configuration
// ============================================================================
echo "\n[2/10] Checking logging configuration...\n";
$pc_config = config_get_path('installedpackages/parentalcontrol/config/0', array());
$logging_enabled = isset($pc_config['enable_logging']) && $pc_config['enable_logging'] === 'on';
$log_level = isset($pc_config['log_level']) ? $pc_config['log_level'] : 'info';

if ($logging_enabled) {
	echo "   ✓ Logging is ENABLED (level: {$log_level})\n";
	$success[] = "Logging enabled";
} else {
	echo "   ✗ Logging is DISABLED\n";
	$issues[] = "CRITICAL: Logging is disabled in config";
	echo "   → FIX: Enable logging in Services → Parental Control → Enable Logging\n";
}

// ============================================================================
// CHECK 3: Log File
// ============================================================================
echo "\n[3/10] Checking log file...\n";
$log_file = getenv('PC_LOG_FILE') ?: PC_LOG_FILE;
echo "   Log file: {$log_file}\n";

if (file_exists($log_file)) {
	$log_size = filesize($log_file);
	$log_lines = count(file($log_file));
	$is_writable = is_writable($log_file);
	
	echo "   ✓ Log file EXISTS\n";
	echo "     - Size: " . round($log_size / 1024, 2) . " KB\n";
	echo "     - Lines: {$log_lines}\n";
	
	if ($is_writable) {
		echo "   ✓ Log file is WRITABLE\n";
		$success[] = "Log file writable";
	} else {
		echo "   ✗ Log file is NOT WRITABLE\n";
		$issues[] = "Log file exists but is not writable";
		echo "   → FIX: Run 'chmod 664 {$log_file}'\n";
	}
	
	// Show last few log entries
	if ($log_lines > 0) {
		echo "\n   Last 3 log entries:\n";
		$lines = array_slice(file($log_file), -3);
		foreach ($lines as $line) {
			echo "   " . trim($line) . "\n";
		}
	}
} else {
	echo "   ⚠ Log file does NOT exist\n";
	$warnings[] = "Log file doesn't exist yet";
	
	// Try to create it
	echo "   → Attempting to create log file...\n";
	$log_dir = dirname($log_file);
	if (!is_dir($log_dir)) {
		mkdir($log_dir, 0755, true);
		echo "     Created directory: {$log_dir}\n";
	}
	
	if (touch($log_file)) {
		chmod($log_file, 0664);
		echo "   ✓ Successfully created log file\n";
		$success[] = "Created log file";
	} else {
		echo "   ✗ FAILED to create log file\n";
		$issues[] = "Cannot create log file - check permissions";
	}
}

// ============================================================================
// CHECK 4: State File
// ============================================================================
echo "\n[4/10] Checking state file...\n";
$state_file = getenv('PC_STATE_FILE') ?: PC_STATE_FILE;
echo "   State file: {$state_file}\n";

if (file_exists($state_file)) {
	$state_size = filesize($state_file);
	$is_writable = is_writable($state_file);
	
	echo "   ✓ State file EXISTS\n";
	echo "     - Size: " . round($state_size / 1024, 2) . " KB\n";
	
	if ($is_writable) {
		echo "   ✓ State file is WRITABLE\n";
		$success[] = "State file writable";
	} else {
		echo "   ✗ State file is NOT WRITABLE\n";
		$issues[] = "State file exists but is not writable";
		echo "   → FIX: Run 'chmod 664 {$state_file}'\n";
	}
	
	// Validate JSON
	$state_content = file_get_contents($state_file);
	$state = json_decode($state_content, true);
	if ($state !== null) {
		echo "   ✓ State file contains VALID JSON\n";
		echo "     - Devices tracked: " . count($state['devices']) . "\n";
		echo "     - Last check: " . (isset($state['last_check']) ? date('Y-m-d H:i:s', $state['last_check']) : 'never') . "\n";
		echo "     - Last reset: " . (isset($state['last_reset']) ? date('Y-m-d H:i:s', $state['last_reset']) : 'never') . "\n";
		$success[] = "State file valid";
	} else {
		echo "   ✗ State file contains INVALID JSON\n";
		$issues[] = "State file corrupted";
		echo "   → FIX: Run 'php -r \"require_once(\\\"/usr/local/pkg/parental_control.inc\\\"); pc_init_state();\"'\n";
	}
} else {
	echo "   ⚠ State file does NOT exist\n";
	$warnings[] = "State file doesn't exist yet";
	
	// Try to create it
	echo "   → Initializing state file...\n";
	try {
		pc_init_state();
		echo "   ✓ Successfully created state file\n";
		$success[] = "Created state file";
	} catch (Exception $e) {
		echo "   ✗ FAILED to create state file: " . $e->getMessage() . "\n";
		$issues[] = "Cannot create state file";
	}
}

// ============================================================================
// CHECK 5: Cron Job
// ============================================================================
echo "\n[5/10] Checking cron job...\n";
$cron_output = shell_exec("crontab -l 2>/dev/null | grep parental_control_cron.php");
if (!empty($cron_output)) {
	echo "   ✓ Cron job is INSTALLED\n";
	echo "     " . trim($cron_output) . "\n";
	$success[] = "Cron job installed";
} else {
	echo "   ✗ Cron job is NOT installed\n";
	$issues[] = "Cron job missing - usage tracking won't work";
	echo "   → FIX: Go to Services → Parental Control and click Save\n";
}

// ============================================================================
// CHECK 6: Cron Script File
// ============================================================================
echo "\n[6/10] Checking cron script file...\n";
$cron_script = '/usr/local/bin/parental_control_cron.php';
if (file_exists($cron_script)) {
	$is_executable = is_executable($cron_script);
	echo "   ✓ Cron script EXISTS\n";
	
	if ($is_executable) {
		echo "   ✓ Cron script is EXECUTABLE\n";
		$success[] = "Cron script executable";
	} else {
		echo "   ✗ Cron script is NOT executable\n";
		$issues[] = "Cron script not executable";
		echo "   → FIX: Run 'chmod 755 {$cron_script}'\n";
	}
} else {
	echo "   ✗ Cron script does NOT exist\n";
	$issues[] = "Cron script file missing";
	echo "   → FIX: Go to Services → Parental Control and click Save\n";
}

// ============================================================================
// CHECK 7: Configured Devices
// ============================================================================
echo "\n[7/10] Checking configured devices...\n";
$devices = config_get_path('installedpackages/parentalcontroldevices/config', array());
$device_count = count($devices);
$enabled_count = 0;

foreach ($devices as $device) {
	if (isset($device['enable']) && $device['enable'] === 'on') {
		$enabled_count++;
	}
}

echo "   Total devices: {$device_count}\n";
echo "   Enabled devices: {$enabled_count}\n";

if ($device_count > 0) {
	echo "   ✓ Devices are configured\n";
	$success[] = "{$device_count} devices configured";
	
	if ($enabled_count === 0) {
		echo "   ⚠ No devices are ENABLED\n";
		$warnings[] = "All devices are disabled";
	}
} else {
	echo "   ⚠ No devices configured yet\n";
	$warnings[] = "No devices configured";
	echo "   → Add devices in Services → Parental Control → Devices tab\n";
}

// ============================================================================
// CHECK 8: Firewall Rules
// ============================================================================
echo "\n[8/10] Checking firewall rules...\n";
$rules = config_get_path('filter/rule', array());
$pc_rule_count = 0;

foreach ($rules as $rule) {
	if (isset($rule['descr']) && strpos($rule['descr'], 'Parental Control') !== false) {
		$pc_rule_count++;
	}
}

echo "   Parental Control firewall rules: {$pc_rule_count}\n";

if ($pc_rule_count > 0) {
	echo "   ✓ Firewall rules are active\n";
	$success[] = "{$pc_rule_count} firewall rules";
} else {
	echo "   ⚠ No firewall rules found\n";
	$warnings[] = "No active firewall rules";
	echo "   → Rules are created when devices exceed limits or are in blocked schedules\n";
}

// ============================================================================
// CHECK 9: PHP Version
// ============================================================================
echo "\n[9/10] Checking PHP version...\n";
$php_version = PHP_VERSION;
echo "   PHP Version: {$php_version}\n";

if (version_compare($php_version, '7.4.0', '>=')) {
	echo "   ✓ PHP version is compatible\n";
	$success[] = "PHP version OK";
} else {
	echo "   ✗ PHP version is TOO OLD (requires 7.4+)\n";
	$issues[] = "PHP version incompatible";
}

// ============================================================================
// CHECK 10: Test Logging
// ============================================================================
echo "\n[10/10] Testing logging function...\n";
try {
	pc_log("Diagnostic test log entry", 'info', array(
		'event.action' => 'diagnostic_test',
		'test.timestamp' => time()
	));
	echo "   ✓ pc_log() function executed without errors\n";
	$success[] = "Logging function works";
	
	if (!$logging_enabled) {
		echo "   ⚠ BUT logging is disabled in config, so nothing was written\n";
	}
} catch (Exception $e) {
	echo "   ✗ pc_log() function FAILED: " . $e->getMessage() . "\n";
	$issues[] = "Logging function error: " . $e->getMessage();
}

// ============================================================================
// SUMMARY
// ============================================================================
echo "\n";
echo "================================================================================\n";
echo "  DIAGNOSTIC SUMMARY\n";
echo "================================================================================\n";
echo "\n";

echo "✓ SUCCESSES (" . count($success) . "):\n";
foreach ($success as $item) {
	echo "  - {$item}\n";
}

if (count($warnings) > 0) {
	echo "\n⚠ WARNINGS (" . count($warnings) . "):\n";
	foreach ($warnings as $item) {
		echo "  - {$item}\n";
	}
}

if (count($issues) > 0) {
	echo "\n✗ CRITICAL ISSUES (" . count($issues) . "):\n";
	foreach ($issues as $item) {
		echo "  - {$item}\n";
	}
	echo "\n";
	echo "================================================================================\n";
	echo "  ACTION REQUIRED\n";
	echo "================================================================================\n";
	echo "\n";
	echo "1. Enable logging:\n";
	echo "   - Go to Services → Parental Control\n";
	echo "   - Check 'Enable Logging'\n";
	echo "   - Set Log Level to 'debug' for troubleshooting\n";
	echo "   - Click Save\n";
	echo "\n";
	echo "2. If cron job is missing:\n";
	echo "   - Go to Services → Parental Control\n";
	echo "   - Click Save (this reinstalls cron job)\n";
	echo "\n";
	echo "3. Manual fixes (if needed):\n";
	echo "   chmod 664 {$log_file}\n";
	echo "   chmod 664 {$state_file}\n";
	echo "   chmod 755 /usr/local/bin/parental_control_cron.php\n";
	echo "\n";
} else {
	echo "\n";
	echo "🎉 All checks passed! System is healthy.\n";
	echo "\n";
	if (count($warnings) > 0) {
		echo "Note: Warnings are informational and don't prevent operation.\n";
		echo "\n";
	}
}

echo "================================================================================\n";
echo "\n";

// Return exit code based on issues
exit(count($issues) > 0 ? 1 : 0);
?>

