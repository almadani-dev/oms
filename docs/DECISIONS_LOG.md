# Decisions Log

## Decision Template

### Date

### Decision

### Reason

### Impact

---

### Date
2026-07-05

### Decision
Trial Balance Phase 1 requires a single `currency_id` filter and never blends totals across currencies. If no currency is flagged `is_base`, the page falls back to the first currency by id rather than leaving the field blank. Per-row مدين/دائن balance stays plain text (uncolored); green/red is reserved exclusively for the overall حالة الميزان (متوازن/غير متوازن) badge.

### Reason
Audit traced `transaction_lines.debit_base`/`credit_base` write paths and found they always hold the line's own-currency amount, never a true company-base-currency conversion (even when `fx_rate != 1`). Summing them across accounts in different currencies would produce a meaningless blended total and a misleading balance verdict. The uncolored-row / colored-summary-badge split keeps "this account is in debit" visually distinct from "the ledger doesn't balance."

### Impact
No blended cross-currency Trial Balance is possible in Phase 1 — users must pick a currency to view its accounts. Opening/closing balance and exports deferred to later phases.


---

### Date
2026-07-06

### Decision
"تصنيف المعاملة" in the Comprehensive Financial Transactions report maps to `TransactionSuperType` (via `transactions_types.transaction_super_type_id`), "نوع المعاملة" maps to `TransactionType` (on the transaction, applied to all its lines), and "المشروع" is resolved only through `transaction_lines.project_cost_id → projects_costs.project_id → projects`. Unlike Trial Balance, the detail table may mix currencies, but every summary (per currency, per category, per type) keeps debit/credit totals separated per currency.

### Reason
These are the only reliable existing relations — no guessing. `debit_base`/`credit_base` remain own-currency amounts (see 2026-07-05 decision), so a journal-style listing can show mixed currencies per line, but any aggregation across lines must stay currency-scoped or it would blend meaningless totals.

### Impact
Lines whose transaction lacks a type/super type show "غير محدد"; lines without a project cost show "غير مرتبط بمشروع". Per-currency balance status can read "غير متوازن" for periods containing cross-currency transactions — expected behavior, not an error.

---

### Date
2026-07-06

### Decision
Reset all business/operational data via `TRUNCATE` on 16 explicitly-listed tables (accounts, partners, projects, projects_costs, transactions, transaction_lines, and their related cost/budget/receipt/attachment/snapshot/alert tables), keeping all settings, lookup tables, auth, and Laravel system tables untouched. Took a full `mysqldump` backup first and required explicit user approval of the keep/purge lists before executing.

### Reason
User wanted to start entering real production data from a clean slate without re-running migrations or touching schema/config/permissions. A full FK audit (`information_schema.KEY_COLUMN_USAGE`) confirmed no kept table depends on any purge table, so a single grouped truncate under `FOREIGN_KEY_CHECKS=0` is safe and consistent — no orphaned references are left in kept tables.

### Impact
All financial/operational history prior to 2026-07-06 is gone from the live database (recoverable only from `storage/app/private/backups/oms_backup_2026-07-06.sql`). AUTO_INCREMENT on all 16 tables restarts from 1. Uploaded files referenced by the now-empty `attachments` table were intentionally left on disk (not deleted).
