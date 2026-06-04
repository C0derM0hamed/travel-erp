# Agent Notes

Use `backend/ARCHITECTURE.md` as the source of truth for backend design.

Important rules:

- Keep accounting changes inside services, not controllers.
- Never post journal lines outside `AccountingService`.
- Do not reintroduce full entity payloads into `/api/bootstrap`.
- Keep currency restricted to `KWD` unless a complete FX accounting design is added.
- Add or update feature tests for financial, authorization, and reporting behavior.

Verification:

```bash
cd backend
php artisan test
php scripts/production-audit.php
```
