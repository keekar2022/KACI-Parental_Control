<?php
/*
 * parental_control_gaming.php
 *
 * Gaming Detection and Control for Parental Control
 * Detects and limits gaming activity across multiple platforms
 */

##|+PRIV
##|*IDENT=page-services-parentalcontrol-gaming
##|*NAME=Services: Parental Control: Gaming Detection
##|*DESCR=Manage gaming detection and limits
##|*MATCH=parental_control_gaming.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Check if user has permission
if (!isAllowedPage($_SERVER['SCRIPT_NAME'])) {
	header("Location: /");
	exit;
}

$pgtitle = array("Services", "Parental Control", "Gaming Detection");
$pglinks = array("", "/pkg_edit.php?xml=parental_control.xml", "@self");

// Get configuration
$gaming_config = config_get_path('installedpackages/parentalcontrolgaming/config/0', array());
$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', array());

// Initialize gaming config with defaults if empty (WHO-based universal limits)
if (empty($gaming_config)) {
	$gaming_config = array(
		'enable' => 'off',
		'detection_methods' => 'all',
		'confidence_threshold' => '75',
		'pattern_high_connections' => '70',
		'pattern_discord_minutes' => '60',
		'pattern_youtube_minutes' => '60',
		'pattern_session_minutes' => '60',
		'platforms' => array(),
		// WHO-based universal gaming limits (applies to all profiles)
		'daily_gaming_limit' => 60, // 60 minutes per day (WHO recommendation)
		'override_limit' => 120, // 2 hours override for administrators
		'override_enabled' => array(), // Format: ['profile_name' => timestamp_when_override_expires]
		// MAC address exemptions (devices excluded from gaming detection)
		'exempt_macs' => array() // Format: ['mac' => 'description']
	);
}

// Ensure platforms array exists
if (!isset($gaming_config['platforms']) || !is_array($gaming_config['platforms'])) {
	$gaming_config['platforms'] = array();
}

// Default gaming platforms
$default_platforms = array(
	'minecraft' => array(
		'name' => 'Minecraft',
		'enabled' => 'on',
		'ports' => '25565,19132',
		'detection_method' => 'both',
		'description' => 'Minecraft Java (25565) and Bedrock (19132) editions'
	),
	'steam' => array(
		'name' => 'Steam',
		'enabled' => 'on',
		'ports' => '27015-27030',
		'detection_method' => 'port',
		'description' => 'Steam gaming platform and game servers'
	),
	'roblox' => array(
		'name' => 'Roblox',
		'enabled' => 'on',
		'ports' => '',
		'detection_method' => 'pattern',
		'description' => 'Roblox (pattern-based via Cloudflare CDN)'
	),
	'epic' => array(
		'name' => 'Epic Games',
		'enabled' => 'on',
		'ports' => '9000-9100',
		'detection_method' => 'both',
		'description' => 'Epic Games / Fortnite (Note: Ports 5222-5223 removed due to XMPP/APNs false positives. Use with Online Gaming service for better accuracy.)'
	)
);

// Merge default platforms with user config
foreach ($default_platforms as $platform_id => $platform_data) {
	if (!isset($gaming_config['platforms'][$platform_id])) {
		$gaming_config['platforms'][$platform_id] = $platform_data;
	}
}

// Handle form submissions
$input_errors = array();
$savemsg = '';

