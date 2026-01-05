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

// DEBUG: Log page access
// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: === PAGE LOADED ===");
// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: REQUEST_URI: " . $_SERVER['REQUEST_URI']);

// Check if user has permission
if (!isAllowedPage($_SERVER['SCRIPT_NAME'])) {
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Permission denied, redirecting");
	header("Location: /");
	exit;
}

// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Permission check passed");

$pgtitle = array("Services", "Parental Control", "Profiles");
$pglinks = array("", "/pkg_edit.php?xml=parental_control.xml", "@self");

/**
 * Get all PC_Service_ aliases from firewall
 * @return array Array of service aliases with name and description
 */
function pc_get_service_aliases() {
	$service_aliases = array();
	$aliases = config_get_path('aliases/alias', array());
	
	if (is_array($aliases)) {
		foreach ($aliases as $alias) {
			// Check if alias name starts with PC_Service_
			if (isset($alias['name']) && strpos($alias['name'], 'PC_Service_') === 0) {
				$service_name = substr($alias['name'], strlen('PC_Service_')); // Remove prefix
				$service_aliases[] = array(
					'alias_name' => $alias['name'],
					'service_name' => $service_name,
					'description' => isset($alias['descr']) ? $alias['descr'] : $service_name
				);
			}
		}
	}
	
	return $service_aliases;
}

// Get configuration
$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Loaded " . count($profiles) . " existing profiles");

// Get available service aliases
$service_aliases = pc_get_service_aliases();

// Handle form submissions
$input_errors = [];
$savemsg = '';

// DEBUG: Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: POST request detected");
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: POST keys: " . implode(", ", array_keys($_POST)));
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: isset(_POST['save']): " . (isset($_POST['save']) ? 'YES' : 'NO'));
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: value of _POST['save']: '" . ($_POST['save'] ?? 'NULL') . "'");
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: if (_POST['save']) would be: " . ($_POST['save'] ? 'TRUE' : 'FALSE'));
} else {
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: GET request (just viewing page)");
}

// DELETE action
if ($_POST['act'] === 'del' && isset($_POST['id']) && is_numeric($_POST['id'])) {
	$id = intval($_POST['id']);
	if (isset($profiles[$id])) {
		$profile_name = $profiles[$id]['name'];
		unset($profiles[$id]);
		$profiles = array_values($profiles); // Re-index
		config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
		write_config("Deleted profile: {$profile_name}");
		
		// Try to sync, but don't fail if it doesn't work
		try {
			parental_control_sync();
		} catch (Exception $e) {
			pc_log("Sync failed but profile deleted: " . $e->getMessage(), 'warning');
		}
		
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
		
		try {
			parental_control_sync();
		} catch (Exception $e) {
			pc_log("Sync failed but device deleted: " . $e->getMessage(), 'warning');
		}
		
		$savemsg = "Device '{$device_name}' has been deleted successfully.";
	}
}

