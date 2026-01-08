#!/bin/sh
#
# diagnose_rule_matching.sh
#
# Comprehensive diagnostic script to identify which firewall rules are matching traffic
# and why logs show empty rule descriptions ()
#
# Usage: 
#   ./diagnose_rule_matching.sh [device_ip]
#   
# Example:
#   ./diagnose_rule_matching.sh 192.168.1.95
#
# Part of KACI-Parental_Control for pfSense
# Copyright (c) 2026 Mukesh Kesharwani

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Get device IP from argument or use default
DEVICE_IP="${1:-}"

echo "${BOLD}============================================================${NC}"
echo "${BOLD}  PARENTAL CONTROL - RULE MATCHING DIAGNOSTIC${NC}"
echo "${BOLD}============================================================${NC}"
echo ""

if [ -z "$DEVICE_IP" ]; then
    echo "${YELLOW}⚠  No device IP specified. Showing general diagnostics.${NC}"
    echo "${BLUE}ℹ  Usage: $0 <device_ip>${NC}"
    echo "${BLUE}ℹ  Example: $0 192.168.1.95${NC}"
    echo ""
else
    echo "${GREEN}✓ Target Device: $DEVICE_IP${NC}"
    echo ""
fi

# ============================================================================
# SECTION 1: CHECK PARENTAL CONTROL TABLES
# ============================================================================
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo "${BOLD}SECTION 1: PARENTAL CONTROL TABLES${NC}"
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# Check parental_control_monitor table
echo "${BLUE}📋 Monitored Devices (parental_control_monitor):${NC}"
MONITOR_IPS=$(pfctl -t parental_control_monitor -T show 2>/dev/null)
if [ -z "$MONITOR_IPS" ]; then
    echo "${RED}   ✗ Table is EMPTY - no devices are being monitored!${NC}"
    echo "${YELLOW}   ⚠  This is why logs show () - traffic doesn't match PC rules${NC}"
    DEVICE_IN_MONITOR=0
else
    echo "$MONITOR_IPS" | while read ip; do
        if [ "$ip" = "$DEVICE_IP" ]; then
            echo "   ${GREEN}✓ $ip ${BOLD}(TARGET DEVICE)${NC}"
        else
            echo "   • $ip"
        fi
    done
    
    if [ -n "$DEVICE_IP" ]; then
        if echo "$MONITOR_IPS" | grep -q "^${DEVICE_IP}$"; then
            DEVICE_IN_MONITOR=1
            echo ""
            echo "${GREEN}   ✓ Target device IS in monitor table${NC}"
        else
            DEVICE_IN_MONITOR=0
            echo ""
            echo "${RED}   ✗ Target device NOT in monitor table${NC}"
            echo "${YELLOW}   ⚠  Traffic from this device won't match PC monitoring rules${NC}"
        fi
    fi
fi
echo ""

# Check parental_control_blocked table
echo "${BLUE}🚫 Blocked Devices (parental_control_blocked):${NC}"
BLOCKED_IPS=$(pfctl -t parental_control_blocked -T show 2>/dev/null)
if [ -z "$BLOCKED_IPS" ]; then
    echo "   ${GREEN}✓ Table is empty - no devices currently blocked${NC}"
    DEVICE_IN_BLOCKED=0
else
    echo "$BLOCKED_IPS" | while read ip; do
        if [ "$ip" = "$DEVICE_IP" ]; then
            echo "   ${RED}✗ $ip ${BOLD}(TARGET DEVICE - BLOCKED!)${NC}"
        else
            echo "   • $ip"
        fi
    done
    
    if [ -n "$DEVICE_IP" ]; then
        if echo "$BLOCKED_IPS" | grep -q "^${DEVICE_IP}$"; then
            DEVICE_IN_BLOCKED=1
            echo ""
            echo "${RED}   ✗ Target device IS blocked${NC}"
        else
            DEVICE_IN_BLOCKED=0
            echo ""
            echo "${GREEN}   ✓ Target device is NOT blocked${NC}"
        fi
    fi
