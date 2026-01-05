#!/usr/bin/env bash
#
# deploy_services_test.sh
# Deploy Online Services feature to test firewall for testing
#
# Usage: ./deploy_services_test.sh

set -e

FIREWALL_IP="${1:-192.168.64.2}"
FIREWALL_USER="mkesharw"
PACKAGE_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "═══════════════════════════════════════════════════════════════════"
echo "  DEPLOYING ONLINE SERVICES FEATURE TO TEST FIREWALL"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Test Firewall: $FIREWALL_USER@$FIREWALL_IP"
echo "Source Directory: $PACKAGE_DIR"
echo ""

# Files to deploy
FILES=(
    "parental_control.inc"
    "parental_control_services.php"
    "parental_control_profiles.php"
    "parental_control_schedules.php"
    "parental_control_status.php"
)

echo "Files to deploy:"
for file in "${FILES[@]}"; do
    echo "  - $file"
done
echo ""

# Check if files exist
echo "Checking files..."
for file in "${FILES[@]}"; do
    if [ ! -f "$PACKAGE_DIR/$file" ]; then
        echo "ERROR: File not found: $file"
        exit 1
    fi
done
echo "✓ All files found"
echo ""

# Copy files to /tmp on firewall
echo "Copying files to firewall..."
for file in "${FILES[@]}"; do
    echo "  Copying $file..."
    scp -q "$PACKAGE_DIR/$file" "$FIREWALL_USER@$FIREWALL_IP:/tmp/$file"
done
echo "✓ Files copied to /tmp"
echo ""

# Deploy files on firewall
echo "Deploying files on firewall..."
ssh "$FIREWALL_USER@$FIREWALL_IP" << 'ENDSSH'
    # Move parental_control.inc to /usr/local/pkg/
    echo "  Installing parental_control.inc..."
    sudo mv /tmp/parental_control.inc /usr/local/pkg/parental_control.inc
    sudo chmod 644 /usr/local/pkg/parental_control.inc
    
    # Move PHP web files to /usr/local/www/
    echo "  Installing web interface files..."
    sudo mv /tmp/parental_control_services.php /usr/local/www/parental_control_services.php
    sudo mv /tmp/parental_control_profiles.php /usr/local/www/parental_control_profiles.php
    sudo mv /tmp/parental_control_schedules.php /usr/local/www/parental_control_schedules.php
    sudo mv /tmp/parental_control_status.php /usr/local/www/parental_control_status.php
    sudo chmod 644 /usr/local/www/parental_control_*.php
    
    # Clear pfSense config cache
    echo "  Clearing pfSense cache..."
    sudo rm -rf /tmp/config.cache
    sudo /etc/rc.restart_webgui
    
    echo ""
    echo "✓ Deployment complete!"
ENDSSH

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  ✅ DEPLOYMENT SUCCESSFUL!"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Next steps:"
echo "  1. Open pfSense web GUI: https://$FIREWALL_IP"
echo "  2. Navigate to: Services → Keekar's Parental Control → Online Services"
echo "  3. Test the new Online Services feature"
echo ""
echo "Default services available:"
echo "  • YouTube"
echo "  • Facebook (includes Instagram, WhatsApp)"
echo "  • Discord"
echo "  • TikTok"
echo "  • Netflix"
echo "  • Twitch"
echo ""
echo "To update IP lists:"
echo "  - Click 'Update IPs' for individual services"
echo "  - Click 'Update All Services' to update all enabled services"
echo ""
echo "Note: This is an EXPERIMENTAL feature. Test thoroughly!"
echo ""

