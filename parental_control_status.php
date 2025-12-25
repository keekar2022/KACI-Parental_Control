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
$tab_array[] = array(gettext("Profiles"), false, "/pkg.php?xml=parental_control_profiles.xml");
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
							
							// Get usage from state (IP-based tracking since v0.2.1)
							$usage_today = 0;
							$device_ip = null;
							
							// First, try to find IP from mac_to_ip_cache
							if (isset($state['mac_to_ip_cache'][$mac])) {
								$device_ip = $state['mac_to_ip_cache'][$mac];
							}
							
							// If IP found, get usage from devices_by_ip
							if ($device_ip && isset($state['devices_by_ip'][$device_ip])) {
								$usage_today = isset($state['devices_by_ip'][$device_ip]['usage_today']) ? 
									intval($state['devices_by_ip'][$device_ip]['usage_today']) : 0;
							}
							
							// Calculate remaining time
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

<?php if (!empty($profiles)): ?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Active Schedules")?></h2>
	</div>
	<div class="panel-body">
		<table class="table table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext("Child/Device")?></th>
					<th><?=gettext("Schedule Type")?></th>
					<th><?=gettext("Time Range")?></th>
					<th><?=gettext("Days")?></th>
					<th><?=gettext("Currently Active")?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($devices as $device): 
					if (!isset($device['enable']) || $device['enable'] != 'on') continue;
					$device_label = htmlspecialchars($device['child_name'] . " - " . $device['device_name']);
					
					// Bedtime
					if (isset($device['bedtime_enable']) && $device['bedtime_enable'] == 'on') {
						$active = pc_is_time_in_range(date('H:i'), $device['bedtime_start'], $device['bedtime_end']);
						echo "<tr>";
						echo "<td>{$device_label}</td>";
						echo "<td>Bedtime</td>";
						echo "<td>{$device['bedtime_start']} - {$device['bedtime_end']}</td>";
						echo "<td>Daily</td>";
						echo "<td>" . ($active ? '<span class="label label-danger">Active</span>' : '<span class="label label-default">Inactive</span>') . "</td>";
						echo "</tr>";
					}
					
					// School
					if (isset($device['school_enable']) && $device['school_enable'] == 'on') {
						$current_day = date('N');
						$is_weekday = ($current_day >= 1 && $current_day <= 5);
						$active = $is_weekday && pc_is_time_in_range(date('H:i'), $device['school_start'], $device['school_end']);
						echo "<tr>";
						echo "<td>{$device_label}</td>";
						echo "<td>School Hours</td>";
						echo "<td>{$device['school_start']} - {$device['school_end']}</td>";
						echo "<td>Monday-Friday</td>";
						echo "<td>" . ($active ? '<span class="label label-danger">Active</span>' : '<span class="label label-default">Inactive</span>') . "</td>";
						echo "</tr>";
					}
				endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

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
		<strong>Keekar's Parental Control</strong> v<?php echo defined('PC_VERSION') ? PC_VERSION : '0.0.9'; ?> 
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

