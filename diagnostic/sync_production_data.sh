#!/bin/sh
#
# sync_production_data.sh
# Syncs KACI Parental Control data from production to test firewall
# Runs DIRECTLY ON TEST FIREWALL (192.168.1.251)
#
# Production: 192.168.1.1 (source)
# Test:       192.168.1.251 (destination - runs this script)
#
# This script syncs:
#   - Parental Control state file (usage data, blocked devices)
#   - Parental Control logs
#   - Parental Control configuration (profiles, schedules, services, devices)
#   - Service aliases (URL tables)
#   - Does NOT sync: interface configs, NAT rules, general firewall rules
#
# Usage:
#   Run on test firewall: ./sync_production_data.sh
#   Or with cron:         */30 * * * * /home/admin/sync_production_data.sh
#

set -e

# ============================================================================
# CONFIGURATION
# ============================================================================

PROD_HOST="192.168.1.1"
USER="admin"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOCK_FILE="/tmp/kaci_sync.lock"
LOG_FILE="/var/log/kaci_sync.log"
TEMP_DIR="/tmp/kaci_sync_data"
SYNC_SUMMARY="/tmp/kaci_sync_summary.txt"

# Files/dirs to sync
STATE_FILE="/var/db/parental_control_state.json"
LOG_DIR_OLD="/var/log/parental_control"
LOG_FILE_MAIN="/var/log/parental_control.jsonl"

# ============================================================================
# FUNCTIONS
# ============================================================================

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $*" | tee -a "$LOG_FILE" >&2
}

setup_ssh_keys() {
    log "==================================================================="
    log "  SSH KEY SETUP - First Time Configuration"
    log "==================================================================="
    log ""
    
    # Check if we already have a key
    if [ ! -f ~/.ssh/id_rsa ]; then
        log "Generating SSH key pair..."
        ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa -N "" -C "kaci-sync@test-firewall"
        log "✓ SSH key generated"
    else
        log "✓ SSH key already exists"
    fi
    
    # Copy key to production
    log ""
    log "Setting up passwordless SSH to PRODUCTION ($PROD_HOST)..."
    log "You will be prompted for the password for ${USER}@${PROD_HOST}"
    log ""
    
    if ! ssh -o BatchMode=yes -o ConnectTimeout=5 ${USER}@${PROD_HOST} "echo test" >/dev/null 2>&1; then
        ssh-copy-id -i ~/.ssh/id_rsa.pub ${USER}@${PROD_HOST} || {
            log "Trying manual method..."
            cat ~/.ssh/id_rsa.pub | ssh ${USER}@${PROD_HOST} "mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
        }
        log "✓ SSH key copied to production"
    else
        log "✓ SSH key already configured for production"
    fi
    
    # Test connection
    log ""
    log "Testing passwordless SSH..."
    if ssh -o BatchMode=yes -o ConnectTimeout=5 ${USER}@${PROD_HOST} "echo test" >/dev/null 2>&1; then
        log "✓ Passwordless SSH to production works!"
    else
        error "Passwordless SSH test failed. Please check configuration."
        exit 1
    fi
    
    log ""
    log "==================================================================="
    log "  SSH Setup Complete!"
    log "==================================================================="
    log ""
}

check_ssh_access() {
    if ! ssh -o BatchMode=yes -o ConnectTimeout=5 ${USER}@${PROD_HOST} "echo test" >/dev/null 2>&1; then
        error "Cannot connect to production via SSH ($PROD_HOST)"
        return 1
    fi
    return 0
}

acquire_lock() {
    if [ -f "$LOCK_FILE" ]; then
        LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
        if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
            log "Another sync is already running (PID: $LOCK_PID), exiting"
            exit 0
        else
            log "Removing stale lock file"
            rm -f "$LOCK_FILE"
        fi
    fi
    echo $$ > "$LOCK_FILE"
}

release_lock() {
    rm -f "$LOCK_FILE"
}

