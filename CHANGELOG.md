# Changelog

All notable changes to KACI Parental Control will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.6.0] - 2025-12-27 ✨ FEATURE: Better Device Discovery

### Added
- **NEW**: Device selection interface for auto-discover with checkboxes
  - Users can now select which discovered devices to add (not automatic)
  - Shows all unassigned devices from DHCP leases
  - Includes "Select All" checkbox for convenience
  - Real-time table view of MAC, IP, hostname, and device name
  
### Changed  
- **IMPROVED**: Cross-profile filtering for device discovery
  - Filters out devices already assigned to ANY profile (not just current)
  - Prevents duplicate device entries across all profiles
  - Shows clear message when all devices are already assigned
  
- **IMPROVED**: Better UX for device management
  - Two-step process: Discover → Select → Add
  - Visual feedback with device count before selection
  - Cancel option to return without adding devices

### Technical
- Uses DHCP leases only (no ARP) per user environment
- JSON encoding for device data transfer in form
- Proper state management during discovery flow

---

## [0.5.3] - 2025-12-27 🐛 BUGFIX

### Fixed
- **BUGFIX**: Removed ARP table scanning (doesn't work in user environment)
  - Now uses DHCP leases exclusively (same as status_dhcp_leases.php)
  - Includes both active leases and static DHCP mappings
  - More reliable and faster device discovery

---

## [0.5.2] - 2025-12-27 🐛 BUGFIX

### Fixed
- **BUGFIX**: Improved auto-discover with better error handling
  - Added `pc_discover_devices()` function implementation
  - Better error messages and feedback
  - Debug logging for troubleshooting

---

## [0.2.1] - 2025-12-26 🚨 CRITICAL FIX

### 🔴 CRITICAL - Layer 3 Compliance

**User-Reported Issue**: Package was using MAC addresses for operational logic, but pfSense operates at Layer 3 (IP addresses). This was a fundamental architectural flaw that would prevent blocking and tracking from working correctly.

### Changed
- **ARCHITECTURE**: Complete rewrite to IP-based operational logic
  - MAC addresses now ONLY used for device identification in configuration
  - All tracking, state storage, and firewall operations now use IP addresses
  - State structure changed from `devices` (by MAC) to `devices_by_ip` (by IP)
  
### Added
- **NEW**: IP-based state file structure (`devices_by_ip`)
- **NEW**: MAC-to-IP resolution cache for performance
- **NEW**: Automatic state migration from v0.2.0 MAC-based to v0.2.1 IP-based
- **NEW**: IP change detection and handling for DHCP renewals
- **NEW**: State file preserves all usage data during upgrade

### Fixed
- **CRITICAL**: Firewall rules now use IP addresses (Layer 3 native)
- **CRITICAL**: Connection tracking now queries by IP address
- **CRITICAL**: Blocking will now actually work (was fundamentally broken)

### Improved
- Logs now include both IP and MAC for clarity
- Analyzer shows IP addresses with MAC references
- Architecture now properly Layer 3 compliant

**Credit**: Issue identified by user feedback

---

## [0.2.0] - 2025-12-26 ⚡ MAJOR OVERHAUL

### 🎯 Real Connection Tracking

**Inspiration**: Based on insights from MultiServiceLimiter project

### Changed
- **CRITICAL FIX**: Replaced ARP-based tracking with pfctl state table queries
  - Now tracks ACTUAL internet usage instead of just network presence
  - Devices with no active connections don't increment usage counters

### Added
- **NEW**: PID lock mechanism to prevent concurrent cron executions
  - Prevents race conditions and state file corruption
- **NEW**: IP address lookup from MAC with caching
- **NEW**: Log analyzer tool (`parental_control_analyzer.sh`)
  - Commands: `stats`, `logs`, `recent`, `device`, `errors`, `watch`, `state`, `status`
  - Color-coded output, real-time monitoring, device-specific queries
- **NEW**: Connection count tracking in state file

### Improved
- Proper interval calculation (matches cron interval exactly)
- Enhanced logging with connection counts and execution time
- Connection tracking uses timeout to prevent hanging
- Graceful degradation if state table query fails

### Technical
- State file path changed to `.jsonl` (preparation for JSONL format)
- Performance optimizations in connection detection

---

## [0.1.4-hotfix2] - 2025-12-25 🔧 CRITICAL HOTFIX

### Fixed
- **CRITICAL**: Logging now enabled by default (was opt-in, should be opt-out)
- **CRITICAL**: Log directory automatically created if missing
- **CRITICAL**: State directory automatically created if missing
- File write operations now have error handling with syslog fallback
- Graceful degradation - critical messages fallback to syslog

### Added
- **NEW**: Comprehensive diagnostic script (`parental_control_diagnostic.php`)
  - Checks: service status, logging, log files, state file, cron job, devices, firewall rules, PHP version
  - Performs live usage tracking test
- **NEW**: Detailed troubleshooting guide

---

## [0.1.4] - 2025-12-25 📦 MAJOR UPDATE

### DRY Refactoring

### Added
- **NEW**: Reusable helper functions
  - `pc_normalize_mac()` - MAC address normalization
  - `pc_validate_mac()` - MAC validation
  - `pc_validate_time()` - Time format validation
  - `pc_validate_numeric_range()` - Numeric range validation
  - `pc_is_mac_unique()` - Duplicate MAC detection
  - `pc_is_service_enabled()` - Service status check
  - `pc_is_device_enabled()` - Device status check
  - `pc_get_devices()` - Device list retrieval
  - `pc_get_profiles()` - Profile list retrieval

### Performance
- **NEW**: Caching system for expensive operations
  - DHCP leases cache (30s TTL)
  - ARP lookup cache (30s TTL)
  - Config cache (30s TTL)
  - State file cache (5s TTL)
  - Automatic cache invalidation on changes

### REST API
- **NEW**: Full RESTful API (`parental_control_api.php`)
  - 9 endpoints: `/devices`, `/profiles`, `/usage`, `/status`, `/override`, `/block`, `/unblock`, `/health`, `/logs`
  - API key authentication (header or query parameter)
  - CORS support for external dashboards
  - Comprehensive API documentation

### Fixed
- PHP parse error from cron syntax in JSDoc comments

### Improved
- Eliminated code duplication across 20+ locations
- Centralized validation logic
- Centralized configuration access

---

## [0.1.3] - 2025-12-25 📚 ENHANCEMENTS

### Added
- **NEW**: Health check endpoint (`parental_control_health.php`)
- **NEW**: Automatic log rotation (5MB per file, keep 10)
- **NEW**: JSDoc documentation for all functions
- **NEW**: Configuration examples directory
- **NEW**: Quick start guide
- **NEW**: Try-catch blocks on critical operations
- **NEW**: Inline "why" comments explaining business logic
- **NEW**: Environment variable support
- **NEW**: Build information tracking (`BUILD_INFO.json`)

### Improved
- Graceful degradation on errors
- Console logging enhancements
- DRY refactoring for common patterns
- Performance optimizations

---

## [0.1.2] - 2025-12-24

### Changed
- Version synchronization across all package files

---

## [0.1.1] - 2025-12-24

### Fixed
- Various bug fixes and improvements

---

## [0.1.0] - 2025-12-24 🎯 MAJOR FIX

### Fixed
- **MAJOR**: Backend parsing of device_selector dropdown
  - Devices now save correctly even without JavaScript
  - Auto-fills `mac_address`, `ip_address`, `device_name` from dropdown selection

### Improved
- Profile XML configpath correction
- Package name consistency

---

## [0.0.7] - 2025-12-24 🔧 CRITICAL FIX

### Fixed
- **CRITICAL**: Correct config path to `/config/0/enable`
- Service enable status now works correctly
- All `config_path_enabled()` calls fixed

---

## [0.0.6] - 2025-12-24

### Fixed
- DHCP/ARP device auto-discovery
- Rewrote `pc_get_dhcp_leases()` to use ARP table directly

### Changed
- Updated JavaScript to vanilla JS (removed jQuery dependency)

### Added
- Console logging for debugging

---

## [0.0.5] - 2025-12-24

### Fixed
- **FINAL FIX**: Correct config path (no [0] element)

---

## [0.0.4] - 2025-12-24

### Added
- Debug logging to identify correct config paths

---

## [0.0.3] - 2025-12-24 🔧 CRITICAL FIX

### Fixed
- **CRITICAL**: Service enable/disable functionality
- Config path checking in all functions

### Added
- Configpath to XML for proper pfSense integration

---

## [0.0.2] - 2025-12-24

### Added
- DHCP/ARP device auto-discovery dropdown
- Auto-populate device info from network

### Fixed
- Shell syntax errors in INSTALL.sh

---

## [0.0.1] - 2025-12-24 🎉 INITIAL RELEASE

### Added
- Profile-based device grouping
- Shared time accounting across devices in a profile
- OpenTelemetry-compliant JSONL logging
- Weekend bonus time feature
- Profile-wide schedule blocking
- Daily and weekly time limits
- MAC address-based device tracking
- Cron-based enforcement
- pfSense firewall integration

---

## Version Numbering

This project uses [Semantic Versioning](https://semver.org/):

```
MAJOR.MINOR.PATCH

- MAJOR: Breaking changes, major architectural changes
- MINOR: New features, non-breaking changes
- PATCH: Bug fixes, small improvements
```

**Examples:**
- `0.0.1` → `0.0.2` - Bug fix
- `0.0.99` → `0.1.0` - New feature
- `0.99.99` → `1.0.0` - Major release

---

## Deferred Features

### Planned for v0.3.0 (Q1 2026)
- pfSense tables for blocking (instead of individual rules)
- JSONL state file format (fault-tolerant)
- State file migration tool
- Enhanced performance metrics

### Planned for v0.4.0 (Q2 2026)
- Per-service tracking (YouTube, Gaming, Netflix, etc.)
- Bandwidth-based quotas
- Mobile app integration
- Advanced reporting and analytics

---

**Project**: KACI Parental Control for pfSense  
**Repository**: https://github.com/keekar2022/KACI-Parental_Control  
**Author**: Mukesh Kesharwani (Keekar)

