<?php
/*
 * parental_control.inc
 * 
 * part of pfSense Parental Control Package
 * Copyright (c) 2025 Mukesh Kesharwani
 * Built with Passion
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 */

require_once("config.inc");
require_once("util.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("notices.inc");
@include_once("cron.inc"); // Optional - may not exist in all pfSense versions

// Package version - Automatically read from VERSION file
if (!defined('PC_VERSION')) {
	$version_file = '/usr/local/pkg/parental_control_VERSION';
	if (file_exists($version_file)) {
		$version_data = parse_ini_file($version_file);
		define('PC_VERSION', $version_data['VERSION'] ?? '1.0.1');
		define('PC_BUILD_DATE', $version_data['BUILD_DATE'] ?? date('Y-m-d'));
	} else {
		// Fallback if VERSION file not deployed (should not happen in production)
		define('PC_VERSION', '1.0.1');
		define('PC_BUILD_DATE', '2025-12-29');
	}
}
define('PC_AUTHOR', 'Mukesh Kesharwani');

// File paths - Support environment variable overrides for flexibility
// This allows customization without editing code, useful for testing and containers
define('PC_LOG_FILE', getenv('PC_LOG_FILE') ?: '/var/log/parental_control.jsonl');
define('PC_STATE_FILE', getenv('PC_STATE_FILE') ?: '/var/db/parental_control_state.json');
// Use /tmp instead of /var/run for PID file - /tmp is world-writable and cleaned on reboot
define('PC_PID_FILE', getenv('PC_PID_FILE') ?: '/tmp/parental_control.pid');
// WHY: Default to 5-minute cron interval to prevent AQM flowset errors
// CRITICAL v1.4.18: Reduced from */5 to */10 (kernel panic prevention)
// Rationale: */5 (every 5 min) = 288 executions/day, high risk of overlap
// New: */10 (every 10 min) = 144 executions/day, much safer
// Trade-off: Slightly less responsive (10 min vs 5 min) but MUCH more stable
// This prevents:
// - Cron job overlap (which caused deadlocks)
// - Resource exhaustion (file descriptors, memory)
// - Kernel panics from excessive pfctl operations
define('PC_CRON_MINUTE', getenv('PC_CRON_MINUTE') ?: '*/5');  // Check every 5 minutes
define('PC_CRON_INTERVAL_SECONDS', 300);  // Match the cron interval (5 minutes = 300 seconds)

// Log rotation settings - Environment overridable for different deployment scenarios
define('PC_MAX_LOG_SIZE', getenv('PC_MAX_LOG_SIZE') ?: (5 * 1024 * 1024));  // 5MB per log file
define('PC_MAX_LOG_FILES', getenv('PC_MAX_LOG_FILES') ?: 10);  // Keep last 10 log files
define('PC_LOG_PREFIX', getenv('PC_LOG_PREFIX') ?: 'parental_control');  // Log file prefix

/**
 * ========================================================================
 * PERFORMANCE CACHING
 * ========================================================================
 */

// Global cache for performance optimization
// WHY: Expensive operations like ARP/DHCP lookups should be cached within a request
// to avoid redundant system calls. Cache is stored in memory and cleared between requests.
global $pc_cache;
$pc_cache = array(
	'dhcp_leases' => null,
	'dhcp_leases_time' => 0,
	'arp_table' => null,
	'arp_table_time' => 0,
	'config_cache' => array(),
	'config_cache_time' => array()
);

// Cache TTL in seconds - short TTL since network state changes quickly
define('PC_CACHE_TTL', 30);

/**
 * Get cached value or compute and cache it
 * 
 * Generic caching wrapper that checks cache freshness and either returns
 * cached value or calls the provided callback to compute a fresh value.
 * 
 * @param string $cache_key Key to use in global cache
 * @param callable $callback Function to call if cache is stale/missing
 * @param int $ttl Cache time-to-live in seconds (default: 30)
 * @return mixed Cached or freshly computed value
 * @since 0.1.4
 * 
 * @example
 * $dhcp_leases = pc_get_cached('dhcp_leases', function() {
 *     return system_get_dhcpleases();
 * }, 60);
 */
function pc_get_cached($cache_key, $callback, $ttl = PC_CACHE_TTL) {
	global $pc_cache;
	
	$value_key = $cache_key;
	$time_key = $cache_key . '_time';
	
	// Check if cache exists and is fresh
	if (isset($pc_cache[$value_key]) && isset($pc_cache[$time_key])) {
		$age = time() - $pc_cache[$time_key];
		if ($age < $ttl) {
			return $pc_cache[$value_key];
		}
	}
	
	// Cache miss or stale - compute fresh value
	$value = $callback();
	$pc_cache[$value_key] = $value;
	$pc_cache[$time_key] = time();
	
	return $value;
}

/**
 * Get IPs from pfctl table with caching
 * 
 * PERFORMANCE OPTIMIZATION v1.4.30: Cache pfctl table reads for status pages
 * WHY: pfctl table queries are expensive, cache for short period to improve responsiveness
 * Use case: Status page refreshes, API polling
 * 
 * @param string $table_name Table name (e.g., 'parental_control_blocked')
 * @param int $ttl Cache TTL in seconds (default: 15)
 * @return array Array of IP addresses (empty array on error)
 * @since 1.4.30
 * 
 * @example
 * $blocked_ips = pc_get_table_ips_cached('parental_control_blocked');
 * $monitored_ips = pc_get_table_ips_cached('parental_control_monitor');
 */
function pc_get_table_ips_cached($table_name, $ttl = 15) {
	return pc_get_cached("pfctl_table_{$table_name}", function() use ($table_name) {
		$ips = array();
		exec("pfctl -t " . escapeshellarg($table_name) . " -T show 2>&1", $ips, $return_code);
		return ($return_code === 0) ? array_filter($ips) : array();
	}, $ttl);
}

/**
 * Clear performance cache
 * 
 * WHY: Called after configuration changes to ensure fresh data is loaded.
 * Prevents showing stale data after user modifies settings.
 * 
 * @param string|null $cache_key Specific cache key to clear, or null to clear all
 * @return void
 * @since 0.1.4
 */
function pc_clear_cache($cache_key = null) {
	global $pc_cache;
	
	if ($cache_key === null) {
		// Clear all caches
		$pc_cache = array(
			'dhcp_leases' => null,
			'dhcp_leases_time' => 0,
			'arp_table' => null,
			'arp_table_time' => 0,
			'config_cache' => array(),
			'config_cache_time' => array()
		);
	} else {
		// Clear specific cache
		unset($pc_cache[$cache_key]);
		unset($pc_cache[$cache_key . '_time']);
	}
}

/**
 * ========================================================================
 * HELPER FUNCTIONS (DRY - Don't Repeat Yourself)
 * ========================================================================
 */

/**
 * Normalize MAC address to lowercase with trimmed whitespace
 * 
 * WHY: MAC addresses can be entered in various formats (AA:BB:CC or aa:bb:cc)
 * and different parts of the system may use different cases. This function
 * provides a single canonical normalization that all code should use.
 * 
 * @param string $mac MAC address in any case with optional whitespace
 * @return string Normalized MAC address in lowercase without whitespace
 * @since 0.1.4
 * 
 * @example
 * $mac = pc_normalize_mac(" AA:BB:CC:DD:EE:FF "); // Returns "aa:bb:cc:dd:ee:ff"
 */
function pc_normalize_mac($mac) {
	return strtolower(trim($mac));
}

/**
 * Validate MAC address format
 * 
 * Checks if MAC address matches standard format: aa:bb:cc:dd:ee:ff
 * Accepts both colon and hyphen separators. Case insensitive.
 * 
 * @param string $mac MAC address to validate
 * @return bool True if valid format, false otherwise
 * @since 0.1.4
 * 
 * @example
 * pc_validate_mac("aa:bb:cc:dd:ee:ff"); // Returns true
 * pc_validate_mac("AA-BB-CC-DD-EE-FF"); // Returns true
 * pc_validate_mac("invalid"); // Returns false
 */
function pc_validate_mac($mac) {
	$mac = pc_normalize_mac($mac);
	return preg_match('/^([0-9a-f]{2}[:-]){5}([0-9a-f]{2})$/i', $mac) === 1;
}

/**
 * Validate time format (HH:MM 24-hour)
 * 
 * Validates that time string is in HH:MM format (24-hour).
 * Hours: 00-23, Minutes: 00-59
 * 
 * @param string $time Time string to validate
 * @return bool True if valid format, false otherwise
 * @since 0.1.4
 * 
 * @example
 * pc_validate_time("14:30"); // Returns true
 * pc_validate_time("25:00"); // Returns false (invalid hour)
 * pc_validate_time("14:70"); // Returns false (invalid minute)
 */
function pc_validate_time($time) {
	if (empty($time)) {
		return false;
	}
	return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) === 1;
}

/**
 * Validate numeric value is within range
 * 
 * Checks that value is numeric and within specified min/max range (inclusive).
 * Used for validating time limits, intervals, etc.
 * 
 * @param mixed $value Value to validate
 * @param int $min Minimum allowed value (inclusive)
 * @param int $max Maximum allowed value (inclusive)
 * @return bool True if valid and in range, false otherwise
 * @since 0.1.4
 * 
 * @example
 * pc_validate_numeric_range(60, 0, 1440); // Returns true
 * pc_validate_numeric_range(2000, 0, 1440); // Returns false (exceeds max)
 * pc_validate_numeric_range("abc", 0, 100); // Returns false (not numeric)
 */
function pc_validate_numeric_range($value, $min, $max) {
	if (!is_numeric($value)) {
		return false;
	}
	$num = intval($value);
	return ($num >= $min && $num <= $max);
}

/**
 * Check if MAC address is unique in configuration
 * 
 * Checks if MAC address already exists in device configuration,
 * excluding the device being currently edited (if editing).
 * 
 * @param string $mac MAC address to check
 * @param int|null $exclude_id Device ID to exclude from check (for edits)
 * @return array Array with keys: 'unique' (bool), 'existing_device' (string|null)
 * @since 0.1.4
 * 
 * @example
 * $result = pc_is_mac_unique("aa:bb:cc:dd:ee:ff", null);
 * if (!$result['unique']) {
 *     echo "MAC already used by: " . $result['existing_device'];
 * }
 */
function pc_is_mac_unique($mac, $exclude_id = null) {
	$mac = pc_normalize_mac($mac);
	$devices = config_get_path('installedpackages/parentalcontroldevices/config', []);
	
	foreach ($devices as $idx => $device) {
		if ($idx == $exclude_id) {
			continue; // Skip the device being edited
		}
		
		if (isset($device['mac_address']) && pc_normalize_mac($device['mac_address']) == $mac) {
			return array(
				'unique' => false,
				'existing_device' => isset($device['device_name']) ? $device['device_name'] : 'Unknown Device'
			);
		}
	}
	
	return array('unique' => true, 'existing_device' => null);
}

/**
 * Check if parental control service is enabled
 * 
 * WHY: This check is repeated in multiple places (cron jobs, sync functions, etc.)
 * Centralizing it makes the code more maintainable and consistent.
 * 
 * @return bool True if service is enabled, false otherwise
 * @since 0.1.4
 * 
 * @example
 * if (!pc_is_service_enabled()) {
 *     return; // Service disabled, exit early
 * }
 */
function pc_is_service_enabled() {
	return config_get_path('installedpackages/parentalcontrol/config/0/enable') === 'on';
}

/**
 * Check if current user is an administrator
 * 
 * WHY: Some sensitive operations (like manual usage reset) should only be available
 * to administrators, not all logged-in users.
 * 
 * Uses pfSense native functions to properly check group membership.
 * Group membership is stored in system/group[]/member[] arrays by UID,
 * not in the user record itself.
 * 
 * @return bool True if current user is in the 'admins' group or has UID 0
 * @since 1.4.25
 * 
 * @example
 * if (!pc_is_admin_user()) {
 *     return; // Not admin, deny access
 * }
 */
function pc_is_admin_user() {
	if (!isset($_SESSION['Username'])) {
		return false;
	}
	
	$username = $_SESSION['Username'];
	
	// Use pfSense native function to get user entry
	// getUserEntry() returns ['idx' => index, 'item' => user_data]
	$user_entry = getUserEntry($username);
	
	if (!$user_entry || !isset($user_entry['item'])) {
		return false;
	}
	
	$user = $user_entry['item'];
	
	// Check if user has UID 0 (root/admin)
	if (isset($user['uid']) && $user['uid'] == '0') {
		return true;
	}
	
	// Use pfSense native function to get user's groups
	// This checks system/group[]/member[] arrays for user's UID
	$user_groups = local_user_get_groups($user, true);
	
	return in_array('admins', $user_groups);
}

/**
 * Check if a device is enabled
 * 
 * WHY: Device enable/disable logic is used in multiple loops.
 * This helper provides consistent checking and easier maintenance.
 * 
 * @param array $device Device configuration array
 * @return bool True if device is enabled, false otherwise
 * @since 0.1.4
 * 
 * @example
 * foreach ($devices as $device) {
 *     if (!pc_is_device_enabled($device)) {
 *         continue;
 *     }
 *     // Process enabled device...
 * }
 */
function pc_is_device_enabled($device) {
	return isset($device['enable']) && $device['enable'] === 'on';
}

/**
 * Get all configured devices
 * 
 * WHY: Avoids repetitive config_get_path calls and provides consistent default value.
 * PERFORMANCE: Caches results for repeated calls within same request.
 * 
 * @param bool $enabled_only If true, return only enabled devices
 * @param bool $no_cache If true, bypass cache and fetch fresh data
 * @return array Array of device configurations
 * @since 0.1.4
 * 
 * @example
 * $all_devices = pc_get_devices();
 * $enabled_devices = pc_get_devices(true);
 */
function pc_get_devices($enabled_only = false, $no_cache = false) {
	// CRITICAL FIX: Extract devices from profiles, not separate config section
	// WHY: Devices are stored nested within profiles, not in a separate section
	$cache_key = 'devices_all';
	
	$all_devices = [];
	
	if ($no_cache) {
		$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
	} else {
		$profiles = pc_get_cached($cache_key, function() {
			return config_get_path('installedpackages/parentalcontrolprofiles/config', []);
		}, 30);
	}
	
	// Extract devices from all profiles
	foreach ($profiles as $profile) {
		// Check if profile is enabled
		if (empty($profile['enabled']) || $profile['enabled'] !== 'on') {
			continue; // Skip disabled profiles
		}
		
		// Get devices from profile (normalize row to devices)
		$profile_devices = [];
		if (isset($profile['devices']) && is_array($profile['devices'])) {
			$profile_devices = $profile['devices'];
		} elseif (isset($profile['row']) && is_array($profile['row'])) {
			$profile_devices = $profile['row'];
		}
		
		// Add profile context to each device
		foreach ($profile_devices as $device) {
			// Skip empty rows
			if (empty($device['mac_address'])) {
				continue;
			}
			
			// Add profile information to device
			$device['profile_name'] = $profile['profile_name'] ?? $profile['name'] ?? 'Unknown';
			$device['child_name'] = $profile['profile_name'] ?? $profile['name'] ?? 'Unknown';
			$device['daily_limit'] = $profile['daily_limit'] ?? 0;
			$device['weekend_bonus'] = $profile['weekend_bonus'] ?? 0;
			$device['profile_enabled'] = $profile['enabled'] ?? 'off';
			
			// CRITICAL FIX: Set device 'enable' key for tracking code
			// WHY: pc_update_device_usage() checks $device['enable'] to determine if device should be tracked
			// If device row has explicit enable key, use it, otherwise default to 'on' if profile is enabled
			if (!isset($device['enable'])) {
				$device['enable'] = 'on'; // Profile is enabled (we're in the enabled profile loop), so enable device
			}
			
			$all_devices[] = $device;
		}
	}
	
	if (!$enabled_only) {
		return $all_devices;
	}
	
	// Filter to only enabled devices
	$enabled_devices = array();
	foreach ($all_devices as $device) {
		if (pc_is_device_enabled($device)) {
			$enabled_devices[] = $device;
		}
	}
	
	return $enabled_devices;
}

/**
 * Get all configured profiles
 * 
 * WHY: Centralized access to profiles with caching for performance.
 * PERFORMANCE: Reduces repeated config_get_path calls.
 * 
 * @param bool $no_cache If true, bypass cache and fetch fresh data
 * @return array Array of profile configurations
 * @since 0.1.4
 * 
 * @example
 * $profiles = pc_get_profiles();
 */
function pc_get_profiles($no_cache = false) {
	$cache_key = 'profiles_all';
	
	$profiles = [];
	if ($no_cache) {
		$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
	} else {
		$profiles = pc_get_cached($cache_key, function() {
			return config_get_path('installedpackages/parentalcontrolprofiles/config', []);
		}, 30);
	}
	
	// CRITICAL FIX: Normalize 'row' to 'devices' for internal code consistency
	// WHY: pfSense ALWAYS stores rowhelpers as 'row' in config.xml
	// But our internal code uses 'devices' for clarity and readability
	// This normalization happens during READ only, never during WRITE
	foreach ($profiles as &$profile) {
		if (isset($profile['row']) && is_array($profile['row'])) {
			$profile['devices'] = $profile['row'];
		}
	}
	unset($profile);
	
	return $profiles;
}

/**
 * ========================================================================
 * VALIDATION FUNCTIONS
 * ========================================================================
 */

/**
 * Validate main settings input from web interface
 * 
 * Validates all configuration fields from the main settings form including
 * grace period, check interval, override duration, and email settings.
 * Adds validation errors to $input_errors array passed by reference.
 * 
 * @param array $post POST data from form submission
 * @param array &$input_errors Array to collect validation error messages (passed by reference)
 * @return void Errors are added to $input_errors array
 * @since 0.1.0
 * 
 * @example
 * $errors = array();
 * parental_control_validate_input($_POST, $errors);
 * if (!empty($errors)) {
 *     // Display errors to user
 * }
 */
function parental_control_validate_input($post, &$input_errors) {
	// Validate grace period
	if (isset($post['grace_period']) && $post['grace_period'] != '') {
		if (!is_numeric($post['grace_period']) || $post['grace_period'] < 0 || $post['grace_period'] > 30) {
			$input_errors[] = "Grace period must be between 0 and 30 minutes.";
		}
	}
	
	// Validate check interval
	if (isset($post['check_interval']) && $post['check_interval'] != '') {
		if (!is_numeric($post['check_interval']) || $post['check_interval'] < 30 || $post['check_interval'] > 300) {
			$input_errors[] = "Check interval must be between 30 and 300 seconds.";
		}
	}
	
	// Validate override duration
	if (isset($post['override_duration']) && $post['override_duration'] != '') {
		if (!is_numeric($post['override_duration']) || $post['override_duration'] < 1 || $post['override_duration'] > 240) {
			$input_errors[] = "Override duration must be between 1 and 240 minutes.";
		}
	}
	
	// Validate email recipients
	if (isset($post['enable_email']) && $post['enable_email'] && !empty($post['email_recipients'])) {
		$emails = explode(',', $post['email_recipients']);
		foreach ($emails as $email) {
			$email = trim($email);
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$input_errors[] = "Invalid email address: {$email}";
			}
		}
	}
}

/**
 * Validate device configuration input
 * 
 * Performs comprehensive validation of device settings including child name,
 * device name, MAC address format and uniqueness, IP address format, time limits,
 * and schedule time formats. Ensures data integrity before saving to configuration.
 * 
 * @param array $post POST data containing device configuration
 * @param array &$input_errors Array to collect validation error messages (passed by reference)
 * @return void Errors are added to $input_errors array
 * @since 0.1.0
 * 
 * @example
 * $errors = array();
 * parental_control_device_validate($_POST, $errors);
 * if (empty($errors)) {
 *     // Save device configuration
 * }
 * 
 * @see parental_control_validate_input() For main settings validation
 */
function parental_control_device_validate($post, &$input_errors) {
	// Validate child name
	if (empty($post['child_name'])) {
		$input_errors[] = "Child name is required.";
	}
	
	// Validate device name
	if (empty($post['device_name'])) {
		$input_errors[] = "Device name is required.";
	}
	
	// Validate MAC address
	if (empty($post['mac_address'])) {
		$input_errors[] = "MAC address is required.";
	} else {
		$mac = $post['mac_address'];
		
		// Check format using helper function
		if (!pc_validate_mac($mac)) {
			$input_errors[] = "Invalid MAC address format. Use format: aa:bb:cc:dd:ee:ff";
		}
		
		// Check for duplicate MAC using helper function
		$current_id = isset($post['id']) ? $post['id'] : null;
		$uniqueness = pc_is_mac_unique($mac, $current_id);
		if (!$uniqueness['unique']) {
			$input_errors[] = "MAC address already exists for device: {$uniqueness['existing_device']}";
		}
	}
	
	// Validate IP address if provided
	if (!empty($post['ip_address']) && !is_ipaddr($post['ip_address'])) {
		$input_errors[] = "Invalid IP address format.";
	}
	
	// Validate time limits using helper function
	if (isset($post['daily_limit']) && $post['daily_limit'] != '') {
		if (!pc_validate_numeric_range($post['daily_limit'], 0, 1440)) {
			$input_errors[] = "Daily limit must be between 0 and 1440 minutes (24 hours).";
		}
	}
	
	if (isset($post['weekly_limit']) && $post['weekly_limit'] != '') {
		if (!pc_validate_numeric_range($post['weekly_limit'], 0, 10080)) {
			$input_errors[] = "Weekly limit must be between 0 and 10080 minutes (7 days).";
		}
	}
	
	// Validate time formats using helper function
	if (!empty($post['bedtime_start']) && !pc_validate_time($post['bedtime_start'])) {
		$input_errors[] = "Invalid bedtime start format. Use HH:MM (24-hour format).";
	}
	
	if (!empty($post['bedtime_end']) && !pc_validate_time($post['bedtime_end'])) {
		$input_errors[] = "Invalid bedtime end format. Use HH:MM (24-hour format).";
	}
	
	if (!empty($post['school_start']) && !pc_validate_time($post['school_start'])) {
		$input_errors[] = "Invalid school start format. Use HH:MM (24-hour format).";
	}
	
	if (!empty($post['school_end']) && !pc_validate_time($post['school_end'])) {
		$input_errors[] = "Invalid school end format. Use HH:MM (24-hour format).";
	}
}

/**
 * Hook called before form display in web interface
 * 
 * This function is called by pfSense before rendering the configuration form.
 * Currently a placeholder for future pre-form processing logic such as
 * loading dynamic data, setting default values, or preparing dropdowns.
 * 
 * @return void
 * @since 0.1.0
 * 
 * @example
 * // Called automatically by pfSense via custom_php_before_form hook
 * parental_control_before_form();
 */
function parental_control_before_form() {
	// Nothing to do yet
}

/**
 * Synchronize configuration and update firewall rules
 * 
 * Main synchronization function called whenever configuration changes.
 * Processes profile devices, checks if service is enabled, updates firewall
 * rules, sets up cron job, initializes state file, and reloads filter configuration.
 * This is the central function that applies all parental control settings.
 * 
 * @return void
 * @since 0.1.0
 * @updated 0.1.3 Added comprehensive logging
 * 
 * @example
 * // Called automatically after configuration changes
 * parental_control_sync();
 * 
 * @see pc_update_firewall_rules() For firewall rule creation
 * @see pc_setup_cron_job() For cron job installation
 * @see pc_process_profile_devices() For device extraction from profiles
 */
function parental_control_sync() {
	global $config;
	
	try {
		pc_log("Syncing parental control configuration", 'info', array(
			'event.action' => 'config_sync_start',
			'event.category' => 'configuration'
		));
		
		// Check if service is enabled
		$enabled = config_get_path('installedpackages/parentalcontrol/config/0/enable') === 'on';
		
		if (!$enabled) {
			pc_log("Parental control is disabled", 'info', array(
				'event.action' => 'service_disabled',
				'service.state' => 'disabled'
			));
			pc_remove_cron_job();
			return;
		}
		
		// MINIMAL SYNC - Just ensure cron job and state file exist
		// Everything else handled by cron job via anchors
		
		// Setup cron job for periodic checks
		pc_setup_cron_job();
		
		// Initialize state file if needed
		pc_init_state();
		
	// CRITICAL: Create pfSense aliases (tables) for dynamic blocking and monitoring
	// WHY: Tables integrate properly with pfSense's rule system (unlike anchors)
	// 1. parental_control_blocked - Devices that exceeded limits (for blocking)
	// 2. parental_control_monitor - ALL profile devices (for usage tracking)
	pc_create_blocking_alias();
	pc_create_monitoring_alias();
	
	// v1.4.31: Initialize service-specific blocking tables
	// Creates per-service blocking aliases for granular service limit enforcement
	pc_init_service_block_tables();
	
	// CRITICAL: Create allow rules for DNS and pfSense access
		// WHY: Blocked devices need DNS to resolve names and access to pfSense for block page
		// ORDER: These MUST come BEFORE the block-all rule (floating rules with quick)
		pc_create_allow_rules();
		
	// NOTE: Captive portal start moved to async call below to avoid blocking boot
	// Previous sync call caused boot hangs - see pc_ensure_captive_portal_running_async()
		
		// CRITICAL: Create NAT redirect rules for HTTP/HTTPS to captive portal
		// WHY: Instead of silent blocking, show users a friendly explanation page
		pc_create_redirect_rules();
		
	// CRITICAL: Create floating rule that blocks traffic from the alias
	// WHY: Floating rules are evaluated BEFORE interface rules (fixes rule ordering)
	// ORDER: This comes LAST so allow rules are checked first
	
	// Create service monitoring rules (must come BEFORE blocking rule)
	pc_create_service_monitoring_rules();
	
	// Create the blocking rule (comes after monitoring rules)
	pc_create_blocking_rule();
	
	// CRITICAL: Start captive portal server in background (non-blocking)
	// WHY: Starting during boot sync can block boot process if server startup hangs
	// SOLUTION: Use background execution to avoid blocking boot
	// NOTE: Server will start automatically, cron job will verify/restart if needed
	pc_ensure_captive_portal_running_async();
	
	// Reload filter/NAT if needed (only if alias or rules were created)
	if (is_subsystem_dirty('aliases') || is_subsystem_dirty('filter') || is_subsystem_dirty('nat')) {
		filter_configure();
		clear_subsystem_dirty('aliases');
		clear_subsystem_dirty('filter');
		clear_subsystem_dirty('nat');
	}
		
		pc_log("Configuration sync completed (table-based blocking)", 'info', array(
			'event.action' => 'config_sync_complete',
			'event.category' => 'configuration',
			'event.outcome' => 'success',
			'method' => 'pf_table',
			'note' => 'Using pfSense tables/aliases - proper rule ordering'
		));
		
	} catch (Exception $e) {
		$error_msg = "Configuration sync failed: " . $e->getMessage();
		pc_log($error_msg, 'error', array(
			'event.action' => 'config_sync_failed',
			'event.category' => 'configuration',
			'event.outcome' => 'failure',
			'error.type' => get_class($e),
			'error.message' => $e->getMessage(),
			'error.file' => $e->getFile(),
			'error.line' => $e->getLine()
		));
		// Don't re-throw - allow system to continue functioning
	}
}

/**
 * Process profile devices to extract information from device_selector dropdown
 * 
 * Parses the device_selector field values (format: mac|ip|hostname) and
 * auto-populates the mac_address, ip_address, and device_name fields.
 * Handles both pipe-delimited format and plain MAC addresses with DHCP/ARP
 * lookup. Modifies configuration in-place and saves if changes detected.
 * 
 * This function bridges the gap between the user-friendly dropdown selection
 * and the structured device data needed for enforcement.
 * 
 * @return void Configuration is modified in global $config and saved if needed
 * @since 0.1.0
 * @updated 0.1.3 Improved error handling and logging
 * 
 * @example
 * // Called automatically during parental_control_sync()
 * pc_process_profile_devices();
 * 
 * @see pc_get_dhcp_leases() For device lookup
 */
function pc_process_profile_devices() {
	global $config;
	
	$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config');
	if (!is_array($profiles)) {
		return;
	}
	
	// Get current DHCP/ARP devices for lookup
	$dhcp_devices = pc_get_dhcp_leases();
	$device_map = array();
	foreach ($dhcp_devices as $dev) {
		$device_map[strtolower($dev['mac'])] = $dev;
	}
	
	$modified = false;
	foreach ($profiles as $pidx => &$profile) {
		// pfSense rowhelpers ALWAYS store as 'row' in config.xml
		// The fieldname in XML just affects form variables during edit
		if (!isset($profile['row']) || !is_array($profile['row'])) {
			continue;
		}
		
		foreach ($profile['row'] as $didx => &$device) {
			if (isset($device['device_selector']) && !empty($device['device_selector'])) {
				$selector_value = trim($device['device_selector']);
				
				// Check if value contains pipe delimiter (mac|ip|hostname format)
				if (strpos($selector_value, '|') !== false) {
					// Parse the device_selector value: mac|ip|hostname
					$parts = explode('|', $selector_value);
					if (count($parts) >= 3) {
						$device['mac_address'] = $parts[0];
						$device['ip_address'] = $parts[1];
						$device['device_name'] = $parts[2];
						$modified = true;
					}
				} else {
					// Treat as MAC address only - look up details from DHCP/ARP
					$mac = strtolower($selector_value);
					if (isset($device_map[$mac])) {
						$dev_info = $device_map[$mac];
						$device['mac_address'] = $dev_info['mac'];
						$device['ip_address'] = $dev_info['ip'];
						$device['device_name'] = $dev_info['hostname'] ?: $dev_info['ip'];
						$modified = true;
						
						pc_log("Looked up device from ARP table", 'debug', array(
							'profile' => $profile['name'],
							'mac' => $device['mac_address'],
							'device' => $device['device_name']
						));
					}
				}
			}
		}
		unset($device);
	}
	unset($profile);
	
	// Save back to config if modified
	if ($modified) {
		config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
		write_config('Auto-populated device information from DHCP/ARP');
		
		// PERFORMANCE: Clear cache after config modification
		// WHY: Ensures subsequent reads get fresh data
		pc_clear_cache('profiles_all');
		pc_clear_cache('devices_all');
		
		pc_log("Processed and saved device information", 'info', array(
			'event.action' => 'devices_processed',
			'event.category' => 'configuration'
		));
	}
}

/**
 * Package installation handler
 * 
 * Called during package installation. Creates default configuration with
 * sensible defaults, initializes required directories (/var/log, /var/db),
 * and creates initial state file. This function ensures the package is
 * ready to use immediately after installation.
 * 
 * @return void
 * @since 0.1.0
 * @updated 0.1.3 Added logging and directory creation
 * 
 * @example
 * // Called automatically by pfSense during package installation
 * parental_control_install();
 * 
 * @see parental_control_deinstall() For uninstallation
 * @see pc_init_state() For state file initialization
 */
function parental_control_install() {
	global $config;
	
	pc_log("Installing Parental Control package", 'info');
	
	// Create default configuration if not exists
	if (!is_array(config_get_path('installedpackages/parentalcontrol'))) {
		config_set_path('installedpackages/parentalcontrol', array(
			'enable' => 'off',
			'enforcement_mode' => 'strict',
			'grace_period' => '5',
			'reset_time' => 'midnight',
			'enable_logging' => 'on',
			'log_level' => 'info',
			'check_interval' => '60',
			'blocked_message' => 'Your internet time is up! Time to take a break and do other activities.',
			'override_duration' => '30'
		));
		write_config("Installed Parental Control with default settings");
	}
	
	// Create log directory
	safe_mkdir('/var/log');
	
	// Create state directory
	safe_mkdir('/var/db');
	
	// Initialize state file
	pc_init_state();
	
	pc_log("Parental Control package installed successfully", 'info');
}

