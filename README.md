# Keekar's Parental Control Package for pfSense

A comprehensive parental control package for pfSense that helps parents manage and limit their children's internet access time.

**Package ID:** KACI-Parental_Control  
**Version:** 1.2.4 (Production Ready)  
**Author:** Mukesh Kesharwani (Keekar)  
**Status:** Production Ready  
**License:** MIT  
**© 2025 Keekar**

---

## 📦 Quick Installation

```bash
./INSTALL.sh <pfsense_ip_address>
# Example: ./INSTALL.sh 192.168.1.1
```

**Requirements:**
- pfSense 2.6.0+ with SSH enabled
- Network access to pfSense
- Basic SSH/command-line knowledge

---

## ✨ Features

### 👨‍👩‍👧‍👦 Profile-Based Management
- **Child Profiles** - Create separate profiles for each child
- **Shared Time Limits** - Time limits apply across ALL devices in a profile
- **Profile Icons** - Visual identification with emojis
- **DHCP/ARP Auto-Discovery** - Easily select devices from your network

### ⏰ Time Management
- **Daily Time Limits** - Set maximum internet time per day (e.g., 2 hours)
- **Weekly Time Limits** - Set cumulative weekly limits  
- **Weekend Bonus** - Grant extra time on weekends
- **Automatic Reset** - Counters reset at midnight (configurable)
- **Bypass-Proof** - Time is shared across all devices, can't switch devices to get more time

### 📅 Schedule-Based Blocking
- **Bedtime Blocking** - Block internet during sleep hours (e.g., 21:00-07:00)
- **School Hours** - Block during school time (weekdays 08:00-15:00)
- **Custom Schedules** - Define any custom blocked periods per profile
- **Flexible Scheduling** - Supports day ranges (Mon-Fri) and specific days

### 🎯 Device Management
- **Auto-Discovery** - Select devices from DHCP/ARP table dropdown
- **MAC Address Tracking** - Reliable device identification
- **Multiple Devices Per Profile** - Each child can have phone, tablet, laptop, etc.
- **Real-time Status** - See which devices are online, blocked, or have time remaining

### 🛡️ Enforcement Modes
- **Strict Mode** - Block all internet traffic (recommended)
- **Moderate Mode** - Block only web browsing (HTTP/HTTPS)
- **DNS Mode** - DNS-based filtering (easier to bypass, not recommended)

### 🚫 Captive Portal Block Page (NEW in v1.1.11!)
- **Automatic Redirect** - Blocked devices see a beautiful block page instead of silent blocking
- **No Authentication** - Block page works without pfSense login
- **Modern UI** - Responsive design with gradient colors and icons
- **Device Information** - Shows device name, IP, profile, usage, and reason for blocking
- **Parent Override** - Password-protected temporary access feature
- **Real-time Info** - Displays when access will be restored

### 📊 Monitoring & Logging
- **Live Dashboard** - Real-time device status and usage
- **Usage Tracking** - Time used today and remaining time
- **OpenTelemetry Logging** - Structured JSON logs for SIEM integration
- **Activity Logging** - Comprehensive logs at `/var/log/parental_control.jsonl`
- **SIEM Ready** - Compatible with Splunk, Elasticsearch, Grafana Loki, Graylog

---

## 🚀 Quick Start

### 1. Install Package

```bash
cd /Users/mkesharw/Documents/Pfsense-ext/Parental_Control
./INSTALL.sh 192.168.64.10  # Your pfSense IP
```

### 2. Enable Service

In pfSense web interface:
1. Go to **Services > Parental Control**
2. Check **Enable Parental Control**
3. Set **Enforcement Mode**: Strict
4. Set **Grace Period**: 5 minutes
5. Click **Save**

### 3. Add Device

1. Go to **Devices** tab
2. Click **+ Add**
3. Configure:
   - **Child Name**: Emma
   - **Device Name**: iPad
   - **MAC Address**: aa:bb:cc:dd:ee:ff (find in device settings)
   - **Daily Time Limit**: 120 (2 hours)
   - **Weekend Bonus**: 60 (extra hour on weekends)
4. Add a schedule (optional):
   - **Name**: Bedtime
   - **Days**: Mon,Tue,Wed,Thu,Sun (school nights)
   - **Start Time**: 21:00
   - **End Time**: 07:00
