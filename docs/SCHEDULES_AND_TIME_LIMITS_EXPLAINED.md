# 🕐 Schedules & Time Limits - How They Work Together

## 📋 Overview

The parental control system uses **TWO independent blocking mechanisms** that work together:

1. **⏰ Time Schedules** - Block during specific hours (e.g., bedtime)
2. **⏱️ Time Limits** - Block when daily usage quota exhausted

Both are enforced through **pfSense anchor rules** for dynamic, fast blocking without full firewall reloads.

---

## 🔄 The Check Flow (Every 5 Minutes)

### Cron Job Execution Sequence:

```
┌─────────────────────────────────────────────┐
│ 1. Load current state                       │
│    - Profile usage counters                 │
│    - Currently blocked devices              │
│    - Last reset time                        │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│ 2. Check if midnight reset needed           │
│    - Reset profile usage_today to 0:00     │
│    - Unblock ALL devices (fresh start)      │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│ 3. Update usage for active devices          │
│    - Check active connections (pfctl)       │
│    - Add 5 mins to PROFILE counter         │
│    - Track device activity                  │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│ 4. Calculate which devices to block         │
│    ┌────────────────────────────────────┐  │
│    │ For each device:                   │  │
│    │                                    │  │
│    │ Step 1: Check parent override?    │  │
│    │   YES → Skip (allow access)       │  │
│    │   NO  → Continue                  │  │
│    │                                    │  │
│    │ Step 2: In blocked schedule? ⏰    │  │
│    │   YES → BLOCK (reason: schedule)  │  │
│    │   NO  → Continue                  │  │
│    │                                    │  │
│    │ Step 3: Time limit exceeded? ⏱️    │  │
│    │   YES → BLOCK (reason: limit)     │  │
│    │   NO  → Allow                     │  │
│    └────────────────────────────────────┘  │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│ 5. Apply firewall changes (only diff)       │
│    - Devices newly blocked → Add rules     │
│    - Devices unblocked → Remove rules      │
│    - No change → Skip (optimization)       │
└─────────────────────────────────────────────┘
```

---

## ⏰ Schedule Blocking (Detailed)

### How It Works:

**Function:** `pc_is_in_blocked_schedule($device)`

**Location:** `parental_control.inc` line 1321

### Check Logic:

```php
1. Get current day & time
   - Day: Monday (1) to Sunday (7)
   - Time: HH:MM format (24-hour)

2. Get device's profile name
   - From: $device['child_name'] or $device['profile_name']

3. Load all schedules from config
   - Path: installedpackages/parentalcontrolschedules/config

4. For each ENABLED schedule:
   a. Does this schedule apply to device's profile?
      - Check profile_names list
      - Skip if profile not in list
   
   b. Does today match schedule days?
      - Example: ["mon", "tue", "wed", "thu", "fri"]
      - Skip if today not in list
   
   c. Is current time in blocked range?
      - Start: 20:00, End: 23:59
      - Current: 22:30 → BLOCKED ✅
      - Current: 14:00 → ALLOWED ✅
   
   d. If ALL conditions match:
      - Return TRUE (device is in blocked schedule)

5. If no schedules match:
   - Return FALSE (device allowed by schedules)
```

### Example Schedule Configuration:

**Schedule Name:** "Bedtime-1"  
**Profile:** Vishesh  
**Days:** Sun, Mon, Tue, Wed, Thu, Fri, Sat (all days)  
**Time:** 20:00 - 23:59  
**Status:** Enabled

**Result:**
- At 22:30 (any day) → Vishesh's devices BLOCKED
- At 14:00 (any day) → Vishesh's devices ALLOWED

---

## ⏱️ Time Limit Blocking (Detailed)

### How It Works:

**Function:** `pc_is_time_limit_exceeded($device, $state)`

**Location:** `parental_control.inc` line 1573

### Check Logic:

```php
1. Get device's profile name
   - From: $device['profile_name']

2. Get profile's daily limit
   - Example: 240 minutes (4 hours)
   - If 0 → UNLIMITED (skip check)

3. Check if weekend (Sat/Sun)
   - Add weekend bonus if applicable
   - Example: 4hrs + 30min bonus = 4:30 on weekends

4. Get PROFILE usage (shared across all devices!)
   - From: $state['profiles'][$profile_name]['usage_today']
   - Example: 245 minutes (4:05)

5. Compare usage vs limit:
   - 245 >= 240 → BLOCKED ✅
   - 180 <  240 → ALLOWED ✅
```

### Example (Vishesh Profile):

**Daily Limit:** 4:00 (240 mins)  
**Devices:** 5 (iPhone, iPad, MacBook, TV, Laptop)