/**
 * Package uninstallation handler
 * 
 * Called during package removal. Removes all firewall rules created by the
 * package, removes cron job, and reloads filter configuration. Note that
 * state file and logs are intentionally preserved for reference and can be
 * manually deleted if desired.
 * 
 * @return void
 * @since 0.1.0
 * 
 * @example
 * // Called automatically by pfSense during package uninstallation
 * parental_control_deinstall();
 * 
 * @see parental_control_install() For installation
 * @see pc_remove_firewall_rules() For rule cleanup
 * @see pc_remove_cron_job() For cron cleanup
 */
function parental_control_deinstall() {
	pc_log("Uninstalling Parental Control package", 'info');
	
	// Remove firewall rules
	pc_remove_firewall_rules();
	
	// Remove cron job
	pc_remove_cron_job();
	
	// Filter reload
	filter_configure();
	
	pc_log("Parental Control package uninstalled", 'info');
	
	// Note: We keep the state file and logs for reference
}

/**
 * Device deletion handler
 * 
 * Called when a device is deleted from configuration. Triggers a full
 * configuration sync to update firewall rules and remove any rules
 * associated with the deleted device.
 * 
 * @return void
 * @since 0.1.0
 * 
 * @see parental_control_sync() For configuration synchronization
 */
function parental_control_device_delete() {
	// Sync will update rules
	parental_control_sync();
}

/**
 * Get today's midnight timestamp
 * 
 * Returns the Unix timestamp for midnight (00:00:00) of the current day in the
 * system's local timezone. This is used for initializing last_reset times to
 * ensure proper alignment with the daily reset schedule from the moment of
 * creation.
 * 
 * CRITICAL: Without this, new installations or profile creations would set
 * last_reset to the current time (e.g., 9:13 AM), causing the system to wait
 * until the next day's midnight before performing the first reset. This function
 * ensures immediate alignment with the daily reset boundary.
 * 
 * @return int Unix timestamp for today at 00:00:00 local time
 * @since 1.4.17
 * 
 * @example
 * $last_reset = pc_get_today_midnight(); // Returns today at 00:00:00
 * 
 * @see pc_init_state() Uses this for state initialization
 * @see pc_should_reset_counters() For reset time checking
 */
function pc_get_today_midnight() {
	return strtotime("today 00:00:00");
}

/**
 * Initialize state file with default structure
 * 
 * Creates a new state file with empty devices and profiles arrays if it
 * doesn't exist. The state file tracks usage statistics, last reset times,
 * and last check times. File is created with JSON_PRETTY_PRINT for easier
 * manual inspection and debugging.
 * 
 * @return void Creates PC_STATE_FILE if it doesn't exist
 * @since 0.1.0
 * @updated 0.1.3 Added debug logging
 * @updated 1.4.17 Set last_reset to today's midnight instead of current time
 * 
 * @example
 * // Called automatically during installation and first run
 * pc_init_state();
 * 
 * @see pc_load_state() For reading state
 * @see pc_save_state() For writing state
 * @see pc_get_today_midnight() For proper reset time initialization
 */
function pc_init_state() {
	if (!file_exists(PC_STATE_FILE)) {
		// GRACEFUL DEGRADATION: Ensure state directory exists
		// WHY: State directory might not exist on fresh install
		$state_dir = dirname(PC_STATE_FILE);
		if (!is_dir($state_dir)) {
			@mkdir($state_dir, 0755, true);
		}
		
		// CRITICAL: v0.2.1+ uses IP-based structure (Layer 3 compliance)
		// CRITICAL FIX v1.4.17: Set last_reset to today's midnight for proper alignment
		$state = array(
			'devices_by_ip' => array(),
			'mac_to_ip_cache' => array(),
			'profiles' => array(),
			'last_reset' => pc_get_today_midnight(),
			'last_check' => time()
		);
		
		try {
			$result = file_put_contents(PC_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
			if ($result !== false) {
				@chmod(PC_STATE_FILE, 0644);
				pc_log("Initialized state file with IP-based structure (Layer 3)", 'info', array(
					'event.action' => 'state_initialized',
					'format' => 'ip_based',
					'version' => '0.2.1'
				));
			} else {
				pc_log("Failed to initialize state file", 'error');
			}
		} catch (Exception $e) {
			pc_log("Error initializing state file: " . $e->getMessage(), 'error');
		}
	}
}

/**
 * Load state from persistent storage file
 * 
 * Reads and decodes the JSON state file containing usage tracking data.
 * If state file doesn't exist, initializes a new one with defaults.
 * Returns associative array with devices, profiles, timestamps, etc.
 * 
 * @return array Associative array containing state data with keys:
 *               - devices: array of device usage keyed by MAC address
 *               - profiles: array of profile usage keyed by profile name
 *               - last_reset: Unix timestamp of last daily reset
 *               - last_check: Unix timestamp of last cron check
 * @since 0.1.0
 * 
 * @example
 * $state = pc_load_state();
 * $usage = $state['devices']['aa:bb:cc:dd:ee:ff']['usage_today'];
 * echo "Usage today: {$usage} minutes";
 * 
 * @see pc_save_state() For writing state
 * @see pc_init_state() For state initialization
 */
function pc_load_state() {
	// PERFORMANCE: Cache state in memory during single request/execution
	// WHY: State is read multiple times during rule processing and usage tracking.
	// File I/O is expensive, so cache for duration of current execution.
	return pc_get_cached('state_data', function() {
		return pc_load_state_from_disk();
	}, 5); // Very short TTL since state changes frequently
}

/**
 * Internal function to load state from disk without caching
 * 
 * @internal
 * @return array State data
 */
function pc_load_state_from_disk() {
	try {
		if (!file_exists(PC_STATE_FILE)) {
			pc_init_state();
		}
		$json = file_get_contents(PC_STATE_FILE);
		$state = json_decode($json, true);
		
		// Validate that state is a valid array
		if (!is_array($state)) {
			throw new Exception("State file contains invalid JSON");
		}
		
		// CRITICAL: Migrate from MAC-based to IP-based state (v0.2.0 → v0.2.1)
		$state = pc_migrate_state_to_ip_based($state);
		
		return $state;
	} catch (Exception $e) {
		pc_log("Failed to load state file, reinitializing: " . $e->getMessage(), 'warning', array(
			'event.action' => 'state_load_failed',
			'event.category' => 'system',
			'error.message' => $e->getMessage(),
			'state.file' => PC_STATE_FILE
		));
		
		// Reinitialize with defaults on failure
		pc_init_state();
		
		// Return fresh state with IP-based structure
		// CRITICAL FIX v1.4.17: Set last_reset to today's midnight for proper alignment
		return array(
			'devices_by_ip' => array(),
			'mac_to_ip_cache' => array(),
			'profiles' => array(),
			'last_reset' => pc_get_today_midnight(),
			'last_check' => time()
		);
	}
}

/**
 * Migrate state file from MAC-based to IP-based structure
 * 
 * Version 0.2.1 architectural fix: pfSense operates at Layer 3 (IP addresses).
 * All operational logic must use IP addresses, not MAC addresses.
 * MAC addresses are only for device identification in configuration.
 * 
 * OLD FORMAT (v0.2.0):
 * {
 *   "devices": {
 *     "aa:bb:cc:dd:ee:ff": {"usage_today": 120}
 *   }
 * }
 * 
 * NEW FORMAT (v0.2.1+):
 * {
 *   "devices_by_ip": {
 *     "192.168.1.115": {"mac": "aa:bb:cc:dd:ee:ff", "usage_today": 120}
 *   },
 *   "mac_to_ip_cache": {
 *     "aa:bb:cc:dd:ee:ff": "192.168.1.115"
 *   }
 * }
 * 
 * @param array $state The loaded state from disk
 * @return array Migrated state with IP-based structure
 * @since 0.2.1
 */
function pc_migrate_state_to_ip_based($state) {
	// Check if migration needed (old format has 'devices', new has 'devices_by_ip')
	if (isset($state['devices']) && !isset($state['devices_by_ip'])) {
		pc_log("Migrating state file from MAC-based to IP-based (Layer 3 fix)", 'info', array(
			'event.action' => 'state_migration_start',
			'migration.from' => 'v0.2.0_mac_based',
			'migration.to' => 'v0.2.1_ip_based',
			'devices.count' => count($state['devices'])
		));
		
		$new_state = array(
			'devices_by_ip' => array(),
			'mac_to_ip_cache' => array(),
			'profiles' => isset($state['profiles']) ? $state['profiles'] : array(),
			'last_reset' => isset($state['last_reset']) ? $state['last_reset'] : 0,
			'last_check' => isset($state['last_check']) ? $state['last_check'] : 0
		);
		
		$migrated = 0;
		$offline = 0;
		
		// Try to migrate each device
		foreach ($state['devices'] as $mac => $device_data) {
			// Try to resolve current IP
			$ip = pc_get_ip_from_mac($mac);
			
			if ($ip) {
				// Device online - migrate to new format
				$new_state['devices_by_ip'][$ip] = array_merge(
					$device_data,
					array(
						'mac' => $mac,
						'ip' => $ip,
						'name' => isset($device_data['name']) ? $device_data['name'] : $mac
					)
				);
				$new_state['mac_to_ip_cache'][$mac] = $ip;
				$migrated++;
				
				pc_log("Migrated device $mac → $ip", 'debug', array(
					'event.action' => 'device_migrated',
					'device.mac' => $mac,
					'client.address' => $ip
				));
			} else {
				// Device offline - can't migrate now, will resolve on next check
				$offline++;
				pc_log("Device $mac offline during migration - will resolve later", 'debug', array(
					'event.action' => 'device_migration_deferred',
					'device.mac' => $mac
				));
			}
		}
		
		pc_log("State migration complete", 'info', array(
			'event.action' => 'state_migration_complete',
			'devices.migrated' => $migrated,
			'devices.offline' => $offline,
			'devices.total' => count($state['devices'])
		));
		
		// Save migrated state immediately
		pc_save_state($new_state);
		
		return $new_state;
	}
	
	// Already in new format or no devices
	return $state;
}

/**
 * Save state to persistent storage file
 * 
 * Writes state array to JSON file with pretty printing for readability.
 * This function is called after any state modifications to persist changes.
 * File is written atomically to prevent corruption during crashes.
 * 
 * @param array $state State array to save (same structure as pc_load_state return)
 * @return void State is written to PC_STATE_FILE
 * @since 0.1.0
 * 
 * @example
 * $state = pc_load_state();
 * $state['devices'][$mac]['usage_today'] += 5;
 * pc_save_state($state);
 * 
 * @see pc_load_state() For reading state
 */
function pc_save_state($state) {
	try {
		// Validate state is array before saving
		if (!is_array($state)) {
			throw new Exception("Invalid state data: must be an array");
		}
		
		// Encode to JSON
		$json = json_encode($state, JSON_PRETTY_PRINT);
		if ($json === false) {
			throw new Exception("Failed to encode state to JSON: " . json_last_error_msg());
		}
		
	// WHY: Ensure state directory exists and is writable
	// CRITICAL FIX: Create /var/db if it doesn't exist (rare but possible)
	// FreeBSD systems should have /var/db, but check anyway for safety
	$state_dir = dirname(PC_STATE_FILE);
	if (!is_dir($state_dir)) {
		if (!mkdir($state_dir, 0755, true)) {
			throw new Exception("Failed to create state directory: {$state_dir}");
		}
	}
	
	// WHY: Write to file atomically (write to temp file, then rename)
	// Design Decision: Use atomic write pattern to prevent corruption
	// Rationale: If process crashes mid-write, we don't want partial/corrupt state file.
	// Rename is atomic on Unix systems - either fully succeeds or fully fails.
	// This ensures we never have a partially-written state file that could break the system.
	
	// CRITICAL FIX v1.4.10+: Use unique temp file name with PID to avoid conflicts
	// BUG: Multiple processes using same temp file name cause race conditions
	// ROOT CAUSE: Fixed temp file name (state.json.tmp) used by all processes
	// SOLUTION: Include PID in temp file name for uniqueness
	$temp_file = PC_STATE_FILE . '.tmp.' . getmypid();
	
	// CRITICAL FIX v1.4.6: Check disk space before writing
	// BUG: file_put_contents() can return byte count but file doesn't exist
	// ROOT CAUSE: Disk full, quota exceeded, or filesystem issues
	$disk_free = @disk_free_space($state_dir);
	$json_size = strlen($json);
	if ($disk_free !== false && $disk_free < ($json_size * 2)) {
		throw new Exception("Insufficient disk space: need " . ($json_size * 2) . " bytes, have {$disk_free} bytes");
	}
	
	// CRITICAL FIX v1.4.10+: Use file_put_contents with LOCK_EX for atomic write
	// Ensures the write is atomic and prevents other processes from interfering
	$bytes_written = file_put_contents($temp_file, $json, LOCK_EX);
	if ($bytes_written === false) {
		// Enhanced error reporting - include permission details
		$error_details = error_get_last();
		$error_msg = "Failed to write state to temporary file: {$temp_file}";
		if ($error_details) {
			$error_msg .= " - " . $error_details['message'];
		}
		// Check directory permissions
		if (is_dir($state_dir)) {
			$perms = substr(sprintf('%o', fileperms($state_dir)), -4);
			$error_msg .= " (dir perms: {$perms})";
		}
		throw new Exception($error_msg);
	}
	
	// CRITICAL FIX v1.4.10+: Add retry mechanism for filesize check
	// BUG: filesize() can return 0 immediately after write (buffer not flushed)
	// ROOT CAUSE: OS write buffer hasn't been flushed to disk yet
	// SOLUTION: Retry with small delays to allow buffer flush
	clearstatcache(true, $temp_file); // Clear stat cache to get fresh info
	
	$retries = 3;
	$actual_size = 0;
	for ($i = 0; $i < $retries; $i++) {
		if (file_exists($temp_file)) {
			$actual_size = filesize($temp_file);
			if ($actual_size == $bytes_written) {
				// Size matches, good to go!
				break;
			}
			// Size mismatch, wait a bit for buffer flush
			usleep(10000); // 10ms delay
			clearstatcache(true, $temp_file);
		} else {
			// File doesn't exist, wait a bit
			usleep(10000);
			clearstatcache(true, $temp_file);
		}
	}
	
	// After retries, verify file exists and has correct size
	if (!file_exists($temp_file)) {
		$error_msg = "Temp file disappeared after write (tried $retries times)";
		$error_msg .= " - wrote {$bytes_written} bytes but file doesn't exist";
		throw new Exception($error_msg);
	}
	
	if ($actual_size != $bytes_written) {
		$error_msg = "Temp file size mismatch after $retries retries: wrote {$bytes_written} bytes, file is {$actual_size} bytes";
		$error_msg .= " (possible disk full or filesystem corruption)";
		@unlink($temp_file); // Clean up partial file
		throw new Exception($error_msg);
	}
	
	// WHY: Atomic rename ensures crash-resistance
	// Even if power fails during rename, filesystem will either have old or new file, never partial
	// CRITICAL FIX: Enhanced error reporting for rename failures
	// CRITICAL FIX v1.4.5: Add fallback to copy+unlink if rename fails
	// BUG: rename() can fail on FreeBSD with "No such file or directory" even when file exists
	// ROOT CAUSE: Cross-filesystem rename, directory permissions, or file locking issues
	// SOLUTION: Try rename() first (atomic), fallback to copy()+unlink() if it fails
	if (!@rename($temp_file, PC_STATE_FILE)) {
		// Rename failed - try fallback method
		pc_log("Rename failed, attempting copy+unlink fallback", 'debug', array(
			'event.action' => 'state_save_fallback',
			'temp_file' => $temp_file,
			'target_file' => PC_STATE_FILE
		));
		
		// Fallback: copy then unlink (not atomic but works across filesystems)
		if (@copy($temp_file, PC_STATE_FILE)) {
			// Copy succeeded, remove temp file
			@unlink($temp_file);
			pc_log("State file saved using copy+unlink fallback", 'info', array(
				'event.action' => 'state_save_fallback_success',
				'method' => 'copy+unlink'
			));
		} else {
			// Both methods failed - report detailed error
			$error_details = error_get_last();
			$error_msg = "Failed to save state file (both rename and copy failed): {$temp_file} -> " . PC_STATE_FILE;
			if ($error_details) {
				$error_msg .= " - " . $error_details['message'];
			}
			
			// Diagnostic information
			$diagnostics = array();
			$diagnostics['temp_file_exists'] = file_exists($temp_file);
			$diagnostics['target_file_exists'] = file_exists(PC_STATE_FILE);
			$diagnostics['dir_exists'] = is_dir($state_dir);
			$diagnostics['dir_writable'] = is_writable($state_dir);
			
			if (file_exists($temp_file)) {
				$diagnostics['temp_file_perms'] = substr(sprintf('%o', fileperms($temp_file)), -4);
				$diagnostics['temp_file_size'] = filesize($temp_file);
			}
			if (file_exists(PC_STATE_FILE)) {
				$diagnostics['target_file_perms'] = substr(sprintf('%o', fileperms(PC_STATE_FILE)), -4);
			}
			if (is_dir($state_dir)) {
				$diagnostics['dir_perms'] = substr(sprintf('%o', fileperms($state_dir)), -4);
			}
			
			$error_msg .= " | Diagnostics: " . json_encode($diagnostics);
			
			// Clean up temp file
			@unlink($temp_file);
			
			throw new Exception($error_msg);
		}
	}
		
		// PERFORMANCE: Invalidate state cache after save
		// WHY: Ensure next pc_load_state() gets fresh data from disk
		pc_clear_cache('state_data');
		
	} catch (Exception $e) {
		pc_log("Failed to save state file: " . $e->getMessage(), 'error', array(
			'event.action' => 'state_save_failed',
			'event.category' => 'system',
			'event.outcome' => 'failure',
			'error.message' => $e->getMessage(),
			'state.file' => PC_STATE_FILE
		));
		
		// Clean up temp files (both old format and new PID-based format)
		@unlink(PC_STATE_FILE . '.tmp'); // Old format
		@unlink(PC_STATE_FILE . '.tmp.' . getmypid()); // New format with PID
		
		// Re-throw to notify caller of failure
		throw $e;
	}
}

/**
 * Update firewall rules for all configured devices
 * 
 * Main firewall rule management function. Removes existing parental control
 * rules and creates new ones based on current configuration. For each enabled
 * device, checks if it should be blocked due to schedule or time limit, and
 * creates appropriate firewall rules.
 * 
 * Rules are created dynamically based on:
 * - Current time and active schedules
 * - Usage statistics from state file
 * - Enforcement mode (strict, moderate, soft)
 * 
 * @return void Firewall rules are added to global $config
 * @since 0.1.0
 * @updated 0.1.3 Improved logging with structured attributes
 * 
 * @example
 * // Called automatically during sync
 * pc_update_firewall_rules();
 * 
 * @see pc_remove_firewall_rules() For rule cleanup
 * @see pc_create_block_rule() For individual rule creation
 * @see pc_is_in_blocked_schedule() For schedule checking
 * @see pc_is_time_limit_exceeded() For limit checking
 */
function pc_update_firewall_rules() {
	global $config;
	
	try {
		pc_log("Updating firewall rules", 'debug');
		
		// Remove existing rules first
		pc_remove_firewall_rules();
		
		// Check if service is enabled
		if (config_get_path('installedpackages/parentalcontrol/config/0/enable') !== 'on') {
			return;
		}
		
		// Get configuration
		$pc_config = config_get_path('installedpackages/parentalcontrol/config/0', []);
		$devices = pc_get_devices(); // Use helper function (DRY)
		$enforcement_mode = isset($pc_config['enforcement_mode']) ? $pc_config['enforcement_mode'] : 'strict';
		
		$state = pc_load_state();
		$current_time = time();
		
		$rules_created = 0;
		$rules_failed = 0;
		
		foreach ($devices as $idx => $device) {
			try {
				// Skip disabled devices using helper function (DRY)
			if (!pc_is_device_enabled($device)) {
				continue;
			}
			
			$mac = pc_normalize_mac($device['mac_address']);
			$should_block = false;
			$reason = '';
				
				// Check if device has active parent override
				if (pc_has_active_override($mac, $state)) {
					// Skip blocking - parent override is active
					pc_log("Skipping block for {$device['device_name']} - parent override active", 'debug', array(
						'event.action' => 'override_active',
						'device.mac' => $mac,
						'device.name' => $device['device_name']
					));
					continue;
				}
				
				// Check if currently in blocked schedule
				if (pc_is_in_blocked_schedule($device)) {
					$should_block = true;
					$reason = 'Scheduled block time';
				}
				
				// Check if time limit exceeded
				if (!$should_block && pc_is_time_limit_exceeded($device, $state)) {
					$should_block = true;
					$reason = 'Time limit exceeded';
				}
				
				if ($should_block) {
					pc_create_block_rule($device, $enforcement_mode, $reason);
					$rules_created++;
				}
			} catch (Exception $e) {
				// Log error but continue processing other devices (graceful degradation)
				$rules_failed++;
				pc_log("Failed to process device rule: " . $e->getMessage(), 'warning', array(
					'event.action' => 'device_rule_failed',
					'device.mac' => isset($device['mac_address']) ? $device['mac_address'] : 'unknown',
					'device.name' => isset($device['device_name']) ? $device['device_name'] : 'unknown',
					'error.message' => $e->getMessage()
				));
			}
		}
		
		pc_log("Firewall rules updated", 'info', array(
			'rules.created' => $rules_created,
			'rules.failed' => $rules_failed
		));
	} catch (Exception $e) {
		pc_log("Firewall rule update failed critically: " . $e->getMessage(), 'error', array(
			'event.action' => 'firewall_update_failed',
			'event.outcome' => 'failure',
			'error.type' => get_class($e),
			'error.message' => $e->getMessage()
		));
		// Don't re-throw - allow system to continue
	}
}

/**
 * Check if device is currently in a blocked schedule period
 * 
 * Evaluates all schedule types (bedtime, school hours, custom) to determine
 * if the device should be blocked at the current time. Handles:
 * - Bedtime schedules (can span midnight)
 * - School hours (weekdays only, Mon-Fri)
 * - Custom schedules with flexible day/time ranges
 * 
 * Returns true if ANY schedule is currently active that blocks the device.
 * 
 * @param array $device Device configuration array containing schedule settings
 * @return bool True if device should be blocked by schedule, false otherwise
 * @since 0.1.0
 * 
 * @example
 * if (pc_is_in_blocked_schedule($device)) {
 *     echo "Device is in scheduled block period";
 * }
 * 
 * @see pc_is_time_in_range() For time range checking
 * @see pc_matches_custom_schedule() For custom schedule parsing
 */
function pc_is_in_blocked_schedule($device) {
	$now = time();
	$current_day = date('N', $now); // 1 (Monday) through 7 (Sunday)
	$current_time = date('H:i', $now);
	
	// Map day numbers to schedule day strings
	$day_map = array(
		1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu',
		5 => 'fri', 6 => 'sat', 7 => 'sun'
	);
	$current_day_str = $day_map[$current_day];
	
	// Get device's profile name
	$profile_name = isset($device['child_name']) ? $device['child_name'] : '';
	
	if (empty($profile_name)) {
		return false;
	}
	
	// Check new KACI-PC-Schedule system
	$schedules = config_get_path('installedpackages/parentalcontrolschedules/config', []);
	
	if (is_array($schedules)) {
		foreach ($schedules as $schedule) {
			if (!is_array($schedule)) continue;
			
			// Skip disabled schedules
			if (!isset($schedule['enabled']) || $schedule['enabled'] != 'on') {
				continue;
			}
			
			// Check if schedule applies to this profile (handle both old and new format)
			$schedule_profiles = array();
			if (isset($schedule['profile_names'])) {
				// New format: multiple profiles (comma-separated string or array)
				if (is_array($schedule['profile_names'])) {
					$schedule_profiles = $schedule['profile_names'];
				} else {
					$schedule_profiles = array_map('trim', explode(',', $schedule['profile_names']));
				}
			} elseif (isset($schedule['profile_name'])) {
				// Old format: single profile (backward compatibility)
				$schedule_profiles = array($schedule['profile_name']);
			}
			
			// Skip if this profile is not in the schedule's profile list
			if (!in_array($profile_name, $schedule_profiles)) {
				continue;
			}
			
		// Check if today matches the schedule days
		$days = isset($schedule['days']) ? $schedule['days'] : array();
		if (is_string($days)) {
			$days = array_map('trim', explode(',', $days));
		} elseif (!is_array($days)) {
			$days = array();
		}
			$day_matches = false;
			
			foreach ($days as $day) {
				if (strtolower($day) == $current_day_str) {
					$day_matches = true;
					break;
				}
			}
			
			// If day matches, check time
			if ($day_matches && isset($schedule['start_time']) && isset($schedule['end_time'])) {
				if (pc_is_time_in_range($current_time, $schedule['start_time'], $schedule['end_time'])) {
					return true; // Device is in blocked schedule
				}
			}
		}
	}
	
	// Legacy support: Check old bedtime/school fields (will be deprecated)
	if (isset($device['bedtime_enable']) && $device['bedtime_enable'] == 'on') {
		$start = $device['bedtime_start'];
		$end = $device['bedtime_end'];
		
		if (pc_is_time_in_range($current_time, $start, $end)) {
			return true;
		}
	}
	
	// Check school hours (Monday-Friday only)
	if ($current_day >= 1 && $current_day <= 5) {
		if (isset($device['school_enable']) && $device['school_enable'] == 'on') {
			$start = $device['school_start'];
			$end = $device['school_end'];
			
			if (pc_is_time_in_range($current_time, $start, $end)) {
				return true;
			}
		}
	}
	
	// Check custom schedule
	if (!empty($device['custom_schedule'])) {
		$schedules_text = explode("\n", $device['custom_schedule']);
		foreach ($schedules_text as $schedule_line) {
			$schedule_line = trim($schedule_line);
			if (empty($schedule_line)) continue;
			
			if (pc_matches_custom_schedule($schedule_line, $current_day, $current_time)) {
				return true;
			}
		}
	}
	
	return false;
}

/**
 * Check if current time falls within a time range
 * 
 * Handles both normal same-day ranges and overnight ranges that span midnight.
 * The logic differs for these two cases:
 * - Same-day range (09:00-17:00): current >= start AND current <= end
 * - Overnight range (21:00-07:00): current >= start OR current <= end
 * 
 * This is critical for bedtime schedules that typically span midnight.
 * 
 * @param string $current Current time in HH:MM format (24-hour)
 * @param string $start Start time in HH:MM format (24-hour)
 * @param string $end End time in HH:MM format (24-hour)
 * @return bool True if current time is within the range, false otherwise
 * @since 0.1.0
 * 
 * @example
 * // Normal range: 09:00-17:00, current: 12:00
 * $in_range = pc_is_time_in_range('12:00', '09:00', '17:00'); // Returns true
 * 
 * // Overnight range: 21:00-07:00, current: 23:00
 * $in_range = pc_is_time_in_range('23:00', '21:00', '07:00'); // Returns true
 * 
 * // Overnight range: 21:00-07:00, current: 05:00
 * $in_range = pc_is_time_in_range('05:00', '21:00', '07:00'); // Returns true
 */
function pc_is_time_in_range($current, $start, $end) {
	// CRITICAL: Different logic for overnight vs same-day ranges
	// WHY: Overnight ranges like bedtime (21:00-07:00) span midnight, requiring OR logic
	// instead of AND. If we used AND for overnight ranges, no time would ever match
	// because no time can be simultaneously >= 21:00 AND <= 07:00.
	// The OR logic matches either "late night" (>= 21:00) OR "early morning" (<= 07:00).
	
	// CRITICAL FIX: Normalize times to HH:MM format for proper comparison
	// BUG: String comparison "20:30" < "7:30" returns TRUE (wrong!)
	// FIX: Pad single-digit hours with leading zero: "7:30" → "07:30"
	// WHY: String comparison works correctly only with zero-padded hours
	// Example: "20:30" < "07:30" → FALSE (correct!), "20:30" < "7:30" → TRUE (wrong!)
	$current = str_pad($current, 5, '0', STR_PAD_LEFT);
	$start = str_pad($start, 5, '0', STR_PAD_LEFT);
	$end = str_pad($end, 5, '0', STR_PAD_LEFT);
	
	if ($start < $end) {
		// Normal range (e.g., 09:00-17:00)
		// WHY: AND logic works here because time progresses naturally through the range
		return ($current >= $start && $current <= $end);
	} else {
		// Overnight range (e.g., 21:00-07:00)
		// WHY: OR logic catches both sides of midnight split
		// Example: At 23:00, we match (23:00 >= 21:00); at 05:00, we match (05:00 <= 07:00)
		return ($current >= $start || $current <= $end);
	}
}

/**
 * Check if current time matches a custom schedule string
 * 
 * Parses and evaluates custom schedule strings like:
 * - "Mon-Fri 18:00-19:00" (weekday range)
 * - "Sun 09:00-11:00" (single day)
 * - "Daily 21:00-07:00" (every day, can span midnight)
 * - "Sat,Sun 10:00-22:00" (specific days)
 * 
 * Supports day ranges, comma-separated days, and overnight time ranges.
 * Day abbreviations: Mon, Tue, Wed, Thu, Fri, Sat, Sun
 * 
 * @param string $schedule Schedule string to parse (format: "days HH:MM-HH:MM")
 * @param int $current_day Current day of week (1=Mon, 7=Sun)
 * @param string $current_time Current time in HH:MM format (24-hour)
 * @return bool True if current time matches schedule, false otherwise
 * @since 0.1.0
 * 
 * @example
 * $current_day = date('N'); // 1-7 (Mon-Sun)
 * $current_time = date('H:i');
 * if (pc_matches_custom_schedule("Mon-Fri 18:00-19:00", $current_day, $current_time)) {
 *     echo "Weekday homework time!";
 * }
 * 
 * @see pc_is_in_blocked_schedule() For schedule evaluation
 * @see pc_is_time_in_range() For time range checking
 */
function pc_matches_custom_schedule($schedule, $current_day, $current_time) {
	// Parse schedule like "Mon-Fri 18:00-19:00" or "Sun 09:00-11:00"
	if (!preg_match('/^(.+?)\s+(\d{2}:\d{2})-(\d{2}:\d{2})$/', $schedule, $matches)) {
		return false;
	}
	
	$days_str = $matches[1];
	$start_time = $matches[2];
	$end_time = $matches[3];
	
	// Check if current day matches
	$day_matches = false;
	
	if (stripos($days_str, 'daily') !== false || stripos($days_str, 'everyday') !== false) {
		$day_matches = true;
	} else {
		$day_map = array(
			'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 
			'fri' => 5, 'sat' => 6, 'sun' => 7
		);
		
		// Handle ranges like "Mon-Fri"
		if (preg_match('/(\w+)-(\w+)/', $days_str, $range_matches)) {
			$start_day = $day_map[strtolower(substr($range_matches[1], 0, 3))];
			$end_day = $day_map[strtolower(substr($range_matches[2], 0, 3))];
			$day_matches = ($current_day >= $start_day && $current_day <= $end_day);
		} else {
			// Handle comma-separated days like "Mon,Wed,Fri"
			$days = explode(',', $days_str);
			foreach ($days as $day) {
				$day = strtolower(trim(substr($day, 0, 3)));
				if (isset($day_map[$day]) && $day_map[$day] == $current_day) {
					$day_matches = true;
					break;
				}
			}
		}
	}
	
	if (!$day_matches) {
		return false;
	}
	
	// Check time range
	return pc_is_time_in_range($current_time, $start_time, $end_time);
}

/**
 * Check if device has exceeded its configured time limit
 * 
 * Evaluates daily time limit including weekend bonus against current usage
 * from state file. Automatically adds weekend bonus on Saturday and Sunday.
 * Returns false if no limit is configured (unlimited access).
 * 
 * @param array $device Device configuration array with daily_limit and weekend_bonus
 * @param array $state Current state array containing usage statistics
 * @return bool True if time limit exceeded, false if under limit or unlimited
 * @since 0.1.0
 * 
 * @example
 * $state = pc_load_state();
 * if (pc_is_time_limit_exceeded($device, $state)) {
 *     pc_create_block_rule($device, 'strict', 'Time limit exceeded');
 * }
 * 
 * @see pc_load_state() For state retrieval
 */
function pc_is_time_limit_exceeded($device, $state) {
	// CRITICAL: Time limits are SHARED across all devices in a profile
	// WHY: Parents want to limit total screen time, not per-device time
	// Example: If profile limit is 4 hours, that's 4 hours TOTAL across all devices
	// Not 4 hours per device (which would give 20 hours total for 5 devices!)
	
	$profile_name = isset($device['profile_name']) ? $device['profile_name'] : null;
	if (!$profile_name) {
		return false; // No profile assigned
	}
	
	// Get daily limit from profile
	$daily_limit = isset($device['daily_limit']) ? intval($device['daily_limit']) : 0;
	if ($daily_limit == 0) {
		return false; // Unlimited
	}
	
	// Check weekend bonus
	$current_day = date('N');
	if ($current_day >= 6 && isset($device['weekend_bonus'])) {
		$daily_limit += intval($device['weekend_bonus']);
	}
	
	// CRITICAL: Get usage from PROFILE, not device
	// All devices in the same profile share the same usage counter
	$usage_today = 0;
	if (isset($state['profiles'][$profile_name]['usage_today'])) {
		$usage_today = $state['profiles'][$profile_name]['usage_today'];
	}
	
	return ($usage_today >= $daily_limit);
}

/**
 * Create firewall block rule for specific device
 * 
 * Generates and adds a firewall rule to block the specified device based on
 * enforcement mode. Rule format varies by mode:
 * - Strict: Blocks all traffic
 * - Moderate: Blocks only HTTP/HTTPS (ports 80, 443)
 * - Soft: Blocks only DNS (port 53)
 * 
 * Rule is added to global $config with description, source MAC, and reason.
 * Whitelist sites are respected (not yet fully implemented).
 * 
 * @param array $device Device configuration with child_name, device_name, mac_address
 * @param string $enforcement_mode Enforcement mode: 'strict', 'moderate', or 'soft'
 * @param string $reason Human-readable reason for blocking (logged and shown in description)
 * @return void Rule is added to global $config firewall rules array
 * @since 0.1.0
 * @updated 0.1.3 Enhanced logging with structured attributes
 * 
 * @example
 * pc_create_block_rule($device, 'strict', 'Time limit exceeded');
 * pc_create_block_rule($device, 'moderate', 'Bedtime schedule');
 * 
 * @see pc_update_firewall_rules() For bulk rule updates
 * @see pc_remove_firewall_rules() For rule removal
 */
function pc_create_block_rule($device, $enforcement_mode, $reason) {
	global $config;
	
	// Ensure port aliases exist (needed for moderate enforcement mode)
	pc_create_port_aliases();
	
	$mac = $device['mac_address'];
	$child_name = $device['child_name'];
	$device_name = $device['device_name'];
	
	// Get whitelist
	$whitelist = array();
	$whitelist_str = config_get_path('installedpackages/parentalcontrol/whitelist_sites', '');
	if (!empty($whitelist_str)) {
		$whitelist = array_filter(array_map('trim', explode("\n", $whitelist_str)));
	}
	
	// First, create an ALLOW rule for access to the block page
	// This ensures blocked users can see WHY they're blocked
	$block_page_rule = array(
		'type' => 'pass',
		'interface' => 'lan',
		'ipprotocol' => 'inet',  // IPv4 + IPv6
		'protocol' => 'tcp',
		'descr' => "Parental Control: Allow Block Page Access - {$child_name} - {$device_name}",
		'source' => array('address' => $mac),
		'destination' => array(
			'address' => 'LAN address', // pfSense firewall itself
			'port' => '443' // HTTPS access to block page
		),
		'log' => 'yes',  // Enable logging
		'created' => array(
			'time' => time(),
			'username' => 'parental_control'
		)
	);
	
	// Add the allow rule first
	$rules = config_get_path('filter/rule', []);
	$rules[] = $block_page_rule;
	config_set_path('filter/rule', $rules);
	
	// Now create the block rule
	$rule = array(
		'type' => 'reject', // Use 'reject' instead of 'block' to send TCP RST
		'interface' => 'lan',
		'ipprotocol' => 'inet',  // IPv4 + IPv6
		'descr' => "Parental Control: {$child_name} - {$device_name} ({$reason})",
		'source' => array('address' => $mac),
		'destination' => array('any' => ''),
		'log' => 'yes',  // Enable logging
		'created' => array(
			'time' => time(),
			'username' => 'parental_control'
		)
	);
	
	// WHY: Adjust rule based on enforcement mode - provides flexible control levels
	// Design Decision: Three-tier enforcement system instead of binary on/off
	// Rationale:
	// - STRICT: Total block - used when absolutely necessary (bedtime, limit exceeded)
	// - MODERATE: Block web browsing only - allows email, messaging, homework apps
	// - SOFT: Block DNS only - tech-savvy kids can bypass but casual users blocked
	// Trade-off: Complexity vs flexibility. Parents appreciate graduated enforcement options.
	switch ($enforcement_mode) {
		case 'moderate':
			// WHY: Block HTTP/HTTPS (ports 80, 443) but allow other protocols
			// Use case: Block web surfing but allow Zoom, email, Minecraft, etc.
			$rule['protocol'] = 'tcp';
			$rule['destination']['port'] = 'KACI_PC_Web';  // Use port alias for HTTP (80) and HTTPS (443)
			break;
			
		case 'soft':
			// WHY: Block DNS only - prevents casual browsing, easily bypassed
			// Use case: "Gentle nudge" mode - reminder to child without hard enforcement
			// Trade-off: Not secure against tech-savvy kids who can use 8.8.8.8
			$rule['protocol'] = 'udp';
			$rule['destination']['port'] = '53';
			break;
			
		case 'strict':
		default:
			// WHY: Block ALL traffic - most secure, no loopholes
			// Use case: Bedtime, punishment, or when time limit exceeded
			// Rule covers all protocols by not specifying any
			break;
	}
	
	// Add rule to config
	if (!is_array(config_get_path('filter/rule'))) {
		config_set_path('filter/rule', array());
	}
	
	$rules = config_get_path('filter/rule');
	$rules[] = $rule;
	config_set_path('filter/rule', $rules);
	
	// Log with structured attributes for SIEM/log analysis
	pc_log("Created block rule for {$child_name} - {$device_name}: {$reason}", 'info', array(
		'event.action' => 'block_rule_created',
		'event.category' => 'firewall',
		'event.type' => 'access',
		'child.name' => $child_name,
		'device.name' => $device_name,
		'device.mac' => $mac,
		'device.ip' => isset($device['ip_address']) ? $device['ip_address'] : null,
		'rule.reason' => $reason,
		'rule.enforcement_mode' => $enforcement_mode,
		'rule.interface' => 'lan',
		'rule.action' => 'block'
	));
}

/**
 * Remove all parental control firewall rules
 * 
 * Scans all firewall rules and removes any with description starting with
 * "Parental Control:". This is called before creating new rules to ensure
 * clean state, and during uninstallation. Logs count of rules removed.
 * 
 * @return void Rules are removed from global $config
 * @since 0.1.0
 * @updated 0.1.3 Added count logging
 * 
 * @example
 * // Remove all existing rules before re-creating
 * pc_remove_firewall_rules();
 * pc_update_firewall_rules();
 * 
 * @see pc_create_block_rule() For rule creation
 */
function pc_remove_firewall_rules() {
	global $config;
	
	$rules = config_get_path('filter/rule', []);
	$new_rules = array();
	$removed_count = 0;
	
	foreach ($rules as $rule) {
		// Match both "Parental Control:" (colon) and "Parental Control -" (dash) patterns
		if (isset($rule['descr']) && 
		    (strpos($rule['descr'], 'Parental Control:') === 0 || 
		     strpos($rule['descr'], 'Parental Control -') === 0)) {
			$removed_count++;
			continue;
		}
		$new_rules[] = $rule;
	}
	
	if ($removed_count > 0) {
		config_set_path('filter/rule', $new_rules);
		pc_log("Removed {$removed_count} parental control firewall rules", 'info');
	}
}

/**
 * Setup cron job for periodic checks and usage tracking
 * 
 * Installs a cron job that runs parental_control_cron.php periodically
 * (default every minute, configurable via PC_CRON_MINUTE). Creates the
 * cron script file and sets proper permissions. The cron job handles
 * usage tracking, counter resets, and firewall rule updates.
 * 
 * Cron job runs as root user to ensure firewall access. Uses pfSense's
 * install_cron_job() function which automatically handles duplicate prevention.
 * 
 * @return void Cron job is registered in system crontab and script file created
 * @since 0.1.0
 * @updated 0.1.3 Made cron interval configurable via PC_CRON_MINUTE constant
 * 
 * @example
 * // Called automatically during installation and configuration sync
 * pc_setup_cron_job();
 * 
 * @see pc_remove_cron_job() For cron job removal
 * @see parental_control_cron_job() For the actual cron handler
 */
function pc_setup_cron_job() {
	// Create cron script first
	$cron_script = <<<'EOD'
#!/usr/local/bin/php-cgi -f
<?php
require_once("/etc/inc/config.inc");
require_once("/usr/local/pkg/parental_control.inc");
parental_control_cron_job();
?>
EOD;
	
	file_put_contents('/usr/local/bin/parental_control_cron.php', $cron_script);
	chmod('/usr/local/bin/parental_control_cron.php', 0755);
	
	// Try pfSense's install_cron_job() first
	try {
		install_cron_job(
			"/usr/local/bin/php /usr/local/bin/parental_control_cron.php",
			true,
			PC_CRON_MINUTE,
			"*",
			"*",
			"*",
			"*",
			"root",
			false
		);
		
		// Verify it was actually installed
		$output = array();
		exec("crontab -l 2>&1 | grep -c 'parental_control'", $output, $ret);
		$count = isset($output[0]) ? intval($output[0]) : 0;
		
		if ($count > 0) {
			pc_log("Cron job installed successfully via install_cron_job()", 'info');
			return;
		}
	} catch (Exception $e) {
		pc_log("install_cron_job() failed: " . $e->getMessage(), 'warning');
	}
	
	// Fallback: Direct crontab manipulation (more reliable)
	pc_log("Using direct crontab manipulation as fallback", 'info');
	
	$cron_entry = PC_CRON_MINUTE . " * * * * /usr/local/bin/php /usr/local/bin/parental_control_cron.php";
	
	// Get current crontab
	$current_crontab = array();
	exec("crontab -l 2>/dev/null", $current_crontab, $ret);
	
	// Remove any existing parental control cron entries (to update schedule if needed)
	$filtered_crontab = array();
	$removed_count = 0;
	foreach ($current_crontab as $line) {
		if (strpos($line, 'parental_control_cron.php') !== false) {
			$removed_count++;
			pc_log("Removing old cron entry: $line", 'debug');
		} else {
			$filtered_crontab[] = $line;
		}
	}
	
	if ($removed_count > 0) {
		pc_log("Removed $removed_count old cron entry/entries", 'info');
	}
	
	// Add our cron entry with current schedule
	$filtered_crontab[] = $cron_entry;
	
	// Write new crontab
	$temp_crontab = tempnam('/tmp', 'crontab_');
	file_put_contents($temp_crontab, implode("\n", $filtered_crontab) . "\n");
	
	exec("crontab $temp_crontab 2>&1", $output, $ret);
	unlink($temp_crontab);
	
	if ($ret === 0) {
		pc_log("Cron job installed/updated successfully: $cron_entry", 'info');
	} else {
		pc_log("Failed to install cron job: " . implode("\n", $output), 'error');
	}
}

/**
 * Remove parental control cron job
 * 
 * Uninstalls the periodic cron job by calling install_cron_job with false
 * parameter and deletes the cron script file. This is called during package
 * uninstallation or when service is disabled. Prevents orphaned cron jobs
 * from continuing to run and cleans up script files.
 * 
 * @return void Cron job is removed from system crontab and script deleted
 * @since 0.1.0
 * @updated 0.1.3 Also removes cron script file
 * 
 * @example
 * // Called automatically during uninstallation
 * pc_remove_cron_job();
 * 
 * @see pc_setup_cron_job() For cron job installation
 */
function pc_remove_cron_job() {
	install_cron_job("/usr/local/bin/php /usr/local/bin/parental_control_cron.php", false);
	@unlink('/usr/local/bin/parental_control_cron.php');
	pc_log("Cron job removed", 'debug');
}

/**
 * Initialize pfSense anchor for smart blocking
 * 
 * Creates a pfSense anchor named 'parental_control' where we can dynamically
 * add/remove block rules without calling filter_configure(). Anchors are
 * persistent and efficient - perfect for dynamic blocking.
 * 
 * Also removes any invalid table-based rules from earlier versions.
 * 
 * @return void
 * @since 0.7.0
 * @updated 0.7.6 Removes invalid table-based rules
 */
function pc_init_block_table() {
	// Initialize the pfSense table-based blocking system (v1.1.8+)
	// Creates/manages the alias and floating rule for dynamic IP blocking
	global $config;
	
	$alias_name = 'parental_control_blocked';
	$rule_descr = 'Parental Control: Dynamic Blocking Rule';
	$needs_filter_reload = false;
	
	// ===== 1. Ensure the alias exists =====
	if (!isset($config['aliases'])) {
		$config['aliases'] = array();
	}
	if (!isset($config['aliases']['alias'])) {
		$config['aliases']['alias'] = array();
	}
	
	$alias_exists = false;
	foreach ($config['aliases']['alias'] as $idx => $alias) {
		if ($alias['name'] === $alias_name) {
			$alias_exists = true;
			// Ensure it's a host alias
			if ($alias['type'] !== 'host') {
				pc_log("Correcting alias '{$alias_name}' type to host", 'info');
				$config['aliases']['alias'][$idx]['type'] = 'host';
				$needs_filter_reload = true;
			}
			break;
		}
	}
	
	if (!$alias_exists) {
		pc_log("Creating blocking alias for dynamic IP management", 'info', array(
			'event.action' => 'alias_created',
			'alias.name' => $alias_name,
			'alias.type' => 'host'
		));
		$new_alias = array(
			'name' => $alias_name,
			'type' => 'host',
			'address' => '',  // Start empty
			'descr' => 'Dynamically managed IPs for Parental Control blocking',
			'detail' => 'DO NOT EDIT - Managed automatically by Parental Control package'
		);
		$config['aliases']['alias'][] = $new_alias;
		$needs_filter_reload = true;
	}
	
	// ===== 2. Ensure the floating rule exists =====
	if (!isset($config['filter'])) {
		$config['filter'] = array();
	}
	if (!isset($config['filter']['rule'])) {
		$config['filter']['rule'] = array();
	}
	
	$rule_exists = false;
	foreach ($config['filter']['rule'] as $idx => $rule) {
		if (isset($rule['descr']) && $rule['descr'] === $rule_descr) {
			$rule_exists = true;
			// Verify it's correctly configured
			if (!isset($rule['floating']) || $rule['floating'] !== 'yes' ||
			    !isset($rule['type']) || $rule['type'] !== 'block' ||
			    !isset($rule['source']['address']) || $rule['source']['address'] !== $alias_name) {
				
				pc_log("Correcting existing blocking rule configuration", 'warning');
				$config['filter']['rule'][$idx] = pc_generate_blocking_rule($alias_name, $rule_descr);
				$needs_filter_reload = true;
			}
			break;
		}
	}
	
	if (!$rule_exists) {
		pc_log("Creating floating blocking rule using alias", 'info', array(
			'event.action' => 'rule_created',
			'rule.type' => 'floating',
			'rule.action' => 'block',
			'rule.source' => $alias_name,
			'note' => 'Rule visible in GUI - Firewall → Rules → Floating'
		));
		$config['filter']['rule'][] = pc_generate_blocking_rule($alias_name, $rule_descr);
		$needs_filter_reload = true;
	}
	
	// ===== 3. Clean up old anchor-based files =====
	$anchor_file = '/tmp/rules.parental_control';
	if (file_exists($anchor_file)) {
		unlink($anchor_file);
		pc_log("Removed legacy anchor file", 'info', array(
			'event.action' => 'cleanup',
			'file' => $anchor_file
		));
	}
	
	// ===== 4. Save config and reload if needed =====
	// Note: We DON'T call write_config here to avoid conflicts when called
	// from profile/schedule saves. The caller should call write_config.
	// We only trigger filter_configure if we made changes that need it.
	if ($needs_filter_reload) {
		pc_log("Blocking system configuration updated (reload needed)", 'info', array(
			'event.action' => 'config_updated',
			'note' => 'Caller should save config and reload filter'
		));
	}
	
	return $needs_filter_reload;
}

/**
 * Generate a pfSense floating rule configuration for blocking IPs in the alias
 *
 * @param string $alias_name The name of the alias/table containing blocked IPs
 * @param string $rule_descr Description for the rule
 * @return array Rule configuration array suitable for config.xml
 * @since 1.1.8
 */
function pc_generate_blocking_rule($alias_name, $rule_descr) {
	return array(
		'type' => 'block',
		'interface' => 'LAN_Interfaces',  // Apply to all LAN interfaces
		'ipprotocol' => 'inet',  // IPv4 + IPv6
		'descr' => $rule_descr,
		'source' => array(
			'address' => $alias_name  // Reference the alias
		),
		'destination' => array(
			'any' => ''
		),
		'floating' => 'yes',
		'quick' => 'yes',  // Stop processing on match
		'direction' => 'any',
		'log' => 'yes',  // Enable logging for debugging
		'created' => array(
			'time' => time(),
			'username' => 'parental_control@package'
		)
	);
}

/**
 * Initialize service-specific blocking tables/aliases
 * 
 * Creates one alias per monitored service for granular per-service blocking.
 * When a profile's combined usage for a service exceeds its limit, all devices
 * in that profile are added to the service-specific block table.
 * 
 * This enables blocking access to specific services (e.g., YouTube) while
 * allowing access to other services and general internet.
 * 
 * @return bool True if initialization successful, false on error
 * @since 1.4.31
 */
function pc_init_service_block_tables() {
	global $config;
	
	// Get all PC_Service_ aliases
	$service_aliases = array();
	$aliases = config_get_path('aliases/alias', array());
	
	if (is_array($aliases)) {
		foreach ($aliases as $alias) {
			if (isset($alias['name']) && strpos($alias['name'], 'PC_Service_') === 0) {
				$service_name = str_replace('PC_Service_', '', $alias['name']);
				$service_aliases[] = strtolower($service_name);
			}
		}
	}
	
	if (empty($service_aliases)) {
		pc_log("No service aliases found, skipping service block table creation", 'debug');
		return true;
	}
	
	$needs_reload = false;
	
	// Ensure aliases structure exists
	if (!isset($config['aliases'])) {
		$config['aliases'] = array();
	}
	if (!isset($config['aliases']['alias'])) {
		$config['aliases']['alias'] = array();
	}
	
	// Create one blocking alias per service
	// CRITICAL: pfSense table names limited to 31 characters (FreeBSD pf limitation)
	// Use shortened prefix: pc_blocked_ instead of parental_control_blocked_
	foreach ($service_aliases as $service_name) {
		$alias_name = 'pc_blocked_' . $service_name;
		$alias_exists = false;
		
		// Check if alias already exists
		foreach ($config['aliases']['alias'] as $idx => $alias) {
			if ($alias['name'] === $alias_name) {
				$alias_exists = true;
				// Ensure it's a host alias
				if ($alias['type'] !== 'host') {
					pc_log("Correcting alias '{$alias_name}' type to host", 'info');
					$config['aliases']['alias'][$idx]['type'] = 'host';
					$needs_reload = true;
				}
				break;
			}
		}
		
		if (!$alias_exists) {
			pc_log("Creating service blocking alias: {$alias_name}", 'info', array(
				'event.action' => 'service_alias_created',
				'alias.name' => $alias_name,
				'alias.type' => 'host',
				'service' => $service_name
			));
			
			$new_alias = array(
				'name' => $alias_name,
				'type' => 'host',
				'address' => '',  // Start empty
				'descr' => "Dynamically managed IPs blocked from {$service_name} service",
				'detail' => 'DO NOT EDIT - Managed automatically by Parental Control package'
			);
			$config['aliases']['alias'][] = $new_alias;
			$needs_reload = true;
		}
	}
	
	if ($needs_reload) {
		pc_log("Service blocking tables initialized", 'info', array(
			'event.action' => 'service_tables_initialized',
			'services.count' => count($service_aliases),
			'services' => implode(', ', $service_aliases)
		));
		mark_subsystem_dirty('aliases');
	}
	
	return true;
}

/**
 * Delete service alias and associated blocking table
 * 
 * Removes both the PC_Service_<name> alias and the pc_blocked_<name> alias
 * from the pfSense configuration. This should be called when a service is
 * deleted to clean up firewall aliases.
 * 
 * @param string $service_name Service name (e.g., "TikTok", "Facebook")
 * @return bool True if successful
 */
function pc_delete_service_alias($service_name) {
	global $config;
	
	$alias_name = 'PC_Service_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $service_name);
	$block_alias_name = 'pc_blocked_' . strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $service_name));
	
	$aliases = config_get_path('aliases/alias', array());
	if (!is_array($aliases)) {
		pc_log("No aliases found, nothing to delete for: {$service_name}", 'debug');
		return false;
	}
	
	$deleted_count = 0;
	
	// Remove both aliases
	foreach ($aliases as $idx => $alias) {
		if ($alias['name'] === $alias_name || $alias['name'] === $block_alias_name) {
			unset($aliases[$idx]);
			$deleted_count++;
			pc_log("Deleted alias: {$alias['name']}", 'info', array(
				'event.action' => 'service_alias_deleted',
				'alias.name' => $alias['name'],
				'service' => $service_name
			));
		}
	}
	
	if ($deleted_count > 0) {
		// Reindex array
		$aliases = array_values($aliases);
		config_set_path('aliases/alias', $aliases);
		
		// NOTE: Do NOT write_config() here - let caller do it in single transaction
		// This prevents dual-write corruption issues
		
		mark_subsystem_dirty('aliases');
		
		pc_log("Deleted {$deleted_count} alias(es) for service: {$service_name}", 'info', array(
			'event.action' => 'service_aliases_deleted',
			'service' => $service_name,
			'deleted_count' => $deleted_count
		));
		
		return true;
	}
	
	pc_log("No aliases found to delete for service: {$service_name}", 'debug');
	return false;
}

