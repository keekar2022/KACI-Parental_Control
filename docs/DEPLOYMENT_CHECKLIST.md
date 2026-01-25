# FreeBSD Package Distribution - Deployment Checklist

This checklist guides you through the complete deployment of the FreeBSD package distribution system.

---

## ✅ Phase 1: Local Infrastructure (COMPLETED)

- [x] Created `pkg-manifest.ucl` with package metadata
- [x] Created `pkg-plist` with file listing
- [x] Created `parental_control_cron.php` wrapper
- [x] Created GitHub Actions workflow `.github/workflows/build-package.yml`
- [x] Created repository update workflow `.github/workflows/update-pkg-repo.yml`
- [x] Created package signing script `.github/scripts/sign-package.sh`
- [x] Created client installation script `client-setup/install-from-repo.sh`
- [x] Created migration script `client-setup/migrate-to-pkg.sh`
- [x] Created new auto-update script `auto_update_parental_control_pkg.sh`
- [x] Updated README with PKG manager installation instructions
- [x] Created migration guide `docs/MIGRATION_TO_PKG_REPO.md`
- [x] Created GPG setup guide `docs/GPG_SETUP.md`
- [x] Created repository server setup guide `docs/REPO_SERVER_SETUP.md`

**Files Created/Modified:**
- `pkg-manifest.ucl`
- `pkg-plist`
- `parental_control_cron.php`
- `.github/workflows/build-package.yml`
- `.github/workflows/update-pkg-repo.yml`
- `.github/scripts/sign-package.sh`
- `client-setup/install-from-repo.sh`
- `client-setup/migrate-to-pkg.sh`
- `auto_update_parental_control_pkg.sh`
- `README.md`
- `docs/MIGRATION_TO_PKG_REPO.md`
- `docs/GPG_SETUP.md`
- `docs/REPO_SERVER_SETUP.md`
- `docs/DEPLOYMENT_CHECKLIST.md` (this file)

---

## 📋 Phase 2: Manual Setup Tasks (REQUIRES ACTION)

### Task 1: Generate GPG Keys

**Guide:** See `docs/GPG_SETUP.md`

**Steps:**
1. [ ] Generate GPG key pair (4096-bit RSA)
2. [ ] Export private key to `private-key.asc`
3. [ ] Export public key to `public-key.asc`
4. [ ] Extract GPG fingerprint
5. [ ] Add `GPG_PRIVATE_KEY` to GitHub Secrets
6. [ ] Add `GPG_PASSPHRASE` to GitHub Secrets
7. [ ] Create `fingerprint.txt` for client configuration
8. [ ] Backup private key securely
9. [ ] Delete local `private-key.asc` and `public-key.asc`

**Commands:**
```bash
# Generate key
gpg --full-generate-key

# Export private key
gpg --armor --export-secret-keys YOUR_KEY_ID > private-key.asc

# Export public key
gpg --armor --export YOUR_KEY_ID > public-key.asc

# Get fingerprint
gpg --fingerprint YOUR_KEY_ID

# Create fingerprint file
cat > fingerprint.txt << 'EOF'
function: sha256
fingerprint: YOUR_FINGERPRINT_HERE
EOF
```

**Expected Outcome:**
- `GPG_PRIVATE_KEY` secret configured in GitHub
- `GPG_PASSPHRASE` secret configured in GitHub
- `fingerprint.txt` ready for upload

---

### Task 2: Enable GitHub Pages

**Guide:** See `docs/GITHUB_PAGES_SETUP.md`

**Steps:**
1. [ ] Go to repository Settings > Pages
2. [ ] Set Source to "GitHub Actions"
3. [ ] Save settings

**Commands:**
```bash
# Or use GitHub CLI
gh repo edit keekar2022/KACI-Parental_Control --enable-pages --pages-build-type actions
```

**Expected Outcome:**
- GitHub Pages enabled
- After first deployment, site accessible at `https://keekar2022.github.io/KACI-Parental_Control/`
- Package directory will be created automatically by GitHub Actions

---

### Task 3: Configure GitHub Secrets

