#!/bin/bash
#
# deploy_sync_to_test.sh
# Deploys production sync scripts to test firewall
#
# Run this from your local machine to set up sync on the test firewall
#

set -e

TEST_HOST="192.168.64.2"
TEST_USER="mkesharw"

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  Deploying Production Sync Scripts to Test Firewall"
echo "  Target: ${TEST_USER}@${TEST_HOST}"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Check if we can connect
if ! ssh -o BatchMode=yes -o ConnectTimeout=5 ${TEST_USER}@${TEST_HOST} "echo 'Connection OK'" >/dev/null 2>&1; then
    echo "⚠ Cannot connect to test firewall via SSH."
    echo "  Attempting connection with password prompt..."
    echo ""
fi

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Step 1: Copying sync scripts..."
scp -q \
    "${SCRIPT_DIR}/sync_production_data.sh" \
    "${SCRIPT_DIR}/setup_sync_cron.sh" \
    "${SCRIPT_DIR}/view_sync_status.sh" \
    ${TEST_USER}@${TEST_HOST}:/tmp/

echo "✓ Scripts copied to /tmp/"
echo ""

echo "Step 2: Installing scripts to /usr/local/bin/..."
ssh ${TEST_USER}@${TEST_HOST} << 'EOSSH'
    sudo mv /tmp/sync_production_data.sh /usr/local/bin/
    sudo mv /tmp/setup_sync_cron.sh /usr/local/bin/
    sudo mv /tmp/view_sync_status.sh /usr/local/bin/
    
    sudo chmod +x /usr/local/bin/sync_production_data.sh
    sudo chmod +x /usr/local/bin/setup_sync_cron.sh
    sudo chmod +x /usr/local/bin/view_sync_status.sh
    
    echo "✓ Scripts installed"
EOSSH

echo "✓ Scripts installed to /usr/local/bin/"
echo ""

echo "Step 3: Running initial setup..."
echo "─────────────────────────────────────────────────────────────────────"
ssh -t ${TEST_USER}@${TEST_HOST} "sudo /usr/local/bin/setup_sync_cron.sh"

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  ✅ Deployment Complete!"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "The test firewall will now sync production data every 4 minutes."
echo ""
echo "On the test firewall (${TEST_HOST}), you can:"
echo ""
echo "  • View sync status:      view_sync_status.sh"
echo "  • Watch live sync:       tail -f /tmp/kaci_sync.log"
echo "  • Run sync manually:     sync_production_data.sh"
echo "  • View cron jobs:        crontab -l"
echo ""
echo "Files synced from production (192.168.1.1):"
echo "  • State file:  /var/db/parental_control/parental_control_state.json"
echo "  • Logs:        /var/log/parental_control/*.log"
echo "  • Config:      Profiles, Schedules, Settings, Services"
echo ""
echo "NOT synced (test-specific):"
echo "  • Interface configurations"
echo "  • NAT rules"
echo "  • Firewall rules"
echo "  • Network settings"
echo ""

