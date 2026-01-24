#!/usr/local/bin/php-cgi -f
<?php
/*
 * parental_control_cron.php
 * 
 * Cron job wrapper for Keekar's Parental Control
 * This script is called every 5 minutes by cron to update usage tracking
 * 
 * Copyright (c) 2026 Mukesh Kesharwani
 * Licensed under GPL-3.0-or-later
 */

require_once("/etc/inc/config.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Call the main cron job function
parental_control_cron_job();
?>
