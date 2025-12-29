#!/bin/sh
#
# UNINSTALL.sh - Complete removal of KACI Parental Control
# 
# This script completely removes all traces of the parental control package
# Use this to clean up before a fresh installation
#

echo "====================================="
echo "KACI Parental Control - UNINSTALL"
echo "====================================="
echo ""
echo "⚠️  WARNING: This will remove ALL parental control data!"
echo "   - Configuration"
echo "   - Profiles and schedules"
echo "   - Usage statistics"
echo "   - Firewall rules"
echo "   - Cron jobs"
echo "   - State files"
echo ""
read -p "Are you sure you want to continue? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "❌ Uninstall cancelled."
    exit 0
fi

echo ""
echo "🗑️  Starting complete removal..."
echo ""

# Stop any running cron jobs
echo "1. Removing cron jobs..."
/usr/local/bin/php -r "require_once('/etc/inc/config.inc'); require_once('/usr/local/pkg/parental_control.inc'); pc_remove_cron_job();" 2>/dev/null || true
crontab -l 2>/dev/null | grep -v 'parental_control' | grep -v 'auto_update_parental_control' | crontab - 2>/dev/null || true
echo "   ✓ Cron jobs removed"

# Remove firewall rules
echo "2. Removing firewall rules..."
/usr/local/bin/php -r "require_once('/etc/inc/config.inc'); require_once('/usr/local/pkg/parental_control.inc'); pc_remove_firewall_rules(); write_config('Removed parental control rules');" 2>/dev/null || true
echo "   ✓ Firewall rules removed"

# Remove configuration from config.xml
echo "3. Removing configuration data..."
/usr/local/bin/php <<'EOF'
<?php
require_once('/etc/inc/config.inc');

// Remove all parental control config sections
config_set_path('installedpackages/parentalcontrol', null);
config_set_path('installedpackages/parentalcontrolprofiles', null);
config_set_path('installedpackages/parentalcontrolschedules', null);

write_config("Removed all parental control configuration");
echo "   ✓ Configuration removed from config.xml\n";
?>
EOF

# Stop captive portal server
echo "4. Stopping captive portal server..."
/usr/local/etc/rc.d/parental_control_captive.sh stop 2>/dev/null || true
echo "   ✓ Captive portal server stopped"

# Remove PHP files
echo "5. Removing PHP files..."
rm -f /usr/local/www/parental_control_profiles.php
rm -f /usr/local/www/parental_control_schedules.php
rm -f /usr/local/www/parental_control_status.php
rm -f /usr/local/www/parental_control_blocked.php
rm -f /usr/local/www/parental_control_captive.php
rm -f /usr/local/etc/rc.d/parental_control_captive.sh
rm -f /usr/local/www/parental_control_api.php
rm -f /usr/local/www/parental_control_diagnostic.php
rm -f /usr/local/www/parental_control_health.php
echo "   ✓ PHP files removed"

# Remove package files
echo "6. Removing package files..."
rm -f /usr/local/pkg/parental_control.inc
rm -f /usr/local/pkg/parental_control.xml
rm -f /usr/local/pkg/parental_control_VERSION
rm -f /usr/local/pkg/info.xml
echo "   ✓ Package files removed"

# Remove cron scripts
echo "7. Removing cron scripts..."
rm -f /usr/local/bin/parental_control_cron.php
rm -f /usr/local/bin/auto_update_parental_control.sh
echo "   ✓ Cron scripts removed"

# Remove state and log files
echo "8. Removing state and log files..."
rm -f /var/db/parental_control_state.json
rm -f /var/db/parental_control_state.json.tmp
rm -f /var/run/parental_control.pid
rm -f /var/run/parental_control_captive.pid
rm -f /var/log/parental_control*.log
rm -f /var/log/parental_control*.jsonl
echo "   ✓ State and log files removed"

# Remove anchor file
echo "9. Removing anchor files..."
rm -f /tmp/rules.parental_control
/sbin/pfctl -a parental_control -F all 2>/dev/null || true
echo "   ✓ Anchor files removed"

# Remove repository directory if it exists
echo "10. Removing repository..."
if [ -d "/root/KACI-Parental_Control" ]; then
    rm -rf /root/KACI-Parental_Control
    echo "   ✓ Repository removed"
else
    echo "   ℹ  Repository not found (already removed)"
fi

# Clear any cached PHP opcache
echo "11. Clearing PHP cache..."
rm -f /tmp/PHP_errors.log
echo "   ✓ PHP cache cleared"

echo ""
echo "✅ Complete removal finished!"
echo ""
echo "📋 Summary:"
echo "   - All configuration removed"
echo "   - All profiles and schedules deleted"
echo "   - All firewall rules removed"
echo "   - All cron jobs removed"
echo "   - All state files deleted"
echo "   - All package files removed"
echo ""
echo "🚀 You can now install fresh using INSTALL.sh"
echo ""

