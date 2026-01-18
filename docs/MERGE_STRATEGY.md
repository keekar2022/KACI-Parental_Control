# Merge Strategy: Develop → Main

**Version:** 1.4.x  
**Purpose:** Safe merging of experimental features to production while excluding development tools

---

## Overview

The `develop` branch contains experimental features and development tools that are useful for testing but should NOT be in the production `main` branch.

**Key Rule:** The `diagnostic/` folder must NEVER be merged to `main`.

---

## Directory Structure

### What's in `diagnostic/` (Develop Only)

The `diagnostic/` folder contains development and testing tools:

- **Sync Tools:** `sync_production_data.sh`, `setup_sync_cron.sh`
- **Fix Scripts:** `fix_*.sh`, `fix_*.php`
- **Diagnostic Tools:** `parental_control_diagnostic.php`, `diagnose_reset.sh`
- **Test Scripts:** `test_http_hijacking.sh`, `deploy_*.sh`
- **Documentation:** `PRODUCTION_SYNC_UPDATED.md`, `ONLINE_SERVICES_EXPERIMENTAL.md`

**Why Exclude?**
- These are development/testing tools, not production features
- They may contain experimental or unstable code
- They're specific to our development environment (192.168.1.251)
- They increase package size unnecessarily

---

## Safe Merge Procedure

### Method 1: Interactive Merge with Exclusion (Recommended)

```bash
# 1. Switch to main branch
git checkout main

# 2. Start merge but don't commit
git merge develop --no-commit --no-ff

# 3. Remove diagnostic folder from staging
git reset HEAD diagnostic/

# 4. Remove diagnostic folder from working tree
git checkout HEAD -- diagnostic/

# 5. Verify diagnostic is not included
git status
# Should show: "Changes to be committed" (without diagnostic/)

# 6. Commit the merge
git commit -m "Merge develop into main (excluding diagnostic/)"

# 7. Verify diagnostic folder is not in main
git ls-tree -r main --name-only | grep "^diagnostic/"
# Should return nothing (0 files)
```

### Method 2: Use Merge Helper Script (Automated)

```bash
# Run the provided helper script
./merge_develop_to_main.sh

# The script will:
# - Check you're on main branch
# - Merge develop with --no-commit
# - Remove diagnostic/ folder
# - Prompt for commit message
# - Verify exclusion
```

### Method 3: Cherry-Pick Specific Commits

For fine-grained control, cherry-pick commits one by one:

```bash
git checkout main

# List commits in develop not in main
git log main..develop --oneline

# Cherry-pick specific commits (excluding diagnostic-related ones)
git cherry-pick <commit-hash>
git cherry-pick <commit-hash>
# ... continue for each desired commit
```

---

## Verification Steps

After merging, always verify:

### 1. Check No Diagnostic Files in Main

```bash
git checkout main
git ls-tree -r main --name-only | grep "^diagnostic/"
```

**Expected:** No output (0 files)

### 2. Check Diagnostic Still in Develop

```bash
git checkout develop
git ls-tree -r develop --name-only | grep "^diagnostic/" | wc -l
```

**Expected:** Should show count (20+ files)

### 3. Verify Merge Content

```bash
git checkout main
git log --oneline -10
```

**Expected:** Recent commits from develop WITHOUT diagnostic-related commits

---

## What TO Merge

These should be merged from develop to main:

✅ **Core Files:**
- `parental_control.inc`
- `parental_control_*.php` (UI files)
- `parental_control.xml`
- `INSTALL.sh`, `UNINSTALL.sh`
- `VERSION`, `info.xml`

✅ **Documentation:**
- `README.md`
- `docs/*.md` (user-facing docs)
- `LICENSE`

✅ **Production Features:**
- Online Services Management
- Service Monitoring
- HTTP Hijacking Block Page
- All bug fixes and improvements

---

## What NOT to Merge

These should NEVER be in main:

❌ **Development Tools:**
- `diagnostic/` folder (entire directory)
- Development-specific scripts
- Test fixtures

❌ **Experimental Docs:**
- `diagnostic/*.md` (experimental documentation)
- Internal testing notes

❌ **Environment-Specific:**
- Production firewall IPs (192.168.1.1)
- Test firewall configs (192.168.1.251)

---

## Common Mistakes to Avoid

### ❌ Don't: Direct Merge Without Exclusion

```bash
git checkout main
git merge develop  # ← DON'T DO THIS!
```

