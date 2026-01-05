<?php
/*
 * parental_control_services.php
 *
 * Online Services IP Management for Parental Control
 * Allows blocking specific online services (YouTube, Facebook, etc.) by IP ranges
 */

##|+PRIV
##|*IDENT=page-services-parentalcontrol-services
##|*NAME=Services: Parental Control: Online Services
##|*DESCR=Manage online service IP lists for blocking
##|*MATCH=parental_control_services.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

// CRITICAL FIX v1.4.10+: Start PHP session for persistent URL storage
// BUG: Session URLs were lost between add_url and verify_fetch actions
// ROOT CAUSE: $services_config reloaded from config on each request, losing session-based URLs
// SOLUTION: Store temporary URLs in $_SESSION to persist across POST requests
session_start();

// Safety check: Ensure config is valid
if (!is_array($config) || $config === -1) {
	require_once("config.inc");
	$config = parse_config(true);
	if (!is_array($config) || $config === -1) {
		die("Fatal: Unable to load configuration. Please restore from backup.");
	}
}

$pgtitle = array(gettext("Services"), gettext("Keekar's Parental Control"), gettext("Online Services"));
$pglinks = array("", "@self", "@self");

// ========================================================================
// Helper Functions for XML-Safe Service Management
// ========================================================================

/**
 * Safe wrapper for write_config() with validation
 * @param string $desc Description for the config change
 * @return bool True if write succeeded, false otherwise
 */
function safe_write_config($desc) {
	global $config;
	
	// Validate config before writing
	if (!is_array($config) || $config === -1) {
		// Config is corrupted, try to reload it
		require_once("config.inc");
		$config = parse_config(true);
		if (!is_array($config) || $config === -1) {
			// Can't recover, give up
			error_log("Cannot write config: config is corrupted");
			return false;
		}
	}
	
	// Check if installedpackages exists
	if (!isset($config['installedpackages'])) {
		$config['installedpackages'] = array();
	}
	
	// Validate the config structure before writing
	if (!isset($config['version']) || !isset($config['system'])) {
		error_log("Cannot write config: missing required structure");
		return false;
	}
	
	// Try to write config
	try {
		$result = @write_config($desc);
		
		// Check if write_config corrupted the config
		if (!is_array($config) || $config === -1) {
			// write_config failed and corrupted $config, reload it
			require_once("config.inc");
			$config = parse_config(true);
			return false;
		}
		
		return true;
	} catch (Exception $e) {
		error_log("Failed to write config: " . $e->getMessage());
		// Reload config after failure
		require_once("config.inc");
		$config = parse_config(true);
		return false;
	} catch (Error $e) {
		error_log("Failed to write config: " . $e->getMessage());
		// Reload config after failure
		require_once("config.inc");
		$config = parse_config(true);
		return false;
	}
}

/**
 * Find a service by name in the config array
 * @param array $config The services config (numeric array)
 * @param string $name The service name to find
 * @return array|null The service data or null if not found
 */
function pc_find_service_by_name(&$config, $name) {
	if (empty($config)) return null;
	foreach ($config as $idx => &$service) {
		if (isset($service['name']) && $service['name'] === $name) {
			return array('index' => $idx, 'service' => &$service);
		}
	}
	return null;
}

/**
 * Add or update a service in the config
 * @param array $config The services config (numeric array)
 * @param string $name The service name
 * @param array $data The service data
 */
function add_or_update_service(&$config, $name, $data) {
	$data['name'] = $name; // Ensure name is set
	$found = pc_find_service_by_name($config, $name);
	if ($found !== null) {
		// Update existing
		$config[$found['index']] = array_merge($config[$found['index']], $data);
	} else {
		// Add new
		$config[] = $data;
	}
}

/**
 * Remove a service by name from the config
 * @param array $config The services config (numeric array)
 * @param string $name The service name to remove
 * @return bool True if removed, false if not found
 */
function remove_service_by_name(&$config, $name) {
	$found = pc_find_service_by_name($config, $name);
	if ($found !== null) {
		array_splice($config, $found['index'], 1);
		return true;
	}
	return false;
}

/**
 * Convert old associative array format to new numeric array format
 * @param array $config The services config
 * @return array The converted config
 */
function convert_to_numeric_array($config) {
	if (empty($config)) return array();
	
	$new_config = array();
	foreach ($config as $key => $service) {
		// If key is not numeric, it's old format
		if (!is_numeric($key)) {
			// Ensure service has a 'name' field
			if (!isset($service['name'])) {
				$service['name'] = $key;
			}
			$new_config[] = $service;
		} else {
			// Already numeric
			$new_config[] = $service;
		}
	}
	return $new_config;
}

/**
 * Send anonymous telemetry data to GitHub repository for feature improvement
 * 
 * Collects anonymous usage statistics to help improve the Online Services feature.
 * NO personally identifiable information is collected (no IPs, MACs, usernames).
 * 
 * @param string $action Action performed (verify, create_alias, monitor_block)
 * @param array $data Additional anonymous data (service name, URL count, status)
 * @return void
 * @since 1.5.0
 */
function pc_send_telemetry($action, $data = array()) {
	// Telemetry endpoint (GitHub Issues API or webhook)
	$telemetry_url = 'https://api.github.com/repos/YOUR_USERNAME/parental-control-telemetry/issues';
	
	// Build anonymous telemetry payload
	$payload = array(
		'timestamp' => time(),
		'version' => '1.5.0',
		'action' => $action,
		'data' => $data
	);
	
	// Log locally for debugging
	error_log("Parental Control Telemetry: " . json_encode($payload));
	
	// TODO: Implement actual GitHub submission
	// For now, just log it locally
	// In future: Send to GitHub Issues API or webhook
	/*
	$options = array(
		'http' => array(
			'method' => 'POST',
			'header' => 'Content-Type: application/json',
			'content' => json_encode($payload),
			'timeout' => 5
		)
	);
	@file_get_contents($telemetry_url, false, stream_context_create($options));
	*/
}

