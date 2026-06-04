# Travel ERP Backend Architecture

This Laravel backend is structured as a layered API for travel-agency operations and accounting.

## Request Flow

1. `routes/api.php` maps each resource to a dedicated controller under `App\Http\Controllers\Api`.
2. Form Requests validate input and reject unauthorized writes early.
3. Policies in `App\Policies` enforce resource-level authorization.
4. Controllers coordinate HTTP concerns only: filters, pagination, responses.
5. Services own business rules and accounting side effects.
6. Eloquent models persist domain state and relationships.

## Accounting Model

The system uses double-entry accounting with persisted journal lines.

- Operation creation posts:
  - Debit clients receivable (`1100`)
  - Credit service revenue (`4100`)
  - Debit service cost (`5100`)
  - Credit vendors payable (`2100`)
- Voucher creation posts:
  - Receipts: debit safe account, credit receivable/general account
  - Payments: debit payable/general account, credit safe account
- Cancellation appends reversing entries with multiplier `-1`. Posted history is not deleted.

## Delivery Decisions

- Currency is restricted to `KWD` until a real FX ledger is implemented.
- `GET /api/bootstrap` returns only metadata (`user`, `users`, `services`, `safes`, `metrics`); large datasets are fetched through paginated endpoints.
- List screens load one API page at a time (`page`, `per_page`, `search`); the SPA does not prefetch all clients/vendors/operations/vouchers on login.
- `PATCH` on clients, vendors, and operations updates master data; financial operation edits (status `new` only) reverse/repost journal lines in `OperationService`.
- `POST /api/vouchers/{id}/void` reverses a single voucher (`voided_at`, journal multiplier −1) without cancelling the operation.
- Mutating POST routes support `Idempotency-Key` to prevent duplicate financial records on retries or double-clicks.
- Safe selection for initial payments is resolved by safe type, not hardcoded IDs.
- Sensitive actions write to `activity_logs`.

## Test Commands

```bash
cd backend
php artisan test
./vendor/bin/pint --test
php scripts/production-audit.php
```
