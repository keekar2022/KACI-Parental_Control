# Auto-Update Feature

**Automatically pull and deploy updates from GitHub to your pfSense firewall**

This feature is perfect for development/testing environments where you want the latest changes deployed automatically without manual installation.

---

## 🎯 What It Does

The auto-update system:
- ✅ Checks GitHub every 5 minutes for new commits
- ✅ Automatically pulls latest changes if available
- ✅ Deploys updated files to correct pfSense locations
- ✅ Validates PHP syntax before deployment
- ✅ Creates automatic backups before each update
- ✅ Rolls back on errors
- ✅ Logs all activities
- ✅ Triggers config sync after updates

---

## 🚀 Quick Setup

### Step 1: Copy Scripts to pfSense

```bash
scp /tmp/auto_update_parental_control.sh mkesharw@fw.keekar.com:/tmp/
scp /tmp/setup_auto_update.sh mkesharw@fw.keekar.com:/tmp/
```

### Step 2: Run Setup

```bash
ssh mkesharw@fw.keekar.com
sudo sh /tmp/setup_auto_update.sh
```

### Step 3: Done!

Updates will now happen automatically every 5 minutes!

---

## 📁 File Locations

| Item | Location |
|------|----------|
| **Auto-update script** | `/usr/local/bin/auto_update_parental_control.sh` |
| **Git repository** | `/root/KACI-Parental_Control` |
| **Log file** | `/var/log/parental_control_auto_update.log` |
| **Backups** | `/root/parental_control_backups/` |
| **Lock file** | `/var/run/parental_control_update.lock` |

---

## 📊 Monitoring

### View Live Updates

```bash
tail -f /var/log/parental_control_auto_update.log
```

### Check Last Update

```bash
tail -20 /var/log/parental_control_auto_update.log
```

### View Update History

```bash
cat /var/log/parental_control_auto_update.log | grep "Update completed"
```

### Check Current Version

```bash
grep CURRENT_VERSION /root/KACI-Parental_Control/VERSION
```

---

## 🔧 Management Commands

### Manual Update (Force Check Now)

```bash
sudo /usr/local/bin/auto_update_parental_control.sh
```

### View Cron Schedule

```bash
sudo crontab -l | grep auto_update
```

### View Backups

```bash
ls -lh /root/parental_control_backups/
```

### Restore from Backup

```bash
# List available backups
ls /root/parental_control_backups/

# Restore specific backup
sudo cp /root/parental_control_backups/backup_YYYYMMDD_HHMMSS/parental_control.inc /usr/local/pkg/
```

---

## ⚙️ Configuration

### Change Update Frequency

Edit the cron schedule:

```bash
# Every 5 minutes (default)
*/5 * * * * /usr/local/bin/auto_update_parental_control.sh

# Every 10 minutes
*/10 * * * * /usr/local/bin/auto_update_parental_control.sh

# Every hour
0 * * * * /usr/local/bin/auto_update_parental_control.sh

# Every 6 hours
0 */6 * * * /usr/local/bin/auto_update_parental_control.sh
```

To modify:

```bash
sudo crontab -e
```

### Disable Auto-Updates

```bash
sudo crontab -l | grep -v auto_update_parental_control | sudo crontab -
```

### Re-enable Auto-Updates

```bash
(sudo crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/auto_update_parental_control.sh") | sudo crontab -
```

---

## 🔍 How It Works

### Update Process Flow

```
1. Check lock file (prevent concurrent updates)
   ↓
2. Fetch latest commits from GitHub
   ↓
3. Compare local vs remote commit hashes
   ↓
4. If updates available:
   a. Create backup of current files
   b. Pull latest changes
   c. Deploy files to pfSense locations
   d. Validate PHP syntax
   e. If syntax error → rollback to backup
   f. If syntax OK → trigger config sync
   ↓
5. Log results
   ↓
6. Clean up old backups (keep last 10)
   ↓
7. Remove lock file
```

### Safety Features

- **Lock file** prevents concurrent updates
- **Backups** created before each update
- **Syntax validation** catches PHP errors
- **Automatic rollback** on failures
- **Comprehensive logging** for debugging
- **Stale lock cleanup** prevents deadlocks

---

## 📝 Log Format

