# FreeBSD Package Repository

This directory contains the FreeBSD package repository for **KACI Parental Control**.

## Repository Structure

```
packages/freebsd/FreeBSD:15:amd64/latest/
├── meta.conf              # Repository metadata configuration
├── meta.txz               # Compressed repository metadata
├── packagesite.txz        # Package index/catalog
├── packagesite.pkg.sig    # GPG signature for packagesite.txz
├── digests.txz            # Package checksums
├── kaci-parental-control-*.pkg     # Package files
└── kaci-parental-control-*.pkg.asc # GPG signatures
```

## Repository Files

### meta.conf
Repository metadata that describes the package repository configuration.

### packagesite.txz
The main package catalog containing:
- Package names and versions
- Dependencies
- Descriptions
- File manifests
- Checksums

### Package Files (*.pkg)
FreeBSD binary packages in `.pkg` format (actually `.txz` archives).

### GPG Signatures (*.pkg.asc, *.pkg.sig)
Detached GPG signatures for package verification.

## Usage

### Configure Repository

```bash
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
```

### Install GPG Fingerprint

```bash
mkdir -p /usr/local/etc/pkg/fingerprints/kaci
fetch -o /usr/local/etc/pkg/fingerprints/kaci/trusted \
  https://keekar2022.github.io/KACI-Parental_Control/fingerprints/fingerprint.txt
```

### Install Package

```bash
pkg update
pkg install -y kaci-parental-control
```

Or direct installation:

```bash
env IGNORE_OSVERSION=yes pkg add -f \
  https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/FreeBSD:15:amd64/latest/kaci-parental-control-latest.pkg
```

## ABI Compatibility

- **Target**: FreeBSD:15:amd64 (pfSense 2.8.1)
- **Built on**: FreeBSD:14:amd64 (GitHub Actions)
- **Compatibility**: Forward compatible (14 → 15)

## Automated Updates

This repository is automatically updated by GitHub Actions:
- Triggered on: Git tags (`v*`) and main branch pushes
- Build workflow: `.github/workflows/build-package.yml`
- Deployment: GitHub Pages (gh-pages branch)

## GPG Verification

Packages are signed with GPG key:
- **Fingerprint**: `7F066616F4E6AFA912A6B418E511980F2F261ED5`
- **Public Key**: Available at `/fingerprints/fingerprint.txt`

## Documentation

- [Installation Guide](https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/GITHUB_PAGES_SETUP.md)
- [Migration Guide](https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/MIGRATION_TO_PKG_REPO.md)
- [GPG Setup](https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/GPG_SETUP.md)
