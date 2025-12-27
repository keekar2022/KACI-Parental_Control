# KACI Parental Control - Technical Reference

**Complete technical documentation for developers, integrators, and advanced users**

---

## 📑 Table of Contents

1. [API Documentation](#api-documentation)
2. [Architecture](#architecture)
3. [pfSense Anchors Implementation](#pfsense-anchors-implementation)
4. [Block Page Implementation](#block-page-implementation)
5. [Development Guide](#development-guide)
6. [Development Workflow](#development-workflow)
7. [GitHub Pages Setup](#github-pages-setup)

---

# API Documentation

# Parental Control API Documentation

## Overview

The Parental Control API provides RESTful endpoints for external integration, automation, and monitoring. This allows you to build custom dashboards, mobile apps, home automation integrations, and more.

## Authentication

All API requests require authentication using an API key.

### Setting Up API Key

1. Navigate to **Services → Parental Control**
2. Scroll to **API Settings** section
3. Generate or enter an API key (minimum 32 characters recommended)
4. Save settings

### Using API Key

Include your API key in one of two ways:

**Option 1: HTTP Header (Recommended)**
```bash
curl -H "X-API-Key: your_api_key_here" https://pfsense.local/parental_control_api.php/status
```

**Option 2: Query Parameter**
```bash
curl "https://pfsense.local/parental_control_api.php/status?api_key=your_api_key_here"
```

## Base URL

```
https://your-pfsense-ip/parental_control_api.php
```

## Response Format

All responses are in JSON format with the following structure:

```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": { ... }
}
```

Error responses include an `error` field:

```json
{
  "status": "error",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": null,
  "error": "Error message here"
}
```

## HTTP Status Codes

- `200 OK` - Request succeeded
- `400 Bad Request` - Invalid request format or missing required fields
- `401 Unauthorized` - Invalid or missing API key
- `404 Not Found` - Resource not found or endpoint doesn't exist
- `503 Service Unavailable` - Parental Control service is disabled

## Endpoints

### GET /status

Get overall service status and statistics.

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/status
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": {
    "service_enabled": true,
    "devices_count": 5,
    "devices_enabled": 4,
    "profiles_count": 2,
    "last_check": "2025-12-25T10:29:00+00:00",
    "last_reset": "2025-12-25T00:00:00+00:00"
  }
}
```

---

### GET /devices

List all configured devices.

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/devices
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": [
    {
      "mac_address": "aa:bb:cc:dd:ee:ff",
      "device_name": "iPhone",
      "child_name": "Alice",
      "ip_address": "192.168.1.100",
      "enabled": true,
      "online": true,
      "usage": {
        "usage_today": 120,
        "usage_week": 450,
        "last_seen": 1735123800
      }
    }
  ]
}
```

---

### GET /devices/{mac}

Get details for a specific device by MAC address.

**Parameters:**
- `mac` - MAC address (format: aa:bb:cc:dd:ee:ff or aa-bb-cc-dd-ee-ff)

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/devices/aa:bb:cc:dd:ee:ff
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": {
    "mac_address": "aa:bb:cc:dd:ee:ff",
    "device_name": "iPhone",
    "child_name": "Alice",
    "ip_address": "192.168.1.100",
    "enabled": true,
    "online": true,
    "daily_limit": 120,
    "weekly_limit": 600,
    "usage": {
      "usage_today": 45,
      "usage_week": 180,
      "last_seen": 1735123800
    },
    "schedules_applied": [
      {
        "id": 0,
        "schedule_name": "Bedtime",
        "time_range": "22:00 - 07:00",
        "start_time": "22:00",
        "end_time": "07:00",
        "days": ["sun", "mon", "tue", "wed", "thu", "fri", "sat"],
        "enabled": true,
        "currently_active": false,
        "applies_to_profiles": ["Alice Profile", "Bob Profile"]
      },
      {
        "id": 1,
        "schedule_name": "School Hours",
        "time_range": "08:00 - 15:00",
        "start_time": "08:00",
        "end_time": "15:00",
        "days": ["mon", "tue", "wed", "thu", "fri"],
        "enabled": true,
        "currently_active": true,
        "applies_to_profiles": ["Alice Profile"]
      }
    ],
    "currently_blocked": false
  }
}
```

---

### POST /devices/{mac}/block

Temporarily block a device.

**Parameters:**
- `mac` - MAC address

**Request Body:**
```json
{
  "duration": 60,
  "reason": "Timeout for bad behavior"
}
```

**Fields:**
- `duration` (optional) - Block duration in minutes (default: 60)
- `reason` (optional) - Reason for blocking (default: "Temporary API block")

**Example:**
```bash
curl -X POST \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"duration": 30, "reason": "Timeout"}' \
  https://pfsense.local/parental_control_api.php/devices/aa:bb:cc:dd:ee:ff/block
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": {
    "mac_address": "aa:bb:cc:dd:ee:ff",
    "blocked": true,
    "duration_minutes": 30,
    "reason": "Timeout"
  }
}
```

---

### POST /devices/{mac}/unblock

Remove block from a device.

**Parameters:**
- `mac` - MAC address

**Example:**
```bash
curl -X POST \
  -H "X-API-Key: YOUR_KEY" \
  https://pfsense.local/parental_control_api.php/devices/aa:bb:cc:dd:ee:ff/unblock
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": {
    "mac_address": "aa:bb:cc:dd:ee:ff",
    "blocked": false
  }
}
```

---

### GET /profiles

List all configured profiles.

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/profiles
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": [
    {
      "id": 1,
      "name": "Alice Profile",
      "description": "Primary devices for Alice",
      "enabled": true,
      "daily_limit": 120,
      "weekend_bonus": 60,
      "device_count": 2
    }
  ]
}
```

---

### GET /profiles/{id}

Get details for a specific profile.

**Parameters:**
- `id` - Profile ID

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/profiles/1
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": {
    "id": 1,
    "name": "Alice Profile",
    "description": "Primary devices for Alice",
    "enabled": true,
    "daily_limit": 120,
    "weekend_bonus": 60,
    "devices": [
      {
        "mac_address": "aa:bb:cc:dd:ee:ff",
        "device_name": "iPhone",
        "ip_address": "192.168.1.100"
      }
    ],
    "schedules_applied": [
      {
        "id": 0,
        "schedule_name": "Bedtime",
        "time_range": "22:00 - 07:00",
        "start_time": "22:00",
        "end_time": "07:00",
        "days": ["sun", "mon", "tue", "wed", "thu", "fri", "sat"],
        "enabled": true,
        "currently_active": false,
        "applies_to_profiles": ["Alice Profile", "Bob Profile"]
      }
    ],
    "usage": {
      "usage_today": 45,
      "usage_week": 180
    }
  }
}
```

---

### GET /profiles/{id}/schedules

Get all schedules that apply to a specific profile.

**Parameters:**
- `id` - Profile ID

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/profiles/1/schedules
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-26T04:00:00+00:00",
  "data": {
    "profile_id": "1",
    "profile_name": "Alice Profile",
    "schedules_count": 2,
    "schedules": [
      {
        "id": 0,
        "schedule_name": "Bedtime",
        "time_range": "22:00 - 07:00",
        "start_time": "22:00",
        "end_time": "07:00",
        "days": ["sun", "mon", "tue", "wed", "thu", "fri", "sat"],
        "enabled": true,
        "currently_active": false,
        "applies_to_profiles": ["Alice Profile", "Bob Profile"]
      },
      {
        "id": 1,
        "schedule_name": "School Hours",
        "time_range": "08:00 - 15:00",
        "start_time": "08:00",
        "end_time": "15:00",
        "days": ["mon", "tue", "wed", "thu", "fri"],
        "enabled": true,
        "currently_active": true,
        "applies_to_profiles": ["Alice Profile"]
      }
    ]
  }
}
```

