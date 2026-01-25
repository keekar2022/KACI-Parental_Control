# KACI Parental Control v1.4.62: Faster Bot Detection + Critical Migration Notice

I'm announcing KACI Parental Control v1.4.62 for pfSense with enhanced bot detection and an important transition to BSD package distribution.

## What's New in v1.4.62

**40% Faster Bot Detection**: Background activity (iCloud sync, app updates, telemetry) is now detected in 30 minutes instead of 50+ minutes, preventing phantom usage accumulation during sleep hours. Production-ready and available now.

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
# Install directly from GitHub Pages
env IGNORE_OSVERSION=yes pkg add -f \
  https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/$(pkg config ABI)/latest/kaci-parental-control-1.4.62.pkg
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
