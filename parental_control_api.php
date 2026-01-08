<?php
/*
 * parental_control_api.php
 *
 * RESTful API for Parental Control package
 * Provides external integration capabilities for automation, monitoring, and management
 *
 * SECURITY: This API should be protected with API keys and rate limiting in production.
 * 
 * Endpoints:
 *   GET  /api/devices           - List all devices
 *   GET  /api/devices/{mac}     - Get device details by MAC
 *   POST /api/devices/{mac}/block   - Block a device temporarily
 *   POST /api/devices/{mac}/unblock - Unblock a device
 *   GET  /api/profiles          - List all profiles
 *   GET  /api/profiles/{id}     - Get profile details
 *   GET  /api/profiles/{id}/schedules - Get schedules for a profile
 *   GET  /api/schedules         - List all schedules
 *   GET  /api/schedules/{id}    - Get schedule details by ID
 *   GET  /api/schedules/active  - Get currently active schedules
 *   GET  /api/usage             - Get usage statistics
 *   GET  /api/status            - Get service status
 *   POST /api/override          - Grant temporary internet override
 *
 * Authentication: API key in X-API-Key header or api_key query parameter
 *
 * @package ParentalControl
 * @version 0.3.0
 * @since 0.1.4
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Set JSON response headers
header("Content-Type: application/json; charset=utf-8");

// PERFORMANCE OPTIMIZATION v1.4.30: Cache GET responses, but not POST
// WHY: Monitoring dashboards poll frequently, caching reduces firewall load
// POST requests should never be cached (state modifications)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	// Cache GET responses for 10 seconds
	header("Cache-Control: public, max-age=10");
	header("Expires: " . gmdate("D, d M Y H:i:s", time() + 10) . " GMT");
} else {
	// POST/PUT/DELETE should not be cached
	header("Cache-Control: no-cache, must-revalidate");
	header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
}

// WHY: Enable CORS for API access from external apps
// Design Decision: Allow CORS but require API key for security
// Rationale: External monitoring dashboards, mobile apps, automation tools need access
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(200);
	exit;
}

/**
 * API authentication check
 * 
 * WHY: Protect API from unauthorized access. API keys are simpler than OAuth for
 * small-scale integrations and pfSense environment.
 * 
 * SECURITY NOTE: In production, store API keys hashed in config.
 * For now, we check against a configured API key in package settings.
 * 
 * @return bool True if authenticated, false otherwise
 */
function api_authenticate() {
	// Get API key from config
	$configured_key = config_get_path('installedpackages/parentalcontrol/config/0/api_key', '');
	
	// If no API key configured, API is disabled for security
	if (empty($configured_key)) {
		return false;
	}
	
	// Check X-API-Key header first, then query parameter
	$provided_key = '';
	if (isset($_SERVER['HTTP_X_API_KEY'])) {
		$provided_key = $_SERVER['HTTP_X_API_KEY'];
	} elseif (isset($_GET['api_key'])) {
		$provided_key = $_GET['api_key'];
	}
	
	// WHY: Use hash_equals to prevent timing attacks
	// Rationale: Regular == comparison can leak information about key through timing
	return hash_equals($configured_key, $provided_key);
}

/**
 * Send JSON response with proper HTTP status code
 * 
 * @param int $status_code HTTP status code
 * @param mixed $data Response data (will be JSON encoded)
 * @param string|null $error Error message if error response
 */
