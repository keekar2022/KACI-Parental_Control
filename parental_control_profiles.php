<?php
/*
 * parental_control_profiles.php
 * 
 * Manage child profiles and their associated devices
 * Pure PHP implementation for better control and reliability
 * 
 * Part of KACI-Parental_Control for pfSense
 * Copyright (c) 2025 Mukesh Kesharwani
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Check if user has permission
if (!isAllowedPage($_SERVER['SCRIPT_NAME'])) {
	header("Location: /");
	exit;
}

$pgtitle = array("Services", "Parental Control", "Profiles");
$pglinks = array("", "/pkg_edit.php?xml=parental_control.xml", "@self");

// Get configuration
$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);

// Handle form submissions
$input_errors = [];
$savemsg = '';

// DELETE action
if ($_POST['act'] === 'del' && isset($_POST['id']) && is_numeric($_POST['id'])) {
	$id = intval($_POST['id']);
	if (isset($profiles[$id])) {
		$profile_name = $profiles[$id]['name'];
		unset($profiles[$id]);
		$profiles = array_values($profiles); // Re-index
		config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
		write_config("Deleted profile: {$profile_name}");
		parental_control_sync();
		$savemsg = "Profile '{$profile_name}' has been deleted successfully.";
		pc_log("Profile deleted via GUI", 'info', array(
			'profile.name' => $profile_name,
			'event.action' => 'profile_deleted'
		));
	}
}

// DELETE DEVICE action
if ($_POST['act'] === 'del_device' && isset($_POST['profile_id']) && isset($_POST['device_id'])) {
	$profile_id = intval($_POST['profile_id']);
	$device_id = intval($_POST['device_id']);
	if (isset($profiles[$profile_id]['row'][$device_id])) {
		$device_name = $profiles[$profile_id]['row'][$device_id]['device_name'];
		unset($profiles[$profile_id]['row'][$device_id]);
		$profiles[$profile_id]['row'] = array_values($profiles[$profile_id]['row']); // Re-index
		config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
		write_config("Deleted device: {$device_name} from profile: {$profiles[$profile_id]['name']}");
		parental_control_sync();
		$savemsg = "Device '{$device_name}' has been deleted successfully.";
	}
}

// SAVE action (Add or Edit Profile)
if ($_POST['save']) {
	// Validation
	if (empty($_POST['name'])) {
		$input_errors[] = "Profile name is required.";
	}
	if (empty($_POST['daily_limit']) || !is_numeric($_POST['daily_limit'])) {
		$input_errors[] = "Daily limit must be a number (minutes).";
	}
	
	if (empty($input_errors)) {
		$profile = array(
			'name' => trim($_POST['name']),
			'daily_limit' => intval($_POST['daily_limit']),
			'weekend_bonus' => isset($_POST['weekend_bonus']) ? intval($_POST['weekend_bonus']) : 0,
			'enabled' => isset($_POST['enabled']) ? 'on' : 'off'
		);
		
		// Handle devices (if editing existing profile, preserve devices)
		if (isset($_POST['id']) && is_numeric($_POST['id'])) {
			$id = intval($_POST['id']);
			// Preserve existing devices
			if (isset($profiles[$id]['row'])) {
				$profile['row'] = $profiles[$id]['row'];
			}
			$profiles[$id] = $profile;
			$action = "Updated";
		} else {
			// New profile, initialize empty devices array
			$profile['row'] = [];
			$profiles[] = $profile;
			$action = "Added";
		}
		
		config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
		write_config("{$action} profile: {$profile['name']}");
		parental_control_sync();
		$savemsg = "Profile '{$profile['name']}' has been {$action} successfully.";
		
		pc_log("Profile {$action} via GUI", 'info', array(
			'profile.name' => $profile['name'],
			'event.action' => 'profile_' . strtolower($action)
		));
		
		// Reload profiles
		$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
	}
}

// SAVE DEVICE action (Add or Edit Device)
if ($_POST['save_device']) {
	$profile_id = intval($_POST['profile_id']);
	
	// Validation
	if (empty($_POST['device_name'])) {
		$input_errors[] = "Device name is required.";
	}
	if (empty($_POST['mac_address'])) {
		$input_errors[] = "MAC address is required.";
	} elseif (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $_POST['mac_address'])) {
		$input_errors[] = "Invalid MAC address format. Use format: XX:XX:XX:XX:XX:XX";
	}
	
	if (empty($input_errors)) {
		$device = array(
			'device_name' => trim($_POST['device_name']),
			'mac_address' => strtolower(trim($_POST['mac_address'])),
			'ip_address' => trim($_POST['ip_address']),
			'hostname' => trim($_POST['hostname'])
		);
		
		if (!isset($profiles[$profile_id]['row'])) {
			$profiles[$profile_id]['row'] = [];
		}
		
		if (isset($_POST['device_id']) && is_numeric($_POST['device_id'])) {
			// Edit existing device
			$device_id = intval($_POST['device_id']);
			$profiles[$profile_id]['row'][$device_id] = $device;
			$action = "Updated";
		} else {
			// Add new device
			$profiles[$profile_id]['row'][] = $device;
			$action = "Added";
		}
		
		config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
		write_config("{$action} device: {$device['device_name']} to profile: {$profiles[$profile_id]['name']}");
		parental_control_sync();
		$savemsg = "Device '{$device['device_name']}' has been {$action} successfully.";
		
		// Reload profiles
		$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
	}
}

// AUTO-POPULATE DEVICES from DHCP/ARP
if (isset($_POST['auto_populate']) && isset($_POST['profile_id'])) {
	$profile_id = intval($_POST['profile_id']);
	
	try {
		$new_devices = pc_discover_devices();
		$added_count = 0;
		
		if (empty($new_devices)) {
			$savemsg = "No devices found on the network. Make sure devices are connected and have IP addresses.";
		} else {
			foreach ($new_devices as $device) {
				// Check if MAC already exists in this profile
				$exists = false;
				if (isset($profiles[$profile_id]['row'])) {
					foreach ($profiles[$profile_id]['row'] as $existing) {
						if (isset($existing['mac_address']) && 
							pc_normalize_mac($existing['mac_address']) === pc_normalize_mac($device['mac_address'])) {
							$exists = true;
							break;
						}
					}
				}
				
				if (!$exists) {
					if (!isset($profiles[$profile_id]['row'])) {
						$profiles[$profile_id]['row'] = [];
					}
					$profiles[$profile_id]['row'][] = $device;
					$added_count++;
				}
			}
			
			if ($added_count > 0) {
				config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
				write_config("Auto-populated {$added_count} devices to profile: {$profiles[$profile_id]['name']}");
				parental_control_sync();
				$savemsg = "Successfully added {$added_count} device(s) from DHCP/ARP. Total discovered: " . count($new_devices);
				// Reload profiles to show new devices
				$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
			} else {
				$savemsg = "No new devices found. All " . count($new_devices) . " discovered devices are already in this profile.";
			}
		}
	} catch (Exception $e) {
		$input_errors[] = "Auto-discovery failed: " . $e->getMessage();
	}
}

// Get edit ID if present
$edit_id = null;
$edit_profile = null;
$manage_devices_id = null;
$edit_device = null;
$edit_device_id = null;

if (isset($_GET['act']) && $_GET['act'] === 'edit' && isset($_GET['id']) && is_numeric($_GET['id'])) {
	$edit_id = intval($_GET['id']);
	if (isset($profiles[$edit_id])) {
		$edit_profile = $profiles[$edit_id];
	}
}

if (isset($_GET['act']) && $_GET['act'] === 'devices' && isset($_GET['id']) && is_numeric($_GET['id'])) {
	$manage_devices_id = intval($_GET['id']);
}

if (isset($_GET['act']) && $_GET['act'] === 'edit_device' && isset($_GET['id']) && isset($_GET['device_id'])) {
	$manage_devices_id = intval($_GET['id']);
	$edit_device_id = intval($_GET['device_id']);
	if (isset($profiles[$manage_devices_id]['row'][$edit_device_id])) {
		$edit_device = $profiles[$manage_devices_id]['row'][$edit_device_id];
	}
}

if (isset($_GET['act']) && $_GET['act'] === 'add_device' && isset($_GET['id'])) {
	$manage_devices_id = intval($_GET['id']);
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}

$tab_array = array();
$tab_array[] = array("Settings", false, "/pkg_edit.php?xml=parental_control.xml");
$tab_array[] = array("Profiles", true, "/parental_control_profiles.php");
$tab_array[] = array("KACI-PC-Schedule", false, "/parental_control_schedules.php");
$tab_array[] = array("Status", false, "/parental_control_status.php");
display_top_tabs($tab_array);
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Profile Management")?></h2>
	</div>
	<div class="panel-body">
		<div class="infoblock">
			<strong>What are Profiles?</strong><br/>
			Profiles group devices by child/user and apply shared time limits and restrictions.<br/><br/>
			
			<strong>Key Features:</strong>
			<ul style="margin-left: 20px;">
				<li><strong>Shared Time Accounting:</strong> All devices in a profile share the same daily limit</li>
				<li><strong>Weekend Bonus:</strong> Extra time on weekends (Fri-Sun)</li>
				<li><strong>Multiple Devices:</strong> Child's phone, tablet, laptop all tracked together</li>
				<li><strong>Auto-Discovery:</strong> Automatically find devices from DHCP/ARP</li>
			</ul>
		</div>
	</div>
</div>

<!-- Profile List -->
<?php if (!$edit_id && !$manage_devices_id): ?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Configured Profiles")?></h2>
	</div>
	<div class="panel-body">
		<?php if (empty($profiles)): ?>
			<div class="alert alert-info">
				<i class="fa fa-info-circle"></i> No profiles configured yet. Click "Add New Profile" below to create one.
			</div>
		<?php else: ?>
			<div class="table-responsive">
				<table class="table table-striped table-hover">
					<thead>
						<tr>
							<th>Profile Name</th>
							<th>Daily Limit</th>
							<th>Weekend Bonus</th>
							<th>Devices</th>
							<th>Status</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($profiles as $idx => $profile): 
							$device_count = isset($profile['row']) ? count($profile['row']) : 0;
							$enabled = isset($profile['enabled']) && $profile['enabled'] == 'on';
							$status_class = $enabled ? 'success' : 'default';
							$status_text = $enabled ? 'Enabled' : 'Disabled';
							$status_icon = $enabled ? 'check' : 'times';
							
							$daily_limit_hours = floor($profile['daily_limit'] / 60);
							$daily_limit_mins = $profile['daily_limit'] % 60;
							$daily_limit_display = sprintf("%d:%02d", $daily_limit_hours, $daily_limit_mins);
							
							$weekend_bonus = isset($profile['weekend_bonus']) ? $profile['weekend_bonus'] : 0;
							$weekend_bonus_hours = floor($weekend_bonus / 60);
							$weekend_bonus_mins = $weekend_bonus % 60;
							$weekend_bonus_display = $weekend_bonus > 0 ? sprintf("+%d:%02d", $weekend_bonus_hours, $weekend_bonus_mins) : "-";
						?>
						<tr>
							<td><strong><?=htmlspecialchars($profile['name'])?></strong></td>
							<td><code><?=$daily_limit_display?></code></td>
							<td><code><?=$weekend_bonus_display?></code></td>
							<td>
								<span class="badge"><?=$device_count?></span>
								<a href="?act=devices&amp;id=<?=$idx?>" class="btn btn-xs btn-default" title="Manage Devices">
									<i class="fa fa-laptop"></i> Manage
								</a>
							</td>
							<td>
								<span class="label label-<?=$status_class?>">
									<i class="fa fa-<?=$status_icon?>"></i> <?=$status_text?>
								</span>
							</td>
							<td>
								<a href="?act=edit&amp;id=<?=$idx?>" class="btn btn-xs btn-info" title="Edit Profile">
									<i class="fa fa-pencil"></i> Edit
								</a>
								<form method="post" style="display:inline;" onsubmit="return confirm('Delete this profile and all its devices?');">
									<input type="hidden" name="act" value="del" />
									<input type="hidden" name="id" value="<?=$idx?>" />
									<button type="submit" class="btn btn-xs btn-danger" title="Delete">
										<i class="fa fa-trash"></i> Delete
									</button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		
		<a href="?act=edit" class="btn btn-success">
			<i class="fa fa-plus"></i> Add New Profile
		</a>
	</div>
</div>
<?php endif; ?>

<!-- Add/Edit Profile Form -->
<?php if ($edit_id !== null || (isset($_GET['act']) && $_GET['act'] === 'edit' && !isset($_GET['id']))): ?>
<form method="post" action="parental_control_profiles.php" class="form-horizontal">
	<?php if ($edit_id !== null): ?>
		<input type="hidden" name="id" value="<?=$edit_id?>" />
	<?php endif; ?>
	
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=$edit_id !== null ? 'Edit Profile' : 'Add New Profile'?></h2>
		</div>
		<div class="panel-body">
			
			<!-- Profile Name -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Profile Name <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<input type="text" name="name" class="form-control" 
						value="<?=htmlspecialchars($edit_profile['name'] ?? '')?>" 
						placeholder="e.g., Vishesh, Mukesh" required />
					<span class="help-block">Child's name or profile identifier</span>
				</div>
			</div>
			
			<!-- Daily Limit -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Daily Limit (minutes) <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<input type="number" name="daily_limit" class="form-control" style="max-width: 200px;"
						value="<?=htmlspecialchars($edit_profile['daily_limit'] ?? '120')?>" 
						min="0" placeholder="e.g., 120 (2 hours)" required />
					<span class="help-block">
						Maximum internet time per day in minutes. 
						Examples: <code>60</code> (1 hour), <code>120</code> (2 hours), <code>180</code> (3 hours)
					</span>
				</div>
			</div>
			
			<!-- Weekend Bonus -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Weekend Bonus (minutes)</label>
				<div class="col-sm-10">
					<input type="number" name="weekend_bonus" class="form-control" style="max-width: 200px;"
						value="<?=htmlspecialchars($edit_profile['weekend_bonus'] ?? '0')?>" 
						min="0" placeholder="e.g., 60 (1 extra hour)" />
					<span class="help-block">
						Extra time on weekends (Friday-Sunday). Added to daily limit. 
						Leave 0 for no bonus.
					</span>
				</div>
			</div>
			
			<!-- Enabled -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Enabled</label>
				<div class="col-sm-10">
					<label class="checkbox">
						<input type="checkbox" name="enabled" 
							<?=(!$edit_profile || (isset($edit_profile['enabled']) && $edit_profile['enabled'] == 'on')) ? 'checked' : ''?> />
						Enable this profile and start tracking devices
					</label>
				</div>
			</div>
			
		</div>
		<div class="panel-footer">
			<button type="submit" name="save" class="btn btn-primary">
				<i class="fa fa-save"></i> Save Profile
			</button>
			<a href="parental_control_profiles.php" class="btn btn-default">
				<i class="fa fa-times"></i> Cancel
			</a>
			<?php if ($edit_id !== null): ?>
				<a href="?act=devices&amp;id=<?=$edit_id?>" class="btn btn-info pull-right">
					<i class="fa fa-laptop"></i> Manage Devices
				</a>
			<?php endif; ?>
		</div>
	</div>
</form>
<?php endif; ?>

<!-- Manage Devices -->
<?php if ($manage_devices_id !== null && !isset($_GET['device_id']) && $_GET['act'] !== 'add_device'): ?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			Manage Devices for: <strong><?=htmlspecialchars($profiles[$manage_devices_id]['name'])?></strong>
		</h2>
	</div>
	<div class="panel-body">
		<?php if (empty($profiles[$manage_devices_id]['row'])): ?>
			<div class="alert alert-info">
				<i class="fa fa-info-circle"></i> No devices configured yet. Add devices manually or use auto-discovery.
			</div>
		<?php else: ?>
			<div class="table-responsive">
				<table class="table table-striped table-hover">
					<thead>
						<tr>
							<th>Device Name</th>
							<th>MAC Address</th>
							<th>IP Address</th>
							<th>Hostname</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($profiles[$manage_devices_id]['row'] as $dev_idx => $device): ?>
						<tr>
							<td><strong><?=htmlspecialchars($device['device_name'])?></strong></td>
							<td><code><?=htmlspecialchars(strtoupper($device['mac_address']))?></code></td>
							<td><?=htmlspecialchars($device['ip_address'] ?? '-')?></td>
							<td><?=htmlspecialchars($device['hostname'] ?? '-')?></td>
							<td>
								<a href="?act=edit_device&amp;id=<?=$manage_devices_id?>&amp;device_id=<?=$dev_idx?>" 
									class="btn btn-xs btn-info" title="Edit Device">
									<i class="fa fa-pencil"></i> Edit
								</a>
								<form method="post" style="display:inline;" onsubmit="return confirm('Delete this device?');">
									<input type="hidden" name="act" value="del_device" />
									<input type="hidden" name="profile_id" value="<?=$manage_devices_id?>" />
									<input type="hidden" name="device_id" value="<?=$dev_idx?>" />
									<button type="submit" class="btn btn-xs btn-danger" title="Delete">
										<i class="fa fa-trash"></i> Delete
									</button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		
		<a href="?act=add_device&amp;id=<?=$manage_devices_id?>" class="btn btn-success">
			<i class="fa fa-plus"></i> Add Device Manually
		</a>
		
		<form method="post" style="display:inline-block; margin-left: 10px;">
			<input type="hidden" name="profile_id" value="<?=$manage_devices_id?>" />
			<button type="submit" name="auto_populate" class="btn btn-primary">
				<i class="fa fa-magic"></i> Auto-Discover Devices
			</button>
		</form>
		
		<a href="parental_control_profiles.php" class="btn btn-default">
			<i class="fa fa-arrow-left"></i> Back to Profiles
		</a>
	</div>
</div>
<?php endif; ?>

<!-- Add/Edit Device Form -->
<?php if (($manage_devices_id !== null && $edit_device_id !== null) || ($_GET['act'] === 'add_device')): ?>
<form method="post" action="parental_control_profiles.php" class="form-horizontal">
	<input type="hidden" name="profile_id" value="<?=$manage_devices_id?>" />
	<?php if ($edit_device_id !== null): ?>
		<input type="hidden" name="device_id" value="<?=$edit_device_id?>" />
	<?php endif; ?>
	
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title">
				<?=$edit_device_id !== null ? 'Edit Device' : 'Add Device'?> - 
				Profile: <strong><?=htmlspecialchars($profiles[$manage_devices_id]['name'])?></strong>
			</h2>
		</div>
		<div class="panel-body">
			
			<!-- Device Name -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Device Name <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<input type="text" name="device_name" class="form-control" 
						value="<?=htmlspecialchars($edit_device['device_name'] ?? '')?>" 
						placeholder="e.g., Vishesh-iPhone, Mukesh-Laptop" required />
					<span class="help-block">Friendly name for this device</span>
				</div>
			</div>
			
			<!-- MAC Address -->
			<div class="form-group">
				<label class="col-sm-2 control-label">MAC Address <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<input type="text" name="mac_address" class="form-control" 
						value="<?=htmlspecialchars($edit_device['mac_address'] ?? '')?>" 
						placeholder="XX:XX:XX:XX:XX:XX" 
						pattern="([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})" required />
					<span class="help-block">
						Hardware address (required). Format: <code>AA:BB:CC:DD:EE:FF</code>
					</span>
				</div>
			</div>
			
			<!-- IP Address -->
			<div class="form-group">
				<label class="col-sm-2 control-label">IP Address</label>
				<div class="col-sm-10">
					<input type="text" name="ip_address" class="form-control" 
						value="<?=htmlspecialchars($edit_device['ip_address'] ?? '')?>" 
						placeholder="192.168.1.100 (optional)" />
					<span class="help-block">Optional. Will be auto-detected from DHCP/ARP</span>
				</div>
			</div>
			
			<!-- Hostname -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Hostname</label>
				<div class="col-sm-10">
					<input type="text" name="hostname" class="form-control" 
						value="<?=htmlspecialchars($edit_device['hostname'] ?? '')?>" 
						placeholder="device-hostname (optional)" />
					<span class="help-block">Optional. Will be auto-detected from DNS</span>
				</div>
			</div>
			
		</div>
		<div class="panel-footer">
			<button type="submit" name="save_device" class="btn btn-primary">
				<i class="fa fa-save"></i> Save Device
			</button>
			<a href="?act=devices&amp;id=<?=$manage_devices_id?>" class="btn btn-default">
				<i class="fa fa-times"></i> Cancel
			</a>
		</div>
	</div>
</form>
<?php endif; ?>

<!-- Package Footer -->
<div style="text-align: center; margin-top: 30px; padding: 15px; border-top: 2px solid #ddd; background: #f9f9f9;">
	<strong>Keekar's Parental Control</strong> v<?=defined('PC_VERSION') ? PC_VERSION : '0.4.1'?><br>
	Built with Passion by <strong>Mukesh Kesharwani</strong> | © <?=date('Y')?> Keekar
</div>

<?php include("foot.inc"); ?>

