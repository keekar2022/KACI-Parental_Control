# FreeBSD Package Repository (Legacy - FreeBSD 14)

This directory contains the FreeBSD package repository for **KACI Parental Control** (Legacy ABI).

## Target Systems

- **pfSense 2.7.x** (FreeBSD 14)
- **OPNsense 24.x** (FreeBSD 14)

> **Note**: For pfSense 2.8.x and newer, use the [FreeBSD:15:amd64](../FreeBSD:15:amd64/latest/) repository instead.

## Repository Structure

```
packages/freebsd/FreeBSD:14:amd64/latest/
├── meta.conf              # Repository metadata configuration
├── meta.txz               # Compressed repository metadata
├── packagesite.txz        # Package index/catalog
├── packagesite.pkg.sig    # GPG signature for packagesite.txz
├── digests.txz            # Package checksums
├── kaci-parental-control-*.pkg     # Package files
└── kaci-parental-control-*.pkg.asc # GPG signatures
```

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
  https://keekar2022.github.io/KACI-Parental_Control/fingerprints/kaci/trusted
```

### Install Package

```bash
pkg update
pkg install -y kaci-parental-control
```

Or direct installation:

```bash
pkg add -f \
  https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/FreeBSD:14:amd64/latest/kaci-parental-control-latest.pkg
```

## Migration to FreeBSD 15

If you're upgrading to pfSense 2.8.x or newer:

```bash
# Remove old package
pkg delete -y kaci-parental-control

# Install from new repository
env IGNORE_OSVERSION=yes pkg add -f \
  https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/FreeBSD:15:amd64/latest/kaci-parental-control-latest.pkg
```

## ABI Compatibility

- **Target**: FreeBSD:14:amd64 (pfSense 2.7.x)
- **Built on**: FreeBSD:14:amd64 (GitHub Actions)
- **Compatibility**: Native (14 → 14)

## Automated Updates

This repository is automatically updated by GitHub Actions:
- Triggered on: Git tags (`v*`) and main branch pushes
- Build workflow: `.github/workflows/build-package.yml`
- Deployment: GitHub Pages (gh-pages branch)

## Documentation

- [Installation Guide](https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/GITHUB_PAGES_SETUP.md)
- [Migration Guide](https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/MIGRATION_TO_PKG_REPO.md)
- [GPG Setup](https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/GPG_SETUP.md)