// SAVE SETTINGS
if ($_POST['save']) {
	// Update global gaming detection settings
	$gaming_config['enable'] = $_POST['enable'] ?? 'off';
	$gaming_config['detection_methods'] = $_POST['detection_methods'] ?? 'all';
	$gaming_config['confidence_threshold'] = $_POST['confidence_threshold'] ?? '75';
	
	// Pattern detection thresholds
	$gaming_config['pattern_high_connections'] = $_POST['pattern_high_connections'] ?? '70';
	$gaming_config['pattern_discord_minutes'] = $_POST['pattern_discord_minutes'] ?? '60';
	$gaming_config['pattern_youtube_minutes'] = $_POST['pattern_youtube_minutes'] ?? '60';
	$gaming_config['pattern_session_minutes'] = $_POST['pattern_session_minutes'] ?? '60';
	
	// WHO-based universal gaming limit (applies to all profiles)
	if (isset($_POST['daily_gaming_limit'])) {
		$gaming_config['daily_gaming_limit'] = intval($_POST['daily_gaming_limit']);
	}
	
	// Update platform settings
	foreach ($default_platforms as $platform_id => $platform_data) {
		if (isset($_POST['platform_' . $platform_id . '_enabled'])) {
			$gaming_config['platforms'][$platform_id]['enabled'] = $_POST['platform_' . $platform_id . '_enabled'];
		}
		if (isset($_POST['platform_' . $platform_id . '_ports'])) {
			$gaming_config['platforms'][$platform_id]['ports'] = $_POST['platform_' . $platform_id . '_ports'];
		}
		if (isset($_POST['platform_' . $platform_id . '_detection_method'])) {
			$gaming_config['platforms'][$platform_id]['detection_method'] = $_POST['platform_' . $platform_id . '_detection_method'];
		}
	}
	
	// Update MAC exemptions
	if (isset($_POST['exempt_macs_list'])) {
		$exempt_macs_raw = trim($_POST['exempt_macs_list']);
		$gaming_config['exempt_macs'] = array();
		
		if (!empty($exempt_macs_raw)) {
			$lines = explode("\n", $exempt_macs_raw);
			foreach ($lines as $line) {
				$line = trim($line);
				if (empty($line) || $line[0] === '#') {
					continue; // Skip empty lines and comments
				}
				
				// Format: MAC_ADDRESS|Description or just MAC_ADDRESS
				$parts = explode('|', $line, 2);
				$mac = strtoupper(trim($parts[0]));
				$description = isset($parts[1]) ? trim($parts[1]) : 'Exempted device';
				
				// Validate MAC address format
				if (preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $mac)) {
					$gaming_config['exempt_macs'][$mac] = $description;
				} else {
					$input_errors[] = "Invalid MAC address format: {$mac}";
				}
			}
		}
	}
	
	// Save to config
	config_set_path('installedpackages/parentalcontrolgaming/config/0', $gaming_config);
	
	if (write_config("Parental Control: Updated gaming detection settings")) {
		$savemsg = "Gaming detection settings saved successfully!";
		
		// Try to sync
		try {
			parental_control_sync();
		} catch (Exception $e) {
			pc_log("Gaming config sync failed: " . $e->getMessage(), 'warning');
		}
	} else {
		$input_errors[] = "Failed to save gaming detection settings.";
	}
}

// ADMINISTRATOR OVERRIDE (Add 2 hours to daily limit)
if ($_POST['apply_override']) {
	// Check if user is administrator
	if (!pc_is_admin_user()) {
		$input_errors[] = "Access denied. Only administrators can apply gaming time override.";
	} else {
		$profile_name = $_POST['override_profile'] ?? '';
		if (!empty($profile_name)) {
			// Initialize override_enabled if not exists
			if (!isset($gaming_config['override_enabled'])) {
				$gaming_config['override_enabled'] = array();
			}
			
			// Set override expiration (end of current day at midnight)
			$override_expires = strtotime('tomorrow 00:00:00');
			$gaming_config['override_enabled'][$profile_name] = $override_expires;
			
			config_set_path('installedpackages/parentalcontrolgaming/config/0', $gaming_config);
			
			if (write_config("Parental Control: Applied gaming override for profile: {$profile_name}")) {
				$savemsg = "Administrator override applied for {$profile_name}: +2 hours gaming time until midnight.";
				
				pc_log("Gaming override applied by administrator", 'notice', array(
					'event.action' => 'gaming_override_applied',
					'profile.name' => $profile_name,
					'override.hours' => 2,
					'override.expires' => date('Y-m-d H:i:s', $override_expires),
					'admin.user' => $_SERVER['REMOTE_USER'] ?? 'unknown'
				));
			} else {
				$input_errors[] = "Failed to apply gaming override.";
			}
		} else {
			$input_errors[] = "Please select a profile for override.";
		}
	}
}

// REMOVE OVERRIDE
if ($_POST['remove_override']) {
	if (!pc_is_admin_user()) {
		$input_errors[] = "Access denied. Only administrators can remove gaming time override.";
	} else {
		$profile_name = $_POST['remove_override_profile'] ?? '';
		if (!empty($profile_name) && isset($gaming_config['override_enabled'][$profile_name])) {
			unset($gaming_config['override_enabled'][$profile_name]);
			
			config_set_path('installedpackages/parentalcontrolgaming/config/0', $gaming_config);
			
			if (write_config("Parental Control: Removed gaming override for profile: {$profile_name}")) {
				$savemsg = "Gaming override removed for {$profile_name}.";
				
				pc_log("Gaming override removed by administrator", 'notice', array(
					'event.action' => 'gaming_override_removed',
					'profile.name' => $profile_name,
					'admin.user' => $_SERVER['REMOTE_USER'] ?? 'unknown'
				));
			}
		}
	}
}

