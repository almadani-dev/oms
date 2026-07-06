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

---

### Date
2026-07-06

### Decision
Opening balances on account creation are recorded as a balanced transaction (`OPB-YYYY-XXXX`, type "قيد افتتاحي" under new super type "قيود افتتاحية") that always **debits** the new account and **credits** a per-currency clearing account "أرصدة افتتاحية" (own AccountType, code `OPB-{CUR}`). `debit_base`/`credit_base` follow the existing own-currency convention (fx_rate stored as line metadata only, never multiplied). `current_balance` became display-only on the account form. Opening lines carry `project_cost_id = null`.

### Reason
The schema has no account nature (asset/liability/equity) — `accounts_type` holds cash/bank/wallet/partner categories — and every existing financial page treats all accounts as debit-normal (debit = increment balance). Branching debit/credit by nature would invent accounting behavior the system doesn't have; the user chose the always-debit convention plus the own-currency base convention explicitly (4 options presented, all recommended options approved). Multiplying by fx_rate would have silently broken per-currency Trial Balance totals.

### Impact
Negative opening balances are not supported (form enforces min 0). Clearing accounts intentionally carry negative balances. Opening entries appear in Trial Balance / Account Statement / Comprehensive report (correct — they are real ledger entries) under their own "قيود افتتاحية" category, and can never appear in project reports or snapshots because those read only `project_cost_receipts`/`project_cost_budgets`/`project_cost_budgets_payments`. If soft-deleted lookup rows ("قيد افتتاحي" type, clearing account, its AccountType) are found, they are restored rather than duplicated.

---

### Date
2026-07-06

### Decision
"تقرير الجهات المانحة" (Donor Financial Report) keeps three currency grains fully separate rather than converting/blending them into one total: cost-side (`projects_costs`/`project_cost_receipts` currency), disbursement-source (`project_cost_budgets.source_currency_id`, also the currency of the tagged `LINE_ADMIN`/`LINE_TRANSFER` transaction lines), and execution (`project_cost_budgets.disbursement_currency_id`, matching `project_cost_budgets_payments.currency_id`). Admin/transfer deduction totals are read from the authoritative `transaction_lines` rows tagged with `ProjectCostBudget::LINE_ADMIN`/`LINE_TRANSFER` (written once, at disbursement time, by `CreateProjectCostBudgetsPayment`) rather than re-derived from the stored percentage columns.

### Reason
Same rationale as the 2026-07-05 Trial Balance decision: summing across currencies produces a meaningless blended figure. Reading deductions from the tagged transaction lines (instead of recomputing `original_amount * percentage`) keeps the report anchored to what was actually posted to the ledger, so it can never drift from the accounting records even if a budget row's stored percentage were edited after the fact.

### Impact
The report shows up to three separate per-currency tables (cost, disbursement-source, execution) instead of one blended summary — correct behavior for a donor whose projects span multiple currencies, not a defect. The deduction join assumes each disbursement transaction is created fresh and 1:1 with its `project_cost_budgets` row (confirmed true in the current `CreateProjectCostBudgetsPayment` code path) — if a future "batch disbursement" feature ever lets one transaction serve multiple budget rows, this join would need to be revisited (see NEXT_STEPS.md).
