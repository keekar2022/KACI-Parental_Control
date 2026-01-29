# Parse Config Crash Fix - v1.4.69

**Date**: 2026-01-30
**Issue**: PHP Fatal Error - Call to undefined function parse_config()
**Severity**: Critical (causes system crash)
**pfSense Version**: 2.8.1-RELEASE

## Problem Description

### Crash Report
```
PHP Fatal error:  Uncaught Error: Call to undefined function parse_config() in Command line code:1
Stack trace:
#0 {main}
  thrown in Command line code on line 1
```

### Root Cause
The `parse_config()` function was deprecated and removed in pfSense 2.8.0+. The codebase was still using this legacy function in multiple locations:

1. **parental_control_services.php** (5 occurrences)
   - Line 28: Safety check for config validity
   - Line 53: Config corruption recovery
   - Line 80: Post write_config() validation
   - Line 89: Exception handler for config write failure
   - Line 95: Error handler for config write failure

2. **INSTALL.sh** (1 occurrence)
   - Line 381: Package registration script

3. **UNINSTALL.sh** (1 occurrence)
   - Line 110: Menu entry removal

### Why This Causes Crashes
- In pfSense 2.8.x, `parse_config()` no longer exists
- Calling undefined functions triggers PHP Fatal Errors
- Fatal errors in command-line PHP code crash the entire process
- This affects installation, uninstallation, and service configuration

## Solution

### Modern pfSense 2.8.x Approach
In pfSense 2.8.x, the configuration is automatically loaded into the global `$config` variable when you require `config.inc`:

```php
// OLD (pfSense 2.7.x and earlier)
require_once("config.inc");
$config = parse_config(true);

// NEW (pfSense 2.8.x and later)
require_once("config.inc");
global $config;  // $config is already loaded
```

### Files Modified

#### 1. parental_control_services.php
**Changed**: All 5 occurrences of `$config = parse_config(true);`
**To**: `global $config;`

**Locations**:
- Lines 25-32: Safety check at file start
- Lines 49-59: Config validation in `safe_write_config()`
- Lines 76-83: Post write_config() check
- Lines 86-90: Exception handler
- Lines 92-97: Error handler

#### 2. INSTALL.sh
**Changed**: Line 381 in package registration script
**From**:
```php
require_once('/etc/inc/config.inc');
require_once('/etc/inc/util.inc');
$config = parse_config();
```

**To**:
```php
require_once('/etc/inc/config.inc');
require_once('/etc/inc/util.inc');
// In pfSense 2.8.x, $config is automatically loaded after requiring config.inc
global $config;
```

#### 3. UNINSTALL.sh
**Changed**: Line 110 in menu removal script
**From**:
```php
require_once('/etc/inc/config.inc');
$config = parse_config(true);
```

**To**:
```php
require_once('/etc/inc/config.inc');
global $config;
// In pfSense 2.8.x, $config is automatically loaded after requiring config.inc
```

## Testing Checklist

### Before Deployment
- [ ] Test package installation on pfSense 2.8.1
- [ ] Test package uninstallation
- [ ] Test service configuration changes
- [ ] Test config write operations
- [ ] Test error recovery paths

### Installation Test
```bash
./INSTALL.sh install <pfsense_ip>
```
Expected: No parse_config() errors

### Uninstallation Test
```bash
ssh admin@<pfsense_ip>
sudo /usr/local/bin/UNINSTALL.sh
```
Expected: Clean removal without errors

### Service Configuration Test
1. Navigate to Services > Keekar's Parental Control > Online Services
2. Add a new service
3. Click "Verify"
4. Click "Monitor&Block"

Expected: No PHP fatal errors in system logs

## Verification

### Check for Remaining parse_config() Calls
```bash
grep -r "parse_config(" .
```
Expected output: No matches (all removed)

### System Log Check
```bash
ssh admin@<pfsense_ip>
tail -f /var/log/system.log | grep -E "parse_config|Fatal"
```
Expected: No parse_config errors after fix

## Compatibility Notes

### pfSense Version Support
- **pfSense 2.8.0+**: ✅ Compatible (uses global $config)
- **pfSense 2.7.x**: ⚠️ May need testing (parse_config() may still exist)
- **pfSense 2.6.x**: ⚠️ May need testing (parse_config() may still exist)

### Backward Compatibility
If backward compatibility with pfSense 2.7.x is needed, use this pattern:

```php
require_once("config.inc");
if (function_exists('parse_config')) {
    // pfSense 2.7.x and earlier
    $config = parse_config(true);
} else {
    // pfSense 2.8.x and later
    global $config;
}
```

However, since the crash report is from pfSense 2.8.1, the current fix is appropriate.

## Impact Assessment

### Severity
- **Critical**: System crashes prevent all functionality
- **Scope**: Affects installation, configuration, and uninstallation
- **User Impact**: Complete package failure on pfSense 2.8.x

### Fix Urgency
- **Priority**: P0 (Highest)
- **Risk**: Low (simple function call replacement)
- **Testing Required**: Medium (verify all config operations)

## Deployment Plan

### Version Bump
- From: v1.4.68
- To: v1.4.69

### Deployment Steps
1. ✅ Fix all parse_config() calls
2. ✅ Verify no remaining occurrences
3. [ ] Update VERSION file
4. [ ] Test on pfSense 2.8.1
5. [ ] Commit changes
6. [ ] Tag release v1.4.69
7. [ ] Update documentation

### Rollout Strategy
- **Target**: All users on pfSense 2.8.x
- **Method**: Auto-update (if enabled) or manual update
- **Notification**: GitHub release notes

## Related Issues

### GitHub Issues
- Create issue: "parse_config() crash on pfSense 2.8.1"
- Label: `bug`, `critical`, `pfsense-2.8`

### Similar Bugs to Watch For
- Any other deprecated pfSense functions in 2.8.x
- Config manipulation functions
- Filter/firewall rule functions

## Documentation Updates

### User Guide
Add note about pfSense 2.8.x compatibility

### Installation Guide
Update minimum version requirements:
- pfSense 2.8.0 or later (recommended)
- pfSense 2.7.x (may work but not tested)

### Troubleshooting Guide
Add section: "parse_config() errors on pfSense 2.8.x"

## Lessons Learned

### What Went Wrong
- Did not test on pfSense 2.8.x before release
- Used deprecated function without checking changelog
- No compatibility layer for multiple pfSense versions

### Prevention
- Test on latest pfSense version before each release
- Review pfSense upgrade guides for deprecated functions
- Add automated tests for critical paths
- Consider version detection in code

### Best Practices
1. **Always check pfSense changelog** when upgrading
2. **Test on target pfSense versions** before release
3. **Use modern APIs** instead of deprecated functions
4. **Add version detection** if supporting multiple versions
5. **Log compatibility notes** in documentation

## Conclusion

This fix resolves a critical crash affecting all pfSense 2.8.x users. The solution is straightforward: replace deprecated `parse_config()` calls with the modern `global $config` approach. All occurrences have been identified and fixed across 3 files.

**Status**: ✅ Fixed
**Verified**: ✅ No remaining parse_config() calls
**Ready for**: Testing on pfSense 2.8.1

---

**Author**: AI Assistant (via Cursor)
**Reviewed**: Mukesh Kesharwani
**Date**: 2026-01-30