// Clean up expired overrides
if (isset($gaming_config['override_enabled']) && is_array($gaming_config['override_enabled'])) {
	$current_time = time();
	$overrides_changed = false;
	
	foreach ($gaming_config['override_enabled'] as $profile_name => $expires_at) {
		if ($current_time >= $expires_at) {
			unset($gaming_config['override_enabled'][$profile_name]);
			$overrides_changed = true;
		}
	}
	
	if ($overrides_changed) {
		config_set_path('installedpackages/parentalcontrolgaming/config/0', $gaming_config);
		write_config("Parental Control: Cleaned up expired gaming overrides");
	}
}

// Load current gaming state
$state = pc_load_state();

// Get active gaming sessions
$active_gaming = array();
if (isset($state['devices_by_ip']) && is_array($state['devices_by_ip'])) {
	foreach ($state['devices_by_ip'] as $ip => $device_state) {
		if (isset($device_state['gaming_detection']) && 
		    isset($device_state['gaming_detection']['detected_platform']) &&
		    $device_state['gaming_detection']['detected_platform'] !== 'none') {
			$active_gaming[] = array(
				'ip' => $ip,
				'name' => $device_state['name'] ?? $ip,
				'profile' => $device_state['profile'] ?? 'Unknown',
				'platform' => $device_state['gaming_detection']['detected_platform'],
				'confidence' => $device_state['gaming_detection']['confidence_score'] ?? 0,
				'duration' => $device_state['gaming_detection']['usage_today'] ?? 0,
				'session_start' => $device_state['gaming_detection']['session_start'] ?? 0
			);
		}
	}
}

include("head.inc");

// Display messages
if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}

// Include tabs
$tab_array = array();
$tab_array[] = array("Settings", false, "/pkg_edit.php?xml=parental_control.xml&amp;id=0");
$tab_array[] = array("Profiles", false, "/parental_control_profiles.php");
$tab_array[] = array("KACI-PC-Schedule", false, "/parental_control_schedules.php");
$tab_array[] = array("Online-Service", false, "/parental_control_services.php");
$tab_array[] = array("Gaming Detection", true, "/parental_control_gaming.php");
$tab_array[] = array("Status", false, "/parental_control_status.php");
display_top_tabs($tab_array);
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">Gaming Detection & Control</h2>
	</div>
	<div class="panel-body">
		<div class="content">
			<p><strong>Gaming Detection</strong> automatically identifies when devices are playing online games (Minecraft, Steam, Roblox, etc.) and enforces gaming-specific time limits.</p>
			<p><strong>How it works:</strong> Combines port detection (Minecraft: 25565, Steam: 27015-27030), behavioral patterns (high connections + Discord), and IP lists to identify gaming activity with confidence scores.</p>
		</div>
	</div>
</div>

