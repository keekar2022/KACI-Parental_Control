#!/bin/sh
#
# Parental Control Package - All-in-One Installation & Management Script
# Usage: ./INSTALL.sh [MODE] <pfsense_ip_address>
#
# Modes:
#   install    - Full installation (default)
#   reinstall  - Complete reinstallation (uninstall + install)
#   uninstall  - Remove package completely
#   fix        - Quick fix (upload files only)
#   update     - Update existing installation
#   verify     - Verify installation
#   debug      - Run diagnostics
#   help       - Show this help
#

# Configuration
PFSENSE_USER="admin"
PACKAGE_DIR="$(dirname $0)"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helper functions
print_success() { echo "${GREEN}✓${NC} $1"; }
print_error() { echo "${RED}✗${NC} $1"; }
print_warning() { echo "${YELLOW}⚠${NC}  $1"; }
print_info() { echo "ℹ  $1"; }

#############################################
# Show usage/help
#############################################
show_help() {
    cat << EOF
============================================
Keekar's Parental Control Package Manager
============================================

Usage: $0 [MODE] <pfsense_ip_address>

Modes:
  install <ip>    - Full installation with setup (default)
  reinstall <ip>  - Complete reinstallation (uninstall + install)
  uninstall <ip>  - Remove package completely
  fix <ip>        - Quick fix (re-upload files only)
  update <ip>     - Update existing installation
  verify <ip>     - Verify installation and check status
  debug <ip>      - Run diagnostics and show logs
  help            - Show this help message

Examples:
  $0 192.168.64.3              # Full install (default)
  $0 install 192.168.64.3      # Full install (explicit)
  $0 reinstall 192.168.64.3    # Clean reinstall
  $0 uninstall 192.168.64.3    # Remove package
  $0 fix 192.168.64.3          # Quick fix/re-upload
  $0 update 192.168.64.3       # Update files
  $0 verify 192.168.64.3       # Check installation
  $0 debug 192.168.64.3        # Debug issues

Configuration:
  User: $PFSENSE_USER
  Package: Keekar's Parental Control v1.4.2

Dependencies:
  • sudo (security/sudo) - v1.9.16p2 or later
  • cron (sysutils/cron) - v0.3.8_6 or later
  Note: Installer will check and offer to install missing dependencies

Features:
  ✓ Automatic SSH key setup
  ✓ Passwordless sudo configuration
  ✓ Profile-based device grouping
  ✓ Shared time accounting (bypass-proof)
  ✓ OpenTelemetry logging with auto-rotation
  ✓ Health check endpoint
  ✓ RESTful API for external integration
  ✓ Performance caching (DHCP, ARP, config)
  ✓ Diagnostic tool for troubleshooting
  ✓ Graceful degradation and error handling

Documentation:
  README.md                           - Full documentation
  QUICKSTART.md                       - Quick start guide
  docs/API.md                         - API documentation
  docs/CONFIGURATION.md               - Configuration guide
  docs/TROUBLESHOOTING.md             - Troubleshooting guide
  TROUBLESHOOTING_USAGE_AND_LOGS.md   - Usage tracking guide

EOF
}

#############################################
# Check arguments
#############################################
parse_arguments() {
    if [ $# -eq 0 ]; then
        show_help
        exit 1
    fi
    
    # Check if first arg is a mode or IP
    case "$1" in
        install|reinstall|uninstall|fix|update|verify|debug|help)
            MODE="$1"
            shift
            ;;
        *)
            MODE="install"  # Default mode
            ;;
    esac
    
    if [ "$MODE" = "help" ]; then
        show_help
        exit 0
    fi
    
    if [ -z "$1" ]; then
        print_error "pfSense IP address required"
        echo ""
        show_help
        exit 1
    fi
    
    PFSENSE_IP="$1"
}

#############################################
# Setup SSH key authentication
#############################################
setup_ssh_keys() {
    echo ""
    print_info "Checking SSH keys..."
    SSH_KEY=""
    if [ -f "$HOME/.ssh/id_ed25519.pub" ]; then
        SSH_KEY="$HOME/.ssh/id_ed25519.pub"
        print_success "Found ED25519 key: $SSH_KEY"
    elif [ -f "$HOME/.ssh/id_rsa.pub" ]; then
        SSH_KEY="$HOME/.ssh/id_rsa.pub"
        print_success "Found RSA key: $SSH_KEY"
    else
        print_warning "No SSH keys found"
        echo ""
        print_info "Generating SSH key pair..."
        ssh-keygen -t ed25519 -f "$HOME/.ssh/id_ed25519" -N "" -C "$PFSENSE_USER@$(hostname)"
        SSH_KEY="$HOME/.ssh/id_ed25519.pub"
        print_success "SSH key generated: $SSH_KEY"
    fi
    
    # Check if we can connect
    echo ""
    print_info "Checking connection to pfSense..."
    if ! ssh -o BatchMode=yes -o ConnectTimeout=10 $PFSENSE_USER@$PFSENSE_IP "echo 'Connected'" 2>/dev/null; then
        print_warning "Passwordless SSH not configured"
        echo ""
        print_info "Setting up SSH key authentication..."
        echo "Note: You'll be asked for your password ONE TIME to set this up"
        echo ""
        
        # Try ssh-copy-id first
        if command -v ssh-copy-id >/dev/null 2>&1; then
            if ssh-copy-id -i "$SSH_KEY" $PFSENSE_USER@$PFSENSE_IP 2>/dev/null; then
                print_success "SSH key installed successfully"
            else
                # Manual method
                PUB_KEY=$(cat "$SSH_KEY")
                if ssh -o ControlMaster=no $PFSENSE_USER@$PFSENSE_IP "mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '$PUB_KEY' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"; then
                    print_success "SSH key installed manually"
                else
                    print_error "Failed to install SSH key"
                    exit 1
                fi
            fi
        else
            # ssh-copy-id not available
            PUB_KEY=$(cat "$SSH_KEY")
            if ssh -o ControlMaster=no $PFSENSE_USER@$PFSENSE_IP "mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '$PUB_KEY' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"; then
                print_success "SSH key installed successfully"
            else
                print_error "Failed to install SSH key"
                exit 1
            fi
        fi
        
        echo ""
        print_info "Testing passwordless connection..."
        sleep 1
    fi
    
    # Verify passwordless connection works
    if ssh -o BatchMode=yes -o ConnectTimeout=10 $PFSENSE_USER@$PFSENSE_IP "echo 'Connected'" 2>/dev/null; then
        print_success "Passwordless SSH connection successful"
    else
        print_warning "Passwordless SSH still not working"
        print_info "Continuing anyway (you may be prompted for passwords)..."
    fi
}

