#!/usr/bin/env bash
#
# fix_csrf.sh
# Deploy CSRF token fix for Online Services page
#

set -e

FIREWALL_IP="${1:-192.168.64.2}"
FIREWALL_USER="mkesharw"
PACKAGE_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "═══════════════════════════════════════════════════════════════════"
echo "  DEPLOYING CSRF TOKEN FIX"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Test Firewall: $FIREWALL_USER@$FIREWALL_IP"
echo "Fixing: CSRF token missing in JavaScript form submissions"
echo ""

# Copy fixed file
echo "Copying fixed parental_control_services.php..."
scp -q "$PACKAGE_DIR/parental_control_services.php" "$FIREWALL_USER@$FIREWALL_IP:/tmp/parental_control_services.php"
echo "✓ File copied"
echo ""

# Deploy on firewall
echo "Deploying fix on firewall..."
ssh "$FIREWALL_USER@$FIREWALL_IP" << 'ENDSSH'
    # Install fixed file
    sudo mv /tmp/parental_control_services.php /usr/local/www/parental_control_services.php
    sudo chmod 644 /usr/local/www/parental_control_services.php
    
    # Clear cache
    sudo rm -rf /tmp/config.cache
    
    echo "✓ Fix deployed"
ENDSSH

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  ✅ CSRF FIX DEPLOYED SUCCESSFULLY!"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Changes made:"
echo "  • Added hidden CSRF token container on page"
echo "  • Created addCsrfToken() JavaScript function"
echo "  • Updated all 6 form submission functions to include CSRF token:"
echo "    - toggleService()"
echo "    - updateService()"
echo "    - updateAllServices()"
echo "    - deleteService()"
echo "    - addUrl()"
echo "    - deleteUrl()"
echo ""
echo "Now try using the Online Services features again:"
echo "  https://$FIREWALL_IP"
echo "  Services → Keekar's Parental Control → Online Services"
echo ""
echo "Test by clicking:"
echo "  • Enable/Disable service toggle"
echo "  • Update IPs button"
echo "  • Add URL field"
echo "  • Delete URL button"
echo ""
echo "The CSRF error should now be gone! ✅"
echo ""