**Usage Timeline:**
```
08:00 - Uses iPhone for 1:00
        Profile Usage: 60 mins → ALLOWED

10:00 - Uses iPad for 1:30
        Profile Usage: 150 mins → ALLOWED

14:00 - Uses MacBook for 1:00
        Profile Usage: 210 mins → ALLOWED

17:00 - Uses TV for 0:30
        Profile Usage: 240 mins → LIMIT REACHED!

18:00 - Tries to use ANY device
        Profile Usage: 240 mins → ALL 5 DEVICES BLOCKED ✅
```

---

## 🚫 Firewall Rules (When Blocked)

### The Anchor System:

**File:** `/tmp/rules.parental_control`  
**Anchor:** `parental_control` (pfSense anchor)

### Rules Created Per Device:

When a device is blocked, **5 rules** are added:

```pf
# Device: 192.168.1.115 (Vishesh-iPhone) - Scheduled block time

# 1. Allow DNS (resolve hostnames)
pass quick proto udp from 192.168.1.115 to any port 53 \
  label "PC-DNS:Vishesh-iPhone"

# 2. Allow access to pfSense GUI (for block page)
pass quick from 192.168.1.115 to 192.168.1.1 \
  label "PC-Allow:Vishesh-iPhone"

# 3. Redirect HTTP to block page
rdr pass proto tcp from 192.168.1.115 to any port 80 \
  -> 192.168.1.1 port 443 \
  label "PC-HTTP:Vishesh-iPhone"

# 4. Redirect HTTPS to block page
rdr pass proto tcp from 192.168.1.115 to any port 443 \
  -> 192.168.1.1 port 443 \
  label "PC-HTTPS:Vishesh-iPhone"

# 5. Block ALL other traffic
block drop quick from 192.168.1.115 to any \
  label "PC-Block:Vishesh-iPhone"
```

### Rule Explanation:

| Rule | Purpose | Why Needed |
|------|---------|------------|
| **Pass DNS** | Allow name resolution | User can see block page by hostname |
| **Pass pfSense** | Allow access to firewall | User can access block page |
| **RDR HTTP** | Redirect port 80 | Shows block page instead of timeout |
| **RDR HTTPS** | Redirect port 443 | Shows block page (cert warning) |
| **Block Drop** | Block everything else | Enforce the block |

### How Rules Are Applied:

```bash
# 1. Rules written to anchor file
echo "# Device rules..." >> /tmp/rules.parental_control

# 2. Anchor reloaded (FAST - no full firewall reload!)
/sbin/pfctl -a parental_control -f /tmp/rules.parental_control

# Result: Rules active immediately (< 1 second)
```

---

## 🔀 Interaction: Schedules + Time Limits

### Priority Order:

1. **Parent Override** (highest priority)
   - If active → ALLOW (bypass everything)

2. **Schedule Blocking** (second priority)
   - If in blocked schedule → BLOCK
   - Reason: "Scheduled block time"

3. **Time Limit** (third priority)
   - If usage exceeded → BLOCK
   - Reason: "Time limit exceeded"

4. **Default** (lowest priority)
   - ALLOW

### Real-World Scenarios:

#### Scenario 1: Schedule + Under Limit

**Vishesh Profile:**
- Time: 22:30 (during bedtime schedule)
- Usage: 2:00 / 4:00 (under limit)

**Result:**
- ⏰ Schedule: BLOCKED ✅
- ⏱️ Limit: ALLOWED
- **Final: BLOCKED** (schedule takes precedence)
- **Reason:** "Scheduled block time"

---

#### Scenario 2: Not in Schedule + Over Limit

**Vishesh Profile:**
- Time: 15:00 (outside schedule)
- Usage: 4:30 / 4:00 (over limit)

**Result:**
- ⏰ Schedule: ALLOWED
- ⏱️ Limit: BLOCKED ✅
- **Final: BLOCKED** (limit exceeded)
- **Reason:** "Time limit exceeded"

---

#### Scenario 3: Schedule + Over Limit (Both Apply!)

**Vishesh Profile:**
- Time: 22:30 (during bedtime schedule)
- Usage: 4:30 / 4:00 (over limit)

**Result:**
- ⏰ Schedule: BLOCKED ✅
- ⏱️ Limit: BLOCKED ✅
- **Final: BLOCKED** (both conditions met)
- **Reason:** "Scheduled block time" (checked first)

**Note:** Even if schedule ends at 23:59, device remains blocked until midnight reset because limit is exceeded!

---

#### Scenario 4: Parent Override Active

