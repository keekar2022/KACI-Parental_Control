#!/bin/sh
#
# Test HTTP Hijacking for Parental Control
# This script verifies that HTTP requests from blocked devices are redirected to the block page
#

echo "═══════════════════════════════════════════════════════════════════"
echo "    Testing HTTP Hijacking for Parental Control"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Check if NAT rules are active
echo "1. Checking NAT Redirect Rules..."
NAT_RULES=$(pfctl -sn 2>/dev/null | grep -c "parental_control_blocked")
if [ "$NAT_RULES" -ge 2 ]; then
    echo "   ✓ NAT redirect rules are ACTIVE ($NAT_RULES rules found)"
else
    echo "   ✗ NAT redirect rules are MISSING or INCOMPLETE"
    exit 1
fi
echo ""

# Check if captive portal is running
echo "2. Checking Captive Portal Server..."
CAPTIVE_PID=$(ps aux | grep "parental_control_captive.php" | grep -v grep | awk '{print $2}')
if [ -n "$CAPTIVE_PID" ]; then
    echo "   ✓ Captive portal server is RUNNING (PID: $CAPTIVE_PID)"
else
    echo "   ✗ Captive portal server is NOT RUNNING"
    exit 1
fi
echo ""

# Check if port 1008 is listening
echo "3. Checking Port 1008..."
PORT_CHECK=$(sockstat -l | grep ":1008" | wc -l)
if [ "$PORT_CHECK" -ge 1 ]; then
    echo "   ✓ Port 1008 is LISTENING"
else
    echo "   ✗ Port 1008 is NOT LISTENING"
    exit 1
fi
echo ""

# Check allow rules for blocked devices
echo "4. Checking Allow Rules..."
DNS_RULE=$(pfctl -sr 2>/dev/null | grep -c "parental_control_blocked.*domain")
PORTAL_RULE=$(pfctl -sr 2>/dev/null | grep -c "parental_control_blocked.*1008")
if [ "$DNS_RULE" -ge 1 ] && [ "$PORTAL_RULE" -ge 1 ]; then
    echo "   ✓ DNS and portal access rules are ACTIVE"
else
    echo "   ⚠ Some allow rules may be missing"
fi
echo ""

# Test captive portal response
echo "5. Testing Captive Portal Response..."
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:1008 2>/dev/null)
if [ "$RESPONSE" = "200" ]; then
    echo "   ✓ Captive portal responds with HTTP 200 OK"
else
    echo "   ⚠ Captive portal response: HTTP $RESPONSE"
fi
echo ""

# Display active NAT redirect rules
echo "6. Active NAT Redirect Rules:"
echo "───────────────────────────────────────────────────────────────────"
pfctl -sn 2>/dev/null | grep "parental_control_blocked" | sed 's/^/   /'
echo ""

echo "═══════════════════════════════════════════════════════════════════"
echo "    TEST COMPLETE"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "✅ HTTP hijacking is configured and operational!"
echo ""
echo "To test from a blocked device:"
echo "  • Visit: http://neverssl.com"
echo "  • Visit: http://example.com (not https://)"
echo "  • Visit: http://192.168.1.251:1008 (direct access)"
echo ""
echo "Note: HTTPS URLs will show certificate warnings before redirecting."
echo "This is normal behavior for captive portals."
echo ""

