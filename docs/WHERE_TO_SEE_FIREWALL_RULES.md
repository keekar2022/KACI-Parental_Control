# 🔍 Where to See Parental Control Firewall Rules

## ❌ **NOT in the Standard GUI**

The parental control firewall rules are **NOT visible** in the standard pfSense GUI locations:

- ❌ **NOT** in Firewall → Rules → LAN
- ❌ **NOT** in Firewall → Rules → WAN
- ❌ **NOT** in Firewall → Rules → Floating
- ❌ **NOT** in any interface tab

**Why?** Because we use **pfSense Anchors** instead!

---

## 🎯 **What are pfSense Anchors?**

**Anchors** are a special pfSense feature that allows:
- ✅ **Dynamic rule management** without GUI
- ✅ **Fast updates** (< 1 second, no full firewall reload)
- ✅ **No config.xml pollution** (thousands of rules would bloat it)
- ✅ **Persistent across reboots** (when properly configured)

**Trade-off:** Rules are NOT visible in the GUI - must use command line.

---

## 🖥️ **How to View the Rules**

### Method 1: View Active Rules in pfSense (RECOMMENDED)

SSH into your firewall and run:

```bash
# View all active rules in parental_control anchor
sudo pfctl -a parental_control -sr
```

**Example Output:**
```pf
# Device: 192.168.1.115 (Vishesh-iPhone) - Scheduled block time
pass quick proto udp from 192.168.1.115 to any port = 53 flags S/SA keep state label "PC-DNS:Vishesh-iPhone"
pass quick from 192.168.1.115 to 192.168.1.1 flags S/SA keep state label "PC-Allow:Vishesh-iPhone"
rdr pass proto tcp from 192.168.1.115 to any port = 80 -> 192.168.1.1 port 443
rdr pass proto tcp from 192.168.1.115 to any port = 443 -> 192.168.1.1 port 443
block drop quick from 192.168.1.115 to any label "PC-Block:Vishesh-iPhone"

# Device: 192.168.1.117 (Anitasiphone) - Time limit exceeded
pass quick proto udp from 192.168.1.117 to any port = 53 flags S/SA keep state label "PC-DNS:Anitasiphone"
pass quick from 192.168.1.117 to 192.168.1.1 flags S/SA keep state label "PC-Allow:Anitasiphone"
rdr pass proto tcp from 192.168.1.117 to any port = 80 -> 192.168.1.1 port 443
rdr pass proto tcp from 192.168.1.117 to any port = 443 -> 192.168.1.1 port 443
block drop quick from 192.168.1.117 to any label "PC-Block:Anitasiphone"
```

---

### Method 2: View Anchor File (Before pfctl Processing)

```bash
# View the raw anchor file
cat /tmp/rules.parental_control
```

**Example Output:**
```pf
# Parental Control Dynamic Rules

# Device: 192.168.1.115 (Vishesh-iPhone) - Scheduled block time
pass quick proto udp from 192.168.1.115 to any port 53 label "PC-DNS:Vishesh-iPhone"
pass quick from 192.168.1.115 to 192.168.1.1 label "PC-Allow:Vishesh-iPhone"
rdr pass proto tcp from 192.168.1.115 to any port 80 -> 192.168.1.1 port 443 label "PC-HTTP:Vishesh-iPhone"
rdr pass proto tcp from 192.168.1.115 to any port 443 -> 192.168.1.1 port 443 label "PC-HTTPS:Vishesh-iPhone"
block drop quick from 192.168.1.115 to any label "PC-Block:Vishesh-iPhone"

# Device: 192.168.1.117 (Anitasiphone) - Time limit exceeded
pass quick proto udp from 192.168.1.117 to any port 53 label "PC-DNS:Anitasiphone"
pass quick from 192.168.1.117 to 192.168.1.1 label "PC-Allow:Anitasiphone"
rdr pass proto tcp from 192.168.1.117 to any port 80 -> 192.168.1.1 port 443 label "PC-HTTP:Anitasiphone"
rdr pass proto tcp from 192.168.1.117 to any port 443 -> 192.168.1.1 port 443 label "PC-HTTPS:Anitasiphone"
block drop quick from 192.168.1.117 to any label "PC-Block:Anitasiphone"
```