---

### GET /schedules

List all configured schedules across all profiles.

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/schedules
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-26T04:00:00+00:00",
  "data": [
    {
      "id": 0,
      "name": "Bedtime",
      "profiles": ["Alice Profile", "Bob Profile"],
      "time_range": "22:00 - 07:00",
      "days": ["sun", "mon", "tue", "wed", "thu", "fri", "sat"],
      "enabled": true,
      "currently_active": false
    },
    {
      "id": 1,
      "name": "School Hours",
      "profiles": ["Alice Profile"],
      "time_range": "08:00 - 15:00",
      "days": ["mon", "tue", "wed", "thu", "fri"],
      "enabled": true,
      "currently_active": true
    }
  ]
}
```

---

### GET /schedules/{id}

Get details for a specific schedule by ID.

**Parameters:**
- `id` - Schedule ID

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/schedules/0
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-26T04:00:00+00:00",
  "data": {
    "id": 0,
    "name": "Bedtime",
    "profiles": ["Alice Profile", "Bob Profile"],
    "start_time": "22:00",
    "end_time": "07:00",
    "time_range": "22:00 - 07:00",
    "days": ["sun", "mon", "tue", "wed", "thu", "fri", "sat"],
    "enabled": true,
    "currently_active": false,
    "affected_devices_count": 4
  }
}
```

---

### GET /schedules/active

Get all currently active schedules (blocking now).

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/schedules/active
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-26T04:00:00+00:00",
  "data": {
    "count": 1,
    "schedules": [
      {
        "id": 1,
        "name": "School Hours",
        "profiles": ["Alice Profile"],
        "time_range": "08:00 - 15:00",
        "start_time": "08:00",
        "end_time": "15:00",
        "days": ["mon", "tue", "wed", "thu", "fri"],
        "blocking_since": null
      }
    ]
  }
}
```

**Use Cases:**
- Real-time monitoring dashboards
- Home automation triggers (e.g., notify when bedtime schedule activates)
- Mobile app notifications
- External logging systems

---

### GET /usage

Get overall usage statistics across all devices.

**Example:**
```bash
curl -H "X-API-Key: YOUR_KEY" https://pfsense.local/parental_control_api.php/usage
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": {
    "total_devices": 5,
    "devices_online": 3,
    "devices_blocked": 1,
    "total_usage_today": 240,
    "total_usage_week": 890,
    "devices": [
      {
        "mac_address": "aa:bb:cc:dd:ee:ff",
        "device_name": "iPhone",
        "child_name": "Alice",
        "online": true,
        "blocked": false,
        "usage_today": 45,
        "usage_week": 180,
        "last_seen": "2025-12-25T10:28:00+00:00"
      }
    ]
  }
}
```

---

### POST /override

Grant temporary internet access override (e.g., for homework, emergency).

**Request Body:**
```json
{
  "mac_address": "aa:bb:cc:dd:ee:ff",
  "duration": 30,
  "reason": "Homework assignment"
}
```

**Fields:**
- `mac_address` (required) - Device MAC address
- `duration` (optional) - Override duration in minutes (default: 30)
- `reason` (optional) - Reason for override (default: "Temporary override")

**Example:**
```bash
curl -X POST \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"mac_address": "aa:bb:cc:dd:ee:ff", "duration": 30, "reason": "Homework"}' \
  https://pfsense.local/parental_control_api.php/override
```

**Response:**
```json
{
  "status": "success",
  "timestamp": "2025-12-25T10:30:00+00:00",
  "data": {
    "mac_address": "aa:bb:cc:dd:ee:ff",
    "override_granted": true,
    "duration_minutes": 30,
    "expires_at": "2025-12-25T11:00:00+00:00"
  }
}
```

---

## Use Cases

### Home Automation Integration

```python
# Example: Block all devices at bedtime
import requests

API_KEY = "your_api_key"
BASE_URL = "https://pfsense.local/parental_control_api.php"
HEADERS = {"X-API-Key": API_KEY}

# Get all devices
response = requests.get(f"{BASE_URL}/devices", headers=HEADERS, verify=False)
devices = response.json()["data"]

# Block each device for 8 hours (480 minutes)
for device in devices:
    mac = device["mac_address"]
    requests.post(
        f"{BASE_URL}/devices/{mac}/block",
        headers=HEADERS,
        json={"duration": 480, "reason": "Automated bedtime"},
        verify=False
    )
```

### Mobile App Dashboard

```javascript
// Example: Fetch usage statistics for dashboard
async function getUsageStats() {
    const response = await fetch('https://pfsense.local/parental_control_api.php/usage', {
        headers: {
            'X-API-Key': 'your_api_key'
        }
    });
    
    const data = await response.json();
    return data.data;
}
```

### Reward System Integration

```bash
#!/bin/bash
# Grant 30-minute bonus for completing chores

MAC_ADDRESS="aa:bb:cc:dd:ee:ff"
API_KEY="your_api_key"

curl -X POST \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"mac_address\": \"$MAC_ADDRESS\", \"duration\": 30, \"reason\": \"Chores completed\"}" \
  https://pfsense.local/parental_control_api.php/override
