# ✅ Successfully Reverted to v1.1.9

**Date:** December 29, 2025  
**Action:** Hard reset from v1.2.3 back to v1.1.9  
**Reason:** Post-v1.1.9 changes caused complexity and firewall corruption

---

## 🎯 What v1.1.9 Includes

### ✅ **Working Features:**
1. **Table-Based Blocking** (v1.1.8)
   - Blocking via pfSense aliases/tables
   - Floating rules (proper rule ordering)
   - Fully working device blocking
   - Visible in pfSense GUI

2. **HTTP/HTTPS Redirects** (v1.1.9)
   - NAT redirects for blocked devices
   - Redirect to pfSense web interface
   - Allow rules for DNS and pfSense access

3. **Core Functionality:**
   - Profile-based time limits
   - Shared profile time accounting (bypass-proof)
   - Schedule support
   - Real-time usage tracking
   - Status page with firewall rules visibility

### ❌ **What Was Removed:**
- ❌ Dedicated block page server (v1.2.0) - too complex
- ❌ VIP-based captive portal (v1.2.2/v1.2.3) - incomplete
- ❌ All post-v1.1.9 experimental features

---

## 📋 What v1.1.9 Does

### **When a Device is Blocked:**

1. ✅ **Blocking Works**
   - Device IP added to `parental_control_blocked` table
   - Floating rule blocks all traffic
   - DNS still works (for name resolution)

2. ✅ **Redirects Work**
   - HTTP/HTTPS requests redirected to pfSense (192.168.1.1)
   - User sees pfSense web interface
   - Can manually navigate to: `http://192.168.1.1/parental_control_blocked.php`

3. ✅ **Block Page Available**
   - Shows why blocked (time limit exceeded, schedule)
   - Shows usage statistics
   - Shows when access returns
   - **Note:** User must navigate to it manually

---

## 🚀 Clean Installation Instructions

### **1. Remove Everything from Firewall First**

From pfSense GUI → **Diagnostics → Command Prompt**, run:

```bash
# Stop any running services
/usr/local/etc/rc.d/parental_control_blockpage_vip.sh stop 2>/dev/null

# Remove all files
rm -rf /usr/local/pkg/parental_control*
rm -rf /usr/local/www/parental_control*
rm -rf /usr/local/bin/parental_control*
rm -rf /usr/local/etc/rc.d/parental_control*
rm -rf /var/db/parental_control*
rm -rf /var/log/parental_control*
rm -rf /var/run/parental_control*
rm -rf /tmp/parental_control*
rm -rf /usr/local/share/pfSense-pkg-KACI-Parental_Control

# Remove cron job
crontab -l | grep -v parental_control | crontab -
```

### **2. Remove Firewall Components**

**Firewall → Aliases:**
- Delete: `parental_control_blocked`

**Firewall → NAT → Port Forward:**
- Delete all "Parental Control" rules

**Firewall → Rules → Floating:**
- Delete all "Parental Control" rules

**Firewall → Virtual IPs:**
- Delete: `10.10.10.10` (if exists)

### **3. Install Clean v1.1.9**

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control
./INSTALL.sh fw.keekar.com
```

---

## 🔍 What to Expect After Clean Install

### **Firewall Components Created:**

1. **Alias (Table):** `parental_control_blocked`
   - Location: Firewall → Aliases
   - Type: Host(s)
   - Contains: Blocked device IPs

2. **Floating Rules:**
   - Allow DNS for blocked devices
   - Allow pfSense access for blocked devices
   - Block all other traffic from blocked devices

3. **NAT Redirects:**
   - HTTP (port 80) → pfSense
   - HTTPS (port 443) → pfSense

4. **NO Virtual IP** (removed from v1.2.x)
5. **NO Dedicated Server** (removed from v1.2.x)

---

## ✅ Verification Steps

After installation, verify:

1. **Check Alias Created:**
   ```bash
   pfctl -t parental_control_blocked -T show
   ```

2. **Check Floating Rules:**
   ```bash
   pfctl -sr | grep -i parental
   ```

3. **Check NAT Redirects:**
   ```bash
   pfctl -sn | grep -i parental
   ```

4. **Test Blocking:**
   - Add a device to a profile with exceeded time
   - Device should be blocked
   - DNS should work
   - HTTP/HTTPS should redirect to pfSense web interface

---

## 📝 User Experience in v1.1.9

**When blocked:**
1. User tries to browse (e.g., google.com)
2. Gets redirected to pfSense login/web page
3. Can manually navigate to: `http://192.168.1.1/parental_control_blocked.php`
4. Sees block page with details

**This is simple, stable, and works reliably.**

---

## 🎉 What's Fixed

- ✅ Blocking works (table-based)
- ✅ No VIP complexity
- ✅ No dedicated server issues
- ✅ No socket/daemon problems
- ✅ Clean, minimal approach
- ✅ All components visible in pfSense GUI

---

## 📊 Version History Cleaned

**Before:** v1.1.9 → v1.2.0 → v1.2.1 → v1.2.2 → v1.2.3  
**After:** v1.1.9 (stable)

All problematic versions (v1.2.x) have been removed from the repository.

---

## 🚨 Important Notes

1. **Backup First:** Always backup your pfSense config before installing
2. **Clean Removal:** Remove all v1.2.x components before installing v1.1.9
3. **Manual Navigation:** Block page requires manual navigation (not automatic captive portal)
4. **This is Stable:** v1.1.9 is the last known stable version

---

## 📞 Support

If issues persist after clean v1.1.9 installation:
1. Check pfSense logs: `/var/log/system.log`
2. Check package logs: `/var/log/parental_control-YYYY-MM-DD.jsonl`
3. Verify all v1.2.x components were removed
4. Do a complete uninstall and fresh install

---

**Current Version:** v1.1.9  
**Status:** ✅ Stable & Clean  
**Last Updated:** 2025-12-29

