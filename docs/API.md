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
    "schedules": {
      "bedtime_start": "21:00",
      "bedtime_end": "07:00",
      "school_start": "09:00",
      "school_end": "15:00"
    }
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
    "schedules": [],
    "usage": {
      "usage_today": 45,
      "usage_week": 180
    }
  }
}
```

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

