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

