# Firewall Rule Matching Diagnostic

## Overview

The `diagnose_rule_matching.sh` script helps identify why firewall logs show empty rule descriptions `()` instead of proper Parental Control rule names.

## Problem

When viewing firewall logs, you see entries like:
```
Jan 9 08:02:32  LAN0  ()  192.168.1.95:43146  142.250.195.142:443  TCP:S
```

The empty parentheses `()` mean no rule description is logged, making it impossible to know which rule matched.

## Common Causes

1. **Monitor Table Empty** - No devices in `parental_control_monitor` table
2. **Rules Missing** - PC rules not created or deleted
3. **Device Not Monitored** - Specific device not in the table
4. **Wrong Rule Matching** - Traffic matches interface rules instead of floating rules
5. **Rule Order Issue** - Another rule with `quick` matches first

## Usage

### Basic Diagnostics (All Devices)

```bash
ssh root@192.168.1.1
cd /root
./diagnose_rule_matching.sh
```

### Device-Specific Analysis

```bash
ssh root@192.168.1.1
cd /root
./diagnose_rule_matching.sh 192.168.1.95
```

Replace `192.168.1.95` with the device IP you want to analyze.

## What It Checks

### Section 1: Parental Control Tables
- ✅ Lists all devices in `parental_control_monitor`
- ✅ Lists all devices in `parental_control_blocked`
- ✅ Checks if target device is in tables

### Section 2: Parental Control Rules
- ✅ Verifies PC rules exist in pfSense
- ✅ Shows rule count and order
- ✅ Checks if logging is enabled
- ✅ Verifies `quick` flag is set

### Section 3: Service Aliases
- ✅ Checks YouTube, Facebook, Discord IP tables
- ✅ Shows IP count per service
- ✅ Verifies tables are not empty

### Section 4: Interface Rules
- ✅ Checks for fallback LAN rules
- ✅ Identifies default allow rules

### Section 5: Device-Specific Analysis
- ✅ Shows active connections from device
- ✅ Matches connections against service IPs
- ✅ Identifies which services are being accessed

### Section 6: Diagnosis & Recommendations
- ✅ Identifies root cause
- ✅ Provides specific solutions
- ✅ Step-by-step fix instructions

### Section 7: Quick Actions
- ✅ Ready-to-run fix commands
- ✅ GUI navigation instructions

## Example Output

```
============================================================
  PARENTAL CONTROL - RULE MATCHING DIAGNOSTIC
============================================================

✓ Target Device: 192.168.1.95

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
SECTION 1: PARENTAL CONTROL TABLES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 Monitored Devices (parental_control_monitor):
   ✓ 192.168.1.95 (TARGET DEVICE)
   • 192.168.1.96
   • 192.168.1.110
   • 192.168.1.27

   ✓ Target device IS in monitor table

🚫 Blocked Devices (parental_control_blocked):
   ✓ Table is empty - no devices currently blocked

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
SECTION 2: PARENTAL CONTROL RULES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🔍 Searching for PC rules in pfSense...
   ✓ Found Parental Control rules

   📊 Total PC rules: 9

   📜 Rule List (in order):
      @0
      📝 Parental Control - YouTube Service Monitor
         ⚡ Quick: YES (terminates rule processing)
         📋 Logging: ENABLED
         ✓ Action: PASS (allow)
      
      @1
      📝 Parental Control - Facebook Service Monitor
         ⚡ Quick: YES (terminates rule processing)
         📋 Logging: ENABLED
         ✓ Action: PASS (allow)
...
```

## Common Fixes

### Fix 1: Tables Empty

**Problem:** `parental_control_monitor` table is empty

**Solution:**
```bash
# Run cron job to populate tables
php /usr/local/bin/parental_control_cron.php

# Verify tables populated
pfctl -t parental_control_monitor -T show
```

### Fix 2: Rules Missing

**Problem:** No PC rules in pfSense

**Solution:**
```bash
# Recreate rules
php -r 'require_once("/usr/local/pkg/parental_control.inc"); parental_control_sync();'

# Reload firewall
/etc/rc.filter_configure
```

### Fix 3: Device Not Monitored

**Problem:** Specific device not in table

**Solution:**
1. Check device is in a profile (GUI: Services → Parental Control → Profiles)
2. Check profile is enabled
3. Run cron: `php /usr/local/bin/parental_control_cron.php`

### Fix 4: Rule Order Issue

**Problem:** Other rules matching first

**Solution:**
1. Navigate to: Firewall → Rules → Floating
2. Ensure PC rules are at the TOP
3. Verify `Quick` checkbox is enabled on all PC rules
4. Drag rules to reorder if needed

## Deployment

### Copy to Firewall

```bash
# From your Mac
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev
scp diagnostic/diagnose_rule_matching.sh root@192.168.1.1:/root/

# On firewall
chmod +x /root/diagnose_rule_matching.sh
```

### Run Diagnostics

```bash
ssh root@192.168.1.1
cd /root
./diagnose_rule_matching.sh 192.168.1.95
```

## Understanding Results

### Good Results ✅

```
✓ Found Parental Control rules
✓ Target device IS in monitor table  
✓ Service aliases loaded with IPs
✓ Logging enabled on all rules
```

**Meaning:** Rules are working correctly. `()` logs are from non-PC traffic.

### Bad Results ❌

```
✗ Table is EMPTY - no devices are being monitored!
✗ NO Parental Control rules found in pf!
✗ Target device NOT in monitor table
```

**Meaning:** PC system not working. Follow recommended fixes.

## Advanced Analysis

### Real-Time Traffic Capture

```bash
# Watch traffic from specific device
tcpdump -n -e -ttt -i igc0 host 192.168.1.95

# Watch only HTTPS traffic
tcpdump -n -e -ttt -i igc0 host 192.168.1.95 and port 443

# Watch traffic to YouTube IPs
tcpdump -n -e -ttt -i igc0 src 192.168.1.95 and dst net 172.253.0.0/16
```

### Check Specific Rule Matching

```bash
# Show all rules with statistics
pfctl -vsr | less

# Check specific table
pfctl -t PC_Service_YouTube -T show

# Check state table for device
pfctl -ss | grep 192.168.1.95
```

## Troubleshooting

### Script Not Running

```bash
# Check permissions
ls -la /root/diagnose_rule_matching.sh

# Make executable
chmod +x /root/diagnose_rule_matching.sh

# Run with explicit shell
sh /root/diagnose_rule_matching.sh
```

### No Output / Errors

```bash
# Run with debug
sh -x /root/diagnose_rule_matching.sh 192.168.1.95

# Check pfctl works
pfctl -sr | head

# Check tables exist
pfctl -t parental_control_monitor -T show
```

## Related Commands

```bash
# View firewall logs
clog /var/log/filter.log | tail -50

# Reload firewall rules
/etc/rc.filter_configure

# Force cron execution
php /usr/local/bin/parental_control_cron.php

# Sync package configuration
php -r 'require_once("/usr/local/pkg/parental_control.inc"); parental_control_sync();'
```

## Support

For issues or questions:
1. Run the diagnostic script and save output
2. Check the Parental Control logs: `/var/log/parental_control.jsonl`
3. Review pfSense system logs: Status → System Logs → Firewall

## Version

- Script Version: 1.0
- Compatible with: KACI-Parental_Control v1.4.30+
- Last Updated: 2026-01-09

