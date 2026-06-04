# Travel ERP — Project Handover

**Document type:** Client delivery package  
**System:** Travel Services Operations & Accounting ERP  
**Stack:** Laravel 12 + MariaDB/MySQL + Arabic SPA frontend

---

## 1. Project overview

Travel ERP is an integrated system for travel agencies to manage:

- Customer and vendor relationships  
- Travel operations (sales, costs, profit)  
- Receipt and payment vouchers  
- Double-entry accounting with a persisted general ledger  
- Financial reports and role-based access control  

The user interface is a single-page application (`frontend/travelsystemv3.html`) served by Laravel at the same origin. All business data is stored in MySQL/MariaDB and accessed through a JSON REST API.

---

## 2. Features implemented

| Module | Capabilities |
|--------|----------------|
| **Authentication** | Email/password login, session logout, profile name/password update |
| **Dashboard** | KPIs, charts, recent operations, debtors/creditors (from dedicated API endpoints) |
| **Clients** | List, search, create, account statement, Excel export |
| **Vendors** | List, search, create, statement, Excel export |
| **Services** | List in settings, enable/disable (admin) |
| **Operations** | Create, list, filter, view details, cancel with reversal entries, Excel/PDF export |
| **Vouchers** | Receipt/payment vouchers, print, Excel export, journal posting |
| **Journal** | Full ledger, filters, trial balance, export/print |
| **Safes** | Cash and bank balances with movements |
| **Reports** | 7 report types (operations, profit, aging, employee, cashflow, client debt, vendor balance) |
| **Settings** | User list (admin), service toggles (admin), profile |

**Accounting:** Every operation and voucher creates balanced journal entries via `AccountingService`. Cancelling an operation posts reversing entries.

---

## 3. System architecture overview

```
┌─────────────────────────────────────────────────────────────┐
│  Browser                                                     │
│  travelsystemv3.html + travel-erp-api-bridge.js              │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTPS (production)
                           │ Cookies + X-XSRF-TOKEN
┌──────────────────────────▼──────────────────────────────────┐
│  Laravel 12 (backend/)                                       │
│  ├── routes/web.php    → SPA, login/logout, static JS        │
│  ├── routes/api.php    → JSON API (auth:sanctum)             │
│  ├── Controllers: Auth, Clients, Operations, Vouchers, etc.   │
│  ├── Policies + Form Requests                                 │
│  ├── Services: Accounting, Operation, Voucher, SafeResolver   │
│  └── Models + migrations + activity audit log                 │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│  MariaDB / MySQL (travel_erp)                                │
└─────────────────────────────────────────────────────────────┘
```

**Authentication model:** Laravel Sanctum **stateful SPA** (session cookies), not API tokens. See `SANCTUM_STATEFUL_DOMAINS` in `.env`.

**Primary data load:** `GET /api/bootstrap` returns metadata only (`user`, `users`, `services`, `safes`, `metrics`). The SPA loads clients, vendors, operations, and vouchers from paginated API endpoints.

**Financial safety:** Accounting is KWD-only until a full FX ledger is implemented. Mutating POST requests support `Idempotency-Key` to prevent duplicate records during retries or double-clicks.

---

## 4. Database overview

### Core tables

| Table | Purpose |
|-------|---------|
| `users` | System users with role (`admin`, `accountant`, `sales`, `auditor`) |
| `services` | Service catalog (flights, visa, hotel, etc.) |
| `clients` | Customers |
| `vendors` | Suppliers / offices |
| `operations` | Sales transactions |
| `vouchers` | Receipt (`receipt`) and payment (`payment`) documents |
| `safes` | Cash boxes and bank accounts |
| `chart_of_accounts` | GL account master |
| `journal_entries` | Double-entry ledger lines |
| `sessions` | Server-side sessions (when `SESSION_DRIVER=database`) |

### Chart of accounts (seeded)

| Code | Name (Arabic) | Type |
|------|---------------|------|
| 1100 | ذمم العملاء | asset |
| 2100 | ذمم الموردين | liability |
| 4100 | إيرادات الخدمات | revenue |
| 5100 | تكلفة الخدمات | expense |
| 1001 | الصندوق الرئيسي | asset (safe 1) |
| 1002 | البنك الأهلي الكويتي | asset (safe 2) |
| 9999 | حساب عام | asset |

### Seeded demo data (first install)