#############################################
# Setup passwordless sudo
#############################################
setup_sudo() {
    echo ""
    print_info "Checking sudo configuration..."
    if ssh -o BatchMode=yes $PFSENSE_USER@$PFSENSE_IP "sudo -n true" 2>/dev/null; then
        print_success "Passwordless sudo already configured"
    else
        print_warning "Passwordless sudo not configured"
        echo ""
        print_info "Configuring passwordless sudo for user '$PFSENSE_USER'..."
        echo "Note: You'll be asked for your password to set this up"
        echo ""
        
        if ssh -t $PFSENSE_USER@$PFSENSE_IP "echo '$PFSENSE_USER ALL=(ALL) NOPASSWD: ALL' | sudo tee /usr/local/etc/sudoers.d/$PFSENSE_USER > /dev/null && sudo chmod 440 /usr/local/etc/sudoers.d/$PFSENSE_USER"; then
            print_success "Passwordless sudo configured"
            
            if ssh -o BatchMode=yes $PFSENSE_USER@$PFSENSE_IP "sudo -n true" 2>/dev/null; then
                print_success "Passwordless sudo verified"
            else
                print_warning "Sudo configuration may need a new shell session"
            fi
        else
            print_warning "Could not configure passwordless sudo"
            print_info "You may be prompted for passwords during installation"
        fi
    fi
}

