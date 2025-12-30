<?php
/*
 * parental_control_captive.php
 * 
 * Standalone captive portal block page (NO AUTHENTICATION REQUIRED)
 * Served by dedicated PHP server on port 1008
 * 
 * ROUTER SCRIPT: Serves multiple files without authentication
 * - /index.html - Project landing page
 * - / or /block - Parental control block page
 * 
 * Part of KACI-Parental_Control for pfSense
 * Copyright (c) 2025 Mukesh Kesharwani
 */

// ============================================================================
// ROUTER LOGIC - Handle different URI requests
// ============================================================================

$request_uri = $_SERVER['REQUEST_URI'];
$request_path = parse_url($request_uri, PHP_URL_PATH);

// Serve index.html for project landing page
if ($request_path === '/index.html' || $request_path === '/index') {
    $index_file = '/usr/local/www/index.html';
    
    if (file_exists($index_file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index_file);
        exit;
    } else {
        // Fallback: serve a simple landing page
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
<html>
<head>
    <title>KACI Parental Control</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        h1 { color: #2c3e50; }
        a { color: #3498db; }
    </style>
</head>
<body>
    <h1>KACI Parental Control for pfSense</h1>
    <p>A comprehensive parental control solution for pfSense firewalls.</p>
    <p><a href="https://github.com/keekar2022/KACI-Parental_Control">View on GitHub</a></p>
    <hr>
    <p><small>Captive Portal Server - Port 1008 - No Authentication Required</small></p>
</body>
</html>';
        exit;
    }
}

// Serve static files from document root (CSS, JS, images, etc.)
if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $request_path)) {
    $file_path = '/usr/local/www' . $request_path;
    
    if (file_exists($file_path) && is_file($file_path)) {
        // Determine content type
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];
        
        $content_type = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
        header("Content-Type: $content_type");
        readfile($file_path);
        exit;
    } else {
        http_response_code(404);
        echo "File not found: " . htmlspecialchars($request_path);
        exit;
    }
}

// ============================================================================
// DEFAULT: SERVE BLOCK PAGE
// ============================================================================

// No pfSense authentication - this is standalone!
// We only need config and state access
require_once("/etc/inc/config.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Get client information
$client_ip = $_SERVER['REMOTE_ADDR'];
$client_mac = null;

// Try to find MAC address from ARP table
exec("arp -an | grep " . escapeshellarg($client_ip), $arp_output);
if (!empty($arp_output)) {
	if (preg_match('/([0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2})/i', $arp_output[0], $matches)) {
		$client_mac = strtolower($matches[1]);
	}
}

// Load state and configuration
$state = pc_load_state();
$pc_config = config_get_path('installedpackages/parentalcontrol/config/0', []);
$blocked_message = isset($pc_config['blocked_message']) ? $pc_config['blocked_message'] : 'Your internet time is up! Time to take a break and do other activities.';

// Find device information
$device_info = null;
$profile_info = null;
$block_reason = 'Access Restricted';
$usage_today = 0;
$usage_limit = 0;

if ($client_mac) {
	$devices = pc_get_devices();
	foreach ($devices as $device) {
		if (pc_normalize_mac($device['mac_address']) === $client_mac) {
			$device_info = $device;
			break;
		}
	}
	
	// Get usage from state
	if (isset($state['devices_by_ip'][$client_ip])) {
		$usage_today = $state['devices_by_ip'][$client_ip]['usage_today'];
	}
	
	// Get profile information
	if ($device_info) {
		$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
		foreach ($profiles as $profile) {
			if ($profile['name'] === $device_info['child_name']) {
				$profile_info = $profile;
				$usage_limit = isset($profile['daily_limit']) ? intval($profile['daily_limit']) : 0;
				break;
			}
		}
		
		// Determine block reason
		if (pc_is_in_blocked_schedule($device_info)) {
			$block_reason = 'Scheduled Block Time';
		} elseif (pc_is_time_limit_exceeded($device_info, $state)) {
			$block_reason = 'Daily Time Limit Exceeded';
		}
	}
}

// Handle parent override request
$override_error = null;
$override_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['override_password'])) {
	$submitted_password = $_POST['override_password'];
	$configured_password = config_get_path('installedpackages/parentalcontrol/config/0/override_password', '');
	
	if (!empty($configured_password) && $submitted_password === $configured_password) {
		// Grant temporary override
		$override_duration = config_get_path('installedpackages/parentalcontrol/config/0/override_duration', 30);
		$override_until = time() + ($override_duration * 60);
		
		// Store override in state
		if (!isset($state['overrides'])) {
			$state['overrides'] = [];
		}
		$state['overrides'][$client_mac] = [
			'until' => $override_until,
			'granted_at' => time(),
			'granted_by' => 'parent',
			'ip' => $client_ip
		];
		pc_save_state($state);
		
		// Log the override
		pc_log("Parent override granted for device", 'info', array(
			'event.action' => 'parent_override',
			'client.mac' => $client_mac,
			'client.ip' => $client_ip,
			'override.duration_minutes' => $override_duration,
			'override.until' => date('Y-m-d H:i:s', $override_until)
		));
		
		// Temporarily remove firewall block (will be re-applied after override expires)
		parental_control_sync();
		
		$override_success = true;
	} else {
		$override_error = "Incorrect password. Please try again.";
		
		pc_log("Failed parent override attempt", 'warning', array(
			'event.action' => 'parent_override_failed',
			'client.mac' => $client_mac,
			'client.ip' => $client_ip
		));
	}
}

