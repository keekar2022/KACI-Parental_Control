# Production Data Sync Script - Updated for Direct Firewall Communication

## Overview

The `sync_production_data.sh` script has been **updated** to support direct communication between production and test firewalls, eliminating the need for a laptop as an intermediary.

### Changes Made

| Aspect | Old Behavior | New Behavior |
|--------|--------------|--------------|
| **Architecture** | Laptop intermediary required | Direct firewall-to-firewall |
| **Source** | Production: 192.168.1.1 | Production: 192.168.1.1 (unchanged) |
| **Destination** | Test: 192.168.64.2 | Test: 192.168.1.251 (updated) |
| **Runs On** | Your laptop | Test firewall (192.168.1.251) |
| **Connection** | Laptop → Both firewalls | Test → Production (direct pull) |

## What Gets Synced

The script syncs the following data from production (192.168.1.1) to test (192.168.1.251):

### 1. **State File** (`/var/db/parental_control_state.json`)
   - Current device usage data
   - Blocked devices list
   - Override status
   - Online devices tracking

### 2. **Log Files**
   - Main JSONL log: `/var/log/parental_control.jsonl` (last 5000 lines)
   - Old format logs: `/var/log/parental_control/*.log` (last 1000 lines each)

### 3. **Configuration**
   - **Profiles**: All parental control profiles with time limits
   - **Devices**: Device-to-profile mappings
   - **Schedules**: Time-based blocking schedules
   - **Services**: Online service configurations (YouTube, Facebook, Discord)
   - **Settings**: Main parental control settings
   - **Service Aliases**: URL table aliases (PC_Service_*)

### 4. **Alias Table Files** (`/var/db/aliastables/PC_Service_*.txt`)
   - Downloaded IP lists for each service
   - Allows immediate testing without re-downloading URLs

## Location

**On Test Firewall (192.168.1.251):**
```
/root/sync_production_data.sh
```

Also available in project:
```
/Users/mkesharw/Documents/KACI-Parental_Control-Dev/diagnostic/sync_production_data.sh
```

## First Time Setup

The script will automatically prompt for SSH key setup on first run.

### Manual SSH Key Setup (if needed):

**On test firewall (192.168.1.251):**

```bash
# Generate SSH key (if not exists)
ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa -N ""

# Copy key to production
ssh-copy-id admin@192.168.1.1

# Test connection
ssh admin@192.168.1.1 "echo 'Connection successful!'"
```

## Usage

### Run Manually

**SSH to test firewall:**
```bash
ssh admin@192.168.1.251
```

**Run sync script:**
```bash
cd ~
./sync_production_data.sh
```

### Expected Output

```
===================================================================
  KACI Parental Control - Production Data Sync
  Production: 192.168.1.1 → Test: test.localdomain
===================================================================

Starting sync at Sun Jan  5 07:40:15 PST 2026
-------------------------------------------------------------------
Syncing state file...
✓ State file synced (45678 bytes)

Syncing log files...
  - Syncing main log file (last 5000 lines)...
  ✓ Main log synced (4523 lines)
✓ 1 log file(s) synced

Syncing configuration...
  - Extracting profiles...
  - Extracting devices...
  - Extracting schedules...
  - Extracting services...
  - Extracting main settings...
  - Extracting service aliases...
  - Importing to test system...
✓ Imported: Profiles (3), Devices (5), Services (3), Service Aliases (3)
✓ Configuration synced

Syncing URL alias table files...
  - Syncing PC_Service_YouTube table...
  ✓ PC_Service_YouTube synced (29453 IPs)
  - Syncing PC_Service_Facebook table...
  ✓ PC_Service_Facebook synced (1444 IPs)
  - Syncing PC_Service_Discord table...
  ✓ PC_Service_Discord synced (28 IPs)
✓ 3 alias table(s) synced

-------------------------------------------------------------------
===================================================================
  SYNC SUMMARY
===================================================================
  State file: 45678 bytes
  Logs: 1 files synced
  Config: Profiles (3), Devices (5), Services (3), Service Aliases (3)
  Alias Tables: 3 files synced
===================================================================

✓ Sync completed successfully at Sun Jan  5 07:40:35 PST 2026
===================================================================

📋 To view full sync log: tail -50 /var/log/kaci_sync.log
📊 To view sync summary: cat /tmp/kaci_sync_summary.txt
```

### Schedule Automatic Sync

To sync data automatically every 30 minutes:

**Edit crontab:**
```bash
crontab -e
```

**Add this line:**
```
*/30 * * * * /root/sync_production_data.sh >> /var/log/kaci_sync_cron.log 2>&1
```

**Save and exit**

This will:
- Run sync every 30 minutes
- Log output to `/var/log/kaci_sync_cron.log`
- Keep test firewall updated with production data

### View Sync Status

**View main sync log:**
```bash
tail -50 /var/log/kaci_sync.log
```

**View latest sync summary:**
```bash
cat /tmp/kaci_sync_summary.txt
```

**View cron log (if scheduled):**
```bash
tail -50 /var/log/kaci_sync_cron.log
```

## Testing After Sync

After running the sync script, verify the data was transferred:

### 1. Check State File
```bash
ls -lh /var/db/parental_control_state.json
cat /var/db/parental_control_state.json | head -50
```

