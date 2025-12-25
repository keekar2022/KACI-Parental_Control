# Configuration Guide
## KACI Parental Control - Advanced Configuration Options

**Version:** 0.1.3  
**Author:** Mukesh Kesharwani  
**Last Updated:** December 25, 2025

---

## Table of Contents

1. [Environment Variables](#environment-variables)
2. [Configuration Files](#configuration-files)
3. [Enforcement Modes](#enforcement-modes)
4. [Time Limit Settings](#time-limit-settings)
5. [Schedule Configuration](#schedule-configuration)
6. [Logging Configuration](#logging-configuration)
7. [Performance Tuning](#performance-tuning)

---

## Environment Variables

KACI Parental Control supports environment variable overrides for flexible deployment and testing.

### Supported Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PC_LOG_FILE` | `/var/log/parental_control.jsonl` | Primary log file path |
| `PC_STATE_FILE` | `/var/db/parental_control_state.json` | State persistence file |
| `PC_CRON_MINUTE` | `*/1` | Cron schedule (every minute) |
| `PC_MAX_LOG_SIZE` | `5242880` (5MB) | Maximum log file size before rotation |
| `PC_MAX_LOG_FILES` | `10` | Number of log files to keep |
| `PC_LOG_PREFIX` | `parental_control` | Log file name prefix |

### Setting Environment Variables

**In pfSense Shell:**
```bash
# Temporary (current session only)
export PC_LOG_FILE=/custom/path/parental.jsonl
export PC_MAX_LOG_SIZE=10485760  # 10MB

# Restart PHP-FPM to apply
/usr/local/etc/rc.d/php-fpm restart
```

**Persistent (survives reboot):**
```bash
# Edit /etc/rc.conf.local
echo 'PC_LOG_FILE="/custom/path/parental.jsonl"' >> /etc/rc.conf.local
echo 'PC_MAX_LOG_SIZE="10485760"' >> /etc/rc.conf.local
```

### Use Cases for Environment Variables

**1. Testing Environment:**
```bash
export PC_STATE_FILE=/tmp/test_state.json
export PC_LOG_FILE=/tmp/test.jsonl
# Run tests without affecting production state
```

**2. High-Volume Deployment:**
```bash
export PC_MAX_LOG_SIZE=52428800  # 50MB files
export PC_MAX_LOG_FILES=50       # Keep more history
```

**3. Low-Disk Space:**
```bash
export PC_MAX_LOG_SIZE=1048576   # 1MB files
export PC_MAX_LOG_FILES=5        # Keep fewer files
```

---

## Configuration Files

### State File Format

**Location:** `/var/db/parental_control_state.json`

**Structure:**
```json
{
  "devices": {
    "aa:bb:cc:dd:ee:ff": {
      "usage_today": 120,
      "usage_week": 600,
      "last_seen": 1735084800
    }
  },
  "profiles": {
    "Emma": {
      "usage_today": 90,
      "usage_week": 450,
      "last_reset": 1735084800
    }
  },
  "last_reset": 1735084800,
  "last_check": 1735088400
}
```

**Fields:**
- `usage_today` / `usage_week`: Minutes of usage
- `last_seen`: Unix timestamp of last device detection
- `last_reset`: Unix timestamp of last daily reset
- `last_check`: Unix timestamp of last cron job run

### pfSense Configuration Paths

KACI Parental Control stores configuration in pfSense's `/cf/conf/config.xml`:

**Configuration Paths:**
- Main settings: `installedpackages/parentalcontrol/config/0`
- Profiles: `installedpackages/parentalcontrolprofiles/config`

**Backup Configuration:**
```bash
# Backup current config
cp /cf/conf/config.xml /root/config-backup-$(date +%Y%m%d).xml

# View parental control config only
xml sel -t -c '//installedpackages/parentalcontrol' /cf/conf/config.xml
```

---

## Enforcement Modes

Choose how strictly to enforce time limits and schedules.

### Strict Mode (Recommended)

**Behavior:** Blocks ALL internet traffic (except LAN)

**Pros:**
- ✅ Most effective - can't be bypassed
- ✅ Blocks all apps and services
- ✅ Works for all protocols (HTTP, HTTPS, games, streaming)

**Cons:**
- ⚠️ Blocks everything including educational apps

**Use Case:** General parental control

**Configuration:**
```xml
<enforcement_mode>strict</enforcement_mode>
```

### Moderate Mode

**Behavior:** Blocks HTTP and HTTPS only

**Pros:**
- ✅ Allows messaging apps and games
- ✅ More flexible for older children
- ✅ Educational apps still work

**Cons:**
- ⚠️ Can be bypassed via non-web protocols
- ⚠️ Doesn't block all streaming services

**Use Case:** Teenagers who need some flexibility

**Configuration:**
```xml
<enforcement_mode>moderate</enforcement_mode>
```

### Soft Mode (DNS-based)

**Behavior:** Blocks DNS resolution only

**Pros:**
- ✅ Very lightweight
- ✅ Minimal firewall impact

**Cons:**
- ⚠️ Easy to bypass (use alternate DNS)
- ⚠️ Not recommended for parental control

**Use Case:** Testing or demonstration only

**Configuration:**
```xml
<enforcement_mode>soft</enforcement_mode>
```

---

## Time Limit Settings

### Daily Limits

**Format:** Minutes (0-1440)

**Examples:**
- `60` = 1 hour/day
- `120` = 2 hours/day
- `240` = 4 hours/day
- `0` = Unlimited

**Configuration:**
```xml
<daily_limit>120</daily_limit>
```

### Weekend Bonus

**Format:** Additional minutes for Friday-Sunday

**Examples:**
- `30` = Extra 30 minutes on weekends
- `60` = Extra 1 hour on weekends
- `120` = Extra 2 hours on weekends

**Total Weekend Time:**
```
Weekend Time = Daily Limit + Weekend Bonus
Example: 120 + 60 = 180 minutes (3 hours) on weekends
```

**Configuration:**
```xml
<weekend_bonus>60</weekend_bonus>
```

### Weekly Limits

**Format:** Minutes (0-10080)

**Purpose:** Set a total weekly cap across all days

**Example Scenarios:**

**Scenario 1: Daily + Weekly Limits**
- Daily: 120 minutes
- Weekly: 600 minutes
- Result: Max 2 hours/day, but only 10 hours/week total

**Scenario 2: Weekly Only**
- Daily: 0 (unlimited)
- Weekly: 840 minutes (14 hours)
- Result: Can use any amount per day, but only 14 hours/week total

**Configuration:**
```xml
<weekly_limit>600</weekly_limit>
```

### Reset Time

**Default:** Midnight (00:00)

**Options:**
- `midnight` = 00:00
- `06:00` = 6:00 AM
- `07:00` = 7:00 AM
- `08:00` = 8:00 AM

**Use Case:** If child stays up past midnight, set reset to 6 AM

**Configuration:**
```xml
<reset_time>midnight</reset_time>
```

---

## Schedule Configuration

### Schedule Format

**Structure:**
```xml
<schedule>
  <name>Bedtime</name>
  <days>mon,tue,wed,thu,sun</days>
  <start_time>21:00</start_time>
  <end_time>07:00</end_time>
</schedule>
```

### Day Specifications

**Format Options:**
- Comma-separated: `mon,wed,fri`
- Range: `mon-fri`
- Mixed: `mon-fri,sun`
- All days: `mon,tue,wed,thu,fri,sat,sun`

**Day Abbreviations:**
- `mon` = Monday
- `tue` = Tuesday
- `wed` = Wednesday
- `thu` = Thursday
- `fri` = Friday
- `sat` = Saturday
- `sun` = Sunday

### Time Format

**Format:** `HH:MM` (24-hour)

**Examples:**
- `08:00` = 8:00 AM
- `15:30` = 3:30 PM
- `21:00` = 9:00 PM
- `23:59` = 11:59 PM

### Overnight Schedules

**Example: Bedtime (21:00-07:00)**
```xml
<start_time>21:00</start_time>
<end_time>07:00</end_time>
```

**Logic:** Block if current time >= 21:00 OR <= 07:00

### Common Schedule Examples

**1. School Night Bedtime:**
```xml
<name>School Night Bedtime</name>
<days>sun,mon,tue,wed,thu</days>
<start_time>20:00</start_time>
<end_time>07:00</end_time>
```

**2. School Hours:**
```xml
<name>School Hours</name>
<days>mon-fri</days>
<start_time>08:00</start_time>
<end_time>15:00</end_time>
```

**3. Homework Time:**
```xml
<name>Homework Time</name>
<days>mon-fri</days>
<start_time>16:00</start_time>
<end_time>18:00</end_time>
```

**4. Family Dinner:**
```xml
<name>Dinner Time</name>
<days>mon,tue,wed,thu,fri,sat,sun</days>
<start_time>18:00</start_time>
<end_time>19:00</end_time>
```

**5. Weekend Only (Reverse Logic):**
```xml
<!-- Block weekdays 00:00-23:59 -->
<name>Weekday Block</name>
<days>mon-fri</days>
<start_time>00:00</start_time>
<end_time>23:59</end_time>
```

---

## Logging Configuration

### Log Levels

**Available Levels:**
- `debug` - Detailed diagnostic information
- `info` - General operational events (default)
- `warning` - Important but non-critical issues
- `error` - Failures requiring attention

**Configuration:**
```xml
<log_level>info</log_level>
```

### Log Format

**Format:** OpenTelemetry-compliant JSON Lines

**Example Log Entry:**
```json
{
  "Timestamp": "2025-12-25T10:30:00.000000Z",
  "SeverityText": "INFO",
  "Body": "Device blocked: Time limit exceeded",
  "Attributes": {
    "child.name": "Emma",
    "device.mac": "aa:bb:cc:dd:ee:ff",
    "reason": "Time limit exceeded"
  }
}
```

### Log Rotation

**Automatic Rotation:**
- Rotates when file reaches `PC_MAX_LOG_SIZE` (default: 5MB)
- Keeps last `PC_MAX_LOG_FILES` files (default: 10)
- Dated filenames: `parental_control-2025-12-25.jsonl`
- Sequential numbering: `parental_control-2025-12-25-1.jsonl`

**Manual Log Management:**
```bash
# View current log
tail -f /var/log/parental_control-$(date +%Y-%m-%d).jsonl

# Archive old logs
tar czf parental_logs_$(date +%Y%m).tar.gz /var/log/parental_control-*.jsonl

# Clear old logs (keep last 7 days)
find /var/log -name "parental_control-*.jsonl" -mtime +7 -delete
```

---

## Performance Tuning

### Cron Job Frequency

**Default:** Every minute (`*/1`)

**Options:**
- `*/1` - Every minute (recommended for accurate tracking)
- `*/2` - Every 2 minutes (light load systems)
- `*/5` - Every 5 minutes (minimal overhead, less accurate)

**Trade-off:**
- More frequent = More accurate tracking, higher system load
- Less frequent = Lower system load, less accurate tracking

**Configuration:**
```bash
export PC_CRON_MINUTE='*/2'  # Every 2 minutes
```

### State File Optimization

**Recommendations:**
1. Keep state file on fast storage (SSD preferred)
2. Avoid network-mounted storage for state file
3. Monitor state file size (should be < 1MB typically)

**Check State File Size:**
```bash
ls -lh /var/db/parental_control_state.json
```

### Log Performance

**For High-Volume Deployments:**
```bash
# Increase log file size
export PC_MAX_LOG_SIZE=52428800  # 50MB

# Reduce log level
# Set to 'warning' or 'error' only in web UI
```

**For Low-Resource Systems:**
```bash
# Decrease log file size and count
export PC_MAX_LOG_SIZE=1048576   # 1MB
export PC_MAX_LOG_FILES=5        # Keep only 5 files
```

---

## Advanced Configuration

### Grace Period

**Purpose:** Warning time before enforcing blocks

**Default:** 5 minutes

**Configuration:**
```xml
<grace_period>5</grace_period>
```

**Behavior:**
- When limit reached, device has grace period before blocking
- Allows user to save work and finish activities
- After grace period, full block is enforced

### Override Password

**Purpose:** Allow parents to temporarily bypass restrictions

**Configuration:**
```xml
<override_password>secure_password_here</override_password>
<override_duration>30</override_duration>
```

**Duration:** Minutes of temporary access (default: 30)

---

## Configuration Backup and Restore

### Backup Configuration

```bash
# Full pfSense backup (includes parental control)
/etc/pfSense/pfSsh.php playback generatebackup

# Backup state file only
cp /var/db/parental_control_state.json /root/pc_state_backup_$(date +%Y%m%d).json
```

### Restore Configuration

```bash
# Restore state file
cp /root/pc_state_backup_20251225.json /var/db/parental_control_state.json

# Verify restore
cat /var/db/parental_control_state.json | jq '.'
```

---

## Troubleshooting Configuration Issues

### Configuration Not Taking Effect

1. **Check service is enabled:**
   - Web UI > Services > Parental Control
   - "Enable Parental Control" should be checked

2. **Force configuration reload:**
   ```bash
   /etc/rc.filter_configure
   ```

3. **Restart PHP-FPM:**
   ```bash
   /usr/local/etc/rc.d/php-fpm restart
   ```

### State File Corruption

```bash
# Backup corrupt file
mv /var/db/parental_control_state.json /root/corrupt_state.json

# Use example template
cp /path/to/config.example/parental_control_state.json.example /var/db/parental_control_state.json

# Edit and customize
vi /var/db/parental_control_state.json
```

---

**For more information, see:**
- [Quick Start Guide](../QUICKSTART.md)
- [README](../README.md)
- [Best Practices](../BEST_PRACTICES-KACI-ParentalControl.md)

**Built with Passion by Mukesh Kesharwani**  
**© 2025 Keekar**

