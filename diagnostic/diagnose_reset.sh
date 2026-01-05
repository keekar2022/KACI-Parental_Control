#!/bin/bash
# Comprehensive Reset Diagnostic Script for Parental Control
# Run this on pfSense: ssh mkesharw@fw.keekar.com 'bash -s' < diagnose_reset.sh

echo "========================================"
echo "PARENTAL CONTROL RESET DIAGNOSTIC"
echo "========================================"
echo ""

echo "=== 1. Current System Time ==="
date
echo ""

echo "=== 2. State File: Last Reset Time ==="
sudo cat /var/db/parental_control_state.json | grep -E '"last_reset"|"last_check"' | head -5
echo ""

echo "=== 3. Profile Usage (Current Values) ==="
sudo cat /var/db/parental_control_state.json | grep -A5 '"profiles"' | head -50
echo ""

echo "=== 4. Recent Reset Log Entries ==="
sudo grep -i "reset" /var/log/system.log | grep -i "parental" | tail -10
echo ""

echo "=== 5. Cron Job Execution History ==="
sudo grep "parental_control_cron" /var/log/system.log | tail -10
echo ""

echo "=== 6. Check Reset Logic ==="
cat > /tmp/check_reset_logic.php << 'PHPEOF'
<?php
require_once('/etc/inc/config.inc');
require_once('/usr/local/pkg/parental_control.inc');

$state = pc_load_state();
$reset_time_config = config_get_path('installedpackages/parentalcontrol/reset_time', 'midnight');
$last_reset = isset($state['last_reset']) ? $state['last_reset'] : 0;

echo "Reset Time Config: $reset_time_config\n";
echo "Last Reset Timestamp: $last_reset (" . date('Y-m-d H:i:s', $last_reset) . ")\n";
echo "Current Time: " . time() . " (" . date('Y-m-d H:i:s') . ")\n";
echo "Hours Since Reset: " . round((time() - $last_reset) / 3600, 2) . " hours\n";
echo "\n";

echo "Should Reset? ";
if (pc_should_reset_counters($last_reset, $reset_time_config)) {
    echo "YES - Reset is DUE!\n";
} else {
    echo "NO - Reset not needed yet\n";
}

echo "\n=== Profile Counters ===\n";
if (isset($state['profiles'])) {
    foreach ($state['profiles'] as $name => $data) {
        $usage = isset($data['usage_today']) ? $data['usage_today'] : 0;
        echo "Profile: $name\n";
        echo "  Usage Today: $usage minutes (" . floor($usage/60) . "h " . ($usage%60) . "m)\n";
        echo "  Usage Week: " . (isset($data['usage_week']) ? $data['usage_week'] : 0) . " minutes\n";
    }
}
?>
PHPEOF

sudo php /tmp/check_reset_logic.php
echo ""

echo "=== 7. Force Manual Reset NOW ==="
cat > /tmp/force_reset.php << 'PHPEOF'
<?php
require_once('/etc/inc/config.inc');
require_once('/usr/local/pkg/parental_control.inc');

echo "Loading state...\n";
$state = pc_load_state();

echo "\n--- BEFORE RESET ---\n";
if (isset($state['profiles'])) {
    foreach ($state['profiles'] as $name => $data) {
        $usage = isset($data['usage_today']) ? $data['usage_today'] : 0;
        echo "$name: $usage minutes\n";
    }
}

echo "\nExecuting reset...\n";
pc_reset_daily_counters($state);
$state['last_reset'] = time();
pc_save_state($state);

echo "Reloading state...\n";
$state = pc_load_state();

echo "\n--- AFTER RESET ---\n";
if (isset($state['profiles'])) {
    foreach ($state['profiles'] as $name => $data) {
        $usage = isset($data['usage_today']) ? $data['usage_today'] : 0;
        echo "$name: $usage minutes\n";
    }
}

echo "\n✓ Reset completed at " . date('Y-m-d H:i:s') . "\n";
?>
PHPEOF

sudo php /tmp/force_reset.php
echo ""

echo "=== 8. Final Verification ==="
sudo cat /var/db/parental_control_state.json | grep -A5 '"profiles"' | head -30
echo ""

echo "========================================"
echo "DIAGNOSTIC COMPLETE"
echo "========================================"

