# SocialFlow - Social Media Scheduler & Management Platform

A modern full-stack social media scheduling platform with direct modular PHP API (`api_v1/`), MySQL/MariaDB database, and React + Vite frontend.

---

## 🚀 Quick Setup Guide

### 1. Environment Configuration (.env)
The environment configuration template is saved in [api_v1/.env](file:///api_v1/.env).
Configure your database credentials in `api_v1/.env`.

---

### 2. Database Migration & Seeding
Make sure MySQL / MariaDB is running, then run:
```bash
cd api_v1
php database/migrate.php
php database/seed.php
```

---

### 3. Running API Server & Scheduler Worker
Start the PHP built-in server:
```bash
php -S localhost:8000
```

*(Optional)* Start the scheduler background worker:
```bash
php api_v1/bin/scheduler_worker.php
```

---

### 4. Running Frontend
Install dependencies and run the Vite dev server:
```bash
cd frontend
npm install
npm run dev
```

Visit the app in your browser at `http://localhost:5173`.
