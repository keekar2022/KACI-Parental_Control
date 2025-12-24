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

// Package version - Auto-increment on each .inc update
define('PC_VERSION', '0.1.2');
define('PC_AUTHOR', 'Mukesh Kesharwani');
define('PC_BUILD_DATE', '2025-12-24');

define('PC_LOG_FILE', '/var/log/parental_control.jsonl');  // JSON Lines format (one JSON object per line)
define('PC_STATE_FILE', '/var/db/parental_control_state.json');
define('PC_CRON_MINUTE', '*/1');  // Check every minute

/**
 * Validate main settings input
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
 * Validate device input
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
		$mac = strtolower(trim($post['mac_address']));
		if (!preg_match('/^([0-9a-f]{2}[:-]){5}([0-9a-f]{2})$/i', $mac)) {
			$input_errors[] = "Invalid MAC address format. Use format: aa:bb:cc:dd:ee:ff";
		}
		
		// Check for duplicate MAC
		$devices = config_get_path('installedpackages/parentalcontroldevices/config', []);
		$current_id = isset($post['id']) ? $post['id'] : null;
		foreach ($devices as $idx => $device) {
			if ($idx != $current_id && strtolower($device['mac_address']) == $mac) {
				$input_errors[] = "MAC address already exists for device: {$device['device_name']}";
				break;
			}
		}
	}
	
	// Validate IP address if provided
	if (!empty($post['ip_address']) && !is_ipaddr($post['ip_address'])) {
		$input_errors[] = "Invalid IP address format.";
	}
	
	// Validate time limits
	if (isset($post['daily_limit']) && $post['daily_limit'] != '') {
		if (!is_numeric($post['daily_limit']) || $post['daily_limit'] < 0 || $post['daily_limit'] > 1440) {
			$input_errors[] = "Daily limit must be between 0 and 1440 minutes (24 hours).";
		}
	}
	
	if (isset($post['weekly_limit']) && $post['weekly_limit'] != '') {
		if (!is_numeric($post['weekly_limit']) || $post['weekly_limit'] < 0 || $post['weekly_limit'] > 10080) {
			$input_errors[] = "Weekly limit must be between 0 and 10080 minutes (7 days).";
		}
	}
	
	// Validate time formats
	if (!empty($post['bedtime_start']) && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $post['bedtime_start'])) {
		$input_errors[] = "Invalid bedtime start format. Use HH:MM (24-hour format).";
	}
	
	if (!empty($post['bedtime_end']) && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $post['bedtime_end'])) {
		$input_errors[] = "Invalid bedtime end format. Use HH:MM (24-hour format).";
	}
	
	if (!empty($post['school_start']) && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $post['school_start'])) {
		$input_errors[] = "Invalid school start format. Use HH:MM (24-hour format).";
	}
	
	if (!empty($post['school_end']) && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $post['school_end'])) {
		$input_errors[] = "Invalid school end format. Use HH:MM (24-hour format).";
	}
}

/**
 * Called before form display
 */
function parental_control_before_form() {
	// Nothing to do yet
}

/**
 * Sync configuration and update firewall rules
 */
function parental_control_sync() {
	global $config;
	
	pc_log("Syncing parental control configuration", 'info', array(
		'event.action' => 'config_sync_start',
		'event.category' => 'configuration'
	));
	
	// Process profiles to extract device information from device_selector
	pc_process_profile_devices();
	
	// Check if service is enabled
	// XML structure: <parentalcontrol><config><enable>on</enable>
	// Path: installedpackages/parentalcontrol/config/enable (NO [0] element!)
	$enabled = config_get_path('installedpackages/parentalcontrol/config/0/enable') === 'on';
	
	if (!$enabled) {
		pc_log("Parental control is disabled, removing rules", 'info', array(
			'event.action' => 'service_disabled',
			'event.category' => 'configuration',
			'service.state' => 'disabled'
		));
		pc_remove_firewall_rules();
		pc_remove_cron_job();
		return;
	}
	
	// Update firewall rules
	pc_update_firewall_rules();
	
	// Setup cron job for periodic checks
	pc_setup_cron_job();
	
	// Initialize state file if needed
	pc_init_state();
	
	// Filter reload
	filter_configure();
	
	pc_log("Configuration sync completed", 'info', array(
		'event.action' => 'config_sync_complete',
		'event.category' => 'configuration',
		'event.outcome' => 'success'
	));
}

/**
 * Process profile devices to extract information from device_selector dropdown
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
		
		pc_log("Processed and saved device information", 'info', array(
			'event.action' => 'devices_processed',
			'event.category' => 'configuration'
		));
	}
}

/**
 * Package installation
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
 * Package uninstallation
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
 */
