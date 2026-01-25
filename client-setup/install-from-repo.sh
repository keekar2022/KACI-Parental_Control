#!/bin/sh
#
# KACI Parental Control - Client Installation Script
# Installs from custom pkg repository
#
# Usage: ./install-from-repo.sh
#

set -e

REPO_URL="https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd"
REPO_NAME="kaci"
PACKAGE_NAME="kaci-parental-control"

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

# Check if running on pfSense/FreeBSD
if [ "$(uname -s)" != "FreeBSD" ]; then
    log_error "This script must be run on FreeBSD/pfSense"
    exit 1
fi

log_info "Installing Keekar's Parental Control from custom repository..."

# Step 1: Create repository configuration
log_info "Step 1: Configuring custom package repository..."
mkdir -p /usr/local/etc/pkg/repos

cat > /usr/local/etc/pkg/repos/${REPO_NAME}.conf << 'EOF'
kaci: {
  url: "https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}/latest",
  mirror_type: "NONE",
  signature_type: "fingerprints",
  fingerprints: "/usr/local/etc/pkg/fingerprints/kaci",
  enabled: yes,
  priority: 10
}
EOF

log_success "Repository configured at /usr/local/etc/pkg/repos/${REPO_NAME}.conf"

# Step 2: Setup GPG fingerprints (if needed)
log_info "Step 2: Setting up GPG fingerprints..."
mkdir -p /usr/local/etc/pkg/fingerprints/${REPO_NAME}

# Fetch GPG fingerprint from repository
log_info "Fetching GPG fingerprint from repository..."
fetch -qo - "${REPO_URL}/fingerprint.txt" > /usr/local/etc/pkg/fingerprints/${REPO_NAME}/trusted 2>/dev/null || {
    log_warning "Could not fetch GPG fingerprint, using unsigned packages"
    cat > /usr/local/etc/pkg/repos/${REPO_NAME}.conf << 'EOF'
kaci: {
  url: "https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}/latest",
  mirror_type: "NONE",
  signature_type: "none",
  enabled: yes,
  priority: 10
}
EOF
}

log_success "GPG fingerprints configured"

# Step 3: Update package repository
log_info "Step 3: Updating package repository..."
pkg update || {
    log_error "Failed to update package repository"
    exit 1
}

log_success "Package repository updated"

# Step 4: Install package
log_info "Step 4: Installing ${PACKAGE_NAME}..."
pkg install -y ${PACKAGE_NAME} || {
    log_error "Failed to install ${PACKAGE_NAME}"
    log_info "Trying to fetch package info..."
    pkg search ${PACKAGE_NAME} || true
    exit 1
}

log_success "Package installed successfully!"

# Step 5: Verify installation
log_info "Step 5: Verifying installation..."
if pkg info ${PACKAGE_NAME} > /dev/null 2>&1; then
    VERSION=$(pkg info ${PACKAGE_NAME} | grep "Version" | awk '{print $3}')
    log_success "KACI Parental Control v${VERSION} is installed"
else
    log_error "Installation verification failed"
    exit 1
fi

# Step 6: Setup cron job
log_info "Step 6: Setting up cron job..."
CRON_CMD="/usr/local/bin/php /usr/local/bin/parental_control_cron.php"
if ! crontab -l -u root 2>/dev/null | grep -q "parental_control_cron"; then
    (crontab -l -u root 2>/dev/null; echo "*/5 * * * * $CRON_CMD") | crontab -u root -
    log_success "Cron job installed"
else
    log_info "Cron job already exists"
fi

# Final message
echo ""
echo "=================================================================="
echo "${GREEN}Keekar's Parental Control installed successfully!${NC}"
echo "=================================================================="
echo ""
echo "Next steps:"
echo "  1. Open pfSense web interface"
echo "  2. Go to Services > Parental Control"
echo "  3. Enable the service and configure profiles"
echo ""
echo "Documentation: https://github.com/keekar2022/KACI-Parental_Control"
echo "Support: https://github.com/keekar2022/KACI-Parental_Control/issues"
echo ""
echo "=================================================================="
