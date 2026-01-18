# Jump Host Pattern - Portable Package

This package contains the essential files to enable the jump host pattern in any project, allowing AI assistants to automatically route firewall commands through your NAS (nas.keekar.com) to avoid CrowdStrike security alerts.

## 📦 Files Included

1. **AI-ASSISTANT-INSTRUCTIONS.md** ⭐ **ESSENTIAL**
   - Instructions for AI assistant on how to use the jump host pattern
   - Copy this to any project directory where you want the AI to use the pattern

2. **QUICK-START.md**
   - Quick reference guide for daily usage
   - Helpful for both you and the AI assistant

3. **ssh-config-template**
   - SSH configuration template
   - Only needed if setting up on a new machine

## 🚀 How to Use in a New Project

### Method 1: For AI Assistant Only (Minimal)

```bash
# In your new project directory
cp AI-ASSISTANT-INSTRUCTIONS.md /path/to/new/project/

# Optional but recommended:
cp QUICK-START.md /path/to/new/project/
```

That's it! The AI assistant will now automatically use the jump host pattern in that project.

### Method 2: Complete Setup (New Machine or Full Documentation)

```bash
# Copy all files
cp * /path/to/new/project/
```

## 🤖 How the AI Assistant Recognizes the Pattern

When you have `AI-ASSISTANT-INSTRUCTIONS.md` in your project:

1. **The AI will automatically**:
   - Use `ssh nas.keekar.com "fw exec 'command'"` for firewall operations
   - Never connect directly to the firewall from your MacBook
   - Log all commands via the `fw` helper script

2. **You don't need to remind it** - it just works!

3. **The pattern applies to**:
   - All firewall management commands
   - File transfers to the firewall
   - Any pfSense operations

## 📝 Usage Examples in New Project

Once you copy the files, you can simply ask the AI:

```
"Check the firewall rules"
"List nginx processes on the firewall"
"Copy this config to the firewall"
```

The AI will automatically use:
```bash
ssh nas.keekar.com "fw exec 'pfctl -sr'"
ssh nas.keekar.com "fw exec 'ps aux | grep nginx'"
# etc.
```

## 🔄 Updating the Pattern

If you need to modify the pattern (e.g., change jump host, add new commands), edit `AI-ASSISTANT-INSTRUCTIONS.md` and propagate it to all your projects.

## 🎯 Key Advantage

**Copy once, works everywhere** - No need to re-explain the pattern to the AI in each project!

## 📚 Additional Resources

For complete documentation and troubleshooting, refer to:
- Original project: `/Users/mkesharw/Documents/Debug-Firewall-Config/`
- `JUMP-HOST-SETUP.md` - Full setup documentation
- `FINAL-SETUP-STEPS.md` - Manual configuration steps

## 🔒 Security Benefits

- ✅ No CrowdStrike alerts
- ✅ Full audit trail
- ✅ Consistent across all projects
- ✅ AI assistant aware

## Summary

**Minimum requirement for AI awareness**: `AI-ASSISTANT-INSTRUCTIONS.md`

**Recommended for convenience**: 
- `AI-ASSISTANT-INSTRUCTIONS.md`
- `QUICK-START.md`

**For complete setup on new machine**: Copy all files

---

*Created: January 15, 2026*  
*Jump Host: nas.keekar.com*  
*Target: pfSense Firewall (192.168.1.1 / fw.keekar.com)*