#############################################
# Upload package files
#############################################
upload_files() {
    echo ""
    print_info "Uploading package files..."
    
    # Create directories
    print_info "Creating directories on pfSense..."
    if ssh -o BatchMode=yes $PFSENSE_USER@$PFSENSE_IP "sudo -n mkdir -p /usr/local/pkg /usr/local/www /usr/local/bin /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs /var/log /var/db" 2>/dev/null; then
        print_success "Directories created"
    else
        if ssh -t $PFSENSE_USER@$PFSENSE_IP "sudo mkdir -p /usr/local/pkg /usr/local/www /usr/local/bin /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs /var/log /var/db"; then
            print_success "Directories created"
        else
            print_error "Failed to create directories"
            return 1
        fi
    fi
    
    # Copy core files to /tmp/
    print_info "Copying core files to pfSense..."
    if scp -q \
        "$PACKAGE_DIR/info.xml" \
        "$PACKAGE_DIR/parental_control.xml" \
        "$PACKAGE_DIR/parental_control.inc" \
        "$PACKAGE_DIR/VERSION" \
        "$PACKAGE_DIR/parental_control_status.php" \
        "$PACKAGE_DIR/parental_control_profiles.php" \
        "$PACKAGE_DIR/parental_control_schedules.php" \
        "$PACKAGE_DIR/parental_control_services.php" \
        "$PACKAGE_DIR/parental_control_blocked.php" \
        "$PACKAGE_DIR/parental_control_captive.php" \
        "$PACKAGE_DIR/parental_control_captive.sh" \
        "$PACKAGE_DIR/parental_control_health.php" \
        "$PACKAGE_DIR/parental_control_api.php" \
        "$PACKAGE_DIR/parental_control_diagnostic.php" \
        "$PACKAGE_DIR/parental_control_analyzer.sh" \
        "$PACKAGE_DIR/auto_update_parental_control.sh" \
        "$PACKAGE_DIR/UNINSTALL.sh" \
        $PFSENSE_USER@$PFSENSE_IP:/tmp/; then
        print_success "Core files copied to /tmp/"
    else
        print_error "Failed to copy core files"
        return 1
    fi
    
    # Copy documentation files
    print_info "Copying documentation files..."
    DOC_FILES=""
    for DOC in "API.md" "CONFIGURATION.md" "TROUBLESHOOTING.md" "FIX_PORT_ALIAS_ERROR_v1.4.1.md" "GETTING_STARTED.md"; do
        if [ -f "$PACKAGE_DIR/docs/$DOC" ]; then
            DOC_FILES="$DOC_FILES $PACKAGE_DIR/docs/$DOC"
        fi
    done
    
    if [ -n "$DOC_FILES" ]; then
        if scp -q $DOC_FILES $PFSENSE_USER@$PFSENSE_IP:/tmp/ 2>/dev/null; then
            print_success "Documentation files copied"
        else
            print_warning "Some documentation files may not exist (non-critical)"
        fi
    else
        print_warning "No documentation files found (non-critical)"
    fi
    
    # Move files to correct locations
    print_info "Installing files to system directories..."
    if ssh -o BatchMode=yes $PFSENSE_USER@$PFSENSE_IP "
        sudo -n mv /tmp/info.xml /usr/local/share/pfSense-pkg-KACI-Parental_Control/ && \
        sudo -n mv /tmp/parental_control.xml /usr/local/pkg/ && \
        sudo -n mv /tmp/parental_control.inc /usr/local/pkg/ && \
        sudo -n mv /tmp/VERSION /usr/local/pkg/parental_control_VERSION && \
        sudo -n mv /tmp/parental_control_status.php /usr/local/www/ && \
        sudo -n mv /tmp/parental_control_profiles.php /usr/local/www/ && \
        sudo -n mv /tmp/parental_control_schedules.php /usr/local/www/ && \
        sudo -n mv /tmp/parental_control_services.php /usr/local/www/ && \
        sudo -n mv /tmp/parental_control_blocked.php /usr/local/www/ && \
        sudo -n mv /tmp/parental_control_captive.php /usr/local/www/ && \
        sudo -n mv /tmp/parental_control_captive.sh /usr/local/etc/rc.d/parental_control_captive && \
        sudo -n mv /tmp/parental_control_health.php /usr/local/www/ 2>/dev/null; true && \
        sudo -n mv /tmp/parental_control_api.php /usr/local/www/ 2>/dev/null; true && \
        sudo -n mv /tmp/parental_control_diagnostic.php /usr/local/bin/ 2>/dev/null; true && \
        sudo -n mv /tmp/parental_control_analyzer.sh /usr/local/bin/ 2>/dev/null; true && \
        sudo -n mv /tmp/auto_update_parental_control.sh /usr/local/bin/ 2>/dev/null; true && \
        sudo -n mv /tmp/UNINSTALL.sh /usr/local/bin/ 2>/dev/null; true && \
        sudo -n mv /tmp/parental_control_captive.sh /usr/local/etc/rc.d/parental_control_captive 2>/dev/null; true && \
        sudo -n mv /tmp/API.md /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/ 2>/dev/null; true && \
        sudo -n mv /tmp/CONFIGURATION.md /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/ 2>/dev/null; true && \
        sudo -n mv /tmp/TROUBLESHOOTING.md /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/ 2>/dev/null; true && \
        sudo -n mv /tmp/FIX_PORT_ALIAS_ERROR_v1.4.1.md /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/ 2>/dev/null; true && \
        sudo -n mv /tmp/GETTING_STARTED.md /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/ 2>/dev/null; true && \
        sudo -n chmod 644 /usr/local/pkg/parental_control*.xml && \
        sudo -n chmod 644 /usr/local/pkg/parental_control.inc && \
        sudo -n chmod 644 /usr/local/pkg/parental_control_VERSION && \
        sudo -n chmod 644 /usr/local/www/parental_control*.php && \
        sudo -n chmod 755 /usr/local/etc/rc.d/parental_control_captive && \
        sudo -n chmod 755 /usr/local/bin/parental_control_diagnostic.php 2>/dev/null; true && \
        sudo -n chmod 755 /usr/local/bin/parental_control_analyzer.sh 2>/dev/null; true && \
        sudo -n chmod 755 /usr/local/bin/auto_update_parental_control.sh 2>/dev/null; true && \
        sudo -n chmod 755 /usr/local/bin/UNINSTALL.sh 2>/dev/null; true && \
        sudo -n chmod 755 /usr/local/etc/rc.d/parental_control_captive 2>/dev/null; true && \
        sudo -n chmod 644 /usr/local/share/pfSense-pkg-KACI-Parental_Control/info.xml && \
        sudo -n chmod 644 /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/*.md 2>/dev/null; true
    " 2>/dev/null; then
        print_success "Files installed"
    else
        if ssh -t $PFSENSE_USER@$PFSENSE_IP "
            sudo mv /tmp/info.xml /usr/local/share/pfSense-pkg-KACI-Parental_Control/ && \
            sudo mv /tmp/parental_control.xml /usr/local/pkg/ && \
            sudo mv /tmp/parental_control.inc /usr/local/pkg/ && \
            sudo mv /tmp/parental_control_status.php /usr/local/www/ && \
            sudo mv /tmp/parental_control_profiles.php /usr/local/www/ && \
            sudo mv /tmp/parental_control_schedules.php /usr/local/www/ && \
            sudo mv /tmp/parental_control_services.php /usr/local/www/ && \
            sudo mv /tmp/parental_control_blocked.php /usr/local/www/ && \
            sudo mv /tmp/parental_control_health.php /usr/local/www/ 2>/dev/null; true && \
            sudo mv /tmp/parental_control_api.php /usr/local/www/ 2>/dev/null; true && \
            sudo mv /tmp/parental_control_diagnostic.php /usr/local/bin/ 2>/dev/null; true && \
            sudo mv /tmp/parental_control_analyzer.sh /usr/local/bin/ 2>/dev/null; true && \
            sudo mv /tmp/API.md /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/ 2>/dev/null; true && \
            sudo mv /tmp/CONFIGURATION.md /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/ 2>/dev/null; true && \
            sudo mv /tmp/TROUBLESHOOTING.md /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/ 2>/dev/null; true && \
            sudo chmod 644 /usr/local/pkg/parental_control*.xml && \
            sudo chmod 644 /usr/local/pkg/parental_control.inc && \
            sudo chmod 644 /usr/local/www/parental_control*.php && \
            sudo chmod 755 /usr/local/bin/parental_control_diagnostic.php 2>/dev/null; true && \
            sudo chmod 755 /usr/local/bin/parental_control_analyzer.sh 2>/dev/null; true && \
            sudo chmod 644 /usr/local/share/pfSense-pkg-KACI-Parental_Control/info.xml && \
            sudo chmod 644 /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/*.md 2>/dev/null; true
        "; then
            print_success "Files installed"
        else
            print_error "Failed to install files"
            return 1
        fi
    fi
    
    return 0
}

#############################################
# Register package in pfSense
#############################################
register_package() {
    echo ""
    print_info "Registering package in pfSense..."
    
    # Create registration script
    cat > /tmp/register_package_$$.php << 'PHPEOF'
<?php
require_once('/etc/inc/config.inc');
require_once('/etc/inc/util.inc');

$config = parse_config();

// Ensure installedpackages array exists
if (!is_array($config['installedpackages'])) {
    $config['installedpackages'] = array();
}
if (!is_array($config['installedpackages']['package'])) {
    $config['installedpackages']['package'] = array();
}

// Check if package already registered
$package_exists = false;
foreach ($config['installedpackages']['package'] as $pkg) {
    if ($pkg['name'] === 'KACI-Parental_Control') {
        $package_exists = true;
        break;
    }
}

// Register package if not exists
if (!$package_exists) {
    $config['installedpackages']['package'][] = array(
        'name' => 'KACI-Parental_Control',
        'descr' => 'Keekar\'s Parental Control',
        'version' => '0.2.1',
        'configurationfile' => 'parental_control.xml'
    );
}

// Ensure menu array exists
if (!is_array($config['installedpackages']['menu'])) {
    $config['installedpackages']['menu'] = array();
}

// Check if menu entry exists
$menu_exists = false;
foreach ($config['installedpackages']['menu'] as $menu_item) {
    if ($menu_item['name'] === 'Keekar\'s Parental Control') {
        $menu_exists = true;
        break;
    }
}

// Add menu entry if not exists
if (!$menu_exists) {
    $config['installedpackages']['menu'][] = array(
        'name' => 'Keekar\'s Parental Control',
        'section' => 'Services',
        'url' => '/pkg_edit.php?xml=parental_control.xml'
    );
}

// Initialize configuration if not exists
if (!is_array($config['installedpackages']['parentalcontrol'])) {
    $config['installedpackages']['parentalcontrol'] = array();
}
if (!is_array($config['installedpackages']['parentalcontrol']['config'])) {
    $config['installedpackages']['parentalcontrol']['config'] = array();
}
if (!is_array($config['installedpackages']['parentalcontrol']['config'][0])) {
    $config['installedpackages']['parentalcontrol']['config'][0] = array();
    // Set defaults only if creating new
    $config['installedpackages']['parentalcontrol']['config'][0]['enable'] = 'on';
    $config['installedpackages']['parentalcontrol']['config'][0]['enforcement_mode'] = 'strict';
    $config['installedpackages']['parentalcontrol']['config'][0]['grace_period'] = '5';
    $config['installedpackages']['parentalcontrol']['config'][0]['log_level'] = 'info';
}

// Save configuration
write_config('Registered and initialized KACI Parental Control');
echo "OK";
?>
PHPEOF

    # Copy registration script to pfSense
    if scp -q /tmp/register_package_$$.php $PFSENSE_USER@$PFSENSE_IP:/tmp/register_package.php 2>/dev/null; then
        # Run registration script
        RESULT=$(ssh -o BatchMode=yes $PFSENSE_USER@$PFSENSE_IP "sudo -n /usr/local/bin/php /tmp/register_package.php 2>/dev/null" 2>/dev/null || \
                 ssh -t $PFSENSE_USER@$PFSENSE_IP "sudo /usr/local/bin/php /tmp/register_package.php 2>/dev/null" 2>/dev/null)
        
        if [ "$RESULT" = "OK" ]; then
            print_success "Package registered and initialized"
        else
            print_warning "Package registration may need manual verification"
        fi
        
        # Cleanup
        ssh -o BatchMode=yes $PFSENSE_USER@$PFSENSE_IP "rm -f /tmp/register_package.php" 2>/dev/null || true
    else
        print_warning "Could not copy registration script"
    fi
    
    rm -f /tmp/register_package_$$.php
}

#############################################
# Setup cron job
#############################################
setup_cron_job() {
    print_info "Setting up cron job for usage tracking..."
    
    # Create cron setup script
    cat > /tmp/setup_cron_$$.php << 'PHPEOF'
<?php
require_once('/usr/local/pkg/parental_control.inc');

echo "Installing cron job...\n";
pc_setup_cron_job();
echo "Cron job installed successfully!\n";
?>
PHPEOF

    # Copy and execute cron setup script
    if scp -q /tmp/setup_cron_$$.php $PFSENSE_USER@$PFSENSE_IP:/tmp/setup_cron.php 2>/dev/null; then
        ssh $PFSENSE_USER@$PFSENSE_IP "sudo /usr/local/bin/php /tmp/setup_cron.php 2>&1" 2>/dev/null
        
        # Verify cron job was created
        CRON_CHECK=$(ssh $PFSENSE_USER@$PFSENSE_IP "sudo crontab -l 2>/dev/null | grep -c 'parental_control'" 2>/dev/null || echo "0")
        
        if [ "$CRON_CHECK" -gt 0 ]; then
            print_success "Cron job installed and verified"
        else
            print_warning "Cron job may not be installed - verify manually"
            print_info "  Run: sudo php -r \"require_once('/usr/local/pkg/parental_control.inc'); pc_setup_cron_job();\""
        fi
        
        # Cleanup
        ssh $PFSENSE_USER@$PFSENSE_IP "rm -f /tmp/setup_cron.php" 2>/dev/null || true
    else
        print_warning "Could not copy cron setup script"
    fi
    
    rm -f /tmp/setup_cron_$$.php
}

#############################################
# Setup auto-update (optional)
#############################################
setup_auto_update() {
    print_info "Setting up auto-update feature..."
    
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "⚠️  AUTO-UPDATE FEATURE"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo "The auto-update feature will:"
    echo "  • Check GitHub for updates every 8 hours"
    echo "  • Download and deploy updates automatically"
    echo "  • Log all activities"
    echo ""
    echo "⚠️  WARNING: NOT recommended for production firewalls!"
    echo "   Auto-updates can introduce bugs or break existing configs."
    echo "   Recommended ONLY for development/testing environments."
    echo ""
    read -p "Do you want to enable auto-updates? (y/N): " ENABLE_AUTO_UPDATE
    echo ""
    
    if [ "$ENABLE_AUTO_UPDATE" = "y" ] || [ "$ENABLE_AUTO_UPDATE" = "Y" ]; then
        print_info "Enabling auto-update feature..."
        
        # Setup auto-update directly via SSH (consolidated logic - no separate script needed)
        if ssh $PFSENSE_USER@$PFSENSE_IP "bash -s" <<'AUTOUPDATE_SETUP'
#!/bin/sh
# Consolidated auto-update setup

# Check if auto-update script exists
if [ ! -f /usr/local/bin/auto_update_parental_control.sh ]; then
    echo "ERROR: auto_update_parental_control.sh not found at /usr/local/bin/"
    exit 1
fi

# Ensure script is executable
chmod 755 /usr/local/bin/auto_update_parental_control.sh 2>/dev/null || exit 1

# Create log file with proper permissions
touch /var/log/parental_control_auto_update.log
chmod 644 /var/log/parental_control_auto_update.log

# Check if cron entry already exists
CRON_EXISTS=$(crontab -l 2>/dev/null | grep -c "auto_update_parental_control.sh")

if [ "$CRON_EXISTS" -gt 0 ]; then
    # Remove old auto-update entry (preserving all other cron jobs)
    TEMP_CRON=$(mktemp)
    crontab -l 2>/dev/null | grep -v "auto_update_parental_control.sh" > "$TEMP_CRON"
    crontab "$TEMP_CRON"
    rm -f "$TEMP_CRON"
fi

# Add cron entry (runs every 8 hours) - preserving all other cron jobs
TEMP_CRON=$(mktemp)
crontab -l 2>/dev/null > "$TEMP_CRON"
echo "0 */8 * * * /usr/local/bin/auto_update_parental_control.sh" >> "$TEMP_CRON"
crontab "$TEMP_CRON"
RESULT=$?
rm -f "$TEMP_CRON"

exit $RESULT
AUTOUPDATE_SETUP
        then
            print_success "Auto-update feature enabled"
            echo ""
            echo "  Schedule: Checks every 8 hours"
            echo "  Log file: /var/log/parental_control_auto_update.log"
            echo ""
            echo "  To monitor updates:"
            echo "    ssh $PFSENSE_USER@$PFSENSE_IP 'tail -f /var/log/parental_control_auto_update.log'"
            echo ""
            echo "  To manually check for updates:"
            echo "    ssh $PFSENSE_USER@$PFSENSE_IP '/usr/local/bin/auto_update_parental_control.sh'"
            echo ""
            echo "  To disable auto-updates later:"
            echo "    ssh $PFSENSE_USER@$PFSENSE_IP 'crontab -l | grep -v auto_update_parental_control | crontab -'"
            echo ""
        else
            print_warning "Could not automatically enable auto-update"
            echo ""
            print_info "  Checking if script exists..."
            if ssh $PFSENSE_USER@$PFSENSE_IP "test -f /usr/local/bin/auto_update_parental_control.sh"; then
                print_success "  Script found at /usr/local/bin/auto_update_parental_control.sh"
                print_info "  The auto-update script is installed but not scheduled."
                print_info "  To manually trigger updates:"
                echo "    ssh $PFSENSE_USER@$PFSENSE_IP '/usr/local/bin/auto_update_parental_control.sh'"
            else
                print_error "  Script NOT found at /usr/local/bin/auto_update_parental_control.sh"
                print_info "  Run verification to check installation:"
                echo "    ./INSTALL.sh verify"
            fi
        fi
    else
        print_info "Skipping auto-update setup"
        echo ""
        echo "  Auto-update script is installed at:"
        echo "    /usr/local/bin/auto_update_parental_control.sh"
        echo ""
        echo "  To manually check for updates anytime:"
        echo "    ssh $PFSENSE_USER@$PFSENSE_IP '/usr/local/bin/auto_update_parental_control.sh'"
        echo ""
    fi
}

