# Online Services IP Management - Experimental Feature

## 🧪 Status: EXPERIMENTAL

**Version:** 1.4.0-beta  
**Test Firewall:** 192.168.64.2  
**Status:** Not committed to GitHub - Testing phase

## Overview

This feature allows you to block specific online services (YouTube, Facebook, Discord, etc.) by maintaining up-to-date IP address lists. Unlike DNS-based blocking, this approach blocks services at the network layer using pfSense firewall aliases and rules.

## How It Works

1. **IP List Sources**: Fetches IP ranges from curated GitHub repositories
2. **pfSense Aliases**: Creates firewall aliases (e.g., `pc_service_youtube`) with the fetched IPs
3. **Firewall Rules**: You can use these aliases in firewall rules to block/allow traffic
4. **Auto-Update**: Manually update IP lists to stay current with service changes

## Pre-Configured Services

The feature comes with 6 pre-configured services:

| Service | Description | IP Sources |
|---------|-------------|------------|
| **YouTube** | Video streaming service | 4 GitHub sources (IPv4 & IPv6) |
| **Facebook** | Social media (includes Instagram, WhatsApp) | 3 GitHub sources |
| **Discord** | Voice, video, text communication | 1 GitHub source |
| **TikTok** | Short-form video platform | 1 GitHub source |
| **Netflix** | Streaming entertainment | 1 GitHub source |
| **Twitch** | Live streaming platform | 1 GitHub source |

## Installation

### Deploy to Test Firewall

```bash
cd /Users/mkesharw/Documents/KACI-Parental_Control
./deploy_services_test.sh
```

Or specify a different firewall IP:

```bash
./deploy_services_test.sh 192.168.64.2
```

### Manual Installation

If the script fails, you can manually deploy:

```bash
# Copy files
scp parental_control.inc root@192.168.64.2:/usr/local/pkg/
scp parental_control_services.php root@192.168.64.2:/usr/local/www/
scp parental_control_profiles.php root@192.168.64.2:/usr/local/www/
scp parental_control_schedules.php root@192.168.64.2:/usr/local/www/
scp parental_control_status.php root@192.168.64.2:/usr/local/www/

# SSH to firewall and restart web GUI
ssh root@192.168.64.2
rm -rf /tmp/config.cache
/etc/rc.restart_webgui
```

## Usage

### Accessing the Interface

1. Open pfSense web GUI
2. Navigate to: **Services → Keekar's Parental Control → Online Services**

### Managing Services

#### Add a New Service

1. Click **"Add New Service"**
2. Enter:
   - **Service Name**: Unique name (e.g., "Spotify", "Reddit")
   - **Description**: Brief description
   - **Initial IP List URL**: GitHub raw URL to IP list
3. Click **"Add Service"**

#### Update IP Lists

**For a single service:**
- Click **"Update IPs"** next to the service
- Wait for the fetch to complete (~30 seconds)

**For all enabled services:**
- Click **"Update All Services"**
- Wait for all fetches to complete (may take several minutes)

#### Add More URL Sources

Each service can have multiple URL sources. To add more:

1. Find the service card
2. Scroll to **"IP List URLs"** section
3. Enter a new GitHub raw URL in the text field
4. Click **"Add URL"**

Example URLs:
```
https://raw.githubusercontent.com/username/repo/main/ips.txt
https://raw.githubusercontent.com/org/project/master/cidr_list.lst
```

#### Enable/Disable Services

- Click **"Enable"** or **"Disable"** to toggle service status
- Disabled services are not updated when you click "Update All Services"

#### Delete a Service

- Click **"Delete"** next to the service
- This removes the service AND its pfSense alias

### Using Service Aliases in Firewall Rules

After updating a service's IP list, a pfSense alias is created with the format:

```
pc_service_<servicename>
```

Examples:
- `pc_service_youtube`
- `pc_service_facebook`
- `pc_service_discord`

**To block a service:**

1. Go to **Firewall → Rules → LAN** (or your internal interface)
2. Add a new rule:
   - **Action**: Block
   - **Source**: Your device/profile alias (e.g., `Kids_Profile`)
   - **Destination**: `pc_service_youtube` (from Aliases dropdown)
   - **Description**: "Block YouTube for Kids Profile"
3. Save and Apply Changes

**To block during specific times:**

1. Create a pfSense Schedule (Firewall → Schedules)
2. Apply the schedule to your firewall rule

## Technical Details

### IP List Parsing

The feature supports multiple IP list formats:

- **Plain IPs**: `8.8.8.8`
- **CIDR notation**: `8.8.8.0/24`
- **IPv6**: `2001:4860:4860::8888`
- **IPv6 CIDR**: `2001:4860::/32`
- **Hosts format**: `0.0.0.0 domain.com` (extracts the IP)
- **Comments**: Lines starting with `#` or `;` are ignored

### pfSense Alias Limitations

pfSense aliases can handle large IP lists, but there are practical limits:

- **Performance**: Very large aliases (10,000+ IPs) may slow down firewall reloads
- **Memory**: Each IP in an alias uses memory
- **Recommended max**: 5,000-10,000 IPs per alias for best performance

If a service has more IPs than recommended, consider:
1. Splitting into multiple aliases (IPv4 vs IPv6)
2. Using DNS-based blocking instead
3. Filtering to include only primary IP ranges

### How IP Fetching Works

1. **Fetch**: Uses `curl` with 30-second timeout
2. **Parse**: Extracts valid IPs and CIDRs from text
3. **Deduplicate**: Removes duplicate IPs
4. **Separate**: Splits IPv4 and IPv6 addresses
5. **Update Alias**: Creates or updates pfSense firewall alias
6. **Reload**: Triggers `filter_configure()` to apply changes

### Backend Functions

