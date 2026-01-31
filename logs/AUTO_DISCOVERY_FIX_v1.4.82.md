# Auto-Discovery Critical Fix - v1.4.82

**Date:** January 31, 2026  
**Version:** 1.4.82  
**Severity:** CRITICAL  
**Impact:** Auto-discovery feature was completely non-functional

---

## Executive Summary

The auto-discovery feature introduced in v1.4.67 was **completely broken** due to an undefined variable bug. Unassigned devices connecting to YouTube, Facebook, Discord, or WhatsApp were never detected or assigned to the Default profile.

**Result:** iPhone and MacBook Pro were able to access YouTube without any profile assignment or restrictions.

---

## The Bug

### Location
`parental_control.inc`, lines 5018-5032 (cron job function)

### Root Cause
The auto-discovery code used an undefined variable `$devices`:

```php
// NEW v1.4.67: Auto-discovery for unassigned devices
// Collect service connections for auto-discovery
$service_connections_by_ip = array();
foreach ($devices as $device) {  // ❌ $devices is UNDEFINED here!
    $ip = pc_get_device_ip($device);
    if ($ip) {
        $connections = pc_get_service_connections($ip, $service_ips_cache, $all_states);
        if (!empty($connections)) {
            $service_connections_by_ip[$ip] = $connections;
        }
    }
}
```

### Why It Failed

1. **Variable Scope Issue**: The `$devices` variable used elsewhere in the cron job is scoped to other function calls (like `pc_update_device_usage()`)
2. **Wrong Device Source**: Even if `$devices` were defined, it would only contain devices **already in profiles** (from `pc_get_devices()`)
3. **Zero Detection**: The `foreach` loop never executed, so `$service_connections_by_ip` remained empty
4. **Silent Failure**: The auto-discovery function ran but had no devices to check

### Real-World Impact

**Before Fix:**
- iPhone: ❌ Not in any profile → Watching YouTube → No detection → No assignment
- MacBook Pro: ❌ Not in any profile → Watching YouTube → No detection → No assignment
- Auto-discovery stats: `checked: 0, discovered: 0, assigned: 0`

**After Fix:**
- Any device: ✅ Not in profile → Connects to YouTube/Facebook/Discord/WhatsApp → Detected → Auto-assigned to Default profile
- Auto-discovery stats: `checked: N, discovered: X, assigned: X`

---

## The Solution

### Changes Made

**File:** `parental_control.inc`, lines 5018-5032

**Before (v1.4.81 and earlier):**
```php
// NEW v1.4.67: Auto-discovery for unassigned devices
// Collect service connections for auto-discovery
$service_connections_by_ip = array();
foreach ($devices as $device) {  // ❌ UNDEFINED
    $ip = pc_get_device_ip($device);
    if ($ip) {
        $connections = pc_get_service_connections($ip, $service_ips_cache, $all_states);
        if (!empty($connections)) {
            $service_connections_by_ip[$ip] = $connections;
        }
    }
}
```

**After (v1.4.82):**
```php
// NEW v1.4.67: Auto-discovery for unassigned devices
// CRITICAL FIX v1.4.82: Discover ALL network devices (not just those in profiles)
// to detect unassigned devices connecting to monitored services
$service_connections_by_ip = array();
$all_network_devices = pc_discover_devices(); // Get ALL devices from DHCP/network

foreach ($all_network_devices as $device) {
    $ip = isset($device['ip_address']) ? $device['ip_address'] : null;
    if ($ip) {
        $connections = pc_get_service_connections($ip, $service_ips_cache, $all_states);
        if (!empty($connections)) {
            $service_connections_by_ip[$ip] = $connections;
        }
    }
}
```

### Key Improvements

1. **✅ Defines Variable**: Calls `pc_discover_devices()` to get ALL network devices
2. **✅ Correct Source**: Uses DHCP leases and network discovery, not just profile devices
3. **✅ Proper Field Names**: Uses `ip_address` field (correct) instead of `ip`
4. **✅ Complete Coverage**: Checks every device on the network, not just assigned ones

---

