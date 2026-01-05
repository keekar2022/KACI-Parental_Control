#!/bin/bash
#
# setup_sync_cron.sh
# Installs cron job on LOCAL LAPTOP to sync production data every 4 minutes
#

set -e

SYNC_SCRIPT="/Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/sync_production_data.sh"
CRON_SCHEDULE="*/4 * * * *"

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  KACI Parental Control - Setup Sync Cron Job (ON LAPTOP)"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Check if sync script exists
if [ ! -f "$SYNC_SCRIPT" ]; then
    echo "ERROR: Sync script not found at $SYNC_SCRIPT"
    echo "Please ensure the script exists."
    exit 1
fi

# Make sure it's executable
chmod +x "$SYNC_SCRIPT"

# Check if cron job already exists
if crontab -l 2>/dev/null | grep -q "sync_production_data.sh"; then
    echo "⚠ Cron job already exists. Removing old entry..."
    crontab -l 2>/dev/null | grep -v "sync_production_data.sh" | crontab -
fi

# Add new cron job
echo "Adding cron job: Every 4 minutes"
(crontab -l 2>/dev/null || true; echo "$CRON_SCHEDULE $SYNC_SCRIPT >/dev/null 2>&1") | crontab -

echo ""
echo "✓ Cron job installed successfully on LAPTOP!"
echo ""
echo "Schedule: Every 4 minutes"
echo "Command:  $SYNC_SCRIPT"
echo "Logs:     /tmp/kaci_sync.log"
echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  Testing Sync Now..."
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Run sync once to test
$SYNC_SCRIPT

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  Setup Complete!"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Next sync will run in 4 minutes."
echo ""
echo "Useful commands:"
echo "  • View sync log:     tail -f /tmp/kaci_sync.log"
echo "  • Run sync manually: $SYNC_SCRIPT"
echo "  • View cron jobs:    crontab -l"
echo "  • Remove cron job:   crontab -l | grep -v sync_production_data.sh | crontab -"
echo ""
echo "NOTE: Keep your laptop powered on and connected to network for"
echo "      automatic syncing. The laptop acts as intermediary between"
echo "      production (192.168.1.1) and test (192.168.64.2)."
echo ""
