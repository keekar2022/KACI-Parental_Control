# KACI Parental Control v1.4.60: Major Release + Critical Migration Notice

I'm announcing KACI Parental Control v1.4.60 for pfSense with major enhancements and an important transition to BSD package distribution.

## What's New

Version 1.4.60 brings improved performance, enhanced monitoring, and refined time tracking algorithms. Production-ready and available now.

## Critical: Migrate to BSD Package Distribution

We're transitioning from code-based deployment to BSD package management. Benefits include:

- Simpler installation (one command)
- Automatic updates via FreeBSD pkg system
- GPG-signed packages for better security
- Tested binary packages for improved stability

## 90-Day Migration Deadline

The old installation method (INSTALL.sh and auto_update scripts) will be decommissioned after April 25, 2026. Migration takes 5 minutes.

## Migration Steps

**Automated (Recommended):**

```bash
fetch -o /tmp/migrate-to-pkg.sh \
  https://raw.githubusercontent.com/keekar2022/KACI-Parental_Control/main/client-setup/migrate-to-pkg.sh

chmod +x /tmp/migrate-to-pkg.sh
/tmp/migrate-to-pkg.sh
```

**Fresh Installation:**

```bash
mkdir -p /usr/local/etc/pkg/repos
cat > /usr/local/etc/pkg/repos/kaci.conf << 'EOF'
kaci: {
  url: "pkg+https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}",
  enabled: yes
}
EOF

pkg update && pkg install -y kaci-parental-control
```

The migration script backs up your configuration, removes old cron jobs, sets up the package repository, and restores all settings. Your profiles and schedules remain intact.

## Seeking Collaborators

I'm looking for help integrating KACI Parental Control into the official pfSense Package Manager. If you have experience with pfSense package development, FreeBSD port maintenance, or package repository management, let's connect.

## Resources

- Migration Guide: https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/MIGRATION_TO_PKG_REPO.md
- Documentation: https://keekar2022.github.io/KACI-Parental_Control/
- Support: https://github.com/keekar2022/KACI-Parental_Control/issues

Moving to BSD package distribution makes this project more mature and reliable, follows pfSense best practices, and positions us for potential official package repository inclusion.

Questions? Drop a comment or reach out directly.

#pfSense #FreeBSD #NetworkSecurity #ParentalControls #OpenSource