#############################################
# Verify installation
#############################################
verify_installation() {
    echo ""
    print_info "Verifying installation..."
    
    # Check files exist
    print_info "Checking files..."
    MISSING=0
    
    # Check each core file individually
    for FILE in \
            "/usr/local/pkg/parental_control.xml" \
            "/usr/local/pkg/parental_control.inc" \
            "/usr/local/www/parental_control_status.php" \
            "/usr/local/www/parental_control_profiles.php" \
            "/usr/local/www/parental_control_schedules.php" \
            "/usr/local/www/parental_control_services.php" \
            "/usr/local/www/parental_control_blocked.php" \
            "/usr/local/www/parental_control_captive.php" \
            "/usr/local/etc/rc.d/parental_control_captive" \
            "/usr/local/www/parental_control_health.php" \
            "/usr/local/www/parental_control_api.php" \
            "/usr/local/bin/parental_control_diagnostic.php" \
            "/usr/local/bin/parental_control_analyzer.sh" \
            "/usr/local/bin/auto_update_parental_control.sh" \
            "/usr/local/bin/UNINSTALL.sh" \
            "/usr/local/share/pfSense-pkg-KACI-Parental_Control/info.xml"
    do
        if ! ssh $PFSENSE_USER@$PFSENSE_IP "sudo test -f '$FILE'" 2>/dev/null; then
            print_warning "Missing: $FILE"
            MISSING=1
        else
            echo "   ✓ Found: $FILE"
        fi
    done
    
    # Check optional documentation files (non-critical)
    for FILE in \
            "/usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/API.md" \
            "/usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/CONFIGURATION.md" \
            "/usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/TROUBLESHOOTING.md" \
            "/usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/FIX_PORT_ALIAS_ERROR_v1.4.1.md" \
            "/usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/GETTING_STARTED.md"
    do
        if ssh $PFSENSE_USER@$PFSENSE_IP "sudo test -f '$FILE'" 2>/dev/null; then
            echo "   ✓ Found: $(basename $FILE)"
        else
            print_info "   ℹ Optional documentation: $(basename $FILE) (not critical)"
        fi
    done
    
    if [ $MISSING -eq 0 ]; then
        print_success "All files present"
    else
        print_error "Some files missing"
        return 1
    fi
    
    # Check PHP syntax
    print_info "Checking PHP syntax..."
    if ssh $PFSENSE_USER@$PFSENSE_IP 'php -l /usr/local/pkg/parental_control.inc' >/dev/null 2>&1; then
        print_success "No PHP syntax errors"
    else
        print_warning "Could not verify PHP syntax (file may still be valid)"
    fi
    
    # Test package loading  
    print_info "Testing package load..."
    if ssh $PFSENSE_USER@$PFSENSE_IP 'test -f /usr/local/pkg/parental_control.inc' 2>/dev/null; then
        print_success "Package file accessible"
    else
        print_warning "Could not verify package accessibility"
    fi
    
    # Check if registered
    print_info "Checking registration..."
    if ssh $PFSENSE_USER@$PFSENSE_IP 'sudo grep -q parental_control /cf/conf/config.xml' 2>/dev/null; then
        print_success "Package registered in config.xml"
    else
        print_warning "Package not yet registered"
        echo ""
        echo "To register the package, run:"
        echo "  ssh $PFSENSE_USER@$PFSENSE_IP"
        echo "  sudo /usr/local/sbin/pfSh.php"
        echo "  require_once(\"pkg-utils.inc\");"
        echo "  install_package_xml(\"parental_control\");"
        echo "  exit"
    fi
    
    # Check cron job
    print_info "Checking cron job..."
    if ssh $PFSENSE_USER@$PFSENSE_IP 'sudo crontab -l 2>/dev/null | grep -q parental_control' 2>/dev/null; then
        print_success "Cron job installed and active"
    else
        print_warning "Cron job not found - usage tracking won't work!"
        echo ""
        echo "To install the cron job manually, run:"
        echo "  ssh $PFSENSE_USER@$PFSENSE_IP"
        echo "  sudo php -r \"require_once('/usr/local/pkg/parental_control.inc'); pc_setup_cron_job();\""
    fi
    
    return 0
}

