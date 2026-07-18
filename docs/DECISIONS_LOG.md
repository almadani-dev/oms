# Decisions Log

## Decision Template

### Date

### Decision

### Reason

### Impact

---

### Date
2026-07-15

### Decision
For the operational-data cleanup tool, partner/donor classification uses only the authoritative `partners.is_donor` boolean. All non-donor partners are treated as a single `unknown_unclassified` bucket, preserved and reported — never a deletion candidate in this phase, even though the task description anticipated separate association/beneficiary/vendor categories.

### Reason
Auditing the actual schema (`partners`/`partners_types` migrations, `Partner`/`PartnerType` models, `PartnerForm`) found no field distinguishing an association/system entity, operational beneficiary, or operational vendor from any other non-donor partner — `partner_type_id` only points to free-text business categories (e.g. "جمعية"/"فرد"/"وزارة") that the task instructions explicitly forbid inferring semantics from. Currently both existing partners are donors, so this is moot today, but the code must not guess a classification the schema doesn't support.

### Impact
No partner row is ever deleted by this tool, in dry-run or apply. If beneficiary/vendor entity types are added to the schema later (e.g. a new `entity_category` field), the service's `buildEntityClassification()` method is the single place to update.

---

### Date
2026-07-15

### Decision
Bulk operational-table deletion in `OperationalDataCleanupService` uses `DB::table()->delete()` / `->whereIn(...)->delete()` (query builder) instead of Eloquent `forceDelete()`.

### Reason
Two independent benefits: (1) the query builder ignores the SoftDeletes global scope, so one statement removes both active and soft-deleted rows without a separate `withTrashed()` pass; (2) it never fires the `Project`/`ProjectCost`/`ProjectCostBudget`/`ProjectCostBudgetsPayment`/`ProjectCostReceipt` Eloquent observers, whose only job is flipping `project_financial_snapshots.is_dirty` — pointless churn here since those snapshot rows are deleted in the same operation. Per task instructions to avoid firing business observers unnecessarily during mass maintenance deletion, without changing the observers' behavior for normal application use.

