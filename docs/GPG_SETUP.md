# GPG Key Setup for Package Signing

This guide explains how to generate GPG keys and configure them for automatic package signing in GitHub Actions.

---

## Step 1: Generate GPG Key

Run these commands on your local machine:

```bash
# Generate a new GPG key
gpg --full-generate-key
```

When prompted:
- **Key type:** (1) RSA and RSA (default)
- **Key size:** 4096
- **Expiration:** 0 = key does not expire (or set custom expiration)
- **Real name:** KACI Parental Control
- **Email:** mkesharw@keekar.com
- **Comment:** Package Signing Key

**Set a strong passphrase when prompted.**

---

## Step 2: Export Keys

```bash
# List keys to get Key ID
gpg --list-secret-keys --keyid-format LONG

# Output will show:
# sec   rsa4096/ABCD1234ABCD1234 2026-01-24 [SC]
#       ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234
# uid                 [ultimate] KACI Parental Control <mkesharw@keekar.com>

# Replace ABCD1234ABCD1234 with your Key ID below

# Export private key (for GitHub Secrets)
gpg --armor --export-secret-keys ABCD1234ABCD1234 > private-key.asc

# Export public key (for fingerprint)
gpg --armor --export ABCD1234ABCD1234 > public-key.asc

# Get fingerprint
gpg --fingerprint ABCD1234ABCD1234
```

---

## Step 3: Add to GitHub Secrets

### Navigate to Repository Settings

1. Go to https://github.com/keekar2022/KACI-Parental_Control
2. Click **Settings** > **Secrets and variables** > **Actions**
3. Click **New repository secret**

### Add GPG_PRIVATE_KEY

- **Name:** `GPG_PRIVATE_KEY`
- **Value:** Paste contents of `private-key.asc`

```bash
# Copy to clipboard (macOS)
cat private-key.asc | pbcopy

# Or just display and manually copy
cat private-key.asc
```

### Add GPG_PASSPHRASE

- **Name:** `GPG_PASSPHRASE`
- **Value:** The passphrase you set during key generation

**Note:** No SSH key needed! Deployment is handled directly by GitHub Actions to GitHub Pages.

---

## Step 4: Generate Fingerprint for Clients

```bash
# Get SHA256 fingerprint
gpg --with-fingerprint --with-colons public-key.asc | grep fpr | cut -d: -f10
```

Example output:
```
7F066616F4E6AFA912A6B418E511980F2F261ED5
```

Create fingerprint file for pkg repository:

```bash
# Create fingerprint file (replace with your actual fingerprint)
cat > fingerprint.txt << 'EOF'
function: sha256
fingerprint: 7F066616F4E6AFA912A6B418E511980F2F261ED5
EOF
```

**Note:** The `fingerprint.txt` file will be automatically deployed to GitHub Pages at `/fingerprints/kaci/trusted` when packages are built. End users will download this file during installation.

---

## Step 5: Verify Setup

### Test GPG Key Import (Local)

```bash
# Import the exported key
gpg --delete-secret-keys "KACI Parental Control"  # Delete test key
gpg --import private-key.asc

# Verify
gpg --list-secret-keys
```

### Test Signing (Local)

```bash
# Create test file
echo "test" > test.txt

# Sign it
gpg --armor --detach-sign test.txt

# Verify signature
gpg --verify test.txt.asc test.txt

# Should output: "Good signature from KACI Parental Control"
```

---

## Step 6: Add Secrets to GitHub (Developer - macOS/Linux)

**IMPORTANT:** This step is for developers ONLY. You need to add your GPG keys to GitHub Secrets.

### 6.1 Copy Private Key

```bash
# Copy private key to clipboard (macOS)
cat private-key.asc | pbcopy

# Or display it (all platforms)
cat private-key.asc
```

### 6.2 Add to GitHub

1. Go to https://github.com/keekar2022/KACI-Parental_Control/settings/secrets/actions
2. Click **New repository secret**
3. Add `GPG_PRIVATE_KEY` - paste the entire contents of `private-key.asc`
4. Add `GPG_PASSPHRASE` - enter the passphrase you used when creating the key

### 6.3 Test the Setup

Trigger a build to test GPG signing:

