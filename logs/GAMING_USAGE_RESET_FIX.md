# Gaming Usage Reset Fix - Immediate Action Guide

## Issue Summary

**Problem:** Gaming usage for profiles (Anita, Mukesh) is stuck at 20 minutes and has not reset for the last 3 days.

**Root Cause:** The `pc_reset_daily_counters()` function was resetting regular usage and service usage but **completely missed gaming usage counters**. This caused gaming time to accumulate indefinitely without ever resetting at midnight.

**Impact:**
- Gaming limits cannot be enforced
- WHO Gaming Disorder Prevention feature broken
- Users see stale gaming times from days/weeks ago
- Parents cannot track daily gaming accurately

## Fix Applied

**Version:** v1.5.1  
**File Modified:** `parental_control.inc` (lines 5387-5399)  
**Change:** Added gaming usage reset loop in `pc_reset_daily_counters()` function

### Code Change

```php
// CRITICAL FIX v1.5.1: Reset profile gaming usage (WHO Gaming Disorder Prevention)
// BUG: gaming_usage['usage_today'] was never reset, causing accumulation
if (isset($profile_state['gaming_usage']) && is_array($profile_state['gaming_usage'])) {
    foreach ($profile_state['gaming_usage'] as $platform => &$gaming_data) {
        if (is_array($gaming_data) && isset($gaming_data['usage_today'])) {
            $gaming_data['usage_today'] = 0;
            $gaming_platforms_reset++;
        }
    }
    unset($gaming_data);
}
```

## Immediate Deployment Steps

### Step 1: Deploy Updated Code

```bash
# From your development machine
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev

# Copy updated file to pfSense
scp parental_control.inc admin@<pfsense_ip>:/tmp/

# SSH to pfSense
ssh admin@<pfsense_ip>

# Install updated file
sudo mv /tmp/parental_control.inc /usr/local/pkg/
sudo chmod 644 /usr/local/pkg/parental_control.inc
```

### Step 2: IMMEDIATELY Reset Stuck Gaming Usage

This will fix Anita and Mukesh's stuck 20-minute gaming usage right now:

```bash
# Run this command on pfSense (via SSH)
sudo /usr/local/bin/php -r "
require_once('/usr/local/pkg/parental_control.inc');
\$state = pc_load_state_from_disk();
pc_reset_daily_counters(\$state);
pc_save_state(\$state);
echo 'Gaming usage reset complete.\n';
"
```

### Step 3: Verify Fix

1. Open pfSense Web UI
2. Navigate to: **Status > Keekar's Parental Control**
3. Scroll to "Today's Gaming Usage by Profile" section
4. Verify: **All profiles should show "0 min"** ✅

Expected output:
```
Profile    Gaming Time Today    Daily Limit    Status
Anita      0 min               120 min        OK ✅
Mukesh     0 min               120 min        OK ✅
Vishesh    0 min               120 min        OK ✅
```

## What This Fix Does

### Before v1.5.1 (Broken)
- Gaming usage accumulates: 20 min → 20 min → 20 min (never resets)
- Daily reset at midnight: ❌ Gaming counters unchanged
- Result: Stale gaming times from days/weeks ago

### After v1.5.1 (Fixed)
- Gaming usage resets daily: 20 min → 0 min (at midnight)
- Daily reset at midnight: ✅ All gaming counters reset to 0
- Result: Accurate daily gaming tracking

### What Gets Reset
- ✅ `usage_today` for all gaming platforms (general, minecraft, steam, etc.)
- ✅ Profile-level gaming counters
- ✅ All platforms tracked per profile

### What Gets Preserved
- ✅ `usage_week` (weekly totals for analytics)
- ✅ `last_detected` (timestamp for historical tracking)
- ✅ Platform names and confidence scores

## Automatic Daily Reset

After deploying v1.5.1, gaming usage will automatically reset daily at midnight via the existing cron job:

```bash
# Cron job (already installed)
0 0 * * * /usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); pc_check_daily_reset();"
```