/**
 * Download URLs synchronously and create table files
 * This prevents "Unresolvable alias" errors by ensuring table files exist before filter reload
 * @param string $alias_name The alias name (e.g., PC_Service_YouTube)
 * @param array $urls Array of URLs to download
 * @return bool True if successful, false otherwise
 */
function pc_download_urls_sync($alias_name, $urls) {
	if (empty($urls)) {
		return false;
	}
	
	$table_file = '/var/db/aliastables/' . $alias_name . '.txt';
	@mkdir('/var/db/aliastables', 0755, true);
	
	$all_ips = array();
	
	foreach ($urls as $url) {
		error_log("Downloading URL for {$alias_name}: {$url}");
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		
		$content = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		if ($http_code == 200 && !empty($content)) {
			$lines = explode("\n", $content);
			$count = 0;
			foreach ($lines as $line) {
				$line = trim($line);
				// Skip empty lines and comments
				if (empty($line) || $line[0] == '#') continue;
				$all_ips[] = $line;
				$count++;
			}
			error_log("Downloaded {$count} IPs from {$url}");
		} else {
			error_log("Failed to download {$url} (HTTP {$http_code})");
		}
	}
	
	if (!empty($all_ips)) {
		file_put_contents($table_file, implode("\n", $all_ips) . "\n");
		chmod($table_file, 0644);
		error_log("Wrote " . count($all_ips) . " entries to {$table_file}");
		
		// Load into pf table
		exec('/sbin/pfctl -t ' . escapeshellarg($alias_name) . ' -T replace -f ' . escapeshellarg($table_file) . ' 2>&1', $output, $ret);
		if ($ret == 0) {
			error_log("Loaded {$alias_name} into pf table successfully");
			return true;
		} else {
			error_log("Failed to load {$alias_name} into pf table: " . implode(', ', $output));
			return false;
		}
	}
	
	return false;
}

/**
 * Create or update a URL alias for a service
 * @param string $service_name The service name
 * @param array $service The service configuration
 * @return bool True if alias was created/updated, false otherwise
 */
function pc_create_service_url_alias($service_name, $service) {
	global $config;
	
	// Skip if no URLs
	if (empty($service['urls'])) {
		return false;
	}
	
	// Ensure aliases structure exists
	if (!isset($config['aliases'])) {
		$config['aliases'] = array();
	}
	if (!isset($config['aliases']['alias'])) {
		$config['aliases']['alias'] = array();
	}
	
	// Clean service name for alias (remove spaces, special chars)
	$alias_name = 'PC_Service_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $service_name);
	
	// Get URLs
	$urls = is_array($service['urls']) ? $service['urls'] : explode("\n", $service['urls']);
	$urls = array_filter(array_map('trim', $urls));
	
	// Skip if no valid URLs
	if (empty($urls)) {
		return false;
	}
	
	// Create alias data using pfSense's native URL Table format
	// This matches the format when creating URL (IPs) aliases manually in pfSense UI
	// pfSense will automatically download and update IPs from URLs
	// CRITICAL: Use 'aliasurl' array (not 'url' string) for multiple URLs to show in UI
	// Based on analysis of manually created alias: type='url', aliasurl=array of URLs
	$alias_data = array(
		'name' => $alias_name,
		'type' => 'url',  // pfSense uses 'url' type for URL Table (IPs) aliases
		'aliasurl' => array_values($urls),  // Array of URLs (shows as separate rows in UI)
		'updatefreq' => '7',  // Update frequency in days
		'descr' => "Added By KACI Parental Control (DO NOT EDIT DIRECTLY) - {$service_name}",
		'detail' => 'Entry added ' . date('r')  // Timestamp like manual aliases
	);
	
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
		// Update existing alias
		$config['aliases']['alias'][$alias_index] = $alias_data;
	} else {
		// Create new alias
		$config['aliases']['alias'][] = $alias_data;
	}
	
	return true;
}

/**
 * Remove a URL alias for a service
 * @param string $service_name The service name
 * @return bool True if alias was removed, false if not found
 */
function pc_remove_service_url_alias($service_name) {
	global $config;
	
	if (!isset($config['aliases']['alias'])) {
		return false;
	}
	
	// Clean service name for alias
	$alias_name = 'PC_Service_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $service_name);
	
	// Find and remove the alias
	foreach ($config['aliases']['alias'] as $index => $alias) {
		if (isset($alias['name']) && $alias['name'] === $alias_name) {
			unset($config['aliases']['alias'][$index]);
			// Re-index the array
			$config['aliases']['alias'] = array_values($config['aliases']['alias']);
			return true;
		}
	}
	
	return false;
}

/**
 * Test if a single URL is accessible
 * @param string $url The URL to test
 * @return bool True if accessible, false otherwise
 */
function pc_test_url_accessibility($url) {
	if (empty($url)) {
		return false;
	}
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
	
	$result = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	
	// Consider 2xx and 3xx as success
	return ($http_code >= 200 && $http_code < 400);
}

/**
 * Verify service URLs by attempting to fetch them
 * @param string $service_name The service name
 * @param array $service_config The service configuration
 * @return array Result with 'success', 'verified_count', 'total_count', and optionally 'error'
 */
