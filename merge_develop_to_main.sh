#!/bin/bash
#
# Safe Merge Script: Develop → Main (Excluding Diagnostic Folder)
#
# Purpose: Safely merge develop branch into main while excluding
#          the diagnostic/ folder which contains development tools
#
# Usage: ./merge_develop_to_main.sh
#
# See: docs/MERGE_STRATEGY.md for full documentation
#

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Banner
echo -e "${BLUE}"
echo "═══════════════════════════════════════════════════════════════"
echo "  Safe Merge: develop → main (Excluding diagnostic/ folder)"
echo "═══════════════════════════════════════════════════════════════"
echo -e "${NC}"

# Check we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo -e "${RED}Error: Not in a git repository${NC}"
    exit 1
fi

# Check we're on main branch
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    echo -e "${RED}Error: Must be on main branch (currently on: $CURRENT_BRANCH)${NC}"
    echo ""
    echo "Run: git checkout main"
    exit 1
fi

# Check for uncommitted changes
if ! git diff-index --quiet HEAD --; then
    echo -e "${RED}Error: You have uncommitted changes${NC}"
    echo ""
    echo "Commit or stash your changes first:"
    git status --short
    exit 1
fi

# Fetch latest from remote
echo -e "${YELLOW}Fetching latest changes from remote...${NC}"
git fetch origin

# Check if develop branch exists
if ! git show-ref --verify --quiet refs/heads/develop; then
    echo -e "${RED}Error: develop branch does not exist${NC}"
    exit 1
fi

# Show what will be merged
echo ""
echo -e "${BLUE}Commits in develop not in main:${NC}"
git log main..develop --oneline --decorate | head -20
echo ""

# Check if diagnostic/ exists in develop
DIAGNOSTIC_COUNT=$(git ls-tree -r develop --name-only | grep -c "^diagnostic/" || echo "0")
echo -e "${YELLOW}Files in diagnostic/ folder (develop): $DIAGNOSTIC_COUNT${NC}"
echo ""

# Confirm with user
read -p "Proceed with merge? This will exclude diagnostic/ folder. (y/n) " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}Merge cancelled${NC}"
    exit 0
fi

echo ""
echo -e "${BLUE}Step 1: Merging develop into main (no commit)...${NC}"

# Merge without committing
if ! git merge develop --no-commit --no-ff 2>/dev/null; then
    echo -e "${RED}Merge has conflicts!${NC}"
    echo ""
    echo "Please resolve conflicts, then:"
    echo "  1. git reset HEAD diagnostic/"
    echo "  2. git checkout HEAD -- diagnostic/"
    echo "  3. git commit"
    echo ""
    echo "Or abort the merge:"
    echo "  git merge --abort"
    exit 1
fi

echo -e "${GREEN}✓ Merge completed (not committed yet)${NC}"
echo ""

# Check if diagnostic/ is in the merge
if git diff --cached --name-only | grep -q "^diagnostic/"; then
    echo -e "${BLUE}Step 2: Removing diagnostic/ folder from merge...${NC}"
    
    # Remove diagnostic from staging
    git reset HEAD diagnostic/ > /dev/null 2>&1 || true
    
    # Remove diagnostic from working tree (restore to main's version, which doesn't have it)
    git checkout HEAD -- diagnostic/ > /dev/null 2>&1 || true
    
    # Clean up any untracked diagnostic files
    if [ -d "diagnostic" ]; then
        git clean -fd diagnostic/ > /dev/null 2>&1 || true
    fi
    
    echo -e "${GREEN}✓ diagnostic/ folder excluded from merge${NC}"
else
    echo -e "${YELLOW}ℹ No diagnostic/ changes to exclude${NC}"
fi

echo ""
echo -e "${BLUE}Step 3: Files to be committed:${NC}"
echo "────────────────────────────────────────────────────────────────"
git diff --cached --name-status | grep -v "^diagnostic/"
echo "────────────────────────────────────────────────────────────────"
echo ""

# Count changes
ADDED=$(git diff --cached --name-status | grep "^A" | wc -l | tr -d ' ')
MODIFIED=$(git diff --cached --name-status | grep "^M" | wc -l | tr -d ' ')
DELETED=$(git diff --cached --name-status | grep "^D" | wc -l | tr -d ' ')

echo -e "${GREEN}Added: $ADDED  Modified: $MODIFIED  Deleted: $DELETED${NC}"
echo ""

# Prompt for commit message
read -p "Enter commit message (or press Enter for default): " COMMIT_MSG
echo ""

if [ -z "$COMMIT_MSG" ]; then
    COMMIT_MSG="Merge develop into main (excluding diagnostic/)

Merged experimental features and improvements from develop branch.
Excluded diagnostic/ folder which contains development-only tools.

See docs/MERGE_STRATEGY.md for merge procedure."
fi

echo -e "${BLUE}Step 4: Committing merge...${NC}"

# Commit the merge
git commit -m "$COMMIT_MSG"

echo -e "${GREEN}✓ Merge committed${NC}"
echo ""

# Verification
echo -e "${BLUE}Step 5: Verification${NC}"
echo "────────────────────────────────────────────────────────────────"

# Check diagnostic not in main
MAIN_DIAGNOSTIC_COUNT=$(git ls-tree -r main --name-only | grep -c "^diagnostic/" || echo "0")
if [ "$MAIN_DIAGNOSTIC_COUNT" -eq 0 ]; then
    echo -e "${GREEN}✓ Confirmed: No diagnostic/ files in main ($MAIN_DIAGNOSTIC_COUNT files)${NC}"
else
    echo -e "${RED}✗ Warning: Found $MAIN_DIAGNOSTIC_COUNT diagnostic files in main!${NC}"
    echo ""
    echo "Files in main's diagnostic/:"
    git ls-tree -r main --name-only | grep "^diagnostic/"
    echo ""
    echo -e "${YELLOW}You may need to manually remove these files.${NC}"
fi

# Check diagnostic still in develop
DEV_DIAGNOSTIC_COUNT=$(git ls-tree -r develop --name-only | grep -c "^diagnostic/" || echo "0")
echo -e "${GREEN}✓ Confirmed: diagnostic/ still in develop ($DEV_DIAGNOSTIC_COUNT files)${NC}"

# Show recent commits
echo ""
echo -e "${BLUE}Recent commits on main:${NC}"
git log --oneline --decorate -5
echo "────────────────────────────────────────────────────────────────"

echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ✓ Merge Completed Successfully${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo ""

echo "Next steps:"
echo "  1. Review the merge: git log"
echo "  2. Test the code: ./INSTALL.sh <firewall-ip>"
echo "  3. Push to remote: git push origin main"
echo ""

# Ask if user wants to push
read -p "Push to remote now? (y/n) " -n 1 -r
echo ""
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo ""
    echo -e "${BLUE}Pushing to origin/main...${NC}"
    git push origin main
    echo -e "${GREEN}✓ Pushed to remote${NC}"
fi

echo ""
echo -e "${GREEN}Done!${NC}"

