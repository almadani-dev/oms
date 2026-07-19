# Prompts Log

### Date
2026-07-18 (financial transaction balance guard — automated verification completion)

### Prompt
User requested completing the missing automated verification for the previously-implemented `FinancialTransactionBalanceGuard` (approved structure, not yet committed) without changing the approved accounting implementation: unit tests for the guard directly (an extensive named list of COMMON PAYLOAD / SINGLE-CURRENCY / MULTI-CURRENCY cases), focused integration tests for all 5 workflows (valid Create/Edit succeed; invalid payload rejected before mutation; counts/balances unchanged; Edit rejection leaves old lines/balances unchanged; an explicit `EditProjectCostReceipt` update-in-place regression), a specified 6-step test-run order with exact reported counts, targeted-search confirmation of 6 specific invariants (no naive cross-currency sum, validate-before-transaction in all 10 paths, exact-payload insert/update with no recalculation, no `<= 0.01` tolerance, no formula change, no delete/reversal change), documentation updates required by CLAUDE.md only, and an explicit "do not stage, do not commit, wait for approval" constraint.

### Purpose
Turn the previously-approved-but-unverified guard implementation into a fully test-backed, independently-confirmed change before it is ever committed — closing the gap between "the design is approved" and "the code actually does what was approved, provably, with tests that could not have been satisfied by weakening production code."

