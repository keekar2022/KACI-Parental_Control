# Deployment Instructions - v1.5.1 Critical Fixes

## Quick Summary

**What's Fixed:**
1. ✅ Gaming services domain resolution (no more pfctl errors)
2. ✅ Boot sequence blocking (no more "Loading keekar Parental Control" hang)

**Upgrade Time:** < 2 minutes
**Downtime:** None (zero downtime upgrade)
**Compatibility:** 100% backward compatible with v1.5.0

---

## Prerequisites

- SSH access to pfSense
- Admin credentials
- Existing KACI Parental Control v1.5.0 (or earlier) installed

---

## Deployment Methods

### Method 1: Automated Deployment (Recommended)

**From your development machine:**

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev
./INSTALL.sh update <pfsense_ip>
```

**What it does:**
- Copies updated files to pfSense
- Sets correct permissions
- Triggers configuration sync
- Verifies installation

**Expected output:**
```
✓ Ensuring passwordless authentication...
✓ Passwordless SSH connection successful
✓ Passwordless sudo already configured
✓ Uploading package files...
✓ Core files copied to /tmp/
✓ Installing files to system directories...
✓ Files installed
✓ Triggering package configuration sync...
✓ Package configuration synced
✓ All files present
✓ No PHP syntax errors
```

---

### Method 2: Manual Deployment

**Step 1: Copy files to pfSense**

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev

# Copy updated files
scp parental_control.inc parental_control_services.php VERSION admin@<pfsense_ip>:/tmp/
```

**Step 2: SSH to pfSense**

```bash
ssh admin@<pfsense_ip>
```

**Step 3: Install files**

```bash
# Move files to correct locations
sudo mv /tmp/parental_control.inc /usr/local/pkg/
sudo mv /tmp/parental_control_services.php /usr/local/www/
sudo mv /tmp/VERSION /usr/local/pkg/parental_control_VERSION

# Set permissions
sudo chmod 644 /usr/local/pkg/parental_control.inc
sudo chmod 644 /usr/local/www/parental_control_services.php
sudo chmod 644 /usr/local/pkg/parental_control_VERSION
```

**Step 4: Trigger sync**

```bash
# Run full sync (uses new boot-aware logic)
sudo /usr/local/bin/php -r "
require_once('/usr/local/pkg/parental_control.inc');
pc_sync_full();
"
```

**Step 5: Verify**

```bash
# Check version
cat /usr/local/pkg/parental_control_VERSION
# Should show: VERSION=1.5.1

# Check logs for errors
tail -50 /var/log/system.log | grep "Parental Control"
```

---

## Post-Deployment Verification

### 1. Verify Version

**Via SSH:**
```bash
cat /usr/local/pkg/parental_control_VERSION
```

**Expected output:**
```
VERSION=1.5.1
BUILD_DATE=2026-02-02
RELEASE_TYPE=critical_bugfix_release
STATUS=stable
```

**Via Web UI:**
- Navigate to `Services > Keekar's Parental Control > Settings`
- Scroll to bottom footer
- Should show: "Keekar's Parental Control v1.5.1 | Build Date: 2026-02-02"

### 2. Test Domain Resolution Fix

**Test Gaming Services:**

1. **Navigate to Online Services tab**
   - Go to `Services > Keekar's Parental Control > Online-Service`

2. **Click "Verify" on Gaming service**
   - Should see: "Successfully verified X of Y URLs"
   - No errors about unresolvable domains

3. **Click "Monitor & Block" on Gaming service**
   - Should see: "Successfully created URL Table alias..."
   - Check logs:
     ```bash
     tail -30 /var/log/system.log | grep "PC_Service"
     ```
   - Should see:
     ```
     Downloaded X IPs from https://raw.githubusercontent.com/...
     Processed Y domains: Z resolved, W failed
     Domain resolution summary: ...
     Loaded PC_Service_Online_Gaming into pf table successfully
     ```

4. **Verify pfctl table**
   ```bash
   sudo pfctl -t PC_Service_Online_Gaming -T show | head -20
   ```
   - Should show list of IP addresses (no error)