---

### Method 3: Check if Specific Device is Blocked

```bash
# Check if a specific IP is blocked
sudo pfctl -a parental_control -sr | grep "192.168.1.115"
```

**Output if blocked:**
```pf
pass quick proto udp from 192.168.1.115 to any port = 53 flags S/SA keep state label "PC-DNS:Vishesh-iPhone"
pass quick from 192.168.1.115 to 192.168.1.1 flags S/SA keep state label "PC-Allow:Vishesh-iPhone"
block drop quick from 192.168.1.115 to any label "PC-Block:Vishesh-iPhone"
```

**Output if NOT blocked:**
```
(no output)
```

---

### Method 4: View All pfSense Anchors

```bash
# List all anchors in pfSense
sudo pfctl -sA
```

**Output:**
```
parental_control
miniupnpd
snort2c/*
```

The `parental_control` anchor is where our rules live!

---

### Method 5: View Anchor Statistics

```bash
# Show statistics for parental_control anchor
sudo pfctl -a parental_control -vsr
```

**Example Output:**
```pf
@0 pass quick proto udp from 192.168.1.115 to any port = 53 flags S/SA keep state label "PC-DNS:Vishesh-iPhone"
  [ Evaluations: 245     Packets: 120     Bytes: 8640     States: 0     ]

@1 pass quick from 192.168.1.115 to 192.168.1.1 flags S/SA keep state label "PC-Allow:Vishesh-iPhone"
  [ Evaluations: 245     Packets: 15      Bytes: 1200     States: 2     ]

@2 block drop quick from 192.168.1.115 to any label "PC-Block:Vishesh-iPhone"
  [ Evaluations: 245     Packets: 230     Bytes: 52800    States: 0     ]
```

This shows:
- How many times each rule was evaluated
- How many packets matched
- How much data was blocked/allowed

---

## 📊 **Understanding the Rule Labels**

Each rule has a **label** that explains its purpose:

| Label | Purpose | Example |
|-------|---------|---------|
| `PC-DNS:DeviceName` | Allow DNS queries | `PC-DNS:Vishesh-iPhone` |
| `PC-Allow:DeviceName` | Allow pfSense access | `PC-Allow:Vishesh-iPhone` |
| `PC-HTTP:DeviceName` | Redirect HTTP to block page | `PC-HTTP:Vishesh-iPhone` |
| `PC-HTTPS:DeviceName` | Redirect HTTPS to block page | `PC-HTTPS:Vishesh-iPhone` |
| `PC-Block:DeviceName` | Block all other traffic | `PC-Block:Vishesh-iPhone` |

You can filter by label:

```bash
# See only block rules
sudo pfctl -a parental_control -sr | grep "PC-Block"

# See only DNS allow rules
sudo pfctl -a parental_control -sr | grep "PC-DNS"
```

---

## 🔍 **Verification Commands**

### Check if Parental Control is Active

```bash
# Check if anchor is loaded
sudo pfctl -sA | grep parental_control
```

**If active:** `parental_control` (shows in list)  
**If not active:** (no output)

---

### Check How Many Devices are Blocked

```bash
# Count blocked devices
sudo pfctl -a parental_control -sr | grep -c "# Device:"
```

**Output:** `3` (means 3 devices currently blocked)

---

### Check Total Number of Rules

```bash
# Count all rules in anchor
sudo pfctl -a parental_control -sr | wc -l
```

**Output:** `15` (means 15 rules total)

**Note:** Each blocked device creates 5 rules, so:
- 3 devices blocked = 15 rules (3 × 5)

---

### Monitor Real-Time Rule Hits

```bash
# Watch rules being hit in real-time
watch -n 1 'sudo pfctl -a parental_control -vsr | grep -A1 "block drop"'
```

This updates every second showing which block rules are actively blocking traffic.

---

## 🛠️ **Troubleshooting**

