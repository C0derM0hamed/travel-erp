# Travel ERP — Backend

Laravel 12 API for the Travel ERP frontend (`../frontend/travelsystemv3.html`).

## Stack

- Laravel 12, Sanctum (SPA cookie auth), MySQL/MariaDB
- Persisted double-entry `journal_entries`
- Dedicated API controllers, Form Requests, Policies, and services (`AccountingService`, `OperationService`, `VoucherService`, `SafeResolver`)

## Local development

```bash
docker compose up -d mariadb
composer install
cp .env.example .env
php artisan key:generate
# Set DB_PORT=3307, DB_* from docker-compose.yml, and SEED_USER_PASSWORD
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8080
```

## Seeding

`php artisan db:seed` requires `SEED_USER_PASSWORD` in `.env`. All seeded users share this password at creation time. Change passwords in production after go-live.

Seeded dataset: 4 users, 5 services, 5 vendors, 8 clients, 13 operations, 10 vouchers, 2 safes, balanced journal.

## API overview

| Method | Path | Notes |
|--------|------|--------|
| POST | `/api/login`, `/api/logout` | Web routes (CSRF) |
| GET | `/api/bootstrap` | Lightweight metadata only |
| POST | `/api/clients`, `/api/vendors` | Blocked for auditor |
| POST | `/api/operations` | Sales+ |
| POST | `/api/operations/{id}/cancel` | Admin, accountant |
| POST | `/api/vouchers` | Accountant+ |
| GET | `/api/journal`, `/api/reports/{type}` | Read |
| PATCH | `/api/services/{id}/toggle` | Admin only |
| POST/PATCH | `/api/users`, `/api/users/{id}` | Admin only |

Mutating POST routes accept `Idempotency-Key` and accounting is KWD-only.

## Tests

```bash
php artisan test --env=testing
```

Uses SQLite `database/testing.sqlite` and `SEED_USER_PASSWORD` from `.env.testing`.

## Production

See **[DEPLOYMENT.md](DEPLOYMENT.md)**.
