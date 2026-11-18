# VPS Setup & Production Deployment Playbook

**Operating System:** Ubuntu 22.04 / 24.04 LTS (x86_64)  
**Stack:** Docker Compose (Nginx, PHP 8.3 FPM, PostgreSQL 16, Redis 7, Queue Worker, Scheduler)  
**Frontend Deployment:** Vercel (or static Nginx hosting)  

---

## 1. Initial Host Server Hardening

### Step 1: Update & Install Base Tools
```bash
sudo apt-get update && sudo apt-get upgrade -y
sudo apt-get install -y curl git ufw fail2ban certbot
```

### Step 2: Configure Firewall (UFW)
```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP (Let's Encrypt renewal)
sudo ufw allow 443/tcp   # HTTPS
sudo ufw enable
```

### Step 3: Install Docker & Docker Compose
```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
```

---

## 2. Application Deployment on VPS

### Step 1: Clone Repository
```bash
cd /var/www
git clone <your-private-repository-url> adplatform
cd adplatform
```

### Step 2: Generate Production Environment
```bash
cp backend/.env.example backend/.env
# Set APP_ENV=production, APP_DEBUG=false, APP_KEY, DB_PASSWORD, REDIS passwords
docker compose run --rm backend php artisan key:generate
```

### Step 3: Obtain SSL Certificate
```bash
sudo certbot certonly --standalone -d api.yourdomain.com
```

### Step 4: Launch Production Docker Fleet
```bash
docker compose -f docker-compose.prod.yml up --build -d
```

### Step 5: Run Database Migrations & Seed Default Roles
```bash
docker compose exec -T backend php artisan migrate --force
docker compose exec -T backend php artisan db:seed --force
```

### Step 6: Verify Live Health Endpoint
```bash
curl https://api.yourdomain.com/api/v1/health
```

---

## 3. Frontend Deployment on Vercel
1. Import repository into Vercel dashboard.
2. Set Root Directory to `frontend`.
3. Set Framework Preset to `Angular`.
4. Build command: `npm run build`.
5. Output directory: `dist/frontend/browser`.
6. Environment variable: `API_URL = https://api.yourdomain.com`.
