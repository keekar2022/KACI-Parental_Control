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

// Handle manual reset (admin only)
// Note: CSRF protection is automatic via csrf-magic.php included in guiconfig.inc
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_reset') {
	if (!pc_is_admin_user()) {
		$error_message = "Access denied. Only administrators can reset usage.";
	} else {
		// Perform reset using proven logic from diagnostic script
		$state = pc_load_state_from_disk();
		pc_reset_daily_counters($state);
		$state['last_reset'] = time(); // Set to current time, not midnight
		pc_save_state($state);
		
		pc_log("Manual usage reset performed by admin: " . $_SESSION['Username'], 'info');
		$success_message = "Usage counters have been reset successfully!";
	}
}

$pgtitle = array(gettext("Services"), gettext("Keekar's Parental Control"), gettext("Status"));
$pglinks = array("", "@self", "@self");

include("head.inc");

// Custom CSS for compact, readable layout
?>
<style>
.panel-body {
	padding: 12px 15px !important;
}
.panel-heading {
	padding: 8px 15px !important;
}
.panel-title {
	font-size: 16px !important;
	font-weight: 600 !important;
}
.alert {
	padding: 8px 12px !important;
	margin-bottom: 10px !important;
	font-size: 13px !important;
}
.table {
	margin-bottom: 10px !important;
	font-size: 13px !important;
}
.table th {
	font-size: 13px !important;
	font-weight: 600 !important;
	padding: 6px 8px !important;
}
.table td {
	padding: 6px 8px !important;
}
.dl-horizontal dt {
	font-size: 13px !important;
	font-weight: 600 !important;
}
.dl-horizontal dd {
	font-size: 13px !important;
}
.badge {
	font-size: 12px !important;
	padding: 4px 8px !important;
}
.label {
	font-size: 12px !important;
	padding: 3px 6px !important;
}
h2.panel-title {
	margin: 0 !important;
}
</style>
<?php

$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=parental_control.xml&id=0");
$tab_array[] = array(gettext("Profiles"), false, "/parental_control_profiles.php");
$tab_array[] = array(gettext("KACI-PC-Schedule"), false, "/parental_control_schedules.php");
$tab_array[] = array(gettext("Online-Service"), false, "/parental_control_services.php");
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

<?php if (pc_is_admin_user()): ?>
<div class="panel panel-warning">
	<div class="panel-heading">
		<h2 class="panel-title">
			<i class="fa-solid fa-undo"></i> <?=gettext("Administrator Controls")?>
		</h2>
	</div>
	<div class="panel-body">
		<?php if (isset($error_message)): ?>
			<div class="alert alert-danger">
				<i class="fa-solid fa-exclamation-triangle"></i>
				<?=htmlspecialchars($error_message)?>
			</div>
		<?php endif; ?>
		
		<?php if (isset($success_message)): ?>
			<div class="alert alert-success">
				<i class="fa-solid fa-check-circle"></i>
				<?=htmlspecialchars($success_message)?>
			</div>
		<?php endif; ?>
		
		<form method="post" action="<?=$_SERVER['PHP_SELF']?>" onsubmit="return confirm('Are you sure you want to reset all usage counters? This will set all profile and device usage to zero.');">
			<input type="hidden" name="action" value="manual_reset">
			<button type="submit" class="btn btn-warning btn-sm" name="reset">
				<i class="fa-solid fa-undo"></i> <?=gettext("Reset All Usage Counters")?>
			</button>
			<p class="text-muted" style="margin: 3px 0 0 0; padding: 0; font-size: 12px;">
				<strong><?=gettext("Note:")?>:</strong> <?=gettext("This will immediately reset all usage counters to zero for all profiles and devices.")?>
			</p>
		</form>
	</div>