function pc_verify_service_urls($service_name, $service_config) {
	if (empty($service_config['urls'])) {
		return array(
			'success' => false,
			'error' => 'No URLs configured for this service',
			'verified_count' => 0,
			'total_count' => 0,
			'url_statuses' => array()
		);
	}
	
	// Handle both string (newline-separated) and array formats
	if (is_array($service_config['urls'])) {
		$urls = array_filter(array_map('trim', $service_config['urls']));
	} else {
		$urls = array_filter(array_map('trim', explode("\n", $service_config['urls'])));
	}
	$total_count = count($urls);
	$verified_count = 0;
	$errors = array();
	$url_statuses = array();
	
	foreach ($urls as $idx => $url) {
		// Skip empty lines and comments
		if (empty($url) || strpos($url, '#') === 0) {
			continue;
		}
		
		// Verify URL is accessible
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		curl_setopt($ch, CURLOPT_NOBODY, false); // Get content for basic analysis
		
		$content = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);
		
		// Consider 2xx and 3xx as success
		$is_active = ($http_code >= 200 && $http_code < 400);
		$content_type = 'unknown';
		
		if ($is_active && !empty($content)) {
			// Quick content type detection (first 5 lines for telemetry only)
			$lines = array_slice(explode("\n", $content), 0, 5);
			$has_ip = false;
			$has_domain = false;
			
			foreach ($lines as $line) {
				$line = trim($line);
				if (empty($line) || strpos($line, '#') === 0) continue;
				
				if (preg_match('/^\d+\.\d+\.\d+\.\d+/', $line)) {
					$has_ip = true;
				} elseif (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $line)) {
					$has_domain = true;
				}
			}
			
			// Simple classification
			if ($has_domain && !$has_ip) {
				$content_type = 'domains';
			} elseif ($has_ip && !$has_domain) {
				$content_type = 'ips';
			} elseif ($has_ip && $has_domain) {
				$content_type = 'mixed';
			}
			
			$verified_count++;
		} else {
			$errors[] = "{$url}: HTTP {$http_code}";
		}
		
		// Store individual URL status
		$url_statuses[$idx] = array(
			'active' => $is_active,
			'last_tested' => time(),
			'http_code' => $http_code,
			'content_type' => $content_type,
			'has_domains' => ($content_type === 'domains' || $content_type === 'mixed')
		);
	}
	
	$success = $verified_count > 0;
	$result = array(
		'success' => $success,
		'verified_count' => $verified_count,
		'total_count' => $total_count,
		'url_statuses' => $url_statuses
	);
	
	if (!$success && !empty($errors)) {
		$result['error'] = implode('; ', array_slice($errors, 0, 3)); // Show first 3 errors
	}
	
	// INFO: Detect domain lists vs IP lists for informational purposes
	// NOTE: pfSense URL aliases CAN handle domain lists - they resolve domains to IPs automatically
	// This detection is kept for future analytics but warnings removed (pfSense handles both types)
	$domain_urls = array();
	$ip_urls = array();
	foreach ($url_statuses as $idx => $status) {
		if (!empty($status['has_domains'])) {
			$domain_urls[] = $urls[$idx];
		}
		if (!empty($status['content_type']) && $status['content_type'] === 'ips') {
			$ip_urls[] = $urls[$idx];
		}
	}
	
	// Store detection results for telemetry (no user-facing warnings)
	if (!empty($domain_urls)) {
		$result['domain_detected'] = true;
		$result['domain_count'] = count($domain_urls);
	}
	if (!empty($ip_urls)) {
		$result['ip_detected'] = true;
		$result['ip_count'] = count($ip_urls);
	}
	
	return $result;
}

// Default services with pre-populated URLs (NUMERIC ARRAY - XML-safe!)
$default_services = array(
	array(
		'name' => 'YouTube',
		'urls' => array(
			'https://raw.githubusercontent.com/touhidurrr/iplist-youtube/main/lists/cidr4.txt',
			'https://raw.githubusercontent.com/touhidurrr/iplist-youtube/main/lists/ipv4.txt',
			'https://raw.githubusercontent.com/touhidurrr/iplist-youtube/main/lists/cidr6.txt',
			'https://raw.githubusercontent.com/touhidurrr/iplist-youtube/main/lists/ipv6.txt'
		),
		'description' => 'Video streaming service',
		'icon' => 'fa-youtube-play'
	),
	array(
		'name' => 'Facebook',
		'urls' => array(
			'https://raw.githubusercontent.com/SecOps-Institute/FacebookIPLists/refs/heads/master/facebook_ip_list.lst',
			'https://raw.githubusercontent.com/SecOps-Institute/FacebookIPLists/refs/heads/master/facebook_ipv4_cidr_blocks.lst',
			'https://raw.githubusercontent.com/SecOps-Institute/FacebookIPLists/refs/heads/master/facebook_ipv6_list.lst'
		),
		'description' => 'Social media platform (includes Instagram, WhatsApp)',
		'icon' => 'fa-facebook'
	),
	array(
		'name' => 'Discord',
		'urls' => array(
			// Placeholder - Discord requires DNS-based blocking, not IP-based
			// Add your own IP list URL here if available
		),
		'description' => '⚠️ Voice, video, and text communication platform (IP-based blocking not recommended - use DNS filtering instead)',
		'icon' => 'fa-comments',
		'note' => 'Discord does not publish official IP lists and uses Cloudflare CDN. For effective Discord blocking, use pfSense DNS Resolver with domain blocking instead of IP-based firewall rules.'
	),
	array(
		'name' => 'TikTok',
		'urls' => array(
			'https://raw.githubusercontent.com/PeterDaveHello/threat-hostlist/master/hosts'
		),
		'description' => 'Short-form video platform',
		'icon' => 'fa-video-camera'
	),
	array(
		'name' => 'Netflix',
		'urls' => array(
			'https://raw.githubusercontent.com/SecOps-Institute/NetflixIPLists/master/netflix_ips.txt'
		),
		'description' => 'Streaming entertainment service',
		'icon' => 'fa-film'
	),
	array(
		'name' => 'Twitch',
		'urls' => array(
			'https://raw.githubusercontent.com/SecOps-Institute/TwitchIPLists/master/twitch_ips.txt'
		),
		'description' => 'Live streaming platform',
		'icon' => 'fa-twitch'
	)
);