### Problem: No Rules Showing

**Command:**
```bash
sudo pfctl -a parental_control -sr
```

**Output:** (empty)

**Possible Causes:**
1. No devices currently blocked
2. Cron job not running
3. Anchor not initialized

**Fix:**
```bash
# 1. Check if cron is running
sudo crontab -l | grep parental

# 2. Check if any devices should be blocked
cat /var/db/parental_control_state.json | jq '.blocked_devices'

# 3. Manually run cron to force update
sudo php /usr/local/bin/parental_control_cron.php
```

---

### Problem: Rules Exist But Device Not Blocked

**Command:**
```bash
# Check if rules exist
sudo pfctl -a parental_control -sr | grep "192.168.1.115"

# Test connectivity from device
ping 8.8.8.8  # from the device
```

**Possible Causes:**
1. Wrong IP address (DHCP changed it)
2. Device bypassing via VPN/proxy
3. Rules not applied correctly

**Fix:**
```bash
# 1. Check device's current IP
arp -an | grep "aa:bb:cc:dd:ee:ff"

# 2. Check MAC to IP mapping in state
cat /var/db/parental_control_state.json | jq '.mac_to_ip_cache'

# 3. Manually reload anchor
sudo pfctl -a parental_control -f /tmp/rules.parental_control
```

---

## 📝 **Quick Reference**

| Task | Command |
|------|---------|
| View all rules | `sudo pfctl -a parental_control -sr` |
| View anchor file | `cat /tmp/rules.parental_control` |
| Check specific IP | `sudo pfctl -a parental_control -sr \| grep "192.168.1.115"` |
| Count blocked devices | `sudo pfctl -a parental_control -sr \| grep -c "# Device:"` |
| View with statistics | `sudo pfctl -a parental_control -vsr` |
| Reload anchor | `sudo pfctl -a parental_control -f /tmp/rules.parental_control` |
| Check if anchor active | `sudo pfctl -sA \| grep parental` |

---

## 🎯 **Why Not Use GUI Rules?**

### Problems with GUI Rules:

1. **❌ Slow Updates** - Full firewall reload (5-10 seconds)
2. **❌ AQM Errors** - Causes "flowset busy" kernel errors
3. **❌ Config Bloat** - Thousands of rules = huge config.xml
4. **❌ Not Dynamic** - Can't easily add/remove rules
5. **❌ GUI Clutter** - Would fill firewall rules page

### Benefits of Anchors:

1. **✅ Fast Updates** - Rules apply instantly (< 1 second)
2. **✅ No Errors** - No AQM flowset issues
3. **✅ Clean Config** - Anchor file separate from config.xml
4. **✅ Dynamic** - Easy to add/remove rules programmatically
5. **✅ Clean GUI** - Doesn't clutter firewall rules page

---

## 💡 **Pro Tip: Create an Alias**

Add this to your firewall's `.bashrc` for easy access:

```bash
# Add to /root/.bashrc
alias pc-rules='pfctl -a parental_control -sr'
alias pc-stats='pfctl -a parental_control -vsr'
alias pc-blocked='pfctl -a parental_control -sr | grep -c "# Device:"'
alias pc-reload='pfctl -a parental_control -f /tmp/rules.parental_control'
```

Then you can just run:
```bash
pc-rules      # View all rules
pc-stats      # View with statistics  
pc-blocked    # Count blocked devices
pc-reload     # Reload anchor
```

---

## 🎉 **Summary**

**Q: Where are the firewall rules?**  
**A:** In the `parental_control` pfSense anchor (command-line only)

**Q: Why not in GUI?**  
**A:** Anchors are faster, cleaner, and don't cause AQM errors

**Q: How do I see them?**  
**A:** `sudo pfctl -a parental_control -sr`

**Q: Which interface?**  
**A:** None! Anchors work at a lower level than interface rules

**Q: Are they persistent?**  
**A:** Yes, as long as the cron job keeps running (every 5 mins)

---

**Built with ❤️ by Mukesh Kesharwani**  
**© 2025 Keekar**

