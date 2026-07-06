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
