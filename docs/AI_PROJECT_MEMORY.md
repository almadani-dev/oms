# AI Project Memory

## Current Context

## Recent Changes

- **2026-07-05** — Added Phase 1 of "ميزان المراجعة" (Trial Balance) report: `TrialBalanceReportService`, `TrialBalancePage`, and its Blade view. Read-only, single-currency-scoped, period-movement-only (no opening/closing balance, no exports yet).
- **2026-07-05** — Added Phase 2: Excel/Word export buttons for Trial Balance (`TrialBalanceExcelExportService`, `TrialBalanceWordExportService`). Exports only ever read the applied/displayed report snapshot on `TrialBalancePage`, never live filter state.

## Important Notes

- `transaction_lines.debit_base`/`credit_base` are **not** converted-to-company-base-currency figures despite the name — they always equal `amount_currency` placed on the debit or credit side, in the line's own currency (confirmed by tracing `CreateGeneralExpense.php`/`CreateGeneralExchange.php` write paths, including cases where `fx_rate != 1`). Any report that sums these fields across accounts must scope to a single currency at a time, or totals will be silently blended and misleading. This drove the Trial Balance's required `currency_id` filter.
- `accounts.current_balance` must never be used for report totals (per project rule and confirmed audit finding) — Trial Balance computes everything from `transaction_lines` directly.

## Sensitive Data Rules