sync_state_file() {
    log "Syncing state file..."
    
    # Fetch state file directly from production
    if scp -q ${USER}@${PROD_HOST}:${STATE_FILE} ${TEMP_DIR}/state.json 2>/dev/null; then
        # Copy to local state file location
        mkdir -p /var/db
        cp ${TEMP_DIR}/state.json ${STATE_FILE}
        chmod 644 ${STATE_FILE}
        
        SIZE=$(stat -f%z ${STATE_FILE} 2>/dev/null || stat -c%s ${STATE_FILE} 2>/dev/null || echo "0")
        log "✓ State file synced ($SIZE bytes)"
        echo "State file: $SIZE bytes" >> "$SYNC_SUMMARY"
        return 0
    else
        log "⚠ State file not found on production (might be new installation)"
        echo "State file: NOT FOUND" >> "$SYNC_SUMMARY"
        return 1
    fi
}

sync_logs() {
    log "Syncing log files..."
    
    LOGS_SYNCED=0
    TOTAL_LINES=0
    
    # Sync dated JSONL log files (parental_control-YYYY-MM-DD.jsonl)
    # Get list of dated log files from last 7 days
    DATED_LOGS=$(ssh ${USER}@${PROD_HOST} "ls -t /var/log/parental_control-*.jsonl 2>/dev/null | head -7 || true")
    
    if [ -n "$DATED_LOGS" ]; then
        log "  Found dated log files on production"
        for LOGFILE in $DATED_LOGS; do
            BASENAME=$(basename "$LOGFILE")
            log "  - Syncing $BASENAME (last 5000 lines)..."
            
            # Copy the entire file (or last 5000 lines if very large)
            ssh ${USER}@${PROD_HOST} "tail -5000 $LOGFILE" > ${TEMP_DIR}/${BASENAME} 2>/dev/null || true
            
            if [ -f ${TEMP_DIR}/${BASENAME} ]; then
                mkdir -p /var/log
                cp ${TEMP_DIR}/${BASENAME} /var/log/${BASENAME}
                chmod 644 /var/log/${BASENAME}
                LINES=$(wc -l < /var/log/${BASENAME} 2>/dev/null || echo "0")
                log "  ✓ $BASENAME synced ($LINES lines)"
                LOGS_SYNCED=$((LOGS_SYNCED + 1))
                TOTAL_LINES=$((TOTAL_LINES + LINES))
            fi
        done
    fi
    
    # Also check for old non-dated format (backwards compatibility)
    if ssh ${USER}@${PROD_HOST} "test -f ${LOG_FILE_MAIN}" 2>/dev/null; then
        log "  - Syncing legacy log file (last 5000 lines)..."
        ssh ${USER}@${PROD_HOST} "tail -5000 ${LOG_FILE_MAIN}" > ${TEMP_DIR}/parental_control.jsonl 2>/dev/null || true
        
        if [ -f ${TEMP_DIR}/parental_control.jsonl ]; then
            cp ${TEMP_DIR}/parental_control.jsonl ${LOG_FILE_MAIN}
            chmod 644 ${LOG_FILE_MAIN}
            LINES=$(wc -l < ${LOG_FILE_MAIN})
            log "  ✓ Legacy log synced ($LINES lines)"
            LOGS_SYNCED=$((LOGS_SYNCED + 1))
            TOTAL_LINES=$((TOTAL_LINES + LINES))
        fi
    fi
    
    # Sync old directory-based logs if they exist
    LOG_FILES=$(ssh ${USER}@${PROD_HOST} "ls ${LOG_DIR_OLD}/*.log 2>/dev/null || true")
    
    if [ -n "$LOG_FILES" ]; then
        mkdir -p ${LOG_DIR_OLD}
        for LOGFILE in $LOG_FILES; do
            BASENAME=$(basename "$LOGFILE")
            log "  - Syncing $BASENAME (last 1000 lines)..."
            
            ssh ${USER}@${PROD_HOST} "tail -1000 $LOGFILE" > ${TEMP_DIR}/${BASENAME} 2>/dev/null || true
            
            if [ -f ${TEMP_DIR}/${BASENAME} ]; then
                cp ${TEMP_DIR}/${BASENAME} ${LOG_DIR_OLD}/${BASENAME}
                chmod 644 ${LOG_DIR_OLD}/${BASENAME}
                log "  ✓ $BASENAME synced"
                LOGS_SYNCED=$((LOGS_SYNCED + 1))
            fi
        done
    fi
    
    if [ $LOGS_SYNCED -eq 0 ]; then
        log "⚠ No log files found on production"
        echo "Logs: NONE FOUND" >> "$SYNC_SUMMARY"
    else
        log "✓ $LOGS_SYNCED log file(s) synced ($TOTAL_LINES total lines)"
        echo "Logs: $LOGS_SYNCED files synced ($TOTAL_LINES lines)" >> "$SYNC_SUMMARY"
    fi
}