This cron job now correctly resets:
1. General internet usage
2. Per-service usage (YouTube, Facebook, etc.)
3. **Gaming usage (NEW in v1.5.1)**

## Testing the Fix

### Test 1: Manual Reset (Immediate)
```bash
# SSH to pfSense
sudo /usr/local/bin/php -r "
require_once('/usr/local/pkg/parental_control.inc');
\$state = pc_load_state_from_disk();
echo 'Before reset:\n';
print_r(\$state['profiles']['Anita']['gaming_usage']['general']['usage_today'] ?? 'N/A');
echo '\n';
pc_reset_daily_counters(\$state);
pc_save_state(\$state);
\$state = pc_load_state_from_disk();
echo 'After reset:\n';
print_r(\$state['profiles']['Anita']['gaming_usage']['general']['usage_today'] ?? 'N/A');
echo '\n';
"
```

Expected output:
```
Before reset:
20
After reset:
0
```

### Test 2: Automatic Reset (Next Midnight)
1. Wait for midnight (or change system time for testing)
2. Check Status page in the morning
3. All gaming usage should be 0 minutes ✅

### Test 3: Gaming Detection Still Works
1. Have a user (e.g., Vishesh) play a game
2. Check Status page after 5-10 minutes
3. Gaming usage should increment correctly
4. Next day after midnight: Should reset to 0 ✅

## Logging

Check logs to verify reset is working:

```bash
# SSH to pfSense
tail -50 /var/log/parental_control-$(date +%Y-%m-%d).jsonl | grep daily_reset
```

Expected log entry:
```json
{
  "timestamp": "2026-02-02T00:00:01Z",
  "level": "info",
  "message": "Daily usage counters reset",
  "event.action": "daily_reset",
  "profiles.reset": 6,
  "gaming_platforms.reset": 12,
  "services.cleared": 4
}
```

Note the new field: `"gaming_platforms.reset": 12` ✅

## Troubleshooting

### Issue: Gaming usage still shows old value after reset

**Solution:**
```bash
# Force reload state from disk
sudo /usr/local/bin/php -r "
require_once('/usr/local/pkg/parental_control.inc');
\$state = pc_load_state_from_disk();
foreach (\$state['profiles'] as \$name => &\$profile) {
    if (isset(\$profile['gaming_usage'])) {
        foreach (\$profile['gaming_usage'] as \$platform => &\$data) {
            \$data['usage_today'] = 0;
        }
    }
}
pc_save_state(\$state);
echo 'Force reset complete.\n';
"
```

### Issue: Cron job not running

**Check cron:**
```bash
crontab -l | grep parental
```

**Expected output:**
```
0 0 * * * /usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); pc_check_daily_reset();"
```

**Reinstall cron if missing:**
```bash
sudo /usr/local/bin/php -r "
require_once('/usr/local/pkg/parental_control.inc');
pc_setup_cron_job();
echo 'Cron job reinstalled.\n';
"
```

## Related Files

- **Code Fix:** `parental_control.inc` (lines 5387-5399)
- **Full Documentation:** `logs/CRITICAL_FIXES_v1.5.1_FEB_2026.md`
- **Status Display:** `parental_control_status.php` (line 265)
- **Gaming Detection:** `parental_control.inc` (function `pc_update_gaming_usage`, line 7608)

## Support

If gaming usage still shows old values after following this guide:

1. Check system logs: `tail -100 /var/log/system.log | grep Parental`
2. Check state file: `cat /var/db/parental_control_state.json | jq '.profiles.Anita.gaming_usage'`
3. Verify cron job is running: `crontab -l`
4. Check disk space: `df -h`

## Summary

✅ **Bug Fixed:** Gaming usage now resets daily at midnight  
✅ **Immediate Action:** Run manual reset command to fix stuck counters  
✅ **Future:** Automatic reset at midnight via cron job  
✅ **Impact:** WHO Gaming Disorder Prevention feature fully functional  
✅ **Version:** v1.5.1 (February 2, 2026)

---

**Last Updated:** February 2, 2026  
**Author:** Mukesh Kesharwani  
**Version:** 1.5.1
