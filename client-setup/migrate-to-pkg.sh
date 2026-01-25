#!/bin/sh
#
# KACI Parental Control - Migration Script
# Migrates from legacy raw file installation to pkg manager
#

set -e

PACKAGE_NAME="kaci-parental-control"
OLD_CRON_CMD="/usr/local/bin/php /usr/local/bin/parental_control_cron.php"
REPO_URL="https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd"

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    echo "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo "${RED}[ERROR]${NC} $1"
}

echo "=================================================================="
echo "${BLUE}KACI Parental Control - Migration to PKG Manager${NC}"
echo "=================================================================="
echo ""

# Check if legacy installation exists
if [ ! -f "/usr/local/pkg/parental_control_VERSION" ]; then
    log_error "No legacy installation found"
    exit 1
fi

CURRENT_VERSION=$(grep "VERSION=" /usr/local/pkg/parental_control_VERSION | cut -d= -f2)
log_info "Detected legacy installation: v$CURRENT_VERSION"

# Backup current configuration
log_info "Step 1: Backing up current configuration..."
cp /cf/conf/config.xml /cf/conf/config.xml.pre-pkg-migration
log_success "Configuration backed up to /cf/conf/config.xml.pre-pkg-migration"

# Backup state file
if [ -f "/var/db/parental_control_state.json" ]; then
    cp /var/db/parental_control_state.json /var/db/parental_control_state.json.backup
    log_success "State file backed up"
fi

# Remove old cron job
log_info "Step 2: Removing legacy cron job..."
crontab -l -u root | grep -v "parental_control" | crontab -u root - || true
log_success "Legacy cron job removed"

# Setup pkg repository
log_info "Step 3: Configuring pkg repository..."
mkdir -p /usr/local/etc/pkg/repos
cat > /usr/local/etc/pkg/repos/kaci.conf << 'EOF'
kaci: {
  url: "https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}/latest",
  mirror_type: "NONE",
  signature_type: "fingerprints",
  fingerprints: "/usr/local/etc/pkg/fingerprints/kaci",
  enabled: yes,
  priority: 10
}
EOF
log_success "Repository configured"

# Download GPG fingerprint
log_info "Step 3b: Installing GPG fingerprint for package verification..."
mkdir -p /usr/local/etc/pkg/fingerprints/kaci
fetch -o /usr/local/etc/pkg/fingerprints/kaci/trusted \
  https://keekar2022.github.io/KACI-Parental_Control/fingerprints/kaci/trusted || {
    log_warning "Failed to download GPG fingerprint, continuing without verification"
    # Fallback to no signature verification
    cat > /usr/local/etc/pkg/repos/kaci.conf << 'EOF2'
kaci: {
  url: "https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}/latest",
  mirror_type: "NONE",
  signature_type: "none",
  enabled: yes,
  priority: 10
}
EOF2
}
log_success "GPG fingerprint installed"

# Install package directly from GitHub Pages
log_info "Step 4: Installing package from GitHub Pages..."
# Detect system ABI
ABI=$(pkg config ABI)
PACKAGE_URL="https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}/latest/${PACKAGE_NAME}-1.4.61.pkg"

log_info "Downloading from: $PACKAGE_URL"
env IGNORE_OSVERSION=yes pkg add -f "$PACKAGE_URL" || {
    log_error "Failed to install package"
    log_info "Rolling back..."
    rm /usr/local/etc/pkg/repos/kaci.conf 2>/dev/null || true
    (crontab -l -u root 2>/dev/null; echo "*/5 * * * * $OLD_CRON_CMD") | crontab -u root -
    exit 1
}

PKG_VERSION=$(pkg info ${PACKAGE_NAME} | grep "Version" | awk '{print $3}')
log_success "Package v$PKG_VERSION installed successfully"

# Restore state file if needed
if [ -f "/var/db/parental_control_state.json.backup" ]; then
    log_info "Step 5: Restoring state file..."
    cp /var/db/parental_control_state.json.backup /var/db/parental_control_state.json
    log_success "State file restored"
fi

# Setup new auto-update
log_info "Step 6: Configuring auto-update with pkg manager..."
cp /usr/local/bin/auto_update_parental_control_pkg.sh /usr/local/bin/auto_update_parental_control.sh 2>/dev/null || true
chmod +x /usr/local/bin/auto_update_parental_control.sh 2>/dev/null || true
log_success "Auto-update configured"

# Verify cron job
log_info "Step 7: Verifying cron job..."
if crontab -l -u root 2>/dev/null | grep -q "parental_control_cron"; then
    log_success "Cron job is active"
else
    log_warning "Cron job not found, installing..."
    (crontab -l -u root 2>/dev/null; echo "*/5 * * * * /usr/local/bin/php /usr/local/bin/parental_control_cron.php") | crontab -u root -
    log_success "Cron job installed"
fi

# Reload configuration
log_info "Step 8: Reloading pfSense configuration..."
/usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); parental_control_sync();" 2>&1
log_success "Configuration reloaded"

echo ""
echo "=================================================================="
echo "${GREEN}Migration completed successfully!${NC}"
echo "=================================================================="
echo ""
echo "Summary:"
echo "  - Legacy version: v$CURRENT_VERSION"
echo "  - New version: v$PKG_VERSION"
echo "  - Installation method: FreeBSD pkg manager"
echo "  - Auto-update: Enabled (checks every 15 minutes)"
echo ""
echo "Backups created:"
echo "  - /cf/conf/config.xml.pre-pkg-migration"
echo "  - /var/db/parental_control_state.json.backup"
echo ""
echo "You can now use standard pkg commands:"
echo "  pkg info ${PACKAGE_NAME}    - Show package info"
echo "  pkg upgrade ${PACKAGE_NAME} - Upgrade to latest"
echo "  pkg delete ${PACKAGE_NAME}  - Uninstall package"
echo ""
echo "=================================================================="
