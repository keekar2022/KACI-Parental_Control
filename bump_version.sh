#!/bin/bash

# Version Bump Script for KACI Parental Control
# Usage: ./bump_version.sh <new_version> "<changelog_entry>"
# Example: ./bump_version.sh 0.2.4 "Fixed device selection bug"
#
# This script also validates that INSTALL.sh and UNINSTALL.sh are synchronized
# to ensure complete cleanup with no leftovers

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

#############################################
# Validate INSTALL/UNINSTALL Synchronization
#############################################
validate_install_uninstall_sync() {
    local INSTALL_SCRIPT="INSTALL.sh"
    local UNINSTALL_SCRIPT="UNINSTALL.sh"
    local INC_FILE="parental_control.inc"
    
    # Check if files exist
    if [ ! -f "$INSTALL_SCRIPT" ] || [ ! -f "$UNINSTALL_SCRIPT" ]; then
        echo -e "${RED}✗ Required scripts not found${NC}"
        return 1
    fi
    
    local ISSUES_FOUND=0
    
    # Extract installed files from INSTALL.sh
    echo -e "${CYAN}1. Checking installed files...${NC}"
    local INSTALL_COUNT=$(grep -E "sudo.*mv /tmp/.*\.(php|sh|xml|md|inc)" "$INSTALL_SCRIPT" | wc -l | tr -d ' ')
    echo "   Found $INSTALL_COUNT files installed by INSTALL.sh"
    
    # Extract files removed by UNINSTALL.sh  
    local UNINSTALL_COUNT=$(grep -E "rm -f (\/usr\/local|\/var)" "$UNINSTALL_SCRIPT" | wc -l | tr -d ' ')
    echo "   Found $UNINSTALL_COUNT removal patterns in UNINSTALL.sh"
    
    # Check for port aliases
    echo ""
    echo -e "${CYAN}2. Checking port alias synchronization...${NC}"
    if [ -f "$INC_FILE" ]; then
        local ALIASES_IN_CODE=$(grep -o "KACI_PC[_A-Za-z]*" "$INC_FILE" 2>/dev/null | sort -u | wc -l | tr -d ' ')
        local ALIASES_IN_UNINSTALL=$(grep -o "KACI_PC[_A-Za-z]*" "$UNINSTALL_SCRIPT" 2>/dev/null | sort -u | wc -l | tr -d ' ')
        
        if [ "$ALIASES_IN_CODE" -eq "$ALIASES_IN_UNINSTALL" ]; then
            echo -e "   ${GREEN}✓ All $ALIASES_IN_CODE port aliases have cleanup code${NC}"
        else
            echo -e "   ${YELLOW}⚠ Alias mismatch: $ALIASES_IN_CODE created, $ALIASES_IN_UNINSTALL removed${NC}"
            ISSUES_FOUND=$((ISSUES_FOUND + 1))
        fi
    fi
    
    # Check for configuration paths
    echo ""
    echo -e "${CYAN}3. Checking configuration cleanup...${NC}"
    if [ -f "$INC_FILE" ]; then
        local CONFIG_PATHS=$(grep -E "config_set_path\('installedpackages" "$INC_FILE" | sed -E "s/.*config_set_path\('([^']+)'.*/\1/" | sort -u)
        local CONFIG_COUNT=0
        local CONFIG_CLEANED=0
        
        while IFS= read -r path; do
            if [ -n "$path" ]; then
                CONFIG_COUNT=$((CONFIG_COUNT + 1))
                if grep -q "$path" "$UNINSTALL_SCRIPT" 2>/dev/null; then
                    CONFIG_CLEANED=$((CONFIG_CLEANED + 1))
                fi
            fi
        done <<< "$CONFIG_PATHS"
        
        if [ $CONFIG_COUNT -eq $CONFIG_CLEANED ]; then
            echo -e "   ${GREEN}✓ All $CONFIG_COUNT config paths have cleanup code${NC}"
        else
            echo -e "   ${YELLOW}⚠ Only $CONFIG_CLEANED of $CONFIG_COUNT config paths have cleanup${NC}"
            ISSUES_FOUND=$((ISSUES_FOUND + 1))
        fi
    fi
    
    # Check for firewall rules
    echo ""
    echo -e "${CYAN}4. Checking firewall rule cleanup...${NC}"
    if grep -q "strpos.*'Parental Control'" "$UNINSTALL_SCRIPT"; then
        echo -e "   ${GREEN}✓ UNINSTALL removes all rules matching 'Parental Control'${NC}"
    else
        echo -e "   ${YELLOW}⚠ UNINSTALL may not remove all firewall rules${NC}"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    # Summary
    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}Synchronization Check Summary${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    
    if [ $ISSUES_FOUND -eq 0 ]; then
        echo -e "${GREEN}✅ No synchronization issues detected${NC}"
        echo -e "${GREEN}✅ INSTALL.sh and UNINSTALL.sh appear to be synchronized${NC}"
        echo ""
        return 0
    else
        echo -e "${YELLOW}⚠ $ISSUES_FOUND potential synchronization issue(s) detected${NC}"
        echo ""
        echo -e "${CYAN}Recommendations:${NC}"
        echo "1. Review UNINSTALL.sh for missing cleanup code"
        echo "2. Ensure all port aliases are in the cleanup list"
        echo "3. Verify all config paths are removed"
        echo "4. Test UNINSTALL.sh on a test system"
        echo ""
        
        read -p "Continue with version bump despite warnings? (y/N) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo -e "${RED}Version bump cancelled${NC}"
            exit 1
        fi
        return 1
    fi
}