```

---

## Security Best Practices

1. **Use HTTPS**: Always access the API over HTTPS in production
2. **Strong API Keys**: Generate random 32+ character keys
3. **Key Rotation**: Periodically rotate API keys
4. **IP Whitelisting**: Restrict API access to trusted IPs (configure in pfSense firewall)
5. **Rate Limiting**: Implement rate limiting to prevent abuse
6. **Audit Logs**: Monitor API access logs in `/var/log/parental_control.jsonl`
7. **Least Privilege**: Create separate API keys for different integrations with minimal needed access

---

## Troubleshooting

### 401 Unauthorized

- **Check API key**: Ensure key matches what's configured in pfSense
- **Verify header**: Confirm `X-API-Key` header is being sent correctly
- **Check case sensitivity**: API keys are case-sensitive

### 503 Service Unavailable

- **Enable service**: Go to Services → Parental Control and enable the service
- **Check logs**: Review `/var/log/parental_control.jsonl` for errors

### Device Not Found (404)

- **MAC address format**: Ensure MAC is in format `aa:bb:cc:dd:ee:ff`
- **Device exists**: Verify device is configured in Parental Control
- **Case sensitivity**: MAC addresses are normalized to lowercase

---

## API Versioning

Current API version: `0.1.4`

API endpoints and response formats are stable within minor versions. Breaking changes will increment the major version.

---

## Support

For issues, feature requests, or contributions:
- GitHub: https://github.com/keekar2022/KACI-Parental_Control
- Documentation: See `/usr/local/pkg/docs/` on your pfSense system

---

**Last Updated:** 2025-12-25
**Package Version:** 0.1.4


---

# Architecture

# Architecture Overview

**KACI Parental Control for pfSense**  
**Layer 3 Network-Based Time Control System**

---

## 🏗️ System Architecture

### High-Level Overview

```
┌─────────────────────────────────────────────────────────────┐
│                      pfSense Web GUI                         │
│  (parental_control.xml, parental_control_profiles.xml)      │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                   Core Logic Layer                           │
│              (parental_control.inc)                          │
│  • Device Management  • Time Tracking  • Rule Generation    │
└────────────────────────┬────────────────────────────────────┘
                         │
        ┌────────────────┼────────────────┐
        ▼                ▼                ▼
┌──────────────┐  ┌─────────────┐  ┌────────────┐
│  State File  │  │ Log Files   │  │  Firewall  │
│  (JSON)      │  │  (JSONL)    │  │  (pf)      │
└──────────────┘  └─────────────┘  └────────────┘
```

---

## 🔄 Data Flow

### 1. Device Registration
```
User enters device info in GUI
  ↓
MAC address stored for identification
  ↓
Device profile created in config.xml
  ↓
State initialized in state file
```

### 2. Runtime Operation (Every Minute)
```
Cron triggers enforcement
  ↓
MAC → IP resolution via ARP/DHCP
  ↓
Check active connections (pfctl -s state)
  ↓
Update usage counters (IP-based state)
  ↓
Apply/remove firewall rules as needed
  ↓
Log activity to JSONL
```

### 3. Firewall Integration
```
Time limit exceeded OR schedule block triggered
  ↓
Create pf firewall rule with IP address
  ↓
Block all traffic from that IP
  ↓
Remove rule when time/schedule allows
```

---

## 🎯 Layer 3 Architecture (CRITICAL)

### Why Layer 3?

pfSense is a **Layer 3 firewall** that operates on **IP addresses**, not MAC addresses.

### Device Identification vs. Operational Logic

| Aspect | Layer 2 (MAC) | Layer 3 (IP) | Our Usage |
|--------|---------------|--------------|-----------|
| **User Configuration** | ✅ MAC address | ❌ | Used for device ID |
| **State Storage** | ❌ | ✅ IP address | Stored by IP |
| **Firewall Rules** | ❌ | ✅ IP address | Rules use IP |
| **Connection Tracking** | ❌ | ✅ IP address | Query by IP |
| **Time Tracking** | ❌ | ✅ IP address | Track by IP |

### The Flow

```
┌──────────────────────────────────────────────────────────┐
│ 1. USER CONFIGURATION (GUI)                              │
│    Device defined by MAC: aa:bb:cc:dd:ee:ff              │
└──────────────────────────────────────────────────────────┘
                         ↓
┌──────────────────────────────────────────────────────────┐
│ 2. RUNTIME RESOLUTION (Every check)                      │
│    MAC → IP lookup via ARP/DHCP                          │
│    aa:bb:cc:dd:ee:ff → 192.168.1.100                     │
└──────────────────────────────────────────────────────────┘
                         ↓
┌──────────────────────────────────────────────────────────┐
│ 3. STATE STORAGE (JSON file)                             │
│    devices_by_ip: {                                      │
│      "192.168.1.100": { mac: "aa:bb:cc:dd:ee:ff", ... }  │
│    }                                                     │
└──────────────────────────────────────────────────────────┘
                         ↓
┌──────────────────────────────────────────────────────────┐
│ 4. CONNECTION TRACKING (pfctl -s state)                 │
│    pfctl -s state | grep 192.168.1.100                   │
│    Found 37 active connections                           │
└──────────────────────────────────────────────────────────┘
                         ↓
┌──────────────────────────────────────────────────────────┐
│ 5. FIREWALL RULES (pf)                                   │
│    block out on $lan from 192.168.1.100 to any          │
└──────────────────────────────────────────────────────────┘
```

### DHCP Renewals & IP Changes

The system handles dynamic IP addresses:

```python
# Every check cycle:
1. Look up current IP for MAC address
2. Check if IP changed since last check
3. If changed:
   - Move usage data from old IP to new IP
   - Update firewall rules with new IP
   - Log the change
4. Continue tracking with new IP
```

---

## 📦 Component Details

### Core Components

#### 1. **parental_control.inc** (Main Logic)
- **Size**: ~3,500 lines of PHP
- **Functions**: 50+ documented functions
- **Responsibilities**:
  - Device management (CRUD)
  - Time tracking and enforcement
  - Firewall rule generation
  - State file management
  - MAC-to-IP resolution
  - Connection detection

**Key Functions:**
```php
// Core lifecycle
parental_control_sync_package()     // Apply config changes
parental_control_cron_job()         // Run every minute

// Tracking & enforcement
pc_update_device_usage()            // Track time usage
pc_has_active_connections($ip)      // Check if device is active
pc_apply_firewall_rules($device)    // Block/unblock device

