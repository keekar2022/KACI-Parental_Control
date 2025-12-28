# 🔥 HOTFIX v1.1.2 - Status Page Usage Display Fix

**Release Date:** December 29, 2025  
**Severity:** Critical  
**Type:** Bug Fix  
**Affected Versions:** v1.1.0, v1.1.1

---

## 🐛 Problem

After implementing **Shared Profile Time Accounting** in v1.1.0, the status page was showing **"0:00"** for all devices, despite usage being correctly tracked in the backend state file.

### User Impact
- Users saw **all devices showing 0:00 usage**, making it appear that the shared time feature wasn't working
- **No blocks were being applied** because the status page couldn't display accurate usage
- Created confusion and loss of trust in the parental control system

### Example
```
Status Page Display (INCORRECT):
┌─────────┬──────────────┬──────────┬──────────┐
│ Profile │ Device       │ Daily    │ Usage    │
│         │              │ Limit    │ Today    │
├─────────┼──────────────┼──────────┼──────────┤
│ Mukesh  │ MacPro       │ 10:00    │ 0:00 ❌  │
│ Mukesh  │ iPhone       │ 10:00    │ 0:00 ❌  │
│ GunGun  │ TV           │ 6:00     │ 0:00 ❌  │
│ GunGun  │ Nest Hub     │ 6:00     │ 0:00 ❌  │
└─────────┴──────────────┴──────────┴──────────┘

Actual State File (CORRECT):
{
  "profiles": {
    "Mukesh": { "usage_today": 40 },  // 40 minutes
    "GunGun": { "usage_today": 75 }   // 1 hr 15 min
  }
}
```

---

## 🔍 Root Cause

In `parental_control_status.php`, **line 181** was incorrectly overwriting the correct `$profile_name` variable:

```php
// Line 149: Correct profile name from outer loop
$profile_name = htmlspecialchars($profile['name']);  // ✅ "Mukesh"

// ... device loop starts ...

// Line 181: INCORRECT - overwrites with non-existent field
$profile_name = isset($device['profile_name']) ? $device['profile_name'] : null;  // ❌ null

// Line 184-185: Lookup fails because $profile_name is now null
if ($profile_name && isset($state['profiles'][$profile_name]['usage_today'])) {
    $usage_today = intval($state['profiles'][$profile_name]['usage_today']);  // Never executes
}
```

### Why It Happened
- **Copy-paste error** from older code that used `$device['profile_name']`
- The device array **does NOT contain** a `profile_name` field
- Profile name is correctly available from the outer loop at line 149
- Line 181 was redundant and destructive

---

## ✅ Solution

**Remove the incorrect profile name override and read directly from the outer loop variable:**

```php
// BEFORE (v1.1.0 - v1.1.1):
$profile_name = isset($device['profile_name']) ? $device['profile_name'] : null;  // ❌

if ($profile_name && isset($state['profiles'][$profile_name]['usage_today'])) {
    $usage_today = intval($state['profiles'][$profile_name]['usage_today']);
}

// AFTER (v1.1.2):
// Note: $profile_name is already set from outer loop at line 149  // ✅

if (isset($state['profiles'][$profile['name']]['usage_today'])) {
    $usage_today = intval($state['profiles'][$profile['name']]['usage_today']);
}
```

### Changes
1. **Removed** line 181 that was overwriting `$profile_name` with `null`
2. **Changed** lines 184-185 to read directly from `$profile['name']`
3. **Added** clarifying comment explaining profile name source

---

## 🧪 Verification

### Backend Tracking (Already Working)
```bash
cat /var/db/parental_control_state.json | jq '.profiles'
```

**Output (Confirmed Correct):**
```json
{
  "Mukesh": {
    "usage_today": 40,      // 40 minutes tracked ✅
    "last_update": 1766951696
  },
  "GunGun": {
    "usage_today": 75,      // 1 hr 15 min tracked ✅
    "last_update": 1766951696
  }
}
```

