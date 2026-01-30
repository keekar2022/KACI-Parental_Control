#!/bin/sh
#
# Keekar's Parental Control - Auto Update Script (PKG Manager Version)
# Uses FreeBSD pkg manager for updates
# Runs via cron every 15 minutes
#

# CRITICAL FIX v1.4.70: Ignore OS version mismatches globally
# WHY: Packages built for FreeBSD 14 work fine on FreeBSD 15
# SOLUTION: Bypass pkg version checks to prevent blocking auto-updates
export IGNORE_OSVERSION=yes

PACKAGE_NAME="kaci-parental-control"
LOCAL_VERSION_FILE="/usr/local/pkg/parental_control_VERSION"
LOG_FILE="/var/log/parental_control_auto_update.log"

# Logging function - logs to both file and syslog
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
    logger -t "parental_control_auto_update" "$1"
}

# Check if package is installed
if ! pkg info ${PACKAGE_NAME} > /dev/null 2>&1; then
    log "Auto-Update: Package not installed via pkg manager"
    
    # Check if installed via old method (raw files)
    if [ -f "$LOCAL_VERSION_FILE" ]; then
        log "Auto-Update: Detected legacy installation"
        log "Auto-Update: Please run migration: /usr/local/bin/migrate-to-pkg.sh"
        exit 1
    fi
    
    log "Auto-Update: Package not installed, skipping"
    exit 0
fi

log "Auto-Update Check Started"

# Get currently installed version
CURRENT_VERSION=$(pkg info ${PACKAGE_NAME} | grep "Version" | awk '{print $3}')
if [ -z "$CURRENT_VERSION" ]; then
    log "Auto-Update: Could not determine current version"
    exit 1
fi

log "Auto-Update: Current version is $CURRENT_VERSION"

# CRITICAL FIX v1.4.70: Ignore OS version mismatches
# WHY: FreeBSD packages built for v14 work fine on v15, but pkg complains
# PROBLEM: "Newer FreeBSD version" errors block auto-updates for all consumers
# SOLUTION: Set IGNORE_OSVERSION=yes to bypass version checks (safe for parental control)
export IGNORE_OSVERSION=yes

# Update repository metadata quietly
log "Auto-Update: Updating repository metadata..."
if ! pkg update -q 2>&1 | tee -a "$LOG_FILE"; then
    log "Auto-Update: Failed to update repository metadata"
    exit 1
fi

# Check for available updates
AVAILABLE_VERSION=$(pkg rquery "%v" ${PACKAGE_NAME} 2>/dev/null)
if [ -z "$AVAILABLE_VERSION" ]; then
    log "Auto-Update: Could not query available version from repository"
    exit 1
fi

log "Auto-Update: Available version is $AVAILABLE_VERSION"

# Compare versions
if [ "$CURRENT_VERSION" = "$AVAILABLE_VERSION" ]; then
    log "Auto-Update: Already up to date (v$CURRENT_VERSION)"
    exit 0
fi

log "Auto-Update: New version available: $CURRENT_VERSION -> $AVAILABLE_VERSION"
log "Auto-Update: Starting package upgrade..."

# Perform upgrade (IGNORE_OSVERSION already set above)
# This allows packages built for FreeBSD 14 to install on FreeBSD 15, etc.
if pkg upgrade -y ${PACKAGE_NAME} 2>&1 | tee -a "$LOG_FILE"; then
    NEW_VERSION=$(pkg info ${PACKAGE_NAME} | grep "Version" | awk '{print $3}')
    log "Auto-Update: SUCCESS! Upgraded to v$NEW_VERSION"
    
    # Reload pfSense configuration
    log "Auto-Update: Reloading pfSense configuration..."
    /usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); parental_control_sync();" 2>&1 | tee -a "$LOG_FILE"
    
    log "Auto-Update: Update complete"
else
    log "Auto-Update: ERROR - Upgrade failed"
    exit 1
fi
