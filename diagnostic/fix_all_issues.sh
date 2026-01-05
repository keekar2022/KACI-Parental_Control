#!/usr/bin/env bash
#
# fix_all_issues.sh
# Deploy all fixes for Online Services feature
#

set -e

FIREWALL_IP="${1:-192.168.64.2}"
FIREWALL_USER="mkesharw"
PACKAGE_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "═══════════════════════════════════════════════════════════════════"
echo "  DEPLOYING ALL FIXES FOR ONLINE SERVICES"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Test Firewall: $FIREWALL_USER@$FIREWALL_IP"
echo ""
echo "Fixes included:"
echo "  1. cron.inc compatibility (pfSense 2.8+)"
echo "  2. CSRF token handling"
echo "  3. String/int division fix in profiles"
echo ""

# Files to deploy
FILES=(
    "parental_control.inc"
    "parental_control_services.php"
    "parental_control_profiles.php"
)

echo "Copying files to firewall..."
for file in "${FILES[@]}"; do
    echo "  Copying $file..."
    scp -q "$PACKAGE_DIR/$file" "$FIREWALL_USER@$FIREWALL_IP:/tmp/$file"
done
echo "✓ All files copied"
echo ""

# Deploy on firewall
echo "Deploying fixes on firewall..."
ssh "$FIREWALL_USER@$FIREWALL_IP" << 'ENDSSH'
    echo "  Installing parental_control.inc..."
    sudo mv /tmp/parental_control.inc /usr/local/pkg/parental_control.inc
    sudo chmod 644 /usr/local/pkg/parental_control.inc
    
    echo "  Installing parental_control_services.php..."
    sudo mv /tmp/parental_control_services.php /usr/local/www/parental_control_services.php
    sudo chmod 644 /usr/local/www/parental_control_services.php
    
    echo "  Installing parental_control_profiles.php..."
    sudo mv /tmp/parental_control_profiles.php /usr/local/www/parental_control_profiles.php
    sudo chmod 644 /usr/local/www/parental_control_profiles.php
    
    echo "  Clearing pfSense cache..."
    sudo rm -rf /tmp/config.cache
    
    echo ""
    echo "✓ All fixes deployed!"
ENDSSH

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  ✅ ALL FIXES DEPLOYED SUCCESSFULLY!"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Fixes applied:"
echo ""
echo "1. cron.inc Compatibility (parental_control.inc)"
echo "   • Made cron.inc require conditional"
echo "   • Added function_exists() checks"
echo "   • Works with pfSense 2.8+ and older versions"
echo ""
echo "2. CSRF Token Handling (parental_control_services.php)"
echo "   • Added proper CSRF token to JavaScript forms"
echo "   • Uses pfSense standard format: sid:SESSION_ID"
echo "   • All form submissions now include CSRF token"
echo ""
echo "3. Type Safety Fix (parental_control_profiles.php)"
echo "   • Added intval() casts before division"
echo "   • Prevents string/int operation errors"
echo "   • Handles missing or empty values gracefully"
echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Now test the features:"
echo "  https://$FIREWALL_IP"
echo ""
echo "Test checklist:"
echo "  ☐ Navigate to: Services → Keekar's PC → Online Services"
echo "  ☐ Page loads without crash"
echo "  ☐ Click Enable/Disable on a service (no CSRF error)"
echo "  ☐ Click 'Update IPs' on YouTube"
echo "  ☐ Navigate to: Services → Keekar's PC → Profiles"
echo "  ☐ Page loads without crash"
echo "  ☐ View profile details"
echo ""
echo "All features should work without errors now! ✅"
echo ""

