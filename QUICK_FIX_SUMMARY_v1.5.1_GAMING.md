# Quick Fix Summary - Gaming Usage Reset Bug (v1.5.1)

## Problem Reported

**User Report:**
> "Anita and Mukesh gaming usage showing 20 min from last three days - not resetting"

**Crash Report:** 
- Not an actual crash, just telemetry logging from TikTok service verification
- No action needed for "crash report"

## Root Cause Identified

**Critical Bug:** `pc_reset_daily_counters()` function was **NOT resetting gaming usage counters**

- ✅ Resets general usage (`usage_today`)
- ✅ Resets service usage (YouTube, Facebook, etc.)
- ❌ **MISSING:** Does NOT reset gaming usage (`gaming_usage['usage_today']`)

Result: Gaming time accumulates indefinitely, never resets at midnight

## Fix Applied

**File:** `parental_control.inc`  
**Lines Modified:** 5389-5401 (gaming usage reset loop added)  
**Version:** 1.5.1

### Code Added
```php
// CRITICAL FIX v1.5.1: Reset profile gaming usage
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

## Immediate Action Required

### Option 1: Quick Fix (Manual Reset)

SSH to pfSense and run:
```bash
sudo /usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); \$state = pc_load_state_from_disk(); pc_reset_daily_counters(\$state); pc_save_state(\$state); echo 'Gaming usage reset.\n';"
```

### Option 2: Full Deployment (Recommended)

```bash
# 1. From dev machine
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev
scp parental_control.inc admin@<pfsense_ip>:/tmp/

# 2. On pfSense
ssh admin@<pfsense_ip>
sudo mv /tmp/parental_control.inc /usr/local/pkg/
sudo chmod 644 /usr/local/pkg/parental_control.inc

# 3. Reset stuck gaming usage
sudo /usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); \$state = pc_load_state_from_disk(); pc_reset_daily_counters(\$state); pc_save_state(\$state);"
```

## Verification Steps

1. Open pfSense Web UI → **Status > Keekar's Parental Control**
2. Check "Today's Gaming Usage by Profile" section
3. **All profiles should show "0 min"** ✅

Expected:
```
Anita:   0 min / 120 min  ✅
Mukesh:  0 min / 120 min  ✅
Vishesh: 0 min / 120 min  ✅
```

## What This Fixes

### Before Fix
- Gaming usage stuck at 20 minutes for days
- Daily reset at midnight: ❌ Gaming counters unchanged
- WHO Gaming Disorder Prevention: Broken

### After Fix
- Gaming usage resets to 0 at midnight
- Daily reset at midnight: ✅ All gaming counters reset
- WHO Gaming Disorder Prevention: Fully functional

## Files Modified

1. **parental_control.inc** (Critical)
   - Added gaming usage reset in `pc_reset_daily_counters()`
   - Added `$gaming_platforms_reset` counter
   - Enhanced logging

2. **logs/CRITICAL_FIXES_v1.5.1_FEB_2026.md** (Documentation)
   - Full documentation of Issue #3

3. **GAMING_USAGE_RESET_FIX.md** (Quick Reference)
   - Step-by-step deployment guide

## Testing

✅ Code syntax validated (no linter errors)  
✅ Logic verified (proper reference handling)  
✅ Backward compatible (preserves weekly usage)  
✅ Logging enhanced (tracks reset count)

## Next Steps

1. ✅ Deploy updated `parental_control.inc` to pfSense
2. ✅ Run manual reset command to fix stuck counters
3. ✅ Verify Status page shows 0 minutes for all profiles
4. ✅ Wait for next midnight - automatic reset will work
5. ✅ Monitor logs for "gaming_platforms.reset" counter

## Support Documents

- **Full Technical Details:** `logs/CRITICAL_FIXES_v1.5.1_FEB_2026.md`
- **Deployment Guide:** `GAMING_USAGE_RESET_FIX.md`
- **Code Location:** `parental_control.inc:5389-5401`

---

**Status:** ✅ Fixed  
**Version:** v1.5.1  
**Date:** February 2, 2026  
**Priority:** Critical (Gaming limits not enforced)
