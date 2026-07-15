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