sync_config() {
    log "Syncing configuration..."
    
    # Extract parental control config sections from production
    log "  - Extracting profiles..."
    ssh ${USER}@${PROD_HOST} "/usr/local/bin/php -r \"
        require_once('/etc/inc/config.inc');
        \\\$profiles = config_get_path('installedpackages/parentalcontrolprofiles/config', array());
        echo json_encode(\\\$profiles, JSON_PRETTY_PRINT);
    \"" > ${TEMP_DIR}/profiles.json 2>/dev/null || echo "[]" > ${TEMP_DIR}/profiles.json
    
    log "  - Extracting devices..."
    ssh ${USER}@${PROD_HOST} "/usr/local/bin/php -r \"
        require_once('/etc/inc/config.inc');
        \\\$devices = config_get_path('installedpackages/parentalcontroldevices/config', array());
        echo json_encode(\\\$devices, JSON_PRETTY_PRINT);
    \"" > ${TEMP_DIR}/devices.json 2>/dev/null || echo "[]" > ${TEMP_DIR}/devices.json
    
    log "  - Extracting schedules..."
    ssh ${USER}@${PROD_HOST} "/usr/local/bin/php -r \"
        require_once('/etc/inc/config.inc');
        \\\$schedules = config_get_path('installedpackages/parentalcontrolschedules/config', array());
        echo json_encode(\\\$schedules, JSON_PRETTY_PRINT);
    \"" > ${TEMP_DIR}/schedules.json 2>/dev/null || echo "[]" > ${TEMP_DIR}/schedules.json
    
    log "  - Extracting services..."
    ssh ${USER}@${PROD_HOST} "/usr/local/bin/php -r \"
        require_once('/etc/inc/config.inc');
        \\\$services = config_get_path('installedpackages/parentalcontrolservices/config', array());
        echo json_encode(\\\$services, JSON_PRETTY_PRINT);
    \"" > ${TEMP_DIR}/services.json 2>/dev/null || echo "[]" > ${TEMP_DIR}/services.json
    
    log "  - Extracting main settings..."
    ssh ${USER}@${PROD_HOST} "/usr/local/bin/php -r \"
        require_once('/etc/inc/config.inc');
        \\\$settings = config_get_path('installedpackages/parentalcontrol/config/0', array());
        echo json_encode(\\\$settings, JSON_PRETTY_PRINT);
    \"" > ${TEMP_DIR}/settings.json 2>/dev/null || echo "{}" > ${TEMP_DIR}/settings.json
    
    log "  - Extracting service aliases..."
    ssh ${USER}@${PROD_HOST} "/usr/local/bin/php -r \"
        require_once('/etc/inc/config.inc');
        \\\$aliases = config_get_path('aliases/alias', array());
        \\\$service_aliases = array();
        foreach (\\\$aliases as \\\$alias) {
            if (isset(\\\$alias['name']) && strpos(\\\$alias['name'], 'PC_Service_') === 0) {
                \\\$service_aliases[] = \\\$alias;
            }
        }
        echo json_encode(\\\$service_aliases, JSON_PRETTY_PRINT);
    \"" > ${TEMP_DIR}/service_aliases.json 2>/dev/null || echo "[]" > ${TEMP_DIR}/service_aliases.json
    
    # Import configs on test system
    log "  - Importing to test system..."
    /usr/local/bin/php << 'EOPHP'
<?php
require_once("/etc/inc/config.inc");

$temp_dir = '/tmp/kaci_sync_data';
$imported = array();

// Import profiles
$profiles_json = @file_get_contents($temp_dir . '/profiles.json');
if ($profiles_json) {
    $profiles = json_decode($profiles_json, true);
    if (is_array($profiles) && !empty($profiles)) {
        config_set_path('installedpackages/parentalcontrolprofiles/config', $profiles);
        $imported[] = "Profiles (" . count($profiles) . ")";
    }
}

// Import devices
$devices_json = @file_get_contents($temp_dir . '/devices.json');
if ($devices_json) {
    $devices = json_decode($devices_json, true);
    if (is_array($devices) && !empty($devices)) {
        config_set_path('installedpackages/parentalcontroldevices/config', $devices);
        $imported[] = "Devices (" . count($devices) . ")";
    }
}

// Import schedules
$schedules_json = @file_get_contents($temp_dir . '/schedules.json');
if ($schedules_json) {
    $schedules = json_decode($schedules_json, true);
    if (is_array($schedules) && !empty($schedules)) {
        config_set_path('installedpackages/parentalcontrolschedules/config', $schedules);
        $imported[] = "Schedules (" . count($schedules) . ")";
    }
}

// Import services
$services_json = @file_get_contents($temp_dir . '/services.json');
if ($services_json) {
    $services = json_decode($services_json, true);
    if (is_array($services) && !empty($services)) {
        // Convert to numeric array if needed
        $services = array_values($services);
        config_set_path('installedpackages/parentalcontrolservices/config', $services);
        $imported[] = "Services (" . count($services) . ")";
    }
}

// Import settings
$settings_json = @file_get_contents($temp_dir . '/settings.json');
if ($settings_json) {
    $settings = json_decode($settings_json, true);
    if (is_array($settings) && !empty($settings)) {
        config_set_path('installedpackages/parentalcontrol/config/0', $settings);
        $imported[] = "Settings";
    }
}

// Import service aliases
$service_aliases_json = @file_get_contents($temp_dir . '/service_aliases.json');
if ($service_aliases_json) {
    $service_aliases = json_decode($service_aliases_json, true);
    if (is_array($service_aliases) && !empty($service_aliases)) {
        // Get existing aliases
        $existing_aliases = config_get_path('aliases/alias', array());
        
        // Remove old PC_Service_ aliases
        $filtered_aliases = array();
        foreach ($existing_aliases as $alias) {
            if (!isset($alias['name']) || strpos($alias['name'], 'PC_Service_') !== 0) {
                $filtered_aliases[] = $alias;
            }
        }
        
        // Add new service aliases
        foreach ($service_aliases as $alias) {
            $filtered_aliases[] = $alias;
        }
        
        config_set_path('aliases/alias', $filtered_aliases);
        $imported[] = "Service Aliases (" . count($service_aliases) . ")";
    }
}

if (!empty($imported)) {
    write_config("Synced Parental Control config from production: " . implode(", ", $imported));
    echo "✓ Imported: " . implode(", ", $imported) . "\n";
    file_put_contents('/tmp/kaci_sync_summary.txt', "Config: " . implode(", ", $imported) . "\n", FILE_APPEND);
} else {
    echo "⚠ No configuration data to import\n";
    file_put_contents('/tmp/kaci_sync_summary.txt', "Config: NONE\n", FILE_APPEND);
}
?>
EOPHP
    
    log "✓ Configuration synced"
}

