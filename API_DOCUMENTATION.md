# Travel ERP — API Documentation

**Version:** 1.0 (matches codebase as delivered)  
**Base URL:** `{APP_URL}` (e.g. `http://127.0.0.1:8080`)  
**API prefix:** `/api`  
**Authentication:** Laravel Sanctum **SPA session cookies** (not Bearer tokens)

---

## General information

### How the SPA authenticates

1. `GET /sanctum/csrf-cookie` — sets `XSRF-TOKEN` cookie  
2. `POST /api/login` — creates session (`travel-erp-session` cookie; name derived from `APP_NAME`)  
3. **`GET /sanctum/csrf-cookie` again after login** — login calls `session()->regenerate()`, which invalidates the pre-login CSRF token; refresh before any POST/PATCH/DELETE  
4. Subsequent mutating requests send cookies + header `X-XSRF-TOKEN: {URL-decoded XSRF-TOKEN cookie value}`  
5. `POST /api/logout` — destroys session (requires authentication)

Login and logout routes are registered in `routes/web.php` (web middleware + session). All other API routes are in `routes/api.php` behind `auth:sanctum` and Laravel’s `statefulApi()` middleware.

**Stateful API sessions:** Protected `/api/*` routes load the session only when Sanctum treats the request as “stateful”. The app uses `App\Http\Middleware\EnsureStatefulApiRequests`, which enables session middleware when either:

- `Origin` / `Referer` matches `SANCTUM_STATEFUL_DOMAINS` (browser SPA), or  
- the request `Host` matches `SANCTUM_STATEFUL_DOMAINS` (Postman, curl, Newman).

Ensure `base_url` / `APP_URL` host and port are listed in `SANCTUM_STATEFUL_DOMAINS` (e.g. `127.0.0.1:8080`).

### Common request headers

| Header | Required | Value |
|--------|----------|--------|
| `Accept` | Recommended | `application/json` |
| `Content-Type` | For JSON bodies | `application/json` |
| `X-XSRF-TOKEN` | POST/PATCH/DELETE (except where CSRF exempt) | URL-decoded value of `XSRF-TOKEN` cookie |
| `Cookie` | After login | `travel-erp-session`, `XSRF-TOKEN` (managed by browser/Postman cookie jar) |

### Roles and permissions

| Role | `role` value | Permissions |
|------|----------------|-------------|
| Admin | `admin` | Full access (Gates bypass) |
| Accountant | `accountant` | Cancel operations, create vouchers, read all, create clients/vendors |
| Sales | `sales` | Create/cancel operations, read all, create clients/vendors |
| Auditor | `auditor` | **Read-only** (all GET); writes return **403** |

Permission checks use `User::canPerform()` and Laravel Gates: `create-op`, `cancel-op`, `create-voucher`, `write-settings`.

### Standard error responses

| HTTP | When | Body (JSON) |
|------|------|-------------|
| 401 | Not logged in / session expired | `{"message":"Unauthenticated."}` |
| 403 | Forbidden (role or Gate) | `{"message":"Forbidden"}` |
| 404 | Unknown route or report type | `{"message":"Not found"}` |
| 429 | Too many login attempts | `{"message":"Too Many Attempts."}` |
| 422 | Validation failed | `{"message":"Validation failed","errors":{"field":["message"]}}` |
| 422 | Invalid login | `{"message":"...","errors":{"email":["بيانات الدخول غير صحيحة"]}}` |

### Success status codes

| Code | Usage |
|------|--------|
| 200 | GET, PATCH, logout |
| 201 | POST create (client, vendor, operation, voucher) |

---

## Authentication

### Get CSRF cookie

**Purpose:** Initialize CSRF protection for session-based SPA requests.

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/sanctum/csrf-cookie` |
| **Authentication** | None |

**Response:** `204 No Content` with `Set-Cookie: XSRF-TOKEN=...`

---

### Login

**Purpose:** Authenticate user and start session.

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/login` |
| **Authentication** | None |
| **CSRF** | Exempt in `bootstrap/app.php` (header still recommended) |

**Request body:**

```json
{
  "email": "admin@travel.kw",
  "password": "your-password-from-env"
}
```

**Data integrity (clients / vendors):** `phone` numbers are normalized (strip spaces, leading `0`, Kuwait country code `965`) before uniqueness checks. Vendor `name` is trimmed and unique. Duplicate create returns **422** with Arabic field errors.