</div>
<?php endif; ?>

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
						<th><?=gettext("Usage Today")?> <small style="font-weight: normal;">(Device)</small></th>
						<th><?=gettext("Remaining")?> <small style="font-weight: normal;">(Profile)</small></th>
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
							
						// IMPROVED ACCOUNTING: Show individual device usage
						// While calculating remaining time from cumulative profile usage
						// This gives better visibility and transparency
						
						$device_ip = null;
						$device_usage_today = 0;
						$profile_total_usage = 0;
						
						// Get device IP for status display
						if (isset($state['mac_to_ip_cache'][$mac])) {
							$device_ip = $state['mac_to_ip_cache'][$mac];
							
							// Get THIS DEVICE's individual usage
							if (isset($state['devices_by_ip'][$device_ip]['usage_today'])) {
								$device_usage_today = intval($state['devices_by_ip'][$device_ip]['usage_today']);
							}
						}
						
						// Get PROFILE's total usage (sum of ALL devices in profile)
						// This is used for remaining time calculation and enforcement
						if (isset($state['profiles'][$profile['name']]['usage_today'])) {
							$profile_total_usage = intval($state['profiles'][$profile['name']]['usage_today']);
						}
						
						// Calculate remaining time based on PROFILE TOTAL usage (shared limit)
						$remaining = $daily_limit - $profile_total_usage;
						if ($remaining < 0) $remaining = 0;
							
							// Determine device status (based on PROFILE total, not individual device)
							$is_online = pc_is_device_online($mac);
							$is_time_exceeded = ($daily_limit > 0 && $profile_total_usage >= $daily_limit);
							
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
							
							// Format times - SHOW INDIVIDUAL DEVICE USAGE
							$device_usage_formatted = sprintf("%d:%02d", floor($device_usage_today / 60), $device_usage_today % 60);
							$limit_formatted = $daily_limit > 0 ? sprintf("%d:%02d", floor($daily_limit / 60), $daily_limit % 60) : "Unlimited";
							$remaining_formatted = $daily_limit > 0 ? sprintf("%d:%02d", floor($remaining / 60), $remaining % 60) : "∞";
							
					?>
					<tr class="<?=$status_class?>">
						<td><?=$profile_name?></td>
						<td>
							<?=$device_name?>
							<?php 
							// NEW v1.4.67: Show auto-discovered badge
							if (!empty($device['auto_discovered'])):
							?>
								<span class="label label-info" style="margin-left: 5px;" title="Auto-discovered on <?=htmlspecialchars($device['auto_discovered'])?>">
									<i class="fa fa-magic"></i> Auto
								</span>
							<?php endif; ?>
						</td>
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
							<td>
								<strong><?=$device_usage_formatted?></strong>
								<?php if ($profile_total_usage != $device_usage_today): ?>
									<br><small style="color: #666;" title="Profile total usage (sum of all devices)">
										<em>Profile Total: <?=sprintf("%d:%02d", floor($profile_total_usage / 60), $profile_total_usage % 60)?></em>
									</small>
								<?php endif; ?>
							</td>
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
		// PERFORMANCE OPTIMIZATION v1.4.30: Use cached pfctl table read
		// WHY: Reduces pfctl calls on status page refreshes, improves responsiveness
		$blocked_ips = pc_get_table_ips_cached('parental_control_blocked');
		
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
			
		<div class="alert alert-info" style="margin: 10px 0; padding: 8px 12px;">
			<h5 style="font-size: 13px; margin: 0 0 6px 0; font-weight: bold;"><i class="fa-solid fa-question-circle"></i> <?=gettext("How Table-Based Blocking Works")?></h5>
			<ul style="margin: 0; padding-left: 20px; font-size: 12px; line-height: 1.6;">
				<li><strong>Alias/Table:</strong> <code>parental_control_blocked</code> contains list of blocked IPs</li>
				<li><strong>Floating Rule:</strong> Blocks traffic from IPs in the table (visible in GUI)</li>
				<li><strong>Dynamic Updates:</strong> IPs added/removed instantly without filter reload</li>
				<li><strong>Rule Ordering:</strong> Floating rules are evaluated BEFORE interface rules</li>
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
			<div style="font-size: 12px; margin: 8px 0; padding: 0;">
				<p style="margin: 4px 0;">
					<i class="fa-solid fa-info-circle"></i>
					<strong>Devices will be blocked automatically when:</strong>
				</p>
				<ul style="margin: 4px 0; padding-left: 25px;">
					<li>Profile time limit exceeded</li>
					<li>Currently in blocked schedule time (e.g., bedtime)</li>
				</ul>
				<p style="margin: 4px 0; font-size: 11px;">
					<strong>Note:</strong> IPs are added to the <code>parental_control_blocked</code> table dynamically.
				</p>
			</div>
			
			<script>
				// Update badge
				document.getElementById('rule-count').textContent = '0 blocked';
				document.getElementById('rule-count').style.background = '#5cb85c';
			</script>
		<?php } ?>
		
		<hr style="margin: 12px 0;">
		<div style="font-size: 12px; color: #777; margin: 8px 0; padding: 8px; background: #f9f9f9; border-left: 3px solid #d9534f; border-radius: 3px;">
			<p style="margin: 0; line-height: 1.4;">
				<strong>Alias/Table:</strong> <code style="font-size: 11px;">parental_control_blocked</code> 
				<span style="color: #999;">(Firewall → Firewall → Aliases)</span>
			</p>
			<p style="margin: 2px 0 0 0; line-height: 1.4;">
				<strong>Floating Rule:</strong> <code style="font-size: 11px;">Parental Control - Dynamic Blocking</code> 
				<span style="color: #999;">(Firewall → Rules → Floating)</span>
			</p>
			<p style="margin: 2px 0 0 0; line-height: 1.4;">
				<strong>CLI Command:</strong> <code style="font-size: 11px; background: #fff; padding: 2px 6px; border: 1px solid #ddd; border-radius: 3px;">pfctl -t parental_control_blocked -T show</code>
			</p>
		</div>
	</div>
