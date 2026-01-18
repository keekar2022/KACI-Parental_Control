# AI Assistant Instructions for Firewall Management

## ⚠️ CRITICAL: Always Use Jump Host

When executing ANY commands related to the pfSense firewall (192.168.1.1 / fw.keekar.com), you **MUST** use the jump host at nas.keekar.com (TrueNAS) as an intermediary.

### Why?
- Corporate MacBook triggers CrowdStrike security alerts when directly connecting to the firewall
- Jump host provides logging and audit trail
- Avoids unnecessary security team escalations

---

## Command Execution Patterns

### ❌ NEVER DO THIS:
```bash
# Direct connection from MacBook - WRONG!
ssh admin@192.168.1.1 "some command"
ssh admin@fw.keekar.com "some command"
```

### ✅ ALWAYS DO THIS:

#### Method 1: Two-hop SSH (Most reliable)
```bash
# Connect to jump host first, then execute
ssh nas.keekar.com "ssh admin@192.168.1.1 'command here'"

# Or with the helper script (preferred - includes logging)
ssh nas.keekar.com "fw exec 'command here'"
```

#### Method 2: Interactive Session
```bash
# Step 1: SSH to jump host (NAS)
ssh nas.keekar.com

# Step 2: From jump host, use helper script
fw exec 'command here'
# OR
ssh admin@192.168.1.1 "command here"
```

---

## Common Firewall Operations

### Check firewall rules:
```bash
ssh nas.keekar.com "fw exec 'pfctl -sr'"
```

### List RC scripts:
```bash
ssh nas.keekar.com "fw exec 'ls -la /usr/local/etc/rc.d/ | grep -i squid'"
```

### Check nginx status:
```bash
ssh nas.keekar.com "fw exec 'pgrep -fl nginx'"
```

### Edit configuration files:
```bash
# Step 1: Connect to jump host (NAS)
ssh nas.keekar.com

# Step 2: Use fw helper for interactive session
fw ssh

# Step 3: Edit files on firewall
vi /usr/local/etc/nginx/nginx.conf
```

### Copy files to firewall:
```bash
# Step 1: Copy from MacBook to jump host
scp nginx.conf.golden nas.keekar.com:~/

# Step 2: From jump host to firewall
ssh nas.keekar.com "scp ~/nginx.conf.golden admin@192.168.1.1:/tmp/"

# Step 3: Move to final location on firewall
ssh nas.keekar.com "fw exec 'sudo mv /tmp/nginx.conf.golden /usr/local/etc/nginx/nginx.conf'"
```

---

## Script Execution Pattern

When user asks to execute scripts or commands on the firewall:

1. **Ask if jump host setup is complete** (if uncertain)
2. **Upload script to jump host first** (if needed)
3. **Execute from jump host** using `fw exec` or multi-hop SSH
4. **Show output and confirm completion**

### Example Workflow:
```bash
# User asks: "Check if Squid is running on the firewall"

# Your response should be:
ssh nas.keekar.com "fw exec 'ps aux | grep squid'"

# NOT:
ssh admin@192.168.1.1 "ps aux | grep squid"  # ❌ WRONG!
```

---

## File Transfer Pattern

Always use jump host as intermediary:

```bash
# Pattern: MacBook → Jump Host → Firewall

# Step 1: MacBook to Jump Host
scp /path/to/local/file 192.168.1.200:~/temp/

# Step 2: Jump Host to Firewall
ssh 192.168.1.200 "scp ~/temp/file admin@192.168.1.1:/tmp/"

# Step 3: Move to final location on firewall (with logging)
ssh 192.168.1.200 "fw exec 'sudo mv /tmp/file /final/location/'"
```

---

## Helper Commands Available on Jump Host

After setup, these commands are available on nas.keekar.com:

- `fw ssh` - Interactive shell to firewall (logged)
- `fw exec 'command'` - Execute command on firewall (logged)
- `fw logs` - View recent firewall command logs
- `fw tail` - Follow logs in real-time

---

## Verification Before Execution

Before executing any firewall command, mentally verify:

1. ✅ Am I using nas.keekar.com as jump host?
2. ✅ Is the command being logged?
3. ✅ Am I NOT connecting directly from the MacBook?

---

## Quick Reference Table

| Task | Command Pattern |
|------|----------------|
| Execute single command | `ssh nas.keekar.com "fw exec 'cmd'"` |
| Interactive session | `ssh nas.keekar.com` then `fw ssh` |
| Copy file to FW | `scp file nas.keekar.com:~/ && ssh nas.keekar.com "scp ~/file admin@192.168.1.1:/tmp/"` |
| View logs | `ssh nas.keekar.com "fw logs"` |
| Check FW status | `ssh nas.keekar.com "fw exec 'uptime'"` |

---

## SSH Config Reference

User should have this in `~/.ssh/config`:

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

This allows simplified commands like `ssh firewall` or `ssh jumphost`, but for explicit clarity in responses, prefer using `nas.keekar.com`.

---

## Exception Handling

If jump host is down or unreachable:
1. Inform user that jump host (nas.keekar.com) appears unreachable
2. Ask if they want to:
   - Troubleshoot jump host connection
   - Temporarily use direct connection (and accept security alert risk)
   - Wait until jump host is available

---

## Summary

🎯 **Golden Rule**: Every firewall operation goes through nas.keekar.com
🎯 **Always log**: Use `fw exec` when possible
🎯 **Never direct**: No direct MacBook → Firewall connections

This protects the user from CrowdStrike alerts while maintaining full operational capability.
