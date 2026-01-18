# Development Tools - KACI Parental Control

## Overview

Development tools for maintaining and releasing the KACI Parental Control package. These are **not** deployed to pfSense - they're used during development only.

## Development Scripts

### 1. bump_version.sh
**Purpose**: Automated version bumping and release management

**What it does**:
- Updates version in all files (VERSION, info.xml, BUILD_INFO.json, etc.)
- Adds changelog entry
- Optionally commits and pushes to GitHub
- **Validates INSTALL/UNINSTALL synchronization** before release

**Usage**:
```bash
./bump_version.sh <version> "<changelog>"

# Examples
./bump_version.sh 1.4.3 "Fixed port alias error"
./bump_version.sh 1.5.0 "Added new enforcement mode"
```

**Files Updated**:
- `VERSION`
- `info.xml`
- `parental_control.inc`
- `parental_control.xml`
- `BUILD_INFO.json`

**Post-Bump Actions**:
1. Prompts to commit changes
2. Prompts to push to GitHub
3. **Runs validate_cleanup.sh automatically**

### 2. Built-in Validation Function
**Purpose**: Ensure INSTALL.sh and UNINSTALL.sh are synchronized

**Integrated into**: `bump_version.sh` (runs automatically)

**What it checks**:
- ✅ All installed files have corresponding removal in UNINSTALL.sh
- ✅ Port aliases created in code are removed by UNINSTALL.sh
- ✅ Configuration paths are cleaned up
- ✅ Firewall rules are removed
- ✅ No leftover files after uninstallation

**When it runs**:
- Automatically during every version bump
- Cannot be skipped (ensures quality)

**Output** (during bump_version.sh):
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Validating INSTALL/UNINSTALL Synchronization
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

1. Checking installed files...
   Found 6 files installed by INSTALL.sh
   Found 25 removal patterns in UNINSTALL.sh

2. Checking port alias synchronization...
   ✓ All 2 port aliases have cleanup code

3. Checking configuration cleanup...
   ✓ All 1 config paths have cleanup code

