# 🔍 Firewall Rules Now Visible in Status Page - v1.1.1

## ✨ New Feature: No CLI Required!

**You asked for it, we delivered!**

The Status page now displays active firewall rules directly in the GUI - **no more SSH or command-line access needed!**

---

## 📺 What You'll See

### When NO Devices are Blocked:

```
┌─────────────────────────────────────────────────────────────┐
│ 🛡️ Active Firewall Rules (pfSense Anchor)    [0 blocked]   │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ✅ No Blocking Active - All devices currently have access.  │
│                                                              │
│ ℹ️ Firewall rules will appear here automatically when      │
│    devices are blocked due to:                              │
│    • Time limit exceeded                                    │
│    • Blocked schedule time (e.g., bedtime)                  │
│                                                              │
│ Location: Anchor: parental_control                          │
│ File: /tmp/rules.parental_control                          │
│ CLI Command: pfctl -a parental_control -sr                 │
└─────────────────────────────────────────────────────────────┘
```

---

### When Devices ARE Blocked:

```
┌─────────────────────────────────────────────────────────────┐
│ 🛡️ Active Firewall Rules (pfSense Anchor)    [3 blocked]   │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ⚠️ Blocking Active - 3 device(s) currently blocked by      │
│    parental control firewall rules.                         │
│                                                              │
│ ℹ️ Note: These rules are managed via pfSense anchors and   │
│    are NOT visible in Firewall → Rules GUI.                │
│    They are applied dynamically by the parental control     │
│    system.                                                  │
│                                                              │
│ Rule Details:                                               │
│ ┌───────────────────────────────────────────────────────┐  │
│ │ # Device: 192.168.1.115 (Vishesh-iPhone) - Schedule  │  │
│ │ pass quick proto udp from 192.168.1.115 port 53      │  │
│ │ pass quick from 192.168.1.115 to 192.168.1.1         │  │
│ │ rdr pass tcp from 192.168.1.115 port 80 → 192...     │  │
│ │ rdr pass tcp from 192.168.1.115 port 443 → 192...    │  │
│ │ block drop quick from 192.168.1.115 to any           │  │
│ │                                                        │  │
│ │ # Device: 192.168.1.117 (Anitasiphone) - Time limit  │  │
│ │ pass quick proto udp from 192.168.1.117 port 53      │  │
│ │ pass quick from 192.168.1.117 to 192.168.1.1         │  │
│ │ rdr pass tcp from 192.168.1.117 port 80 → 192...     │  │
│ │ rdr pass tcp from 192.168.1.117 port 443 → 192...    │  │
│ │ block drop quick from 192.168.1.117 to any           │  │
│ │                                                        │  │
│ │ # Device: 192.168.1.96 (HISENSETV) - Schedule        │  │
│ │ pass quick proto udp from 192.168.1.96 port 53       │  │
│ │ pass quick from 192.168.1.96 to 192.168.1.1          │  │
│ │ rdr pass tcp from 192.168.1.96 port 80 → 192...      │  │
│ │ rdr pass tcp from 192.168.1.96 port 443 → 192...     │  │
│ │ block drop quick from 192.168.1.96 to any            │  │
│ └───────────────────────────────────────────────────────┘  │
│                                                              │
│ ℹ️ Rule Legend:                                             │
│ • pass quick - Allow specific traffic (DNS, pfSense)        │
│ • rdr pass - Redirect HTTP/HTTPS to block page             │
│ • block drop - Block all other traffic                     │
│                                                              │
│ Location: Anchor: parental_control                          │
│ File: /tmp/rules.parental_control                          │
│ CLI Command: pfctl -a parental_control -sr                 │
└─────────────────────────────────────────────────────────────┘
```

---

## 🎨 Color Coding

The rules are displayed with **syntax highlighting** for easy reading:

| Color | Rule Type | Purpose |
|-------|-----------|---------|
| **🔴 Red/Bold** | `# Device:` header | Shows which device is blocked |
| **🟢 Green** | `pass quick` | Allow DNS and pfSense access |
| **🔵 Blue** | `rdr pass` | Redirect to block page |
| **🔴 Red/Bold** | `block drop` | Block all other traffic |

---

## 📊 Information Displayed

### For Each Blocked Device:

1. **Device Header**
   ```
   # Device: 192.168.1.115 (Vishesh-iPhone) - Scheduled block time
   ```
   Shows: IP address, device name, reason for blocking

2. **DNS Allow Rule**
   ```
   pass quick proto udp from 192.168.1.115 to any port = 53
   ```
   Purpose: Allow device to resolve domain names

3. **pfSense Allow Rule**
   ```
   pass quick from 192.168.1.115 to 192.168.1.1
   ```
   Purpose: Allow device to access block page

4. **HTTP Redirect**
   ```
   rdr pass proto tcp from 192.168.1.115 to any port = 80 -> 192.168.1.1 port 443
   ```
   Purpose: Redirect HTTP traffic to block page

5. **HTTPS Redirect**
   ```
   rdr pass proto tcp from 192.168.1.115 to any port = 443 -> 192.168.1.1 port 443
   ```
   Purpose: Redirect HTTPS traffic to block page

6. **Block Rule**
   ```
   block drop quick from 192.168.1.115 to any
   ```
   Purpose: Block all other internet traffic

---

## 🔄 Real-Time Updates

The Status page shows **current state** when you view it:

- **Refreshes:** Every time you reload the page
- **Live Data:** Executes `pfctl -a parental_control -sr` on demand
- **Accurate:** Always shows the actual active rules

**Auto-refresh example:**
1. Open Status page at 19:55 → Shows "0 blocked"
2. Wait for 20:00 (Bedtime-1 starts)
3. Refresh Status page at 20:05 → Shows "5 blocked" (Vishesh devices)