function parental_control_device_delete() {
	// Sync will update rules
	parental_control_sync();
}

/**
 * Initialize state file
 */
function pc_init_state() {
	if (!file_exists(PC_STATE_FILE)) {
		$state = array(
			'devices' => array(),
			'last_reset' => time(),
			'last_check' => time()
		);
		file_put_contents(PC_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
		pc_log("Initialized state file", 'debug');
	}
}

/**
 * Load state from file
 */
function pc_load_state() {
	if (!file_exists(PC_STATE_FILE)) {
		pc_init_state();
	}
	$json = file_get_contents(PC_STATE_FILE);
	return json_decode($json, true);
}

/**
 * Save state to file
 */
function pc_save_state($state) {
	file_put_contents(PC_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * Update firewall rules for all devices
 */
function pc_update_firewall_rules() {
	global $config;
	
	pc_log("Updating firewall rules", 'debug');
	
	// Remove existing rules first
	pc_remove_firewall_rules();
	
	// Check if service is enabled
	if (config_get_path('installedpackages/parentalcontrol/config/0/enable') !== 'on') {
		return;
	}
	
	// Get configuration
	$pc_config = config_get_path('installedpackages/parentalcontrol/config/0', []);
	$devices = config_get_path('installedpackages/parentalcontroldevices/config', []);
	$enforcement_mode = isset($pc_config['enforcement_mode']) ? $pc_config['enforcement_mode'] : 'strict';
	
	$state = pc_load_state();
	$current_time = time();
	
	foreach ($devices as $idx => $device) {
		if (!isset($device['enable']) || $device['enable'] != 'on') {
			continue;
		}
		
		$mac = strtolower(trim($device['mac_address']));
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
			pc_create_block_rule($device, $enforcement_mode, $reason);
		}
	}
	
	pc_log("Firewall rules updated", 'info');
}

/**
 * Check if device is in a blocked schedule
 */
function pc_is_in_blocked_schedule($device) {
	$now = time();
	$current_day = date('N', $now); // 1 (Monday) through 7 (Sunday)
	$current_time = date('H:i', $now);
	
	// Check bedtime
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
		$schedules = explode("\n", $device['custom_schedule']);
		foreach ($schedules as $schedule) {
			$schedule = trim($schedule);
			if (empty($schedule)) continue;
			
			if (pc_matches_custom_schedule($schedule, $current_day, $current_time)) {
				return true;
			}
		}
	}
	
	return false;
}

/**
 * Check if current time is in range
 */
function pc_is_time_in_range($current, $start, $end) {
	if ($start < $end) {
		// Normal range (e.g., 09:00-17:00)
		return ($current >= $start && $current <= $end);
	} else {
		// Overnight range (e.g., 21:00-07:00)
		return ($current >= $start || $current <= $end);
	}
}

/**
 * Check if current time matches custom schedule
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
 * Check if device has exceeded time limit
 */
function pc_is_time_limit_exceeded($device, $state) {
	$mac = strtolower(trim($device['mac_address']));
	
	// Get daily limit
	$daily_limit = isset($device['daily_limit']) ? intval($device['daily_limit']) : 0;
	if ($daily_limit == 0) {
		return false; // Unlimited
	}
	
	// Check weekend bonus
	$current_day = date('N');
	if ($current_day >= 6 && isset($device['weekend_bonus'])) {
		$daily_limit += intval($device['weekend_bonus']);
	}
	
	// Get usage from state
	$usage_today = 0;
	if (isset($state['devices'][$mac]['usage_today'])) {
		$usage_today = $state['devices'][$mac]['usage_today'];
	}
	
	return ($usage_today >= $daily_limit);
}

/**
 * Create firewall block rule for device
 */
function pc_create_block_rule($device, $enforcement_mode, $reason) {
	global $config;
	
	$mac = $device['mac_address'];
	$child_name = $device['child_name'];
	$device_name = $device['device_name'];
	
	// Get whitelist
	$whitelist = array();
	$whitelist_str = config_get_path('installedpackages/parentalcontrol/whitelist_sites', '');
	if (!empty($whitelist_str)) {
		$whitelist = array_filter(array_map('trim', explode("\n", $whitelist_str)));
	}
	
	$rule = array(
		'type' => 'block',
		'interface' => 'lan',
		'ipprotocol' => 'inet',
		'descr' => "Parental Control: {$child_name} - {$device_name} ({$reason})",
		'source' => array('address' => $mac),
		'destination' => array('any' => ''),
		'created' => array(
			'time' => time(),
			'username' => 'parental_control'
		)
	);
	
	// Adjust rule based on enforcement mode
	switch ($enforcement_mode) {
		case 'moderate':
			// Block only HTTP/HTTPS
			$rule['protocol'] = 'tcp';
			$rule['destination']['port'] = '80,443';
			break;
			
		case 'soft':
			// Block only DNS
			$rule['protocol'] = 'udp';
			$rule['destination']['port'] = '53';
			break;
			
		case 'strict':
		default:
			// Block all internet (rule covers all protocols)
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
 */
function pc_remove_firewall_rules() {
	global $config;
	
	$rules = config_get_path('filter/rule', []);
	$new_rules = array();
	$removed_count = 0;
	
	foreach ($rules as $rule) {
		if (isset($rule['descr']) && strpos($rule['descr'], 'Parental Control:') === 0) {
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
 * Setup cron job for periodic checks
 */
function pc_setup_cron_job() {
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
	
	// Create cron script
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
	
	pc_log("Cron job installed", 'debug');
}

/**
 * Remove cron job
 */
function pc_remove_cron_job() {
	install_cron_job("/usr/local/bin/php /usr/local/bin/parental_control_cron.php", false);
	@unlink('/usr/local/bin/parental_control_cron.php');
	pc_log("Cron job removed", 'debug');
}

/**
 * Cron job handler - called periodically to update rules and track usage
 */
function parental_control_cron_job() {
	// Check if service is enabled
	if (config_get_path('installedpackages/parentalcontrol/config/0/enable') !== 'on') {
		return;
	}
	
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
	}
	
	// Update usage for active devices (simplified - would need actual connection tracking)
	pc_update_device_usage($state);
	
	$state['last_check'] = $current_time;
	pc_save_state($state);
	
	// Update firewall rules
	parental_control_sync();
}

/**
 * Check if counters should be reset
 */
function pc_should_reset_counters($last_reset, $reset_time) {
	$now = time();
	$today_reset = strtotime("today " . str_replace(':', ':', $reset_time));
	
	// If last reset was before today's reset time and we're past it, reset
	return ($last_reset < $today_reset && $now >= $today_reset);
}

/**
 * Reset daily usage counters
 */
function pc_reset_daily_counters(&$state) {
	foreach ($state['devices'] as $mac => &$device_state) {
		$device_state['usage_today'] = 0;
	}
	pc_log("Daily usage counters reset", 'info');
}

/**
 * Update device usage (placeholder - would need actual connection tracking)
 */
function pc_update_device_usage(&$state) {
	// This is a simplified version
	// In production, you would:
	// 1. Query firewall state table for active connections by MAC
	// 2. Calculate time since last check
	// 3. Add to usage counters
	
	$devices = config_get_path('installedpackages/parentalcontroldevices/config', []);
	$check_interval = config_get_path('installedpackages/parentalcontrol/check_interval', 60);
	
	foreach ($devices as $device) {
		if (!isset($device['enable']) || $device['enable'] != 'on') {
			continue;
		}
		
		$mac = strtolower(trim($device['mac_address']));
		
		if (!isset($state['devices'][$mac])) {
			$state['devices'][$mac] = array(
				'usage_today' => 0,
				'usage_week' => 0,
				'last_seen' => 0
			);
		}
		
		// Check if device is currently online and not blocked
		if (pc_is_device_online($mac) && !pc_is_in_blocked_schedule($device)) {
			// Add usage time (in minutes)
			$usage_minutes = ceil($check_interval / 60);
			$state['devices'][$mac]['usage_today'] += $usage_minutes;
			$state['devices'][$mac]['usage_week'] += $usage_minutes;
			$state['devices'][$mac]['last_seen'] = time();
		}
	}
}

/**
 * Check if device is currently online via ARP table
 */
function pc_is_device_online($mac) {
	$mac = strtolower(trim($mac));
	$arp_table = system_get_arp_table();
	
	if (!is_array($arp_table)) {
		return false;
	}
	
	foreach ($arp_table as $entry) {
		if (isset($entry['mac-address']) && strtolower($entry['mac-address']) == $mac) {
			return true;
		}
	}
	
	return false;
}

/**
 * Logging function - OpenTelemetry compliant format
 * 
 * @param string $message The log message (Body)
 * @param string $level Severity level: debug, info, warning, error
 * @param array $attributes Additional structured attributes for SIEM/log analysis
 * @return void
 */
function pc_log($message, $level = 'info', $attributes = array()) {
	global $config;
	
	// Check if logging is enabled
	$pc_config = config_get_path('installedpackages/parentalcontrol/config', []);
	if (!is_array($pc_config) || empty($pc_config['enable_logging'])) {
		return;
	}
	
	$log_level = config_get_path('installedpackages/parentalcontrol/log_level', 'info');
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
			'service.version' => '1.0.0',
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
	
	// Write JSON log entry (one line per entry for easy parsing)
	$json_log = json_encode($log_record, JSON_UNESCAPED_SLASHES) . "\n";
	file_put_contents(PC_LOG_FILE, $json_log, FILE_APPEND);
	
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
	
	// Validate devices
	if (isset($post['devices']) && is_array($post['devices'])) {
		foreach ($post['devices'] as $idx => $device) {
			if (empty($device['device_name'])) {
				$input_errors[] = "Device #{$idx}: Device name is required.";
			}
			
			if (empty($device['mac_address'])) {
				$input_errors[] = "Device #{$idx}: MAC address is required.";
			} else {
				$mac = strtolower(trim($device['mac_address']));
				if (!preg_match('/^([0-9a-f]{2}[:-]){5}([0-9a-f]{2})$/i', $mac)) {
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
		foreach ($post['schedules'] as $idx => $schedule) {
			if (empty($schedule['name'])) {
				$input_errors[] = "Schedule #{$idx}: Name is required.";
			}
			
			if (empty($schedule['days'])) {
				$input_errors[] = "Schedule #{$idx}: Days are required.";
			}
			
			if (empty($schedule['start_time']) || !preg_match('/^\d{2}:\d{2}$/', $schedule['start_time'])) {
				$input_errors[] = "Schedule #{$idx}: Valid start time required (HH:MM format).";
			}
			
			if (empty($schedule['end_time']) || !preg_match('/^\d{2}:\d{2}$/', $schedule['end_time'])) {
				$input_errors[] = "Schedule #{$idx}: Valid end time required (HH:MM format).";
			}
		}
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
	$mac = strtolower(trim($mac));
	$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config');
	
	if (!is_array($profiles)) {
		return null;
	}
	
	foreach ($profiles as $profile) {
		if (!isset($profile['devices']) || !is_array($profile['devices'])) {
			continue;
		}
		
		foreach ($profile['devices'] as $device) {
			if (strtolower(trim($device['mac_address'])) == $mac) {
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
 */
function pc_get_profile_usage($profile_id) {
	$state = pc_load_state();
	
	if (!isset($state['profiles'][$profile_id])) {
		return array(
			'usage_today' => 0,
			'usage_week' => 0,
			'last_reset' => time()
		);
	}
	
	return $state['profiles'][$profile_id];
}

/**
 * Update profile usage in state file
 */
function pc_update_profile_usage($profile_id, $minutes_to_add) {
	$state = pc_load_state();
	
	if (!isset($state['profiles'])) {
		$state['profiles'] = array();
	}
	
	if (!isset($state['profiles'][$profile_id])) {
		$state['profiles'][$profile_id] = array(
			'usage_today' => 0,
			'usage_week' => 0,
			'last_reset' => time()
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
	// Check if service is enabled
	if (config_get_path('installedpackages/parentalcontrol/config/0/enable') !== 'on') {
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
 * Get DHCP leases for device auto-discovery
 */
function pc_get_dhcp_leases() {
	global $config;
	
	$leases = array();
	
	// Get ARP table first (most reliable)
	$arp_table = system_get_arp_table();
	if (is_array($arp_table)) {
		foreach ($arp_table as $entry) {
			if (isset($entry['mac-address']) && isset($entry['ip-address'])) {
				$mac = strtolower(str_replace('-', ':', $entry['mac-address']));
				$ip = $entry['ip-address'];
				
				// Try to get hostname from DNS
				$hostname = '';
				if (function_exists('gethostbyaddr')) {
					$resolved = @gethostbyaddr($ip);
					if ($resolved != $ip) {
						$hostname = $resolved;
					}
				}
				
				// If no hostname, try to get from DHCP static mappings
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
		}
	}
	
	// Sort by hostname/IP for easier selection
	usort($leases, function($a, $b) {
		$name_a = $a['hostname'] ?: $a['ip'];
		$name_b = $b['hostname'] ?: $b['ip'];
		return strcasecmp($name_a, $name_b);
	});
	
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
	$js .= "            option.value = device.mac;\n";
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
	$js .= "    document.addEventListener('DOMContentLoaded', initDeviceSelectors);\n";
	$js .= "} else {\n";
	$js .= "    initDeviceSelectors();\n";
	$js .= "}\n\n";
	
	$js .= "// Re-initialize when clicking buttons (for dynamic rows)\n";
	$js .= "document.addEventListener('click', function(e) {\n";
	$js .= "    if (e.target.classList.contains('btn') || e.target.closest('.btn')) {\n";
	$js .= "        setTimeout(initDeviceSelectors, 300);\n";
	$js .= "    }\n";
	$js .= "});\n";
	
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

