# GitHub Pages Setup Guide

## Enable GitHub Pages for Your Repository

To host the announcement page (`index.html`) as a live website, follow these steps:

### Step 1: Go to Repository Settings

1. Open your repository on GitHub: https://github.com/keekar2022/KACI-Parental_Control
2. Click the **Settings** tab (top right, near the code tab)

### Step 2: Navigate to Pages Settings

1. In the left sidebar, scroll down and click **Pages**
2. You'll see "GitHub Pages" configuration

### Step 3: Configure Source

1. Under **Source**, select:
   - **Branch:** `main`
   - **Folder:** `/ (root)`
2. Click **Save**

### Step 4: Wait for Deployment

1. GitHub will take 1-2 minutes to build and deploy
2. You'll see a message: "Your site is ready to be published at..."
3. The URL will be: **https://keekar2022.github.io/KACI-Parental_Control/**

### Step 5: Verify

1. Visit: https://keekar2022.github.io/KACI-Parental_Control/
2. You should see the professional landing page with the KACI Parental Control announcement

---

## Custom Domain (Optional)

If you want to use a custom domain (e.g., parental-control.keekar.com):

1. In the **Pages** settings, under **Custom domain**, enter your domain
2. Add these DNS records in your domain provider:
   ```
   Type: CNAME
   Name: parental-control (or your subdomain)
   Value: keekar2022.github.io
   ```
3. Wait for DNS propagation (5-60 minutes)
4. Enable **Enforce HTTPS** in GitHub Pages settings

---

## URLs After Setup

Once GitHub Pages is enabled, you can share these URLs:

### Main Landing Page
- **Live URL:** https://keekar2022.github.io/KACI-Parental_Control/
- **Markdown:** https://github.com/keekar2022/KACI-Parental_Control/blob/main/ANNOUNCEMENT.md

### Documentation
- **README:** https://github.com/keekar2022/KACI-Parental_Control/blob/main/README.md
- **Installation Guide:** https://github.com/keekar2022/KACI-Parental_Control/blob/main/FRESH_INSTALL_COMPLETE.md
- **API Docs:** https://github.com/keekar2022/KACI-Parental_Control/blob/main/docs/API.md

### Repository
- **Main Repo:** https://github.com/keekar2022/KACI-Parental_Control
- **Issues:** https://github.com/keekar2022/KACI-Parental_Control/issues
- **Releases:** https://github.com/keekar2022/KACI-Parental_Control/releases

---

## Where to Announce

Once GitHub Pages is live, announce your package on:

### pfSense Community
1. **pfSense Forums:** https://forum.netgate.com/
   - Category: Packages
   - Title: "[ANNOUNCE] KACI Parental Control - Free Bypass-Proof Time Management"
   - Link to your GitHub Pages URL

2. **pfSense Subreddit:** https://reddit.com/r/PFSENSE
   - Post with screenshots and link

### General Communities
1. **r/homelab** - https://reddit.com/r/homelab
2. **r/selfhosted** - https://reddit.com/r/selfhosted
3. **r/Parenting** - https://reddit.com/r/Parenting
4. **Hacker News** - https://news.ycombinator.com/

### Social Media
1. **Twitter/X** - Tag @pfSense, @Netgate
2. **LinkedIn** - Share in networking/IT groups
3. **Facebook** - Parenting and tech groups

---

## Sample Announcement Post

```
🎉 Announcing KACI Parental Control for pfSense v0.9.1

After months of development, I'm excited to release a FREE, open-source 
parental control package for pfSense that actually works!

🏆 Key Innovation: Shared time limits across ALL devices
   No more device hopping - kids can't bypass by switching devices

✅ Features:
   • Bypass-proof (network-level firewall)
   • Smart scheduling (bedtime, school hours)
   • Auto-discover devices
   • Real-time dashboard
   • RESTful API
   • Auto-updates

🚀 Installation: 5 minutes via SSH
💰 Cost: FREE forever (MIT License)
🔒 Privacy: 100% local, no cloud

📖 Learn more & install:
https://keekar2022.github.io/KACI-Parental_Control/

GitHub:
https://github.com/keekar2022/KACI-Parental_Control

Built by a network engineer and parent who got tired of daily 
screen time battles. Hoping it helps other families too!

#pfSense #ParentalControl #OpenSource #HomeNetwork
```

---

## Maintenance

### Updating the Live Site

Any time you update `index.html` and push to the `main` branch:
1. GitHub automatically rebuilds the site (takes 1-2 minutes)
2. Changes go live at https://keekar2022.github.io/KACI-Parental_Control/
3. No manual deployment needed!

### Adding Blog Posts (Optional)

You can create a `docs/` folder with additional markdown files:
- `docs/tutorial.md`
- `docs/faq.md`
- `docs/troubleshooting.md`

These will be accessible at:
- https://keekar2022.github.io/KACI-Parental_Control/docs/tutorial
- https://keekar2022.github.io/KACI-Parental_Control/docs/faq
- etc.

---

## Analytics (Optional)

To track visitors, add Google Analytics or Plausible to `index.html`:

### Plausible (Privacy-friendly, recommended)
```html
<script defer data-domain="keekar2022.github.io" 
  src="https://plausible.io/js/script.js"></script>
```

### Google Analytics
```html
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXXXXXXX');
</script>
```

---

## Success! 🎉

You now have:
- ✅ Professional landing page (`index.html`)
- ✅ Comprehensive announcement (`ANNOUNCEMENT.md`)
- ✅ Installation instructions
- ✅ Ready to share with the world!

**Next step:** Enable GitHub Pages and start spreading the word!