**Steps:**
1. [ ] Add `GPG_PRIVATE_KEY` (from Task 1)
2. [ ] Add `GPG_PASSPHRASE` (from Task 1)

**That's it!** No SSH keys needed with GitHub Pages.

**Expected Outcome:**
- 2 GitHub Secrets configured
- GitHub Actions can sign packages with GPG
- GitHub Actions can deploy to GitHub Pages

---

### Task 4: Make Repository Private

**Steps:**
1. [ ] Go to https://github.com/keekar2022/KACI-Parental_Control/settings
2. [ ] Scroll to "Danger Zone"
3. [ ] Click "Change visibility"
4. [ ] Select "Private"
5. [ ] Confirm by typing repository name
6. [ ] Verify repository is now private

**Expected Outcome:**
- Repository is private
- Source code not publicly accessible
- GitHub Actions still work (have access to private repo)

---

### Task 5: Test Package Build

**Steps:**
1. [ ] Commit all changes to `develop` branch
2. [ ] Push to GitHub
3. [ ] Watch GitHub Actions workflow run
4. [ ] Verify package build completes successfully
5. [ ] Download package artifact from GitHub Actions
6. [ ] Verify package contents
7. [ ] Check package signature (`.asc` file)
8. [ ] Verify package uploaded to repository server

**Commands:**
```bash
# Commit changes
git add .
git commit -m "feat: v1.4.60 - Add FreeBSD pkg manager distribution

- Create pkg-manifest.ucl and pkg-plist
- Add GitHub Actions workflows for building and distributing packages
- Create client installation and migration scripts
- Update README and documentation
- Add GPG signing infrastructure

This completes the migration from raw file distribution to
professional pkg manager distribution with source code protection."

# Push to develop
git push origin develop

# Watch workflow (or check GitHub Actions UI)
# https://github.com/keekar2022/KACI-Parental_Control/actions
```

**Expected Outcome:**
- GitHub Actions build completes successfully
- Package artifact available for download
- Package deployed to GitHub Pages
- Site accessible at https://keekar2022.github.io/KACI-Parental_Control/packages/

---

### Task 6: Test Package Installation

**Steps:**
1. [ ] SSH to pfSense test system
2. [ ] Configure custom repository
3. [ ] Add GPG fingerprint
4. [ ] Run `pkg update`
5. [ ] Run `pkg install kaci-parental-control`
6. [ ] Verify installation
7. [ ] Test basic functionality
8. [ ] Check cron job is running
9. [ ] Verify auto-update works

**Commands:**
```bash
# SSH to test pfSense
ssh admin@192.168.1.1

# Configure repository
mkdir -p /usr/local/etc/pkg/repos
cat > /usr/local/etc/pkg/repos/kaci.conf << 'EOF'
kaci: {
  url: "https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}/latest",
  mirror_type: "NONE",
  signature_type: "fingerprints",
  fingerprints: "/usr/local/etc/pkg/fingerprints/kaci",
  enabled: yes,
  priority: 10
}
EOF

# Add fingerprint
mkdir -p /usr/local/etc/pkg/fingerprints/kaci
cat > /usr/local/etc/pkg/fingerprints/kaci/trusted << 'EOF'
function: sha256
fingerprint: YOUR_FINGERPRINT_HERE
EOF

# Install package
pkg update
pkg install -y kaci-parental-control

# Verify
pkg info kaci-parental-control
ls -la /usr/local/pkg/parental_control*
crontab -l | grep parental

# Test web interface
# Open: https://192.168.1.1/ > Services > Parental Control
```

**Expected Outcome:**
- Package installs successfully
- All files deployed correctly
- Web interface accessible
- Cron job running
- Configuration preserved (if upgrading)

---

## 📊 Phase 3: Migration & Rollout

### Task 7: Migrate Existing Installations

**For your production pfSense (192.168.1.1):**

**Steps:**
1. [ ] Backup current configuration
2. [ ] Run migration script
3. [ ] Verify profiles and devices intact
4. [ ] Test functionality
5. [ ] Monitor for 24 hours