/**
 * Inject anchor reference into live pfSense firewall configuration
 * 
 * CRITICAL WORKAROUND: Since pfSense's filter generation hooks aren't being called
 * reliably, we manually inject our anchor reference into the running pf configuration
 * using pfctl. This ensures our anchor is evaluated even if filter_configure() was
 * called without our hook being triggered.
 * 
 * WHY: pfSense rules are evaluated top-to-bottom. Without our anchor in the main
 * ruleset, our blocking rules are never checked. This function uses pfctl's ability
 * to modify the running configuration without full reload.
 * 
 * METHOD: Uses `pfctl -a` to check if anchor exists in main ruleset, and if not,
 * loads a minimal config that includes our anchor reference.
 * 
 * @return void Anchor reference is added to running config
 * @since 1.1.7
 */
function pc_inject_anchor_reference() {
	// Check if our anchor is already in the main ruleset
	exec('/sbin/pfctl -sr 2>&1 | grep -c "anchor.*parental_control"', $output, $return_code);
	$anchor_exists = isset($output[0]) && intval($output[0]) > 0;
	
	if ($anchor_exists) {
		// Already exists, nothing to do
		return;
	}
	
	// Anchor doesn't exist in main ruleset - inject it
	// Create a temporary pf config snippet with just our anchor
	$temp_config = "/tmp/pf_anchor_inject.conf";
	$anchor_rule = "# Parental Control\nanchor \"parental_control\" all\n";
	file_put_contents($temp_config, $anchor_rule);
	
	// Load this as an additional ruleset (doesn't replace existing rules)
	// Note: This is a workaround. Ideally pfSense would call our generation hook.
	exec("/sbin/pfctl -f {$temp_config} 2>&1", $output2, $return_code2);
	
	if ($return_code2 === 0) {
		pc_log("Injected anchor reference into running firewall config", 'info', array(
			'event.action' => 'anchor_reference_injected',
			'method' => 'pfctl_direct',
			'anchor.name' => 'parental_control'
		));
	} else {
		pc_log("Failed to inject anchor reference: " . implode("\n", $output2), 'warning');
	}
	
	@unlink($temp_config);
}

/**
 * pfSense filter generation hook - called automatically during filter_configure()
 * 
 * CRITICAL: This function is called by pfSense's filter generation system to inject
 * custom rules into the firewall configuration. By implementing this function, our
 * anchor reference is automatically included every time pfSense regenerates its rules.
 * 
 * WHY: pfSense calls `{packagename}_generate_rules()` during filter generation,
 * allowing packages to inject custom pf rules. This is the PROPER way to add anchors.
 * 
 * Returns pf syntax that will be included in the main firewall configuration BEFORE
 * interface-specific rules, ensuring our anchor is evaluated early.
 * 
 * @return string PF rules to inject (anchor reference)
 * @since 1.1.7
 */
function parental_control_generate_rules($type) {
	// Only inject on filter reload (not other types like NAT)
	if ($type != 'filter') {
		return '';
	}
	
	$rules = '';
	
	// Check if service is enabled
	$enabled = config_get_path('installedpackages/parentalcontrol/config/0/enable') === 'on';
	if (!$enabled) {
		return '';
	}
	
	// Inject our anchor reference
	// This will be evaluated BEFORE interface rules
	$rules .= "# Parental Control Dynamic Blocking\n";
	$rules .= "anchor \"parental_control\" all\n";
	$rules .= "\n";
	
	pc_log("Filter generation hook called - anchor reference injected", 'debug', array(
		'event.action' => 'filter_rules_generated',
		'rules.type' => $type,
		'anchor.name' => 'parental_control'
	));
	
	return $rules;
}

/**
 * Create pfSense alias (table) for blocked devices
 * 
 * CRITICAL: This is the CORRECT way to do dynamic blocking in pfSense
 * WHY: Tables/aliases are native pfSense features that integrate properly with
 * the firewall rule system. Unlike anchors, rules using aliases ARE properly
 * evaluated in the correct order.
 * 
 * Creates an alias named "parental_control_blocked" that contains IP addresses
 * of devices that should be blocked. This alias is then used by a floating rule.
 * 
 * @return bool True if alias exists or was created successfully
 * @since 1.1.8
 */
function pc_create_blocking_alias() {
	// Check if alias already exists
	$aliases = config_get_path('aliases/alias', []);
	$alias_exists = false;
	$alias_index = -1;
	
	foreach ($aliases as $index => $alias) {
		if (isset($alias['name']) && $alias['name'] === 'parental_control_blocked') {
			$alias_exists = true;
			$alias_index = $index;
			break;
		}
	}
	
	if ($alias_exists) {
		pc_log("Blocking alias already exists", 'debug');
		return true;
	}
	
	// Create new alias
	$new_alias = array(
		'name' => 'parental_control_blocked',
		'type' => 'host',
		'address' => '',  // Initially empty
		'descr' => 'Parental Control - Blocked Devices (Auto-managed - DO NOT EDIT)',
		'detail' => 'This alias is automatically managed by Parental Control package||'
	);
	
	$aliases[] = $new_alias;
	config_set_path('aliases/alias', $aliases);
	
	write_config('Parental Control: Created blocking alias (table)');
	
	pc_log("Created blocking alias for dynamic IP management", 'info', array(
		'event.action' => 'alias_created',
		'alias.name' => 'parental_control_blocked',
		'alias.type' => 'host'
	));
	
	// Mark that filter needs reload
	mark_subsystem_dirty('aliases');
	
	return true;
}

/**
 * Create monitoring alias for parental control
 * 
 * Creates a host alias containing ALL profile device IPs for monitoring purposes.
 * This alias is used to track usage of all monitored devices across all services.
 * 
 * @return bool True if alias exists or was created successfully
 * @since 1.4.8
 */
function pc_create_monitoring_alias() {
	// Check if alias already exists
	$aliases = config_get_path('aliases/alias', []);
	$alias_exists = false;
	$alias_index = -1;
	
	foreach ($aliases as $index => $alias) {
		if (isset($alias['name']) && $alias['name'] === 'parental_control_monitor') {
			$alias_exists = true;
			$alias_index = $index;
			break;
		}
	}
	
	if ($alias_exists) {
		pc_log("Monitoring alias already exists", 'debug');
		return true;
	}
	
	// Create new alias
	$new_alias = array(
		'name' => 'parental_control_monitor',
		'type' => 'host',
		'address' => '',  // Initially empty, populated by cron
		'descr' => 'Parental Control - All Monitored Devices (Auto-managed - DO NOT EDIT)',
		'detail' => 'This alias is automatically managed by Parental Control package||'
	);
	
	$aliases[] = $new_alias;
	config_set_path('aliases/alias', $aliases);
	
	write_config('Parental Control: Created monitoring alias');
	
	pc_log("Created monitoring alias for usage tracking", 'info', array(
		'event.action' => 'alias_created',
		'alias.name' => 'parental_control_monitor',
		'alias.type' => 'host'
	));
	
	// Mark that filter needs reload
	mark_subsystem_dirty('aliases');
	
	return true;
}

/**
 * Update monitoring alias with all profile device IPs
 * 
 * Populates the parental_control_monitor alias with IPs of ALL devices
 * configured in any profile. This enables usage tracking for all monitored devices.
 * 
 * @param array $state Current state with MAC-to-IP mapping
 * @return bool True if alias was updated successfully
 * @since 1.4.8
 */
function pc_update_monitor_table($state) {
	// Get all profile device MACs
	$all_devices = pc_get_all_profile_devices();
	
	if (empty($all_devices)) {
		pc_log("No profile devices configured for monitoring", 'debug');
		return false;
	}
	
	// Get IPs for online devices
	$monitor_ips = array();
	foreach ($all_devices as $mac) {
		if (isset($state['mac_to_ip_cache'][$mac])) {
			$ip = $state['mac_to_ip_cache'][$mac];
			$monitor_ips[] = $ip;
		}
	}
	
	if (empty($monitor_ips)) {
		pc_log("No profile devices currently online for monitoring", 'debug');
		return false;
	}
	
	// Get current IPs in the monitoring table
	exec("/sbin/pfctl -t parental_control_monitor -T show 2>&1", $current_ips, $ret);
	if ($ret !== 0) {
		// Table doesn't exist yet, that's okay
		$current_ips = array();
	}
	
	$current_ips = array_map('trim', $current_ips);
	$current_ips = array_filter($current_ips);
	
	// Calculate changes needed
	$to_add = array_diff($monitor_ips, $current_ips);
	$to_remove = array_diff($current_ips, $monitor_ips);
	
	$changes = 0;
	
	// Add new IPs
	foreach ($to_add as $ip) {
		exec("/sbin/pfctl -t parental_control_monitor -T add {$ip} 2>&1", $output, $ret);
		if ($ret === 0) {
			$changes++;
			pc_log("Added {$ip} to monitoring table", 'debug');
		}
	}
	
	// Remove stale IPs
	foreach ($to_remove as $ip) {
		exec("/sbin/pfctl -t parental_control_monitor -T delete {$ip} 2>&1", $output, $ret);
		if ($ret === 0) {
			$changes++;
			pc_log("Removed {$ip} from monitoring table", 'debug');
		}
	}
	
	if ($changes > 0) {
		pc_log("Updated monitoring table: +" . count($to_add) . " -" . count($to_remove) . " devices", 'info', array(
			'event.action' => 'monitor_table_updated',
			'devices.added' => count($to_add),
			'devices.removed' => count($to_remove),
			'devices.total' => count($monitor_ips)
		));
	}
	
	return true;
}

