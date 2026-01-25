# Migration Guide: Legacy Installation → PKG Manager

This guide explains how to migrate from the legacy raw file installation method to the new FreeBSD package manager distribution.

---

## Overview

**Old Method (Legacy):**
- Files downloaded directly from GitHub
- Manual installation via `INSTALL.sh`
- Updates via `auto_update_parental_control.sh` pulling raw files

**New Method (Recommended):**
- Binary `.txz` packages built via GitHub Actions
- Installation via FreeBSD `pkg` manager
- Updates via standard `pkg upgrade` commands
- Source code protected (private repository)

---

## Benefits of PKG Manager Distribution

✅ **Source Code Protection** - Binary distribution, source code stays private  
✅ **Easier Installation** - Single command installation  
✅ **Automatic Updates** - Integrated with FreeBSD pkg system  
✅ **Dependency Management** - Automatic dependency resolution  
✅ **Package Signing** - GPG-signed packages for security  
✅ **Version Control** - Easy rollback and version management  
✅ **Professional Distribution** - Industry-standard package management  

---

## Prerequisites

- Existing KACI Parental Control installation (v1.4.32 or later)
- pfSense/FreeBSD system with network access
- SSH or console access to pfSense
- Backup of your configuration (recommended)

---

## Migration Steps

### Option 1: Automated Migration (Recommended)

```bash
# Download migration script
fetch -o /tmp/migrate-to-pkg.sh https://raw.githubusercontent.com/keekar2022/KACI-Parental_Control/main/client-setup/migrate-to-pkg.sh

# Make executable
chmod +x /tmp/migrate-to-pkg.sh

# Run migration
/tmp/migrate-to-pkg.sh
```

The script will:
1. Backup current configuration and state
2. Remove legacy cron job
3. Configure pkg repository
4. Install package via pkg manager
5. Restore state and configuration
6. Setup new auto-update mechanism

### Option 2: Manual Migration

#### Step 1: Backup Configuration

```bash
# Backup pfSense config
cp /cf/conf/config.xml /cf/conf/config.xml.pre-pkg-migration

# Backup state file
cp /var/db/parental_control_state.json /var/db/parental_control_state.json.backup

# Note current version
grep "VERSION=" /usr/local/pkg/parental_control_VERSION
```

#### Step 2: Remove Legacy Cron Job

```bash
# Remove old auto-update cron job
crontab -l -u root | grep -v "parental_control" | crontab -u root -
```

#### Step 3: Configure PKG Repository

```bash
# Create repository configuration
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

# Update repository
pkg update
```

#### Step 4: Install via PKG Manager

```bash
# Install package
pkg install -y kaci-parental-control

# Verify installation
pkg info kaci-parental-control
```

#### Step 5: Restore State (if needed)

```bash
# Restore state file
cp /var/db/parental_control_state.json.backup /var/db/parental_control_state.json

# Reload configuration
/usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); parental_control_sync();"
```

#### Step 6: Verify Cron Job

```bash
# Check cron job exists
crontab -l | grep parental_control

# If missing, add it
(crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/php /usr/local/bin/parental_control_cron.php") | crontab -
```

---

## Verification

After migration, verify everything is working:

### 1. Check Package Status

```bash
pkg info kaci-parental-control
```

Expected output:
```
kaci-parental-control-1.4.59
Name           : kaci-parental-control
Version        : 1.4.59
...
```

### 2. Check pfSense Web Interface

1. Go to **Services > Parental Control**
2. Verify profiles and devices are intact
3. Check **Status > Parental Control** for usage data

### 3. Check Logs

```bash
tail -f /var/log/parental_control.jsonl
```

Should show recent activity.

### 4. Test Auto-Update

```bash
# Trigger manual auto-update check
/usr/local/bin/auto_update_parental_control.sh

# Check logs
tail /var/log/parental_control_auto_update.log
```

---

## Troubleshooting

### Issue: Package Not Found

```bash
# Check repository configuration
cat /usr/local/etc/pkg/repos/kaci.conf

# Manually update repository
pkg update -f

# Check available packages
pkg search kaci
```

### Issue: Configuration Lost

```bash
# Restore from backup
cp /cf/conf/config.xml.pre-pkg-migration /cf/conf/config.xml

# Reload pfSense
/etc/rc.reload_all
```

### Issue: Cron Job Not Running

```bash
# Check cron is enabled
service cron status

# Reinstall cron job
(crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/php /usr/local/bin/parental_control_cron.php") | crontab -

# Verify
crontab -l | grep parental
```

### Issue: State File Missing

```bash
# Restore from backup
cp /var/db/parental_control_state.json.backup /var/db/parental_control_state.json

# Or let it regenerate (will lose usage history)
rm /var/db/parental_control_state.json
/usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); parental_control_cron_job();"
```

---

## Rollback Procedure

If migration fails and you need to rollback:

### 1. Uninstall PKG Version

```bash
pkg delete -y kaci-parental-control
rm /usr/local/etc/pkg/repos/kaci.conf
```

### 2. Restore Configuration

```bash
cp /cf/conf/config.xml.pre-pkg-migration /cf/conf/config.xml
cp /var/db/parental_control_state.json.backup /var/db/parental_control_state.json
```

### 3. Reinstall Legacy Version

```bash
# Download and run legacy installer
fetch -o /tmp/INSTALL.sh https://raw.githubusercontent.com/keekar2022/KACI-Parental_Control/main/INSTALL.sh
chmod +x /tmp/INSTALL.sh
/tmp/INSTALL.sh localhost
```

### 4. Restore Cron Job

```bash
(crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/php /usr/local/bin/parental_control_cron.php") | crontab -
```

---

## Post-Migration

### Using PKG Commands

```bash
# Check installed version
pkg info kaci-parental-control

# Check for updates
pkg update
pkg upgrade -n kaci-parental-control

# Upgrade to latest
pkg upgrade kaci-parental-control

# View package files
pkg info -l kaci-parental-control

# View package dependencies
pkg info -d kaci-parental-control
```

### Auto-Update Behavior

- Checks every 15 minutes for new versions
- Automatically upgrades when available
- Logs to `/var/log/parental_control_auto_update.log`
- Reloads configuration after upgrade

### Disable Auto-Update (if needed)

```bash
# Remove auto-update cron job
crontab -l | grep -v "auto_update" | crontab -
```

---

## FAQ

**Q: Will I lose my profiles and devices?**  
A: No, all configuration is preserved in pfSense config.xml and will be automatically migrated.

**Q: Will I lose usage history?**  
A: No, the state file is backed up and restored during migration.

**Q: Can I use both methods?**  
A: No, only one installation method should be active. PKG manager method is recommended.

**Q: How do I update in the future?**  
A: Either wait for auto-update (15 min check interval) or manually run `pkg upgrade kaci-parental-control`.

**Q: What if GitHub goes down?**  
A: PKG repository is hosted on GitHub Pages with 99.9% uptime. In the rare event of GitHub outage, manual installation from releases is possible.

**Q: Is the source code still available?**  
A: Source code access is restricted. Binary packages are distributed via pkg manager.

**Q: How do I uninstall?**  
A: Use `pkg delete kaci-parental-control` to cleanly remove the package.

---

## Support

If you encounter issues during migration:

1. Check this troubleshooting guide
2. Review logs: `/var/log/parental_control.jsonl` and `/var/log/parental_control_auto_update.log`
3. Open an issue: https://github.com/keekar2022/KACI-Parental_Control/issues

---

**Last Updated:** January 24, 2026  
**Applies To:** KACI Parental Control v1.4.59+