function api_response($status_code, $data, $error = null) {
	http_response_code($status_code);
	
	$response = array(
		'status' => $status_code >= 200 && $status_code < 300 ? 'success' : 'error',
		'timestamp' => date('c'), // ISO 8601 format
		'data' => $data
	);
	
	if ($error !== null) {
		$response['error'] = $error;
	}
	
	echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

/**
 * Get schedules that apply to a specific profile
 * 
 * @param string $profile_name Profile name to check
 * @return array Array of schedule data
 */
function api_get_schedules_for_profile($profile_name) {
	$all_schedules = config_get_path('installedpackages/parentalcontrolschedules/config', []);
	$matching_schedules = array();
	
	if (!is_array($all_schedules)) {
		return $matching_schedules;
	}
	
	$current_time = date('H:i');
	$current_day_num = date('N');
	$day_map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
	
	foreach ($all_schedules as $idx => $schedule) {
		if (!is_array($schedule)) continue;
		
		// Get schedule profiles (handle both old and new format)
		$schedule_profiles = array();
		if (isset($schedule['profile_names'])) {
			if (is_array($schedule['profile_names'])) {
				$schedule_profiles = $schedule['profile_names'];
			} else {
				$schedule_profiles = array_map('trim', explode(',', $schedule['profile_names']));
			}
		} elseif (isset($schedule['profile_name'])) {
			$schedule_profiles = array($schedule['profile_name']);
		}
		
		// Check if this schedule applies to the requested profile
		if (!in_array($profile_name, $schedule_profiles)) {
			continue;
		}
		
		// Parse days
		$days = array();
		if (isset($schedule['days'])) {
			if (is_array($schedule['days'])) {
				$days = $schedule['days'];
			} else {
				$days = array_map('trim', explode(',', $schedule['days']));
			}
		}
		
		// Check if currently active
		$is_active = false;
		$day_matches = false;
		
		foreach ($days as $day) {
			$day_lower = strtolower($day);
			if (isset($day_map[$day_lower]) && $day_map[$day_lower] == $current_day_num) {
				$day_matches = true;
				break;
			}
		}
		
		if ($day_matches && isset($schedule['start_time']) && isset($schedule['end_time'])) {
			if (pc_is_time_in_range($current_time, $schedule['start_time'], $schedule['end_time'])) {
				$is_active = true;
			}
		}
		
		$matching_schedules[] = array(
			'id' => $idx,
			'schedule_name' => isset($schedule['name']) ? $schedule['name'] : 'Unnamed',
			'time_range' => isset($schedule['start_time']) && isset($schedule['end_time']) ? 
				$schedule['start_time'] . ' - ' . $schedule['end_time'] : null,
			'start_time' => isset($schedule['start_time']) ? $schedule['start_time'] : null,
			'end_time' => isset($schedule['end_time']) ? $schedule['end_time'] : null,
			'days' => $days,
			'enabled' => isset($schedule['enabled']) && $schedule['enabled'] === 'on',
			'currently_active' => $is_active,
			'applies_to_profiles' => $schedule_profiles
		);
	}
	
	return $matching_schedules;
}

/**
 * Parse request URI to extract endpoint and parameters
 * 
 * @return array Array with keys: 'resource', 'id', 'action'
 */
function parse_request_uri() {
	// WHY: pfSense URLs are complex, extract the relevant path after parental_control_api.php
	$uri = $_SERVER['REQUEST_URI'];
	$base = '/parental_control_api.php';
	$path = '';
	
	if (strpos($uri, $base) !== false) {
		$path = substr($uri, strpos($uri, $base) + strlen($base));
		// Remove query string
		if (strpos($path, '?') !== false) {
			$path = substr($path, 0, strpos($path, '?'));
		}
	}
	
	$path = trim($path, '/');
	$parts = array_filter(explode('/', $path));
	
	return array(
		'resource' => isset($parts[0]) ? $parts[0] : '',
		'id' => isset($parts[1]) ? $parts[1] : null,
		'action' => isset($parts[2]) ? $parts[2] : null
	);
}

// ========================================================================
// AUTHENTICATION
// ========================================================================

if (!api_authenticate()) {
	api_response(401, null, 'Unauthorized: Invalid or missing API key. Configure API key in Parental Control settings.');
}

// Check if service is enabled
if (!pc_is_service_enabled()) {
	api_response(503, null, 'Service Unavailable: Parental Control is disabled.');
}

// ========================================================================
// ROUTING
// ========================================================================

$method = $_SERVER['REQUEST_METHOD'];
$route = parse_request_uri();
$resource = $route['resource'];
$id = $route['id'];
$action = $route['action'];

// GET /api/status - Service status
if ($method === 'GET' && $resource === 'status') {
	$state = pc_load_state();
	api_response(200, array(
		'service_enabled' => pc_is_service_enabled(),
		'devices_count' => count(pc_get_devices()),
		'devices_enabled' => count(pc_get_devices(true)),
		'profiles_count' => count(pc_get_profiles()),
		'last_check' => isset($state['last_check']) ? date('c', $state['last_check']) : null,
		'last_reset' => isset($state['last_reset']) ? date('c', $state['last_reset']) : null
	));
}

// GET /api/devices - List all devices
if ($method === 'GET' && $resource === 'devices' && $id === null) {
	$devices = pc_get_devices();
	$state = pc_load_state();
	
	$device_list = array();
	foreach ($devices as $device) {
		$mac = pc_normalize_mac($device['mac_address']);
		$device_info = array(
			'mac_address' => $mac,
			'device_name' => $device['device_name'],
			'child_name' => isset($device['child_name']) ? $device['child_name'] : '',
			'ip_address' => isset($device['ip_address']) ? $device['ip_address'] : null,
			'enabled' => pc_is_device_enabled($device),
			'online' => pc_is_device_online($mac),
			'usage' => isset($state['devices'][$mac]) ? $state['devices'][$mac] : null
		);
		$device_list[] = $device_info;
	}
	
	api_response(200, $device_list);
}

// GET /api/devices/{mac} - Get device details
if ($method === 'GET' && $resource === 'devices' && $id !== null) {
	$devices = pc_get_devices();
	$mac = pc_normalize_mac($id);
	$state = pc_load_state();
	
	foreach ($devices as $device) {
		if (pc_normalize_mac($device['mac_address']) === $mac) {
			// Get schedules that apply to this device's profile
			$profile_name = isset($device['child_name']) ? $device['child_name'] : '';
			$schedules_applied = api_get_schedules_for_profile($profile_name);
			
			// Get device IP from state (IP-based tracking since v0.2.1)
			$device_ip = null;
			if (isset($state['mac_to_ip_cache'][$mac])) {
				$device_ip = $state['mac_to_ip_cache'][$mac];
			}
			
			// Get usage from IP-based state
			$usage = null;
			if ($device_ip && isset($state['devices_by_ip'][$device_ip])) {
				$usage = $state['devices_by_ip'][$device_ip];
			}
			
			$device_info = array(
				'mac_address' => $mac,
				'device_name' => $device['device_name'],
				'child_name' => $profile_name,
				'ip_address' => $device_ip,
				'enabled' => pc_is_device_enabled($device),
				'online' => pc_is_device_online($mac),
				'daily_limit' => isset($device['daily_limit']) ? intval($device['daily_limit']) : 0,
				'weekly_limit' => isset($device['weekly_limit']) ? intval($device['weekly_limit']) : 0,
				'usage' => $usage,
				'schedules_applied' => $schedules_applied,
				'currently_blocked' => pc_is_in_blocked_schedule($device) || pc_is_time_limit_exceeded($device, $state)
			);
			api_response(200, $device_info);
		}
	}
	
	api_response(404, null, "Device not found: {$id}");
}

// POST /api/devices/{mac}/block - Temporarily block device
if ($method === 'POST' && $resource === 'devices' && $id !== null && $action === 'block') {
	$devices = pc_get_devices();
	$mac = pc_normalize_mac($id);
	
	// Read POST body for duration
	$input = json_decode(file_get_contents('php://input'), true);
	$duration = isset($input['duration']) ? intval($input['duration']) : 60; // Default 60 minutes
	$reason = isset($input['reason']) ? $input['reason'] : 'Temporary API block';
	
	foreach ($devices as $device) {
		if (pc_normalize_mac($device['mac_address']) === $mac) {
			// Create block rule
			$enforcement_mode = config_get_path('installedpackages/parentalcontrol/config/0/enforcement_mode', 'strict');
			pc_create_block_rule($device, $enforcement_mode, $reason);
			filter_configure();
			
			// Log the API action
			pc_log("Device blocked via API", 'info', array(
				'event.action' => 'api_device_block',
				'device.mac' => $mac,
				'device.name' => $device['device_name'],
				'block.duration_minutes' => $duration,
				'block.reason' => $reason,
				'api.source' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'
			));
			
			api_response(200, array(
				'mac_address' => $mac,
				'blocked' => true,
				'duration_minutes' => $duration,
				'reason' => $reason
			));
		}
	}
	
	api_response(404, null, "Device not found: {$id}");
}

// POST /api/devices/{mac}/unblock - Unblock device
if ($method === 'POST' && $resource === 'devices' && $id !== null && $action === 'unblock') {
	$mac = pc_normalize_mac($id);
	
	// Remove firewall rules for this device
	pc_remove_firewall_rules(); // This removes all rules, then re-sync will add back necessary ones
	parental_control_sync(); // Re-sync to apply correct rules
	
	pc_log("Device unblocked via API", 'info', array(
		'event.action' => 'api_device_unblock',
		'device.mac' => $mac,
		'api.source' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'
	));
	
	api_response(200, array(
		'mac_address' => $mac,
		'blocked' => false
	));
}

// GET /api/profiles - List all profiles
if ($method === 'GET' && $resource === 'profiles' && $id === null) {
	$profiles = pc_get_profiles();
	$profile_list = array();
	
	foreach ($profiles as $profile) {
		$profile_info = array(
			'id' => isset($profile['id']) ? $profile['id'] : null,
			'name' => isset($profile['name']) ? $profile['name'] : '',
			'description' => isset($profile['description']) ? $profile['description'] : '',
			'enabled' => isset($profile['enabled']) && $profile['enabled'] === 'on',
			'daily_limit' => isset($profile['daily_limit']) ? intval($profile['daily_limit']) : 0,
			'weekend_bonus' => isset($profile['weekend_bonus']) ? intval($profile['weekend_bonus']) : 0,
			'device_count' => isset($profile['devices']) ? count($profile['devices']) : 0
		);
		$profile_list[] = $profile_info;
	}
	
	api_response(200, $profile_list);
}

// GET /api/profiles/{id} - Get profile details
if ($method === 'GET' && $resource === 'profiles' && $id !== null) {
	$profiles = pc_get_profiles();
	
	foreach ($profiles as $profile) {
		if (isset($profile['id']) && $profile['id'] == $id) {
			// Get schedules that apply to this profile
			$profile_name = isset($profile['name']) ? $profile['name'] : '';
			$schedules_applied = api_get_schedules_for_profile($profile_name);
			
			$profile_info = array(
				'id' => $profile['id'],
				'name' => $profile_name,
				'description' => isset($profile['description']) ? $profile['description'] : '',
				'enabled' => isset($profile['enabled']) && $profile['enabled'] === 'on',
				'daily_limit' => isset($profile['daily_limit']) ? intval($profile['daily_limit']) : 0,
				'weekend_bonus' => isset($profile['weekend_bonus']) ? intval($profile['weekend_bonus']) : 0,
				'devices' => isset($profile['devices']) ? $profile['devices'] : array(),
				'schedules_applied' => $schedules_applied,
				'usage' => pc_get_profile_usage($profile)
			);
			api_response(200, $profile_info);
		}
	}
	
	api_response(404, null, "Profile not found: {$id}");
}

// GET /api/usage - Get overall usage statistics
if ($method === 'GET' && $resource === 'usage') {
	$state = pc_load_state();
	$devices = pc_get_devices(true); // Only enabled devices
	
	$usage_stats = array(
		'total_devices' => count($devices),
		'devices_online' => 0,
		'devices_blocked' => 0,
		'total_usage_today' => 0,
		'total_usage_week' => 0,
		'devices' => array()
	);
	
	foreach ($devices as $device) {
		$mac = pc_normalize_mac($device['mac_address']);
		$device_usage = isset($state['devices'][$mac]) ? $state['devices'][$mac] : array(
			'usage_today' => 0,
			'usage_week' => 0,
			'last_seen' => 0
		);
		
		$is_online = pc_is_device_online($mac);
		if ($is_online) {
			$usage_stats['devices_online']++;
		}
		
		// Check if currently blocked
		$is_blocked = pc_is_in_blocked_schedule($device) || pc_is_time_limit_exceeded($device, $state);
		if ($is_blocked) {
			$usage_stats['devices_blocked']++;
		}
		
		$usage_stats['total_usage_today'] += intval($device_usage['usage_today']);
		$usage_stats['total_usage_week'] += intval($device_usage['usage_week']);
		
		$usage_stats['devices'][] = array(
			'mac_address' => $mac,
			'device_name' => $device['device_name'],
			'child_name' => isset($device['child_name']) ? $device['child_name'] : '',
			'online' => $is_online,
			'blocked' => $is_blocked,
			'usage_today' => intval($device_usage['usage_today']),
			'usage_week' => intval($device_usage['usage_week']),
			'last_seen' => isset($device_usage['last_seen']) ? date('c', $device_usage['last_seen']) : null
		);
	}
	
	api_response(200, $usage_stats);
}

// POST /api/override - Grant temporary internet override
if ($method === 'POST' && $resource === 'override') {
	$input = json_decode(file_get_contents('php://input'), true);
	
	if (!isset($input['mac_address'])) {
		api_response(400, null, 'Missing required field: mac_address');
	}
	
	$mac = pc_normalize_mac($input['mac_address']);
	$duration = isset($input['duration']) ? intval($input['duration']) : 30; // Default 30 minutes
	$reason = isset($input['reason']) ? $input['reason'] : 'Temporary override';
	
	// Find device
	$devices = pc_get_devices();
	$device_found = false;
	
	foreach ($devices as $device) {
		if (pc_normalize_mac($device['mac_address']) === $mac) {
			$device_found = true;
			
			// Remove current block (if any) and mark override in state
			$state = pc_load_state();
			if (!isset($state['overrides'])) {
				$state['overrides'] = array();
			}
			
			$state['overrides'][$mac] = array(
				'granted_at' => time(),
				'expires_at' => time() + ($duration * 60),
				'reason' => $reason
			);
			
			pc_save_state($state);
			
			// Re-sync rules to apply override
			parental_control_sync();
			
			pc_log("Temporary override granted via API", 'info', array(
				'event.action' => 'api_override_granted',
				'device.mac' => $mac,
				'device.name' => $device['device_name'],
				'override.duration_minutes' => $duration,
				'override.reason' => $reason,
				'api.source' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'
			));
			
			api_response(200, array(
				'mac_address' => $mac,
				'override_granted' => true,
				'duration_minutes' => $duration,
				'expires_at' => date('c', time() + ($duration * 60))
			));
		}
	}
	
	if (!$device_found) {
		api_response(404, null, "Device not found: {$mac}");
	}
}

// GET /api/schedules - List all schedules
if ($method === 'GET' && $resource === 'schedules' && $id === null && $action === null) {
	$schedules = config_get_path('installedpackages/parentalcontrolschedules/config', []);
	$schedule_list = array();
	
	if (is_array($schedules)) {
		$current_time = date('H:i');
		$current_day_num = date('N');
		$day_map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
		
		foreach ($schedules as $idx => $schedule) {
			if (!is_array($schedule)) continue;
			
			// Get schedule profiles (handle both old and new format)
			$schedule_profiles = array();
			if (isset($schedule['profile_names'])) {
				if (is_array($schedule['profile_names'])) {
					$schedule_profiles = $schedule['profile_names'];
				} else {
					$schedule_profiles = array_map('trim', explode(',', $schedule['profile_names']));
				}
			} elseif (isset($schedule['profile_name'])) {
				$schedule_profiles = array($schedule['profile_name']);
			}
			
			// Parse days
			$days = array();
			if (isset($schedule['days'])) {
				if (is_array($schedule['days'])) {
					$days = $schedule['days'];
				} else {
					$days = array_map('trim', explode(',', $schedule['days']));
				}
			}
			
			// Check if currently active
			$is_active = false;
			$day_matches = false;
			
			foreach ($days as $day) {
				$day_lower = strtolower($day);
				if (isset($day_map[$day_lower]) && $day_map[$day_lower] == $current_day_num) {
					$day_matches = true;
					break;
				}
			}
			
			if ($day_matches && isset($schedule['start_time']) && isset($schedule['end_time'])) {
				if (pc_is_time_in_range($current_time, $schedule['start_time'], $schedule['end_time'])) {
					$is_active = true;
				}
			}
			
			$schedule_list[] = array(
				'id' => $idx,
				'name' => isset($schedule['name']) ? $schedule['name'] : 'Unnamed',
				'profiles' => $schedule_profiles,
				'time_range' => isset($schedule['start_time']) && isset($schedule['end_time']) ? 
					$schedule['start_time'] . ' - ' . $schedule['end_time'] : null,
				'days' => $days,
				'enabled' => isset($schedule['enabled']) && $schedule['enabled'] === 'on',
				'currently_active' => $is_active
			);
		}
	}
	
	api_response(200, $schedule_list);
}

// GET /api/schedules/active - Get currently active schedules
if ($method === 'GET' && $resource === 'schedules' && $id === 'active') {
	$schedules = config_get_path('installedpackages/parentalcontrolschedules/config', []);
	$active_schedules = array();
	
	if (is_array($schedules)) {
		$current_time = date('H:i');
		$current_day_num = date('N');
		$day_map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
		
		foreach ($schedules as $idx => $schedule) {
			if (!is_array($schedule)) continue;
			
			// Skip disabled schedules
			if (!isset($schedule['enabled']) || $schedule['enabled'] != 'on') {
				continue;
			}
			
			// Get schedule profiles
			$schedule_profiles = array();
			if (isset($schedule['profile_names'])) {
				if (is_array($schedule['profile_names'])) {
					$schedule_profiles = $schedule['profile_names'];
				} else {
					$schedule_profiles = array_map('trim', explode(',', $schedule['profile_names']));
				}
			} elseif (isset($schedule['profile_name'])) {
				$schedule_profiles = array($schedule['profile_name']);
			}
			
			// Parse days
			$days = array();
			if (isset($schedule['days'])) {
				if (is_array($schedule['days'])) {
					$days = $schedule['days'];
				} else {
					$days = array_map('trim', explode(',', $schedule['days']));
				}
			}
			
			// Check if currently active
			$day_matches = false;
			foreach ($days as $day) {
				$day_lower = strtolower($day);
				if (isset($day_map[$day_lower]) && $day_map[$day_lower] == $current_day_num) {
					$day_matches = true;
					break;
				}
			}
			
			if ($day_matches && isset($schedule['start_time']) && isset($schedule['end_time'])) {
				if (pc_is_time_in_range($current_time, $schedule['start_time'], $schedule['end_time'])) {
					$active_schedules[] = array(
						'id' => $idx,
						'name' => isset($schedule['name']) ? $schedule['name'] : 'Unnamed',
						'profiles' => $schedule_profiles,
						'time_range' => $schedule['start_time'] . ' - ' . $schedule['end_time'],
						'start_time' => $schedule['start_time'],
						'end_time' => $schedule['end_time'],
						'days' => $days,
						'blocking_since' => null // Could calculate based on start_time
					);
				}
			}
		}
	}
	
	api_response(200, array(
		'count' => count($active_schedules),
		'schedules' => $active_schedules
	));
}

// GET /api/schedules/{id} - Get schedule details by ID
if ($method === 'GET' && $resource === 'schedules' && $id !== null && $action === null && $id !== 'active') {
	$schedules = config_get_path('installedpackages/parentalcontrolschedules/config', []);
	$schedule_id = intval($id);
	
	if (is_array($schedules) && isset($schedules[$schedule_id])) {
		$schedule = $schedules[$schedule_id];
		
		// Get schedule profiles
		$schedule_profiles = array();
		if (isset($schedule['profile_names'])) {
			if (is_array($schedule['profile_names'])) {
				$schedule_profiles = $schedule['profile_names'];
			} else {
				$schedule_profiles = array_map('trim', explode(',', $schedule['profile_names']));
			}
		} elseif (isset($schedule['profile_name'])) {
			$schedule_profiles = array($schedule['profile_name']);
		}
		
		// Parse days
		$days = array();
		if (isset($schedule['days'])) {
			if (is_array($schedule['days'])) {
				$days = $schedule['days'];
			} else {
				$days = array_map('trim', explode(',', $schedule['days']));
			}
		}
		
		// Check if currently active
		$current_time = date('H:i');
		$current_day_num = date('N');
		$day_map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
		$is_active = false;
		$day_matches = false;
		
		foreach ($days as $day) {
			$day_lower = strtolower($day);
			if (isset($day_map[$day_lower]) && $day_map[$day_lower] == $current_day_num) {
				$day_matches = true;
				break;
			}
		}
		
		if ($day_matches && isset($schedule['start_time']) && isset($schedule['end_time'])) {
			if (pc_is_time_in_range($current_time, $schedule['start_time'], $schedule['end_time'])) {
				$is_active = true;
			}
		}
		
		$schedule_info = array(
			'id' => $schedule_id,
			'name' => isset($schedule['name']) ? $schedule['name'] : 'Unnamed',
			'profiles' => $schedule_profiles,
			'start_time' => isset($schedule['start_time']) ? $schedule['start_time'] : null,
			'end_time' => isset($schedule['end_time']) ? $schedule['end_time'] : null,
			'time_range' => isset($schedule['start_time']) && isset($schedule['end_time']) ? 
				$schedule['start_time'] . ' - ' . $schedule['end_time'] : null,
			'days' => $days,
			'enabled' => isset($schedule['enabled']) && $schedule['enabled'] === 'on',
			'currently_active' => $is_active,
			'affected_devices_count' => count_devices_in_profiles($schedule_profiles)
		);
		
		api_response(200, $schedule_info);
	}
	
	api_response(404, null, "Schedule not found: {$id}");
}

// GET /api/profiles/{id}/schedules - Get schedules for a specific profile
if ($method === 'GET' && $resource === 'profiles' && $id !== null && $action === 'schedules') {
	$profiles = pc_get_profiles();
	$profile_found = false;
	
	foreach ($profiles as $profile) {
		if (isset($profile['id']) && $profile['id'] == $id) {
			$profile_found = true;
			$profile_name = isset($profile['name']) ? $profile['name'] : '';
			$schedules_applied = api_get_schedules_for_profile($profile_name);
			
			api_response(200, array(
				'profile_id' => $id,
				'profile_name' => $profile_name,
				'schedules_count' => count($schedules_applied),
				'schedules' => $schedules_applied
			));
		}
	}
	
	if (!$profile_found) {
		api_response(404, null, "Profile not found: {$id}");
	}
}

// Helper function to count devices in given profiles
function count_devices_in_profiles($profile_names) {
	$devices = pc_get_devices();
	$count = 0;
	
	foreach ($devices as $device) {
		$device_profile = isset($device['child_name']) ? $device['child_name'] : '';
		if (in_array($device_profile, $profile_names)) {
			$count++;
		}
	}
	
	return $count;
}

// If we get here, endpoint not found
api_response(404, null, "Endpoint not found: {$method} /{$resource}" . ($id ? "/{$id}" : '') . ($action ? "/{$action}" : ''));
?>

