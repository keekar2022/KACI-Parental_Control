# Quick Start Guide - KACI Parental Control
## Get Running in 5 Minutes ⚡

**Version:** 0.1.3  
**Author:** Mukesh Kesharwani  
**Last Updated:** December 25, 2025

This guide will help you install and configure KACI Parental Control in under 5 minutes.

---

## 📋 Prerequisites Checklist

Before you begin, make sure you have:

- [ ] **pfSense 2.7.0 or later** installed and running
- [ ] **SSH access enabled** on pfSense (System > Advanced > Admin Access)
- [ ] **SSH key authentication** set up (optional but recommended)
- [ ] **Child devices' MAC addresses** identified (see tips below)
- [ ] **Network access** to pfSense from your computer

### 💡 How to Find MAC Addresses

**On iPad/iPhone:**
- Settings > General > About > Wi-Fi Address

**On Android:**
- Settings > About Phone > Status > Wi-Fi MAC Address

**On Windows:**
- Open Command Prompt > Type `ipconfig /all`

**On Mac:**
- System Preferences > Network > Advanced > Hardware

**On pfSense (easiest method):**
- Status > DHCP Leases (shows all connected devices)
- Or: Diagnostics > ARP Table

---

## 🚀 Step 1: Install Package (2 minutes)

### Clone the Repository

```bash
# Clone from GitHub
git clone https://github.com/keekar2022/KACI-Parental_Control.git
cd KACI-Parental_Control
```

### Run Installation Script

```bash
# Replace 192.168.1.1 with your pfSense IP address
./INSTALL.sh install 192.168.1.1
```

**What this does:**
- Sets up SSH key authentication (if needed)
- Uploads package files to pfSense
- Registers the package in pfSense
- Verifies installation

**Expected output:**
```
✓ SSH key authentication configured
✓ Files uploaded
✓ Package registered
✓ Installation verified
```

### Troubleshooting Installation

**If you get "SSH key not found":**
- The script will generate one automatically
- Just follow the prompts

**If you get "Permission denied":**
- Make sure you can SSH to pfSense: `ssh admin@192.168.1.1`
- Check pfSense SSH settings: System > Advanced > Admin Access

**If installation fails:**
```bash
# Try reinstall mode (cleans up first)
./INSTALL.sh reinstall 192.168.1.1

# Or verify what's wrong
./INSTALL.sh verify 192.168.1.1
```

---

## 🌐 Step 2: Access Web Interface (30 seconds)

1. Open your web browser
2. Navigate to your pfSense: `https://192.168.1.1`
3. Log in with your pfSense credentials
4. Go to: **Services > Keekar's Parental Control**

**You should see:**
- Settings tab (main configuration)
- Profiles tab (manage child profiles)
- Status tab (real-time monitoring)

---

## 👧 Step 3: Create Your First Profile (2 minutes)

### Enable the Service

1. On the **Settings** tab
2. Check ✅ **Enable Parental Control**
3. Select **Enforcement Mode: Strict** (recommended)
4. Click **Save**

### Create a Child Profile

1. Click the **Profiles** tab
2. Click **+ Add** button
3. Fill in the profile information:

