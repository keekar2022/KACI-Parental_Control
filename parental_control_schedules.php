<?php
/*
 * parental_control_schedules.php
 * 
 * Manage time-based blocking schedules for Parental Control
 * Pure PHP implementation (no XML complexity)
 * 
 * Part of KACI-Parental_Control for pfSense
 * Copyright (c) 2025 Mukesh Kesharwani
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Check if user has permission
if (!isAllowedPage()) {
	header("Location: /");
	exit;
}

$pgtitle = array("Services", "Parental Control", "KACI-PC-Schedule");
$pglinks = array("", "/pkg_edit.php?xml=parental_control.xml", "@self");

// Get configuration
$schedules = config_get_path('installedpackages/parentalcontrolschedules/config', []);
$profiles = pc_get_profile_options();

// Handle form submissions
$input_errors = [];
$savemsg = '';

// DELETE action
if ($_POST['act'] === 'del' && isset($_POST['id']) && is_numeric($_POST['id'])) {
	$id = intval($_POST['id']);
	if (isset($schedules[$id])) {
		$schedule_name = $schedules[$id]['name'];
		unset($schedules[$id]);
		$schedules = array_values($schedules); // Re-index
		config_set_path('installedpackages/parentalcontrolschedules/config', $schedules);
		write_config("Deleted schedule: {$schedule_name}");
		parental_control_sync();
		$savemsg = "Schedule '{$schedule_name}' has been deleted successfully.";
		pc_log("Schedule deleted via GUI", 'info', array(
			'schedule.name' => $schedule_name,
			'event.action' => 'schedule_deleted'
		));
	}
}

// SAVE action (Add or Edit)
if ($_POST['save']) {
	// Validation
	if (empty($_POST['name'])) {
		$input_errors[] = "Schedule name is required.";
	}
	if (empty($_POST['profile_names']) || !is_array($_POST['profile_names'])) {
		$input_errors[] = "At least one profile must be selected.";
	}
	if (empty($_POST['days']) || !is_array($_POST['days'])) {
		$input_errors[] = "At least one day must be selected.";
	}
	if (empty($_POST['start_time'])) {
		$input_errors[] = "Start time is required.";
	}
	if (empty($_POST['end_time'])) {
		$input_errors[] = "End time is required.";
	}
	
	// Time format validation
	if (!empty($_POST['start_time']) && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $_POST['start_time'])) {
		$input_errors[] = "Start time must be in HH:MM format (e.g., 08:00, 13:30, 22:00).";
	}
	if (!empty($_POST['end_time']) && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $_POST['end_time'])) {
		$input_errors[] = "End time must be in HH:MM format (e.g., 17:00, 20:30, 24:00).";
	}
	
	if (empty($input_errors)) {
		$schedule = array(
			'name' => trim($_POST['name']),
			'profile_names' => $_POST['profile_names'], // Array
			'days' => $_POST['days'], // Array
			'start_time' => trim($_POST['start_time']),
			'end_time' => trim($_POST['end_time']),
			'enabled' => isset($_POST['enabled']) ? 'on' : 'off'
		);
		
		if (isset($_POST['id']) && is_numeric($_POST['id'])) {
			// Edit existing
			$id = intval($_POST['id']);
			$schedules[$id] = $schedule;
			$action = "Updated";
		} else {
			// Add new
			$schedules[] = $schedule;
			$action = "Added";
		}
		
		config_set_path('installedpackages/parentalcontrolschedules/config', $schedules);
		write_config("{$action} schedule: {$schedule['name']}");
		parental_control_sync();
		$savemsg = "Schedule '{$schedule['name']}' has been {$action} successfully.";
		
		pc_log("Schedule {$action} via GUI", 'info', array(
			'schedule.name' => $schedule['name'],
			'event.action' => 'schedule_' . strtolower($action)
		));
		
		// Reload schedules
		$schedules = config_get_path('installedpackages/parentalcontrolschedules/config', []);
	}
}

// Get edit ID if present
$edit_id = null;
$edit_schedule = null;
if (isset($_GET['act']) && $_GET['act'] === 'edit' && isset($_GET['id']) && is_numeric($_GET['id'])) {
	$edit_id = intval($_GET['id']);
	if (isset($schedules[$edit_id])) {
		$edit_schedule = $schedules[$edit_id];
		// Convert old format to new if needed
		if (isset($edit_schedule['profile_name']) && !isset($edit_schedule['profile_names'])) {
			$edit_schedule['profile_names'] = array($edit_schedule['profile_name']);
		}
		if (isset($edit_schedule['days']) && is_string($edit_schedule['days'])) {
			$edit_schedule['days'] = array_map('trim', explode(',', $edit_schedule['days']));
		}
	}
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
$tab_array[] = array("Profiles", false, "/pkg.php?xml=parental_control_profiles.xml");
$tab_array[] = array("KACI-PC-Schedule", true, "/parental_control_schedules.php");
$tab_array[] = array("Status", false, "/parental_control_status.php");
display_top_tabs($tab_array);
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("KACI-PC-Schedule Management")?></h2>
	</div>
	<div class="panel-body">
		<div class="infoblock">
			<strong>What are KACI-PC-Schedules?</strong><br/>
			Time-based blocking schedules specific to Keekar's Parental Control (KACI-PC).<br/>
			They are <strong>separate from pfSense's built-in Schedules</strong> in Firewall &gt; Schedules.<br/><br/>
			
			<strong>Use Cases:</strong>
			<ul style="margin-left: 20px;">
				<li><strong>Bedtime:</strong> Block internet during sleeping hours (e.g., 22:00-07:00)</li>
				<li><strong>School Hours:</strong> Block during school time (e.g., 08:00-15:00 on weekdays)</li>
				<li><strong>Dinner Time:</strong> Block during family meals (e.g., 18:00-19:00)</li>
				<li><strong>Study Time:</strong> Block during homework hours</li>
			</ul>
			
			<strong>How it works:</strong> When a schedule is active, ALL devices in the selected profile(s) are blocked,
			regardless of whether they have remaining time in their daily limit.
		</div>
	</div>
</div>

<!-- Schedule List -->
<?php if (!$edit_id): ?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Configured Schedules")?></h2>
	</div>
	<div class="panel-body">
		<?php if (empty($schedules)): ?>
			<div class="alert alert-info">
				<i class="fa fa-info-circle"></i> No schedules configured yet. Click "Add New Schedule" below to create one.
			</div>
		<?php else: ?>
			<div class="table-responsive">
				<table class="table table-striped table-hover">
					<thead>
						<tr>
							<th>Schedule Name</th>
							<th>Profile(s)</th>
							<th>Days</th>
							<th>Time Range</th>
							<th>Status</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($schedules as $idx => $schedule): 
							// Handle both old and new format
							$profile_display = '';
							if (isset($schedule['profile_names'])) {
								$profile_display = is_array($schedule['profile_names']) 
									? implode(', ', $schedule['profile_names']) 
									: $schedule['profile_names'];
							} elseif (isset($schedule['profile_name'])) {
								$profile_display = $schedule['profile_name'];
							}
							
							$days_display = '';
							if (isset($schedule['days'])) {
								$days_display = is_array($schedule['days']) 
									? implode(', ', array_map('ucfirst', $schedule['days'])) 
									: ucwords(str_replace(',', ', ', $schedule['days']));
							}
							
							$enabled = isset($schedule['enabled']) && $schedule['enabled'] == 'on';
							$status_class = $enabled ? 'success' : 'default';
							$status_text = $enabled ? 'Enabled' : 'Disabled';
							$status_icon = $enabled ? 'check' : 'times';
						?>
						<tr>
							<td><strong><?=htmlspecialchars($schedule['name'])?></strong></td>
							<td><?=htmlspecialchars($profile_display)?></td>
							<td><small><?=htmlspecialchars($days_display)?></small></td>
							<td><code><?=htmlspecialchars($schedule['start_time'])?> - <?=htmlspecialchars($schedule['end_time'])?></code></td>
							<td>
								<span class="label label-<?=$status_class?>">
									<i class="fa fa-<?=$status_icon?>"></i> <?=$status_text?>
								</span>
							</td>
							<td>
								<a href="?act=edit&amp;id=<?=$idx?>" class="btn btn-xs btn-info" title="Edit">
									<i class="fa fa-pencil"></i> Edit
								</a>
								<form method="post" style="display:inline;" onsubmit="return confirm('Delete this schedule?');">
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
		
		<?php if (!$edit_id): ?>
		<a href="?act=edit" class="btn btn-success">
			<i class="fa fa-plus"></i> Add New Schedule
		</a>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<!-- Add/Edit Form -->
<?php if ($edit_id !== null || (isset($_GET['act']) && $_GET['act'] === 'edit')): ?>
<form method="post" action="parental_control_schedules.php">
	<?php if ($edit_id !== null): ?>
		<input type="hidden" name="id" value="<?=$edit_id?>" />
	<?php endif; ?>
	
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=$edit_id !== null ? 'Edit Schedule' : 'Add New Schedule'?></h2>
		</div>
		<div class="panel-body">
			
			<!-- Schedule Name -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Schedule Name <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<input type="text" name="name" class="form-control" 
						value="<?=htmlspecialchars($edit_schedule['name'] ?? '')?>" 
						placeholder="e.g., Bedtime, School Hours" required />
					<span class="help-block">Descriptive name for this schedule</span>
				</div>
			</div>
			
			<!-- Profile(s) -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Profile(s) <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<select name="profile_names[]" class="form-control" multiple size="<?=max(3, count($profiles))?>" required>
						<?php if (empty($profiles)): ?>
							<option value="" disabled>No profiles configured - create profiles first</option>
						<?php else: ?>
							<?php foreach ($profiles as $profile_name): 
								$selected = $edit_schedule && isset($edit_schedule['profile_names']) 
									&& in_array($profile_name, $edit_schedule['profile_names']) ? 'selected' : '';
							?>
								<option value="<?=htmlspecialchars($profile_name)?>" <?=$selected?>>
									<?=htmlspecialchars($profile_name)?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
					<span class="help-block">
						Hold Ctrl/Cmd to select multiple profiles. 
						<a href="/pkg.php?xml=parental_control_profiles.xml" target="_blank">Manage Profiles →</a>
					</span>
				</div>
			</div>
			
			<!-- Days -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Days <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<?php
					$days = array(
						'sun' => 'Sunday',
						'mon' => 'Monday',
						'tue' => 'Tuesday',
						'wed' => 'Wednesday',
						'thu' => 'Thursday',
						'fri' => 'Friday',
						'sat' => 'Saturday'
					);
					foreach ($days as $value => $label):
						$checked = $edit_schedule && isset($edit_schedule['days']) 
							&& in_array($value, $edit_schedule['days']) ? 'checked' : '';
					?>
						<label class="checkbox-inline">
							<input type="checkbox" name="days[]" value="<?=$value?>" <?=$checked?> />
							<?=$label?>
						</label>
					<?php endforeach; ?>
					<span class="help-block">Select days when this schedule is active</span>
				</div>
			</div>
			
			<!-- Start Time -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Start Time <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<input type="text" name="start_time" class="form-control" style="max-width: 200px;"
						value="<?=htmlspecialchars($edit_schedule['start_time'] ?? '')?>" 
						placeholder="HH:MM (e.g., 08:00, 22:00)" 
						pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]" required />
					<span class="help-block">
						24-hour format. Examples: <code>08:00</code> (8am), <code>13:30</code> (1:30pm), <code>22:00</code> (10pm)
					</span>
				</div>
			</div>
			
			<!-- End Time -->
			<div class="form-group">
				<label class="col-sm-2 control-label">End Time <span class="text-danger">*</span></label>
				<div class="col-sm-10">
					<input type="text" name="end_time" class="form-control" style="max-width: 200px;"
						value="<?=htmlspecialchars($edit_schedule['end_time'] ?? '')?>" 
						placeholder="HH:MM (e.g., 17:00, 24:00)" 
						pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]" required />
					<span class="help-block">
						24-hour format. Examples: <code>17:00</code> (5pm), <code>20:30</code> (8:30pm), <code>24:00</code> (midnight)
					</span>
				</div>
			</div>
			
			<!-- Enabled -->
			<div class="form-group">
				<label class="col-sm-2 control-label">Enabled</label>
				<div class="col-sm-10">
					<label class="checkbox">
						<input type="checkbox" name="enabled" 
							<?=(!$edit_schedule || (isset($edit_schedule['enabled']) && $edit_schedule['enabled'] == 'on')) ? 'checked' : ''?> />
						Enable this schedule
					</label>
				</div>
			</div>
			
		</div>
		<div class="panel-footer">
			<button type="submit" name="save" class="btn btn-primary">
				<i class="fa fa-save"></i> Save Schedule
			</button>
			<a href="parental_control_schedules.php" class="btn btn-default">
				<i class="fa fa-times"></i> Cancel
			</a>
		</div>
	</div>
</form>
<?php endif; ?>

<!-- Package Footer -->
<div style="text-align: center; margin-top: 30px; padding: 15px; border-top: 2px solid #ddd; background: #f9f9f9;">
	<strong>Keekar's Parental Control</strong> v<?=defined('PC_VERSION') ? PC_VERSION : '0.3.4'?><br>
	Built with Passion by <strong>Mukesh Kesharwani</strong> | © <?=date('Y')?> Keekar
</div>

<?php include("foot.inc"); ?>

