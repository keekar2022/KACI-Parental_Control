#!/bin/bash
# Deploy jump host pattern to a new project
# Usage: ./deploy-to-project.sh /path/to/target/project

if [ -z "$1" ]; then
    echo "Usage: $0 <target-directory>"
    echo ""
    echo "Examples:"
    echo "  $0 ~/Projects/MyNewProject"
    echo "  $0 ../AnotherProject"
    exit 1
fi

TARGET_DIR="$1"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"

# Create target directory if it doesn't exist
if [ ! -d "$TARGET_DIR" ]; then
    echo "Creating directory: $TARGET_DIR"
    mkdir -p "$TARGET_DIR"
fi

echo "Deploying jump host pattern to: $TARGET_DIR"
echo ""

# Copy essential file
echo "✓ Copying AI-ASSISTANT-INSTRUCTIONS.md (essential)"
cp "$SOURCE_DIR/AI-ASSISTANT-INSTRUCTIONS.md" "$TARGET_DIR/"

# Copy recommended file
echo "✓ Copying QUICK-START.md (recommended)"
cp "$SOURCE_DIR/QUICK-START.md" "$TARGET_DIR/"

# Copy README
echo "✓ Copying README.md (documentation)"
cp "$SOURCE_DIR/README.md" "$TARGET_DIR/JUMP-HOST-PATTERN-README.md"

echo ""
echo "✅ Deployment complete!"
echo ""
echo "Files copied to: $TARGET_DIR"
echo "  - AI-ASSISTANT-INSTRUCTIONS.md (AI will auto-use jump host pattern)"
echo "  - QUICK-START.md (quick reference)"
echo "  - JUMP-HOST-PATTERN-README.md (documentation)"
echo ""
echo "The AI assistant will now automatically use nas.keekar.com as jump host"
echo "for all firewall operations in this project."
echo ""
echo "No CrowdStrike alerts! 🎉"