// Load current services config
$services_config = config_get_path('installedpackages/parentalcontrolservices/config', array());

// Convert old format if needed (associative array -> numeric array)
$services_config = convert_to_numeric_array($services_config);

// Initialize with default services if none exist
if (empty($services_config)) {
	$services_config = $default_services;
}

// CRITICAL FIX v1.4.10+: Merge session URLs with config URLs
// BUG: Temporary URLs added via add_url were lost on page reload
// ROOT CAUSE: $services_config reloaded fresh from config, losing session URLs
// SOLUTION: Restore session URLs after loading config
if (!isset($_SESSION['pc_service_urls'])) {
	$_SESSION['pc_service_urls'] = array();
}

// Merge session URLs into loaded services
foreach ($services_config as $idx => $service) {
	$service_name = $service['name'];
	if (isset($_SESSION['pc_service_urls'][$service_name])) {
		// Merge session URLs with config URLs
		$config_urls = isset($service['urls']) ? (array)$service['urls'] : array();
		$session_urls = $_SESSION['pc_service_urls'][$service_name];
		$services_config[$idx]['urls'] = array_unique(array_merge($config_urls, $session_urls));
	}
}

// Handle form submissions
if ($_POST) {
	if (isset($_POST['action'])) {
		switch ($_POST['action']) {
			case 'add_service':
				$service_name = trim($_POST['service_name']);
				if (!empty($service_name)) {
					$existing = pc_find_service_by_name($services_config, $service_name);
					if ($existing === null) {
						add_or_update_service($services_config, $service_name, array(
							'urls' => array($_POST['service_url']),
							'description' => trim($_POST['service_description']),
							'icon' => 'fa-globe',
							'enabled' => 'on',
							'last_update' => 0,
							'ip_count' => 0
						));
					config_set_path('installedpackages/parentalcontrolservices/config', $services_config);
					safe_write_config("Added new service: {$service_name}");
						pc_log("Added new online service: {$service_name}", 'info');
						$savemsg = "Service '{$service_name}' added successfully.";
					}
				}
				break;
			
			case 'delete_service':
				$service_name = $_POST['service_name'];
				if (remove_service_by_name($services_config, $service_name)) {
					// Delete associated pfSense alias
					pc_delete_service_alias($service_name);
				config_set_path('installedpackages/parentalcontrolservices/config', $services_config);
				safe_write_config("Deleted service: {$service_name}");
					pc_log("Deleted online service: {$service_name}", 'info');
					$savemsg = "Service '{$service_name}' deleted successfully.";
				}
				break;
			
		case 'add_url':
			$service_name = $_POST['service_name'];
			$new_url = trim($_POST['new_url']);
			if (!empty($new_url)) {
				$found = pc_find_service_by_name($services_config, $service_name);
				if ($found !== null) {
					// CRITICAL FIX v1.4.10+: Store URL in PHP session for persistence
					// Initialize session storage for this service
					if (!isset($_SESSION['pc_service_urls'][$service_name])) {
						$_SESSION['pc_service_urls'][$service_name] = array();
					}
					
					// Check if URL already exists (in config or session)
					$existing_urls = isset($found['service']['urls']) ? (array)$found['service']['urls'] : array();
					$all_urls = array_merge($existing_urls, $_SESSION['pc_service_urls'][$service_name]);
					
					if (!in_array($new_url, $all_urls)) {
						// Add to session storage
						$_SESSION['pc_service_urls'][$service_name][] = $new_url;
						
						// Also update in-memory config for current request
						if (!isset($services_config[$found['index']]['urls'])) {
							$services_config[$found['index']]['urls'] = array();
						}
						$services_config[$found['index']]['urls'][] = $new_url;
						
						// NOTE: Not saving to config to avoid corruption
						// URLs are session-based until alias is created
						$savemsg = "URL added to '{$service_name}' for this session. Click [Verify] to check, then [Monitor&Block] to make it permanent.";
						error_log("Added URL to {$service_name} (session storage): {$new_url}");
					} else {
						$savemsg = "URL already exists in '{$service_name}'.";
					}
				}
			}
			break;
			
		case 'delete_url':
			$service_name = $_POST['service_name'];
			$url_index = intval($_POST['url_index']);
			$found = pc_find_service_by_name($services_config, $service_name);
			if ($found !== null && isset($found['service']['urls'][$url_index])) {
				$deleted_url = $found['service']['urls'][$url_index];
				
				// CRITICAL FIX v1.4.10+: Also remove from session storage
				if (isset($_SESSION['pc_service_urls'][$service_name])) {
					$key = array_search($deleted_url, $_SESSION['pc_service_urls'][$service_name]);
					if ($key !== false) {
						unset($_SESSION['pc_service_urls'][$service_name][$key]);
						$_SESSION['pc_service_urls'][$service_name] = array_values($_SESSION['pc_service_urls'][$service_name]); // Reindex
					}
				}
				
				// Remove the URL
				array_splice($found['service']['urls'], $url_index, 1);
				
				// Also remove the status for this URL
				if (isset($found['service']['url_status'])) {
					array_splice($found['service']['url_status'], $url_index, 1);
				}
				
				$services_config[$found['index']] = $found['service'];
				
				// NOTE: Not saving to config to avoid corruption
				// Changes are session-based only
				$savemsg = "URL removed from '{$service_name}' for this session.";
				error_log("Deleted URL from {$service_name} (session only): {$deleted_url}");
			}
			break;
			
		case 'verify_fetch':
			$service_name = $_POST['service_name'];
			$found = pc_find_service_by_name($services_config, $service_name);
			if ($found !== null) {
				// Send telemetry: URL verification initiated
				pc_send_telemetry('verify_urls', array(
					'service' => $service_name,
					'url_count' => count($found['service']['urls'])
				));
				
				$result = pc_verify_service_urls($service_name, $found['service']);
				if ($result['success']) {
					// Update status in memory (for current page view)
					// Note: Not saved to config to avoid write errors
					$services_config[$found['index']]['last_verified'] = time();
					$services_config[$found['index']]['verified'] = true;
					$services_config[$found['index']]['verified_urls'] = $result['verified_count'];
					
					// Send telemetry: Successful URL verification
					pc_send_telemetry('verify_urls_success', array(
						'service' => $service_name,
						'total_urls' => count($found['service']['urls']),
						'verified_urls' => $result['verified_count'],
						'status_results' => $result['statuses'],
						'has_domains' => isset($result['domain_detected']) ? $result['domain_detected'] : false,
						'has_ips' => isset($result['ip_detected']) ? $result['ip_detected'] : false
					));
					
					// Update individual URL statuses in memory
					if (isset($result['url_statuses'])) {
						$services_config[$found['index']]['url_status'] = $result['url_statuses'];
					}
					
					// Don't write verification results to config - causes write failures
					// Status will persist for current session/page view only
					// config_set_path('installedpackages/parentalcontrolservices/config', $services_config);
					// safe_write_config("Verified URLs for service: {$service_name}");
					
					$savemsg = "Successfully verified {$result['verified_count']} of {$result['total_count']} URLs for '{$service_name}'. Status shown for this session only.";
					
					// INFO: Domain detection kept for telemetry only (no user warnings)
					// pfSense URL aliases handle both domain lists and IP lists correctly
				} else {
					$input_errors[] = "Failed to verify '{$service_name}': {$result['error']}";
				}
			}
			break;
			
	case 'create_alias':
		$service_name = $_POST['service_name'];
		$found = pc_find_service_by_name($services_config, $service_name);
		if ($found !== null) {
			$service = $found['service'];
			
			// Send telemetry: Monitor&Block action initiated
			pc_send_telemetry('create_alias_monitor_block', array(
				'service' => $service_name,
				'url_count' => count($service['urls'])
			));
			
			// Check if service has any URLs (don't require 'verified' flag since it's session-based)
			if (empty($service['urls'])) {
				$input_errors[] = "Service '{$service_name}' has no URLs configured.";
				break;
			}
			
			// Create the URL alias using pfSense's native URL Table format
			// pfSense will automatically download and manage the IP lists
			if (pc_create_service_url_alias($service_name, $service)) {
				// Write config to persist the alias
				try {
					write_config("Created URL Table alias for service: {$service_name}");
					
					// Get the alias name that was created
					$alias_name = 'PC_Service_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $service_name);
					
					error_log("Created URL Table alias: {$alias_name} with " . count($service['urls']) . " URL(s)");
					
					require_once("/usr/local/pkg/parental_control.inc");
					
					// Download URLs synchronously to prevent "Unresolvable alias" errors
					error_log("Downloading URLs synchronously for: {$alias_name}");
					pc_download_urls_sync($alias_name, $service['urls']);
					
					// Mark aliases as dirty to trigger pfSense's URL download
					mark_subsystem_dirty('aliases');
					
					// Create service monitoring rules for this alias
					if (function_exists('pc_create_service_monitoring_rules')) {
						pc_create_service_monitoring_rules();
						error_log("Created service monitoring rules for: {$alias_name}");
					}
					
					// Reload filter - table files already exist, so no "Unresolvable" errors
					require_once("/etc/inc/filter.inc");
					filter_configure();
					error_log("Filter reload completed successfully");
					
					$savemsg = "Successfully created URL Table alias '{$alias_name}' with " . count($service['urls']) . " URL(s) and monitoring rules. ";
					$savemsg .= "pfSense will automatically download and update the IP lists (updates every 7 days). ";
					$savemsg .= "<br><strong>Note:</strong> Go to <a href='parental_control_profiles.php'>Profiles page</a> to set time limits for this service.";
					error_log("Created URL Table alias for service: {$service_name} (alias: {$alias_name})");
					
					// Send telemetry: Successful alias creation
					pc_send_telemetry('alias_created_success', array(
						'service' => $service_name,
						'url_count' => count($service['urls']),
						'alias_name' => $alias_name
					));
					
					// CRITICAL FIX v1.4.10+: Save session URLs to config and clear session
					// BUG: Session URLs were not persisted to config after alias creation
					// SOLUTION: Save URLs to config and clear session after successful alias creation
					if (isset($_SESSION['pc_service_urls'][$service_name]) && !empty($_SESSION['pc_service_urls'][$service_name])) {
						// Update config with session URLs
						$config_services = config_get_path('installedpackages/parentalcontrolservices/config', array());
						$config_services = convert_to_numeric_array($config_services);
						
						foreach ($config_services as $idx => $cfg_service) {
							if ($cfg_service['name'] === $service_name) {
								// Merge session URLs with existing config URLs
								$existing_urls = isset($cfg_service['urls']) ? (array)$cfg_service['urls'] : array();
								$all_urls = array_unique(array_merge($existing_urls, $_SESSION['pc_service_urls'][$service_name]));
								$config_services[$idx]['urls'] = $all_urls;
								break;
							}
						}
						
						// Save updated config
						config_set_path('installedpackages/parentalcontrolservices/config', $config_services);
						write_config("Saved session URLs for service: {$service_name}");
						error_log("Saved " . count($_SESSION['pc_service_urls'][$service_name]) . " session URL(s) to config for: {$service_name}");
						
						// Clear session URLs for this service (they're now in config)
						unset($_SESSION['pc_service_urls'][$service_name]);
					}
				} catch (Exception $e) {
					$input_errors[] = "Failed to save URL alias for '{$service_name}': " . $e->getMessage();
					error_log("Failed to write config for alias: " . $e->getMessage());
					
					// Send telemetry: Failed alias creation
					pc_send_telemetry('alias_created_failed', array(
						'service' => $service_name,
						'error' => $e->getMessage()
					));
				}
			} else {
				$input_errors[] = "Failed to create URL alias for '{$service_name}'. Check system logs for details.";
			}
		}
		break;
			
	case 'save_enabled':
		// Get list of enabled services from checkboxes
		$enabled_services = isset($_POST['enabled_services']) ? $_POST['enabled_services'] : array();
		
		// NOTE: Saving enabled/disabled state is currently disabled due to config write issues
		// The checkboxes will work for the current session but won't persist after page refresh
		// This is intentional to prevent config corruption
		
		$enabled_count = count($enabled_services);
		$total_count = count($services_config);
		$disabled_count = $total_count - $enabled_count;
		
		// Update in memory only (not saved to config)
		foreach ($services_config as $idx => &$service) {
			$service_name = isset($service['name']) ? $service['name'] : "Service {$idx}";
			$is_enabled = in_array($service_name, $enabled_services);
			
			if ($is_enabled) {
				$service['enabled'] = 'on';
			} else {
				unset($service['enabled']);
			}
		}
		unset($service);
		
		// DO NOT write to config - causes restore loops
		// config_set_path('installedpackages/parentalcontrolservices/config', $services_config);
		// safe_write_config("Updated enabled services");
		
		$savemsg = "Status updated for this session: {$enabled_count} service(s) checked, {$disabled_count} unchecked. " .
		           "Note: Changes won't persist after page refresh - this is intentional to prevent config issues.";
		
		// Log without pc_log to avoid config issues
		error_log("Services enabled/disabled: {$enabled_count} enabled, {$disabled_count} disabled (session only)");
		break;
		}
	}
}