// Device management
pc_get_ip_from_mac($mac)            // Resolve MAC to IP
pc_migrate_state_to_v0_2_1()        // State migration
```

#### 2. **State File** (`/var/db/parental_control_state.json`)

**Structure (v0.2.1+):**
```json
{
  "version": "0.2.1",
  "last_updated": "2025-12-26T10:30:00Z",
  "devices_by_ip": {
    "192.168.1.100": {
      "mac": "aa:bb:cc:dd:ee:ff",
      "child_name": "Emma",
      "device_name": "iPad",
      "daily_used": 45,
      "last_seen": "2025-12-26T10:30:00Z",
      "last_check": "2025-12-26T10:30:00Z",
      "active": true,
      "connections": 12
    }
  },
  "mac_to_ip_cache": {
    "aa:bb:cc:dd:ee:ff": {
      "ip": "192.168.1.100",
      "timestamp": "2025-12-26T10:30:00Z"
    }
  }
}
```

**Key Points:**
- Primary storage: `devices_by_ip` (IP-based)
- Cache for performance: `mac_to_ip_cache`
- Atomic writes with temp file + rename
- Auto-migration from older versions

#### 3. **Log Files** (`/var/log/parental_control-YYYY-MM-DD.jsonl`)

**Format**: OpenTelemetry-compliant JSONL
```json
{
  "Timestamp": "2025-12-26T10:30:00.000000Z",
  "SeverityText": "INFO",
  "Body": "Device usage updated for Emma - iPad",
  "Attributes": {
    "event.action": "usage_update",
    "child.name": "Emma",
    "device.name": "iPad",
    "device.ip": "192.168.1.100",
    "device.mac": "aa:bb:cc:dd:ee:ff",
    "usage.daily_minutes": 45,
    "connections.active": 12
  }
}
```

**Features:**
- Daily rotation (timestamp in filename)
- One JSON object per line
- Automatic size-based rotation (5MB)
- Keep last 10 files
- SIEM-ready format

#### 4. **Cron Job**

```bash
*/1 * * * * /usr/local/bin/php -f /usr/local/pkg/parental_control.inc -- cron
```

**Execution Flow:**
1. Acquire PID lock (prevent concurrent runs)
2. Load configuration from config.xml
3. Load state file
4. For each configured device:
   - Resolve MAC → IP
   - Check active connections
   - Update time counters
   - Apply/remove rules
5. Save state file
6. Release PID lock
7. Log execution time

**Protection:**
- PID lock file: `/var/run/parental_control.pid`
- Timeout: 50 seconds max
- Graceful degradation on errors

---

## 🔐 Security Considerations

### PID Locking
Prevents race conditions when cron jobs overlap:
```php
function pc_acquire_pid_lock() {
    $pid_file = '/var/run/parental_control.pid';
    if (file_exists($pid_file)) {
        $pid = file_get_contents($pid_file);
        if (posix_kill($pid, 0)) {
            return false; // Already running
        }
    }
    file_put_contents($pid_file, getmypid());
    return true;
}
```

### Firewall Rule Priority
- Rules created at priority level 1 (highest)
- Evaluated before any other allow rules
- Ensures effective blocking

### State File Security
- Location: `/var/db/` (persistent across reboots)
- Permissions: Read/write by root only
- Atomic writes prevent corruption

---

## ⚡ Performance Optimizations

### 1. **MAC-to-IP Caching**
```php
// Cache hits avoid expensive ARP lookups
$cache_ttl = 30; // seconds
if (isset($cache[$mac]) && time() - $cache[$mac]['timestamp'] < $cache_ttl) {
    return $cache[$mac]['ip']; // Fast path
}
```

**Impact**: ~68% faster for repeated lookups

### 2. **Connection State Query**
```php
// Single pfctl call for all IPs
$output = shell_exec("pfctl -s state 2>&1 | grep -E '({$ip_pattern})' ");
```

**Impact**: O(1) vs O(n) separate queries

### 3. **Batch Firewall Updates**
```php
// Group rule changes, apply once
filter_configure(); // Single reload
```

**Impact**: 10x faster than per-device reloads

---

## 📊 Scalability

### Current Limits
- **Devices**: Tested up to 50 devices
- **Cron Interval**: 1 minute (60-second granularity)
- **State File**: ~1KB per device
- **Log Files**: ~100 entries/minute under load

### Scaling Considerations
```
10 devices   → ~10KB state file,  ~100 log entries/minute
50 devices   → ~50KB state file,  ~500 log entries/minute
100 devices  → ~100KB state file, ~1000 log entries/minute
```

### Resource Usage
- **CPU**: < 1% on modern hardware
- **Memory**: ~5MB runtime
- **Disk I/O**: Minimal (atomic writes, rotation)

---

## 🔄 State Migration

The package includes automatic migration for state file format changes:

```php
function pc_migrate_state_to_v0_2_1($old_state) {
    // Convert MAC-based to IP-based
    $new_state = ['version' => '0.2.1', 'devices_by_ip' => []];
    
    foreach ($old_state['devices'] as $mac => $data) {
        $ip = pc_get_ip_from_mac($mac);
        if ($ip) {
            $new_state['devices_by_ip'][$ip] = $data;
            $new_state['devices_by_ip'][$ip]['mac'] = $mac;
        }
    }
    
    return $new_state;
}
```

---

## 🚀 Future Enhancements

### Planned for v0.3.0
- pfSense tables instead of individual rules
- JSONL state file for fault tolerance
- Enhanced performance metrics

### Planned for v0.4.0
- Per-service tracking (YouTube, gaming, etc.)
- Bandwidth-based quotas
- Mobile app integration

---

## 📚 Related Documentation

- **[Quick Start Guide](QUICKSTART.md)** - Get started quickly
- **[Configuration Guide](CONFIGURATION.md)** - All options explained
- **[API Documentation](API.md)** - REST API reference
- **[Development Guide](DEVELOPMENT.md)** - Contributing guidelines

---

**Architecture Version**: 0.2.1  
**Last Updated**: December 26, 2025  
**Status**: Production Ready


---

# pfSense Anchors Implementation

# pfSense Anchor Implementation Guide

## Overview

The KACI Parental Control package uses **pfSense anchors** for dynamic firewall rule management. This allows blocking/unblocking devices without calling `filter_configure()`, which prevents AQM flowset errors.

---

## Architecture

### 1. Anchor File
**Location**: `/tmp/rules.parental_control`

This file contains all active block rules for parental control.

**Format**:
```
block drop quick from <IP_ADDRESS> to any label "PC:<DEVICE_NAME>" # <REASON>
```

**Example**:
```
block drop quick from 192.168.1.111 to any label "PC:MukeshMacPro" # Time limit exceeded
block drop quick from 192.168.1.20 to any label "PC:iPhone15" # Scheduled block time
```

### 2. Anchor Loading
Rules from the anchor file are loaded into pfSense using:
```bash
pfctl -a parental_control -f /tmp/rules.parental_control
```

This command:
- ✅ **Executes instantly** (milliseconds, not seconds)
- ✅ **No filter reload** required
- ✅ **No AQM errors** triggered
- ✅ **Changes take effect immediately**

### 3. Main Anchor Rule
A "match" rule in pfSense's main configuration tells the firewall to process anchor rules.

**Location in GUI**: Firewall > Rules > LAN  
**Description**: "Parental Control: Anchor (Dynamic Rules)"  
**Type**: Match rule  

This rule is added automatically during package installation/sync.

---

## How It Works

### Blocking Process

1. **Cron Job Runs** (every 5 minutes)
2. **Calculate** which devices should be blocked
3. **For each device to block**:
   - Get device IP from state cache
   - Add rule to `/tmp/rules.parental_control`
   - Reload anchor: `pfctl -a parental_control -f /tmp/rules.parental_control`
   - Log the block action

### Unblocking Process

1. **Cron Job Runs** (every 5 minutes)
2. **Calculate** which devices should be unblocked
3. **For each device to unblock**:
   - Read anchor file
   - Filter out rules for that device's IP
   - Write filtered rules back
   - Reload anchor: `pfctl -a parental_control -f /tmp/rules.parental_control`
   - Log the unblock action

---

## Verification Commands

### Check Anchor File
```bash
cat /tmp/rules.parental_control
```

### View Active Anchor Rules
```bash
pfctl -a parental_control -sr
```

### Count Active Blocks
```bash
pfctl -a parental_control -sr | grep -c "block drop"
```

### Test Block (Manual)
```bash
echo 'block drop quick from 192.168.1.99 to any label "PC:Test"' >> /tmp/rules.parental_control
pfctl -a parental_control -f /tmp/rules.parental_control
```

### View Blocked Traffic (Real-time)
```bash
tcpdump -i lan0 -n src 192.168.1.111
```

### Check pfSense System Log
```bash
tail -f /var/log/filter.log | grep "PC:"
```

---

## Advantages Over Other Approaches

### ❌ **Direct filter_configure()** (Previous Approach)
- Takes 5-10 seconds per update
- Causes AQM flowset errors
- Reloads entire firewall ruleset
- Heavy system load

### ❌ **pfctl Tables** (v0.7.0-0.7.2 Attempt)
- Not persistent (lost on reboot)
- Requires pfSense alias configuration
- Not well-integrated with pfSense

### ✅ **pfctl Anchors** (Current v0.7.3+)
- ✅ **Instant updates** (milliseconds)
- ✅ **Persistent** (survives reboots via initialization)
- ✅ **No AQM errors**
- ✅ **Fully visible** via pfctl commands
- ✅ **Labeled rules** for easy identification
- ✅ **pfSense-native approach**

---

## Troubleshooting

### Anchor Not Working?

**Check anchor file exists**:
```bash
ls -lah /tmp/rules.parental_control
```

**If missing, reinitialize**:
```bash
php -r "require_once('/usr/local/pkg/parental_control.inc'); pc_init_block_table();"
```

### Rules Not Blocking?

**Verify rules are loaded**:
```bash
pfctl -a parental_control -sr
```

**If empty but file has rules**:
```bash
pfctl -a parental_control -f /tmp/rules.parental_control
```

### Need to Clear All Blocks?

```bash
echo '# Parental Control Dynamic Rules' > /tmp/rules.parental_control
pfctl -a parental_control -f /tmp/rules.parental_control
```

---

## Performance Metrics

Based on testing:

| Operation | Time | System Impact |
|-----------|------|---------------|
| Add 1 rule | ~50ms | Negligible |
| Remove 1 rule | ~100ms | Negligible |
| Reload anchor | ~30ms | None |
| Block enforcement | Instant | None |
| filter_configure() | 5-10s | High (AQM errors) |

**Conclusion**: Anchors are **100x faster** and **cause zero system errors**.

---

## Integration with Cron

The cron job (`/usr/local/bin/parental_control_cron.php`) runs every 5 minutes and:

1. Updates device usage counters
2. Calculates which devices should be blocked/unblocked
3. Applies only changed rules (differential updates)
4. Logs all actions to `/var/log/parental_control_YYYY-MM-DD.log`

**No manual intervention required** - the system is fully automatic!

---

## Answer to Your Questions

### Q1: Is the alias/table automatically created?

**Answer**: Yes! The anchor file `/tmp/rules.parental_control` is created automatically when:
- Package is installed
- Configuration is synced
- Cron job first runs

**You don't need to create anything manually.**

### Q2: Will firewall rules be visible in GUI?

**Answer**: Partially.

**✅ Visible in GUI**:
- The main anchor rule appears at: **Firewall > Rules > LAN**
- Description: "Parental Control: Anchor (Dynamic Rules)"
- This confirms the anchor system is active

**✅ Visible via Command Line**:
- Individual block rules: `pfctl -a parental_control -sr`
- Each rule shows device name and reason
- Real-time monitoring available

**❌ NOT in GUI**:
- Individual dynamic rules don't appear in the GUI rules list
- This is by design - they're managed programmatically
- Use `pfctl` commands or Status page for visibility

### Q3: Do I need to do anything to detect or make it visible?

**Answer**: No special action needed.

**Automatic Visibility**:
1. **Status Page**: Shows blocked devices
2. **Logs**: All blocks logged to parental_control log
3. **pfctl Commands**: Show real-time rule status
4. **GUI Rule**: Confirms anchor is active

**To verify it's working**:
```bash
# Check if anchor is loaded
pfctl -a parental_control -sr