// Check if currently overridden
$is_overridden = false;
$override_remaining = 0;
if ($client_mac && isset($state['overrides'][$client_mac])) {
	if ($state['overrides'][$client_mac]['until'] > time()) {
		$is_overridden = true;
		$override_remaining = ceil(($state['overrides'][$client_mac]['until'] - time()) / 60);
	}
}

// Calculate next reset time
$reset_time = config_get_path('installedpackages/parentalcontrol/config/0/reset_time', '00:00');
$next_reset = strtotime("today " . $reset_time);
if ($next_reset <= time()) {
	$next_reset = strtotime("tomorrow " . $reset_time);
}

// Format usage times
function format_minutes($minutes) {
	$hours = floor($minutes / 60);
	$mins = $minutes % 60;
	return sprintf("%d:%02d", $hours, $mins);
}

// Get version
$version = defined('PC_VERSION') ? PC_VERSION : '1.1.10';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Access Restricted - Parental Control</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
	<style>
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}
		
	body {
		background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
		font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
		min-height: 100vh;
		margin: 0;
		padding: 0;
	}
	
	.content-wrapper {
		min-height: 100vh;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 20px 20px 20px 20px;
	}
		
	.info-banner {
		background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
		color: white;
		padding: 12px 20px;
		text-align: center;
		font-size: 0.95em;
		box-shadow: 0 2px 8px rgba(0,0,0,0.15);
		width: 100%;
		position: fixed;
		top: 0;
		left: 0;
		z-index: 1000;
	}
	
	.info-banner a {
		color: #fff;
		text-decoration: underline;
		font-weight: bold;
	}
	
	.info-banner a:hover {
		color: #ffd700;
	}
	
	.block-container {
		max-width: 600px;
		width: 100%;
		background: white;
		border-radius: 20px;
		box-shadow: 0 20px 60px rgba(0,0,0,0.3);
		overflow: hidden;
		animation: slideIn 0.5s ease-out;
		margin-top: 60px; /* Space for fixed banner */
	}
		
		@keyframes slideIn {
			from {
				opacity: 0;
				transform: translateY(-50px);
			}
			to {
				opacity: 1;
				transform: translateY(0);
			}
		}
		
		.block-header {
			background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
			color: white;
			padding: 40px 30px;
			text-align: center;
		}
		
		.block-header.success {
			background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
		}
		
		.block-header i {
			font-size: 64px;
			margin-bottom: 15px;
			animation: pulse 2s infinite;
		}
		
		@keyframes pulse {
			0%, 100% { opacity: 1; transform: scale(1); }
			50% { opacity: 0.8; transform: scale(1.1); }
		}
		
		.block-header h1 {
			margin: 10px 0 0 0;
			font-size: 32px;
			font-weight: bold;
			text-shadow: 0 2px 4px rgba(0,0,0,0.2);
		}
		
		.block-body {
			padding: 40px 30px;
		}
		
		.block-message {
			background: #f8f9fa;
			border-left: 4px solid #f5576c;
			padding: 20px;
			margin-bottom: 30px;
			border-radius: 8px;
			font-size: 16px;
			line-height: 1.6;
			color: #495057;
		}
		
		.info-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 15px;
			margin-bottom: 30px;
		}
		
		.info-item {
			background: #f8f9fa;
			padding: 20px;
			border-radius: 10px;
			text-align: center;
			transition: transform 0.2s;
		}
		
		.info-item:hover {
			transform: translateY(-5px);
			box-shadow: 0 5px 15px rgba(0,0,0,0.1);
		}
		
		.info-item .label {
			font-size: 12px;
			color: #6c757d;
			text-transform: uppercase;
			font-weight: 600;
			margin-bottom: 8px;
			letter-spacing: 0.5px;
		}
		
		.info-item .value {
			font-size: 24px;
			font-weight: bold;
			color: #495057;
		}
		
		.info-item .value.exceeded {
			color: #dc3545;
		}
		
		.info-item .value.success {
			color: #28a745;
		}
		
		.info-item.full-width {
			grid-column: 1 / -1;
		}
		
		.device-info {
			background: #e9ecef;
			padding: 20px;
			border-radius: 10px;
			margin-bottom: 25px;
			font-size: 14px;
			line-height: 1.8;
		}
		
		.device-info strong {
			color: #495057;
			display: inline-block;
			min-width: 120px;
		}
		
		.override-section {
			background: #fff3cd;
			border: 2px solid #ffc107;
			border-radius: 10px;
			padding: 25px;
			margin-top: 30px;
		}
		
		.override-section h3 {
			margin: 0 0 10px 0;
			color: #856404;
			font-size: 20px;
			display: flex;
			align-items: center;
			gap: 10px;
		}
		
		.override-section p {
			margin: 0 0 20px 0;
			color: #856404;
			line-height: 1.6;
		}
		
		.form-group {
			margin-bottom: 20px;
		}
		
		.form-group label {
			display: block;
			margin-bottom: 8px;
			font-weight: 600;
			color: #495057;
			font-size: 14px;
		}
		
		.form-group input {
			width: 100%;
			padding: 12px 15px;
			border: 2px solid #ced4da;
			border-radius: 8px;
			font-size: 16px;
			transition: border-color 0.2s;
		}
		
		.form-group input:focus {
			outline: none;
			border-color: #667eea;
			box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
		}
		
		.btn-override {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
			border: none;
			padding: 15px 30px;
			border-radius: 10px;
			font-size: 16px;
			font-weight: bold;
			cursor: pointer;
			width: 100%;
			transition: all 0.3s;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 10px;
		}
		
		.btn-override:hover {
			transform: translateY(-2px);
			box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
		}
		
		.btn-override:active {
			transform: translateY(0);
		}
		
		.alert {
			padding: 15px 20px;
			border-radius: 8px;
			margin-bottom: 20px;
			display: flex;
			align-items: center;
			gap: 10px;
			animation: shake 0.5s;
		}
		
		@keyframes shake {
			0%, 100% { transform: translateX(0); }
			25% { transform: translateX(-10px); }
			75% { transform: translateX(10px); }
		}
		
		.alert-danger {
			background: #f8d7da;
			color: #721c24;
			border: 1px solid #f5c6cb;
		}
		
		.alert-success {
			background: #d4edda;
			color: #155724;
			border: 1px solid #c3e6cb;
		}
		
		.success-container {
			text-align: center;
			padding: 40px 30px;
		}
		
		.success-container i {
			font-size: 100px;
			color: #28a745;
			margin-bottom: 20px;
			animation: bounceIn 0.8s;
		}
		
		@keyframes bounceIn {
			0% { transform: scale(0); }
			50% { transform: scale(1.2); }
			100% { transform: scale(1); }
		}
		
		.success-container h2 {
			font-size: 28px;
			margin-bottom: 15px;
			color: #495057;
		}
		
		.success-container p {
			font-size: 16px;
			color: #6c757d;
			line-height: 1.6;
			margin-bottom: 20px;
		}
		
		.countdown {
			font-size: 64px;
			font-weight: bold;
			color: #667eea;
			margin: 30px 0;
			text-shadow: 0 2px 4px rgba(0,0,0,0.1);
		}
		
		.footer-info {
			text-align: center;
			padding: 25px;
			background: #f8f9fa;
			color: #6c757d;
			font-size: 13px;
			line-height: 1.6;
			border-top: 1px solid #dee2e6;
		}
		
		.footer-info strong {
			color: #495057;
		}
		
		@media (max-width: 600px) {
			.info-grid {
				grid-template-columns: 1fr;
			}
			
			.block-header h1 {
				font-size: 24px;
			}
			
			.countdown {
				font-size: 48px;
			}
		}
	</style>
