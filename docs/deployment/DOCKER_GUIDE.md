# Docker Environment & Local Setup Guide

## 1. Architecture Overview

The local development environment uses Docker Compose to orchestrate 6 services connected via a shared bridge network (`app-network`):

| Service Name | Container Name | Base Image / Build | Port Mapping | Purpose |
|---|---|---|---|---|
| **backend** | `ad_platform_backend` | `./docker/php` (PHP 8.3-FPM) | - (Internal 9000) | Laravel REST API & backend application |
| **nginx** | `ad_platform_nginx` | `nginx:alpine` | `8000:80` | Web server, reverse proxy, SSL termination |
| **postgres** | `ad_platform_postgres` | `postgres:16-alpine` | `5432:5432` | Relational database (PostgreSQL 16) |
| **redis** | `ad_platform_redis` | `redis:7-alpine` | `6379:6379` | In-memory cache, queue broker, locks |
| **queue-worker** | `ad_platform_queue` | `./docker/php` | - | Laravel asynchronous queue consumer |
| **scheduler** | `ad_platform_scheduler` | `./docker/php` | - | Laravel cron task scheduler |

---

## 2. Quick Start Commands

### Initial Setup
```bash
# 1. Copy environment variables
cp .env.example .env
cp backend/.env.example backend/.env

# 2. Build and start containers
docker compose up --build -d

# 3. Verify running containers and health checks
docker compose ps
```

### Useful Management Commands
```bash
# View aggregated real-time logs
docker compose logs -f

# View logs for a specific service
docker compose logs -f backend
docker compose logs -f queue-worker

# Run Artisan commands inside the backend container
docker compose exec backend php artisan migrate
docker compose exec backend php artisan tinker

# Stop all services safely
docker compose down

# Stop and wipe database/cache volumes (CAUTION: Destructive)
docker compose down -v
```

---

## 3. Local URLs & Endpoints

- **Nginx Health Check:** `http://localhost:8000/nginx-health`
- **Laravel API Health Check:** `http://localhost:8000/api/v1/health`
- **PostgreSQL Database:** `localhost:5432` (`ad_user` / `ad_password_secret_local`)
- **Redis Cache:** `localhost:6379`
- **Angular Frontend:** `http://localhost:4200` (run locally via `cd frontend && npm start`)

---

## 4. Troubleshooting & FAQ

### Port 8000 or 5432 Already in Use
If port 8000 is occupied by another application, modify `NGINX_PORT` in `.env` (e.g. `8080:80`).
If local PostgreSQL is already bound to 5432, alter `POSTGRES_PORT` in `.env` (e.g. `5433:5432`).

### Database Connection Refused
Ensure the `postgres` container has passed its healthcheck (`docker compose ps`). Laravel connects via the Docker internal DNS name `postgres:5432`, not `localhost`.
