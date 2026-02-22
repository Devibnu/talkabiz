#!/bin/bash
# ============================================================
# Talkabiz Post-Deploy Permission Fix
# ============================================================
# Run after every deploy to ensure www-data can write to
# storage/ and bootstrap/cache/
#
# WHY: artisan commands run as root during deploy can create
# log files (e.g. security-YYYY-MM-DD.log) owned by root:root.
# PHP-FPM (www-data) then gets "Permission denied" on write.
#
# USAGE:
#   bash /var/www/talkabiz/fix-permissions.sh
#   # or integrate into deploy pipeline after git pull
#
# SAFE: Idempotent, can run multiple times without side effects
# ============================================================

set -euo pipefail

RELEASE_DIR="/var/www/talkabiz/current"
WEB_USER="www-data"
WEB_GROUP="www-data"

echo "[deploy] Fixing permissions for ${RELEASE_DIR}..."

# 1. Fix storage ownership & permissions
chown -R ${WEB_USER}:${WEB_GROUP} "${RELEASE_DIR}/storage"
find "${RELEASE_DIR}/storage" -type d -exec chmod 775 {} \;
find "${RELEASE_DIR}/storage" -type f -exec chmod 664 {} \;

# 2. Fix bootstrap/cache ownership & permissions
chown -R ${WEB_USER}:${WEB_GROUP} "${RELEASE_DIR}/bootstrap/cache"
find "${RELEASE_DIR}/bootstrap/cache" -type d -exec chmod 775 {} \;
find "${RELEASE_DIR}/bootstrap/cache" -type f -exec chmod 664 {} \;

# 3. Fix shared storage if it exists (for release-based deploys)
SHARED_STORAGE="/var/www/talkabiz/shared/storage"
if [ -d "${SHARED_STORAGE}" ]; then
    chown -R ${WEB_USER}:${WEB_GROUP} "${SHARED_STORAGE}"
    find "${SHARED_STORAGE}" -type d -exec chmod 775 {} \;
    find "${SHARED_STORAGE}" -type f -exec chmod 664 {} \;
    echo "[deploy] Shared storage permissions fixed"
fi

# 4. Ensure log directories exist
mkdir -p "${RELEASE_DIR}/storage/logs"
chown ${WEB_USER}:${WEB_GROUP} "${RELEASE_DIR}/storage/logs"
chmod 775 "${RELEASE_DIR}/storage/logs"

echo "[deploy] Permissions fixed successfully ✓"