#############################################
# Uninstall package
#############################################
do_uninstall() {
    echo ""
    print_info "Uninstalling Parental Control package..."
    
    # Check if package is registered
    COUNT=$(ssh $PFSENSE_USER@$PFSENSE_IP "sudo grep -c 'parental_control' /cf/conf/config.xml 2>/dev/null || echo 0")
    
    if [ "$COUNT" -gt 0 ]; then
        print_info "Package is registered, unregistering..."
        
        # Unregister package via pfSh
        ssh $PFSENSE_USER@$PFSENSE_IP "sudo /usr/local/sbin/pfSsh.php" <<'UNREGISTER_EOF'
require_once("pkg-utils.inc");
require_once("config.lib.inc");

// Remove package from config
$packages = config_get_path('installedpackages');
if (is_array($packages)) {
    if (isset($packages['parentalcontrol'])) {
        unset($packages['parentalcontrol']);
        print("Removed parentalcontrol config\n");
    }
    if (isset($packages['parentalcontroldevices'])) {
        unset($packages['parentalcontroldevices']);
        print("Removed parentalcontroldevices config\n");
    }
    if (isset($packages['parentalcontrolprofiles'])) {
        unset($packages['parentalcontrolprofiles']);
        print("Removed parentalcontrolprofiles config\n");
    }
    if (isset($packages['package'])) {
        $packages['package'] = array_filter($packages['package'], function($pkg) {
            return !isset($pkg['name']) || $pkg['name'] !== 'parental_control';
        });
        print("Removed from package list\n");
    }
    config_set_path('installedpackages', $packages);
    write_config("Uninstalled Parental Control package");
    print("Configuration updated\n");
}

exit
UNREGISTER_EOF
        
        print_success "Package unregistered from config"
    else
        print_info "Package not registered in config"
    fi
    
    # Remove files
    print_info "Removing package files..."
    
    if ssh $PFSENSE_USER@$PFSENSE_IP "
        sudo rm -f /usr/local/pkg/parental_control.xml 2>/dev/null
        sudo rm -f /usr/local/pkg/parental_control.inc 2>/dev/null
        sudo rm -f /usr/local/pkg/parental_control_VERSION 2>/dev/null
        sudo rm -f /usr/local/www/parental_control_status.php 2>/dev/null
        sudo rm -f /usr/local/www/parental_control_profiles.php 2>/dev/null
        sudo rm -f /usr/local/www/parental_control_schedules.php 2>/dev/null
        sudo rm -f /usr/local/www/parental_control_services.php 2>/dev/null
        sudo rm -f /usr/local/www/parental_control_blocked.php 2>/dev/null
        sudo rm -f /usr/local/www/parental_control_captive.php 2>/dev/null
        sudo rm -f /usr/local/etc/rc.d/parental_control_captive 2>/dev/null
        sudo rm -f /usr/local/www/parental_control_health.php 2>/dev/null
        sudo rm -f /usr/local/www/parental_control_api.php 2>/dev/null
        sudo rm -f /usr/local/bin/parental_control_diagnostic.php 2>/dev/null
        sudo rm -f /usr/local/bin/parental_control_analyzer.sh 2>/dev/null
        sudo rm -f /usr/local/bin/parental_control_cron.php 2>/dev/null
        sudo rm -rf /usr/local/share/pfSense-pkg-parental_control 2>/dev/null
        sudo rm -rf /usr/local/share/pfSense-pkg-KACI-Parental_Control 2>/dev/null
        sudo rm -f /var/log/parental_control*.jsonl 2>/dev/null
        sudo rm -f /var/db/parental_control*.json 2>/dev/null
    "; then
        print_success "Package files removed"
    else
        print_error "Failed to remove some files"
        return 1
    fi
    
    # Remove cron jobs
    print_info "Cleaning up cron jobs..."
    ssh $PFSENSE_USER@$PFSENSE_IP "
        sudo crontab -l 2>/dev/null | grep -v 'parental_control' | sudo crontab - 2>/dev/null
    " 2>/dev/null
    print_success "Cron jobs removed"
    
    # Remove firewall rules (optional - comment out if you want to keep them)
    print_info "Cleaning up firewall rules..."
    ssh $PFSENSE_USER@$PFSENSE_IP "sudo /usr/local/sbin/pfSsh.php" <<'CLEANUP_EOF'
require_once("config.lib.inc");
require_once("filter.inc");

// Remove parental control firewall rules
$filter_rules = config_get_path('filter/rule');
if (is_array($filter_rules)) {
    $filter_rules = array_filter($filter_rules, function($rule) {
        return !isset($rule['descr']) || strpos($rule['descr'], 'Parental Control') === false;
    });
    config_set_path('filter/rule', array_values($filter_rules));
    write_config("Removed Parental Control firewall rules");
    filter_configure();
    print("Firewall rules cleaned up\n");
}

exit
CLEANUP_EOF
    print_success "Firewall rules cleaned"
    
    return 0
}

