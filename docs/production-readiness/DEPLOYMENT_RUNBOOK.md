# PRODUCTION DEPLOYMENT RUNBOOK

**Project:** Multi-Tenant Advertising Management Platform  
**Target Environment:** Linux VPS (Ubuntu 22.04 / 24.04 LTS), Docker 24+, Docker Compose v2+  
**Auditor:** Senior DevOps & Site Reliability Engineer  
**Audit Date:** 2026-11-19  

---

## 1. Pre-Deployment Checklist

Before initiating deployment, verify:
- [ ] VPS provisioned with at least 2 vCPUs, 4 GB RAM, 40 GB NVMe SSD.
- [ ] Docker and Docker Compose v2 installed.
- [ ] Domain names pointed to VPS public IP:
  - Backend API: `api.adplatform.com`
  - Frontend SPA: `app.adplatform.com` (or hosted on Vercel)
- [ ] Developer credentials prepared for Meta, Google, TikTok, and AI providers.

---

## 2. Step-by-Step Deployment Instructions

### Step 1: Clone Repository on VPS
```bash
git clone <repository_url> /opt/ad-platform
cd /opt/ad-platform
```

### Step 2: Configure Production Environment Variables
Create `.env` in repository root and in `backend/.env`:
```bash
cp backend/.env.example backend/.env
```
Ensure the following variables are configured in `backend/.env`:
```ini
APP_NAME="Advertising Platform"
APP_ENV=production
APP_KEY=base64:GENERATE_VIA_ARTISAN
APP_DEBUG=false
APP_URL=https://api.adplatform.com

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=ad_platform_prod
DB_USERNAME=ad_prod_user
DB_PASSWORD=GENERATE_SECURE_PASSWORD

REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

FRONTEND_URL=https://app.adplatform.com
SANCTUM_STATEFUL_DOMAINS=app.adplatform.com
SESSION_DOMAIN=.adplatform.com
```

### Step 3: Build Production Docker Images
```bash
docker compose -f docker-compose.prod.yml build
```

### Step 4: Provision SSL Certificates via Certbot
Before starting Nginx with production SSL certificates:
```bash
# Obtain Let's Encrypt certificate in standalone mode
sudo certbot certonly --standalone -d api.adplatform.com --email ops@adplatform.com --agree-tos --non-interactive

# Symlink or mount certificates to expected Nginx location
sudo mkdir -p /etc/letsencrypt/live/adplatform/
sudo cp /etc/letsencrypt/live/api.adplatform.com/fullchain.pem /etc/letsencrypt/live/adplatform/
sudo cp /etc/letsencrypt/live/api.adplatform.com/privkey.pem /etc/letsencrypt/live/adplatform/
```

### Step 5: Start Production Containers
```bash
docker compose -f docker-compose.prod.yml up -d
```

### Step 6: Generate Key & Run Database Migrations
```bash
# Generate encryption key if not set
docker compose -f docker-compose.prod.yml exec backend php artisan key:generate --force

# Run database migrations
docker compose -f docker-compose.prod.yml exec backend php artisan migrate --force

# Seed essential roles and permissions
docker compose -f docker-compose.prod.yml exec backend php artisan db:seed --class=RolePermissionSeeder --force
```

### Step 7: Optimize Laravel Caches for Production
```bash
docker compose -f docker-compose.prod.yml exec backend php artisan config:cache
docker compose -f docker-compose.prod.yml exec backend php artisan route:cache
docker compose -f docker-compose.prod.yml exec backend php artisan view:cache
```

### Step 8: Deploy Frontend SPA
If hosting frontend on Vercel:
1. Connect Vercel to repository root with root directory set to `frontend`.
2. Set build command: `npm run build`.
3. Set output directory: `dist/frontend/browser`.
4. Configure environment variable: `API_URL = https://api.adplatform.com/api/v1`.

---

## 3. Post-Deployment Verification & Smoke Tests

Execute the following verification calls from an external terminal:

### 1. Healthcheck Probe
```bash
curl -I -k https://api.adplatform.com/api/v1/health
```
**Expected Response:** `HTTP/1.1 200 OK`, JSON `{"status":"healthy","database":"connected","redis":"connected"}`.

### 2. HTTPS & Security Headers Probe
```bash
curl -I https://api.adplatform.com/
```
**Verify Presence Of:**
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`

### 3. Check Background Worker Status
```bash
docker compose -f docker-compose.prod.yml ps
```
All 6 containers (`nginx`, `backend`, `postgres`, `redis`, `queue-worker`, `scheduler`) must display state `Up` or `Up (healthy)`.
