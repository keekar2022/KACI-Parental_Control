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

