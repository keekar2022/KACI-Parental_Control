# Block Page User Experience Guide

## 📱 What Users See When Blocked

### ✅ **YES! Users WILL see a message explaining why access is blocked**

---

## 🎯 How It Works

### Step 1: User Tries to Browse
```
User opens browser → Types "google.com" or any website
```

### Step 2: Automatic Redirect
```
pfSense intercepts the request → Redirects to block page
```

### Step 3: Block Page Displays
```
┌────────────────────────────────────────────────────────┐
│  🔒 KACI Parental Control - Access Restricted          │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ⏰ Your internet time is up!                          │
│     Time to take a break and do other activities.      │
│                                                         │
│  📊 Usage Information:                                 │
│     ├─ Used Today: 8 hours 0 minutes                   │
│     ├─ Daily Limit: 8 hours 0 minutes                  │
│     └─ Time Resets: Today at 12:00 AM                  │
│                                                         │
│  🚫 Block Reason: Daily Time Limit Exceeded            │
│                                                         │
│  👨‍👩‍👧‍👦 Parent Override (Optional):                        │
│     If you need access for homework or emergencies,    │
│     ask a parent to enter the override password.       │
│                                                         │
│     Password: [________________]                        │
│     [Grant Temporary Access]                            │
│                                                         │
└────────────────────────────────────────────────────────┘
```

---

## 🎨 Block Page Features

### Information Displayed

1. **Custom Message**
   - Configurable in Settings
   - Default: "Your internet time is up! Time to take a break and do other activities."
   - Can be personalized per family

2. **Usage Statistics**
   - ✅ Time used today (e.g., "8 hours 15 minutes")
   - ✅ Daily limit (e.g., "8 hours 0 minutes")
   - ✅ Remaining time (if not exceeded)
   - ✅ Next reset time (e.g., "Today at 12:00 AM")

3. **Block Reason**
   - **"Daily Time Limit Exceeded"** - Used all allowed time
   - **"Scheduled Block Time"** - Currently in blocked hours (e.g., bedtime)
   - **"Access Restricted"** - Generic message if reason unknown

4. **Device Information**
   - Device name (e.g., "MukeshMacPro")
   - Profile name (e.g., "Mukesh")
   - IP address (for troubleshooting)

5. **Parent Override Form** (if enabled)
   - Password field
   - "Grant Temporary Access" button
   - Override duration (configurable, default 30 minutes)
   - Success/error messages

---

## 🔧 Configuration

### Enable Block Page Messages

**Location**: Services > Parental Control > Settings

**Settings**:

1. **Blocked Message**
   ```
   Default: "Your internet time is up! Time to take a break and do other activities."
   
   Customize examples:
   - "Study time! Internet will be available after homework."
   - "Bedtime! See you tomorrow morning. 😴"
   - "Family time! Let's talk and play together."
   ```

2. **Override Password**
   ```
   Set a password parents can use to grant temporary access
   Example: "Parent2025"
   
   Leave empty to disable parent override feature
   ```

3. **Override Duration**
   ```
   How long override lasts (in minutes)
   Default: 30 minutes
   Range: 5-240 minutes (4 hours max)
   ```

---

## 📋 Example Scenarios

### Scenario 1: Time Limit Exceeded

**User**: Mukesh (8-hour daily limit)  
**Time Used**: 8 hours 5 minutes  
**Time**: 3:00 PM  

**Block Page Shows**:
```
⏰ Your internet time is up!
   Time to take a break and do other activities.

📊 Usage: 8:05 / 8:00 (limit exceeded)
🔄 Resets: Today at 12:00 AM (9 hours from now)
🚫 Reason: Daily Time Limit Exceeded
```

---

### Scenario 2: Scheduled Block Time

**User**: Vishesh  
**Schedule**: Blocked 10:00 PM - 7:00 AM (bedtime)  
**Time**: 10:30 PM  

**Block Page Shows**:
```
😴 Bedtime! See you tomorrow morning.

📊 Usage: 3:45 / 8:00 (4:15 remaining)
⏰ Schedule: Blocked until 7:00 AM tomorrow
🚫 Reason: Scheduled Block Time
```

---

### Scenario 3: Parent Override Success

**User**: Clicks "Grant Temporary Access"  
**Password**: Correct  

**Block Page Shows**:
```
✅ Access Granted!

You have 30 minutes of temporary access.
This override will expire at 4:00 PM.

Redirecting to your original page...
(Auto-redirect in 3 seconds)
```

---

## 🔍 Technical Details

### How Redirect Works

**Anchor Rules** (created for each blocked device):
```bash
# Allow DNS so user can resolve hostnames
pass quick proto udp from 192.168.1.111 to any port 53

# Allow access to pfSense (for block page)
pass quick from 192.168.1.111 to 192.168.1.1

# Redirect HTTP → pfSense HTTPS
rdr pass proto tcp from 192.168.1.111 to any port 80 -> 192.168.1.1 port 443

# Redirect HTTPS → pfSense HTTPS
rdr pass proto tcp from 192.168.1.111 to any port 443 -> 192.168.1.1 port 443

# Block everything else
block drop quick from 192.168.1.111 to any
```

### What Happens

1. **User types**: `http://google.com`
2. **DNS resolves**: Google's IP address
3. **HTTP request sent**: To Google's IP on port 80
4. **pfSense intercepts**: Redirect rule matches
5. **Redirects to**: `https://192.168.1.1/parental_control_blocked.php`
6. **Block page loads**: Shows reason and stats

---

## ❓ FAQ

### Q: Will users see the block page automatically?
**A**: ✅ **YES!** When they try to browse any website, they're automatically redirected to the block page.

### Q: What if they try HTTPS sites?
**A**: ✅ **Still works!** HTTPS is also redirected. They may see a certificate warning (because pfSense's cert doesn't match the site they tried to visit), but clicking "Proceed Anyway" shows the block page.

### Q: Can they bypass it?
**A**: ❌ **NO.** All traffic is blocked except:
- DNS (so redirect works)
- Access to pfSense (for block page)
- Everything else is blocked

### Q: What if they use a VPN or proxy?
**A**: ❌ **Blocked.** The firewall blocks at the IP level, so VPN/proxy connections can't be established.

### Q: Can they see the block page without trying to browse?
**A**: ✅ **YES!** They can directly visit:
```
https://firewall/parental_control_blocked.php
or
https://192.168.1.1/parental_control_blocked.php
```

### Q: What if parent override is disabled?
**A**: The override form won't show. Users just see the message and stats.

### Q: How long does parent override last?
**A**: Configurable (default 30 minutes). After that, blocking resumes automatically.

---

## 🎯 Summary

### ✅ What Users See:

| Situation | User Experience |
|-----------|----------------|
| **Tries to browse** | Automatically redirected to block page |
| **Block page shows** | Reason, usage stats, reset time |
| **Parent override** | Can request temporary access (if enabled) |
| **After override** | Access granted for configured duration |
| **Override expires** | Blocking resumes automatically |

### ✅ Benefits:

- 🎯 **Clear communication** - No confusion about why internet isn't working
- 📊 **Transparency** - Users see their usage and limits
- 🔐 **Flexibility** - Parents can grant emergency access
- 🤝 **Better compliance** - Understanding leads to cooperation
- 📱 **User-friendly** - Professional, informative interface

---

## 🚀 Ready to Use!

The block page is **automatically enabled** with v0.7.4. No configuration needed!

**Optional customization**:
1. Go to: Services > Parental Control > Settings
2. Set custom blocked message
3. Configure override password (if desired)
4. Set override duration

**That's it! Users will now see helpful messages when blocked.** 🎉

