#!/usr/local/bin/php -f
<?php
/*
 * reset_youtube_usage.php
 *
 * CLI script to reset YouTube usage only and clear the YouTube block table.
 * Use when YouTube detection has false positives (e.g. Gmail/Google Photos
 * counted as YouTube) and devices are incorrectly blocked.
 *
 * Run on the firewall (via jump host):
 *   ssh nas.keekar.com "fw exec 'php /usr/local/pkg/reset_youtube_usage.php'"
 *
 * Copyright (c) 2026 Mukesh Kesharwani
 * Licensed under GPL-3.0-or-later
 */

$pgm = 'reset_youtube_usage.php';
if (php_sapi_name() !== 'cli') {
	echo "Run this script from the command line.\n";
	exit(1);
}

// Bootstrap pfSense (required for config and parental_control.inc)
require_once('/etc/inc/config.inc');
require_once('/usr/local/pkg/parental_control.inc');

// Load state from disk (bypass cache so we have latest)
$state = pc_load_state_from_disk();

// Reset YouTube usage and clear YouTube block table only
pc_reset_service_usage_and_unblock($state, 'YouTube', true);

// Persist state
pc_save_state($state);

echo "Done. YouTube usage reset and block table cleared. Other usage (general, Facebook, etc.) unchanged.\n";
exit(0);
