# KACI Parental Control - Technical Reference

**Complete technical documentation for developers, integrators, and advanced users**

---

## 📑 Table of Contents

1. [API Documentation](#api-documentation)
2. [Gaming Detection System](#gaming-detection-system)
3. [Architecture](#architecture)
4. [pfSense Anchors Implementation](#pfsense-anchors-implementation)
5. [Block Page Implementation](#block-page-implementation)
6. [Development Guide](#development-guide)
7. [Development Workflow](#development-workflow)
8. [GitHub Pages Setup](#github-pages-setup)

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

**Last Updated:** 2026-01-18
**Package Version:** 1.4.x (Production)


---

# Gaming Detection System

**NEW in v1.4.71**

## Overview

The Gaming Detection System automatically identifies online gaming activity and enforces gaming-specific time limits. It combines three detection methods for comprehensive coverage:

1. **Port-Based Detection:** Identifies games by standard network ports (Minecraft: 25565, Steam: 27015-27030)
2. **Pattern-Based Detection:** Analyzes behavioral patterns (high connections + Discord + YouTube)
3. **IP List Detection:** Uses gaming platform IP ranges (future enhancement)

## Architecture

### Component Diagram

```
Cron Job (every 5 minutes)
    ↓
pc_is_gaming_enabled() ← Check master switch
    ↓ (if enabled)
pc_detect_and_track_gaming()
    ├─→ pc_detect_gaming_by_port() → Port scanning
    ├─→ pc_detect_gaming_by_pattern() → Behavioral analysis
    └─→ pc_identify_game_platform() → Platform identification
        ↓
    pc_update_gaming_usage() → Track usage (device + profile)
        ↓
    pc_enforce_gaming_limits()
        ├─→ pc_check_gaming_limits() → Check limits
        └─→ pc_add_device_block_table() → Block if exceeded
```

### Data Flow

1. **Detection Phase:**
   - Scan pfctl state table for gaming ports
   - Analyze device connection patterns
   - Assign confidence score (0-100%)
   - Identify game platform (Minecraft, Steam, etc.)

2. **Tracking Phase:**
   - Update device-level gaming_detection state
   - Update profile-level gaming_usage counters
   - Track per-platform and general gaming time

3. **Enforcement Phase:**
   - Check per-game limits (Minecraft: 60 min)
   - Check general gaming limit (all games: 120 min)
   - Block device if any limit exceeded
   - Log enforcement action

## API Functions

### Core Detection Functions

#### pc_is_gaming_enabled()

**Returns:** `bool` - True if gaming detection is enabled globally

**Usage:**
```php
if (pc_is_gaming_enabled()) {
    // Run gaming detection
}
```

#### pc_detect_gaming_by_port($device_ip, $all_states = null)

**Parameters:**
- `$device_ip` (string) - Device IP address
- `$all_states` (array, optional) - Pre-loaded pfctl state table

**Returns:** `array`
```php
[
    'detected' => bool,
    'platforms' => array('minecraft' => 'Minecraft', 'steam' => 'Steam'),
    'ports' => array(25565, 27015, 27016)
]
```

**Example:**
```php
$result = pc_detect_gaming_by_port('192.168.1.27');
if ($result['detected']) {
    echo "Gaming detected on ports: " . implode(', ', $result['ports']);
}
```

#### pc_detect_gaming_by_pattern(&$state, $device_ip)

**Parameters:**
- `$state` (array, reference) - State array
- `$device_ip` (string) - Device IP address

**Returns:** `array`
```php
[
    'detected' => bool,
    'confidence' => int (0-100),
    'indicators' => [
        'high_connections' => bool,
        'discord_active' => bool,
        'youtube_active' => bool,
        'sustained_session' => bool
    ],
    'matched_count' => int (0-4)
]
```

**Example:**
```php
$result = pc_detect_gaming_by_pattern($state, '192.168.1.27');
if ($result['detected'] && $result['confidence'] >= 75) {
    echo "Gaming pattern detected with {$result['confidence']}% confidence";
    echo "Indicators: " . $result['matched_count'] . " of 4 matched";
}
```

#### pc_identify_game_platform(&$state, $device_ip, $all_states = null)

**Parameters:**
- `$state` (array, reference) - State array
- `$device_ip` (string) - Device IP address
- `$all_states` (array, optional) - Pre-loaded pfctl state table

**Returns:** `array`
```php
[
    'platform' => 'minecraft|steam|roblox|epic|unknown|none',
    'platform_name' => 'Minecraft',
    'confidence' => int (0-100),
    'method' => 'port|pattern|ip|combined',
    'details' => array(
        'ports' => [25565] // for port detection
        // OR
        'indicators' => [...] // for pattern detection
    )
]
```

**Example:**
```php
$platform = pc_identify_game_platform($state, '192.168.1.27', $all_states);
if ($platform['platform'] !== 'none') {
    echo "Detected: {$platform['platform_name']} ({$platform['confidence']}%)";
    echo "Method: {$platform['method']}";
}
```

#### pc_update_gaming_usage(&$state, $device_ip, $game_platform, $confidence, $interval_minutes, $detection_method)

**Parameters:**
- `$state` (array, reference) - State array to update
- `$device_ip` (string) - Device IP address
- `$game_platform` (string) - Platform ID (minecraft, steam, etc.)
- `$confidence` (int) - Confidence score (0-100)
- `$interval_minutes` (int) - Minutes to add to usage
- `$detection_method` (string) - Method used (port, pattern, ip)

**Effect:** Updates state array with gaming usage data

**Example:**
```php
pc_update_gaming_usage($state, '192.168.1.27', 'minecraft', 99, 5, 'port');
// Adds 5 minutes to Minecraft usage for this device and profile
```

#### pc_check_gaming_limits($device, &$state)

**Parameters:**
- `$device` (array) - Device data from config
- `$state` (array, reference) - State array

**Returns:** `array`
```php
[
    'exceeded' => bool,
    'reason' => 'minecraft limit exceeded (75/60 minutes)',
    'limit_type' => 'platform|general',
    'platform' => 'minecraft' // if platform limit
]
```

**Example:**
```php
$limit_check = pc_check_gaming_limits($device, $state);
if ($limit_check['exceeded']) {
    echo "Block device: " . $limit_check['reason'];
}
```

## State Structure

### Device-Level Gaming Data

Added to `$state['devices_by_ip'][$ip]`:

```php
'gaming_detection' => [
    'detected_platform' => 'minecraft|steam|roblox|epic|unknown|none',
    'confidence_score' => 95, // 0-100
    'detection_method' => 'port|pattern|ip|combined',
    'session_start' => 1738305600, // Unix timestamp
    'usage_today' => 75, // minutes
    'usage_week' => 180, // minutes
    'last_detected' => 1738308000 // Unix timestamp
]
```

### Profile-Level Gaming Data

Added to `$state['profiles'][$profile_name]`:

```php
'gaming_usage' => [
    'general' => [
        'usage_today' => 120, // All games combined
        'usage_week' => 360
    ],
    'minecraft' => [
        'usage_today' => 60,
        'usage_week' => 180,
        'last_detected' => 1738308000
    ],
    'steam' => [
        'usage_today' => 45,
        'usage_week' => 150,
        'last_detected' => 1738305600
    ],
    'roblox' => [...],
    'epic' => [...]
]
```

## Configuration Structure

Gaming detection configuration stored at:  
`config.xml → installedpackages/parentalcontrolgaming/config/0`

```php
[
    'enable' => 'on|off', // Master switch
    'detection_methods' => 'all|ports|patterns|iplists',
    'confidence_threshold' => 75, // 50-95
    
    // Pattern detection thresholds
    'pattern_high_connections' => 70,
    'pattern_discord_minutes' => 60,
    'pattern_youtube_minutes' => 60,
    'pattern_session_minutes' => 60,
    
    // Platform configurations
    'platforms' => [
        'minecraft' => [
            'name' => 'Minecraft',
            'enabled' => 'on',
            'ports' => '25565,19132',
            'detection_method' => 'both',
            'description' => 'Minecraft Java and Bedrock'
        ],
        'steam' => [...],
        'roblox' => [...],
        'epic' => [...]
    ]
]
```

## Detection Algorithms

### Port Detection Algorithm

```php
function pc_detect_gaming_by_port($device_ip, $all_states) {
    // 1. Load pfctl state table
    // 2. Filter for device IP
    // 3. Parse destination ports
    // 4. Match against configured gaming ports
    // 5. Return detected platforms with ports
}
```

**Time Complexity:** O(n × m) where n = device connections, m = gaming platforms  
**Accuracy:** 95-99% (very high for standard ports)

### Pattern Detection Algorithm

```php
function pc_detect_gaming_by_pattern(&$state, $device_ip) {
    // 1. Get device state
    // 2. Check 4 indicators:
    //    - Connection count >= 70
    //    - Discord usage >= 60 min
    //    - YouTube usage >= 60 min
    //    - Session duration >= 60 min
    // 3. Calculate confidence:
    //    - 4 matches = 90%
    //    - 3 matches = 75%
    //    - 2 matches = 50%
    // 4. Require 2+ matches for detection
}
```

**Based On:** Real-world Minecraft investigation (see `logs/GAMING_INVESTIGATION_2026-01-29.md`)  
**Accuracy:** 75-90% (good for CDN-proxied games)  
**False Positive Rate:** Low with 75% confidence threshold

### Platform Identification Algorithm

```php
function pc_identify_game_platform(&$state, $device_ip, $all_states) {
    // 1. Try port detection first (highest accuracy)
    //    - If port match: Return platform with 99% confidence
    // 2. If no port match, try pattern detection
    //    - Minecraft pattern: Discord + YouTube + High connections
    //    - Roblox pattern: High connections only
    //    - Unknown: Generic gaming pattern
    // 3. Return platform with confidence score
}
```

**Precedence:** Port detection > Pattern detection > No detection  
**Accuracy:** Port=99%, Minecraft pattern=85%, Roblox pattern=75%

## Integration Points

### Cron Job Integration

Gaming detection runs in the main cron job loop:

**File:** `parental_control.inc`, line ~5007

```php
// After bot detection
if (pc_is_gaming_enabled()) {
    pc_detect_and_track_gaming($state, $service_ips_cache, $all_states);
    pc_enforce_gaming_limits($state);
}
```

**Frequency:** Every 5 minutes (same as main cron)  
**Execution Time:** ~100-500ms (depending on device count)

### State File Integration

Gaming data stored in existing state file: `/var/db/parental_control_state.json`

**Auto-migrated:** Old state files automatically get gaming_detection fields

### Firewall Integration

Gaming enforcement uses existing blocking mechanism:
- `pc_add_device_block_table()` - Add device to block table
- `parental_control_blocked` pfSense table
- Blocks all internet when gaming limit exceeded

## Performance Characteristics

### Detection Performance

| Devices | Platforms | Detection Time | Memory Usage |
|---------|-----------|----------------|--------------|
| 5 | 4 | ~100ms | ~2MB |
| 10 | 4 | ~200ms | ~4MB |
| 20 | 4 | ~500ms | ~8MB |

**Optimization:** Pre-loads pfctl state table once per cron cycle (shared with service detection)

### Storage Requirements

**Per Device:** ~500 bytes (gaming_detection state)  
**Per Profile:** ~200 bytes per platform (gaming_usage)  
**Total (10 devices, 5 profiles, 4 platforms):** ~15KB

**State File Growth:** Minimal (< 1KB per week)

## Logging

### Gaming Detection Events

**Event:** `gaming_detected`
```json
{
  "@timestamp": "2026-01-31T09:30:00+11:00",
  "event.action": "gaming_detected",
  "device.name": "macbookpro",
  "client.address": "192.168.1.27",
  "user.name": "Vishesh",
  "gaming.platform": "minecraft",
  "gaming.confidence": 99,
  "gaming.method": "port",
  "usage.added": 5
}
```

**Event:** `gaming_blocked`
```json
{
  "@timestamp": "2026-01-31T10:15:00+11:00",
  "event.action": "gaming_blocked",
  "device.name": "macbookpro",
  "device.mac": "92:d7:c1:51:05:e1",
  "client.address": "192.168.1.27",
  "block.reason": "Gaming limit exceeded: minecraft limit exceeded (65/60 minutes)",
  "limit.type": "platform"
}
```

### Query Gaming Logs

```bash
# All gaming events
tail -f /var/log/parental_control-$(date +%Y-%m-%d).jsonl | grep gaming

# Gaming detections only
cat /var/log/parental_control-*.jsonl | jq 'select(.["event.action"] == "gaming_detected")'

# Gaming blocks only
cat /var/log/parental_control-*.jsonl | jq 'select(.["event.action"] == "gaming_blocked")'
```

## Configuration API

### Get Gaming Configuration

```php
$gaming_config = pc_get_gaming_config();
// Returns array with all gaming detection settings
```

### Check if Enabled

```php
if (pc_is_gaming_enabled()) {
    // Gaming detection is enabled
}
```

### Update Gaming Configuration

```php
$gaming_config = config_get_path('installedpackages/parentalcontrolgaming/config/0', []);
$gaming_config['enable'] = 'on';
$gaming_config['confidence_threshold'] = 80;
config_set_path('installedpackages/parentalcontrolgaming/config/0', $gaming_config);
write_config('Updated gaming detection settings');
```

## Extension Points

### Adding New Gaming Platforms

1. **Add platform to default_platforms** in `parental_control_gaming.php`:
```php
'valorant' => array(
    'name' => 'Valorant',
    'enabled' => 'on',
    'ports' => '8180-8190,7000-8000',
    'detection_method' => 'port',
    'description' => 'Valorant competitive FPS'
)
```

2. **Configure detection:** Ports will be automatically checked

3. **Set limits:** Configure per-profile limits in Gaming Detection tab

### Custom Detection Logic

To add custom detection logic, extend `pc_identify_game_platform()`:

```php
// Check for custom indicators
if ($has_custom_pattern) {
    return array(
        'platform' => 'custom_game',
        'platform_name' => 'Custom Game',
        'confidence' => 85,
        'method' => 'custom'
    );
}
```

## Testing

### Manual Testing

```bash
# Check gaming detection status
cat /var/db/parental_control_state.json | \
  jq '.devices_by_ip[] | select(.gaming_detection.detected_platform != "none")'

# Check gaming usage by profile
cat /var/db/parental_control_state.json | \
  jq '.profiles.Vishesh.gaming_usage'

# Test port detection
pfctl -s state | grep "192.168.1.27" | grep "25565"
```

### Automated Testing

```bash
# Run cron job manually
/usr/local/bin/php /usr/local/bin/parental_control_cron.php

# Check logs for gaming detection
tail -20 /var/log/parental_control-$(date +%Y-%m-%d).jsonl | grep gaming
```

## Known Limitations

1. **CDN-Proxied Games:** Games behind Cloudflare may not show standard ports (use pattern detection)
2. **Browser Games:** Hard to distinguish from normal browsing (pattern detection helps)
3. **Dynamic Ports:** Some games use random ports (may need IP list detection)
4. **VPN Gaming:** Gaming through VPN may not be detected (encrypt ed traffic)

## Future Enhancements

1. **Machine Learning:** Train model on gaming vs non-gaming patterns
2. **Automatic IP List Updates:** Fetch from GitHub repos (lord-alfred/ipranges)
3. **Domain-Based Detection:** Track DNS queries for game domains
4. **Gaming Achievements:** Track milestones and progress
5. **Parental Alerts:** Real-time notifications when gaming detected

---

# Architecture

# Architecture Overview

**KACI Parental Control for pfSense**  
**Layer 3 Network-Based Time Control System**

---

## 📦 System Requirements and Dependencies

### Required pfSense/FreeBSD Packages

KACI Parental Control requires the following packages to function properly:

#### 1. **sudo** (security/sudo)
- **Minimum Version:** 1.9.16p2
- **Purpose:** Allows delegation of privileges for shell commands, enabling the package to perform administrative tasks
- **Installation:** Automatically checked and offered for installation by INSTALL.sh
- **Verification:** `pkg info sudo`

#### 2. **cron** (sysutils/cron)
- **Minimum Version:** 0.3.8_6
- **Purpose:** Manages scheduled tasks for usage tracking and time limit enforcement
- **Installation:** Usually part of FreeBSD base system
- **Verification:** `which crontab && service cron status`

### Dependency Management

The INSTALL.sh script includes automatic dependency checking:

```bash
# Automatic check during installation
./INSTALL.sh <pfsense_ip>
# Output:
# ============================================
# Checking Package Dependencies
# ============================================
# 
# ℹ  Checking for sudo package...
# ✓ sudo is installed (version: 1.9.16p2)
# ℹ  Checking for cron service...
# ✓ cron is available
# ✓ All required dependencies are satisfied
```

**Features:**
- ✅ Automatic detection of missing packages
- ✅ Interactive prompt to install missing dependencies
- ✅ Uses pfSense's native `pkg` command
- ✅ Verifies successful installation
- ✅ Prevents installation if dependencies are not satisfied
- ✅ Provides manual installation instructions if needed

**Manual Installation** (if needed):
```bash
# SSH to pfSense
ssh admin@pfsense_ip

# Install sudo
pkg install -y sudo

# Verify cron
which crontab
service cron status
```

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

**Architecture Version**: 1.4.x  
**Last Updated**: January 18, 2026  
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

### Multi-File Router Capability

The captive portal server (`parental_control_captive.php`) acts as a **router script** that can serve multiple files:

**Supported Routes:**
- `/` - Default block page (shows device status and parent override)
- `/index.html` - Project landing page (if deployed)
- `/*.css`, `/*.js`, `/*.png` - Static files (CSS, JavaScript, images)

**Router Logic:**
```php
// Check requested URI
$request_uri = $_SERVER['REQUEST_URI'];
$request_path = parse_url($request_uri, PHP_URL_PATH);

// Route to index.html
if ($request_path === '/index.html' || $request_path === '/index') {
    readfile('/usr/local/www/index.html');
    exit;
}

// Route to static files
if (preg_match('/\.(css|js|png|jpg)$/i', $request_path)) {
    readfile('/usr/local/www' . $request_path);
    exit;
}

// Default: Serve block page
// ... block page rendering ...
```

**Benefits:**
- ✅ Single server (port 1008) serves multiple files
- ✅ No authentication required for any route
- ✅ Easy to extend with additional routes
- ✅ Perfect for blocked devices

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

6. **Cross-Links** (if index.html is deployed)
   - Top banner: "Learn more about this project: View Project Info"
   - Footer link: "📖 About This Project"
   - Both link to `/index.html` without authentication

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
- **Current Version**: v1.4.x

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
- **docs/USER_GUIDE.md** - Latest release notes and changelog
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
💰 Cost: FREE forever (GPL 3.0 or later)
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

# 🎯 Shared Profile Time Accounting - v1.1.0

## 🚨 CRITICAL FIX: Bypass-Proof Time Limits

**Release Date:** December 29, 2025  
**Version:** 1.1.0  
**Severity:** CRITICAL - Security/Bypass Fix

---

## 💡 The Problem You Identified

> "When I allocated 4 Hrs to Vishesh Profile, I meant to say that cumulative of all devices it should be 4 hrs. Not like 4Hrs for each devices in the profile."

**You were 100% correct!** The old implementation had a MAJOR flaw:

###Before v1.1.0 (BROKEN):
- **Vishesh Profile:** 5 devices × 4 hours = **20 hours/day total** ❌
- **Mukesh Profile:** 2 devices × 10 hours = **20 hours/day total** ❌
- **Anita Profile:** 3 devices × 6 hours = **18 hours/day total** ❌

**Children could bypass limits by switching between devices!**

---

## ✅ The Solution (v1.1.0)

### After v1.1.0 (FIXED):
- **Vishesh Profile:** 4 hours **TOTAL** across all 5 devices ✅
- **Mukesh Profile:** 10 hours **TOTAL** across all 2 devices ✅
- **Anita Profile:** 6 hours **TOTAL** across all 3 devices ✅

**Truly bypass-proof - usage is SHARED across all devices!**

---

## 📊 Real-World Example

### Vishesh Profile (4 hour daily limit):

**Scenario:**
1. Uses iPhone for 1 hour → Profile usage: 1:00
2. Switches to iPad for 2 hours → Profile usage: 3:00
3. Switches to MacBook for 1 hour → Profile usage: 4:00
4. **ALL 5 devices now BLOCKED** (limit reached)

**Old behavior (v1.0.x):**
- Each device would get 4 hours = 20 hours total! ❌

**New behavior (v1.1.0):**
- All devices share 4 hours = 4 hours total! ✅

---

## 🔧 Technical Changes

### 1. Profile-Level Usage Tracking

**New State Structure:**
```json
{
  "profiles": {
    "Vishesh": {
      "usage_today": 135,
      "usage_week": 890,
      "last_reset": 1766950647
    },
    "Mukesh": {
      "usage_today": 245,
      "usage_week": 1520,
      "last_reset": 1766950647
    }
  }
}
```

### 2. Modified Functions

#### `pc_update_device_usage()`
```php
// OLD: Added time to device counter
$state['devices_by_ip'][$ip]['usage_today'] += $interval_minutes;

// NEW: Also adds time to PROFILE counter
$state['profiles'][$profile_name]['usage_today'] += $interval_minutes;
```

#### `pc_is_time_limit_exceeded()`
```php
// OLD: Checked device usage
$usage_today = $state['devices'][$mac]['usage_today'];

// NEW: Checks PROFILE usage (shared)
$usage_today = $state['profiles'][$profile_name]['usage_today'];
```

#### `pc_reset_daily_counters()`
```php
// NEW: Also resets profile counters at midnight
foreach ($state['profiles'] as $profile_name => &$profile_state) {
    $profile_state['usage_today'] = 0;
}
```

### 3. Status Page Updates

**Now shows SHARED profile usage:**
- All devices in same profile show the SAME usage value
- "Usage Today" displays profile total, not individual device time
- "Remaining" calculates from profile usage

---

## 📅 Current Status (Your Firewall)

```
Profile          Devices    Daily Limit    Current Usage    Shared?
─────────────────────────────────────────────────────────────────────
Vishesh          5          4:00          0:00             ✅ YES
Mukesh           2         10:00          0:00             ✅ YES
Anita            3          6:00          0:00             ✅ YES
```

**All counters reset to 0:00 after v1.1.0 deployment.**

---

## 🔄 How It Works Now

### Usage Accumulation:
1. **Any device** in a profile is active
2. Usage adds to the **PROFILE counter** (not device)
3. All devices check against the **same profile usage**
4. When limit reached, **ALL devices** in profile are blocked

### Example Timeline (Vishesh - 4hr limit):

| Time  | Device      | Activity       | Profile Usage | Status |
|-------|-------------|----------------|---------------|---------|
| 08:00 | iPhone      | Browsing (1hr) | 1:00          | ✅ Active |
| 09:00 | iPad        | Gaming (1.5hr) | 2:30          | ✅ Active |
| 10:30 | MacBook     | Homework (1hr) | 3:30          | ✅ Active |
| 11:30 | TV          | Streaming (30m)| 4:00          | ⚠️ Limit! |
| 12:00 | **ALL 5**   | **BLOCKED**    | 4:00          | 🚫 Blocked |

---

## 🎯 Benefits

### For Parents:
- ✅ **True Control:** 4 hours means 4 hours, not 20!
- ✅ **Bypass-Proof:** Can't switch devices to get more time
- ✅ **Fair:** All devices share the same budget
- ✅ **Predictable:** Know exactly how much time is available

### For Children:
- ✅ **Flexible:** Choose which device to use
- ✅ **Fair:** Can't hog time on one device
- ✅ **Transparent:** See total time remaining
- ✅ **Consistent:** Same rules across all devices

### For System:
- ✅ **Secure:** No bypass vulnerability
- ✅ **Efficient:** Single counter to track
- ✅ **Reliable:** No sync issues between devices
- ✅ **Scalable:** Works with any number of devices

---

## 🚀 Testing & Verification

### 1. Check Status Page
Navigate to: **Services → KACI Parental Control → Status**

**What you should see:**
- All devices in same profile show **SAME** usage
- Example: If Vishesh uses iPhone for 30min, ALL 5 devices show 0:30

### 2. Test Multi-Device Usage
1. Use device A for 1 hour
2. Check device B's remaining time
3. **Should show:** 3:00 remaining (not 4:00!)

### 3. Verify Blocking
1. Use profile time until limit reached
2. **ALL devices** in profile should be blocked
3. **Other profiles** should still work

---

## 📊 Expected Behavior

### Vishesh Profile (4:00 limit):
- Device 1: 1:00 used → Profile: 1:00, Remaining: 3:00
- Device 2: 1:30 used → Profile: 2:30, Remaining: 1:30
- Device 3: 1:00 used → Profile: 3:30, Remaining: 0:30
- Device 4: 0:30 used → Profile: 4:00, **ALL BLOCKED** ✅

### Mukesh Profile (10:00 limit):
- Currently 30 mins used across devices
- Profile shows: 0:30 used, 9:30 remaining
- Shared across MacBook Pro + iPhone

### Anita Profile (6:00 limit):
- Currently 0 mins used
- Profile shows: 0:00 used, 6:00 remaining
- Shared across iPhone + iPad + other device

---

## 🔄 Migration Notes

### Automatic Migration:
- ✅ **No config changes needed**
- ✅ **All counters start at 0:00**
- ✅ **Works immediately after upgrade**
- ✅ **No data loss**

### What Changed:
- Usage now accumulates at profile level
- Blocking affects all devices in profile
- Status page shows shared usage

### What Stayed Same:
- Profile limits (4hrs, 10hrs, 6hrs)
- Weekend bonuses
- Schedule blocking
- Device management

---

## 📝 Frequently Asked Questions

### Q: Why did usage drop to 0:00?
**A:** Counters were reset during v1.1.0 deployment for clean start with new shared accounting system.

### Q: Will devices share time across different days?
**A:** No! Counters reset at midnight daily (as before).

### Q: Can I still set different limits per profile?
**A:** Yes! Each profile has its own limit, but devices IN that profile share it.

### Q: What if device changes profiles?
**A:** Usage tracks to the profile it's assigned to. Moving devices doesn't transfer usage.

### Q: Does this affect schedules?
**A:** No. Schedule blocking still works independently per device.

---

## 🎉 Success Criteria

✅ **All profiles showing 0:00 usage after reset**  
✅ **v1.1.0 deployed successfully**  
✅ **Profile tracking structure created**  
✅ **Cron job active and running**  
✅ **Status page updated to show shared usage**

**Your system is now properly configured with bypass-proof shared profile time accounting!**

---

## 🔮 Next Steps

1. **Monitor:** Watch status page as devices are used
2. **Verify:** Confirm usage accumulates at profile level
3. **Test:** Try switching devices, verify shared counter
4. **Enjoy:** True parental control, finally bypass-proof!

---

**Built with ❤️ by Mukesh Kesharwani**  
**© 2025 Keekar**

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

# ✨ Automatic Version Management - v1.0.2

## 🎯 Problem Solved

**Before (v1.0.1 and earlier):**
- Version numbers hardcoded in multiple files
- Manual updates required in 6+ locations for each release
- Prone to inconsistencies and forgotten updates
- Fallback values that became outdated

**After (v1.0.2):**
- ✅ **Single Source of Truth**: VERSION file
- ✅ **Zero Manual Updates**: All pages read version automatically
- ✅ **Always Consistent**: All footers show same version
- ✅ **DRY Principle**: Define once, use everywhere

---

## 🔧 How It Works

### 1. VERSION File (Source of Truth)
```ini
VERSION=1.0.2
BUILD_DATE=2025-12-29
RELEASE_TYPE=feature
STATUS=production-ready
```

Located at: `/usr/local/pkg/parental_control_VERSION` on pfSense

### 2. Automatic Detection in parental_control.inc
```php
// Automatically read from VERSION file
if (!defined('PC_VERSION')) {
	$version_file = '/usr/local/pkg/parental_control_VERSION';
	if (file_exists($version_file)) {
		$version_data = parse_ini_file($version_file);
		define('PC_VERSION', $version_data['VERSION'] ?? '1.0.2');
		define('PC_BUILD_DATE', $version_data['BUILD_DATE'] ?? date('Y-m-d'));
	} else {
		// Fallback if VERSION file not deployed (should not happen)
		define('PC_VERSION', '1.0.2');
		define('PC_BUILD_DATE', '2025-12-29');
	}
}
```

### 3. PHP Pages Use PC_VERSION Directly
**Before:**
```php
v<?=defined('PC_VERSION') ? PC_VERSION : '1.0.1'?>
```

**After:**
```php
v<?=PC_VERSION?>
```

No fallback needed - `PC_VERSION` is guaranteed to be defined!

---

## 📦 Deployment

### Files Modified
1. **`parental_control.inc`** - Reads VERSION file on load
2. **`parental_control_status.php`** - Removed hardcoded fallback
3. **`parental_control_profiles.php`** - Removed hardcoded fallback
4. **`parental_control_schedules.php`** - Removed hardcoded fallback
5. **`parental_control_blocked.php`** - Removed hardcoded fallback
6. **`INSTALL.sh`** - Deploys VERSION file as `parental_control_VERSION`

### Installation Process
```bash
./INSTALL.sh update fw.keekar.com
```

The script:
1. Copies `VERSION` to `/tmp/VERSION`
2. Moves it to `/usr/local/pkg/parental_control_VERSION`
3. Sets permissions to 644 (readable by all)

---

## ✅ Verification

### Check VERSION File Exists
```bash
ssh mkesharw@fw.keekar.com 'cat /usr/local/pkg/parental_control_VERSION'
```

Expected output:
```
VERSION=1.0.2
BUILD_DATE=2025-12-29
RELEASE_TYPE=feature
STATUS=production-ready
```

### Check Footer Display
Navigate to any page in the web GUI:
- Services → KACI Parental Control → Status
- Services → KACI Parental Control → Profiles
- Services → KACI Parental Control → Schedules

Footer should show:
```
Keekar's Parental Control v1.0.2
Built with Passion by Mukesh Kesharwani | © 2025 Keekar
Build Date: 2025-12-29
```

---

## 🚀 Release Process (Simplified!)

### Old Way (v1.0.1 and earlier)
```bash
# Update 6+ files manually:
1. Edit VERSION
2. Edit info.xml
3. Edit parental_control.xml
4. Edit parental_control.inc (PC_VERSION)
5. Edit all PHP page fallbacks
6. Edit index.html (2 places)
7. Update CHANGELOG.md
8. Commit & deploy
```

### New Way (v1.0.2+)
```bash
# Update 4 files only:
1. Edit VERSION
2. Edit info.xml
3. Edit parental_control.xml
4. Edit index.html (2 places)
5. Update CHANGELOG.md
6. Commit & deploy

# PHP pages update automatically! 🎉
```

---

## 📊 Files That Update Automatically

| Page | Footer Version | Source |
|------|----------------|--------|
| Status | ✅ Auto | PC_VERSION → VERSION file |
| Profiles | ✅ Auto | PC_VERSION → VERSION file |
| Schedules | ✅ Auto | PC_VERSION → VERSION file |
| Block Page | ✅ Auto | PC_VERSION → VERSION file |
| API | ✅ Auto | PC_VERSION → VERSION file |
| Health Check | ✅ Auto | PC_VERSION → VERSION file |

---

## 🎯 Benefits

### For Developers
- ✅ **Less Work**: Update 1 file instead of 6+
- ✅ **No Mistakes**: Can't forget to update a file
- ✅ **Faster Releases**: Fewer files to modify
- ✅ **Clean Code**: No hardcoded values

### For Users
- ✅ **Consistency**: All pages show same version
- ✅ **Reliability**: Version always accurate
- ✅ **Transparency**: Clear what version they're running

### For Maintenance
- ✅ **DRY Principle**: Define once, use everywhere
- ✅ **Scalability**: Easy to add new pages
- ✅ **Testability**: Single point to verify
- ✅ **Documentation**: VERSION file is self-documenting

---

## 🔄 Backward Compatibility

### Fallback Behavior
If VERSION file doesn't exist (e.g., manual installation without INSTALL.sh):
- Falls back to `PC_VERSION = '1.0.2'`
- Falls back to `PC_BUILD_DATE = '2025-12-29'`
- System continues to function normally
- All pages still display version (the fallback value)

### Migration from v1.0.1
- **Automatic**: Run `./INSTALL.sh update`
- **No config changes needed**
- **No data loss**
- **Immediate effect**

---

## 📝 Example: Updating to v1.0.3

```bash
# 1. Edit VERSION file
echo "VERSION=1.0.3
BUILD_DATE=$(date +%Y-%m-%d)
RELEASE_TYPE=bugfix
STATUS=production-ready" > VERSION

# 2. Edit info.xml
sed -i '' 's/<version>1.0.2<\/version>/<version>1.0.3<\/version>/' info.xml

# 3. Edit parental_control.xml
sed -i '' 's/<version>1.0.2<\/version>/<version>1.0.3<\/version>/' parental_control.xml

# 4. Edit index.html
sed -i '' 's/Version 1.0.2/Version 1.0.3/' index.html
sed -i '' 's/1.0.2<\/div>/1.0.3<\/div>/' index.html

# 5. Update CHANGELOG.md
# (Add your changelog entry)

# 6. Commit
git add -A
git commit -m "Release v1.0.3"
git tag -a v1.0.3 -m "v1.0.3"
git push origin main --tags

# 7. Deploy
./INSTALL.sh update fw.keekar.com

# DONE! All PHP pages now show v1.0.3 automatically! 🎉
```

---

## 🎉 Result

All PHP pages in your pfSense firewall **automatically display the correct version** from the VERSION file!

**No more hardcoded version numbers!**  
**No more manual footer updates!**  
**Just works!** ✨

---

**Built with ❤️ by Mukesh Kesharwani**  
**© 2025 Keekar**

# Script Consolidation - Version 1.4.2

## Overview

Consolidated 8 shell scripts into 4 main scripts for easier maintenance and better organization.

## Before (8 Scripts)

1. ✅ **INSTALL.sh** - Installation script
2. ✅ **UNINSTALL.sh** - Uninstallation script
3. ✅ **auto_update_parental_control.sh** - Auto-update functionality
4. ✅ **parental_control_analyzer.sh** - Log analysis
5. ❌ **diagnose_reset.sh** - Reset diagnostic (CONSOLIDATED)
6. ❌ **verify_files.sh** - Installation verification (CONSOLIDATED)
7. ✅ **bump_version.sh** - Version bumping (KEPT - development tool)
8. ✅ **parental_control_captive.sh** - RC script for captive portal

## After (5 Core Scripts + 2 Dev Tools)

### 1. INSTALL.sh
**Purpose**: Installation, updates, and deployment

**Features**:
- Fresh installation
- Update existing installation
- Verify mode (calls analyzer)
- Auto-update setup
- File deployment
- Configuration registration

**Usage**:
```bash
./INSTALL.sh              # Interactive installation
./INSTALL.sh install      # Fresh install
./INSTALL.sh update       # Update files
./INSTALL.sh verify       # Verify installation
```

### 2. UNINSTALL.sh
**Purpose**: Complete package removal

**Features**:
- Removes all package files
- Removes firewall rules and aliases
- Removes port aliases (KACI_PC_Ports, KACI_PC_Web)
- Removes cron jobs
- Removes state and log files
- Removes configuration

**Usage**:
```bash
./UNINSTALL.sh           # Complete removal (prompts for confirmation)
```

### 3. auto_update_parental_control.sh
**Purpose**: Automatic updates from GitHub

**Features**:
- Checks GitHub for updates
- Downloads and deploys updates
- Logs all activities
- Can be run manually or via cron

**Usage**:
```bash
/usr/local/bin/auto_update_parental_control.sh
```

**Cron Schedule** (if enabled):
```
0 */8 * * * /usr/local/bin/auto_update_parental_control.sh
```

### 4. parental_control_analyzer.sh
**Purpose**: All-in-one diagnostic and management tool

**Features**:
- Log analysis and statistics
- Real-time log watching
- Device activity tracking
- System status checks
- **Reset diagnostic** (from diagnose_reset.sh)
- **Installation verification** (from verify_files.sh)

**Commands**:

#### Original Commands
```bash
parental_control_analyzer.sh stats         # Show statistics
parental_control_analyzer.sh stats-all     # All logs stats
parental_control_analyzer.sh recent 50     # Last 50 entries
parental_control_analyzer.sh device MAC    # Device activity
parental_control_analyzer.sh errors        # Show errors
parental_control_analyzer.sh watch         # Real-time logs
parental_control_analyzer.sh state         # Show state file
parental_control_analyzer.sh status        # System status
```

#### New Commands (Consolidated)
```bash
parental_control_analyzer.sh reset         # Reset diagnostic (was diagnose_reset.sh)
parental_control_analyzer.sh verify        # Verify installation (was verify_files.sh)
```

### 5. parental_control_captive.sh (RC Script)
**Purpose**: FreeBSD RC script for captive portal server

**Location**: `/usr/local/etc/rc.d/parental_control_captive`

**Features**:
- Starts/stops captive portal server
- Runs PHP built-in server on port 1008
- Serves block page without authentication
- Managed by FreeBSD RC system

**Usage**:
```bash
service parental_control_captive start
service parental_control_captive stop
service parental_control_captive restart
service parental_control_captive status
```

**Note**: This is now properly deployed by INSTALL.sh to `/usr/local/etc/rc.d/`

## Consolidated Functionality

### Reset Diagnostic (diagnose_reset.sh → analyzer)

**Old Way**:
```bash
ssh root@pfsense < diagnose_reset.sh
```

**New Way**:
```bash
ssh root@pfsense 'parental_control_analyzer.sh reset'
```

**Features**:
- Shows current system time
- Displays last reset time
- Shows profile usage counters
- Checks reset logic
- Shows cron execution history
- **Interactive reset** - prompts before forcing reset
- Verifies reset completed successfully

### Installation Verification (verify_files.sh → analyzer)

**Old Way**:
```bash
ssh root@pfsense '/usr/local/bin/verify_files.sh'
```

**New Way**:
```bash
ssh root@pfsense 'parental_control_analyzer.sh verify'
```

**Checks**:
- ✓ Core package files (4 files)
- ✓ Web interface files (7 files)
- ✓ Executable scripts (4 files)
- ✓ Cron jobs
- ✓ Package version
- ✓ Configuration in config.xml
- ✓ Firewall aliases (parental_control_blocked, KACI_PC_Ports, KACI_PC_Web)
- ✓ Firewall rules
- ✓ State and log files

**Color-coded output**:
- ✓ Green: Success
- ✗ Red: Error/Missing
- ⚠ Yellow: Warning/Optional

### Version Bumping (bump_version.sh → **KEPT SEPARATE**)

**Status**: ✅ **RESTORED and ENHANCED**

**Reason for Keeping**: 
- Development tool, not a core package script
- Not deployed to pfSense (development-only)
- Essential for release management
- **Now includes INSTALL/UNINSTALL synchronization validation**

**Enhanced Features**:
```bash
./bump_version.sh 1.4.2 "Your changelog"

# Automatically:
# 1. Updates VERSION, info.xml, BUILD_INFO.json, etc.
# 2. Adds changelog entry
# 3. Runs validate_cleanup.sh to check sync
# 4. Prompts for commit
# 5. Prompts for push to GitHub
```

**New Validation Script**: `validate_cleanup.sh`
- Ensures INSTALL.sh and UNINSTALL.sh are synchronized
- Prevents leftover files after uninstall
- Automatically run by bump_version.sh before release
- Can be run manually anytime

See `docs/DEVELOPMENT_TOOLS.md` for complete documentation.

## File Structure

### Before
```
KACI-Parental_Control/
├── INSTALL.sh
├── UNINSTALL.sh
├── auto_update_parental_control.sh
├── parental_control_analyzer.sh
├── diagnose_reset.sh              ← Consolidated
├── verify_files.sh                ← Consolidated
├── bump_version.sh                ← Kept (dev tool)
├── parental_control_captive.sh
└── ...
```

### After
```
KACI-Parental_Control/
├── Core Package Scripts (Deployed to pfSense)
│   ├── INSTALL.sh                     ✅ Enhanced
│   ├── UNINSTALL.sh                   ✅ Updated  
│   ├── auto_update_parental_control.sh ✅ Kept
│   ├── parental_control_analyzer.sh   ✅ Enhanced (includes reset & verify)
│   └── parental_control_captive.sh    ✅ Properly deployed
│
├── Development Tools (NOT deployed)
│   ├── bump_version.sh                ✅ Enhanced with sync validation
│   └── validate_cleanup.sh            ✅ NEW - INSTALL/UNINSTALL sync checker
│
└── docs/                              ✅ All .md files organized here
    ├── DEVELOPMENT_TOOLS.md           ✅ NEW
    ├── BEST_PRACTICES_KACI.md
    └── ...
```

## Benefits

### 1. Simplified Maintenance
- ✅ Fewer files to track and update
- ✅ Consolidated functionality in logical places
- ✅ Easier to find and fix issues

### 2. Better User Experience
- ✅ One tool (`analyzer`) for all diagnostics
- ✅ Consistent command interface
- ✅ Less confusion about which script to use

### 3. Easier Deployment
- ✅ Fewer files to upload
- ✅ Simpler INSTALL.sh logic
- ✅ Reduced chance of missing files

### 4. Better Organization
- ✅ All documentation in `docs/` folder
- ✅ Clear separation of concerns
- ✅ RC script properly deployed to system location

## Migration Guide

### For Existing Users

If you have existing scripts referenced in documentation or automation:

#### Old Reset Command
```bash
# OLD
ssh root@pfsense < diagnose_reset.sh

# NEW
ssh root@pfsense 'parental_control_analyzer.sh reset'
```

#### Old Verify Command
```bash
# OLD
ssh root@pfsense '/usr/local/bin/verify_files.sh'

# NEW
ssh root@pfsense 'parental_control_analyzer.sh verify'
```

#### Old Version Bump
```bash
# OLD
./bump_version.sh 1.4.2 "New feature"

# NEW (Manual)
# Edit VERSION, info.xml, and BUILD_INFO.json manually
```

### For New Users

Simply use the 4 main scripts:

1. **Install**: `./INSTALL.sh`
2. **Analyze**: `parental_control_analyzer.sh [command]`
3. **Update**: `auto_update_parental_control.sh`
4. **Uninstall**: `./UNINSTALL.sh`

## Testing

### Verify Consolidation

```bash
# Check remaining scripts
ls -la *.sh
# Should show only 5 files:
# - INSTALL.sh
# - UNINSTALL.sh
# - auto_update_parental_control.sh
# - parental_control_analyzer.sh
# - parental_control_captive.sh

# Test analyzer reset command
ssh root@pfsense 'parental_control_analyzer.sh reset'

# Test analyzer verify command
ssh root@pfsense 'parental_control_analyzer.sh verify'

# Test captive portal deployment
ssh root@pfsense 'test -x /usr/local/etc/rc.d/parental_control_captive && echo "OK"'
```

## Documentation Updates

All references to removed scripts have been updated in:

- ✅ INSTALL.sh
- ✅ UNINSTALL.sh
- ✅ BUILD_INFO.json
- ✅ README.md (if needed)
- ✅ This document

## Backward Compatibility

### Breaking Changes
- ❌ `diagnose_reset.sh` no longer exists
- ❌ `verify_files.sh` no longer exists
- ❌ `bump_version.sh` no longer exists

### Migration Path
All functionality is preserved, just moved:
- ✅ Reset → `parental_control_analyzer.sh reset`
- ✅ Verify → `parental_control_analyzer.sh verify`
- ✅ Version bump → Manual or automated CI/CD

### No Impact On
- ✅ Package functionality
- ✅ Firewall rules
- ✅ Cron jobs
- ✅ Auto-updates
- ✅ Web interface
- ✅ API endpoints

## Version Information

**Version**: 1.4.x  
**Date**: 2026-01-18  
**Type**: Enhancement (Script Consolidation)  
**Status**: Production Ready  

## Summary

Successfully reduced script count from 8 to 4 main scripts while preserving all functionality:

- ✅ **Removed 3 scripts** (diagnose_reset.sh, verify_files.sh, bump_version.sh)
- ✅ **Enhanced analyzer** with reset and verify commands
- ✅ **Properly deploy captive.sh** as RC script
- ✅ **Updated INSTALL.sh** and UNINSTALL.sh
- ✅ **Organized documentation** in docs/ folder
- ✅ **Maintained backward compatibility** through command migration

---

**Package**: KACI-Parental_Control  
**Maintainer**: Mukesh Kesharwani  
**Repository**: https://github.com/keekar/KACI-Parental-Control

# Final Project Structure - v1.4.2

## ✅ Script Consolidation Complete

Successfully organized all shell scripts into clear categories with proper synchronization validation.

## Shell Scripts (7 Total)

### Core Package Scripts (5) - Deployed to pfSense

1. **INSTALL.sh** (42K)
   - Installation and deployment
   - Update functionality
   - Verify mode
   - Auto-update setup
   - **Location**: `/usr/local/` (various subdirs)

2. **UNINSTALL.sh** (6.2K)
   - Complete package removal
   - Removes all installed files
   - Cleans up aliases and rules
   - **Synchronized with INSTALL.sh**

3. **auto_update_parental_control.sh** (5.4K)
   - Automatic GitHub updates
   - Manual update capability
   - **Deployed to**: `/usr/local/bin/`

4. **parental_control_analyzer.sh** (25K) ⭐
   - Log analysis and statistics
   - Device activity tracking
   - System status checks
   - **reset** command (consolidated from diagnose_reset.sh)
   - **verify** command (consolidated from verify_files.sh)
   - **Deployed to**: `/usr/local/bin/`

5. **parental_control_captive.sh** (4.3K)
   - FreeBSD RC script
   - Manages captive portal server
   - **Deployed to**: `/usr/local/etc/rc.d/parental_control_captive`

### Development Tools (2) - NOT Deployed

6. **bump_version.sh** (4.7K) 🔧
   - Version management
   - Changelog updates
   - **Automatically validates INSTALL/UNINSTALL sync**
   - Git commit and push automation
   - **Usage**: Development only

7. **validate_cleanup.sh** (6.6K) 🔧
   - INSTALL/UNINSTALL synchronization validator
   - Ensures no leftover files
   - Checks aliases, rules, configs
   - **Usage**: Development and QA

## Removed/Consolidated (2)

✅ **diagnose_reset.sh** → `parental_control_analyzer.sh reset`  
✅ **verify_files.sh** → `parental_control_analyzer.sh verify`

## Documentation (15 files in docs/)

```
docs/
├── BEST_PRACTICES_KACI.md
├── DEVELOPMENT_TOOLS.md              ← NEW
├── GETTING_STARTED.md
├── README.md
├── TECHNICAL_REFERENCE.md
└── USER_GUIDE.md

Note: Historical files (CHANGELOG_v1.4.1.md, CRITICAL_FIX_v1.1.4.md, etc.) 
      have been consolidated into main documentation files.
```

## Usage Quick Reference

### For End Users (on pfSense)

```bash
# View statistics
parental_control_analyzer.sh stats

# Watch logs in real-time
parental_control_analyzer.sh watch

# Check system status
parental_control_analyzer.sh status

# Reset counters (interactive)
parental_control_analyzer.sh reset

# Verify installation
parental_control_analyzer.sh verify

# Manual update check
/usr/local/bin/auto_update_parental_control.sh
```

### For Developers (local machine)

```bash
# Bump version (with automatic validation)
./bump_version.sh 1.4.3 "Your changelog message"

# Validate INSTALL/UNINSTALL sync
./validate_cleanup.sh

# Install to pfSense
./INSTALL.sh

# Uninstall from pfSense
./UNINSTALL.sh
```

## Key Features

### 1. Consolidated Diagnostics
All diagnostic tools in one place:
- `analyzer reset` instead of separate `diagnose_reset.sh`
- `analyzer verify` instead of separate `verify_files.sh`
- Consistent command interface

### 2. Automated Synchronization Validation
**Problem Solved**: Leftover files after uninstall

**Solution**: `validate_cleanup.sh` ensures INSTALL.sh and UNINSTALL.sh are synchronized

**Checks**:
- ✅ All installed files have removal in UNINSTALL.sh
- ✅ All port aliases are removed
- ✅ All configurations are cleaned up
- ✅ All firewall rules are removed

**Automatic Execution**: Runs before every version bump

### 3. Development vs Production Separation

**Core Scripts** (Deployed):
- Essential for package operation
- Deployed to pfSense during installation
- Updated via auto-update mechanism

**Dev Tools** (Not Deployed):
- Used during development only
- Never deployed to production pfSense
- Facilitate release management

## Deployment Flow

```
Development Machine                    pfSense Router
─────────────────────                  ──────────────

bump_version.sh ──┐
validate_cleanup.sh ┘                                
                                       
                 ↓ (git push)
                                       
            GitHub Repo
                                       
                 ↓ (./INSTALL.sh or auto-update)
                                       
                                       /usr/local/bin/
                                       ├── auto_update_parental_control.sh
                                       ├── parental_control_analyzer.sh
                                       └── parental_control_diagnostic.php
                                       
                                       /usr/local/etc/rc.d/
                                       └── parental_control_captive
                                       
                                       /usr/local/pkg/
                                       ├── parental_control.inc
                                       └── parental_control.xml
                                       
                                       /usr/local/www/
                                       └── parental_control_*.php
```

## Synchronization Guarantee

**Before v1.4.2**: Manual tracking of installed files  
**After v1.4.2**: Automated validation ensures completeness

Every release now includes automatic validation that:
1. No files are left behind after uninstall
2. All aliases are properly removed
3. All configurations are cleaned up
4. Complete package removal is guaranteed

## Testing Checklist

```bash
# 1. Validate synchronization
./validate_cleanup.sh
# Expected: ✅ No issues

# 2. Test installation
./INSTALL.sh
# Expected: All files deployed

# 3. Test analyzer commands
ssh root@pfsense 'parental_control_analyzer.sh verify'
ssh root@pfsense 'parental_control_analyzer.sh reset'
# Expected: Commands work correctly

# 4. Test uninstallation
./UNINSTALL.sh
# Expected: Complete removal

# 5. Verify no leftovers
ssh root@pfsense 'find /usr/local -name "*parental*"'
# Expected: No files found

# 6. Bump version (includes auto-validation)
./bump_version.sh 1.4.3 "Test release"
# Expected: Validation passes, version updated
```

## Benefits Summary

✅ **Fewer Scripts**: 8 → 7 (with better organization)  
✅ **Clear Separation**: Core vs Development tools  
✅ **Automated Validation**: No leftover files guaranteed  
✅ **Better Maintenance**: validate_cleanup.sh enforces sync  
✅ **Consolidated Tools**: One analyzer for all diagnostics  
✅ **Proper Deployment**: Captive portal correctly installed  
✅ **Organized Docs**: All documentation in docs/  

## Version Information

**Version**: 1.4.x  
**Build**: 0.3.5  
**Release Date**: 2026-01-18  
**Type**: Enhancement  
**Status**: Production Ready  

## File Counts

- **Core Scripts**: 5 (deployed to pfSense)
- **Dev Tools**: 2 (development only)
- **Total Scripts**: 7
- **Documentation**: 15 files (in docs/)
- **PHP Files**: 13 (package code)
- **Total Project Files**: ~35 key files

## Summary

Successfully consolidated and organized all scripts with:
- ✅ Clear separation of concerns
- ✅ Automated synchronization validation
- ✅ No leftover files after uninstall
- ✅ Better developer experience
- ✅ Better end-user experience

All changes tested and production-ready! 🎉

---

**Package**: KACI-Parental_Control  
**Maintainer**: Mukesh Kesharwani  
**Repository**: https://github.com/keekar/KACI-Parental-Control

# Script Consolidation Summary - v1.4.2

## ✅ Completed Successfully

Successfully consolidated shell scripts from **8 to 5 files** (4 main + 1 RC script).

## Final Script Structure

### Main Scripts (4)

1. **INSTALL.sh** (42K)
   - Installation and deployment
   - Update functionality
   - Verify mode (calls analyzer)
   - Auto-update setup

2. **UNINSTALL.sh** (6.2K)
   - Complete package removal
   - Removes all traces
   - Cleans up port aliases

3. **auto_update_parental_control.sh** (5.4K)
   - Automatic GitHub updates
   - Manual update capability
   - Logging and state tracking

4. **parental_control_analyzer.sh** (25K) ⭐ **ENHANCED**
   - Log analysis (original)
   - Device tracking (original)
   - System status (original)
   - **NEW**: Reset diagnostic (from diagnose_reset.sh)
   - **NEW**: Installation verification (from verify_files.sh)

### RC Script (1)

5. **parental_control_captive.sh** (4.3K)
   - FreeBSD RC script
   - Manages captive portal server
   - Now properly deployed by INSTALL.sh

## Removed Scripts (3)

✅ **diagnose_reset.sh** → Consolidated into `analyzer reset`  
✅ **verify_files.sh** → Consolidated into `analyzer verify`  
✅ **bump_version.sh** → Removed (development-only, manual alternative documented)

## Documentation Organization

All **14 documentation files** now in `docs/` folder:

```
docs/
├── BEST_PRACTICES_KACI.md
├── DEVELOPMENT_TOOLS.md
├── GETTING_STARTED.md
├── README.md
├── TECHNICAL_REFERENCE.md
└── USER_GUIDE.md

Note: Historical/temporary files consolidated into main docs.
```

## Usage Examples

### Reset Counters
```bash
# Old way
ssh root@pfsense < diagnose_reset.sh

# New way
ssh root@pfsense 'parental_control_analyzer.sh reset'
```

### Verify Installation
```bash
# Old way
ssh root@pfsense '/usr/local/bin/verify_files.sh'

# New way
ssh root@pfsense 'parental_control_analyzer.sh verify'
```

### All Analyzer Commands
```bash
parental_control_analyzer.sh stats         # Statistics
parental_control_analyzer.sh recent 50     # Last 50 entries
parental_control_analyzer.sh device MAC    # Device activity
parental_control_analyzer.sh watch         # Real-time logs
parental_control_analyzer.sh status        # System status
parental_control_analyzer.sh reset         # Reset diagnostic ⭐ NEW
parental_control_analyzer.sh verify        # Verify installation ⭐ NEW
```

## Benefits

✅ **Simplified Maintenance** - Fewer files to manage  
✅ **Better Organization** - All docs in one place  
✅ **Easier to Use** - One tool for all diagnostics  
✅ **Proper Deployment** - Captive portal RC script correctly installed  
✅ **No Lost Functionality** - Everything preserved, just reorganized  

## Version Updates

- **Package Version**: 1.4.1 → 1.4.2
- **Build Version**: 0.3.4 → 0.3.5
- **Release Type**: Minor Enhancement
- **Status**: Production Ready

## Files Modified

### Updated
- ✅ INSTALL.sh - Deploy captive.sh, remove consolidated script references
- ✅ UNINSTALL.sh - Remove consolidated script cleanup
- ✅ parental_control_analyzer.sh - Added reset and verify commands
- ✅ BUILD_INFO.json - Updated version and changelog
- ✅ VERSION - Updated to 1.4.2
- ✅ info.xml - Updated to 1.4.2

### Removed
- ✅ diagnose_reset.sh
- ✅ verify_files.sh
- ✅ bump_version.sh

### Created
- ✅ Consolidated into docs/TECHNICAL_REFERENCE.md
- ✅ Consolidated into docs/USER_GUIDE.md

## Testing Checklist

```bash
# 1. Verify script count
ls -1 *.sh | wc -l
# Expected: 5

# 2. Test analyzer reset
ssh root@pfsense 'parental_control_analyzer.sh reset'
# Should show diagnostic and prompt for reset

# 3. Test analyzer verify
ssh root@pfsense 'parental_control_analyzer.sh verify'
# Should check all files and show status

# 4. Test captive portal deployment
ssh root@pfsense 'test -x /usr/local/etc/rc.d/parental_control_captive && echo "OK"'
# Expected: OK

# 5. Verify installation
./INSTALL.sh verify
# Should complete without errors
```

## Next Steps

1. ✅ Test installation on pfSense
2. ✅ Verify all commands work
3. ✅ Update any external documentation
4. ✅ Commit changes to repository

---

**Consolidation Date**: 2026-01-18  
**Version**: 1.4.x  
**Status**: ✅ Complete and Production Ready


---

## Historical Architecture Notes

### Switch to Table-Based Blocking (v1.1.8)

**Problem Solved**: pfSense anchors weren't being evaluated properly due to rule ordering issues.

**Solution Implemented**: 
- Switched from anchor-based blocking to pfSense table/alias-based blocking
- Created `parental_control_blocked` alias (type: host)
- Uses floating rules for proper evaluation order
- IPs dynamically added/removed via `pfctl -t` commands
- Fully integrated with pfSense GUI

**Key Functions**:
```php
pc_create_blocking_alias()    // Creates the host alias
pc_create_blocking_rule()      // Creates floating block rule
pc_add_device_block_table()    // Adds IP to table
pc_remove_device_block_table() // Removes IP from table
```

**Why This Works**:
- Floating rules evaluated before interface rules
- No anchor reference injection needed
- Visible and manageable in pfSense GUI
- Native pfSense feature (stable and supported)

### Version Stability Notes

**v1.1.9**: Last known stable version before v1.2.x experiments
- Table-based blocking working
- HTTP/HTTPS redirects working
- NAT rules for captive portal
- Simple and reliable approach

**v1.2.x Series** (Deprecated): 
- Attempted dedicated block page server
- VIP-based captive portal complexity
- Socket/daemon issues
- Reverted back to v1.1.9 approach

**v1.4.x Series** (Current):
- Built on stable v1.1.9 foundation
- Port alias fixes (KACI_PC_Ports, KACI_PC_Web)
- Enhanced diagnostics (analyzer with reset/verify)
- Script consolidation and validation
- Production-ready and stable

### Critical Bug Fixes in History

**v1.1.4**: Missing `cron.inc` include
- **Issue**: PHP Fatal Error when setting up cron jobs
- **Fix**: Added `require_once("cron.inc");` to parental_control.inc
- **Impact**: Critical - package could crash during installation
- **Lesson**: Always include all direct dependencies