4. Checking firewall rule cleanup...
   ✓ UNINSTALL removes all rules matching 'Parental Control'

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Synchronization Check Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ No synchronization issues detected
✅ INSTALL.sh and UNINSTALL.sh appear to be synchronized
```

**Note**: This validation function is built into `bump_version.sh` and cannot be run separately. This ensures every release is validated.

## Core Package Scripts

These **ARE** deployed to pfSense:

### 1. INSTALL.sh
- Installation and updates
- File deployment
- Configuration setup
- Auto-update configuration

### 2. UNINSTALL.sh
- Complete package removal
- Removes all installed files
- Cleans up configurations
- Removes firewall rules and aliases

### 3. auto_update_parental_control.sh
- Automatic updates from GitHub
- Manual update trigger
- Deployed to `/usr/local/bin/` on pfSense

### 4. parental_control_analyzer.sh
- Log analysis and diagnostics
- System status checks
- Reset counters
- Installation verification
- Deployed to `/usr/local/bin/` on pfSense

### 5. parental_control_captive.sh
- FreeBSD RC script
- Manages captive portal server
- Deployed to `/usr/local/etc/rc.d/` on pfSense

## Development Workflow

### Making Changes

#### Adding a New File

1. **Add to INSTALL.sh**:
   ```bash
   # In upload section
   "$PACKAGE_DIR/my_new_file.php" \
   
   # In move section
   sudo -n mv /tmp/my_new_file.php /usr/local/www/ && \
   ```

2. **Add to UNINSTALL.sh**:
   ```bash
   # In appropriate removal section
   rm -f /usr/local/www/my_new_file.php
   ```

3. **Validate** (automatic during version bump):
   ```bash
   ./bump_version.sh 1.4.3 "Added new file"
   # Validation runs automatically
   ```

#### Adding a New Port Alias

1. **Add to parental_control.inc**:
   ```php
   $new_alias = array(
       'name' => 'KACI_PC_NewAlias',
       'type' => 'port',
       'address' => '8080 8443',
       //...
   );
   ```

2. **Add to UNINSTALL.sh**:
   ```php
   // In alias removal section
   $pc_aliases = ['parental_control_blocked', 'KACI_PC_Ports', 'KACI_PC_Web', 'KACI_PC_NewAlias'];
   ```

3. **Validate**:
   ```bash
   ./validate_cleanup.sh
   ```

### Release Process

1. **Make your changes**
2. **Test thoroughly**
3. **Bump version** (validation runs automatically):
   ```bash
   ./bump_version.sh 1.4.3 "Your changelog message"
   ```
4. **Review validation results** (automatic)
5. **Review changes**
6. **Commit** (prompted by bump_version.sh)
7. **Push to GitHub** (prompted by bump_version.sh)

## Troubleshooting

### Validation Fails

If `validate_cleanup.sh` reports issues:

**Issue**: File installed but not removed
```
⚠ May not be removed: /usr/local/bin/my_script.sh
```

**Solution**:
1. Edit UNINSTALL.sh
2. Add removal line:
   ```bash
   rm -f /usr/local/bin/my_script.sh
   ```
3. Run validation again

**Issue**: Port alias created but not removed
```
⚠ Alias KACI_PC_MyAlias created but not removed
```

**Solution**:
1. Edit UNINSTALL.sh
2. Add to `$pc_aliases` array:
   ```php
   $pc_aliases = ['parental_control_blocked', 'KACI_PC_Ports', 'KACI_PC_Web', 'KACI_PC_MyAlias'];
   ```
3. Run validation again

## File Structure

```
KACI-Parental_Control/
├── Development Tools (NOT deployed)
│   └── bump_version.sh           ← Version management + validation
│
├── Core Scripts (Deployed to pfSense)
│   ├── INSTALL.sh
│   ├── UNINSTALL.sh
│   ├── auto_update_parental_control.sh
│   ├── parental_control_analyzer.sh
│   └── parental_control_captive.sh
│
├── Documentation (10 files)
│   └── docs/
│       ├── BEST_PRACTICES_KACI.md
│       ├── DEVELOPMENT_TOOLS.md
│       ├── GETTING_STARTED.md
│       ├── TECHNICAL_REFERENCE.md
│       ├── USER_GUIDE.md
│       └── ...
│
└── Package Files (Deployed to pfSense)
    ├── parental_control.inc
    ├── parental_control.xml
    ├── parental_control_*.php
    └── ...
```

## Best Practices

### 1. Always Validate
Run `validate_cleanup.sh` after:
- Adding new files to INSTALL.sh
- Creating new aliases in code
- Modifying UNINSTALL.sh
- Before every release

### 2. Keep INSTALL/UNINSTALL Synchronized
- Every file added in INSTALL.sh must be removed in UNINSTALL.sh
- Every alias created must be removed
- Every config path set must be unset
- Test uninstall on a test system

### 3. Document Changes
- Update BUILD_INFO.json changelog
- Update version in all files via bump_version.sh
- Add documentation for new features

### 4. Test Before Release
1. Test installation on clean system
2. Test functionality
3. Test uninstallation (verify no leftovers)
4. Run validation script
5. Then bump version and release

## Automation

### Pre-Release Checklist (Automated by bump_version.sh)

When you run `bump_version.sh`, it automatically:
1. ✅ Updates all version files
2. ✅ Updates changelog
3. ✅ Runs `validate_cleanup.sh`
4. ✅ Prompts for commit
5. ✅ Prompts for push to GitHub

This ensures you can't release without validating synchronization.

## Summary

**Development Tools** (1 script):
- `bump_version.sh` - Version management with built-in validation

**Core Package Scripts** (5 scripts):
- 4 main scripts (INSTALL, UNINSTALL, auto_update, analyzer)
- 1 RC script (captive.sh)

**Documentation** (10 files):
- Consolidated into essential guides
- No duplicate or redundant documentation

**Key Principle**: 
> Every file installed must be uninstalled. No leftovers.

The built-in validation function in `bump_version.sh` enforces this principle automatically before every release.

---

**Version**: 1.4.x  
**Date**: 2026-01-18  
**Type**: Development Documentation

