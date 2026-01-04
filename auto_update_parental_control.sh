#!/bin/sh
#
# Keekar's Parental Control - Auto Update Script
# Checks GitHub for updates and deploys automatically
# Runs via cron every 15 minutes
#

GITHUB_RAW_URL="https://raw.githubusercontent.com/keekar2022/KACI-Parental_Control/main"
GITHUB_API_URL="https://api.github.com/repos/keekar2022/KACI-Parental_Control/commits/main"
LOCAL_VERSION_FILE="/usr/local/pkg/parental_control_VERSION"
LOG_FILE="/var/log/parental_control_auto_update.log"
STATE_FILE="/var/db/parental_control_auto_update_state"
TMP_DIR="/tmp/parental_control_update_$$"

# Logging function - logs to both file and syslog
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
    logger -t "parental_control_auto_update" "$1"
}

# Check if package is installed
if [ ! -f "$LOCAL_VERSION_FILE" ]; then
    log "Auto-Update: Package not installed, skipping update check"
    exit 0
fi

log "Auto-Update Check Started"

# Get local version
LOCAL_VERSION=$(grep "VERSION=" "$LOCAL_VERSION_FILE" | cut -d= -f2)
if [ -z "$LOCAL_VERSION" ]; then
    log "Auto-Update: Could not read local version, aborting"
    exit 1
fi

log "Auto-Update: Local version is $LOCAL_VERSION"

# Get latest GitHub commit hash
LATEST_COMMIT=$(fetch -qo - "$GITHUB_API_URL" 2>/dev/null | grep -o '"sha":\s*"[^"]*"' | head -1 | cut -d'"' -f4)
if [ -z "$LATEST_COMMIT" ]; then
    log "Auto-Update: Could not fetch latest commit from GitHub (network issue?)"
    exit 1
fi

# Check if we've already processed this commit
if [ -f "$STATE_FILE" ]; then
    LAST_COMMIT=$(cat "$STATE_FILE")
    if [ "$LATEST_COMMIT" = "$LAST_COMMIT" ]; then
        COMMIT_SHORT=$(echo "$LATEST_COMMIT" | cut -c1-8)
        log "Auto-Update: Already up to date (commit: $COMMIT_SHORT)"
        exit 0
    fi
fi

COMMIT_SHORT=$(echo "$LATEST_COMMIT" | cut -c1-8)
log "Auto-Update: New commit detected ($COMMIT_SHORT), checking for updates..."

# Create temporary directory
mkdir -p "$TMP_DIR"
cd "$TMP_DIR" || exit 1

# Download VERSION file from GitHub
if ! fetch -qo VERSION "$GITHUB_RAW_URL/VERSION"; then
    log "Auto-Update: Failed to download VERSION file from GitHub"
    rm -rf "$TMP_DIR"
    exit 1
fi

# Get remote version
REMOTE_VERSION=$(grep "VERSION=" VERSION | cut -d= -f2)
if [ -z "$REMOTE_VERSION" ]; then
    log "Auto-Update: Could not parse remote version"
    rm -rf "$TMP_DIR"
    exit 1
fi

log "Auto-Update: Remote version is $REMOTE_VERSION"

# Compare versions
if [ "$LOCAL_VERSION" = "$REMOTE_VERSION" ]; then
    log "Auto-Update: Already up-to-date at version $LOCAL_VERSION (commit: $COMMIT_SHORT) - No update needed"
    # Update state to prevent rechecking same commit
    echo "$LATEST_COMMIT" > "$STATE_FILE"
    rm -rf "$TMP_DIR"
    exit 0
fi

log "Auto-Update: Update available! $LOCAL_VERSION -> $REMOTE_VERSION"
log "Auto-Update: Downloading update files..."

# List of files to download
FILES="
parental_control.inc
parental_control.xml
parental_control_profiles.php
parental_control_schedules.php
parental_control_status.php
parental_control_blocked.php
parental_control_captive.php
parental_control_captive.sh
parental_control_api.php
parental_control_health.php
parental_control_diagnostic.php
parental_control_analyzer.sh
info.xml
VERSION
"

# Download each file
DOWNLOAD_SUCCESS=true
for file in $FILES; do
    if ! fetch -qo "$file" "$GITHUB_RAW_URL/$file"; then
        log "Auto-Update: WARNING - Failed to download $file"
        DOWNLOAD_SUCCESS=false
    fi
done

if [ "$DOWNLOAD_SUCCESS" = "false" ]; then
    log "Auto-Update: Some files failed to download, aborting update"
    rm -rf "$TMP_DIR"
    exit 1
fi

log "Auto-Update: All files downloaded successfully"
log "Auto-Update: Deploying update..."

# Backup current installation
BACKUP_DIR="/var/backups/parental_control_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
cp /usr/local/pkg/parental_control.inc "$BACKUP_DIR/" 2>/dev/null
cp /usr/local/pkg/parental_control.xml "$BACKUP_DIR/" 2>/dev/null
cp /usr/local/pkg/parental_control_VERSION "$BACKUP_DIR/" 2>/dev/null
log "Auto-Update: Backup created at $BACKUP_DIR"

# Deploy files
# Create directories if they don't exist (match INSTALL.sh directory structure)
mkdir -p /usr/local/share/pfSense-pkg-KACI-Parental_Control

cp parental_control.inc /usr/local/pkg/
cp parental_control.xml /usr/local/pkg/
cp info.xml /usr/local/share/pfSense-pkg-KACI-Parental_Control/
cp VERSION /usr/local/pkg/parental_control_VERSION

cp parental_control_profiles.php /usr/local/www/
cp parental_control_schedules.php /usr/local/www/
cp parental_control_status.php /usr/local/www/
cp parental_control_blocked.php /usr/local/www/
cp parental_control_captive.php /usr/local/www/
cp parental_control_api.php /usr/local/www/
cp parental_control_health.php /usr/local/www/

cp parental_control_diagnostic.php /usr/local/bin/
cp parental_control_analyzer.sh /usr/local/bin/
cp parental_control_captive.sh /usr/local/etc/rc.d/parental_control_captive

# Set permissions
chmod 755 /usr/local/pkg/parental_control.inc
chmod 644 /usr/local/pkg/parental_control.xml
chmod 644 /usr/local/www/parental_control_*.php
chmod 755 /usr/local/bin/parental_control_diagnostic.php
chmod 755 /usr/local/bin/parental_control_analyzer.sh
chmod 755 /usr/local/etc/rc.d/parental_control_captive

log "Auto-Update: Files deployed successfully"

# Reload package configuration (minimal impact)
log "Auto-Update: Reloading package configuration..."
/usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); parental_control_sync();" 2>&1 | while read line; do
    log "Auto-Update: $line"
done

# Update state file
echo "$LATEST_COMMIT" > "$STATE_FILE"

# Cleanup
rm -rf "$TMP_DIR"

log "Auto-Update: Update completed successfully! $LOCAL_VERSION -> $REMOTE_VERSION"
log "Auto-Update: System is now running version $REMOTE_VERSION"
log "Auto-Update Check Completed"

exit 0

