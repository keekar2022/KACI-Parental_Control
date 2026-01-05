# HTTP Hijacking Guide - KACI Parental Control

## Overview

The KACI Parental Control system **already includes HTTP hijacking functionality**. When a device is blocked, any HTTP request is automatically redirected to a friendly block page explaining why access is restricted.

## How It Works

### Architecture

```
Blocked Device
    ↓
    Makes HTTP/HTTPS request
    ↓
pfSense Firewall (NAT Rules)
    ↓
    Redirects to 192.168.1.251:1008
    ↓
Captive Portal Server (parental_control_captive.php)
    ↓
    Serves block page
```

### Active Components

1. **NAT Redirect Rules**
   - HTTP (port 80) → 192.168.1.251:1008
   - HTTPS (port 443) → 192.168.1.251:1008
   - Source: `parental_control_blocked` alias
   
2. **Captive Portal Server**
   - PHP built-in server on port 1008
   - Serves `parental_control_captive.php`
   - Shows block message, usage stats, parent override
   
3. **Allow Rules**
   - DNS (port 53) allowed for blocked devices
   - Access to pfSense (ports 80, 443, 1008) allowed

## Why It Works on WiFi Reconnect

When devices connect to WiFi, operating systems automatically check for captive portals by making **plain HTTP requests**:

- **Windows**: `http://www.msftconnecttest.com/connecttest.txt`
- **Android**: `http://connectivitycheck.gstatic.com/generate_204`
- **iOS**: `http://captive.apple.com/hotspot-detect.html`

These HTTP requests work perfectly with the hijacking, which is why the block page appears automatically when reconnecting to WiFi.

## Why It Seems Not to Work During Regular Browsing

### The HTTPS Problem

Modern browsers use HTTPS by default. When you type "google.com", the browser automatically converts it to "https://google.com".

**What happens:**
1. Browser requests `https://example.com`
2. Firewall redirects HTTPS:443 → 192.168.1.251:1008
3. Browser expects SSL certificate for `example.com`
4. Gets certificate for `192.168.1.251` instead
5. **Certificate mismatch → Browser shows warning**
6. User clicks "Proceed anyway"
7. Block page displays

**This is normal behavior for ALL captive portals** (hotels, airports, coffee shops, etc.)

## Testing HTTP Hijacking

### From a Blocked Device

**Option 1: HTTP-Only Sites (Recommended)**
```
Visit: http://neverssl.com
Result: Block page appears immediately ✓
```

**Option 2: Force Plain HTTP**
```
Type in address bar: http://example.com
(Must include "http://" prefix)
Result: Block page appears ✓
```

**Option 3: Direct Portal Access**
```
Visit: http://192.168.1.251:1008
Result: Block page or status page ✓
```

**Option 4: WiFi Reconnect**
```
Disconnect and reconnect to WiFi
Result: OS captive portal detection triggers block page ✓
```

### From pfSense Shell

**Verify NAT Rules:**
```bash
pfctl -sn | grep parental_control_blocked
```

Expected output:
```
rdr pass on em1 ... from <parental_control_blocked> to any port = http -> 192.168.1.251 port 1008
rdr pass on em1 ... from <parental_control_blocked> to any port = https -> 192.168.1.251 port 1008
```

**Test Captive Portal:**
```bash
curl -v http://localhost:1008
```

Expected: HTTP 200 OK with block page HTML

**Run Diagnostic Script:**
```bash
/tmp/test_http_hijacking.sh
```

## User Experience Recommendations

### For End Users (Children)

Create bookmarks on devices:

1. **"Check Internet Status"**
   - URL: `http://192.168.1.251:1008`
   - When clicked: Shows current usage and limits
   
2. **"NeverSSL"**
   - URL: `http://neverssl.com`
   - When clicked: Triggers block page if blocked

### For Parents

Educate children:
- If internet stops working, visit the bookmarked page
- They will see a friendly explanation
- Parent override password can grant temporary access

## Technical Details

### NAT Rule Structure

```php
array(
    'source' => array('address' => 'parental_control_blocked'),
    'destination' => array('any' => '', 'port' => '80'),
    'protocol' => 'tcp',
    'target' => '192.168.1.251',
    'local-port' => '1008',
    'interface' => 'lan',
    'descr' => 'Parental Control - Redirect HTTP to Block Page',
    'associated-rule-id' => 'pass'
)
```

### Captive Portal Server

- **Type**: PHP built-in server
- **Command**: `php -S 0.0.0.0:1008 -t /usr/local/www /usr/local/www/parental_control_captive.php`
- **Script**: `/usr/local/www/parental_control_captive.php`
- **Port**: 1008
- **Auto-start**: Yes (via rc.d script)

### Allow Rules

Blocked devices can access:
- DNS (port 53) - Required to resolve domain names
- pfSense HTTP (port 80) - For block page access
- pfSense HTTPS (port 443) - For block page access
- Captive Portal (port 1008) - For block page

## Comparison with Other Captive Portals

| Feature | KACI Parental Control | Hotel WiFi | Airport WiFi |
|---------|----------------------|------------|--------------|
| HTTP Hijacking | ✓ Yes | ✓ Yes | ✓ Yes |
| HTTPS Certificate Warning | ✓ Yes | ✓ Yes | ✓ Yes |
| WiFi Reconnect Detection | ✓ Yes | ✓ Yes | ✓ Yes |
| Plain HTTP Works | ✓ Yes | ✓ Yes | ✓ Yes |
| HTTPS Shows Warning | ✓ Yes | ✓ Yes | ✓ Yes |

**Conclusion**: Your system behaves identically to professional captive portals. This is not a bug - it's standard behavior.

## Troubleshooting

### Block Page Not Appearing

1. **Check NAT rules are active:**
   ```bash
   pfctl -sn | grep parental_control_blocked
   ```

2. **Check captive portal is running:**
   ```bash
   ps aux | grep parental_control_captive
   ```

3. **Check device is in blocked list:**
   ```bash
   pfctl -t parental_control_blocked -T show
   ```

4. **Test with plain HTTP:**
   Visit `http://neverssl.com` (not HTTPS)

### Certificate Warnings on HTTPS

**This is normal!** HTTPS hijacking always shows certificate warnings because:
- Browser expects certificate for `example.com`
- Gets certificate for `192.168.1.251` instead
- Certificate domain mismatch

**Solutions:**
- Use plain HTTP sites for testing
- Click "Proceed anyway" on certificate warning
- Use the direct portal URL: `http://192.168.1.251:1008`

## Summary

- ✅ HTTP hijacking is **fully configured and operational**
- ✅ NAT redirect rules are **active**
- ✅ Captive portal server is **running**
- ✅ Works exactly like hotel/airport WiFi
- ⚠️ HTTPS shows certificate warnings (normal behavior)
- ⚠️ WiFi reconnect always works (OS uses plain HTTP)

**The system is working as designed!**

---

*Part of KACI Parental Control for pfSense*  
*Copyright © 2025 Mukesh Kesharwani*

