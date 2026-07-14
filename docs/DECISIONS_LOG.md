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
Fixed the broken "صورة الإشعار" attachment image by removing the stray empty `public/storage` directory and recreating it as a symlink (`php artisan storage:link`), rather than changing any application code.

### Reason
Investigation showed the existing code (`FileUpload` disk config, file-move-and-rename logic, `Storage::disk('public')->url()` in the view) already followed correct Laravel/Filament convention. The actual defect was that `public/storage` existed as a plain empty directory instead of a symlink, which silently made `php artisan storage:link` a no-op ("link already exists") and 404'd every attachment URL. Confirmed the directory was empty before removing it, and got explicit user confirmation before deleting it.

### Impact
All existing attachments (receipt images/PDFs, execution-payment and general-expense proofs, etc.) that were already correctly saved under `storage/app/public/...` are now servable at `/storage/...` again — this was a site-wide symlink outage, not specific to Project Cost Receipts. No code, migration, or data changed.

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

---

### Date
2026-07-14

### Decision
`transactions.description` is generated automatically by one shared service (`TransactionDescriptionBuilder`) for all 6 financial write flows, in the fixed format `دائن: {credit entries} | مدين: {debit entries} | ملخص العملية: {summary}.` — credit side always first, debit side always second, summary always last. Each entry's currency code comes from `transaction_lines.currency_id`, **never** `accounts.currency_id`. Users cannot edit this value through any of the 6 flows' forms.

### Reason
Credit-before-debit and one canonical format make the field usable for search/audit/exports without every reader needing to know each flow's internal line order. The line's own currency (not the account's) is authoritative because cross-currency flows (disbursement, general exchange) post a line in a currency that can legitimately differ from the account's home currency at `fx_rate != 1` — this mirrors the existing project rule that `debit_base`/`credit_base` are always in the line's own currency, never the account's (see 2026-07-05 Important Notes in AI_PROJECT_MEMORY.md).

### Impact
Every Create/Edit page for the 6 flows now calls `TransactionDescriptionBuilder::buildAndSave()` as the last step inside its existing `DB::transaction()`, using a short flow-specific Arabic summary built from final saved relations (with graceful fallbacks when an optional relation like partner is absent). If either side of a transaction ends up with zero lines, the builder throws and the whole flow rolls back — no transaction can be left without a description. Historical transactions were not backfilled (explicitly out of scope for this task) — only newly created/edited transactions get a generated description. The separate raw `TransactionResource` admin CRUD (`admin/transactions`) originally still let a user free-type `description` directly; this was closed the same day — see the later 2026-07-14 "read-only audit resource" decision below.

---

### Date
2026-07-14

### Decision
While adding `RefreshDatabase`-based tests for the description feature, two pre-existing, unrelated migration bugs were found and fixed (approved separately, mid-task, after a full read-only investigation): `2026_06_09_130009_create_exchange_rate_history_table.php` was restored to create the table under its originally-intended singular name (`exchange_rate_history`), and `2026_06_09_174625_rename_exchange_rate_history_to_exchange_rate_histories.php`'s rename was made idempotent (`Schema::hasTable()` guards). `2026_06_09_130012_create_accounts_table.php` had its duplicate `current_balance`/`iban` column definitions removed (already added correctly by the later `2026_06_09_193018_add_columns_to_accounts_table.php`). A third incompatibility — `2026_06_24_000005_backfill_denormalized_currency_and_amounts.php` using MySQL-only `UPDATE ... JOIN` raw SQL — was found and **deliberately left untouched**.

### Reason
Both fixed bugs meant a fresh `migrate`/`RefreshDatabase` run would abort immediately (before reaching any table this task's tests actually needed), because each pair's create-migration file had, at some point after the live dev DB was already migrated, been edited to directly include columns/names that a later migration also tries to add/rename — invisible on the live dev DB (both migrations already recorded `Ran` from their real historical batches) but fatal on any genuinely fresh database (new clone, CI, or `:memory:` test DB). The third issue (MySQL-only backfill SQL) is real, historically-significant financial-backfill logic — rewriting it to be cross-database-compatible was judged out of scope and a materially different kind of risk than a migration-consistency fix, so `TransactionDescriptionBuilderTest` instead migrates only the handful of structural migrations it needs, skipping that one entirely.

### Impact
Confirmed via `migrate:status` before and after that the dev DB's recorded migration batches (3, 4, 5, 14, 19) were unaffected — these edits changed only the migration **files**, not any live schema or data. Any future fresh install, CI run, or `RefreshDatabase`-based test now succeeds past these two points. The repo still cannot run a full fresh `migrate` end-to-end because of the untouched MySQL-only backfill migration — flagged in AI_PROJECT_MEMORY.md's Important Notes and NEXT_STEPS.md for future consideration.

---

### Date
2026-07-14

### Decision
`TransactionResource` (`admin/transactions`, "المعاملات المالية") is permanently a **read-only audit resource**: no create, edit, delete, restore, or force-delete on transactions or their lines, enforced at both the UI layer (no such actions/routes exist) and the authorization layer (`can*` methods hardcoded to `false`, not just hidden buttons). The 6 legitimate financial flows (receipts, disbursement, execution payments, general expenses, general exchanges, opening balance) remain the **only** paths that can create or edit a `Transaction`/`TransactionLine`. Transactions and their lines are displayed newest-first by default (`defaultSort('id', 'desc')` on both the resource's list table and `LinesRelationManager`'s table), while users can still click any column header to sort differently.

### Reason
A read-only audit was requested after making `description` read-only on this resource's form, and it surfaced a materially bigger problem than the field-level one: the raw create flow produced a bare `Transaction` with **zero lines**, no `DB::transaction()`, and no balance/currency handling — a direct bypass of the double-entry + account-balance-update guarantee every other write path in this system enforces (violates the CLAUDE.md rule "Every financial operation must remain balanced double-entry accounting"). Its edit flow also let header fields (`partner_id`, `fiscal_year_id`, `transaction_type_id`, `transaction_time`) be changed independently of the transaction's owning `ProjectCostReceipt`/`ProjectCostBudget`/etc. row, risking silent desync between a financial record and its ledger entry. `LinesRelationManager` had the same gap at the line level (manual line create/edit/delete with no balance check, no account-balance increment/decrement). This is the same category of issue as the `ProjectCostBudgetResource` ("المبالغ المرصودة") removed on 2026-07-06 for being "a legacy bypass of the real business flow" — the fix here is narrower (keep the resource for its genuine audit value: list/search/filter/view any transaction and its lines) rather than deleting it outright, since unlike that resource, this one's read-only capability is actively useful and was explicitly requested to be preserved.

### Impact
`admin/transactions/create` and `admin/transactions/{record}/edit` no longer resolve to any route (404). `CreateTransaction.php`/`EditTransaction.php` page classes were deleted as confirmed-unreferenced dead code. The navigation entry, list table (search/filters/columns), view page, and lines relation table all remain fully functional for audit — nothing was removed from what a reviewer can see, only what they can change. Verified headlessly via reflection (built the real `Table` objects and called `getFlatActions()`/`getDefaultSortColumn()` directly) that both tables expose exactly the expected read-only action set and sort order, and via tinker that every `can*` authorization check returns `false`. Re-ran the six-flow tinker verification afterward and got byte-identical results to before this change, confirming the 6 legitimate flows and `TransactionDescriptionBuilder` were completely unaffected.
