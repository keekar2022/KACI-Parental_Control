#!/bin/sh
#
# Keekar's Parental Control - Auto Update Setup Script
# Installs auto-update functionality on pfSense
#

echo "=========================================="
echo "Auto-Update Setup for Parental Control"
echo "=========================================="
echo ""

# Check if auto-update script exists
if [ ! -f "/usr/local/bin/auto_update_parental_control.sh" ]; then
    echo "ERROR: Auto-update script not found at /usr/local/bin/auto_update_parental_control.sh"
    echo "Please run INSTALL.sh first to deploy the auto-update script."
    exit 1
fi

echo "✓ Auto-update script found"

# Ensure script is executable
chmod 755 /usr/local/bin/auto_update_parental_control.sh
echo "✓ Script permissions set"

# Create log file with proper permissions
touch /var/log/parental_control_auto_update.log
chmod 644 /var/log/parental_control_auto_update.log
echo "✓ Log file created"

# Check if cron entry already exists
CRON_EXISTS=$(crontab -l 2>/dev/null | grep -c "auto_update_parental_control.sh")

if [ "$CRON_EXISTS" -gt 0 ]; then
    echo ""
    echo "⚠  Auto-update cron job already exists"
    echo ""
    echo "Current cron entry:"
    crontab -l 2>/dev/null | grep "auto_update_parental_control.sh"
    echo ""
    read -p "Do you want to replace it? (y/n): " REPLACE
    
    if [ "$REPLACE" = "y" ] || [ "$REPLACE" = "Y" ]; then
        # Remove old entry
        crontab -l 2>/dev/null | grep -v "auto_update_parental_control.sh" | crontab -
        echo "✓ Old cron entry removed"
    else
        echo "Keeping existing cron entry. Setup complete."
        exit 0
    fi
fi

# Add cron entry (runs every 8 hours)
(crontab -l 2>/dev/null; echo "0 */8 * * * /usr/local/bin/auto_update_parental_control.sh") | crontab -

if [ $? -eq 0 ]; then
    echo "✓ Cron job installed (checks every 8 hours)"
else
    echo "✗ Failed to install cron job"
    exit 1
fi

echo ""
echo "=========================================="
echo "Auto-Update Setup Complete!"
echo "=========================================="
echo ""
echo "The system will now:"
echo "  • Check GitHub for updates every 8 hours"
echo "  • Download and deploy updates automatically"
echo "  • Log all activities to /var/log/parental_control_auto_update.log"
echo ""
echo "To verify installation:"
echo "  crontab -l | grep auto_update"
echo ""
echo "To watch live updates:"
echo "  tail -f /var/log/parental_control_auto_update.log"
echo ""
echo "To manually trigger an update check:"
echo "  /usr/local/bin/auto_update_parental_control.sh"
echo ""
echo "To disable auto-updates:"
echo "  crontab -l | grep -v auto_update_parental_control | crontab -"
echo ""
echo "⚠  WARNING: Auto-updates are NOT recommended for production!"
echo "   Use this feature only in development/testing environments."
echo ""

exit 0