```
[2025-12-26 09:15:00] =========================================
[2025-12-26 09:15:00] Auto-Update Check Started
[2025-12-26 09:15:00] =========================================
[2025-12-26 09:15:01] Fetching latest changes from GitHub...
[2025-12-26 09:15:02] Local commit:  170189f
[2025-12-26 09:15:02] Remote commit: 170189f
[2025-12-26 09:15:02] No updates available
[2025-12-26 09:15:02] Auto-Update Check Completed
```

---

## ⚠️ Important Notes

### For Production

**NOT RECOMMENDED** for production firewalls! Auto-updates can introduce:
- Unexpected changes
- Potential bugs
- Service disruptions

**Use Case**: Development/testing environments only

### For Production Firewalls

Use manual updates with proper testing:

```bash
# 1. Test in dev environment first
# 2. Review changes
# 3. Schedule maintenance window
# 4. Manually deploy: ./INSTALL.sh fw.keekar.com
# 5. Verify functionality
```

### Git Requirements

The script requires `git` to be installed on pfSense:

```bash
# Install git (done automatically by setup script)
sudo pkg install -y git
```

### Network Access

pfSense must have internet access to reach GitHub:
- HTTPS (port 443) access to github.com
- DNS resolution working

---

## 🐛 Troubleshooting

### Updates Not Running

**Check cron job:**
```bash
sudo crontab -l | grep auto_update
```

**Check if script is executable:**
```bash
ls -l /usr/local/bin/auto_update_parental_control.sh
```

**Run manually to see errors:**
```bash
sudo /usr/local/bin/auto_update_parental_control.sh
```

### Updates Failing

**Check the log:**
```bash
tail -50 /var/log/parental_control_auto_update.log
```

**Check git status:**
```bash
cd /root/KACI-Parental_Control
sudo git status
sudo git log -1
```

**Reset repository if corrupted:**
```bash
cd /root
sudo rm -rf KACI-Parental_Control
# Run setup again
sudo sh /tmp/setup_auto_update.sh
```

### Lock File Issues

**Remove stale lock:**
```bash
sudo rm -f /var/run/parental_control_update.lock
```

### Disk Space

**Check available space:**
```bash
df -h /root
```

**Clean old backups:**
```bash
cd /root/parental_control_backups
sudo rm -rf backup_202*
```

---

## 🔐 Security Considerations

### Read-Only Access

The script uses `git clone` and `git pull` which are read-only operations. No write access to GitHub is required.

### Local File Permissions

All deployed files are owned by `root` with appropriate permissions.

### Backup Security

Backups are stored in `/root/` which is only accessible by root user.

---

## 📊 Statistics

The auto-update system tracks:
- Last update check time
- Last successful update
- Current version/commit
- Update success/failure rate
- Rollback occurrences

View in logs:
```bash
grep "Update completed" /var/log/parental_control_auto_update.log | tail -10
```

---

## 🔄 Update Workflow Example

**Typical Development Cycle:**

1. **Developer**: Make changes locally
2. **Developer**: Test changes
3. **Developer**: `git commit && git push` to GitHub
4. **pfSense**: Auto-detects update within 5 minutes
5. **pfSense**: Automatically deploys changes
6. **Developer**: Verify deployment worked
7. **Repeat**

**Time Savings:**
- Manual deployment: ~2-3 minutes
- Auto-update: 0 seconds (automatic)
- Per day (20 updates): **40-60 minutes saved!**

---

## 📚 Related Documentation

- [Quick Start Guide](docs/QUICKSTART.md)
- [Development Workflow](docs/DEVELOPMENT.md)
- [Installation Guide](README.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)

---

## 💡 Tips

### Development Best Practices

1. **Test locally** before pushing to GitHub
2. **Use feature branches** for experimental changes
3. **Watch the logs** during active development
4. **Keep backups** of working versions
5. **Document breaking changes** in commit messages

### Monitoring During Development

**Terminal 1** - Watch auto-update logs:
```bash
ssh mkesharw@fw.keekar.com "tail -f /var/log/parental_control_auto_update.log"
```

**Terminal 2** - Watch parental control logs:
```bash
ssh mkesharw@fw.keekar.com "tail -f /var/log/parental_control-*.jsonl | jq -c ."
```

**Terminal 3** - Watch pfSense system log:
```bash
ssh mkesharw@fw.keekar.com "tail -f /var/log/system.log | grep parental"
```

---

**Author**: Mukesh Kesharwani  
**Version**: 1.0  
**Date**: December 26, 2025  
**Status**: Production Ready for Dev/Test Environments

