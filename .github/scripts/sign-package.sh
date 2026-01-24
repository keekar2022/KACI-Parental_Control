#!/bin/bash
#
# Package Signing Script for KACI Parental Control
# Signs FreeBSD .pkg files with GPG
#

set -e

if [ $# -lt 1 ]; then
    echo "Usage: $0 <package-file>"
    exit 1
fi

PACKAGE_FILE="$1"

if [ ! -f "$PACKAGE_FILE" ]; then
    echo "Error: Package file not found: $PACKAGE_FILE"
    exit 1
fi

# Check for GPG key in environment
if [ -z "$GPG_PRIVATE_KEY" ]; then
    echo "Error: GPG_PRIVATE_KEY environment variable not set"
    exit 1
fi

echo "Importing GPG key..."
echo "$GPG_PRIVATE_KEY" | gpg --batch --import

echo "Signing package: $PACKAGE_FILE"
if [ -n "$GPG_PASSPHRASE" ]; then
    echo "$GPG_PASSPHRASE" | gpg --batch --yes --passphrase-fd 0 \
        --armor --detach-sign "$PACKAGE_FILE"
else
    gpg --batch --yes --armor --detach-sign "$PACKAGE_FILE"
fi

echo "✅ Package signed: ${PACKAGE_FILE}.asc"

# Generate checksums
echo "Generating checksums..."
sha256sum "$PACKAGE_FILE" > "${PACKAGE_FILE}.sha256"
md5sum "$PACKAGE_FILE" > "${PACKAGE_FILE}.md5"

echo "✅ Checksums generated"
echo "   - ${PACKAGE_FILE}.sha256"
echo "   - ${PACKAGE_FILE}.md5"