fi
echo ""

# ============================================================================
# SECTION 2: CHECK PARENTAL CONTROL RULES
# ============================================================================
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo "${BOLD}SECTION 2: PARENTAL CONTROL RULES${NC}"
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

echo "${BLUE}🔍 Searching for PC rules in pfSense...${NC}"
PC_RULES=$(pfctl -sr | grep -i "parental" 2>/dev/null)

if [ -z "$PC_RULES" ]; then
    echo "${RED}   ✗ NO Parental Control rules found in pf!${NC}"
    echo "${YELLOW}   ⚠  This explains why logs show ()${NC}"
    echo ""
    echo "${BLUE}   💡 Solution: Run sync to recreate rules${NC}"
    echo "      php -r 'require_once(\"/usr/local/pkg/parental_control.inc\"); parental_control_sync();'"
else
    echo "${GREEN}   ✓ Found Parental Control rules${NC}"
    echo ""
    
    # Count rules
    RULE_COUNT=$(echo "$PC_RULES" | wc -l | tr -d ' ')
    echo "   ${BLUE}📊 Total PC rules: $RULE_COUNT${NC}"
    echo ""
    
    # Show rules with details
    echo "${BLUE}   📜 Rule List (in order):${NC}"
    pfctl -vsr 2>/dev/null | grep -B 2 -A 8 "Parental Control" | while IFS= read -r line; do
        if echo "$line" | grep -q "^@"; then
            # Rule number
            echo "      ${BOLD}$line${NC}"
        elif echo "$line" | grep -q "label"; then
            # Rule label (description)
            LABEL=$(echo "$line" | sed 's/.*label "\([^"]*\)".*/\1/')
            echo "      📝 $LABEL"
        elif echo "$line" | grep -q "quick"; then
            echo "         ⚡ Quick: YES (terminates rule processing)"
        elif echo "$line" | grep -q "log"; then
            echo "         📋 Logging: ENABLED"
        elif echo "$line" | grep -q "pass\|block"; then
            ACTION=$(echo "$line" | awk '{print $1}')
            if [ "$ACTION" = "pass" ]; then
                echo "         ${GREEN}✓ Action: PASS (allow)${NC}"
            else
                echo "         ${RED}✗ Action: BLOCK (deny)${NC}"
            fi
        fi
    done
fi
echo ""

# ============================================================================
# SECTION 3: CHECK SERVICE ALIASES
# ============================================================================
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo "${BOLD}SECTION 3: SERVICE ALIASES (IP TABLES)${NC}"
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

for service in YouTube Facebook Discord; do
    TABLE_NAME="PC_Service_${service}"
    echo "${BLUE}🌐 $service (${TABLE_NAME}):${NC}"
    
    IPS=$(pfctl -t "$TABLE_NAME" -T show 2>/dev/null | head -10)
    if [ -z "$IPS" ]; then
        echo "   ${RED}✗ Table is EMPTY${NC}"
        echo "   ${YELLOW}⚠  Rules for this service won't match any traffic${NC}"
    else
        IP_COUNT=$(pfctl -t "$TABLE_NAME" -T show 2>/dev/null | wc -l | tr -d ' ')
        echo "   ${GREEN}✓ Loaded: $IP_COUNT IPs${NC}"
        echo "   Sample IPs:"
        echo "$IPS" | head -5 | while read ip; do
            echo "      • $ip"
        done
        if [ "$IP_COUNT" -gt 5 ]; then
            echo "      ... and $((IP_COUNT - 5)) more"
        fi
    fi
    echo ""
done

# ============================================================================
# SECTION 4: CHECK INTERFACE RULES (FALLBACK)
# ============================================================================
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo "${BOLD}SECTION 4: INTERFACE RULES (LAN)${NC}"
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

echo "${BLUE}🔍 Checking LAN interface rules...${NC}"
LAN_RULES=$(pfctl -sr | grep -A 5 "on.*lan" 2>/dev/null | head -20)

