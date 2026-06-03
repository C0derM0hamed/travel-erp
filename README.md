# Travel ERP

Arabic-first travel agency operations and accounting system. The UI lives in `frontend/`; the Laravel 12 API and database live in `backend/`.

## Features

- Operations, clients, vendors, vouchers, journal, safes, reports, settings
- Role-based access: admin, accountant, sales, auditor
- Double-entry accounting with persisted journal entries
- Same-origin SPA auth (Laravel Sanctum cookies)

## Quick start (local)

```bash
cd backend
cp .env.example .env
# Edit .env: set APP_KEY (php artisan key:generate), DB_*, and SEED_USER_PASSWORD
docker compose up -d mariadb
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8080
```

Open **http://127.0.0.1:8080** and sign in with a seeded user email (see seeder) and the password you set in `SEED_USER_PASSWORD`.

Default seeded accounts (emails only):

| Email | Role |
|-------|------|
| admin@travel.kw | Admin |
| accountant@travel.kw | Accountant |
| sales@travel.kw | Sales |
| auditor@travel.kw | Auditor |

Passwords are **not** stored in the frontend. Set `SEED_USER_PASSWORD` in `.env` before seeding.

## Tests

```bash
cd backend
php artisan test --env=testing
```

## Documentation

### Client handoff package

- **[PROJECT_HANDOVER.md](PROJECT_HANDOVER.md)** — installation, architecture, roles, troubleshooting
- **[API_DOCUMENTATION.md](API_DOCUMENTATION.md)** — complete REST API reference
- **[Travel-ERP.postman_collection.json](Travel-ERP.postman_collection.json)** — Postman collection (31 requests)

### Technical

- **[backend/DEPLOYMENT.md](backend/DEPLOYMENT.md)** — production deployment checklist
- **[backend/README.md](backend/README.md)** — backend technical reference

## Project layout

```
frontend/
  travelsystemv3.html      # Main UI
  travel-erp-api-bridge.js # API integration
backend/
  app/                     # Laravel application
  database/                # Migrations & seeders
  routes/                  # Web + API routes
  docker-compose.yml       # Local MariaDB
```