5. Click **Save**

### 4. Monitor

- Go to **Status** tab to see real-time device status
- View logs: `ssh admin@192.168.64.10 "tail -f /var/log/parental_control.jsonl"`

---

## 📋 Configuration Examples

### Example 1: School Night Rules (Most Common)

```
Device: Child's iPad
Daily Limit: 120 minutes (2 hours)
Weekend Bonus: 60 minutes (1 extra hour Sat/Sun)

Schedule 1 - Bedtime:
  Days: Sun,Mon,Tue,Wed,Thu
  Time: 21:00 - 07:00

Schedule 2 - School Hours:
  Days: Mon,Tue,Wed,Thu,Fri
  Time: 08:00 - 15:00
```

**Result:**
- 2 hours internet per weekday, 3 hours on weekends
- No internet during bedtime (school nights)
- No internet during school hours

### Example 2: Weekend Only Access

```
Device: Gaming Console
Daily Limit: 180 minutes (3 hours)

Schedule - Weekdays Blocked:
  Days: Mon,Tue,Wed,Thu,Fri
  Time: 00:00 - 23:59
```

**Result:** Only accessible on weekends, 3 hours per day

### Example 3: Time Limits Only (No Schedule)

```
Device: Laptop
Daily Limit: 240 minutes (4 hours)
Weekend Bonus: 120 minutes (2 extra hours)
```

**Result:** 4 hours/day weekdays, 6 hours/day weekends, available anytime

---

## 🔧 Advanced Configuration

### Grace Period
Set warning time before blocking (5-15 minutes recommended):
- Device still has access during grace period
- Allows user to save work

### Content Filtering
Enable DNS-based content filtering:
1. Go to **Settings** > **Advanced**
2. Check **Enable Content Filtering**
3. Select DNS provider:
   - **OpenDNS FamilyShield** - Blocks adult content
   - **Cloudflare for Families** - Blocks malware + adult content
   - **Custom** - Specify your own DNS servers

### Multiple Children
Create device entries for each child:
```
Child 1 (Age 8):
  - iPad: 90 min/day, bedtime 20:00-07:00
  - Nintendo: Weekend only, 120 min/day

Child 2 (Age 14):
  - Phone: 180 min/day, bedtime 22:00-07:00
  - Laptop: 240 min/day, no bedtime
```

---

## 📁 Package Files

### Core Package Files

| File | Purpose | Lines |
|------|---------|-------|
| `parental_control.inc` | Core PHP logic and enforcement engine | ~3,100 |
| `parental_control.xml` | Main package definition and pfSense hooks | ~200 |
| `info.xml` | Package metadata for pfSense registry | ~50 |
| `VERSION` | Single source of truth for version number | ~5 |

### Web Interface (PHP Pages)

| File | Purpose |
|------|---------|
| `parental_control_profiles.php` | Profile management UI (create/edit/delete profiles) |
| `parental_control_schedules.php` | Schedule management UI (bedtime, no-internet times) |
| `parental_control_status.php` | Real-time status dashboard with device usage |
| `parental_control_blocked.php` | Block page shown to users (authenticated) |
| `parental_control_captive.php` | Captive portal block page (non-authenticated) |
| `parental_control_api.php` | RESTful API for external integrations |
| `parental_control_health.php` | Health check endpoint for monitoring |

### Diagnostic & Management Tools

| File | Purpose |
|------|---------|
| `parental_control_diagnostic.php` | Comprehensive diagnostic tool for troubleshooting |
| `parental_control_analyzer.sh` | Log analyzer and statistics generator |
| `parental_control_captive.sh` | RC script for captive portal web server |

### Installation & Maintenance

| File | Purpose |
|------|---------|
| `INSTALL.sh` | Automated installation/update script (SSH-based) |
| `UNINSTALL.sh` | Complete package removal script |
| `bump_version.sh` | Version management automation script |

### Documentation

| Location | Contents |
|----------|----------|
| `README.md` | This file - overview and quick start |
| `docs/USER_GUIDE.md` | Complete user guide with changelog |
| `docs/TECHNICAL_REFERENCE.md` | API, architecture, development guide |
| `docs/GETTING_STARTED.md` | Installation and setup walkthrough |
| `BEST_PRACTICES_KACI.md` | Lessons learned from the project |

