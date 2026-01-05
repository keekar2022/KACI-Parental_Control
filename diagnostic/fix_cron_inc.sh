#!/usr/bin/env bash
#
# fix_cron_inc.sh
# Quick fix for cron.inc compatibility issue (pfSense 2.8+)
#

set -e

FIREWALL_IP="${1:-192.168.64.2}"
FIREWALL_USER="mkesharw"
PACKAGE_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "═══════════════════════════════════════════════════════════════════"
echo "  DEPLOYING CRON.INC COMPATIBILITY FIX"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Test Firewall: $FIREWALL_USER@$FIREWALL_IP"
echo "Fixing: pfSense 2.8+ compatibility (cron.inc removed)"
echo ""

# Copy fixed file
echo "Copying fixed parental_control.inc..."
scp -q "$PACKAGE_DIR/parental_control.inc" "$FIREWALL_USER@$FIREWALL_IP:/tmp/parental_control.inc"
echo "✓ File copied"
echo ""

# Deploy on firewall
echo "Deploying fix on firewall..."
ssh "$FIREWALL_USER@$FIREWALL_IP" << 'ENDSSH'
    # Install fixed file
    sudo mv /tmp/parental_control.inc /usr/local/pkg/parental_control.inc
    sudo chmod 644 /usr/local/pkg/parental_control.inc
    
    # Clear cache
    sudo rm -rf /tmp/config.cache
    
    echo "✓ Fix deployed"
ENDSSH

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  ✅ FIX DEPLOYED SUCCESSFULLY!"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Changes made:"
echo "  • Made cron.inc require conditional (only if file exists)"
echo "  • Added function_exists() check before calling install_cron_job()"
echo "  • Updated pc_remove_cron_job() for pfSense 2.8+ compatibility"
echo "  • Added manual crontab handling fallback"
echo ""
echo "Now try accessing the Online Services tab again:"
echo "  https://$FIREWALL_IP"
echo "  Services → Keekar's Parental Control → Online Services"
echo ""

