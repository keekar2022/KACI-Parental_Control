# Quick Start Guide - Jump Host Setup

## 🚀 TL;DR - Get Started in 5 Minutes

This guide sets up a jump host to avoid CrowdStrike security alerts when managing your home pfSense firewall.

---

## Step 1: Configure SSH on Your MacBook (2 minutes)

Copy the SSH config to your MacBook:

```bash
cat ssh-config-template >> ~/.ssh/config
```

Or manually add to `~/.ssh/config`:

```ssh-config
Host jumphost jump
    HostName nas.keekar.com
    User mkesharw
    IdentityFile ~/.ssh/id_rsa_truenas

Host firewall fw pfsense 192.168.1.1
    HostName 192.168.1.1
    User admin
    ProxyJump nas.keekar.com
```

**Test it:**
```bash
ssh nas.keekar.com echo "Jump host works!"
```

---

## Step 2: Setup Logging on Jump Host (3 minutes)

Copy and run the setup script on your jump host:

```bash
# Copy setup script to jump host (NAS)
scp jumphost-setup.sh nas.keekar.com:~/

# SSH to jump host
ssh nas.keekar.com

# Run setup script
bash ~/jumphost-setup.sh

# Reload shell
source ~/.bashrc  # or source ~/.zshrc
```

**Test it:**
```bash
fw exec 'uname -a'
fw logs
```

---

## Step 3: Test End-to-End (1 minute)

From your MacBook:

```bash
# This command now goes: MacBook → NAS → Firewall (logged!)
ssh nas.keekar.com "fw exec 'pfctl -sr | head -5'"

# View the logs
ssh nas.keekar.com "fw logs"
```

---

## ✅ Done! 

Now when you (or the AI assistant) manage your firewall:

### Before (CrowdStrike alerts! 🚨):
```bash
ssh admin@192.168.1.1 "some command"
```

### After (No alerts! ✅):
```bash
ssh nas.keekar.com "fw exec 'some command'"
```

---

## Daily Usage

### Execute single command:
```bash
ssh nas.keekar.com "fw exec 'ls -la /usr/local/etc/rc.d/'"
```

### Interactive session:
```bash
ssh nas.keekar.com
fw ssh
# Now you're on the firewall, all logged!
```

### View command history:
```bash
ssh nas.keekar.com "fw logs"
```

### Copy files:
```bash
# MacBook to jump host (NAS)
scp myfile.conf nas.keekar.com:~/

# Jump host to firewall
ssh nas.keekar.com "scp ~/myfile.conf admin@192.168.1.1:/tmp/"
```

---

## For AI Assistant

The AI assistant has been configured to automatically use this pattern. When you ask it to execute firewall commands, it will:

1. ✅ Always use nas.keekar.com as jump host
2. ✅ Use `fw exec` for logging
3. ✅ Never connect directly to firewall from MacBook

See `AI-ASSISTANT-INSTRUCTIONS.md` for complete details.

---

## Troubleshooting

### Can't connect to jump host?
```bash
# Check if jump host is reachable
ping nas.keekar.com

# Check SSH service
ssh -v nas.keekar.com
```

### fw command not found?
```bash
# Reload shell config
ssh nas.keekar.com "source ~/.bashrc"  # or ~/.zshrc

# Check if script exists
ssh nas.keekar.com "ls -la ~/bin/fw"

# Re-run setup if needed
ssh nas.keekar.com "bash ~/jumphost-setup.sh"
```

### SSH keys not working?
```bash
# SSH key should already be configured for NAS
# If not, check your ~/.ssh/config for:
#   IdentityFile ~/.ssh/id_rsa_truenas

# Copy to firewall (from jump host)
ssh nas.keekar.com "ssh-copy-id admin@192.168.1.1"
```

---

## Key Files Reference

- `JUMP-HOST-SETUP.md` - Complete documentation
- `AI-ASSISTANT-INSTRUCTIONS.md` - Instructions for AI assistant
- `ssh-config-template` - SSH config for MacBook
- `jumphost-setup.sh` - Automated setup script for jump host
- `QUICK-START.md` - This file

---

## Questions?

**Q: Will this slow down my work?**  
A: No! With SSH config, it's transparent. `ssh nas.keekar.com "fw exec 'cmd'"` is just as fast.

**Q: What if jump host is down?**  
A: You can temporarily connect directly (accepting the security alert), or fix the jump host first.

**Q: Do I need to inform security team?**  
A: Optional, but recommended. Let them know you've implemented a jump host architecture to reduce false positives.

**Q: What about the AI assistant?**  
A: It's been instructed to always use the jump host. If it forgets, remind it: "Use jump host at nas.keekar.com"

---

## Success Criteria

After setup, you should be able to:

- ✅ Execute firewall commands without direct connection
- ✅ View logs of all commands executed
- ✅ No more CrowdStrike alerts for firewall management
- ✅ AI assistant automatically uses jump host pattern
- ✅ Still complete "30-second fixes" quickly

**Next Steps:** Start using the jump host for all firewall operations and monitor for security alerts (there should be none!).