/**
 * Create port aliases for parental control rules
 * 
 * Creates port aliases required by pfSense firewall rules. pfSense cannot use 
 * comma-separated ports directly in rules - they must reference a port alias.
 * 
 * Creates two aliases:
 * 1. KACI_PC_Ports: 80, 443, 1008 (HTTP, HTTPS, Captive Portal)
 * 2. KACI_PC_Web: 80, 443 (HTTP, HTTPS for moderate enforcement mode)
 * 
 * @return bool True if aliases exist or were created successfully
 * @since 1.1.12
 */
function pc_create_port_aliases() {
	global $config;
	
	// Ensure aliases structure exists
	if (!isset($config['aliases'])) {
		$config['aliases'] = array();
	}
	if (!isset($config['aliases']['alias'])) {
		$config['aliases']['alias'] = array();
	}
	
	$aliases_to_create = array(
		array(
			'name' => 'KACI_PC_Ports',
			'ports' => '80 443 1008',
			'descr' => 'Parental Control - pfSense Access Ports (HTTP, HTTPS, Captive Portal)',
			'detail' => 'HTTP||HTTPS||Captive Portal||'
		),
		array(
			'name' => 'KACI_PC_Web',
			'ports' => '80 443',
			'descr' => 'Parental Control - Web Ports (HTTP, HTTPS)',
			'detail' => 'HTTP||HTTPS||'
		)
	);
	
	$needs_write = false;
	
	foreach ($aliases_to_create as $alias_def) {
		$alias_name = $alias_def['name'];
		$ports = $alias_def['ports'];
		
		// Check if alias already exists
		$alias_exists = false;
		$alias_index = -1;
		
		foreach ($config['aliases']['alias'] as $index => $alias) {
			if (isset($alias['name']) && $alias['name'] === $alias_name) {
				$alias_exists = true;
				$alias_index = $index;
				break;
			}
		}
		
		if ($alias_exists) {
			// Verify the alias has correct ports and type
			$current_ports = isset($config['aliases']['alias'][$alias_index]['address']) ? 
				$config['aliases']['alias'][$alias_index]['address'] : '';
			$current_type = isset($config['aliases']['alias'][$alias_index]['type']) ? 
				$config['aliases']['alias'][$alias_index]['type'] : '';
			
			if ($current_ports !== $ports || $current_type !== 'port') {
				pc_log("Updating port alias '{$alias_name}' with correct configuration", 'info');
				$config['aliases']['alias'][$alias_index]['type'] = 'port';
				$config['aliases']['alias'][$alias_index]['address'] = $ports;
				$config['aliases']['alias'][$alias_index]['descr'] = $alias_def['descr'];
				$config['aliases']['alias'][$alias_index]['detail'] = $alias_def['detail'];
				$needs_write = true;
			} else {
				pc_log("Port alias '{$alias_name}' already exists with correct configuration", 'debug');
			}
		} else {
			// Create new port alias
			$new_alias = array(
				'name' => $alias_name,
				'type' => 'port',
				'address' => $ports,
				'descr' => $alias_def['descr'],
				'detail' => $alias_def['detail']
			);
			
			$config['aliases']['alias'][] = $new_alias;
			$needs_write = true;
			
			pc_log("Created port alias '{$alias_name}'", 'info', array(
				'event.action' => 'alias_created',
				'alias.name' => $alias_name,
				'alias.type' => 'port',
				'alias.ports' => $ports
			));
		}
	}
	
	if ($needs_write) {
		write_config('Parental Control: Created/updated port aliases');
		mark_subsystem_dirty('aliases');
	}
	
	return true;
}

/**
 * Create floating firewall rule that blocks traffic from parental control alias
 * 
 * CRITICAL: This rule will be in pfSense's main configuration and properly ordered
 * WHY: Floating rules are evaluated BEFORE interface-specific rules, ensuring our
 * blocking happens before any LAN allow rules.
 * 
 * Creates a floating rule that:
 * - Blocks traffic from IPs in parental_control_blocked alias
 * - Applies to LAN interface
 * - Direction: IN (incoming from devices)
 * - Action: BLOCK (drop packets silently)
 * - Quick: YES (stop processing on match)
 * 
 * @return bool True if rule exists or was created successfully
 * @since 1.1.8
 */
function pc_create_blocking_rule() {
	// Check if rule already exists
	$rules = config_get_path('filter/rule', []);
	$rule_exists = false;
	
	foreach ($rules as $rule) {
		if (isset($rule['descr']) && $rule['descr'] === 'Parental Control - Dynamic Blocking') {
			$rule_exists = true;
			break;
		}
	}
	
	if ($rule_exists) {
		pc_log("Blocking rule already exists", 'debug');
		return true;
	}
	
	// Create floating rule that blocks traffic from our alias
	$new_rule = array(
		'type' => 'block',
		'interface' => 'lan',  // Apply to LAN interface
		'ipprotocol' => 'inet',  // IPv4 + IPv6
		'direction' => 'any',  // Both directions - simpler and more reliable
		'floating' => 'yes',
		'quick' => 'yes',  // Apply immediately on match
		'source' => array(
			'address' => 'parental_control_blocked'  // Use our alias
		),
		'destination' => array(
			'any' => ''
		),
		'descr' => 'Parental Control - Dynamic Blocking',
		'log' => 'yes',  // Enable logging
		'created' => array(
			'time' => time(),
			'username' => 'system@parentalcontrol'
		)
	);
	
	// Add at beginning of ruleset (highest priority)
	array_unshift($rules, $new_rule);
	config_set_path('filter/rule', $rules);
	
	write_config('Parental Control: Added blocking rule (table-based)');
	
	pc_log("Created blocking rule using alias", 'info', array(
		'event.action' => 'rule_created',
		'rule.type' => 'floating',
		'rule.action' => 'block',
		'rule.source' => 'parental_control_blocked',
		'note' => 'Rule visible in GUI - Firewall → Rules → Floating'
	));
	
	// Mark that filter needs reload
	mark_subsystem_dirty('filter');
	
	return true;
}

/**
 * Create service monitoring rules for online services
 * 
 * Creates pass rules for each PC_Service_ alias to allow traffic from
 * profile devices to monitored services. These rules are positioned
 * just before the dynamic blocking rule to enable per-service tracking.
 * 
 * @return bool True if rules were created successfully
 * @since 1.4.7
 */
function pc_create_service_monitoring_rules() {
	global $config;
	
	// Get all PC_Service_ aliases
	$service_aliases = array();
	$aliases = config_get_path('aliases/alias', array());
	
	if (is_array($aliases)) {
		foreach ($aliases as $alias) {
			if (isset($alias['name']) && strpos($alias['name'], 'PC_Service_') === 0) {
				$service_aliases[] = $alias['name'];
			}
		}
	}
	
	if (empty($service_aliases)) {
		pc_log("No service aliases found for monitoring", 'debug');
		return true;
	}
	
	// NOTE: We create rules even if no devices are configured yet
	// The rules use the parental_control_blocked alias which will be empty until devices are added
	// This allows the rules to be in place and ready when devices are added later
	
	// Get current rules
	$rules = config_get_path('filter/rule', []);
	$needs_update = false;
	
	// Find the position of the dynamic blocking rule
	$blocking_rule_index = null;
	foreach ($rules as $idx => $rule) {
		if (isset($rule['descr']) && $rule['descr'] === 'Parental Control - Dynamic Blocking') {
			$blocking_rule_index = $idx;
			break;
		}
	}
	
	if ($blocking_rule_index === null) {
		pc_log("Dynamic blocking rule not found, cannot position service monitoring rules", 'warning');
		return false;
	}
	
	// Remove any existing service-specific rules
	$rules = array_filter($rules, function($rule) {
		// Keep rule if it's NOT a service monitoring or blocking rule
		return !(isset($rule['descr']) && 
			(strpos($rule['descr'], 'Parental Control - Service Monitor:') === 0 ||
			 strpos($rule['descr'], 'Parental Control - Service Block:') === 0 ||
			 preg_match('/^Parental Control - .+ (Dynamic Blocking|Service Monitor|Service Block)$/', $rule['descr'])));
	});
	$rules = array_values($rules); // Re-index array
	
	// Re-find blocking rule index after removal
	$blocking_rule_index = null;
	foreach ($rules as $idx => $rule) {
		if (isset($rule['descr']) && $rule['descr'] === 'Parental Control - Dynamic Blocking') {
			$blocking_rule_index = $idx;
			break;
		}
	}
	
	// Create new service monitoring AND blocking rules
	// For each service, we create TWO rules:
	// 1. BLOCK rule: blocks devices in parental_control_blocked from accessing service
	// 2. MONITOR rule: allows/tracks devices in parental_control_monitor accessing service
	// 
	// Rule sequence (evaluated top to bottom):
	// - DNS/pfSense access (allow blocked devices to see captive portal)
	// - Service BLOCK rules (block violators from specific services)  ← These rules
	// - Service MONITOR rules (allow/track everyone else)             ← These rules
	// - General BLOCK rule (catch-all for blocked devices)
	
	$new_rules = array();
	foreach ($service_aliases as $alias_name) {
		$service_name = str_replace('PC_Service_', '', $alias_name);
		$service_name_lower = strtolower($service_name);
		// CRITICAL: Use shortened prefix to stay within 31 char limit
		$service_block_alias = 'pc_blocked_' . $service_name_lower;
		
		// RULE 1: BLOCK rule for devices that exceeded service-specific limits
		// v1.4.31: Now uses per-service block tables instead of general block table
		// This allows blocking specific services while allowing others
		$block_rule = array(
			'type' => 'block',
			'interface' => 'lan',
			'ipprotocol' => 'inet',  // IPv4 + IPv6
			'direction' => 'any',  // Both directions - simpler and more reliable
			'floating' => 'yes',
			'quick' => 'yes',
			'source' => array(
				'address' => $service_block_alias  // Service-specific block table
			),
			'destination' => array(
				'address' => $alias_name  // The service alias
			),
			'descr' => "Parental Control - {$service_name} Service Block",
			'created' => array(
				'time' => time(),
				'username' => 'system@parentalcontrol'
			),
			'log' => 'yes'  // Enable logging for enforcement tracking
		);
		
		// RULE 2: MONITOR rule for all profile devices (usage tracking)
		$monitor_rule = array(
			'type' => 'pass',
			'interface' => 'lan',
			'ipprotocol' => 'inet',  // IPv4 + IPv6
			'direction' => 'any',  // Both directions - simpler and more reliable
			'floating' => 'yes',
			'quick' => 'yes',
			'source' => array(
				'address' => 'parental_control_monitor'  // ALL profile devices
			),
			'destination' => array(
				'address' => $alias_name  // The service alias
			),
			'descr' => "Parental Control - {$service_name} Service Monitor",
			'created' => array(
				'time' => time(),
				'username' => 'system@parentalcontrol'
			),
			'log' => 'yes'  // Enable logging for usage tracking
		);
		
		// Add block rule first, then monitor rule (order matters!)
		$new_rules[] = $block_rule;
		$new_rules[] = $monitor_rule;
	}
	
	// Insert new rules just before the blocking rule
	array_splice($rules, $blocking_rule_index, 0, $new_rules);
	
	config_set_path('filter/rule', $rules);
	
	// BUGFIX v1.4.42: Do NOT write_config() here - let caller handle it
	// PROBLEM: Dual-write with delete_service causes rules to persist
	// SOLUTION: Caller writes config after all changes prepared
	// write_config('Parental Control: Updated service monitoring rules');
	
	pc_log("Prepared " . count($new_rules) . " service monitoring rules (config not written yet)", 'info', array(
		'event.action' => 'service_rules_prepared',
		'rule.count' => count($new_rules),
		'services' => implode(', ', array_map(function($a) { return str_replace('PC_Service_', '', $a); }, $service_aliases))
	));
	
	// CRITICAL: Load alias tables into pf and add rules to userrules anchor
	// WHY: Ensures rules work even if filter_configure() marks aliases as "Unresolvable"
	// This is a backup mechanism that directly manipulates pf
	
	// Get the real LAN interface (e.g., igc0, em0, etc.)
	$lan_if = get_real_interface('lan');
	if (empty($lan_if)) {
		pc_log("Could not determine LAN interface, skipping backup pfctl rules", 'warning');
		$lan_if = 'lan';  // Fallback
	}
	
	$pf_rules = array();
	foreach ($service_aliases as $alias_name) {
		$service_name = str_replace('PC_Service_', '', $alias_name);
		$table_file = "/var/db/aliastables/{$alias_name}.txt";
		
		// Load table into pf if file exists
		if (file_exists($table_file) && filesize($table_file) > 0) {
			// Create/update pf table
			$cmd = "pfctl -t {$alias_name} -T replace -f {$table_file} 2>&1";
			$output = array();
			$return_var = 0;
			exec($cmd, $output, $return_var);
			
			if ($return_var === 0) {
				pc_log("Loaded pf table: {$alias_name}", 'debug');
			} else {
				pc_log("Failed to load pf table: {$alias_name} - " . implode(' ', $output), 'warning');
			}
			
			// Add TWO rules to userrules anchor as backup
			// RULE 1: Block rule for devices that exceeded limits (evaluated first)
			// RULE 2: Monitor rule for all profile devices (evaluated second, tracks usage)
			// This ensures the rules work even if filter_configure() doesn't generate them
			// NOTE: Using dynamically detected LAN interface ({$lan_if})
			$pf_rules[] = "block in log quick on {$lan_if} inet from <parental_control_blocked> to <{$alias_name}> label \"USER_RULE: PC - {$service_name} Service Block\"";
			$pf_rules[] = "pass in log quick on {$lan_if} inet from <parental_control_monitor> to <{$alias_name}> keep state label \"USER_RULE: PC - {$service_name} Service Monitor\"";
		}
	}
	
	// Load rules into userrules anchor if any were created
	if (!empty($pf_rules)) {
		$rules_content = implode("\n", $pf_rules) . "\n";
		$temp_rules_file = tempnam('/tmp', 'pc_userrules_');
		file_put_contents($temp_rules_file, $rules_content);
		
		$cmd = "pfctl -a userrules -f {$temp_rules_file} 2>&1";
		$output = array();
		$return_var = 0;
		exec($cmd, $output, $return_var);
		
		if ($return_var === 0) {
			pc_log("Loaded " . count($pf_rules) . " rules into userrules anchor", 'info');
		} else {
			pc_log("Failed to load rules into userrules anchor: " . implode(' ', $output), 'warning');
		}
		
		@unlink($temp_rules_file);
	}
	
	// Mark that filter needs reload
	mark_subsystem_dirty('filter');
	
	return true;
}

/**
 * Get all devices from all profiles
 * 
 * Retrieves MAC addresses of all devices configured across all parental
 * control profiles. Used for creating firewall rules that apply to all
 * monitored devices.
 * 
 * @return array Array of normalized MAC addresses
 * @since 1.4.7
 */
function pc_get_all_profile_devices() {
	$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
	$devices = array();
	
	foreach ($profiles as $profile) {
		if (isset($profile['row']) && is_array($profile['row'])) {
			foreach ($profile['row'] as $device) {
				if (isset($device['mac_address'])) {
					$devices[] = pc_normalize_mac($device['mac_address']);
				}
			}
		}
	}
	
	return array_unique($devices);
}

/**
 * Create NAT redirect rules for HTTP/HTTPS to block page
 * 
 * When devices are blocked, we want to redirect their HTTP/HTTPS traffic
 * to a friendly block page instead of just silently dropping packets.
 * 
 * Creates NAT rules that redirect:
 * - HTTP (port 80) → pfSense block page
 * - HTTPS (port 443) → pfSense block page (will show cert warning)
 * 
 * @return bool True if rules exist or were created successfully
 * @since 1.1.9
 */
function pc_create_redirect_rules() {
	global $config;
	
	$lan_ip = get_interface_ip('lan');
	if (empty($lan_ip)) {
		$lan_ip = '192.168.1.1'; // Fallback
	}
	
	$captive_port = '1008'; // Dedicated captive portal server port
	
	// Get NAT rules
	if (!isset($config['nat'])) {
		$config['nat'] = array();
	}
	if (!isset($config['nat']['rule'])) {
		$config['nat']['rule'] = array();
	}
	
	$nat_rules = $config['nat']['rule'];
	$needs_save = false;
	
	// Check if HTTP redirect rule exists
	$http_redirect_exists = false;
	$https_redirect_exists = false;
	
	foreach ($nat_rules as $key => $rule) {
		if (isset($rule['descr'])) {
			if ($rule['descr'] === 'Parental Control - Redirect HTTP to Block Page') {
				// Check if it needs updating (old version redirected to port 80)
				if (isset($rule['local-port']) && $rule['local-port'] !== $captive_port) {
					// Remove old rule, will recreate with correct port
					unset($config['nat']['rule'][$key]);
					$needs_save = true;
				} else {
					$http_redirect_exists = true;
				}
			}
			if ($rule['descr'] === 'Parental Control - Redirect HTTPS to Block Page') {
				// Check if it needs updating (old version redirected to port 443)
				if (isset($rule['local-port']) && $rule['local-port'] !== $captive_port) {
					// Remove old rule, will recreate with correct port
					unset($config['nat']['rule'][$key]);
					$needs_save = true;
				} else {
					$https_redirect_exists = true;
				}
			}
		}
	}
	
	// Reindex array after deletions
	if ($needs_save) {
		$config['nat']['rule'] = array_values($config['nat']['rule']);
	}
	
	// Create HTTP redirect rule if needed
	if (!$http_redirect_exists) {
		$http_rule = array(
			'source' => array(
				'address' => 'parental_control_blocked'
			),
			'destination' => array(
				'any' => '',
				'port' => '80'
			),
			'protocol' => 'tcp',
			'target' => $lan_ip,
			'local-port' => $captive_port,
			'interface' => 'lan',
			'descr' => 'Parental Control - Redirect HTTP to Block Page',
			'associated-rule-id' => 'pass'
		);
		
		array_unshift($config['nat']['rule'], $http_rule);
		$needs_save = true;
		
		pc_log("Created HTTP redirect rule for captive portal", 'info', array(
			'event.action' => 'nat_rule_created',
			'rule.type' => 'port_forward',
			'rule.protocol' => 'HTTP',
			'redirect.target' => "{$lan_ip}:{$captive_port}"
		));
	}
	
	// Create HTTPS redirect rule if needed
	if (!$https_redirect_exists) {
		$https_rule = array(
			'source' => array(
				'address' => 'parental_control_blocked'
			),
			'destination' => array(
				'any' => '',
				'port' => '443'
			),
			'protocol' => 'tcp',
			'target' => $lan_ip,
			'local-port' => $captive_port,
			'interface' => 'lan',
			'descr' => 'Parental Control - Redirect HTTPS to Block Page',
			'associated-rule-id' => 'pass'
		);
		
		array_unshift($config['nat']['rule'], $https_rule);
		$needs_save = true;
		
		pc_log("Created HTTPS redirect rule for captive portal", 'info', array(
			'event.action' => 'nat_rule_created',
			'rule.type' => 'port_forward',
			'rule.protocol' => 'HTTPS',
			'redirect.target' => "{$lan_ip}:{$captive_port}"
		));
	}
	
	if ($needs_save) {
		write_config('Parental Control: Added/updated redirect rules for captive portal');
		mark_subsystem_dirty('nat');
	}
	
	return true;
}

/**
 * Create filter rules to allow DNS and pfSense access for blocked devices
 * 
 * Before blocking everything, we need to allow:
 * 1. DNS queries (so domain names resolve)
 * 2. Access to pfSense GUI (so block page can load)
 * 
 * These rules must be BEFORE the block-all rule (higher priority).
 * 
 * @return bool True if rules exist or were created successfully
 * @since 1.1.9
 */
function pc_create_allow_rules() {
	global $config;
	
	// First, ensure the port aliases exist for pfSense access ports
	pc_create_port_aliases();
	
	$lan_ip = get_interface_ip('lan');
	if (empty($lan_ip)) {
		$lan_ip = '192.168.1.1';
	}
	
	$rules = config_get_path('filter/rule', []);
	$needs_save = false;
	
	// Check if allow rules exist
	$dns_allow_exists = false;
	$pfsense_allow_exists = false;
	
	foreach ($rules as $rule) {
		if (isset($rule['descr'])) {
			if ($rule['descr'] === 'Parental Control - Allow DNS for Blocked Devices') {
				$dns_allow_exists = true;
			}
			if ($rule['descr'] === 'Parental Control - Allow pfSense Access for Blocked Devices') {
				$pfsense_allow_exists = true;
			}
		}
	}
	
	// Create DNS allow rule if needed
	if (!$dns_allow_exists) {
		$dns_rule = array(
			'type' => 'pass',
			'interface' => 'lan',
			'ipprotocol' => 'inet',  // IPv4 + IPv6
			'protocol' => 'udp',
			'direction' => 'any',  // Both directions - simpler and more reliable
			'source' => array(
				'address' => 'parental_control_blocked'
			),
			'destination' => array(
				'any' => '',
				'port' => '53'
			),
			'floating' => 'yes',
			'quick' => 'yes',
			'descr' => 'Parental Control - Allow DNS for Blocked Devices',
			'log' => 'yes',  // Enable logging
			'created' => array(
				'time' => time(),
				'username' => 'system@parentalcontrol'
			)
		);
		
		array_unshift($rules, $dns_rule);
		$needs_save = true;
		
		pc_log("Created DNS allow rule for blocked devices", 'info', array(
			'event.action' => 'filter_rule_created',
			'rule.type' => 'pass',
			'rule.protocol' => 'DNS'
		));
	}
	
	// Create pfSense access allow rule if needed (includes captive portal port 1008)
	if (!$pfsense_allow_exists) {
		$pfsense_rule = array(
			'type' => 'pass',
			'interface' => 'lan',
			'ipprotocol' => 'inet',  // IPv4 + IPv6
			'protocol' => 'tcp',
			'direction' => 'any',  // Both directions - simpler and more reliable
			'source' => array(
				'address' => 'parental_control_blocked'
			),
			'destination' => array(
				'address' => $lan_ip,
				'port' => 'KACI_PC_Ports'  // Use port alias for HTTP (80), HTTPS (443), and Captive Portal (1008)
			),
			'floating' => 'yes',
			'quick' => 'yes',
			'descr' => 'Parental Control - Allow pfSense Access for Blocked Devices',
			'log' => 'yes',  // Enable logging
			'created' => array(
				'time' => time(),
				'username' => 'system@parentalcontrol'
			)
		);
		
		array_unshift($rules, $pfsense_rule);
		$needs_save = true;
		
		pc_log("Created pfSense access allow rule for blocked devices (using port alias)", 'info', array(
			'event.action' => 'filter_rule_created',
			'rule.type' => 'pass',
			'rule.destination' => $lan_ip,
			'rule.port_alias' => 'KACI_PC_Ports'
		));
	}
	
	if ($needs_save) {
		config_set_path('filter/rule', $rules);
		write_config('Parental Control: Added allow rules for blocked devices');
		mark_subsystem_dirty('filter');
	}
	
	return true;
}

/**
 * Ensure captive portal server is running (BLOCKING - use for manual starts only)
 * 
 * Starts the standalone PHP web server on port 1008 to serve the block page
 * without authentication. This creates a true captive portal experience.
 * 
 * Uses the RC script parental_control_captive (FreeBSD RC script) to manage the server.
 * If the server is not running, it will be started automatically.
 * 
 * WARNING: This function BLOCKS until server starts (2+ seconds). DO NOT call during boot/sync!
 * Use pc_ensure_captive_portal_running_async() for non-blocking startup during boot.
 * 
 * @return bool True if server is running or was started successfully
 * @since 1.1.11
 * @updated 1.4.3 Added warning about blocking behavior
 */
function pc_ensure_captive_portal_running() {
	$rc_script = '/usr/local/etc/rc.d/parental_control_captive';
	$pidfile = '/var/run/parental_control_captive.pid';
	
	// Check if RC script exists
	if (!file_exists($rc_script)) {
		pc_log("Captive portal RC script not found, cannot start server", 'warning', array(
			'event.action' => 'captive_portal_missing',
			'file.path' => $rc_script
		));
		return false;
	}
	
	// Check if already running
	if (file_exists($pidfile)) {
		$pid = trim(file_get_contents($pidfile));
		if (!empty($pid) && posix_kill($pid, 0)) {
			// Server is running
			pc_log("Captive portal server is already running", 'debug', array(
				'event.action' => 'captive_portal_status',
				'service.state' => 'running',
				'process.pid' => $pid
			));
			return true;
		} else {
			// Stale PID file
			pc_log("Removing stale captive portal PID file", 'info', array(
				'event.action' => 'captive_portal_cleanup',
				'file.path' => $pidfile
			));
			unlink($pidfile);
		}
	}
	
	// Start the server (using onestart to bypass rc.conf requirement)
	pc_log("Starting captive portal server", 'info', array(
		'event.action' => 'captive_portal_start',
		'service.port' => 1008
	));
	
	exec($rc_script . ' onestart 2>&1', $output, $return_code);
	
	if ($return_code === 0) {
		pc_log("Captive portal server started successfully", 'info', array(
			'event.action' => 'captive_portal_started',
			'event.outcome' => 'success',
			'service.port' => 1008
		));
		return true;
	} else {
		pc_log("Failed to start captive portal server", 'error', array(
			'event.action' => 'captive_portal_start_failed',
			'event.outcome' => 'failure',
			'exit.code' => $return_code,
			'output' => implode("\n", $output)
		));
		return false;
	}
}

/**
 * Ensure captive portal server is running (NON-BLOCKING - safe for boot/sync)
 * 
 * Starts the captive portal server in the background without blocking.
 * Safe to call during boot/sync operations. Server startup happens asynchronously
 * and will complete in the background.
 * 
 * WHY: Blocking startup during boot sync causes firewall to hang at boot with
 *      "Loading KACI Parental Control" message and never returns to prompt.
 * ROOT CAUSE: exec() call in pc_ensure_captive_portal_running() blocks for 2+ seconds
 *             while RC script does sleep + verification. During boot, this blocks
 *             the entire pfSense configuration resync process.
 * SOLUTION: Use background execution (nohup + &) to start server without waiting.
 *           Cron job will verify/restart if needed. Boot completes normally.
 * 
 * @return void Does not return status (fire and forget)
 * @since 1.4.3
 */
function pc_ensure_captive_portal_running_async() {
	$rc_script = '/usr/local/etc/rc.d/parental_control_captive';
	$pidfile = '/var/run/parental_control_captive.pid';
	
	// Check if RC script exists
	if (!file_exists($rc_script)) {
		pc_log("Captive portal RC script not found, cannot start server", 'warning', array(
			'event.action' => 'captive_portal_missing',
			'file.path' => $rc_script,
			'startup_mode' => 'async'
		));
		return;
	}
	
	// Quick check if already running (don't wait for verification)
	if (file_exists($pidfile)) {
		$pid = trim(@file_get_contents($pidfile));
		if (!empty($pid) && @posix_kill($pid, 0)) {
			// Server is already running, no need to start
			pc_log("Captive portal server already running, skipping async start", 'debug', array(
				'event.action' => 'captive_portal_status',
				'service.state' => 'running',
				'process.pid' => $pid,
				'startup_mode' => 'async'
			));
			return;
		}
	}
	
	// Start the server in background (fire and forget)
	// Use nohup and redirect to /dev/null for true background execution
	// The & operator makes it truly non-blocking
	pc_log("Starting captive portal server (async/non-blocking)", 'info', array(
		'event.action' => 'captive_portal_start_async',
		'service.port' => 1008,
		'startup_mode' => 'background',
		'note' => 'Non-blocking startup for boot safety'
	));
	
	// Background execution: nohup + & + redirect stderr/stdout
	// This returns immediately without waiting for server to start
	exec("nohup {$rc_script} onestart > /dev/null 2>&1 &");
	
	// Don't wait for completion - let it start in background
	// Cron job will verify status and restart if needed
	pc_log("Captive portal start command issued (background)", 'debug', array(
		'event.action' => 'captive_portal_start_issued',
		'note' => 'Server starting in background, not blocking boot'
	));
}

/**
 * Calculate which devices should be blocked right now
 * 
 * Examines all configured devices and determines which should be blocked based on:
 * - Time limit exceeded
 * - Active schedule block
 * - Parent override status (skips blocking if active)
 * 
 * Returns array with MAC addresses as keys for fast lookup
 * 
 * @param array $state Current state with usage data
 * @return array Associative array [mac => device_info] for blocked devices
 * @since 0.7.0
 */
function pc_calculate_blocked_devices($state) {
	$blocked = [];
	$devices = pc_get_devices();
	$pc_config = config_get_path('installedpackages/parentalcontrol/config/0', []);
	$enforcement_mode = isset($pc_config['enforcement_mode']) ? $pc_config['enforcement_mode'] : 'strict';
	
	foreach ($devices as $device) {
		// Skip disabled devices
		if (!pc_is_device_enabled($device)) {
			continue;
		}
		
		$mac = pc_normalize_mac($device['mac_address']);
		
		// Check if device has active parent override
		if (pc_has_active_override($mac, $state)) {
			continue; // Skip blocking - parent override is active
		}
		
		$should_block = false;
		$reason = '';
		
		// Check if currently in blocked schedule
		if (pc_is_in_blocked_schedule($device)) {
			$should_block = true;
			$reason = 'Scheduled block time';
		}
		
		// Check if time limit exceeded
		if (!$should_block && pc_is_time_limit_exceeded($device, $state)) {
			$should_block = true;
			$reason = 'Time limit exceeded';
		}
		
		if ($should_block) {
			// CRITICAL: Only block devices that are ONLINE (have an IP address)
			// WHY: pfSense firewall rules require IP addresses, not MAC addresses
			// Offline devices will be blocked automatically when they come online
			
			// Check if device has an IP address in the cache
			$ip_address = isset($state['mac_to_ip_cache'][$mac]) ? $state['mac_to_ip_cache'][$mac] : null;
			
			if ($ip_address) {
				// Device is online - can be blocked
				$profile_name = isset($device['profile_name']) ? $device['profile_name'] : 
				                (isset($device['child_name']) ? $device['child_name'] : 'Unknown');
				
				$blocked[$mac] = [
					'device' => $device,
					'reason' => $reason,
					'enforcement_mode' => $enforcement_mode,
					'ip' => $ip_address,
					'profile' => $profile_name
				];
			} else {
				// Device is offline - skip for now, will block when it comes online
				pc_log("Device {$mac} should be blocked but is offline - will block when online", 'debug', array(
					'event.action' => 'block_deferred',
					'device.mac' => $mac,
					'device.name' => isset($device['device_name']) ? $device['device_name'] : 'Unknown',
					'block.reason' => $reason,
					'note' => 'Device offline - no IP address'
				));
			}
		}
	}
	
	return $blocked;
}

/**
 * Apply smart firewall changes for devices whose state changed
 * 
 * Compares previous blocked devices with current blocked devices and only
 * updates firewall rules for devices whose state changed. This avoids calling
 * filter_configure() which causes AQM flowset errors.
 * 
 * Uses direct pfctl commands for selective rule updates instead of full reload.
 * 
 * @param array $old_blocked Previously blocked devices [mac => info]
 * @param array $new_blocked Devices that should be blocked now [mac => info]
 * @param array $state Current state (for IP lookups)
 * @return int Number of firewall changes applied
 * @since 0.7.0
 */