## Testing & Verification

### Manual Test Plan

1. **Remove devices from profiles** (simulate unassigned state)
   ```bash
   # Via pfSense GUI: System > Packages > Parental Control > Profiles
   # Remove iPhone and MacBook Pro from any profile
   ```

2. **Access monitored services** from those devices
   ```
   # On iPhone or MacBook Pro:
   - Open YouTube and watch a video
   - Or visit Facebook/Discord/WhatsApp
   ```

3. **Wait 5 minutes** (cron job interval)

4. **Check logs** for auto-discovery
   ```bash
   ssh admin@fw.keekar.com
   tail -f /var/log/parental_control/debug.log | grep -i "auto"
   ```

   **Expected log entries:**
   ```
   Auto-discovered device and assigned to Default profile
   event.action: device_auto_discovered
   device.name: iPhone / MacBook-Pro
   services_detected: [YouTube]
   profile.assigned: Default
   ```

5. **Check dashboard** for notification
   ```
   # Via pfSense GUI: Status > Dashboard
   # Should see notification about auto-discovered devices
   ```

6. **Verify profile assignment**
   ```
   # Via pfSense GUI: System > Packages > Parental Control > Profiles
   # Check "Default" profile - should now contain the auto-discovered devices
   ```

### Automated Verification

```bash
# SSH into pfSense
ssh admin@fw.keekar.com

# Check version
grep VERSION /usr/local/pkg/parental_control.inc | head -1

# Verify the fix is present
grep -A10 "CRITICAL FIX v1.4.82" /usr/local/pkg/parental_control.inc | head -15

# Monitor auto-discovery in real-time
tail -f /var/log/parental_control/debug.log | grep -E "(auto.?discov|Default profile)"
```

### Expected Behavior After Fix

**Scenario 1: New Device Connects to YouTube**
```
Time: 12:00:00 - iPhone connects to network (DHCP)
Time: 12:00:05 - iPhone opens YouTube app
Time: 12:05:00 - Cron job runs
Time: 12:05:03 - Auto-discovery detects iPhone with YouTube connections
Time: 12:05:04 - iPhone auto-assigned to Default profile
Time: 12:05:05 - Notification appears on dashboard
```

**Scenario 2: Existing Device Without Profile**
```
Status: MacBook Pro on network but not in any profile
Action: User watches YouTube for 10 minutes
Result: Within next cron cycle (≤5 min), detected and assigned to Default
```

**Scenario 3: Device Already in Profile**
```
Status: iPad in "Kids" profile
Action: User watches YouTube
Result: No auto-discovery action (already assigned, skipped)
```

---

## Monitoring

### Success Metrics

**Key Log Patterns:**
```bash
# Auto-discovery running
grep "Auto-discovery completed" /var/log/parental_control/debug.log

# Devices discovered
grep "device_auto_discovered" /var/log/parental_control/debug.log

# Stats
grep -E "checked|discovered|assigned" /var/log/parental_control/debug.log | tail -5
```

**Expected After 24 Hours:**
- `checked`: Number of network devices analyzed
- `discovered`: Devices connecting to monitored services without profile
- `assigned`: Devices successfully added to Default profile
- **Success rate**: `assigned / discovered` should be ~100%

### Error Patterns to Watch

```bash
# Check for PHP errors related to undefined variables (should be ZERO after fix)
grep -i "undefined.*devices" /var/log/parental_control/debug.log

# Check for auto-discovery failures
grep -E "auto.*fail|discovery.*error" /var/log/parental_control/debug.log
```

---

## Deployment Checklist

- [x] Code fix implemented (`parental_control.inc`)
- [x] Version bumped to 1.4.82
- [x] Documentation created (this file)
- [ ] Code review (optional but recommended)
- [ ] Backup current configuration
- [ ] Deploy to production
- [ ] Monitor logs for 24-48 hours
- [ ] Verify auto-discovery stats > 0
- [ ] Test with real devices (iPhone, MacBook)

---

## Deployment Commands

