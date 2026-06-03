# Travel ERP — Production Deployment Guide

## Prerequisites

- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`
- Composer 2.x
- MySQL 8+ or MariaDB 10.6+
- HTTPS reverse proxy (Nginx or Apache)
- Node.js **not** required (static frontend served by Laravel)

**Repository layout:** Deploy the full repo so `backend/` and `frontend/` remain siblings. Laravel serves `../frontend/travelsystemv3.html` — deploying only `backend/` breaks the UI.

## 1. Server preparation

```bash
cd /var/www/travel-erp/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

## 2. Environment (`.env`)

| Variable | Production value |
|----------|------------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-domain.example` (must match public URL) |
| `DB_*` | Production database credentials |
| `SESSION_DRIVER` | `database` |
| `SESSION_ENCRYPT` | `true` (recommended in production) |
| `SESSION_DOMAIN` | Your cookie domain if needed |
| `SANCTUM_STATEFUL_DOMAINS` | `your-domain.example` (and `www.` if used) |
| `TRUSTED_PROXIES` | Reverse proxy IPs, or `*` only when Laravel is not directly internet-facing |
| `SEED_USER_PASSWORD` | Strong password (only for initial `db:seed`) |
| `QUEUE_CONNECTION` | `sync` (no queue worker required) |

**Security:** Never commit `.env`. Remove or rotate `SEED_USER_PASSWORD` after the first seed if you prefer not to keep it in production `.env`.

**HTTPS behind a proxy:** Ensure your reverse proxy forwards `X-Forwarded-Proto` and set `TRUSTED_PROXIES` so Laravel detects HTTPS correctly. This is required for secure session cookies and Sanctum cookie auth.

## 3. Database

If using Docker MariaDB locally/staging, start it **before** migrating:

```bash
docker compose up -d mariadb   # from backend/
php artisan migrate --force
php artisan db:seed --force   # first install only; requires SEED_USER_PASSWORD
```

`db:seed` resets all ERP demo data before inserting the UAT dataset. Run it only for the first install or a deliberate demo reset. In production, seeding without `--force` is blocked unless `ALLOW_PRODUCTION_SEED=true`.

Change all user passwords after first login (via database or a future admin tool). Seeded passwords all use `SEED_USER_PASSWORD` once at seed time.

## 4. Laravel optimization

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 5. Web server

Document root must be `backend/public`.

Example Nginx location:

```nginx
root /var/www/travel-erp/backend/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

The app serves:

- `/` — main UI (`frontend/travelsystemv3.html`)
- `/travel-erp-api-bridge.js` — API bridge
- `/api/*` — JSON API (Sanctum)

Enable HTTPS and redirect HTTP → HTTPS.

## 6. File permissions

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
```

## 7. Docker MariaDB (optional, local/staging)

```bash
docker compose up -d mariadb
```

Default compose maps `127.0.0.1:3307` → MariaDB with database/user `travel_erp`.

## 8. Post-deploy verification

```bash
php artisan test --env=testing   # on CI/staging with SQLite
php scripts/production-audit.php   # optional CLI checks
curl -I https://your-domain.example/
```

Manual checks:

1. Login with each role
2. Dashboard, operations, clients, vendors, vouchers, journal, safes, reports, settings
3. Journal trial balance shows balanced
4. Create operation (sales), voucher (accountant), confirm auditor cannot write

## 9. Backups

- Schedule daily MySQL dumps of `travel_erp`
- Retain `storage/` if you add uploads later

## 10. Rollback

- Restore database dump
- Redeploy previous application release
- Run `php artisan migrate:rollback` only when release notes require it