```bash
# Commit the fingerprint.txt file
git add fingerprint.txt
git commit -m "Add GPG fingerprint for package verification"
git push origin develop

# Monitor the GitHub Actions workflow
# Check: https://github.com/keekar2022/KACI-Parental_Control/actions
```

The workflow should:
- Build the package
- Sign it with GPG (creates `.pkg.asc` file)
- Deploy to GitHub Pages including the fingerprint

---

## Step 7: End User Installation (FreeBSD/pfSense Systems ONLY)

**IMPORTANT:** This section is for END USERS installing the package on FreeBSD/pfSense, NOT for developers on macOS.

End users will configure their systems with:

```bash
# Configure repository
mkdir -p /usr/local/etc/pkg/repos
cat > /usr/local/etc/pkg/repos/kaci.conf << 'EOF'
kaci: {
  url: "https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}/latest",
  mirror_type: "none",
  signature_type: "fingerprints",
  fingerprints: "/usr/local/etc/pkg/fingerprints/kaci",
  enabled: yes,
  priority: 10
}
EOF

# Download and install GPG fingerprint
mkdir -p /usr/local/etc/pkg/fingerprints/kaci
fetch -o /usr/local/etc/pkg/fingerprints/kaci/trusted \
  https://keekar2022.github.io/KACI-Parental_Control/fingerprints/kaci/trusted

# Verify fingerprint was downloaded
cat /usr/local/etc/pkg/fingerprints/kaci/trusted

# Install package
pkg update
pkg install -y kaci-parental-control
```

**Note:** The fingerprint is automatically fetched from GitHub Pages, ensuring it matches the signing key.

---

## Security Best Practices

### 1. Passphrase Management
- Use a strong, unique passphrase (20+ characters)
- Store passphrase in GitHub Secrets only
- Never commit passphrase to repository

### 2. Key Storage
- Keep private key secure (encrypted backup recommended)
- Delete `private-key.asc` and `public-key.asc` after setup
- Don't share private key

### 3. Key Rotation
- Consider rotating keys annually
- Update GitHub Secrets with new key
- Rebuild and resign all packages
- Update fingerprint on all clients

### 4. Backup
```bash
# Encrypted backup of private key
gpg --armor --export-secret-keys ABCD1234ABCD1234 | gpg --symmetric > gpg-backup.asc.gpg

# Store gpg-backup.asc.gpg in secure location (1Password, vault, etc.)
```

---

## Troubleshooting

### Issue: "gpg: signing failed: No secret key"

```bash
# Check if key exists
gpg --list-secret-keys

# Reimport if needed
gpg --import private-key.asc
```

### Issue: "gpg: signing failed: Inappropriate ioctl for device"

```bash
# Set GPG_TTY environment variable
export GPG_TTY=$(tty)

# Or use --batch flag with passphrase-fd
echo "PASSPHRASE" | gpg --batch --passphrase-fd 0 --armor --detach-sign file.txt
```

### Issue: GitHub Actions signature fails

Check:
1. `GPG_PRIVATE_KEY` secret contains full private key (including BEGIN/END lines)
2. `GPG_PASSPHRASE` secret is correct
3. Workflow has correct gpg command syntax

---

## Summary Checklist

### Developer Setup (Steps 1-6)
- [x] GPG key generated (4096-bit RSA)
- [x] Private key exported to `private-key.asc`
- [x] Public key exported to `public-key.asc`
- [x] Fingerprint extracted: `7F066616F4E6AFA912A6B418E511980F2F261ED5`
- [x] `fingerprint.txt` file created
- [ ] `GPG_PRIVATE_KEY` added to GitHub Secrets
- [ ] `GPG_PASSPHRASE` added to GitHub Secrets
- [ ] Workflow tested (pushed to GitHub)
- [ ] Verified signature files (`.pkg.asc`) are generated
- [ ] Backed up private key securely
- [ ] Deleted local `private-key.asc` and `public-key.asc` files

### End User Setup (Step 7)
- This is performed by end users on their FreeBSD/pfSense systems
- Developers do NOT need to perform these steps on macOS

---

**Last Updated:** January 24, 2026  
**Author:** Mukesh Kesharwani