if [ -n "$LAN_RULES" ]; then
    echo "${YELLOW}   ⚠  Found interface rules on LAN${NC}"
    echo "   ${BLUE}These rules are evaluated AFTER floating rules${NC}"
    echo "   ${BLUE}If floating rules don't match, traffic falls through here${NC}"
    echo ""
    echo "   ${BOLD}Sample LAN rules:${NC}"
    echo "$LAN_RULES" | head -10
else
    echo "${GREEN}   ✓ No interface-specific rules found${NC}"
fi
echo ""

# ============================================================================
# SECTION 5: DEVICE-SPECIFIC ANALYSIS
# ============================================================================
if [ -n "$DEVICE_IP" ]; then
    echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo "${BOLD}SECTION 5: DEVICE-SPECIFIC ANALYSIS${NC}"
    echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    
    echo "${BLUE}🔍 Analyzing traffic from $DEVICE_IP...${NC}"
    echo ""
    
    # Check active connections
    echo "${BLUE}📊 Active Connections:${NC}"
    CONNECTIONS=$(pfctl -ss | grep "$DEVICE_IP" 2>/dev/null | grep ESTABLISHED | head -10)
    if [ -z "$CONNECTIONS" ]; then
        echo "   ${YELLOW}⚠  No active connections found${NC}"
    else
        CONN_COUNT=$(pfctl -ss | grep "$DEVICE_IP" 2>/dev/null | grep ESTABLISHED | wc -l | tr -d ' ')
        echo "   ${GREEN}✓ Found $CONN_COUNT active connections${NC}"
        echo ""
        echo "   Sample connections:"
        echo "$CONNECTIONS" | head -5 | while read line; do
            echo "      $line"
        done
    fi
    echo ""
    
    # Try to match against service IPs
    echo "${BLUE}🎯 Matching Connections Against Service Aliases:${NC}"
    for service in YouTube Facebook Discord; do
        TABLE_NAME="PC_Service_${service}"
        SERVICE_IPS=$(pfctl -t "$TABLE_NAME" -T show 2>/dev/null)
        
        if [ -n "$SERVICE_IPS" ] && [ -n "$CONNECTIONS" ]; then
            MATCHED=0
            echo "$CONNECTIONS" | while read conn; do
                DEST_IP=$(echo "$conn" | awk '{print $4}' | cut -d: -f1)
                if echo "$SERVICE_IPS" | grep -q "^${DEST_IP}$"; then
                    MATCHED=1
                    echo "   ${GREEN}✓ $service: Connection to $DEST_IP${NC}"
                fi
            done
        fi
    done
    echo ""
fi

# ============================================================================
# SECTION 6: DIAGNOSIS & RECOMMENDATIONS
# ============================================================================
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo "${BOLD}SECTION 6: DIAGNOSIS & RECOMMENDATIONS${NC}"
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

echo "${BOLD}🔍 Why are firewall logs showing () instead of rule names?${NC}"
echo ""

# Check conditions
MONITOR_EMPTY=0
if [ -z "$MONITOR_IPS" ]; then
    MONITOR_EMPTY=1
fi

RULES_MISSING=0
if [ -z "$PC_RULES" ]; then
    RULES_MISSING=1
fi

# Provide diagnosis
if [ $RULES_MISSING -eq 1 ]; then
    echo "${RED}❌ CRITICAL: No Parental Control rules exist in pfSense${NC}"
    echo ""
    echo "${BOLD}ROOT CAUSE:${NC}"
    echo "   • PC rules were not created or were deleted"
    echo "   • Traffic matches default interface rules (no label)"
    echo ""
    echo "${BOLD}SOLUTION:${NC}"
    echo "   1. Run package sync to recreate rules:"
    echo "      ${GREEN}php -r 'require_once(\"/usr/local/pkg/parental_control.inc\"); parental_control_sync();'${NC}"
    echo ""
    echo "   2. Reload firewall filter:"
    echo "      ${GREEN}/etc/rc.filter_configure${NC}"
    echo ""
    