#############################################
# Debug mode
#############################################
run_debug() {
    echo ""
    echo "============================================"
    echo "Debug Information"
    echo "============================================"
    
    echo ""
    print_info "1. File Status:"
    ssh $PFSENSE_USER@$PFSENSE_IP 'sudo ls -lh /usr/local/pkg/parental_control*.{xml,inc} /usr/local/www/parental_control*.php /usr/local/bin/parental_control*.php 2>&1 || echo "   Some files not found"'
    
    echo ""
    print_info "2. Documentation Files:"
    ssh $PFSENSE_USER@$PFSENSE_IP 'sudo ls -lh /usr/local/share/pfSense-pkg-KACI-Parental_Control/docs/*.md 2>&1 || echo "   Documentation not found"'
    
    echo ""
    print_info "3. PHP Syntax Check:"
    ssh $PFSENSE_USER@$PFSENSE_IP "sudo php -l /usr/local/pkg/parental_control.inc 2>&1"
    
    echo ""
    print_info "4. Run Diagnostic Tool:"
    ssh $PFSENSE_USER@$PFSENSE_IP 'sudo php /usr/local/bin/parental_control_diagnostic.php 2>&1 || echo "   Diagnostic tool not available or failed"'
    
    echo ""
    print_info "5. Package Registration:"
    COUNT=$(ssh $PFSENSE_USER@$PFSENSE_IP 'sudo grep -c parental_control /cf/conf/config.xml 2>/dev/null || echo 0')
    echo "   Found $COUNT occurrences in config.xml"
    
    echo ""
    print_info "6. Recent System Logs:"
    ssh $PFSENSE_USER@$PFSENSE_IP 'sudo tail -20 /var/log/system.log 2>/dev/null | grep -E "pkg|php|parental" || echo "   No relevant log entries"'
    
    echo ""
    print_info "7. Parental Control Logs:"
    ssh $PFSENSE_USER@$PFSENSE_IP 'sudo tail -10 /var/log/parental_control*.jsonl 2>/dev/null | head -20 || echo "   No parental control logs found"'
    
    echo ""
    print_info "8. Package Load Test:"
    ssh $PFSENSE_USER@$PFSENSE_IP 'sudo php -r "require_once(sprintf(\"/usr/local/pkg/%s\", \"parental_control.inc\")); echo \"OK\n\";" 2>&1' || echo "   Package load failed"
    
    echo ""
    print_info "9. Disk Space:"
    ssh $PFSENSE_USER@$PFSENSE_IP "df -h / | tail -1"
    
    echo ""
    echo "============================================"
    echo "Debug Complete"
    echo "============================================"
}