### Total Package Size
- **Core Package**: ~3,500 lines of PHP code
- **Web UI**: ~2,000 lines across 7 PHP pages
- **Scripts**: ~1,500 lines of shell scripts
- **Documentation**: ~8,000 lines across 4 comprehensive guides
- **Disk Space**: ~500KB total installed size

---

## 🔍 How It Works

### 1. **Device Identification**
- Tracks devices by MAC address
- Monitors ARP table for device presence
- Detects when device connects to network

### 2. **Time Tracking**
- Cron job runs every minute
- Checks device connections via ARP
- Increments usage counter if device is online
- Enforces block when limit reached

### 3. **Schedule Enforcement**
- Checks current time against configured schedules
- Blocks device during scheduled periods
- Independent of time limit counters

### 4. **Firewall Integration**
- Creates pfSense firewall rules dynamically
- Rules block based on source MAC/IP
- Updates rules via `filter_configure()` API
- Rules persist until time/schedule allows access

### 5. **Configuration Storage**
- Settings stored in pfSense `/cf/conf/config.xml`
- Uses `<installedpackages><parental_control>` section
- Survives reboots
- Backed up with pfSense config backups

---

## 🐛 Troubleshooting

### Package Not Appearing in Menu
```bash
# SSH to pfSense (replace YOUR-FIREWALL-IP)
ssh admin@YOUR-FIREWALL-IP

# Check files are present
ls -la /usr/local/pkg/parental_control*

# Check system log
tail -50 /var/log/system.log | grep parental

# Try reinstalling (from your local machine)
cd /path/to/KACI-Parental_Control
./INSTALL.sh YOUR-FIREWALL-IP
```

