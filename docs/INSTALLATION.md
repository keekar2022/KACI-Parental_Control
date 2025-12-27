# Fresh Installation Complete - v0.9.0

## ✅ Installation Summary

The KACI Parental Control package has been completely removed and freshly reinstalled on your pfSense firewall.

**Date:** December 28, 2025  
**Version:** v0.9.0  
**Firewall:** fw.keekar.com

---

## 🧹 What Was Removed

The `UNINSTALL.sh` script performed a complete cleanup:

1. ✓ All cron jobs removed
2. ✓ All firewall rules removed
3. ✓ All configuration data removed from config.xml
4. ✓ All PHP files removed
5. ✓ All package files removed
6. ✓ All cron scripts removed
7. ✓ All state and log files removed
8. ✓ All anchor files removed
9. ✓ Repository removed
10. ✓ PHP cache cleared

---

## 📦 What Was Installed

Fresh installation via `INSTALL.sh` deployed:

### Core Package Files
- `/usr/local/pkg/parental_control.inc` - Core logic (133KB)
- `/usr/local/pkg/parental_control.xml` - Main settings page definition

### Web Interface Pages
- `/usr/local/www/parental_control_profiles.php` - Profile & device management
- `/usr/local/www/parental_control_schedules.php` - Schedule management
- `/usr/local/www/parental_control_status.php` - Status & monitoring
- `/usr/local/www/parental_control_blocked.php` - Block page with override
- `/usr/local/www/parental_control_api.php` - RESTful API
- `/usr/local/www/parental_control_health.php` - Health check endpoint

### Cron Scripts
- `/usr/local/bin/parental_control_cron.php` - Main cron job (runs every 5 min)
- `/usr/local/bin/auto_update_parental_control.sh` - Auto-update script (runs every 15 min)

---

## 🎯 Next Steps - Testing Your Fresh Installation

### Step 1: Access the Web Interface

1. Open your pfSense web interface: https://fw.keekar.com
2. Navigate to **Services > Keekar's Parental Control**
3. Verify the package is enabled (should show "on")

### Step 2: Create Your First Profile

1. Click the **Profiles** tab
2. Click **+ Add Profile**
3. Fill in the details:
   - **Profile Name:** (e.g., "Vishesh" or "Mukesh")
   - **Daily Time Limit:** (e.g., "8:00" for 8 hours)
   - **Weekend Bonus:** (optional, e.g., "2:00" for 2 extra hours)
   - **Reset Time:** (leave empty for midnight)
4. Click **Save**

### Step 3: Add Devices to Profile

1. After saving the profile, you'll see the device management section
2. Click **Auto-Discover Devices** to see all devices on your network
3. Check the boxes next to the devices you want to add
4. Click **Add Selected Devices**

**OR** manually add a device:
1. Click **+ Add Device**
2. Enter:
   - **Device Name:** (e.g., "MukeshMacPro")
   - **MAC Address:** (e.g., "7e:e8:48:7d:69:0f")
   - **IP Address:** (optional, e.g., "192.168.1.111")
3. Click **Save**

### Step 4: Create a Schedule (Optional)

1. Click the **Schedules** tab
2. Click **+ Add Schedule**
3. Fill in the details:
   - **Schedule Name:** (e.g., "School Hours")
   - **Profiles:** Select which profiles this applies to
   - **Days:** Check the days (Mon-Sun)
   - **Start Time:** (e.g., "08:00")
   - **End Time:** (e.g., "15:00")
4. Click **Save**

### Step 5: Monitor Status

1. Click the **Status** tab
2. You should see:
   - Profile & Device Status (online/offline, usage, remaining time)
   - Active Schedules (if any)
   - System Health

---

## 🔧 Key Fixes in v0.9.0

### 1. Profiles Page Save Issue - FIXED ✅
- **Problem:** Profiles were not saving when clicking Save button
- **Root Cause:** `if ($_POST['save'])` was evaluating to false
- **Fix:** Changed to `if (isset($_POST['save']))`

### 2. Schedules Page Save Issue - FIXED ✅
- **Problem:** Schedules were not saving when clicking Save button
- **Root Cause:** Same as profiles - `if ($_POST['save'])` was evaluating to false
- **Fix:** Changed to `if (isset($_POST['save']))`

### 3. Simplified `parental_control_sync()` - FIXED ✅
- **Problem:** Calling `filter_configure()` on every save caused 5-10 second delays and timeouts
- **Fix:** Removed `filter_configure()` calls; now uses pfSense anchors for dynamic rule management

### 4. Resilient Saves - FIXED ✅
- **Problem:** If sync failed, the entire save would fail
- **Fix:** Wrapped `parental_control_sync()` in try-catch blocks so GUI saves complete even if sync has issues

---

## 🚀 Features Available

### ✓ Profile-Based Device Grouping
- Group multiple devices under one profile
- Shared time limits across all devices (bypass-proof)

### ✓ Auto-Discover Devices
- Scans DHCP leases to find all devices on your network
- Checkbox interface to select which devices to add
- Filters out devices already assigned to other profiles

