# Manual Install v1.5.3 to fw.keekar.com

When SSH key install to the firewall is not possible, use these steps from your Mac. You will be prompted for the admin password for each `scp` and `ssh` (or use Option A to add your key first, then run `./INSTALL.sh update fw.keekar.com` once).

**Target:** fw.keekar.com  
**User:** admin (change if your pfSense admin user is different)  
**Version:** 1.5.3

---

## 1. From your Mac – copy files to the firewall

Run from: `/Users/mkesharw/Documents/KACI-Parental_Control-Dev`

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev
PFSENSE="admin@fw.keekar.com"

# Core package files
scp info.xml parental_control.xml parental_control.inc VERSION \
  parental_control_status.php parental_control_profiles.php parental_control_schedules.php \
  parental_control_services.php parental_control_gaming.php parental_control_blocked.php \
  parental_control_captive.php parental_control_captive.sh parental_control_health.php \
  parental_control_api.php parental_control_cron.php parental_control_analyzer.sh \
  auto_update_parental_control.sh UNINSTALL.sh \
  $PFSENSE:/tmp/
```

---

## 2. On the firewall – install files and set permissions

SSH in (you’ll be prompted for password):

```bash
ssh admin@fw.keekar.com
```

Then run (copy-paste the whole block):

```bash
sudo mkdir -p /usr/local/pkg /usr/local/www /usr/local/bin /usr/local/share/pfSense-pkg-KACI-Parental_Control /var/log /var/db
sudo mv /tmp/info.xml /usr/local/share/pfSense-pkg-KACI-Parental_Control/
sudo mv /tmp/parental_control.xml /usr/local/pkg/
sudo mv /tmp/parental_control.inc /usr/local/pkg/
sudo mv /tmp/VERSION /usr/local/pkg/parental_control_VERSION
sudo mv /tmp/parental_control_status.php /usr/local/www/
sudo mv /tmp/parental_control_profiles.php /usr/local/www/
sudo mv /tmp/parental_control_schedules.php /usr/local/www/
sudo mv /tmp/parental_control_services.php /usr/local/www/
sudo mv /tmp/parental_control_gaming.php /usr/local/www/
sudo mv /tmp/parental_control_blocked.php /usr/local/www/
sudo mv /tmp/parental_control_captive.php /usr/local/www/
sudo mv /tmp/parental_control_health.php /usr/local/www/
sudo mv /tmp/parental_control_api.php /usr/local/www/
sudo mv /tmp/parental_control_captive.sh /usr/local/etc/rc.d/parental_control_captive
sudo mv /tmp/parental_control_cron.php /usr/local/bin/
sudo mv /tmp/parental_control_analyzer.sh /usr/local/bin/
sudo mv /tmp/auto_update_parental_control.sh /usr/local/bin/
sudo mv /tmp/UNINSTALL.sh /usr/local/bin/

sudo chmod 644 /usr/local/pkg/parental_control*.xml /usr/local/pkg/parental_control.inc /usr/local/pkg/parental_control_VERSION
sudo chmod 644 /usr/local/www/parental_control*.php
sudo chmod 755 /usr/local/etc/rc.d/parental_control_captive
sudo chmod 755 /usr/local/bin/parental_control_cron.php /usr/local/bin/parental_control_analyzer.sh /usr/local/bin/auto_update_parental_control.sh /usr/local/bin/UNINSTALL.sh
```

---

## 3. Sync package configuration (on firewall)

Still over SSH on the firewall:

```bash
sudo /usr/local/bin/php -r "require_once('/usr/local/pkg/parental_control.inc'); parental_control_sync();"
```

---

## 4. Verify version

On the firewall:

```bash
cat /usr/local/pkg/parental_control_VERSION
```

You should see `VERSION=1.5.3` and the current build date.

---

## 5. In the pfSense UI

- Go to **Services → Keekar's Parental Control** (any tab).
- If the menu doesn’t show, go to **System → Package Manager** and reinstall or sync the package if needed.
- Optionally open **Status** to confirm the package is running and reporting 1.5.3.

---

## If you fix SSH key access later

After adding your `id_ed25519.pub` to the firewall’s authorized keys (and optionally passwordless sudo), you can use the script for future updates:

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control-Dev
./INSTALL.sh update fw.keekar.com
```