**Login rate limit:** `POST /api/login` is limited to **10 attempts per minute** per IP (HTTP **429** when exceeded).

**Validation rules** (`LoginRequest`):

| Field | Rules |
|-------|--------|
| `email` | required, email |
| `password` | required, string |

**Success `200`:**

```json
{
  "user": {
    "id": 1,
    "name": "أحمد الكندري",
    "email": "admin@travel.kw",
    "role": "admin",
    "roleLabel": "مدير النظام",
    "avatar": "أ"
  }
}
```

**Error `422` (invalid credentials):**

```json
{
  "message": "بيانات الدخول غير صحيحة",
  "errors": {
    "email": ["بيانات الدخول غير صحيحة"]
  }
}
```

---

### Logout

**Purpose:** End session.

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/logout` |
| **Authentication** | Required (`auth:sanctum`) |

**Success `200`:**

```json
{
  "message": "Logged out"
}
```

---

### Current user

**Purpose:** Return authenticated user profile.

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/me` |
| **Authentication** | Required |
| **Permissions** | Any authenticated role |

**Success `200`:** Same `user` object shape as login.

---

## Dashboard

### Bootstrap (full application data)

**Purpose:** Single payload used by the frontend SPA (`loadAllData()`). Includes users, services, vendors, clients, operations, vouchers, safes, and summary **metrics** (not the full journal — use `GET /api/journal` for ledger data).

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/bootstrap` |
| **Authentication** | Required |
| **Permissions** | Any authenticated role |

**Success `200` (structure):**

```json
{
  "users": [{ "id": 1, "name": "...", "email": "...", "role": "admin", "roleLabel": "...", "avatar": "أ" }],
  "services": [{ "id": 1, "name": "تذكرة طيران", "icon": "✈️", "active": true, "created_at": "...", "updated_at": "..." }],
  "vendors": [{ "id": 1, "name": "...", "category": "airline", "phone": "...", "contact": "...", "address": "..." }],
  "clients": [{ "id": 1, "name": "...", "phone": "...", "alt_phone": "...", "civil_id": "...", "email": "...", "nationality": "...", "notes": "..." }],
  "operations": [{
    "id": 1, "ref": "OP-001", "client_id": 1, "service_id": 1, "vendor_id": 1,
    "currency": "KWD", "client_price": 450, "vendor_cost": 320, "profit": 130,
    "initial_payment": 200, "payment_method": "cash", "notes": "...",
    "status": "completed", "created_by": 3, "date": "2026-04-01",
    "client": "محمد سالم الصبيح", "service": "تذكرة طيران", "vendor": "الخطوط الجوية الكويتية"
  }],
  "vouchers": [{
    "id": 1, "ref": "RV-001", "type": "receipt", "party_type": "client", "party_id": 1,
    "amount": 250, "currency": "KWD", "method": "cash", "safe_id": 1, "operation_id": 1,
    "desc": "تحصيل جزئي OP-001", "description": "تحصيل جزئي OP-001", "date": "2026-04-01", "created_by": 2
  }],
  "safes": [{ "id": 1, "name": "الصندوق الرئيسي", "type": "cash", "currency": "KWD", "initial": 5000, "opening_balance": 5000, "balance": 5800 }],
  "metrics": {
    "total_receipts": 4625,
    "total_payments": 820,
    "journal_count": 68,
    "journal_balanced": true
  }
}
```

**Note:** `clients` and `vendors` include computed `balance`. Journal lines are loaded separately via `GET /api/journal` (supports pagination).

---

### Dashboard KPIs

**Purpose:** Aggregated metrics for dashboard widgets (charts, KPIs, top debtors). **Not used by the current SPA** (SPA uses bootstrap + client-side aggregation), but implemented and available.

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/dashboard` |
| **Authentication** | Required |
| **Permissions** | Any authenticated role |

**Success `200`:**