</div>

<!-- Per-Service Usage Breakdown -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<i class="fa-solid fa-pie-chart"></i> <?=gettext("Per-Service Usage Breakdown")?>
		</h2>
	</div>
	<div class="panel-body table-responsive">
		<?php
		$has_service_usage = false;
		
		// Check if any device has service usage
		if (isset($state['devices_by_ip'])) {
			foreach ($state['devices_by_ip'] as $ip => $device_data) {
				if (isset($device_data['service_usage']) && !empty($device_data['service_usage'])) {
					foreach ($device_data['service_usage'] as $service_name => $service_data) {
						if ($service_data['usage_today'] > 0) {
							$has_service_usage = true;
							break 2;
						}
					}
				}
			}
		}
		
		if (!$has_service_usage): ?>
			<div class="alert alert-info">
				<i class="fa-solid fa-info-circle"></i>
				<?=gettext("No per-service usage data yet. Service usage will be tracked once devices access monitored services (Facebook, YouTube, Discord, etc.).")?>
				<p class="text-muted" style="margin-top: 10px; margin-bottom: 0;">
					<strong>Tracked Services:</strong> Check the <em>Online-Service</em> tab to see configured services.
				</p>
			</div>
		<?php else: ?>
			<table class="table table-striped table-hover">
				<thead>
					<tr style="background: #5bc0de; color: white;">
						<th><?=gettext("Profile")?></th>
						<th><?=gettext("Device")?></th>
						<th><?=gettext("Service")?></th>
						<th><?=gettext("Time Today")?></th>
						<th><?=gettext("Time This Week")?></th>
						<th><?=gettext("Last Seen")?></th>
						<th><?=gettext("Active Now")?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					// Service icon mapping
					$service_icons = array(
						'Facebook' => 'fa-facebook',
						'YouTube' => 'fa-youtube-play',
						'Discord' => 'fa-comments',
						'TikTok' => 'fa-music',
						'Instagram' => 'fa-instagram',
						'Twitter' => 'fa-twitter',
						'Twitch' => 'fa-twitch',
						'Netflix' => 'fa-film'
					);
					
					// Iterate through profiles to get device-to-profile mapping
					foreach ($profiles as $profile):
						if (!is_array($profile) || !isset($profile['name'])) continue;
						if (!isset($profile['enabled']) || $profile['enabled'] != 'on') continue;
						
						$profile_name = htmlspecialchars($profile['name']);
						$devices = isset($profile['devices']) && is_array($profile['devices']) ? $profile['devices'] : array();
						
						foreach ($devices as $device):
							if (!is_array($device) || !isset($device['mac_address'])) continue;
							
							$mac = strtolower(trim($device['mac_address']));
							$device_name = htmlspecialchars($device['device_name']);
							
							// Get IP from state
							$device_ip = null;
							if (isset($state['mac_to_ip_cache'][$mac])) {
								$device_ip = $state['mac_to_ip_cache'][$mac];
							}
							
							if (!$device_ip || !isset($state['devices_by_ip'][$device_ip])) continue;
							if (!isset($state['devices_by_ip'][$device_ip]['service_usage'])) continue;
							
							$service_usage = $state['devices_by_ip'][$device_ip]['service_usage'];
							
							// Display each service this device has used
							foreach ($service_usage as $service_name => $service_data):
								if ($service_data['usage_today'] == 0) continue; // Skip unused services
								
								$usage_today = sprintf("%d:%02d", floor($service_data['usage_today'] / 60), $service_data['usage_today'] % 60);
								$usage_week = sprintf("%d:%02d", floor($service_data['usage_week'] / 60), $service_data['usage_week'] % 60);
								$last_seen = isset($service_data['last_seen']) && $service_data['last_seen'] > 0 ? 
									date('H:i:s', $service_data['last_seen']) : 'Never';
								$connections = isset($service_data['connections']) ? $service_data['connections'] : 0;
								
								$icon = isset($service_icons[$service_name]) ? $service_icons[$service_name] : 'fa-globe';
								
								// NEW v1.4.31: Get profile-level service usage and limits
								$profile_service_usage = 0;
								$service_limit = 0;
								$service_limit_with_bonus = 0;
								$is_weekend = (date('N') >= 6);
								$is_service_blocked = false;
								
								// Get profile service usage
								if (isset($state['profiles'][$profile_name]['service_usage'][$service_name]['usage_today'])) {
									$profile_service_usage = $state['profiles'][$profile_name]['service_usage'][$service_name]['usage_today'];
								}
								
								// Get service limit from profile config
								if (isset($profile['service_limits'])) {
									$service_alias = 'pc_service_' . strtolower($service_name);
									if (isset($profile['service_limits'][$service_alias]['daily_limit'])) {
										$service_limit = intval($profile['service_limits'][$service_alias]['daily_limit']);
										$service_limit_with_bonus = $service_limit;
										
										// Add weekend bonus if applicable
										if ($is_weekend && isset($profile['service_limits'][$service_alias]['weekend_bonus'])) {
											$service_limit_with_bonus += intval($profile['service_limits'][$service_alias]['weekend_bonus']);
										}
										
										// Check if blocked
										if ($service_limit > 0 && $profile_service_usage >= $service_limit_with_bonus) {
											$is_service_blocked = true;
										}
									}
								}
								
								$remaining_minutes = $service_limit_with_bonus - $profile_service_usage;
								$row_style = $is_service_blocked ? 'background-color: #f8d7da;' : '';
							?>
							<tr style="<?=$row_style?>">
								<td><?=$profile_name?></td>
								<td><strong><?=$device_name?></strong></td>
								<td>
									<i class="fa-solid <?=$icon?>"></i>
									<strong><?=htmlspecialchars($service_name)?></strong>
									<?php if ($is_service_blocked): ?>
										<span class="label label-danger" style="margin-left: 5px;">BLOCKED</span>
									<?php endif; ?>
									<?php if ($service_limit > 0): ?>
										<br><small class="text-muted">
											Profile: <?=sprintf("%d:%02d", floor($profile_service_usage / 60), $profile_service_usage % 60)?> / <?=sprintf("%d:%02d", floor($service_limit_with_bonus / 60), $service_limit_with_bonus % 60)?>
											<?php if (!$is_service_blocked && $remaining_minutes > 0): ?>
												(<?=sprintf("%d:%02d", floor($remaining_minutes / 60), $remaining_minutes % 60)?> left)
											<?php endif; ?>
										</small>
									<?php endif; ?>
								</td>
								<td><span class="label label-primary" style="font-size: 13px;"><?=$usage_today?></span></td>
								<td><?=$usage_week?></td>
								<td><small class="text-muted"><?=$last_seen?></small></td>
								<td>
									<?php if ($connections > 0 && !$is_service_blocked): ?>
										<span class="label label-success"><?=$connections?> active</span>
									<?php elseif ($is_service_blocked): ?>
										<span class="label label-danger">Blocked</span>
									<?php else: ?>
										<span class="label label-default">Idle</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php 
							endforeach; // services
						endforeach; // devices
					endforeach; // profiles
					?>
				</tbody>
			</table>
			
			<div class="alert alert-info" style="margin: 10px 0; padding: 8px 12px;">
				<h5 style="font-size: 13px; margin: 0 0 6px 0; font-weight: bold;"><i class="fa-solid fa-info-circle"></i> <?=gettext("About Service Tracking")?></h5>
				<ul style="margin: 0; padding-left: 20px; font-size: 12px; line-height: 1.6;">
					<li><strong>Tracked Services:</strong> Only services configured in <em>Online-Service</em> tab are tracked</li>
					<li><strong>Update Frequency:</strong> Service usage updates every <?=PC_CRON_INTERVAL_SECONDS / 60?> minutes</li>
					<li><strong>Accuracy:</strong> Based on actual TCP connections to service IP ranges</li>
					<li><strong>Active Connections:</strong> Number of current connections to this service</li>
					<li><strong>Reset:</strong> Daily usage resets at midnight (weekly usage preserved)</li>
				</ul>
			</div>
		<?php endif; ?>
	</div>