sync_alias_tables() {
    log "Syncing URL alias table files..."
    
    # Get list of PC_Service_ aliases
    ALIAS_NAMES=$(ssh ${USER}@${PROD_HOST} "/usr/local/bin/php -r \"
        require_once('/etc/inc/config.inc');
        \\\$aliases = config_get_path('aliases/alias', array());
        foreach (\\\$aliases as \\\$alias) {
            if (isset(\\\$alias['name']) && strpos(\\\$alias['name'], 'PC_Service_') === 0) {
                echo \\\$alias['name'] . '\n';
            }
        }
    \"" 2>/dev/null)
    
    if [ -n "$ALIAS_NAMES" ]; then
        mkdir -p /var/db/aliastables
        TABLES_SYNCED=0
        
        for ALIAS_NAME in $ALIAS_NAMES; do
            TABLE_FILE="/var/db/aliastables/${ALIAS_NAME}.txt"
            
            # Check if table file exists on production
            if ssh ${USER}@${PROD_HOST} "test -f ${TABLE_FILE}" 2>/dev/null; then
                log "  - Syncing ${ALIAS_NAME} table..."
                
                if scp -q ${USER}@${PROD_HOST}:${TABLE_FILE} ${TABLE_FILE} 2>/dev/null; then
                    chmod 644 ${TABLE_FILE}
                    LINES=$(wc -l < ${TABLE_FILE} 2>/dev/null || echo "0")
                    log "  ✓ ${ALIAS_NAME} synced ($LINES IPs)"
                    TABLES_SYNCED=$((TABLES_SYNCED + 1))
                fi
            fi
        done
        
        if [ $TABLES_SYNCED -gt 0 ]; then
            log "✓ $TABLES_SYNCED alias table(s) synced"
            echo "Alias Tables: $TABLES_SYNCED files synced" >> "$SYNC_SUMMARY"
        else
            log "⚠ No alias table files found on production"
            echo "Alias Tables: NONE FOUND" >> "$SYNC_SUMMARY"
        fi
    else
        log "⚠ No service aliases found on production"
        echo "Alias Tables: NO ALIASES" >> "$SYNC_SUMMARY"
    fi
}