```json
{
  "today_sales": 0,
  "today_profit": 0,
  "total_receipts": 4625,
  "total_cash_receipts": 4625,
  "total_payments": 820,
  "week": {
    "days": ["03-28", "03-29", "..."],
    "receipts": [0, 250, 80],
    "payments": [0, 320, 50]
  },
  "services": [{ "name": "تذكرة طيران", "count": 4 }],
  "last_operations": [{ "id": 12, "ref": "OP-012", "status": "processing", "..." : "..." }],
  "overdue_operations": [],
  "top_debtors": [{ "id": 1, "name": "...", "balance": 200 }],
  "top_creditors": [{ "id": 1, "name": "...", "balance": 70 }]
}
```

`total_receipts` is client AR collections. `total_cash_receipts` is cash/bank inflow from receipt vouchers, including general receipts. `overdue_operations` only includes non-cancelled, non-completed operations with past dates and positive operation-level client outstanding balance.

---

## Clients

### List clients

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/clients` |
| **Authentication** | Required |
| **Permissions** | Any authenticated role |

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | Optional filter on name, phone, civil_id |
| `page` | integer | Page number (default `1`) |
| `per_page` | integer | Items per page (default `50`, max `200`) |

**Success `200`:**

```json
{
  "data": [{ "id": 1, "name": "...", "balance": 200, "operations_count": 2 }],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 50, "total": 8 }
}
```

---

### Create client

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/clients` |
| **Authentication** | Required |
| **Permissions** | admin, accountant, sales (**not** auditor) |

**Request body:**

```json
{
  "name": "عميل جديد",
  "phone": "99998877",
  "alt_phone": "",
  "civil_id": "",
  "email": "client@example.com",
  "nationality": "كويتي",
  "notes": ""
}
```

**Validation rules** (`StoreClientRequest`):

| Field | Rules |
|-------|--------|
| `name` | required, string, max:255 |
| `phone` | required, string, max:50, **unique** (normalized: strips spaces, leading `0`, `965` country code) |
| `alt_phone` | nullable, string, max:50 (same normalization when provided) |
| `civil_id` | nullable, string, max:50, **unique** when provided |
| `email` | nullable, email, max:255 |
| `nationality` | nullable, string, max:100 |
| `notes` | nullable, string, max:2000 |

**Success `201`:** Client object with `balance` and `operations_count` (same shape as list item).

**Error `403`:** Auditor or unauthenticated.

---

### Client statement

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/clients/{client}/statement` |
| **Path parameters** | `client` — client ID |
| **Authentication** | Required |
| **Permissions** | Any authenticated role |

**Success `200`:**

```json
{
  "client": { "id": 1, "name": "...", "balance": 200, "operations_count": 2 },
  "total_purchases": 850,
  "paid": 650,
  "balance": 200,
  "rows": [{
    "id": 1, "date": "2026-04-01", "ref": "OP-001", "account": "ذمم العملاء",
    "debit": 450, "credit": 0, "desc": "...", "balance": 450
  }]
}
```

---

## Vendors

### List vendors

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/vendors` |
| **Query parameters** | `search` — optional (name, phone) |
| **Authentication** | Required |

**Success `200`:**

```json
{
  "data": [{
    "id": 1,
    "name": "الخطوط الجوية الكويتية",
    "category": "airline",
    "phone": "1884800",
    "contact": "قسم المبيعات",
    "address": "الكويت",
    "balance": 70
  }]
}
```

---

### Create vendor

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/vendors` |
| **Permissions** | admin, accountant, sales (**not** auditor) |

**Request body:**

```json
{
  "name": "مورد جديد",
  "category": "hotel",
  "phone": "22334455",
  "contact": "مكتب الحجز",
  "address": "الكويت"
}
```

**Validation rules** (`StoreVendorRequest`):

| Field | Rules |
|-------|--------|
| `name` | required, string, max:255, **unique** |
| `category` | nullable, in: airline, hotel, visa, transport, other |
| `phone` | nullable, string, max:50 |
| `contact` | nullable, string, max:255 |
| `address` | nullable, string, max:255 |

**Success `201`:** Vendor with `balance`.

---

### Vendor statement

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/vendors/{vendor}/statement` |
| **Path parameters** | `vendor` — vendor ID |

**Success `200`:**

```json
{
  "vendor": { "id": 1, "name": "...", "balance": 70 },
  "balance": 70,
  "rows": [{ "id": 3, "date": "2026-04-01", "ref": "OP-001", "debit": 0, "credit": 320, "desc": "..." }]
}
```

---

## Services

There is **no** standalone `GET /api/services` endpoint. Active services are returned in **`GET /api/bootstrap`** (`services` array).

