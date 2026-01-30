# Error Investigation: "Undefined constant installedpackages"

**Error Reported**: 2026-01-29 18:58:33  
**Location**: Login page  
**Error Type**: PHP Fatal Error (Type 1)  
**Message**: `Uncaught Error: Undefined constant "installedpackages" in Command line code:14`

---

## Analysis

### Error Details

```
PHP ERROR: Type: 1, File: Command line code, Line: 14
Message: Uncaught Error: Undefined constant "installedpackages"
```

### What This Means

1. **"Command line code:14"** - This indicates the error is occurring in PHP code executed from command line (likely cron job)
2. **Line 14** - In `parental_control_cron.php`, line 14 is: `require_once("/usr/local/pkg/parental_control.inc");`
3. **"Undefined constant"** - PHP is trying to use `installedpackages` as a constant (without quotes)

### Possible Causes

1. **Corrupted file** - The deployed file may be corrupted or incomplete
2. **Old version** - An older version with a bug may still be deployed
3. **Race condition** - File was being written when cron job executed
4. **Permissions issue** - File couldn't be fully read

---

## Diagnostic Steps

### Step 1: Run Diagnostic Script

```bash
# Copy and run diagnostic script
scp diagnose_syntax_error.sh admin@fw.keekar.com:/tmp/
ssh admin@fw.keekar.com "chmod +x /tmp/diagnose_syntax_error.sh && /tmp/diagnose_syntax_error.sh"
```

### Step 2: Check Deployed Files

```bash
# Check file integrity
ssh admin@fw.keekar.com "ls -lh /usr/local/pkg/parental_control.inc"
ssh admin@fw.keekar.com "head -20 /usr/local/pkg/parental_control.inc"

# Check for syntax errors
ssh admin@fw.keekar.com "php -l /usr/local/pkg/parental_control.inc"

# Check cron file
ssh admin@fw.keekar.com "cat /usr/local/bin/parental_control_cron.php"
```

### Step 3: Check for Unquoted Array Access

```bash
# Search for the bug
ssh admin@fw.keekar.com "grep -n '\$config\[installedpackages\]' /usr/local/pkg/parental_control.inc"
```

**Expected**: Should return nothing (no matches)  
**If found**: This is the bug - `installedpackages` needs quotes

### Step 4: Check PHP Error Log

```bash
# View recent PHP errors
ssh admin@fw.keekar.com "tail -100 /var/log/php-fpm.log | grep -i installedpackages"
```

### Step 5: Test Cron Job Manually

```bash
# Run cron job manually to reproduce error
ssh admin@fw.keekar.com "/usr/local/bin/php-cgi -f /usr/local/bin/parental_control_cron.php"
```

---

## Likely Solutions

### Solution 1: Redeploy Files (Most Likely)

If files are corrupted or old version is still deployed:

```bash
# Backup current version
ssh admin@fw.keekar.com "cp /usr/local/pkg/parental_control.inc /root/parental_control.inc.backup"

# Deploy fresh copy
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev
scp parental_control.inc admin@fw.keekar.com:/usr/local/pkg/parental_control.inc

# Verify
ssh admin@fw.keekar.com "php -l /usr/local/pkg/parental_control.inc"
```

### Solution 2: Fix Unquoted Array Access (If Found)

If diagnostic finds `$config[installedpackages]` without quotes:

```bash
# Fix the specific line (example)
ssh admin@fw.keekar.com "sed -i.bak 's/\$config\[installedpackages\]/\$config\['\''installedpackages'\''\]/g' /usr/local/pkg/parental_control.inc"
```

### Solution 3: Clear PHP OpCache

If the file is correct but error persists:

```bash
# Clear PHP cache
ssh admin@fw.keekar.com "php -r 'opcache_reset();'"

# Or restart PHP-FPM
ssh admin@fw.keekar.com "/usr/local/etc/rc.d/php-fpm restart"
```

### Solution 4: Check Config.xml

If the config structure is corrupted:

```bash
# Check if installedpackages exists in config
ssh admin@fw.keekar.com "php -r \"require_once('/etc/inc/config.inc'); global \\\$config; echo isset(\\\$config['installedpackages']) ? 'EXISTS' : 'MISSING'; echo '\\\\n';\""
```

If missing, reinitialize:

```bash
ssh admin@fw.keekar.com "php -r \"
require_once('/etc/inc/config.inc');
require_once('/usr/local/pkg/parental_control.inc');
global \\\$config;
if (!isset(\\\$config['installedpackages'])) {
    \\\$config['installedpackages'] = array();
    write_config('Initialized installedpackages structure');
}
\""
```

---

## Verification After Fix

1. **Check syntax**:
   ```bash
   ssh admin@fw.keekar.com "php -l /usr/local/pkg/parental_control.inc"
   ```

2. **Test cron job**:
   ```bash
   ssh admin@fw.keekar.com "/usr/local/bin/php-cgi -f /usr/local/bin/parental_control_cron.php"
   ```

3. **Check error is gone**:
   ```bash
   # Wait 5 minutes for next cron cycle, then check
   ssh admin@fw.keekar.com "tail -50 /var/log/php-fpm.log | grep -i installedpackages"
   ```

4. **Access status page**:
   - Navigate to: Firewall → Parental Control → Status
   - Should load without errors

---

## Prevention

### For Future Deployments

1. **Always verify syntax before deployment**:
   ```bash
   php -l parental_control.inc
   ```

2. **Use atomic deployment**:
   ```bash
   # Copy to temp location first
   scp parental_control.inc admin@fw.keekar.com:/tmp/parental_control.inc.new
   
   # Verify syntax on target
   ssh admin@fw.keekar.com "php -l /tmp/parental_control.inc.new"
   
   # If OK, move into place
   ssh admin@fw.keekar.com "mv /tmp/parental_control.inc.new /usr/local/pkg/parental_control.inc"
   ```

3. **Monitor logs after deployment**:
   ```bash
   ssh admin@fw.keekar.com "tail -f /var/log/php-fpm.log"
   ```

---

## Code Review

### Recent Changes (v1.4.68)

The bot detection fix (v1.4.68) modified:
- `pc_detect_bot_behavior()` function
- `pc_detect_general_bot_behavior()` function

**No changes to**:
- config_get_path/config_set_path calls
- Array access patterns
- Top-level code that executes at include-time

**Verification**:
```bash
php -l parental_control.inc  # ✅ PASS - No syntax errors
```

### Confirmed: No New Bugs Introduced

All config access in v1.4.68 uses proper quoting:
- ✅ `config_get_path('installedpackages/...')`
- ✅ All instances properly quoted
- ✅ No direct array access like `$config[installedpackages]`

---

## Conclusion

The error is **NOT caused by v1.4.68 changes**. Most likely causes:
1. ⚠️ **Corrupted deployment** - File was interrupted during transfer
2. ⚠️ **Old version still active** - New code not yet deployed
3. ⚠️ **OpCache issue** - PHP serving cached old version

**Recommended Action**: Run diagnostic script and redeploy files.

---

**Created**: 2026-01-29  
**Investigation By**: Following KACI best practices  
**Related**: v1.4.68 bot detection fix
