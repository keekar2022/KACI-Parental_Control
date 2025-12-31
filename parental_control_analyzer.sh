#!/bin/sh
#
# parental_control_analyzer.sh
# Helper script to analyze Parental Control JSONL logs
# Compatible with pfSense (requires jq)
#
# Usage: ./parental_control_analyzer.sh [command] [options]
#

LOG_FILE="/var/log/parental_control.jsonl"
LOG_DIR="/var/log"
STATE_FILE="/var/db/parental_control_state.json"
PID_FILE="/var/run/parental_control.pid"
VERSION_FILE="/usr/local/pkg/parental_control_VERSION"
BUILD_INFO_FILE="/usr/local/share/pfSense-pkg-KACI-Parental_Control/BUILD_INFO.json"

# Detect if we're in a terminal that supports colors
if [ -t 1 ] && [ -z "$NO_COLOR" ] && [ "$TERM" != "dumb" ]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    CYAN='\033[0;36m'
    NC='\033[0m' # No Color
else
    RED=''
    GREEN=''
    YELLOW=''
    BLUE=''
    CYAN=''
    NC=''
fi

# Helper function to print with color
print_color() {
    local color="$1"
    shift
    printf "${color}%s${NC}\n" "$*"
}

# Get all log files (current + rotated)
get_all_log_files() {
    if [ -f "$LOG_FILE" ]; then
        echo "$LOG_FILE"
    fi
    find "$LOG_DIR" -name "parental_control_*.jsonl" -type f 2>/dev/null | sort -r
}

# Get combined logs from all files
get_combined_logs() {
    get_all_log_files | while read -r logfile; do
        cat "$logfile" 2>/dev/null
    done
}

# Check if jq is installed
if ! command -v jq > /dev/null 2>&1; then
    printf "${RED}Error: jq is not installed. Install with: pkg install jq${NC}\n"
    exit 1
fi

# Print header
print_header() {
    printf "${BLUE}========================================${NC}\n"
    printf "${BLUE}  Parental Control Log Analyzer${NC}\n"
    printf "${BLUE}========================================${NC}\n"
    echo ""
}

# List all log files
list_logs() {
    print_header
    printf "${GREEN}=== Available Log Files ===${NC}\n"
    echo ""
    
    log_count=0
    total_size=0
    
    get_all_log_files | while read -r logfile; do
        if [ -f "$logfile" ]; then
            size=$(du -h "$logfile" | awk '{print $1}')
            size_bytes=$(du -k "$logfile" | awk '{print $1}')
            total_size=$((total_size + size_bytes))
            entries=$(wc -l < "$logfile")
            log_count=$((log_count + 1))
            
            if [ "$logfile" = "$LOG_FILE" ]; then
                printf "${GREEN}[CURRENT]${NC} %s\n" "$logfile"
            else
                echo "          $logfile"
            fi
            echo "          Size: $size, Entries: $entries"
            echo ""
        fi
    done
    
    echo "Total log files: $log_count"
}

# Show usage statistics
show_stats() {
    print_header
    printf "${GREEN}=== Usage Statistics (Current Log) ===${NC}\n"
    echo ""
    
    # Total entries
    total=$(wc -l < "$LOG_FILE" 2>/dev/null || echo "0")
    echo "Total log entries: $total"
    
    # Check if rotated logs exist
    rotated_count=$(find "$LOG_DIR" -name "parental_control_*.jsonl" -type f 2>/dev/null | wc -l | tr -d ' ')
    if [ "$rotated_count" -gt 0 ]; then
        printf "${YELLOW}Note: $rotated_count rotated log file(s) available. Use 'stats-all' to include them.${NC}\n"
    fi
    
    # Events by type
    echo ""
    echo "Events by action:"
    cat "$LOG_FILE" 2>/dev/null | jq -r '.attributes."event.action" // "unknown"' | sort | uniq -c | sort -rn
    
    # Most active devices
    echo ""
    echo "Top 10 devices by activity:"
    cat "$LOG_FILE" 2>/dev/null | jq -r '.attributes."device.mac" // empty' | sort | uniq -c | sort -rn | head -10
    
    # Devices that hit limits
    echo ""
    echo "Devices that exceeded limits:"
    cat "$LOG_FILE" 2>/dev/null | jq -r 'select(.attributes."event.action" == "limit_exceeded") | .attributes."device.mac"' | sort | uniq -c
    
    # Errors count
    echo ""
    errors=$(cat "$LOG_FILE" 2>/dev/null | jq -r 'select(.severityText == "ERROR" or .severityText == "error")' | wc -l)
    warnings=$(cat "$LOG_FILE" 2>/dev/null | jq -r 'select(.severityText == "WARN" or .severityText == "warn")' | wc -l)
    printf "Errors: ${RED}%s${NC}\n" "$errors"
    printf "Warnings: ${YELLOW}%s${NC}\n" "$warnings"
}