**Expected Behavior:**
- ✅ No "no IP address found" errors
- ✅ Table loads successfully with IPs
- ✅ Graceful handling of unresolvable domains
- ✅ Detailed logging with resolution summary

### 3. Test Boot Sequence Fix

**Test Option 1: Safe Test (Check logs first)**

```bash
# Check if post-boot script exists
ls -l /tmp/parental_control_post_boot.sh

# Check post-boot log
cat /var/log/parental_control_post_boot.log

# Check system log for boot context detection
grep "Boot sync" /var/log/system.log
```

**Test Option 2: Full Boot Test (Reboot firewall)**

⚠️ **Warning:** This will reboot your firewall. Schedule during maintenance window.

```bash
# 1. SSH to pfSense
ssh admin@<pfsense_ip>

# 2. Tail logs in background (before reboot)
tail -f /var/log/system.log > /tmp/boot_log.txt 2>&1 &

# 3. Reboot
sudo reboot

# 4. Wait for boot to complete
# - Watch console or SSH reconnect
# - Should see "Enter an Option:" prompt within 1 minute

# 5. Reconnect and check logs
ssh admin@<pfsense_ip>
grep "Boot sync" /var/log/system.log
cat /var/log/parental_control_post_boot.log
```

**Expected Boot Behavior:**
- ✅ Boot completes normally (no hang)
- ✅ "Enter an Option:" prompt appears within 45-60 seconds
- ✅ No "Loading keekar Parental Control" freeze
- ✅ System log shows "Boot sync: Minimal initialization"
- ✅ Post-boot log shows "Full sync completed"

---

## Rollback Instructions (If Needed)

If you encounter issues and need to rollback to v1.5.0:

### Option 1: Using Git

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev
git checkout v1.5.0
./INSTALL.sh update <pfsense_ip>
```

### Option 2: Manual Restore

If you have backups of v1.5.0 files:

```bash
# Copy backup files to pfSense
scp parental_control.inc.v1.5.0 admin@<pfsense_ip>:/tmp/parental_control.inc
scp parental_control_services.php.v1.5.0 admin@<pfsense_ip>:/tmp/parental_control_services.php

# SSH to pfSense
ssh admin@<pfsense_ip>

# Restore files
sudo mv /tmp/parental_control.inc /usr/local/pkg/
sudo mv /tmp/parental_control_services.php /usr/local/www/
sudo chmod 644 /usr/local/pkg/parental_control.inc
sudo chmod 644 /usr/local/www/parental_control_services.php

# Trigger sync
sudo /usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); parental_control_sync();"
```

---

## Troubleshooting

### Issue: "Domain resolution shows many failures"

**Cause:** DNS not properly configured or unreachable

**Fix:**
1. Check DNS configuration:
   ```bash
   # Via Web UI:
   System > General Setup > DNS Servers
   
   # Via SSH:
   cat /etc/resolv.conf
   ```

2. Test DNS resolution:
   ```bash
   nslookup steamcommunity.com
   dig steamcommunity.com
   ```

3. Verify DNS servers are reachable:
   ```bash
   ping -c 3 8.8.8.8
   ping -c 3 1.1.1.1
   ```

**Note:** Some domain resolution failures are normal (old/deprecated domains in v2fly lists). Success rate of 90%+ is expected.

---

### Issue: "Post-boot sync not running"

**Cause:** Script not created or execution failed

**Diagnosis:**
```bash
# Check if script was created
ls -l /tmp/parental_control_post_boot.sh

# Check post-boot log
cat /var/log/parental_control_post_boot.log

