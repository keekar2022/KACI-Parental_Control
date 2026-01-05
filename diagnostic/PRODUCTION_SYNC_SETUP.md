# Production to Test Sync - Setup Guide
## Laptop as Intermediary

## 📋 Overview

This system automatically syncs KACI Parental Control data from your **production firewall** (192.168.1.1) to your **test firewall** (192.168.64.2) every 4 minutes using your **laptop as intermediary**.

**Architecture:**
```
Production (192.168.1.1)
         ↓
    Your Laptop (intermediary)
         ↓
Test (192.168.64.2)
```

Since the two firewalls cannot communicate directly, your laptop fetches data from production and pushes it to test.

## 🎯 What Gets Synced

### ✅ Synced (Every 4 minutes)
- **State File**: `/var/db/parental_control/parental_control_state.json`
  - Device usage data
  - Profile usage totals
  - Blocked devices list
  - Discovered devices
- **Logs**: `/var/log/parental_control/*.log` (last 1000 lines)
- **Configuration**:
  - Profiles (device groups, time limits)
  - Schedules (bedtime, school time, etc.)
  - Settings (global config)
  - Services (Online Services feature - if XML-safe)

### ❌ NOT Synced (Test-specific)
- Interface configurations
- NAT rules
- Firewall rules
- Network settings
- Static IP mappings
- Non-KACI packages

## 📦 Scripts

| Script | Location | Purpose |
|--------|----------|---------|
| `sync_production_data.sh` | `/Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/` | Main sync script |
| `setup_sync_cron.sh` | Same | Installs cron job on laptop |
| `view_sync_status.sh` | Same | View sync status |

## 🚀 Quick Start

### Step 1: Navigate to Script Directory

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
```

### Step 2: Make Scripts Executable

```bash
chmod +x sync_production_data.sh setup_sync_cron.sh view_sync_status.sh
```

### Step 3: Run Setup (First Time)

```bash
./setup_sync_cron.sh
```

**On first run, it will:**
1. Generate SSH key pair on your laptop
2. Prompt for production firewall password
3. Copy SSH key to production (for passwordless access)
4. Prompt for test firewall password
5. Copy SSH key to test (for passwordless access)
6. Test connections to both systems
7. Perform the first sync
8. Install cron job to run every 4 minutes

**You'll see:**
```
SSH KEY SETUP - First Time Configuration
Generating SSH key pair...
✓ SSH key generated

Setting up passwordless SSH to PRODUCTION (192.168.1.1)...
[Enter password for production]

Setting up passwordless SSH to TEST (192.168.64.2)...
[Enter password for test]

✓ Passwordless SSH to both systems works!
```

### Step 4: Verify It's Working

```bash
./view_sync_status.sh
```

## 📊 Monitoring & Management

### View Sync Status

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
./view_sync_status.sh
```

**Shows:**
- Current sync status (Running/Idle)
- Cron schedule
- Network connectivity to both firewalls
- Last sync time
- State file info on test system
- Recent activity

### Watch Live Sync

```bash
tail -f /tmp/kaci_sync.log
```

### Run Manual Sync

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
./sync_production_data.sh
```

### View All Logs

```bash
cat /tmp/kaci_sync.log
```

### Check Cron Jobs

```bash
crontab -l | grep sync_production_data
```

## 🔧 Advanced Usage

### Change Sync Interval

Edit cron schedule:
```bash
crontab -e
```

Find the line with `sync_production_data.sh` and change `*/4` to desired interval:
- `*/2` = Every 2 minutes
- `*/5` = Every 5 minutes
- `*/10` = Every 10 minutes

### Disable Auto-Sync

```bash
crontab -l | grep -v sync_production_data.sh | crontab -
```

### Re-enable Auto-Sync

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
./setup_sync_cron.sh
```

### Clear Sync Logs

```bash
rm /tmp/kaci_sync.log
```

### Test SSH Connections

```bash
# Test production
ssh -o BatchMode=yes mkesharw@192.168.1.1 "echo 'Production OK'"

# Test test firewall
ssh -o BatchMode=yes mkesharw@192.168.64.2 "echo 'Test OK'"
```

If these work without password prompts, passwordless SSH is configured correctly.

## 🛠️ Troubleshooting

### Issue: "Cannot connect via SSH"

**Solution:**
```bash
# Re-run setup to reconfigure SSH keys
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
./sync_production_data.sh
```

Enter passwords when prompted to re-setup SSH keys.

