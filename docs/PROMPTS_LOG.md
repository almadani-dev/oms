# Prompts Log

## Prompt Template

### Date

### Prompt

### Purpose

### Result

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
