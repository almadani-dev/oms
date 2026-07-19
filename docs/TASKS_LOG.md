# Tasks Log

## Task Template

### Date

### Task

### Result

### Changed Files

### Verification

### Commit Hash

---

### Date
2026-07-15

### Task
Build a safe, environment-gated Artisan command + service to permanently wipe operational/transactional data from the OMS development database while preserving system configuration, donors, accounts, currencies, exchange-rate history, and users/roles/permissions. Phase 1 only: implement, test, and dry-run — apply must not run without explicit approval.

### Result
Implemented `App\Services\Maintenance\OperationalDataCleanupService` (+ `OperationalCleanupReport` DTO) and `php artisan oms:clean-operational-data --dry-run` / `--apply --confirmation=... --backup-file=...` / `--skip-files`. Environment guard restricts to `local`/`development`/`testing`; apply refuses without the exact confirmation token `DELETE-OMS-OPERATIONAL-DATA` and a verified non-empty backup file. Deletion is child-before-parent (12 tables + linked attachments + account-balance reset to 0), inside one `DB::transaction()`, using `DB::table()->delete()` to cover active+soft-deleted rows in one statement and to avoid firing the 5 project-snapshot-dirtying Eloquent observers unnecessarily. Donor classification uses only `partners.is_donor` (no other authoritative field exists); all non-donor partners are preserved and reported as unclassified. Attachment files are deleted only by exact `file_path` match to a deleted attachment row, after the DB transaction commits; unmatched files in known attachment directories are reported as orphans, never deleted. Ran `--dry-run` against the real dev DB: 20 preserved tables/2 users/5 roles/24 permissions/15 accounts (11 non-zero balances)/2 donors/0 exchange-rate-history rows untouched; 13 operational tables with rows (10+2 transactions, 24+4 transaction_lines, 1+1 projects, etc.) proposed for deletion; 8 linked attachment DB rows (6 files present, 2 missing), 6 orphan files (706133 bytes, likely leftovers from the 2026-07-06 manual TRUNCATE reset) reported only; found and reported one unexpected legacy table `bank_accounts` (0 rows, no model) — confirmed zero writes via before/after row-count and balance comparison.