### Impact
Deletion order must be fully explicit and FK-verified by hand (documented in the service's `DELETION_ORDER` constant and the dry-run report), since it can no longer rely on Eloquent relationship cascades or model events to keep data consistent mid-deletion.

---

### Date
2026-07-18

### Decision
Approved business rules for positive-amount validation, confirmed by the user before implementation: (1) every financial amount must be ≥ 0.01; (2) `fx_rate` must be > 0, rejected (not silently converted to 1) when submitted as 0 or negative — the existing `?: 1` fallback is only safe for a genuinely-missing key, not an explicit 0; (3) each of the administrative/transfer percentages must be in [0, 100], and their **combined** total must be strictly < 100 (exactly 100 is invalid) — because a 100% combined deduction makes `amount_after_deductions` and `final_amount` zero, which must never post; (4) execution-payment over-budget submission stays a non-blocking warning, unchanged by this task; (5) server-side account/currency/type revalidation for the 4 workflows other than execution payments is a separate, later task.

### Reason
The same-day read-only audit found these 5 workflows had `numeric()`/`required()` as their only Filament-level protection, with **zero** independent server-side check — a directly-submitted Livewire request (or a future UI regression) could post a zero, negative, or percentage-annihilated financial entry into a double-entry ledger. The combined-percentage-strictly-less-than-100 rule specifically closes the "0 remaining amount posted as valid" edge case the audit flagged.

### Impact
`App\Services\Validation\FinancialAmountGuard` is now the single authoritative place these 6 rules live; any future financial write flow (or a rewrite of an existing one) must call it before its `DB::transaction()`, not just rely on Filament's `minValue()`/`maxValue()`. Historical rows already in the database were not validated retroactively and are unaffected.

---

### Date
2026-07-18

### Decision
Approved business rules for server-side financial account validation, confirmed by the user before implementation: (1) the same account is explicitly allowed on both sides of an operation (debit=credit, source=destination, etc.) across all 5 financial workflows — no distinct-account check exists or will be added, anywhere; (2) every submitted account must exist, not be soft-deleted, and match the expected account type/bank type/currency — always, on both Create and Edit; (3) on **Create**, every selected account must additionally be `is_active = true`; (4) on **Edit**, an account unchanged from the record's originally-saved account_id may remain inactive (a historical record may reference an account that was active when created but has since been deactivated), but if the user replaces it with a different account, that new account must be active — accounts are never silently swapped or reactivated by this validation; (5) existing historical data (including any currently-inactive or otherwise "invalid" account already referenced by a saved record) must not be modified, repaired, restored, reactivated, or deleted by this task.

### Reason
The same-day read-only audit found the 4 non-execution-payment workflows had zero independent server-side account validation — `account_type_id`/`bank_type_id` submitted alongside an `account_id` are pure Livewire UI state, never persisted, so a directly-submitted request could pair a real `account_id` with a mismatched type/bank/currency that the rendered Select would never have offered. The audit also found every balance-update call used `Account::find($id)?->increment/decrement(...)`, silently skipping the balance update (while still writing the transaction line) whenever `$id` pointed at a missing or soft-deleted account — closed by using the already-guard-verified `Account` object instead. The Create-vs-Edit active-account split specifically avoids two failure modes: allowing brand-new operations against a deliberately-deactivated account (Create), and breaking the ability to edit an old, still-valid record for an unrelated reason (date, notes) just because the real world account it references was closed sometime after creation (Edit).

### Impact
`App\Services\Validation\FinancialAccountGuard` is now the single authoritative place these rules live; any future financial write flow must call `assertAccounts()` before its `DB::transaction()` and use the returned `Account` objects for balance updates, not a fresh `Account::find($data[...])?->`. `ExecutionPaymentForm::validateCreditAccount()` (previously the only such guard in the codebase, with no active-account check at all) now accepts the same active-account behavior via two additive optional parameters, so all 5 financial workflows are consistent on this rule going forward. No historical data was touched — any currently-inactive account already referenced by a saved record remains exactly as-is and stays editable as long as it isn't replaced.

---

### Date
2026-07-16

### Decision
Execution Payment's editable credit-account replacement is restricted to the **same currency** as the execution payment (the selected budget's disbursement/destination currency). No exchange-rate/fx fields were added. No new database column was added — the actually-selected credit account continues to be stored only via `transaction_lines.account_id` on the line tagged `notes = ProjectCostBudgetsPayment::LINE_CREDIT` / `line_role = TransactionLineRole::ExecutionSource`.