# Show usage statistics from ALL logs
show_stats_all() {
    print_header
    printf "${GREEN}=== Usage Statistics (All Logs) ===${NC}\n"
    printf "${YELLOW}Note: Processing all logs - this may take a moment...${NC}\n"
    echo ""
    
    # Total entries across all logs
    total=$(get_combined_logs | wc -l)
    echo "Total log entries: $total"
    
    # Log files count
    log_files_count=$(get_all_log_files | wc -l)
    echo "Log files: $log_files_count"
    
    # Events by type
    echo ""
    echo "Events by action:"
    get_combined_logs | jq -r '.attributes."event.action" // "unknown"' | sort | uniq -c | sort -rn
    
    # Most active devices
    echo ""
    echo "Top 10 devices by activity:"
    get_combined_logs | jq -r '.attributes."device.mac" // empty' | sort | uniq -c | sort -rn | head -10
    
    # Errors count
    echo ""
    errors=$(get_combined_logs | jq -r 'select(.severityText == "ERROR" or .severityText == "error")' | wc -l)
    warnings=$(get_combined_logs | jq -r 'select(.severityText == "WARN" or .severityText == "warn")' | wc -l)
    printf "Errors: ${RED}%s${NC}\n" "$errors"
    printf "Warnings: ${YELLOW}%s${NC}\n" "$warnings"
}