function pc_apply_smart_firewall_changes($old_blocked, $new_blocked, $state) {
	$changes = 0;
	
	// Find devices that need to be UNBLOCKED (were blocked, but should not be now)
	foreach ($old_blocked as $mac => $info) {
		if (!isset($new_blocked[$mac])) {
			// Device should be unblocked - use table-based method
			if (pc_remove_device_block_table($mac, $state)) {
				$changes++;
				pc_log("Unblocked device: {$mac}", 'info', array(
					'event.action' => 'device_unblocked',
					'device.mac' => $mac
				));
			}
		}
	}
	
	// CRITICAL FIX: Always ensure ALL devices that should be blocked ARE in pfctl table
	// WHY: pfctl table can be cleared (reboot, manual flush) while state still has devices marked
	// SOLUTION: Re-add all blocked devices to table (pfctl -T add is idempotent, no duplicates)
	//
	// OLD LOGIC (BROKEN):
	// - Only add if not in old_blocked
	// - Assumes pfctl table mirrors state
	// - Breaks when pfctl table is cleared but state persists
	//
	// NEW LOGIC (FIXED):
	// - Always ensure device is in pfctl table
	// - Check if IP is actually in table, not just in state
	// - Guarantees pfctl table always matches intended blocks
	
	foreach ($new_blocked as $mac => $info) {
		// Check if IP is actually in pfctl table (not just in state)
		$ip = isset($state['mac_to_ip_cache'][$mac]) ? $state['mac_to_ip_cache'][$mac] : null;
		
		if ($ip) {
			// Check if this IP is in the pfctl table
			exec("/sbin/pfctl -t parental_control_blocked -T test {$ip} 2>&1", $test_output, $test_ret);
			$in_table = ($test_ret === 0);
			
			if (!$in_table) {
				// IP not in table - add it now
				if (pc_add_device_block_table($info['device'], $info['enforcement_mode'], $info['reason'], $state)) {
					$changes++;
					pc_log("Blocked device: {$mac} - {$info['reason']}", 'info', array(
						'event.action' => 'device_blocked',
						'device.mac' => $mac,
						'device.name' => $info['device']['device_name'] ?? 'Unknown',
						'block.reason' => $info['reason'],
						'note' => 'Added to pfctl table'
					));
				}
			}
		}
	}
	
	return $changes;
}

/**
 * Add device IP to pfSense blocking table
 * 
 * CRITICAL: Uses pfSense tables (aliases) instead of anchors for proper rule ordering
 * WHY: Tables work with pfSense's native rule system and are evaluated in correct order
 * 
 * Adds the device's IP address to the parental_control_blocked table. The floating
 * rule will then block all traffic from this IP.
 * 
 * @param array $device Device information including mac_address
 * @param string $enforcement_mode Enforcement mode (not used with tables)
 * @param string $reason Reason for blocking
 * @param array $state Current state for IP lookup
 * @return bool True if device was blocked successfully
 * @since 1.1.8
 */
function pc_add_device_block_table($device, $enforcement_mode, $reason, $state) {
	$mac = pc_normalize_mac($device['mac_address']);
	$device_name = isset($device['device_name']) ? $device['device_name'] : $mac;
	
	// Get device IP from state
	$ip = null;
	if (isset($state['mac_to_ip_cache'][$mac])) {
		$ip = $state['mac_to_ip_cache'][$mac];
	}
	
	if (empty($ip)) {
		// Device is offline - cannot block
		pc_log("Device {$mac} is offline - cannot block (no IP address)", 'debug', array(
			'event.action' => 'block_skipped',
			'device.mac' => $mac,
			'reason' => 'device_offline'
		));
		return false;
	}
	
	// Add IP to pfSense table
	exec("/sbin/pfctl -t parental_control_blocked -T add {$ip} 2>&1", $output, $return_code);
	
	if ($return_code === 0) {
		pc_log("Blocked {$ip} ({$device_name}) via pfSense table - {$reason}", 'info', array(
			'event.action' => 'device_blocked',
			'device.ip' => $ip,
			'device.mac' => $mac,
			'device.name' => $device_name,
			'block.reason' => $reason,
			'method' => 'pf_table',
			'table.name' => 'parental_control_blocked'
		));
		return true;
	} else {
		pc_log("Failed to block {$ip}: " . implode("\n", $output), 'error', array(
			'event.action' => 'block_failed',
			'device.ip' => $ip,
			'device.mac' => $mac,
			'error.output' => implode("\n", $output)
		));
		return false;
	}
}

/**
 * Remove device IP from pfSense blocking table
 * 
 * Removes the device's IP address from the parental_control_blocked table,
 * allowing traffic from this IP again.
 * 
 * @param string $mac Device MAC address
 * @param array $state Current state for IP lookup
 * @return bool True if device was unblocked successfully
 * @since 1.1.8
 */
function pc_remove_device_block_table($mac, $state) {
	$mac = pc_normalize_mac($mac);
	
	// Get device IP from state
	$ip = null;
	if (isset($state['mac_to_ip_cache'][$mac])) {
		$ip = $state['mac_to_ip_cache'][$mac];
	}
	
	if (empty($ip)) {
		return true;  // Nothing to remove
	}
	
	// Remove IP from pfSense table
	exec("/sbin/pfctl -t parental_control_blocked -T delete {$ip} 2>&1", $output, $return_code);
	
	if ($return_code === 0) {
		pc_log("Unblocked {$ip} ({$mac}) via pfSense table", 'info', array(
			'event.action' => 'device_unblocked',
			'device.ip' => $ip,
			'device.mac' => $mac,
			'method' => 'pf_table',
			'table.name' => 'parental_control_blocked'
		));
		return true;
	}
	
	return false;
}

/**
 * Add IP to a service-specific blocking table
 * 
 * Blocks a specific IP address from accessing a particular service
 * (e.g., YouTube, Facebook, Discord) while allowing access to other
 * services and general internet.
 * 
 * @param string $ip IP address to block
 * @param string $service_name Service name (e.g., 'youtube', 'facebook')
 * @return bool True if IP added successfully
 * @since 1.4.31
 */
function pc_add_ip_to_service_table($ip, $service_name) {
	if (empty($ip) || empty($service_name)) {
		return false;
	}
	
	$service_name_lower = strtolower($service_name);
	// CRITICAL: Use shortened prefix to stay within 31 char limit
	$table_name = 'pc_blocked_' . $service_name_lower;
	
	// Add IP to service-specific table
	exec("/sbin/pfctl -t {$table_name} -T add {$ip} 2>&1", $output, $return_code);
	
	if ($return_code === 0) {
		pc_log("Blocked {$ip} from {$service_name} service", 'info', array(
			'event.action' => 'service_blocked',
			'device.ip' => $ip,
			'service.name' => $service_name,
			'method' => 'pf_table',
			'table.name' => $table_name
		));
		return true;
	} else {
		pc_log("Failed to block {$ip} from {$service_name}: " . implode(' ', $output), 'warning', array(
			'event.action' => 'service_block_failed',
			'device.ip' => $ip,
			'service.name' => $service_name,
			'error' => implode(' ', $output)
		));
		return false;
	}
}

/**
 * Remove IP from a service-specific blocking table
 * 
 * Unblocks an IP address from a specific service, restoring access
 * to that service.
 * 
 * @param string $ip IP address to unblock
 * @param string $service_name Service name (e.g., 'youtube', 'facebook')
 * @return bool True if IP removed successfully
 * @since 1.4.31
 */
function pc_remove_ip_from_service_table($ip, $service_name) {
	if (empty($ip) || empty($service_name)) {
		return false;
	}
	
	$service_name_lower = strtolower($service_name);
	// CRITICAL: Use shortened prefix to stay within 31 char limit
	$table_name = 'pc_blocked_' . $service_name_lower;
	
	// Remove IP from service-specific table
	exec("/sbin/pfctl -t {$table_name} -T delete {$ip} 2>&1", $output, $return_code);
	
	if ($return_code === 0) {
		pc_log("Unblocked {$ip} from {$service_name} service", 'info', array(
			'event.action' => 'service_unblocked',
			'device.ip' => $ip,
			'service.name' => $service_name,
			'method' => 'pf_table',
			'table.name' => $table_name
		));
		return true;
	}
	
	return false;
}

/**
 * Get all IPs currently blocked from a specific service
 * 
 * Queries the pfctl table for a service to get the list of
 * IP addresses currently blocked from that service.
 * 
 * @param string $service_name Service name (e.g., 'youtube', 'facebook')
 * @return array Array of IP addresses
 * @since 1.4.31
 */
function pc_get_service_blocked_ips($service_name) {
	if (empty($service_name)) {
		return array();
	}
	
	$service_name_lower = strtolower($service_name);
	// CRITICAL: Use shortened prefix to stay within 31 char limit
	$table_name = 'pc_blocked_' . $service_name_lower;
	
	// Query the table
	exec("/sbin/pfctl -t {$table_name} -T show 2>&1", $output, $return_code);
	
	if ($return_code === 0 && !empty($output)) {
		// Filter out empty lines and return
		return array_filter(array_map('trim', $output));
	}
	
	return array();
}

/**
 * Clear all IPs from a service-specific blocking table
 * 
 * Removes all entries from a service block table, typically used
 * during daily reset or manual unblock operations.
 * 
 * @param string $service_name Service name (e.g., 'youtube', 'facebook')
 * @return bool True if table cleared successfully
 * @since 1.4.31
 */
function pc_clear_service_table($service_name) {
	if (empty($service_name)) {
		return false;
	}
	
	$service_name_lower = strtolower($service_name);
	// CRITICAL: Use shortened prefix to stay within 31 char limit
	$table_name = 'pc_blocked_' . $service_name_lower;
	
	// Flush the table
	exec("/sbin/pfctl -t {$table_name} -T flush 2>&1", $output, $return_code);
	
	if ($return_code === 0) {
		pc_log("Cleared all IPs from {$service_name} block table", 'info', array(
			'event.action' => 'service_table_cleared',
			'service.name' => $service_name,
			'table.name' => $table_name
		));
		return true;
	}
	
	return false;
}

/**
 * Add firewall block for a specific device using pfSense anchor
 * 
 * @deprecated since 1.1.8 - Use pc_add_device_block_table() instead
 * 
 * Creates rules that:
 * 1. Allow access to pfSense (so user can see block page)
 * 2. Redirect HTTP/HTTPS to block page
 * 3. Block all other traffic
 * 
 * This provides user-friendly blocking with explanation instead of silent drop.
 * 
 * @param array $device Device information
 * @param string $enforcement_mode 'strict' or 'moderate'
 * @param string $reason Block reason for logging
 * @param array $state Current state (for IP lookup)
 * @return bool True if rule added successfully
 * @since 0.7.0
 * @updated 0.7.4 Added block page redirect
 */
function pc_add_device_block($device, $enforcement_mode, $reason, $state) {
	$mac = pc_normalize_mac($device['mac_address']);
	
	// Get device IP from state (MAC to IP cache)
	$ip = null;
	if (isset($state['mac_to_ip_cache'][$mac])) {
		$ip = $state['mac_to_ip_cache'][$mac];
	}
	
	if (empty($ip)) {
		// Device is offline - this is normal, not an error
		// Will be blocked automatically when it comes online
		pc_log("Device {$mac} is offline - cannot block (no IP address)", 'debug', array(
			'event.action' => 'block_skipped',
			'device.mac' => $mac,
			'reason' => 'device_offline',
			'note' => 'Will block automatically when device comes online'
		));
		return false;
	}
	
	// Get pfSense LAN IP for redirect
	$lan_ip = get_interface_ip('lan');
	if (empty($lan_ip)) {
		$lan_ip = '192.168.1.1'; // Fallback
	}
	
	// Add rules to anchor file
	$anchor_file = '/tmp/rules.parental_control';
	$device_name = isset($device['device_name']) ? $device['device_name'] : 'Unknown';
	
	// Read existing rules to avoid duplicates
	$existing_rules = file_exists($anchor_file) ? file_get_contents($anchor_file) : '';
	
	// Check if rules already exist for this IP
	if (strpos($existing_rules, "# Device: {$ip}") !== false) {
		// Rules already exist, no need to add
		return true;
	}
	
	// Build the rules for this device
	// Rule 1: Allow DNS (so they can resolve names)
	// Rule 2: Allow access to pfSense itself (for accessing block page if configured)
	// Rule 3: Block everything else
	// Note: Removed rdr rules - they cause syntax errors in pfSense anchors
	
	$rules = "# Device: {$ip} ({$device_name}) - {$reason}\n";
	$rules .= "pass quick proto udp from {$ip} to any port = 53 label \"PC-DNS:{$device_name}\"\n";
	$rules .= "pass quick from {$ip} to {$lan_ip} label \"PC-Allow:{$device_name}\"\n";
	$rules .= "block drop quick from {$ip} to any label \"PC-Block:{$device_name}\"\n";
	$rules .= "\n";
	
	// Append to anchor file
	file_put_contents($anchor_file, $rules, FILE_APPEND | LOCK_EX);
	
	// Reload anchor (fast, no filter_configure needed)
	// CRITICAL: We need to ensure pfSense knows about our anchor
	// First, inject anchor reference if it doesn't exist
	pc_inject_anchor_reference();
	
	// Then load our rules
	exec("/sbin/pfctl -a parental_control -f {$anchor_file} 2>&1", $output, $return_code);
	
	if ($return_code === 0) {
		pc_log("Blocked {$ip} ({$mac}) with redirect to block page - {$reason}", 'info', array(
			'event.action' => 'device_blocked',
			'device.ip' => $ip,
			'device.mac' => $mac,
			'device.name' => $device_name,
			'block.reason' => $reason,
			'enforcement.mode' => $enforcement_mode,
			'redirect.enabled' => true
		));
		return true;
	} else {
		pc_log("Failed to block {$ip}: " . implode("\n", $output), 'error', array(
			'event.action' => 'block_failed',
			'device.ip' => $ip,
			'device.mac' => $mac,
			'error.output' => implode("\n", $output)
		));
		return false;
	}
}

/**
 * Remove firewall block for a specific device
 * 
 * Removes all rules for device's IP from the parental_control anchor.
 * This includes pass rules, redirect rules, and block rules.
 * 
 * @param string $mac Device MAC address
 * @param array $state Current state (for IP lookup)
 * @return bool True if rule removed successfully
 * @since 0.7.0
 * @updated 0.7.4 Removes entire device rule block
 */
function pc_remove_device_block($mac, $state) {
	$mac = pc_normalize_mac($mac);
	
	// Get device IP from state
	$ip = null;
	if (isset($state['mac_to_ip_cache'][$mac])) {
		$ip = $state['mac_to_ip_cache'][$mac];
	}
	
	if (empty($ip)) {
		return true; // No IP to remove
	}
	
	$anchor_file = '/tmp/rules.parental_control';
	
	if (!file_exists($anchor_file)) {
		return true; // Nothing to remove
	}
	
	// Read existing rules
	$rules = file($anchor_file, FILE_IGNORE_NEW_LINES);
	$new_rules = array();
	$skip_until_blank = false;
	$removed = false;
	
	// Filter out all rules for this IP (including the comment header)
	foreach ($rules as $rule) {
		// Check if this is the start of this device's rule block
		if (strpos($rule, "# Device: {$ip}") !== false) {
			$skip_until_blank = true;
			$removed = true;
			continue;
		}
		
		// If we're skipping, continue until we hit a blank line
		if ($skip_until_blank) {
			if (trim($rule) === '') {
				$skip_until_blank = false;
			}
			continue;
		}
		
		// Keep this rule
		$new_rules[] = $rule;
	}
	
	// Write back the filtered rules
	file_put_contents($anchor_file, implode("\n", $new_rules) . "\n", LOCK_EX);
	
	// Reload anchor
	exec("/sbin/pfctl -a parental_control -f {$anchor_file} 2>&1", $output, $return_code);
	
	if ($return_code === 0 && $removed) {
		pc_log("Unblocked {$ip} ({$mac}) - all rules removed", 'info', array(
			'event.action' => 'device_unblocked',
			'device.ip' => $ip,
			'device.mac' => $mac
		));
	}
	
	return true;
}

/**
 * Check and enforce profile-level service limits
 * 
 * For each enabled profile, checks if service-specific usage limits have been
 * exceeded. When a limit is exceeded, all devices in that profile are blocked
 * from accessing that specific service while retaining access to other services.
 * 
 * Service limits support weekend bonuses and automatic midnight reset.
 * 
 * @param array &$state Current state array (modified by reference)
 * @return void Devices are added/removed from service-specific block tables
 * @since 1.4.31
 */
function pc_check_profile_service_limits(&$state) {
	// Get all profiles
	$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', array());
	
	if (!is_array($profiles) || empty($profiles)) {
		return;
	}
	
	// Check if it's weekend (Saturday or Sunday)
	$is_weekend = (date('N') >= 6);
	
	// Process each profile
	foreach ($profiles as $profile) {
		// Skip disabled profiles
		if (!isset($profile['enabled']) || $profile['enabled'] !== 'on') {
			continue;
		}
		
		$profile_name = $profile['name'];
		
		// Check if profile has service limits configured
		if (!isset($profile['service_limits']) || !is_array($profile['service_limits'])) {
			continue;
		}
		
		// Get all devices in this profile
		$profile_devices = array();
		$profile_device_ips = array();
		
		if (isset($profile['devices']) && is_array($profile['devices'])) {
			foreach ($profile['devices'] as $device) {
				$mac = pc_normalize_mac($device['mac_address']);
				$profile_devices[] = $mac;
				
				// Get IP from state cache
				if (isset($state['mac_to_ip_cache'][$mac])) {
					$ip = $state['mac_to_ip_cache'][$mac];
					if (!empty($ip)) {
						$profile_device_ips[] = $ip;
					}
				}
			}
		}
		
		if (empty($profile_device_ips)) {
			// No online devices in this profile, skip
			continue;
		}
		
		// Check each service limit
		foreach ($profile['service_limits'] as $service_alias => $limit_config) {
			// Extract service name from alias (e.g., 'pc_service_youtube' -> 'youtube')
			$service_name = str_replace('pc_service_', '', strtolower($service_alias));
			
			// Get the daily limit
			$daily_limit = isset($limit_config['daily_limit']) ? intval($limit_config['daily_limit']) : 0;
			
			if ($daily_limit <= 0) {
				continue; // No limit configured for this service
			}
			
			// Apply weekend bonus if applicable
			$effective_limit = $daily_limit;
			if ($is_weekend && isset($limit_config['weekend_bonus'])) {
				$effective_limit += intval($limit_config['weekend_bonus']);
			}
			
			// Get profile's current usage for this service
			$service_usage = 0;
			if (isset($state['profiles'][$profile_name]['service_usage'][$service_name]['usage_today'])) {
				$service_usage = $state['profiles'][$profile_name]['service_usage'][$service_name]['usage_today'];
			}
			
			// Check if limit exceeded
			if ($service_usage >= $effective_limit) {
				// BLOCK: Add all profile device IPs to service-specific block table
				foreach ($profile_device_ips as $ip) {
					pc_add_ip_to_service_table($ip, $service_name);
				}
				
				pc_log("Profile {$profile_name} exceeded {$service_name} limit - blocked all devices", 'info', array(
					'event.action' => 'profile_service_limit_exceeded',
					'profile.name' => $profile_name,
					'service.name' => $service_name,
					'usage.today' => $service_usage,
					'usage.limit' => $effective_limit,
					'devices.blocked' => count($profile_device_ips),
					'weekend.bonus' => $is_weekend ? ($effective_limit - $daily_limit) : 0
				));
			} else {
				// UNBLOCK: Remove profile device IPs from service-specific block table
				// (in case they were manually unblocked or usage was reset)
				foreach ($profile_device_ips as $ip) {
					pc_remove_ip_from_service_table($ip, $service_name);
				}
			}
		}
	}
}

/**
 * Cron job handler for periodic enforcement and tracking
 * 
 * Main cron job function called every 5 minutes by system cron. Performs:
 * 1. Checks if service is enabled (exits if disabled)
 * 2. Loads current state and configuration
 * 3. Checks if daily counters need reset (based on reset_time)
 * 4. Updates usage for online devices (increments by check interval)
 * 5. Calculates which devices should be blocked
 * 6. Applies firewall changes only for devices whose state changed
 * 7. Saves updated state
 * 
 * This is the heartbeat of the parental control system, ensuring real-time
 * enforcement and accurate usage tracking WITHOUT causing AQM flowset errors.
 * 
 * @return void State is modified and firewall rules updated as needed
 * @since 0.1.0
 * @updated 0.7.0 Added smart blocking without filter_configure()
 * 
 * @example
 * // Called automatically by cron every 5 minutes:
 * // Cron: every 5 minutes (* /5 * * * *) php parental_control_cron.php
 * parental_control_cron_job();
 * 
 * @see pc_should_reset_counters() For reset time checking
 * @see pc_reset_daily_counters() For counter reset
 * @see pc_update_device_usage() For usage tracking
 * @see pc_calculate_blocked_devices() For determining block status
 * @see pc_apply_smart_firewall_changes() For selective rule updates
 */
function parental_control_cron_job() {
	// CRITICAL FIX v1.4.18: Set execution timeout (kernel panic prevention)
	// Prevents infinite loops and resource exhaustion
	set_time_limit(60);  // Maximum 60 seconds execution
	ini_set('max_execution_time', '60');
	
	// Acquire PID lock to prevent concurrent executions
	if (!pc_acquire_pid_lock()) {
		// Another instance is running, exit gracefully
		return;
	}
	
	// Register shutdown function to release lock
	register_shutdown_function('pc_release_pid_lock');
	
	try {
		// Check if service is enabled using helper function (DRY)
		if (!pc_is_service_enabled()) {
			pc_release_pid_lock();
			return;
		}
		
		$start_time = microtime(true);
		
		// CRITICAL v1.4.18: Add watchdog timer
		// If execution takes > 55 seconds, log warning and prepare to exit
		$watchdog_triggered = false;
		declare(ticks=1);
		register_tick_function(function() use ($start_time, &$watchdog_triggered) {
			// v1.4.28: Increased timeout from 55s to 90s after performance optimizations
			// Even with caching, busy firewalls may need extra time for large state tables
			if (!$watchdog_triggered && (microtime(true) - $start_time) > 90) {
				$watchdog_triggered = true;
				pc_log("WATCHDOG: Cron job exceeded 90 seconds, forcing exit", 'error', array(
					'event.action' => 'watchdog_triggered',
					'execution.duration.seconds' => (microtime(true) - $start_time)
				));
				pc_release_pid_lock();
				exit(1);
			}
		});
		
		// Get the configuration
		$pc_config = config_get_path('installedpackages/parentalcontrol/config', []);
		
		$state = pc_load_state();
		$current_time = time();
		
		// Check if we need to reset daily counters
		$reset_time = config_get_path('installedpackages/parentalcontrol/reset_time', 'midnight');
		$last_reset = isset($state['last_reset']) ? $state['last_reset'] : 0;
		
	if (pc_should_reset_counters($last_reset, $reset_time)) {
		pc_reset_daily_counters($state);
		$state['last_reset'] = $current_time;
		
		// NEW v1.4.45: Cleanup old logs (90-day retention policy)
		// Runs once per day during daily reset to prevent disk space issues
		pc_cleanup_old_logs(90);
		
		// CRITICAL: After usage reset, only unblock TIME-LIMIT-based blocks
		// WHY: Schedule-based blocks should remain active (e.g., bedtime 20:00-06:30)
		// If we clear ALL blocks, devices in active schedules will be unblocked briefly
		// Solution: Keep schedule-based blocks, remove only time-limit-based blocks
		if (isset($state['blocked_devices']) && is_array($state['blocked_devices'])) {
			$kept_blocks = [];
			foreach ($state['blocked_devices'] as $mac => $block_info) {
				$reason = $block_info['reason'] ?? '';
				// Keep blocks with schedule-related reasons
				if (stripos($reason, 'schedule') !== false || stripos($reason, 'bedtime') !== false) {
					$kept_blocks[$mac] = $block_info;
					pc_log("Keeping schedule-based block during reset", 'debug', array(
						'device.mac' => $mac,
						'block.reason' => $reason
					));
				}
			}
			$state['blocked_devices'] = $kept_blocks;
			
			if (count($kept_blocks) > 0) {
				pc_log("Usage reset: kept " . count($kept_blocks) . " schedule-based blocks active", 'info', array(
					'event.action' => 'usage_reset',
					'blocks.kept' => count($kept_blocks),
					'blocks.removed' => count($state['blocked_devices']) - count($kept_blocks)
				));
			}
		}
	}
		
	// PERFORMANCE OPTIMIZATION v1.4.28: Load service IPs and state table once
	// Instead of querying pfctl separately for each device (N × M calls),
	// we load all data once and pass to each device check (M + 1 calls total)
	// Example: 5 devices × 3 services = 15 calls → 4 calls (74% reduction)
	$service_ips_cache = pc_load_all_service_ips();
	$all_states = pc_get_all_established_states();
	
	// Update usage for active devices (now uses REAL connection tracking)
	$state_changed = pc_update_device_usage($state, $service_ips_cache, $all_states);
	
	// CRITICAL: Update monitoring table with ALL profile device IPs
	// WHY: Service monitoring rules need this table to track usage for all devices
	// NOTE: This is separate from blocking - monitoring tracks everyone, blocking only violators
	pc_update_monitor_table($state);
	
	// NEW v1.4.31: Check and enforce service-specific limits
	// Blocks devices from specific services when profile service limits are exceeded
	pc_check_profile_service_limits($state);
	
	// NEW v1.4.43: Detect and handle bot/background traffic
	// Analyzes connection patterns to identify automated traffic (iOS background refresh,
	// smart TV telemetry, etc.) that shouldn't count toward parental control limits.
	// If bot behavior is sustained for 15+ minutes, service tracking is reset for that device+service.
	pc_detect_bot_behavior($state);
	
	// SMART BLOCKING: Calculate which devices should be blocked NOW
	// Then compare with previous state and only update changed devices
	// This avoids calling filter_configure() which causes AQM errors
	$currently_blocked = isset($state['blocked_devices']) ? $state['blocked_devices'] : [];
	$should_be_blocked = pc_calculate_blocked_devices($state);
	
	// Apply firewall changes only for devices whose state changed
	$changes_applied = pc_apply_smart_firewall_changes($currently_blocked, $should_be_blocked, $state);
	
	// Update state with new blocked devices list
	$state['blocked_devices'] = $should_be_blocked;
	$state['last_check'] = $current_time;
	pc_save_state($state);
	
	$execution_time = round((microtime(true) - $start_time) * 1000, 2);
	
	// v1.4.28: Enhanced performance telemetry
	pc_log("Cron job completed successfully", 'info', array(
		'event.action' => 'cron_job_complete',
		'execution.duration.ms' => $execution_time,
		'execution.duration.seconds' => round($execution_time / 1000, 2),
		'devices.processed' => $tracked_count,
		'devices.blocked' => count($should_be_blocked),
		'firewall.changes' => $changes_applied,
		'event.outcome' => 'success',
		'performance.optimized' => true,
		'optimization.version' => '1.4.28'
	));
		
	} catch (Exception $e) {
		$error_msg = "Cron job execution failed: " . $e->getMessage();
		pc_log($error_msg, 'error', array(
			'event.action' => 'cron_job_failed',
			'event.category' => 'system',
			'event.outcome' => 'failure',
			'error.type' => get_class($e),
			'error.message' => $e->getMessage(),
			'error.file' => $e->getFile(),
			'error.line' => $e->getLine(),
			'error.stack_trace' => $e->getTraceAsString()
		));
		log_error("Parental Control: Cron job failed - " . $e->getMessage());
		// Don't re-throw - cron will try again in next cycle
	} finally {
		// Always release PID lock
		pc_release_pid_lock();
	}
}

/**
 * Check if device has an active parent override
 * 
 * Checks if a device has been granted temporary access via parent override
 * password. Returns true if override is active (not expired).
 * 
 * @param string $mac MAC address of device (will be normalized)
 * @param array $state State array containing overrides
 * @return bool True if device has active override, false otherwise
 * @since 0.3.3
 * 
 * @example
 * if (pc_has_active_override($mac, $state)) {
 *     // Skip blocking this device
 * }
 */
function pc_has_active_override($mac, $state) {
	$mac = pc_normalize_mac($mac);
	
	if (!isset($state['overrides']) || !is_array($state['overrides'])) {
		return false;
	}
	
	if (!isset($state['overrides'][$mac])) {
		return false;
	}
	
	$override = $state['overrides'][$mac];
	
	// Check if override has expired
	if ($override['until'] <= time()) {
		// Clean up expired override
		unset($state['overrides'][$mac]);
		pc_save_state($state);
		return false;
	}
	
	return true;
}

/**
 * Check if daily counters should be reset based on configured reset time
 * 
 * Compares last reset timestamp with today's configured reset time to determine
 * if a new day has begun (relative to the reset time, not midnight). This allows
 * parents to set a "day boundary" at a logical time like 6:00 AM instead of midnight.
 * 
 * Returns true if:
 * - Last reset was before today's reset time AND
 * - Current time is past today's reset time
 * 
 * @param int $last_reset Unix timestamp of last counter reset
 * @param string $reset_time Time of day to reset in HH:MM format (default: "00:00")
 * @return bool True if counters should be reset, false otherwise
 * @since 0.1.0
 * 
 * @example
 * // Reset time is 06:00, last reset was yesterday at 23:00, now is 07:00
 * if (pc_should_reset_counters($last_reset, '06:00')) {
 *     pc_reset_daily_counters($state);
 * }
 * 
 * @see pc_reset_daily_counters() For actual counter reset
 */
function pc_should_reset_counters($last_reset, $reset_time) {
	$now = time();
	$today_reset = strtotime("today " . str_replace(':', ':', $reset_time));
	
	// CRITICAL FIX: Proper daily reset boundary check
	// WHY: We need to reset exactly once per day at the configured time
	// 
	// The challenge: Determine if we've crossed a reset boundary since last reset
	// - Can't just compare timestamps (breaks with manual resets after reset time)
	// - Need to check if we've entered a NEW reset period
	//
	// Solution: Check if last reset was in a PREVIOUS reset period
	// - If now >= today's reset time, check if last reset < today's reset time
	// - If now < today's reset time, check if last reset < yesterday's reset time
	//
	// This ensures:
	// 1. Reset runs once per day at configured time
	// 2. Manual resets after the daily reset don't trigger another reset
	// 3. Works with any reset time (midnight, 6am, etc.)
	
	if ($now >= $today_reset) {
		// We're currently past today's reset time
		// Should reset if last reset was before today's reset time
		// (could be yesterday, or earlier today before the reset time)
		return ($last_reset < $today_reset);
	} else {
		// We haven't reached today's reset time yet
		// Should reset if last reset was before YESTERDAY's reset time
		// (this handles the case where it's currently 3am, reset time is 6am,
		// and last reset was yesterday before 6am)
		$yesterday_reset = strtotime("yesterday " . str_replace(':', ':', $reset_time));
		return ($last_reset < $yesterday_reset);
	}
}

/**
 * Reset daily usage counters for all devices
 * 
 * Sets usage_today to 0 for all devices in state. Called when reset time is
 * reached (typically midnight or configured reset time). Weekly counters are
 * reset separately on configured day of week. Modifies state by reference.
 * 
 * IMPORTANT: Resets BOTH devices (MAC-based) and devices_by_ip (IP-based) arrays
 * to maintain backward compatibility and support both tracking methods.
 * 
 * @param array &$state State array containing devices and usage data (modified by reference)
 * @return void State is modified in-place
 * @since 0.1.0
 * @updated 0.3.2 Fixed to reset devices_by_ip (IP-based tracking)
 * 
 * @example
 * $state = pc_load_state();
 * if (pc_should_reset_counters($state['last_reset'], '00:00')) {
 *     pc_reset_daily_counters($state);
 *     $state['last_reset'] = time();
 *     pc_save_state($state);
 * }
 * 
 * @see pc_should_reset_counters() For checking if reset is needed
 * @see parental_control_cron_job() For automatic reset scheduling
 */
