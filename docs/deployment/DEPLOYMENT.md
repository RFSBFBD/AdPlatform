# Deployment Architecture & Operational Guide

## 1. Environments Overview

| Environment | Frontend | Backend API | Queues & Cron | Database & Cache |
|---|---|---|---|---|
| **Local Development** | Angular CLI (`localhost:4200`) or Nginx proxy | PHP-FPM / Laravel (`localhost:8000`) | Docker Queue Worker & Scheduler | Docker PostgreSQL 16 & Redis 7 |
| **Staging** | Vercel Preview Deployment | Staging VPS (Docker / PHP-FPM HTTPS) | Staging Queue Worker & Scheduler | Staging PostgreSQL & Redis |
| **Production** | Vercel Custom Domain or VPS Nginx | Production VPS (Docker Compose HTTPS) | Docker Queue Worker & Scheduler | Managed or VPS PostgreSQL 16 & Redis 7 |

---

## 2. Security Boundaries & Rules

1. **No Backend on Vercel:** Vercel is strictly utilized for the Angular frontend preview/production static build.
2. **Environment Separation:** Local, Staging, and Production environment files (`.env`) are kept isolated. No production secrets are committed to Git.
3. **HTTPS / TLS:** All staging and production communication between Angular and Laravel must occur over TLS (HTTPS).
4. **CORS & Cookies:** In production, credentials (`withCredentials: true`) are strictly restricted to the authorized frontend domain.
