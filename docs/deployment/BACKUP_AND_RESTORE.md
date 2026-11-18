# Database Backup, Disaster Recovery & Restoration Guide

**Primary Database:** PostgreSQL 16 (Dockerized / VPS)  
**System Target:** Advertising Management Platform  
**Last Updated:** 2026-11-18  

---

## 1. Automated Daily Backup Strategy

We implement a dedicated, timestamped daily dump of the PostgreSQL database using `pg_dump` with gzip compression.

### Automated Backup Script (`scripts/backup-db.sh`)
```bash
#!/bin/bash
set -e

BACKUP_DIR="/var/backups/adplatform/postgres"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
FILENAME="ad_platform_backup_${TIMESTAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"

# Execute pg_dump inside the docker container
docker compose exec -T postgres pg_dump -U ad_user -d ad_platform | gzip > "${BACKUP_DIR}/${FILENAME}"

# Retain backups for 30 days, purge older
find "$BACKUP_DIR" -type f -name "ad_platform_backup_*.sql.gz" -mtime +30 -delete

echo "[$(date)] Backup successfully generated: ${BACKUP_DIR}/${FILENAME}"
```

### Cron Schedule Setup on Host VPS
```bash
# Run daily at 02:00 AM UTC
0 2 * * * /var/www/adplatform/scripts/backup-db.sh >> /var/log/adplatform_backup.log 2>&1
```

---

## 2. Restoration & Disaster Recovery Procedure

To restore from a backup file in case of server failure or database corruption:

### Step 1: Ensure Containers are Running
```bash
docker compose -f docker-compose.prod.yml up -d postgres
```

### Step 2: Drop Existing Connections & Restore
```bash
# Terminate existing connections
docker compose exec -T postgres psql -U ad_user -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'ad_platform';"

# Recreate clean database
docker compose exec -T postgres psql -U ad_user -d postgres -c "DROP DATABASE IF EXISTS ad_platform;"
docker compose exec -T postgres psql -U ad_user -d postgres -c "CREATE DATABASE ad_platform OWNER ad_user;"

# Decompress and stream SQL into the postgres container
gunzip -c /var/backups/adplatform/postgres/ad_platform_backup_YYYYMMDD_HHMMSS.sql.gz | docker compose exec -T postgres psql -U ad_user -d ad_platform
```

### Step 3: Run Database Health Check
```bash
docker compose exec -T backend php artisan migrate --status
curl -f http://localhost:8000/api/v1/health
```