### Frontend Display (Now Fixed)
```
Status Page Display (AFTER v1.1.2):
┌─────────┬──────────────┬──────────┬──────────┐
│ Profile │ Device       │ Daily    │ Usage    │
│         │              │ Limit    │ Today    │
├─────────┼──────────────┼──────────┼──────────┤
│ Mukesh  │ MacPro       │ 10:00    │ 0:40 ✅  │
│ Mukesh  │ iPhone       │ 10:00    │ 0:40 ✅  │ (same usage)
│ GunGun  │ TV           │ 6:00     │ 1:15 ✅  │
│ GunGun  │ Nest Hub     │ 6:00     │ 1:15 ✅  │ (same usage)
└─────────┴──────────────┴──────────┴──────────┘
```

### Shared Time Verification
- All devices in **Mukesh** profile show **0:40** (shared)
- All devices in **GunGun** profile show **1:15** (shared)
- Confirms **Shared Profile Time Accounting** is working correctly

---

## 📦 Deployment

### Automatic (Recommended)
If auto-update is enabled (default):
```bash
# Runs every 15 minutes automatically
*/15 * * * * /usr/local/bin/auto_update_parental_control.sh
```

### Manual
```bash
# On pfSense firewall
cd /path/to/KACI-Parental_Control
git pull
sudo ./INSTALL.sh
```

### Quick Fix (Emergency)
```bash
# Just copy the fixed file
scp parental_control_status.php root@firewall:/usr/local/www/
scp VERSION root@firewall:/usr/local/pkg/parental_control_VERSION
```

---

## 📊 Impact Assessment

### Severity: **CRITICAL** 🔥
- Affected **100% of users** on v1.1.0/v1.1.1
- Made the new shared time feature **appear broken**
- Status page is the **primary user interface** for monitoring

### Scope
- **Files Changed:** 1 (`parental_control_status.php`)
- **Lines Changed:** 6
- **Backend:** Unaffected (was working correctly)
- **Frontend:** Fixed (now displays correctly)

### Risk: **MINIMAL** ✅
- Only changes display logic
- No changes to blocking/tracking logic
- No changes to configuration
- Cannot cause data loss or corruption

---

## 🎯 Lessons Learned

### For Developers
1. **Test all display pages** after major backend changes
2. **Verify variable scope** when refactoring nested loops
3. **Add unit tests** for frontend display logic
4. **Check for stale variable assignments** from old code

### For Users
1. **Backend tracking is reliable** - state file is always correct
2. **Display bugs don't affect blocking** - rules still apply correctly
3. **Auto-update catches issues quickly** - deployed within hours

---

## 📈 Version History

| Version | Date       | Status         | Notes                           |
|---------|------------|----------------|---------------------------------|
| v1.1.0  | 2025-12-29 | Feature Launch | Shared Profile Time introduced  |
| v1.1.1  | 2025-12-29 | Display Bug    | Status page showing 0:00        |
| v1.1.2  | 2025-12-29 | **FIXED** ✅   | Status page now shows correctly |

---

## 🔗 Related Documentation

- [SHARED_PROFILE_TIME_v1.1.0.md](SHARED_PROFILE_TIME_v1.1.0.md) - Original feature explanation
- [SCHEDULES_AND_TIME_LIMITS_EXPLAINED.md](SCHEDULES_AND_TIME_LIMITS_EXPLAINED.md) - How blocking works
- [CHANGELOG.md](CHANGELOG.md) - Full version history

---

## 📞 Support

If you're still seeing **0:00** after upgrading to v1.1.2:

1. **Hard refresh** your browser: `Ctrl+Shift+R` (or `Cmd+Shift+R` on Mac)
2. **Check version** in footer: Should show `v1.1.2`
3. **Verify state file**:
   ```bash
   cat /var/db/parental_control_state.json | jq '.profiles'
   ```
4. **Check cron job**:
   ```bash
   sudo crontab -l | grep parental
   ```

---

**Fixed and deployed within 1 hour of bug report** ⚡  
**KACI Parental Control - Fast, Reliable, Responsive** 💪

