<?php
/*
 * parental_control_status.php
 *
 * Status and monitoring page for Parental Control
 */

##|+PRIV
##|*IDENT=page-services-parentalcontrol-status
##|*NAME=Services: Parental Control: Status
##|*DESCR=View parental control device status and usage
##|*MATCH=parental_control_status.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

$pgtitle = array(gettext("Services"), gettext("Keekar's Parental Control"), gettext("Status"));
$pglinks = array("", "@self", "@self");

include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=parental_control.xml&id=0");
$tab_array[] = array(gettext("Profiles"), false, "/parental_control_profiles.php");
$tab_array[] = array(gettext("KACI-PC-Schedule"), false, "/parental_control_schedules.php");
$tab_array[] = array(gettext("Status"), true, "/parental_control_status.php");
display_top_tabs($tab_array);

// Load state and profiles
$state = pc_load_state();
$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
$enabled = (config_get_path('installedpackages/parentalcontrol/config/0/enable') === 'on');

// Fix rowhelper field name - pfSense stores rowhelper fields as 'row' not 'devices'
if (is_array($profiles)) {
	foreach ($profiles as &$profile) {
		if (isset($profile['row']) && is_array($profile['row'])) {
			$profile['devices'] = $profile['row'];
		}
	}
	unset($profile);
}

