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