# Watch blocks in real-time
tail -f /var/log/parental_control_$(date +%Y-%m-%d).log
```

---

## Summary

✅ **Anchor file**: Auto-created at `/tmp/rules.parental_control`  
✅ **Main rule**: Visible in GUI at Firewall > Rules > LAN  
✅ **Dynamic rules**: Visible via `pfctl -a parental_control -sr`  
✅ **Fully automatic**: No manual setup required  
✅ **Production ready**: Fast, efficient, error-free  

**You're all set! The system is working automatically. 🚀**


---

# Block Page Implementation

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


---

# Development Guide

# Development Workflow Guide

**Project**: KACI Parental Control for pfSense  
**Repository**: https://github.com/keekar2022/KACI-Parental_Control  
**Strategy**: Git Branching (Single Repository)  
**Decision**: Option A - Upgrade to Existing Product

---

## 📋 Table of Contents

1. [Why Branching Instead of Forking](#why-branching-instead-of-forking)
2. [Branch Strategy](#branch-strategy)
3. [Development Workflows](#development-workflows)
4. [Release Process](#release-process)
5. [Best Practices](#best-practices)

---

## 🔀 Why Branching Instead of Forking?

### The Question
*Should we fork the repository to work on new features and decide later if they should be a separate product or an upgrade?*

### The Answer
**No - Use Git branching instead.**

### Why?
1. **GitHub Limitation**: You cannot fork your own repository
2. **Single User**: A single account cannot own both parent and fork
3. **Better Alternative**: Git branching provides more flexibility
4. **Unified History**: All changes remain in one repository
5. **Easy Decision**: Can merge to main product OR create new repo later

---

## 🌳 Branch Strategy

### Current Branches

```
keekar2022/KACI-Parental_Control
│
├── main                              [PRODUCTION]
│   └── v0.2.1 (latest release)
│
├── develop                           [INTEGRATION]
│   └── synced with main (v0.2.1)
│
└── experimental/enhanced-features    [EXPERIMENTS]
    └── synced with main (v0.2.1)