function pc_reset_daily_counters(&$state) {
	$reset_count = 0;
	$profile_reset_count = 0;
	
	// Reset MAC-based tracking (legacy, for backward compatibility)
	if (isset($state['devices']) && is_array($state['devices'])) {
		foreach ($state['devices'] as $mac => &$device_state) {
			$device_state['usage_today'] = 0;
			$reset_count++;
		}
		unset($device_state); // Break reference
	}
	
	// Reset IP-based tracking (current method - Layer 3)
	if (isset($state['devices_by_ip']) && is_array($state['devices_by_ip'])) {
		foreach ($state['devices_by_ip'] as $ip => &$device_state) {
			$device_state['usage_today'] = 0;
			
			// NEW v1.4.22: Reset per-service usage
			if (isset($device_state['service_usage']) && is_array($device_state['service_usage'])) {
				foreach ($device_state['service_usage'] as $service_name => &$service_data) {
					$service_data['usage_today'] = 0;
					// Keep usage_week, last_seen, and connections
				}
				unset($service_data); // Break reference
			}
			
			$reset_count++;
		}
		unset($device_state); // Break reference
	}
	
	// CRITICAL: Reset PROFILE counters (shared time accounting)
	// WHY: Profiles track cumulative time across all devices
	// This must be reset at midnight for daily limits to work correctly
	if (isset($state['profiles']) && is_array($state['profiles'])) {
		foreach ($state['profiles'] as $profile_name => &$profile_state) {
			$profile_state['usage_today'] = 0;
			$profile_state['last_reset'] = time();
			
			// NEW v1.4.31: Reset profile service usage
			if (isset($profile_state['service_usage']) && is_array($profile_state['service_usage'])) {
				foreach ($profile_state['service_usage'] as $service_name => &$service_data) {
					$service_data['usage_today'] = 0;
					// Keep usage_week and last_seen
				}
				unset($service_data); // Break reference
			}
			
			$profile_reset_count++;
		}
		unset($profile_state); // Break reference
	}
	
	// NEW v1.4.31: Clear all service-specific block tables
	// WHY: Service blocks should be lifted at midnight when usage counters reset
	// Get all service aliases to know which tables to clear
	$aliases = config_get_path('aliases/alias', array());
	$services_cleared = 0;
	
	if (is_array($aliases)) {
		foreach ($aliases as $alias) {
			if (isset($alias['name']) && strpos($alias['name'], 'PC_Service_') === 0) {
				$service_name = str_replace('PC_Service_', '', $alias['name']);
				$service_name_lower = strtolower($service_name);
				
				if (pc_clear_service_table($service_name_lower)) {
					$services_cleared++;
				}
			}
		}
	}
	
	pc_log("Daily usage counters reset", 'info', array(
		'event.action' => 'daily_reset',
		'event.category' => 'system',
		'devices.reset' => $reset_count,
		'profiles.reset' => $profile_reset_count,
		'services.cleared' => $services_cleared,
		'reset.type' => 'daily'
	));
}

/**
 * Cleanup old log files (90-day retention policy)
 * 
 * Removes log files older than the specified retention period to prevent disk
 * space issues. Runs automatically as part of the daily reset process.
 * 
 * Logs cleaned up:
 * - parental_control-*.jsonl (daily logs older than 90 days)
 * - parental_control_captive.log.*.gz (rotated captive logs older than 90 days)
 * - multi_service_limiter*.jsonl (old experimental logs)
 * 
 * NEW v1.4.45: Automatic log rotation and cleanup
 * 
 * @param int $retention_days Number of days to retain logs (default: 90)
 * @return array Statistics about cleaned files
 * @since 1.4.45
 * 
 * @example
 * // Called automatically during daily reset
 * $stats = pc_cleanup_old_logs(90);
 * 
 * @see pc_reset_daily_counters() For daily reset integration
 */
function pc_cleanup_old_logs($retention_days = 90) {
	$log_dir = '/var/log';
	$cutoff_time = time() - ($retention_days * 86400); // 90 days in seconds
	$stats = array(
		'files_deleted' => 0,
		'space_freed' => 0,
		'errors' => 0
	);
	
	// Pattern 1: Daily parental control logs (parental_control-YYYY-MM-DD.jsonl)
	$daily_logs = glob("{$log_dir}/parental_control-*.jsonl");
	if (is_array($daily_logs)) {
		foreach ($daily_logs as $log_file) {
			$mtime = @filemtime($log_file);
			if ($mtime && $mtime < $cutoff_time) {
				$size = @filesize($log_file);
				if (@unlink($log_file)) {
					$stats['files_deleted']++;
					$stats['space_freed'] += $size;
					pc_log("Deleted old log: " . basename($log_file), 'info', array(
						'event.action' => 'log_cleanup',
						'file' => basename($log_file),
						'age_days' => round((time() - $mtime) / 86400),
						'size' => $size
					));
				} else {
					$stats['errors']++;
				}
			}
		}
	}
	
	// Pattern 2: Rotated captive portal logs (parental_control_captive.log.TIMESTAMP.gz)
	$captive_logs = glob("{$log_dir}/parental_control_captive.log.*.gz");
	if (is_array($captive_logs)) {
		foreach ($captive_logs as $log_file) {
			$mtime = @filemtime($log_file);
			if ($mtime && $mtime < $cutoff_time) {
				$size = @filesize($log_file);
				if (@unlink($log_file)) {
					$stats['files_deleted']++;
					$stats['space_freed'] += $size;
					pc_log("Deleted old captive log: " . basename($log_file), 'info', array(
						'event.action' => 'log_cleanup',
						'file' => basename($log_file),
						'age_days' => round((time() - $mtime) / 86400),
						'size' => $size
					));
				} else {
					$stats['errors']++;
				}
			}
		}
	}
	
	// Pattern 3: Old multi-service limiter logs (can be cleaned immediately - obsolete)
	$msl_logs = glob("{$log_dir}/multi_service_limiter*.jsonl");
	if (is_array($msl_logs)) {
		foreach ($msl_logs as $log_file) {
			$size = @filesize($log_file);
			if (@unlink($log_file)) {
				$stats['files_deleted']++;
				$stats['space_freed'] += $size;
				pc_log("Deleted obsolete multi-service limiter log: " . basename($log_file), 'info', array(
					'event.action' => 'log_cleanup',
					'file' => basename($log_file),
					'reason' => 'obsolete',
					'size' => $size
				));
			} else {
				$stats['errors']++;
			}
		}
	}
	
	if ($stats['files_deleted'] > 0) {
		$space_freed_mb = round($stats['space_freed'] / 1024 / 1024, 2);
		pc_log("Log cleanup completed", 'info', array(
			'event.action' => 'log_cleanup_complete',
			'files.deleted' => $stats['files_deleted'],
			'space.freed.bytes' => $stats['space_freed'],
			'space.freed.mb' => $space_freed_mb,
			'retention.days' => $retention_days,
			'errors' => $stats['errors']
		));
	}
	
	return $stats;
}

/**
 * Acquire PID lock to prevent concurrent cron executions
 * 
 * Creates a PID file to ensure only one instance of the cron job runs at a time.
 * If another instance is running, this function returns false and the current
 * execution should exit gracefully. This prevents race conditions and database
 * corruption from multiple simultaneous state file writes.
 * 
 * @return bool True if lock acquired successfully, false if another instance is running
 * @since 0.2.0
 * 
 * @example
 * if (!pc_acquire_pid_lock()) {
 *     pc_log("Another instance is running, exiting", 'info');
 *     return;
 * }
 * 
 * @see pc_release_pid_lock() To release the lock when done
 */
function pc_acquire_pid_lock() {
	$pid_file = PC_PID_FILE;
	$current_pid = getmypid();
	$fp = null;
	
	try {
		// CRITICAL FIX v1.4.18: Check for stale locks (kernel panic prevention)
		// If lock file is older than 10 minutes, assume process crashed and force cleanup
		if (file_exists($pid_file)) {
			$lock_age = time() - filemtime($pid_file);
			if ($lock_age > 600) {  // 10 minutes
				// Read old PID
				$old_pid = @file_get_contents($pid_file);
				$old_pid = trim($old_pid);
				
				// Check if process still exists
				$process_exists = !empty($old_pid) && posix_kill($old_pid, 0);
				
				if (!$process_exists || $lock_age > 1800) {  // Process dead OR lock > 30 min
					pc_log("Detected stale lock (age: {$lock_age}s, pid: $old_pid), forcing cleanup", 'warn', array(
						'event.action' => 'stale_lock_cleanup',
						'lock.age.seconds' => $lock_age,
						'process.pid' => $old_pid,
						'process.exists' => $process_exists
					));
					
					// Force cleanup
					@unlink($pid_file);
					
					// Kill the stuck process if it still exists
					if ($process_exists && !empty($old_pid)) {
						posix_kill($old_pid, SIGTERM);
						sleep(1);
						if (posix_kill($old_pid, 0)) {
							posix_kill($old_pid, SIGKILL);
						}
						pc_log("Killed stuck process: $old_pid", 'warn');
					}
				}
			}
		}
		
		// Try to open PID file with exclusive non-blocking lock
		$fp = @fopen($pid_file, 'c+');
		if ($fp === false) {
			pc_log("Failed to open PID file", 'error', array(
				'event.action' => 'lock_open_failed',
				'file.path' => $pid_file,
				'event.outcome' => 'failure'
			));
			return false;
		}
		
		// Try to acquire exclusive lock (non-blocking)
		if (!flock($fp, LOCK_EX | LOCK_NB)) {
			// Lock failed - another process has it
			// Read the PID of the lock holder for logging
			rewind($fp);
			$old_pid = trim(fgets($fp));
			
			pc_log("Another instance is already running (PID: $old_pid)", 'warn', array(
				'event.action' => 'lock_exists',
				'process.pid' => $old_pid,
				'event.outcome' => 'failure'
			));
			
			return false;  // Will trigger finally{} cleanup
		}
		
		// We have the lock! Write our PID to the file
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, $current_pid . "\n");
		fflush($fp);
		
		// Store file handle in global variable so it stays open (and locked)
		// CRITICAL v1.4.18: Track both the handle and acquisition state
		global $pc_pid_lock_handle, $pc_pid_lock_acquired;
		$pc_pid_lock_handle = $fp;
		$pc_pid_lock_acquired = true;
		
		pc_log("PID lock acquired", 'debug', array(
			'event.action' => 'lock_acquired',
			'process.pid' => $current_pid
		));
		
		return true;
		
	} catch (Exception $e) {
		pc_log("Exception during PID lock acquisition: " . $e->getMessage(), 'error');
		return false;
		
	} finally {
		// CRITICAL FIX v1.4.18: Guaranteed cleanup on failure
		// If we failed to acquire lock, ensure file descriptor is closed
		global $pc_pid_lock_acquired;
		if (!isset($pc_pid_lock_acquired) || !$pc_pid_lock_acquired) {
			if (isset($fp) && is_resource($fp)) {
				@flock($fp, LOCK_UN);
				@fclose($fp);
			}
		}
	}
}

/**
 * Release PID lock
 * 
 * Removes the PID file to allow future cron executions. Should be called
 * at the end of cron job execution or in a shutdown handler to ensure
 * lock is always released even if script exits unexpectedly.
 * 
 * @return void
 * @since 0.2.0
 * 
 * @example
 * register_shutdown_function('pc_release_pid_lock');
 * 
 * @see pc_acquire_pid_lock() To acquire the lock
 */
function pc_release_pid_lock() {
	global $pc_pid_lock_handle, $pc_pid_lock_acquired;
	
	// CRITICAL FIX v1.4.18: Only release if we actually acquired the lock
	// Prevents double-free errors and exceptions
	if (!isset($pc_pid_lock_acquired) || !$pc_pid_lock_acquired) {
		return;  // Lock was never acquired, nothing to release
	}
	
	// Close the file handle (which automatically releases the flock)
	if (isset($pc_pid_lock_handle) && is_resource($pc_pid_lock_handle)) {
		@flock($pc_pid_lock_handle, LOCK_UN);
		@fclose($pc_pid_lock_handle);
		$pc_pid_lock_handle = null;
		
		pc_log("PID lock released", 'debug', array(
			'event.action' => 'lock_released',
			'process.pid' => getmypid()
		));
	}
	
	// Clean up PID file
	$pid_file = PC_PID_FILE;
	if (file_exists($pid_file)) {
		@unlink($pid_file);
	}
	
	// Mark as released
	$pc_pid_lock_acquired = false;
}

/**
 * Safe pfctl command execution wrapper
 * 
 * CRITICAL v1.4.18: Kernel panic prevention wrapper for pfctl commands
 * Adds safety checks before executing pfctl to prevent kernel panics:
 * - Validates command syntax
 * - Adds timeout (5 seconds default)
 * - Logs all executions
 * - Catches and logs errors
 * - Prevents malicious command injection
 * 
 * @param string $pfctl_args The pfctl arguments (e.g., "-t table -T add 192.168.1.1")
 * @param int $timeout Maximum execution time in seconds (default: 5)
 * @return array ['success' => bool, 'output' => array, 'return_code' => int]
 * @since 1.4.18
 * 
 * @example
 * $result = pc_safe_pfctl("-t parental_control_blocked -T add 192.168.1.100");
 * if (!$result['success']) {
 *     pc_log("Failed to add IP to table", 'error');
 * }
 */
function pc_safe_pfctl($pfctl_args, $timeout = 5) {
	// CRITICAL: Validate pfctl_args to prevent command injection
	// Only allow: alphanumeric, dash, underscore, dot, slash, space, colon
	if (!preg_match('/^[a-zA-Z0-9\s\-_\.\/\:]+$/', $pfctl_args)) {
		pc_log("SECURITY: Rejected invalid pfctl command", 'error', array(
			'event.action' => 'pfctl_rejected',
			'reason' => 'invalid_characters',
			'args' => substr($pfctl_args, 0, 100)
		));
		return array('success' => false, 'output' => array(), 'return_code' => 255);
	}
	
	// Build safe command with timeout
	$timeout = max(1, min($timeout, 30));  // Clamp between 1-30 seconds
	$cmd = "/usr/bin/timeout {$timeout} /sbin/pfctl {$pfctl_args} 2>&1";
	
	// Execute with logging
	$output = array();
	$return_code = 0;
	
	$start = microtime(true);
	exec($cmd, $output, $return_code);
	$duration = round((microtime(true) - $start) * 1000, 2);
	
	$success = ($return_code === 0);
	
	// Log execution
	pc_log(($success ? "pfctl executed successfully" : "pfctl execution failed"), 
		($success ? 'debug' : 'warn'), array(
		'event.action' => 'pfctl_execute',
		'pfctl.args' => $pfctl_args,
		'execution.duration.ms' => $duration,
		'return_code' => $return_code,
		'event.outcome' => ($success ? 'success' : 'failure')
	));
	
	// Check for timeout
	if ($return_code === 124) {
		pc_log("pfctl command timed out after {$timeout} seconds", 'error', array(
			'event.action' => 'pfctl_timeout',
			'pfctl.args' => $pfctl_args,
			'timeout.seconds' => $timeout
		));
	}
	
	return array(
		'success' => $success,
		'output' => $output,
		'return_code' => $return_code,
		'duration_ms' => $duration
	);
}

/**
 * Get IP address for a given MAC address
 * 
 * Searches ARP table and DHCP leases to find the current IP address
 * associated with a MAC address. Returns null if no IP found.
 * Uses caching to avoid repeated lookups within the same execution.
 * 
 * @param string $mac MAC address in format aa:bb:cc:dd:ee:ff
 * @return string|null IP address or null if not found
 * @since 0.2.0
 * 
 * @example
 * $ip = pc_get_ip_from_mac('aa:bb:cc:dd:ee:ff');
 * if ($ip) {
 *     echo "Device is at $ip";
 * }
 * 
 * @see pc_normalize_mac() For MAC address normalization
 */
function pc_get_ip_from_mac($mac) {
	$mac = pc_normalize_mac($mac);
	static $mac_to_ip_cache = array();
	
	// Return cached result if available
	if (isset($mac_to_ip_cache[$mac])) {
		return $mac_to_ip_cache[$mac];
	}
	
	// Try ARP table first
	try {
		$arp_table = system_get_arp_table();
		if (is_array($arp_table)) {
			foreach ($arp_table as $entry) {
				if (isset($entry['mac-address']) && 
					pc_normalize_mac($entry['mac-address']) == $mac) {
					$ip = $entry['ip-address'];
					$mac_to_ip_cache[$mac] = $ip;
					return $ip;
				}
			}
		}
	} catch (Exception $e) {
		pc_log("Failed to get ARP table: " . $e->getMessage(), 'warn');
	}
	
	// Try DHCP leases
	$dhcp_leases = pc_get_dhcp_leases();
	foreach ($dhcp_leases as $lease) {
		if (pc_normalize_mac($lease['mac']) == $mac) {
			$ip = $lease['ip'];
			$mac_to_ip_cache[$mac] = $ip;
			return $ip;
		}
	}
	
	// Not found
	$mac_to_ip_cache[$mac] = null;
	return null;
}

/**
 * Check if device has active connections in pfSense state table
 * 
 * Queries pfSense firewall state table to determine if a device (by IP)
 * has any active (ESTABLISHED) connections. This is the ground truth for
 * actual internet usage vs just being present on the network.
 * 
 * WHY: ARP presence != internet usage. A device can be on network but idle.
 * Only devices with active connections should have usage incremented.
 * 
 * @param string $ip IP address to check for active connections
 * @return int Number of active (ESTABLISHED) connections
 * @since 0.2.0
 * 
 * @example
 * $ip = pc_get_ip_from_mac($mac);
 * if (pc_has_active_connections($ip) > 0) {
 *     // Device is actively using internet
 *     pc_increment_usage($mac);
 * }
 * 
 * @see pc_update_device_usage() Which uses this function
 */
/**
 * Check if an IP address is private/local (RFC 1918, RFC 4193, etc.)
 * 
 * Returns true if the IP is in any of these ranges:
 * - 10.0.0.0/8 (RFC 1918 - Private)
 * - 172.16.0.0/12 (RFC 1918 - Private)
 * - 192.168.0.0/16 (RFC 1918 - Private)
 * - 127.0.0.0/8 (Loopback)
 * - 169.254.0.0/16 (Link-local)
 * - 224.0.0.0/4 (Multicast)
 * - 255.255.255.255 (Broadcast)
 * - fc00::/7 (IPv6 Unique Local)
 * - fe80::/10 (IPv6 Link-local)
 * 
 * @param string $ip IP address to check
 * @return bool True if private/local, false if public
 * @since 1.4.7
 */
function pc_is_private_ip($ip) {
	if (empty($ip)) {
		return true;
	}
	
	// IPv6 check
	if (strpos($ip, ':') !== false) {
		// IPv6 Unique Local (fc00::/7)
		if (preg_match('/^f[cd][0-9a-f]{2}:/i', $ip)) {
			return true;
		}
		// IPv6 Link-local (fe80::/10)
		if (preg_match('/^fe[89ab][0-9a-f]:/i', $ip)) {
			return true;
		}
		// IPv6 Loopback (::1)
		if ($ip === '::1') {
			return true;
		}
		return false; // Other IPv6 addresses are considered public
	}
	
	// IPv4 checks
	$long_ip = ip2long($ip);
	if ($long_ip === false) {
		return true; // Invalid IP, treat as private
	}
	
	// Check private ranges
	$private_ranges = array(
		array('10.0.0.0', '10.255.255.255'),       // 10.0.0.0/8
		array('172.16.0.0', '172.31.255.255'),     // 172.16.0.0/12
		array('192.168.0.0', '192.168.255.255'),   // 192.168.0.0/16
		array('127.0.0.0', '127.255.255.255'),     // 127.0.0.0/8 (Loopback)
		array('169.254.0.0', '169.254.255.255'),   // 169.254.0.0/16 (Link-local)
		array('224.0.0.0', '239.255.255.255'),     // 224.0.0.0/4 (Multicast)
	);
	
	foreach ($private_ranges as $range) {
		$start = ip2long($range[0]);
		$end = ip2long($range[1]);
		if ($long_ip >= $start && $long_ip <= $end) {
			return true;
		}
	}
	
	// Check broadcast
	if ($ip === '255.255.255.255') {
		return true;
	}
	
	return false; // Public IP
}

function pc_has_active_connections($ip) {
	if (empty($ip)) {
		return 0;
	}
	
	// Query pfSense state table for this IP
	// Timeout after 2 seconds to prevent hanging
	// Only count ESTABLISHED connections (not SYN, FIN, etc.)
	$escaped_ip = escapeshellarg($ip);
	exec("/usr/bin/timeout 2 /sbin/pfctl -s state 2>/dev/null | /usr/bin/grep ESTABLISHED | /usr/bin/grep $escaped_ip", $output, $return_code);
	
	if ($return_code !== 0 && $return_code !== 1) {
		// Command failed (timeout = 124, or other error)
		pc_log("Failed to query state table for $ip (exit code: $return_code)", 'warn', array(
			'event.action' => 'state_table_query_failed',
			'client.address' => $ip,
			'exit.code' => $return_code
		));
		return 0;
	}
	
	// CRITICAL FIX v1.4.7: Only count connections to PUBLIC IPs (external internet)
	// WHY: Local LAN traffic (192.168.x.x -> 192.168.x.x) shouldn't count as "internet usage"
	// Examples of what NOT to count:
	// - Device talking to local NAS/printer (192.168.1.100 -> 192.168.1.50)
	// - DHCP broadcasts (-> 255.255.255.255)
	// - mDNS/SSDP discovery (-> 224.0.0.251)
	// - Link-local traffic (-> 169.254.x.x)
	//
	// State table format: "all tcp 192.168.1.100:54321 -> 8.8.8.8:443 ESTABLISHED:ESTABLISHED"
	// NOTE: Each connection appears TWICE in pfSense state table (with and without NAT view)
	$unique_connections = array();
	
	if (is_array($output)) {
		foreach ($output as $line) {
			// Parse state line to extract source and destination IPs + ports
			// Format examples:
			// 1. "all tcp 192.168.1.100:54321 -> 8.8.8.8:443 ESTABLISHED:ESTABLISHED"
			// 2. "all tcp 203.191.182.168:12345 (192.168.1.100:54321) -> 8.8.8.8:443 ESTABLISHED:ESTABLISHED" (NAT)
			
			$src_ip = null;
			$src_port = null;
			$dst_ip = null;
			$dst_port = null;
			
			// First try to match NAT format (with parentheses)
			if (preg_match('/\((\d+\.\d+\.\d+\.\d+):(\d+)\)\s+->\s+(\d+\.\d+\.\d+\.\d+):(\d+)/', $line, $matches)) {
				// NAT format: extract IP from inside parentheses
				$src_ip = $matches[1];
				$src_port = $matches[2];
				$dst_ip = $matches[3];
				$dst_port = $matches[4];
			}
			// Otherwise try standard format (no NAT)
			elseif (preg_match('/(\d+\.\d+\.\d+\.\d+):(\d+)\s+->\s+(\d+\.\d+\.\d+\.\d+):(\d+)/', $line, $matches)) {
				// Standard format
				$src_ip = $matches[1];
				$src_port = $matches[2];
				$dst_ip = $matches[3];
				$dst_port = $matches[4];
			}
			
			// Only count if source is our device AND destination is a PUBLIC IP
			if ($src_ip === $ip && !pc_is_private_ip($dst_ip)) {
				// Use unique key to avoid counting duplicate NAT entries
				$connection_key = "{$src_ip}:{$src_port}->{$dst_ip}:{$dst_port}";
				$unique_connections[$connection_key] = true;
			}
		}
	}
	
	return count($unique_connections);
}

/**
 * Check if IP matches IP address or CIDR range
 * 
 * @param string $ip IP address to check
 * @param string $range IP address or CIDR range (e.g., "157.240.1.0/24")
 * @return bool True if IP is in range
 * @since 1.4.22
 */
function pc_ip_matches_range($ip, $range) {
	$range = trim($range);
	
	// Simple IP match
	if ($ip === $range) {
		return true;
	}
	
	// CIDR range check
	if (strpos($range, '/') !== false) {
		list($subnet, $bits) = explode('/', $range);
		$ip_long = ip2long($ip);
		$subnet_long = ip2long($subnet);
		
		if ($ip_long === false || $subnet_long === false) {
			return false;
		}
		
		$mask = -1 << (32 - (int)$bits);
		$subnet_long &= $mask; // Apply mask to subnet
		
		return ($ip_long & $mask) == $subnet_long;
	}
	
	return false;
}

/**
 * Get service-specific connection counts for a device
 * 
 * Checks pfSense state table for connections from device IP to service-specific IP ranges.
 * Returns array of service names and their connection counts.
 * 
 * @param string $device_ip Device IP address
 * @return array Array of ['service_name' => connection_count]
 * @since 1.4.22
 */

/**
 * Load all service IPs once for cron job performance optimization
 * 
 * Queries pfctl tables for all PC_Service_ aliases in a single pass.
 * Returns a mapping of service names to their IP arrays for reuse
 * across multiple device checks.
 * 
 * PERFORMANCE: Reduces N device × M service pfctl calls to M pfctl calls
 * Example: 5 devices × 3 services = 15 calls → 3 calls (5x faster)
 * 
 * @return array Service name => IP array mapping
 * @since 1.4.28
 * 
 * @example
 * $service_ips_cache = pc_load_all_service_ips();
 * // Returns: ['Discord' => ['1.2.3.4', '5.6.7.8'], 'YouTube' => [...]]
 * 
 * @see pc_get_service_connections() Which uses this cache
 * @see parental_control_cron_job() Which calls this once per execution
 */
function pc_load_all_service_ips() {
	$service_ips_cache = array();
	$aliases = config_get_path('aliases/alias', array());
	
	if (!is_array($aliases)) {
		return $service_ips_cache;
	}
	
	foreach ($aliases as $alias) {
		if (isset($alias['name']) && strpos($alias['name'], 'PC_Service_') === 0) {
			$service_name = str_replace('PC_Service_', '', $alias['name']);
			$alias_name = $alias['name'];
			
			// Query this service's IP table once
			$cmd = "pfctl -t {$alias_name} -T show 2>&1";
			exec($cmd, $output, $return_var);
			
			if ($return_var === 0 && !empty($output)) {
				$service_ips_cache[$service_name] = $output;
			} else {
				// Empty array for services with no IPs (prevents errors)
				$service_ips_cache[$service_name] = array();
			}
		}
	}
	
	return $service_ips_cache;
}

/**
 * Get all ESTABLISHED states once for cron job performance optimization
 * 
 * Queries pfctl state table once and returns all ESTABLISHED connections.
 * This avoids querying the state table separately for each device.
 * 
 * PERFORMANCE: Reduces N device pfctl calls to 1 pfctl call
 * Example: 5 devices = 5 calls → 1 call (5x faster)
 * 
 * On busy firewalls with 10,000+ states, this single query is much
 * faster than filtering via grep for each device.
 * 
 * @return array Array of state table lines (ESTABLISHED only)
 * @since 1.4.28
 * 
 * @example
 * $all_states = pc_get_all_established_states();
 * // Then filter in memory for each device (fast!)
 * $device_states = array_filter($all_states, fn($s) => strpos($s, $ip));
 * 
 * @see pc_get_service_connections() Which uses this cache
 * @see parental_control_cron_job() Which calls this once per execution
 */
function pc_get_all_established_states() {
	$cmd = "pfctl -ss 2>&1 | grep 'ESTABLISHED'";
	exec($cmd, $output, $return_var);
	
	if ($return_var === 0 && !empty($output)) {
		return $output;
	}
	
	return array();
}

function pc_get_service_connections($device_ip, $service_ips_cache = null, $all_states = null) {
	// PERFORMANCE OPTIMIZATION v1.4.28:
	// Accept pre-loaded caches to avoid redundant pfctl calls
	// If not provided, load them (backward compatibility for direct calls)
	
	// Load service IPs cache if not provided
	if ($service_ips_cache === null) {
		$service_ips_cache = pc_load_all_service_ips();
	}
	
	if (empty($service_ips_cache)) {
		return array();
	}
	
	// Load state table if not provided
	if ($all_states === null) {
		$all_states = pc_get_all_established_states();
	}
	
	if (empty($all_states)) {
		return array(); // No connections
	}
	
	// Filter states for this device (in memory - fast!)
	// Format with NAT: "all tcp NAT_IP:port (DEVICE_IP:port) -> DEST_IP:port ESTABLISHED:ESTABLISHED"
	$device_states = array_filter($all_states, function($line) use ($device_ip) {
		return strpos($line, "({$device_ip}:") !== false;
	});
	
	if (empty($device_states)) {
		return array(); // No connections for this device
	}
	
	// Count connections per service
	$service_connections = array();
	foreach ($service_ips_cache as $service_name => $ip_list) {
		$service_connections[$service_name] = 0;
	}
	
	// Process filtered device states
	foreach ($device_states as $line) {
		// Parse NAT state line to extract device IP and destination IP
		// Format: "all tcp NAT_IP:port (192.168.1.110:12345) -> 157.240.1.1:443 ESTABLISHED:ESTABLISHED"
		// Format: "all tcp NAT_IP:port (192.168.1.110:12345) (157.240.1.1:443) <-> 157.240.1.1:443 ESTABLISHED:ESTABLISHED"
		$escaped_ip = preg_quote($device_ip, '/');
		if (preg_match('/\((' . $escaped_ip . '):(\d+)\)\s+(?:\([\d.]+:\d+\)\s+)?(?:->|<->)\s+(\d+\.\d+\.\d+\.\d+):(\d+)/', $line, $matches)) {
			$device_ip_matched = $matches[1];
			$dst_ip = $matches[3];
			
			// Check which service this destination IP belongs to
			foreach ($service_ips_cache as $service_name => $ip_list) {
				foreach ($ip_list as $service_ip_or_range) {
					if (pc_ip_matches_range($dst_ip, $service_ip_or_range)) {
						$service_connections[$service_name]++;
						break 2; // Connection counted, move to next line
					}
				}
			}
		}
	}
	
	return $service_connections;
}

/**
 * Update device usage tracking using real connection tracking
 * 
 * For each configured device, checks for ACTIVE connections in pfSense state table.
 * Only devices with active connections have their usage incremented. This provides
 * accurate usage tracking based on actual internet usage, not just network presence.
 * 
 * CRITICAL CHANGE (v0.2.0): Now uses pfctl state table instead of ARP table.
 * Previous version tracked presence; this version tracks actual usage.
 * 
 * @param array &$state State array containing devices and usage data (modified by reference)
 * @return void State is modified in-place with updated usage counters
 * @since 0.1.0
 * @updated 0.2.0 Complete rewrite with real connection tracking
 * 
 * @example
 * $state = pc_load_state();
 * pc_update_device_usage($state);
 * pc_save_state($state);
 * 
 * @see pc_has_active_connections() For connection detection
 * @see parental_control_cron_job() For periodic usage updates
 */