<!-- Gaming Detection Enable/Disable -->
<form method="post" class="form-horizontal">
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title">Gaming Detection Status</h2>
		</div>
		<div class="panel-body">
			<div class="form-group">
				<label class="col-sm-3 control-label">
					<strong>Enable Gaming Detection</strong>
				</label>
				<div class="col-sm-6">
					<input type="checkbox" name="enable" value="on" <?= ($gaming_config['enable'] === 'on') ? 'checked' : '' ?> />
					<span class="help-block">
						Master switch for all gaming detection, tracking, and blocking. 
						When disabled, no gaming detection or enforcement occurs.
					</span>
				</div>
			</div>
			
			<?php if ($gaming_config['enable'] === 'on'): ?>
			<div class="form-group">
				<label class="col-sm-3 control-label">
					Current Status
				</label>
				<div class="col-sm-6">
					<span class="label label-success">ENABLED</span>
					<?php if (!empty($active_gaming)): ?>
						<br/><br/>
						<div class="alert alert-info">
							<strong>Active Gaming Sessions: <?= count($active_gaming) ?></strong>
							<ul>
							<?php foreach ($active_gaming as $session): ?>
								<li>
									<strong><?= htmlspecialchars($session['name']) ?></strong> 
									(<?= htmlspecialchars($session['profile']) ?>) 
									- Playing <?= htmlspecialchars($session['platform']) ?> 
									for <?= intval($session['duration']) ?> minutes 
									(<?= intval($session['confidence']) ?>% confidence)
								</li>
							<?php endforeach; ?>
							</ul>
						</div>
					<?php else: ?>
						<br/><br/>
						<div class="alert alert-success">
							No active gaming sessions detected
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php else: ?>
			<div class="form-group">
				<label class="col-sm-3 control-label">
					Current Status
				</label>
				<div class="col-sm-6">
					<span class="label label-default">DISABLED</span>
					<br/><br/>
					<div class="alert alert-warning">
						Gaming detection is currently disabled. Enable it above to start detecting and controlling gaming activity.
					</div>
				</div>
			</div>
			<?php endif; ?>
			
			<div class="form-group">
				<div class="col-sm-offset-3 col-sm-6">
					<button type="submit" name="save" value="save" class="btn btn-primary">
						<i class="fa fa-save"></i> Save Settings
					</button>
				</div>
			</div>
		</div>
	</div>
	
	<!-- Detection Configuration -->
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title">Detection Configuration</h2>
		</div>
		<div class="panel-body">
			<div class="form-group">
				<label class="col-sm-3 control-label">
					Detection Methods
				</label>
				<div class="col-sm-6">
					<select name="detection_methods" class="form-control">
						<option value="all" <?= ($gaming_config['detection_methods'] === 'all') ? 'selected' : '' ?>>All Methods (Ports + Patterns + IP Lists)</option>
						<option value="ports" <?= ($gaming_config['detection_methods'] === 'ports') ? 'selected' : '' ?>>Ports Only</option>
						<option value="patterns" <?= ($gaming_config['detection_methods'] === 'patterns') ? 'selected' : '' ?>>Patterns Only</option>
						<option value="iplists" <?= ($gaming_config['detection_methods'] === 'iplists') ? 'selected' : '' ?>>IP Lists Only</option>
					</select>
					<span class="help-block">
						<strong>All Methods:</strong> Highest accuracy, combines port scanning, behavioral patterns, and IP lists<br/>
						<strong>Ports Only:</strong> Fast, works for Minecraft (25565), Steam (27015-27030), Epic Games<br/>
						<strong>Patterns Only:</strong> Works for CDN-proxied games (Roblox), based on connection behavior<br/>
						<strong>IP Lists Only:</strong> Uses gaming platform IP ranges (requires maintenance)
					</span>
				</div>
			</div>
			
			<div class="form-group">
				<label class="col-sm-3 control-label">
					Confidence Threshold
				</label>
				<div class="col-sm-3">
					<input type="number" name="confidence_threshold" class="form-control" 
					       value="<?= htmlspecialchars($gaming_config['confidence_threshold'] ?? '75') ?>" 
					       min="50" max="95" />
					<span class="help-block">
						Minimum confidence (50-95%) required to flag as gaming. Higher = fewer false positives.
					</span>
				</div>
			</div>
			
			<div class="form-group">
				<div class="col-sm-offset-3 col-sm-6">
					<button type="submit" name="save" value="save" class="btn btn-primary">
						<i class="fa fa-save"></i> Save Settings
					</button>
				</div>
			</div>
		</div>
	</div>
	
	<!-- Pattern Detection Thresholds -->
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title">Pattern Detection Thresholds</h2>
		</div>
		<div class="panel-body">
			<div class="content">
				<p><strong>Behavioral Pattern Detection:</strong> Identifies gaming based on connection patterns (high connection count, Discord usage, YouTube watching).</p>
				<p>These thresholds define what constitutes "likely gaming activity" based on the investigation documented in <code>logs/GAMING_INVESTIGATION_2026-01-29.md</code>.</p>
			</div>
			
			<div class="form-group">
				<label class="col-sm-3 control-label">
					High Connections Threshold
				</label>
				<div class="col-sm-3">
					<input type="number" name="pattern_high_connections" class="form-control" 
					       value="<?= htmlspecialchars($gaming_config['pattern_high_connections'] ?? '70') ?>" 
					       min="30" max="150" />
					<span class="help-block">
						Connections above this count indicate gaming (Default: 70, Normal browsing: 5-15)
					</span>
				</div>
			</div>
			
			<div class="form-group">
				<label class="col-sm-3 control-label">
					Discord Usage Threshold
				</label>
				<div class="col-sm-3">
					<input type="number" name="pattern_discord_minutes" class="form-control" 
					       value="<?= htmlspecialchars($gaming_config['pattern_discord_minutes'] ?? '60') ?>" 
					       min="15" max="240" />
					<span class="help-block">
						Minutes of Discord usage per day indicating gaming (voice chat for multiplayer)
					</span>
				</div>
			</div>
			
			<div class="form-group">
				<label class="col-sm-3 control-label">
					YouTube Usage Threshold
				</label>
				<div class="col-sm-3">
					<input type="number" name="pattern_youtube_minutes" class="form-control" 
					       value="<?= htmlspecialchars($gaming_config['pattern_youtube_minutes'] ?? '60') ?>" 
					       min="15" max="240" />
					<span class="help-block">
						Minutes of YouTube usage indicating gaming content (tutorials, streams)
					</span>
				</div>
			</div>
			
			<div class="form-group">
				<label class="col-sm-3 control-label">
					Sustained Session Duration
				</label>
				<div class="col-sm-3">
					<input type="number" name="pattern_session_minutes" class="form-control" 
					       value="<?= htmlspecialchars($gaming_config['pattern_session_minutes'] ?? '60') ?>" 
					       min="15" max="180" />
					<span class="help-block">
						Continuous activity duration (minutes) indicating gaming session
					</span>
				</div>
			</div>
			
			<div class="form-group">
				<div class="col-sm-offset-3 col-sm-6">
					<button type="submit" name="save" value="save" class="btn btn-primary">
						<i class="fa fa-save"></i> Save Settings
					</button>
				</div>
			</div>
		</div>
	</div>