**Profile Information:**
- **Profile Name:** Emma (or your child's name)
- **Description:** iPad and iPhone (optional)
- **Profile Icon:** 👧 Girl (optional, makes it easier to identify)

**Time Limits:**
- **Daily Time Limit:** `120` minutes (2 hours)
- **Weekend Bonus:** `60` minutes (1 extra hour on weekends)
- **Weekly Limit:** Leave empty (or set if you want weekly limits too)

### Add a Device

1. In the **Devices** section, click **Add Row**
2. **Quick Select:** Choose from the dropdown (auto-fills everything) ✨
   - Or manually enter:
     - **Device Name:** iPad
     - **MAC Address:** aa:bb:cc:dd:ee:ff (from prerequisites)
     - **IP Address:** Optional (leave empty for DHCP)

💡 **Pro Tip:** The Quick Select dropdown shows all devices on your network with their names and MAC addresses. Look for [ONLINE] devices.

### Add a Schedule (Optional but Recommended)

1. In the **Schedules** section, click **Add Row**
2. **Bedtime Example:**
   - **Schedule Name:** Bedtime
   - **Days:** sun,mon,tue,wed,thu (school nights)
   - **Start Time:** 21:00 (9 PM)
   - **End Time:** 07:00 (7 AM)

3. Click **Save**

🎉 **Congratulations!** Your first profile is configured!

---

## 📊 Step 4: Verify It's Working (30 seconds)

### Check Status Dashboard

1. Go to the **Status** tab
2. You should see:
   - ✅ **Service Status:** Active
   - Your profile name (Emma)
   - Device status (Online or Offline)
   - Usage statistics (0:00 initially)

### Test Device Detection

**If device shows as "Offline" but it's on:**
1. Make sure the device is connected to the network
2. Check the MAC address is correct
3. Wait 1 minute for the cron job to run
4. Refresh the Status page

**If device shows as "Online":** ✅ Perfect! You're all set!

### Monitor Usage

- **Usage Today:** Shows time used today
- **Daily Limit:** Shows total allowed time
- **Remaining:** Shows time left

The system automatically:
- ✅ Tracks usage every minute
- ✅ Blocks when limit is reached
- ✅ Unblocks at bedtime schedule
- ✅ Resets counters at midnight

---

## 🎯 Common Use Cases

### Scenario 1: Multiple Children

Create separate profiles for each child:

**Emma (Age 8):**
- Daily: 90 minutes
- Weekend: +30 minutes
- Bedtime: 20:00-07:00
- Devices: iPad, iPhone

**Jake (Age 14):**
- Daily: 180 minutes
- Weekend: +60 minutes
- Bedtime: 22:00-07:00
- Devices: Laptop, Phone, Gaming Console

### Scenario 2: School Hours Block

Add to any profile:
- **Schedule Name:** School Hours
- **Days:** mon,tue,wed,thu,fri
- **Start Time:** 08:00
- **End Time:** 15:00

### Scenario 3: Weekend Only (Gaming Console)

- **Daily Time Limit:** 180 minutes
- **Schedule:** Weekday Block
  - **Days:** mon,tue,wed,thu,fri
  - **Time:** 00:00-23:59

Result: Only accessible on weekends

---

## 🐛 Troubleshooting

### Device Not Being Blocked

**Check these in order:**

1. **Is service enabled?**
   - Settings tab > "Enable Parental Control" should be checked

2. **Is enforcement mode correct?**
   - Settings tab > Enforcement Mode = "Strict" (recommended)

3. **Is the MAC address correct?**
   ```bash
   # SSH to pfSense
   ssh admin@192.168.1.1
   # Check ARP table
   arp -an
   ```

4. **Are firewall rules created?**
   - Firewall > Rules > LAN
   - Look for "Parental Control: ..." rules

### Time Not Being Tracked

1. **Check Status tab:**
   - Is device showing as "Online"?
   - Is "Usage Today" incrementing?

2. **Check cron job:**
   ```bash
   ssh admin@192.168.1.1
   crontab -l | grep parental
   ```
   Should show: `*/1 * * * * ...`

3. **Check logs:**
   - Status tab > Recent Log Entries
   - Or SSH: `tail -f /var/log/parental_control-$(date +%Y-%m-%d).jsonl`

### Package Not Appearing in Menu

```bash
# SSH to pfSense
ssh admin@192.168.1.1

# Check files are present
ls -la /usr/local/pkg/parental_control*

# Check system log
tail -50 /var/log/system.log | grep parental

# Reinstall if needed
cd /path/to/KACI-Parental_Control
./INSTALL.sh reinstall 192.168.1.1
```

### Device Has Access After Limit Reached

1. **Grace Period:** Check Settings tab > Grace Period (default: 5 minutes)
   - Device gets warning time before complete block
   
2. **Different MAC/IP:** Device might be using a different network adapter
   - Check DHCP leases for multiple entries for same device

3. **Rule Order:** Parental control rules should be at top of firewall rules
   - If other rules process first, they might allow traffic

---

## 🔍 Advanced Tips

### View Detailed Logs

```bash
ssh admin@192.168.1.1
cd /var/log

# View today's log
cat parental_control-$(date +%Y-%m-%d).jsonl | jq '.'

# Filter by child
cat parental_control-*.jsonl | jq 'select(.Attributes."child.name" == "Emma")'

# Show only errors
cat parental_control-*.jsonl | jq 'select(.SeverityText == "ERROR")'
```

### Health Check Endpoint

Monitor service health:
```bash
curl http://192.168.1.1/parental_control_health.php | jq '.'
```

### Manual State Reset

```bash
ssh admin@192.168.1.1
rm /var/db/parental_control_state.json
# Service will recreate on next run
```

---

## 📚 Next Steps

Now that you have the basics working:

1. **Read the Full README** - `/README.md`
   - Advanced configuration options
   - Multiple profiles
   - Custom schedules
   - Content filtering

2. **Check Best Practices** - `/BEST_PRACTICES-KACI-ParentalControl.md`
   - Coding standards
   - Logging patterns
   - Troubleshooting tips

3. **Explore Features:**
   - Add more children/profiles
   - Set up email notifications
   - Configure weekend bonuses
   - Create custom schedules

4. **Join the Community:**
   - GitHub: https://github.com/keekar2022/KACI-Parental_Control
   - Report issues
   - Request features
   - Contribute improvements

---

## 🆘 Need Help?

### Documentation
- **README.md** - Complete package documentation
- **RECOMMENDATIONS_FOR_ADOPTION.md** - Enhancement roadmap
- **BEST_PRACTICES-KACI-ParentalControl.md** - Technical guide

### Command Reference

```bash
# Installation
./INSTALL.sh install <pfsense-ip>      # Full install
./INSTALL.sh reinstall <pfsense-ip>    # Clean reinstall
./INSTALL.sh verify <pfsense-ip>       # Verify install
./INSTALL.sh debug <pfsense-ip>        # Show diagnostics

# Monitoring
ssh admin@<pfsense-ip> "tail -f /var/log/parental_control-*.jsonl"
ssh admin@<pfsense-ip> "crontab -l | grep parental"
ssh admin@<pfsense-ip> "pfctl -sr | grep 'Parental Control'"
```

### Quick Diagnostics

```bash
# Run all checks at once
./INSTALL.sh debug 192.168.1.1
```

---

## ✅ Success Checklist

After completing this guide, you should have:

- [x] Package installed on pfSense
- [x] Service enabled in web interface
- [x] At least one child profile created
- [x] At least one device configured
- [x] Device showing correct online/offline status
- [x] Time tracking working (visible in Status tab)
- [x] Schedules configured (if desired)

**Total Time:** ~5 minutes ⚡  
**Difficulty:** Beginner-friendly  
**Status:** Ready to use! 🎉

---

**Built with Passion by Mukesh Kesharwani**  
**© 2025 Keekar**  
**Version 0.1.3**