### Toggle service active status

| | |
|--|--|
| **Method** | `PATCH` |
| **URL** | `/api/services/{service}/toggle` |
| **Path parameters** | `service` — service ID |
| **Authentication** | Required |
| **Permissions** | **Admin only** (`write-settings` Gate) |

**Request body:** None

**Success `200`:**

```json
{
  "id": 1,
  "name": "تذكرة طيران",
  "icon": "✈️",
  "active": false,
  "created_at": "...",
  "updated_at": "..."
}
```

**Error `403`:** Non-admin users.

---

## Operations

### List operations

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/operations` |
| **Authentication** | Required |

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter: `new`, `processing`, `completed`, `cancelled`, or omit/`all` |
| `service` | integer | Filter by `service_id` |
| `search` | string | Search ref, client name, or service name |

**Success `200`:**

```json
{
  "data": [{
    "id": 1,
    "ref": "OP-001",
    "client_id": 1,
    "service_id": 1,
    "vendor_id": 1,
    "currency": "KWD",
    "client_price": 450,
    "vendor_cost": 320,
    "profit": 130,
    "initial_payment": 200,
    "payment_method": "cash",
    "notes": "تذكرة القاهرة ذهاب وإياب",
    "status": "completed",
    "created_by": 3,
    "date": "2026-04-01",
    "client": "محمد سالم الصبيح",
    "service": "تذكرة طيران",
    "vendor": "الخطوط الجوية الكويتية"
  }]
}
```

---

### Create operation

**Purpose:** Create operation, post journal entries, and optionally create initial receipt voucher.

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/operations` |
| **Permissions** | admin, sales (`create_op`) |

**Request body:**

```json
{
  "client_id": 1,
  "service_id": 1,
  "vendor_id": 1,
  "currency": "KWD",
  "client_price": 100,
  "vendor_cost": 75,
  "initial_payment": 50,
  "payment_method": "cash",
  "notes": "Test operation",
  "date": "2026-06-03"
}
```

**Validation rules** (`StoreOperationRequest`):

| Field | Rules |
|-------|--------|
| `client_id` | required, exists:clients,id |
| `service_id` | required, exists:services,id **and active** |
| `vendor_id` | required, exists:vendors,id |
| `currency` | nullable, string, size:3 |
| `client_price` | required, numeric, decimal:0,3, min:0.001 |
| `vendor_cost` | required, numeric, gte:0, **lte:client_price** (no loss-making sales) |
| `initial_payment` | nullable, numeric, gte:0, **lte:client_price** |
| `payment_method` | nullable, in: cash, bank, knet, check |
| `notes` | nullable, string, max:2000 |
| `date` | nullable, date, **not in the future** |

**Success `201`:** Operation payload; `profit` = client_price − vendor_cost; status starts as `new` unless the operation is fully settled immediately. If `initial_payment` > 0, a receipt voucher is auto-created to the cash or bank safe matching `payment_method`.

**Side effects:** 4 journal lines per operation (+ 2 per auto-receipt if applicable).

**Error `403`:** Accountant, auditor.

---

### Show operation

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/operations/{operation}` |
| **Path parameters** | `operation` — operation ID |

**Success `200`:** Operation fields plus:

```json
{
  "journal": [{ "id": 1, "ref": "OP-001", "account": "ذمم العملاء", "debit": 450, "credit": 0 }],
  "vouchers": [{ "id": 1, "ref": "RV-001", "type": "receipt", "amount": 250 }]
}
```

---

### Cancel operation

**Purpose:** Set status to `cancelled`, post reversing journal entries for the operation, and reverse all vouchers linked to that operation.

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/operations/{operation}/cancel` |
| **Permissions** | admin, sales, accountant (`cancel-op` Gate) |

**Request body:** None

**Success `200`:** Operation with `"status": "cancelled"`.

**Error `422`:** Operation already cancelled (`errors.operation`).

**Error `403`:** Auditor.

---

### Update operation status

**Purpose:** Move an operation through the workflow without changing accounting entries.

| | |
|--|--|
| **Method** | `PATCH` |
| **URL** | `/api/operations/{operation}/status` |
| **Permissions** | admin; sales can move `new` to `processing`; accountant can move settled `processing` operations to `completed` |

**Request body:**