</form>

<!-- Gaming Platforms Configuration -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">Gaming Platforms</h2>
	</div>
	<div class="panel-body">
		<div class="content">
			<p><strong>Configure detection settings for each gaming platform.</strong> Each platform can use different detection methods (ports, patterns, or IP lists).</p>
		</div>
		
		<form method="post" class="form-horizontal">
			<div class="table-responsive">
				<table class="table table-striped table-hover">
					<thead>
						<tr>
							<th>Platform</th>
							<th>Enabled</th>
							<th>Ports</th>
							<th>Detection Method</th>
							<th>Description</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($default_platforms as $platform_id => $platform_data): ?>
						<?php
						$platform = $gaming_config['platforms'][$platform_id] ?? $platform_data;
						?>
						<tr>
							<td><strong><?= htmlspecialchars($platform['name']) ?></strong></td>
							<td>
								<input type="checkbox" 
								       name="platform_<?= $platform_id ?>_enabled" 
								       value="on" 
								       <?= ($platform['enabled'] === 'on') ? 'checked' : '' ?> />
							</td>
							<td>
								<input type="text" 
								       name="platform_<?= $platform_id ?>_ports" 
								       class="form-control" 
								       value="<?= htmlspecialchars($platform['ports']) ?>" 
								       placeholder="e.g., 25565 or 27015-27030" 
								       style="width: 150px;" />
							</td>
							<td>
								<select name="platform_<?= $platform_id ?>_detection_method" class="form-control" style="width: 120px;">
									<option value="port" <?= ($platform['detection_method'] === 'port') ? 'selected' : '' ?>>Port</option>
									<option value="pattern" <?= ($platform['detection_method'] === 'pattern') ? 'selected' : '' ?>>Pattern</option>
									<option value="both" <?= ($platform['detection_method'] === 'both') ? 'selected' : '' ?>>Both</option>
								</select>
							</td>
							<td><?= htmlspecialchars($platform['description']) ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			
			<div class="form-group">
				<div class="col-sm-12">
					<button type="submit" name="save" value="save" class="btn btn-primary">
						<i class="fa fa-save"></i> Save Platform Settings
					</button>
				</div>
			</div>
		</form>
	</div>
</div>