elif [ $MONITOR_EMPTY -eq 1 ]; then
    echo "${YELLOW}⚠  WARNING: Monitor table is empty${NC}"
    echo ""
    echo "${BOLD}ROOT CAUSE:${NC}"
    echo "   • No devices in parental_control_monitor table"
    echo "   • PC monitoring rules exist but never match"
    echo "   • Traffic falls through to default LAN rules (no label)"
    echo ""
    echo "${BOLD}SOLUTION:${NC}"
    echo "   1. Force cron job to populate tables:"
    echo "      ${GREEN}php /usr/local/bin/parental_control_cron.php${NC}"
    echo ""
    echo "   2. Verify profiles have devices configured:"
    echo "      ${GREEN}Check: Services → Parental Control → Profiles${NC}"
    echo ""
    
elif [ -n "$DEVICE_IP" ] && [ $DEVICE_IN_MONITOR -eq 0 ]; then
    echo "${YELLOW}⚠  Device $DEVICE_IP is NOT in monitor table${NC}"
    echo ""
    echo "${BOLD}ROOT CAUSE:${NC}"
    echo "   • Device is configured but not in parental_control_monitor"
    echo "   • Could be offline, DHCP issue, or not in enabled profile"
    echo "   • PC rules don't match this device's traffic"
    echo ""
    echo "${BOLD}SOLUTION:${NC}"
    echo "   1. Check device is in a profile:"
    echo "      ${GREEN}Services → Parental Control → Profiles${NC}"
    echo ""
    echo "   2. Check profile is enabled"
    echo ""
    echo "   3. Force cron run to update tables:"
    echo "      ${GREEN}php /usr/local/bin/parental_control_cron.php${NC}"
    echo ""
    
else
    echo "${GREEN}✓ All checks passed!${NC}"
    echo ""
    echo "${BOLD}FINDINGS:${NC}"
    echo "   • PC rules exist and are properly configured"
    echo "   • Monitor table has devices"
    if [ -n "$DEVICE_IP" ]; then
        echo "   • Target device is in monitor table"
    fi
    echo ""
    echo "${BOLD}POSSIBLE CAUSES FOR () LOGS:${NC}"
    echo "   1. Traffic is to IPs NOT in service aliases"
    echo "      → Generic Google/Apple services, not YouTube/Facebook/Discord"
    echo ""
    echo "   2. Rule order: Other rules matching first"
    echo "      → Check Firewall → Rules → Floating for conflicts"
    echo ""
    echo "   3. Logging might be on a different interface rule"
    echo "      → Check Firewall → Rules → LAN0 for default allow rule"
    echo ""
fi

# ============================================================================
# SECTION 7: QUICK ACTIONS
# ============================================================================
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo "${BOLD}SECTION 7: QUICK ACTIONS${NC}"
echo "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

echo "${BLUE}💡 Common fixes:${NC}"
echo ""
echo "   ${BOLD}Fix 1: Populate tables and recreate rules${NC}"
echo "   ${GREEN}php /usr/local/bin/parental_control_cron.php && \\${NC}"
echo "   ${GREEN}php -r 'require_once(\"/usr/local/pkg/parental_control.inc\"); parental_control_sync();'${NC}"
echo ""
echo "   ${BOLD}Fix 2: Check rule order in GUI${NC}"
echo "   ${GREEN}Navigate to: Firewall → Rules → Floating${NC}"
echo "   ${GREEN}Verify PC rules are at the top with 'Quick' enabled${NC}"
echo ""
echo "   ${BOLD}Fix 3: View real-time rule matching${NC}"
echo "   ${GREEN}tcpdump -n -e -ttt -i igc0 host $DEVICE_IP 2>&1 | head -20${NC}"
echo ""

echo "${BOLD}============================================================${NC}"
echo "${BOLD}  DIAGNOSTIC COMPLETE${NC}"
echo "${BOLD}============================================================${NC}"
echo ""

