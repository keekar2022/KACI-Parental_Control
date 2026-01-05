#!/bin/bash
#
# view_sync_status.sh
# View status of production data sync (runs on LAPTOP)
#

PROD_HOST="192.168.1.1"
TEST_HOST="192.168.64.2"
USER="mkesharw"
LOG_FILE="/tmp/kaci_sync.log"
LOCK_FILE="/tmp/kaci_sync.lock"
STATE_FILE_TEST="/var/db/parental_control/parental_control_state.json"

clear

echo "═══════════════════════════════════════════════════════════════════"
echo "  KACI Parental Control - Sync Status (Laptop Intermediary)"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Check if sync is running
if [ -f "$LOCK_FILE" ]; then
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
        echo "🔄 Status: SYNCING (PID: $LOCK_PID)"
    else
        echo "✓ Status: IDLE (stale lock)"
    fi
else
    echo "✓ Status: IDLE"
fi

echo ""

# Check cron job
if crontab -l 2>/dev/null | grep -q "sync_production_data.sh"; then
    CRON_SCHEDULE=$(crontab -l 2>/dev/null | grep "sync_production_data.sh" | awk '{print $1, $2, $3, $4, $5}')
    echo "📅 Cron Schedule: $CRON_SCHEDULE (Every 4 minutes)"
else
    echo "⚠ Cron Schedule: NOT CONFIGURED"
fi

echo ""

# Network connectivity
echo "🌐 Network Status:"
if ping -c 1 -W 1 $PROD_HOST >/dev/null 2>&1; then
    echo "  ✓ Production ($PROD_HOST) - Reachable"
else
    echo "  ✗ Production ($PROD_HOST) - NOT Reachable"
fi

if ping -c 1 -W 1 $TEST_HOST >/dev/null 2>&1; then
    echo "  ✓ Test ($TEST_HOST) - Reachable"
else
    echo "  ✗ Test ($TEST_HOST) - NOT Reachable"
fi

echo ""

# Last sync info
if [ -f "$LOG_FILE" ]; then
    LAST_SYNC=$(grep "Sync completed successfully" "$LOG_FILE" | tail -1 | sed 's/\[//g' | sed 's/\].*//g')
    if [ -n "$LAST_SYNC" ]; then
        echo "🕐 Last Sync: $LAST_SYNC"
    else
        echo "🕐 Last Sync: Never (or in progress)"
    fi
    
    LOG_SIZE=$(ls -lh "$LOG_FILE" | awk '{print $5}')
    echo "📄 Log Size: $LOG_SIZE"
else
    echo "⚠ No sync log found"
fi

echo ""

# State file info on test system
echo "💾 Test System State File:"
STATE_INFO=$(ssh -o BatchMode=yes -o ConnectTimeout=5 ${USER}@${TEST_HOST} \
    "if [ -f ${STATE_FILE_TEST} ]; then \
        ls -lh ${STATE_FILE_TEST} | awk '{print \$5, \$6, \$7, \$8}'; \
     else \
        echo 'Not found'; \
     fi" 2>/dev/null)

if [ -n "$STATE_INFO" ] && [ "$STATE_INFO" != "Not found" ]; then
    echo "  Size & Modified: $STATE_INFO"
    
    # Try to get device stats if jq is available
    STATS=$(ssh -o BatchMode=yes -o ConnectTimeout=5 ${USER}@${TEST_HOST} \
        "if command -v jq >/dev/null 2>&1 && [ -f ${STATE_FILE_TEST} ]; then \
            echo -n 'Devices: '; \
            jq '.devices_by_ip | length' ${STATE_FILE_TEST} 2>/dev/null || echo '?'; \
            echo -n 'Profiles: '; \
            jq '.profiles | length' ${STATE_FILE_TEST} 2>/dev/null || echo '?'; \
            echo -n 'Blocked: '; \
            jq '.blocked_devices | length' ${STATE_FILE_TEST} 2>/dev/null || echo '?'; \
        fi" 2>/dev/null)
    
    if [ -n "$STATS" ]; then
        echo "  $STATS"
    fi
else
    echo "  ⚠ State file not found or inaccessible"
fi

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  Recent Sync Activity (Last 20 lines)"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

if [ -f "$LOG_FILE" ]; then
    tail -20 "$LOG_FILE"
else
    echo "No log file found."
fi

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "Commands:"
echo "  • Watch live:        tail -f /tmp/kaci_sync.log"
echo "  • Run sync now:      ~/Documents/KACI-Parental_Control/diagnostic/sync_production_data.sh"
echo "  • Full log:          cat /tmp/kaci_sync.log"
echo "  • Clear log:         rm /tmp/kaci_sync.log"
echo ""
echo "Architecture: Laptop → Production (192.168.1.1) → Laptop → Test (192.168.64.2)"
echo "              (Laptop acts as intermediary)"
echo ""