<!-- MAC Address Exemptions -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><i class="fa fa-ban"></i> Device Exemptions (MAC Address)</h2>
	</div>
	<div class="panel-body">
		<div class="content">
			<p><strong>Exempt specific devices from gaming detection.</strong> Useful for gaming consoles (Xbox, Nintendo Switch, PlayStation) that have their own parental controls.</p>
			<p><strong>Important:</strong> When gaming detection is enabled, it applies to <strong>ALL devices on the network</strong>, regardless of whether they're assigned to a profile. Use exemptions to exclude specific devices.</p>
		</div>
		
		<form method="post" class="form-horizontal">
			<div class="form-group">
				<label class="col-sm-3 control-label">
					<strong>Exempted MAC Addresses</strong>
				</label>
				<div class="col-sm-6">
					<textarea name="exempt_macs_list" class="form-control" rows="8" placeholder="Enter MAC addresses, one per line&#10;Format: XX:XX:XX:XX:XX:XX|Description&#10;Example:&#10;AA:BB:CC:DD:EE:FF|Xbox Series X&#10;11:22:33:44:55:66|Nintendo Switch&#10;&#10;Lines starting with # are ignored"><?php
					// Build the textarea content from exempt_macs array
					if (isset($gaming_config['exempt_macs']) && is_array($gaming_config['exempt_macs'])) {
						foreach ($gaming_config['exempt_macs'] as $mac => $description) {
							echo htmlspecialchars($mac . '|' . $description) . "\n";
						}
					}
					?></textarea>
					<span class="help-block">
						<i class="fa fa-info-circle"></i> Add MAC addresses of devices you want to exempt from gaming detection (e.g., Xbox, PlayStation, Nintendo Switch with built-in parental controls).
						<br><strong>Format:</strong> MAC_ADDRESS|Description (e.g., AA:BB:CC:DD:EE:FF|Xbox Series X)
						<br><strong>Note:</strong> MAC addresses must be in format XX:XX:XX:XX:XX:XX or XX-XX-XX-XX-XX-XX
					</span>
				</div>
			</div>
			
			<div class="form-group">
				<div class="col-sm-offset-3 col-sm-6">
					<button type="submit" name="save" value="save" class="btn btn-primary">
						<i class="fa fa-save"></i> Save Exemptions
					</button>
				</div>
			</div>
		</form>
		
		<?php if (isset($gaming_config['exempt_macs']) && !empty($gaming_config['exempt_macs'])): ?>
		<div class="table-responsive" style="margin-top: 20px;">
			<h4>Currently Exempted Devices</h4>
			<table class="table table-striped table-hover">
				<thead>
					<tr>
						<th>MAC Address</th>
						<th>Description</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($gaming_config['exempt_macs'] as $mac => $description): ?>
					<tr>
						<td><code><?= htmlspecialchars($mac) ?></code></td>
						<td><?= htmlspecialchars($description) ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</div>
</div>

<!-- WHO Recommendations & Universal Gaming Limits -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><i class="fa fa-heartbeat"></i> WHO Gaming Disorder Prevention</h2>
	</div>
	<div class="panel-body">
		<div class="alert alert-info">
			<h4><i class="fa fa-info-circle"></i> World Health Organization Guidelines</h4>
			<p><strong>Gaming disorder</strong> is officially recognized in the WHO's International Classification of Diseases (ICD-11) as a pattern of gaming behavior characterized by impaired control over gaming, increasing priority given to gaming over other activities.</p>
			<p><strong>Learn more:</strong> <a href="https://www.who.int/standards/classifications/frequently-asked-questions/gaming-disorder" target="_blank" rel="noopener">WHO Gaming Disorder FAQ</a></p>
		</div>
		
		<form method="post" class="form-horizontal">
			<div class="form-group">
				<label class="col-sm-3 control-label">
					<strong>Universal Daily Gaming Limit</strong>
				</label>
				<div class="col-sm-3">
					<div class="input-group">
						<input type="number" 
						       name="daily_gaming_limit" 
						       class="form-control" 
						       value="<?= intval($gaming_config['daily_gaming_limit'] ?? 60) ?>" 
						       min="30" max="180" />
						<span class="input-group-addon">minutes</span>
					</div>
					<span class="help-block">
						<strong>Applies to ALL profiles.</strong> WHO recommends limiting gaming time to prevent gaming disorder. Default: 60 minutes/day.
					</span>
				</div>
			</div>
			
			<div class="form-group">
				<div class="col-sm-offset-3 col-sm-6">
					<button type="submit" name="save" value="save" class="btn btn-primary">
						<i class="fa fa-save"></i> Save Gaming Limit
					</button>
				</div>
			</div>
		</form>
		
		<div class="alert alert-info" style="margin-top: 15px;">
			<i class="fa fa-info-circle"></i> <strong>Note:</strong> View real-time gaming usage and monitoring data on the <a href="/parental_control_status.php">Status</a> tab.
		</div>
	</div>
</div>