# Check arguments
if [ $# -lt 1 ]; then
    echo -e "${RED}Error: Version number required${NC}"
    echo "Usage: $0 <version> [changelog]"
    echo "Example: $0 0.2.4 'Fixed device selection bug'"
    exit 1
fi

NEW_VERSION="$1"
CHANGELOG="${2:-Version bump}"
BUILD_DATE=$(date +"%Y-%m-%d")
BUILD_TIMESTAMP=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
GIT_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")

echo -e "${YELLOW}=== KACI Parental Control Version Bump ===${NC}"
echo "New Version: ${NEW_VERSION}"
echo "Changelog: ${CHANGELOG}"
echo "Build Date: ${BUILD_DATE}"
echo "Git Commit: ${GIT_COMMIT}"
echo ""

# Get current version from VERSION file
if [ -f "VERSION" ]; then
    CURRENT_VERSION=$(grep "CURRENT_VERSION=" VERSION | cut -d'=' -f2)
    echo "Current Version: ${CURRENT_VERSION}"
else
    echo -e "${RED}Error: VERSION file not found${NC}"
    exit 1
fi

# Confirm with user
read -p "Proceed with version bump? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 1
fi

echo -e "${GREEN}Updating version files...${NC}"

# 1. Update VERSION file
echo "  → VERSION"
sed -i '' "s/CURRENT_VERSION=.*/CURRENT_VERSION=${NEW_VERSION}/" VERSION
sed -i '' "s/BUILD_DATE=.*/BUILD_DATE=${BUILD_DATE}/" VERSION

# Add new changelog entry to VERSION file
sed -i '' "/# Version History:/a\\
# ${NEW_VERSION} - ${BUILD_DATE} - ${CHANGELOG}
" VERSION

# 2. Update parental_control.inc
echo "  → parental_control.inc"
sed -i '' "s/define('PC_VERSION', '.*');/define('PC_VERSION', '${NEW_VERSION}');/" parental_control.inc
sed -i '' "s/define('PC_BUILD_DATE', '.*');/define('PC_BUILD_DATE', '${BUILD_DATE}');/" parental_control.inc

# 3. Update parental_control.xml
echo "  → parental_control.xml"
sed -i '' "s|<version>.*</version>|<version>${NEW_VERSION}</version>|" parental_control.xml

# 4. Update info.xml
echo "  → info.xml"
sed -i '' "s|<version>.*</version>|<version>${NEW_VERSION}</version>|" info.xml

# 5. Update BUILD_INFO.json
echo "  → BUILD_INFO.json"
# Update build_info section
sed -i '' "s/\"version\": \".*\"/\"version\": \"${NEW_VERSION}\"/" BUILD_INFO.json
sed -i '' "s/\"build_date\": \".*\"/\"build_date\": \"${BUILD_TIMESTAMP}\"/" BUILD_INFO.json
sed -i '' "s/\"git_commit\": \".*\"/\"git_commit\": \"${GIT_COMMIT}\"/" BUILD_INFO.json

# Add changelog entry to BUILD_INFO.json (this is complex, let's create a temp file)
python3 - <<PYTHON_SCRIPT
import json
import sys

# Read BUILD_INFO.json
with open('BUILD_INFO.json', 'r') as f:
    data = json.load(f)

# Add new changelog entry at the beginning
new_entry = {
    "version": "${NEW_VERSION}",
    "date": "${BUILD_DATE}",
    "type": "update",
    "changes": ["${CHANGELOG}"]
}

if 'changelog' not in data:
    data['changelog'] = []

data['changelog'].insert(0, new_entry)

# Write back
with open('BUILD_INFO.json', 'w') as f:
    json.dump(data, f, indent=2)
    f.write('\n')

print("  → BUILD_INFO.json changelog updated")
PYTHON_SCRIPT

# 6. Update README.md
echo "  → README.md"
sed -i '' "s/\*\*Version:\*\* [0-9.]*/**Version:** ${NEW_VERSION}/" README.md

# 7. Update docs/GETTING_STARTED.md
echo "  → docs/GETTING_STARTED.md"
sed -i '' "s/\*\*Version [0-9.]*/**Version ${NEW_VERSION}/" docs/GETTING_STARTED.md
sed -i '' "s/Version: [0-9.]*/Version: ${NEW_VERSION}/" docs/GETTING_STARTED.md

# 8. Update docs/USER_GUIDE.md (only current version, not historical)
echo "  → docs/USER_GUIDE.md"
sed -i '' "s/\*\*Version:\*\* [0-9.]* /**Version:** ${NEW_VERSION} /" docs/USER_GUIDE.md
sed -i '' "s/\*\*Version 0\.[0-9.]*/**Version ${NEW_VERSION}/" docs/USER_GUIDE.md

# 9. Update docs/TECHNICAL_REFERENCE.md (only current version, not historical)
echo "  → docs/TECHNICAL_REFERENCE.md"
sed -i '' "s/\*\*Package Version:\*\* [0-9.]*/**Package Version:** ${NEW_VERSION}/" docs/TECHNICAL_REFERENCE.md
sed -i '' "s/\*\*Architecture Version\*\*: [0-9.]*/**Architecture Version**: ${NEW_VERSION}/" docs/TECHNICAL_REFERENCE.md
sed -i '' "s/\*\*Current Version\*\*: v[0-9.]*/**Current Version**: v${NEW_VERSION}/" docs/TECHNICAL_REFERENCE.md

echo ""
echo -e "${GREEN}✅ Version bumped to ${NEW_VERSION}${NC}"
echo ""
echo "Files updated:"
echo "  - VERSION"
echo "  - parental_control.inc"
echo "  - parental_control.xml"
echo "  - info.xml"
echo "  - BUILD_INFO.json"
echo "  - README.md"
echo "  - docs/GETTING_STARTED.md"
echo "  - docs/USER_GUIDE.md"
echo "  - docs/TECHNICAL_REFERENCE.md"
echo ""

# Ask if user wants to commit
read -p "Commit these changes? (y/N) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    git add VERSION parental_control.inc parental_control.xml info.xml BUILD_INFO.json README.md docs/GETTING_STARTED.md docs/USER_GUIDE.md docs/TECHNICAL_REFERENCE.md
    git commit -m "chore: Bump version to ${NEW_VERSION}

${CHANGELOG}

Auto-generated by bump_version.sh"
    echo -e "${GREEN}✅ Changes committed${NC}"
    echo ""
    read -p "Push to GitHub? (y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git push origin main
        echo -e "${GREEN}✅ Pushed to GitHub${NC}"
    fi
else
    echo "Files staged but not committed. Review changes and commit manually."
fi

echo ""
echo -e "${GREEN}Done!${NC}"
echo ""

# Validate INSTALL.sh and UNINSTALL.sh synchronization
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}Validating INSTALL/UNINSTALL Synchronization${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
validate_install_uninstall_sync