```bash
# 1. Backup current state
ssh admin@fw.keekar.com "cp /usr/local/pkg/parental_control.inc /root/backups/parental_control.inc.1.4.81.backup"

# 2. Deploy new version (from development machine)
scp parental_control.inc admin@fw.keekar.com:/usr/local/pkg/parental_control.inc

# 3. Verify deployment
ssh admin@fw.keekar.com "grep 'CRITICAL FIX v1.4.82' /usr/local/pkg/parental_control.inc | wc -l"
# Expected: 1 (should see the fix comment)

# 4. Check version
ssh admin@fw.keekar.com "grep VERSION /usr/local/pkg/parental_control.inc | head -1"
# Expected: VERSION=1.4.82

# 5. Reload configuration
ssh admin@fw.keekar.com "php -r \"require_once('config.inc'); require_once('util.inc'); require_once('/usr/local/pkg/parental_control.inc'); parental_control_sync();\""

# 6. Monitor in real-time
ssh admin@fw.keekar.com "tail -f /var/log/parental_control/debug.log | grep -i auto"
```

---

## Post-Deployment Validation

**Within 5 minutes after deployment:**
```bash
# Should see auto-discovery running
ssh admin@fw.keekar.com "grep 'Auto-discovery' /var/log/parental_control/debug.log | tail -5"
```

**Within 1 hour after deployment:**
```bash
# Should see devices discovered (if any unassigned devices accessing monitored services)
ssh admin@fw.keekar.com "grep 'device_auto_discovered' /var/log/parental_control/debug.log | tail -10"
```

**Success Indicators:**
- ✅ No PHP undefined variable errors in logs
- ✅ Auto-discovery stats show `checked > 0`
- ✅ If unassigned devices exist and access YouTube/Facebook/Discord/WhatsApp, they appear in Default profile
- ✅ Dashboard shows auto-discovery notifications

---

## Rollback Plan

If issues arise after deployment:

```bash
# 1. Restore previous version
ssh admin@fw.keekar.com "cp /root/backups/parental_control.inc.1.4.81.backup /usr/local/pkg/parental_control.inc"

# 2. Reload configuration
ssh admin@fw.keekar.com "php -r \"require_once('config.inc'); require_once('util.inc'); require_once('/usr/local/pkg/parental_control.inc'); parental_control_sync();\""

# 3. Verify rollback
ssh admin@fw.keekar.com "grep VERSION /usr/local/pkg/parental_control.inc | head -1"
# Should show: VERSION=1.4.81
```

---

## Related Issues

### Future Enhancements

1. **Add State Persistence**: Track auto-discovered devices in state to show "auto-discovered" badge in UI
2. **Configurable Monitored Services**: Allow admin to customize which services trigger auto-discovery
3. **Notification Improvements**: Email/SMS alerts when new devices are auto-discovered
4. **Manual Override**: Allow marking devices as "never auto-assign" for guest devices
5. **Discovery Threshold**: Only auto-assign after N connections over Y minutes (reduce false positives)

### Known Limitations

1. **DHCP Dependency**: Only discovers devices with DHCP leases (not static IP devices outside DHCP)
2. **5-Minute Delay**: Discovery runs every 5 minutes via cron, not real-time
3. **Service Detection**: Requires active connections to monitored services (YouTube/Facebook/Discord/WhatsApp)
4. **Default Profile Only**: Always assigns to "Default" profile (no smart profile selection)

---

## Summary

**Problem:** Auto-discovery feature was completely non-functional since v1.4.67 due to undefined variable bug

**Root Cause:** Used `$devices` variable without defining it, preventing any device detection

**Solution:** Call `pc_discover_devices()` to get ALL network devices from DHCP leases

**Impact:** Critical - Unassigned devices had unrestricted access to monitored services

**Status:** Fixed in v1.4.82

**Testing:** Requires manual verification with real devices (iPhone/MacBook Pro)

**Monitoring:** Check logs for `device_auto_discovered` events after deployment

---

**Deployed by:** AI Assistant  
**Date:** 2026-01-31  
**Reviewed by:** _Pending_  
**Production Deployment:** _Pending_