### Reason
The audit found that `CreateExecutionPayment`/`EditExecutionPayment` write both the beneficiary and credit lines with one shared `$currencyId` and `fx_rate = 1` — there is no per-line currency-conversion story in this flow (unlike the disbursement flow's source→destination fx conversion). Allowing a different-currency replacement account would require introducing a second currency, a real `fx_rate`, and a second `amount_currency` per line — a materially larger redesign explicitly out of scope for "make the credit account editable." It would also violate the project-wide "never mix currencies" rule and the account-currency filter already enforced on the beneficiary side. Since `transaction_lines.account_id` on the `LINE_CREDIT` line is already a normal, mutable FK column, no migration is needed to make the account itself editable — only the currency restriction stops it from becoming a currency-mixing risk.

### Impact
`ExecutionPaymentForm::validateCreditAccount()` hard-rejects (via `ValidationException`, not just via Select filtering) any submitted `credit_account_id` whose `currency_id` doesn't equal `ExecutionPaymentForm::budgetCurrencyId($budgetId)`. If the business later genuinely needs a cross-currency temporary credit account (per the "later transfer back" scenario in the original request), that requires a separate, explicitly-approved fx-handling redesign of this flow — not a follow-up to this task.

---

### Date
2026-07-16

### Decision
Fixed the pre-existing Execution Payment Edit drift bug as part of the credit-account editability task, rather than treating it as a separate follow-up: `EditExecutionPayment::mutateFormDataBeforeFill()` now hydrates the credit-account cascade from the payment's own saved `LINE_CREDIT` transaction line, and `handleRecordUpdate()` no longer recomputes the credit account from `ExecutionPaymentForm::budgetDestinationLine()` at all.

### Reason
The same-day audit found that both methods previously re-derived the credit account from the *budget's current* destination line on every Edit — so editing an execution payment for any unrelated reason (e.g. only the date) after the underlying budget's destination account had been changed elsewhere would silently move the historical credit line onto a different account, with the balance reversal/reapplication following it. This is exactly the historical-account-preservation requirement in the approved business requirement (#8), and leaving the bug in place while adding a user-facing "override the credit account" feature would have made the drift risk worse, not better — the form's own reactive default (on a genuine `project_cost_budget_id` change) already provides the correct "apply new default only on real budget change" behavior, so no separate mechanism was needed once hydration stopped reading from the budget.

### Impact
Saving an Edit without touching the credit account (or the budget) now always preserves the exact historically-used account, regardless of what happens to the budget afterwards. Covered by `test_edit_initial_hydration_loads_saved_credit_line_not_current_budget_destination` and `test_edit_date_only_after_budget_destination_changed_elsewhere_preserves_historical_credit_account` in the new test file.

---

### Date
2026-07-16

### Decision
Adopted a project-wide OMS financial-form UI convention for credit/debit account section layout: on desktop/wide screens (≥`lg`), the creditor account section renders on the right and the debtor account section on the left, side by side in equal-width columns; on narrow/mobile/tablet screens (<`lg`), they stack vertically with the creditor section above the debtor section. Implemented via a plain `Filament\Schemas\Components\Grid::make(['default' => 1, 'lg' => 2])` wrapping the two account `Section`s, with the credit section placed first in source order — relying on native CSS Grid right-to-left auto-placement (the app already renders `dir="rtl"`) rather than any custom CSS.

### Reason
`GeneralExpenseForm.php` and `ProjectCostReceiptForm.php` previously rendered the debit and credit account sections as two separate full-width `Section`s stacked vertically, in debit-then-credit order — visually unbalanced and inconsistent with `ExecutionPaymentForm.php`'s already-corrected (same-day) credit-first order. The task explicitly required a native-Filament, CSS-Grid-based responsive solution (no bespoke CSS) and RTL-correct right/left placement verified against real rendering behavior, not just source order. CSS Grid's auto-placement algorithm places the first grid item on the right when the container's computed `direction` is `rtl`, which this app's `dir="rtl"` root already provides — so simply grouping the two sections into one 2-column grid, credit first, satisfies the requirement with zero custom styling.

### Impact
Applied to `GeneralExpenseForm.php`, `ProjectCostReceiptForm.php`, and `ExecutionPaymentForm.php` (all three already have a clear, unambiguous single credit/debit pair). **Deliberately not applied** to `ProjectCostBudgetsPaymentForm.php` (صرف مبلغ المشروع) and `GeneralExchangeForm.php` (التحويلات العامة): both have one credit/source account plus three debit/deduction/destination accounts (12–16 form fields) inside a single unified `Section::make('الحسابات')->columns(2)`, where each account's own type/bank-type/currency/account fields already rely on that shared 2-column auto-flow layout. Splitting that block apart into a distinct "credit right, debits following" grid would require restructuring the internal column flow of a Section that several *other* fields' positions implicitly depend on — a nontrivial layout rewrite with real visual-regression risk for a cosmetic-only task. Both forms already satisfy the substantive rule (source/credit account first in reading order, therefore already above all debit sections on mobile) through their existing field order, so the risk of a rewrite was judged to outweigh the purely cosmetic benefit of an explicit right/left split. If a future task wants this, it should be scoped and tested on its own, not bundled into a general layout-standardization pass.

---

### Date
2026-07-15 (execution)

### Decision
When the user-specified backup file (`storage/app/backups/oms_before_operational_cleanup.sql`) turned out not to exist at apply time, stopped and asked rather than substituting the older unrelated 2026-07-06 backup or proceeding without one. Once the user explicitly chose "create a fresh mysqldump now," created it with `mysqldump --routines --triggers --single-transaction`, passing the password only via the `MYSQL_PWD` environment variable (never as a command-line argument or in any printed output).

### Reason
The task's own mandatory rule ("If the file is missing or empty, STOP") and the standing rule against proceeding around a failed safety precondition. An old, unrelated backup would not actually protect today's data, and printing/echoing DB credentials would violate the "do not expose credentials" requirement even during a self-directed remediation step.

### Impact
None going forward — the backup now exists at the exact path required, is verified non-empty (120,266 bytes), and the apply proceeded only after that verification passed.

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

---

### Date
2026-07-14

### Decision
`transaction_lines` gained two system-generated, additive-only metadata columns: `description` (one-line Arabic per-line explanation, exact format `{مدين|دائن}: حساب {name} ({line currency code}) — {posted amount} | الغرض: {purpose}.`) and `line_role` (stable English machine value from the new single-vocabulary string-backed enum `App\Enums\TransactionLineRole`, 11 roles, each with an Arabic UI label). Roles are assigned explicitly by the authoritative financial flow at line-creation time (and on the receipt edit flow's in-place line updates, enabling self-heal of pre-feature NULL roles) — never inferred from account name, debit/credit side, line order, description, or notes. Purposes are supplied per-flow (not per-role globally) because the same role legitimately carries different wording in different flows. Zero-amount lines (0% admin/transfer deduction placeholders) are skipped — `description` stays NULL — instead of throwing, matching how `transactions.description` already omits them (user-approved deviation from the strict draft spec after the audit showed throwing would roll back every 0%-deduction disbursement/exchange). `transaction_lines.notes` and all `LINE_*` constants remain completely untouched and stay the tags reports/edit flows match on; migrating readers to `line_role` requires a separately approved audit. No historical backfill; no DB enum; no index on `line_role` yet. The standalone `TransactionLineResource` (`admin/transaction-lines`) was also converted to a strictly read-only audit resource (routes reduced to index/view, page classes deleted, 8 `can*` → false) — it was a raw line-level CRUD bypass of double-entry integrity missed by the same-day `TransactionResource` hardening.

### Reason
The parent `transactions.description` explains the whole operation but not why each individual account moved; auditors reading a single ledger line (Account Statement, line tables) need the line's own business purpose. A machine-readable role assigned at creation time is the only reliable way to know a line's purpose without parsing Arabic text or notes tags — the audit confirmed receipts/expenses have no notes tag at all and opening-balance lines share one ambiguous tag, so inference was impossible anyway. Shared text formatting was extracted into the `FormatsTransactionText` trait (used by both builders) so transaction- and line-level descriptions can never drift apart; `TransactionDescriptionBuilder`'s output stayed byte-identical (its 15 exact-string tests prove it).

### Impact
All 6 create flows and all 5 edit flows now write `line_role` on every line and generate per-line descriptions inside their existing `DB::transaction()` (validation failure rolls back the whole financial operation — no partial metadata). Historical lines keep NULL in both columns; edited pre-feature receipts self-heal. New read-only columns (role badge with Arabic label, searchable description) appear on `admin/transaction-lines` and the transaction view's lines table; all reports/exports/snapshots deliberately unchanged in this phase. `admin/transaction-lines/create` and `.../{record}/edit` no longer exist as routes.

---

### Date
2026-07-15

### Decision
Historical (pre-2026-07-14) transactions and transaction lines belonging to a **deterministically classifiable** flow are now backfilled with canonical `description`/`line_role` values via `php artisan transactions:backfill-descriptions --apply`. A transaction is classified only by (1) a direct `transaction_id` foreign key on its flow's authoritative domain record, or (2) `transactions_types.name = 'قيد افتتاحي'` for opening balance (which has no domain record). `transaction_lines.notes` `LINE_*` tags are used only as supporting evidence to map a line to its role once the flow is already known this way — never to identify the flow itself, and never account name/type, description text, line order, or amount similarity. A transaction that cannot be classified this way is left completely untouched and reported as unclassified; none currently exist in the dev DB (0/10). The command is idempotent by construction (it only calls `->save()` when a computed value differs from what's stored) rather than via any separate diffing layer, reusing the exact same generation code (`TransactionDescriptionBuilder::build()` and a new public `TransactionLineDescriptionBuilder::describeLine()`) the 6 live flows already use, so a backfilled row is byte-identical to what a fresh create/edit of the same data would produce today.

### Reason
The prior 2026-07-14 decision explicitly deferred historical backfill because no approved, deterministic classification method existed yet for old rows. This task defines and implements that method. Reusing the live builders (rather than re-deriving formatting logic in the backfill command) guarantees the backfilled text can never drift from what the live flows generate, and guarantees idempotence for free via Eloquent's dirty-attribute check — no bespoke "already correct" comparison logic to get wrong. Reusing `TransactionLineDescriptionBuilder`'s existing zero-amount-skip behavior (rather than reimplementing it) automatically applies the same "0% admin/transfer deduction lines keep `description = NULL`" rule approved on 2026-07-14.

### Impact
All 10 active transactions / 24 active lines in the dev DB are now classified and backfilled (0 unclassified). `transactions:backfill-descriptions` is safe to re-run at any time (e.g. after future data imports) — a second run against already-canonical data updates 0 rows. Only `transactions.description`, `transaction_lines.line_role`, and `transaction_lines.description` are ever written; amounts, notes, `LINE_*` tags, balances, and record counts are provably unchanged (verified via a before/after snapshot of every listed field). If a future historical row genuinely cannot be classified (e.g. a manually-inserted transaction bypassing all 6 flows, which should not be possible given `TransactionResource`'s read-only hardening), it is skipped and reported by id/reason rather than guessed — a documented future review item, not a silent gap.

---

### Date
2026-07-15

### Decision
The three approved fields — `وصف العملية المالية` (`transactions.description`), `دور سطر القيد` (`transaction_lines.line_role`, resolved to its Arabic label), `وصف سطر القيد` (`transaction_lines.description`) — are added **only** to the detailed financial-movement sections of the four reports named in this task (Comprehensive Financial Transactions, Account Statement, Project Financial Details, Donor Financial Report), always alongside the report's existing fields, never replacing them. Where a report's existing grain is already one row per transaction line (Comprehensive, Account Statement), the fields are added as plain new columns/cells. Where a report's grain is one row per domain record or movement, not per line (Project Financial Details' receipts/budgets/payments; Donor Financial Report's movements), the parent description is added at that same grain and the movement's accounting lines are exposed via a nested/expandable widget (an HTML `<details>` block on screen, a nested nested table in Word, a wrapped multi-line summary column in Donor's Excel) rather than exploding the report into a new row-per-line grain. Trial Balance, all summary cards, dashboard totals, the Projects General Financial Report summary, percentage cards, and collection-rate calculations were not touched.

### Reason
The task explicitly required preserving each report's existing grain and calculations rather than assuming every report is (or should become) line-grained — Project Financial Details and the Donor Financial Report's movements section were already domain-record/movement-grained before this task, and forcing them to one-row-per-line would have doubled-to-quadrupled their row counts and changed what "one row" means to an existing reader, which is a bigger, riskier change than what was asked. The nested-widget approach shows every required field without that grain change. `transaction_lines.notes` is never used as the displayed line description (per explicit instruction) — the new `وصف سطر القيد` field always comes from `transaction_lines.description`, kept visually distinct from any pre-existing notes column.

### Impact
Every added field is display-only, sourced directly from the already-backfilled stored columns (never regenerated at render time, never parsed back into any calculation). NULL/unclassified values render as "—" everywhere, per the approved historical-display convention. `TransactionResource`'s `LinesRelationManager` reuse of `TransactionLineRole::labelFor()` is now duplicated (by necessity, since each report service is a separate, non-shared class per this codebase's existing convention) into `ComprehensiveFinancialTransactionsReportService`, `AccountStatementReportService`, `ProjectFinancialDetailsPage`, and `DonorFinancialReportService` — all via the same enum, so the Arabic label text can never drift between them. `DonorFinancialReportService`'s admin/transfer deduction **totals** still match on `transaction_lines.notes` `LINE_*` tags exactly as before (untouched) — the new `lines`/`line_role_label` arrays added to each movement are separate, purely-additive display data fetched by a new bulk query, not a replacement for that existing calculation logic.
