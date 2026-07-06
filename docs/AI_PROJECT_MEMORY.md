# AI Project Memory

## Current Context

## Recent Changes

- **2026-07-05** — Added Phase 1 of "ميزان المراجعة" (Trial Balance) report: `TrialBalanceReportService`, `TrialBalancePage`, and its Blade view. Read-only, single-currency-scoped, period-movement-only (no opening/closing balance, no exports yet).
- **2026-07-05** — Added Phase 2: Excel/Word export buttons for Trial Balance (`TrialBalanceExcelExportService`, `TrialBalanceWordExportService`). Exports only ever read the applied/displayed report snapshot on `TrialBalancePage`, never live filter state.

- **2026-07-06** — Added Phase 1 of “تقرير الحركات المالية الشامل” (Comprehensive Financial Transactions report): `ComprehensiveFinancialTransactionsReportService`, `ComprehensiveFinancialTransactionsPage` (slug `comprehensive-financial-transactions`, nav sort 4 after Trial Balance), and its Blade view. Read-only journal listing, one row per transaction line, mixed currencies allowed in the detail table but every summary grouped per currency. Category = TransactionSuperType, type = TransactionType, project via transaction_lines.project_cost_id → projects_costs.project_id. No exports yet.

- **2026-07-06** — Enhanced the Comprehensive Financial Transactions summary section: category/type sections retitled to إحصائيات and now show distinct-transaction counts (`transaction_count` per bucket in `finalizeGrouped()`, tracked via transaction-id sets — never line count ÷ 2); general summary labels renamed to عدد المعاملات / عدد بنود القيود. Debit/credit totals stayed line-based and per-currency.

- **2026-07-06** — Added Phase 2: Excel/Word export buttons for the Comprehensive Financial Transactions report (`ComprehensiveFinancialTransactionsExcelExportService`, `ComprehensiveFinancialTransactionsWordExportService`). Same snapshot-only pattern as Trial Balance exports: they render the applied/displayed result only, gated by `canExport()` on `hasSubmitted`. Category/type stats export one row per bucket+currency with the distinct-transaction count written once per bucket.

- **2026-07-06** — Data reset: purged all rows (TRUNCATE, AUTO_INCREMENT reset) from 16 operational/business tables so the user can start entering clean real data — `accounts`, `attachments`, `bank_accounts`, `general_exchanges`, `general_expenses`, `partners`, `project_cost_budgets`, `project_cost_budgets_payments`, `project_cost_receipts`, `project_financial_alerts`, `project_financial_snapshot_currency_totals`, `project_financial_snapshots`, `projects`, `projects_costs`, `transaction_lines`, `transactions`. All settings/lookup/auth/system tables (`settings`, `roles`, `permissions`, `users`, `currencies`, `fiscal_years`, `bank_types`, `accounts_type`, `partners_types`, `projects_super`, `projects_status`, `transaction_super_types`, `transactions_types`, etc.) were left untouched. No migrations, no schema changes, no code changes. Full pre-purge backup at `storage/app/private/backups/oms_backup_2026-07-06.sql`. Storage files (attachments, receipts) were **not** deleted — only the DB rows referencing them.

## Important Notes

- `transaction_lines.debit_base`/`credit_base` are **not** converted-to-company-base-currency figures despite the name — they always equal `amount_currency` placed on the debit or credit side, in the line's own currency (confirmed by tracing `CreateGeneralExpense.php`/`CreateGeneralExchange.php` write paths, including cases where `fx_rate != 1`). Any report that sums these fields across accounts must scope to a single currency at a time, or totals will be silently blended and misleading. This drove the Trial Balance's required `currency_id` filter.
- `accounts.current_balance` must never be used for report totals (per project rule and confirmed audit finding) — Trial Balance computes everything from `transaction_lines` directly.

## Sensitive Data Rules