New functions added to `parental_control.inc`:

| Function | Purpose |
|----------|---------|
| `pc_update_service_ips()` | Fetch and update IP lists for a service |
| `pc_fetch_ip_list()` | Download IP list from URL using curl |
| `pc_parse_ip_list()` | Parse text content to extract IPs |
| `pc_is_valid_ip_or_cidr()` | Validate IP address or CIDR notation |
| `pc_create_service_alias()` | Create/update pfSense alias with IPs |
| `pc_delete_service_alias()` | Remove pfSense alias when service deleted |

## Finding IP List Sources

### Recommended GitHub Repositories

Good sources for service IP lists:

- **SecOps-Institute**: [GitHub](https://github.com/SecOps-Institute)
  - Maintains lists for Facebook, Netflix, Twitch, etc.
  
- **touhidurrr**: [iplist-youtube](https://github.com/touhidurrr/iplist-youtube)
  - YouTube IP ranges (updated regularly)

- **PeterDaveHello**: [threat-hostlist](https://github.com/PeterDaveHello/threat-hostlist)
  - Various service IP lists

### How to Add a New Service

Example: Adding Spotify

1. **Find IP list**: Search GitHub for "spotify ip list"
   - Found: `https://github.com/example/spotify-ips/blob/main/ips.txt`
2. **Get raw URL**: Click "Raw" button on GitHub
   - Result: `https://raw.githubusercontent.com/example/spotify-ips/main/ips.txt`
3. **Add to KACI**: Use this raw URL when creating the service

### URL Requirements

- ✅ Must be a GitHub **raw** URL (starts with `raw.githubusercontent.com`)
- ✅ Must return plain text (not HTML)
- ✅ Must contain valid IPs or CIDRs (one per line)
- ❌ No redirects or authentication required
- ❌ No rate-limiting issues

## Testing Checklist

When testing this feature:

- [ ] Can access "Online Services" tab
- [ ] Pre-configured services appear (YouTube, Facebook, etc.)
- [ ] Can add a new custom service
- [ ] Can add URL to existing service
- [ ] Can delete URL from service
- [ ] Can update single service IP list
- [ ] Can update all services at once
- [ ] pfSense alias is created (check Firewall → Aliases)
- [ ] Alias contains IPs (check alias details)
- [ ] Can use alias in firewall rule
- [ ] Blocking works (test with YouTube, Facebook)
- [ ] Can enable/disable service
- [ ] Can delete service (alias is removed)
- [ ] Web GUI doesn't crash or show errors
- [ ] Logs show activity (check /var/log/parental_control.jsonl)

## Known Limitations

1. **IP-based blocking is not perfect**
   - Services may use CDNs with shared IPs
   - IPs change over time (requires regular updates)
   - May block other services on shared infrastructure

2. **VPN/Proxy bypass**
   - Users can bypass IP blocking with VPNs
   - Consider combining with DNS blocking

3. **Performance impact**
   - Very large IP lists may slow firewall reloads
   - Recommend updating during off-peak hours

4. **Manual updates required**
   - No auto-update scheduler yet (planned feature)
   - You must manually click "Update IPs"

5. **No integration with profiles yet**
   - You must manually create firewall rules
   - Future: Assign services directly to profiles

## Future Enhancements

Planned features for future versions:

- [ ] **Auto-update scheduler**: Automatically fetch IP lists daily/weekly
- [ ] **Profile integration**: Assign blocked services directly to profiles
- [ ] **Schedule integration**: Block services during specific times
- [ ] **Statistics**: Track how many times each service was blocked
- [ ] **Whitelist mode**: Allow only specific services (inverse blocking)
- [ ] **Service categories**: Group services (Social Media, Gaming, Streaming)
- [ ] **IP list validation**: Check if IP lists are still active/valid
- [ ] **Backup/Restore**: Export/import service configurations
- [ ] **API support**: Integrate with external IP list APIs

## Troubleshooting

### "Failed to update service" error

**Possible causes:**
1. GitHub URL is incorrect or file was moved/deleted
2. Firewall cannot reach GitHub (DNS/internet issue)
3. Curl timeout (GitHub slow or rate-limited)

**Solution:**
- Verify URL works in browser
- Check firewall internet connectivity
- Try again later (GitHub may be slow)

### Alias not appearing in firewall

**Solution:**
1. Check if service update succeeded (look for success message)
2. Go to Firewall → Aliases and search for `pc_service_`
3. If missing, try updating the service again
4. Check pfSense system logs for errors

### Blocking doesn't work

**Possible causes:**
1. Firewall rule not created or in wrong order
2. Service uses different IPs not in the list
3. User accessing via VPN/proxy

**Solution:**
1. Create explicit firewall rule using the service alias
2. Check if rule is above "Allow all" rules
3. Test with packet capture (Diagnostics → Packet Capture)
4. Update IP list (may be outdated)

### Too many IPs, firewall slow

**Solution:**
1. Check IP count for each service (shown on service card)
2. If > 10,000 IPs, consider:
   - Removing some URL sources
   - Using only CIDR lists (smaller than plain IP lists)
   - Splitting into multiple services (IPv4 vs IPv6)

## Support & Feedback

This is an **experimental feature** - your feedback is valuable!

**Test Environment:**
- Firewall IP: 192.168.64.2
- Test thoroughly before production deployment

**Report Issues:**
- Include: Service name, error message, pfSense version
- Check logs: `/var/log/parental_control.jsonl`
- Provide screenshots if possible

**When ready for production:**
1. Test all features thoroughly
2. Document any issues or improvements needed
3. Notify to commit to GitHub repository
4. Update VERSION file and CHANGELOG

---

**Built with Passion by Mukesh Kesharwani**  
© 2025 Keekar's Parental Control

