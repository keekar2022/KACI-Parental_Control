# 🔧 HOTFIX v1.0.1 - Critical Cron Job Installation Fix

**Release Date:** December 29, 2025  
**Severity:** CRITICAL  
**Impact:** HIGH - All v1.0.0 users should upgrade immediately

---

## 🐛 Problem Identified

During production deployment, we discovered that the cron job responsible for:
- Usage tracking (every 5 minutes)
- Daily counter reset (at midnight)
- Schedule enforcement

**Was NOT being installed reliably** using pfSense's `install_cron_job()` function.

### Symptoms Observed
1. ✗ Daily usage counters **not resetting at midnight**
2. ✗ Usage showing **yesterday's data** (e.g., 6:25 hours at 6:10 AM)
3. ✗ Devices showing **"Time Exceeded"** immediately after midnight
4. ✗ No usage tracking happening (devices stuck at 0 or old values)

---

## ✅ Solution Implemented

Enhanced `pc_setup_cron_job()` function in `parental_control.inc` with a **dual-method approach**:

### Primary Method
- Uses pfSense's native `install_cron_job()` function
- Attempts to install via pfSense's cron management system
- Verifies installation by checking actual crontab

### Fallback Method (NEW)
- Direct crontab manipulation if primary fails
- Reads current crontab
- Adds parental control entry if not present
- Writes back to crontab
- More reliable across different pfSense versions

### Verification (NEW)
- After each method, checks if cron was actually installed
- Logs success/failure for troubleshooting
- Ensures cron job persists across reboots

---

## 📝 Technical Changes

### Modified Files
1. **`parental_control.inc`**
   - Enhanced `pc_setup_cron_job()` function (lines 1782-1860)
   - Added fallback crontab manipulation
   - Added verification checks
   - Improved error logging

2. **`VERSION`**
   - Updated: 1.0.0 → 1.0.1
   - Build date: 2025-12-29
   - Release type: hotfix

3. **`info.xml`** & **`parental_control.xml`**
   - Updated version tags to 1.0.1

4. **`CHANGELOG.md`**
   - Added v1.0.1 section with detailed fix description

5. **`index.html`**
   - Updated version display to 1.0.1

---

## 🚀 Upgrade Instructions

### For New Installations
Simply run the latest `INSTALL.sh` - the fix is included.

```bash
cd /path/to/KACI-Parental_Control
./INSTALL.sh
```

### For Existing v1.0.0 Users

#### Option 1: Auto-Update (Recommended)
The auto-update system will pull v1.0.1 automatically within 15 minutes.

#### Option 2: Manual Update
```bash
# On your local machine
cd /path/to/KACI-Parental_Control
git pull origin main

# Deploy to firewall
./INSTALL.sh
```

#### Option 3: Quick Fix (Immediate)
If you need the fix RIGHT NOW:

```bash
# SSH to your pfSense firewall
ssh mkesharw@fw.keekar.com

# Manually install cron job
echo "*/5 * * * * /usr/local/bin/php /usr/local/bin/parental_control_cron.php" | sudo crontab -

# Verify
sudo crontab -l | grep parental

# Manually reset counters
sudo php -r "require_once('/etc/inc/config.inc'); require_once('/usr/local/pkg/parental_control.inc'); \$state = pc_load_state_from_disk(); pc_reset_daily_counters(\$state); \$state['last_reset'] = time(); \$state['blocked_devices'] = []; pc_save_state(\$state);"
```

---

## ✅ Verification

After upgrading, verify the fix:

1. **Check Cron Installation**
   ```bash
   ssh mkesharw@fw.keekar.com 'sudo crontab -l | grep parental'
   ```
   Expected output:
   ```
   */5 * * * * /usr/local/bin/php /usr/local/bin/parental_control_cron.php
   ```

2. **Check Status Page**
   - Navigate to: Services → KACI Parental Control → Status
   - Verify "Last Check" timestamp updates every 5 minutes
   - Verify "Last Reset" shows today's midnight (00:00:00)
   - Verify all devices show correct usage (not yesterday's data)

3. **Check State File**
   ```bash
   ssh mkesharw@fw.keekar.com 'cat /var/db/parental_control_state.json | jq ".last_reset, .last_check"'
   ```

---

## 📊 Testing Results

### Production Environment
- **Firewall:** fw.keekar.com (pfSense 2.7.2)
- **Test Date:** December 29, 2025
- **Result:** ✅ PASS

**Before Fix:**
- Crontab: Empty (no parental control entry)
- Usage: 385 minutes (6:25 hrs) at 6:10 AM
- Status: Devices showing "Time Exceeded"

**After Fix:**
- Crontab: ✅ Installed correctly
- Usage: 0 minutes (reset successful)
- Status: All devices online with full time remaining
- Tracking: Working (5-minute increments)

---

## 🎯 Impact Assessment

### Severity: CRITICAL
- **Without this fix:** Package is non-functional
- **Affected users:** All v1.0.0 installations
- **Upgrade urgency:** IMMEDIATE

### User Impact
- **Parents:** Daily limits not enforced correctly
- **Children:** May have unlimited access or be blocked incorrectly
- **System:** Usage statistics inaccurate

---

## 🔮 Future Improvements

To prevent similar issues:
1. Add automated installation tests to `INSTALL.sh`
2. Create post-installation verification script
3. Add cron health check to status page
4. Consider moving to pfSense's package system cron management

---

## 📞 Support

If you encounter issues after upgrading:

1. **Check Logs:**
   ```bash
   ssh mkesharw@fw.keekar.com 'tail -50 /var/log/system.log | grep parental'
   ```

2. **Manual Cron Fix:**
   See "Option 3: Quick Fix" above

3. **GitHub Issues:**
   https://github.com/keekar2022/KACI-Parental_Control/issues

---

## 📜 Changelog Entry

```markdown
## [1.0.1] - 2025-12-29 🔧 CRITICAL HOTFIX

### Fixed
- **Cron Job Installation**: Enhanced pc_setup_cron_job() with dual-method approach
  - Primary: Uses pfSense's install_cron_job() function
  - Fallback: Direct crontab manipulation if primary method fails
  - Verification: Checks if cron was actually installed after each method
- **Daily Reset**: Now works reliably as cron job is guaranteed to be installed
- **Usage Tracking**: Devices now properly track usage every 5 minutes

### Impact
- HIGH: Without this fix, daily usage counters would not reset at midnight
- HIGH: Usage tracking would not work at all without the cron job
- Recommendation: All v1.0.0 users should upgrade immediately
```

---

**Built with ❤️ by Mukesh Kesharwani**  
**© 2025 Keekar**

