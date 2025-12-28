<?php
/*
 * parental_control_blocked.php
 * 
 * Block page shown to users when their internet access is restricted
 * Displays reason for block and allows parent override
 * 
 * Part of KACI-Parental_Control for pfSense
 * Copyright (c) 2025 Mukesh Kesharwani
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Get client information
$client_ip = $_SERVER['REMOTE_ADDR'];
$client_mac = null;

// Detect if this is a redirect (user tried to browse somewhere)
$is_redirect = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== $_SERVER['SERVER_NAME'];
$original_url = $is_redirect ? 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] : '';

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

$pgtitle = array("Services", "Parental Control", "Access Blocked");
include("head.inc");
?>

<style>
body {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.block-container {
	max-width: 600px;
	margin: 50px auto;
	background: white;
	border-radius: 20px;
	box-shadow: 0 20px 60px rgba(0,0,0,0.3);
	overflow: hidden;
}

.block-header {
	background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
	color: white;
	padding: 30px;
	text-align: center;
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
	margin: 10px 0;
	font-size: 28px;
	font-weight: bold;
}

.block-body {
	padding: 30px;
}

.block-message {
	background: #f8f9fa;
	border-left: 4px solid #f5576c;
	padding: 20px;
	margin-bottom: 25px;
	border-radius: 8px;
	font-size: 16px;
	line-height: 1.6;
}

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 15px;
	margin-bottom: 25px;
}

.info-item {
	background: #f8f9fa;
	padding: 15px;
	border-radius: 8px;
	text-align: center;
}

.info-item .label {
	font-size: 12px;
	color: #6c757d;
	text-transform: uppercase;
	margin-bottom: 5px;
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

.override-section {
	background: #fff3cd;
	border: 2px solid #ffc107;
	border-radius: 8px;
	padding: 20px;
	margin-top: 25px;
}

.override-section h3 {
	margin: 0 0 15px 0;
	color: #856404;
	font-size: 18px;
}

.form-group {
	margin-bottom: 15px;
}

.form-group label {
	display: block;
	margin-bottom: 5px;
	font-weight: 600;
	color: #495057;
}

.form-group input {
	width: 100%;
	padding: 10px;
	border: 1px solid #ced4da;
	border-radius: 5px;
	font-size: 14px;
}

.btn-override {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: white;
	border: none;
	padding: 12px 30px;
	border-radius: 8px;
	font-size: 16px;
	font-weight: bold;
	cursor: pointer;
	width: 100%;
	transition: transform 0.2s;
}

.btn-override:hover {
	transform: translateY(-2px);
	box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.alert {
	padding: 12px 15px;
	border-radius: 5px;
	margin-bottom: 15px;
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
	padding: 40px;
}

.success-container i {
	font-size: 80px;
	color: #28a745;
	margin-bottom: 20px;
}

.countdown {
	font-size: 48px;
	font-weight: bold;
	color: #667eea;
	margin: 20px 0;
}

.footer-info {
	text-align: center;
	padding: 20px;
	background: #f8f9fa;
	color: #6c757d;
	font-size: 12px;
}
</style>

<div class="block-container">
	<?php if ($override_success): ?>
		<!-- Override Success -->
		<div class="block-header">
			<i class="fa fa-check-circle"></i>
			<h1>Access Granted!</h1>
		</div>
		<div class="success-container">
			<i class="fa fa-unlock-alt"></i>
			<h2>Override Active</h2>
			<p>Internet access has been temporarily restored for <strong><?= $override_duration ?> minutes</strong>.</p>
			<div class="countdown" id="countdown"><?= $override_remaining ?></div>
			<p>minutes remaining</p>
			<button class="btn-override" onclick="window.close(); location.reload();">Continue Browsing</button>
		</div>
	<?php elseif ($is_overridden): ?>
		<!-- Currently Overridden -->
		<div class="block-header" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
			<i class="fa fa-unlock"></i>
			<h1>Override Active</h1>
		</div>
		<div class="success-container">
			<i class="fa fa-check-circle" style="color: #28a745;"></i>
			<h2>Access Restored</h2>
			<p>You have <strong><?= $override_remaining ?> minutes</strong> of internet access remaining.</p>
			<div class="countdown" id="countdown"><?= $override_remaining ?></div>
			<p style="color: #6c757d; margin-top: 20px;">Access will be restricted again when the timer expires.</p>
		</div>
	<?php else: ?>
		<!-- Blocked State -->
		<div class="block-header">
			<i class="fa fa-ban"></i>
			<h1>Access Restricted</h1>
		</div>
		
		<div class="block-body">
			<div class="block-message">
				<?= htmlspecialchars($blocked_message) ?>
			</div>
			
			<div class="info-grid">
				<div class="info-item">
					<div class="label">Reason</div>
					<div class="value" style="font-size: 16px; color: #dc3545;">
						<i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($block_reason) ?>
					</div>
				</div>
				
				<div class="info-item">
					<div class="label">Profile</div>
					<div class="value" style="font-size: 16px;">
						<?= $device_info ? htmlspecialchars($device_info['child_name']) : 'Unknown' ?>
					</div>
				</div>
				
				<?php if ($usage_limit > 0): ?>
				<div class="info-item">
					<div class="label">Usage Today</div>
					<div class="value <?= $usage_today >= $usage_limit ? 'exceeded' : '' ?>">
						<?= format_minutes($usage_today) ?>
					</div>
				</div>
				
				<div class="info-item">
					<div class="label">Daily Limit</div>
					<div class="value">
						<?= format_minutes($usage_limit) ?>
					</div>
				</div>
				<?php endif; ?>
				
				<div class="info-item" style="grid-column: 1 / -1;">
					<div class="label">Access Resets At</div>
					<div class="value" style="font-size: 20px; color: #28a745;">
						<i class="fa fa-clock"></i> <?= date('g:i A', $next_reset) ?>
					</div>
				</div>
			</div>
			
			<?php if ($device_info): ?>
			<div style="background: #e9ecef; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
				<strong>Device:</strong> <?= htmlspecialchars($device_info['device_name']) ?><br>
				<strong>IP Address:</strong> <?= htmlspecialchars($client_ip) ?><br>
				<?php if ($client_mac): ?>
				<strong>MAC Address:</strong> <?= htmlspecialchars(strtoupper($client_mac)) ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			
			<!-- Parent Override Section -->
			<?php if (!empty(config_get_path('installedpackages/parentalcontrol/config/0/override_password'))): ?>
			<div class="override-section">
				<h3><i class="fa fa-key"></i> Parent Override</h3>
				<p style="margin: 0 0 15px 0; color: #856404;">Parents can enter the override password to grant temporary access.</p>
				
				<?php if ($override_error): ?>
				<div class="alert alert-danger">
					<i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($override_error) ?>
				</div>
				<?php endif; ?>
				
				<form method="POST" action="">
					<div class="form-group">
						<label for="override_password">Password:</label>
						<input type="password" id="override_password" name="override_password" required autofocus>
					</div>
					<button type="submit" class="btn-override">
						<i class="fa fa-unlock"></i> Grant Access (<?= config_get_path('installedpackages/parentalcontrol/config/0/override_duration', 30) ?> minutes)
					</button>
				</form>
			</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	
	<div class="footer-info">
		<strong>Keekar's Parental Control</strong> v<?= PC_VERSION ?><br>
		Built with Passion by <strong>Mukesh Kesharwani</strong> | © <?= date('Y') ?> Keekar
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

<?php include("foot.inc"); ?>