---

## 📍 Where to Find It

**Navigation:**
```
Services → KACI Parental Control → Status
```

**Location on Page:**
- Below "Active Schedules" section
- Above "Recent Log Entries" section

---

## 🎯 Use Cases

### 1. Verify Blocking is Working

**Scenario:** "Is Vishesh really blocked during bedtime?"

**Solution:**
1. Open Status page at 20:00
2. Look for "Active Firewall Rules" section
3. See all 5 Vishesh devices listed with block rules
4. Confirmed! ✅

---

### 2. Debug Issues

**Scenario:** "Why can't I access internet?"

**Solution:**
1. Open Status page
2. Check if your device IP appears in rules
3. See reason: "Time limit exceeded" or "Scheduled block time"
4. Mystery solved! ✅

---

### 3. Monitor Real-Time Changes

**Scenario:** "Does blocking happen automatically?"

**Solution:**
1. Open Status page at 19:58 → Shows "0 blocked"
2. Wait 2 minutes
3. Refresh at 20:01 → Shows "5 blocked"
4. Confirmed automatic! ✅

---

### 4. Check Specific Device

**Scenario:** "Is Anitasiphone blocked?"

**Solution:**
1. Open Status page
2. Search for "Anitasiphone" in rules
3. If found → Blocked ✅
4. If not found → Not blocked ✅

---

## 💡 Pro Tips

### Tip 1: Use Browser Search

Press `Ctrl+F` (or `Cmd+F`) to search for:
- Device name: "Vishesh-iPhone"
- IP address: "192.168.1.115"
- Block reason: "Time limit exceeded"

### Tip 2: Count Devices Quickly

Look at the badge in the section header:
- **Green badge "0 blocked"** = All clear
- **Red badge "3 blocked"** = 3 devices blocked

### Tip 3: Understand Block Reasons

Rules show the reason in the device header:
- `Scheduled block time` = During bedtime/schedule
- `Time limit exceeded` = Used all daily time
- `Parent override active` = Won't be in list!

### Tip 4: Check Right After Changes

Made a change to schedules or limits?
1. Wait 5 minutes (cron cycle)
2. Refresh Status page
3. See updated rules

---

## 🆚 Before vs After v1.1.1

### Before (v1.1.0 and earlier):

```
❌ Had to SSH to firewall
❌ Run: sudo pfctl -a parental_control -sr
❌ Command-line knowledge required
❌ Copy/paste from terminal
❌ Not user-friendly for non-technical users
```

### After (v1.1.1):

```
✅ Just open Status page in browser
✅ Rules displayed automatically
✅ Color-coded and explained
✅ No CLI knowledge needed
✅ User-friendly for everyone
```

---

## 📊 Example Scenarios

### Scenario 1: All Allowed (Morning, 8:00 AM)

**Status Page Shows:**
```
✅ No Blocking Active - All devices currently have access.

Profiles:
- Vishesh: 0:00 / 4:00 (5 devices online)
- Mukesh: 0:00 / 10:00 (2 devices online)
- Anita: 0:00 / 6:00 (3 devices online)

Firewall Rules: 0 blocked
```

---

### Scenario 2: Time Limit Exceeded (Afternoon, 5:00 PM)

**Status Page Shows:**
```
⚠️ Blocking Active - 5 device(s) currently blocked

# Device: 192.168.1.115 (Vishesh-iPhone) - Time limit exceeded
# Device: 192.168.1.113 (Vishesh-iphone) - Time limit exceeded
# Device: 192.168.1.112 (Visheshbookpro14) - Time limit exceeded
# Device: 192.168.1.96 (HISENSETV) - Time limit exceeded
# Device: 192.168.1.95 (Basement-TV) - Time limit exceeded

Profiles:
- Vishesh: 4:00 / 4:00 (LIMIT REACHED)

Firewall Rules: 5 blocked
```

---

### Scenario 3: Bedtime (Evening, 10:00 PM)

**Status Page Shows:**
```
⚠️ Blocking Active - 5 device(s) currently blocked

# Device: 192.168.1.115 (Vishesh-iPhone) - Scheduled block time
# Device: 192.168.1.113 (Vishesh-iphone) - Scheduled block time
# Device: 192.168.1.112 (Visheshbookpro14) - Scheduled block time
# Device: 192.168.1.96 (HISENSETV) - Scheduled block time
# Device: 192.168.1.95 (Basement-TV) - Scheduled block time

Active Schedules:
- Bedtime-1 (20:00 - 23:59) → BLOCKING NOW

Firewall Rules: 5 blocked
```

---

## 🎉 Summary

### What Changed:

✅ **New Section:** "Active Firewall Rules (pfSense Anchor)"  
✅ **Real-time Display:** Shows actual pfctl output  
✅ **Color-coded:** Easy to understand  
✅ **Device Count:** Badge shows blocked count  
✅ **Rule Legend:** Explains each rule type  
✅ **No CLI:** Everything in the GUI

### Benefits:

✅ **Transparency:** See exactly what's happening  
✅ **Debugging:** Easy to verify and troubleshoot  
✅ **User-friendly:** No technical knowledge needed  
✅ **Real-time:** Always shows current state  
✅ **Professional:** Clean, informative display

---

## 🚀 Try It Now!

**Navigate to:**
```
https://fw.keekar.com/parental_control_status.php
```

**Or in pfSense GUI:**
```
Services → KACI Parental Control → Status
```

**Scroll to:**
```
"Active Firewall Rules (pfSense Anchor)" section
```

---

**Your Status page is now a complete monitoring dashboard!** 🎉

---

**Built with ❤️ by Mukesh Kesharwani**  
**© 2025 Keekar**