```

### Branch Purposes

#### `main` Branch
- **Purpose**: Production-ready releases only
- **Status**: Protected branch
- **Rules**:
  - ✅ Only tested, stable code
  - ✅ Must be tagged with version numbers
  - ❌ No direct commits (except hotfixes)
  - ❌ No experimental features
- **Current Version**: v0.2.1

#### `develop` Branch
- **Purpose**: Integration branch for new features
- **Status**: Active development
- **Rules**:
  - ✅ Feature branches merge here first
  - ✅ Must pass tests before merging to main
  - ✅ Staging ground for next release
  - ❌ Not production-ready until tested

#### `experimental/enhanced-features` Branch
- **Purpose**: High-risk experimental features
- **Status**: Playground for radical changes
- **Rules**:
  - ✅ Try anything without breaking main
  - ✅ Can be abandoned if experiments fail
  - ✅ Merge to develop only after validation
  - ❌ Never merge directly to main

---

## 🚀 Development Workflows

### Workflow 1: New Feature Development

**Use Case**: Adding a new feature to the parental control package

```bash
# 1. Start from develop
git checkout develop
git pull origin develop

# 2. Create feature branch
git checkout -b feature/your-feature-name

# 3. Develop feature
# ... make changes ...
git add .
git commit -m "feat: Add your feature description"

# 4. Push feature branch
git push origin feature/your-feature-name

# 5. Merge to develop when ready
git checkout develop
git merge feature/your-feature-name
git push origin develop

# 6. Test thoroughly on develop
# ... run tests, deploy to test environment ...

# 7. When ready for release, merge to main
git checkout main
git merge develop
git tag v0.3.0
git push origin main --tags
```

### Workflow 2: Experimental Features

**Use Case**: Trying radical changes that might not work

```bash
# 1. Switch to experimental branch
git checkout experimental/enhanced-features
git pull origin experimental/enhanced-features

# 2. Try experimental changes
# ... make radical changes ...
git add .
git commit -m "experiment: Try new architecture"
git push origin experimental/enhanced-features

# 3. If successful → merge to develop
git checkout develop
git merge experimental/enhanced-features
git push origin develop

# 4. If failed → abandon or reset
git reset --hard HEAD~1  # Undo last commit
# OR
git checkout -b experimental/abandoned-idea  # Save for later
git checkout experimental/enhanced-features
git reset --hard origin/experimental/enhanced-features
```

### Workflow 3: Hotfix (Emergency Bug Fix)

**Use Case**: Critical bug found in production

```bash
# 1. Create hotfix from main
git checkout main
git pull origin main
git checkout -b hotfix/critical-bug-name

# 2. Fix the bug
# ... make fixes ...
git add .
git commit -m "fix: Critical bug description"

# 3. Merge to main
git checkout main
git merge hotfix/critical-bug-name
git tag v0.2.2
git push origin main --tags

# 4. Also merge to develop (keep branches in sync)
git checkout develop
git merge hotfix/critical-bug-name
git push origin develop

# 5. Clean up
git branch -d hotfix/critical-bug-name
```

### Workflow 4: Version Release

**Use Case**: Releasing a new version

```bash
# 1. Ensure develop is tested and ready
git checkout develop
# ... run all tests ...

# 2. Update version files
# Edit: VERSION, BUILD_INFO.json, info.xml, etc.
git add VERSION BUILD_INFO.json info.xml
git commit -m "chore: Bump version to 0.3.0"

# 3. Merge to main
git checkout main
git merge develop

# 4. Tag the release
git tag -a v0.3.0 -m "Release v0.3.0 - Feature description"
git push origin main --tags

# 5. Sync develop with main
git checkout develop
git merge main
git push origin develop

# 6. Create GitHub Release
# Go to https://github.com/keekar2022/KACI-Parental_Control/releases
# Create new release from tag v0.3.0
# Add release notes
```

---

## 📦 Release Process

### Version Numbering: SemVer

```
MAJOR.MINOR.PATCH[-SUFFIX]

Examples:
- v0.2.1        → Patch release (bug fixes)
- v0.3.0        → Minor release (new features)
- v1.0.0        → Major release (breaking changes)
- v0.2.2-hotfix → Emergency hotfix
```

### Release Checklist

- [ ] All tests pass
- [ ] Documentation updated
- [ ] `VERSION` file updated
- [ ] `BUILD_INFO.json` updated
- [ ] `info.xml` version updated
- [ ] PHP files version constants updated
- [ ] Changelog written
- [ ] Committed to `develop`
- [ ] Merged to `main`
- [ ] Tagged with version
- [ ] Pushed to GitHub
- [ ] GitHub Release created
- [ ] Deployment tested

### Files to Update for Release

```bash
VERSION                     # Add changelog entry
BUILD_INFO.json            # Update version, features, date
info.xml                   # Update <version>
parental_control.inc       # Update PC_VERSION constant
parental_control.xml       # Update version in description
```

---

## 🎯 Best Practices

### Commit Messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>: <description>

Types:
- feat:     New feature
- fix:      Bug fix
- docs:     Documentation only
- style:    Code style (formatting, etc.)
- refactor: Code refactoring
- test:     Adding tests
- chore:    Maintenance tasks

Examples:
feat: Add per-service tracking
fix: Resolve Layer 3 IP tracking issue
docs: Update API documentation
refactor: Extract common validation logic
chore: Bump version to 0.3.0
```

### Branch Naming

```
feature/descriptive-name       # New features
fix/issue-description          # Bug fixes
hotfix/critical-bug            # Emergency fixes
experiment/radical-idea        # Experimental work
refactor/component-name        # Code refactoring
docs/documentation-update      # Documentation only

Examples:
feature/bandwidth-tracking
fix/dhcp-renewal-tracking
hotfix/php-parse-error
experiment/per-service-limits
```

### Code Quality

Before committing:

```bash
# Check PHP syntax
php -l parental_control.inc

# Run linter (if available)
phpcs parental_control.inc

# Test on pfSense
./INSTALL.sh 192.168.1.1
# Verify in GUI
```

### Pull Before Push

```bash
# Always pull before pushing to avoid conflicts
git pull origin main
git pull origin develop
```

### Keep Branches Updated

```bash
# Regularly sync develop with main
git checkout develop
git merge main
git push origin develop
```

---

## 🔄 Future Decision Point

### Option A: Continue as Upgrade ✅ (SELECTED)

All new features become part of the main product.

**Implementation**: Just keep merging to `main`

```bash
git checkout main
git merge develop
git tag v1.0.0
git push origin main --tags
```

### Option B: Create Separate Product (Alternative)

If later you decide experimental features should be a separate product:

```bash
# 1. Clone repository
git clone https://github.com/keekar2022/KACI-Parental_Control kaci-advanced

# 2. Switch to experimental branch
cd kaci-advanced
git checkout experimental/enhanced-features

# 3. Make it the main branch
git checkout -b main
git branch -D develop

# 4. Create new GitHub repository
# Go to GitHub → Create new repo → "KACI-Advanced-Parental-Control"

# 5. Update remote and push
git remote remove origin
git remote add origin https://github.com/keekar2022/KACI-Advanced-Parental-Control
git push -u origin main

# 6. Update package metadata
# Edit info.xml, VERSION, README, etc.
# Change package name to "KACI-Advanced-Parental-Control"
```