```json
{ "status": "processing" }
```

Allowed manual transitions are `new → processing` and `processing → completed`. Completion is rejected unless both client and vendor outstanding balances for the operation are zero. Voucher posting also auto-completes linked operations once both sides are settled.

---

## Vouchers

### List vouchers

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/vouchers` |
| **Query parameters** | `type` — optional: `receipt` or `payment` |

**Success `200`:**

```json
{
  "data": [{
    "id": 1,
    "ref": "RV-001",
    "type": "receipt",
    "party_type": "client",
    "party_id": 1,
    "amount": 250,
    "currency": "KWD",
    "method": "cash",
    "safe_id": 1,
    "operation_id": 1,
    "desc": "تحصيل جزئي OP-001",
    "description": "تحصيل جزئي OP-001",
    "date": "2026-04-01",
    "created_by": 2
  }]
}
```

---

### Create voucher

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/vouchers` |
| **Permissions** | admin, accountant (`create_voucher`) |

**Request body:**

```json
{
  "type": "payment",
  "party_type": "vendor",
  "party_id": 2,
  "amount": 20,
  "currency": "KWD",
  "method": "bank",
  "safe_id": 2,
  "operation_id": null,
  "ref": null,
  "reference_number": null,
  "description": "Test payment",
  "date": "2026-06-03"
}
```

**Validation rules** (`StoreVoucherRequest`):

| Field | Rules |
|-------|--------|
| `type` | required, in: receipt, payment |
| `party_type` | nullable, in: client, vendor, general |
| `party_id` | required when party_type is client/vendor; must exist in matching table |
| `amount` | required, numeric, gt:0, min:0.001; **must not exceed outstanding client/vendor balance** (or operation outstanding when `operation_id` is set) |
| `currency` | nullable, string, size:3 |
| `method` | nullable, in: cash, bank, knet, check |
| `safe_id` | required, exists:safes,id |
| `operation_id` | nullable, exists:operations,id; **rejected if cancelled**; must match party |
| `ref` | nullable, string, max:50, unique:vouchers,ref (auto-generated via monotonic sequence if omitted) |
| `reference_number` | nullable, string, max:255 |
| `description` | nullable, string, max:2000 |
| `date` | nullable, date |

**Success `201`:** Voucher payload with `reversed: false`. If linked operation is later cancelled, `reversed` becomes `true` on reads (journal is reversed; voucher row remains for audit).

**Error `403`:** Sales, auditor.

---

### Show voucher

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/vouchers/{voucher}` |

**Success `200`:** Single voucher with `safe` and `operation` relations loaded internally.

---

## Journal

### List / filter journal

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/journal` |

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | ref or description |
| `account` | string | Account name or code; use `all` or omit for all |
| `from` | date (Y-m-d) | Start date |
| `to` | date (Y-m-d) | End date |
| `page` | integer | Page number (default `1`) |
| `per_page` | integer | Items per page (default `50`, max `200`) |

**Success `200`:** Paginated `data` array plus `meta`, filtered `totals` (debit/credit/balanced for the **full filtered query**, not just the current page), and `accounts` list.

```json
{
  "data": [{
    "id": 1,
    "date": "2026-04-01",
    "ref": "OP-001",
    "operation_id": 1,
    "voucher_id": null,
    "type": "op",
    "account": "ذمم العملاء",
    "party": "client",
    "party_id": 1,
    "party_name": "محمد سالم الصبيح",
    "debit": 450,
    "credit": 0,
    "desc": "OP-001 - تذكرة طيران - محمد سالم الصبيح"
  }],
  "totals": {
    "debit": 16950,
    "credit": 16950,
    "balanced": true
  },
  "accounts": [
    "ذمم العملاء",
    "ذمم الموردين",
    "إيرادات الخدمات",
    "تكلفة الخدمات",
    "الصندوق الرئيسي",
    "البنك الأهلي الكويتي",
    "حساب عام"
  ]
}
```

---

## Safes

### List safes with balances

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/safes` |

**Success `200`:**

```json
{
  "data": [{
    "id": 1,
    "name": "الصندوق الرئيسي",
    "type": "cash",
    "currency": "KWD",
    "initial": 5000,
    "opening_balance": 5000,
    "balance": 5800,
    "movements": [{ "id": 49, "date": "2026-04-01", "ref": "RV-001", "debit": 250, "credit": 0 }]
  }]
}
```

Seeded cash safe balance: **5800.000 KWD** (verified by tests).

---

## Reports

All report endpoints use:

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/reports/{type}` |
| **Authentication** | Required |