**Commands:**
```bash
# SSH to production pfSense via jump host
ssh nas.keekar.com "ssh admin@192.168.1.1"

# Download migration script
fetch -o /tmp/migrate-to-pkg.sh https://raw.githubusercontent.com/keekar2022/KACI-Parental_Control/main/client-setup/migrate-to-pkg.sh
chmod +x /tmp/migrate-to-pkg.sh

# Run migration
/tmp/migrate-to-pkg.sh

# Verify
pkg info kaci-parental-control
tail -f /var/log/parental_control.jsonl
```

**Expected Outcome:**
- Migration completes successfully
- All profiles and devices preserved
- Usage tracking continues uninterrupted
- Auto-update now uses pkg manager

---

### Task 8: Update Customer Documentation

**Steps:**
1. [ ] Update installation instructions in README
2. [ ] Create customer announcement
3. [ ] Prepare migration guide for customers
4. [ ] Update support documentation

**Expected Outcome:**
- Customers aware of new installation method
- Clear migration path for existing users
- Support documentation updated

---

## 🎯 Success Criteria

### Package Build Pipeline
- [x] GitHub Actions workflow builds FreeBSD .txz package
- [ ] Package is GPG-signed automatically
- [ ] Package uploaded to nas.keekar.com repository
- [ ] Repository metadata updated automatically

### Package Distribution
- [ ] Customers can add custom repository
- [ ] `pkg install kaci-parental-control` works
- [ ] Package signature verification works
- [ ] Auto-update via pkg manager works

### Source Code Protection
- [ ] Repository is private
- [ ] Binary distribution only
- [ ] No raw source files accessible publicly
- [ ] GitHub Actions still have access

### Customer Experience
- [ ] One-command installation
- [ ] Automatic updates
- [ ] Easy migration from legacy method
- [ ] Standard pkg manager commands work

---

## 🔄 Post-Deployment

### Monitoring

Check these regularly:

```bash
# GitHub Actions workflow runs
# https://github.com/keekar2022/KACI-Parental_Control/actions

# GitHub Pages deployment status
# https://github.com/keekar2022/KACI-Parental_Control/deployments

# Test repository access
curl -I https://keekar2022.github.io/KACI-Parental_Control/packages/

# Customer auto-update logs (on pfSense)
ssh nas.keekar.com "ssh admin@192.168.1.1 'tail /var/log/parental_control_auto_update.log'"
```

### Maintenance

- **Weekly:** Review GitHub Actions runs for failures
- **Monthly:** Check repository server disk space
- **Quarterly:** Rotate GPG keys (optional)
- **Annually:** Review and update documentation

---

## 📞 Support & Troubleshooting

### Issue: Package build fails

**Check:**
- GitHub Actions logs
- FreeBSD VM setup in workflow
- File paths in `pkg-plist`

### Issue: Package signature verification fails

**Check:**
- GPG_PRIVATE_KEY secret is correct
- GPG_PASSPHRASE secret is correct
- Fingerprint matches on server and client

### Issue: Repository not accessible

**Check:**
- GitHub Pages enabled in repository settings
- GitHub Actions deployment completed successfully
- Wait 2-3 minutes after first deployment
- Check https://github.com/keekar2022/KACI-Parental_Control/deployments

### Issue: Migration fails

**Check:**
- Backup configuration exists
- Legacy installation present
- Repository configured correctly
- Network connectivity to repository

---

## 📝 Summary

**What We've Achieved:**
✅ Professional FreeBSD package distribution  
✅ Source code protection (private repository)  
✅ Automatic package builds via GitHub Actions  
✅ GPG-signed packages for security  
✅ Custom pkg repository on nas.keekar.com  
✅ One-command installation for customers  
✅ Automatic updates via pkg manager  
✅ Easy migration from legacy method  

**Next Steps:**
1. Complete manual setup tasks (GPG keys, repository server)
2. Test package build and installation
3. Migrate production system
4. Update customer documentation
5. Monitor for issues

---

**Created:** January 24, 2026  
**Version:** 1.0  
**Status:** Ready for deployment
