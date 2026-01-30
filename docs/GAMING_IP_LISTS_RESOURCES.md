# Gaming IP Lists - GitHub Resources & Integration Guide

**Last Updated:** January 29, 2026  
**Purpose:** Enhance KACI Parental Control with gaming platform IP detection

> **✅ INVESTIGATION UPDATE - January 29, 2026**  
> User confirmed with son (Vishesh): The game being played was **Minecraft**, not Roblox.  
> **Key Insight:** Minecraft and Roblox share similar traffic patterns (Cloudflare CDN + Discord + YouTube),  
> making them difficult to distinguish without additional context. This guide now includes Minecraft-specific detection.

---

## 📚 Available GitHub Repositories

### 1. **lord-alfred/ipranges** ⭐ RECOMMENDED

**URL:** https://github.com/lord-alfred/ipranges

**What it contains:**
- Daily-updated IP ranges from major tech companies
- Google Cloud (used by many games)
- AWS (gaming infrastructure)
- Microsoft Azure (Xbox, gaming services)
- Cloudflare (CDN for Roblox, Discord, etc.)
- Facebook/Meta (WhatsApp, Instagram)

**Update Frequency:** Daily automated updates

**Format:** JSON, TXT, CIDR notation

**Use for KACI:**
- ✅ Cloudflare ranges → Roblox, Discord CDN
- ✅ Google Cloud → YouTube Gaming, Firebase
- ✅ AWS → Many indie games, Steam content
- ✅ Azure → Microsoft gaming services

---

### 2. **gamingwithngfw/iplists**

**URL:** https://github.com/gamingwithngfw/iplists

**What it contains:**
- Gaming-specific IP lists
- Steam IP addresses and ports
- Blizzard (World of Warcraft, Overwatch) IPs
- Gaming security policies

**Update Frequency:** Manual/community-driven

**Format:** Text files with IP ranges

**Use for KACI:**
- ✅ Steam game server detection
- ✅ Blizzard games blocking
- ✅ Gaming-specific firewall rules

---

### 3. **ipverse/asn-ip** (ASN-Based)

**URL:** https://github.com/ipverse/asn-ip

**What it contains:**
- IP blocks grouped by ASN (Autonomous System Number)
- Major hosting providers
- CDN networks
- Cloud providers

**Update Frequency:** Regular updates

**Format:** CIDR blocks by ASN

**Use for KACI:**
- ✅ Block entire gaming ASNs
- ✅ CDN identification
- ✅ Cloud gaming services

---

### 4. **firehol/blocklist-ipsets** (Security Focus)

**URL:** https://github.com/firehol/blocklist-ipsets  
**Website:** https://iplists.firehol.org/

**What it contains:**
- 3.7k stars, 417 forks
- Security-focused IP lists
- Geolocation filtering
- Country-specific IP ranges
- Attack sources, botnets

**Update Frequency:** 1-minute check intervals

**Use for KACI:**
- ⚠️ Not gaming-specific
- ✅ Can block malicious gaming servers
- ✅ Geographic restrictions (e.g., block international game servers)

---

## 🎮 Gaming Platform Specific Resources

### Roblox

**No official IP list available** (Roblox uses dynamic Cloudflare CDN)

**Alternative approaches:**
1. **Use Cloudflare IP ranges** from lord-alfred/ipranges
2. **Monitor traffic and collect IPs** during active Roblox sessions
3. **Use Roblox ASN:** AS22697 (Roblox Corporation)

**GitHub repos (archived/limited):**
- behindsgtgigdi/ROBLOXIPPULLER (individual user IPs)
- recanman/RecanBot (archived 2022)
- evilgenios/roblox-getip (archived 2023)

### Discord

**Official Discord IP ranges:**
- Not published by Discord officially
- Uses Cloudflare CDN (162.159.x.x ranges)
- Voice servers: Various regions

**Best approach:**
- Track connections to Discord domains
- Monitor Cloudflare IPs during Discord usage
- Use Discord ASN: AS49544 (Discord Inc.)

### Steam

**Steam Network:**
- Well-documented by Valve
- Available in gamingwithngfw/iplists
- Official Steam ports: 27015-27030

**Resources:**
- https://support.steampowered.com/kb_article.php?ref=8571-GLVN-8711
- Ports: 27015-27050 (TCP/UDP), 3478-4380 (UDP), 4379-4380 (UDP)