include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=parental_control.xml&id=0");
$tab_array[] = array(gettext("Profiles"), false, "/parental_control_profiles.php");
$tab_array[] = array(gettext("KACI-PC-Schedule"), false, "/parental_control_schedules.php");
$tab_array[] = array(gettext("Online-Service"), true, "/parental_control_services.php");
$tab_array[] = array(gettext("Status"), false, "/parental_control_status.php");
display_top_tabs($tab_array);

if (isset($savemsg)) {
	print_info_box($savemsg, 'success');
}

if (isset($input_errors) && count($input_errors) > 0) {
	print_input_errors($input_errors);
}

?>

<style>
.service-card {
	border: 1px solid #ddd;
	border-radius: 8px;
	padding: 15px;
	margin-bottom: 15px;
	background: #fff;
	box-shadow: 0 2px 4px rgba(0,0,0,0.05);
	transition: all 0.3s ease;
}

.service-card:hover {
	box-shadow: 0 4px 8px rgba(0,0,0,0.1);
	transform: translateY(-2px);
}

.service-card.disabled {
	opacity: 0.6;
	background: #f8f9fa;
}

.service-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
}

.service-title {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 18px;
	font-weight: bold;
	color: #000;
}

.service-icon {
	font-size: 24px;
	color: #667eea;
}