</div>

<!-- Monitored Devices (Table-Based) -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<i class="fa-solid fa-eye"></i> <?=gettext("Monitored Devices (pfSense Table)")?>
			<span class="badge" style="background: #5bc0de; margin-left: 10px;" id="monitor-count">Loading...</span>
		</h2>
	</div>
	<div class="panel-body">
		<?php
		// PERFORMANCE OPTIMIZATION v1.4.30: Use cached pfctl table read
		// WHY: Reduces pfctl calls on status page refreshes, improves responsiveness
		$monitored_ips = pc_get_table_ips_cached('parental_control_monitor');
		
		// Get device info from state file
		$state = pc_load_state();
		$monitored_devices = array();
		
		// Create reverse lookup: IP -> MAC (reuse from above if needed)
		$ip_to_mac = array();
		if (isset($state['mac_to_ip_cache']) && is_array($state['mac_to_ip_cache'])) {
			foreach ($state['mac_to_ip_cache'] as $mac => $ip) {
				$ip_to_mac[$ip] = $mac;
			}
		}
		
		foreach ($monitored_ips as $ip) {
			$ip = trim($ip);
			if (empty($ip)) continue;
			
			// Find device info from profiles configuration
			$device_name = 'Unknown Device';
			$device_mac = 'Unknown';
			$profile_name = 'Unknown';
			$status = 'Monitoring';
			
			// Get MAC from IP
			if (isset($ip_to_mac[$ip])) {
				$mac = $ip_to_mac[$ip];
				$device_mac = $mac;
				
				// Search through ALL profiles to find this device
				// This matches the logic used in the main "Profile & Device Status" section
				$device_found = false;
				if (is_array($profiles) && !empty($profiles)) {
					foreach ($profiles as $profile) {
						if (!is_array($profile) || !isset($profile['name'])) continue;
						
						// Get profile devices
						$profile_devices = isset($profile['devices']) && is_array($profile['devices']) ? $profile['devices'] : array();
						
						// Search for matching MAC in this profile
						foreach ($profile_devices as $device) {
							if (!is_array($device) || !isset($device['mac_address'])) continue;
							
							$device_mac_normalized = strtolower(trim($device['mac_address']));
							if ($device_mac_normalized === $mac) {
								// Found the device!
								$device_name = isset($device['device_name']) && !empty($device['device_name']) ? $device['device_name'] : $ip;
								$profile_name = $profile['name'];
								$device_found = true;
								break 2; // Break out of both loops
							}
						}
					}
				}
				
				// If device not found in profiles, try state file as fallback
				if (!$device_found && isset($state['devices'][$mac])) {
					$dev = $state['devices'][$mac];
					$device_name = isset($dev['name']) ? $dev['name'] : (isset($dev['hostname']) ? $dev['hostname'] : $ip);
					
					// Try to get profile name from state
					if (isset($dev['profile_name'])) {
						$profile_name = $dev['profile_name'];
					} elseif (isset($dev['child_name'])) {
						$profile_name = $dev['child_name'];
					}
				}
				
				// Check if device is online
				$is_online = pc_is_device_online($mac);
				$status = $is_online ? 'Online' : 'Offline';
			}
			
			$monitored_devices[] = array(
				'ip' => $ip,
				'name' => $device_name,
				'mac' => $device_mac,
				'profile' => $profile_name,
				'status' => $status
			);
		}
		
		$monitor_count = count($monitored_devices);
		
		if ($monitor_count > 0) { ?>
			<div class="alert alert-info">
				<i class="fa-solid fa-eye"></i>
				<strong><?=gettext("Monitoring Active")?></strong> - 
				<?php echo $monitor_count; ?> device(s) currently being monitored by parental control.
			</div>
			
			<p class="text-info">
				<i class="fa-solid fa-info-circle"></i>
				<strong>Method:</strong> pfSense Tables (Native) - Monitoring rules ARE visible in <strong>Firewall → Rules → Floating</strong>
			</p>
			
			<div style="margin-top: 15px;">
				<strong style="font-size: 15px;">
					<i class="fa-solid fa-eye"></i> <?=gettext("Currently Monitored Devices:")?>
				</strong>
				<table class="table table-striped table-hover" style="margin-top: 10px; border: 1px solid #ddd;">
					<thead>
						<tr style="background: #5bc0de; color: white;">
							<th><i class="fa-solid fa-network-wired"></i> IP Address</th>
							<th><i class="fa-solid fa-laptop"></i> Device Name</th>
							<th><i class="fa-solid fa-fingerprint"></i> MAC Address</th>
							<th><i class="fa-solid fa-user"></i> Profile</th>
							<th><i class="fa-solid fa-signal"></i> Status</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($monitored_devices as $device): 
							$status_color = ($device['status'] == 'Online') ? '#5cb85c' : '#999';
							$status_icon = ($device['status'] == 'Online') ? 'fa-check-circle' : 'fa-power-off';
						?>
						<tr style="background: #f9f9f9;">
							<td>
								<code style="color: #5bc0de; font-weight: bold; font-size: 13px; background: #e6f7ff; padding: 3px 8px; border-radius: 3px;">
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
								<span class="label" style="font-size: 11px; background: <?php echo $status_color; ?>;">
									<i class="fa-solid <?php echo $status_icon; ?>"></i> <?php echo htmlspecialchars($device['status']); ?>
								</span>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			
			<div class="alert alert-info" style="margin: 10px 0; padding: 8px 12px;">
				<h5 style="font-size: 13px; margin: 0 0 6px 0; font-weight: bold;"><i class="fa-solid fa-question-circle"></i> <?=gettext("How Device Monitoring Works")?></h5>
				<ul style="margin: 0; padding-left: 20px; font-size: 12px; line-height: 1.6;">
					<li><strong>Alias/Table:</strong> <code>parental_control_monitor</code> contains list of monitored device IPs</li>
					<li><strong>Floating Rules:</strong> Log and track traffic from monitored devices (visible in GUI)</li>
					<li><strong>Dynamic Updates:</strong> IPs added/removed instantly without filter reload</li>
					<li><strong>Purpose:</strong> Track usage time and enforce daily limits for profiles</li>
					<li><strong>Access:</strong> Monitored devices have internet access (unless blocked by time limit)</li>
				</ul>
			</div>
			
			<script>
				// Update badge count
				document.getElementById('monitor-count').textContent = '<?php echo $monitor_count; ?> monitored';
				document.getElementById('monitor-count').style.background = '#5bc0de';
			</script>
			
		<?php } else { ?>
			<div class="alert alert-warning">
				<i class="fa-solid fa-exclamation-circle"></i>
				<strong><?=gettext("No Monitoring Active")?></strong> - No devices currently being monitored.
			</div>
			<div style="font-size: 12px; margin: 8px 0; padding: 0;">
				<p style="margin: 4px 0;">
					<i class="fa-solid fa-info-circle"></i>
					<strong>Devices are added to monitoring when:</strong>
				</p>
				<ul style="margin: 4px 0; padding-left: 25px;">
					<li>They are part of an enabled profile</li>
					<li>They have internet access (not blocked)</li>
					<li>Their usage time is being tracked</li>
				</ul>
				<p style="margin: 4px 0; font-size: 11px; color: #666;">
					<strong>Note:</strong> IPs are added to the <code>parental_control_monitor</code> table dynamically by the cron job.
				</p>
			</div>
			
			<script>
				// Update badge
				document.getElementById('monitor-count').textContent = '0 monitored';
				document.getElementById('monitor-count').style.background = '#f0ad4e';
			</script>
		<?php } ?>
		
		<hr style="margin: 12px 0;">
		<div style="font-size: 12px; color: #777; margin: 8px 0; padding: 8px; background: #f9f9f9; border-left: 3px solid #5bc0de; border-radius: 3px;">
			<p style="margin: 0; line-height: 1.4;">
				<strong>Alias/Table:</strong> <code style="font-size: 11px;">parental_control_monitor</code> 
				<span style="color: #999;">(Firewall → Firewall → Aliases)</span>
			</p>
			<p style="margin: 2px 0 0 0; line-height: 1.4;">
				<strong>Floating Rules:</strong> Service-specific monitoring rules 
				<span style="color: #999;">(Firewall → Rules → Floating)</span>
			</p>
			<p style="margin: 2px 0 0 0; line-height: 1.4;">
				<strong>CLI Command:</strong> <code style="font-size: 11px; background: #fff; padding: 2px 6px; border: 1px solid #ddd; border-radius: 3px;">pfctl -t parental_control_monitor -T show</code>
			</p>
		</div>
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