**Valid `{type}` values** (invalid type returns **404**):

| type | Purpose |
|------|---------|
| `operations` | Operations summary with revenue/cost/profit totals |
| `profit` | Profit by service |
| `aging` | Client receivables aging buckets |
| `employee` | Performance by user who created operations |
| `cashflow` | Daily cash/bank movement |
| `clients-debt` | Clients with positive balance |
| `vendors-balance` | Vendors with positive payables |

### Example: `GET /api/reports/clients-debt`

```json
{
  "rows": [{
    "id": 1,
    "name": "محمد سالم الصبيح",
    "phone": "99001122",
    "nationality": "كويتي",
    "totalPurchases": 850,
    "totalPaid": 650,
    "balance": 200,
    "opsCount": 2,
    "lastOpDate": "2026-04-10"
  }],
  "totalDebt": 1205
}
```

### Example: `GET /api/reports/profit`

```json
{
  "rows": [{
    "name": "باقة سياحية",
    "icon": "🌍",
    "count": 2,
    "revenue": 4700,
    "cost": 3700,
    "profit": 1000
  }]
}
```

---

## Settings

### List users

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/users` |
| **Permissions** | **Admin only** (`write-settings`) |

**Success `200`:**

```json
{
  "data": [{ "id": 1, "name": "...", "email": "admin@travel.kw", "role": "admin", "roleLabel": "مدير النظام", "avatar": "أ" }]
}
```

---

### Update profile

| | |
|--|--|
| **Method** | `PATCH` |
| **URL** | `/api/profile` |
| **Permissions** | Any authenticated user |

**Request body:**

```json
{
  "name": "أحمد الكندري"
}
```

**Validation** (`UpdateProfileRequest`): `name` — required, string, max:255

**Success `200`:**

```json
{
  "user": { "id": 1, "name": "أحمد الكندري", "email": "admin@travel.kw", "role": "admin", "roleLabel": "مدير النظام", "avatar": "أ" }
}
```

---

## Web routes (non-JSON)

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/` | Main SPA (`frontend/travelsystemv3.html`) |
| GET | `/travel-erp` | Same SPA (alias) |
| GET | `/travel-erp-api-bridge.js` | Frontend API bridge |
| GET | `/up` | Laravel health check |

---

## Accounting rules (API side effects)

| Action | Journal impact |
|--------|----------------|
| Create operation | 4 lines: receivables/revenue + cost/payables |
| Cancel operation | 4 reversing lines (multiplier −1) + reverse all linked vouchers |
| Create voucher | 2 lines: safe ↔ party account |
| Initial payment on operation | Auto receipt voucher + 2 journal lines |

**Receipt limits:** Client receipt vouchers cannot exceed the client's outstanding balance (or the linked operation's outstanding when `operation_id` is set).

**Report totals:** `paid` fields on statements and debt reports use **net journal collections** (not raw voucher row sums), so cancelled operations do not inflate KPIs.

Trial balance: total debits must equal total credits (`totals.balanced: true` on `/api/journal`).

---

## Postman

Import **`Travel-ERP.postman_collection.json`** from the project root.

1. Set collection variables: `base_url` (e.g. `http://127.0.0.1:8080`), `email`, **`password`** (required — set to `SEED_USER_PASSWORD` from `.env`; empty password causes login failure).
2. Run the **Authentication** folder in order: **Get CSRF Cookie** → **Login** → **Refresh CSRF Cookie (after login)** → **Get Current User**.
3. Run **Dashboard** (or other folders). Cookies and `xsrf_token` are updated automatically by the collection scripts.

**Automated smoke test (Newman):**

```bash
npx newman run Travel-ERP.postman_collection.json \
  --env-var "password=YOUR_PASSWORD" \
  --folder "0 — Smoke Test (شغّل بالترتيب)"
```

This folder runs auth, CSRF refresh, all GET smoke checks, and every POST/PATCH endpoint in order.

`base_url` host/port must appear in `SANCTUM_STATEFUL_DOMAINS`. See **`PROJECT_HANDOVER.md`** for full setup.