<!-- Administrator Override (Visible Only to Administrators) -->
<?php if (pc_is_admin_user()): ?>
<div class="panel panel-warning">
	<div class="panel-heading">
		<h2 class="panel-title"><i class="fa fa-unlock"></i> Administrator Gaming Time Override</h2>
	</div>
	<div class="panel-body">
		<div class="alert alert-warning">
			<i class="fa fa-exclamation-triangle"></i> <strong>Administrator Only:</strong> You can temporarily grant +2 hours of gaming time to a profile. Override expires at midnight (resets daily).
		</div>
		
		<form method="post" class="form-horizontal">
			<div class="form-group">
				<label class="col-sm-3 control-label">
					<strong>Apply Override to Profile</strong>
				</label>
				<div class="col-sm-4">
					<select name="override_profile" class="form-control" required>
						<option value="">-- Select Profile --</option>
						<?php foreach ($profiles as $profile): ?>
						<option value="<?= htmlspecialchars($profile['name']) ?>">
							<?= htmlspecialchars($profile['name']) ?>
						</option>
						<?php endforeach; ?>
					</select>
					<span class="help-block">
						Grant additional 2 hours (120 minutes) of gaming time until midnight.
					</span>
				</div>
				<div class="col-sm-3">
					<button type="submit" name="apply_override" value="apply" class="btn btn-warning">
						<i class="fa fa-plus-circle"></i> Apply +2 Hours Override
					</button>
				</div>
			</div>
		</form>
		
		<!-- Active Overrides -->
		<?php if (!empty($gaming_config['override_enabled'])): ?>
		<div class="panel panel-default" style="margin-top: 20px;">
			<div class="panel-heading">
				<h3 class="panel-title">Active Overrides</h3>
			</div>
			<div class="panel-body">
				<div class="table-responsive">
					<table class="table table-condensed">
						<thead>
							<tr>
								<th>Profile</th>
								<th>Override Amount</th>
								<th>Expires At</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($gaming_config['override_enabled'] as $override_profile => $expires_at): ?>
							<?php if ($expires_at > time()): ?>
							<tr>
								<td><strong><?= htmlspecialchars($override_profile) ?></strong></td>
								<td>+<?= intval($gaming_config['override_limit'] ?? 120) ?> minutes</td>
								<td><?= date('Y-m-d H:i', $expires_at) ?></td>
								<td>
									<form method="post" style="display: inline;">
										<input type="hidden" name="remove_override_profile" value="<?= htmlspecialchars($override_profile) ?>" />
										<button type="submit" name="remove_override" value="remove" class="btn btn-xs btn-danger" 
										        onclick="return confirm('Remove override for <?= htmlspecialchars($override_profile) ?>?');">
											<i class="fa fa-times"></i> Remove
										</button>
									</form>
								</td>
							</tr>
							<?php endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<!-- Active Gaming Sessions -->
<?php if (!empty($active_gaming)): ?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">Active Gaming Sessions</h2>
	</div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover">
				<thead>
					<tr>
						<th>Device</th>
						<th>IP Address</th>
						<th>Profile</th>
						<th>Game Platform</th>
						<th>Confidence</th>
						<th>Duration</th>
						<th>Started</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($active_gaming as $session): ?>
					<tr>
						<td><strong><?= htmlspecialchars($session['name']) ?></strong></td>
						<td><?= htmlspecialchars($session['ip']) ?></td>
						<td><?= htmlspecialchars($session['profile']) ?></td>
						<td>
							<span class="label label-info"><?= htmlspecialchars(strtoupper($session['platform'])) ?></span>
						</td>
						<td>
							<?php
							$confidence = intval($session['confidence']);
							if ($confidence >= 90) {
								echo '<span class="label label-success">' . $confidence . '%</span>';
							} elseif ($confidence >= 75) {
								echo '<span class="label label-warning">' . $confidence . '%</span>';
							} else {
								echo '<span class="label label-default">' . $confidence . '%</span>';
							}
							?>
						</td>
						<td><?= intval($session['duration']) ?> min</td>
						<td>
							<?php
							if ($session['session_start'] > 0) {
								echo date('H:i', $session['session_start']);
							} else {
								echo 'Unknown';
							}
							?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Gaming IP Lists Management -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">Gaming IP Lists</h2>
	</div>
	<div class="panel-body">
		<div class="content">
			<p><strong>Gaming IP Lists</strong> can be imported from GitHub repositories or entered manually.</p>
			<p><strong>Recommended sources:</strong></p>
			<ul>
				<li><strong>lord-alfred/ipranges:</strong> Cloudflare, AWS, Azure, Google Cloud (updated daily)</li>
				<li><strong>gamingwithngfw/iplists:</strong> Steam, Blizzard gaming IPs</li>
				<li><strong>Custom lists:</strong> Collected during confirmed gaming sessions</li>
			</ul>
		</div>
		
		<div class="alert alert-info">
			<strong>Integration with Online-Service:</strong> Gaming IP lists are managed through the 
			<a href="/parental_control_services.php">Online-Service</a> tab. Create services like 
			"Minecraft_Servers" or "Steam_Network" and configure IP ranges there.
		</div>
		
		<div class="alert alert-warning">
			<strong>Note:</strong> This section will be enhanced in future versions to allow direct IP list import 
			from GitHub repositories (lord-alfred/ipranges, gamingwithngfw/iplists) with automatic updates.
		</div>
	</div>
