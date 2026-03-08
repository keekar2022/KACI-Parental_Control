#!/usr/local/bin/php -f
<?php
/*
 * reset_facebook_usage.php
 *
 * CLI script to reset Facebook usage only and clear the Facebook block table.
 * Use when Facebook detection has false positives (e.g. WhatsApp/Instagram
 * background sync counted as Facebook) and devices are incorrectly blocked.
 *
 * Run on the firewall (via jump host):
 *   ssh nas.keekar.com "fw exec 'php /usr/local/pkg/reset_facebook_usage.php'"
 *
 * Copyright (c) 2026 Mukesh Kesharwani
 * Licensed under GPL-3.0-or-later
 */

$pgm = 'reset_facebook_usage.php';
if (php_sapi_name() !== 'cli') {
	echo "Run this script from the command line.\n";
	exit(1);
}

// Bootstrap pfSense (required for config and parental_control.inc)
require_once('/etc/inc/config.inc');
require_once('/usr/local/pkg/parental_control.inc');

// Load state from disk (bypass cache so we have latest)
$state = pc_load_state_from_disk();

// Reset Facebook usage and clear Facebook block table only
pc_reset_service_usage_and_unblock($state, 'Facebook', true);

// Persist state
pc_save_state($state);

echo "Done. Facebook usage reset and block table cleared. Other usage (general, YouTube, etc.) unchanged.\n";
exit(0);