function pc_update_device_usage(&$state, $service_ips_cache = null, $all_states = null) {
	$devices = pc_get_devices(); // Use helper function (DRY)
	$interval_seconds = PC_CRON_INTERVAL_SECONDS; // Use constant (typically 60 seconds)
	$interval_minutes = ceil($interval_seconds / 60);
	$tracked_count = 0;
	
	// CRITICAL: Ensure devices_by_ip exists (Layer 3 architecture)
	if (!isset($state['devices_by_ip'])) {
		$state['devices_by_ip'] = array();
	}
	if (!isset($state['mac_to_ip_cache'])) {
		$state['mac_to_ip_cache'] = array();
	}
	
	foreach ($devices as $device) {
		// Skip disabled devices using helper function (DRY)
		if (!pc_is_device_enabled($device)) {
			continue;
		}
		
		$mac = pc_normalize_mac($device['mac_address']);
		$device_name = isset($device['device_name']) ? $device['device_name'] : $mac;
		
		// CRITICAL: Get CURRENT IP address (may change due to DHCP)
		$ip = pc_get_ip_from_mac($mac);
		
		if (empty($ip)) {
			// Device offline - check if we had previous IP
			if (isset($state['mac_to_ip_cache'][$mac])) {
				$old_ip = $state['mac_to_ip_cache'][$mac];
				if (isset($state['devices_by_ip'][$old_ip])) {
					$state['devices_by_ip'][$old_ip]['offline_since'] = time();
				}
			}
			pc_log("Device $mac ($device_name) offline (no IP address)", 'debug', array(
				'event.action' => 'device_offline',
				'device.mac' => $mac,
				'device.name' => $device_name
			));
			continue;
		}
		
		// CRITICAL: Handle IP changes (DHCP renewals)
		if (isset($state['mac_to_ip_cache'][$mac])) {
			$old_ip = $state['mac_to_ip_cache'][$mac];
			if ($old_ip != $ip && isset($state['devices_by_ip'][$old_ip])) {
				// IP changed - migrate state to new IP
				pc_log("Device $mac ($device_name) IP changed: $old_ip → $ip", 'info', array(
					'event.action' => 'ip_changed',
					'device.mac' => $mac,
					'device.name' => $device_name,
					'client.address.old' => $old_ip,
					'client.address.new' => $ip
				));
				
				// Migrate all usage data to new IP
				$state['devices_by_ip'][$ip] = $state['devices_by_ip'][$old_ip];
				$state['devices_by_ip'][$ip]['ip'] = $ip; // Update IP field
				unset($state['devices_by_ip'][$old_ip]);
				
				// TODO: Update firewall rules (old IP → new IP)
				// This will be implemented in v0.3.0 with pfSense tables
			}
		}
		
		// Update MAC → IP cache
		$state['mac_to_ip_cache'][$mac] = $ip;
		
		// CRITICAL: Initialize device state BY IP (Layer 3)
		if (!isset($state['devices_by_ip'][$ip])) {
			$state['devices_by_ip'][$ip] = array(
				'mac' => $mac,
				'name' => $device_name,
				'ip' => $ip,
				'usage_today' => 0,
				'usage_week' => 0,
				'last_seen' => 0,
				'connections_last_check' => 0,
				'first_seen' => time()
			);
		}
		
		// Update device info (may have changed in config)
		$state['devices_by_ip'][$ip]['mac'] = $mac;
		$state['devices_by_ip'][$ip]['name'] = $device_name;
		$state['devices_by_ip'][$ip]['ip'] = $ip;
		unset($state['devices_by_ip'][$ip]['offline_since']); // Clear offline flag
		
		// CRITICAL: Check if device should be blocked (for devices coming online)
		// WHY: When offline devices come back online, they need to be blocked immediately
		// if their profile has exceeded time limits or they're in a blocked schedule
		$device_came_online = !isset($state['blocked_devices'][$mac]);
		if ($device_came_online) {
			// Check if this device should be blocked
			$should_block = false;
			$block_reason = '';
			
			// Check schedule
			if (pc_is_in_blocked_schedule($device)) {
				$should_block = true;
				$block_reason = 'Scheduled block time';
			}
			
			// Check time limit
			if (!$should_block && pc_is_time_limit_exceeded($device, $state)) {
				$should_block = true;
				$block_reason = 'Time limit exceeded';
			}
			
			// Block immediately if needed
			if ($should_block) {
				pc_log("Device $device_name came online and should be blocked - blocking now", 'info', array(
					'event.action' => 'device_online_blocked',
					'device.mac' => $mac,
					'device.name' => $device_name,
					'client.address' => $ip,
					'block.reason' => $block_reason
				));
				
				$pc_config = config_get_path('installedpackages/parentalcontrol/config/0', []);
				$enforcement_mode = isset($pc_config['enforcement_mode']) ? $pc_config['enforcement_mode'] : 'strict';
				pc_add_device_block_table($device, $enforcement_mode, $block_reason, $state);
			}
		}
		
		// CRITICAL: Check for REAL active INTERNET connections BY IP (Layer 3)
		// v1.4.7: Now only counts connections to PUBLIC IPs (external internet)
		// Local LAN traffic (e.g., to NAS, printer) is NOT counted
		$internet_connection_count = pc_has_active_connections($ip);
		
		// Store connection count for diagnostics
		$state['devices_by_ip'][$ip]['connections_last_check'] = $internet_connection_count;
		$state['devices_by_ip'][$ip]['internet_connections'] = $internet_connection_count;
		
		if ($internet_connection_count > 0) {
			// CRITICAL: Don't track usage during blocked schedule time!
			// WHY: If device is in a blocked schedule (e.g., bedtime), usage should not accumulate
			// even if the device manages to have connections (firewall might be delayed in blocking)
			$in_blocked_schedule = pc_is_in_blocked_schedule($device);
			
			if ($in_blocked_schedule) {
				pc_log("Device $device_name ($mac @ $ip) has internet traffic but in blocked schedule - NOT tracking usage", 'info', array(
					'event.action' => 'usage_skipped_schedule',
					'device.mac' => $mac,
					'device.name' => $device_name,
					'client.address' => $ip,
					'internet_connections' => $internet_connection_count,
					'reason' => 'in_blocked_schedule'
				));
				continue; // Skip to next device
			}
			
		// Device has active connections and NOT in blocked schedule - increment usage
		$state['devices_by_ip'][$ip]['usage_today'] += $interval_minutes;
		$state['devices_by_ip'][$ip]['usage_week'] += $interval_minutes;
		$state['devices_by_ip'][$ip]['last_seen'] = time();
		$tracked_count++;
		
		// NEW v1.4.22: Track per-service usage
		// v1.4.28: Pass pre-loaded caches for performance
		$service_connections = pc_get_service_connections($ip, $service_ips_cache, $all_states);
		if (!empty($service_connections)) {
			// Initialize service usage tracking
			if (!isset($state['devices_by_ip'][$ip]['service_usage'])) {
				$state['devices_by_ip'][$ip]['service_usage'] = array();
			}
			
			foreach ($service_connections as $service_name => $conn_count) {
				if ($conn_count > 0) {
					// Initialize service if needed
					if (!isset($state['devices_by_ip'][$ip]['service_usage'][$service_name])) {
						$state['devices_by_ip'][$ip]['service_usage'][$service_name] = array(
							'usage_today' => 0,
							'usage_week' => 0,
							'last_seen' => 0,
							'connections' => 0,
							// NEW v1.4.43: Bot detection tracking
							'connection_history' => array(), // Last 15 samples of connection counts
							'bot_score' => 0, // 0-15: number of consecutive bot-like samples
							'bot_detected_at' => 0, // Timestamp when bot first detected
							'is_bot' => false // Whether currently flagged as bot traffic
						);
					}
					
					// NEW v1.4.43: Bot detection - track connection history
					// Store last 15 minutes of connection counts (sliding window)
					if (!isset($state['devices_by_ip'][$ip]['service_usage'][$service_name]['connection_history'])) {
						$state['devices_by_ip'][$ip]['service_usage'][$service_name]['connection_history'] = array();
					}
					$state['devices_by_ip'][$ip]['service_usage'][$service_name]['connection_history'][] = $conn_count;
					
					// Keep only last 15 samples (15 minutes with 1-minute interval)
					if (count($state['devices_by_ip'][$ip]['service_usage'][$service_name]['connection_history']) > 15) {
						array_shift($state['devices_by_ip'][$ip]['service_usage'][$service_name]['connection_history']);
					}
					
					// Add interval minutes to service usage (ONLY if not flagged as bot)
					$is_bot = isset($state['devices_by_ip'][$ip]['service_usage'][$service_name]['is_bot']) && 
					          $state['devices_by_ip'][$ip]['service_usage'][$service_name]['is_bot'];
					
					if (!$is_bot) {
						$state['devices_by_ip'][$ip]['service_usage'][$service_name]['usage_today'] += $interval_minutes;
						$state['devices_by_ip'][$ip]['service_usage'][$service_name]['usage_week'] += $interval_minutes;
					} else {
						pc_log("Skipping usage tracking for $device_name @ $ip - $service_name (bot traffic detected)", 'debug', array(
							'event.action' => 'bot_traffic_skipped',
							'device.name' => $device_name,
							'client.address' => $ip,
							'service.name' => $service_name,
							'connections' => $conn_count
						));
					}
					
					$state['devices_by_ip'][$ip]['service_usage'][$service_name]['last_seen'] = time();
					$state['devices_by_ip'][$ip]['service_usage'][$service_name]['connections'] = $conn_count;
				}
			}
		}
		
		// CRITICAL: SHARED PROFILE TIME ACCOUNTING
		// WHY: Time limits are per PROFILE, not per device
		// All devices in a profile share the same time budget
		// Example: 4 hour limit = 4 hours TOTAL across ALL devices
		$profile_name = isset($device['profile_name']) ? $device['profile_name'] : null;
		if ($profile_name) {
		// Initialize profile tracking if needed
		// CRITICAL FIX v1.4.17: Set last_reset to today's midnight for proper alignment
		if (!isset($state['profiles'])) {
			$state['profiles'] = array();
		}
		if (!isset($state['profiles'][$profile_name])) {
			$state['profiles'][$profile_name] = array(
				'usage_today' => 0,
				'usage_week' => 0,
				'last_reset' => pc_get_today_midnight()
			);
		}
		
		// NEW v1.4.31: Aggregate service usage to profile level
		// WHY: Service limits are per PROFILE (like general time limits)
		// All devices in a profile share the same service time budget
		if (!empty($service_connections)) {
			// Initialize profile service usage tracking if needed
			if (!isset($state['profiles'][$profile_name]['service_usage'])) {
				$state['profiles'][$profile_name]['service_usage'] = array();
			}
			
			foreach ($service_connections as $service_name => $conn_count) {
				if ($conn_count > 0) {
					// Initialize profile service if needed
					if (!isset($state['profiles'][$profile_name]['service_usage'][$service_name])) {
						$state['profiles'][$profile_name]['service_usage'][$service_name] = array(
							'usage_today' => 0,
							'usage_week' => 0,
							'last_seen' => 0
						);
					}
					
					// Add interval minutes to profile's service usage
					$state['profiles'][$profile_name]['service_usage'][$service_name]['usage_today'] += $interval_minutes;
					$state['profiles'][$profile_name]['service_usage'][$service_name]['usage_week'] += $interval_minutes;
					$state['profiles'][$profile_name]['service_usage'][$service_name]['last_seen'] = time();
				}
			}
		}
				
				// Add usage to PROFILE counter (shared across all devices)
				$state['profiles'][$profile_name]['usage_today'] += $interval_minutes;
				$state['profiles'][$profile_name]['usage_week'] += $interval_minutes;
				$state['profiles'][$profile_name]['last_update'] = time();
				
				$profile_usage_today = $state['profiles'][$profile_name]['usage_today'];
			} else {
				$profile_usage_today = 0;
			}
			
			pc_log("Device $device_name ($mac @ $ip) using internet: added $interval_minutes minutes", 'info', array(
				'event.action' => 'usage_tracked',
				'device.mac' => $mac,
				'device.name' => $device_name,
				'client.address' => $ip,
				'profile.name' => $profile_name,
				'usage.minutes' => $interval_minutes,
				'usage.device_today' => $state['devices_by_ip'][$ip]['usage_today'],
				'usage.profile_today' => $profile_usage_today,
				'internet_connections' => $internet_connection_count,
				'event.outcome' => 'success'
			));
			
			// Check if PROFILE exceeded limits (not individual device)
			if ($profile_name && isset($device['daily_limit']) && $device['daily_limit'] > 0) {
				if ($profile_usage_today >= $device['daily_limit']) {
					pc_log("Profile $profile_name exceeded daily limit", 'warn', array(
						'event.action' => 'profile_limit_exceeded',
						'profile.name' => $profile_name,
						'device.triggered_by' => $device_name,
						'device.mac' => $mac,
						'client.address' => $ip,
						'usage.profile_today' => $profile_usage_today,
						'usage.limit' => $device['daily_limit']
					));
				}
			}
		} else {
			pc_log("Device $device_name ($mac @ $ip) idle: no internet connections (may have local LAN traffic only)", 'debug', array(
				'event.action' => 'device_idle',
				'device.mac' => $mac,
				'device.name' => $device_name,
				'client.address' => $ip,
				'internet_connections' => 0
			));
		}
	}
	
	pc_log("Usage update completed: tracked $tracked_count active devices", 'info', array(
		'event.action' => 'usage_update_complete',
		'devices.tracked' => $tracked_count,
		'devices.total' => count($devices),
		'devices_by_ip' => count($state['devices_by_ip'])
	));
}

/**
 * Detect bot-like behavior in service connections
 * 
 * Analyzes connection patterns for each device+service combination to identify
 * automated/background traffic vs. human usage. Background processes (iOS app refresh,
 * smart TV telemetry, etc.) often maintain persistent low-level connections that
 * inflate usage time inappropriately.
 * 
 * Detection criteria:
 * - Low connection count (1-5 connections sustained over time)
 * - Low variance (consistent pattern, not varied like human browsing)
 * - Sustained duration (15+ consecutive minutes of bot-like pattern)
 * 
 * When bot behavior is detected for 15 consecutive minutes, the service tracking
 * is reset for that specific device+service combination only.
 * 
 * @param array &$state State array with device and service tracking (modified by reference)
 * @return void State is modified in-place with bot detection flags
 * @since 1.4.43
 * 
 * @example
 * // Called from cron job after usage update
 * pc_update_device_usage($state);
 * pc_detect_bot_behavior($state); // Analyzes patterns and resets if needed
 * pc_save_state($state);
 */
function pc_detect_bot_behavior(&$state) {
	// Configuration thresholds
	$BOT_MAX_CONNECTIONS = 5;         // Max connections to be considered "low activity"
	$BOT_MIN_SAMPLES = 15;            // Minimum samples needed (15 minutes)
	$BOT_CONSECUTIVE_THRESHOLD = 15;  // Consecutive bot-like samples before action
	$BOT_MAX_VARIANCE = 2.0;          // Max variance in connection count (very consistent = bot)
	
	$bot_detected_count = 0;
	$bot_reset_count = 0;
	
	if (!isset($state['devices_by_ip'])) {
		return; // No devices to check
	}
	
	foreach ($state['devices_by_ip'] as $ip => &$device_state) {
		if (!isset($device_state['service_usage']) || empty($device_state['service_usage'])) {
			continue; // No service usage to analyze
		}
		
		foreach ($device_state['service_usage'] as $service_name => &$service_state) {
			// Need enough samples to make a determination
			if (!isset($service_state['connection_history']) || 
			    count($service_state['connection_history']) < $BOT_MIN_SAMPLES) {
				continue; // Not enough data yet
			}
			
			$history = $service_state['connection_history'];
			$sample_count = count($history);
			
			// Calculate statistics
			$avg = array_sum($history) / $sample_count;
			$variance = 0;
			foreach ($history as $val) {
				$variance += pow($val - $avg, 2);
			}
			$variance = $variance / $sample_count;
			$std_dev = sqrt($variance);
			
			// Determine if pattern is bot-like
			$is_low_activity = ($avg <= $BOT_MAX_CONNECTIONS);
			$is_consistent = ($variance <= $BOT_MAX_VARIANCE);
			$is_sustained = ($sample_count >= $BOT_MIN_SAMPLES);
			
			$is_bot_pattern = ($is_low_activity && $is_consistent && $is_sustained);
			
			// Update bot score (consecutive count of bot-like behavior)
			if ($is_bot_pattern) {
				if (!isset($service_state['bot_score'])) {
					$service_state['bot_score'] = 0;
				}
				$service_state['bot_score']++;
				
				// Mark timestamp when first detected
				if ($service_state['bot_score'] == 1) {
					$service_state['bot_detected_at'] = time();
				}
				
				// If sustained for threshold duration, take action
				if ($service_state['bot_score'] >= $BOT_CONSECUTIVE_THRESHOLD) {
					if (!isset($service_state['is_bot']) || !$service_state['is_bot']) {
						// First time flagging as bot - PAUSE tracking (preserve accumulated usage)
						// CRITICAL v1.4.44: Usage accumulated BEFORE bot detection is preserved!
						$device_name = isset($device_state['name']) ? $device_state['name'] : $ip;
						
						pc_log("Bot behavior detected for {$device_name} @ {$ip} - {$service_name}", 'notice', array(
							'event.action' => 'bot_detected',
							'device.name' => $device_name,
							'client.address' => $ip,
							'service.name' => $service_name,
							'avg_connections' => round($avg, 2),
							'variance' => round($variance, 2),
							'samples' => $sample_count,
							'duration_minutes' => $service_state['bot_score'],
							'note' => 'Tracking paused, existing usage preserved'
						));
						
						// Pause service tracking for this device+service (preserves existing usage)
						pc_reset_service_tracking($state, $ip, $service_name, 'bot_detected');
						
						$service_state['is_bot'] = true;
						$bot_detected_count++;
						$bot_reset_count++;
					}
				}
			} else {
				// Pattern is NOT bot-like - reset score if it was previously flagged
				if (isset($service_state['bot_score']) && $service_state['bot_score'] > 0) {
					$device_name = isset($device_state['name']) ? $device_state['name'] : $ip;
					
					pc_log("Human activity resumed for {$device_name} @ {$ip} - {$service_name}", 'info', array(
						'event.action' => 'human_activity_resumed',
						'device.name' => $device_name,
						'client.address' => $ip,
						'service.name' => $service_name,
						'previous_bot_score' => $service_state['bot_score'],
						'current_avg_connections' => round($avg, 2)
					));
					
					$service_state['bot_score'] = 0;
					$service_state['is_bot'] = false;
					$service_state['bot_detected_at'] = 0;
				}
			}
		}
	}
	
	if ($bot_detected_count > 0 || $bot_reset_count > 0) {
		pc_log("Bot detection completed: {$bot_detected_count} new detections, {$bot_reset_count} paused", 'info', array(
			'event.action' => 'bot_detection_complete',
			'bot.new_detections' => $bot_detected_count,
			'bot.paused' => $bot_reset_count,
			'note' => 'Tracking paused (usage preserved), will resume when human activity detected'
		));
	}
}

/**
 * Pause service tracking for a specific device and service (bot detected)
 * 
 * CRITICAL v1.4.44: This function does NOT reset usage counters!
 * It only PAUSES tracking by clearing connection history and keeping is_bot flag.
 * 
 * WHY: When bot traffic is detected AFTER legitimate human usage, we must preserve
 * the usage that was already accumulated. Only future tracking is paused.
 * 
 * BEHAVIOR:
 * - User watches YouTube for 60 minutes → usage_today = 60
 * - User leaves, bot detected after 15 minutes → is_bot = true
 * - Bot traffic continues: usage_today STAYS at 60 (tracking paused)
 * - User returns and watches 30 more minutes → usage_today = 90 (tracking resumed)
 * 
 * IMPORTANT: Only pauses the specific service for the specific device - does NOT
 * affect other services or other devices.
 * 
 * @param array &$state State array with tracking data (modified by reference)
 * @param string $ip Device IP address
 * @param string $service_name Service name (e.g., "YouTube", "Facebook")
 * @param string $reason Reason for pause (e.g., "bot_detected", "manual")
 * @return bool True if pause was performed, false otherwise
 * @since 1.4.43
 * @updated 1.4.44 Changed from "reset" to "pause" - preserves accumulated usage
 * 
 * @example
 * // Pause YouTube tracking for a specific device (preserves existing usage)
 * pc_reset_service_tracking($state, '192.168.1.110', 'YouTube', 'bot_detected');
 */
function pc_reset_service_tracking(&$state, $ip, $service_name, $reason = 'manual') {
	$device_name = isset($state['devices_by_ip'][$ip]['name']) ? 
	               $state['devices_by_ip'][$ip]['name'] : $ip;
	
	// CRITICAL v1.4.44: PAUSE tracking, do NOT reset usage counters
	// WHY: Legitimate usage before bot detection must be preserved
	if (isset($state['devices_by_ip'][$ip]['service_usage'][$service_name])) {
		$current_usage = $state['devices_by_ip'][$ip]['service_usage'][$service_name]['usage_today'];
		
		// ONLY clear connection history and metadata (not usage!)
		// usage_today and usage_week are PRESERVED
		$state['devices_by_ip'][$ip]['service_usage'][$service_name]['connections'] = 0;
		$state['devices_by_ip'][$ip]['service_usage'][$service_name]['connection_history'] = array();
		// Keep: usage_today, usage_week, bot_score, bot_detected_at, is_bot
		
		pc_log("Paused service tracking (bot): {$device_name} @ {$ip} - {$service_name} (reason: {$reason})", 'notice', array(
			'event.action' => 'service_tracking_paused',
			'device.name' => $device_name,
			'client.address' => $ip,
			'service.name' => $service_name,
			'pause.reason' => $reason,
			'usage.preserved' => $current_usage,
			'note' => 'Usage counters preserved, tracking paused until human activity resumes'
		));
	}
	
	// Remove IP from service-specific blocking table if present
	// This ensures the device can continue to access the service while bot traffic is ignored
	$service_alias_name = 'pc_blocked_' . strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $service_name));
	$blocked_ips = pc_get_service_blocked_ips($service_alias_name);
	
	if (in_array($ip, $blocked_ips)) {
		pc_remove_ip_from_service_table($service_alias_name, $ip);
		
		pc_log("Removed {$ip} from {$service_alias_name} table (bot traffic)", 'debug', array(
			'event.action' => 'ip_removed_from_service_table',
			'client.address' => $ip,
			'service.name' => $service_name,
			'table.name' => $service_alias_name
		));
	}
	
	return true;
}

/**
 * Check if device is currently online on the network
 * 
 * Uses ARP table (system_get_arp_table) to detect if device with specified
 * MAC address is active. Returns true if found in ARP cache, false otherwise.
 * This method is reliable for local network devices but may not detect devices
 * that haven't communicated recently (ARP cache expires after ~20 min on FreeBSD).
 * 
 * @param string $mac MAC address in format aa:bb:cc:dd:ee:ff (case insensitive)
 * @return bool True if device is online, false if offline or ARP unavailable
 * @since 0.1.0
 * @updated 0.1.3 Using system_get_arp_table() instead of shell exec
 * 
 * @example
 * if (pc_is_device_online('aa:bb:cc:dd:ee:ff')) {
 *     pc_update_device_usage($mac, $state);
 * }
 * 
 * @see pc_get_dhcp_leases() For DHCP-based device detection
 * @see pc_update_device_usage() For usage tracking
 */
function pc_is_device_online($mac) {
	// WHY: Normalize MAC address to lowercase and trim whitespace
	// Reason: MAC addresses can be entered in various formats (AA:BB:CC or aa:bb:cc)
	// and ARP table may use different case. Normalization ensures reliable matching.
	// Trim removes accidental whitespace from config/form submissions.
	$mac = pc_normalize_mac($mac);
	
	// PRIORITY 1: Check ARP table (fast, real-time network presence)
	// v1.4.7 FIX: Changed from state file to ARP table as primary check
	// WHY: Online status should be based on network presence, not internet connections
	// A device can be online (on network) but not using internet (only local traffic)
	// 
	// REMOVED: State file connection count check (was using 'internet_connections' which is 0 for local-only traffic)
	// NOW: ARP table is source of truth for "Is device on the network?"
	// 
	// system_get_arp_table() can fail on some systems or during heavy load
	// If ARP fails, we fallback to DHCP leases
	try {
		$arp_table = system_get_arp_table();
		
		if (is_array($arp_table)) {
			foreach ($arp_table as $entry) {
				if (isset($entry['mac-address']) && strtolower($entry['mac-address']) == $mac) {
					return true;
				}
			}
		}
	} catch (Exception $e) {
		pc_log("ARP table lookup failed, falling back to DHCP leases: " . $e->getMessage(), 'debug', array(
			'event.action' => 'arp_lookup_failed',
			'device.mac' => $mac,
			'fallback.method' => 'dhcp_leases'
		));
	}
	
	// FALLBACK: Check DHCP leases as alternative method
	// WHY: If ARP fails, DHCP leases can still show recently active devices
	// Trade-off: Less real-time but better than no tracking at all
	try {
		$leases = system_get_dhcpleases();
		if (isset($leases['lease']) && is_array($leases['lease'])) {
			foreach ($leases['lease'] as $lease) {
				if (isset($lease['mac']) && strtolower($lease['mac']) == $mac) {
					// Check if lease is recent (within last 5 minutes)
					if (isset($lease['end']) && $lease['end'] > (time() - 300)) {
						return true;
					}
				}
			}
		}
	} catch (Exception $e) {
		pc_log("DHCP lease lookup also failed: " . $e->getMessage(), 'warning', array(
			'event.action' => 'dhcp_lookup_failed',
			'device.mac' => $mac
		));
	}
	
	// If both methods fail, conservatively return false
	// WHY: Better to under-track than over-track usage
	return false;
}

/**
 * ========================================================================
 * CONSOLE LOGGING ENHANCEMENTS
 * ========================================================================
 */

/**
 * Enhanced CLI logging with emoji support
 * 
 * Provides formatted console output with emoji icons for better readability
 * when running scripts in terminal. Only outputs to console when running in
 * CLI mode (php_sapi_name() === 'cli'). Also logs to file via pc_log().
 * 
 * @param string $message The message to log
 * @param string $level Log level: debug, info, success, warning, error
 * @param array $attributes Optional attributes for structured logging
 * @return void
 * @since 0.1.3
 * 
 * @example
 * pc_cli_log("Starting synchronization", 'info');
 * pc_cli_log("Firewall rules updated", 'success');
 * pc_cli_log("Warning: ARP table unavailable", 'warning');
 * pc_cli_log("Error: Failed to save state", 'error');
 */
function pc_cli_log($message, $level = 'info', $attributes = array()) {
	// Emoji mapping for different log levels
	// Using clear, universally-supported emojis for terminal compatibility
	$emoji = array(
		'debug' => '🔍',
		'info' => 'ℹ️ ',
		'success' => '✅',
		'warning' => '⚠️ ',
		'error' => '❌'
	);
	
	$symbol = isset($emoji[$level]) ? $emoji[$level] : 'ℹ️ ';
	
	// Only output to console if running in CLI mode
	// This prevents output when running via web interface
	if (php_sapi_name() === 'cli') {
		echo $symbol . ' ' . $message . "\n";
	}
	
	// Map 'success' level to 'info' for file logging
	// (file logging only supports: debug, info, warning, error)
	$file_log_level = ($level === 'success') ? 'info' : $level;
	
	// Also log to file for permanent record
	pc_log($message, $file_log_level, $attributes);
}

/**
 * ========================================================================
 * LOG ROTATION FUNCTIONS
 * ========================================================================
 */

/**
 * Get current log file with automatic rotation
 * 
 * Implements automatic log rotation when files reach PC_MAX_LOG_SIZE.
 * Creates dated log files with sequential numbering when size limit reached.
 * 
 * @return string Path to current log file
 * @since 0.1.3
 * 
 * @example
 * /var/log/parental_control-2025-12-25.jsonl
 * /var/log/parental_control-2025-12-25-1.jsonl
 * /var/log/parental_control-2025-12-25-2.jsonl
 */
function pc_get_current_log_file() {
	$log_dir = dirname(PC_LOG_FILE);
	
	// GRACEFUL DEGRADATION: Ensure log directory exists
	// WHY: Log directory might not exist on fresh install or after system restore
	if (!is_dir($log_dir)) {
		@mkdir($log_dir, 0755, true);
	}
	
	$date = date('Y-m-d');
	$base_name = $log_dir . '/' . PC_LOG_PREFIX . '-' . $date;
	
	$file_index = 0;
	while (true) {
		// Build filename with optional index suffix
		$suffix = ($file_index === 0) ? '' : '-' . $file_index;
		$log_file = $base_name . $suffix . '.jsonl';
		
		// Create file if it doesn't exist
		if (!file_exists($log_file)) {
			@touch($log_file);
			@chmod($log_file, 0644);
			return $log_file;
		}
		
		// Check if current file is below size limit
		// We use < instead of <= to allow some headroom
		if (filesize($log_file) < PC_MAX_LOG_SIZE) {
			return $log_file;
		}
		
		// Current file is full, try next index
		$file_index++;
		
		// Safety check: prevent infinite loop (max 100 files per day)
		if ($file_index > 100) {
			// If we somehow hit 100 files in one day, just use the last one
			// This shouldn't happen in normal operation
			return $log_file;
		}
	}
}

/**
 * Clean up old log files
 * 
 * Removes old log files keeping only the most recent PC_MAX_LOG_FILES.
 * Called periodically (1% chance on each log write) to avoid overhead.
 * 
 * This function sorts log files by modification time and deletes the oldest
 * ones, ensuring we don't accumulate too many log files over time.
 * 
 * @return int Number of files deleted
 * @since 0.1.3
 */
function pc_cleanup_old_logs() {
	$log_dir = dirname(PC_LOG_FILE);
	$log_pattern = $log_dir . '/' . PC_LOG_PREFIX . '-*.jsonl';
	$log_files = glob($log_pattern);
	
	if (!is_array($log_files) || count($log_files) <= PC_MAX_LOG_FILES) {
		return 0; // Nothing to clean up
	}
	
	// Sort by modification time (oldest first)
	// We use a custom sort to handle the case where filemtime might fail
	usort($log_files, function($a, $b) {
		$time_a = @filemtime($a);
		$time_b = @filemtime($b);
		if ($time_a === false) return 1;  // Move failed files to end
		if ($time_b === false) return -1;
		return $time_a - $time_b;
	});
	
	// Calculate how many files to delete
	$files_to_delete_count = count($log_files) - PC_MAX_LOG_FILES;
	$files_to_delete = array_slice($log_files, 0, $files_to_delete_count);
	
	$deleted = 0;
	foreach ($files_to_delete as $file) {
		if (@unlink($file)) {
			$deleted++;
		}
	}
	
	if ($deleted > 0) {
		pc_log("Cleaned up {$deleted} old log files", 'debug', array(
			'event.action' => 'log_cleanup',
			'files_deleted' => $deleted,
			'files_remaining' => count($log_files) - $deleted
		));
	}
	
	return $deleted;
}

/**
 * Logging function - OpenTelemetry compliant format with automatic rotation
 * 
 * Logs messages in OpenTelemetry-compliant JSON Lines format with automatic
 * log rotation when files reach size limit. Each log entry is a complete JSON
 * object on a single line for efficient parsing and streaming.
 * 
 * @param string $message The log message (Body)
 * @param string $level Severity level: debug, info, warning, error
 * @param array $attributes Additional structured attributes for SIEM/log analysis
 * @return void
 * @since 0.1.0
 * @updated 0.1.3 Added automatic log rotation
 * 
 * @example
 * pc_log("Device blocked", 'info', array(
 *     'device.mac' => 'aa:bb:cc:dd:ee:ff',
 *     'child.name' => 'Emma',
 *     'reason' => 'Time limit exceeded'
 * ));
 */
