# 🎯 KACI Project Best Practices

**Lessons Learned & Design Decisions from KACI Parental Control**

This document captures unique best practices, design decisions, and lessons learned during the development of KACI Parental Control. These practices evolved through real-world problem-solving and are not commonly found in standard development guides.

**Purpose:** Use this as a reference checklist for future projects to avoid common pitfalls and implement proven solutions.

---

## 📑 Table of Contents

1. [Version Management](#version-management)
2. [Logging & Debugging](#logging--debugging)
3. [UI/UX Design](#uiux-design)
4. [State Management](#state-management)
5. [Data Persistence](#data-persistence)
6. [Error Handling](#error-handling)
7. [Code Organization](#code-organization)
8. [Documentation](#documentation)
9. [Git Practices](#git-practices)
10. [API Design](#api-design)
11. [Security Patterns](#security-patterns)
12. [Performance Optimization](#performance-optimization)
13. [Testing & Verification](#testing--verification)
14. [Data Structures](#data-structures)
15. [pfSense-Specific Best Practices](#pfsense-specific-best-practices)

---

## Version Management

### ✅ **Automatic Version Loading**

**Problem:** Hardcoded version numbers across multiple files lead to inconsistencies.

**Solution:** Single source of truth with automatic loading.

```php
// ✅ GOOD: Load version from file
$version_file = '/usr/local/pkg/parental_control_VERSION';
if (file_exists($version_file)) {
    $version_data = parse_ini_file($version_file);
    define('PC_VERSION', $version_data['VERSION'] ?? '0.0.0');
    define('PC_BUILD_DATE', $version_data['BUILD_DATE'] ?? 'unknown');
}

// ❌ BAD: Hardcoded with fallback
define('PC_VERSION', defined('PC_VERSION') ? PC_VERSION : '0.9.0');
```

**Key Points:**
- Single `VERSION` file in INI format
- All code reads from this file
- No hardcoded fallbacks (they mask issues)
- Deploy VERSION file with package

---

### ✅ **Pre-Commit Hooks for Version Enforcement**

**Problem:** Developers forget to bump version after code changes.

**Solution:** Pre-commit hook that aborts if code changed but version didn't.

```bash
#!/bin/bash
# .git/hooks/pre-commit

# Check if code files changed
CODE_CHANGED=$(git diff --cached --name-only | grep -E '\.(php|inc|xml)$')

# Check if version files changed
VERSION_CHANGED=$(git diff --cached --name-only | grep -E '^(VERSION|info\.xml)')

if [ -n "$CODE_CHANGED" ] && [ -z "$VERSION_CHANGED" ]; then
    echo "⚠️  CODE CHANGED BUT VERSION NOT BUMPED!"
    echo "Run: ./bump_version.sh [major|minor|patch] 'changelog'"
    exit 1
fi
```

**Benefits:**
- Enforces semantic versioning
- Prevents version drift
- Automatic changelog updates

---

### ✅ **Semantic Versioning with Automated Bumping**

**Script:** `bump_version.sh major|minor|patch "changelog message"`

**Key Features:**
- Updates VERSION file, info.xml, parental_control.xml, index.html
- Appends to CHANGELOG.md automatically
- Updates BUILD_INFO.json
- Can be called from CI/CD pipeline

---

## Logging & Debugging

### ✅ **JSONL (JSON Lines) Format**

**Problem:** Traditional logs are hard to parse, search, and analyze.

**Solution:** Use JSONL - one JSON object per line.

```php
// ✅ GOOD: Structured JSONL logging
function pc_log($message, $level = 'info', $context = array()) {
    $log_entry = array(
        '@timestamp' => date('c'),
        'log.level' => $level,
        'message' => $message,
        'service.name' => 'parental_control',
        'service.version' => PC_VERSION,
        'event.module' => 'parental_control',
        'event.dataset' => 'parental_control.main',
        'host.hostname' => php_uname('n')
    );
    
    // Merge context
    $log_entry = array_merge($log_entry, $context);
    
    // Write as single line JSON
    file_put_contents(PC_LOG_FILE, json_encode($log_entry) . "\n", FILE_APPEND);
}

// ❌ BAD: Unstructured text logging
error_log("[PC] Device blocked: 192.168.1.10");
```

**Benefits:**
- Easy to parse with `jq`, `grep`, or log aggregators
- Searchable by any field
- Compatible with ELK stack, Splunk, Datadog
- Machine-readable for automation

**Usage Examples:**
```bash
# Find all blocked devices
cat /var/log/parental_control.jsonl | jq 'select(.event.action == "block")'

# Count errors by type
cat /var/log/parental_control.jsonl | jq -r '.error.type' | sort | uniq -c

# Find profile usage updates
grep 'profile_usage_updated' /var/log/parental_control.jsonl | jq .
```

---

### ✅ **ECS (Elastic Common Schema) Compliance**

**Use standardized field names** for better interoperability:

```php
// Standard ECS fields
'@timestamp'         // ISO 8601 timestamp
'log.level'          // debug, info, warning, error, critical
'message'            // Human-readable message
'event.action'       // What happened (block, allow, update)
'event.category'     // Category (firewall, authentication, configuration)
'event.outcome'      // success, failure, unknown
'user.name'          // Profile name
'source.ip'          // Device IP
'source.mac'         // Device MAC
'error.type'         // Error class
'error.message'      // Error details
```

**Reference:** https://www.elastic.co/guide/en/ecs/current/

---

### ✅ **Context-Rich Logging**

**Always include context** for debugging:

```php
// ✅ GOOD: Rich context
pc_log("Device blocked due to time limit", 'warning', array(
    'event.action' => 'block',
    'event.category' => 'firewall',
    'event.reason' => 'time_limit_exceeded',
    'user.name' => 'Vishesh',
    'source.ip' => '192.168.1.115',
    'source.mac' => 'ca:96:f3:a7:26:15',
    'device.name' => 'Vishesh-iPhone',
    'usage.today' => 240,  // minutes
    'limit.daily' => 240,
    'profile.id' => 'Vishesh'
));

// ❌ BAD: Minimal context
pc_log("Device blocked", 'warning');
```

---

## UI/UX Design

### ✅ **NEVER Use White Text (Except on Black)**

**Problem:** White text on colored backgrounds is often unreadable.

**Rule:** Use dark colors for text, light colors for backgrounds.

```css
/* ✅ GOOD: Dark text on light background */
.header {
    background-color: #3b82f6;  /* Blue background */
    color: #1e293b;              /* Dark slate text */
}

.content {
    background-color: #f8fafc;   /* Light background */
    color: #1e293b;              /* Dark text */
}

/* ❌ BAD: White text on colored background */
.header {
    background-color: #3b82f6;
    color: #ffffff;  /* Hard to read! */
}
```

**Exceptions:**
- Black or very dark backgrounds (< #333333)
- High contrast mode explicitly enabled
- Inverted color schemes

**Accessibility:**
- Use WCAG contrast ratio calculator
- Minimum 4.5:1 for normal text
- Minimum 3:1 for large text (18pt+)

---

### ✅ **Fixed Dropdown Sizes (Not Dynamic)**

**Problem:** Dynamic dropdown sizes (`size="<?=count($items)?>"`) create inconsistent UX.

**Solution:** Use fixed, reasonable sizes with scrolling.

```php
// ✅ GOOD: Fixed size
<select name="profiles[]" multiple size="4">
    <?php foreach ($profiles as $profile): ?>
        <option value="<?=$profile['name']?>"><?=$profile['name']?></option>
    <?php endforeach; ?>
</select>

// ❌ BAD: Dynamic size
<select name="profiles[]" multiple size="<?=count($profiles)?>">
    <!-- Size changes as profiles are added, breaking layout -->
</select>

// ❌ ALSO BAD: size="<?=max(3, count($profiles))?>">
// Still grows without limit
```

**Recommended Fixed Sizes:**
- Small lists: `size="3"`
- Medium lists: `size="4"` ✅ **Best default**
- Large lists: `size="6"`

---

### ✅ **Consistent Version Display in Footers**

**Always show version in page footers** for debugging:

```php
<footer style="margin-top: 40px; padding: 20px; background: #f8fafc; border-top: 2px solid #e2e8f0; text-align: center;">
    <p style="color: #64748b; margin: 0;">
        <strong>Keekar's Parental Control</strong> v<?=PC_VERSION?>
    </p>
    <p style="color: #94a3b8; font-size: 0.9em; margin: 5px 0 0 0;">
        Built with Passion by Mukesh Kesharwani | © 2025 Keekar
    </p>
</footer>
```

**Benefits:**
- Users can report exact version
- Developers can verify deployment
- Support can identify version-specific issues

---

## State Management

### ✅ **Atomic File Operations with rename()**

**Problem:** Crashes during file writes corrupt state.

**Solution:** Write to temp file, then atomic rename.

```php
// ✅ GOOD: Atomic write
function pc_save_state($state) {
    $state_file = '/var/db/parental_control_state.json';
    $temp_file = $state_file . '.tmp.' . getmypid();
    
    // Write to temp file
    $json = json_encode($state, JSON_PRETTY_PRINT);
    if (file_put_contents($temp_file, $json) === false) {
        return false;
    }
    
    // Atomic rename (crash-resistant)
    return rename($temp_file, $state_file);
}

// ❌ BAD: Direct write (crash = corrupted state)
function pc_save_state($state) {
    file_put_contents('/var/db/parental_control_state.json', json_encode($state));
}
```

**Why It Works:**
- `rename()` is atomic on Unix systems
- If crash occurs during write, temp file is corrupted, not main file
- If crash occurs during rename, old file is still valid

---

### ✅ **Profile-Level Tracking (Not Per-Device)**

**Problem:** Kids can bypass per-device limits by switching devices.

**Solution:** Track usage at profile level, shared across all devices.

```php
// ✅ GOOD: Profile-level tracking (bypass-proof)
$state['profiles']['Vishesh']['usage_today'] += 5;  // All devices share this

// ❌ BAD: Per-device tracking (bypassable)
$state['devices']['iPhone']['usage_today'] += 5;    // Can switch to iPad
$state['devices']['iPad']['usage_today'] += 5;      // Each device has own limit
```

**Implementation:**
```php
function pc_update_device_usage($mac, &$state) {
    // Find device's profile
    $profile_name = pc_get_device_profile($mac);
    
    // Update PROFILE usage (shared)
    if (!isset($state['profiles'][$profile_name])) {
        $state['profiles'][$profile_name] = array(
            'usage_today' => 0,
            'usage_week' => 0,
            'last_update' => time()
        );
    }
    
    $state['profiles'][$profile_name]['usage_today'] += PC_CRON_INTERVAL_MINUTES;
}
```

---

### ✅ **Backward Compatibility with Normalization**

**Problem:** Config format changes break existing installations.

**Solution:** Normalize on read, write in new format.

```php
// ✅ GOOD: Bidirectional normalization
function pc_get_devices($profile) {
    // Support both old 'row' and new 'devices' fieldnames
    if (isset($profile['devices']) && is_array($profile['devices'])) {
        return $profile['devices'];
    } elseif (isset($profile['row']) && is_array($profile['row'])) {
        return $profile['row'];  // Old format
    }
    return array();
}

// Always write in new format
function pc_save_profile($profile) {
    if (isset($profile['row'])) {
        $profile['devices'] = $profile['row'];  // Migrate on save
        unset($profile['row']);
    }
    config_set_path('installedpackages/parentalcontrol/config/' . $id, $profile);
}
```

---

## Data Persistence

### ✅ **XML Arrays Must Be Strings**

**Problem:** PHP arrays saved directly to pfSense XML cause corruption.

**Solution:** Convert arrays to comma-separated strings.

```php
// ✅ GOOD: Convert to string for XML
$schedule = array(
    'name' => 'Bedtime',
    'profile_names' => implode(',', $_POST['profile_names']),  // String
    'days' => implode(',', $_POST['days'])                     // String
);
config_set_path('installedpackages/parentalcontrolschedules/config/' . $id, $schedule);

// When reading back
$profile_names = is_string($schedule['profile_names']) 
    ? explode(',', $schedule['profile_names']) 
    : $schedule['profile_names'];

// ❌ BAD: Store array directly
$schedule = array(
    'profile_names' => $_POST['profile_names']  // Array - breaks XML!
);
```

**Why:** pfSense config.xml expects scalar values. Arrays cause:
- Invalid XML structure
- Config corruption
- Automatic backup restore

---

### ✅ **JSON for State, XML for Config**

**Use the right format for the right purpose:**

| Format | Use For | Why |
|--------|---------|-----|
| **JSON** | Runtime state, caches, logs | Easy to parse, supports complex structures |
| **XML** | pfSense configuration | Required by pfSense, survives reboots |
| **INI** | Simple config (VERSION file) | Human-readable, easy to parse |

```php
// ✅ JSON for state (complex, frequently updated)
$state = array(
    'devices_by_ip' => array(...),
    'profiles' => array(...),
    'mac_to_ip_cache' => array(...)
);
file_put_contents('/var/db/parental_control_state.json', json_encode($state));

// ✅ XML for config (simple, rarely updated, needs persistence)
config_set_path('installedpackages/parentalcontrol/config', $config);
write_config('Parental Control: Updated settings');
```

---

## Error Handling

### ✅ **Try-Catch Around write_config()**

**Problem:** `write_config()` can fail if config is locked or permissions issue.

**Solution:** Always wrap in try-catch, especially in CLI/cron context.

```php
// ✅ GOOD: Graceful error handling
try {
    write_config('Parental Control: Updated profile');
    pc_log("Config saved successfully", 'info');
} catch (Exception $e) {
    // Don't crash - log and continue
    pc_log("Failed to save config: " . $e->getMessage(), 'error', array(
        'error.type' => get_class($e),
        'error.message' => $e->getMessage(),
        'error.stack_trace' => $e->getTraceAsString()
    ));
    // State was saved to JSON, config update can retry later
}

// ❌ BAD: No error handling
write_config('Parental Control: Updated profile');  // Fatal error if locked!
```

**Why It Matters:**
- Cron jobs run in CLI context (different permissions)
- Multiple processes might access config simultaneously
- GUI saves should complete even if sync fails

---

### ✅ **Separate GUI Save from Background Sync**

**Problem:** Heavy background operations (like `filter_configure()`) timeout GUI saves.

**Solution:** GUI saves config immediately, cron handles heavy lifting.

```php
// ✅ GOOD: Fast GUI save, background processing
if ($_POST['save']) {
    // 1. Validate and save config (fast, <1 second)
    $profile['name'] = $_POST['name'];
    config_set_path('installedpackages/parentalcontrol/config/' . $id, $profile);
    write_config('Parental Control: Saved profile');
    
    // 2. Attempt sync, but don't block on it
    try {
        parental_control_sync();  // May call filter_configure()
    } catch (Exception $e) {
        pc_log("Sync will retry on next cron run", 'warning');
    }
    
    // 3. Always show success to user
    $savemsg = "Profile saved successfully!";
    header("Location: parental_control_profiles.php?savemsg=" . urlencode($savemsg));
    exit;
}

// ❌ BAD: GUI waits for everything
if ($_POST['save']) {
    config_set_path(...);
    write_config(...);
    parental_control_sync();        // Calls filter_configure() - takes 5-10 seconds
    filter_configure();              // Another 5-10 seconds - user sees timeout!
}
```

---

## Code Organization

### ✅ **Pure PHP Pages for Complex Forms (Not XML)**

**When to use PHP vs XML:**

| Scenario | Use | Why |
|----------|-----|-----|
| Simple settings form | XML (`packagegui`) | pfSense handles everything |
| Dynamic dropdowns | PHP | Can query database, compute values |
| Multi-step forms | PHP | Better control flow |
| Complex validation | PHP | Custom error messages |
| AJAX interactions | PHP | JSON responses |

```php
// ✅ GOOD: PHP for complex forms
// parental_control_profiles.php
<?php
require_once("guiconfig.inc");
require_once("/usr/local/pkg/parental_control.inc");

// Dynamic profile list from config
$profiles = pc_get_profiles();

// Complex device discovery
if ($_POST['autodiscover']) {
    $dhcp_devices = pc_discover_devices();
    $existing_macs = pc_get_all_assigned_macs();
    $available_devices = array_filter($dhcp_devices, function($device) use ($existing_macs) {
        return !in_array($device['mac'], $existing_macs);
    });
}

// Custom HTML rendering
?>
<select name="devices[]" multiple>
    <?php foreach ($available_devices as $device): ?>
        <option value="<?=$device['mac']?>"><?=$device['name']?></option>
    <?php endforeach; ?>
</select>
```

**Drawbacks of XML for complex forms:**
- Limited dynamic content
- Hard to debug
- Poor IDE support
- Confusing error messages

---

### ✅ **Reusable Functions with pc_ Prefix**

**All parental control functions** use `pc_` prefix to avoid naming conflicts.

```php
// ✅ GOOD: Namespaced functions
function pc_get_profiles() { ... }
function pc_save_state($state) { ... }
function pc_is_time_limit_exceeded($profile) { ... }

// ❌ BAD: Generic names (conflict risk)
function get_profiles() { ... }      // Conflicts with pfSense core?
function save_state($state) { ... }   // Conflicts with other packages?
```

**Benefits:**
- Easy to find all package functions (`grep "function pc_"`)
- No conflicts with pfSense core or other packages
- Clear ownership

---

## Documentation

### ✅ **Maximum 4 Consolidated Files**

**Problem:** Many small docs = navigation hell, duplicated info, maintenance burden.

**Solution:** Consolidate to 4 logical documents.

```
docs/
├── README.md                  # Navigation hub, quick links
├── GETTING_STARTED.md         # Installation, setup, first use
├── USER_GUIDE.md              # Config, troubleshooting, changelog
└── TECHNICAL_REFERENCE.md     # API, architecture, features, development
```

**Benefits:**
- Easy to find information (3 places to check)
- Complete context (related info together)
- Easier to maintain (fewer files)
- Better for Ctrl+F searching

**What NOT to do:**
- ❌ Separate file for every feature
- ❌ Separate file for every version's changes
- ❌ README files in multiple directories

---

### ✅ **Code References with Line Numbers**

**For existing code**, use this format:

```markdown
The status page displays profile usage at line 185:

```185:186:parental_control_status.php
if (isset($state['profiles'][$profile['name']]['usage_today'])) {
    $usage_today = intval($state['profiles'][$profile['name']]['usage_today']);
}
```
```

**Benefits:**
- Creates clickable links in IDEs
- Easy to find exact code location
- Readers can verify claims
- Updates visible in diffs

**For new/proposed code**, use standard markdown code blocks.

---

### ✅ **Inline Comments Explain WHY, Not WHAT**

```php
// ✅ GOOD: Explains WHY and context
// WHY: pfSense anchors persist across reboots but are faster than filter_configure()
// Design Decision: Use anchors instead of direct firewall rules
// Trade-off: Rules not visible in GUI, but 100x faster updates
function pc_apply_firewall_rules($device_ip, $reason) {
    pc_add_anchor_rule("block drop from $device_ip to any");
}

// ❌ BAD: States the obvious
// This function adds a firewall rule
function pc_apply_firewall_rules($device_ip, $reason) {
    pc_add_anchor_rule("block drop from $device_ip to any");
}
```

---

## Git Practices

### ✅ **Semantic Commit Messages**

**Format:** `type(scope): description`

**Types:**
- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation only
- `refactor:` Code restructure, no behavior change
- `perf:` Performance improvement
- `test:` Add/update tests
- `chore:` Maintenance tasks

```bash
# ✅ GOOD: Clear, categorized
git commit -m "feat(profiles): Add auto-discover devices with checkbox selection"
git commit -m "fix(status): Status page now displays shared profile usage"
git commit -m "docs(api): Add schedules API endpoint documentation"

# ❌ BAD: Vague
git commit -m "updates"
git commit -m "fix bug"
git commit -m "changes"
```

---

### ✅ **Version in Commit Messages**

**For version bumps**, include version in commit:

```bash
# ✅ GOOD: Version explicit
git commit -m "fix(ui): v1.1.3 - Reduce schedule profile dropdown to 4 lines"

# ❌ BAD: No version context
git commit -m "fix dropdown size"
```

---

### ✅ **Document with Code Changes**

**Always update docs in same commit** as code changes:

```bash
# ✅ GOOD: Code + docs together
git add parental_control.inc
git add parental_control_status.php
git add docs/USER_GUIDE.md
git commit -m "feat: v1.1.0 - Add shared profile time accounting

- Modified pc_update_device_usage() to track at profile level
- Updated status page to display profile usage
- Added complete feature documentation to USER_GUIDE"

# ❌ BAD: Code and docs in separate commits
git commit -m "add profile tracking"
# ... 3 days later ...
git commit -m "update docs for profile tracking"
```

---

## API Design

### ✅ **RESTful Endpoints with Consistent Structure**

```
GET  /api/resources              - List all
GET  /api/resources/{id}         - Get specific
POST /api/resources              - Create new
PUT  /api/resources/{id}         - Update
DELETE /api/resources/{id}       - Delete
GET  /api/resources/{id}/nested  - Get related
```

**Example:**
```
GET  /api/profiles               - List all profiles
GET  /api/profiles/Vishesh       - Get Vishesh profile
GET  /api/profiles/Vishesh/schedules  - Get schedules for Vishesh
GET  /api/schedules              - List all schedules
GET  /api/schedules/active       - Get currently active schedules
```

---

### ✅ **Consistent JSON Response Format**

```json
{
    "success": true,
    "message": "Profile retrieved successfully",
    "timestamp": "2025-12-29T07:00:00+00:00",
    "data": {
        "name": "Vishesh",
        "daily_limit": 240,
        "usage_today": 45
    }
}
```

**On Error:**
```json
{
    "success": false,
    "message": "Profile not found",
    "timestamp": "2025-12-29T07:00:00+00:00",
    "error": {
        "code": "PROFILE_NOT_FOUND",
        "details": "No profile exists with name: InvalidProfile"
    }
}
```

---

### ✅ **CORS Headers for External Integration**

```php
// Enable CORS for API endpoints
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
```

---

## Security Patterns

### ✅ **Bypass-Proof Design**

**Think like an adversary.** Kids will try to bypass. Design accordingly.

**Common Bypass Attempts:**
1. **Device Switching** → Solution: Profile-level tracking
2. **MAC Spoofing** → Solution: Track by IP as well (with MAC-to-IP cache)
3. **Time Zone Changes** → Solution: Use server time, not client time
4. **Cache Poisoning** → Solution: Refresh cache periodically
5. **Parent Override** → Solution: Password-protected with timeout

```php
// ✅ GOOD: Multiple validation layers
function pc_should_block_device($mac) {
    // Layer 1: Check parent override (highest priority)
    if (pc_has_active_override($mac)) {
        return false;
    }
    
    // Layer 2: Check schedule (time-based blocking)
    if (pc_is_in_blocked_schedule($mac)) {
        return true;
    }
    
    // Layer 3: Check profile time limit (usage-based blocking)
    $profile = pc_get_device_profile($mac);
    if (pc_is_time_limit_exceeded($profile)) {
        return true;
    }
    
    return false;
}
```

---

### ✅ **Parent Override with Auto-Expiry**

**Password-protected overrides** that automatically expire:

```php
function pc_grant_override($mac, $duration_minutes) {
    $override = array(
        'mac' => $mac,
        'granted_at' => time(),
        'expires_at' => time() + ($duration_minutes * 60),
        'granted_by' => $_SESSION['username'] ?? 'parent'
    );
    
    $overrides = pc_get_active_overrides();
    $overrides[] = $override;
    pc_save_overrides($overrides);
    
    pc_log("Override granted", 'info', array(
        'event.action' => 'override_granted',
        'user.name' => $mac,
        'duration.minutes' => $duration_minutes
    ));
}

// Cleanup expired overrides on every cron run
function pc_cleanup_expired_overrides(&$state) {
    $now = time();
    $state['overrides'] = array_filter($state['overrides'], function($override) use ($now) {
        return $override['expires_at'] > $now;
    });
}
```

---

## Performance Optimization

### ✅ **Avoid filter_configure() Unless Necessary**

**Problem:** `filter_configure()` reloads entire firewall (5-10 seconds).

**Solution:** Use pfSense anchors for dynamic rules.

```php
// ✅ GOOD: Update anchor rules (< 1 second)
function pc_apply_firewall_changes($changes) {
    foreach ($changes['to_block'] as $device_ip) {
        pc_add_anchor_rule($device_ip, 'block');
    }
    foreach ($changes['to_unblock'] as $device_ip) {
        pc_remove_anchor_rule($device_ip);
    }
    // Fast! No filter_configure() needed
}

// ❌ BAD: Full firewall reload
function pc_apply_firewall_changes($changes) {
    // Add rules to config.xml
    config_set_path('filter/rule', $rules);
    write_config('Parental Control: Updated rules');
    filter_configure();  // 5-10 second reload!
}
```

**When to use filter_configure():**
- Initial setup only
- Manual user changes in GUI
- Major configuration overhaul

**When NOT to use:**
- Cron jobs (every 5 minutes)
- Auto-updates
- Device state changes

---

### ✅ **Cron Job Frequency vs. Time Granularity**

**Balance precision with system load:**

```php
// Cron: Every 5 minutes
define('PC_CRON_MINUTE', '*/5');
define('PC_CRON_INTERVAL_MINUTES', 5);

// Track usage in 5-minute increments
$state['profiles'][$profile_name]['usage_today'] += 5;  // minutes
```

**Rationale:**
- 5 minutes is imperceptible to users (4 hrs = 240 min ± 5 min)
- Reduces system load vs. 1-minute checks
- Avoids AQM "flowset busy" errors from excessive filter updates
- Still accurate enough for daily limits

---

## Testing & Verification

### ✅ **Comprehensive Verification Reports**

**After major updates**, create verification report:

```markdown
# Verification Report: v1.0.0 → v1.1.2

## Version Information
- VERSION file: 1.1.2 ✅
- info.xml: 1.1.2 ✅
- All PHP pages: 1.1.2 ✅

## Features Implemented
### v1.1.0 - Shared Profile Time
- [x] Backend: pc_update_profile_usage() working
- [x] Frontend: Status page displays correctly
- [x] API: Returns profile-level usage
- [x] Docs: Complete explanation

## Cross-References
- [x] Status page ↔ API use same functions
- [x] Documentation matches code
- [x] Changelog reflects all changes
```

---

### ✅ **State File Inspection for Debugging**

**JSON state files are debuggable:**

```bash
# Quick inspection
cat /var/db/parental_control_state.json | jq .

# Check specific profile
cat /var/db/parental_control_state.json | jq '.profiles.Vishesh'

# Find devices online
cat /var/db/parental_control_state.json | jq '.devices_by_ip | to_entries[] | select(.value.last_seen > 1735459200)'
```

**Benefits:**
- No database access needed
- Human-readable
- Version control friendly
- Easy to backup/restore

---

## Data Structures

### ✅ **Hierarchical State Structure**

```php
$state = array(
    // Top-level metadata
    'last_update' => time(),
    'last_reset' => strtotime('today midnight'),
    'version' => '1.1.2',
    
    // Profile-level tracking (NEW in v1.1.0)
    'profiles' => array(
        'Vishesh' => array(
            'usage_today' => 45,      // minutes
            'usage_week' => 180,
            'last_update' => time()
        )
    ),
    
    // Device-level data
    'devices_by_ip' => array(
        '192.168.1.115' => array(
            'mac' => 'ca:96:f3:a7:26:15',
            'profile' => 'Vishesh',
            'last_seen' => time(),
            'status' => 'online'
        )
    ),
    
    // Fast MAC→IP lookup cache
    'mac_to_ip_cache' => array(
        'ca:96:f3:a7:26:15' => '192.168.1.115'
    ),
    
    // Active overrides
    'overrides' => array(
        array(
            'mac' => 'ca:96:f3:a7:26:15',
            'expires_at' => time() + 1800
        )
    )
);
```

**Benefits:**
- Logical grouping
- Easy to query
- Clear ownership
- Supports future extensions

---

## pfSense-Specific Best Practices

### ✅ **XML Path Functions (Not Direct Array Access)**

```php
// ✅ GOOD: Use config path functions
$profiles = config_get_path('installedpackages/parentalcontrol/config', array());
config_set_path('installedpackages/parentalcontrol/config/' . $id, $profile);

// ❌ BAD: Direct array access
global $config;
$profiles = $config['installedpackages']['parentalcontrol']['config'];
$config['installedpackages']['parentalcontrol']['config'][$id] = $profile;
```

**Why:**
- Handles missing keys gracefully
- Returns default values
- Prevents "Undefined index" errors
- Future-proof (pfSense API may change)

---

### ✅ **Check isAllowedPage() for Security**

```php
// At top of every PHP page
if (!isAllowedPage($_SERVER['SCRIPT_NAME'])) {
    header("Location: /");
    exit;
}
```

**Why:**
- Enforces pfSense privilege system
- Prevents unauthorized access
- Required for multi-user environments

---

### ✅ **Dual-Method Cron Installation**

**Problem:** `install_cron_job()` sometimes fails silently.

**Solution:** Try pfSense function first, fallback to direct crontab.

```php
function pc_setup_cron_job() {
    $cron_cmd = '/usr/local/bin/php /usr/local/bin/parental_control_cron.php';
    
    // Method 1: pfSense function (preferred)
    install_cron_job($cron_cmd, true, '*/5', '*', '*', '*', '*', 'root');
    
    // Method 2: Verify and fallback if needed
    $existing = shell_exec("crontab -l -u root 2>/dev/null | grep 'parental_control_cron'");
    if (empty($existing)) {
        // Fallback: Direct crontab manipulation
        $cron_entry = "*/5 * * * * $cron_cmd\n";
        shell_exec("(crontab -l -u root 2>/dev/null; echo '$cron_entry') | crontab -u root -");
        pc_log("Cron installed via fallback method", 'warning');
    }
}
```

---

## 📋 Quick Reference Checklist

Use this checklist when starting a new project:

### Version Management
- [ ] Single VERSION file (INI format)
- [ ] Automatic version loading in code
- [ ] Pre-commit hook enforces version bumps
- [ ] Semantic versioning (major.minor.patch)
- [ ] Version displayed in footers

### Logging
- [ ] JSONL format (one JSON per line)
- [ ] ECS-compliant field names
- [ ] Context-rich log entries
- [ ] Log rotation configured

### UI/UX
- [ ] No white text (except on black backgrounds)
- [ ] Fixed dropdown sizes (not dynamic)
- [ ] Consistent footers with version
- [ ] WCAG contrast ratios met
- [ ] Responsive design

### State Management
- [ ] Atomic file writes (temp + rename)
- [ ] Profile-level tracking (not per-device)
- [ ] Backward compatibility normalization
- [ ] JSON for state, XML for config

### Error Handling
- [ ] Try-catch around write_config()
- [ ] Separate GUI saves from background sync
- [ ] Graceful degradation
- [ ] User-friendly error messages

### Code Organization
- [ ] PHP pages for complex forms
- [ ] Prefixed functions (pc_*)
- [ ] Reusable utility functions
- [ ] Clear separation of concerns

### Documentation
- [ ] Maximum 4 consolidated files
- [ ] Code references with line numbers
- [ ] Comments explain WHY not WHAT
- [ ] README with quick navigation

### Git Practices
- [ ] Semantic commit messages
- [ ] Version in commit messages
- [ ] Document with code changes
- [ ] Meaningful branch names

### API Design
- [ ] RESTful endpoints
- [ ] Consistent JSON response format
- [ ] CORS headers for integration
- [ ] API documentation in code

### Security
- [ ] Bypass-proof design
- [ ] Password-protected overrides
- [ ] Auto-expiry for temporary access
- [ ] Multiple validation layers

### Performance
- [ ] Avoid filter_configure() where possible
- [ ] Use pfSense anchors for dynamic rules
- [ ] Optimal cron frequency (5 min)
- [ ] Efficient state structure

---

## 🎓 Lessons Learned Summary

### Top 10 Non-Obvious Insights

1. **JSONL beats plain text logs** - Searchable, parseable, analyzable
2. **White text is evil** - Dark text on light backgrounds always
3. **Dynamic dropdown sizes = bad UX** - Fixed size = consistency
4. **Profile-level tracking = bypass-proof** - Device-level = easily bypassed
5. **Temp + rename = crash-resistant** - Direct writes = corruption risk
6. **Try-catch write_config()** - CLI context fails differently than GUI
7. **Separate GUI saves from sync** - Avoid timeouts, better UX
8. **PHP beats XML for complexity** - XML is great until it's not
9. **4 docs > 14 docs** - Consolidation wins
10. **Dual cron installation** - Fallback prevents silent failures

---

## 📚 References & Further Reading

### Standards
- **ECS (Elastic Common Schema):** https://www.elastic.co/guide/en/ecs/current/
- **Semantic Versioning:** https://semver.org/
- **WCAG (Accessibility):** https://www.w3.org/WAI/WCAG21/quickref/
- **RESTful API Design:** https://restfulapi.net/

### pfSense
- **pfSense Developer Docs:** https://docs.netgate.com/pfsense/en/latest/development/
- **pfSense Anchors:** https://www.openbsd.org/faq/pf/anchors.html

### Git
- **Conventional Commits:** https://www.conventionalcommits.org/

---

## 💡 Contributing to This Document

Found a new best practice? Add it! This is a living document.

**Format:**
```markdown
### ✅ **Your Best Practice Title**

**Problem:** What problem does this solve?

**Solution:** What's the better approach?

```code example```

**Benefits:**
- Benefit 1
- Benefit 2
```

---

**Last Updated:** January 18, 2026  
**Project:** KACI Parental Control  
**Version:** 1.4.x

---

**Use these practices. Avoid the pitfalls. Build better software.** 🚀