- 4 users, 5 services, 5 vendors, 8 clients  
- 12 operations, 10 vouchers, 2 safes  
- 68 journal entries (balanced)
- `db:seed` resets the ERP dataset before inserting demo data. Use it only for first install or intentional demo reset.

---

## 5. Roles and permissions

| Role | Email (seed) | Create op | Update status | Cancel op | Vouchers | Clients/vendors | Settings |
|------|----------------|-----------|---------------|-----------|----------|-----------------|----------|
| Admin | admin@travel.kw | Yes | Yes | Yes | Yes | Yes | Yes |
| Accountant | accountant@travel.kw | No | Complete settled ops | Yes | Yes | Yes | Read |
| Sales | sales@travel.kw | Yes | New → processing | Yes | No | Yes | Read |
| Auditor | auditor@travel.kw | No | No | No | No | No | Read |

Passwords are set once at seed time from `SEED_USER_PASSWORD` in `.env`. **Change before production use.**

---

## 6. Installation guide (local / staging)

### Prerequisites

- PHP 8.2+, Composer 2.x  
- MariaDB 10.6+ or MySQL 8+  
- Optional: Docker for local database  

### Steps

```bash
cd travel-erp/backend
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

- `DB_*` — database connection  
- `SEED_USER_PASSWORD` — password for all seeded users  
- `SANCTUM_STATEFUL_DOMAINS` — include your host:port  

Start database (Docker example):

```bash
docker compose up -d mariadb
# Default: 127.0.0.1:3307, DB travel_erp / travel_erp / travel_erp
```

Migrate and seed:

```bash
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8080
```

Open **http://127.0.0.1:8080** and log in with a seeded email and `SEED_USER_PASSWORD`.

### Verify installation

```bash
php artisan test --env=testing
php scripts/production-audit.php   # optional CLI checks
```

---

## 7. Deployment guide

Full production steps: **`backend/DEPLOYMENT.md`**

Summary:

1. Deploy `backend/` to server; web root = `backend/public`  
2. `composer install --no-dev --optimize-autoloader`  
3. Configure production `.env` (see `backend/.env.production.example`)  
4. `php artisan migrate --force` and one-time `db:seed`  
5. `php artisan config:cache` + `route:cache`  
6. Enable HTTPS, set `APP_DEBUG=false`  

---

## 8. Environment variables

| Variable | Purpose |
|----------|---------|
| `APP_NAME` | Application name |
| `APP_ENV` | `local` / `production` |
| `APP_KEY` | Encryption key (`php artisan key:generate`) |
| `APP_DEBUG` | **Must be `false` in production** |
| `APP_URL` | Full application URL (scheme + host) |
| `DB_*` | Database connection |
| `SESSION_DRIVER` | Use `database` for server sessions |
| `SESSION_ENCRYPT` | `true` in production |
| `SESSION_SECURE_COOKIE` | `true` with HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | Domains allowed for SPA cookies |
| `SEED_USER_PASSWORD` | Required for `db:seed` only |

---

## 9. Database setup steps

1. Create database and user with full privileges on that database.  
2. Set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env`.  
3. Run `php artisan migrate --force`.  
4. Run `php artisan db:seed --force` (requires `SEED_USER_PASSWORD`).  
5. Confirm: `php artisan tinker` → `JournalEntry::count()` should be > 0 after seed.

---

## 10. First-time setup instructions (client)

1. Receive server access and `.env` template.  
2. Set strong `SEED_USER_PASSWORD`; run migrate + seed **once**.  
3. Log in as `admin@travel.kw` and verify dashboard, journal balance, and reports.  
4. Create real users or change passwords in database (`users.password` = `Hash::make(...)`).  
5. Replace demo clients/vendors/operations with live data or clear and re-enter.  
6. Remove or unset `SEED_USER_PASSWORD` from production `.env` after seeding.  
7. Configure backups (see below).

---

## 11. Backup recommendations

| Asset | Frequency | Method |
|-------|-----------|--------|
| MySQL database | Daily | `mysqldump travel_erp > backup_YYYYMMDD.sql` |
| `.env` | On change | Secure secret store (not in git) |
| `storage/` | Weekly | If file uploads added later |

Test restore on staging before relying on backups.

---

## 12. Troubleshooting guide