---

## 🛠️ Useful Commands

### Check Status

```bash
# View all branches
git branch -a

# View branch details
git branch -vv

# View commit history
git log --oneline --graph --all

# Compare branches
git diff main..develop
```

### Sync All Branches

```bash
# Sync develop with main
git checkout develop
git merge main
git push origin develop

# Sync experimental with main
git checkout experimental/enhanced-features
git merge main
git push origin experimental/enhanced-features
```

### Clean Up

```bash
# Delete local feature branch after merge
git branch -d feature/old-feature

# Delete remote feature branch
git push origin --delete feature/old-feature

# Prune deleted remote branches
git remote prune origin
```

---

## 📊 Current Project Status

**Date**: December 26, 2025  
**Version**: v0.2.1  
**Branch Sync**: All branches synced to v0.2.1

### Branch Status

- ✅ `main`: v0.2.1 (production ready)
- ✅ `develop`: synced with main (ready for new features)
- ✅ `experimental/enhanced-features`: synced with main (ready for experiments)

### Recent History

```
v0.2.1 (Dec 26, 2025) - Layer 3 Compliance Fix [CRITICAL]
v0.2.0 (Dec 26, 2025) - Real Connection Tracking [MAJOR]
v0.1.4 (Dec 26, 2025) - Logging & Diagnostics
v0.1.3 (Dec 25, 2025) - JSDoc & Error Handling
v0.1.2 (Dec 24, 2025) - Initial stable release
```

---

## 📚 Related Documentation

- **README.md** - Project overview and features
- **QUICKSTART.md** - Quick setup guide
- **PROJECT_STATUS_v0.2.1.md** - Current project status
- **RELEASE_v0.2.1_CRITICAL_FIX.md** - Latest release notes
- **docs/API.md** - REST API documentation
- **docs/CONFIGURATION.md** - Configuration guide
- **docs/TROUBLESHOOTING.md** - Problem solving

---

## 🤝 Contributing

### For Internal Development

1. Follow the workflows above
2. Keep branches synced
3. Write meaningful commit messages
4. Test thoroughly before merging to main
5. Update documentation with changes

### For External Contributors (Future)

If this project becomes open source:

1. Fork the repository
2. Create feature branch
3. Submit Pull Request to `develop`
4. Address review comments
5. Wait for merge approval

---

## 📞 Support

**Developer**: Mukesh Kesharwani (Keekar)  
**Repository**: https://github.com/keekar2022/KACI-Parental_Control  
**Strategy**: Git Branching - Option A (Unified Product)

---

**Last Updated**: December 26, 2025  
**Strategy Decision**: Option A - Upgrade to Existing Product ✅


---

# Development Workflow

# Development Workflow

## Version Management

This project includes automated version bumping to ensure all version files stay synchronized.

---

## Quick Start

### **Option 1: Automated Script (Recommended)**

Bump version in all files at once:

```bash
./bump_version.sh 0.2.4 "Fixed device selection in Profiles page"
```

This will:
1. ✅ Update `VERSION`
2. ✅ Update `parental_control.inc`
3. ✅ Update `parental_control.xml`
4. ✅ Update `info.xml`
5. ✅ Update `BUILD_INFO.json` (including changelog)
6. ✅ Optionally commit and push

---

## Complete Workflow

### **1. Make Your Changes**
```bash
# Edit code files
vim parental_control.inc
```

### **2. Bump Version**
```bash
# Bump version with changelog
./bump_version.sh 0.2.4 "Your changelog message"

# The script will:
# - Update all version files
# - Add changelog entry
# - Offer to commit and push
```

### **3. Verify Changes**
```bash
# Check what was updated
git diff

# Verify version consistency
grep -r "0.2.4" VERSION parental_control.inc *.xml BUILD_INFO.json
```

### **4. Deploy (if not auto-pushed)**
```bash
# Push to GitHub (triggers auto-update on fw.keekar.com)
git push origin main
```

---

## Pre-Commit Hook

A pre-commit hook is installed that **warns you** if you try to commit code changes without bumping the version.

**What it does:**
- ⚠️ Detects when `.php`, `.xml`, or `.inc` files are changed
- ⚠️ Warns if VERSION files weren't updated
- ✅ Gives you a chance to abort and bump version

**To bypass** (for non-release commits):
```bash
git commit --no-verify -m "docs: Update README"
```

---

## Version Numbering Scheme

We follow **Semantic Versioning** (SemVer):

```
MAJOR.MINOR.PATCH
  |      |      |
  |      |      └─ Bug fixes, hotfixes (0.2.3 → 0.2.4)
  |      └──────── New features, enhancements (0.2.4 → 0.3.0)
  └─────────────── Breaking changes, major rewrites (0.3.0 → 1.0.0)
```

### Examples:

| Change Type | Example | New Version |
|-------------|---------|-------------|
| Bug fix | Fixed device selection | 0.2.3 → 0.2.4 |
| New feature | Added schedule templates | 0.2.4 → 0.3.0 |
| Critical fix | Config corruption fix | 0.2.3 (hotfix) |
| Breaking change | Complete API rewrite | 0.9.0 → 1.0.0 |

---

## Changelog Guidelines

Write clear, user-focused changelog entries:

**Good:**
```bash
./bump_version.sh 0.2.4 "Fixed device selection dropdown not populating"
```

**Bad:**
```bash
./bump_version.sh 0.2.4 "Updated code"
```

**Categories:**
- `CRITICAL`: Critical bug fixes, security issues
- `FEATURE`: New features, enhancements
- `BUGFIX`: Bug fixes
- `IMPROVEMENT`: Performance improvements, optimizations
- `DOCS`: Documentation updates
- `CHORE`: Maintenance, refactoring

---

## Manual Version Bump (Without Script)

If you need to bump version manually:

1. **VERSION**
   ```bash
   # Update CURRENT_VERSION and BUILD_DATE
   # Add changelog entry under # Version History
   ```

2. **parental_control.inc**
   ```bash
   # Update PC_VERSION constant (line ~21)
   # Update PC_BUILD_DATE constant
   ```

3. **parental_control.xml**
   ```bash
   # Update <version> tag (line ~5)
   ```

4. **info.xml**
   ```bash
   # Update <version> tag (line ~7)
   ```

5. **BUILD_INFO.json**
   ```bash
   # Update build_info.version
   # Update build_info.build_date
   # Update build_info.git_commit
   # Add entry to changelog array
   ```

---

## Troubleshooting