### 2. Check Logs
```bash
ls -lh /var/log/parental_control.jsonl
tail -20 /var/log/parental_control.jsonl
```

### 3. Check Configuration (Web UI)
- Go to Parental Control > Profiles
- Verify profiles from production are listed
- Go to Parental Control > Devices
- Verify devices are synced

### 4. Check Service Aliases (Web UI)
- Go to Firewall > Aliases > URLs
- Look for: `PC_Service_YouTube`, `PC_Service_Facebook`, `PC_Service_Discord`
- Click Edit on any alias
- Verify URLs are populated

### 5. Check Alias Tables
```bash
ls -lh /var/db/aliastables/PC_Service_*.txt
wc -l /var/db/aliastables/PC_Service_*.txt
```

### 6. Test with Real Data (Web UI)
- Go to Parental Control > Status
- Should show devices and usage from production
- Check if blocked devices are listed

## Troubleshooting

### SSH Connection Fails

**Error:**
```
Cannot connect to production via SSH (192.168.1.1)
```

**Solution:**
```bash
# Test connection manually
ssh admin@192.168.1.1

# If password is required, setup SSH keys:
ssh-copy-id admin@192.168.1.1

# Or run script with --setup flag (if implemented)
./sync_production_data.sh
```

### State File Not Found

**Warning:**
```
⚠ State file not found on production (might be new installation)
```

**Explanation:** Production firewall doesn't have state file yet (new installation or service not started).

**Solution:** Start parental control on production first.

### No Log Files

**Warning:**
```
⚠ No log files found on production
```

**Explanation:** Parental control hasn't generated logs yet.

**Solution:** Let production run for a while to generate logs.

### Sync Running (Lock File)

**Message:**
```
Another sync is already running (PID: 12345), exiting
```

**Explanation:** Another instance of sync is running.

**Solution:** Wait for it to complete, or remove lock file:
```bash
rm -f /tmp/kaci_sync.lock
```

### Permission Denied

**Error:**
```
Permission denied
```

**Solution:** Run as root or with sudo:
```bash
sudo ./sync_production_data.sh
```

## Advanced Usage

### Sync Specific Components Only

**Edit script to comment out unwanted syncs:**

```bash
# Edit the script
vi ~/sync_production_data.sh

# Comment out unwanted sections in main():
# sync_state_file      # Skip state file
sync_logs              # Only logs
sync_config            # Only config
# sync_alias_tables    # Skip alias tables
```

### Dry Run (Test Mode)

To see what would be synced without actually syncing:

```bash
# Add this at the top of each sync function
echo "DRY RUN: Would sync..."
return 0
```

### Custom Production Host

If your production IP changes:

```bash
# Edit script
vi ~/sync_production_data.sh

# Change PROD_HOST line:
PROD_HOST="192.168.1.1"  # Change this to new IP
```

Or run with environment variable:
```bash
PROD_HOST="192.168.1.10" ./sync_production_data.sh
```

## Architecture Comparison

### Old Architecture (Laptop Intermediary)
```
Production (192.168.1.1)
    ↓ SSH/SCP
Laptop (Your Machine)
    ↓ SSH/SCP
Test (192.168.64.2)
```

### New Architecture (Direct)
```
Production (192.168.1.1)
    ↓ SSH/SCP (Direct)
Test (192.168.1.251)
```

## Benefits of Direct Sync

1. **Simpler**: No need to run on laptop
2. **Automated**: Can be scheduled with cron
3. **Faster**: One-hop instead of two-hop transfer
4. **Reliable**: Doesn't depend on laptop being online
5. **Real-time**: Can sync frequently (every 30 min)

## Files Generated

| File | Description | Location |
|------|-------------|----------|
| `kaci_sync.lock` | Lock file (prevents concurrent runs) | `/tmp/kaci_sync.lock` |
| `kaci_sync.log` | Main sync log | `/var/log/kaci_sync.log` |
| `kaci_sync_summary.txt` | Latest sync summary | `/tmp/kaci_sync_summary.txt` |
| `kaci_sync_data/` | Temporary sync directory | `/tmp/kaci_sync_data/` |
| `kaci_sync_cron.log` | Cron job log (if scheduled) | `/var/log/kaci_sync_cron.log` |

## Security Considerations

1. **SSH Keys**: Passwordless SSH uses public key authentication
2. **Root Access**: Script runs as root on test firewall
3. **Network**: Both firewalls must be on same network or have routing
4. **Firewall Rules**: Ensure SSH (port 22) is allowed between firewalls

## Next Steps

1. **Run the script** manually to verify it works
2. **Check the output** and verify data was synced
3. **Test new features** with real production data
4. **Schedule automatic sync** with cron if desired
5. **Monitor sync logs** for any issues

## Support

If you encounter issues:

1. **Check SSH connectivity**: `ssh admin@192.168.1.1`
2. **Review sync log**: `tail -100 /var/log/kaci_sync.log`
3. **Verify file permissions**: `ls -l /root/sync_production_data.sh`
4. **Test manually first**: Run script interactively to see errors

---

*Updated: January 5, 2026*  
*Part of KACI Parental Control for pfSense*  
*Copyright © 2025 Mukesh Kesharwani*