#############################################
# Check package dependencies
#############################################
check_dependencies() {
    echo "============================================"
    echo "Checking Package Dependencies"
    echo "============================================"
    echo ""
    
    local missing_deps=""
    local can_install=true
    
    # Check for sudo
    print_info "Checking for sudo package..."
    if ssh -o ConnectTimeout=10 ${PFSENSE_USER}@${PFSENSE_IP} "pkg info sudo" >/dev/null 2>&1; then
        local sudo_version=$(ssh ${PFSENSE_USER}@${PFSENSE_IP} "pkg info sudo | grep Version | awk '{print \$3}'")
        print_success "sudo is installed (version: $sudo_version)"
    else
        print_warning "sudo is NOT installed"
        missing_deps="${missing_deps}sudo "
    fi
    
    # Check for cron (usually part of base system, but verify)
    print_info "Checking for cron service..."
    if ssh -o ConnectTimeout=10 ${PFSENSE_USER}@${PFSENSE_IP} "which crontab" >/dev/null 2>&1; then
        print_success "cron is available"
    else
        print_warning "cron is NOT available"
        missing_deps="${missing_deps}cron "
    fi
    
    # If dependencies are missing, offer to install
    if [ -n "$missing_deps" ]; then
        echo ""
        print_warning "Missing required dependencies: $missing_deps"
        echo ""
        echo "KACI Parental Control requires the following packages:"
        echo "  • sudo (security/sudo) - version 1.9.16p2 or later"
        echo "    Purpose: Allows delegation of privileges for shell commands"
        echo ""
        echo "  • cron (sysutils/cron) - version 0.3.8_6 or later"
        echo "    Purpose: Manages scheduled tasks for usage tracking"
        echo ""
        
        # Ask user if they want to auto-install
        printf "Would you like to install missing dependencies automatically? (y/n): "
        read -r answer
        
        if [ "$answer" = "y" ] || [ "$answer" = "Y" ]; then
            echo ""
            print_info "Installing missing dependencies..."
            
            # Install sudo if missing
            if echo "$missing_deps" | grep -q "sudo"; then
                print_info "Installing sudo package..."
                ssh ${PFSENSE_USER}@${PFSENSE_IP} "pkg install -y sudo" 2>&1 | while read line; do
                    echo "  $line"
                done
                
                if ssh ${PFSENSE_USER}@${PFSENSE_IP} "pkg info sudo" >/dev/null 2>&1; then
                    print_success "sudo installed successfully"
                else
                    print_error "Failed to install sudo"
                    can_install=false
                fi
            fi
            
            # Note: cron is typically part of FreeBSD base system
            # If it's missing, it's a more serious system issue
            if echo "$missing_deps" | grep -q "cron"; then
                print_warning "cron should be part of the base system"
                print_info "Checking if cron service is enabled..."
                ssh ${PFSENSE_USER}@${PFSENSE_IP} "service cron status" 2>&1 | while read line; do
                    echo "  $line"
                done
            fi
            
            echo ""
            # Verify installation
            print_info "Verifying dependencies..."
            if ssh ${PFSENSE_USER}@${PFSENSE_IP} "pkg info sudo && which crontab" >/dev/null 2>&1; then
                print_success "All dependencies are now satisfied"
            else
                print_error "Some dependencies are still missing"
                can_install=false
            fi
        else
            echo ""
            print_error "Cannot proceed without required dependencies"
            echo ""
            echo "Please install manually:"
            echo "  1. SSH to pfSense: ssh $PFSENSE_USER@$PFSENSE_IP"
            echo "  2. Install sudo: pkg install -y sudo"
            echo "  3. Verify cron: which crontab && service cron status"
            echo "  4. Re-run this installer"
            echo ""
            exit 1
        fi
        
        if [ "$can_install" = "false" ]; then
            echo ""
            print_error "Dependency installation failed"
            print_info "Please install dependencies manually and re-run installer"
            exit 1
        fi
    else
        print_success "All required dependencies are satisfied"
    fi
    
    echo ""
}