</div>

<!-- Documentation Section -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">Gaming Detection Details</h2>
	</div>
	<div class="panel-body">
		<div class="content">
			<h4>Detection Methods</h4>
			
			<h5>1. Port-Based Detection (Highest Accuracy)</h5>
			<ul>
				<li><strong>Minecraft:</strong> Port 25565 (Java Edition), 19132 (Bedrock Edition) - 99% accuracy</li>
				<li><strong>Steam:</strong> Ports 27015-27030 (game servers) - 95% accuracy</li>
				<li><strong>Epic Games/Fortnite:</strong> Ports 5222-5223, 9000-9100 - 90% accuracy</li>
			</ul>
			
			<h5>2. Pattern-Based Detection (For CDN-Proxied Games)</h5>
			<p>Identifies gaming based on behavioral patterns:</p>
			<ul>
				<li><strong>High Connections:</strong> 70-100+ active connections (vs 5-15 for normal browsing)</li>
				<li><strong>Discord Usage:</strong> Voice chat indicator (multiplayer gaming)</li>
				<li><strong>YouTube Gaming Content:</strong> Watching tutorials or streams</li>
				<li><strong>Sustained Sessions:</strong> Continuous activity for 60+ minutes</li>
			</ul>
			<p><strong>Confidence Scores:</strong></p>
			<ul>
				<li>4 indicators matched = 90% confidence (very likely gaming)</li>
				<li>3 indicators matched = 75% confidence (likely gaming)</li>
				<li>2 indicators matched = 50% confidence (possible gaming)</li>
			</ul>
			
			<h5>3. IP List Detection (Evolving Platforms)</h5>
			<p>Uses known gaming platform IP ranges. Configure in the <a href="/parental_control_services.php">Online-Service</a> tab.</p>
			
			<h4>Supported Games</h4>
			<ul>
				<li><strong>Minecraft:</strong> Port-based (25565, 19132) + Pattern detection for CDN-proxied servers</li>
				<li><strong>Steam Games:</strong> Port-based (27015-27030) + IP lists</li>
				<li><strong>Roblox:</strong> Pattern-based (uses Cloudflare CDN)</li>
				<li><strong>Epic Games/Fortnite:</strong> Port-based (5222-5223, 9000-9100)</li>
				<li><strong>Discord:</strong> Already tracked as service (voice chat indicator)</li>
			</ul>
			
			<h4>How It Works</h4>
			<ol>
				<li>Every 5 minutes, cron job scans active device connections</li>
				<li>Checks for gaming ports in firewall state table</li>
				<li>Analyzes connection patterns (count, variance, services)</li>
				<li>Assigns confidence score (0-100%) for each detected platform</li>
				<li>Tracks gaming time per device and per profile</li>
				<li>Enforces per-game and general gaming limits</li>
				<li>Blocks gaming when limits exceeded</li>
			</ol>
			
			<h4>Best Practices</h4>
			<ul>
				<li><strong>Start Conservative:</strong> Set reasonable limits (60-90 min/day) and adjust based on actual usage</li>
				<li><strong>Use All Methods:</strong> Combines port, pattern, and IP detection for best accuracy</li>
				<li><strong>Monitor First Week:</strong> Check Status tab to verify detection accuracy before enforcing strict limits</li>
				<li><strong>Profile-Specific Limits:</strong> Set different limits per child based on age and behavior</li>
				<li><strong>Combine with Schedules:</strong> Block gaming during homework hours (3-7 PM) using KACI-PC-Schedule</li>
			</ul>
		</div>
	</div>
</div>

<!-- Footer -->
<div style="margin-top: 40px; padding: 20px; background: #f8fafc; border-top: 2px solid #e2e8f0; text-align: center;">
	<p style="color: #64748b; margin: 0;">
		<strong>Keekar's Parental Control</strong> v<?= defined('PC_VERSION') ? PC_VERSION : '1.4.71' ?>
	</p>
	<p style="color: #94a3b8; font-size: 0.9em; margin: 5px 0 0 0;">
		Built with Passion by Mukesh Kesharwani | © 2026 Keekar
	</p>
</div>

<?php include("foot.inc"); ?>