### Issue: "Another sync is already running"

**Solution:**
```bash
# Remove stale lock file
rm /tmp/kaci_sync.lock

# Run sync again
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
./sync_production_data.sh
```

### Issue: Sync not running automatically

**Check cron:**
```bash
crontab -l | grep sync_production_data
```

If nothing shows up:
```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
./setup_sync_cron.sh
```

### Issue: "State file not found on production"

This is normal for new installations. The script will sync once data exists.

### Issue: "Services config is not XML-safe, skipped"

The Online Services feature on production uses old format. This is expected and safe to ignore.

### Issue: Network unreachable

Make sure:
1. Your laptop can reach 192.168.1.1 (production)
2. Your laptop can reach 192.168.64.2 (test)
3. You're connected to the correct network
4. Firewalls allow SSH (port 22)

Test connectivity:
```bash
ping 192.168.1.1
ping 192.168.64.2
```

## 📁 File Locations

### On Your Laptop

| File | Location | Purpose |
|------|----------|---------|
| Sync scripts | `/Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/` | Executable scripts |
| Sync log | `/tmp/kaci_sync.log` | Detailed sync log |
| Lock file | `/tmp/kaci_sync.lock` | Prevents concurrent runs |
| Temp data | `/tmp/kaci_sync_data/` | Temporary sync data |

### On Production Firewall (192.168.1.1)

| File | Location | Purpose |
|------|----------|---------|
| State file | `/var/db/parental_control/parental_control_state.json` | Usage data (source) |
| Logs | `/var/log/parental_control/*.log` | Application logs (source) |
| Config | `/cf/conf/config.xml` | pfSense config (source) |

### On Test Firewall (192.168.64.2)

| File | Location | Purpose |
|------|----------|---------|
| State file | `/var/db/parental_control/parental_control_state.json` | Synced usage data |
| Logs | `/var/log/parental_control/*.log` | Synced logs |
| Config | `/cf/conf/config.xml` | Synced profiles/schedules |

## 🔐 Security Notes

1. **SSH Keys**: 4096-bit RSA keys generated on your laptop
2. **Access**: Laptop has read access to production, write access to test
3. **No Passwords**: After setup, no passwords stored or transmitted
4. **Lock File**: Prevents multiple simultaneous syncs
5. **Temporary Data**: Cleaned up after each sync

## 🎯 Benefits

### For Testing
- ✅ **Real Data**: Test with actual device usage, profiles, schedules
- ✅ **Realistic**: See how new features behave with production config
- ✅ **Safe**: Test firewall isolated from production
- ✅ **Fresh**: Data refreshed every 4 minutes
- ✅ **Comprehensive**: All parental control data synced

### For Development
- ✅ **Debug**: Real-world scenarios for troubleshooting
- ✅ **Validate**: Confirm fixes work with actual data
- ✅ **Compare**: Side-by-side comparison (production vs test)
- ✅ **Confidence**: Deploy to production with certainty

## 📝 Example Workflow

1. **Develop** new feature on local machine
2. **Deploy** to test firewall (192.168.64.2)
3. **Automatic sync** brings production data to test (via laptop)
4. **Test** feature with real devices/profiles/schedules
5. **Monitor** logs and behavior
6. **Fix** issues if needed
7. **Deploy** to production (192.168.1.1) with confidence

## ⚠️ Important Notes

1. **Laptop Must Be On**: Your laptop must be powered on and connected to network for automatic syncing
2. **One-Way Sync**: Production → Test only. Changes on test are overwritten every 4 minutes
3. **4-Minute Refresh**: Test data is at most 4 minutes old
4. **Config Sections**: Only KACI Parental Control config synced
5. **Disk Space**: Logs capped at last 1000 lines to save space
6. **Network Requirements**: Laptop must be able to reach both firewalls

## 🎉 Result

After setup, you'll have a test environment that automatically mirrors your production KACI Parental Control setup, with your laptop acting as the sync intermediary!

---

## 📞 Need Help?

**View sync status:**
```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
./view_sync_status.sh
```

**Check logs:**
```bash
tail -f /tmp/kaci_sync.log
```

**Manual sync:**
```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control/diagnostic/
./sync_production_data.sh
```

**Architecture Reminder:**
```
Production (192.168.1.1)
         ↓ SSH (fetch)
    Your Laptop
         ↓ SSH (push)
Test (192.168.64.2)
```

Your laptop downloads data from production and uploads it to test every 4 minutes!
