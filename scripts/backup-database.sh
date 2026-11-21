#!/usr/bin/env bash
# ==============================================================================
# SOC ADVERTISING PLATFORM — AUTOMATED POSTGRESQL BACKUP SCRIPT
# ==============================================================================
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/opt/ad-platform/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-ad_platform_postgres}"
POSTGRES_USER="${POSTGRES_USER:-ad_user}"
POSTGRES_DB="${POSTGRES_DB:-advertising_platform}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILENAME="${POSTGRES_DB}_backup_${TIMESTAMP}.sql.gz"
BACKUP_PATH="${BACKUP_DIR}/${BACKUP_FILENAME}"
CHECKSUM_PATH="${BACKUP_DIR}/${BACKUP_FILENAME}.sha256"

mkdir -p "${BACKUP_DIR}"

echo "[INFO] Starting database backup for '${POSTGRES_DB}' at $(date -u +"%Y-%m-%dT%H:%M:%SZ")..."

# 1. Execute pg_dump from container and stream through gzip (using -i without pseudo-tty -t)
# Filter transaction_timeout for PG16/PG17 cross-version compatibility
if ! docker exec -i "${POSTGRES_CONTAINER}" pg_dump -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" --no-owner --clean --if-exists | sed '/transaction_timeout/d' | gzip -9 > "${BACKUP_PATH}"; then
    echo "[ERROR] pg_dump pipeline failed during execution." >&2
    rm -f "${BACKUP_PATH}"
    exit 1
fi

# 2. Automated backup failure detection: verify file exists, non-empty, and valid gzip
if [ ! -s "${BACKUP_PATH}" ]; then
    echo "[ERROR] Backup file ${BACKUP_PATH} is missing or empty (0 bytes)." >&2
    rm -f "${BACKUP_PATH}"
    exit 1
fi

if ! gzip -t "${BACKUP_PATH}"; then
    echo "[ERROR] Backup integrity check failed: ${BACKUP_PATH} is corrupted." >&2
    rm -f "${BACKUP_PATH}"
    exit 1
fi

# 3. Compute SHA256 cryptographic checksum
sha256sum "${BACKUP_PATH}" > "${CHECKSUM_PATH}"

FILESIZE=$(du -h "${BACKUP_PATH}" | cut -f1)
echo "[INFO] Backup completed successfully: ${BACKUP_PATH} (${FILESIZE})"
echo "[INFO] Checksum verified: $(cat "${CHECKSUM_PATH}")"

# 4. Apply retention policy (delete backups older than RETENTION_DAYS)
echo "[INFO] Purging backups older than ${RETENTION_DAYS} days..."
find "${BACKUP_DIR}" -name "${POSTGRES_DB}_backup_*.sql.gz*" -mtime +"${RETENTION_DAYS}" -delete

echo "[INFO] Backup routine finished cleanly."