| Symptom | Likely cause | Fix |
|---------|----------------|-----|
| Login works but API returns 401 | API routes not loading session (Postman/curl lack `Origin`, or host not in `SANCTUM_STATEFUL_DOMAINS`) | Align `APP_URL` and `SANCTUM_STATEFUL_DOMAINS` with `base_url` host:port; app uses `EnsureStatefulApiRequests` for cookie clients |
| CSRF token mismatch (419 on POST/PATCH) | Stale `XSRF-TOKEN` after login (`session()->regenerate()`) | Call `/sanctum/csrf-cookie` again after login; send decoded `X-XSRF-TOKEN` on mutating requests |
| `SEED_USER_PASSWORD` error on seed | Env not set | Add variable to `.env` and re-run seed |
| Blank dashboard after login | Bootstrap failed | Check network tab for `/api/bootstrap`; verify DB migrated |
| 403 on save | Role restriction | Use correct role (e.g. accountant for vouchers) |
| Journal not balanced | Data corruption / partial post | Restore backup; contact support |
| Port 8080 in use | Old PHP process | `fuser -k 8080/tcp` or change serve port |

**Logs:** `backend/storage/logs/laravel.log`

**Health check:** `GET /up` (Laravel built-in)

---

## 13. API documentation reference

| Document | Location |
|----------|----------|
| Full API reference | **`API_DOCUMENTATION.md`** (project root) |
| Endpoint list | 32 API operations + CSRF + login/logout |
| Auth model | Sanctum SPA cookies (documented in API guide) |

---

## 14. Postman collection reference

| File | Location |
|------|----------|
| Collection | **`Travel-ERP.postman_collection.json`** (project root) |

### Import steps

1. Open Postman → **Import** → select `Travel-ERP.postman_collection.json`.  
2. Open collection **Variables**; set `password` to your `SEED_USER_PASSWORD`.  
3. Run **Authentication → Get CSRF Cookie**.  
4. Run **Authentication → Login**.  
5. Run any other request (cookies are stored automatically).

**Variables:**

| Variable | Default | Description |
|----------|---------|-------------|
| `base_url` | `http://127.0.0.1:8080` | Application URL |
| `email` | `admin@travel.kw` | Login email |
| `password` | *(empty)* | Login password |
| `xsrf_token` | *(auto)* | Set by CSRF request test script |

**Note:** This API does **not** use Bearer tokens. Postman must use the **cookie jar**.

---

## 15. Production deployment checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`  
- [ ] `APP_KEY` generated and secured  
- [ ] HTTPS enabled; `APP_URL` uses `https://`  
- [ ] `SANCTUM_STATEFUL_DOMAINS` matches production domain  
- [ ] `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`  
- [ ] Database credentials are production-specific  
- [ ] `composer install --no-dev`  
- [ ] `php artisan migrate --force`  
- [ ] One-time seed with strong password; then rotate user passwords  
- [ ] Remove `SEED_USER_PASSWORD` from `.env` after seed (optional)  
- [ ] `config:cache`, `route:cache`  
- [ ] File permissions on `storage/` and `bootstrap/cache/`  
- [ ] Daily database backup scheduled  
- [ ] Smoke test: login, bootstrap, create operation, journal balanced  
- [ ] Run `php artisan test` on CI/staging  

---

## 16. Repository layout

```
travel-erp/
├── API_DOCUMENTATION.md          ← API reference (this handoff)
├── PROJECT_HANDOVER.md           ← This document
├── Travel-ERP.postman_collection.json
├── README.md                     ← Quick start
├── frontend/
│   ├── travelsystemv3.html       ← UI
│   └── travel-erp-api-bridge.js  ← API client
└── backend/
    ├── app/                      ← Application code
    ├── database/migrations/      ← Schema
    ├── database/seeders/         ← Demo data
    ├── routes/api.php            ← API routes
    ├── DEPLOYMENT.md             ← Production deploy
    ├── .env.production.example
    └── docker-compose.yml        ← Local MariaDB
```

---

## 17. Support and maintenance

| Task | Command |
|------|---------|
| Run tests | `cd backend && php artisan test --env=testing` |
| Clear caches | `php artisan optimize:clear` |
| Rebuild caches | `php artisan config:cache && php artisan route:cache` |

For API changes, update `API_DOCUMENTATION.md` and the Postman collection together.

---

**Handover status:** Feature-complete application with documentation and API collection suitable for client UAT and production deployment.
