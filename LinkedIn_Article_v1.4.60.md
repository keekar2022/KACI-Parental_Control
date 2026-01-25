# 🚀 KACI Parental Control v1.4.60: Major Enhancement + Critical Migration Update

I'm excited to announce the release of **KACI Parental Control v1.4.60** with major enhancements for pfSense! This release marks a significant milestone in our journey toward professional BSD package distribution.

## 🎯 What's New in v1.4.60

Version 1.4.60 brings major enhancements to the parental control system, including improved performance, enhanced monitoring capabilities, and refined time tracking algorithms. This production-ready release represents months of development and testing.

## 📦 Important: Migrate to BSD Package Distribution

**Starting today, we're transitioning from code-based deployment to industry-standard BSD package management.** This change brings significant benefits to all users:

✅ **Easier Installation** - One command, fully automated  
✅ **Automatic Updates** - Integrated with FreeBSD pkg system  
✅ **Enhanced Security** - GPG-signed packages  
✅ **Professional Distribution** - Industry-standard approach  
✅ **Better Stability** - Tested binary packages  

## ⏰ Critical Timeline: 90 Days to Migrate

**The legacy code-based deployment (INSTALL.sh and auto_update scripts) will be available for the next 90 days only.** After that, these methods will be decommissioned. Please migrate to the BSD package distribution before **April 25, 2026**.

## 🔧 How to Migrate (Takes 5 Minutes)

**Option 1: Automated Migration** (Recommended)
```bash
fetch -o /tmp/migrate-to-pkg.sh \
  https://raw.githubusercontent.com/keekar2022/KACI-Parental_Control/main/client-setup/migrate-to-pkg.sh

chmod +x /tmp/migrate-to-pkg.sh
/tmp/migrate-to-pkg.sh
```

**Option 2: Fresh Installation**
```bash
# Configure repository
mkdir -p /usr/local/etc/pkg/repos
cat > /usr/local/etc/pkg/repos/kaci.conf << 'EOF'
kaci: {
  url: "pkg+https://keekar2022.github.io/KACI-Parental_Control/packages/freebsd/${ABI}",
  enabled: yes
}
EOF

# Install package
pkg update
pkg install -y kaci-parental-control
```

The migration script automatically:
- Backs up your configuration and state
- Removes legacy cron jobs
- Configures the new pkg repository
- Installs the package via pkg manager
- Restores your settings seamlessly

## 🤝 Call for Collaboration

**Want to make KACI Parental Control available directly through the pfSense Package Manager?**

I'm actively seeking collaborators who can help integrate this package into the official pfSense package repository. If you have experience with:
- pfSense package development and submission
- FreeBSD port maintenance
- Package repository management
- Community engagement within pfSense ecosystem

Let's connect! Together, we can make advanced parental controls accessible to thousands of pfSense users worldwide through native package manager integration.

## 📚 Resources

- **Migration Guide**: https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/MIGRATION_TO_PKG_REPO.md
- **Documentation**: https://keekar2022.github.io/KACI-Parental_Control/
- **Support**: https://github.com/keekar2022/KACI-Parental_Control/issues

## 🎯 Why This Matters

Moving to BSD package distribution represents a maturation of this project from a script-based tool to an enterprise-grade solution. It aligns with FreeBSD and pfSense best practices, enhances security through package signing, and provides a foundation for potential inclusion in the official pfSense package repository.

**Don't wait—migrate today and enjoy a more stable, secure, and maintainable parental control solution!**

---

*Have questions about migration? Drop a comment below or reach out directly. I'm here to help make your transition smooth and hassle-free.*

#pfSense #FreeBSD #NetworkSecurity #ParentalControls #OpenSource #PackageManagement #BSDPackages #Cybersecurity

---
© 2026 Keekar - Built with Passion