?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Parental Control Status")?></h2>
	</div>
	<div class="panel-body">
		<?php if (!$enabled): ?>
			<div class="alert alert-warning">
				<i class="fa-solid fa-exclamation-triangle"></i>
				<?=gettext("Parental Control is currently disabled. Enable it in Settings to start monitoring and enforcement.")?>
			</div>
		<?php else: ?>
			<div class="alert alert-success">
				<i class="fa-solid fa-check-circle"></i>
				<?=gettext("Parental Control is active and monitoring devices.")?>
			</div>
		<?php endif; ?>
		
		<dl class="dl-horizontal">
			<dt><?=gettext("Last Check")?></dt>
			<dd><?php 
				if (isset($state['last_check'])) {
					echo date('Y-m-d H:i:s', $state['last_check']);
				} else {
					echo gettext("Never");
				}
			?></dd>
			
			<dt><?=gettext("Last Reset")?></dt>
			<dd><?php 
				if (isset($state['last_reset'])) {
					echo date('Y-m-d H:i:s', $state['last_reset']);
				} else {
					echo gettext("Never");
				}
			?></dd>
			
			<dt><?=gettext("Active Profiles")?></dt>
			<dd><?php 
				$active_count = 0;
				$total_devices = 0;
				$profile_count = 0;
				
				if (is_array($profiles) && !empty($profiles)) {
					$profile_count = count($profiles);
					foreach ($profiles as $profile) {
						if (!is_array($profile)) continue;
						
						if (isset($profile['enabled']) && $profile['enabled'] == 'on') {
							$active_count++;
						}
						// Count non-empty devices
						if (isset($profile['devices']) && is_array($profile['devices'])) {
							foreach ($profile['devices'] as $dev) {
								if (isset($dev['mac_address']) && !empty($dev['mac_address'])) {
									$total_devices++;
								}
							}
						}
					}
				}
				echo $active_count . " / " . $profile_count . " profiles (" . $total_devices . " devices)";
			?></dd>
		</dl>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Profile & Device Status")?></h2>
	</div>
	<div class="panel-body table-responsive">
		<?php if (empty($profiles)): ?>
			<div class="alert alert-info">
				<i class="fa-solid fa-info-circle"></i>
				<?=gettext("No profiles configured. Go to the Profiles tab to add child profiles and devices.")?>
			</div>
		<?php else: ?>
			<table class="table table-striped table-hover">
				<thead>
					<tr>
						<th><?=gettext("Profile")?></th>
						<th><?=gettext("Device")?></th>
						<th><?=gettext("MAC Address")?></th>
						<th><?=gettext("IP Address")?></th>
						<th><?=gettext("Status")?></th>
						<th><?=gettext("Daily Limit")?></th>
						<th><?=gettext("Usage Today")?></th>
						<th><?=gettext("Remaining")?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					if (is_array($profiles) && !empty($profiles)) {
						foreach ($profiles as $profile): 
							if (!is_array($profile) || !isset($profile['name'])) {
								continue;
							}
							
							if (!isset($profile['enabled']) || $profile['enabled'] != 'on') {
								continue;
							}
							
							$profile_name = htmlspecialchars($profile['name']);
							$daily_limit = isset($profile['daily_limit']) ? intval($profile['daily_limit']) : 0;
							
							// Get weekend bonus if applicable
							if (date('N') >= 6 && isset($profile['weekend_bonus'])) {
								$daily_limit += intval($profile['weekend_bonus']);
							}
							
							// Get profile devices
							$devices = isset($profile['devices']) && is_array($profile['devices']) ? $profile['devices'] : array();
							
							if (empty($devices)): ?>
								<tr>
									<td><?=$profile_name?></td>
									<td colspan="7"><em><?=gettext("No devices configured")?></em></td>
								</tr>
							<?php continue;
							endif;
							
							foreach ($devices as $idx => $device):
								if (!is_array($device) || !isset($device['mac_address'])) {
									continue;
								} 
							$mac = strtolower(trim($device['mac_address']));
							$device_name = htmlspecialchars($device['device_name']);
							$mac_display = htmlspecialchars($device['mac_address']);
							
						// CRITICAL: Get usage from PROFILE (shared time accounting)
						// WHY: All devices in a profile share the same time budget
						// Example: 4 hour limit = 4 hours TOTAL across ALL devices in profile
						$usage_today = 0;
						$device_ip = null;
						// Note: $profile_name is already set from outer loop at line 149
						
						// Get PROFILE usage (not individual device usage)
						// Note: $profile_name is from $profile['name'], NOT $device['profile_name']
						if (isset($state['profiles'][$profile['name']]['usage_today'])) {
							$usage_today = intval($state['profiles'][$profile['name']]['usage_today']);
						}
							
							// Also get device IP for status display
							if (isset($state['mac_to_ip_cache'][$mac])) {
								$device_ip = $state['mac_to_ip_cache'][$mac];
							}
							
							// Calculate remaining time based on PROFILE usage
							$remaining = $daily_limit - $usage_today;
							if ($remaining < 0) $remaining = 0;
							
							// Determine device status
							$is_online = pc_is_device_online($mac);
							$is_time_exceeded = ($daily_limit > 0 && $usage_today >= $daily_limit);
							
							if ($is_time_exceeded) {
								$status = '<span class="label label-danger"><i class="fa-solid fa-clock"></i> Time Exceeded</span>';
								$status_class = 'danger';
							} elseif ($is_online) {
								$status = '<span class="label label-success"><i class="fa-solid fa-check"></i> Online</span>';
								$status_class = 'success';
							} else {
								$status = '<span class="label label-default"><i class="fa-solid fa-power-off"></i> Offline</span>';
								$status_class = 'default';
							}
							
							// Format times
							$usage_formatted = sprintf("%d:%02d", floor($usage_today / 60), $usage_today % 60);
							$limit_formatted = $daily_limit > 0 ? sprintf("%d:%02d", floor($daily_limit / 60), $daily_limit % 60) : "Unlimited";
							$remaining_formatted = $daily_limit > 0 ? sprintf("%d:%02d", floor($remaining / 60), $remaining % 60) : "∞";
							
						?>
						<tr class="<?=$status_class?>">
							<td><?=$profile_name?></td>
							<td><?=$device_name?></td>
							<td><code><?=$mac_display?></code></td>
							<td><?php 
								if ($device_ip) {
									echo '<code>' . htmlspecialchars($device_ip) . '</code>';
								} else {
									echo '<em style="color: #999;">Not Found</em>';
								}
							?></td>
							<td><?=$status?></td>
							<td><?=$limit_formatted?></td>
							<td><?=$usage_formatted?></td>
							<td><strong><?=$remaining_formatted?></strong></td>
						</tr>
						<?php endforeach; // devices ?>
					<?php endforeach; // profiles ?>
					<?php } // if profiles ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Active Schedules")?></h2>
	</div>
	<div class="panel-body">
		<?php
		// Load schedules from new config path
		$schedules = config_get_path('installedpackages/parentalcontrolschedules/config', []);
		
		if (empty($schedules)): ?>
			<div class="alert alert-info">
				<i class="fa-solid fa-info-circle"></i>
				<?=gettext("No schedules configured. Go to the KACI-PC-Schedule tab to add time-based blocking schedules.")?>
			</div>
		<?php else: ?>
			<table class="table table-striped table-condensed">
				<thead>
					<tr>
						<th><?=gettext("Schedule Name")?></th>
						<th><?=gettext("Profile(s)")?></th>
						<th><?=gettext("Time Range")?></th>
						<th><?=gettext("Days")?></th>
						<th><?=gettext("Currently Active")?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					$current_time = date('H:i');
					$current_day_num = date('N'); // 1=Mon, 7=Sun
					$day_map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
					
					foreach ($schedules as $schedule): 
						if (!is_array($schedule)) continue;
						
						// Skip disabled schedules
						if (!isset($schedule['enabled']) || $schedule['enabled'] != 'on') {
							continue;
						}
						
						$name = htmlspecialchars($schedule['name']);
						
						// Handle both old (profile_name) and new (profile_names) format
						if (isset($schedule['profile_names'])) {
							if (is_array($schedule['profile_names'])) {
								$profile = htmlspecialchars(implode(', ', $schedule['profile_names']));
							} else {
								$profile = htmlspecialchars(str_replace(',', ', ', $schedule['profile_names']));
							}
						} elseif (isset($schedule['profile_name'])) {
							$profile = htmlspecialchars($schedule['profile_name']);
						} else {
							$profile = 'Unknown';
						}
						
						$start_time = $schedule['start_time'];
						$end_time = $schedule['end_time'];
						
						// Format days (handle both array and comma-separated string)
						$days_array = array();
						if (isset($schedule['days'])) {
							if (is_array($schedule['days'])) {
								$days_array = $schedule['days'];
							} else {
								$days_array = array_map('trim', explode(',', $schedule['days']));
							}
						}
						$days_display = empty($days_array) ? 'None' : implode(', ', array_map('ucfirst', $days_array));
						
						// Check if currently active
						$is_active = false;
						
						// Check if today matches the schedule days
						$day_matches = false;
						foreach ($days_array as $day) {
							$day_lower = strtolower($day);
							if (isset($day_map[$day_lower]) && $day_map[$day_lower] == $current_day_num) {
								$day_matches = true;
								break;
							}
						}
						
						// If day matches, check time
						if ($day_matches && pc_is_time_in_range($current_time, $start_time, $end_time)) {
							$is_active = true;
						}
						
						$status_badge = $is_active ? 
							'<span class="label label-danger"><i class="fa-solid fa-ban"></i> BLOCKING NOW</span>' : 
							'<span class="label label-default"><i class="fa-solid fa-check"></i> Inactive</span>';
						
						$row_class = $is_active ? 'danger' : '';
					?>
					<tr class="<?=$row_class?>">
						<td><strong><?=$name?></strong></td>
						<td><?=$profile?></td>
						<td><code><?=$start_time?> - <?=$end_time?></code></td>
						<td><?=$days_display?></td>
						<td><?=$status_badge?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<!-- Active Firewall Rules (Table-Based) -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<i class="fa-solid fa-shield-alt"></i> <?=gettext("Active Firewall Rules (pfSense Table)")?>
			<span class="badge" style="background: #5bc0de; margin-left: 10px;" id="rule-count">Loading...</span>
		</h2>
	</div>
	<div class="panel-body">
		<?php
		// Get blocked IPs from pfSense table
		$blocked_ips = array();
		exec('pfctl -t parental_control_blocked -T show 2>&1', $blocked_ips, $return_code);
		
		// Remove empty lines
		$blocked_ips = array_filter($blocked_ips, 'trim');
		
		// Get device info from state file
		$state = pc_load_state();
		$blocked_devices = array();
		
		// Create reverse lookup: IP -> MAC
		$ip_to_mac = array();
		if (isset($state['mac_to_ip_cache']) && is_array($state['mac_to_ip_cache'])) {
			foreach ($state['mac_to_ip_cache'] as $mac => $ip) {
				$ip_to_mac[$ip] = $mac;
			}
		}
		
		foreach ($blocked_ips as $ip) {
			$ip = trim($ip);
			if (empty($ip)) continue;
			
			// Find device info from state
			$device_name = 'Unknown Device';
			$device_mac = 'Unknown';
			$profile_name = 'Unknown';
			$reason = 'Time Limit Exceeded';
			
			// Get MAC from IP
			if (isset($ip_to_mac[$ip])) {
				$mac = $ip_to_mac[$ip];
				$device_mac = $mac;
				
				// Get device details from state
				if (isset($state['devices'][$mac])) {
					$dev = $state['devices'][$mac];
					$device_name = isset($dev['name']) ? $dev['name'] : (isset($dev['hostname']) ? $dev['hostname'] : $ip);
				}
				
				// Get profile and reason from blocked_devices
				if (isset($state['blocked_devices'][$mac])) {
					$block_info = $state['blocked_devices'][$mac];
					
					// Get profile name
					if (isset($block_info['device']['profile_name'])) {
						$profile_name = $block_info['device']['profile_name'];
					} elseif (isset($block_info['device']['child_name'])) {
						$profile_name = $block_info['device']['child_name'];
					} elseif (isset($block_info['profile'])) {
						$profile_name = $block_info['profile'];
					}
					
					// Get reason
					if (isset($block_info['reason'])) {
						$reason = $block_info['reason'];
					}
					
					// Get device name from block info if not found yet
					if ($device_name === 'Unknown Device' && isset($block_info['device']['device_name'])) {
						$device_name = $block_info['device']['device_name'];
					}
				}
			}
			
			$blocked_devices[] = array(
				'ip' => $ip,
				'name' => $device_name,
				'mac' => $device_mac,
				'profile' => $profile_name,
				'reason' => $reason
			);
		}
		
		$device_count = count($blocked_devices);
		
		if ($device_count > 0) { ?>
			<div class="alert alert-warning">
				<i class="fa-solid fa-exclamation-triangle"></i>
				<strong><?=gettext("Blocking Active")?></strong> - 
				<?php echo $device_count; ?> device(s) currently blocked by parental control firewall rules.
			</div>
			
			<p class="text-info">
				<i class="fa-solid fa-info-circle"></i>
				<strong>Method:</strong> pfSense Tables (Native) - Blocking rule IS visible in <strong>Firewall → Rules → Floating</strong>
			</p>
			
			<div style="margin-top: 15px;">
				<strong style="font-size: 15px;">
					<i class="fa-solid fa-ban"></i> <?=gettext("Currently Blocked Devices:")?>
				</strong>
				<table class="table table-striped table-hover" style="margin-top: 10px; border: 1px solid #ddd;">
					<thead>
						<tr style="background: #d9534f; color: white;">
							<th><i class="fa-solid fa-network-wired"></i> IP Address</th>
							<th><i class="fa-solid fa-laptop"></i> Device Name</th>
							<th><i class="fa-solid fa-fingerprint"></i> MAC Address</th>
							<th><i class="fa-solid fa-user"></i> Profile</th>
							<th><i class="fa-solid fa-exclamation-circle"></i> Reason</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($blocked_devices as $device): ?>
						<tr style="background: #fff5f5;">
							<td>
								<code style="color: #d9534f; font-weight: bold; font-size: 13px; background: #ffe6e6; padding: 3px 8px; border-radius: 3px;">
									<?php echo htmlspecialchars($device['ip']); ?>
								</code>
							</td>
							<td>
								<strong><?php echo htmlspecialchars($device['name']); ?></strong>
							</td>
							<td>
								<span style="font-family: 'Courier New', monospace; font-size: 11px; color: #666; background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">
									<?php echo htmlspecialchars($device['mac']); ?>
								</span>
							</td>
							<td>
								<span class="label label-info" style="font-size: 11px;">
									<i class="fa-solid fa-user-circle"></i> <?php echo htmlspecialchars($device['profile']); ?>
								</span>
							</td>
							<td>
								<span class="label label-danger" style="font-size: 11px;">
									<i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($device['reason']); ?>
								</span>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			
			<div class="alert alert-info" style="margin-top: 15px;">
				<h4><i class="fa-solid fa-question-circle"></i> <?=gettext("How Table-Based Blocking Works:")?></h4>
				<ul style="margin-bottom: 0;">
					<li><strong>Alias/Table:</strong> <code>parental_control_blocked</code> contains list of blocked IPs</li>
					<li><strong>Floating Rule:</strong> Blocks traffic from IPs in the table (visible in GUI)</li>
					<li><strong>Dynamic Updates:</strong> IPs added/removed instantly without filter reload</li>
					<li><strong>Rule Ordering:</strong> Floating rules are evaluated BEFORE interface rules (correct order)</li>
				</ul>
			</div>
			
			<script>
				// Update badge count
				document.getElementById('rule-count').textContent = '<?php echo $device_count; ?> blocked';
				document.getElementById('rule-count').style.background = '#d9534f';
			</script>
			
		<?php } else { ?>
			<div class="alert alert-success">
				<i class="fa-solid fa-check-circle"></i>
				<strong><?=gettext("No Blocking Active")?></strong> - All devices currently have access.
			</div>
			<p class="text-muted">
				<i class="fa-solid fa-info-circle"></i>
				Devices will be blocked automatically when:
			</p>
			<ul class="text-muted">
				<li>Profile time limit exceeded</li>
				<li>Currently in blocked schedule time (e.g., bedtime)</li>
			</ul>
			<p class="text-muted">
				<strong>Note:</strong> IPs are added to the <code>parental_control_blocked</code> table dynamically.
			</p>
			
			<script>
				// Update badge
				document.getElementById('rule-count').textContent = '0 blocked';
				document.getElementById('rule-count').style.background = '#5cb85c';
			</script>
		<?php } ?>
		
		<hr>
		<p class="text-muted" style="font-size: 11px; margin-bottom: 0;">
			<strong>Alias/Table:</strong> <code>parental_control_blocked</code> (Firewall → Firewall → Aliases) | 
			<strong>Floating Rule:</strong> <code>Parental Control - Dynamic Blocking</code> (Firewall → Rules → Floating) | 
			<strong>CLI Command:</strong> <code>pfctl -t parental_control_blocked -T show</code>
		</p>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Recent Log Entries")?></h2>
	</div>
	<div class="panel-body">
		<?php
		// Get current log file (dated filename)
		$current_log = pc_get_current_log_file();
		
		if (file_exists($current_log) && filesize($current_log) > 0) {
			$log_lines = array_slice(file($current_log), -20);
			$log_lines = array_reverse($log_lines);
			?>
			<pre style="max-height: 300px; overflow-y: auto;"><?php
			foreach ($log_lines as $line) {
				// Parse JSON and format nicely
				$log_entry = json_decode($line, true);
				if ($log_entry) {
					$timestamp = isset($log_entry['Timestamp']) ? date('H:i:s', strtotime($log_entry['Timestamp'])) : '';
					$body = isset($log_entry['Body']) ? $log_entry['Body'] : '';
					echo htmlspecialchars($timestamp . ' | ' . $body) . "\n";
				} else {
					echo htmlspecialchars($line);
				}
			}
			?></pre>
		<?php } else { ?>
			<p><?=gettext("No log entries yet.")?></p>
		<?php } ?>
	</div>
</div>

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