**Vishesh Profile:**
- Time: 22:30 (during bedtime schedule)
- Usage: 4:30 / 4:00 (over limit)
- Override: ACTIVE (30 min duration)

**Result:**
- 🔓 Override: ACTIVE ✅
- ⏰ Schedule: (skipped - override active)
- ⏱️ Limit: (skipped - override active)
- **Final: ALLOWED** (override bypasses everything)

---

## 📊 Example: Your Current Setup

### Vishesh Profile:
- **Limit:** 4:00 daily (240 mins)
- **Schedule 1 (Bedtime-1):** 20:00 - 23:59 (every day)
- **Schedule 2 (BedTime-2):** 00:00 - 06:30 (every day)
- **Devices:** 5 (iPhone, iPad, MacBook, TV, Laptop)

### Timeline Example (Monday):

| Time  | Activity | Usage | Schedule? | Limit? | Result |
|-------|----------|-------|-----------|--------|---------|
| 06:00 | Wake up | 0:00/4:00 | 🚫 BedTime-2 | ✅ Under | 🚫 **BLOCKED** (schedule) |
| 06:30 | Ready | 0:00/4:00 | ✅ Free | ✅ Under | ✅ **ALLOWED** |
| 08:00 | Use iPad (1hr) | 1:00/4:00 | ✅ Free | ✅ Under | ✅ **ALLOWED** |
| 12:00 | Use iPhone (2hr) | 3:00/4:00 | ✅ Free | ✅ Under | ✅ **ALLOWED** |
| 16:00 | Use MacBook (1hr) | 4:00/4:00 | ✅ Free | ⚠️ At Limit | ✅ **ALLOWED** (last mins) |
| 17:00 | Try any device | 4:00/4:00 | ✅ Free | 🚫 Exceeded | 🚫 **BLOCKED** (limit) |
| 20:00 | Bedtime starts | 4:00/4:00 | 🚫 Bedtime-1 | 🚫 Exceeded | 🚫 **BLOCKED** (both!) |
| 23:59 | Schedule ends | 4:00/4:00 | ✅ Free | 🚫 Exceeded | 🚫 **BLOCKED** (limit still) |
| 00:00 | **MIDNIGHT RESET** | 0:00/4:00 | 🚫 BedTime-2 | ✅ Reset | 🚫 **BLOCKED** (schedule) |

---

## 🔍 Viewing Active Rules

### Check Current Firewall Rules:

```bash
# View all rules in parental control anchor
sudo pfctl -a parental_control -sr

# View blocked devices
cat /tmp/rules.parental_control

# Check if specific IP is blocked
sudo pfctl -a parental_control -sr | grep "192.168.1.115"
```

### Example Output:

```pf
# Device: 192.168.1.115 (Vishesh-iPhone) - Scheduled block time
pass quick proto udp from 192.168.1.115 to any port = 53 flags S/SA keep state label "PC-DNS:Vishesh-iPhone"
pass quick from 192.168.1.115 to 192.168.1.1 flags S/SA keep state label "PC-Allow:Vishesh-iPhone"
block drop quick from 192.168.1.115 to any label "PC-Block:Vishesh-iPhone"
```

---

## 🎯 Key Points

### Schedule Blocking:
✅ **Checked first** (highest priority after override)  
✅ **Independent** of time limits  
✅ **Day & time specific**  
✅ **Multiple schedules** can apply to same profile  
✅ **Applies immediately** when time range starts

### Time Limit Blocking:
✅ **Checked second** (after schedules)  
✅ **Shared across all devices** in profile  
✅ **Accumulates** throughout the day  
✅ **Resets at midnight**  
✅ **Applies when limit reached**

### Firewall Rules:
✅ **Created dynamically** via pfSense anchor  
✅ **Fast application** (< 1 second)  
✅ **Per-device** (one set per blocked IP)  
✅ **Redirect to block page** (HTTP/HTTPS)  
✅ **Removed when unblocked**

---

## 🛠️ Troubleshooting

### Device not blocking during schedule?

**Check:**
1. Is schedule enabled?
2. Is profile name correct?
3. Are days configured correctly?
4. Is time range correct (24-hour format)?
5. Run: `tail -f /var/log/system.log | grep parental`

### Device blocking at wrong time?

**Check:**
1. Firewall timezone settings
2. Schedule time format (HH:MM)
3. Day mapping (mon, tue, wed, etc.)

### Rules not applying?

**Check:**
1. Anchor file exists: `ls -l /tmp/rules.parental_control`
2. Cron job running: `sudo crontab -l | grep parental`
3. Rules loaded: `sudo pfctl -a parental_control -sr`

---

**Built with ❤️ by Mukesh Kesharwani**  
**© 2025 Keekar**