// SAVE action (Add or Edit Profile)
if (isset($_POST['save'])) {
	// DEBUG: Log that save was triggered
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Save button clicked");
	// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: POST data: " . print_r($_POST, true));
	
	// Validation
	if (empty($_POST['name'])) {
		$input_errors[] = "Profile name is required.";
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Validation failed - name empty");
	}
	if (empty($_POST['daily_limit']) || !is_numeric($_POST['daily_limit'])) {
		$input_errors[] = "Daily limit must be a number (minutes).";
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Validation failed - daily_limit invalid");
	}
	
	if (empty($input_errors)) {
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Validation passed, creating profile");
		
		$profile = array(
			'name' => trim($_POST['name']),
			'daily_limit' => intval($_POST['daily_limit']),
			'weekend_bonus' => isset($_POST['weekend_bonus']) ? intval($_POST['weekend_bonus']) : 0,
			'enabled' => isset($_POST['enabled']) ? 'on' : 'off'
		);
		
		// Handle service-specific limits
		$service_limits = array();
		foreach ($service_aliases as $service) {
			$alias_name = $service['alias_name'];
			$daily_key = 'service_daily_' . $alias_name;
			$weekend_key = 'service_weekend_' . $alias_name;
			
			if (isset($_POST[$daily_key]) && is_numeric($_POST[$daily_key]) && intval($_POST[$daily_key]) > 0) {
				$service_limits[$alias_name] = array(
					'daily_limit' => intval($_POST[$daily_key]),
					'weekend_bonus' => isset($_POST[$weekend_key]) && is_numeric($_POST[$weekend_key]) ? intval($_POST[$weekend_key]) : 0
				);
			}
		}
		
		if (!empty($service_limits)) {
			$profile['service_limits'] = $service_limits;
		}
		
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Profile data: " . print_r($profile, true));
		
		// Handle devices (if editing existing profile, preserve devices)
		if (isset($_POST['id']) && is_numeric($_POST['id'])) {
			$id = intval($_POST['id']);
			// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Updating existing profile ID: $id");
			// Preserve existing devices
			if (isset($profiles[$id]['row'])) {
				$profile['row'] = $profiles[$id]['row'];
			}
			$profiles[$id] = $profile;
			$action = "Updated";
		} else {
			// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Adding new profile");
			// New profile, initialize empty devices array
			$profile['row'] = [];
			$profiles[] = $profile;
			$action = "Added";
		}
		
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Calling config_set_path");
		config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
		
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Calling write_config");
		try {
			write_config("{$action} profile: {$profile['name']}");
			// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: write_config SUCCESS");
		} catch (Exception $e) {
			// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: write_config FAILED: " . $e->getMessage());
			$input_errors[] = "Failed to save configuration: " . $e->getMessage();
		}
		
		// Try to sync, but don't fail if it doesn't work
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Calling parental_control_sync");
		try {
			parental_control_sync();
			// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Sync SUCCESS");
		} catch (Exception $e) {
			// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Sync FAILED: " . $e->getMessage());
			// Log error but continue - profile was already saved
			pc_log("Sync failed but profile saved: " . $e->getMessage(), 'warning');
		}
		
		if (empty($input_errors)) {
			$savemsg = "Profile '{$profile['name']}' has been {$action} successfully.";
			// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: SUCCESS - Setting savemsg: $savemsg");
		}
		
		pc_log("Profile {$action} via GUI", 'info', array(
			'profile.name' => $profile['name'],
			'event.action' => 'profile_' . strtolower($action)
		));
		
		// Reload profiles
		$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Reloaded profiles, count: " . count($profiles));
	} else {
		// DEBUG: error_log("PARENTAL_CONTROL_DEBUG: Save failed due to validation errors");
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
		
		try {
			parental_control_sync();
		} catch (Exception $e) {
			pc_log("Sync failed but device saved: " . $e->getMessage(), 'warning');
		}
		
		$savemsg = "Device '{$device['device_name']}' has been {$action} successfully.";
		
		// Reload profiles
		$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
	}
}

// DISCOVER DEVICES (show selection interface)
$discovered_devices = [];
$show_device_selector = false;
if (isset($_POST['discover_devices']) && isset($_POST['profile_id'])) {
	$profile_id = intval($_POST['profile_id']);
	
	try {
		$all_devices = pc_discover_devices();
		
		if (empty($all_devices)) {
			$savemsg = "No devices found on the network. Make sure devices are connected and have IP addresses.";
		} else {
			// Get all assigned MACs from ALL profiles
			$assigned_macs = [];
			foreach ($profiles as $prof) {
				if (isset($prof['row']) && is_array($prof['row'])) {
					foreach ($prof['row'] as $dev) {
						if (isset($dev['mac_address'])) {
							$assigned_macs[] = pc_normalize_mac($dev['mac_address']);
						}
					}
				}
			}
			
			// Filter out already assigned devices
			foreach ($all_devices as $device) {
				$mac = pc_normalize_mac($device['mac_address']);
				if (!in_array($mac, $assigned_macs)) {
					$discovered_devices[] = $device;
				}
			}
			
			if (empty($discovered_devices)) {
				$savemsg = "Found " . count($all_devices) . " device(s), but all are already assigned to profiles.";
			} else {
				$show_device_selector = true;
				$savemsg = "Found " . count($discovered_devices) . " unassigned device(s). Select the ones you want to add:";
			}
		}
	} catch (Exception $e) {
		$input_errors[] = "Auto-discovery failed: " . $e->getMessage();
	}
}

