#!/bin/sh
#
# view_firewall_logs.sh
#
# View pfSense firewall logs without clog dependency
# Works with both circular and plain text log formats
#
# Usage: 
#   ./view_firewall_logs.sh [device_ip] [lines]
#   
# Examples:
#   ./view_firewall_logs.sh                    # Show last 50 lines
#   ./view_firewall_logs.sh 192.168.1.95      # Filter by device IP
#   ./view_firewall_logs.sh 192.168.1.95 100  # Show last 100 lines
#   ./view_firewall_logs.sh "" 100            # Show last 100 lines (all)
#
# Part of KACI-Parental_Control for pfSense

DEVICE_IP="${1:-}"
LINES="${2:-50}"
FILTER_LOG="/var/log/filter.log"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'
BOLD='\033[1m'

echo "${BOLD}============================================================${NC}"
echo "${BOLD}  FIREWALL LOG VIEWER${NC}"
echo "${BOLD}============================================================${NC}"
echo ""

# Check if log file exists
if [ ! -f "$FILTER_LOG" ]; then
    echo "${RED}✗ Error: Log file not found: $FILTER_LOG${NC}"
    exit 1
fi

# Try different methods to read the log
read_log() {
    # Method 1: Try clog (traditional)
    if command -v clog >/dev/null 2>&1; then
        clog "$FILTER_LOG"
        return 0
    fi
    
    # Method 2: Try full path to clog
    if [ -x "/usr/sbin/clog" ]; then
        /usr/sbin/clog "$FILTER_LOG"
        return 0
    fi
    
    # Method 3: Try reading directly (works if not circular)
    if cat "$FILTER_LOG" >/dev/null 2>&1; then
        cat "$FILTER_LOG"
        return 0
    fi
    
    # Method 4: Try dd to read circular log
    if dd if="$FILTER_LOG" 2>/dev/null | strings | grep -v "^$"; then
        return 0
    fi
    
    echo "${RED}✗ Unable to read log file${NC}" >&2
    return 1
}

# Parse a log line (simplified pfSense format)
parse_line() {
    line="$1"
    
    # Skip empty or malformed lines
    if [ -z "$line" ] || ! echo "$line" | grep -q "," ; then
        return
    fi
    
    # pfSense filterlog format (simplified):
    # Fields are comma-separated, typical important fields:
    # timestamp,action,interface,protocol,src_ip,dst_ip,src_port,dst_port
    
    # Extract key fields
    timestamp=$(echo "$line" | awk '{print $1, $2, $3}')
    
    # Look for rule description in the line
    if echo "$line" | grep -q "Parental Control"; then
        rule=$(echo "$line" | grep -o "Parental Control[^,]*" | head -1)
        color="${GREEN}"
    else
        rule="(default)"
        color="${NC}"
    fi
    
    # Extract IPs if possible
    src_ip=$(echo "$line" | cut -d',' -f19 2>/dev/null || echo "?")
    dst_ip=$(echo "$line" | cut -d',' -f20 2>/dev/null || echo "?")
    
    # Simple display
    echo "${color}${rule}${NC} | ${src_ip} → ${dst_ip}"
}

echo "${BLUE}📋 Reading firewall logs...${NC}"
echo ""

# Get the logs
LOG_CONTENT=$(read_log)

if [ $? -ne 0 ]; then
    echo "${RED}✗ Failed to read logs${NC}"
    echo ""
    echo "${YELLOW}Try viewing logs through pfSense GUI:${NC}"
    echo "  Status → System Logs → Firewall → Normal View"
    exit 1
fi

# Filter and display
if [ -n "$DEVICE_IP" ]; then
    echo "${CYAN}Filter: Device IP = $DEVICE_IP | Last $LINES entries${NC}"
    echo "${BOLD}────────────────────────────────────────────────────────────${NC}"
    
    # Filter by device IP and show last N lines
    echo "$LOG_CONTENT" | grep "$DEVICE_IP" | tail -n "$LINES"
    
    echo ""
    echo "${BOLD}────────────────────────────────────────────────────────────${NC}"
    
    # Show Parental Control specific entries
    PC_ENTRIES=$(echo "$LOG_CONTENT" | grep "$DEVICE_IP" | grep -i "parental" | wc -l | tr -d ' ')
    TOTAL_ENTRIES=$(echo "$LOG_CONTENT" | grep "$DEVICE_IP" | wc -l | tr -d ' ')
    
    echo ""
    echo "${BOLD}📊 Statistics for $DEVICE_IP:${NC}"
    echo "   ${GREEN}Parental Control entries: $PC_ENTRIES${NC}"
    echo "   ${BLUE}Total entries: $TOTAL_ENTRIES${NC}"
    
    if [ "$PC_ENTRIES" -gt 0 ]; then
        echo ""
        echo "${GREEN}✓ Parental Control logging is working!${NC}"
        echo ""
        echo "${BOLD}Parental Control entries:${NC}"
        echo "$LOG_CONTENT" | grep "$DEVICE_IP" | grep -i "parental" | tail -10
    else
        echo ""
        echo "${YELLOW}⚠  No Parental Control entries found for this device${NC}"
        echo "   This means traffic is to non-service IPs (normal)"
    fi
else
    echo "${CYAN}Filter: All traffic | Last $LINES entries${NC}"
    echo "${BOLD}────────────────────────────────────────────────────────────${NC}"
    
    # Show last N lines
    echo "$LOG_CONTENT" | tail -n "$LINES"
    
    echo ""
    echo "${BOLD}────────────────────────────────────────────────────────────${NC}"
    
    # Show statistics
    PC_ENTRIES=$(echo "$LOG_CONTENT" | grep -i "parental" | wc -l | tr -d ' ')
    TOTAL_ENTRIES=$(echo "$LOG_CONTENT" | wc -l | tr -d ' ')
    
    echo ""
    echo "${BOLD}📊 Overall Statistics:${NC}"
    echo "   ${GREEN}Parental Control entries: $PC_ENTRIES${NC}"
    echo "   ${BLUE}Total log entries: $TOTAL_ENTRIES${NC}"
    
    if [ "$PC_ENTRIES" -gt 0 ]; then
        echo ""
        echo "${BOLD}Recent Parental Control entries:${NC}"
        echo "$LOG_CONTENT" | grep -i "parental" | tail -5
    fi
fi

echo ""
echo "${BOLD}============================================================${NC}"
echo ""
echo "${BLUE}💡 Tips:${NC}"
echo "   • Use GUI: Status → System Logs → Firewall"
echo "   • Real-time: tail -f /var/log/filter.log | grep 192.168.1.95"
echo "   • Search PC: grep -i parental /var/log/filter.log"
echo ""