# Show recent activity
show_recent() {
    local count="${1:-20}"
    print_header
    printf "${GREEN}=== Recent Activity (last $count entries) ===${NC}\n"
    echo ""
    
    if [ -n "$RED" ]; then
        tail -"$count" "$LOG_FILE" 2>/dev/null | jq -r '
            .timestamp + " " + 
            (if .severityText == "ERROR" or .severityText == "error" then "\u001b[31m" + .severityText + "\u001b[0m" 
             elif .severityText == "WARN" or .severityText == "warn" then "\u001b[33m" + .severityText + "\u001b[0m" 
             else .severityText end) + " " + 
            (.attributes."event.action" // "N/A") + " " + 
            .body
        '
    else
        tail -"$count" "$LOG_FILE" 2>/dev/null | jq -r '
            .timestamp + " " + 
            .severityText + " " + 
            (.attributes."event.action" // "N/A") + " " + 
            .body
        '
    fi
}

# Show device activity
show_device() {
    mac=$1
    
    if [ -z "$mac" ]; then
        printf "${RED}Error: Please provide MAC address${NC}\n"
        echo "Usage: $0 device aa:bb:cc:dd:ee:ff"
        exit 1
    fi
    
    print_header
    printf "${GREEN}=== Activity for Device: $mac ===${NC}\n"
    echo ""
    
    cat "$LOG_FILE" 2>/dev/null | jq -r --arg mac "$mac" '
        select(.attributes."device.mac" == $mac) | 
        .timestamp + " [" + .severityText + "] " + 
        (.attributes."event.action" // "N/A") + " " + 
        .body
    ' | tail -50
}

# Show errors and warnings
show_errors() {
    print_header
    printf "${RED}=== Errors and Warnings ===${NC}\n"
    echo ""
    
    cat "$LOG_FILE" 2>/dev/null | jq -r '
        select(.severityText == "ERROR" or .severityText == "WARN" or .severityText == "error" or .severityText == "warn") | 
        .timestamp + " [" + .severityText + "] " + .body + 
        (if .attributes."error.type" then " (Type: " + .attributes."error.type" + ")" else "" end)
    ' | tail -50
}

# Watch logs in real-time
watch_logs() {
    print_header
    printf "${GREEN}=== Watching logs in real-time (Ctrl+C to stop) ===${NC}\n"
    echo ""
    
    if [ -n "$RED" ]; then
        tail -f "$LOG_FILE" 2>/dev/null | jq -r '
            .timestamp + " [" + 
            (if .severityText == "ERROR" or .severityText == "error" then "\u001b[31m" + .severityText + "\u001b[0m"
             elif .severityText == "WARN" or .severityText == "warn" then "\u001b[33m" + .severityText + "\u001b[0m"
             else .severityText end) + "] " +
            (.attributes."event.action" // "N/A") + " - " + 
            .body
        '
    else
        tail -f "$LOG_FILE" 2>/dev/null | jq -r '
            .timestamp + " [" + .severityText + "] " +
            (.attributes."event.action" // "N/A") + " - " + 
            .body
        '
    fi
}

# Show current state file
show_state() {
    print_header
    printf "${GREEN}=== Current State File ===${NC}\n"
    echo ""
    
    if [ ! -f "$STATE_FILE" ]; then
        printf "${RED}State file not found: $STATE_FILE${NC}\n"
        return
    fi
    
    echo "State file: $STATE_FILE"
    echo "Size: $(ls -lh "$STATE_FILE" 2>/dev/null | awk '{print $5}')"
    echo ""
    
    # Check format (v0.2.1+ uses devices_by_ip, v0.2.0 uses devices)
    format_check=$(cat "$STATE_FILE" 2>/dev/null | jq -r 'if .devices_by_ip then "ip_based" elif .devices then "mac_based" else "unknown" end')
    
    if [ "$format_check" = "ip_based" ]; then
        echo "Format: Layer 3 (IP-based) ✅"
        echo ""
        echo "Device Summary (by IP Address):"
        echo ""
        
        # Parse IP-based format (v0.2.1+)
        if [ -n "$RED" ]; then
            cat "$STATE_FILE" 2>/dev/null | jq -r '
                .devices_by_ip // {} | 
                to_entries[] | 
                .key as $ip | 
                .value | 
                $ip + " (MAC: " + (.mac // "unknown") + ", Name: " + (.name // "unknown") + ")" +
                "\n  Today: " + (.usage_today | tostring) + "min" +
                ", Week: " + (.usage_week | tostring) + "min" +
                ", Connections: " + ((.connections_last_check // 0) | tostring) +
                (if .offline_since then " [OFFLINE since " + (.offline_since | tostring) + "]" else "" end)
            '
        else
            cat "$STATE_FILE" 2>/dev/null | jq -r '
                .devices_by_ip // {} | 
                to_entries[] | 
                .key as $ip | 
                .value | 
                $ip + " (MAC: " + (.mac // "unknown") + ")" +
                " Today: " + (.usage_today | tostring) + "min" +
                ", Week: " + (.usage_week | tostring) + "min"
            '
        fi
        
        # Show MAC → IP cache
        echo ""
        printf "${CYAN}MAC → IP Cache:${NC}\n"
        cat "$STATE_FILE" 2>/dev/null | jq -r '
            .mac_to_ip_cache // {} | 
            to_entries[] | 
            "  " + .key + " → " + .value
        ' || echo "  (empty)"
        
    elif [ "$format_check" = "mac_based" ]; then
        printf "${YELLOW}Format: Layer 2 (MAC-based) - OLD FORMAT${NC}\n"
        printf "${YELLOW}⚠ This format is deprecated. Reinstall to migrate to IP-based.${NC}\n"
        echo ""
        echo "Device Summary (by MAC Address):"
        echo ""
        
        # Parse MAC-based format (v0.2.0)
        cat "$STATE_FILE" 2>/dev/null | jq -r '
            .devices // {} | 
            to_entries[] | 
            .key as $mac | 
            .value | 
            $mac + ": Today=" + (.usage_today | tostring) + "min, Week=" + (.usage_week | tostring) + "min"
        '
    else
        printf "${RED}Format: Unknown or invalid${NC}\n"
    fi
    
    # Show metadata
    echo ""
    printf "${CYAN}Metadata:${NC}\n"
    cat "$STATE_FILE" 2>/dev/null | jq -r '
        "  Last Reset: " + (if .last_reset then (.last_reset | tostring) else "never" end) + 
        "\n  Last Check: " + (if .last_check then (.last_check | tostring) else "never" end) +
        "\n  Total Devices: " + (if .devices_by_ip then (.devices_by_ip | length | tostring) elif .devices then (.devices | length | tostring) else "0" end)
    ' || echo "  (no metadata)"
}

# Show system status
show_status() {
    print_header
    printf "${GREEN}=== System Status ===${NC}\n"
    echo ""
    
    # Check if service is running
    if [ -f "$PID_FILE" ]; then
        pid=$(cat "$PID_FILE")
        if ps -p "$pid" > /dev/null 2>&1; then
            printf "${GREEN}✓ Service is RUNNING (PID: $pid)${NC}\n"
        else
            printf "${YELLOW}⚠ PID file exists but process not found (stale lock)${NC}\n"
        fi
    else
        printf "${YELLOW}⚠ No PID file (service may not be running)${NC}\n"
    fi
    
    # Check log file
    if [ -f "$LOG_FILE" ]; then
        log_size=$(du -h "$LOG_FILE" | awk '{print $1}')
        printf "${GREEN}✓ Log file exists: $log_size${NC}\n"
    else
        printf "${YELLOW}⚠ Log file not found${NC}\n"
    fi
    
    # Check state file
    if [ -f "$STATE_FILE" ]; then
        state_size=$(du -h "$STATE_FILE" | awk '{print $1}')
        device_count=$(wc -l < "$STATE_FILE" 2>/dev/null || echo "0")
        printf "${GREEN}✓ State file exists: $state_size ($device_count devices)${NC}\n"
    else
        printf "${YELLOW}⚠ State file not found${NC}\n"
    fi
    
    # Check cron job
    if crontab -l 2>/dev/null | grep -q "parental_control"; then
        printf "${GREEN}✓ Cron job configured${NC}\n"
    else
        printf "${RED}✗ Cron job NOT configured${NC}\n"
    fi
}

# Show help
show_help() {
    printf "${BLUE}Parental Control Log Analyzer${NC}\n"
    echo ""
    echo "Usage: $0 [command] [options]"
    echo ""
    echo "Commands:"
    printf "  ${GREEN}%-20s${NC} %s\n" "stats" "Show usage statistics (current log only)"
    printf "  ${GREEN}%-20s${NC} %s\n" "stats-all" "Show usage statistics (all logs)"
    printf "  ${GREEN}%-20s${NC} %s\n" "logs" "List all log files (current + rotated)"
    printf "  ${GREEN}%-20s${NC} %s\n" "recent [N]" "Show recent activity (last N entries, default 20)"
    printf "  ${GREEN}%-20s${NC} %s\n" "device <mac>" "Show activity for specific device"
    printf "  ${GREEN}%-20s${NC} %s\n" "errors" "Show errors and warnings"
    printf "  ${GREEN}%-20s${NC} %s\n" "watch" "Watch logs in real-time"
    printf "  ${GREEN}%-20s${NC} %s\n" "state" "Show current state file"
    printf "  ${GREEN}%-20s${NC} %s\n" "status" "Show system status"
    printf "  ${GREEN}%-20s${NC} %s\n" "reset" "Diagnose and force reset counters"
    printf "  ${GREEN}%-20s${NC} %s\n" "verify" "Verify all package files are present"
    printf "  ${GREEN}%-20s${NC} %s\n" "help" "Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 stats                      # Show statistics from current log"
    echo "  $0 stats-all                  # Show statistics from all logs"
    echo "  $0 recent 50                  # Show last 50 entries"
    echo "  $0 device aa:bb:cc:dd:ee:ff  # Show activity for device"
    echo "  $0 watch                      # Watch logs in real-time"
    echo "  $0 reset                      # Run reset diagnostic and force reset"
    echo "  $0 verify                     # Verify installation"
    echo ""
    echo "Files:"
    echo "  Current Log: $LOG_FILE"
    echo "  Rotated Logs: $LOG_DIR/parental_control_*.jsonl"
    echo "  State File: $STATE_FILE"
    echo "  PID File: $PID_FILE"
    echo ""
}

# Run reset diagnostic and force reset
run_reset_diagnostic() {
    print_header
    printf "${GREEN}=== PARENTAL CONTROL RESET DIAGNOSTIC ===${NC}\n"
    echo ""
    
    print_color "$BLUE" "=== 1. Current System Time ==="
    date
    echo ""
    
    print_color "$BLUE" "=== 2. State File: Last Reset Time ==="
    grep -E '"last_reset"|"last_check"' "$STATE_FILE" 2>/dev/null | head -5 || echo "State file not found"
    echo ""
    
    print_color "$BLUE" "=== 3. Profile Usage (Current Values) ==="
    grep -A5 '"profiles"' "$STATE_FILE" 2>/dev/null | head -50 || echo "No profiles found"
    echo ""
    
    print_color "$BLUE" "=== 4. Recent Reset Log Entries ==="
    grep -i "reset" /var/log/system.log 2>/dev/null | grep -i "parental" | tail -10 || echo "No reset entries found"
    echo ""
    
    print_color "$BLUE" "=== 5. Cron Job Execution History ==="
    grep "parental_control_cron" /var/log/system.log 2>/dev/null | tail -10 || echo "No cron execution logs"
    echo ""
    
    print_color "$BLUE" "=== 6. Check Reset Logic ==="
    php -r "
    require_once('/etc/inc/config.inc');
    require_once('/usr/local/pkg/parental_control.inc');
    
    \$state = pc_load_state();
    \$reset_time_config = config_get_path('installedpackages/parentalcontrol/reset_time', 'midnight');
    \$last_reset = isset(\$state['last_reset']) ? \$state['last_reset'] : 0;
    
    echo \"Reset Time Config: \$reset_time_config\n\";
    echo \"Last Reset Timestamp: \$last_reset (\" . date('Y-m-d H:i:s', \$last_reset) . \")\n\";
    echo \"Current Time: \" . time() . \" (\" . date('Y-m-d H:i:s') . \")\n\";
    echo \"Hours Since Reset: \" . round((time() - \$last_reset) / 3600, 2) . \" hours\n\";
    echo \"\n\";
    
    echo \"Should Reset? \";
    if (pc_should_reset_counters(\$last_reset, \$reset_time_config)) {
        echo \"YES - Reset is DUE!\n\";
    } else {
        echo \"NO - Reset not needed yet\n\";
    }
    
    echo \"\n=== Profile Counters ===\n\";
    if (isset(\$state['profiles'])) {
        foreach (\$state['profiles'] as \$name => \$data) {
            \$usage = isset(\$data['usage_today']) ? \$data['usage_today'] : 0;
            echo \"Profile: \$name\n\";
            echo \"  Usage Today: \$usage minutes (\" . floor(\$usage/60) . \"h \" . (\$usage%60) . \"m)\n\";
            echo \"  Usage Week: \" . (isset(\$data['usage_week']) ? \$data['usage_week'] : 0) . \" minutes\n\";
        }
    }
    " 2>/dev/null || print_color "$RED" "Error checking reset logic"
    echo ""
    
    print_color "$BLUE" "=== 7. Force Manual Reset NOW ==="
    read -p "Do you want to force reset counters now? (y/N) " -r
    echo
    if [ "$REPLY" = "y" ] || [ "$REPLY" = "Y" ]; then
        php -r "
        require_once('/etc/inc/config.inc');
        require_once('/usr/local/pkg/parental_control.inc');
        
        echo \"Loading state...\n\";
        \$state = pc_load_state();
        
        echo \"\n--- BEFORE RESET ---\n\";
        if (isset(\$state['profiles'])) {
            foreach (\$state['profiles'] as \$name => \$data) {
                \$usage = isset(\$data['usage_today']) ? \$data['usage_today'] : 0;
                echo \"\$name: \$usage minutes\n\";
            }
        }
        
        echo \"\nExecuting reset...\n\";
        pc_reset_daily_counters(\$state);
        \$state['last_reset'] = time();
        pc_save_state(\$state);
        
        echo \"Reloading state...\n\";
        \$state = pc_load_state();
        
        echo \"\n--- AFTER RESET ---\n\";
        if (isset(\$state['profiles'])) {
            foreach (\$state['profiles'] as \$name => \$data) {
                \$usage = isset(\$data['usage_today']) ? \$data['usage_today'] : 0;
                echo \"\$name: \$usage minutes\n\";
            }
        }
        
        echo \"\n✓ Reset completed at \" . date('Y-m-d H:i:s') . \"\n\";
        " 2>/dev/null || print_color "$RED" "Error performing reset"
    else
        print_color "$YELLOW" "Reset cancelled"
    fi
    echo ""
    
    print_color "$GREEN" "=== 8. Final Verification ==="
    grep -A5 '"profiles"' "$STATE_FILE" 2>/dev/null | head -30
    echo ""
    
    print_color "$GREEN" "DIAGNOSTIC COMPLETE"
}

# Verify installation files
verify_installation() {
    print_header
    printf "${GREEN}=== PARENTAL CONTROL FILE VERIFICATION ===${NC}\n"
    echo ""
    
    MISSING=0
    TOTAL=0
    
    check_file() {
        FILE=$1
        TOTAL=$((TOTAL + 1))
        
        if [ -f "$FILE" ]; then
            printf "${GREEN}✓${NC} Found: $FILE\n"
        else
            printf "${RED}✗${NC} Missing: $FILE\n"
            MISSING=$((MISSING + 1))
        fi
    }
    
    check_executable() {
        FILE=$1
        TOTAL=$((TOTAL + 1))
        
        if [ -f "$FILE" ]; then
            if [ -x "$FILE" ]; then
                printf "${GREEN}✓${NC} Executable: $FILE\n"
            else
                printf "${YELLOW}⚠${NC} Not executable: $FILE\n"
                MISSING=$((MISSING + 1))
            fi
        else
            printf "${RED}✗${NC} Missing: $FILE\n"
            MISSING=$((MISSING + 1))
        fi
    }
    
    print_color "$BLUE" "1. Checking core package files..."
    check_file "/usr/local/pkg/parental_control.xml"
    check_file "/usr/local/pkg/parental_control.inc"
    check_file "/usr/local/pkg/parental_control_VERSION"
    check_file "/usr/local/share/pfSense-pkg-KACI-Parental_Control/info.xml"
    
    echo ""
    print_color "$BLUE" "2. Checking web interface files..."
    check_file "/usr/local/www/parental_control_status.php"
    check_file "/usr/local/www/parental_control_profiles.php"
    check_file "/usr/local/www/parental_control_schedules.php"
    check_file "/usr/local/www/parental_control_blocked.php"
    check_file "/usr/local/www/parental_control_captive.php"
    check_file "/usr/local/www/parental_control_health.php"
    check_file "/usr/local/www/parental_control_api.php"
    
    echo ""
    print_color "$BLUE" "3. Checking executable scripts..."
    check_executable "/usr/local/etc/rc.d/parental_control_captive"
    check_executable "/usr/local/bin/parental_control_diagnostic.php"
    check_executable "/usr/local/bin/parental_control_analyzer.sh"
    check_executable "/usr/local/bin/auto_update_parental_control.sh"
    
    echo ""
    print_color "$BLUE" "4. Checking cron job..."
    CRON_ENTRIES=$(crontab -l 2>/dev/null | grep -c "parental_control")
    if [ "$CRON_ENTRIES" -gt 0 ]; then
        printf "${GREEN}✓${NC} Found $CRON_ENTRIES parental control cron job(s)\n"
        crontab -l 2>/dev/null | grep "parental_control" | sed 's/^/  /'
    else
        printf "${YELLOW}⚠${NC} No cron jobs found (may not be enabled)\n"
    fi
    
    echo ""
    print_color "$BLUE" "5. Checking package version..."
    if [ -f "$VERSION_FILE" ]; then
        VERSION=$(cat "$VERSION_FILE")
        printf "${GREEN}✓${NC} Installed version: $VERSION\n"
    else
        printf "${RED}✗${NC} Version file not found\n"
    fi
    
    echo ""
    print_color "$BLUE" "6. Checking configuration..."
    CONFIG_CHECK=$(php -r "
    require_once('/etc/inc/config.inc');
    \$pc_config = config_get_path('installedpackages/parentalcontrol');
    if (is_array(\$pc_config)) {
        echo 'FOUND';
    } else {
        echo 'MISSING';
    }
    " 2>/dev/null)
    
    if [ "$CONFIG_CHECK" = "FOUND" ]; then
        printf "${GREEN}✓${NC} Configuration exists in config.xml\n"
    else
        printf "${YELLOW}⚠${NC} No configuration found (package may not be initialized)\n"
    fi
    
    echo ""
    print_color "$BLUE" "7. Checking firewall aliases..."
    ALIAS_CHECK=$(php -r "
    require_once('/etc/inc/config.inc');
    \$aliases = config_get_path('aliases/alias', []);
    \$found = [];
    foreach (\$aliases as \$alias) {
        if (in_array(\$alias['name'], ['parental_control_blocked', 'KACI_PC_Ports', 'KACI_PC_Web'])) {
            \$found[] = \$alias['name'];
        }
    }
    echo implode(',', \$found);
    " 2>/dev/null)
    
    if [ -n "$ALIAS_CHECK" ]; then
        printf "${GREEN}✓${NC} Found aliases: $ALIAS_CHECK\n"
    else
        printf "${YELLOW}⚠${NC} No parental control aliases found (created on first sync)\n"
    fi
    
    echo ""
    print_color "$BLUE" "8. Checking firewall rules..."
    RULE_CHECK=$(php -r "
    require_once('/etc/inc/config.inc');
    \$rules = config_get_path('filter/rule', []);
    \$count = 0;
    foreach (\$rules as \$rule) {
        if (isset(\$rule['descr']) && strpos(\$rule['descr'], 'Parental Control') !== false) {
            \$count++;
        }
    }
    echo \$count;
    " 2>/dev/null)
    
    if [ "$RULE_CHECK" -gt 0 ]; then
        printf "${GREEN}✓${NC} Found $RULE_CHECK parental control firewall rule(s)\n"
    else
        printf "${YELLOW}⚠${NC} No firewall rules found (created on first sync)\n"
    fi
    
    echo ""
    print_color "$GREEN" "======================================"
    print_color "$GREEN" "Summary"
    print_color "$GREEN" "======================================"
    echo "Total files checked: $TOTAL"
    
    if [ $MISSING -eq 0 ]; then
        printf "${GREEN}✓ All required files present!${NC}\n"
        echo ""
        echo "Installation verified successfully."
        echo ""
        echo "Next steps:"
        echo "  1. Enable package: Services → Keekar's Parental Control"
        echo "  2. Configure profiles: Click 'Profiles' tab"
        echo "  3. Check status: Click 'Status' tab"
        return 0
    else
        printf "${RED}✗ $MISSING file(s) missing or not executable${NC}\n"
        echo ""
        echo "Some files are missing. Reinstall may be required."
        return 1
    fi
}

# Main command dispatcher
case "${1:-help}" in
    stats)
        show_stats
        ;;
    stats-all)
        show_stats_all
        ;;
    logs|list)
        list_logs
        ;;
    recent)
        show_recent "${2:-20}"
        ;;
    device)
        show_device "$2"
        ;;
    errors)
        show_errors
        ;;
    watch)
        watch_logs
        ;;
    state)
        show_state
        ;;
    status)
        show_status
        ;;
    reset)
        run_reset_diagnostic
        ;;
    verify)
        verify_installation
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        printf "${RED}Unknown command: $1${NC}\n"
        echo ""
        show_help
        exit 1
        ;;
esac