### Result
Writing the requested unit tests surfaced that the guard (as implemented in the prior pass) did not yet check several things the test matrix required — see `docs/DECISIONS_LOG.md` for why closing those gaps by extending the guard (not the workflow files) was the correct call. Added 34 unit tests + 22 integration tests (all passing), ran the 6-step sequence exactly as specified (34/34 → 22/22 → 26/26 → 53/53 → 69/69 → 206/207, the 1 full-suite failure being the same pre-existing unrelated `ExampleTest` seen in every prior task), and confirmed all 6 targeted-search invariants hold. See `docs/TASKS_LOG.md` (2026-07-18, this entry's task) for the exact commands and `docs/AI_PROJECT_MEMORY.md` for the full guard description (this pass also backfilled memory/task documentation for the two prior undocumented passes — the read-only discovery/design and the initial guard implementation — since neither had been logged yet). Not staged, not committed at this point — diff and full test results shown for approval first.

**Follow-up correction (same day, before commit):** the reviewer flagged that the guard let a *missing* `fx_rate` silently default to `1.0` — needed because `EditProjectCostReceipt`'s in-place update payload never set that key — as conflicting with the "no financial value silently assumed" requirement, and as capable of hiding a historically-corrupted stored `fx_rate`. Required correction: make `fx_rate` genuinely required everywhere with no fallback, and fix the one file with the actual gap (`EditProjectCostReceipt`) to explicitly write `fx_rate = 1`, plus tests proving the guard now rejects a missing `fx_rate`, proving a valid receipt Edit normalizes a corrupted stored `fx_rate` to `1`, and proving a rejected Edit still leaves it untouched. Implemented exactly as scoped — 2 more guard unit tests, 1 more integration test, both existing final counts re-verified (209/210, same 1 pre-existing unrelated failure) — approved, staged, and committed as `validate transaction balance with multi-currency support`. See `docs/DECISIONS_LOG.md` and `docs/TASKS_LOG.md` (both 2026-07-18 correction entries) for full detail.

---

### Date
2026-07-18 (server-side financial account validation)

### Prompt
User requested a focused read-only audit of server-side financial account validation across the 4 workflows that still relied mainly on Filament Select filtering (receipts, project cost budget payments, general expenses, general exchanges — execution payments already had a guard), asking for confirmed affected files, a per-field validation matrix, confirmed vulnerabilities with file/line references, business decisions needing confirmation, a minimal implementation plan, tests, and risk sizing — no changes yet. After reviewing the audit, user approved specific business rules (same account legally allowed on both sides of any operation, always; every account must exist/not-be-trashed/match type-bank-currency; Create requires every account active; Edit allows an unchanged historical account to stay inactive but requires a newly-selected replacement to be active; no historical data touched) and requested implementation: one reusable `FinancialAccountGuard` service with a focused `assertAccountMatches()` base method, wiring it into all 8 Create/Edit pages before any mutation, extending `ExecutionPaymentForm::validateCreditAccount()` only for active-account consistency, focused tests, and no staging/committing.

### Purpose
Close a real financial-integrity gap (a submitted account_id could bypass the rendered Select options entirely, and every balance-update call silently skipped on a missing/deleted account while still writing the transaction line) with the smallest safe, fully-tested change, in two phases (audit-then-approve) so the exact business rules — especially the Create-vs-Edit active-account split — were confirmed before any code changed.

### Result
Audit phase produced the affected-files/validation-matrix/vulnerabilities cited above (see `docs/TASKS_LOG.md` 2026-07-18 entry, not separately logged as its own task since no code changed in that phase). Implementation phase added `App\Services\Validation\FinancialAccountGuard` (`assertAccountMatches()`, batch `assertAccounts()`, `requireActiveOnChange()`), wired it into all 8 Create/Edit handlers before `DB::transaction()` (Edit pages restructured to fetch pre-mutation saved lines before the guard call, to compute per-role active requirements), replaced every `Account::find($data[...])?->increment/decrement()` with the guard-verified `Account` instance, and additively extended `ExecutionPaymentForm::validateCreditAccount()` with two optional active-account parameters. Added 60 new/targeted tests (150/151 full suite, 1 pre-existing unrelated failure), including a same-account-on-both-sides acceptance test per the approved rule. See `docs/TASKS_LOG.md`, `docs/AI_PROJECT_MEMORY.md`, and `docs/DECISIONS_LOG.md` (2026-07-18 entries) for full detail. Not committed, per instructions — diff shown for approval first.

---

### Date
2026-07-18 (positive-amount validation)

### Prompt
User requested a focused read-only audit of positive-amount validation across the 5 financial workflows (receipts, disbursements, execution payments, general expenses, general exchanges), asking for confirmed affected files, a per-field UI/server validation table, edge cases needing business decisions, a minimal implementation plan, tests, and risk sizing — no changes yet. After reviewing the plan, user approved specific business rules (amount ≥ 0.01; fx_rate > 0, no silent 0→1 fallback; percentages in [0,100] with combined < 100; derived amounts > 0; execution-payment over-budget behavior unchanged; other workflows' account/currency revalidation out of scope) and requested implementation: Filament form constraints, one reusable `FinancialAmountGuard` service, wiring it into all 10 Create/Edit pages before any mutation, removing the unsafe `fx_rate ?: 1` fallback in the two deduction/FX workflows only, focused tests, and a `git diff --stat` + summary before any commit (no commit without approval).

### Purpose
Close a real financial-integrity gap (zero/negative amounts, FX rates, and percentages could previously post into the double-entry ledger with no server-side check) with the smallest safe, fully-tested change, in two phases (audit-then-approve) so the exact business rules were confirmed before any code changed.

### Result
Audit phase produced the affected-files/validation table cited above (see `docs/TASKS_LOG.md` 2026-07-18 audit summary, not separately logged as a task since no code changed in that phase). Implementation phase added `App\Services\Validation\FinancialAmountGuard`, `minValue()`/`maxValue()` constraints on 5 Forms, guard calls in all 10 Create/Edit handlers before `DB::transaction()`, replaced the 4 unsafe `?: 1` fx_rate fallbacks on submitted data with `?? 1`, and added 33 new/targeted tests (104/105 full suite, 1 pre-existing unrelated failure). See `docs/TASKS_LOG.md`, `docs/AI_PROJECT_MEMORY.md`, and `docs/DECISIONS_LOG.md` (2026-07-18 entries) for full detail. Not committed, per instructions — diff shown for approval first.

---

## Prompt Template

### Date

### Prompt

### Purpose

### Result

---

### Date
2026-07-16 (General Exchange rearrangement)

### Prompt
User requested a UI-layout-only rearrangement of the General Exchange form (previously left unchanged by the general layout-standard task as a multi-account form): move "تفاصيل المعاملة" and "النسب والمبالغ" to a top row (تفاصيل المعاملة right, النسب والمبالغ left on desktop, تفاصيل المعاملة first on mobile), move the full "الحسابات" section underneath (full width, internal order/fields untouched), and keep "المرفقات" last. Explicit no-touch list (accounting logic, calculations, percentages, currencies, validation, live callbacks, account filtering, transaction lines, balances, descriptions, models, migrations, reports, exports) and explicit instruction not to modify `CreateGeneralExchange.php`/`EditGeneralExchange.php`.

### Purpose
Fix the General Exchange form's visual imbalance (large accounts section previously sitting beside/between the transaction sections) using the same RTL-aware native-Grid approach already applied to the other financial forms, without touching any of its multi-account calculation/deduction/fx logic.

### Result
Wrapped "تفاصيل المعاملة" (first, renders right) and "النسب والمبالغ" (second, renders left) in a `Grid::make(['default' => 1, 'lg' => 2])`; moved the unchanged "الحسابات" section underneath, full width; "المرفقات" stays last. Every field, callback, option query, and helper method in `GeneralExchangeForm.php` is byte-identical to before — only the top-level component tree was rearranged. 70 relevant existing tests pass; no dedicated form test exists for this page. See TASKS_LOG.md and AI_PROJECT_MEMORY.md (2026-07-16 General Exchange entries) for full detail. Not committed, per instructions.

---

### Date
2026-07-16 (UI layout standard)

### Prompt
User requested implementation of an approved responsive UI layout standard for credit/debit account sections across the OMS financial forms: desktop shows creditor right / debtor left side by side with equal width, mobile stacks creditor above debtor, using native Filament v5 responsive Grid columns (no custom CSS unless unavoidable) and verified against real RTL rendering rather than source order alone. Named General Expenses as the primary page to fix (currently debit-before-credit and visually unbalanced), plus Project Cost Receipts and Execution Payments (preserving the just-implemented editable credit-account cascade exactly), with an explicit multi-account-forms rule for the disbursement and general-exchange forms (source/credit first, don't force a two-column split, leave unchanged and report the reason if restructuring would be risky). Required the same mandatory pre-read/git-check/Graphify-first workflow, no commit/push, and a full verification + visual checklist.

### Purpose
Make the credit/debit account sections visually balanced and consistent across the financial forms without touching any accounting calculation, saved data, or field behavior.

### Result
Wrapped the credit and debit `Section`s of `GeneralExpenseForm.php` and `ProjectCostReceiptForm.php` in a shared `Grid::make(['default' => 1, 'lg' => 2])`, credit section reordered first (both were debit-first before); `ExecutionPaymentForm.php` only needed the `Grid` wrap since its credit section was already first. Multi-account forms (`ProjectCostBudgetsPaymentForm.php`, `GeneralExchangeForm.php`) were left unchanged with the reasoning documented, per the task's own escape hatch. 40 targeted tests + full suite (78/79, same pre-existing unrelated failure) confirm zero behavioral regression. See TASKS_LOG.md, AI_PROJECT_MEMORY.md, and DECISIONS_LOG.md (2026-07-16 layout entries) for full detail. Not committed, per instructions.

---

### Date
2026-07-16 (implementation)

### Prompt
Following a read-only audit of the Execution Payment credit-account flow (same-day, prior prompt), user approved implementation with explicit constraints: smallest safe change set, mandatory pre-read of CLAUDE.md/Master Reference/memory docs, `git status`/`migrate:status` checks first, stop-and-ask if code differed materially from the audit, no commit/push, exact field names and behavior for the new credit-account cascade, mandatory server-side re-validation independent of Select options (with Arabic `ValidationException` messages), exact Create/Edit handler responsibilities, 7 named required test scenarios with a specific list of assertions each, and a full verification checklist (lint, new tests, existing description/financial tests, full suite with pre-existing-failure separation, route inspection, `optimize:clear`, `graphify update .`, explicit list of commands NOT to run).

### Purpose
Implement the approved future behavior for `/admin/execution-payments/create`: move "الحساب الدائن" above "الحساب المدين (المستفيد)", make the credit account a real editable same-currency cascade (still auto-defaulting from the budget's destination account), and fix the pre-existing Edit drift bug identified during the audit — without a migration and without touching any of the explicitly listed protected files (models/enum/builders/disbursement flow/reports/snapshots).

### Result
Implemented exactly as scoped in 3 files (`ExecutionPaymentForm.php`, `CreateExecutionPayment.php`, `EditExecutionPayment.php`), no migration, no protected file touched. 7 new tests added and passing; existing description/financial suites (70/70) and the full suite (78/79, 1 pre-existing unrelated failure) confirmed unaffected. Routes unchanged. `optimize:clear` and `graphify update .` run. See TASKS_LOG.md, AI_PROJECT_MEMORY.md, and DECISIONS_LOG.md (2026-07-16 entries) for full detail. Not committed, per instructions.

---

### Date
2026-07-16 (audit)

### Prompt
User requested a read-only discovery and impact audit (no edits, no migrations, no commits, lowest-token-consumption, Graphify-first) of the Execution Payment (`صرف مبالغ التنفيذ`, `/admin/execution-payments/create`) credit/debit account flow, ahead of a planned change to move the credit-account section above the debit section and make the credit account user-editable (defaulting from the budget's destination account, same-currency only) while preserving balanced double-entry accounting and historical accuracy on Edit. Required a specific mandatory output format answering 27 numbered questions with direct code evidence, exact file paths, and a minimal-change recommendation — explicitly not to implement anything.

### Purpose
Establish, with verified code evidence rather than assumptions, exactly where the credit account is currently auto-selected, where (if anywhere) it is authoritatively stored, whether a migration would be needed to make it editable, and the safest currency-restriction rule — before any implementation prompt was issued.

### Result
Delivered the 13-section audit report: confirmed the credit account is currently a display-only, non-dehydrated field recomputed from `ProjectCostBudget::LINE_DESTINATION` on every Create/Edit save (not stored on the domain record); confirmed no migration is required since `transaction_lines.account_id` on the `LINE_CREDIT`/`execution_source` line is already the authoritative, mutable store; discovered a pre-existing Edit drift bug (historical credit account silently recomputed from the budget's *current* destination on every edit); recommended same-currency-only for the replacement account. This audit directly informed the same-day approved implementation prompt above.

---

### Date
2026-07-15

### Prompt
User requested a safe cleanup of the OMS development database: remove all operational/transactional/project/test data while preserving system configuration, donors, accounts (balances reset to 0), currencies, exchange-rate history, and users/roles/permissions. Explicit two-phase instructions: implement a dedicated `oms:clean-operational-data` Artisan command + service, run dry-run/audit only in this phase, and STOP for explicit approval before any `--apply` execution. Extensive mandatory safety rules: environment guard (local/development/testing only), confirmation-token + verified-backup-file requirements for apply, FK-safe explicit deletion order, soft-delete-aware (active+trashed) deletion, attachment file safety (only delete files deterministically linked to deleted DB rows, report orphans separately), no identity/AUTO_INCREMENT reset, and "stop and ask" if any relationship/classification is unclear rather than guessing.

### Purpose
Prepare the dev database for clean real-data entry without risking donor, accounting-configuration, or historical exchange-rate data, and without ever touching production/staging.

### Result
Implemented `OperationalDataCleanupService` + `OperationalCleanupReport` DTO + `CleanOperationalData` console command, all schema relationships verified by reading actual migrations/models (not guessed), 20 new tests, full test suite run, dry-run executed against the real dev DB confirming zero writes. Apply was not run. See TASKS_LOG.md and DECISIONS_LOG.md (2026-07-15 entries) for full detail.

---

### Date
2026-07-15 (execution)

### Prompt
User approved execution of the previously-designed cleanup, restating the full mandatory-preservation list, attachment-safety rules, exact preconditions to check, the exact backup file path to use, the exact apply command, a 21-point post-apply verification checklist, a required second idempotence run, and full documentation/reporting requirements. When the specified backup file was found missing, a follow-up prompt explicitly chose "create a fresh mysqldump now" at that exact path, with explicit sub-requirements (create directory if missing, use the configured connection without exposing credentials, verify success and non-empty size, stop if verification fails, then continue with the approved apply).

### Purpose
Actually execute the approved, previously dry-run-only cleanup against the real dev database, with a fresh pre-cleanup backup and full independent verification.

### Result
Backup created and verified (120,266 bytes). Apply executed successfully; post-apply verification (both the command's own and an independent 21-point manual check) passed. Second apply run confirmed idempotence (zero additional changes). See TASKS_LOG.md and AI_PROJECT_MEMORY.md (2026-07-15 execution entries) for full detail.

---

### Date
2026-07-05

### Prompt
User requested (in Plan Mode) implementation of Phase 1 of a new "ميزان المراجعة" (Trial Balance) Filament report page, following on from an earlier audit-only conversation. Key constraints specified: single required `currency_id` filter (no cross-currency blending, per the audit's finding on `debit_base`/`credit_base`), read-only, no exports, no opening/closing balance, reuse `AccountStatementPage`/`AccountStatementReportService` patterns, run `php artisan optimize:clear` after implementation (no migrations), and deliver a detailed verification report.

### Purpose
Give the accounting team a way to verify total debits equal total credits across all accounts of one currency for a given period.

### Result
Plan approved and implemented as described in TASKS_LOG.md (2026-07-05 entry) and DECISIONS_LOG.md (2026-07-05 entry). Full plan file: `C:\Users\laptop\.claude\plans\implement-phase-1-trial-wiggly-lark.md`.

---

### Date
2026-07-05

### Prompt
User requested (in Plan Mode) Phase 2 of Trial Balance: professional Excel and Word exports mirroring the quality of `AccountStatementExcelExportService`/`AccountStatementWordExportService`/`ProjectFinancialDetailsWordExportService`. Constraints: exports must use only the applied/displayed result (never live filter state), no frozen panes but keep autofilter, real XLSX/DOCX (no CSV/HTML), verify PhpSpreadsheet/PhpWord were already installed before writing any code, no changes to `TrialBalanceReportService`'s calculations, and a detailed verification report.

### Purpose
Let accountants/managers download the on-screen Trial Balance as a shareable, print-ready file instead of only viewing it in the browser.

### Result
Plan approved and implemented as described in TASKS_LOG.md (2026-07-05, second entry). Same plan file, updated in place: `C:\Users\laptop\.claude\plans\implement-phase-1-trial-wiggly-lark.md`.

---

### Date
2026-07-06

### Prompt
User requested Phase 1 of "تقرير الحركات المالية الشامل": a read-only comprehensive financial transactions (general journal) Filament page under التقارير, one row per transaction line, date-range required filters plus optional currency (multi), category, type, account, account type, and project filters, "عرض"-gated loading, per-currency/category/type summaries, no exports, no writes, no migrations, no use of accounts.current_balance.

### Purpose
Give accountants a full journal view of every accounting line in a period across all transaction sources, with balance status per currency.

### Result
Implemented directly (auto-agent mode, pre-approved) as described in TASKS_LOG.md (2026-07-06 entry) and DECISIONS_LOG.md (2026-07-06 entry).

---

### Date
2026-07-06

### Prompt
User requested a small summary-section enhancement to "تقرير الحركات المالية الشامل": rename "عدد الأسطر" wording to "عدد بنود القيود", show "عدد المعاملات" as distinct transaction IDs (explicitly not line count ÷ 2), and make the category/type sections "إحصائيات حسب تصنيف المعاملة" / "إحصائيات حسب نوع المعاملة" show distinct transaction counts with line-based debit/credit totals still grouped per currency. No export, no accounting-logic changes, no migrations.

### Purpose
Make the top statistics clearer for management/accounting: transactions counted once even when they have 2+ lines, with بنود القيود counted separately.

### Result
Implemented as described in TASKS_LOG.md (2026-07-06, second entry).

---

### Date
2026-07-06

### Prompt
User requested Phase 2 of "تقرير الحركات المالية الشامل": professional Excel (PhpSpreadsheet) and Word (PhpWord) exports mirroring the Trial Balance/Account Statement export quality. Constraints: export only the applied/displayed result (never live filter state), block export before "عرض" with a specific Arabic warning, real XLSX/DOCX, RTL, no frozen panes but keep autofilter, real numeric cells with #,##0.00, A4 portrait Tahoma Word document with footer/page numbers, per-currency separation everywhere, "الكل" for unset filters, sanitized filenames, no Maatwebsite Excel, verify packages in composer.json first.

### Purpose
Let accountants/managers download the on-screen comprehensive journal as shareable, print-ready official files.

### Result
Implemented as described in TASKS_LOG.md (2026-07-06, third entry).

---

### Date
2026-07-06

### Prompt
User requested a full reset of operational/business data in the `oms` database to start entering clean real data, with an explicit keep list (system/auth/settings/lookup tables) and an explicit purge list (accounts, partners, projects, transactions, and related financial tables). Hard constraints: no `migrate:fresh`, no dropped tables, no migration/code changes, no deleting settings/lookup/system/auth data, no deleting storage files yet, stop and ask if any table is unclear, show KEEP/PURGE lists and wait for approval before deleting, back up first (or provide the exact `mysqldump` command), use `FOREIGN_KEY_CHECKS=0`/`1` around the purge, reset AUTO_INCREMENT, include soft-deleted rows, then run `optimize:clear` and `reports:refresh-projects-financial`, and finally show row counts to confirm.

### Purpose
Let the user discard test/sample operational data and begin real production data entry without losing configuration, permissions, or historical schema.

### Result
Implemented as described in TASKS_LOG.md (2026-07-06, fourth entry) and DECISIONS_LOG.md (2026-07-06 entry). Backup taken at `storage/app/private/backups/oms_backup_2026-07-06.sql` before any deletion; user approved the lists via AskUserQuestion before the purge ran.

---

### Date
2026-07-06

### Prompt
User requested proper accounting support for account opening balances on account creation: optional opening_balance / opening_balance_date / opening_balance_fx_rate fields on the create form only, auto-generating a balanced "قيد افتتاحي" transaction (`OPB-YYYY-XXXX`) against a clearing account "أرصدة افتتاحية", with `project_cost_id = null` so project reports are unaffected. Hard constraints: read-only impact report first then STOP for approval, no report-formula/snapshot/project-report changes, no unrelated files, reuse existing numbering/balance patterns, STOP and ASK if account nature doesn't exist or the clearing account can't be created cleanly, DB::transaction throughout, current_balance stays display-only.

### Purpose
Let accountants enter real starting balances for accounts (post data-reset) through proper double-entry bookkeeping instead of hand-editing `current_balance`.

### Result
Impact report delivered; 4 decision points raised (no account nature exists → always-debit; fx_rate as metadata per existing own-currency base convention; new AccountType for clearing accounts; new super type "قيود افتتاحية") — user approved all recommended options. Implemented as described in TASKS_LOG.md (2026-07-06, fifth entry) and DECISIONS_LOG.md (2026-07-06, second entry).

---

### Date
2026-07-14

### Prompt
User requested automatic Arabic `transactions.description` generation across all 6 financial write flows (receipts, project disbursement, execution payments, general expenses, general exchanges, opening balance), with an exact stored format (`دائن: {credit} | مدين: {debit} | ملخص العملية: {summary}.`, credit before debit, `؛ ` for multiple accounts per side, English digits/2 decimals/thousands separators, currency from `transaction_lines.currency_id` not `accounts.currency_id`), one shared `TransactionDescriptionBuilder` service, per-flow Arabic summary templates with fallbacks, regeneration on edit, no second DB transaction, no migration, no historical backfill, and required a read-only impact report first (STOP for approval), then implementation with unit + targeted integration verification.

### Purpose
Make every financial transaction human-readable at a glance (list/search/audit/exports) without changing any accounting calculation, balance, or currency-handling logic.

### Result
Impact report delivered and approved with clarifications (currency must come from the line not the account; exact `حساب`-prefix dedup rule; explicit validation requiring both sides non-empty or throw; summary normalization rules). Implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-14 entries). Mid-implementation, `RefreshDatabase`-based testing surfaced two unrelated pre-existing migration bugs; user was shown a full read-only investigation (exact files, error, historical batch evidence, safe-fix proposal) before approving a minimal fix for each, and approved leaving a third, MySQL-only-SQL migration incompatibility untouched. All 15 unit tests + 6 real create-flow + 5 real edit-regeneration tinker verifications passed against real dev-DB data with zero residue (rolled-back transaction).

---

### Date
2026-07-14

### Prompt
Follow-up: user asked to close the remaining manual-edit bypass on the raw `TransactionResource` form — make `description` read-only there before final approval. After that small fix, user requested a further read-only audit (6 specific questions: navigation visibility, direct create access, whether create also builds balanced lines, independent header editing, effect of the description fix on a raw create, and whether the raw CRUD is actively needed) before approving any further change. Based on that audit's findings, user approved converting the whole resource into a strictly read-only audit resource: remove create/edit routes and actions, harden authorization (`can*` → `false`, not just hidden buttons), keep list/view/lines fully visible for audit, delete now-unreachable page classes if safe, and add `defaultSort('id', 'desc')` to both the transactions list and the lines relation table.

### Purpose
Prevent any path — form field, direct URL, or raw CRUD — from creating or mutating a `Transaction`/`TransactionLine` outside the 6 legitimate, balance-safe financial flows, while preserving 100% of this resource's genuine audit value (search, filter, view, inspect lines).

### Result
`description` made `disabled()->dehydrated(false)` on `TransactionForm` (same pattern as `current_balance` on `AccountForm`). Read-only audit answered all 6 questions directly from code (confirmed: bare/line-less/unbalanced create, independent header edits, no balance updates) — implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-14, second and third entries). `create`/`edit` routes removed, `CreateTransaction.php`/`EditTransaction.php` deleted (confirmed unreferenced), 8 `can*` methods hardcoded `false` on both `TransactionResource` and `LinesRelationManager`, all mutation actions removed from `ListTransactions`/`ViewTransaction`/`TransactionsTable`/`LinesRelationManager`, `defaultSort('id','desc')` added to both tables. Verified headlessly via reflection (real `Table` objects, `getFlatActions()`/`getDefaultSortColumn()`) rather than a full Livewire/HTTP mount, plus a full re-run of the six-flow tinker verification confirming zero impact on the 6 legitimate flows and `TransactionDescriptionBuilder`.

---

### Date
2026-07-14

### Prompt
User requested the line-level counterpart of the transaction-description feature: add nullable system-generated `transaction_lines.description` (exact format `{مدين|دائن}: حساب {name} ({line currency}) — {posted amount} | الغرض: {purpose}.`) and `transaction_lines.line_role` (stable English machine value from one centralized vocabulary, assigned explicitly by the authoritative flow — never inferred from account/side/order/description/notes), across all 6 financial flows, preserving `transaction_lines.notes`/`LINE_*` tags untouched, no historical backfill, no report/export changes, plus a mandated read-only impact report first (delivered and approved), then implementation including hardening the standalone raw `TransactionLineResource` (a line-level CRUD bypass the audit surfaced) into a read-only audit resource.

### Purpose
Make each individual ledger line self-explanatory for audit/search (which account moved and why) and give future reports a reliable machine-readable role instead of parsing Arabic notes tags — without touching any accounting amount, balance, or existing tag.

### Result
Implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-14 line-description entries). One mid-implementation deviation was raised and user-approved before proceeding: legitimately zero-amount deduction lines (0% admin/transfer) are skipped with NULL description instead of throwing, since the flows always create them and the literal "both sides zero → throw" rule would have rolled back every 0%-deduction disbursement/exchange. 33/33 unit tests passing; all 6 create + 5 edit flows verified against real dev data in a rolled-back transaction with zero residue.

---

### Date
2026-07-15

### Prompt
User requested two things in one task: (1) safely backfill the existing historical (pre-2026-07-14) transactions/transaction lines so `description`/`line_role` show correctly in reports, via a dedicated `--dry-run`/`--apply` Artisan command, with an explicit classification priority (direct FK on the authoritative domain record first, `notes` `LINE_*` tags only as supporting evidence after the flow is known, never account name/type/description-parsing/line-order/amount-similarity), explicit zero-amount-line handling (assign role, keep description NULL), a mandatory integrity snapshot proving only the 3 metadata columns change, and idempotence proven by a second `--apply` run; (2) display the three approved, permanently-named Arabic labels (`وصف العملية المالية` / `دور سطر القيد` / `وصف سطر القيد`) in the detailed financial-movement sections of 4 named reports (Comprehensive Financial Transactions, Account Statement, Project Financial Details, Donor Financial Report) — screen, Excel, and Word wherever each export already exists — explicitly excluding Trial Balance, summary cards, dashboard totals, and any percentage/collection-rate calculation, plus standardizing the same 3 labels on the transaction/transaction-line audit resources. Workflow constraints: use the current session (no clean-tree requirement beyond the already-completed prior feature), run the standard git/migrate-status checks first, use Graphify before broad source browsing, STOP and ask on any accounting-meaning ambiguity, do not commit/push, and produce one detailed final report covering every phase.

### Purpose
Close the historical-data gap left by the 2026-07-14 description/role feature (which deliberately excluded backfill pending an approved classification method) and make the new metadata actually visible where accountants read detailed financial movements, without risking any accounting figure, balance, or existing report calculation.

### Result
Implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-15 entries). No accounting-meaning ambiguity was hit — the 6 flows' authoritative domain-record FKs and the opening-balance transaction-type name were sufficient to classify all 10 dev-DB transactions deterministically (0 unclassified), so no STOP-and-ask was needed. `--dry-run`/`--apply`/idempotence-re-`--apply`/integrity-snapshot sequence all passed; 17 new backfill tests + 33 existing description/role-builder tests + full suite (52/52 relevant, 1 pre-existing unrelated failure) all passing; one real sample export per updated report/format (7 files) generated from live dev data and verified to re-open. Not committed, per instruction.
