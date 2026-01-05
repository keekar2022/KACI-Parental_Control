#!/usr/bin/env bash
#
# update_verify_fetch.sh
# Update Online Services to use "Verify Fetch" instead of "Update IPs"
#

set -e

FIREWALL_IP="${1:-192.168.64.2}"
FIREWALL_USER="mkesharw"
PACKAGE_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "═══════════════════════════════════════════════════════════════════"
echo "  UPDATING ONLINE SERVICES: VERIFY FETCH FEATURE"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Test Firewall: $FIREWALL_USER@$FIREWALL_IP"
echo ""
echo "Changes:"
echo "  • Changed 'Update IPs' → 'Verify Fetch'"
echo "  • Tests URL accessibility without fetching data"
echo "  • Designed for URL Alias Table use"
echo "  • Shows verification status instead of IP count"
echo ""

# Files to deploy
FILES=(
    "parental_control.inc"
    "parental_control_services.php"
)

echo "Copying files to firewall..."
for file in "${FILES[@]}"; do
    echo "  Copying $file..."
    scp -q "$PACKAGE_DIR/$file" "$FIREWALL_USER@$FIREWALL_IP:/tmp/$file"
done
echo "✓ All files copied"
echo ""

# Deploy on firewall
echo "Deploying updates on firewall..."
ssh "$FIREWALL_USER@$FIREWALL_IP" << 'ENDSSH'
    echo "  Installing parental_control.inc..."
    sudo mv /tmp/parental_control.inc /usr/local/pkg/parental_control.inc
    sudo chmod 644 /usr/local/pkg/parental_control.inc
    
    echo "  Installing parental_control_services.php..."
    sudo mv /tmp/parental_control_services.php /usr/local/www/parental_control_services.php
    sudo chmod 644 /usr/local/www/parental_control_services.php
    
    echo "  Clearing pfSense cache..."
    sudo rm -rf /tmp/config.cache
    
    echo ""
    echo "✓ Update deployed!"
ENDSSH

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  ✅ VERIFY FETCH FEATURE DEPLOYED!"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "What changed:"
echo ""
echo "1. Button Text:"
echo "   ❌ OLD: 'Update IPs' - fetched and stored IPs"
echo "   ✅ NEW: 'Verify Fetch' - just tests URL accessibility"
echo ""
echo "2. Statistics Display:"
echo "   ❌ OLD: Shows 'X IPs' and 'Last update'"
echo "   ✅ NEW: Shows 'Verified/Not verified' and 'Last verified'"
echo ""
echo "3. Backend Functions:"
echo "   Added: pc_verify_service_urls() - tests URLs without downloading"
echo "   Added: pc_test_url_accessibility() - HEAD request only"
echo ""
echo "4. Info Banner:"
echo "   Updated to explain URL Alias Table usage"
echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "How to use:"
echo "  1. Go to: Services → Keekar's PC → Online Services"
echo "  2. Click 'Verify Fetch' on any service"
echo "  3. URLs are tested for accessibility"
echo "  4. Status shows 'Verified' if successful"
echo "  5. Copy URLs to create pfSense URL Alias Tables manually"
echo ""
echo "Example: Create URL Alias Table in pfSense"
echo "  1. Go to: Firewall → Aliases → URLs"
echo "  2. Add new URL Alias"
echo "  3. Name: YouTube_IPs"
echo "  4. URLs: Copy from 'Online Services' page"
echo "  5. Use in firewall rules"
echo ""
echo "This approach is better than IP-based blocking because:"
echo "  • URLs update automatically in pfSense"
echo "  • No need to manually refresh IPs"
echo "  • More reliable for services that change IPs frequently"
echo ""