// ADD SELECTED DEVICES
if (isset($_POST['add_selected_devices']) && isset($_POST['profile_id'])) {
	$profile_id = intval($_POST['profile_id']);
	
	if (empty($_POST['selected_devices'])) {
		$input_errors[] = "Please select at least one device to add.";
	} else {
		$added_count = 0;
		
		// Decode the selected devices JSON
		foreach ($_POST['selected_devices'] as $device_json) {
			$device = json_decode($device_json, true);
			
			if ($device && isset($device['mac_address'])) {
				// Double-check it's not already in this profile
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
		}
		
		if ($added_count > 0) {
			config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
			write_config("Added {$added_count} discovered devices to profile: {$profiles[$profile_id]['name']}");
			parental_control_sync();
			$savemsg = "Successfully added {$added_count} device(s) to profile.";
			// Reload profiles
			$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', []);
		} else {
			$savemsg = "No devices were added (they may have been added already).";
		}
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
$tab_array[] = array("Online-Service", false, "/parental_control_services.php");
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
							<th>Service Limits</th>
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
								<?php 
								$service_limits = isset($profile['service_limits']) ? $profile['service_limits'] : array();
								if (!empty($service_limits)): 
								?>
									<span class="badge badge-info" title="<?=count($service_limits)?> service(s) with custom limits">
										<i class="fa fa-globe"></i> <?=count($service_limits)?>
									</span>
									<button type="button" class="btn btn-xs btn-default" 
										data-toggle="collapse" data-target="#service-limits-<?=$idx?>" 
										title="Show/Hide Service Limits">
										<i class="fa fa-eye"></i>
									</button>
									<div id="service-limits-<?=$idx?>" class="collapse" style="margin-top: 5px;">
										<small>
											<?php foreach ($service_limits as $alias_name => $limits): 
												$svc_name = str_replace('PC_Service_', '', $alias_name);
												$svc_daily = $limits['daily_limit'];
												$svc_weekend = isset($limits['weekend_bonus']) ? $limits['weekend_bonus'] : 0;
											?>
												<div style="padding: 2px 0;">
													<strong><?=htmlspecialchars($svc_name)?></strong>: 
													<?=$svc_daily?>min
													<?php if ($svc_weekend > 0): ?>
														(+<?=$svc_weekend?>min weekend)
													<?php endif; ?>
												</div>
											<?php endforeach; ?>
										</small>
									</div>
								<?php else: ?>
									<span class="text-muted">-</span>
								<?php endif; ?>
							</td>
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
		
		<!-- Service-Specific Limits -->
		<?php if (!empty($service_aliases)): ?>
		<div class="form-group">
			<label class="col-sm-2 control-label">
				<i class="fa fa-globe"></i> Service-Specific Limits
			</label>
			<div class="col-sm-10">
				<div class="panel panel-default">
					<div class="panel-heading">
						<h3 class="panel-title">
							<i class="fa fa-list"></i> Online Service Time Limits
							<span class="badge badge-info"><?=count($service_aliases)?> Available</span>
						</h3>
					</div>
					<div class="panel-body">
						<p class="text-muted">
							<i class="fa fa-info-circle"></i> 
							Set different time limits for specific online services (YouTube, Facebook, Discord, etc.). 
							These limits are <strong>separate</strong> from the general daily limit above.
							Leave empty to not track these services separately.
						</p>
						
						<div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
							<table class="table table-striped table-hover table-condensed">
								<thead style="position: sticky; top: 0; background: #f5f5f5;">
									<tr>
										<th width="30%">Service</th>
										<th width="30%">Daily Limit (min)</th>
										<th width="30%">Weekend Bonus (min)</th>
										<th width="10%">Action</th>
									</tr>
								</thead>
								<tbody>
									<?php 
									$existing_limits = isset($edit_profile['service_limits']) ? $edit_profile['service_limits'] : array();
									foreach ($service_aliases as $service): 
										$alias_name = $service['alias_name'];
										$service_name = $service['service_name'];
										$current_daily = isset($existing_limits[$alias_name]['daily_limit']) ? $existing_limits[$alias_name]['daily_limit'] : '';
										$current_weekend = isset($existing_limits[$alias_name]['weekend_bonus']) ? $existing_limits[$alias_name]['weekend_bonus'] : '';
									?>
									<tr>
										<td>
											<strong><?=htmlspecialchars($service_name)?></strong>
											<br>
											<small class="text-muted"><?=htmlspecialchars($alias_name)?></small>
										</td>
										<td>
											<input type="number" 
												name="service_daily_<?=htmlspecialchars($alias_name)?>" 
												class="form-control input-sm" 
												value="<?=htmlspecialchars($current_daily)?>"
												min="0" 
												placeholder="e.g., 30"
												style="max-width: 150px;">
										</td>
										<td>
											<input type="number" 
												name="service_weekend_<?=htmlspecialchars($alias_name)?>" 
												class="form-control input-sm" 
												value="<?=htmlspecialchars($current_weekend)?>"
												min="0" 
												placeholder="e.g., 15"
												style="max-width: 150px;">
										</td>
										<td>
											<button type="button" class="btn btn-xs btn-default" 
												onclick="document.getElementsByName('service_daily_<?=htmlspecialchars($alias_name)?>')[0].value=''; document.getElementsByName('service_weekend_<?=htmlspecialchars($alias_name)?>')[0].value='';"
												title="Clear limits">
												<i class="fa fa-times"></i>
											</button>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						
						<div class="alert alert-info" style="margin-top: 10px; margin-bottom: 0;">
							<i class="fa fa-lightbulb-o"></i> <strong>How it works:</strong>
							<ul style="margin-bottom: 0;">
								<li><strong>General Limit:</strong> Controls total internet time (set above)</li>
								<li><strong>Service Limits:</strong> Additional restrictions for specific services</li>
								<li><strong>Example:</strong> 120min daily limit + 30min YouTube limit = child can use YouTube for max 30 minutes of their 120 minutes</li>
								<li><strong>Note:</strong> Requires firewall rules using these aliases to enforce service-specific blocking</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php else: ?>
		<div class="form-group">
			<label class="col-sm-2 control-label"></label>
			<div class="col-sm-10">
				<div class="alert alert-warning">
					<i class="fa fa-exclamation-triangle"></i> 
					<strong>No Service Aliases Found</strong><br>
					Create service aliases (starting with "PC_Service_") from the 
					<a href="/parental_control_services.php" class="alert-link">Online-Service</a> tab 
					to enable per-service time limits.
				</div>
			</div>
		</div>
		<?php endif; ?>
		
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
			<button type="submit" name="discover_devices" class="btn btn-primary">
				<i class="fa fa-magic"></i> Auto-Discover Devices
			</button>
		</form>
		
		<a href="parental_control_profiles.php" class="btn btn-default">
			<i class="fa fa-arrow-left"></i> Back to Profiles
		</a>
	</div>
</div>

<!-- Device Selection Panel (shown after discovery) -->
<?php if ($show_device_selector && !empty($discovered_devices)): ?>
<div class="panel panel-info" style="margin-top: 20px;">
	<div class="panel-heading">
		<h2 class="panel-title">
			<i class="fa fa-check-square-o"></i> Select Devices to Add to: 
			<strong><?=htmlspecialchars($profiles[$manage_devices_id]['name'])?></strong>
		</h2>
	</div>
	<div class="panel-body">
		<form method="post" action="parental_control_profiles.php#device-selector">
			<input type="hidden" name="profile_id" value="<?=$manage_devices_id?>" />
			
			<div class="table-responsive">
				<table class="table table-striped table-hover">
					<thead>
						<tr>
							<th style="width: 50px;">
								<input type="checkbox" id="select_all_devices" 
									onclick="toggleAllDevices(this)" title="Select/Deselect All" />
							</th>
							<th>Device Name</th>
							<th>MAC Address</th>
							<th>IP Address</th>
							<th>Hostname</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($discovered_devices as $idx => $device): ?>
						<tr>
							<td>
								<input type="checkbox" name="selected_devices[]" 
									value="<?=htmlspecialchars(json_encode($device))?>" 
									class="device-checkbox" />
							</td>
							<td><strong><?=htmlspecialchars($device['device_name'])?></strong></td>
							<td><code><?=htmlspecialchars(strtoupper($device['mac_address']))?></code></td>
							<td><?=htmlspecialchars($device['ip_address'] ?? '-')?></td>
							<td><?=htmlspecialchars($device['hostname'] ?? '-')?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			
			<div class="alert alert-info">
				<i class="fa fa-info-circle"></i> 
				<strong>Tip:</strong> Use the checkbox at the top to select/deselect all devices. 
				Only unassigned devices are shown (devices already in other profiles are filtered out).
			</div>
			
			<button type="submit" name="add_selected_devices" class="btn btn-success">
				<i class="fa fa-plus"></i> Add Selected Devices
			</button>
			<a href="?act=devices&amp;id=<?=$manage_devices_id?>" class="btn btn-default">
				<i class="fa fa-times"></i> Cancel
			</a>
		</form>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
function toggleAllDevices(checkbox) {
	var checkboxes = document.querySelectorAll('.device-checkbox');
	for (var i = 0; i < checkboxes.length; i++) {
		checkboxes[i].checked = checkbox.checked;
	}
}
//]]>
</script>
<?php endif; ?>
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
	<strong>Keekar's Parental Control</strong> v<?=PC_VERSION?><br>
	Built with Passion by <strong>Mukesh Kesharwani</strong> | © <?=date('Y')?> Keekar
</div>

<?php include("foot.inc"); ?>