# Check system uptime (should be >5 minutes for post-boot to complete)
uptime
```

**Fix:**
```bash
# Manually trigger full sync
sudo /usr/local/bin/php -r "
require_once('/usr/local/pkg/parental_control.inc');
pc_sync_full();
"
```

---

### Issue: "Boot still slow after upgrade"

**Cause:** Other pfSense packages or system issues

**Diagnosis:**
1. Check Parental Control timing:
   ```bash
   grep "sync.*complete" /var/log/system.log | tail -5
   ```
   - Boot sync should show "<2 seconds"
   - Full sync should show "~10-30 seconds"

2. Check for other packages:
   ```bash
   # List installed packages
   pkg info | grep pfSense-pkg
   
   # Check system log for other slow operations
   grep "Loading" /var/log/system.log
   ```

**Fix:**
- If Parental Control times look good, issue is elsewhere
- Check other packages for boot delays
- Review pfSense boot logs for bottlenecks

---

### Issue: "Gaming services still not blocking"

**Cause:** Table not loaded or firewall rules not applied

**Diagnosis:**
1. Check if table exists:
   ```bash
   sudo pfctl -t PC_Service_Online_Gaming -T show
   ```

2. Check if rules exist:
   ```bash
   sudo pfctl -sr | grep "PC_Service"
   ```

3. Check alias configuration:
   - Navigate to `Firewall > Aliases`
   - Look for `PC_Service_Online_Gaming`
   - Should show "URL Table (IPs)" type

**Fix:**
1. Manually trigger alias creation:
   - Go to `Services > Keekar's Parental Control > Online-Service`
   - Click "Monitor & Block" on Gaming service

2. Reload filter:
   ```bash
   sudo /usr/local/bin/php -r "
   require_once('/etc/inc/filter.inc');
   filter_configure();
   "
   ```

---

## Support

If you encounter issues not covered here:

1. **Check logs:**
   ```bash
   tail -100 /var/log/system.log
   tail -50 /var/log/parental_control*.jsonl
   cat /var/log/parental_control_post_boot.log
   ```

2. **Enable debug mode:**
   - `Services > Keekar's Parental Control > Settings`
   - Set "Log Level" to "Debug - Very detailed"
   - Save and reproduce issue

3. **Collect diagnostic information:**
   ```bash
   # System info
   uname -a
   uptime
   
   # Package version
   cat /usr/local/pkg/parental_control_VERSION
   
   # Configuration
   sudo grep -A 20 "parentalcontrol" /cf/conf/config.xml
   
   # Recent logs (last 100 lines)
   tail -100 /var/log/system.log > /tmp/pc_debug.log
   ```

4. **Contact developer:**
   - Include log files
   - Describe issue and steps to reproduce
   - Provide system information

---

## Post-Deployment Checklist

After deployment, verify the following:

- [ ] Version shows v1.5.1 in Web UI footer
- [ ] VERSION file shows 1.5.1
- [ ] Gaming services can be verified without errors
- [ ] Gaming services can be added with "Monitor & Block"
- [ ] pfctl tables load successfully (no "no IP address found" errors)
- [ ] System logs show domain resolution statistics
- [ ] Boot completes normally (test reboot)
- [ ] No boot hangs at "Loading keekar Parental Control"
- [ ] Post-boot log shows full sync completed
- [ ] Existing parental control rules still work
- [ ] Device blocking still functions correctly
- [ ] Captive portal still accessible

---

## Next Steps

After successful deployment:

1. **Monitor for 24 hours**
   - Check logs periodically
   - Verify blocking is working
   - Test gaming service blocks

2. **Test gaming services**
   - Add/remove gaming services
   - Verify blocking works
   - Check domain resolution logs

3. **Schedule future updates**
   - Watch for v1.5.2 release
   - Plan for async domain resolution feature
   - Consider enabling auto-updates (dev environments only)

---

## Additional Resources

- **Full deployment log:** `logs/CRITICAL_FIXES_v1.5.1_FEB_2026.md`
- **User guide:** `docs/USER_GUIDE.md`
- **API documentation:** `docs/API.md`
- **Troubleshooting guide:** `docs/TROUBLESHOOTING.md`

---

**Deployment prepared by:** AI Assistant  
**Date:** February 2, 2026  
**Version:** 1.5.1  
**Priority:** Critical (Production Issues)

---

**End of Deployment Instructions**