display_summary() {
    log ""
    log "==================================================================="
    log "  SYNC SUMMARY"
    log "==================================================================="
    
    if [ -f "$SYNC_SUMMARY" ]; then
        cat "$SYNC_SUMMARY" | while read line; do
            log "  $line"
        done
    fi
    
    log "==================================================================="
}

# ============================================================================
# MAIN
# ============================================================================

main() {
    # Initialize summary file
    echo "=== KACI Parental Control Data Sync ===" > "$SYNC_SUMMARY"
    echo "From: $PROD_HOST" >> "$SYNC_SUMMARY"
    echo "Date: $(date '+%Y-%m-%d %H:%M:%S')" >> "$SYNC_SUMMARY"
    echo "" >> "$SYNC_SUMMARY"
    
    log "==================================================================="
    log "  KACI Parental Control - Production Data Sync"
    log "  Production: $PROD_HOST → Test: $(hostname)"
    log "==================================================================="
    
    # Check if this is first run (no SSH keys set up)
    if ! check_ssh_access 2>/dev/null; then
        log "SSH keys not configured. Running first-time setup..."
        setup_ssh_keys
    fi
    
    # Acquire lock to prevent concurrent runs
    acquire_lock
    trap release_lock EXIT INT TERM
    
    # Create temp directory
    mkdir -p "$TEMP_DIR"
    
    # Perform sync
    log ""
    log "Starting sync at $(date)"
    log "-------------------------------------------------------------------"
    
    sync_state_file
    log ""
    
    sync_logs
    log ""
    
    sync_config
    log ""
    
    sync_alias_tables
    log ""
    
    # Cleanup
    rm -rf "$TEMP_DIR"
    
    log "-------------------------------------------------------------------"
    
    display_summary
    
    log ""
    log "✓ Sync completed successfully at $(date)"
    log "==================================================================="
    log ""
    
    echo ""
    echo "📋 To view full sync log: tail -50 $LOG_FILE"
    echo "📊 To view sync summary: cat $SYNC_SUMMARY"
    echo ""
}

# Run main function
main "$@"
