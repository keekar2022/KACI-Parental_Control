# FreeBSD Package Repository Structure

This directory contains the FreeBSD package repository for KACI Parental Control, organized by operating system, architecture, and version.

## Directory Structure

```
packages/
└── freebsd/                          # FreeBSD packages
    └── FreeBSD:15:amd64/             # ABI-specific repository
        └── latest/                   # Latest version channel
            ├── meta.conf             # Repository metadata
            ├── meta.txz              # Compressed metadata
            ├── packagesite.txz       # Package catalog
            ├── packagesite.pkg.sig   # Catalog signature
            ├── digests.txz           # Checksums
            ├── *.pkg                 # Package files
            └── *.pkg.asc             # Package signatures
```

## ABI Directories

Each ABI (Application Binary Interface) has its own repository directory:

- **FreeBSD:15:amd64** - pfSense 2.8.x, OPNsense 25.x (Current)
- **FreeBSD:14:amd64** - pfSense 2.7.x, OPNsense 24.x (Legacy)

## Version Channels

### latest/
Contains the most recent stable release. This is the default channel for production use.

### Future Channels (Not Implemented)
- `stable/` - Long-term stable versions
- `testing/` - Pre-release testing versions
- `nightly/` - Development builds

## Repository Format

Packages follow the FreeBSD `pkg` repository format:
- Uses `.txz` compression
- Includes UCL manifests
- GPG-signed for security
- Supports delta updates

## Deployment

Files are deployed to GitHub Pages at:
```
https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}/latest/
```

## Maintenance

Repository files are automatically generated and deployed by:
- **Workflow**: `.github/workflows/build-package.yml`
- **Trigger**: Git tags (`v*`) and main branch pushes
- **Builder**: GitHub Actions with FreeBSD VM
- **Deployment**: GitHub Pages (gh-pages branch)

## Usage

See individual ABI directories for specific installation instructions.
