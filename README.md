# SocialFlow - Social Media Scheduler & Management Platform

A full-stack social media scheduling platform with PHP vanilla MVC backend, MySQL/MariaDB database, and React + Vite + Tailwind CSS frontend.

---

## 🚀 Quick Setup Guide

### 1. Environment Configuration (.env)
The environment configuration template is saved in [backend/.env.example](file:///backend/.env.example).

To set up your environment:
1. In the `backend` folder, duplicate or copy `.env.example` to `.env`:
   - **Windows (PowerShell):**
     ```powershell
     Copy-Item backend/.env.example backend/.env
     ```
   - **Linux / macOS / Bash:**
     ```bash
     cp backend/.env.example backend/.env
     ```
2. Verify your database credentials in `backend/.env` (default is MySQL root user with no password on `127.0.0.1:3306`).

---

### 2. Database Migration & Seeding
Make sure MySQL / MariaDB is running (e.g. via XAMPP or MySQL Service), then run:
```bash
cd backend
php database/migrate.php
php database/seed.php
```

---

### 3. Running Backend Server & Scheduler Worker
Start the PHP built-in server:
```bash
cd backend
php -S localhost:8000 -t public
```

*(Optional)* Start the scheduler background worker in another terminal:
```bash
cd backend
php bin/scheduler_worker.php
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