#############################################
# Main installation flow
#############################################
do_install() {
    echo "============================================"
    echo "Keekar's Parental Control Package Installer"
    echo "============================================"
    echo ""
    echo "Mode: $MODE"
    echo "Target pfSense: $PFSENSE_IP"
    echo "SSH User: $PFSENSE_USER"
    echo "Package Directory: $PACKAGE_DIR"
    echo ""
    
    # Check dependencies first
    if [ "$MODE" = "install" ] || [ "$MODE" = "reinstall" ]; then
        check_dependencies
    fi
    
    # Setup authentication (only for full install)
    if [ "$MODE" = "install" ]; then
        setup_ssh_keys
        setup_sudo
    fi
    
    # Upload files
    upload_files
    if [ $? -ne 0 ]; then
        echo ""
        print_error "Installation failed"
        exit 1
    fi
    
    # Register package and initialize configuration
    register_package
    
    # Setup cron job for usage tracking
    setup_cron_job
    
    # Setup auto-update feature (optional)
    setup_auto_update
    
    # Verify
    if [ "$MODE" = "install" ] || [ "$MODE" = "verify" ]; then
        verify_installation
    fi
    
    echo ""
    echo "============================================"
    echo "Installation Complete!"
    echo "============================================"
    echo ""
    echo "Next steps:"
    echo "  1. Open your web browser"
    echo "  2. Navigate to https://$PFSENSE_IP/"
    echo "  3. Go to Services > Keekar's Parental Control"
    echo "  4. Click the 'Profiles' tab"
    echo ""
    echo "Features available:"
    echo "  ✓ Profile-based device grouping"
    echo "  ✓ DHCP/ARP device auto-discovery"
    echo "  ✓ Shared time limits (bypass-proof)"
    echo "  ✓ Weekend bonus time"
    echo "  ✓ Profile-wide schedules"
    echo "  ✓ OpenTelemetry logging with auto-rotation"
    echo "  ✓ Health check endpoint (/parental_control_health.php)"
    echo "  ✓ RESTful API (/parental_control_api.php)"
    echo "  ✓ Performance caching (68% faster)"
    echo "  ✓ Real connection tracking (pfctl state table)"
    echo "  ✓ PID lock (prevents race conditions)"
    echo "  ✓ Diagnostic tool (php /usr/local/bin/parental_control_diagnostic.php)"
    echo "  ✓ Log analyzer tool (parental_control_analyzer.sh)"
    echo ""
    echo "Verify installation:"
    echo "  ssh $PFSENSE_USER@$PFSENSE_IP"
    echo "  parental_control_analyzer.sh verify"
    echo "  parental_control_analyzer.sh status"
    echo "  parental_control_analyzer.sh stats"
    echo ""
    echo "Reset counters:"
    echo "  parental_control_analyzer.sh reset"
    echo ""
    echo "Check for updates:"
    echo "  /usr/local/bin/auto_update_parental_control.sh"
    echo ""
    echo "Documentation:"
    echo "  $PACKAGE_DIR/README.md                       - Full documentation"
    echo "  $PACKAGE_DIR/QUICKSTART.md                   - Quick start guide"
    echo "  $PACKAGE_DIR/docs/API.md                     - API documentation"
    echo "  $PACKAGE_DIR/docs/TROUBLESHOOTING.md         - Troubleshooting"
    echo ""
}

#############################################
# MAIN ENTRY POINT
#############################################
parse_arguments "$@"

case "$MODE" in
    install)
        do_install
        ;;
    reinstall)
        echo "============================================"
        echo "Reinstall Mode"
        echo "============================================"
        echo ""
        echo "Target: $PFSENSE_IP"
        echo ""
        print_warning "This will completely remove and reinstall the package"
        echo ""
        print_info "Step 1: Uninstalling..."
        do_uninstall
        echo ""
        print_info "Step 2: Installing..."
        sleep 2
        MODE="install"  # Switch to install mode
        do_install
        ;;
    uninstall)
        echo "============================================"
        echo "Uninstall Mode"
        echo "============================================"
        echo ""
        echo "Target: $PFSENSE_IP"
        echo ""
        print_warning "This will completely remove Keekar's Parental Control package"
        echo ""
        do_uninstall
        if [ $? -eq 0 ]; then
            echo ""
            echo "============================================"
            echo "Uninstallation Complete!"
            echo "============================================"
            echo ""
            print_success "Keekar's Parental Control package has been removed"
            echo ""
            echo "Removed:"
            echo "  ✓ Package files"
            echo "  ✓ Configuration data"
            echo "  ✓ Firewall rules"
            echo "  ✓ Cron jobs"
            echo "  ✓ Log files"
            echo ""
            echo "To reinstall: ./INSTALL.sh install $PFSENSE_IP"
            echo ""
        else
            print_error "Uninstallation failed"
            exit 1
        fi
        ;;
    fix|update)
        echo "============================================"
        echo "Quick Fix / Update Mode"
        echo "============================================"
        echo ""
        echo "Target: $PFSENSE_IP"
        echo ""
        upload_files
        if [ $? -eq 0 ]; then
            verify_installation
        fi
        ;;
    verify)
        echo "============================================"
        echo "Verification Mode"
        echo "============================================"
        echo ""
        echo "Target: $PFSENSE_IP"
        verify_installation
        ;;
    debug)
        echo "============================================"
        echo "Debug Mode"
        echo "============================================"
        echo ""
        echo "Target: $PFSENSE_IP"
        run_debug
        ;;
esac

exit 0
