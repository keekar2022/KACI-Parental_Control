#!/bin/bash
# Quick Reset Script for KACI Parental Control
# Resets time counters for ALL profiles and devices
# Usage: ./reset_all_time.sh <pfsense_ip>

if [ -z "$1" ]; then
    echo "❌ Error: Please provide pfSense IP address"
    echo "Usage: ./reset_all_time.sh <pfsense_ip>"
    echo "Example: ./reset_all_time.sh 192.168.1.1"
    exit 1
fi

PFSENSE_IP="$1"

echo "========================================"
echo "🔄 RESET ALL TIME COUNTERS"
echo "========================================"
echo ""
echo "Target: $PFSENSE_IP"
echo ""

# Create the reset script
cat > /tmp/reset_time.php << 'PHPEOF'
<?php
require_once('/etc/inc/config.inc');
require_once('/usr/local/pkg/parental_control.inc');

echo "Loading current state...\n";
$state = pc_load_state();

echo "\n=== BEFORE RESET ===\n";
if (isset($state['profiles']) && is_array($state['profiles'])) {
    foreach ($state['profiles'] as $name => $data) {
        $usage = isset($data['usage_today']) ? $data['usage_today'] : 0;
        $usage_week = isset($data['usage_week']) ? $data['usage_week'] : 0;
        $hours = floor($usage / 60);
        $mins = $usage % 60;
        $week_hours = floor($usage_week / 60);
        $week_mins = $usage_week % 60;
        echo "Profile: $name\n";
        echo "  Daily: {$hours}h {$mins}m ($usage minutes)\n";
        echo "  Weekly: {$week_hours}h {$week_mins}m ($usage_week minutes)\n";
    }
} else {
    echo "No profiles found.\n";
}

echo "\n🔄 Executing reset...\n";

// Reset all counters
pc_reset_daily_counters($state);

// Also reset weekly counters
if (isset($state['profiles']) && is_array($state['profiles'])) {
    foreach ($state['profiles'] as $profile_name => &$profile_state) {
        $profile_state['usage_week'] = 0;
    }
    unset($profile_state);
}

// Update reset timestamp
$state['last_reset'] = time();

// Save state
pc_save_state($state);

echo "✓ State saved\n";

// Reload to verify
echo "\nReloading state to verify...\n";
$state = pc_load_state();

echo "\n=== AFTER RESET ===\n";
if (isset($state['profiles']) && is_array($state['profiles'])) {
    foreach ($state['profiles'] as $name => $data) {
        $usage = isset($data['usage_today']) ? $data['usage_today'] : 0;
        $usage_week = isset($data['usage_week']) ? $data['usage_week'] : 0;
        echo "Profile: $name\n";
        echo "  Daily: $usage minutes\n";
        echo "  Weekly: $usage_week minutes\n";
    }
} else {
    echo "No profiles found.\n";
}

echo "\n✅ RESET COMPLETED at " . date('Y-m-d H:i:s') . "\n";
echo "All profile time counters have been reset to 0.\n";
?>
PHPEOF

# Copy script to pfSense and execute
echo "Connecting to pfSense..."
scp -q /tmp/reset_time.php mkesharw@$PFSENSE_IP:/tmp/ 2>&1

if [ $? -ne 0 ]; then
    echo "❌ Error: Could not connect to pfSense at $PFSENSE_IP"
    echo "Please check:"
    echo "  - IP address is correct"
    echo "  - SSH is enabled on pfSense"
    echo "  - You have SSH access (try: ssh mkesharw@$PFSENSE_IP)"
    exit 1
fi

echo "Executing reset on pfSense..."
ssh admin@$PFSENSE_IP "sudo php /tmp/reset_time.php"

if [ $? -eq 0 ]; then
    echo ""
    echo "========================================"
    echo "✅ RESET SUCCESSFUL"
    echo "========================================"
    echo ""
    echo "All profile and device time counters have been reset to 0."
    echo "Users can now use their full daily/weekly limits."
    echo ""
else
    echo ""
    echo "❌ Reset failed. Please check the error messages above."
    exit 1
fi

# Cleanup
ssh admin@$PFSENSE_IP "rm -f /tmp/reset_time.php" 2>/dev/null
rm -f /tmp/reset_time.php 2>/dev/null