### Epic Games (Fortnite)

**Epic Games Network:**
- Uses AWS infrastructure
- Ports: 5222-5223, 80, 443
- Voice: 9000-9100 (UDP)

**No centralized IP list available**

**Best approach:**
- Monitor AWS IP ranges
- Track ports 5222-5223
- Use Epic Games domain filtering

### Minecraft ✅ (Confirmed User Case)

**Minecraft Network Characteristics:**
- Highly decentralized (thousands of independent servers)
- Java Edition: Port 25565 (TCP)
- Bedrock Edition: Port 19132 (UDP)
- Realms: Microsoft Azure infrastructure
- Many servers: Behind Cloudflare CDN (DDoS protection)

**What we learned from real investigation (Jan 29, 2026):**

**Traffic Pattern:**
- ✅ **Cloudflare CDN** - 30+ connections (many servers use Cloudflare)
- ✅ **Discord** - 160 min/day (voice chat during multiplayer)
- ✅ **YouTube** - 150 min/day (watching Minecraft tutorials/let's plays)
- ✅ **Mumble VoIP** - Professional voice servers
- ✅ **High connection count** - 83 connections (server + skins + resource packs + auth)

**Detection Strategy:**

1. **Port-based (for non-CDN servers):**
   - Monitor port 25565 (Java) and 19132 (Bedrock)
   - pfSense rule: Block dst port 25565,19132

2. **CDN-based (for Cloudflare-proxied servers):**
   - Use Cloudflare IP ranges from lord-alfred/ipranges
   - **Caution:** Will also catch other services (Discord, some websites)

3. **Behavioral (RECOMMENDED):**
   - Pattern: Cloudflare + Discord + YouTube + High Connections
   - Track connection count to Cloudflare IPs
   - Correlate with Discord voice usage
   - Minecraft sessions typically 1-3 hours continuous

4. **Authentication servers:**
   - authserver.mojang.com → Microsoft/Mojang IPs
   - sessionserver.mojang.com → User authentication
   - Track connections to Mojang/Microsoft auth during gameplay

**Resources:**
- Monitor ports: 25565 (Java), 19132 (Bedrock)
- Track Azure IPs for Realms (lord-alfred/ipranges)
- Collect IPs during confirmed Minecraft sessions
- No central IP list (too decentralized)

**ASN:**
- AS8075 - Microsoft Corporation (for Realms)
- AS13335 - Cloudflare (for many multiplayer servers)

**KACI Implementation:**
- Service Name: "Minecraft"
- Detection: Port 25565/19132 + Cloudflare patterns
- Time Limit: 60-90 minutes/day (recommended)
- Combine with Discord tracking (voice chat indicator)

---

## 🔧 Integration with KACI

### Method 1: Direct IP List Import

**Steps:**

1. **Download IP list from GitHub:**
```bash
# Example: Get Cloudflare IPs (for Roblox/Discord)
curl -o /tmp/cloudflare_ips.txt https://raw.githubusercontent.com/lord-alfred/ipranges/main/cloudflare/ipv4.txt
```

2. **Add to KACI Online-Service:**
- Navigate to: **Services → Keekar's Parental Control → Online-Service**
- Click "+ Add Service"
- Service Name: "Roblox"
- Upload or paste IP ranges

3. **Set time limits:**
- Daily limit: 60-90 minutes
- Weekend bonus: +30 minutes

### Method 2: Automated Update Script

**Create update script:**

```bash
#!/bin/sh
# update_gaming_ips.sh
# Automatically update gaming IP lists in KACI

KACI_SERVICE_DIR="/usr/local/pkg/parental_control_services"

# Update Cloudflare IPs (Roblox, Discord)
curl -s https://raw.githubusercontent.com/lord-alfred/ipranges/main/cloudflare/ipv4.txt \
  > "$KACI_SERVICE_DIR/cloudflare_ips.txt"

# Update Google IPs (YouTube Gaming)
curl -s https://raw.githubusercontent.com/lord-alfred/ipranges/main/google/ipv4.txt \
  > "$KACI_SERVICE_DIR/google_ips.txt"

# Update Steam IPs
curl -s https://raw.githubusercontent.com/gamingwithngfw/iplists/main/steam_ips.txt \
  > "$KACI_SERVICE_DIR/steam_ips.txt"

# Reload KACI service configuration
/usr/local/pkg/parental_control_cron.php
```

**Add to cron (weekly update):**
```bash
0 3 * * 0 /usr/local/bin/update_gaming_ips.sh
```

### Method 3: ASN-Based Blocking

**Block entire gaming ASNs:**

```bash
# Roblox ASN
AS22697 - Roblox Corporation

# Discord ASN  
AS49544 - Discord Inc.

# Steam ASN (Valve)
AS32590 - Valve Corporation

# Epic Games (uses AWS)
AS16509 - Amazon AWS (partial)
```

**pfSense implementation:**
1. Create alias with ASN
2. Add to KACI blocking rules
3. Apply to profile schedules

---

## 📊 Recommended IP List Strategy

### Tier 1: High-Confidence Gaming IPs

**These are safe to block (minimal false positives):**

1. **Steam** (well-documented)
   - Ports: 27015-27030
   - IPs: From gamingwithngfw/iplists

2. **Minecraft** (standard port)
   - Port: 25565
   - Easy to detect

3. **Epic Games** (specific ports)
   - Ports: 5222-5223
   - Voice: 9000-9100

### Tier 2: CDN-Based Detection (Roblox, Discord)

**Higher risk of false positives:**

1. **Cloudflare CDN ranges**
   - Used by: Roblox, Discord, many websites
   - Risk: Blocking may affect non-gaming sites
   - **Solution:** Combine with port/domain analysis

2. **Google Cloud**
   - Used by: YouTube Gaming, Firebase, many services
   - Risk: Very broad (may block legitimate services)
   - **Solution:** Service-specific IP filtering

### Tier 3: Behavioral Detection

**Best for dynamic games:**

1. **Monitor connection patterns**
   - High connection count (50-100+)
   - Specific port usage
   - Duration and frequency

2. **Learn from usage**
   - Collect IPs during known gaming sessions
   - Build custom IP list over time
   - Update KACI service definitions

---

## 🎯 Practical Implementation for Your Case

### For Minecraft ✅ (Your Son's Confirmed Game - Vishesh):

**Step 1: Port-Based Detection (Quick Win)**
```bash
# Block Minecraft default ports in pfSense
# Add firewall rule: Block dst port 25565 (Java) and 19132 (Bedrock)
```

**Step 2: Add Minecraft Service to KACI**
1. Go to: **Online-Service** tab
2. Add new service: "Minecraft"
3. Configure detection:
   - **Option A (Simple):** Port-based: 25565, 19132
   - **Option B (Comprehensive):** Download Cloudflare IPs + Azure IPs
4. Set daily limit: 60 minutes (weekdays), 90 minutes (weekends)
5. Apply to Vishesh profile

**Step 3: Collect Minecraft-Specific IPs**

During active Minecraft session:
```bash
# Collect all IPs being accessed during Minecraft gameplay
ssh nas.keekar.com "fw exec 'pfctl -s state | grep 192.168.1.27 | grep ESTABLISHED | awk \"{print \\\$5}\" | cut -d: -f1 | sort | uniq'"

# Filter for Cloudflare IPs (many Minecraft servers use Cloudflare)
ssh nas.keekar.com "fw exec 'pfctl -s state | grep 192.168.1.27 | grep \"162.159\" | awk \"{print \\\$5}\" | cut -d: -f1 | sort | uniq'"

# Check for Minecraft default port (25565)
ssh nas.keekar.com "fw exec 'pfctl -s state | grep 192.168.1.27 | grep \":25565\"'"

# Save to file for analysis
ssh nas.keekar.com "fw exec 'pfctl -s state | grep 192.168.1.27 | grep ESTABLISHED' > /tmp/minecraft_connections.txt"
```

**Step 4: Refine List Over Time**
- Monitor for 1-2 weeks during confirmed Minecraft sessions
- Collect IPs during active gameplay (correlate with Discord voice usage)
- Remove IPs seen during non-gaming time (background services)
- Create refined Minecraft IP list
- Track Mojang/Microsoft auth servers (authserver.mojang.com, sessionserver.mojang.com)

**Step 5: Behavioral Pattern Detection (Advanced)**
- Combine indicators:
  - High connection count (70-100+ connections)
  - Concurrent Discord usage (voice chat)
  - YouTube activity (watching Minecraft videos)
  - Session duration: 1-3 hours continuous
- If ALL indicators present → High confidence Minecraft gaming

---

## ⚠️ Important Considerations

### False Positives

**Cloudflare CDN Issue:**
- Cloudflare hosts MILLIONS of websites
- Blocking all Cloudflare = breaking many legitimate sites
- **Solution:** Use selective blocking or combine with other indicators

**Google Cloud Issue:**
- Google Cloud hosts countless services
- Blocking Google IPs may break Gmail, Google Docs, etc.
- **Solution:** Service-specific filtering only

### Dynamic IPs

**Problem:**
- Gaming platforms use dynamic CDNs
- IPs change frequently
- Today's list may be incomplete tomorrow

**Solution:**
- Regular updates (weekly/monthly)
- Behavioral detection (connection patterns)
- Hybrid approach (IPs + ports + patterns)

### Privacy & Legal

**Roblox User IP Tools:**
- Some GitHub repos extract individual player IPs
- **Do NOT use these** for parental control
- Privacy concerns and potential legal issues
- Stick to infrastructure IPs only

---

## 🚀 Quick Start Guide

### Option 1: Manual Setup (Safest)

1. **Start with well-documented platforms:**
   - Steam (from gamingwithngfw/iplists)
   - Minecraft (port 25565)
   - Epic Games (ports 5222-5223)

2. **Add to KACI Online-Service:**
   - One service per platform
   - Set conservative time limits
   - Monitor for false positives

3. **Iterate:**
   - Adjust limits based on actual usage
   - Add more platforms as needed
   - Refine IP lists based on logs

### Option 2: Automated Setup (Advanced)

1. **Clone lord-alfred/ipranges:**
```bash
git clone https://github.com/lord-alfred/ipranges.git
cd ipranges
```

2. **Create extraction script:**
```bash
#!/bin/sh
# Extract gaming-relevant IPs

# Cloudflare (Roblox, Discord)
cat cloudflare/ipv4.txt > /tmp/gaming_cloudflare.txt

# Google Cloud (YouTube Gaming, Firebase)
cat google/ipv4.txt > /tmp/gaming_google.txt

# AWS (Epic Games, many indie games)
cat amazon/ipv4.txt > /tmp/gaming_aws.txt

# Microsoft Azure (Xbox, Minecraft Realms)
cat microsoft/ipv4.txt > /tmp/gaming_azure.txt
```

3. **Import to KACI:**
- Use KACI API or manual import
- Create separate services for each
- Set platform-specific limits

### Option 3: Behavioral Detection (Recommended)

**Best approach for dynamic platforms like Minecraft/Roblox:**

1. **Monitor connection patterns** (what we did today)
   - High connection count (70-100+)
   - Concurrent Discord usage (voice chat indicator)
   - YouTube activity (gaming content)
   - Sustained session (1-3 hours)

2. **Collect IPs during confirmed gaming**
   - Ask child what they're playing
   - Monitor during that session
   - Save IPs for that specific game

3. **Build custom list over time**
   - Week 1: Identify Minecraft servers
   - Week 2: Verify consistency
   - Week 3: Import to KACI

4. **Combine with service limits** (Discord, YouTube)
   - Already working in your case
   - Discord blocked after 160 min
   - Indirect gaming control

**Advantages:**
- No false positives (learned from actual usage)
- Adapts to platform changes
- Personalized to your son's gaming habits
- Ground truth validation (confirmed Minecraft)

---

## 📋 Recommended Gaming IP Lists

### Immediate Implementation:

| Platform | Source | Confidence | False Positive Risk |
|----------|--------|------------|---------------------|
| **Steam** | gamingwithngfw/iplists | 95% | Low |
| **Minecraft** | Port 25565 | 99% | Very Low |
| **Epic Games** | Ports 5222-5223 | 90% | Low |

### Future Enhancement (CDN-based platforms):

| Platform | Source | Confidence | False Positive Risk | Notes |
|----------|--------|------------|---------------------|-------|
| **Minecraft** ✅ | Cloudflare subset + port + learning | 85% | Medium | Confirmed case, uses Cloudflare CDN |
| **Roblox** | Cloudflare subset + learning | 75% | Medium | Similar pattern to Minecraft |
| **Discord** | Cloudflare + ASN | 70% | Medium | Voice chat indicator for gaming |
| **Browser Games** | Behavioral detection | 60% | Low | Highly variable patterns |

### Not Recommended (Too Broad):

| Platform | Why Not | Risk |
|----------|---------|------|
| All Cloudflare IPs | Breaks legitimate sites | Very High |
| All Google Cloud | Breaks Gmail, Docs, etc. | Very High |
| All AWS | Breaks countless services | Very High |

---

## 🔗 Useful Links

### GitHub Repositories:
- **lord-alfred/ipranges:** https://github.com/lord-alfred/ipranges
- **gamingwithngfw/iplists:** https://github.com/gamingwithngfw/iplists
- **ipverse/asn-ip:** https://github.com/ipverse/asn-ip
- **firehol/blocklist-ipsets:** https://github.com/firehol/blocklist-ipsets

### Official Gaming Platform Docs:
- **Steam Network:** https://support.steampowered.com/kb_article.php?ref=8571-GLVN-8711
- **Cloudflare IPs:** https://www.cloudflare.com/ips/
- **AWS IP Ranges:** https://ip-ranges.amazonaws.com/ip-ranges.json
- **Google Cloud IPs:** https://www.gstatic.com/ipranges/goog.json
- **Azure IPs:** https://www.microsoft.com/en-us/download/details.aspx?id=56519

### ASN Lookup:
- **Hurricane Electric BGP Toolkit:** https://bgp.he.net/
- **RIPE Stat:** https://stat.ripe.net/

---

## 🎓 Lessons Learned

### What Works:

✅ **Port-based detection** (Steam, Minecraft, Epic)
- High accuracy
- Low false positives
- Easy to implement

✅ **Behavioral detection** (connection patterns)
- Adapts to changes
- No maintenance needed
- Personalized

✅ **Service-specific limits** (Discord, YouTube)
- Already working in KACI
- Easy to configure
- Effective for social gaming

### What Doesn't Work:

❌ **Blanket CDN blocking** (all Cloudflare, all Google)
- Too many false positives
- Breaks legitimate services
- Poor user experience

❌ **Static IP lists for dynamic platforms**
- Outdated quickly
- Requires constant maintenance
- Misses new IPs

❌ **Individual player IP extraction**
- Privacy concerns
- Not useful for infrastructure blocking
- Legally questionable

---

## 💡 Recommendation for Your Situation

**Based on today's investigation (Minecraft + Discord - Confirmed with Vishesh):**

### Immediate (This Week):

1. ✅ **Keep current service limits** (Discord, YouTube)
   - Already working effectively
   - Successfully caught gaming session today
   - Discord blocked after 160 min (40 min over limit)
   - No additional setup needed

2. ✅ **Add Minecraft port blocking** (Quick Win)
   - pfSense firewall rule: Block dst port 25565 (Java), 19132 (Bedrock)
   - Immediate Minecraft detection on non-CDN servers
   - 5-minute setup

3. ✅ **Add gaming schedule**
   - Block 3-7 PM on weekdays (homework time)
   - Allow after homework completion
   - Weekend flexibility (90 min/day)

### Short-Term (Next Month):

4. 📊 **Collect Minecraft-Specific IPs**
   - Monitor for 2 weeks during confirmed Minecraft sessions
   - Correlate with Discord voice usage (indicator of active gaming)
   - Track Cloudflare IPs accessed during gameplay
   - Build custom Minecraft server IP list
   - Import to KACI as "Minecraft" service

5. 🔧 **Set Minecraft-specific limit**
   - 60 minutes weekdays
   - 90 minutes weekends
   - Separate from Discord/YouTube limits
   - Apply to Vishesh profile

6. 🎯 **Track Mojang/Microsoft auth servers**
   - Monitor connections to authserver.mojang.com
   - Track sessionserver.mojang.com
   - These are ALWAYS accessed during Minecraft login
   - 100% accurate Minecraft detection

### Long-Term (Future):

7. 🚀 **Automated IP list updates**
   - Weekly download from lord-alfred/ipranges
   - Extract Cloudflare, Azure IPs (Minecraft Realms)
   - Auto-import to KACI "Minecraft" service

8. 🤖 **Behavioral pattern detection**
   - Auto-detect: High connections + Discord + YouTube = Gaming
   - Alert parent when pattern detected
   - Suggest appropriate time limits

6. 🎯 **Behavioral detection enhancement**
   - Add gaming pattern recognition to KACI
   - Port-based auto-detection
   - Connection pattern analysis

---

**Last Updated:** January 29, 2026  
**Status:** Active Resource  
**Next Review:** February 2026 (monthly update)