</head>
<body>
	<!-- Info Banner - Full Width at Top -->
	<div class="info-banner">
		<i class="fa-solid fa-circle-info"></i> 
		<strong>New to KACI Parental Control?</strong> 
		Learn more about this project: <a href="/index.html">View Project Info</a>
	</div>
	
	<!-- Content Wrapper for Centering -->
	<div class="content-wrapper">
		<div class="block-container">
		<?php if ($override_success): ?>
			<!-- Override Success -->
			<div class="block-header success">
				<i class="fa-solid fa-circle-check"></i>
				<h1>Access Granted!</h1>
			</div>
			<div class="success-container">
				<i class="fa-solid fa-unlock-keyhole"></i>
				<h2>Override Active</h2>
				<p>Internet access has been temporarily restored for <strong><?= htmlspecialchars(config_get_path('installedpackages/parentalcontrol/config/0/override_duration', 30)) ?> minutes</strong>.</p>
				<div class="countdown" id="countdown"><?= $override_remaining ?></div>
				<p style="font-size: 14px; color: #6c757d;">minutes remaining</p>
				<button class="btn-override" onclick="window.close(); if (!window.closed) location.href='about:blank';">
					<i class="fa-solid fa-globe"></i> Continue Browsing
				</button>
			</div>
		<?php elseif ($is_overridden): ?>
			<!-- Currently Overridden -->
			<div class="block-header success">
				<i class="fa-solid fa-unlock"></i>
				<h1>Override Active</h1>
			</div>
			<div class="success-container">
				<i class="fa-solid fa-circle-check" style="color: #28a745;"></i>
				<h2>Access Restored</h2>
				<p>You have <strong><?= $override_remaining ?> minutes</strong> of internet access remaining.</p>
				<div class="countdown" id="countdown"><?= $override_remaining ?></div>
				<p style="color: #6c757d; margin-top: 20px;">Access will be restricted again when the timer expires.</p>
				<button class="btn-override" onclick="window.close(); if (!window.closed) location.href='about:blank';">
					<i class="fa-solid fa-globe"></i> Continue Browsing
				</button>
			</div>
		<?php else: ?>
			<!-- Blocked State -->
			<div class="block-header">
				<i class="fa-solid fa-ban"></i>
				<h1>Access Restricted</h1>
			</div>
			
			<div class="block-body">
				<div class="block-message">
					<?= htmlspecialchars($blocked_message) ?>
				</div>
				
				<div class="info-grid">
					<div class="info-item">
						<div class="label"><i class="fa-solid fa-exclamation-circle"></i> Reason</div>
						<div class="value" style="font-size: 14px; color: #dc3545;">
							<?= htmlspecialchars($block_reason) ?>
						</div>
					</div>
					
					<div class="info-item">
						<div class="label"><i class="fa-solid fa-user"></i> Profile</div>
						<div class="value" style="font-size: 16px;">
							<?= $device_info ? htmlspecialchars($device_info['child_name']) : 'Unknown' ?>
						</div>
					</div>
					
					<?php if ($usage_limit > 0): ?>
					<div class="info-item">
						<div class="label"><i class="fa-solid fa-clock"></i> Usage Today</div>
						<div class="value <?= $usage_today >= $usage_limit ? 'exceeded' : '' ?>">
							<?= format_minutes($usage_today) ?>
						</div>
					</div>
					
					<div class="info-item">
						<div class="label"><i class="fa-solid fa-hourglass-half"></i> Daily Limit</div>
						<div class="value">
							<?= format_minutes($usage_limit) ?>
						</div>
					</div>
					<?php endif; ?>
					
					<div class="info-item full-width">
						<div class="label"><i class="fa-solid fa-rotate"></i> Access Resets At</div>
						<div class="value" style="font-size: 20px; color: #28a745;">
							<?= date('g:i A', $next_reset) ?>
						</div>
					</div>
				</div>
				
				<?php if ($device_info): ?>
				<div class="device-info">
					<div><strong><i class="fa-solid fa-mobile-screen"></i> Device:</strong> <?= htmlspecialchars($device_info['device_name']) ?></div>
					<div><strong><i class="fa-solid fa-network-wired"></i> IP Address:</strong> <?= htmlspecialchars($client_ip) ?></div>
					<?php if ($client_mac): ?>
					<div><strong><i class="fa-solid fa-hashtag"></i> MAC Address:</strong> <code><?= htmlspecialchars(strtoupper($client_mac)) ?></code></div>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				
				<!-- Parent Override Section -->
				<?php if (!empty(config_get_path('installedpackages/parentalcontrol/config/0/override_password'))): ?>
				<div class="override-section">
					<h3><i class="fa-solid fa-key"></i> Parent Override</h3>
					<p>Parents can enter the override password to grant temporary access.</p>
					
					<?php if ($override_error): ?>
					<div class="alert alert-danger">
						<i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($override_error) ?>
					</div>
					<?php endif; ?>
					
					<form method="POST" action="">
						<div class="form-group">
							<label for="override_password"><i class="fa-solid fa-lock"></i> Password:</label>
							<input type="password" id="override_password" name="override_password" required autofocus placeholder="Enter parent password">
						</div>
						<button type="submit" class="btn-override">
							<i class="fa-solid fa-unlock"></i> Grant Access (<?= htmlspecialchars(config_get_path('installedpackages/parentalcontrol/config/0/override_duration', 30)) ?> minutes)
						</button>
					</form>
				</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		
		<div class="footer-info">
			<strong>Keekar's Parental Control</strong> v<?= htmlspecialchars($version) ?><br>
			Built with Passion by <strong>Mukesh Kesharwani</strong> | © <?= date('Y') ?> Keekar<br>
			<a href="/index.html" style="color: #667eea; text-decoration: none; font-size: 0.9em;">📖 About This Project</a>
		</div>
	</div>

	<?php if ($override_success || $is_overridden): ?>
	<script>
		// Countdown timer
		var minutes = <?= $override_remaining ?>;
		var countdownElement = document.getElementById('countdown');
		
		setInterval(function() {
			minutes--;
			if (minutes <= 0) {
				location.reload();
			} else {
				countdownElement.textContent = minutes;
			}
		}, 60000); // Update every minute
		
		// Auto-close and refresh after 5 seconds
		setTimeout(function() {
			window.close();
			if (!window.closed) {
			location.href = 'about:blank';
		}
	}, 5000);
</script>
<?php endif; ?>
	</div><!-- /content-wrapper -->
</body>
</html>