### Changed Files
- `app/Services/Maintenance/OperationalDataCleanupService.php` (new)
- `app/Services/Maintenance/OperationalCleanupReport.php` (new)
- `app/Console/Commands/CleanOperationalData.php` (new)
- `tests/Feature/Commands/CleanOperationalDataCommandTest.php` (new, 20 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
`php -l` clean on all 4 new/changed PHP files. New test suite: 20/20 passing (67 assertions) against a selectively-migrated SQLite `:memory:` schema. Full `php artisan test`: 71/72 (the 1 failure, `ExampleTest`, is pre-existing/unrelated — hits `/`, a route this Filament app never defines). `php artisan optimize:clear` ran clean. `php artisan oms:clean-operational-data --dry-run` run against the real dev DB and confirmed zero writes (row counts and account balances identical before/after via direct tinker query). `--apply` was never run against the real dev DB, per instructions.

### Commit Hash
Not committed — awaiting explicit approval for the apply phase before any commit.

---

### Date
2026-07-16

### Task
Following the same-day read-only audit of the Execution Payment (`صرف مبالغ التنفيذ`) credit-account flow, implement the approved enhancement: move the "الحساب الدائن" section above "الحساب المدين (المستفيد)" on `/admin/execution-payments/create`, replace the display-only credit account with a real editable account-type → bank-type → currency → account cascade (defaulting from the selected budget's destination account, but user-overridable to any same-currency account before saving), and fix the pre-existing Edit drift bug where the historical credit account could be silently overwritten by the budget's current destination account.

### Result
Changed 3 files: `ExecutionPaymentForm.php` (section reorder, new `credit_account_type_id`/`credit_bank_type_id`/`credit_currency`/`credit_account_id` cascade replacing `credit_account_display`, new `applyCreditDefaults()` helper wired into the `project_cost_budget_id` Select's `afterStateUpdated`, new `validateCreditAccount()` server-side guard throwing `ValidationException` with Arabic messages), `CreateExecutionPayment.php` (uses the validated submitted credit account instead of always recomputing from the budget destination), `EditExecutionPayment.php` (hydrates the credit cascade from the payment's own saved `LINE_CREDIT` line instead of the budget's current destination — fixing the drift bug — and no longer recomputes the credit account from the budget in `handleRecordUpdate()`). No migration, no model change: the actually-selected account is still persisted only via `transaction_lines.account_id` on the `LINE_CREDIT`/`execution_source` line, exactly as before. Cross-currency replacement accounts are rejected server-side, not just hidden by Select options. `TransactionLineRole`, `TransactionDescriptionBuilder`, `TransactionLineDescriptionBuilder`, the disbursement flow, reports, and financial snapshots were not touched. See `docs/AI_PROJECT_MEMORY.md` (2026-07-16 entry) for full detail and `docs/DECISIONS_LOG.md` for the currency-restriction and drift-fix reasoning.

### Changed Files
- `app/Filament/Resources/ExecutionPayments/Schemas/ExecutionPaymentForm.php`
- `app/Filament/Resources/ExecutionPayments/Pages/CreateExecutionPayment.php`
- `app/Filament/Resources/ExecutionPayments/Pages/EditExecutionPayment.php`
- `tests/Feature/ExecutionPayments/ExecutionPaymentCreditAccountTest.php` (new, 7 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set)

### Verification
`php -l` clean on all 4 changed/new PHP files. New test suite: 7/7 passing (32 assertions) against a selectively-migrated SQLite `:memory:` schema (same pattern as `CleanOperationalDataCommandTest`), covering untouched-default create, manual-override create, cross-currency rejection (zero mutation), Edit hydration from the saved line (not the drifted budget destination), Edit-with-unrelated-field-change preserving the historical account, Edit manual account change (single active `LINE_CREDIT` line, correct balance reversal/reapplication, updated descriptions), and the reactive default on a genuine budget change. Existing description/line-description/financial regression suites (`TransactionDescriptionBuilderTest`, `TransactionLineDescriptionBuilderTest`, `BackfillTransactionDescriptionsCommandTest`, `CleanOperationalDataCommandTest`): 70/70 passing, unaffected. Full `php artisan test`: 78/79 (the 1 failure, `ExampleTest`, is pre-existing/unrelated — hits `/`, a route this Filament app never defines). `route:list` confirmed the 4 execution-payments routes (index/create/view/edit) are unchanged. `php artisan optimize:clear` ran clean. `graphify update .` ran clean (AST-only, no API cost).

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-18

### Task
Implement the approved positive-amount validation change, following the same-day read-only audit of all 5 financial workflows (project cost receipts, project cost budget disbursements, execution payments, general expenses, general exchanges) that found no server-side protection against zero/negative amounts, FX rates, or deduction percentages. Add matching Filament UI constraints, a reusable independent server-side guard, wire it into all 10 Create/Edit page handlers before any mutation, remove the unsafe silent `fx_rate ?: 1` fallback on submitted data in the two deduction/FX workflows, and add targeted tests.

### Result
Added `->minValue(0.01)` to all 5 amount fields (`ProjectCostReceiptForm.amount`, `ExecutionPaymentForm.amount`, `GeneralExpenseForm.amount`, `ProjectCostBudgetsPaymentForm.original_amount`, `GeneralExchangeForm.original_amount`), `->minValue(0)->maxValue(100)` to both percentage fields in `ProjectCostBudgetsPaymentForm`/`GeneralExchangeForm`, and `->minValue(0.000001)` to both `fx_rate` fields. New `App\Services\Validation\FinancialAmountGuard` (static methods, Arabic `ValidationException` messages) is called in all 10 Create/Edit `handleRecordCreation`/`handleRecordUpdate` methods, before `DB::transaction()` opens in every case — simple workflows call `assertSimpleAmount()`, the two deduction/FX workflows call the composed `assertDisbursementInputs()` covering the original amount, both percentages (individually and combined < 100), the FX rate, and both derived amounts. Replaced `(float) ($data['fx_rate'] ?: 1)` with `(float) ($data['fx_rate'] ?? 1)` at the 4 submitted-data sites in `CreateProjectCostBudgetsPayment`/`EditProjectCostBudgetsPayment`/`CreateGeneralExchange`/`EditGeneralExchange` handlers — an explicitly-submitted `0` now reaches the guard and is rejected instead of silently becoming `1`. Display-only `?: 1` fallbacks (Edit hydration reading saved records, the forms' "احسب" live-preview button) and `deriveAmounts()`'s own internal formula were deliberately left untouched, per instructions. Execution-payment over-budget behavior stays a non-blocking warning; account/currency/type revalidation for the other 4 workflows stays out of scope.

### Changed Files
- `app/Services/Validation/FinancialAmountGuard.php` (new)
- `app/Filament/Resources/ProjectCostReceipts/Schemas/ProjectCostReceiptForm.php`
- `app/Filament/Resources/ProjectCostReceipts/Pages/CreateProjectCostReceipt.php`
- `app/Filament/Resources/ProjectCostReceipts/Pages/EditProjectCostReceipt.php`
- `app/Filament/Resources/ExecutionPayments/Schemas/ExecutionPaymentForm.php`
- `app/Filament/Resources/ExecutionPayments/Pages/CreateExecutionPayment.php`
- `app/Filament/Resources/ExecutionPayments/Pages/EditExecutionPayment.php`
- `app/Filament/Resources/GeneralExpenses/Schemas/GeneralExpenseForm.php`
- `app/Filament/Resources/GeneralExpenses/Pages/CreateGeneralExpense.php`
- `app/Filament/Resources/GeneralExpenses/Pages/EditGeneralExpense.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Schemas/ProjectCostBudgetsPaymentForm.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/CreateProjectCostBudgetsPayment.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/EditProjectCostBudgetsPayment.php`
- `app/Filament/Resources/GeneralExchanges/Schemas/GeneralExchangeForm.php`
- `app/Filament/Resources/GeneralExchanges/Pages/CreateGeneralExchange.php`
- `app/Filament/Resources/GeneralExchanges/Pages/EditGeneralExchange.php`
- `tests/Unit/Services/Validation/FinancialAmountGuardTest.php` (new, 19 tests)
- `tests/Feature/GeneralExpenses/GeneralExpenseAmountValidationTest.php` (new, 3 tests)
- `tests/Feature/GeneralExchanges/GeneralExchangeAmountValidationTest.php` (new, 4 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set)

### Verification
New targeted tests: 33/33 passing (74 assertions) — `FinancialAmountGuardTest` (19: zero/negative/valid amount, fx_rate, individual and combined percentages, derived amounts) + `GeneralExpenseAmountValidationTest` (3: zero/negative amount rejected before any DB write, valid amount accepted — representative simple workflow) + `GeneralExchangeAmountValidationTest` (4: combined percentages = 100 rejected, zero/negative fx_rate rejected, valid values accepted — representative deduction/FX workflow, all rejections confirmed zero-mutation via before/after `Transaction`/`TransactionLine`/model counts and unchanged account balances) + existing `ExecutionPaymentCreditAccountTest` (7, unaffected). Full `php artisan test`: 104/105 (the 1 failure, `ExampleTest`, is pre-existing/unrelated — hits `/`, a route this Filament app never defines).

### Commit Hash
Not committed — awaiting explicit approval.

---

### Date
2026-07-18

### Task
Implement the approved server-side financial account validation change, following the same-day read-only audit of the 4 workflows that relied only on Filament Select filtering for account validity (project cost receipts, project cost budget disbursements, general expenses, general exchanges — execution payments already had `ExecutionPaymentForm::validateCreditAccount()`). Add a reusable `FinancialAccountGuard`, wire it into all 8 Create/Edit page handlers before any mutation, extend the execution-payment validator with the same active-account behavior for consistency, and add targeted tests.

### Result
New `App\Services\Validation\FinancialAccountGuard`: `assertAccountMatches()` (existence/not-trashed, account_type_id, bank_type_id, currency, optional `$requireActive`), batch `assertAccounts(array $specs): array` (verified `Account` models keyed by role), and `requireActiveOnChange(?int $original, $submitted): bool`. Called in all 8 Create/Edit handlers before `DB::transaction()` opens. Edit pages now fetch the record's pre-mutation saved lines *before* the guard call (moved out of the transaction closure) specifically to compute, per account role, whether the submitted account_id differs from the historically-saved one — `require_active` is `true` only when it does, so an untouched historical account may stay inactive while a newly-selected replacement must be active; every account is required active on Create. Every `Account::find($data['xxx_account_id'])?->increment/decrement(...)` balance-update call was replaced with the guard-verified `Account` instance (no `?->` needed). Approved rule confirmed and preserved: the same account may be used on multiple roles (debit=credit, source=destination, etc.) — no distinct-account check exists or was added. `ExecutionPaymentForm::validateCreditAccount()` gained two new optional named parameters (`$requireActiveCredit`/`$requireActiveBeneficiary`, default `true`) purely additively — its existing 4 checks and messages are untouched; `CreateExecutionPayment.php` needed no change (defaults match Create semantics), `EditExecutionPayment.php` now computes the same old-line-based flags. No migration, no accounting-formula change, no historical data touched.

### Changed Files
- `app/Services/Validation/FinancialAccountGuard.php` (new)
- `app/Filament/Resources/ProjectCostReceipts/Pages/CreateProjectCostReceipt.php`
- `app/Filament/Resources/ProjectCostReceipts/Pages/EditProjectCostReceipt.php`
- `app/Filament/Resources/GeneralExpenses/Pages/CreateGeneralExpense.php`
- `app/Filament/Resources/GeneralExpenses/Pages/EditGeneralExpense.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/CreateProjectCostBudgetsPayment.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/EditProjectCostBudgetsPayment.php`
- `app/Filament/Resources/GeneralExchanges/Pages/CreateGeneralExchange.php`
- `app/Filament/Resources/GeneralExchanges/Pages/EditGeneralExchange.php`
- `app/Filament/Resources/ExecutionPayments/Schemas/ExecutionPaymentForm.php`
- `app/Filament/Resources/ExecutionPayments/Pages/EditExecutionPayment.php`
- `tests/Unit/Services/Validation/FinancialAccountGuardTest.php` (new, 15 tests)
- `tests/Feature/GeneralExpenses/GeneralExpenseAccountValidationTest.php` (new, 9 tests)
- `tests/Feature/GeneralExchanges/GeneralExchangeAccountValidationTest.php` (new, 7 tests)
- `tests/Feature/ProjectCostReceipts/ProjectCostReceiptAccountValidationTest.php` (new, 6 tests)
- `tests/Feature/ProjectCostBudgetsPayments/ProjectCostBudgetsPaymentAccountValidationTest.php` (new, 6 tests)
- `tests/Feature/ExecutionPayments/ExecutionPaymentCreditAccountTest.php` (extended, +4 tests)
- `tests/Feature/GeneralExpenses/GeneralExpenseAmountValidationTest.php` (fixture fix: added account_type_id/bank_type_id to baseData, now required by the new guard)
- `tests/Feature/GeneralExchanges/GeneralExchangeAmountValidationTest.php` (same fixture fix)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set)

### Verification
New/updated targeted tests: 60/60 passing — `FinancialAccountGuardTest` (15, unit-level: existence/soft-delete/type/bank/currency/active mismatches, batch validation, same-account-both-sides, `requireActiveOnChange`), `GeneralExpenseAccountValidationTest` (9), `GeneralExchangeAccountValidationTest` (7, dual-currency destination + same-account-all-4-roles), `ProjectCostReceiptAccountValidationTest` (6), `ProjectCostBudgetsPaymentAccountValidationTest` (6), plus 4 new tests added to `ExecutionPaymentCreditAccountTest` (now 11 total) covering the active-account behavior. Full `php artisan test`: 150/151 (the 1 failure, `ExampleTest`, is pre-existing/unrelated — hits `/`, a route this Filament app never defines).

### Commit Hash
Not committed — awaiting explicit approval.

---

### Date
2026-07-16

### Task
Implement the approved responsive UI layout standard for credit/debit account sections across the OMS financial forms: desktop/wide screens show the creditor section on the right and the debtor section on the left, side by side with equal widths; narrow/mobile screens stack them vertically with the creditor section above the debtor section. Apply to General Expenses (primary page, currently debit-before-credit and visually unbalanced), Project Cost Receipts, and Execution Payments (preserving the recently-implemented editable credit-account cascade exactly). Assess the two multi-account forms (disbursement, general exchange) and only restructure them if it can be done without a risky rewrite.

### Result
Wrapped the credit and debit `Section`s of `GeneralExpenseForm.php` and `ProjectCostReceiptForm.php` in a shared `Grid::make(['default' => 1, 'lg' => 2])`, reordering the credit section to appear first in source order (both previously had debit first, stacked as two separate full-width cards). `ExecutionPaymentForm.php` already had the credit section first from the same-day credit-account-editable task, so only the `Grid` wrap was added. On desktop (≥lg breakpoint) this produces two equal-width columns; because the app renders `dir="rtl"`, native CSS Grid RTL auto-placement puts the first schema child (credit) on the right and the second (debit) on the left with no custom CSS. On narrow screens (<lg) the grid collapses to one column, preserving credit-above-debit source order. `ProjectCostBudgetsPaymentForm.php` (صرف مبلغ المشروع) and `GeneralExchangeForm.php` (التحويلات العامة) were left unchanged — see Decisions Log. No field names, options queries, live/afterStateUpdated callbacks, dehydration flags, validation, Create/Edit handlers, models, or migrations were touched in any file.

### Changed Files
- `app/Filament/Resources/GeneralExpenses/Schemas/GeneralExpenseForm.php`
- `app/Filament/Resources/ProjectCostReceipts/Schemas/ProjectCostReceiptForm.php`
- `app/Filament/Resources/ExecutionPayments/Schemas/ExecutionPaymentForm.php`
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
`php -l` clean on all 3 changed files. `php artisan view:cache` compiled all Blade templates with no errors, then `php artisan view:clear`. Targeted regression: `ExecutionPaymentCreditAccountTest` + `TransactionDescriptionBuilderTest` + `TransactionLineDescriptionBuilderTest` — 40/40 passing, confirming the credit-account cascade, defaults, validation, and description generation are all unaffected by the layout change. Full `php artisan test`: 78/79 (the 1 failure, `ExampleTest`, is the same pre-existing/unrelated failure as before this task — hits `/`, a route this Filament app never defines). `php artisan optimize:clear` ran clean. `graphify update .` ran clean (AST-only). No migration was run.

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-16

### Task
Rearrange the General Exchange form (`التحويلات العامة`, `/admin/general-exchanges/create`/`{record}/edit`) layout only: top area with "تفاصيل المعاملة" (right) and "النسب والمبالغ" (left) side by side on desktop / stacked (تفاصيل المعاملة first) on mobile; full-width "الحسابات" section underneath, internally unchanged; "المرفقات" last. No accounting logic, calculations, validation, or field behavior changes.

### Result
In `GeneralExchangeForm.php`: wrapped "تفاصيل المعاملة" and "النسب والمبالغ" in a new top-level `Grid::make(['default' => 1, 'lg' => 2])`, with "تفاصيل المعاملة" placed first in source order (renders on the right on desktop via native RTL CSS Grid auto-placement, since the app already renders `dir="rtl"`; stacks first/above on narrow screens). Moved "الحسابات" (full width, 4-account block: مصدر دائن، نسبة إدارية مدين، تحويل مدين، وجهة مدين) to sit underneath that row — its 16 fields, options queries, `afterStateUpdated`/`live()` callbacks, and `dehydrated()` flags are byte-identical to before, just relocated as a whole block. "المرفقات" remains last, unchanged. Old order was النسب والمبالغ → الحسابات → تفاصيل المعاملة → المرفقات (with الحسابات sitting between/beside the two transaction sections); new order is [تفاصيل المعاملة | النسب والمبالغ] → الحسابات → المرفقات. Only the top-level component tree changed; every field name, calculation, validation rule, currency/account filter, and helper method (`calculate()`, `deriveAmounts()`, `clearAmounts()`, `currencyName()`, `accountOptions()`) is untouched. `CreateGeneralExchange.php`/`EditGeneralExchange.php` were not modified.

### Changed Files
- `app/Filament/Resources/GeneralExchanges/Schemas/GeneralExchangeForm.php`
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
`php -l` clean. `php artisan view:cache` compiled all Blade templates with no errors, then `php artisan view:clear`. No dedicated `GeneralExchangeForm` test exists, so ran the closest relevant existing coverage: `CleanOperationalDataCommandTest` (exercises General Exchange rows), `BackfillTransactionDescriptionsCommandTest`, `TransactionDescriptionBuilderTest`, `TransactionLineDescriptionBuilderTest` — 70/70 passing. `php artisan optimize:clear` ran clean. `graphify update .` ran clean (AST-only). No migration was run.

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-15 (execution)

### Task
Execute the previously approved operational-data cleanup apply against the real dev database, per explicit user approval.

### Result
Precondition re-check: git clean (only prior implementation work unstaged), all 61 migrations `Ran`, dry-run counts matched the approved report exactly. The specified backup file did not exist yet — created it via `mysqldump --routines --triggers --single-transaction` (credentials passed only via `MYSQL_PWD` env var, never printed) to `storage/app/backups/oms_before_operational_cleanup.sql` (120,266 bytes), verified non-empty, then proceeded per the user's explicit instruction.

Ran `php artisan oms:clean-operational-data --apply --confirmation=DELETE-OMS-OPERATIONAL-DATA --backup-file="C:\laragon\www\oms\storage\app\backups\oms_before_operational_cleanup.sql"` at 12:21:16. Deleted (all now 0 active+trashed): 2 projects, 3 projects_costs, 1 project_cost_budget, 1 project_cost_budgets_payment, 3 project_cost_receipts, 2 general_expenses, 1 general_exchange, 12 transactions, 28 transaction_lines, 8 attachments, 1 project_financial_snapshot, 2 project_financial_snapshot_currency_totals, 0 project_financial_alerts. Reset all 15 accounts' `current_balance` to 0.00 (was 11 non-zero). Deleted exactly 6 physical attachment files (1,474,023 bytes), 0 failures; left the 6 pre-existing orphan files (706,133 bytes) untouched; `receipts/`/`general-expenses/` directories now empty but not removed; `public/storage` symlink untouched. Post-apply verification (service-internal fingerprint diff): **PASSED**. Independently re-verified all 21 checklist items via tinker + filesystem inspection: donors=2, accounts=15 (definitions unchanged), non-zero balances=0, users=2, roles=5, permissions=24, currencies=3, exchange_rate_histories=0 (unchanged), all reference tables unchanged, migrations=61, `bank_accounts`=0/untouched — all matched exactly.

Re-ran the identical `--apply` command a second time at 12:22:27 to verify idempotence: 0 additional files deleted, all counts identical, verification PASSED again. Confirmed via filesystem: still exactly 6 orphan files totaling 706,133 bytes, byte-identical to the first run.

Ran `php artisan optimize:clear` and `graphify update .` afterward (no further app-code changes).

### Changed Files
- Database only (`oms` MySQL database — not git-tracked): 12 operational tables emptied, 15 account balances reset to 0, 8 attachment rows removed.
- Filesystem: 6 attachment files deleted under `storage/app/public/{receipts,general-expenses,payments,execution-payments}/`.
- New: `storage/app/backups/oms_before_operational_cleanup.sql` (backup, git-ignored, not committed).
- `graphify-out/**` (regenerated).
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`, `OMS_Master_Reference.md` (this entry set).

### Verification
21-point manual checklist (donors, accounts, balances, users/roles/permissions, currencies, exchange-rate history, reference tables, migrations, bank_accounts, operational-table zero counts, orphan-file preservation) all confirmed independently, in addition to the command's own built-in post-apply verification (PASSED on both runs). Second apply run confirmed full idempotence (zero additional changes).

### Commit Hash
Not committed — no commit was requested for this data-only operation.

---

### Date
2026-07-05

### Task
Audit + implement Phase 1 of "ميزان المراجعة" (Trial Balance) report: read-only Filament page showing per-account debit/credit totals and balance status for a selected currency and date range.

### Result
Implemented and browser-verified. Page appears under "التقارير" (after "تقرير كشف الحساب"), scoped to a single required currency to avoid blending `debit_base`/`credit_base` across currencies (see Important Notes in AI_PROJECT_MEMORY.md). No opening/closing balance, no exports — period movement only, per approved Phase 1 scope.

### Changed Files
- `app/Services/Reports/TrialBalanceReportService.php` (new)
- `app/Filament/Pages/TrialBalancePage.php` (new)
- `resources/views/filament/pages/trial-balance-page.blade.php` (new)

### Verification
- `php -l` on both PHP files: no syntax errors.
- `php artisan route:list` confirms `GET admin/trial-balance` registered.
- Tinker cross-checks against real data: service totals matched a manual `DB::table` aggregate exactly (USD: debit=245,400.00, credit=390,000.00); confirmed soft-deleted transactions/lines are excluded (totals changed materially when the guard was removed in an isolated check); confirmed `include_zero_accounts=false` correctly drops zero-movement accounts; confirmed an account_type filter with no matching accounts returns a clean empty result.
- Browser (Playwright, logged in as the seeded dev superadmin): page loads with correct defaults (currency defaults to the `is_base` currency, dates default to start-of-month/today, toggle ON); pre-submit empty state renders; changing any filter reverts to the empty state; submitting renders summary cards and table matching the tinker-verified numbers; switching currency to ILS shows a fully disjoint account set (no cross-currency bleed); no console or `storage/logs/laravel.log` errors during the session.
- `php artisan optimize:clear` run after implementation, per task instructions. No migrations run.

### Commit Hash
(not committed yet)

---

### Date
2026-07-05

### Task
Phase 2 of "ميزان المراجعة" (Trial Balance): add "تصدير Excel" and "تصدير Word" export buttons that export exactly the applied/displayed report result.

### Result
Implemented and verified (tinker + browser). Both `phpoffice/phpspreadsheet` and `phpoffice/phpword` were already installed (confirmed in `composer.json` before writing any code). Exports read only the `applied*`/summary/`rows` properties snapshotted in `TrialBalancePage::showReport()` — never live filter state — so changing a filter after "عرض" without re-submitting blocks export with a warning notification, exactly as required. No change to any debit/credit/balance calculation.

### Changed Files
- `app/Services/Reports/TrialBalanceReportService.php` (additive only: `currency_code` and `account_type_label` added to the returned array for export labels/filenames; no calculation changed)
- `app/Filament/Pages/TrialBalancePage.php` (added `getHeaderActions()`, `exportExcel()`, `exportWord()`, `canExport()`, two new applied-snapshot properties)
- `app/Services/Reports/TrialBalanceExcelExportService.php` (new)
- `app/Services/Reports/TrialBalanceWordExportService.php` (new)

### Verification
- `php -l` on all four files: no syntax errors.
- Tinker: generated the Excel/Word exports directly from `TrialBalanceReportService::generate()` output (USD, wide date range) and re-opened them with PhpSpreadsheet/ZipArchive readers — confirmed real `.xlsx` (sheet title "ميزان المراجعة", RTL true, `getFreezePane()` null, autofilter range present) and real `.docx` (valid zip with `word/document.xml`, contains the title text and `bidi` RTL markup). Repeated with an account-type filter that matches zero accounts — both exports still generate cleanly with the "لا توجد بيانات ضمن الفترة المحددة" message and correct zeroed summary.
- Browser (Playwright, seeded dev superadmin): both header buttons render; clicking either before pressing "عرض" shows the exact warning notification text and blocks export; pressing "عرض" then changing a filter without resubmitting re-blocks export with the same warning; submitting properly and downloading both files succeeds with filenames `trial-balance-USD-2020-01-01-2030-12-31.xlsx`/`.docx`; no console errors. One unrelated Windows view-cache race (`rename ... Access is denied`) appeared in `storage/logs/laravel.log` on Filament's Dashboard sidebar component — confirmed unrelated to any Trial Balance file (grep for "TrialBalance" in the log found nothing) and self-resolved on the next request.
- `php artisan optimize:clear` run after implementation. No migrations run.

### Commit Hash
(not committed yet)


---

### Date
2026-07-06

### Task
Phase 1 of "تقرير الحركات المالية الشامل" (Comprehensive Financial Transactions report): new read-only Filament report page listing every transaction line in a period, one row per line, with per-currency/per-category/per-type summaries. No exports in this phase.

### Result
Implemented and verified. New service + page + Blade view following the Trial Balance pattern exactly (hasSubmitted gate, applied-filter snapshot, clearResults on any filter change). Data source is one joined read-only query over `transaction_lines` → `transactions` → `accounts`, with left joins to `accounts_type`, `currencies`, `transactions_types`, `transaction_super_types`, `projects_costs`/`projects` (project via `transaction_lines.project_cost_id`), and `users` (creator via `transactions.created_by`). All summaries are grouped per currency — no blended multi-currency total exists anywhere. `accounts.current_balance` untouched; no accounting write logic touched.

### Changed Files
- `app/Services/Reports/ComprehensiveFinancialTransactionsReportService.php` (new)
- `app/Filament/Pages/ComprehensiveFinancialTransactionsPage.php` (new)
- `resources/views/filament/pages/comprehensive-financial-transactions-page.blade.php` (new)

### Verification
- `php -l` on both PHP files: no syntax errors.
- `php artisan route:list` confirms `GET admin/comprehensive-financial-transactions` registered.
- Tinker smoke test against real data (wide date range): 66 lines / 23 transactions / 2 currencies returned; currency summaries, category summaries (3 super types), and row shape all correct; rows sorted oldest→newest by transaction_time, then transaction id, then line id; lines without a project cost show "غير مرتبط بمشروع".
- Note: per-currency status can legitimately show "غير متوازن" when a transaction's debit and credit legs are in different currencies — this reflects the ledger, not a report defect.
- `php artisan optimize:clear` run after implementation. No migrations run.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Enhancement to "تقرير الحركات المالية الشامل" summary section: renamed "عدد القيود"→"عدد المعاملات" and "عدد الأسطر المحاسبية"→"عدد بنود القيود" in the general summary, retitled the category/type sections to "إحصائيات حسب تصنيف المعاملة" / "إحصائيات حسب نوع المعاملة", and switched their count column from line count to distinct transaction count ("عدد المعاملات").

### Result
Implemented and verified. `accumulateGrouped()` now also tracks distinct transaction IDs per category/type bucket and `finalizeGrouped()` exposes `transaction_count` per bucket (never line_count/2, never per-line double counting). Financial debit/credit totals remain line-based and grouped per currency — no blended multi-currency total. Currency summary, filters, detail table, and all accounting logic untouched. Read-only throughout; `accounts.current_balance` untouched.

### Changed Files
- `app/Services/Reports/ComprehensiveFinancialTransactionsReportService.php` (distinct transaction_count per category/type bucket)
- `resources/views/filament/pages/comprehensive-financial-transactions-page.blade.php` (labels, section titles, transaction_count column)

### Verification
- `php -l` clean; `php artisan optimize:clear` run. No migrations run.
- Tinker against real data: 68 lines / 24 distinct transactions; "صرف مبلغ مشروع" shows tx=9 from 34 lines (multi-line transactions counted once); per-bucket tx counts sum to 24 = overall count = independent `DB::table(...)->distinct()->count('t.id')` cross-check; per-currency totals unchanged from Phase 1 logic.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Phase 2 of "تقرير الحركات المالية الشامل": add "تصدير Excel" and "تصدير Word" header buttons that export exactly the applied/displayed report result.

### Result
Implemented and verified. Both `phpoffice/phpspreadsheet` (^5.8) and `phpoffice/phpword` (^1.4) were already installed (confirmed in composer.json before writing any code; Maatwebsite Excel not used). Exports read only the `applied*`/summary/`rows` properties snapshotted in `showReport()` — never live filter state — so changing a filter after "عرض" (which triggers `clearResults()`) blocks export with the warning "يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير" until "عرض" is pressed again. Both exports include: report info with applied filters (unset filters shown as "الكل"), general summary (عدد المعاملات distinct / عدد بنود القيود / عدد العملات الظاهرة), per-currency summary with متوازن/غير متوازن status, category and type statistics (one row per bucket + currency; distinct transaction count written once per bucket), and the full detail table in page order. No blended multi-currency total anywhere. No calculation changes; no accounting write logic touched.

### Changed Files
- `app/Services/Reports/ComprehensiveFinancialTransactionsExcelExportService.php` (new)
- `app/Services/Reports/ComprehensiveFinancialTransactionsWordExportService.php` (new)
- `app/Filament/Pages/ComprehensiveFinancialTransactionsPage.php` (added `getHeaderActions()`, `exportExcel()`, `exportWord()`, `canExport()` — no other changes)

### Verification
- `php -l` on all three files: no syntax errors. `php artisan optimize:clear` run; route still registered. No migrations run.
- Tinker round-trip on real data (68 lines / 24 tx) and on an empty 1990 range: both files regenerate and re-open cleanly. XLSX verified: sheet "الحركات المالية", `getRightToLeft()=true`, `getFreezePane()=NULL` (no frozen rows), autofilter `A46:L114` on the detail table, numeric cell J47 is a real double with `#,##0.00` format, correct Content-Type and sanitized filename `comprehensive-financial-transactions-{from}-{to}.xlsx`. DOCX verified: valid zip with `word/document.xml`, `bidi` RTL markup, Tahoma default, all six sections present, footer with OMS + export datetime + `{PAGE}/{NUMPAGES}` fields. Empty-result exports contain "لا توجد حركات مالية ضمن الفترة المحددة" plus title/filters/summaries.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Reset all operational/business data in the `oms` database so real data entry can start clean, while preserving settings, lookup tables, auth (users/roles/permissions), and Laravel system tables. User specified exact keep/purge lists and required: no `migrate:fresh`, no dropped tables, no code/migration changes, backup first, show keep/purge lists for approval before deleting, reset AUTO_INCREMENT, include soft-deleted rows, run `optimize:clear` and `reports:refresh-projects-financial` after.

### Result
Inspected all 41 tables in the database and cross-checked against the user's keep list (25 tables) — matched exactly, no unclear tables. Queried `information_schema.KEY_COLUMN_USAGE` and confirmed no kept table has a foreign key into any purge table (purge-table FKs only point to kept tables or self-reference within the purge set), so truncating all 16 purge tables together under `FOREIGN_KEY_CHECKS=0` is safe. Presented both lists with row counts to the user for approval before touching data. User approved and asked me to run the backup. Took a full `mysqldump` backup (DB has an empty local root password, so no credential was read/exposed), verified it non-empty and containing `INSERT INTO` statements for key tables, then truncated the 16 purge tables (TRUNCATE resets AUTO_INCREMENT and removes all rows including soft-deleted). Ran `php artisan optimize:clear` and `php artisan reports:refresh-projects-financial` (0 refreshed, as expected with 0 projects). Confirmed final row counts: all 16 purge tables at 0, all 25 keep tables unchanged.

### Changed Files
None (data-only change; no code, no migrations). New file: `storage/app/private/backups/oms_backup_2026-07-06.sql` (pre-purge backup, not committed to git).

### Verification
- Row counts before and after purge captured via `DB::table($t)->count()` for all 41 tables.
- FK audit via `information_schema.KEY_COLUMN_USAGE` confirmed safe purge order/grouping.
- Backup file confirmed non-empty (169 KB) with grep-verified `INSERT INTO` rows for `transactions`, `projects`, `accounts`.
- Post-purge: all 16 purge tables show 0 rows; all 25 keep tables show their original row counts (`settings`=5, `roles`=5, `permissions`=24, `users`=2, `currencies`=3, etc.).
- `php artisan optimize:clear` completed successfully; `php artisan reports:refresh-projects-financial` ran with 0 refreshed/skipped/removed (correct, since `projects` is now empty).

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Add optional opening balance support when creating a new account, as a balanced double-entry opening transaction (never a manual `current_balance` write), without touching any report formula, project report logic, or snapshot calculation.

### Result
Implemented after a read-only impact report and explicit user approval of 4 flagged decisions: (1) no account nature exists in the schema, so the target account is always debited (matches the system-wide debit-normal convention); (2) `debit_base`/`credit_base` = own-currency amount per the existing convention — the user-entered fx_rate is stored on the lines as metadata only, never multiplied in; (3) clearing accounts "أرصدة افتتاحية" get their own new AccountType of the same name, one clearing account per currency with code `OPB-{CUR}`; (4) transaction type "قيد افتتاحي" created under a new super type "قيود افتتاحية". Flow: `CreateAccount::handleRecordCreation` strips the 3 virtual form fields; if opening_balance ≤ 0 it creates the account normally; otherwise one `DB::transaction()` creates the account → resolves fiscal year from the opening date (fallback: active FY; ValidationException if none) → firstOrCreate/restore-if-trashed lookups → `OPB-YYYY-XXXX` number via the same withTrashed+lockForUpdate MAX-suffix generator used by the other financial pages → transaction with description "قيد افتتاحي للحساب: {name}" → 2 balanced lines with `project_cost_id = null` → `increment`/`decrement` balance updates. `current_balance` is now `disabled()->dehydrated(false)` in the form (display-only on create and edit); the opening fields are visible on create only.

### Changed Files
- `app/Filament/Resources/Accounts/Schemas/AccountForm.php` (current_balance display-only; new create-only "الرصيد الافتتاحي" section with opening_balance / opening_balance_date / opening_balance_fx_rate)
- `app/Filament/Resources/Accounts/Pages/CreateAccount.php` (handleRecordCreation + opening transaction/lines/clearing-account/numbering logic)

### Verification
- `php -l` clean on both files; `php artisan optimize:clear` run; `php artisan test` passes (only example tests exist).
- Tinker end-to-end inside an outer rolled-back transaction (DB left untouched, 0 accounts / 0 transactions after): no-opening-balance create makes 0 transactions; 1500 USD opening → account balance 1500.00, tx OPB-2026-0001 with type "قيد افتتاحي" under "قيود افتتاحية", 2 lines D=1500/C=1500 both `project_cost_id=NULL`, clearing account OPB-USD (type "أرصدة افتتاحية") balance −1500; second USD account → OPB-2026-0002 and the same clearing account reused (balance −3500); ILS account with fx_rate 3.6 → separate OPB-ILS clearing account, `credit_base=700` (own currency, fx stored as metadata only).
- TrialBalanceReportService: balanced=true for both USD and ILS with opening entries present.
- `reports:refresh-projects-financial` with opening entries present: 0 snapshots created/changed — confirmed the snapshot service reads only `project_cost_receipts`/`project_cost_budgets`/`project_cost_budgets_payments`, never `transaction_lines`, so opening entries cannot affect project reports.
- No report/snapshot/service file was modified.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Implement "تقرير الجهات المانحة" (Donor Financial Report): a read-only Filament report page showing one donor, its linked projects, and the full cost/disbursement/execution financial picture between the organization and that donor, plus Excel and Word exports. Followed with a full audit (no code changes) before docs/commit.

### Result
Implemented and audited. New service + page + Blade view + 2 export services, following the established Trial Balance / Comprehensive Financial Transactions pattern (hasSubmitted gate, applied-filter snapshot, `clearResults()` on any filter change, exports read the on-screen snapshot only). `donor_id` required (`partners.is_donor = true` only); projects scoped via `projects.donor_id`; optional date_from/date_to filter `projects.approval_date` only; project_status_id/project_super_id/project_id are project filters; `currency_id` is display-only (hides non-matching rows/groups, never excludes a project). Three currency grains never mixed: cost-side (planned/received/surplus from `projects_costs`/`project_cost_receipts`), disbursement-source (original/admin-deduction/transfer-deduction/after-deductions from `project_cost_budgets.source_currency_id` + `transaction_lines` tagged `ProjectCostBudget::LINE_ADMIN`/`LINE_TRANSFER`), and execution (final/execution-paid/remaining from `project_cost_budgets.disbursement_currency_id` + `project_cost_budgets_payments`). "Real" disbursements = `transaction_id IS NOT NULL`, matching `ProjectsReportPage`'s existing convention. No existing report, snapshot, observer, model, migration, or accounting write logic was modified.

### Changed Files
- `app/Filament/Pages/DonorFinancialReportPage.php` (new)
- `app/Services/Reports/DonorFinancialReportService.php` (new)
- `app/Services/Reports/DonorFinancialReportExcelExportService.php` (new)
- `app/Services/Reports/DonorFinancialReportWordExportService.php` (new)
- `resources/views/filament/pages/donor-financial-report-page.blade.php` (new)

### Verification
- Full code audit against the checklist: file scope (`git status`/`git diff --stat` confirmed only the 5 files above are new, nothing else modified), page registration (auto-discovered via `AdminPanelProvider`'s existing `discoverPages()`, nav group التقارير, label تقرير الجهات المانحة, no manual registration needed), filter behavior, query correctness (soft-deletes excluded at every join level; no N+1 — all aggregates bulk-fetched before the per-project loop; no fan-out double counting since each disbursement transaction is 1:1 with its `project_cost_budgets` row, traced through `CreateProjectCostBudgetsPayment`), admin/transfer deduction tagging (confirmed `ProjectCostBudget::LINE_ADMIN`/`LINE_TRANSFER` constants are the same ones written by the real disbursement-creation code, not the differently-worded `GeneralExchange` constants), currency separation, Blade RTL/dark-light/empty-states/sign-coloring, and both export services (verified pure renderers of the passed-in `$report` array, no recalculation).
- `php -l` on all 4 new PHP files: no syntax errors.
- `php artisan optimize:clear`: succeeded.
- `php artisan route:list --path=donor-financial-report`: confirmed `GET admin/donor-financial-report` registered.
- Tinker smoke test against real dev data (1 donor, 1 linked project, 2 `projects_costs` rows in USD/ILS, 0 receipts): `DonorFinancialReportService::generate()` ran without error; correctly returned unmerged per-currency cost rows (planned 20,000 USD / 40,000 ILS, received 0 in both) — no cross-currency blending.
- Dev DB currently has 0 `project_cost_budgets` and 0 `project_cost_budgets_payments` rows, so the disbursement-source, deductions, final-disbursement, execution-paid, and remaining-execution sections could only be verified by code review, not by live numeric comparison — full numeric validation is pending real disbursement data (see NEXT_STEPS.md).
- No migrations run.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Fix broken image icon for "صورة الإشعار" on the Project Cost Receipt view page (`/admin/project-cost-receipts/{id}`).

### Result
Diagnosed as environmental, not a code bug. Inspected `ProjectCostReceiptForm` (FileUpload field, disk `public`, directory `receipts`), `CreateProjectCostReceipt`/`EditProjectCostReceipt` (file-move-and-rename logic into `Attachment.file_path`), and `ViewProjectCostReceipt`'s infolist (`Storage::disk('public')->url($attachment->file_path)`) — all already correct per Filament/Laravel convention, so no application code was changed. Root cause: `public/storage` was a real, empty directory rather than a symlink, so `php artisan storage:link` had previously silently failed ("link already exists") and every attachment URL 404'd. Confirmed the directory was empty (`find -mindepth 1` → 0 entries), removed it, and re-ran `php artisan storage:link`, which created the correct symlink to `storage/app/public`. Verified via tinker against receipt #1's real attachment row: `Storage::disk('public')->url()` now returns a URL whose target file `Storage::disk('public')->exists()` confirms is present, and `readlink -f public/storage` now resolves to `storage/app/public`.

### Changed Files
- None (application code unchanged)
- Filesystem only: removed the stray empty `public/storage` directory and recreated it as a symlink via `php artisan storage:link` (user-confirmed before deletion)

### Verification
- `stat`/`readlink -f public/storage` before fix: real empty directory, not a symlink.
- `php artisan storage:link` before fix: `ERROR The [...\public\storage] link already exists.`
- `find public/storage -mindepth 1 | wc -l` → 0, confirming the directory held nothing before removal.
- After `rmdir` + `php artisan storage:link`: command succeeded ("link has been connected to [...storage/app/public]"); `readlink -f public/storage` now resolves to `storage/app/public`.
- Tinker: `ProjectCostReceipt::find(1)->attachments()->first()` → `file_path = receipts/receive_2_20260706_15000.jpeg`, `Storage::disk('public')->url()` → `http://localhost/storage/receipts/receive_2_20260706_15000.jpeg`, `Storage::disk('public')->exists()` → true.
- No migrations run, no accounting/transaction logic touched.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Remove the old "المبالغ المرصودة" Filament resource (`/admin/project-cost-budgets`) from the sidebar and routing, without deleting the `project_cost_budgets` table, the `ProjectCostBudget` model, or touching any accounting/disbursement/execution-payment logic.

### Result
Confirmed via `graphify query` + grep that `App\Filament\Resources\ProjectCostBudgets\ProjectCostBudgetResource` (auto-discovered by `AdminPanelProvider::discoverResources()`) was the only class registering `admin/project-cost-budgets` / "المبالغ المرصودة", and that every file under its namespace (`Pages/{List,Create,View,Edit}ProjectCostBudget`, `RelationManagers/PaymentsRelationManager`, `Schemas/ProjectCostBudgetForm`, `Schemas/ProjectCostBudgetInfolist`, `Tables/ProjectCostBudgetsTable`) was referenced only from within that same namespace — no other resource, page, service, or test depended on any of them. Traced the actual data flow and found this resource was a plain generic CRUD directly on `project_cost_budgets` (no transaction lines, no balance updates) — genuinely obsolete, since the real disbursement flow (`ProjectCostBudgetsPaymentResource` / "صرف مبلغ المشروع") already creates every real `project_cost_budgets` row itself inside a balanced `DB::transaction()`. Deleted the entire `app/Filament/Resources/ProjectCostBudgets/` directory (`git rm -r`), then ran `php artisan optimize:clear`, `php artisan filament:optimize`, `php artisan icons:cache`, and `graphify update .`.

### Changed Files
- `app/Filament/Resources/ProjectCostBudgets/ProjectCostBudgetResource.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Pages/ListProjectCostBudgets.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Pages/CreateProjectCostBudget.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Pages/ViewProjectCostBudget.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Pages/EditProjectCostBudget.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/RelationManagers/PaymentsRelationManager.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Schemas/ProjectCostBudgetForm.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Schemas/ProjectCostBudgetInfolist.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Tables/ProjectCostBudgetsTable.php` (deleted)

### Verification
- `php artisan route:list --path=project-cost-budgets` → only the 4 `project-cost-budgets-disbursements` routes remain (the different, untouched resource); no plain `project-cost-budgets` route.
- `php artisan route:list --path=project-cost-budgets-disbursements` → all 4 CRUD routes for "صرف مبلغ المشروع" (`ProjectCostBudgetsPaymentResource`) still registered.
- `php artisan route:list` filtered for `execution-payment` → all 4 CRUD routes for "صرف مبالغ التنفيذ" still registered.
- Tinker: `class_exists(App\Models\ProjectCostBudget::class)` → true; `Schema::hasTable('project_cost_budgets')` → true; `ProjectCostBudget::count()` unaffected (still reflects the pre-existing dev-data purge, 0 rows) — model, table, and data untouched.
- No migrations run, no accounting/transaction/receipt/disbursement/execution-payment logic touched. `ProjectCostBudgetsPaymentResource.php` was only read, never edited (its one comment mentioning `ProjectCostBudgetResource` is a data-provenance note, not a navigation reference, so left as-is per task scope).

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Remove the old "تقارير المشاريع" Filament page (`/admin/projects-report-page`) completely from navigation and routing, without touching the current general financial report page, project financial snapshot tables/services, or any accounting logic.

### Result
Confirmed via `graphify query` + grep that `App\Filament\Pages\ProjectsReportPage` (auto-discovered by `AdminPanelProvider::discoverPages()`, no custom route, no manual registration) was the only class registering that URL/label, had no dedicated DB table or migration (it only read `projects`/`projects_costs`/`project_cost_budgets` etc. read-only, correlated subqueries, no writes), and its Blade view (`resources/views/filament/pages/projects-report-page.blade.php`) was used exclusively by this page. No other code, test, or route referenced either file. Deleted both files (`git rm`), then ran `php artisan optimize:clear`, `php artisan filament:optimize`, `php artisan icons:cache`, and `graphify update .`.

### Changed Files
- `app/Filament/Pages/ProjectsReportPage.php` (deleted)
- `resources/views/filament/pages/projects-report-page.blade.php` (deleted)

### Verification
- `php artisan route:list --path=projects-report-page` → no matching routes (confirmed removed).
- `php artisan route:list --path=admin` filtered for report pages → `account-statement`, `comprehensive-financial-transactions`, `donor-financial-report`, `project-financial-details/{project}`, `projects-general-financial-page`, and `trial-balance` all still registered and unaffected.
- `php -l app/Providers/Filament/AdminPanelProvider.php` → no syntax errors (panel provider itself untouched; page removal relies on Filament's directory auto-discovery, so no edit was needed there).
- No migrations run, no DB tables/data touched, no accounting/transaction/receipt/budget/export logic touched.

### Commit Hash

---

### Date
2026-07-14

### Task
Add automatic Arabic `transactions.description` generation to all 6 financial write flows (receipts, project disbursement, execution payments, general expenses, general exchanges, opening balance), via one shared formatter service. Exact format: `دائن: {credit entries} | مدين: {debit entries} | ملخص العملية: {summary}.`, credit before debit, one line, English digits/thousands separators/2 decimals, currency from `transaction_lines.currency_id` (never `account.currency`).

### Result
Implemented and verified. New `app/Services/Transactions/TransactionDescriptionBuilder.php::buildAndSave()` reads a transaction's final active lines (eager-loaded `account`→`withTrashed()` + `currency`), groups credit before debit preserving line-id order, formats/normalizes, throws if either side is empty (rolls back the caller's existing `DB::transaction()`). Each of the 11 Create/Edit pages (opening balance has no edit flow) now builds its own short Arabic summary from final saved relations and calls the builder as the last step inside its existing transaction boundary, before the success notification; edit flows call it again after lines are reversed/rebuilt. `transactions.notes`, `transaction_lines.notes`/`LINE_*` tags, all balance-update/currency/accounting-formula code, and attachment behavior are untouched. No migration (`description` column pre-existed). No historical backfill (explicitly out of scope). Also fixed two unrelated pre-existing migration bugs discovered while adding `RefreshDatabase`-based tests (approved separately mid-task — see DECISIONS_LOG.md 2026-07-14 entries); a third MySQL-only migration incompatibility was found and deliberately left untouched.

### Changed Files
- `app/Services/Transactions/TransactionDescriptionBuilder.php` (new)
- `app/Filament/Resources/ProjectCostReceipts/Pages/CreateProjectCostReceipt.php`, `EditProjectCostReceipt.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/CreateProjectCostBudgetsPayment.php`, `EditProjectCostBudgetsPayment.php`
- `app/Filament/Resources/ExecutionPayments/Pages/CreateExecutionPayment.php`, `EditExecutionPayment.php`
- `app/Filament/Resources/GeneralExpenses/Pages/CreateGeneralExpense.php`, `EditGeneralExpense.php`
- `app/Filament/Resources/GeneralExchanges/Pages/CreateGeneralExchange.php`, `EditGeneralExchange.php`
- `app/Filament/Resources/Accounts/Pages/CreateAccount.php`
- `database/migrations/2026_06_09_130009_create_exchange_rate_history_table.php` (unrelated pre-existing bug fix)
- `database/migrations/2026_06_09_174625_rename_exchange_rate_history_to_exchange_rate_histories.php` (unrelated pre-existing bug fix)
- `database/migrations/2026_06_09_130012_create_accounts_table.php` (unrelated pre-existing bug fix)
- `tests/Unit/Services/TransactionDescriptionBuilderTest.php` (new, 15 tests)

### Verification
- `php -l` on all 14 changed/new PHP files: no syntax errors.
- `tests/Unit/Services/TransactionDescriptionBuilderTest.php`: 15/15 passing, 23 assertions — covers single/multiple credit and debit entries with `؛` separation, currency sourced from the line (not the account, including a deliberately mismatched-currency-account case), `حساب` prefix add/no-duplicate, English digits/thousands/2-decimal formatting, credit-before-debit-before-summary ordering, summary normalization (whitespace collapse, repeated-period collapse, exactly one trailing period), soft-deleted account still renders its name, soft-deleted lines excluded, zero-value lines excluded, missing-credit/missing-debit throws, `buildAndSave` persists to `transactions.description`. Runs against a minimal hand-migrated SQLite schema (not `RefreshDatabase` — see DECISIONS_LOG.md) with `PRAGMA foreign_keys = OFF`.
- Full existing suite (`php artisan test`): 16/17 passing; the 1 failure (`Tests\Feature\ExampleTest`) is the default Laravel welcome-page scaffold test hitting `/` (404, since `resources/views/welcome.blade.php` doesn't exist in this admin-only app) — confirmed pre-existing and unrelated to this change.
- Targeted tinker verification (real dev DB, wrapped in `DB::beginTransaction()`/`DB::rollBack()`, zero residue — transaction count 10 before and after): invoked each flow's real `handleRecordCreation`/`handleRecordUpdate` via reflection with real accounts/currencies/projects/partners. All 6 creates + 5 edits (all except opening balance, which has no edit flow) produced correctly formatted descriptions: credit first, debit second, summary last, correct account names/currency codes/posted amounts, multi-account `؛` separation and mixed-currency handling on the disbursement/exchange flows, correct old→new value swap on every edit (changed amount + changed account both reflected), and the "already starts with حساب" de-dup rule confirmed live against a deliberately `حساب`-prefixed test account name.
- `php artisan migrate:status` checked before and after both migration-file edits: all affected migrations still show their original `Ran` batch numbers (3/4/5/14/19) — confirms zero live DB operations, only the migration **files** changed.
- `php artisan optimize:clear` and `graphify update .` run after implementation.

### Commit Hash
(not committed yet)

---

### Date
2026-07-14

### Task
Close the remaining manual-edit bypass on the raw `TransactionResource` ("المعاملات المالية", `admin/transactions`): first made `description` read-only there, then — after a follow-up read-only audit found it also allowed bare/unbalanced transaction creation and header edits independent of the owning financial record — converted the whole resource into a strictly read-only audit resource (no create/edit/delete/restore/force-delete on transactions or their lines), with authorization-layer hardening, not just hidden buttons. Also applied `defaultSort('id', 'desc')` to both its list table and the lines relation table.

### Result
Implemented and verified. `TransactionResource::getPages()` now only registers `index`/`view`; `create`/`edit` routes no longer exist. `CreateTransaction.php`/`EditTransaction.php` deleted (confirmed unreferenced elsewhere). `TransactionResource` overrides all 8 `can*` authorization methods (`canCreate`, `canEdit`, `canDelete`, `canDeleteAny`, `canRestore`, `canRestoreAny`, `canForceDelete`, `canForceDeleteAny`) to return `false`. `ListTransactions`/`ViewTransaction` no longer have header actions. `TransactionsTable` keeps only `ViewAction` + `defaultSort('id','desc')` (dropped `EditAction`, the `BulkActionGroup`/`DeleteBulkAction`). `LinesRelationManager` keeps its read-only columns/search/sort + `defaultSort('id','desc')` but has zero header/record actions and the same 8 `can*` overrides (as `protected` instance methods, matching `InteractsWithRelationshipTable`'s signatures). `TransactionForm`'s `description` field stays `disabled()->dehydrated(false)` (from the prior same-day fix, unchanged). Navigation entry "المعاملات المالية" stays visible; `TrashedFilter` kept (read-only filter, not a mutation). The 6 legitimate financial flows, `TransactionDescriptionBuilder`, and all accounting/balance/currency logic were not touched.

### Changed Files
- `app/Filament/Resources/Transactions/TransactionResource.php` (pages reduced to index/view; 8 `can*` overrides added)
- `app/Filament/Resources/Transactions/Pages/ListTransactions.php` (removed `CreateAction` header action)
- `app/Filament/Resources/Transactions/Pages/ViewTransaction.php` (removed `EditAction` header action)
- `app/Filament/Resources/Transactions/Tables/TransactionsTable.php` (removed `EditAction`/bulk delete; added `defaultSort('id','desc')`)
- `app/Filament/Resources/Transactions/RelationManagers/LinesRelationManager.php` (removed all header/record mutation actions; added `defaultSort('id','desc')` + 8 `can*` overrides)
- `app/Filament/Resources/Transactions/Schemas/TransactionForm.php` (from the prior fix in this same task — `description` already `disabled()->dehydrated(false)`)
- `app/Filament/Resources/Transactions/Pages/CreateTransaction.php` (deleted, unreachable after route removal)
- `app/Filament/Resources/Transactions/Pages/EditTransaction.php` (deleted, unreachable after route removal)

### Verification
- `php -l` on all 5 changed/kept PHP files: no syntax errors.
- `php artisan route:list --path=transactions`: before → `index`, `create`, `view`, `edit` (4 routes); after → `index`, `view` only (2 routes). `admin/transactions/create` and `admin/transactions/{record}/edit` are no longer registered.
- Headless `Table` inspection via reflection (no full Livewire/HTTP mount needed): `TransactionsTable::configure()` → `getFlatActions()` returns exactly one `Filament\Actions\ViewAction`, `getDefaultSortColumn()`/`Direction()` → `id`/`desc`. `LinesRelationManager::table()` → `getFlatActions()` returns 0 actions, same `id`/`desc` default sort. `ListTransactions`/`ViewTransaction` → `getHeaderActions()` both return `[]`.
- Tinker: `TransactionResource::canCreate()`/`canEdit()`/`canDelete()`/`canDeleteAny()`/`canRestore()`/`canRestoreAny()`/`canForceDelete()`/`canForceDeleteAny()` all return `false`; `shouldRegisterNavigation()` → `true`; `getNavigationLabel()` → "المعاملات المالية" unchanged; `LinesRelationManager::canCreate()` → `false`.
- Re-ran the six-flow tinker verification (rolled back, zero residue, transaction count 10 before/after): byte-identical generated descriptions to the prior verification — confirms the 6 legitimate flows and `TransactionDescriptionBuilder` are completely unaffected by this resource-level change.
- Re-ran `tests/Unit/Services/TransactionDescriptionBuilderTest.php`: 15/15 still passing.
- `php artisan optimize:clear` and `graphify update .` run after implementation. `git diff --check` clean (only a benign CRLF-normalization notice from Git, not a real whitespace error).

### Commit Hash
(not committed yet)

---

### Date
2026-07-14

### Task
Add automatic per-line Arabic `transaction_lines.description` and structured machine-readable `transaction_lines.line_role` across all 6 financial write flows (the line-level counterpart of the same-day `transactions.description` feature), with one centralized role vocabulary, a shared text formatter, no changes to accounting logic/notes/LINE_* tags, no historical backfill — plus hardening the standalone raw `TransactionLineResource` into a read-only audit resource (a line-level integrity bypass surfaced by this task's audit).

### Result
Implemented and verified. New migration adds `description` (nullable text) + `line_role` (nullable string(50)) after `notes` — ran cleanly on the dev DB (single ALTER, no data touched). New `App\Enums\TransactionLineRole` (11 string-backed roles + `arabicLabel()`/`labelFor()`). New `App\Services\Transactions\TransactionLineDescriptionBuilder::buildAndSaveForTransaction()` builds `{مدين|دائن}: حساب {name} ({line currency}) — {posted amount} | الغرض: {purpose}.` per active line, ordered by id, eager-loading account (withTrashed) + currency, throwing inside the caller's DB::transaction() on missing/unknown role, missing purpose, both-sides-positive, negative side, or missing account/currency; zero-amount lines (0% deduction placeholders) are skipped with NULL description (user-approved). Shared trait `FormatsTransactionText` now backs both description builders; `TransactionDescriptionBuilder` output stayed byte-identical. All 6 create + 5 edit flows assign `line_role` explicitly at every line write and call the line builder just before the parent description builder; the receipt edit flow's in-place line updates also set roles (self-heal for pre-feature receipts). `TransactionLineResource` is now read-only: index/view routes only, Create/Edit page classes deleted (confirmed unreferenced), 8 `can*` → false, no mutation actions, `defaultSort('id','desc')`, new columns: line_role Arabic-label badge + searchable/limited description (+notes toggleable); the view form shows both new fields disabled. `LinesRelationManager` gained the same two read-only columns (mutation hardening untouched). Reports/exports/snapshots untouched.

### Changed Files
- `database/migrations/2026_07_14_120000_add_description_and_line_role_to_transaction_lines_table.php` (new)
- `app/Enums/TransactionLineRole.php` (new)
- `app/Services/Transactions/Support/FormatsTransactionText.php` (new trait)
- `app/Services/Transactions/TransactionLineDescriptionBuilder.php` (new)
- `app/Services/Transactions/TransactionDescriptionBuilder.php` (uses the trait; API/output unchanged)
- `app/Models/TransactionLine.php` (fillable += description, line_role)
- `app/Filament/Resources/ProjectCostReceipts/Pages/CreateProjectCostReceipt.php` + `EditProjectCostReceipt.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/CreateProjectCostBudgetsPayment.php` + `EditProjectCostBudgetsPayment.php`
- `app/Filament/Resources/ExecutionPayments/Pages/CreateExecutionPayment.php` + `EditExecutionPayment.php`
- `app/Filament/Resources/GeneralExpenses/Pages/CreateGeneralExpense.php` + `EditGeneralExpense.php`
- `app/Filament/Resources/GeneralExchanges/Pages/CreateGeneralExchange.php` + `EditGeneralExchange.php`
- `app/Filament/Resources/Accounts/Pages/CreateAccount.php`
- `app/Filament/Resources/TransactionLines/TransactionLineResource.php` (read-only hardening)
- `app/Filament/Resources/TransactionLines/Pages/ListTransactionLines.php` + `ViewTransactionLine.php` (header actions removed)
- `app/Filament/Resources/TransactionLines/Pages/CreateTransactionLine.php` + `EditTransactionLine.php` (deleted)
- `app/Filament/Resources/TransactionLines/Tables/TransactionLinesTable.php` (ViewAction only, defaultSort, new columns)
- `app/Filament/Resources/TransactionLines/Schemas/TransactionLineForm.php` (view-only line_role/description fields)
- `app/Filament/Resources/Transactions/RelationManagers/LinesRelationManager.php` (2 new read-only columns)
- `tests/Unit/Services/TransactionLineDescriptionBuilderTest.php` (new)

### Verification
- `php -l` on all changed PHP files: no syntax errors.
- `php artisan test tests/Unit/Services`: 33/33 passing (15 existing TransactionDescriptionBuilder exact-string tests — proves byte-identical parent output — + 18 new TransactionLineDescriptionBuilder tests covering both side formats, line-vs-account currency, prefix dedup, digits/separators/decimals, one-final-period + whitespace normalization, soft-deleted account label, soft-deleted line exclusion, zero-line skip, both-positive/negative/missing-role/unknown-role/missing-purpose throws, all 11 roles, notes+parent-description untouched, cross-currency disbursement and exchange).
- Rolled-back tinker verification against real dev-DB data (reflection-invoked real `handleRecordCreation`/`handleRecordUpdate`): all 6 create flows + all 5 edit flows produced correct roles and descriptions (verified side label, `حساب`-prefixed account name, line currency code, posted amount 2dp/thousands, approved purpose wording, one final period, one line); receipt edit self-heal from deliberately-NULLed roles confirmed; disbursement edit with 0% transfer confirmed the zero line keeps NULL description while the other 3 lines render; cross-currency disbursement (USD→ILS) and exchange (USD→EUR) show each line's own currency. Zero residue after rollback: transaction/line/account counts, all account balances, all line notes, and all existing transaction descriptions byte-identical before/after.
- `php artisan route:list --path=transaction-lines`: only `index` + `view` remain (create/edit routes gone).
- `php artisan optimize:clear` + `graphify update .` run after implementation.
- Known limitation (pre-existing, unchanged): full `RefreshDatabase` still blocked by the MySQL-only `2026_06_24_000005_backfill_denormalized_currency_and_amounts.php`; both unit suites use the schema-only SQLite pattern instead. The pre-existing `Tests\Feature\ExampleTest` welcome-page failure also remains (unrelated scaffold test).

### Commit Hash
(not committed yet)

---

### Date
2026-07-15

### Task
Backfill the existing classified transactions/transaction lines so historical data shows the same `description`/`line_role` metadata as the 2026-07-14 live feature, then surface the three approved fields (`وصف العملية المالية`, `دور سطر القيد`, `وصف سطر القيد`) in all detailed financial-movement report sections (Comprehensive Financial Transactions, Account Statement, Project Financial Details, Donor Financial Report) and standardize their labels on the transaction/transaction-line audit resources.

### Result
**Phase 1 (audit).** Confirmed via `TransactionResource`'s own docblock and code trace that every `Transaction` is created only by the 6 legitimate flows — no other write path exists — so classification only needed to identify *which* of the 6 flows produced a given transaction, never guess. Classification priority implemented exactly as specified: (1) a direct `transaction_id` FK on the flow's authoritative domain record (`ProjectCostReceipt`, `ProjectCostBudget`, `ProjectCostBudgetsPayment`, `GeneralExpense`, `GeneralExchange`); (2) `transactions_types.name = 'قيد افتتاحي'` for opening balance (the only flow with no domain record of its own — `Account` stores no `transaction_id`). `transaction_lines.notes` `LINE_*` tags are used only as supporting evidence to map a line to its role *after* the flow is already identified via (1)/(2) — never to identify the flow. Two-line flows (receipt, execution payment, general expense, opening balance) assign roles purely by debit/credit position (always exactly one non-zero debit + one non-zero credit line by construction); four-line flows (disbursement, general exchange) map by their existing `LINE_SOURCE`/`LINE_ADMIN`/`LINE_TRANSFER`/`LINE_DESTINATION` notes tags. Dev DB audit: 10 active transactions / 24 active lines, all 24 lines `line_role IS NULL`, 6 transactions `description IS NULL` (created before the description feature) and 4 opening-balance transactions already had a *non-canonical* older description format (`"قيد افتتاحي للحساب: {name}"` vs. the current `"تسجيل الرصيد الافتتاحي لحساب {name}"`) — correctly flagged for regeneration since it differs from what the live builder produces today. 0 unclassified transactions, 0 unclassified lines, 2 legitimate zero-amount lines (0% admin/transfer deduction placeholders on one disbursement/exchange pair).

**Phase 2 (backfill command).** New `php artisan transactions:backfill-descriptions` (`--dry-run` / `--apply`, mutually exclusive; usage shown and no writes if neither given; optional `--transaction-id=`/`--chunk=200`). New `App\Services\Transactions\Backfill\TransactionFlowClassifier` (classification + per-flow role/purpose/summary resolution, reusing the exact approved wording already live in the 6 Create pages) and `ClassifiedTransaction` DTO, both under a new `app/Services/Transactions/Backfill/` namespace — no changes to the 6 live flows themselves. Minimal additive refactor to `TransactionLineDescriptionBuilder`: extracted a new public `describeLine()` method (same validation/generation logic, callable without saving) so the backfill command reuses the exact same code path as the live flows instead of re-deriving it; `buildAndSaveForTransaction()`'s behavior is unchanged (verified by the existing 18 tests still passing byte-for-byte). The command classifies each active transaction (bulk-fetching all 5 domain tables + eager-loading lines per chunk — no N+1), assigns `line_role`, computes `transaction_lines.description` via `describeLine()` (zero-amount lines always resolve to `NULL`) and `transactions.description` via the existing `TransactionDescriptionBuilder::build()`, and only calls `->save()` (in `--apply` mode, each transaction inside its own `DB::transaction()`) when a value actually differs from what's stored — Eloquent's dirty-check means an already-canonical row issues no UPDATE at all, making the command naturally idempotent. Never touches soft-deleted rows (default Eloquent scopes), amounts, notes, balances, or creates/deletes any row.

**Execution.** `--dry-run`: 10/10 transactions classified (opening_balance=4, disbursement=1, execution_payment=1, general_expense=2, general_exchange=1, receipt=1), 0 unclassified, 0 failed, 10 transactions would update (including the 4 opening-balance ones with stale descriptions), 24 lines would update, 2 zero-amount lines correctly seen. Spot-checked generated text for 4 representative transactions (opening balance, disbursement, general exchange with its 2 zero-amount deduction lines, receipt) — all matched the approved wording exactly. `--apply` run 1: identical counts, all persisted. `--apply` run 2 (idempotence check): 0 transactions updated, 0 lines updated, 10/24 unchanged — proves idempotence. Integrity snapshot (captured before run 1, compared after run 2): transaction count, line count, account count, every `accounts.current_balance`, and every transactions/transaction_lines field listed in the task's "unchanged fields" list (id, transaction_number, transaction_time, fiscal_year_id, transaction_type_id, partner_id, reference, notes, deleted_at / id, transaction_id, account_id, currency_id, amount_currency, debit_base, credit_base, fx_rate, notes, deleted_at) were byte-identical before/after.

**Phase 3 (report display).** Added the three fields to the detailed movement sections of all four reports — additively, never replacing an existing field, never changing a calculation/total/balance:
- **Comprehensive Financial Transactions**: added `transaction_description`/`line_role_label`/`line_description` to the service's per-line row array (dash-normalized for NULL); screen table gained 3 new columns (line-clamped preview + tooltip for the two long-text fields, badge for role); Excel gained 3 new columns (M–O, wrapped/fixed-width, existing A–L unchanged); Word's previously flat 11-column detail table was restructured into one block per transaction (header line + وصف العملية المالية paragraph + an 8-column line-detail subtable including the 2 new fields) — the only structural (not just additive) change in this task, made because a 14-column flat table would not fit A4 portrait.
- **Account Statement**: existing `description` column (already `transactions.description`) relabeled "الوصف" → "وصف العملية المالية" (same data, no logic change); added `line_role_label`/`line_description` as 2 new columns/cells across the screen table, Excel (J–K), and Word.
- **Project Financial Details**: this report's grain is one row per domain record (receipt/budget/payment), not one row per line, so per the task's own grain-preservation rule the parent description was added as a same-grain column and each row's active transaction lines were attached as a nested collapsible `<details>` widget on screen (and a nested Word subtable) rather than exploding the report's row grain. `costs` (no transaction) and `deductions` (a computed breakdown of an already-shown budget) were left untouched. No Excel export exists for this report (only Word) — confirmed via `Glob`, so none was added.
- **Donor Financial Report**: the `movements` array (already a merged receipt+budget+payment timeline) gained `transaction_description` + a `lines` array per movement; screen table and Word got a per-movement expandable/nested line-detail widget; Excel (which is genuinely one-row-per-movement, not one-row-per-line) got 2 new columns — `وصف العملية المالية` and a wrapped multi-line "{role}: {description}" summary per accounting line, keeping the existing movement grain rather than exploding it into a new row-per-line worksheet. All existing donor totals, the collection percentage, and every deduction calculation were left untouched — confirmed unchanged by re-reading `buildCostSummary`/`buildDisbSourceSummary`/`buildDisbFinalSummary`, none of which were touched.
Trial Balance, all summary cards, dashboard totals, the Projects General Financial Report summary, percentage cards, and collection-rate calculations were explicitly not touched, per task scope.

**Audit resource labels.** Standardized on `TransactionForm` (`description` → "وصف العملية المالية"), `LinesRelationManager`, `TransactionLineForm`, and `TransactionLinesTable` (`line_role` → "دور سطر القيد", `description` → "وصف سطر القيد"). Both resources remain strictly read-only (confirmed via `route:list`: index/view only, no create/edit/delete routes) and default-sorted `id desc`, unchanged from the 2026-07-14 hardening.

### Changed Files
- `app/Console/Commands/BackfillTransactionDescriptions.php` (new)
- `app/Services/Transactions/Backfill/TransactionFlowClassifier.php` (new)
- `app/Services/Transactions/Backfill/ClassifiedTransaction.php` (new)
- `app/Services/Transactions/Backfill/TransactionClassificationException.php` (new)
- `app/Services/Transactions/TransactionLineDescriptionBuilder.php` (additive `describeLine()` extraction; `buildAndSaveForTransaction()` behavior unchanged)
- `tests/Feature/Commands/BackfillTransactionDescriptionsCommandTest.php` (new, 17 tests)
- `app/Services/Reports/ComprehensiveFinancialTransactionsReportService.php`, `ComprehensiveFinancialTransactionsExcelExportService.php`, `ComprehensiveFinancialTransactionsWordExportService.php`
- `resources/views/filament/pages/comprehensive-financial-transactions-page.blade.php`
- `app/Services/Reports/AccountStatementReportService.php`, `AccountStatementExcelExportService.php`, `AccountStatementWordExportService.php`
- `resources/views/filament/pages/account-statement-page.blade.php`
- `app/Filament/Pages/ProjectFinancialDetailsPage.php`, `app/Services/Reports/ProjectFinancialDetailsWordExportService.php`
- `resources/views/filament/pages/project-financial-details-page.blade.php`
- `app/Services/Reports/DonorFinancialReportService.php`, `DonorFinancialReportExcelExportService.php`, `DonorFinancialReportWordExportService.php`
- `resources/views/filament/pages/donor-financial-report-page.blade.php`
- `app/Filament/Resources/Transactions/Schemas/TransactionForm.php`, `app/Filament/Resources/Transactions/RelationManagers/LinesRelationManager.php`
- `app/Filament/Resources/TransactionLines/Schemas/TransactionLineForm.php`, `app/Filament/Resources/TransactionLines/Tables/TransactionLinesTable.php`

### Verification
- `php -l` on every changed/new PHP file: no syntax errors. `php artisan view:cache` (precompiles all Blade, including the 4 changed pages): succeeded with no errors, then `view:clear` to leave dev state as found.
- `php artisan test`: 52/52 relevant tests passing (17 new backfill tests + 33 existing description/role-builder tests + 2 pre-existing unrelated), plus the one pre-existing unrelated `ExampleTest` `/` 404 failure (confirmed via `git stash`/re-run to already fail identically with zero of this task's changes applied).
- Backfill dry-run/apply/re-apply/integrity-snapshot sequence against real dev data as described above under Result.
- Tinker smoke test generating one real sample export per updated report/format (Comprehensive Excel+Word, Account Statement Excel+Word, Project Financial Details Word, Donor Excel+Word — 7 files) directly from each report service's live output against real dev data; every file re-opened successfully (`PhpSpreadsheet::load()` for `.xlsx`, `ZipArchive::open()` + `word/document.xml` presence check + `PHPWord::load()` for `.docx`) and printed sample field values confirming `transaction_description`/`line_role_label`/`line_description` are populated correctly end-to-end.
- `php artisan route:list --path=transactions` / `--path=transaction-lines`: confirmed still only `index`/`view` routes on both resources (no regression to the 2026-07-14 read-only hardening).
- `php artisan optimize:clear` and `graphify update .` run after implementation. `git diff --check`: clean.

### Commit Hash
(not committed yet)

---

### Date
2026-07-18

### Task
Read-only discovery/design (Phase 1), followed by approved focused implementation, followed by this completion pass: build automated test coverage for the previously-implemented `App\Services\Validation\FinancialTransactionBalanceGuard` and its 10-file wiring, without changing the approved accounting implementation. Cover the guard directly with unit tests (malformed payloads only, never by weakening production code), add focused integration tests for all 5 financial workflows proving the guard is correctly wired (valid Create/Edit still succeed; any rejection happens before mutation; counts/balances unchanged; Edit rejection leaves old lines/balances untouched), add an explicit `EditProjectCostReceipt` regression (it updates existing lines in place rather than recreating them), run the full test suite in a specified order, and confirm by targeted search that no naive cross-currency `SUM()` check exists, every one of the 10 paths validates before `DB::transaction()`, every path inserts/updates the exact validated payload with no recalculation, no `<= 0.01` tolerance exists anywhere, and no accounting formula or delete/reversal logic was touched.

### Result
While writing the unit tests it became clear the guard (as it stood after the prior implementation pass) did not yet check several things the requested test matrix explicitly required: `amount_currency` positivity/match-to-active-side, `fx_rate` positivity (and `== 1` for single-currency lines), missing-required-key detection, missing/duplicate `line_role`, wrong role-direction (a line's actual debit/credit side vs. its role's expected side), and matching an authoritative `notes` tag. These are payload-structure/consistency rules, not accounting formulas — closing them is "completing the guard," not "changing the accounting implementation" — so `FinancialTransactionBalanceGuard.php` was extended. This extension needed **zero changes** to any of the 10 production Create/Edit files — confirmed by running their existing 47 tests before writing a single new test, and again after. Then added: 34 unit tests (`FinancialTransactionBalanceGuardTest`, hand-built line arrays via two small private factories, no database) covering every COMMON PAYLOAD / SINGLE-CURRENCY / MULTI-CURRENCY case in the request, including a same-currency example proving role-direction is a genuinely independent check from the balance-sum check (numerically balanced but swapped roles); 22 integration tests (`BalanceGuardIntegrationTest`, one class per workflow, real reflection-invoked `handleRecordCreation`/`handleRecordUpdate`/`mutateFormDataBeforeFill` against the same selectively-migrated SQLite schema pattern as the existing `*AccountValidationTest`/`ExecutionPaymentCreditAccountTest` files) proving valid Create/Edit still complete end-to-end with the guard now in the pipeline, and that rejection (triggered via the earliest-reachable upstream guard — a zero amount for the 3 single-currency workflows, combined percentages of 100% for the 2 multi-currency workflows, since the balance guard's own math is provably unreachable from valid user input by design — it re-verifies what the same already-validated inputs just produced, as a defense against a future code regression, not against bad input) leaves transaction/line counts and every account balance completely unchanged; plus 2 dedicated `EditProjectCostReceipt` regression tests proving the exact validated update payload lands on the same `TransactionLine` row IDs (not recreated) and that a rejected edit leaves both existing lines' `account_id`/`currency_id`/`amount_currency`/`debit_base`/`credit_base`/`line_role` completely untouched (no partial update). Ran the 6-step sequence requested (guard unit tests → new integration tests → existing amount-validation tests → existing account-validation tests → existing five-workflow tests → full suite) — see Verification below for exact counts. Targeted-search confirmation (Phase 4 of the request): grepped `app/` for any `debit_base`/`credit_base` sum-equality pattern outside the guard (none found — only two unrelated `=== 0.0 && === 0.0` null-line checks in description builders); grepped all 10 files and confirmed every `FinancialTransactionBalanceGuard::assert*` call's line number is lower than that file's `DB::transaction(` line number; grepped and confirmed all 10 files insert/update via the literal validated array (`TransactionLine::create($line + [...])` in 9 files, `?->update($lineUpdates[...])` in `EditProjectCostReceipt`) with no recomputation in between; grepped for `abs(` in the guard (zero matches) and for `<= 0.01` anywhere touched (only pre-existing, unrelated `FinancialAmountGuard` minimum-amount wording); grepped and confirmed the `round($original * $adminPct / 100, 2)` / `round($afterDeduct * $fxRate, 2)` formula lines are byte-identical to before, in all 4 locations; confirmed zero diff on any `*Table.php` delete/reversal method (`git diff --stat`/`git status` against those files: empty).

**Correction (same day, before commit):** the first version of the `fx_rate` fix above defaulted a *missing* `fx_rate` to `1.0` rather than requiring it, specifically because `EditProjectCostReceipt`'s in-place `->update()` payload never included that key. This was flagged as conflicting with the approved requirement that no financial value be silently assumed, and as a real risk: a historically-corrupted stored `fx_rate` (not actually `1`) would never be checked against its true stored value, only against the guard's assumed `1.0`. Fixed by restoring `fx_rate` to `FinancialTransactionBalanceGuard::REQUIRED_KEYS` (rejected outright if missing — in both `assertValidLinePayload()` and, independently, `assertBalancedSingleCurrencyLines()`, with no fallback anywhere left in the guard) and by adding `'fx_rate' => 1` explicitly to both the debit and credit arrays in `EditProjectCostReceipt::buildReceiptLineUpdates()` — the same explicit value every other line-write payload in the codebase already carried. Confirmed by grep that this was the *only* one of the 10 workflow files missing an explicit `fx_rate` in its validated payload. A side effect (now covered by a dedicated test): a valid receipt Edit now normalizes any historically-corrupted stored `fx_rate` back to `1`, since the update payload always writes it explicitly; a rejected Edit still leaves the old line — corrupted `fx_rate` included — completely untouched, since validation happens before any mutation. Added 2 more guard unit tests (missing-`fx_rate` rejection, in both `assertValidLinePayload()` and `assertBalancedSingleCurrencyLines()`) and 1 more `ProjectCostReceipts` integration test (corrupt-then-reject-then-valid-normalizes); extended two existing integration tests to also assert on `fx_rate`. No accounting formula, balance-update formula, migration, or delete/reversal logic changed by this correction — same as the pass it corrects. Committed together with this documentation update.

### Changed Files
- `app/Services/Validation/FinancialTransactionBalanceGuard.php` (extended — required-keys including `fx_rate`, `amount_currency`/`fx_rate` value checks with no fallback, role presence/uniqueness/direction, optional notes-tag matching; no change to the balance equations themselves)
- `app/Filament/Resources/ProjectCostReceipts/Pages/EditProjectCostReceipt.php` (correction only: `'fx_rate' => 1` added explicitly to both update payload arrays)
- `tests/Unit/Services/Validation/FinancialTransactionBalanceGuardTest.php` (new, 36 tests)
- `tests/Feature/ProjectCostReceipts/BalanceGuardIntegrationTest.php` (new, 7 tests, incl. the update-in-place and fx_rate-normalization regression tests)
- `tests/Feature/ExecutionPayments/BalanceGuardIntegrationTest.php` (new, 4 tests)
- `tests/Feature/GeneralExpenses/BalanceGuardIntegrationTest.php` (new, 4 tests)
- `tests/Feature/ProjectCostBudgetsPayments/BalanceGuardIntegrationTest.php` (new, 4 tests)
- `tests/Feature/GeneralExchanges/BalanceGuardIntegrationTest.php` (new, 4 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set — also backfills documentation for the two prior undocumented passes: the read-only design/discovery and the initial guard implementation, both same-day and previously uncommitted)
- No other production workflow file (`app/Filament/Resources/*/Pages/Create*.php`/`Edit*.php`) was touched — the other 9 files carrying the original implementation's diff are unchanged since that pass.

### Verification
Exact command sequence and final results, in the requested order (after the correction above):
1. `php artisan test tests/Unit/Services/Validation/FinancialTransactionBalanceGuardTest.php` → **36/36 passed, 36 assertions**.
2. `php artisan test tests/Feature/ProjectCostReceipts/BalanceGuardIntegrationTest.php` → **7/7 passed, 48 assertions**.
3. `php artisan test tests/Feature/ProjectCostReceipts tests/Feature/ExecutionPayments tests/Feature/GeneralExpenses tests/Feature/ProjectCostBudgetsPayments tests/Feature/GeneralExchanges` (every test file for the 5 workflows, old and new together) → **70/70 passed, 285 assertions**.
4. `php artisan test` (full suite) → **209/210 passed, 554 assertions; 1 failure**: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (expects 200 on `/`, gets 404) — confirmed pre-existing and unrelated: the file is untouched by `git status`/`git diff`, and every prior task's log entry back to 2026-07-15 records this exact same single failure on this exact same test.
- `php -l` clean on the guard file, `EditProjectCostReceipt.php`, and all 6 test files.
- Targeted-search verification (Phase 4 of the original request, re-confirmed after the correction) all hold: no naive cross-currency sum, validate-before-transaction in all 10 files, exact-payload insert/update with no recomputation in all 10 files, zero `abs()`/`<= 0.01` tolerance in the guard, formula lines byte-identical, zero diff on any delete/reversal method. `fx_rate` is now confirmed explicit in every one of the 10 validated payloads (grep), with no default/fallback anywhere left in `FinancialTransactionBalanceGuard.php`.
- `php artisan optimize:clear` and `graphify update .` run after implementation and again after the correction.

### Commit Hash
`validate transaction balance with multi-currency support` — see `git log` for the hash.

---

### Date
2026-07-19

### Task
Implement OMS permissions foundation (Task 1 of the roles-and-permissions phase, following the 2026-07-19 read-only audit — see PROMPTS_LOG). Build the permission registry, `Gate::before` Super Admin bypass, an idempotent `oms:sync-permissions` command, and immediately restrict the currently wide-open `UserResource` to Super Admin only, as an emergency lockdown pending the full safe user-management phase (Task 3). Explicitly out of scope: RoleResource/PermissionResource, protecting the other 24 resources, Filament Shield, any migration, last-Super-Admin deletion logic.

### Result
Added `App\Support\Permissions\PermissionRegistry` — the single source of truth for all 154 permission names (`module.action` convention), their Arabic labels, module grouping, and `defaultPermissionsForRole()` for the 5 system roles, built strictly from the confirmed inventory (19 SoftDelete-CRUD modules + `users` = 20, 2 CRUD-without-SoftDelete modules, 3 read-only modules including `permissions`, `roles` module, 6 report pages × view/export, `users.assign_super_admin`). Added `App\Services\Permissions\PermissionSyncService` + `php artisan oms:sync-permissions`: creates missing permissions and the 5 system roles inside one `DB::transaction()`, reconciles each system role's permissions to the registry's defaults on every run, never deletes a permission, never touches any role outside the 5 system-role names, clears the Spatie permission cache at the end. Added a central `Gate::before` in `AppServiceProvider::boot()` — bypasses only for `hasRole('Super Admin')`, returns `null` (not `false`) otherwise so normal Spatie checks still run, never bypasses a guest, calls only `hasRole()` (no `can()`) to avoid recursion. Added `App\Policies\UserPolicy` — every ability hardcoded `false` (an explicit temporary lockdown, not a real permission check); Laravel auto-discovers it for `App\Models\User` via the standard `App\Models\X` → `App\Policies\XPolicy` convention, so `UserResource` and its 4 Pages needed **zero code changes** — confirmed by reading Filament's own `HasAuthorization`/`CanAuthorizeResourceAccess` source: `canViewAny()`/`canCreate()`/`canEdit()`/`canView()` all delegate to the policy once one exists, and every resource page's `mount()` already calls `abort_unless(canX(), 403)`. Refactored `DatabaseSeeder` to call `PermissionSyncService::sync()` instead of its old inline coarse `"{action} {module}"` grid + per-role `syncPermissions()` calls; the old coarse permissions (e.g. `"view finance"`) are left in the database untouched — confirmed via tinker against the real local DB after running the command (178 total permissions = 154 new + 24 old coarse ones still present, 0 deleted). `TransactionResource`/`TransactionLineResource`'s hardcoded `canCreate()/canEdit()/canDelete() = false` overrides never call `Gate`, so they are unaffected by the bypass — added a regression test proving this explicitly for a Super Admin.

A real environment issue was hit and resolved during test-writing (documented in AI_PROJECT_MEMORY.md): full HTTP round-trips via `$this->get('/admin/users')` don't preserve `actingAs()` in this environment (traced to `Filament\Http\Middleware\Authenticate` seeing the panel guard as unauthenticated on the fresh kernel dispatch, via `withoutExceptionHandling()`), and separately `route()`/`url()` bake the local `APP_URL` (`.../oms/public`) into generated URLs that the test client then mis-resolves. This is pre-existing and unrelated to this change — the entire prior 209-test suite never issues a real HTTP GET against a Filament panel route, for the same reason. `UserResourceLockdownTest` instead asserts directly against `UserResource::canViewAny()/canCreate()/canEdit()/canView()/canDelete()` — the exact same authorization decision Filament's `abort_unless(canX(), 403)` uses in production.

### Changed Files
- `app/Support/Permissions/PermissionRegistry.php` (new)
- `app/Services/Permissions/PermissionSyncService.php` (new)
- `app/Console/Commands/SyncPermissions.php` (new — `oms:sync-permissions`)
- `app/Policies/UserPolicy.php` (new — temporary Super-Admin-only lockdown)
- `app/Providers/AppServiceProvider.php` (added `Gate::before` Super Admin bypass in `boot()`)
- `database/seeders/DatabaseSeeder.php` (refactored: old coarse permission/role seeding replaced with `PermissionSyncService::sync()`; old permissions not deleted)
- `tests/Feature/Permissions/SuperAdminGateBypassTest.php` (new, 4 tests)
- `tests/Feature/Permissions/SyncPermissionsCommandTest.php` (new, 6 tests)
- `tests/Feature/Permissions/SystemRoleDefaultPermissionsTest.php` (new, 5 tests)
- `tests/Feature/Users/UserResourceLockdownTest.php` (new, 4 tests)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)
- No migration added. No existing Filament Resource/Page other than `UserResource`'s authorization (via the new Policy, not a file edit) was touched.

### Verification
1. New permission-foundation tests: `php artisan test --filter="SuperAdminGateBypassTest|SyncPermissionsCommandTest|SystemRoleDefaultPermissionsTest|UserResourceLockdownTest"` → **19/19 passed, 420 assertions**.
2. Full suite: `php artisan test` → **228/229 passed, 974 assertions; 1 failure**: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` — confirmed pre-existing/unrelated, the same single failure recorded in every prior task's log entry back to 2026-07-15.
3. `php artisan oms:sync-permissions` run against the real local database (after tests passed): 1st run — 154 permissions created, 0 found, 5 roles found (0 created — already present from an earlier partial seed), permissions assigned: Super Admin 154, Admin 140, Accountant 48, Project Manager 20, Viewer 46. 2nd run (idempotency check) — 0 permissions created / 154 found, 0 roles created / 5 found, identical per-role counts.
4. Post-sync tinker check against the real local DB: 178 total permissions (154 new + 24 pre-existing coarse ones, 0 deleted); 5 roles total; noted the local DB has no actual `superadmin@oms.com` user yet (the 5 roles pre-existed from an earlier partial seed run, but `DatabaseSeeder::run()` — which creates that user — has not been executed here) — informational only, out of this task's scope.
5. `php -l` clean on all 8 new/changed PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction — real admin email + completed real HTTP authorization tests)

### Task
Correction to the same-day permissions-foundation task, before commit. The prior pass's local-DB check for a seeded Super Admin user searched for `superadmin@oms.com` (the seeder's hardcoded email) and reported "no" — user corrected this: the actual administrator account is `oms@oms.com`, and the earlier negative result must not be treated as proof no Super Admin exists. Requested: (1) a read-only re-check of `oms@oms.com` (existence, not-soft-deleted, exact `Super Admin` role, `Gate::before` recognition, `UserResource` access) against the real local DB, with no writes and no new admin account; (2) inspect and report `DatabaseSeeder`'s current admin-seeding behavior without changing it unless clearly required (and report first if so); (3) a useful `oms:sync-permissions` warning when zero users hold the Super Admin role, without ever creating a user from that command; (4) completing the previously-punted real HTTP-level 403/200 tests for `UserResource` (the prior pass had substituted direct `canX()` assertions after hitting an environment quirk) — not staged, not committed.

### Result
Read-only tinker check against the real local DB (zero writes: only `User::withTrashed()->where(...)->first()`, `hasRole()`, `Gate::allows()`, `UserResource::canViewAny()/canCreate()`, `Auth::setUser()`/`forgetUser()` — the latter two are in-process only, no DB/session write) confirmed all 4 requested facts: `oms@oms.com` exists and is not soft-deleted; it holds the exact role `Super Admin` (and only that role); `Gate::before` recognizes it (an arbitrary, unregistered permission string was allowed — proof the bypass, not a coincidental real permission, is what granted it); `UserResource::canViewAny()`/`canCreate()` both return true for it. `DatabaseSeeder::seedSuperAdmin()` was inspected and found unchanged from before this task's first pass (only the `assignRole()` argument was ever a constant-reference change, same value) — it hardcodes `User::firstOrCreate(['email' => 'superadmin@oms.com'], ...)`, a **different** email than the real `oms@oms.com` admin. This is a pre-existing mismatch, not introduced by this task: running the seeder as-is today would create a second, independent Super Admin account rather than recognizing `oms@oms.com`. Per instructions, this was **not changed** — reported only (see the new DECISIONS_LOG entry for the proposed fix, pending approval).

Found the real root cause of the earlier HTTP-testing environment quirk (previously worked around, not fixed): `Filament\Http\Middleware\Authenticate::authenticate()` has a hardcoded rule, independent of any role/permission — if the user model doesn't implement `Filament\Models\Contracts\FilamentUser`, the panel is only reachable when `config('app.env') === 'local'` (vendor source: `abort_if($user instanceof FilamentUser ? ... : (config('app.env') !== 'local'), 403)`). `App\Models\User` doesn't implement that interface, and PHPUnit runs with `APP_ENV=testing`, so *every* request — including an authenticated Super Admin — was 403ing before Gate/Policy ever ran; this had nothing to do with sessions or `actingAs()`. Forcing `config(['app.env' => 'local'])` in the test's `setUp()` (test-only, zero production files touched) reaches the actual authorization layer. Rewrote `UserResourceLockdownTest` to issue real `$this->get('/admin/users')` /`/create`/`/{id}`/`/{id}/edit` requests and assert `assertForbidden()`/`assertOk()` directly, replacing the prior `canX()`-only assertions. Added the requested sync-command warning: `PermissionSyncService::sync()` now also returns a read-only `super_admin_user_count` (via `Role::where('name', 'Super Admin')->first()?->users()->count()`, no write), and `oms:sync-permissions` prints an Arabic warning when it's 0 — the command still never creates or assigns a user.

### Changed Files
- `app/Services/Permissions/PermissionSyncService.php` (added read-only `super_admin_user_count` to `sync()`'s return; no new writes)
- `app/Console/Commands/SyncPermissions.php` (prints a warning when `super_admin_user_count === 0`)
- `tests/Feature/Users/UserResourceLockdownTest.php` (rewritten: real HTTP `$this->get()` + `assertForbidden()`/`assertOk()` instead of direct `canX()` assertions, with the `app.env`/`URL::forceRootUrl` fix documented in its class docblock)
- `tests/Feature/Permissions/SyncPermissionsCommandTest.php` (2 new tests: warns when 0 Super Admin users and creates none; no warning once one exists)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md` (this entry set)
- `database/seeders/DatabaseSeeder.php` — **not changed** in this correction (inspected and reported only, per instructions)
- No migration added. No administrator credentials added, changed, or exposed. No write to the real local database.

### Verification
1. Read-only tinker check against the real local DB (see Result above) — all 4 requested facts confirmed, zero writes.
2. Permission-foundation + UserResource suite: `php artisan test --filter="SuperAdminGateBypassTest|SyncPermissionsCommandTest|SystemRoleDefaultPermissionsTest|UserResourceLockdownTest"` → **21/21 passed, 416 assertions** (19 from the first pass + 2 new warning tests), including the 3 now-real-HTTP `UserResourceLockdownTest` cases (non-Super-Admin 403 on list/create/view/edit; Super Admin 200 on all 4).
3. Full suite: `php artisan test` → **230/231 passed, 970 assertions; 1 failure** — the same pre-existing/unrelated `ExampleTest` failure recorded in every prior task's log entry back to 2026-07-15.
4. Post-run tinker re-check against the real local DB: user count, `oms@oms.com`/`superadmin@oms.com` presence, permission count (178), and role count (5) all identical to before this correction — confirms no write occurred (all new HTTP tests run against the isolated `:memory:` SQLite test schema, never the real database).
5. `php -l` clean on all changed/new PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction 2 — FilamentUser panel access + duplicate-Super-Admin-safe seeder)

### Task
Two focused corrections to the same-day permissions-foundation work, before commit. (1) The prior correction's real HTTP tests required a test-only `config(['app.env' => 'local'])` because `App\Models\User` doesn't implement `Filament\Models\Contracts\FilamentUser` — flagged as masking a real production issue (Filament rejects every user in any non-`local` environment without that interface). Required implementing `FilamentUser::canAccessPanel()` on `User` (entry-only, no permission logic, no hardcoded emails/IDs/roles, equivalent to `! $this->trashed()`) and removing the test workaround, with the real HTTP tests still passing under normal `APP_ENV=testing`. (2) `DatabaseSeeder::seedSuperAdmin()` still targets `superadmin@oms.com` while the real admin is `oms@oms.com` — required the smallest safe fix: skip bootstrap-user creation entirely when any non-soft-deleted user already holds the `Super Admin` role, never modify an existing administrator, preserve clean-install behavior otherwise, and keep `oms:sync-permissions` creating zero users. No migrations, no redesign, no staging/commit.

### Result
`App\Models\User` now `implements Filament\Models\Contracts\FilamentUser` with `canAccessPanel(Panel $panel): bool { return ! $this->trashed(); }` — panel entry no longer depends on `config('app.env')` in any environment; all actual authorization (who can do what once inside) remains entirely with `Gate::before` + `App\Policies\UserPolicy` + future Resource/Page policies, unchanged. Removed `config(['app.env' => 'local'])` from `UserResourceLockdownTest::setUp()`; all 4 of its real-HTTP tests (403 for a normal user on list/create/view/edit, 200 for Super Admin) pass unmodified under the suite's normal `APP_ENV=testing`. `DatabaseSeeder::seedSuperAdmin()` now opens with `if (User::role(PermissionRegistry::SUPER_ADMIN)->exists()) { return; }` — `User::role()` is Spatie's query scope and inherits the model's default SoftDeletes scope, so a soft-deleted former Super Admin correctly does **not** block bootstrap creation, while any active one (regardless of email) does. When no Super Admin exists, behavior is byte-identical to before (same `firstOrCreate(['email' => 'superadmin@oms.com'], ...)`, same `Hash::make('password123')`, same `assignRole()`) — no new credentials introduced. Confirmed via a dedicated test that an existing `oms@oms.com` Super Admin is left completely untouched (email, name, password hash, `updated_at`, and role set all unchanged) after running the full seeder.

### Changed Files
- `app/Models/User.php` (implements `FilamentUser`, adds `canAccessPanel()`)
- `database/seeders/DatabaseSeeder.php` (`seedSuperAdmin()` now skips bootstrap creation if any non-soft-deleted Super Admin already exists)
- `tests/Feature/Users/UserResourceLockdownTest.php` (removed the `app.env` workaround; docblock rewritten to explain the real fix)
- `tests/Feature/Users/UserFilamentAccessTest.php` (new, 4 tests — implements-contract, panel access across 4 environments, soft-deleted rejection, confirms `app.env` stays `testing`)
- `tests/Feature/Permissions/DatabaseSeederSuperAdminTest.php` (new, 8 tests — empty-DB bootstrap, hashed password, idempotent re-run, existing-admin-blocks-bootstrap, existing-admin-untouched, soft-deleted-doesn't-block, sync-permissions warning behavior both ways)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md` (this entry set)
- No migration added. No real database user created, modified, or deleted. No credentials exposed.

### Verification
1. `UserResourceLockdownTest` alone → **4/4 passed, 10 assertions** (no `app.env` override, normal `APP_ENV=testing`).
2. `DatabaseSeederSuperAdminTest` alone → **8/8 passed, 20 assertions**.
3. `SyncPermissionsCommandTest` alone → unchanged, still passing (covered in the combined run below).
4. All permissions-related tests together (`SuperAdminGateBypassTest|SyncPermissionsCommandTest|SystemRoleDefaultPermissionsTest|UserResourceLockdownTest|UserFilamentAccessTest|DatabaseSeederSuperAdminTest`) → **33/33 passed, 444 assertions**.
5. Full suite: `php artisan test` → **242/243 passed, 998 assertions; 1 failure** — the same pre-existing/unrelated `ExampleTest` failure recorded in every prior task's log entry back to 2026-07-15.
6. Post-run tinker re-check against the real local DB: user count (2), `oms@oms.com` present with `Super Admin` role, `superadmin@oms.com` absent, 178 permissions, 5 roles — all identical to before this correction, confirming zero writes to the real database.
7. `grep -rn "app.env.*local" tests/` confirms the only remaining match is the explanatory docblock comment in `UserResourceLockdownTest.php`, not a config override.
8. `php -l` clean on all changed/new PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction 3 — no hardcoded bootstrap credential + soft-delete-safe seeding)

### Task
Final focused security correction to the same-day permissions-foundation work, before commit. Flagged: `DatabaseSeeder` still contained a hardcoded bootstrap administrator email/password (`superadmin@oms.com` / `password123`) — unacceptable in source control for a permissions/security task — plus an unhandled soft-delete edge case (`users.email` is unique; if the bootstrap email already existed as a soft-deleted user, `firstOrCreate()` would hit a duplicate-email constraint instead of recovering it). Required: a small config-backed bootstrap-admin setting (email/name/password, all from environment variables, no default password), reading env through config rather than directly; when an active Super Admin already exists, skip entirely; when none exists, require the config (fail with a clear `RuntimeException`, no password exposed, no partial user) and handle three cases via `withTrashed()` lookup by the configured email — no existing row (create), active existing row (promote only, no credential overwrite), soft-deleted existing row (restore + reset password, no duplicate row). 12 specific tests requested. No migrations, no redesign, no staging/commit.

### Result
Added `config/oms.php` with a single `bootstrap_admin` array (`email`/`name`/`password`, all `env()`-sourced, `password` has no default — `name` defaults to `'Super Admin'` since it isn't sensitive). `DatabaseSeeder::seedSuperAdmin()` rewritten: (1) unchanged early-exit guard when any active Super Admin already exists; (2) reads `config('oms.bootstrap_admin.*')`, throws `RuntimeException` with a message naming only the required env var names (never a value) if email or password is blank, before touching the database; (3) `User::withTrashed()->where('email', $email)->first()` to find any prior row under that email; (4) no row → `User::create()` + `Hash::make($password)` + `assignRole()`; (5) active row → `assignRole()` only, zero field writes (name/email/password/`updated_at` all untouched — confirmed by test); (6) soft-deleted row → `restore()` + reassign `password` (freshly hashed) + `save()` + `assignRole()`, never a second `INSERT`. Confirmed via `grep -rn "password123|superadmin@oms.com" app/ database/seeders/ config/` → zero matches. `.env.example` documents the three new optional `OMS_BOOTSTRAP_ADMIN_*` variables (all blank placeholders, no real value, matching the existing pattern for other optional/sensitive vars like `AWS_SECRET_ACCESS_KEY`). `DatabaseSeederSuperAdminTest` fully rewritten around a synthetic `bootstrap-admin@test.local` / `Correct-Horse-Battery-Staple-1` test fixture (13 tests, none touching the real database) covering every required case, including a dedicated test that the exception message never contains the configured password string.

### Changed Files
- `config/oms.php` (new — `bootstrap_admin.{email,name,password}`, all `env()`-sourced)
- `database/seeders/DatabaseSeeder.php` (`seedSuperAdmin()` rewritten: config-driven, `withTrashed()` lookup, restore-not-duplicate, `RuntimeException` on missing config)
- `.env.example` (added `OMS_BOOTSTRAP_ADMIN_EMAIL`/`_NAME`/`_PASSWORD`, all blank)
- `tests/Feature/Permissions/DatabaseSeederSuperAdminTest.php` (rewritten, 13 tests — was 8)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md` (this entry set)
- No migration added. `.env` (the real local environment file) was **not** read or modified — only `.env.example`, a template with no real values. No real database user created, modified, or deleted. No credential exposed anywhere (source, test output, or exception messages).

### Verification
1. `DatabaseSeederSuperAdminTest` alone → **13/13 passed, 42 assertions**.
2. `SyncPermissionsCommandTest` alone → **8/8 passed, 171 assertions**.
3. `UserResourceLockdownTest` alone → **4/4 passed, 10 assertions**.
4. All permissions-related tests together (`SuperAdminGateBypassTest|SyncPermissionsCommandTest|SystemRoleDefaultPermissionsTest|UserResourceLockdownTest|UserFilamentAccessTest|DatabaseSeederSuperAdminTest`) → **38/38 passed, 466 assertions**.
5. Full suite: `php artisan test` → **247/248 passed, 1020 assertions; 1 failure** — the same pre-existing/unrelated `ExampleTest` failure recorded in every prior task's log entry back to 2026-07-15.
6. `grep -rn "password123|superadmin@oms.com" app/ database/seeders/ config/` → no matches (confirms no hardcoded bootstrap credential remains in source).
7. Post-run tinker re-check against the real local DB: user count (2), `oms@oms.com` present with `Super Admin`, `superadmin@oms.com` absent, 178 permissions, 5 roles — all identical to before this correction, confirming zero writes to the real database.
8. `php -l` clean on all changed/new PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (OMS Permissions Task 2A — Filament Resource/RelationManager authorization)

### Task
Protect all existing Filament Resources and RelationManagers using the Task 1 permission foundation (`PermissionRegistry`, `PermissionSyncService`, `Gate::before` Super Admin bypass). Explicitly scoped to Resource/RelationManager CRUD authorization only — custom report-page/export authorization is deferred to Task 2B. Must not redesign Task 1, install Filament Shield, add migrations, or touch financial formulas/queries/posting logic/forms/table layouts. Must respect the confirmed correction from the Task 2 discovery audit: `ExecutionPaymentResource`'s model is `App\Models\ProjectCostBudgetsPayment` (permission prefix `execution_payments`) and `ProjectCostBudgetsPaymentResource`'s model is the *different* `App\Models\ProjectCostBudget` (permission prefix `project_cost_budgets_payments`) — the two do not share a table, so there is no row overlap, but the prefix-to-model mapping is intentionally non-obvious and had to be documented in code, not just in a report.

### Result
Added a reusable `App\Policies\Concerns\AuthorizesCrud` trait (viewAny/view/create/update/delete/deleteAny/restore/restoreAny hardcoded to a single `<module>.<operation>` permission check; forceDelete/forceDeleteAny hardcoded `false` unconditionally — force delete stays Super-Admin-only via the existing `Gate::before` bypass, never a normal permission) and 23 concrete Policy classes (one per protected model), each declaring only its `permissionModule()` string — Laravel's standard model→policy auto-discovery convention (already proven working for `UserPolicy` in Task 1) wires them in with zero explicit registration. `TransactionPolicy`/`TransactionLinePolicy` additionally override `mutable(): false`, so create/update/delete/restore stay hardcoded `false` regardless of any permission grant — mirroring, not replacing, `TransactionResource`/`TransactionLineResource`'s own pre-existing hardcoded `canX()` overrides (both layers now independently enforce the same read-only-audit rule). `ProjectCostBudgetPolicy`/`ProjectCostBudgetsPaymentPolicy` carry explicit docblocks warning that their permission prefixes are deliberately swapped relative to what their class names suggest, per the confirmed Task 2 discovery mapping. Soft-delete rule (built into the shared trait, applied uniformly): `view()`/`update()`/`delete()` deny a trashed record for ordinary (mutable) resources — `view()` stays permission-gated but trashed-blind for the 2 read-only audit resources, since viewing trashed audit history is the point; `restore()`/`restoreAny()` require `<module>.restore` AND only ever apply to an already-trashed record. No Resource or RelationManager source file needed any authorization code added — confirmed empirically (not assumed) that Filament resolves RelationManager `canCreate()`/`canEdit()`/`canDelete()`/`canView()` against the *related* model's own policy (read from `vendor/filament/filament/.../InteractsWithRelationshipTable.php::getAuthorizationResponse()`, then proven via `Livewire::test()` against all 5 existing RelationManagers), so protecting each model once via its Policy automatically protects both its standalone Resource and every RelationManager built on it — e.g. viewing a Project (`projects.view`) does not grant `project_costs.create` via `Projects/CostsRelationManager`, because that RelationManager's authorization checks against `ProjectCostPolicy`, not `ProjectPolicy`.

### Changed Files
- `app/Policies/Concerns/AuthorizesCrud.php` (new — shared CRUD/soft-delete/force-delete-lockout trait)
- `app/Policies/{Account,AccountType,Attachment,BankType,Currency,ExchangeRateHistory,FiscalYear,GeneralExchange,GeneralExpense,Partner,PartnerType,Project,ProjectCost,ProjectCostBudget,ProjectCostBudgetsPayment,ProjectCostReceipt,ProjectStatus,ProjectSuper,Setting,Transaction,TransactionLine,TransactionSuperType,TransactionType}Policy.php (23 new files — one per protected model; see the model→policy→permission-prefix map in this session's final report)
- `tests/Unit/Policies/PolicyDiscoveryTest.php` (new, 24 tests — proves `Gate::getPolicyFor()` resolves every one of the 23 models to its intended Policy class and `permissionModule()`, with dedicated emphasis on the two swapped-prefix models; no DB needed)
- `tests/Feature/Permissions/CrudPolicyBehaviorTest.php` (new, 161 assertions across a 23-module data provider — view_any/view/create/update/delete/restore each require their own permission and are never granted by another; soft-delete rules; force-delete never grantable; read-only modules stay immutable regardless of permission)
- `tests/Feature/Permissions/ResourceHttpAuthorizationTest.php` (new, 68 tests — real HTTP 403/200 on index/create across all 23 protected resources; navigation-eligibility source-level check with the one documented `ProjectCostResource` exception; Super Admin access-all)
- `tests/Feature/Permissions/ExecutionPaymentBudgetDisbursementScopeTest.php` (new, 5 tests — the `execution_payments`/`project_cost_budgets_payments` pair specifically: each permission grants only its own workflow via real HTTP; a real numeric-id collision across the two physically separate tables never cross-exposes; a budget-only id 404s via the execution-payments route; a soft-deleted execution payment is blocked on its normal Edit route)
- `tests/Feature/Permissions/RelationManagerAuthorizationTest.php` (new, 8 tests via `Livewire::test()` — proves all 4 CRUD-capable RelationManagers (Projects/Costs, ProjectCosts/Budgets, ProjectCosts/Receipts, Currencies/ExchangeRateHistory) authorize against their related model's own permission module, independent of the parent resource's permissions)
- No migration added. No Resource, RelationManager, Page, Form, Table, or financial service/query file was modified. No custom report-page or export authorization implemented (deferred to Task 2B, per instructions).

### Verification
1. `PolicyDiscoveryTest` alone → **24/24 passed, 49 assertions**.
2. `CrudPolicyBehaviorTest` alone → **161/161 passed, 435 assertions**.
3. `ResourceHttpAuthorizationTest` alone → **68/68 passed, 157 assertions, 2 skipped** (the 2 skips are the read-only Transaction/TransactionLine resources' nonexistent create route, by design).
4. `ExecutionPaymentBudgetDisbursementScopeTest` alone → **5/5 passed, 24 assertions**.
5. `RelationManagerAuthorizationTest` alone → **8/8 passed, 24 assertions**.
6. Entire `tests/Feature/Permissions` namespace together (new + all pre-existing: `SuperAdminGateBypassTest`, `SyncPermissionsCommandTest`, `SystemRoleDefaultPermissionsTest`, `DatabaseSeederSuperAdminTest`) → **273/275 passed, 1092 assertions, 2 skipped** (same 2 as above).
7. `tests/Feature/Users/UserResourceLockdownTest.php` alone (untouched by this task) → **4/4 passed, 10 assertions** — `UserResource`/`UserPolicy` lockdown confirmed unaffected.
8. Full suite: `php artisan test` → **513/516 passed, 1709 assertions; 1 failure** — the same pre-existing/unrelated `ExampleTest` failure (hits `/`, a route this Filament app never defines) recorded in every prior task's log entry back to 2026-07-15; 2 skipped (same as above). All 5 financial-workflow test namespaces (`ExecutionPayments`, `ProjectCostBudgetsPayments`, `GeneralExpenses`, `GeneralExchanges`, `ProjectCostReceipts`) passed unmodified within this run, confirming zero impact on financial create/edit/delete logic.
9. `git status --short` / `git diff --stat`: every change is a **new, untracked file** — zero modifications to any existing tracked file (confirms no Resource/RelationManager/financial source was touched).
10. `php -l` clean on all 29 new PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.