### ✓ Time Limits
- Daily time limits per profile
- Weekend bonus time
- Automatic daily counter reset at midnight
- Real-time usage tracking (1-minute granularity)

### ✓ Schedules
- Block access during specific time periods
- Multi-profile support (one schedule can apply to multiple profiles)
- Day-of-week selection (Mon-Sun)

### ✓ Block Page with Parent Override
- User-friendly block page when access is restricted
- Shows reason for blocking (time limit or schedule)
- Shows current usage and remaining time
- Parent override option with password

### ✓ Anchor-Based Firewall Rules
- Dynamic rule management without full firewall reloads
- Persistent across reboots
- Automatic cleanup of stale rules

### ✓ RESTful API
- External integration support
- Endpoints for profiles, devices, schedules, usage, overrides
- JSON responses

### ✓ Health Check Endpoint
- `/parental_control_health.php` for monitoring
- Returns system status, cron status, rule counts

---

## 📊 Monitoring & Diagnostics

### Check Cron Jobs
```bash
ssh mkesharw@fw.keekar.com 'crontab -l | grep parental'
```

### Check State File
```bash
ssh mkesharw@fw.keekar.com 'cat /var/db/parental_control_state.json | jq .'
```

### Check Anchor Rules
```bash
ssh mkesharw@fw.keekar.com 'sudo pfctl -a parental_control -sr'
```

### Check System Logs
```bash
ssh mkesharw@fw.keekar.com 'tail -50 /var/log/system.log | grep parental'
```

### Check Package Logs
```bash
ssh mkesharw@fw.keekar.com 'tail -50 /var/log/parental_control.log'
```

---

## 🐛 Troubleshooting

### If Profiles/Schedules Don't Save
1. Check system logs for PHP errors
2. Verify file permissions on `/usr/local/www/parental_control_*.php`
3. Check that pfSense config is not locked

### If Devices Don't Get Blocked
1. Verify cron job is running: `crontab -l`
2. Check anchor rules: `sudo pfctl -a parental_control -sr`
3. Check state file: `cat /var/db/parental_control_state.json`
4. Verify device MAC/IP is correct

### If Auto-Discover Doesn't Work
1. Check DHCP leases: Visit Status > DHCP Leases in pfSense
2. Verify devices have active DHCP leases
3. Check that devices aren't already assigned to other profiles

### If Block Page Doesn't Appear
1. Verify NAT redirect rules exist: `sudo pfctl -a parental_control -sn`
2. Check that device is actually blocked: `sudo pfctl -a parental_control -sr`
3. Try clearing browser cache and accessing an HTTP site (not HTTPS)

---

## 📝 Important Notes

### Cron Job Frequency
- **Parental Control Cron:** Every 5 minutes (time tracking, rule enforcement)
- **Auto-Update Cron:** Every 15 minutes (checks for package updates)

### Time Tracking Granularity
- Time counters increment every 5 minutes (based on cron frequency)
- If a device is online for 4 minutes, no time is counted
- If a device is online for 6 minutes, 5 minutes are counted

### Daily Counter Reset
- Automatically resets at midnight (or custom time per profile)
- Uses the firewall's system time
- Resets both `devices` and `devices_by_ip` arrays for backward compatibility

### Firewall Rule Management
- Uses pfSense anchors for dynamic rules
- No full `filter_configure()` reloads needed
- Rules persist across reboots
- Automatic cleanup of stale rules

---

## 🎉 You're Ready to Test!

Your pfSense firewall now has a completely fresh installation of the KACI Parental Control package with all the latest fixes.

**Start by:**
1. Creating a test profile
2. Adding a device (use auto-discover or manual entry)
3. Setting a short time limit (e.g., 1 hour) for quick testing
4. Monitoring the Status page to see real-time updates

**Test the blocking:**
1. Wait for the time limit to expire (or create a schedule that's currently active)
2. Try accessing the internet from the blocked device
3. You should see the block page with the reason and override option

**Test the save functionality:**
1. Edit an existing profile
2. Change the time limit
3. Click Save
4. Verify the change was saved (refresh the page)

---

## 📚 Documentation

- **README.md** - Full package documentation
- **CHANGELOG.md** - All changes and version history
- **ANCHOR_GUIDE.md** - How the anchor system works
- **BLOCK_PAGE_GUIDE.md** - Block page implementation details
- **docs/API.md** - API documentation
- **AUTO_UPDATE.md** - Auto-update feature documentation

---

## 🔄 Updating the Package

The auto-update feature is enabled by default. It will:
- Check for updates every 15 minutes
- Pull the latest code from GitHub
- Deploy changes automatically
- Log all updates to `/var/log/parental_control_update.log`

To manually update:
```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control
./INSTALL.sh update fw.keekar.com
```

---

## 🗑️ Uninstalling

If you need to remove the package completely:
```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control
ssh mkesharw@fw.keekar.com
cd /tmp/KACI-Parental_Control
echo "yes" | sudo ./UNINSTALL.sh
```

---

**Installation completed successfully!** 🎊

You now have a clean slate to test all the features and verify that everything works as expected.