**Problem:** Will merge everything including diagnostic/

### ❌ Don't: Delete diagnostic/ After Merge

```bash
git merge develop
git rm -rf diagnostic/
git commit -m "Remove diagnostic"
```

**Problem:** Git history will show diagnostic was added then removed (messy)

### ✅ Do: Exclude During Merge

```bash
git merge develop --no-commit
git reset HEAD diagnostic/
git checkout HEAD -- diagnostic/
git commit
```

**Benefit:** Clean history, diagnostic never enters main

---

## Automated Helper Script

We provide `merge_develop_to_main.sh` to automate safe merging:

```bash
#!/bin/bash
# Location: ./merge_develop_to_main.sh

set -e

# Check we're on main
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    echo "Error: Must be on main branch"
    exit 1
fi

echo "Starting safe merge: develop → main"
echo "Excluding: diagnostic/ folder"
echo ""

# Merge without committing
git merge develop --no-commit --no-ff

# Remove diagnostic from staging and working tree
git reset HEAD diagnostic/
git checkout HEAD -- diagnostic/

echo ""
echo "Files to be committed:"
git diff --cached --name-only | grep -v "^diagnostic/"
echo ""

read -p "Proceed with commit? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    git commit -m "Merge develop into main (excluding diagnostic/)"
    echo ""
    echo "✅ Merge complete!"
    echo ""
    echo "Verification:"
    DIAGNOSTIC_COUNT=$(git ls-tree -r main --name-only | grep -c "^diagnostic/" || echo "0")
    if [ "$DIAGNOSTIC_COUNT" -eq 0 ]; then
        echo "✅ Confirmed: No diagnostic/ files in main"
    else
        echo "❌ Warning: Found $DIAGNOSTIC_COUNT diagnostic files in main!"
    fi
else
    echo "Merge aborted. Run 'git merge --abort' to clean up."
fi
```

---

## Emergency: If Diagnostic Was Merged to Main

If you accidentally merged diagnostic/ to main:

### Option 1: Revert the Merge (If Not Pushed)

```bash
git reset --hard HEAD~1  # Go back before merge
# Then redo merge properly
```

### Option 2: Remove in New Commit (If Already Pushed)

```bash
git checkout main
git rm -rf diagnostic/
git commit -m "Remove diagnostic/ folder from main (should only be in develop)"
git push origin main
```

### Option 3: Rebase to Remove (Advanced)

```bash
# Use interactive rebase to edit history
git rebase -i HEAD~5
# Mark the merge commit as 'edit'
# Remove diagnostic/ folder
# Continue rebase
```

---

## .gitignore Configuration

The `.gitignore` file includes:

```gitignore
# Development and diagnostic tools (exclude from main branch)
# Note: These are tracked in develop branch but should NOT be merged to main
# See docs/MERGE_STRATEGY.md for proper merge procedure
diagnostic/
```

**Note:** This prevents accidentally adding NEW files to diagnostic/ when on main branch, but doesn't untrack existing files in develop.

---

## Branch Strategy Summary

```
main (production)
  ├─ Core features only
  ├─ Stable, tested code
  ├─ No diagnostic/ folder
  └─ Version: 1.4.10, 1.5.0, etc.

develop (development)
  ├─ All features + experimental
  ├─ Includes diagnostic/ folder
  ├─ Testing environment tools
  └─ Version: 1.4.10+, 1.5.0+, etc.

experimental/* (feature branches)
  └─ Short-lived feature branches
```

---

## Checklist Before Merging

- [ ] All tests pass on develop branch
- [ ] Experimental features are stable enough for production
- [ ] Version number updated in `VERSION` file
- [ ] Documentation updated in `docs/`
- [ ] `diagnostic/` folder confirmed NOT needed in production
- [ ] Merge procedure reviewed
- [ ] Verification steps prepared

---

## Questions?

**Q: Why not just remove diagnostic/ from develop?**  
**A:** We need it for development and testing! It's useful in develop, just not in main.

**Q: Can we use .gitattributes to exclude it?**  
**A:** `.gitattributes` with `export-ignore` only affects `git archive`, not merges.

**Q: What if I need a diagnostic tool in production?**  
**A:** Promote it to a production feature by moving it out of `diagnostic/` and into the main codebase.

---

**Last Updated:** January 6, 2026  
**Maintained By:** Development Team  
**See Also:** `README.md`, `CONTRIBUTING.md`