function pc_log($message, $level = 'info', $attributes = array()) {
	global $config;
	
	// Check if logging is enabled
	// WHY: Default to enabled (opt-out) instead of disabled (opt-in)
	// Design Decision: Logging is essential for troubleshooting, so enable by default
	// User can explicitly disable if desired, but most users want logs
	$pc_config = config_get_path('installedpackages/parentalcontrol/config/0', array());
	$logging_enabled = !isset($pc_config['enable_logging']) || $pc_config['enable_logging'] === 'on';
	
	// GRACEFUL DEGRADATION: If file logging disabled, fall back to syslog for critical messages
	// WHY: Critical errors (error level) should always be logged somewhere for debugging
	if (!$logging_enabled) {
		if ($level === 'error' || $level === 'warning') {
			// Fallback to pfSense syslog for critical messages
			log_error("[Parental Control] {$level}: {$message}");
		}
		return;
	}
	
	$log_level = config_get_path('installedpackages/parentalcontrol/config/0/log_level', 'info');
	$levels = array('debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3);
	$severity_numbers = array('debug' => 5, 'info' => 9, 'warning' => 13, 'error' => 17);
	
	if ($levels[$level] < $levels[$log_level]) {
		return;
	}
	
	// OpenTelemetry Log Record structure
	$log_record = array(
		// Timestamp in RFC3339/ISO8601 format with microseconds
		'Timestamp' => gmdate('Y-m-d\TH:i:s') . '.' . substr(microtime(), 2, 6) . 'Z',
		
		// ObservedTimestamp (when log was observed/recorded)
		'ObservedTimestamp' => gmdate('Y-m-d\TH:i:s') . '.' . substr(microtime(), 2, 6) . 'Z',
		
		// Severity
		'SeverityText' => strtoupper($level),
		'SeverityNumber' => $severity_numbers[$level],
		
		// Body (the log message)
		'Body' => $message,
		
		// Resource attributes (describes the source)
		'Resource' => array(
			'service.name' => 'pfsense-parental-control',
			'service.version' => PC_VERSION,
			'service.instance.id' => gethostname(),
			'deployment.environment' => 'production'
		),
		
		// Log attributes (structured data)
		'Attributes' => array_merge(
			array(
				'log.file.name' => basename(PC_LOG_FILE),
				'log.file.path' => PC_LOG_FILE,
				'process.pid' => getmypid(),
				'host.name' => gethostname()
			),
			$attributes  // Merge user-provided attributes
		)
	);
	
	// Add trace context if available (for distributed tracing)
	if (isset($attributes['trace_id']) && isset($attributes['span_id'])) {
		$log_record['TraceId'] = $attributes['trace_id'];
		$log_record['SpanId'] = $attributes['span_id'];
		$log_record['TraceFlags'] = isset($attributes['trace_flags']) ? $attributes['trace_flags'] : '01';
	}
	
	// Get current log file (with automatic rotation if needed)
	$current_log = pc_get_current_log_file();
	
	// Write JSON log entry (one line per entry for easy parsing)
	// GRACEFUL DEGRADATION: If file write fails, fall back to syslog
	try {
		$json_log = json_encode($log_record, JSON_UNESCAPED_SLASHES) . "\n";
		$result = @file_put_contents($current_log, $json_log, FILE_APPEND);
		
		if ($result === false) {
			// File write failed - fallback to syslog
			log_error("[Parental Control] Failed to write to log file. Message: {$message}");
		}
	} catch (Exception $e) {
		// Fallback to syslog if logging completely fails
		log_error("[Parental Control] Logging error: " . $e->getMessage() . ". Original message: {$message}");
	}
	
	// Periodically clean up old log files (1% chance per log write)
	// This ensures cleanup happens without adding overhead to every log operation
	if (rand(1, 100) === 1) {
		pc_cleanup_old_logs();
	}
	
	// Also write human-readable format to system log for important messages
	if ($level == 'warning' || $level == 'error') {
		$human_readable = "[{$level}] {$message}";
		if (!empty($attributes)) {
			$attr_str = json_encode($attributes);
			$human_readable .= " | Attributes: {$attr_str}";
		}
		log_error("Parental Control: {$human_readable}");
	}
}

/**
 * Send email notification
 */
function pc_send_notification($subject, $message) {
	// Check if email notifications are enabled
	$pc_config = config_get_path('installedpackages/parentalcontrol/config', []);
	if (!is_array($pc_config) || empty($pc_config['enable_email'])) {
		return;
	}
	
	$recipients = config_get_path('installedpackages/parentalcontrol/email_recipients', '');
	if (empty($recipients)) {
		return;
	}
	
	$emails = array_map('trim', explode(',', $recipients));
	foreach ($emails as $email) {
		if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
			send_smtp_message($message, $subject, $email);
		}
	}
}

/**
 * ========================================================================
 * PROFILE-BASED FUNCTIONS (Version 2.0 Features)
 * ========================================================================
 */

/**
 * Validate profile configuration input
 */
function parental_control_profile_validate(&$post, &$input_errors) {
	// Pre-process device_selector to populate device fields
	// WHY: pfSense rowhelper submits data as 'row' array, not 'devices'
	// We need to process this BEFORE validation to ensure fields are populated
	if (isset($post['row']) && is_array($post['row'])) {
		foreach ($post['row'] as $idx => &$device) {
			if (isset($device['device_selector']) && !empty($device['device_selector'])) {
				// Parse the device_selector value: mac|ip|hostname
				$parts = explode('|', $device['device_selector']);
				if (count($parts) >= 3) {
					// Auto-fill fields if they're empty
					if (empty($device['mac_address'])) {
						$device['mac_address'] = $parts[0];
					}
					if (empty($device['ip_address'])) {
						$device['ip_address'] = $parts[1];
					}
					if (empty($device['device_name'])) {
						$device['device_name'] = $parts[2];
					}
				}
			}
		}
		unset($device); // Break reference
	}
	
	// CRITICAL: For validation, use 'row' as 'devices' but don't modify $post
	// WHY: Validation checks 'devices', but we must NOT add 'devices' key to $post
	// because that corrupts XML. Use a local reference instead.
	$devices_to_validate = isset($post['row']) ? $post['row'] : (isset($post['devices']) ? $post['devices'] : array());
	
	// Validate profile name
	if (empty($post['name'])) {
		$input_errors[] = "Profile name is required.";
	}
	
	// Validate daily limit
	if (isset($post['daily_limit']) && $post['daily_limit'] != '') {
		if (!is_numeric($post['daily_limit']) || $post['daily_limit'] < 0) {
			$input_errors[] = "Daily limit must be a positive number.";
		}
	}
	
	// Validate weekend bonus
	if (isset($post['weekend_bonus']) && $post['weekend_bonus'] != '') {
		if (!is_numeric($post['weekend_bonus']) || $post['weekend_bonus'] < 0) {
			$input_errors[] = "Weekend bonus must be a positive number.";
		}
	}
	
	// Validate weekly limit
	if (isset($post['weekly_limit']) && $post['weekly_limit'] != '') {
		if (!is_numeric($post['weekly_limit']) || $post['weekly_limit'] < 0) {
			$input_errors[] = "Weekly limit must be a positive number.";
		}
	}
	
	// Validate devices (use local variable, don't modify $post)
	if (!empty($devices_to_validate) && is_array($devices_to_validate)) {
		foreach ($devices_to_validate as $idx => $device) {
			if (empty($device['device_name'])) {
				$input_errors[] = "Device #{$idx}: Device name is required.";
			}
			
			if (empty($device['mac_address'])) {
				$input_errors[] = "Device #{$idx}: MAC address is required.";
			} else {
				// Use helper function for validation (DRY)
				if (!pc_validate_mac($device['mac_address'])) {
					$input_errors[] = "Device #{$idx}: Invalid MAC address format.";
				}
			}
			
			if (!empty($device['ip_address']) && !is_ipaddr($device['ip_address'])) {
				$input_errors[] = "Device #{$idx}: Invalid IP address format.";
			}
		}
	}
	
	// Validate schedules
	if (isset($post['schedules']) && is_array($post['schedules'])) {
		foreach ($post['schedules'] as $idx => &$schedule) {
			if (empty($schedule['name'])) {
				$input_errors[] = "Schedule #{$idx}: Name is required.";
			}
			
			// Handle days field (can be array from multi-select or comma-separated string)
			if (empty($schedule['days'])) {
				$input_errors[] = "Schedule #{$idx}: At least one day is required.";
			} else {
				// Convert array to comma-separated string for storage
				if (is_array($schedule['days'])) {
					$schedule['days'] = implode(',', $schedule['days']);
				}
				// Validate day values
				$valid_days = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
				$selected_days = array_map('trim', explode(',', $schedule['days']));
				foreach ($selected_days as $day) {
					if (!in_array(strtolower($day), $valid_days)) {
						$input_errors[] = "Schedule #{$idx}: Invalid day value '{$day}'.";
					}
				}
			}
			
			if (empty($schedule['start_time']) || !preg_match('/^\d{2}:\d{2}$/', $schedule['start_time'])) {
				$input_errors[] = "Schedule #{$idx}: Valid start time required (HH:MM format, e.g., 08:00 or 22:30).";
			}
			
			if (empty($schedule['end_time']) || !preg_match('/^\d{2}:\d{2}$/', $schedule['end_time'])) {
				$input_errors[] = "Schedule #{$idx}: Valid end time required (HH:MM format, e.g., 17:00 or 23:45).";
			}
		}
		unset($schedule); // Break reference
	}
}

/**
 * Get available profile names for schedule dropdown
 * 
 * @return array Array of profile names
 */
function pc_get_profile_options() {
	$profiles = pc_get_profiles();
	$options = array();
	
	foreach ($profiles as $profile) {
		if (isset($profile['name']) && !empty($profile['name'])) {
			$options[] = $profile['name'];
		}
	}
	
	return $options;
}

/**
 * Discover devices from DHCP leases
 * 
 * Gets active devices from DHCP leases (same data as status_dhcp_leases.php)
 * Used for auto-populating device lists in profiles
 * 
 * NOTE: ARP table is NOT used because ARP resolution doesn't work reliably
 * in this environment. Only DHCP leases are used.
 * 
 * @return array Array of discovered devices with MAC, IP, hostname, and device_name
 * @since 0.5.1
 * @updated 0.5.3 Removed ARP table scanning, only use DHCP leases
 */
function pc_discover_devices() {
	$devices = [];
	$seen_macs = [];
	
	// Get devices from DHCP leases (same as status_dhcp_leases.php)
	$leases = system_get_dhcpleases();
	
	// Process active leases
	if (isset($leases['lease']) && is_array($leases['lease'])) {
		foreach ($leases['lease'] as $lease) {
			if (isset($lease['mac']) && !empty($lease['mac'])) {
				$mac = pc_normalize_mac($lease['mac']);
				
				// Skip if already seen or invalid
				if (isset($seen_macs[$mac]) || $mac === '00:00:00:00:00:00') {
					continue;
				}
				
				// Get lease information
				$hostname = isset($lease['hostname']) ? $lease['hostname'] : '';
				$ip = isset($lease['ip']) ? $lease['ip'] : '';
				
				// Only include leases with valid IP addresses
				if (empty($ip)) {
					continue;
				}
				
				// Generate friendly device name
				// Priority: Hostname > IP > MAC
				$device_name = $hostname;
				if (empty($device_name)) {
					$device_name = $ip;
				}
				if (empty($device_name)) {
					$device_name = strtoupper($mac);
				}
				
				$devices[] = [
					'device_name' => $device_name,
					'mac_address' => $mac,
					'ip_address' => $ip,
					'hostname' => $hostname
				];
				
				$seen_macs[$mac] = true;
			}
		}
	}
	
	// Also check for static DHCP mappings
	$config = config_get_path('dhcpd', []);
	if (is_array($config)) {
		foreach ($config as $dhcp_if => $dhcp_config) {
			if (isset($dhcp_config['staticmap']) && is_array($dhcp_config['staticmap'])) {
				foreach ($dhcp_config['staticmap'] as $static) {
					if (isset($static['mac']) && !empty($static['mac'])) {
						$mac = pc_normalize_mac($static['mac']);
						
						// Skip if already seen
						if (isset($seen_macs[$mac])) {
							continue;
						}
						
						$hostname = isset($static['hostname']) ? $static['hostname'] : '';
						$ip = isset($static['ipaddr']) ? $static['ipaddr'] : '';
						
						// Only include if has IP
						if (empty($ip)) {
							continue;
						}
						
						// Generate friendly device name
						$device_name = $hostname;
						if (empty($device_name)) {
							$device_name = $ip;
						}
						if (empty($device_name)) {
							$device_name = strtoupper($mac);
						}
						
						$devices[] = [
							'device_name' => $device_name,
							'mac_address' => $mac,
							'ip_address' => $ip,
							'hostname' => $hostname
						];
						
						$seen_macs[$mac] = true;
					}
				}
			}
		}
	}
	
	pc_log("Discovered " . count($devices) . " devices from DHCP leases", 'info', array(
		'event.action' => 'devices_discovered',
		'device.count' => count($devices),
		'source' => 'dhcp_leases_only'
	));
	
	return $devices;
}

/**
 * Validate schedule configuration input (for KACI-PC-Schedule tab)
 * 
 * @param array $post POST data from form submission
 * @param array &$input_errors Array to collect validation error messages
 * @return void
 */
function parental_control_schedule_validate(&$post, &$input_errors) {
	// Validate schedule name
	if (empty($post['name'])) {
		$input_errors[] = "Schedule name is required.";
	}
	
	// Validate profile selection (now supports multiple profiles)
	if (empty($post['profile_names'])) {
		$input_errors[] = "At least one profile must be selected.";
	} else {
		// Convert array to comma-separated string for storage
		if (is_array($post['profile_names'])) {
			$selected_profiles = $post['profile_names'];
			$post['profile_names'] = implode(',', $post['profile_names']);
		} else {
			// Already a string, split it
			$selected_profiles = array_map('trim', explode(',', $post['profile_names']));
		}
		
		// Verify all selected profiles exist
		$all_profiles = pc_get_profiles();
		$valid_profile_names = array();
		foreach ($all_profiles as $profile) {
			if (isset($profile['name'])) {
				$valid_profile_names[] = $profile['name'];
			}
		}
		
		foreach ($selected_profiles as $selected_name) {
			if (!in_array($selected_name, $valid_profile_names)) {
				$input_errors[] = "Profile '{$selected_name}' does not exist.";
			}
		}
	}
	
	// Validate days (can be array from multi-select or comma-separated string)
	if (empty($post['days'])) {
		$input_errors[] = "At least one day is required.";
	} else {
		// Convert array to comma-separated string for storage
		if (is_array($post['days'])) {
			$post['days'] = implode(',', $post['days']);
		}
		// Validate day values
		$valid_days = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
		$selected_days = array_map('trim', explode(',', $post['days']));
		foreach ($selected_days as $day) {
			if (!in_array(strtolower($day), $valid_days)) {
				$input_errors[] = "Invalid day value '{$day}'.";
			}
		}
	}
	
	// Validate start time
	if (empty($post['start_time']) || !preg_match('/^\d{2}:\d{2}$/', $post['start_time'])) {
		$input_errors[] = "Valid start time required (HH:MM format, e.g., 08:00 or 22:30).";
	}
	
	// Validate end time
	if (empty($post['end_time']) || !preg_match('/^\d{2}:\d{2}$/', $post['end_time'])) {
		$input_errors[] = "Valid end time required (HH:MM format, e.g., 17:00 or 23:45).";
	}
}

/**
 * Get profile by ID
 */
function pc_get_profile($profile_id) {
	$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config');
	if (!is_array($profiles)) {
		return null;
	}
	
	foreach ($profiles as $profile) {
		if (isset($profile['id']) && $profile['id'] == $profile_id) {
			return $profile;
		}
	}
	
	return null;
}

/**
 * Get profile for a specific MAC address
 */
function pc_get_profile_by_mac($mac) {
	$mac = pc_normalize_mac($mac);
	$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config');
	
	if (!is_array($profiles)) {
		return null;
	}
	
	foreach ($profiles as $profile) {
		if (!isset($profile['devices']) || !is_array($profile['devices'])) {
			continue;
		}
		
		foreach ($profile['devices'] as $device) {
			if (pc_normalize_mac($device['mac_address']) == $mac) {
				return $profile;
			}
		}
	}
	
	return null;
}

/**
 * Get all devices in a profile
 */
function pc_get_profile_devices($profile) {
	if (!isset($profile['devices']) || !is_array($profile['devices'])) {
		return array();
	}
	
	return $profile['devices'];
}

/**
 * Get profile usage from state file
 * 
 * @updated 1.4.17 Set last_reset to today's midnight for new profiles
 */
function pc_get_profile_usage($profile_id) {
	$state = pc_load_state();
	
	if (!isset($state['profiles'][$profile_id])) {
		// CRITICAL FIX v1.4.17: Set last_reset to today's midnight for proper alignment
		return array(
			'usage_today' => 0,
			'usage_week' => 0,
			'last_reset' => pc_get_today_midnight()
		);
	}
	
	return $state['profiles'][$profile_id];
}

/**
 * Update profile usage in state file
 * 
 * @updated 1.4.17 Set last_reset to today's midnight for new profiles
 */
function pc_update_profile_usage($profile_id, $minutes_to_add) {
	$state = pc_load_state();
	
	if (!isset($state['profiles'])) {
		$state['profiles'] = array();
	}
	
	if (!isset($state['profiles'][$profile_id])) {
		// CRITICAL FIX v1.4.17: Set last_reset to today's midnight for proper alignment
		$state['profiles'][$profile_id] = array(
			'usage_today' => 0,
			'usage_week' => 0,
			'last_reset' => pc_get_today_midnight()
		);
	}
	
	$state['profiles'][$profile_id]['usage_today'] += $minutes_to_add;
	$state['profiles'][$profile_id]['usage_week'] += $minutes_to_add;
	$state['profiles'][$profile_id]['last_update'] = time();
	
	pc_save_state($state);
}

/**
 * Check if any device in profile is online (active)
 */
function pc_is_profile_active($profile) {
	$devices = pc_get_profile_devices($profile);
	
	foreach ($devices as $device) {
		if (pc_is_device_online($device['mac_address'])) {
			return true;
		}
	}
	
	return false;
}

/**
 * Profile-based cron job (replaces device-based tracking)
 */
function pc_profile_cron_job() {
	// Check if service is enabled using helper function (DRY)
	if (!pc_is_service_enabled()) {
		return;
	}
	
	// Get the configuration
	$pc_config = config_get_path('installedpackages/parentalcontrol/config', []);
	
	$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config');
	if (!is_array($profiles)) {
		return;
	}
	
	foreach ($profiles as $profile) {
		// Skip disabled profiles
		if (empty($profile['enabled'])) {
			continue;
		}
		
		// Check if any device in profile is online
		if (!pc_is_profile_active($profile)) {
			continue;
		}
		
		// Get profile ID
		$profile_id = isset($profile['id']) ? $profile['id'] : $profile['name'];
		
		// Track usage (1 minute increment)
		pc_update_profile_usage($profile_id, 1);
		
		// Check if profile exceeded limits
		$usage = pc_get_profile_usage($profile_id);
		$daily_limit = isset($profile['daily_limit']) ? intval($profile['daily_limit']) : 0;
		
		// Add weekend bonus if applicable
		if (date('N') >= 5 && isset($profile['weekend_bonus'])) { // Friday-Sunday
			$daily_limit += intval($profile['weekend_bonus']);
		}
		
		// Check if limit exceeded
		if ($daily_limit > 0 && $usage['usage_today'] >= $daily_limit) {
			// Block all devices in profile
			pc_block_profile($profile, "Daily time limit exceeded");
			
			pc_log("Profile {$profile['name']} exceeded daily limit", 'info', array(
				'event.action' => 'profile_limit_exceeded',
				'profile.name' => $profile['name'],
				'profile.id' => $profile_id,
				'usage.today' => $usage['usage_today'],
				'usage.limit' => $daily_limit
			));
		}
		
		// Check schedules
		if (isset($profile['schedules']) && is_array($profile['schedules'])) {
			foreach ($profile['schedules'] as $schedule) {
				if (pc_is_in_schedule($schedule)) {
					pc_block_profile($profile, "Blocked by schedule: {$schedule['name']}");
				}
			}
		}
	}
}

/**
 * Block all devices in a profile
 */
function pc_block_profile($profile, $reason) {
	$devices = pc_get_profile_devices($profile);
	$enforcement_mode = config_get_path('installedpackages/parentalcontrol/enforcement_mode', 'strict');
	
	foreach ($devices as $device) {
		pc_create_block_rule($device, $enforcement_mode, $reason);
	}
}

/**
 * Migrate old device-based config to profile-based
 */
function pc_migrate_to_profiles() {
	$old_devices = config_get_path('installedpackages/parentalcontroldevices/config');
	if (!is_array($old_devices) || empty($old_devices)) {
		return; // Nothing to migrate
	}
	
	$profiles = array();
	$profile_id = 1;
	
	// Group devices by child name
	$grouped = array();
	foreach ($old_devices as $device) {
		$child_name = $device['child_name'];
		if (!isset($grouped[$child_name])) {
			$grouped[$child_name] = array();
		}
		$grouped[$child_name][] = $device;
	}
	
	// Create profiles from grouped devices
	foreach ($grouped as $child_name => $devices) {
		$profile = array(
			'id' => $profile_id++,
			'name' => $child_name,
			'description' => 'Migrated from device-based configuration',
			'daily_limit' => isset($devices[0]['daily_limit']) ? $devices[0]['daily_limit'] : '',
			'weekend_bonus' => isset($devices[0]['weekend_bonus']) ? $devices[0]['weekend_bonus'] : '',
			'enabled' => 'on',
			'devices' => array(),
			'schedules' => array()
		);
		
		// Add devices to profile
		foreach ($devices as $device) {
			$profile['devices'][] = array(
				'device_name' => $device['device_name'],
				'mac_address' => $device['mac_address'],
				'ip_address' => isset($device['ip_address']) ? $device['ip_address'] : ''
			);
			
			// Merge schedules (if any)
			if (isset($device['schedules']) && is_array($device['schedules'])) {
				foreach ($device['schedules'] as $schedule) {
					$profile['schedules'][] = $schedule;
				}
			}
		}
		
		$profiles[] = $profile;
	}
	
	// Save profiles
	config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
	write_config("Migrated to profile-based parental control");
	
	pc_log("Migrated devices to profiles", 'info', array(
		'event.action' => 'migration_complete',
		'device_count' => count($old_devices),
		'profile_count' => count($profiles)
	));
}

/**
 * Get all network devices for auto-discovery dropdown
 * 
 * Combines data from multiple sources to build comprehensive device list:
 * 1. ARP table (most reliable, shows currently active devices)
 * 2. DHCP leases (shows assigned IPs and hostnames)
 * 3. DHCP static mappings (shows configured static assignments)
 * 
 * Returns array of devices with MAC|IP|hostname format suitable for dropdown.
 * Deduplicates by MAC address, prioritizing entries with most information.
 * 
 * @return array Array of associative arrays with keys: mac, ip, hostname, display
 *               'display' key contains formatted string: "MAC|IP|hostname"
 * @since 0.1.0
 * @updated 0.1.3 Added support for system_get_arp_table()
 * 
 * @example
 * $devices = pc_get_dhcp_leases();
 * foreach ($devices as $device) {
 *     echo "<option value='{$device['display']}'>{$device['hostname']}</option>";
 * }
 * 
 * @see pc_process_profile_devices() For processing dropdown selections
 */
function pc_get_dhcp_leases() {
	// PERFORMANCE: Cache the result since ARP/DNS lookups are expensive
	// WHY: Multiple devices might be rendered in UI simultaneously, each calling this.
	// Cache for 30 seconds - network state changes slowly enough that this is safe.
	return pc_get_cached('dhcp_leases', function() use (&$config) {
		return pc_get_dhcp_leases_uncached();
	}, 30);
}

/**
 * Internal function to get DHCP leases without caching
 * 
 * WHY: Separated from pc_get_dhcp_leases() to allow caching wrapper.
 * Don't call this directly - use pc_get_dhcp_leases() instead.
 * 
 * @internal
 * @return array Array of lease information
 */
function pc_get_dhcp_leases_uncached() {
	global $config;
	
	$leases = array();
	
	// GRACEFUL DEGRADATION: Try to get devices, but don't fail if sources unavailable
	// WHY: Device auto-discovery is convenience feature, not critical for operation
	// If ARP/DHCP lookups fail, return empty array so UI still works (manual entry)
	
	try {
		// Get ARP table first (most reliable)
		$arp_table = system_get_arp_table();
		if (is_array($arp_table)) {
			foreach ($arp_table as $entry) {
				try {
					if (isset($entry['mac-address']) && isset($entry['ip-address'])) {
						$mac = strtolower(str_replace('-', ':', $entry['mac-address']));
						$ip = $entry['ip-address'];
						
						// Try to get hostname from DNS (may be slow, use timeout)
						$hostname = '';
						if (function_exists('gethostbyaddr')) {
							// WHY: @ suppresses warnings because gethostbyaddr can fail/timeout
							// FALLBACK: If DNS lookup fails, just use IP
							$resolved = @gethostbyaddr($ip);
							if ($resolved != $ip) {
								$hostname = $resolved;
							}
						}
						
						// FALLBACK: If no hostname, try DHCP static mappings
						// WHY: Static mappings are reliable and don't require DNS
						if (empty($hostname) && is_array($config['dhcpd'])) {
							foreach ($config['dhcpd'] as $dhcp_if => $dhcp_config) {
								if (isset($dhcp_config['staticmap']) && is_array($dhcp_config['staticmap'])) {
									foreach ($dhcp_config['staticmap'] as $static) {
										if (isset($static['mac']) && strtolower($static['mac']) == $mac) {
											$hostname = isset($static['hostname']) ? $static['hostname'] : '';
											break 2;
										}
									}
								}
							}
						}
						
						$leases[] = array(
							'mac' => $mac,
							'ip' => $ip,
							'hostname' => $hostname,
							'online' => true
						);
					}
				} catch (Exception $e) {
					// Continue processing other entries if one fails
					pc_log("Failed to process ARP entry: " . $e->getMessage(), 'debug');
				}
			}
		}
	} catch (Exception $e) {
		// If ARP table completely unavailable, log but continue
		// WHY: Auto-discovery failure shouldn't break manual device entry
		pc_log("Failed to get device list: " . $e->getMessage(), 'warning', array(
			'event.action' => 'device_discovery_failed',
			'error.message' => $e->getMessage(),
			'fallback.behavior' => 'manual_entry_still_available'
		));
	}
	
	// Sort by hostname/IP for easier selection
	// GRACEFUL: usort won't fail on empty array
	if (!empty($leases)) {
		usort($leases, function($a, $b) {
			$name_a = $a['hostname'] ?: $a['ip'];
			$name_b = $b['hostname'] ?: $b['ip'];
			return strcasecmp($name_a, $name_b);
		});
	}
	
	return $leases;
}

/**
 * Generate JavaScript for device auto-population
 */
function pc_generate_device_selector_js() {
	$leases = pc_get_dhcp_leases();
	
	$js = "<script type=\"text/javascript\">\n";
	$js .= "//<![CDATA[\n";
	$js .= "var dhcpDevices = " . json_encode($leases) . ";\n";
	$js .= "console.log('Loaded ' + dhcpDevices.length + ' DHCP/ARP devices');\n\n";
	
	$js .= "function initDeviceSelectors() {\n";
	$js .= "    var selects = document.querySelectorAll('select[name*=\"device_selector\"]');\n";
	$js .= "    console.log('Found ' + selects.length + ' device selector dropdowns');\n";
	$js .= "    selects.forEach(function(select) {\n";
	$js .= "        // Clear existing options except the first one\n";
	$js .= "        while (select.options.length > 1) {\n";
	$js .= "            select.remove(1);\n";
	$js .= "        }\n";
	$js .= "        // Add device options\n";
	$js .= "        dhcpDevices.forEach(function(device) {\n";
	$js .= "            var label = (device.hostname || device.ip) + ' (' + device.mac + ')';\n";
	$js .= "            if (device.online) label += ' [ONLINE]';\n";
	$js .= "            var option = document.createElement('option');\n";
	$js .= "            // CRITICAL: Value must be 'mac|ip|hostname' format to match XML and parsing logic\n";
	$js .= "            option.value = device.mac + '|' + device.ip + '|' + (device.hostname || device.ip);\n";
	$js .= "            option.text = label;\n";
	$js .= "            select.add(option);\n";
	$js .= "        });\n";
	$js .= "        // Add change handler\n";
	$js .= "        select.onchange = function() { populateDeviceFromLease(this); };\n";
	$js .= "    });\n";
	$js .= "}\n\n";
	
	$js .= "function populateDeviceFromLease(selectElement) {\n";
	$js .= "    var selectedValue = selectElement.value;\n";
	$js .= "    if (selectedValue === '') return;\n";
	$js .= "    console.log('Selected value: ' + selectedValue);\n\n";
	
	$js .= "    // Parse the value: mac|ip|hostname\n";
	$js .= "    var parts = selectedValue.split('|');\n";
	$js .= "    if (parts.length >= 3) {\n";
	$js .= "        var mac = parts[0];\n";
	$js .= "        var ip = parts[1];\n";
	$js .= "        var hostname = parts[2];\n";
	$js .= "        console.log('Parsed - MAC: ' + mac + ', IP: ' + ip + ', Hostname: ' + hostname);\n\n";
	
	$js .= "        // Get the row container\n";
	$js .= "        var row = selectElement.parentElement;\n";
	$js .= "        while (row && row.tagName !== 'TR') {\n";
	$js .= "            row = row.parentElement;\n";
	$js .= "        }\n";
	$js .= "        if (row) {\n";
	$js .= "            // Populate device name\n";
	$js .= "            var nameInput = row.querySelector('input[name*=\"device_name\"]');\n";
	$js .= "            if (nameInput) {\n";
	$js .= "                nameInput.value = hostname;\n";
	$js .= "                console.log('Set device name to: ' + hostname);\n";
	$js .= "            }\n";
	$js .= "            // Populate MAC address\n";
	$js .= "            var macInput = row.querySelector('input[name*=\"mac_address\"]');\n";
	$js .= "            if (macInput) {\n";
	$js .= "                macInput.value = mac;\n";
	$js .= "                console.log('Set MAC to: ' + mac);\n";
	$js .= "            }\n";
	$js .= "            // Populate IP address\n";
	$js .= "            var ipInput = row.querySelector('input[name*=\"ip_address\"]');\n";
	$js .= "            if (ipInput) {\n";
	$js .= "                ipInput.value = ip;\n";
	$js .= "                console.log('Set IP to: ' + ip);\n";
	$js .= "            }\n";
	$js .= "        }\n";
	$js .= "    }\n";
	$js .= "}\n\n";
	
	$js .= "// Initialize on page load\n";
	$js .= "if (document.readyState === 'loading') {\n";
	$js .= "    document.addEventListener('DOMContentLoaded', function() {\n";
	$js .= "        initDeviceSelectors();\n";
	$js .= "        setupRowhelperObserver();\n";
	$js .= "    });\n";
	$js .= "} else {\n";
	$js .= "    initDeviceSelectors();\n";
	$js .= "    setupRowhelperObserver();\n";
	$js .= "}\n\n";
	
	$js .= "// Monitor for new rows added by rowhelper\n";
	$js .= "function setupRowhelperObserver() {\n";
	$js .= "    // Use MutationObserver to detect when new rows are added\n";
	$js .= "    var targetNode = document.body;\n";
	$js .= "    var config = { childList: true, subtree: true };\n";
	$js .= "    var observer = new MutationObserver(function(mutations) {\n";
	$js .= "        mutations.forEach(function(mutation) {\n";
	$js .= "            if (mutation.addedNodes.length > 0) {\n";
	$js .= "                // Check if added nodes contain device selector dropdowns\n";
	$js .= "                mutation.addedNodes.forEach(function(node) {\n";
	$js .= "                    if (node.nodeType === 1) { // Element node\n";
	$js .= "                        var selects = node.querySelectorAll ? node.querySelectorAll('select[name*=\"device_selector\"]') : [];\n";
	$js .= "                        if (selects.length > 0 || (node.tagName === 'SELECT' && node.name && node.name.indexOf('device_selector') > -1)) {\n";
	$js .= "                            console.log('New device row detected, re-initializing selectors');\n";
	$js .= "                            setTimeout(initDeviceSelectors, 100);\n";
	$js .= "                        }\n";
	$js .= "                    }\n";
	$js .= "                });\n";
	$js .= "            }\n";
	$js .= "        });\n";
	$js .= "    });\n";
	$js .= "    observer.observe(targetNode, config);\n";
	$js .= "    console.log('Rowhelper observer initialized');\n";
	$js .= "}\n";
	
	$js .= "//]]>\n";
	$js .= "</script>\n";
	
	return $js;
}

/**
 * Generate device selector dropdown HTML
 */
function pc_generate_device_selector($row_index) {
	$leases = pc_get_dhcp_leases();
	
	$html = '<select onchange="populateDeviceFromLease(this, ' . $row_index . ')" class="form-control">';
	$html .= '<option value="">-- Select from DHCP/ARP --</option>';
	
	foreach ($leases as $lease) {
		$label = $lease['hostname'] ? $lease['hostname'] : $lease['ip'];
		$label .= ' (' . $lease['mac'] . ')';
		if ($lease['online']) {
			$label .= ' [ONLINE]';
		}
		$html .= '<option value="' . htmlspecialchars($lease['mac']) . '">' . htmlspecialchars($label) . '</option>';
	}
	
	$html .= '</select>';
	
	return $html;
}

?>

