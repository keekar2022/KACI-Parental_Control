#!/usr/local/bin/php -q
<?php
/**
 * Emergency fix for corrupted Online Services config structure
 * This converts from associative array (service name as key) to numeric array
 */

require_once("/etc/inc/config.inc");

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  FIXING ONLINE SERVICES CONFIG STRUCTURE\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Load current config (might be corrupted)
$old_config = config_get_path('installedpackages/parentalcontrolservices/config', array());

echo "Current config structure:\n";
var_dump($old_config);
echo "\n";

// Convert to numeric array if it's an associative array
$new_config = array();
$converted = 0;

if (!empty($old_config)) {
	foreach ($old_config as $key => $service) {
		// If the key is not numeric, it's using service name as key (BAD for XML)
		if (!is_numeric($key)) {
			// Ensure service has a 'name' field
			if (!isset($service['name'])) {
				$service['name'] = $key;
			}
			$new_config[] = $service; // Add with numeric index
			$converted++;
		} else {
			// Already numeric, just copy
			$new_config[] = $service;
		}
	}
}

if ($converted > 0) {
	echo "✓ Converted {$converted} services to proper structure\n\n";
	echo "New config structure:\n";
	var_dump($new_config);
	echo "\n";
	
	// Save the fixed structure
	config_set_path('installedpackages/parentalcontrolservices/config', $new_config);
	write_config("Fixed Online Services config structure (converted to numeric array)");
	
	echo "✓ Config saved successfully!\n";
	echo "✓ pfSense should stop restoring from backup now.\n\n";
} else {
	echo "✓ Config structure is already correct (numeric array)\n";
	echo "  OR config is empty.\n\n";
	
	// If empty, just clear it completely
	if (empty($new_config)) {
		config_del_path('installedpackages/parentalcontrolservices');
		write_config("Removed empty/corrupted Online Services config");
		echo "✓ Removed empty config section.\n\n";
	}
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "DONE! Config should be fixed now.\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";
?>

