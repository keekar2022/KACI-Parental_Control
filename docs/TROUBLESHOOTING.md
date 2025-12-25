# Troubleshooting Guide
## KACI Parental Control - Common Issues & Solutions

**Version:** 0.1.3  
**Author:** Mukesh Kesharwani  
**Last Updated:** December 25, 2025

---

## Table of Contents

1. [Installation Issues](#installation-issues)
2. [Service Not Working](#service-not-working)
3. [Devices Not Being Blocked](#devices-not-being-blocked)
4. [Time Tracking Issues](#time-tracking-issues)
5. [Performance Problems](#performance-problems)
6. [Log File Issues](#log-file-issues)
7. [Configuration Problems](#configuration-problems)
8. [Diagnostic Commands](#diagnostic-commands)

---

## Installation Issues

### Package Not Appearing in pfSense Menu

**Symptoms:**
- Can't find "Keekar's Parental Control" in Services menu
- Package seems installed but menu missing

**Diagnosis:**
```bash
ssh admin@192.168.1.1

# Check if files are present
ls -la /usr/local/pkg/parental_control*

# Expected output:
# parental_control.inc
# parental_control.xml
# parental_control_profiles.xml
```

**Solutions:**

**Option 1: Verify Installation**
```bash
./INSTALL.sh verify 192.168.1.1
```

**Option 2: Check System Log**
```bash
ssh admin@192.168.1.1
tail -50 /var/log/system.log | grep parental
```

**Option 3: Reinstall Package**
```bash
./INSTALL.sh reinstall 192.168.1.1
```

**Option 4: Manual Menu Refresh**
```bash
ssh admin@192.168.1.1
rm /tmp/menu_cache
/etc/rc.restart_webgui
```

### Installation Script Fails

**Symptoms:**
- Installation script exits with error
- SSH connection fails
- Permission denied errors

**Common Causes & Solutions:**

**1. SSH Not Enabled:**
- pfSense > System > Advanced > Admin Access
- Check "Enable Secure Shell"
- Click Save

**2. SSH Key Authentication Failed:**
```bash
# Generate new SSH key
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519

# Copy to pfSense
ssh-copy-id admin@192.168.1.1
```

**3. Sudo Not Configured:**
```bash
# Install with setup (includes sudo config)
./INSTALL.sh install 192.168.1.1
```

**4. Wrong IP Address:**
```bash
# Test pfSense connectivity
ping 192.168.1.1
ssh admin@192.168.1.1
```

---

## Service Not Working

### Service Shows as Disabled

**Symptoms:**
- Status page shows "Service is disabled"
- No firewall rules being created
- No time tracking happening

**Solution:**
1. Go to **Services > Keekar's Parental Control**
2. Go to **Settings** tab
3. Check ✅ **Enable Parental Control**
4. Click **Save**

**Verify:**
```bash
ssh admin@192.168.1.1
cat /cf/conf/config.xml | grep -A5 "parentalcontrol"
# Should show <enable>on</enable>
```

### Cron Job Not Running

**Symptoms:**
- Time not being tracked
- No log entries being created
- Usage always shows 0:00

**Diagnosis:**
```bash
ssh admin@192.168.1.1
crontab -l | grep parental
# Should show: */1 * * * * ...
```

**Solutions:**

**Option 1: Reinstall Cron Job**
```bash
ssh admin@192.168.1.1
# Remove old entry
crontab -l | grep -v parental | crontab -

# Reinstall package (recreates cron job)
cd /path/to/KACI-Parental_Control
./INSTALL.sh fix 192.168.1.1
```

**Option 2: Manual Cron Entry**
```bash
ssh admin@192.168.1.1
crontab -e
# Add this line:
*/1 * * * * /usr/local/bin/php /usr/local/bin/parental_control_cron.php
```

**Option 3: Check Cron Log**
```bash
ssh admin@192.168.1.1
tail -f /var/log/cron
# Watch for parental control execution
```

---

## Devices Not Being Blocked

### Device Has Internet Access Despite Limit Exceeded

**Symptoms:**
- Device shows "Time Exceeded" but still has access
- Rules visible in firewall but not blocking
- Child can still browse

**Diagnosis Steps:**

**1. Check Enforcement Mode:**
- Settings tab > Enforcement Mode
- Must be "Strict" or "Moderate" (not "Soft")

**2. Verify MAC Address:**
```bash
ssh admin@192.168.1.1
arp -an | grep 192.168.1.100  # Replace with device IP
# Verify MAC matches configuration
```

**3. Check Firewall Rules:**
- Firewall > Rules > LAN
- Look for "Parental Control: ..." rules
- Should be at or near top of rule list

**4. Check Rule Order:**
```bash
ssh admin@192.168.1.1
pfctl -sr | grep -A2 -B2 "Parental Control"
# Rules should appear before allow rules
```

**Solutions:**

**Option 1: Rule Order Issue**
- Firewall > Rules > LAN
- Drag parental control rules to TOP of list
- Click Save > Apply Changes

**Option 2: Wrong MAC Address**
1. Status > DHCP Leases
2. Find device, copy exact MAC address
3. Update device configuration with correct MAC
4. Save configuration

**Option 3: Device Using Different Network**
- Check if device has cellular/mobile data
- Verify device is on correct WiFi network
- Disable mobile data on device

**Option 4: Cache Issue**
```bash
ssh admin@192.168.1.1
/etc/rc.filter_configure
/etc/rc.restart_webgui
```

### Grace Period Not Expiring

**Symptoms:**
- Device still has access after grace period
- Grace period seems to last forever

**Check Grace Period Setting:**
- Settings tab > Grace Period
- Default is 5 minutes
- Set to 0 to disable grace period

**Verify Time Tracking:**
```bash
ssh admin@192.168.1.1
cat /var/db/parental_control_state.json | jq '.devices'
# Check usage_today value
```

---

## Time Tracking Issues

### Time Not Being Tracked

**Symptoms:**
- Usage always shows 0:00
- Status page shows no activity
- Device online but time not incrementing

**Diagnosis:**

**1. Check Device Status:**
- Go to Status tab
- Is device showing as "Online" or "Offline"?

**2. Verify ARP Entry:**
```bash
ssh admin@192.168.1.1
arp -an | grep "aa:bb:cc:dd:ee:ff"  # Your MAC
# Should return a line if device is connected
```

**3. Check Cron Job:**
```bash
ssh admin@192.168.1.1
crontab -l | grep parental
# Verify cron job exists
```

**4. Check Logs:**
```bash
ssh admin@192.168.1.1
tail -20 /var/log/parental_control-$(date +%Y-%m-%d).jsonl | jq '.'
# Look for "Device X is online" messages
```

**Solutions:**

**Option 1: Cron Job Missing**
```bash
./INSTALL.sh fix 192.168.1.1
```

**Option 2: State File Issue**
```bash
ssh admin@192.168.1.1
rm /var/db/parental_control_state.json
# Will be recreated automatically
```

**Option 3: Wrong MAC Address**
- Update device with correct MAC from ARP table

### Usage Resets Unexpectedly

**Symptoms:**
- Usage counter resets to 0:00 during the day
- Unexpected resets outside of reset time

**Check Reset Time:**
- Settings tab > Reset Time
- Default: Midnight
- Verify this matches your expectation

**Check System Time:**
```bash
ssh admin@192.168.1.1
date
# Verify timezone and time are correct
```

**Check State File:**
```bash
ssh admin@192.168.1.1
cat /var/db/parental_control_state.json | jq '.last_reset'
# Shows Unix timestamp of last reset
```

---

## Performance Problems

### pfSense Running Slow

**Symptoms:**
- Web UI sluggish
- High CPU usage
- Slow response times

**Diagnosis:**

**1. Check Log File Size:**
```bash
ssh admin@192.168.1.1
ls -lh /var/log/parental_control-*.jsonl
# Files should be < 5MB each
```

**2. Check Cron Frequency:**
```bash
crontab -l | grep parental
# Should be */1 (every minute)
```

**3. Check System Load:**
```bash
top
# Look for parental_control_cron.php
```

**Solutions:**

**Option 1: Reduce Log Level**
- Settings tab > Log Level > "Warning" or "Error"
- Reduces log volume

**Option 2: Increase Cron Interval**
```bash
export PC_CRON_MINUTE='*/2'  # Every 2 minutes
./INSTALL.sh fix 192.168.1.1
```

**Option 3: Clean Up Old Logs**
```bash
ssh admin@192.168.1.1
find /var/log -name "parental_control-*.jsonl" -mtime +7 -delete
```

**Option 4: Reduce Check Frequency**
- Settings tab > Check Interval > 120 seconds
- Less frequent checks = lower overhead

### Disk Space Full

**Symptoms:**
- "No space left on device" errors
- Web UI fails to save configuration
- System unstable

**Check Disk Usage:**
```bash
ssh admin@192.168.1.1
df -h
# Check / (root) filesystem usage
```

**Solutions:**

**Option 1: Clean Up Log Files**
```bash
ssh admin@192.168.1.1
# Delete old parental control logs
rm /var/log/parental_control-*.jsonl

# Clean other logs
rm /var/log/*.old
```

**Option 2: Reduce Log Size**
```bash
export PC_MAX_LOG_SIZE=1048576   # 1MB per file
export PC_MAX_LOG_FILES=5        # Keep only 5 files
```

**Option 3: Disable Logging Temporarily**
- Settings tab > Uncheck "Enable Logging"
- Save configuration

---

## Log File Issues

### Log Files Not Being Created

**Symptoms:**
- No log files in /var/log/
- Status page shows no log entries
- Debugging is difficult

**Diagnosis:**
```bash
ssh admin@192.168.1.1

# Check if directory exists
ls -ld /var/log/

# Check permissions
ls -l /var/log/ | grep parental
```

**Solutions:**

**Option 1: Enable Logging**
- Settings tab > Check "Enable Logging"
- Save configuration

**Option 2: Fix Permissions**
```bash
ssh admin@192.168.1.1
chmod 755 /var/log
touch /var/log/parental_control-$(date +%Y-%m-%d).jsonl
chmod 644 /var/log/parental_control-*.jsonl
```

**Option 3: Manually Trigger Log Entry**
```bash
ssh admin@192.168.1.1
/usr/local/bin/php /usr/local/bin/parental_control_cron.php
# Should create log file
```

### Cannot Parse Log Files

**Symptoms:**
- Log files exist but can't read them
- `jq` returns parse errors
- Log format looks wrong

**Check Log Format:**
```bash
ssh admin@192.168.1.1
head -1 /var/log/parental_control-$(date +%Y-%m-%d).jsonl
# Should be valid JSON on single line
```

**Solution: Install jq if missing:**
```bash
ssh admin@192.168.1.1
pkg install jq
```

**View Logs Without jq:**
```bash
cat /var/log/parental_control-*.jsonl | python -m json.tool
```

---

## Configuration Problems

### Configuration Changes Not Saving

**Symptoms:**
- Click Save but changes disappear
- Settings revert to defaults
- Can't modify profiles

**Solutions:**

**Option 1: Check Disk Space**
```bash
df -h
# Root filesystem must have free space
```

**Option 2: Check Config File Permissions**
```bash
ssh admin@192.168.1.1
ls -l /cf/conf/config.xml
# Should be writable by admin
```

**Option 3: Clear Browser Cache**
- Hard refresh: Ctrl+Shift+R (or Cmd+Shift+R on Mac)
- Clear browser cache completely
- Try incognito/private window

**Option 4: Backup and Restore Config**
```bash
# Backup current config
cp /cf/conf/config.xml /root/config.xml.backup

# Try saving again
```

### Profile or Device Disappeared

**Symptoms:**
- Profile/device was configured but now missing
- No entry in configuration

**Check Configuration:**
```bash
ssh admin@192.168.1.1
cat /cf/conf/config.xml | grep -A20 "parentalcontrolprofiles"
```

**Solutions:**

**Option 1: Restore from Backup**
- pfSense > Diagnostics > Backup & Restore
- Restore from recent backup

**Option 2: Check Config History**
```bash
ssh admin@192.168.1.1
ls -lt /cf/conf/backup/config-*.xml | head -5
# Find backup from before deletion
```

---

## Diagnostic Commands

### Quick Diagnostics

**All-in-One Diagnostic Script:**
```bash
./INSTALL.sh debug 192.168.1.1
```

**Manual Diagnostic Commands:**

**1. Check Service Status:**
```bash
ssh admin@192.168.1.1 "grep -c 'enable>on' /cf/conf/config.xml"
```

**2. Check File Integrity:**
```bash
ssh admin@192.168.1.1 "php -l /usr/local/pkg/parental_control.inc"
```

**3. Check Cron Job:**
```bash
ssh admin@192.168.1.1 "crontab -l | grep parental"
```

**4. Check Firewall Rules:**
```bash
ssh admin@192.168.1.1 "pfctl -sr | grep 'Parental Control'"
```

**5. Check ARP Table:**
```bash
ssh admin@192.168.1.1 "arp -an"
```

**6. Check State File:**
```bash
ssh admin@192.168.1.1 "cat /var/db/parental_control_state.json | jq '.'"
```

**7. Check Recent Logs:**
```bash
ssh admin@192.168.1.1 "tail -20 /var/log/parental_control-$(date +%Y-%m-%d).jsonl | jq '.'"
```

**8. Test Health Endpoint:**
```bash
curl -s http://192.168.1.1/parental_control_health.php | jq '.'
```

### Advanced Diagnostics

**Monitor in Real-Time:**
```bash
# Watch cron execution
ssh admin@192.168.1.1 "tail -f /var/log/cron"

# Watch parental control logs
ssh admin@192.168.1.1 "tail -f /var/log/parental_control-$(date +%Y-%m-%d).jsonl"

# Watch firewall logs
ssh admin@192.168.1.1 "tcpdump -i lan0 -n host 192.168.1.100"  # Device IP
```

**Performance Monitoring:**
```bash
# Check system load
ssh admin@192.168.1.1 "uptime"

# Check memory usage
ssh admin@192.168.1.1 "free -m"

# Check PHP processes
ssh admin@192.168.1.1 "ps aux | grep php"
```

---

## Getting Help

### Before Requesting Support

Please collect this information:

1. **Version Information:**
   ```bash
   cat /Users/mkesharw/Documents/KACI-Parental_Control/VERSION
   ```

2. **System Information:**
   ```bash
   ssh admin@192.168.1.1 "uname -a"
   ssh admin@192.168.1.1 "cat /etc/version"
   ```

3. **Recent Logs:**
   ```bash
   ssh admin@192.168.1.1 "tail -50 /var/log/parental_control-$(date +%Y-%m-%d).jsonl"
   ```

4. **Configuration (sanitized):**
   ```bash
   ssh admin@192.168.1.1 "cat /cf/conf/config.xml | grep -A50 parentalcontrol"
   # Remove any sensitive information before sharing
   ```

### Support Resources

- **GitHub Issues:** https://github.com/keekar2022/KACI-Parental_Control/issues
- **Documentation:** `/README.md`, `/QUICKSTART.md`, `/docs/`
- **Health Check:** `http://your-pfsense-ip/parental_control_health.php`

---

**Built with Passion by Mukesh Kesharwani**  
**© 2025 Keekar**  
**Version 0.1.3**