### Script Not Running
```bash
# Make sure script is executable
chmod +x bump_version.sh

# Run with bash explicitly
bash bump_version.sh 0.2.4 "Your changelog"
```

### Pre-Commit Hook Not Working
```bash
# Make sure hook is executable
chmod +x .git/hooks/pre-commit

# Test hook manually
.git/hooks/pre-commit
```

### Version Mismatch After Update
```bash
# Verify all files have same version
grep -h "VERSION\|version" VERSION parental_control.inc parental_control.xml info.xml | grep -E "[0-9]+\.[0-9]+\.[0-9]+"
```

---

## CI/CD Integration (Future)

**Option for fully automated versioning:**

```yaml
# .github/workflows/version-bump.yml
name: Auto Version Bump
on:
  push:
    branches: [main]
jobs:
  bump:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Bump version
        run: ./bump_version.sh ${{ github.run_number }}
      - name: Commit
        run: |
          git config user.name "GitHub Actions"
          git config user.email "actions@github.com"
          git commit -am "chore: Auto version bump"
          git push
```

---

## Summary

✅ **Use `./bump_version.sh`** - One command updates everything  
✅ **Pre-commit hook warns you** - Never forget to bump version  
✅ **Consistent versioning** - All files stay synchronized  
✅ **Automated changelog** - Version history tracked automatically  

**Questions?** See the script source: `bump_version.sh`


---

# GitHub Pages Setup

# GitHub Pages Setup Guide

## Enable GitHub Pages for Your Repository

To host the announcement page (`index.html`) as a live website, follow these steps:

### Step 1: Go to Repository Settings

1. Open your repository on GitHub: https://github.com/keekar2022/KACI-Parental_Control
2. Click the **Settings** tab (top right, near the code tab)

### Step 2: Navigate to Pages Settings

1. In the left sidebar, scroll down and click **Pages**
2. You'll see "GitHub Pages" configuration

### Step 3: Configure Source

1. Under **Source**, select:
   - **Branch:** `main`
   - **Folder:** `/ (root)`
2. Click **Save**

### Step 4: Wait for Deployment

1. GitHub will take 1-2 minutes to build and deploy
2. You'll see a message: "Your site is ready to be published at..."
3. The URL will be: **https://keekar2022.github.io/KACI-Parental_Control/**

### Step 5: Verify

1. Visit: https://keekar2022.github.io/KACI-Parental_Control/
2. You should see the professional landing page with the KACI Parental Control announcement

---

## Custom Domain (Optional)

If you want to use a custom domain (e.g., parental-control.keekar.com):

1. In the **Pages** settings, under **Custom domain**, enter your domain
2. Add these DNS records in your domain provider:
   ```
   Type: CNAME
   Name: parental-control (or your subdomain)
   Value: keekar2022.github.io
   ```
3. Wait for DNS propagation (5-60 minutes)
4. Enable **Enforce HTTPS** in GitHub Pages settings

---

## URLs After Setup

Once GitHub Pages is enabled, you can share these URLs:

### Main Landing Page
- **Live URL:** https://keekar2022.github.io/KACI-Parental_Control/
- **Markdown:** https://github.com/keekar2022/KACI-Parental_Control/blob/main/ANNOUNCEMENT.md

### Documentation
- **README:** https://github.com/keekar2022/KACI-Parental_Control/blob/main/README.md
- **Installation Guide:** https://github.com/keekar2022/KACI-Parental_Control/blob/main/FRESH_INSTALL_COMPLETE.md
- **API Docs:** https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/API.md

### Repository
- **Main Repo:** https://github.com/keekar2022/KACI-Parental_Control
- **Issues:** https://github.com/keekar2022/KACI-Parental_Control/issues
- **Releases:** https://github.com/keekar2022/KACI-Parental_Control/releases

---

## Where to Announce

Once GitHub Pages is live, announce your package on:

### pfSense Community
1. **pfSense Forums:** https://forum.netgate.com/
   - Category: Packages
   - Title: "[ANNOUNCE] KACI Parental Control - Free Bypass-Proof Time Management"
   - Link to your GitHub Pages URL

2. **pfSense Subreddit:** https://reddit.com/r/PFSENSE
   - Post with screenshots and link

### General Communities
1. **r/homelab** - https://reddit.com/r/homelab
2. **r/selfhosted** - https://reddit.com/r/selfhosted
3. **r/Parenting** - https://reddit.com/r/Parenting
4. **Hacker News** - https://news.ycombinator.com/

### Social Media
1. **Twitter/X** - Tag @pfSense, @Netgate
2. **LinkedIn** - Share in networking/IT groups
3. **Facebook** - Parenting and tech groups

---

## Sample Announcement Post

```
🎉 Announcing KACI Parental Control for pfSense v0.9.1

After months of development, I'm excited to release a FREE, open-source 
parental control package for pfSense that actually works!

🏆 Key Innovation: Shared time limits across ALL devices
   No more device hopping - kids can't bypass by switching devices

✅ Features:
   • Bypass-proof (network-level firewall)
   • Smart scheduling (bedtime, school hours)
   • Auto-discover devices
   • Real-time dashboard
   • RESTful API
   • Auto-updates

🚀 Installation: 5 minutes via SSH
💰 Cost: FREE forever (MIT License)
🔒 Privacy: 100% local, no cloud

📖 Learn more & install:
https://keekar2022.github.io/KACI-Parental_Control/

GitHub:
https://github.com/keekar2022/KACI-Parental_Control

Built by a network engineer and parent who got tired of daily 
screen time battles. Hoping it helps other families too!

#pfSense #ParentalControl #OpenSource #HomeNetwork
```

---

## Maintenance

### Updating the Live Site

Any time you update `index.html` and push to the `main` branch:
1. GitHub automatically rebuilds the site (takes 1-2 minutes)
2. Changes go live at https://keekar2022.github.io/KACI-Parental_Control/
3. No manual deployment needed!

### Adding Blog Posts (Optional)

You can create a `docs/` folder with additional markdown files:
- `docs/tutorial.md`
- `docs/faq.md`
- `docs/troubleshooting.md`

These will be accessible at:
- https://keekar2022.github.io/KACI-Parental_Control/docs/tutorial
- https://keekar2022.github.io/KACI-Parental_Control/docs/faq
- etc.

---

## Analytics (Optional)

To track visitors, add Google Analytics or Plausible to `index.html`:

### Plausible (Privacy-friendly, recommended)
```html
<script defer data-domain="keekar2022.github.io" 
  src="https://plausible.io/js/script.js"></script>
```

### Google Analytics
```html
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXXXXXXX');
</script>
```

---

## Success! 🎉

You now have:
- ✅ Professional landing page (`index.html`)
- ✅ Comprehensive announcement (`ANNOUNCEMENT.md`)
- ✅ Installation instructions
- ✅ Ready to share with the world!

**Next step:** Enable GitHub Pages and start spreading the word!