### Rules Not Blocking
See [User Guide](docs/USER_GUIDE.md#troubleshooting) for complete troubleshooting steps including:
- Checking enforcement mode
- Verifying MAC addresses
- Inspecting firewall rules
- Viewing and analyzing logs

### Time Not Being Tracked
1. **Check cron job**: `crontab -l` should show parental_control entry
2. **Check device is online**: Go to Status tab in UI
3. **View logs**: Look for "Device X is online" messages
4. **Verify ARP**: Device must appear in ARP table

### Device Still Has Access After Limit
1. **Check grace period**: Device has X minutes grace period after limit
2. **Check device IP/MAC**: Might be using different IP/MAC
3. **Check rules order**: Parental control rules should be at top
4. **Reapply config**: Edit device and save again to regenerate rules

### After pfSense Reboot
- Package automatically starts
- Cron jobs recreated
- Firewall rules reapplied
- Usage counters preserved

---

## 📊 Technical Details

### System Requirements
- **pfSense**: 2.7.0 or later
- **FreeBSD**: 13.0+ (included with pfSense 2.7+)
- **PHP**: 8.1+ (included with pfSense)
- **Disk Space**: ~100KB for package files
- **Memory**: Minimal (~5MB runtime)

### File Locations on pfSense
```
/usr/local/pkg/parental_control.xml          # Main UI
/usr/local/pkg/parental_control_devices.xml  # Device UI  
/usr/local/pkg/parental_control.inc          # Core logic
/usr/local/www/parental_control_status.php   # Status page
/usr/local/share/pfSense-pkg-parental_control/info.xml  # Metadata
/cf/conf/config.xml                           # Configuration storage
/var/log/parental_control.jsonl                 # Activity log
```

### Cron Job
Runs every minute: `*/1 * * * *`

Executes: `/usr/local/bin/php -f /usr/local/pkg/parental_control.inc -- cron`

### API Functions

**Core Functions** (in `parental_control.inc`):
- `parental_control_validate_form()` - Validates user input
- `parental_control_sync_package()` - Applies configuration changes
- `parental_control_cron_job()` - Main enforcement logic (runs every minute)
- `parental_control_get_device_status()` - Gets real-time device status
- `parental_control_log()` - Writes to log file

**pfSense Integration:**
- `filter_configure()` - Reloads firewall rules
- `mwexec()` - Executes system commands
- `write_config()` - Saves configuration
- `get_arp_table()` - Gets connected devices

---

## 🔄 Updating the Package

To update after making changes:

```bash
# Edit files locally
vim parental_control.inc  # or other files

# Test syntax (PHP files only)
php -l parental_control.inc

# Reinstall on pfSense (replace YOUR-FIREWALL-IP)
./INSTALL.sh YOUR-FIREWALL-IP

# Check pfSense web UI
# Verify changes took effect
```

---

## 🆘 Support & Resources

### 📚 Documentation

**Complete documentation in 3 comprehensive guides:**

- **[🚀 Getting Started](docs/GETTING_STARTED.md)** - Installation, Quick Start, and Overview
- **[📖 User Guide](docs/USER_GUIDE.md)** - Configuration, Troubleshooting, and Maintenance
- **[🔬 Technical Reference](docs/TECHNICAL_REFERENCE.md)** - API, Architecture, and Development

**See also:**
- **[Changelog](docs/USER_GUIDE.md#changelog)** - Complete version history (in User Guide)
- **[Documentation Index](docs/README.md)** - Navigate all documentation
- **[Release Notes](docs/USER_GUIDE.md#release-notes)** - What's new in v1.0.0 (in User Guide)

### Useful Commands
```bash
# Replace YOUR-FIREWALL-IP with your actual pfSense IP address

# View logs in real-time
ssh admin@YOUR-FIREWALL-IP "tail -f /var/log/parental_control.jsonl"

# Check cron job
ssh admin@YOUR-FIREWALL-IP "crontab -l | grep parental"

# View ARP table (find device MACs)
ssh admin@YOUR-FIREWALL-IP "arp -an"

# Check firewall rules
ssh admin@YOUR-FIREWALL-IP "pfctl -sr | grep parental"

# Reinstall package
./INSTALL.sh YOUR-FIREWALL-IP
```

### Logs

**Format:** OpenTelemetry-compliant JSON Lines (JSONL)  
**Location:** `/var/log/parental_control.jsonl`  
**Structure:** One complete JSON object per line (not a JSON array)

**Why JSONL?**
- ✅ Stream-friendly (`tail -f` works)
- ✅ Memory-efficient (process line-by-line)
- ✅ Easy to append
- ✅ SIEM standard format

**Log Contents:**
- Device connections/disconnections
- Time limit enforcement
- Schedule blocks/unblocks
- Configuration changes
- Errors and warnings

**Example Log Entry:**
```json
{
  "Timestamp": "2025-12-24T21:00:00.000000Z",
  "SeverityText": "INFO",
  "Body": "Created block rule for Emma - iPad: Bedtime schedule",
  "Attributes": {
    "event.action": "block_rule_created",
    "child.name": "Emma",
    "device.name": "iPad",
    "device.mac": "aa:bb:cc:dd:ee:ff",
    "rule.reason": "Bedtime schedule"
  }
}
```

**Query Logs:**
```bash
# Replace YOUR-FIREWALL-IP with your actual pfSense IP address

# View logs prettified
ssh admin@YOUR-FIREWALL-IP "cat /var/log/parental_control.jsonl | jq '.'"

# Filter by child (replace "Emma" with your child's name)
ssh admin@YOUR-FIREWALL-IP "cat /var/log/parental_control.jsonl | jq 'select(.Attributes.\"child.name\" == \"Emma\")'"

# Get error logs only
ssh admin@YOUR-FIREWALL-IP "cat /var/log/parental_control.jsonl | jq 'select(.SeverityText == \"ERROR\")'"
```

**SIEM Integration:**  
The package uses OpenTelemetry-compliant JSONL format for logs:
- Compatible with Splunk, Elasticsearch, Grafana Loki, Graylog
- Stream-friendly format (works with `tail -f`)
- One JSON object per line for efficient parsing
- See [User Guide](docs/USER_GUIDE.md#configuration-guide) for advanced logging options

---

## 📝 License & Credits

**Package:** KACI Parental Control for pfSense  
**Version:** 1.1.10 (Production Ready)  
**Status:** Production Ready  
**Compatibility:** pfSense 2.6.0+  
**License:** MIT

---

**Need help?** See our comprehensive [Documentation](docs/README.md) for installation, configuration, and troubleshooting guides.