.service-stats {
	display: flex;
	gap: 20px;
	font-size: 12px;
	color: #000;
	margin-bottom: 10px;
}

.service-stat {
	display: flex;
	align-items: center;
	gap: 5px;
}

.service-urls {
	margin: 10px 0;
}

.url-item {
	display: flex;
	align-items: center;
	gap: 5px;
	padding: 8px;
	background: #f8f9fa;
	border-radius: 4px;
	margin-bottom: 5px;
	font-size: 12px;
	font-family: monospace;
	color: #000;
}

.url-text {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.url-status-badge {
	flex-shrink: 0;
}

.url-status-badge span {
	white-space: nowrap;
	cursor: help;
}

.service-actions {
	display: flex;
	gap: 5px;
	flex-wrap: wrap;
}

.btn-xs {
	padding: 2px 8px;
	font-size: 11px;
}

.add-url-form {
	margin-top: 10px;
	padding-top: 10px;
	border-top: 1px solid #eee;
}

.badge-success {
	background-color: #28a745;
}

.badge-warning {
	background-color: #ffc107;
	color: #333;
}

.badge-secondary {
	background-color: #6c757d;
}

.info-banner {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: white;
	padding: 15px 20px;
	border-radius: 8px;
	margin-bottom: 20px;
}

.info-banner h4 {
	margin: 0 0 10px 0;
	font-size: 18px;
}

.info-banner p {
	margin: 5px 0;
	font-size: 14px;
}
</style>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<i class="fa fa-globe"></i> <?=gettext("Online Services IP Management")?>
		</h2>
	</div>
	<div class="panel-body">
		
		<!-- EXPERIMENTAL FEATURE WARNING -->
		<div class="alert alert-warning" style="border-left: 5px solid #ff9800; background-color: #fff3cd; padding: 15px; margin-bottom: 20px;">
			<h4 style="color: #856404; margin-top: 0;">
				<i class="fa fa-exclamation-triangle"></i> <strong>EXPERIMENTAL FEATURE - Data Collection Notice</strong>
			</h4>
			<p style="color: #856404; margin-bottom: 10px;">
				<strong>⚠️ This feature is currently in EXPERIMENTAL stage.</strong> 
				It may undergo significant changes in future releases.
			</p>
			<p style="color: #856404; margin-bottom: 10px;">
				<strong>📊 Telemetry & Data Collection:</strong> 
				When you perform actions on this page (verify URLs, create aliases, monitor services), 
				<strong>anonymous usage data will be submitted to our GitHub repository</strong> to help improve the feature.
			</p>
			<p style="color: #856404; margin-bottom: 0;">
				<strong>Data collected includes:</strong> Service names, URL status (active/dead), 
				action timestamps, and feature usage patterns. 
				<strong>No personally identifiable information (IP addresses, device MACs, usernames) is collected.</strong>
			</p>
		</div>
		
		<div class="info-banner">
			<h4><i class="fa fa-info-circle"></i> Online Services - URL Management</h4>
			<p>This feature manages URL sources for blocking popular online services (YouTube, Facebook, etc.) using <strong>URL Alias Tables</strong>.</p>
			<p><strong>How it works:</strong> Store GitHub repository URLs that contain service IPs. Use these URLs in pfSense URL Alias Tables for DNS-based or IP-based blocking.</p>
			<p><strong>URL Status Badges:</strong> 
			<span style="background: #28a745; color: white; padding: 3px 6px; border-radius: 3px; font-size: 10px;"><i class="fa fa-check-circle"></i> ACTIVE</span> | 
			<span style="background: #dc3545; color: white; padding: 3px 6px; border-radius: 3px; font-size: 10px;"><i class="fa fa-times-circle"></i> DEAD</span> | 
			<span style="background: #6c757d; color: white; padding: 3px 6px; border-radius: 3px; font-size: 10px;"><i class="fa fa-question-circle"></i> UNKNOWN</span> 
			— Click <strong>[Verify & Fetch]</strong> button to check all URLs for a service.
			</p>
			<p><strong>Workflow:</strong> Add/edit URLs (session) → Click <strong>[Verify]</strong> to test (optional) → Click <strong>[Monitor&Block]</strong> to create alias and rules.</p>
		<p><strong>Note:</strong> URL changes are session-based. Click <strong>[Monitor&Block]</strong> to create a permanent firewall alias and monitoring rules.</p>
		</div>

		<?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
		<div class="alert alert-info">
			<strong><i class="fa fa-bug"></i> Debug Info - Saved Configuration:</strong>
			<pre style="background: #f8f9fa; padding: 10px; margin-top: 10px; border-radius: 4px; font-size: 11px; max-height: 300px; overflow: auto;"><?php
				foreach ($services_config as $svc_name => $svc_data) {
					echo "Service: {$svc_name}\n";
					echo "  enabled: " . (isset($svc_data['enabled']) ? var_export($svc_data['enabled'], true) : 'NOT SET') . "\n";
					echo "  URLs: " . count($svc_data['urls'] ?? []) . "\n\n";
				}
			?></pre>
			<small><i class="fa fa-info-circle"></i> To hide this, remove <code>?debug=1</code> from URL</small>
		</div>
		<?php endif; ?>

		<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
			<div style="display: flex; gap: 10px; align-items: center;">
				<button type="button" class="btn btn-success" onclick="$('#addServiceModal').modal('show')">
					<i class="fa fa-plus"></i> Add New Service
			</button>
		</div>
			<div>
				<span class="badge badge-info"><?=count($services_config)?> Services</span>
			</div>
		</div>
		
	<?php if (empty($services_config)): ?>
			<div class="alert alert-info">
				<i class="fa fa-info-circle"></i> No services configured yet. Click "Add New Service" to get started.
			</div>
		<?php else: ?>
		<div id="services-container">
			<?php foreach ($services_config as $idx => $service): 
				// Get the actual service name from the service array
				$service_name = isset($service['name']) ? $service['name'] : "Service {$idx}";
			?>
				<div class="service-card">
					<div class="service-header">
						<div class="service-title">
							<div style="display: flex; align-items: center; gap: 10px;">
								<i class="fa <?=htmlspecialchars($service['icon'] ?? 'fa-globe')?> service-icon"></i>
								<span><?=htmlspecialchars($service_name)?></span>
							</div>
						</div>
					<div class="service-actions">
						<!-- Individual action buttons -->
						<form method="post" action="parental_control_services.php" style="display:inline;">
							<input type="hidden" name="action" value="verify_fetch">
							<input type="hidden" name="service_name" value="<?=htmlspecialchars($service_name)?>">
							<button type="submit" class="btn btn-xs btn-primary" 
								onclick="return confirm('Verify URL accessibility for <?=htmlspecialchars($service_name)?>?');">
								<i class="fa fa-check-circle"></i> Verify
							</button>
						</form>
						<form method="post" action="parental_control_services.php" style="display:inline;">
							<input type="hidden" name="action" value="create_alias">
							<input type="hidden" name="service_name" value="<?=htmlspecialchars($service_name)?>">
							<button type="submit" class="btn btn-xs btn-success" 
								onclick="return confirm('Monitor and Block <?=htmlspecialchars($service_name)?> for Parental Control?\n\nThis will create a firewall alias and tracking rules.');">
								<i class="fa fa-shield"></i> Monitor&Block
							</button>
						</form>
						<form method="post" action="parental_control_services.php" style="display:inline;">
							<input type="hidden" name="action" value="delete_service">
							<input type="hidden" name="service_name" value="<?=htmlspecialchars($service_name)?>">
							<button type="submit" class="btn btn-xs btn-danger" 
								onclick="return confirm('Delete service <?=htmlspecialchars($service_name)?>?\n\nThis will remove all stored URLs.');">
								<i class="fa fa-trash"></i> Delete
							</button>
						</form>
					</div>
						</div>
						
						<?php if (isset($service['description'])): ?>
							<div style="color: #666; font-size: 13px; margin-bottom: 10px;">
								<?=htmlspecialchars($service['description'])?>
							</div>
						<?php endif; ?>
						
						<div class="service-stats">
							<div class="service-stat">
								<i class="fa fa-list"></i>
								<span><strong><?=count($service['urls'] ?? array())?></strong> URL sources</span>
							</div>
							<div class="service-stat">
								<i class="fa fa-check-circle"></i>
								<span>Status: <?=isset($service['verified']) && $service['verified'] ? '<strong style="color: #28a745;">Verified</strong>' : '<strong style="color: #6c757d;">Not verified</strong>'?></span>
							</div>
							<div class="service-stat">
								<i class="fa fa-clock-o"></i>
								<span>Last verified: <?=isset($service['last_verified']) && $service['last_verified'] > 0 ? date('Y-m-d H:i', $service['last_verified']) : 'Never'?></span>
							</div>
						</div>
						
						<div class="service-urls">
							<strong style="font-size: 13px;">IP List URLs:</strong>
							<?php if (!empty($service['urls'])): ?>
								<?php foreach ($service['urls'] as $idx => $url): 
									$url_status = isset($service['url_status'][$idx]) ? $service['url_status'][$idx] : null;
									$is_active = $url_status && isset($url_status['active']) ? $url_status['active'] : null;
									$last_tested = $url_status && isset($url_status['last_tested']) ? $url_status['last_tested'] : null;
								?>
									<div class="url-item">
										<span class="url-status-badge" style="display: inline-block; min-width: 70px; text-align: center; margin-right: 10px;">
										<?php if ($is_active !== null): ?>
											<?php if ($is_active): ?>
												<span style="background: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;" title="Last tested: <?=date('Y-m-d H:i', $last_tested)?>">
													<i class="fa fa-check-circle"></i> ACTIVE
												</span>
											<?php else: ?>
												<span style="background: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;" title="Last tested: <?=date('Y-m-d H:i', $last_tested)?>">
													<i class="fa fa-times-circle"></i> DEAD
												</span>
											<?php endif; ?>
										<?php else: ?>
											<span style="background: #6c757d; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;" title="Click [Test] to check">
												<i class="fa fa-question-circle"></i> UNKNOWN
											</span>
										<?php endif; ?>
										</span>
					<span class="url-text" title="<?=htmlspecialchars($url)?>">
						<?=htmlspecialchars($url)?>
					</span>
					<form method="post" action="parental_control_services.php" style="display:inline; margin-left: 5px;">
											<input type="hidden" name="action" value="delete_url">
											<input type="hidden" name="service_name" value="<?=htmlspecialchars($service_name)?>">
											<input type="hidden" name="url_index" value="<?=$idx?>">
											<button type="submit" class="btn btn-xs btn-danger" 
												onclick="return confirm('Delete this URL?');">
												<i class="fa fa-times"></i>
											</button>
										</form>
									</div>
								<?php endforeach; ?>
							<?php else: ?>
								<div class="alert alert-warning" style="margin: 5px 0; padding: 8px;">
									No URL sources configured.
								</div>
							<?php endif; ?>
							
							<div class="add-url-form">
								<form method="post" action="parental_control_services.php" onsubmit="return validateUrl(this);">
									<input type="hidden" name="action" value="add_url">
									<input type="hidden" name="service_name" value="<?=htmlspecialchars($service_name)?>">
									<div class="input-group input-group-sm">
										<input type="text" class="form-control" name="new_url" 
											placeholder="Enter GitHub raw URL for IP list..." style="font-size: 12px;" required>
										<span class="input-group-btn">
											<button class="btn btn-success" type="submit">
												<i class="fa fa-plus"></i> Add URL
											</button>
										</span>
									</div>
								</form>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			
	<?php endif; ?>
	</div>
</div>

<!-- Add Service Modal -->
<div class="modal fade" id="addServiceModal" tabindex="-1" role="dialog">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<form method="post" action="parental_control_services.php">
				<input type="hidden" name="action" value="add_service">
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal">&times;</button>
					<h4 class="modal-title"><i class="fa fa-plus"></i> Add New Service</h4>
				</div>
				<div class="modal-body">
					<div class="form-group">
						<label>Service Name *</label>
						<input type="text" name="service_name" class="form-control" required 
							placeholder="e.g., YouTube, Facebook, Discord">
						<small class="help-block">Unique name for this service</small>
					</div>
					<div class="form-group">
						<label>Description</label>
						<input type="text" name="service_description" class="form-control" 
							placeholder="Brief description of the service">
					</div>
					<div class="form-group">
						<label>Initial IP List URL</label>
						<input type="url" name="service_url" class="form-control" 
							placeholder="https://raw.githubusercontent.com/...">
						<small class="help-block">You can add more URLs after creating the service</small>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-success">
						<i class="fa fa-plus"></i> Add Service
					</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script>
// Simple URL validation for add URL form
function validateUrl(form) {
	var urlInput = form.querySelector('input[name="new_url"]');
	var url = urlInput.value.trim();
	
	if (!url) {
		alert('Please enter a URL');
		return false;
	}
	
	if (!url.startsWith('http://') && !url.startsWith('https://')) {
		alert('URL must start with http:// or https://');
		return false;
	}
	
	return true;
}
</script>

<!-- Package Footer -->
<div style="text-align: center; margin-top: 30px; padding: 15px; border-top: 2px solid #ddd; background: #f9f9f9;">
	<p style="margin: 5px 0; color: #666; font-size: 13px;">
		<strong>Keekar's Parental Control</strong> v<?php echo PC_VERSION; ?> 
		<span style="margin: 0 10px;">|</span>
		Built with Passion by <strong>Mukesh Kesharwani</strong>
		<span style="margin: 0 10px;">|</span>
		© <?php echo date('Y'); ?> Keekar
	</p>
	<p style="margin: 5px 0; color: #999; font-size: 11px;">
		Build Date: <?php echo defined('PC_BUILD_DATE') ? PC_BUILD_DATE : '2025-12-24'; ?>
	</p>
</div>

<?php include("foot.inc"); ?>

