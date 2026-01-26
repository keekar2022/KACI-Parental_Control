# GPG Fingerprints for Package Verification

This directory contains GPG fingerprints for verifying KACI Parental Control packages.

## Structure

```
fingerprints/
├── kaci/
│   └── trusted          # GPG fingerprint for pkg verification
└── fingerprint.txt      # Raw fingerprint (legacy location)
```

## GPG Key Information

**Key ID**: `E511980F2F261ED5`  
**Fingerprint**: `7F066616F4E6AFA912A6B418E511980F2F261ED5`  
**Type**: RSA 4096-bit  
**Created**: 2026-01-24  
**Purpose**: Signing FreeBSD packages for KACI Parental Control  

## Usage

### Automatic Installation

The migration script automatically downloads the fingerprint:

```bash
mkdir -p /usr/local/etc/pkg/fingerprints/kaci
fetch -o /usr/local/etc/pkg/fingerprints/kaci/trusted \
  https://keekar2022.github.io/KACI-Parental_Control/fingerprints/kaci/trusted
```

### Manual Verification

1. Download the fingerprint:
```bash
fetch https://keekar2022.github.io/KACI-Parental_Control/fingerprints/fingerprint.txt
```

2. Verify it matches the published fingerprint:
```
7F066616F4E6AFA912A6B418E511980F2F261ED5
```

3. Place in pkg fingerprints directory:
```bash
mkdir -p /usr/local/etc/pkg/fingerprints/kaci
cp fingerprint.txt /usr/local/etc/pkg/fingerprints/kaci/trusted
```

## Repository Configuration

Configure your repository to use fingerprint verification:

```bash
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

## File Format

The `trusted` file contains the SHA256 fingerprint in FreeBSD `pkg` format:

```
function: sha256
fingerprint: 7F066616F4E6AFA912A6B418E511980F2F261ED5
```

## Security Notes

- **Always verify** the fingerprint through a secure channel before trusting it
- The fingerprint should match across:
  - GitHub repository documentation
  - Project website
  - Official announcements
- If the fingerprint changes, it will be announced through official channels

## Troubleshooting

### Fingerprint Not Found

If you see `Failed to download GPG fingerprint`:

1. Check internet connectivity
2. Verify the URL is accessible
3. Fallback to unsigned repository (not recommended):
```bash
signature_type: "none"
```

### Signature Verification Failed

If package signature verification fails:

1. Update the fingerprint
2. Clear pkg cache: `pkg clean -a`
3. Re-download: `pkg update -f`
4. Try installation again

## References

- [FreeBSD pkg Documentation](https://man.freebsd.org/pkg/)
- [GPG Setup Guide](../docs/GPG_SETUP.md)
- [Repository Configuration](../docs/GITHUB_PAGES_SETUP.md)
