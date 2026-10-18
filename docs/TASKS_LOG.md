# Tasks Log

## Task Template

### Date

### Task

### Result

### Changed Files

### Verification


---

### Date
2026-10-18 (Receipt form donor picker — bounded first 50 on opening)

### Task
The «الجهة المانحة» (`partner_id`) Select of the Project Cost Receipt form opened with an empty list (Search Batch E: server-side search, nothing preloaded) until the user typed.

### Result
- Audit first: the implementation matched the Batch E description; the Donor Financial Report picker already used `FinancialLookupFilters::partnerOptions('', donorsOnly)` for its opening list.
- The receipt picker now reuses `FinancialLookupFilters::partnerOptions('', self::donorsOnly(...))` as `->options()`: the first 50 eligible donors (name order) on opening, one `LIMIT 50` query, never the whole table.
- Unchanged: Arabic-aware typed search (≤ 50), selected-label resolution and donors-only `in` validation through `partnerLabel()`, SoftDelete exclusion, receipt accounting and create / edit / delete write paths, `transaction.partner_id` semantics, the shared helper, other forms.

### Changed Files
- `app/Filament/Resources/ProjectCostReceipts/Schemas/ProjectCostReceiptForm.php`
- `tests/Feature/Search/LookupSelectSearchTest.php` (receipt case of the partner data-provider test now expects bounded donor-only initial options; new `test_receipt_donor_picker_opens_on_a_bounded_first_page_of_donors`)
- `graphify-out/` (`graphify update .`)

### Verification
- `LookupSelectSearchTest` 21/21 (205 assertions).
- `ProjectCostReceiptAccountValidationTest` + `BalanceGuardIntegrationTest` 13/13 (62 assertions).
- `graphify update .` completed. Full suites not run (forbidden).

### Commit Hash
Not committed.

---

### Date
2026-10-18 (Comprehensive projection rebuild — timezone-safe month enumeration)

### Task
Fix the rebuild dropping the latest month's aggregates when the earliest `transaction_time` is historical (year 0025) under Asia/Jerusalem.

### Result
- `ComprehensiveReportProjectionRebuilder::months()` uses integer year/month arithmetic instead of a cumulative Carbon cursor; the final month is always included. Formulas, inclusion rules, verifier and live projector unchanged; no migration; no ledger data changed.

### Changed Files
- `app/Services/Reports/ComprehensiveFinancialTransactions/Projection/ComprehensiveReportProjectionRebuilder.php`
- `tests/Feature/Reports/ComprehensiveReportProjectionMonthRangeTest.php` (new)

### Verification
- `ComprehensiveReportProjectionMonthRangeTest` 2/2 (27 assertions); the same test against the old code fails (24011 vs 24012 months).
- `ComprehensiveReportProjectionPageTest` 30/30 (188), `ComprehensiveReportProjectionWritePathsTest` 11/11 (57). Full suites not run (forbidden).

### Commit Hash
`fix(comprehensive): make projection month rebuild timezone-safe` (not pushed).

---

### Date
2026-10-13 (Donor Financial Report — real-time projection read path; `oms_stress` only; committed `4b9f8c8`)

### Task
Remove the full-report rebuild from «عرض»: derived projection maintained in the same DB transaction as every financial write, 50-row reads, exact summaries, rebuild / verify commands, parity with the legacy service.

### Result
- **Baseline (legacy service, donor 17):** 10.2–13.2 s, 19 queries, +55 MB (120 projects, 345 cost items, 9,695 movements, 21,546 lines); donor 295 1.49 s, donor 300 0.17 s. Cost = random I/O (lines query 3.7–4.3 s, payments 2.3 s, deduction lines 1.5 s).
- **Rebuild** (`--fresh --verify`): 395,566 movements / 883,761 lines in 223 s, PHP 58 MB flat; verifier 0 differences (79–94 s). Storage: movements 65.7 MB + 11.5 MB index, lines 159 MB (≈ 270 MB per 1M accounting lines).
- **Parity (legacy vs projection, incl. CSV records):** all 537 donors 0 differences (372,865 movements, 832,844 lines); 78 filter cases (donors 17, 295, 300 + 5 random × date / status / type / currency / project / combined) 0 differences. Currency row order notes: disbursement-final grain only (MySQL temp-table order in legacy).
- **Reads (donor 17, warm):** «عرض» server 163–244 ms (scope 24–33 ms, summary 36–124 ms); full Livewire «عرض» 0.23–0.33 s (1.58 s first cold call); projects page 17–47 ms, cost items 6–13 ms, movements first / middle / last 14–66 ms; pager requests 0.11–0.23 s server. Donor 295 «عرض» 0.20 s, donor 300 0.15 s. Request 2.9–3.3 KB, state ≤ 2.9 KB. All queries PRIMARY-range (EXPLAIN); no extra index.
- **CSV donor 17:** 7.3–8.0 s from the projection (legacy 13–15 s), byte-identical (17,989,622 bytes); remaining time = text hydration I/O (line texts ~4 s, transaction texts ~2.2 s). Kept synchronous. Excel 0.31–0.62 s / Word 0.37–0.42 s (47 / 40 KB), Excel == Word.
- **Write overhead (rolled back on stress):** receipts 1.9–7.5 ms median (max 35 ms, 4–8 queries), budgets 4.1–15.7 ms (max 24 ms, 9–13), payments 1.6–6.7 ms (max 13 ms, 4–8).
- **Browser (stress site, real Livewire):** donors 17 / 295 / 300 on the projection path; donor 17 «عرض» visible 0.59–1.26 s (HTTP 0.45–0.93 s, 614 KB HTML), pager moves 0.43–0.80 s visible via First / Previous / Next / Last (HTTP 0.28–0.62 s; a request without report work costs 0.17–0.78 s on this FastCGI setup); donors 295 / 300 «عرض» 0.38–0.52 s. Filter change cleared the report; re-«عرض» (ILS) 3,427 movements. CSV 21,546 rows built in 8.73 s, download 200 / 17,989,622 bytes; Excel 2.5 s and Word 200; one audit event each (ids and counts only). 49 requests all 200 (47 Livewire updates, no 404, no PayloadTooLarge), no console error, no new `laravel.log` entry, stress banner present.
- Graphify `update .`: 16,405 → 16,530 nodes, 42,528 → 42,910 edges; no code node lost (docs / view node ids re-keyed, counts equal or higher).

### Changed Files
- New: `database/migrations/2026_10_13_100000_create_donor_report_projection_tables.php`; `app/Services/Reports/DonorFinancialReport/Projection/{DonorReportProjection,DonorReportProjectionState,DonorReportProjector,DonorReportScope,DonorReportReader,DonorReportProjectionRebuilder,DonorReportProjectionVerifier,DonorReportParityChecker}.php`; `app/Console/Commands/Reports/{Rebuild,Verify}DonorFinancialReportProjectionCommand.php`; `tests/Feature/Reports/DonorFinancialReportProjectionWritePathsTest.php`.
- Changed: `app/Filament/Pages/DonorFinancialReportPage.php`, `resources/views/filament/pages/donor-financial-report-page.blade.php`, `app/Services/Reports/DonorFinancialReport/Csv/DonorFinancialReportCsvExport.php`, the 9 write sites (`ProjectCostReceipts`, `ProjectCostBudgetsPayments`, `ExecutionPayments` Create / Edit pages and Tables), `app/Services/Maintenance/OperationalDataCleanupService.php`, `tests/Feature/Reports/DonorFinancialReportExportTest.php`.

### Verification
- `DonorFinancialReportExportTest` 26/26, `DonorFinancialReportProjectionWritePathsTest` 10/10, `ComprehensiveReportProjectionWritePathsTest` 11/11, `AccountStatementWritePathsTest` 8/8, `ReportExportAuditTest` 23/23, `ReportExportAuthorizationTest` 28/28, `ReportPageAccessTest` 30/30, `CleanOperationalDataCommandTest` 37/37, financial audit / guard / attachment tests of the three workflows (11 files) all pass. Full suites not run (forbidden).

### Commit Hash
`4b9f8c8` feat(donor-report): add real-time reporting projection (not pushed).

---

### Date
2026-10-12 (Donor Financial Report — final export model, server-side report state, line-grain CSV, selectors; verified on `oms_stress`; committed `9026abf`)

### Task
Apply «تصدير البيانات CSV» (detail only) / «تصدير الإحصائيات Excel / Word» (statistics only, one worksheet) to تقرير الجهات المانحة; then fix the Livewire payload blocker, the search-only pickers and the CSV grain.

### Result
- Buttons «تصدير البيانات CSV» · «تصدير الإحصائيات Excel» · «تصدير الإحصائيات Word»; filters, «عرض» gate (a filter change clears the report and blocks export), browsing and formulas unchanged.
- Report state server-side (`DonorFinancialReportState`): Livewire request after «عرض» 3,655 KB → 1.3–1.5 KB (donor 295), ~31–37 MB → 1.2–1.5 KB (donor 17); no `PayloadTooLargeException`, no 500 (44 requests in the browser run, all 200).
- Pickers: 50 donors / 50 projects of the donor on opening; donors 17 / 295 / 300 (positions 136 / 227 / 274 by name) found by search, labels resolved.
- CSV: 19 columns, one row per accounting line — التاريخ, نوع الحركة, كود المشروع, اسم المشروع, رقم المعاملة, وصف العملية المالية, المرجع, المبلغ, العملة, المبلغ النهائي (بعد الخصومات والتحويل), عملة الصرف, ملاحظات, رمز الحساب, اسم الحساب, عملة السطر, مدين, دائن, دور سطر القيد, وصف سطر القيد. Reuses `AccountStatementCsvFormat` / `…CsvPart` / `…CsvZip` / `…CsvSnapshot` and the owner-scoped export store; per-user lock + 30 s re-delivery of an identical export.
- Statistics: one sheet «الإحصائيات»; Excel == Word.
- **Verifier (CSV vs `DonorFinancialReportService::generate()` + an independent SQL count of active lines), 14 cases** (donor 17 all / date / status / type / EUR / ILS / one project / project+ILS / combined; donors 8, 295, 300, 344): 0 differences; rows = service lines = SQL lines (donor 17: 9,695 movements → 21,546 rows); 0 duplicates; ties of the same date text and kind ordered by id (the service: database order).
- **Benchmarks (dev laptop, noisy; 128 MB buffer pool), donor 17:** movement query 3.9–5.9 s, line query 4.5–5.2 s, reader 9.3–10.4 s, encoding negligible, file ready 12.6–13.7 s (1,572–1,685 rows/s), 17.6 MB raw / 1.6 MB ZIP, peak ~10 MB above baseline. «عرض» (service) 9.8–11.4 s; store write ≤ 0.12 s, reads ≤ 0.07 s; Excel / Word ≤ 0.43 s, 47 / 40 KB. Donors 295 / 300: CSV 0.24 / 0.06 s.
- Browser (stress site): donors 17, 295, 300 — «عرض», CSV (downloads 200: 17,989,622 / 1,808,005 / 145,477 bytes), Excel, Word, filter change after «عرض» (report cleared, export blocked); audit 9 events `csv/detail_data`, `xlsx|docx/statistics`, ids and counts only.
- **Screen paging (movements, projects, cost items — 50 / page, independent pagers):** donor 17 «عرض» HTML 35.1 MB → 0.48 MB server-side (browser response 54.3 MB → 0.63 MB; the projects cell markup was also compacted — its indentation was half that table's HTML); re-render 0.70 s / +102 MB → 0.07 s / +4 MB; page moves 0.06–0.14 s server, 0.44–0.87 s in a visible browser tab (1.6–2.7 s when the automated tab is hidden: timer / rAF throttling). Requests 1.4–1.6 KB, state out ~1.2 KB. Stored pages / slices == the service's movements (9,695), projects (120) and cost items (345), order and values, 0 duplicates (donors 17, 8, 295, 300). Exports from pages 2/3 · 7/7 · 2/194: CSV 21,546 rows / 17,989,622 bytes, Excel / Word 120 projects / 345 cost items. 88 browser requests, all 200.

### Changed Files
- New: `app/Services/Reports/DonorFinancialReport/{DonorFinancialReportState,DonorFinancialReportStatistics}.php`, `…/Csv/DonorFinancialReportCsvExport.php`, `…/Csv/DonorFinancialReportCsvFormat.php`, `app/Http/Controllers/Reports/DonorFinancialReportCsvDownloadController.php`, `tests/Feature/Reports/DonorFinancialReportExportTest.php`.
- Changed: `app/Filament/Pages/DonorFinancialReportPage.php`, `resources/views/filament/pages/donor-financial-report-page.blade.php` (+ new `partials/donor-financial-report-pager.blade.php`), `app/Services/Reports/{Statistics/StatisticsReport,Excel/StatisticsWorkbook,Word/StatisticsDocument}.php` (`money_optional`), `app/Console/Commands/PurgeReportSnapshotsCommand.php`, `app/Services/Audit/Reports/{ReportExportFormat,ReportExportPurpose}.php` (comments), `config/oms.php`, `routes/web.php`, `tests/Feature/Search/LookupSelectSearchTest.php`.
- Deleted (zero callers): `app/Services/Reports/DonorFinancialReportExcelExportService.php`, `app/Services/Reports/DonorFinancialReportWordExportService.php`.

### Verification
- `DonorFinancialReportExportTest` 21/21 (689 assertions); `LookupSelectSearchTest` 20/20, `ReportExportAuditTest` 23/23, `ReportExportAuthorizationTest` 28/28, `ReportPageAccessTest` 30/30, `AccountStatementStatisticsExcelTest` 8/8, `ComprehensiveStatisticsExcelTest` 8/8. Full suites not run (forbidden).

### Commit Hash
`9026abf` feat(donor-report): modernize exports and bound report state.

---

### Date
2026-10-11 (final pre-commit — single-sheet statistics Excel + safe dead-code cleanup; verified on `oms_stress`)

### Task
- Statistics Excel = exactly ONE right-to-left worksheet on both reports, sections stacked vertically.
- Remove only the proven-dead old Word-detail code; keep the old detailed-Excel infrastructure and report it.

### Result
- **One worksheet «الإحصائيات»** on both reports, in the same section order as the Word document (one `StatisticsReport` model):
  - Account Statement: title and export time, period, applied filters, general statistics, notes, per-currency summary, «ملخص الحسابات» (with the sheet's autofilter).
  - Comprehensive: title and export time, report data, applied filters, general summary, per-currency summary, per classification, per transaction type. No autofilter (Excel allows one per worksheet, and two group tables share the sheet).
- **Removed after a reference + Graphify audit (no callers):** `AccountStatementWordExportService`, `ComprehensiveFinancialTransactionsWordExportService`, `AccountStatementPage::exportRows()`, `AccountStatementExportPlan::wordMaxUnits()`, `AccountStatementExportLayout::money()`, the comprehensive page's `inlineExportRows()` / `liveExportSnapshot()` / `discardLiveExportSnapshot()` / `auditExport()` / `wordMaxLines()` / `wordLimitNotice()`, and config `word_max_units` / `word_max_lines`.
- **Kept intentionally (old detailed Excel, unreachable from the UI; removal is a later user decision):** the streaming / styled detail Excel services, both `Build…ExcelExportJob` jobs, the Excel export stores, the xlsx routes and controller branches, the pages' Excel export properties / methods and Blade pollers, and their config caps. `AccountStatementExcelExportService` is live (the Muwakha family statement uses it).
- **Word rules unchanged:** statistics only; Account Statement is refused only above 2,000 summary rows (`word_max_summary_rows`); Comprehensive has no limit.
- **Real files on `oms_stress`** (3 Account Statement + 3 Comprehensive cases, plus the browser downloads): 1 RTL worksheet, every section once and in order, no detail header; Excel == Word with 0 differences.
- **Static checks:** `php -l` and Pint clean on the new / changed files (`ComprehensiveFinancialTransactionsPage` keeps its pre-existing Pint findings, not reformatted); no reference to any removed symbol remains; Graphify rebuilt.

### Changed Files
- `AccountStatementStatistics`, `ComprehensiveStatistics`, both statistics Excel services, both report pages, `AccountStatementExportPlan`, `AccountStatementExportLayout`, `config/oms.php`.
- Tests: `AccountStatementStatisticsExcelTest`, `ComprehensiveStatisticsExcelTest`, `ComprehensiveFinancialTransactionsLargeExportTest`.
- Deleted: the two old Word services. Graphify; docs.

### Verification
- Targeted PHPUnit only: 16 paths, **360 tests, 18,433 assertions, all passing**.
- `tests/Feature/Muwakha`: 184 / 204 pass. The 20 `MuwakhaFamilyImportTest` failures are PRE-EXISTING and unrelated: the import command treats the test's absolute Windows temp path as relative. Not fixed (out of scope).

### Commit Hash
`aabf67e` refactor(reports): use single-sheet statistics exports and remove dead Word paths; knowledge graph: `d8ce9e0` chore(graphify): refresh knowledge graph — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---

### Date
2026-10-11 (addendum — Word also statistics-only on both reports; verified on `oms_stress`)

### Task
Turn «تصدير Word» into «تصدير الإحصائيات Word» on both reports: the same statistics as Excel, no detail rows; re-decide the size limit; prove Excel == Word.

### Result
- **Buttons (both pages):** «تصدير البيانات CSV» · «تصدير الإحصائيات Excel» · «تصدير الإحصائيات Word» (page constants `CSV_EXPORT_LABEL` / `EXCEL_EXPORT_LABEL` / `WORD_EXPORT_LABEL`).
- **One statistics model, two renderers:** `Statistics/StatisticsReport` (built by `AccountStatementStatistics` / `ComprehensiveStatistics`), rendered by `Excel/StatisticsWorkbook` (OpenSpout) and `Word/StatisticsDocument` (PhpWord: A4 landscape, RTL paragraphs, `bidiVisual` tables, repeated header rows).
- **Size limit:** the movement-based limits are no longer consulted. Account Statement Word is refused only above `word_max_summary_rows` = 2,000 summary rows (accounts + currencies); the message recommends Excel statistics and CSV. Comprehensive Word has no limit (49 rows for the full range).
- **Audit:** Word = `format docx`, `purpose statistics`; the statistics `purpose_label` is now «تصدير الإحصائيات والملخصات».
- **Word benchmarks (`oms_stress`):** AS 7 summary rows 0.05 s; AS 1,615 rows 0.97 s / 94 MB; AS 3,890 rows 3.63 s / 152 MB and 5,000 rows 5.38 s / 184 MB (both now refused); CF full range, 49 rows, 0.05 s. The cost follows the summary rows (~0.7 ms per row), never the movements.
- **Parity Excel == Word: 0 differences** (stress script on 6 cases up to 1,626 rows; PHPUnit `assertExcelWordParity()`; browser downloads).
- **Browser (LAN stress site):** three buttons on both pages; AS Word delivered for 187,582 movements (refused under the old rules); AS with 3,388 summary rows refused with the new message; CF Word delivered for 583,229 lines.
- The old Word-detail code became unused here; it was removed in the final pre-commit entry above.

### Changed Files
- New (under `app/Services/Reports/`): `Statistics/StatisticsReport.php`, `Word/StatisticsDocument.php`, `AccountStatement/AccountStatementStatistics.php`, `ComprehensiveFinancialTransactions/ComprehensiveStatistics.php`, `AccountStatementStatisticsWordExportService.php`, `ComprehensiveFinancialTransactionsStatisticsWordExportService.php`; plus `tests/Support/Reports/StatisticsFileReader.php`.
- Changed: both report pages, both statistics Excel services, `Excel/StatisticsWorkbook.php`, `AccountStatementExportPlan.php`, `ReportExportPurpose.php`, `ReportExportFormat.php` (doc), `config/oms.php` (`account_statement.word_max_summary_rows`), and the related report tests.

### Verification
- Targeted PHPUnit only: 16 paths, **360 tests, 18,442 assertions, all passing**.
- Benchmarks and parity scripts ran read-only on `oms_stress`, outside PHPUnit.

### Commit Hash
`062e6d1` feat(reports): separate detail CSV from statistics Excel and Word (together with the 2026-10-11 data/statistics split) — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---

### Date
2026-10-11 (Both financial reports: data export separated from statistics export — verified on `oms_stress`)

### Task
On both reports: «تصدير البيانات CSV» = detailed rows ONLY; «تصدير الإحصائيات Excel» = statistics, summaries and applied filters ONLY. No financial semantic change.

### Result
- **CSV = detail rows only.** The summary CSV files are no longer written. Columns, order, segments, workers, the proof before delivery and the `reports` job are unchanged; the summaries are still read, only as the proof's expectations.
- **Packaging (user decision):** one plain `.csv` when the export fits in one file of at most `csv.plain_max_rows` = 100,000 planned rows; otherwise a ZIP of the detail file(s) only. Plain exports are written as stored pieces and concatenated once (`AccountStatementCsvPart::concatenate()`); downloads send `text/csv; charset=UTF-8` or `application/zip`.
- **Excel = statistics only**, generated immediately in the request (no queue, no snapshot build) through the shared OpenSpout writer `Excel/StatisticsWorkbook`: RTL, bordered sections, `#,##0.00` money, `#,##0` counts. Comprehensive builds it from the summaries the displayed report already holds (no query).
- **Audit:** optional `ReportExportPurpose` → `purpose` + `purpose_label` (`detail_data` «تصدير البيانات التفصيلية (CSV)» / `statistics`). CSV adds `package_type`; `package_bytes` is the delivered file's size. No exported content is logged.
- **Old detailed Excel path:** left in place but unwired from the buttons, so a workbook queued before the change still completes; unreachable from the UI, removal is a user decision.
- **CSV performance A/B** (pre-change code vs working tree, interleaved, 3 rounds, 8 workers, median): ZIP exports unchanged within noise (AS 1,067,022 rows 13.82 → 14.15 s; CF 1,221,151 lines 12.56 → 12.46 s); plain-CSV exports +0.1–1.5 s (≈ 10× more bytes, no DEFLATE).
- **Excel statistics generation:** AS 4,997 accounts 1.95 s / 222 KB; AS 1–5 accounts ≈ 0.1 s; Comprehensive full range 0.15 s.
- **Correctness (0 differences):** CSV records byte-identical (SHA-256) to the old export's movement files in 7 cases; independent replay of row counts, running balances and per-account / per-currency totals; statistics workbooks read back cell by cell equal the report summaries in 7 cases.
- **Browser (LAN stress site):** three buttons side by side; background CSV ZIPs contain only detail files; small exports are a plain `.csv` with BOM; changing a filter without «عرض» refuses both exports. Delivery checked in Apache's access log (automated Chrome masks downloads).
- **Operational:** the running `reports` worker had the old code in memory; it was restarted.

### Changed Files
- New: `Excel/StatisticsWorkbook.php`, `AccountStatementStatisticsExcelExportService.php`, `ComprehensiveFinancialTransactionsStatisticsExcelExportService.php`, `app/Services/Audit/Reports/ReportExportPurpose.php`, `AccountStatementStatisticsExcelTest`, `ComprehensiveStatisticsExcelTest`.
- Changed: both report pages; the Account Statement and Comprehensive CSV export / format classes and `AccountStatementCsvPart`; `AccountStatementExcelDownloadController`, `ComprehensiveFinancialTransactionsCsvDownloadController`; both CSV jobs; `ReportExportAuditRecorder`, `ReportExportFormat` (doc); `config/oms.php` (`csv.plain_max_rows` × 2); the related report tests; `graphify-out/`.

### Verification
- Targeted PHPUnit only: 16 paths, **356 tests, 18,323 assertions, all passing**.
- Benchmarks, correctness and statistics checks ran read-only on `oms_stress`, outside PHPUnit.

### Commit Hash
`062e6d1` feat(reports): separate detail CSV from statistics Excel and Word (together with the Word addendum) — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---

### Date
2026-10-10 (تقرير الحركات المالية الشامل «تصدير CSV» — implemented and verified on `oms_stress`)

### Task
Add «تصدير CSV» to `/admin/comprehensive-financial-transactions`: the applied filters, every line, read from the real-time projection, exact and Excel-friendly; 1M lines as fast as practical (stretch ≤ 5 s) without dropping columns.

### Result
- **Architecture** (`app/Services/Reports/ComprehensiveFinancialTransactions/Csv/`): `ComprehensiveCsvExport` (plan, workers, proof, package); `ComprehensiveCsvSegment` (contiguous time windows; transactions and source notes read once by id; one unbuffered stream of projection lines joined to their own ledger line; every row checked against its projection row); `ComprehensiveCsvLabels` (master data once per process); `ComprehensiveCsvFormat` (the 16 detail columns). Format, ZIP, snapshot and export-store classes are reused from the Account Statement CSV. Files hold ≤ 1,000,000 rows, cut on day boundaries (the ZIP's summary file was removed on 2026-10-11).
- **Work units** = lines weighted by sparsity: ≤ 25,000 units inline in the click request, otherwise the `reports` job with up to 8 parallel segments.
- **Consistency proof:** per-segment fingerprints re-read in a new snapshot after every worker opened its own, plus totals equal to `ComprehensiveReportSummaries`; a mismatch rebuilds (3 attempts) and is never delivered.
- Projection not READY → CSV refused with an explicit notice.
- **Performance** (8 workers, DEFLATE level 1, median; this laptop drifted ~2× during the day): 1,002,837 lines 12.6–27.9 s; 1,221,151 lines 15.2–31.3 s (101 MB ZIP); one account (58,900 lines) 5.3–5.6 s; small filtered cases 0.4–1.0 s. Memory flat (parent 46 MB, workers 72–96 MB).
- **≤ 5 s for 1M: NO.** Bottleneck: MySQL reading the ledger texts through a 128 MB InnoDB buffer pool (full-column DB floor 7.3–10.9 s at 1M on 8 connections); the export is ~1.7–2.6× that floor.
- **Independent correctness** (the report's ledger reader or plain ledger SQL, never the projection or CSV code): 7 cases up to the full 1,221,151 lines, **0 row and 0 summary differences**.
- **Real Excel 16:** opens on double-click, Arabic headers, numeric money, multi-line notes kept in one cell, SUMs equal the summary.
- **Stress validation** (real page, job, worker and routes in-process; HTTP on the LAN site): repeated clicks create one job; the full range built in 35 s by `queue:work --queue=reports`; paging stayed responsive; another user 404, no permission 403, unsigned URL 403; one audit event per export.
- Pre-existing, unrelated: an unauthenticated `Accept: application/json` request to any Filament-authenticated route answers 500 instead of 401.

### Changed Files
- New: the `Csv/` classes above plus `ComprehensiveCsvMismatch`, `ComprehensiveCsvDrift`, `ComprehensiveCsvUnavailable`; `ComprehensiveCsvSegmentCommand` (`oms:comprehensive-csv-segment`, hidden); `BuildComprehensiveFinancialTransactionsCsvExportJob`; `ComprehensiveFinancialTransactionsCsvDownloadController`; `ComprehensiveFinancialTransactionsCsvExportTest`.
- Modified: the comprehensive page and Blade (CSV action, JSON poller); `routes/web.php` (`.csv`, `.csv-status`); `AccountStatementExcelExports` (base directory + config prefix); `PurgeReportSnapshotsCommand`; `ReportExportFormat` (doc); `config/oms.php`; `docs/COMPREHENSIVE_REPORT_DEPLOYMENT_CHECKLIST.md` (C1–C3); `graphify-out/*`.

### Verification
Targeted tests only, all passing: `ComprehensiveFinancialTransactionsCsvExportTest` 29/29, `AccountStatementCsvExportTest` 20/20, `tests/Unit/Services/Reports` 21/21, `ComprehensiveReportProjectionPageTest` 30/30, `ComprehensiveFinancialTransactionsPageTest` 33/33, `ComprehensiveTransactionTypeMultiSelectTest` 13/13, `ComprehensiveFinancialTransactionsLargeExportTest` 15/15, `ReportExportAuthorizationTest` 28/28, `ReportPageAccessTest` 30/30, `tests/Feature/Audit/Reports` 23/23, `AccountStatementReportTest` 48/48.

### Commit Hash
`86bbaa0` feat(comprehensive): add high-performance CSV export — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---


### Date
2026-10-08 (Account Statement «تصدير CSV» — implemented and verified on `oms_stress`)

### Task
Add «تصدير CSV» to `/admin/account-statement`: an ultra-fast, exact, Excel-friendly export of the applied filters, read from the projection; stretch target 1M movements in ≤ 5 s.

### Result
- **Architecture** (AI_PROJECT_MEMORY → Operational Reference → Account Statement architecture): day-aligned segments, each one unbuffered query over the projection joined to the ledger texts; integer-cents running balances from checkpoint openings; up to 8 parallel CLI segment workers in the `reports` job; per-worker DEFLATE pieces joined by a streaming ZIP writer (CRC combine, ZIP64); per-export proof (segment chain + summary); files of ≤ 1,000,000 rows; inline ≤ 25,000 units; signed, owner-scoped download, audited once. (The ZIP's summary CSV was removed on 2026-10-11.)
- **Matrix** (all 4,997 accounts, 8 workers, median): 100,368 rows 3.06 s; 500,526 rows 7.36 s; **1,000,293 rows 11.28 s**; 1,221,151 rows 17.28 s (82.9 MB ZIP). Parent memory 76–80 MB, flat.
- **≤ 5 s for 1M: NO.** Bottleneck: MySQL reading ~810 MB of ledger text through the 128 MB buffer pool (DB-read floor 11.54 s on 8 connections); the export matches that floor. A projection-only export (3.6 s) would drop the required descriptions and notes.
- **Independent correctness** (ledger SQL only, bcmath balance replay, every cell): 4 cases up to the full 1,221,151 rows, **0 differences**.
- **Real Excel 16:** 14 columns, Arabic headers, no import wizard, numeric money, SUM equals the summary.
- **Browser (stress site):** direct export 200; a 756,123-row background export built in 8 s with a lightweight JSON status poll; no Livewire errors. The browser may request one download URL several times, so the CSV stays downloadable by its owner until it expires and is audited once.
- One synthetic `kernel-test` audit row was written on stress by an in-process test.

### Changed Files
- New: `app/Services/Reports/AccountStatement/Csv/` (`AccountStatementCsvExport`, `…Segment`, `…Labels`, `…Format`, `…Part`, `…Zip`, `…Snapshot`, `…Mismatch`); `AccountStatementCsvSegmentCommand`; `BuildAccountStatementCsvExportJob`; `AccountStatementCsvExportTest`; `AccountStatementCsvPackageTest`; `tests/Support/Reports/AccountStatementLedgerFixture.php`.
- Modified: the account statement page and Blade; `AccountStatementExcelDownloadController`; `AccountStatementExcelExports`; `AccountStatementExportPlan`; `AccountStatementRows`; `ReportExportFormat`; `PurgeReportSnapshotsCommand`; `config/oms.php`; `routes/web.php`; `ReportExportAuditTest`; `docs/ACCOUNT_STATEMENT_ARCHITECTURE.md`.

### Verification
Targeted tests only, all passing: `AccountStatementCsvExportTest` 20/20, `AccountStatementCsvPackageTest` 21/21, `AccountStatementReportTest` 48/48, `ReportExportAuditTest` 23/23, `ComprehensiveFinancialTransactionsLargeExportTest` 15/15, `AccountStatementWritePathsTest` 8/8, `ComprehensiveFinancialTransactionsSnapshotTest --filter purge` 3/3. Benchmarks and verification ran outside PHPUnit on `oms_stress`.

### Commit Hash
`e0392c1` feat(account-statement): add projected multi-account reporting and CSV export (together with the 2026-10-07 redesign and polish) — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---


### Date
2026-10-07 (normal oms.test isolated in its own FastCGI pool; export-job `$timeout` deprecation fixed)

### Task
Move `http://oms.test/admin` off threaded `mod_php` to the same FastCGI isolation as stress, prove both sites under concurrency, and fix the undeclared `$timeout` in the two export jobs.

### Result
- **Runtime:** `oms.test` moved from `apache2handler` (563 / 7,500 probes saw a missing key or fallback values) to `cgi-fcgi` in its own pool (`sites-enabled/05-oms-test-fcgid.conf`, loaded before Laragon's regenerated `auto.oms.test.conf`; it injects no environment, so `.env` applies). The stress config is unchanged.
- **Concurrency:** 20,000 identity probes (up to 96 concurrent) plus 3,600 real requests on both sites: 0 missing key; 0 wrong environment, database, storage or Livewire hash; 0 cross-environment leaks; logs 0 × 404 and 0 × 5xx after the switch. Artifacts: 224 ephemeral-port 500s caused by my load script, and 7 transient Windows Blade compile `rename()` races.
- **Databases:** ledger fingerprints identical on both; the test guest sessions were removed.
- **Deprecation:** both export jobs assigned `$this->timeout` without declaring it (a dynamic property on PHP 8.2+). Fixed with `public ?int $timeout = null;`; the payload still carries `timeout=1800`, `maxTries=1`, `failOnTimeout=true`, queue `reports`.

### Changed Files
- Server (outside git): new `sites-enabled/05-oms-test-fcgid.conf` in Laragon's Apache `etc`; backups in `_backup_2026-10-07_oms-test-isolation/`.
- App: `app/Jobs/BuildAccountStatementExcelExportJob.php`, `app/Jobs/BuildComprehensiveFinancialTransactionsExcelExportJob.php` (property declaration only).
- Docs: `docs/LOCAL_STRESS_RUNTIME.md` (both sites) and the memory docs.

### Verification
- `httpd -t` Syntax OK; `httpd -S` shows the expected precedence.
- Targeted tests: 5 report test files, 129 tests / 13,633 assertions, all pass. Pint clean. The temporary probe was deleted (404 on both hosts).

### Commit Hash
`530e422` fix(jobs): declare report export job timeout (the comprehensive Excel job; the account statement jobs carry the declaration in `e0392c1`); documentation: `696b4e8` docs(dev): document isolated local FastCGI runtimes — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---


### Date
2026-10-07 (local stress runtime isolation — server config only)

### Task
Isolate the LAN stress site from normal `oms` at the PHP process level, so that no request can resolve the other environment's key, database or storage, and prove it under concurrency.

### Result
- **Before:** Apache 2.4.62 WinNT MPM (threaded) with PHP 8.3.28 TS as `mod_php` for every site; the stress environment came from a global `<Location> SetEnv`; one PID served both. 600 parallel probes showed missing keys, sqlite fallbacks, `env=production` and wrong Livewire hashes.
- **After:** stress is served by mod_fcgid `php-cgi` workers (`cgi-fcgi`, separate PIDs). 8,700 probes, including during browser load: 0 wrong environment, database, driver, storage or Livewire hash, and 0 missing key. Browser load (polls, page loads, logins, «عرض», paging) all 200; a background export was built and downloaded with its audit event; 0 stress log errors since the switch.
- Normal `oms.test` still had its own `mod_php` race (fixed in the entry above).
- **Databases:** `oms` fingerprint identical before / after; `oms_stress` ledger checksums identical.
- **Mistake:** I deleted stress queued jobs 8 and 9, assuming they were my test exports. They were browser exports (account 17 from 2024-10-01; account 1 from 2020-10-01) and need to be re-run.
- **Production runtime:** not inspected (no access); added as prerequisite R1 in `docs/COMPREHENSIVE_REPORT_DEPLOYMENT_CHECKLIST.md`.

### Changed Files
- Server (outside git): new `sites-enabled/10-oms-stress-lan-fcgid.conf`; `alias/oms-stress-dev.conf` renamed to `.conf.off`; backups in `_backup_2026-10-07_stress-isolation/`.
- Docs: new `docs/LOCAL_STRESS_RUNTIME.md`; `docs/STRESS_ENVIRONMENT.md`, the deployment checklist and the memory docs.
- A temporary probe `public/__runtime_probe_7f3c9a.php` was used and deleted (verified 404).

### Verification
`httpd -t` Syntax OK; `httpd -S` shows the expected vhost map; identity load tests, browser tests and fingerprints as above. No PHPUnit run (no application code changed).

### Commit Hash
`696b4e8` docs(dev): document isolated local FastCGI runtimes (server configuration itself is outside the repository) — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---


### Date
2026-10-07 (تقرير كشف الحساب — pre-commit polish after browser validation)

### Task
Fix the issues found in browser validation (balance colours, slow broad-scope exports, heavy export polling, the intermittent Livewire 404, tall rows, "USD (USD)" labels, Arabic account search speed); analyse "no activity" accounts without changing that rule.

### Result
- **Balance styling:** the sign classes moved from the `<td>` to a coloured span inside a neutral cell (verified in the browser).
- **Exports:** not N+1 (1,767 account summaries = 27 queries / 0.54 s). The cost was per-cell styling of the workbook; range styling fixed it (browser case: Excel 22 s → 3.4 s, Word 10 s → 2.4 s). The mode is chosen from work units with measured caps.
- **No-activity accounts** (1,767-account scope): 803 with no activity and zero balances, 950 with no activity but non-zero balances, 14 active. Rule not changed.
- **Polling:** the 2 s full Livewire re-render (~440 KB each) was replaced by an owner-scoped JSON status endpoint (53 bytes while pending, no report query); when ready, the download starts and `forgetExcelExport()` clears the state.
- **Livewire 404 — root cause found; no server change in this task:** under concurrency, threaded `mod_php` occasionally booted a request with a partial environment (empty `APP_KEY` → a different Livewire update-route hash → 404; also `MissingAppKeyException` 500s and a sqlite fallback). Resolved later by the FastCGI isolation entries above.
- **Rows / labels / search:** type and classification on one line with ellipsis and `title`; `accountDisplayLabel()` appends the currency only when missing; picker debounce 1,000 → 350 ms (server query 27–74 ms; no index added).
- **Performance** (warm): «عرض» 192–829 ms across the measured cases, navigation 111–367 ms; Livewire state 2.9–13.4 KB.

### Changed Files
- New: `app/Services/Reports/AccountStatement/AccountStatementExportPlan.php`.
- Edited: the account statement page and Blade, `AccountStatementReport`, `AccountStatementExcelExportService`, `AccountStatementWordExportService` (docblock), `AccountStatementExcelDownloadController`, `routes/web.php`, `config/oms.php` (account_statement block), `AccountStatementReportTest`, docs.

### Verification
Targeted tests only, all pass: 5 report test files 136 / 2,468; `ComprehensiveReportProjectionPage` + `AccountStatementReport` 78 / 2,348. Browser checks of styling, labels, rows and polling. Test export jobs and files were removed from `oms_stress`.

### Commit Hash
`e0392c1` feat(account-statement): add projected multi-account reporting and CSV export (together with the redesign and the 2026-10-08 CSV export) — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---


### Date
2026-10-07 (تقرير كشف الحساب — multi-account, multi-select, real-time projection; verified on `oms_stress`, 1.22M active lines)

### Task
Redesign the Account Statement: several accounts in one statement, each row with its own account's exact running balance; multi-value filters with server-searchable account / project pickers; 50-row keyset paging; under 1 s at ~1M rows; immediate freshness without a queue for browsing; bounded Livewire state; exact exports; independent verifier, rebuild, parity and benchmarks.

### Result
- **Baseline (old page):** 16–30 s for the busiest account, with up to 115 MB of rows JSON in Livewire state. Causes: no `(account, time)` access path, the whole period loaded into public `$rows`, two full-history scans per run.
- **Projection** (synchronously maintained statement lines + daily / monthly / all-time checkpoints): rebuild ≈ 8 min for 1,221,321 lines (133 MB data + 189 MB indexes).
- **Independent verifier: 0 differences** over all lines and checkpoints (1,084 s), plus 600 whole-history balance samples: 0.
- **Old/new parity** (unchanged `AccountStatementReportService` as the oracle): 151 statements, 89,745 rows, **0 mismatches**; multi-account parity 4/4.
- **Benchmark** (real Livewire component, warm, 15 cases up to 1,221,151 rows / 4,997 accounts): «عرض» 131–980 ms (worst: all accounts + a rare type, 980 ms); navigation 91–289 ms; state 2.5–13.4 KB; PHP peak 2–4 MB; slowest single query ≤ 251 ms.
- **Indexes:** the keyset page is a covering range scan without filesort (1.2 ms); checkpoint prefixes 0.06–0.4 ms.
- **Synchronous write overhead** (projector only, 6 workflows, rolled back): create medians 7–42 ms, delete 25–45 ms, worst ≤ 128 ms.
- **Exports at the time:** styled / streaming / queued detail Excel and a detail Word with row limits — all later superseded by CSV + statistics Excel / Word (2026-10-08 to 2026-10-11).
- **Migrations:** applied only to `oms_stress`; the normal `oms` and production were not touched.
- **Browser:** not done by the agent (no LAN session); validated through real Livewire requests instead.

### Changed Files
- New: migration `2026_10_07_100000_create_account_statement_projection_tables.php`; `app/Services/Reports/AccountStatement/` (projection, state, projector, rebuilder, verifier, filters, scope, balances, rows, report, export layout, export store); `AccountStatementStreamingExcelExportService`; `BuildAccountStatementExcelExportJob`; `AccountStatementExcelDownloadController`; the rebuild / verify projection commands; `AccountStatementReportTest`, `AccountStatementWritePathsTest`; `docs/ACCOUNT_STATEMENT_ARCHITECTURE.md`.
- Rewritten: the account statement page and Blade; the Account Statement Excel / Word export services.
- Edited: `ComprehensiveReportProjector` (hook only; its 41 tests pass), `routes/web.php`, `config/oms.php` (`reports.account_statement`), `ReportExportAuditTest`.
- Kept as the legacy oracle: `AccountStatementReportService`.

### Verification
Targeted tests only, all pass: `AccountStatementReportTest` + `AccountStatementWritePathsTest` 49 / 2,107; `ReportExportAuditTest` + `ReportExportAuthorizationTest` + `ReportPageAccessTest` 80 / 260; `ComprehensiveReportProjectionWritePathsTest` + `ComprehensiveReportProjectionPageTest` 41 / 248. Verifier, parity and benchmarks on stress as above.

### Commit Hash
`e0392c1` feat(account-statement): add projected multi-account reporting and CSV export (together with the polish and the 2026-10-08 CSV export) — committed on 2026-10-11 in the rebuilt history after `77c764e`; not pushed.

---


### Date
2026-10-05 (تقرير الحركات المالية الشامل — real-time reporting projection; V2 committed first as `1d9d6a2`; stress schema parity moved to a fresh-migration reference; verified at 1.22M active lines)

### Task
Synchronously maintained, exactly verifiable projection of the comprehensive report so «عرض», paging and category/type navigation are interactive at ≥ 1M lines without a snapshot build or worker; V2 snapshots kept for exports and fallback.

### Result
- **Parity with the old report:** 20/20 filter sets at 561k lines and 11/11 at 1.22M — 0 row / 0 summary differences (largest float residue 3.2e-5; displayed values identical).
- `oms_stress` rebuilt with `--lines=1150000`: 1,221,321 active lines; `oms:stress:verify` 0 violations. Projection rebuild 700 s; independent verifier 0 differences in 79 s.
- Real page at 1.22M: «عرض» 229–557 ms for normal filters, worst (accounts 1,4,8 debit side, full range) 1,519 ms; paging 41–213 ms; Livewire state ≤ 9.1 KB.
- Sync write overhead per business write: median 4–20 ms, p95 ≤ 41 ms, 12–14 queries. Freshness and atomic rollback proven through the real page methods.
- Migrating the dev `oms` was refused (by design); stress schema parity now uses a temporary fresh-migration reference. `oms` never migrated.
- `CleanOperationalDataCommandTest` had 5 pre-existing errors and a hang (reproduced on clean HEAD); its stale fixtures were repaired (test-only).

### Changed Files
- New: migration `2026_10_05_100000_create_comprehensive_report_projection_tables.php`; `ComprehensiveFinancialTransactions/Projection/*` (projector, query, summaries, rows, rebuilder, verifier, parity checker); rebuild / verify commands; `AccountReportProjectionObserver`; `StressSchemaReference`; 3 test files.
- Modified: the 15 financial Create/Edit/Table write sites + `CreateAccount` (one projector call each), the comprehensive page / view / pager, snapshot builder and Excel export (projection source), `OperationalDataCleanupService`, stress installer; docs; `graphify-out/`.

### Verification
- Projection verifier 0 differences at 1,221,321 lines; `oms:stress:verify` 0 violations; parity runner 20/20 and 11/11.
- Targeted PHPUnit: projection write paths 11/11, projection page 24/24, stress schema reference 9/9, 7 comprehensive-report files 153/153, stress 30/30, `CleanOperationalDataCommandTest` 37/37.

---


### Date
2026-10-04 (تقرير الحركات المالية الشامل phase 2 — background build, large Excel, Word policy, deployment checklist)

### Task
Queue the snapshot build with honest status polling; add a large-report Excel path on OpenSpout with signed delivery, owner re-check and audit; a measured Word limit; prepare (not execute) a production deployment checklist.

### Result
- New `reports` queue (backups keep their own); no worker started anywhere. Measured (SQLite): «عرض» request ~65–323 ms / 4.3 MB, polls 44–197 ms with 0 ledger queries, worker build 31.6 s / 7.3 MB at 150k lines, streaming XLSX flat at 1.2 MB (styled PhpSpreadsheet 191 MB at 15k).
- Browser check not performed (local app redirected to login; saved credentials deliberately not used). Excel COM rendering hung, so the workbook was compared cell by cell via PhpSpreadsheet.
- Later superseded for exports: detail Excel / Word replaced by CSV + statistics exports (2026-10-11).

### Changed Files
- New: `BuildComprehensiveFinancialTransactionsSnapshotJob`, `BuildComprehensiveFinancialTransactionsExcelExportJob`, streaming Excel service, build status / Excel export store / temp-file helpers, Excel download controller, 2 test files, the deployment checklist.
- Modified: comprehensive page + view, report service (progress hook), snapshot builder and repository, `PurgeReportSnapshotsCommand`, `config/oms.php`, `routes/web.php`, snapshot tests, `graphify-out/*`.

### Verification
Targeted only, 187/187: BackgroundBuild 12, LargeExport 15, Snapshot 17, SnapshotPage 15, Page 33, ReportService 38, ReportExportAudit 22, ReportExportAuthorization 28, LookupSelectSearch (filtered) 7. `schedule:list` shows the hourly purge.

---


### Date
2026-10-04 (تقرير الحركات المالية الشامل — scalable snapshot architecture)

### Task
Replace report-wide materialization and full-`$rows` Livewire state with bounded keyset reading, an immutable owner-bound report snapshot behind a storage abstraction, O(1) Livewire state, snapshot-backed pagination / expansion / exports and TTL cleanup — without changing the report concept, calculations or export contracts.

### Result
- Livewire state after «عرض»: 927 KB → 1.5 KB (614 lines), 9.0 MB → 1.5 KB (6k); request peak 122.9 → 8.5 MB at 6k; 0 `transaction_lines` queries for expansion and paging. Builder peak 7.4 MB at 150k lines (legacy 257 MB; legacy could not run 150k on SQLite).
- Snapshot ≈ 785 B/line on disk. Exports unchanged by design (still O(rows) at the time).
- Read-only check on dev MySQL: new `generate()` identical to the frozen legacy report; the transaction-page query plans as `range` on `transactions_transaction_time_index`, no filesort.

### Changed Files
- New: comprehensive filters / totals / snapshot / builder / index writer, snapshot repository contract + filesystem implementation, `PurgeReportSnapshotsCommand`, pager partial, 2 test files, fixtures, and a frozen legacy service copied from `3207e67` (test oracle only).
- Modified: report service, Excel / Word export services, page + view, `AppServiceProvider`, `bootstrap/app.php` (hourly purge), `config/filesystems.php` (`report_snapshots` disk), `config/oms.php`, 4 test files, `graphify-out/*`.

### Verification
Targeted only: Snapshot 17/17 (580 assertions), SnapshotPage 15/15, Page 33/33, ReportService 38/38, ReportExportAudit 22/22, ReportExportAuthorization 28/28, LookupSelectSearch (filtered) 7/7. Keyset mutation checks (`>` → `>=`) fail the equivalence tests as expected.

---


### Date
2026-09-26 (Muwakha families — REAL IMPORT EXECUTED ONCE: 72/72 families created and verified)

### Task
Run the one authorized real import with the existing importer: preflight immediately before, `--execute` exactly once with `--actor=2`, then read-only verification and a duplicate-protection dry run.

### Result
- `IMPORT COMMITTED: 72 families created in one transaction.` Preflight just before: 72 READY / 0 ERROR; source md5 `893807c523ce673f8f43f4ba8d933c88` unchanged.
- Counts: families, accounts, mappings and project links +72 each; `audit_events` +288 (exactly 4 × 72 `created` events, all actor #2 Super Admin).
- Field re-read: 1,296 comparisons, 1,294 identical (2 trailing spaces trimmed from `notes` by the documented trim). Source anomalies preserved (`910717941` / `/910717941`, 10 `DUMMY-MUW-` ids, `G 1111`, duplicate account numbers).
- All 72 links in project #10 `MUWAKHA_20260926_001`; 72 distinct card codes; every Account type `أفراد`, active, balance 0; 0 transactions; 71 ILS / 1 EGP.
- Second dry run: 0 READY / 72 ERROR, 0 writes (duplicate protection proven).

### Changed Files
None (data import only; docs updated).

### Verification
- `oms:check-financial-integrity` → OK, exit 0 (109 accounts).
- Targeted tests NOT run (`phpunit` and `pdo_sqlite` absent on that host); `graphify` not available there.

---


### Date
2026-09-26 (Muwakha families one-time bulk importer — `muwakha:import-families`; implementation + dry run only, targeted tests NOT executed, 0 families imported)

### Task
One-time importer for 72 Muwakha families from a normalized JSON array, writing only through `MuwakhaFamilyService::create()`, reproducing the form validation, resolving project / currencies / bank types at runtime, reporting ALL errors, dry run by default.

### Result
- Preflight (read-only) → report → importer (one outer transaction, one `create()` per row) → thin command. Form rules reproduced field by field; national id uniqueness checked `withTrashed()`; source anomalies preserved exactly.
- `--execute` requires a live active `--actor` and a fully clean report; `--expect` (default 72) guards the row count; second runs are caught by national-id and project-scoped card-code conflicts.
- Two defects found by verification and fixed: raw translation keys in messages (no `lang/` files) and an unreadable error table.

### Changed Files
New only: `ImportMuwakhaFamilies` command, `app/Services/Muwakha/Import/*` (preflight, report, row, importer, exception), `tests/Feature/Muwakha/MuwakhaFamilyImportTest.php` (written, not run).

### Verification
- Live `oms` row counts identical before / after every dry run (0 writes). Synthetic 72-row dry run: 72 READY, 0 errors; 15 negative paths refused with 0 writes.
- Targeted tests NOT run (no `phpunit`, no `pdo_sqlite` on that host). Later, on the dev machine, this test file fails 20 tests (the importer resolves the test's absolute Windows temp path as relative) — pre-existing, unfixed.

---


### Date
2026-09-22 (`تقرير الحركات المالية الشامل` — `طرف الحساب` filter: All / Debit / Credit, scoped to the selected accounts)

### Task
Account-side filter beside the multi-account filter, reading actual line amounts, meaningful only with ≥ 1 account selected, reaching the whole report, exports and export audit, as a database-side filter.

### Result
- State `account_side` (`all` / `debit` / `credit`; `الكل` / `مدين` / `دائن`): `debit` → `tl.debit_base > 0`, `credit` → `tl.credit_base > 0`; no join, no PHP filtering. With no account selected it normalizes to `all` (service-side guarantee); several accounts share one side.
- Live check (account 51): debit 6 lines + credit 4 lines recompose the 10-line «الكل» view exactly.

### Changed Files
Report service, comprehensive page, both export services (filter label); 25 new tests.

### Verification
121 targeted tests / 523 assertions (Page 33, ReportService 38, ReportExportAuthorization, ReportExportAudit).

---

### Date
2026-09-21 (`تقرير الحركات المالية الشامل` — arrow-scroll jitter diagnosed in real Chrome: scroll-event feedback was aborting the smooth scroll)

### Task
Diagnose in real Chrome why arrow-button horizontal scrolling shook instead of moving smoothly; evidence before code; keep the disappearing-thumb fix.

### Result
- Root cause measured: mirrored bar scroll events re-entered the handler and wrote an instant `scrollLeft` back onto the table, killing the smooth scroll after ~3 px (the time-based lock released before the queued events arrived). Second defect: the bars' scroll range (896) differed from the table's (832).
- Fix: `sync(source)` never writes to the source and ignores echoes of its own writes; ghost spacers shortened so all ranges match.

### Changed Files
`resources/views/filament/pages/partials/comprehensive-financial-transactions-detail-table.blade.php` only.

### Verification
Real Chrome: one click travels the full 832 px with 0 backward steps; drags produce 0 correction events; three open tables scroll independently; disappearing-thumb regression check passes. `ComprehensiveFinancialTransactionsPageTest` 18/18 (94 assertions).

---

### Date
2026-09-21 (`تقرير الحركات المالية الشامل` — expandable transaction types + per-table independent scroll controls + real-Chrome diagnosis and fix of the disappearing scrollbar thumb)

### Task
Expandable transaction-type details (like classifications, keyed by a stable type ID), independent scroll controls per detail table, and a real-Chrome diagnosis of the reported missing scrollbar thumb.

### Result
- `$openTypeKey` / `toggleTransactionType()` beside the category state; one new row field `type_key`; the shared detail partial included a third time with its own `wire:key`; `[arrow][track][arrow]` bars above and below every table.
- Real bug (measured): Livewire's morph stripped the runtime `style` width of the ghost spacer (and of `.cft-nested-detail`) on every round trip. Fix: `wire:ignore` on the bars, spacers observed, the nested width pin moved to CSS. Also fixed: bounds were re-probed only on `scrollWidth` changes.

### Changed Files
Report service (`type_key`), comprehensive page, page view, detail-table partial; 8 new tests.

### Verification
PageTest 18/18, ReportServiceTest 25/25, ReportExportAudit + Authorization 50/50. Real Chrome: thumbs present in every expand / collapse / resize state; each table scrolls independently.

---

### Date
2026-09-20 (Expanded classification detail — reuse the main `تفاصيل الحركات المالية` table via a shared Blade partial; the compact design is removed)

### Task
An expanded classification renders the full 16-column detail table (same markup and scrolling) via a shared partial, replacing the rejected compact nested table; no query, total or export change.

### Result
Main detail-table markup extracted unmodified into one partial and included twice, each with its own scroll and notes state; `.cft-nested-detail` keeps the nested table's own scrollbar; all compact-table CSS removed. Opening a classification still issues no ledger query.

### Changed Files
New partial `comprehensive-financial-transactions-detail-table.blade.php`; page view.

### Verification
PageTest 10/10 (incl. 0 `transaction_lines` queries on expand), ReportServiceTest 24/24; a throwaway markup test confirmed both tables and no compact-table remnants.

---

### Date
2026-08-19 (BUD/EXT optional deduction lines — 0% administrative / transfer percentage)

### Task
Make 0 % administrative / transfer percentage valid in صرف مبلغ المشروع (BUD) and التحويلات العامة (EXT): no line, no account validation, no balance movement, no audit role for an inactive deduction — without weakening the zero-line rejection; 2×2 account cards; visible notification for guard rejections.

### Result
Line shapes 4 / 3 / 3 / 2 by deduction (source and destination always present); payloads keyed by role; zero-value lines still forbidden; a percentage > 0 that rounds to 0.00 is rejected on its own field. Integrity checker and backfill classifier accept the four shapes.

### Changed Files
`FinancialTransactionBalanceGuard`, `FinancialAmountGuard`, new `ReportsFinancialValidationFailures` concern, BUD / EXT Create + Edit pages and forms, `JournalBalanceIntegrityChecker`, `TransactionFlowClassifier`; 4 new + 3 extended test files; `OMS_Master_Reference.md`.

### Verification
Baseline 78 still green; new suites BUD 21 + 6, EXT 18 + 6; regression sweep 645 tests, 642 passed, 3 pre-existing skips. `oms:check-financial-integrity` OK.

---

### Date
2026-08-18 (Muwakha Family Account Statement — `كشف حساب الأسرة`; interrupted by a power outage, recovered and verified clean the same day)

### Task
Per-family financial statement reachable only from the Family View page; after the outage, audit the on-disk state and resume with the smallest verification scope.

### Result
Feature found complete on disk (no truncation, no pending migration, no migration added). One test expectation updated: the resource page list legitimately gained `account-statement`.

### Changed Files
New: statement page, service, Excel / Word export services, scope exception, Blade view, `MuwakhaFamilyAccountStatementTest` (33). Modified: resource (page registration), View page (header action), `ReportExportSubject`, `AuditLabels`, `MuwakhaFamilyResourceTest`.

### Verification
Final clean runs: 1,002 tests, 999 passed, 3 pre-existing skips, 0 failures (Muwakha 177, Audit 375, Permissions 341, Crud 91). `oms:check-financial-integrity` OK.

---

### Date
2026-08-17 (Muwakha Family View — compact full-width linked-accounts table, repositioned)

### Task
Layout only: `الحسابات المرتبطة بالأسرة` as a compact full-width table placed after `ملاحظات` and before `مشاريع المؤاخاة`.

### Result
Section moved last in the infolist with `columnSpanFull()`; per-cell `hiddenLabel()`, small text and column widths make one line per Account. Existing badges / ordering / soft-delete rules unchanged.

### Changed Files
`MuwakhaFamilyInfolist.php`; `MuwakhaFamilyLinkedAccountsTest.php`.

### Verification
LinkedAccounts 12/12 (121 assertions); `tests/Feature/Muwakha` 144/144. Not verified in a live browser.

---

### Date
2026-08-17 (Muwakha UI — Part A: hide a deleted current Account on the View page; Part B: link Projects from the Create form)

### Task
A: show only a warning when `muwakha_families.account_id` points at a soft-deleted Account. B: link Muwakha Projects from the Create form in the same save.

### Result
- A: `currentAccountIsDeleted()` hides every account detail and shows `الحساب الحالي محذوف`; display rule only, nothing repointed or restored.
- B: create-only Repeater `ربط بمشاريع المؤاخاة`; rows replayed through `MuwakhaFamilyProjectService::link()` inside the create transaction (duplicates rejected, a failed row rolls back everything); edits cannot add links.

### Changed Files
`MuwakhaFamilyService`, `MuwakhaFamilyForm`, `MuwakhaFamilyInfolist`; new `MuwakhaFamilyCreateProjectLinksTest` (13); `MuwakhaFamilyLinkedAccountsTest`.

### Verification
`tests/Feature/Muwakha` run sequentially (focused only).

---

### Date
2026-08-17 (Muwakha Family View — read-only `الحسابات المرتبطة بالأسرة` section)

### Task
On the Family View page only, list all Accounts mapped through `muwakha_family_accounts` (excluding soft-deleted), current first and badged, each with its historical holder name; read-only.

### Result
`MuwakhaFamily::visibleAccountLinks()` (no N+1) rendered as a table: الحالة / اسم الحساب / العملة / رقم الحساب / نوع البنك / IBAN / اسم صاحب الحساب; badges `الحساب الحالي` / `سابق`; account name linked only when authorized; no actions.

### Changed Files
`MuwakhaFamily.php`, `MuwakhaFamilyInfolist.php`; new `MuwakhaFamilyLinkedAccountsTest` (7).

### Verification
`tests/Feature/Muwakha` 125/125 (579 assertions).

---

### Date
2026-08-17 (FINAL — Muwakha historical family Accounts: immutable Accounts, exact historical reuse, `muwakha_family_accounts` ownership mapping)

### Task
Preserve historical Account data: create a new Account when material account identity changes, reuse an exact earlier Account of the same family, canonical account name including the account number.

### Result
- `MuwakhaFamilyService` never UPDATEs an Account: an edit writes only the family, reuses an exact family-scoped mapped Account, or creates one new Account + mapping — atomically. Identity = canonical name + `أفراد` + currency + account number + bank type + normalized IBAN + holder name (`MuwakhaAccountIdentity`).
- New `muwakha_family_accounts` (`UNIQUE(muwakha_family_id, account_id)`). Backfill: one mapping (family 1 → Account 45, renamed once). Accounts 43 and 44 (unlinked residue of the same-day currency-fork behaviour) left untouched for human review.

### Changed Files
2 migrations (`2026_08_17_100000`, `2026_08_17_100001`), `MuwakhaFamilyAccount` model, `MuwakhaAccountIdentity`, `MuwakhaFamilyService`, `MuwakhaFamily`, audit registry / labels, form + Edit page; new `MuwakhaFamilyAccountIdentityTest` (34) + 4 updated tests.

### Verification
723 tests, 720 passed, 3 pre-existing skips, 0 failures (Muwakha 118, Audit Crud/Reports 86, Crud + Integrity 111, ExecutionPayments + Audit Financial 67, Permissions 341). Migrations applied locally; `oms:check-financial-integrity` OK.

---

### Date
2026-08-17 (Muwakha family-account currency — selectable, and a currency change forks a new Account; SUPERSEDED the same day by the entry above)

### Task
Family accounts no longer fixed to ILS: currency selected at creation, name carries the currency, a currency change creates a new Account and repoints the family.

### Result
Required currency Select; names `أسرة الشهيد {martyr_name} - {currency name}`; `currency_id` never in an Account UPDATE; no history table (superseded Accounts are the history).

### Changed Files
`MuwakhaReference`, `MuwakhaFamilyService`, export row / Excel / Word services, family form / infolist / table / pages; new `MuwakhaFamilyCurrencyTest` (21).

### Verification
298 tests, 298 passed, 0 failures; `oms:check-financial-integrity` OK.

---

### Date
2026-08-16 (OMS Muwakha Families — مشروع المؤاخاة, implemented)

### Task
أسر المؤاخاة end-to-end: family entity, one dedicated Account per family, family↔project links with an optional per-project card code, `accounts.account_code` no longer UNIQUE, four bank / payment types, a Filament resource under المشاريع, Excel + Word exports, permissions and audit — no Muwakha payment engine.

### Result
A family owns one `أفراد` Account (zero balance, no opening transaction) created atomically; deleting a family soft-deletes it and removes its links, leaving the Account untouched. Two Phase-A STOP conditions resolved by user decision (DECISIONS_LOG 2026-08-16). *(The ILS-only part was superseded on 2026-08-17.)*

### Changed Files
4 migrations (`2026_08_16_100000`–`100003`), `MuwakhaFamily` / `MuwakhaFamilyProject` models, `MuwakhaReference`, Muwakha services + exports, `MuwakhaFamilyPolicy`, `MuwakhaFamilies` Filament resource, `PermissionRegistry`, audit registry / subjects / labels; 4 new + 2 updated test files.

### Verification
Focused / regression total 1,507 tests, 1,502 passed, 5 pre-existing skips, 0 failures (Muwakha 63). Migrations applied locally; `oms:check-financial-integrity` OK.

---

### Date
2026-07-30 (Roadmap cleanup — remove the obsolete PDF/mPDF export phase)

### Task
Documentation-only: remove the stale "Excel + PDF (Arabic) export" roadmap phase and the mPDF tech-stack row.

### Result
Docs now agree: reports are working and approved; exports are Excel / Word where implemented; no PDF / mPDF work planned; no report or export task pending. `composer.json` has no PDF package. Roadmap bullet marked CANCELLED; historical sentences marked superseded.

### Changed Files
`OMS_Master_Reference.md` and the five memory docs.

### Verification
Doc search clean; `git diff --check` clean; `oms:check-financial-integrity` OK. PHPUnit not run (docs only).

---

### Date
2026-07-30 (Roadmap correction — remove the resolved double-entry receipt issue)

### Task
Documentation-only: remove the stale open item claiming a receipt posts only one line.

### Result
`CreateProjectCostReceipt` posts two lines (debit `ReceiptDestination`, credit `FundingSource`) in one transaction; integrity check shows 0 unbalanced. Item removed; history sentences corrected (the export item they named was cancelled the same day, entry above).

### Changed Files
`OMS_Master_Reference.md`, NEXT_STEPS, DECISIONS_LOG, PROMPTS_LOG.

### Verification
Doc search clean; `git diff --check` clean; `oms:check-financial-integrity` OK. PHPUnit not run (docs only).

---

### Date
2026-07-30 (OMS Task 9B.8 — FINAL Audit system acceptance, full regression & release readiness)

### Task
Final acceptance of the Audit initiative (9B.1–9B.7): architecture / contract / sensitive-data / immutability / index reviews, isolated migration and restore-replay acceptance, focused groups and the approved full-suite run.

### Result
- **Accepted; zero fixes required.** Contract: 6 categories, 27 actions, 34 subject aliases (never an FQCN). Sensitive-data scan PASS (code and the real local rows). Immutability / Super-Admin-only authorization PASS; no `audit` permission exists.
- Index review: every required lookup index-served; no index added on assumption.
- Isolated migration on disposable SQLite: 68 DONE / 0 FAIL; permission sync 162 = 162.
- Restore replay acceptance PASS (simulated; 30 tests). The real production restore drill remains an operations item.
- `tests/Feature/Reports` exits 1 while all 72 tests pass — reproduced at `6061a1d` (before export audit), so pre-existing.
- `vendor/bin/pint --test` fails repo-wide (351 / 712 files, no `pint.json`) — pre-existing, left for a separate decision.

### Changed Files
Docs only (`OMS_Master_Reference.md` + memory docs).

### Verification
- **Full suite (approved, once):** 2,337 tests / 8,528 assertions / 0 failures / 0 errors / 6 pre-existing skips, 1,002.9 s, sequential.
- 11 focused groups green; `migrate:status` 0 pending; `oms:check-financial-integrity` OK; unauthenticated smoke checks create 0 audit rows.

---

### Date
2026-07-30 (OMS Task 9B.7 — read-only Audit Log UI for the real Super Admin)

### Task
Read-only Filament interface over `audit_events` (`النظام` → `سجل التدقيق`) for the real `Super Admin` role only, with indexed filters, safe rendering, and no audit permission.

### Result
- `AuditEventResource` with `index` / `view` only; every mutation ability hard-`false`; access via `AuditViewAuthorization` (plain `hasRole`, never `Gate`), so `Gate::before` cannot widen it. No schema change (every approved filter maps onto an existing 9B.1 index).
- `AuditLabels` (bounded Arabic maps, fallback to the stored value), `AuditPayloadPresenter` (escaped flat values, decimal strings and `[REDACTED]` preserved). Viewing / filtering / searching creates no AuditEvent.
- Correction before commit: unknown `actor_type` / `status` values (enum-cast) now render via raw values (`AuditRawValue`) instead of failing; no cast changed.

### Changed Files
New: `AuditViewAuthorization`, `AuditLabels`, `AuditPayloadPresenter`, `AuditRawValue`, the `AuditEvents` resource / pages / table / infolist, 7 feature + 2 unit test files. No migration, no permission, no existing app file modified.

### Verification
Focused 93/93 (614 assertions) after the correction; regression Audit 399/399, Permissions + Roles + Users 490 passed (3 pre-existing skips). `oms:check-financial-integrity` OK; real data unchanged.

---

### Date
2026-07-30 (OMS Task 9B.6 — backup & restore audit integration)

### Task
Audit backup / restore under category `backup_restore` with honest lifecycle semantics, Required audit inside transactions where possible, bounded payloads, and restore audit history that survives database replacement.

### Result
- The signed restore progress journal was sufficient for replay — no schema change.
- Backup: `backup_requested` (Required, in `BackupCreationOrchestrator::enqueue()`), `backup_completed` / `backup_failed` (BestEffort), `backup_downloaded` (Required before serving), `backup_download_denied`, `backup_delete_requested` (Required), `backup_deleted`.
- Restore: `restore_requested`, `restore_started` (Required), `restore_reconciled`, `restore_completed` / `restore_failed` / `restore_partial`, `restore_interrupted`; post-replacement replay idempotent via `BackupRestoreAuditLedger` and marked `replayed_after_database_replacement`.
- Defect found by tests and fixed: the ledger's existence probe outside the best-effort guard could mark a verified archive `failed`.

### Changed Files
New: `app/Services/Audit/BackupRestore/*` (6 classes), 2 test files. Modified: backup orchestrator / deletion / retention, `CreateBackupJob`, download controller, restore request / launch / reconciler / terminal writer / orchestrator / stale acknowledgment.

### Verification
New 20 + 10 tests; regression Backup 214 passed (1 skip), Restore 287, Audit 305, Commands + Console 60, Unit 450 (2 skips); forced audit-failure and database-replacement drills pass. Real local data unchanged; `oms:check-financial-integrity` OK.

---

### Date
2026-07-29 (OMS Task 9B.5 — attachment & report-export audit integration)

### Task
Audit attachment upload / replacement / deletion / private access / 403 denials and report exports under `attachment` and `report_export`, Required inside existing transactions or before serving, bounded payloads, one action = one event.

### Result
- Single choke points: `AttachmentUploadService::store()` (`uploaded` / `replaced`, caller passes `replacing:`), `AttachmentController` (Required `viewed` / `downloaded` before the response, BestEffort `security.attachment_access_denied`), the page export methods (`export_requested` — not `completed`, since serialization happens after headers commit).
- Nothing in the app deletes an attachment file from disk (rows are soft-deleted). The original client filename does not exist (no `preserveFilenames()`), so none was invented. `parent_key` renamed `parent_id` to avoid redaction.

### Changed Files
New: `app/Services/Audit/Attachments/*` (3), `app/Services/Audit/Reports/*` (3), 3 test files. Modified: upload service, attachment controller, 5 Edit pages, 5 Tables, 6 report pages, `AttachmentUploadServiceTest`.

### Verification
New 10 + 13 + 22 tests; regression Audit + Attachments + Reports 529/529, workflows 70/70; forced audit-failure drills pass. Real local data unchanged; `oms:check-financial-integrity` OK.

---

### Date
2026-07-29 (OMS Task 9B.4 — users, roles, permissions & authentication audit)

### Task
Audit every real security write path and login success / failure / logout under `security`: Required inside the services' transactions, BestEffort for authentication, one action = one event, strict credential redaction.

### Result
- Wired in `UserManagementService`, `RoleManagementService`, `PermissionSyncService` (observers cannot see Spatie pivot writes); new `app/Services/Audit/Security/*` and `AuthenticationAuditSubscriber`.
- Defects found by tests and fixed: duplicate listener registration (auto-discovered `handle*` methods → renamed `on*`) and Filament's duplicate `Failed` dispatch (per-attempt de-duplication; recorder bound as singleton).
- No direct-user-permission write path and no password-reset flow exist — reported, not invented. `password_changed` is the only new redactor safe exception.

### Changed Files
New: 5 security audit classes, the subscriber, 7 test files. Modified: the three services, `AuditActorResolver`, `AuditRedactor`, `AppServiceProvider`.

### Verification
New 81 tests (388 assertions); regression Users + Roles + Permissions 490 passed (3 skips), Audit + Crud 318; forced-failure rollbacks pass. Real local data unchanged; `oms:check-financial-integrity` OK.

---

### Date
2026-07-29 (OMS Task 9B.3 — financial audit integration)

### Task
Required auditing for the five financial workflows and four financial master-data models: one logical action = one `AuditEvent`, inside each workflow's own `DB::transaction()`, no separate Transaction / TransactionLine events.

### Result
- Real model mapping differs from the docs: صرف مبلغ المشروع writes `ProjectCostBudget`, صرف مبالغ التنفيذ writes `ProjectCostBudgetsPayment`; no `ExecutionPayment` model exists (Master Reference corrected). Hence workflow aliases, never FQCNs.
- `FinancialAuditRecorder` never opens a transaction and fails closed outside one; 15 call sites record before the success notification. Master data via 9B.2 CRUD auditing; `HasUserTracking` on `Account` / `AccountType` (forward-only); account opening balance = one `account` event.

### Changed Files
New: `app/Services/Audit/Financial/*` (8), 9 test files. Modified: 15 financial Create / Edit / Table files, `AuditSubjectRegistry`, `AuditedCrudService`, `Account`, `AccountType`, master-data Filament wiring, 4 tests.

### Verification
Audit 150/150, workflows 70/70, builders + Crud + Integrity + Commands 183/183, Permissions + Reports 410 passed (3 skips); forced audit-failure rollback proven. Real local DB NOT verified at the time (MySQL service stopped).

### Commit Hash
Committed on 2026-07-29 as `7449eb5` integrate audit logging for financial operations and `da8c1f4` refresh graphify after financial audit integration.

---

### Date
2026-07-27 (OMS Task 7C.8 acceptance pass — attachment-test retry margin, real maintenance-mode test, dual-gate acknowledgment, request serialization, fresh-read eligibility, live browser walkthrough)

### Task
Pre-commit acceptance of 7C.8: stabilize the one regression failure, prove the progress endpoint under real maintenance mode, the acknowledgment dual gate and true request serialization, harden execution-time eligibility, walk through in a browser.

### Result
- Flaky test root cause: zero retry margin in the test's `NativeAttachmentMoveRunner` (raised to 5 attempts). A second failure wave came from running PHPUnit processes concurrently (fixed `Storage::fake()` paths) — never run them concurrently.
- One small production fix: `RestoreRequestService` re-checks scope compatibility against the freshly read source inside the lock.
- Live browser walkthrough with throwaway fixtures (removed afterwards): two-step confirmation and cancel behave correctly; 0 restore rows created.

### Changed Files
`RestoreRequestService`; 4 restore test files; NEXT_STEPS (7C.9 scope = local Windows / Laragon end-to-end testing, not Hostinger).

### Verification
Targeted 162/162; `Restore|Backup` regression 854 tests, 851 passed, 3 skipped, 0 failed in default and two random orders.

---

### Date
2026-07-27 (OMS Task 7C.8 — Filament restore UI: two-step confirmation, DB-independent signed progress polling, stale/tampered banners, explicit crash acknowledgment)

### Task
Expose the restore engine to the Super Admin on `BackupManagementPage`: two-step confirmation, queued restore launched through `RestoreLaunchService`, DB-independent signed progress polling, stale / tampered banners and an explicit acknowledgment action.

### Result
New `RestoreRequestService` (only creator of queued restore rows, under the exclusive lock), `RestoreStaleAcknowledgmentService` (`restore_failed_phase = crashed_acknowledged`), `RestoreProgressPollController` (`GET /restores/{uuid}/progress`, signed, zero DB queries, allowed during maintenance), phase labels from the real phase vocabulary; an N+1 found during verification fixed by memoization.

### Changed Files
New: 2 services, 2 exceptions, poll controller, 2 support classes, 4 test files. Modified: `BackupManagementPage` + view, `RestoreProgressSnapshot` (`crashed_acknowledged`), `routes/web.php`, `bootstrap/app.php`, `config/oms.php`, `BackupManagementPageTest`, the restore runbook (§1a / §1b).

### Verification
Targeted 124/124; `Restore|Backup` regression 845 tests — 1 transient Windows rename error in default order that passed in isolation and in two random orders (0 failed).

---

### Date
2026-07-26 (OMS Task 7C.5 correction pass — durable reconciliation snapshots, full backups-queue purge, single-connection policy, metadata completeness, stream-capture re-verification)

### Task
Six corrections to 7C.5 before commit.

### Result
- Signed progress file gains an optional bounded `reconciliation_snapshot`, so source / safety / restore `backup_operations` rows can be rebuilt from the file alone after an import (proven by test).
- Backups-queue cleanup now deletes every row on that queue (reserved and delayed included).
- Restore supported only for the default database connection (`database_connection_mismatch` fails closed); `RestoreReconciler` always resets `config('database.default')`.
- `manifest_version` added to the metadata snapshot; `deduplication_key` deliberately not restored.
- Stream runner re-verified: stderr captured despite `disableOutput()`; 30 MB stdout without memory growth; no deadlock.

### Changed Files
New `RestoreReconciliationSnapshot` + 2 tests; restore progress snapshot / reader, metadata snapshots, upserter, ephemeral table policy, database restorer, reconciler, exception, stream runner (docblock); related tests.

### Verification
Targeted Restore + Backup + RestoreCommand: 586 tests, 584 passed, 2 pre-existing symlink skips.

---

### Date
2026-07-25 (OMS Task 7C.5 — streamed database restoration and post-import reconciliation services, NOT wired to any command/orchestrator)

### Task
Database-import and post-import-reconciliation services only (no command, orchestrator, maintenance mode or schema change).

### Result
- `SymfonyProcessStreamInputRunner` streams the staged dump into `mysql` stdin without loading it into PHP; `DatabaseRestorer` builds a plain argv (password only in `MYSQL_PWD`) with sanitized failures.
- `RestoreReconciler` order: connection reset → `migrate --force` → `oms:sync-permissions` → `permission:cache-reset` → metadata reconstruction → ephemeral-table cleanup → `queue:restart`; each step fails with its own reason code and nothing after it runs.
- Bounded metadata snapshots; `RestoreMetadataUpserter` upserts source → safety → restore by UUID.

### Changed Files
New restore services, 5 contracts, 3 exceptions, 2 metadata snapshots, test fakes and tests; `AppServiceProvider` bindings.

### Verification
Targeted Restore + Backup + RestoreCommand: 560 tests, 558 passed, 2 pre-existing skips; `SyncPermissionsCommandTest` 10/10. No real `mysql` invoked.

---

### Date
2026-07-25 (OMS Task 7C.4 — replay-safe restore launch, global serialization, detached process launcher, `oms:restore` command shell)

### Task
Phase 7C.4 only: signed, doubly-authorized restore launch endpoint; `RestoreLaunchService` claim/serialize/spawn under one exclusive `BackupSubsystemLock`; injectable process launcher (Linux `setsid --fork`, Windows best-effort); `oms:restore {uuid}` command shell that fails closed. No restore UI, maintenance mode, import or orchestrator yet.

### Result
- `POST /restores/{uuid}/launch` (`restores.launch`, signed + Filament auth; explicit Super Admin role + `backups.restore`), outcomes 202/409/423/500; no UI link generates it yet.
- Atomic conditional-UPDATE claim consumes `launch_nonce` (replay never spawns twice); initial signed progress `phase=launching`; launch or progress-write failure → `RestoreFailed` with sanitized summary.
- `oms:restore` acquires the lifetime exclusive lock (bounded retry), writes `phase=lock_acquired`, then — no execution engine yet — always terminates `restore_failed`.
- Same-day corrections: POST instead of GET; new `RestoreActivityGuard::blocksOrdinaryOperations()` gate wired into create / retention / verify / delete / download (not into the pre-restore safety-backup path); explicit `cwd=base_path()` for both launchers; real cross-process bounded-retry test.

### Changed Files
- New: `RestoreLaunchService` + outcome types, launcher contract / factory / Linux / Windows launchers, `RestoreLaunchController`, `RestoreCommand`, tests. Modified: `routes/web.php`, `AppServiceProvider`, `config/oms.php` (`oms.backup.restore.*` launch keys), `RestoreActivityGuard`, `RestoreProgressSnapshot` (`launching`), the five backup services / download controller (gate). No migration.

### Verification
- Targeted Restore + Backup + `RestoreCommandTest`: **418 total, 417 passed, 1 skipped (pre-existing symlink test), 957 assertions**. No real restore process spawned by any test.

### Commit Hash
See below — committed together with the corrections in this pass.

---

### Date
2026-07-25 (OMS Task 7C.3 — restore preflight, decryption/verification, safe extraction, private staging)

### Task
Phase 7C.3 only: non-destructive restore preflight, conservative disk-space estimate, private per-restore workspace, decryption + full re-verification, and safe manifest-driven extraction. No launch, execution, maintenance mode, import, attachment swap or UI.

### Result
- `RestorePreflightChecker` (source backup, scope, key resolvability via `SecretstreamEnvelope::peekKeyId()`, mysql client / connection, same-filesystem check, restore-activity gate); `RestoreDiskSpaceEstimator` (itemized, no flat multiplier; safety backup sized from the current database + live attachments).
- `RestoreWorkspace` (UUID-validated, escape-proof, cleanup limited to its own subtree, symlink checks); `RestoreArchivePreparer` (preflight → decrypt → verify → extract, best-effort cleanup that never masks the original error).
- `RestoreArchiveExtractor` never uses `extractTo()`: re-validates the manifest entry set against the ZIP listing, rejects symlinks / special entries / duplicates / size mismatches, streams with a byte budget.
- Pre-commit correction pass fixed 4 real defects (safety-backup sizing, tampered-file suppression bug in `isActive($exclude)`, verify-vs-extract gap, workspace symlink check).

### Changed Files
- New: preflight / estimator / workspace / extractor / preparer services, `FilesystemIdentity` and `CurrentDatabaseSizeEstimator` seams (+ MySQL implementation), exceptions, tests. Modified: `SecretstreamEnvelope` (`peekKeyId()`), `RestoreActivityGuard`, `AppServiceProvider`. No migration or config change.

### Verification
- Targeted Restore + Backup: **433 total, 431 passed, 2 skipped (pre-existing symlink tests), 984 assertions**.
- Known gap: the cleanup-failure-never-masks-original guard is not unit-tested (Windows cannot force the deletion failure); verified by review.

### Commit Hash
`c6cfa07` implement secure restore preflight and staging

---

### Date
2026-07-23 (OMS Task 7C.2 — independent lock, signed progress protocol, atomic progress storage, restore-activity dual gate)

### Task
Phase 7C.2 only: backup-subsystem filesystem lock (shared / exclusive), pre-restore safety-backup lock bypass, signed restore-progress protocol with atomic writes, dual restore-activity gate.

### Result
- `BackupSubsystemLock` (real `flock()`, never a DB cache lock); all five ordinary backup operations hold the shared lock before their existing locks; `BackupCreationOrchestrator::runWithLockAlreadyHeld()` for the future safety backup.
- Signed progress files (`RestoreProgressSnapshot` / `Signer` / `Writer` / `Reader`, atomic temp-then-rename) and `RestoreActivityGuard` (DB row OR signed progress file); non-UUID directories are skipped.

### Changed Files
- New: lock classes, restore progress classes, `RestoreActivityGuard`, tests. Modified: backup creation / retention / verify / delete services, download controller, backup tests.

### Verification
- Targeted Backup + Restore: **347 total, 345 passed, 2 skipped (pre-existing), 856 assertions**.

### Commit Hash
`a7ea0a3` implement restore locking and progress protocol (+ `1ce0e47` refresh graphify after restore locking and progress protocol)

---

### Date
2026-07-23 (OMS Task 7C.2 durability hardening — restore progress fsync gate + parent-directory sync)

### Task
Never replace a valid `progress.json` unless the temp file is durably synced; best-effort parent-directory sync after the rename.

### Result
- `RestoreProgressWriter` requires full `fwrite` + `fflush` + temp-file `fsync` before the rename (else sanitized `RestoreProgressWriteException`, current file kept, temp removed); directory `fsync` after rename (unsupported on Windows). Injectable `RestoreProgressDurability` seam. `progress.previous.json` stays best-effort only.

### Changed Files
- New: durability contract + native / fake implementations, `RestoreProgressWriteException`. Modified: `RestoreProgressWriter` and its test.

### Verification
- Restore subset: **66 total, 66 passed, 94 assertions** (6 new durability tests).

### Commit Hash
_(not committed at time of writing — see "harden restore progress durability")_

---

### Date
2026-07-23 (OMS Task 7C.1 — restore domain/schema/config/authorization foundation)

### Task
Phase 7C.1 only: restore domain, schema, configuration, authorization and deletion-eligibility foundation.

### Result
- `backup_operations` gained `restore_metadata` (JSON) and `launch_nonce`; `BackupType::Restore`, `BackupStatus::RestorePartial`; `backups.restore` permission (Super Admin only); private `restores` disk + `oms.backup.restore` config; deletion rule `restore_source_in_use` (matches every active status).
- Fixed a pre-existing bug: `BackupDeletionRejectedException::forReason()` silently coerced unknown reason codes to `unexpected_failure`.

### Changed Files
- New migration `2026_07_23_150000_add_restore_columns_to_backup_operations_table`; enums, `BackupOperation`, `PermissionRegistry`, filesystems / oms config, deletion service, labels, management page, tests.

### Verification
- Targeted Backup + enum / label / permission tests: **291 total, 289 passed, 2 skipped (pre-existing), 1189 assertions**. No migration run against the real database.

### Commit Hash
_(not committed — awaiting review)_

---

### Date
2026-07-23 (Backup management page — deletion-eligibility UI clarification)

### Task
Replace the misleading "الحماية" column with "إمكانية الحذف", showing the real deletion-eligibility decision from `BackupDeletionService`'s own rules.

### Result
- New `BackupDeletionEligibility` + `BackupDeletionService::eligibility()` mirroring `delete()`'s rule order (incl. a lock peek); `delete()` reuses it, so UI and service cannot disagree. `lastKnownGoodId()` memoized (one query per table render).
- Badges: «مسموح» / «ممنوع — آخر نسخة ناجحة» / «ممنوع — محمية يدويًا» / «ممنوع — قيد الاستخدام»; the details modal's protection values now say «محمية يدويًا» / «غير محمية يدويًا». No deletion rule changed.

### Changed Files
- New `BackupDeletionEligibility`; modified `BackupDeletionService`, `BackupDeletionRejectedException` (`forReason()`), `BackupManagementPage`, backup tests.

### Verification
- `tests/Feature/Backup`: 174 total, 173 passed, 1 skipped (pre-existing), 499 assertions.

### Commit Hash
(pending — not yet committed)

---

### Date
2026-07-23 (OMS Task 7B.2 — Filament backup management page)

### Task
Super-Admin-only Filament management surface for the 7B.1 backup core (stats, create / verify / download / delete, operations table, notifications). Restore excluded (Task 7C).

### Result
- `BackupManagementPage` («النظام» → «النسخ الاحتياطي والاستعادة»): every action re-checks Super Admin role + `backups.*` permission in its own closure; 4 overview stats; queued manual backup (duplicate-submit lock); queued integrity check; download via `backups.download` (UUID only); delete requires typing `DELETE` and is refused for protected / active / last-known-good / referenced pre-restore backups; safe details modal (never paths or keys); 10 s polling, bounded queries.
- Database notifications enabled (`notifications` migration + `->databaseNotifications()`); `BackupNotifier` notifies the initiating user or all active Super Admins; `BackupErrorSanitizer` also applied in `CreateBackupJob::failed()`.
- Corrected pre-existing 7B.1 defect: manual backups were always `is_protected = true` (now `false`; retention still keeps manual backups). Replaced a stale hardcoded permission-count test with a registry-derived check.

### Changed Files
- New: page, widget, view, backup services (schedule, stats, deletion, notifier), support classes, notifications, migration `2026_07_23_090000_create_notifications_table`, tests. Modified: backup jobs, `AdminPanelProvider`, `BackupCreationOrchestrator`.

### Verification
- Backup tests: **238 total, 236 passed, 2 skipped (pre-existing), 658 assertions**; permission tests 45/45 (838 assertions). No real backup or migration run.

### Commit Hash
_(to be filled after commit — `add secure backup management page`)_

---

### Date
2026-07-23 (OMS Task 7B.1 — encrypted backup core foundation)

### Task
Backup foundation: private storage, versioned libsodium-encrypted archives, pre-publication verification, DB-enforced scheduled deduplication, per-backup file locking, secure download, dedicated `backups` queue / permissions. Restore, UI and real execution out of scope.

### Result
- `backup_operations` table + `BackupOperation` (SoftDeletes, UUID, unique `deduplication_key`); enums type / scope / status; private `backups` disk.
- Archive = ZIP (`manifest.json`, `database/dump.sql`, `files/attachments/**`) in a versioned XChaCha20-Poly1305 Secretstream envelope; `BackupKeyRing` fails closed on bad keys; candidates are decrypted and fully verified before atomic publish; `BackupIntegrityVerifier` re-runs the same verification.
- Scheduled dedup via the UNIQUE key; `BackupFileLock` held through download streaming (HTTP 423 if busy); retention skips locked rows; `mysqldump` streamed with `MYSQL_PWD` only in the child env and bounded / sanitized stderr; all symlinks rejected.
- `backups` permission module (no `restore` yet); scheduler: daily 02:00, weekly Fri 02:30, retention 03:00 (Asia/Gaza) on the `backups` queue.

### Changed Files
- New: `app/Services/Backup/**`, model, enums, 3 jobs, 2 commands, download controller, migration `2026_07_22_100000_create_backup_operations_table`, tests. Modified: `config/oms.php`, `config/filesystems.php`, `bootstrap/app.php`, `routes/web.php`, `PermissionRegistry`, `AppServiceProvider`, `.env.example` (placeholders only).

### Verification
- Backup tests: **143 total, 141 passed, 2 skipped (OS symlink tests on Windows), 411 assertions**; permission tests 16/16. No real backup, `mysqldump` or migration run.

### Commit Hash
_(to be filled after commit — `add encrypted backup core foundation`)_

---

### Date
2026-07-22 (OMS-wide CRUD redirect standard)

### Task
Standardize Filament redirects: Create / Edit → the record's View page (when `canView`), otherwise Index; page-level Delete → Index.

### Result
- Shared concern `App\Filament\Concerns\RedirectsToResourceView` applied to the 44 Create / Edit pages of all 22 editable resources. Delete already redirected to Index. Read-only resources and RelationManager / modal CRUD are intentional exceptions. No authorization or persistence change.

### Changed Files
- New concern + `tests/Feature/Crud/*`; 44 page classes (`use` only).

### Verification
- Targeted CRUD / authorization / financial suites: **402 total, 399 passed, 3 skipped (pre-existing), 1242 assertions**.

### Commit Hash
Committed as `standardize CRUD redirects across resources` (see `git log -1 --format=%H`).

---

### Date
2026-07-22

### Task
Test-suite cleanup: remove the obsolete stock `ExampleTest` and fix the one risky Users test.

### Result
- Deleted `tests/Feature/ExampleTest.php` (asserted `GET /` = 200; OMS intentionally has no `/` route; no route added, no filler test).
- `RoleAssignmentSafetyTest`: added `assertArrayHasKey('roles', $e->errors())`. Test-only change.

### Changed Files
- `tests/Feature/ExampleTest.php` (deleted), `tests/Feature/Users/RoleAssignmentSafetyTest.php`, `graphify-out/**`.

### Verification
- `tests/Feature/Users/`: **64 passed, 0 risky, 134 assertions**.

### Commit Hash
_(to be filled after commit — `clean obsolete test scaffolding`)_

---

### Date
2026-07-22

### Task
OMS Task 6D: rebuild `AttachmentResource` as a secure, read-only financial attachment registry («النظام» → «سجل المرفقات»).

### Result
- `FinancialAttachmentRegistry` is the single source of truth for the five supported attachable types (label, parent `.view` permission, eager loads, display accessors). Resource re-enabled in navigation; all `canX()` still false; `AttachmentForm` deleted.
- Two-layer authorization: `attachments.view_any` to open; each row also needs `attachments.view` + its parent `.view` (scoped list; unauthorized direct URL → 403; unsupported types → 404).
- Preview / download only through `attachments.show` + parent policy; missing / trashed rows never expose a link or path; no N+1 (query-count test). File-availability filter intentionally omitted.

### Changed Files
- New `FinancialAttachmentRegistry`, `AttachmentRegistryTest` (42 tests); modified the resource, table, view page and three authorization tests.

### Verification
- `AttachmentRegistryTest` 42 passed (101 assertions); targeted set **274 tests → 271 passed, 3 skipped (pre-existing), 856 assertions**.

### Commit Hash
_(to be filled after commit — `add secure financial attachment registry`)_

---

### Date
2026-07-22

### Task
OMS Task 6C: remove the six orphaned public attachment files under `storage/app/public/{execution-payments,payments}`.

### Result
- **No deletion performed — the six files were already absent** (only `.gitignore` remained under `storage/app/public`). They were present and unchanged as of commit `27969b5` (2026-07-21) and were never git-tracked; per-file hashes could not be recorded (not fabricated).
- Read-only DB check: 0 attachment rows at all (table empty). Old public URLs return HTTP 403; `/storage/.gitignore` 200 (symlink intact). All 5 financial forms already use the private `attachments` disk and `attachments.show`.

### Changed Files
- Documentation only.

### Verification
- `tests/Feature/Attachments`: **141 passed, 400 assertions**.

### Commit Hash
Not committed — awaiting review (no staging).

---

### Date
2026-07-21

### Task
OMS Task 6B: move the five financial resources' uploads from the public disk to the private `attachments` disk, with secure inline preview and keep / replace / remove on Edit; then apply the `disk` column migration to the real local database.

### Result
- `AttachmentUploadService` centralizes upload / rename / move (allowlisted directories, deterministic filenames, cleanup on failure); shared `secure-attachment-preview` component (server-built `attachments.show` links only) on all 5 View and Edit pages; private path never prefilled into `FileUpload`.
- After approval: full `mysqldump` backup taken, then only migration `2026_07_21_000001_add_disk_to_attachments_table` run on the real local DB (`disk varchar(32) default 'public'`; 0 attachment rows; orphan files unchanged).
- Coverage gap: the post-move DB-failure cleanup branch is reviewed but not unit-tested.

### Changed Files
- New `AttachmentUploadService`, preview component, 3 test files (77 tests); the 5 financial forms and their Create / Edit / View pages.

### Verification
- Targeted Attachments + financial + authorization tests: **303 tests, 300 passed, 3 skipped (pre-existing), 1040 assertions**.

### Commit Hash
`move financial attachments to private storage` (hash recorded after commit — see below)

---

### Date
2026-07-21

### Task
Final authorization acceptance test pass for Permissions Tasks 1–5 (testing only).

### Result
- Added `tests/Feature/Permissions/AuthorizationAcceptanceTest.php` (22 tests, 200 assertions) for the gaps found by auditing existing coverage; data built through the real `PermissionSyncService`.
- **No authorization defect found.** `users.assign_roles` does not exist by design: role assignment is governed by `users.create` / `users.update` + `UserManagementService`; `users.assign_super_admin` is separate.

### Changed Files
- New `AuthorizationAcceptanceTest`; `graphify-out/**`.

### Verification
- Permissions 339/341 (2 pre-existing skips), Roles 88/88, Users 64/64, Reports 58/58, financial 70/70; full suite 782/785 (pre-existing `ExampleTest` failure, 2 skips, 1 risky).

### Commit Hash
`add final authorization acceptance coverage` (hash recorded after commit — see below)

---

### Date
2026-07-21

### Task
OMS Permissions Task 5: read-only `PermissionResource` plus a protected permission-sync action; new permission `permissions.sync` (Super Admin only).

### Result
- `PermissionPolicy` (all mutations false), `PermissionManagementService` (actor + exact Super Admin role + `permissions.sync` before calling the untouched `PermissionSyncService::sync()`), `PermissionResource` with index / view only and all 8 mutation `canX()` hard-false.
- `PermissionRegistry`: `permissions.sync` in new group «النظام والصلاحيات», `moduleForPermission()`; total 154 → 155. Legacy / custom permissions remain visible («صلاحيات مخصصة»). Crafted Livewire calls rejected server-side.

### Changed Files
- New policy, service, resource (pages, table, infolist), 5 test files; modified `PermissionRegistry`, `AppServiceProvider`, 2 permission tests.

### Verification
- New tests 39/39; Permissions 317/319 (2 pre-existing skips), Roles 88/88, Users 64/64, financial 70/70; full suite 760/763 (pre-existing gaps).

### Commit Hash
Not committed — awaiting explicit approval, per task instructions.

---

### Date
2026-07-15

### Task
Environment-gated command to wipe operational / transactional data from the dev database while preserving configuration, donors, accounts, currencies, FX history and users / roles / permissions. Phase 1: implement, test, dry-run.

### Result
- `OperationalDataCleanupService` + `oms:clean-operational-data` (`--dry-run`, `--apply` with exact confirmation token + verified backup file, `--skip-files`); allowed only in `local` / `development` / `testing`.
- Child-before-parent deletion of 12 tables + linked attachments + balance reset in one `DB::transaction()` via `DB::table()->delete()`; donors via `partners.is_donor` only; files deleted only by exact path after commit; orphans only reported.
- Dry-run on the real dev DB: zero writes; 6 orphan files (706,133 bytes) and an unused legacy `bank_accounts` table (0 rows) reported.

### Changed Files
- New service, report DTO, command, `CleanOperationalDataCommandTest` (20 tests).

### Verification
- New tests 20/20 (67 assertions); full suite 71/72 (pre-existing `ExampleTest`). `--apply` not run.

### Commit Hash
Not committed — awaiting explicit approval for the apply phase before any commit.

---

### Date
2026-07-16

### Task
Execution Payment («صرف مبالغ التنفيذ»): credit section above the beneficiary section, editable credit-account cascade defaulting from the budget's destination account, and fix the Edit drift bug.

### Result
- `ExecutionPaymentForm`: type → bank type → currency → account cascade with `applyCreditDefaults()` and server-side `validateCreditAccount()` (cross-currency rejected); Create uses the validated submitted account; Edit hydrates from the payment's own saved `LINE_CREDIT` line instead of the budget's current destination (drift fixed). No migration: the credit account still lives only in `transaction_lines.account_id`.

### Changed Files
- `ExecutionPaymentForm`, `CreateExecutionPayment`, `EditExecutionPayment`, new `ExecutionPaymentCreditAccountTest` (7 tests).

### Verification
- New tests 7/7 (32 assertions); description / cleanup regression suites 70/70; full suite 78/79 (pre-existing `ExampleTest`).

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-18

### Task
Positive-amount validation across the 5 financial workflows (UI constraints + independent server-side guard).

### Result
- UI: amounts `minValue(0.01)`, percentages 0–100, `fx_rate` `minValue(0.000001)`.
- New `FinancialAmountGuard` called in all 10 Create / Edit handlers before `DB::transaction()` (combined percentages < 100, derived amounts > 0).
- Submitted `fx_rate ?: 1` → `?? 1` at the 4 submitted-data sites, so an explicit `0` is rejected; display-only fallbacks untouched. Over-budget execution stays a warning.

### Changed Files
- New `FinancialAmountGuard`; the 5 forms and their Create / Edit pages; 3 new test files.

### Verification
- New tests 33/33 (74 assertions), rejections proven zero-mutation; full suite 104/105 (pre-existing `ExampleTest`).

### Commit Hash
Not committed — awaiting explicit approval.

---

### Date
2026-07-18

### Task
Server-side financial account validation for the 4 workflows relying only on Select filtering.

### Result
- New `FinancialAccountGuard` (exists / not trashed, type, bank type, currency, active) called in all 8 Create / Edit handlers before `DB::transaction()`; the verified `Account` instances are used for balance updates.
- Active required on Create, and on Edit only when the account changed (`requireActiveOnChange`). The same account may serve several roles (no distinct-account check). `ExecutionPaymentForm::validateCreditAccount()` extended additively with the same active rule.

### Changed Files
- New guard; 8 Create / Edit pages; `ExecutionPaymentForm`, `EditExecutionPayment`; 5 new test files + fixture fixes.

### Verification
- New / updated tests 60/60; full suite 150/151 (pre-existing `ExampleTest`).

### Commit Hash
Not committed — awaiting explicit approval.

---

### Date
2026-07-16

### Task
Responsive credit / debit section layout: creditor right and debtor left on desktop, creditor above debtor on mobile.

### Result
- `GeneralExpenseForm` and `ProjectCostReceiptForm`: credit section moved first and both wrapped in `Grid::make(['default' => 1, 'lg' => 2])` (RTL auto-placement); `ExecutionPaymentForm`: Grid wrap only. Disbursement and General Exchange forms left unchanged (see DECISIONS_LOG). No field or behaviour change.

### Changed Files
- The 3 forms; `graphify-out/**`.

### Verification
- `php -l`, `view:cache` clean; credit-account + description tests 40/40; full suite 78/79 (pre-existing `ExampleTest`).

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-16

### Task
General Exchange («التحويلات العامة») form layout only: [تفاصيل المعاملة | النسب والمبالغ] side by side, then full-width «الحسابات», then «المرفقات».

### Result
- `GeneralExchangeForm` top-level tree reordered with a 2-column Grid; the 16 account fields, calculations and helpers are byte-identical. Create / Edit pages untouched.

### Changed Files
- `GeneralExchangeForm.php`; `graphify-out/**`.

### Verification
- `php -l`, `view:cache` clean; related suites 70/70.

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-15 (execution)

### Task
Execute the approved operational-data cleanup `--apply` against the real dev database.

### Result
- Backup taken first with `mysqldump --single-transaction` (credentials via `MYSQL_PWD` only), verified non-empty.
- Deleted: 2 projects, 3 project costs, 1 budget, 1 execution payment, 3 receipts, 2 general expenses, 1 general exchange, 12 transactions, 28 lines, 8 attachments, snapshot rows; all 15 account balances reset to 0.00; 6 linked files deleted (1,474,023 bytes); the 6 orphan files (706,133 bytes) untouched.
- Built-in verification PASSED; 21-point manual checklist matched (donors 2, accounts 15, users 2, roles 5, permissions 24, currencies 3, migrations 61). A second run was a no-op (idempotent).

### Changed Files
- Database and attachment files only (not git-tracked); `graphify-out/**`.

### Verification
- Command verification PASSED on both runs; independent 21-point checklist confirmed.

### Commit Hash
Not committed — no commit was requested for this data-only operation.

---

### Date
2026-07-05

### Task
Trial Balance («ميزان المراجعة») Phase 1: read-only page of per-account debit / credit totals for one required currency and a date range.

### Result
- `TrialBalanceReportService` + `TrialBalancePage` under «التقارير». One currency is required, so `debit_base` / `credit_base` are never blended across currencies. Period movement only; no opening / closing balances or exports in this phase.

### Changed Files
- New service, page and Blade view.

### Verification
- Tinker totals matched a manual aggregate (USD debit 245,400.00 / credit 390,000.00); soft-deleted rows excluded; browser-checked (defaults, empty states, no cross-currency bleed).

### Commit Hash
(not committed yet)

---

### Date
2026-07-05

### Task
Trial Balance Phase 2: «تصدير Excel» and «تصدير Word» of exactly the applied / displayed result.

### Result
- Exports read only the snapshot taken in `showReport()`; changing a filter without «عرض» blocks export with a warning. PhpSpreadsheet / PhpWord were already installed. No calculation change (service only gained label fields).

### Changed Files
- New `TrialBalanceExcelExportService`, `TrialBalanceWordExportService`; page and service updated.

### Verification
- Generated files re-opened (real RTL `.xlsx` with autofilter, real `.docx` with `bidi`), incl. an empty result; browser download and the stale-filter warning checked.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
«تقرير الحركات المالية الشامل» Phase 1: read-only page listing every transaction line in a period with per-currency / per-category / per-type summaries.

### Result
- Service + page + view following the Trial Balance pattern (submit gate, applied-filter snapshot, clear on filter change). One joined read-only query over lines → transactions → accounts with lookups; every summary is per currency (no blended total).
- Per-currency «غير متوازن» can be legitimate when a transaction's legs are in different currencies.

### Changed Files
- New service, page and Blade view.

### Verification
- Tinker on real data: 66 lines / 23 transactions / 2 currencies; correct ordering and summaries.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Comprehensive report summary wording and counts: «عدد المعاملات» / «عدد بنود القيود»; category / type sections count distinct transactions.

### Result
- `accumulateGrouped()` tracks distinct transaction ids per bucket (never line_count / 2); money totals stay line-based per currency.

### Changed Files
- Comprehensive report service and Blade view.

### Verification
- 68 lines / 24 transactions; per-bucket counts sum to 24 = an independent distinct count.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Comprehensive report Phase 2: «تصدير Excel» and «تصدير Word» of exactly the applied / displayed result.

### Result
- Exports read only the applied snapshot (stale filters block export with «يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير»). Both contain report info and filters («الكل» when unset), general / per-currency / category / type summaries and the full detail table; no blended multi-currency total.

### Changed Files
- New Excel and Word export services; page header actions.

### Verification
- Round-trip on real data and an empty range: RTL `.xlsx` with numeric `#,##0.00` cells and autofilter; `.docx` with `bidi`, Tahoma and page footer.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Reset all operational / business data in `oms` so real data entry starts clean, keeping settings, lookups, auth and Laravel system tables (user-specified keep / purge lists).

### Result
- All 41 tables checked against the 25-table keep list (exact match); FK audit confirmed the 16 purge tables could be truncated together. Lists approved by the user, full `mysqldump` backup verified, then the 16 tables truncated (AUTO_INCREMENT reset, soft-deleted rows included); `optimize:clear` and `reports:refresh-projects-financial` run.

### Changed Files
- None (data-only; backup file not committed).

### Verification
- Before / after row counts for all 41 tables: purge tables 0, keep tables unchanged (settings 5, roles 5, permissions 24, users 2, currencies 3).

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Optional opening balance on account creation as a balanced double-entry transaction (never a manual `current_balance` write).

### Result
- Approved rules: the target account is always debited (no account nature exists); `debit_base` / `credit_base` = own-currency amount, fx stored as metadata only; one clearing account per currency (`OPB-{CUR}`, type «أرصدة افتتاحية»); type «قيد افتتاحي» under super type «قيود افتتاحية»; numbering `OPB-YYYY-XXXX`; fiscal year from the opening date.
- `current_balance` is now display-only in the form; opening fields appear on create only.

### Changed Files
- `AccountForm.php`, `CreateAccount.php`.

### Verification
- Rolled-back tinker end-to-end (USD and ILS cases, clearing-account reuse); Trial Balance balanced; project snapshots unaffected (they never read `transaction_lines`).

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
«تقرير الجهات المانحة» (Donor Financial Report): read-only page for one donor's projects and full cost / disbursement / execution picture, with Excel and Word exports; then a full audit.

### Result
- Service + page + view + 2 export services (same pattern as the other reports). `donor_id` required (`partners.is_donor`); dates filter `projects.approval_date`; `currency_id` is display-only.
- Three currency grains never mixed: cost side, disbursement source (with admin / transfer deductions from `ProjectCostBudget::LINE_ADMIN` / `LINE_TRANSFER` lines) and execution. "Real" disbursements = `transaction_id IS NOT NULL`. No existing report or write logic changed.
- Disbursement / execution sections were verified by code review only (no such dev data yet).

### Changed Files
- 5 new files (page, service, 2 export services, Blade view).

### Verification
- Audit checklist passed (soft deletes, no N+1, no fan-out double counting); tinker smoke test: unmerged per-currency cost rows (20,000 USD / 40,000 ILS).

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Fix the broken «صورة الإشعار» image on the Project Cost Receipt view page.

### Result
- Environmental, not a code bug: `public/storage` was an empty real directory, so `storage:link` had silently failed. Removed it (user-confirmed) and re-ran `php artisan storage:link`. No application code changed.

### Changed Files
- None (filesystem symlink only).

### Verification
- `readlink -f public/storage` → `storage/app/public`; the receipt attachment URL resolves to an existing file.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Remove the obsolete «المبالغ المرصودة» resource (`/admin/project-cost-budgets`) without touching the table, model or disbursement logic.

### Result
- Confirmed (graphify + grep) the resource was a plain generic CRUD with no ledger effect and no external references; the real disbursement flow («صرف مبلغ المشروع») creates `project_cost_budgets` rows itself. Deleted `app/Filament/Resources/ProjectCostBudgets/`.

### Changed Files
- The `ProjectCostBudgets` resource directory (9 files deleted).

### Verification
- Routes: plain `project-cost-budgets` gone; disbursement and execution-payment routes intact; model and table intact.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Remove the old «تقارير المشاريع» page (`/admin/projects-report-page`).

### Result
- Confirmed it was self-contained and read-only; deleted `ProjectsReportPage.php` and its Blade view.

### Changed Files
- 2 files deleted.

### Verification
- Route gone; all other report routes still registered.

### Commit Hash

---

### Date
2026-07-14

### Task
Automatic Arabic `transactions.description` for all 6 financial write flows via one shared formatter: `دائن: … | مدين: … | ملخص العملية: ….` (credit before debit, English digits, 2 decimals, currency from the line).

### Result
- New `TransactionDescriptionBuilder::buildAndSave()` (credit before debit, throws if a side is empty, inside the caller's `DB::transaction()`); called by all 11 Create / Edit pages as the last step. Notes, `LINE_*` tags and accounting code untouched; no backfill.
- Fixed two unrelated pre-existing migration bugs found while adding tests (see DECISIONS_LOG 2026-07-14); a third MySQL-only migration was left untouched.

### Changed Files
- New builder + `TransactionDescriptionBuilderTest` (15 tests); 11 Create / Edit pages; 3 migration files (bug fixes, no live DB operation).

### Verification
- Unit tests 15/15; full suite 16/17 (pre-existing `ExampleTest`); rolled-back tinker of all 6 creates + 5 edits produced correct descriptions; `migrate:status` unchanged.

### Commit Hash
(not committed yet)

---

### Date
2026-07-14

### Task
Close the manual-edit bypass on `TransactionResource` («المعاملات المالية»): make it a strictly read-only audit resource.

### Result
- Pages reduced to index / view (Create / Edit pages deleted); all 8 `can*` overrides return false on the resource and on `LinesRelationManager`; no header, record or bulk mutation actions; `description` disabled; `defaultSort('id', 'desc')`. The 6 financial flows are unaffected.

### Changed Files
- `TransactionResource`, its list / view pages, table, `LinesRelationManager`, form; 2 page classes deleted.

### Verification
- Routes: only index / view; all `can*` false; six-flow tinker descriptions byte-identical; builder tests 15/15.

### Commit Hash
(not committed yet)

---

### Date
2026-07-14

### Task
Per-line Arabic `transaction_lines.description` and machine-readable `line_role` across all 6 financial flows; harden `TransactionLineResource` to read-only.

### Result
- Migration `2026_07_14_120000` adds `description` + `line_role`; `TransactionLineRole` enum (11 roles + Arabic labels); `TransactionLineDescriptionBuilder` (`{مدين|دائن}: حساب {name} ({currency}) — {amount} | الغرض: {purpose}.`, throws on missing role / purpose; zero-amount placeholder lines get NULL description); shared `FormatsTransactionText` trait (parent output byte-identical).
- All 6 create + 5 edit flows set roles explicitly (receipt edit self-heals old rows). `TransactionLineResource`: index / view only, 8 `can*` false; new role badge + description columns, also in `LinesRelationManager`.

### Changed Files
- New migration, enum, trait, builder, unit test; `TransactionLine` model; the 11 financial pages; TransactionLine resource files (2 pages deleted).

### Verification
- `tests/Unit/Services` 33/33; rolled-back tinker of all flows incl. cross-currency, zero residue.
- Known limitation: `RefreshDatabase` still blocked by the MySQL-only `2026_06_24_000005` migration; `ExampleTest` failure pre-existing.

### Commit Hash
(not committed yet)

---

### Date
2026-07-15

### Task
Backfill historical `description` / `line_role` metadata, then show «وصف العملية المالية» / «دور سطر القيد» / «وصف سطر القيد» in the detailed sections of the four movement reports and standardize the audit-resource labels.

### Result
- Classification: flow identified only by the domain record's `transaction_id` FK (5 flows) or type «قيد افتتاحي» (opening balance); `LINE_*` notes used only to map roles after the flow is known.
- `php artisan transactions:backfill-descriptions` (`--dry-run` / `--apply`, `--transaction-id`, `--chunk`) with `TransactionFlowClassifier`; reuses the live builders (new public `describeLine()`); writes only changed values, so it is idempotent; never touches amounts, notes, balances or soft-deleted rows.
- Dev run: 10/10 transactions classified, 0 unclassified; apply updated 10 transactions / 24 lines (incl. 4 stale opening-balance descriptions); second apply 0 updates; integrity snapshot byte-identical.
- Reports (display only, no calculation change): Comprehensive (screen, Excel columns M–O, Word restructured into per-transaction blocks to fit A4), Account Statement (screen / Excel / Word), Project Financial Details (nested line details, Word only — it has no Excel), Donor report (nested details; Excel keeps one row per movement).

### Changed Files
- New backfill command + `Backfill/` classifier classes + test (17 tests); `TransactionLineDescriptionBuilder` (`describeLine()`); the four reports' services, exports and views; transaction / line audit resource labels.

### Verification
- 52/52 relevant tests + the pre-existing `ExampleTest` failure; 7 sample exports re-opened; audit resources still index / view only.

### Commit Hash
(not committed yet)

---

### Date
2026-07-18

### Task
Test coverage for `FinancialTransactionBalanceGuard` and its wiring into the 10 financial Create / Edit paths, without changing the approved accounting implementation.

### Result
- The guard was completed with payload-structure rules (required keys incl. `fx_rate`, positive `amount_currency` matching the active side, `fx_rate` > 0 and `== 1` for single-currency lines, line-role presence / uniqueness / direction, optional notes-tag match) — no change to the balance equations; the 10 production paths needed no change.
- Same-day correction: a missing `fx_rate` is now rejected (no default), and `EditProjectCostReceipt` writes `'fx_rate' => 1` explicitly (the only path missing it); a valid receipt edit normalizes a corrupted stored `fx_rate`.
- Confirmed by search: no naive cross-currency sum, validation before `DB::transaction()` in all 10 files, exact validated payload written, no tolerance, formula lines unchanged.

### Changed Files
- `FinancialTransactionBalanceGuard.php`, `EditProjectCostReceipt.php`; new guard unit test (36 tests) and 5 `BalanceGuardIntegrationTest` files (23 tests).

### Verification
- Guard 36/36; receipt integration 7/7; the 5 workflow directories **70/70 (285 assertions)**; full suite 209/210 (pre-existing `ExampleTest`).

### Commit Hash
`validate transaction balance with multi-currency support` — see `git log` for the hash.

---

### Date
2026-07-19

### Task
OMS permissions foundation (Task 1): permission registry, `Gate::before` Super Admin bypass, idempotent `oms:sync-permissions`, and an emergency Super-Admin-only lockdown of `UserResource`.

### Result
- `PermissionRegistry`: 154 `module.action` permissions with Arabic labels, modules and defaults for the 5 system roles. `PermissionSyncService` + `oms:sync-permissions`: creates missing permissions / system roles, reconciles system-role defaults, never deletes a permission or touches other roles, clears the Spatie cache.
- `Gate::before` grants only for `hasRole('Super Admin')` (returns null otherwise). Temporary `UserPolicy` denies everything (Super Admin passes via the bypass). `DatabaseSeeder` now calls the sync service; the 24 old coarse permissions are left in place.
- Found: HTTP panel tests were 403ing for an environment reason (resolved in the corrections below).

### Changed Files
- New registry, sync service, command, `UserPolicy`, 4 test files; `AppServiceProvider`, `DatabaseSeeder`. No migration.

### Verification
- New tests 19/19 (420 assertions); full suite 228/229 (pre-existing `ExampleTest`). Real sync run: 154 created, 5 roles found (Super Admin 154, Admin 140, Accountant 48, Project Manager 20, Viewer 46); second run idempotent; 178 total permissions (0 deleted).

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction — real admin email + completed real HTTP authorization tests)

### Task
Re-check the real administrator account read-only, report the seeder's admin behaviour, warn from `oms:sync-permissions` when no Super Admin user exists, and complete real HTTP 403/200 tests for `UserResource`.

### Result
- Read-only check (zero writes): the real admin account exists, is not soft-deleted, holds exactly `Super Admin`, and is recognized by `Gate::before`.
- Reported (not changed): the seeder targeted a different hardcoded email, so it would have created a second Super Admin.
- Root cause of the HTTP 403s: Filament allows a non-`FilamentUser` model only when `app.env === 'local'`; tests used a temporary env override (removed in correction 2).
- `oms:sync-permissions` now prints an Arabic warning when 0 users hold Super Admin; it never creates a user.

### Changed Files
- `PermissionSyncService`, `SyncPermissions` command, `UserResourceLockdownTest` (real HTTP), `SyncPermissionsCommandTest` (+2).

### Verification
- Permission suite 21/21 (416 assertions); full suite 230/231 (pre-existing `ExampleTest`); real DB unchanged.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction 2 — FilamentUser panel access + duplicate-Super-Admin-safe seeder)

### Task
Implement `FilamentUser` on `User` (removing the test env workaround) and stop the seeder from creating a second Super Admin.

### Result
- `User implements FilamentUser`; `canAccessPanel()` = not trashed (no permission logic); real HTTP tests pass under `APP_ENV=testing`.
- `seedSuperAdmin()` returns early when any non-soft-deleted Super Admin exists; an existing admin is left completely untouched (proven by test).

### Changed Files
- `User.php`, `DatabaseSeeder.php`, `UserResourceLockdownTest`, new `UserFilamentAccessTest` (4) and `DatabaseSeederSuperAdminTest` (8).

### Verification
- Permission-related tests 33/33 (444 assertions); full suite 242/243 (pre-existing `ExampleTest`); real DB unchanged.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction 3 — no hardcoded bootstrap credential + soft-delete-safe seeding)

### Task
Remove the hardcoded bootstrap admin credential from the seeder and handle a soft-deleted bootstrap email safely.

### Result
- New `config/oms.php` → `bootstrap_admin.{email,name,password}` from `OMS_BOOTSTRAP_ADMIN_*` env vars (no default password; `.env.example` placeholders only).
- `seedSuperAdmin()`: skip if an active Super Admin exists; otherwise require the config (`RuntimeException` naming only the env vars); `withTrashed()` lookup → create / promote only (no credential overwrite) / restore + reset password (no duplicate row). No hardcoded credential remains in source.

### Changed Files
- `config/oms.php` (new), `DatabaseSeeder.php`, `.env.example`, `DatabaseSeederSuperAdminTest` (13 tests).

### Verification
- Seeder tests 13/13; permission-related tests 38/38 (466 assertions); full suite 247/248 (pre-existing `ExampleTest`); real DB unchanged.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (OMS Permissions Task 2A — Filament Resource/RelationManager authorization)

### Task
Protect every Filament Resource and RelationManager with the Task 1 permissions (report pages deferred to 2B), without Shield, migrations or financial changes.

### Result
- Shared `AuthorizesCrud` trait + 23 policies (one per model, auto-discovered): each ability needs its own `<module>.<operation>`; force delete never grantable (Super Admin only via `Gate::before`); trashed records denied for view / update / delete (audit resources may view trashed); restore needs `<module>.restore`.
- `TransactionPolicy` / `TransactionLinePolicy` stay immutable regardless of grants. Swapped prefixes documented in code: `ExecutionPaymentResource` → model `ProjectCostBudgetsPayment` → `execution_payments`; `ProjectCostBudgetsPaymentResource` → model `ProjectCostBudget` → `project_cost_budgets_payments`.
- RelationManagers authorize against the related model's own policy (verified), so no Resource / RelationManager file changed.

### Changed Files
- New trait, 23 policies, 5 test files (only new files).

### Verification
- `tests/Feature/Permissions` 273/275 (2 by-design skips, 1092 assertions); full suite 513/516 (pre-existing `ExampleTest`, 2 skips); financial suites unaffected.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-20 (OMS Permissions Task 2B — custom report page and export authorization)

### Task
Protect the six custom report pages (navigation, direct URL, export buttons, direct Livewire export calls) with the existing `reports.<page>.view` / `.export` permissions.

### Result
- Trait `AuthorizesReportAccess` on all 6 pages: `canAccess()` (403 on mount and every Livewire request; drives navigation) and `authorizeReportExport()` as the first statement of all 11 export methods (needs view + export); export actions `->visible()` by permission.
- `ProjectFinancialDetailsPage` stays out of navigation; its direct route is now permission-gated. No report calculation, export format or label changed.

### Changed Files
- New trait; the 6 report pages; `ReportPageAccessTest` (30), `ReportExportAuthorizationTest` (28).

### Verification
- New tests 30/30 and 28/28; full suite 574 tests, 571 passed (pre-existing `ExampleTest`, 2 skips).

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-20 (OMS Permissions Task 3 — secure user management)

### Task
Replace the Task-1 lockdown `UserPolicy` with granular `users.*` permissions and a full safety layer for `UserResource`.

### Result
- Migration `2026_07_20_000000_add_is_active_to_users_table`; `User` model default `is_active = true`; `canAccessPanel()` = not trashed AND active.
- `UserManagementService` (all mutations transactional): `canManageUser()` privilege-subset check (target must not be Super Admin or hold protected permissions — `users.assign_super_admin`, `roles.*`, `permissions.*` — and every target permission must be held by the actor); `assignableRoleNames()` for added roles; self-edit cannot change own roles / `is_active`; last-active-Super-Admin protected with a locking read.
- `UserPolicy` granular; single-record delete / restore through the service; no bulk or force-delete actions; roles Select not relationship-bound; password hashed once by the model cast, with confirmation; seeder and sync count only active Super Admins.

### Changed Files
- New migration and service; `User`, `UserPolicy`, the user form / pages / table / resource, `DatabaseSeeder`, `PermissionSyncService`; 7 new user test files (old lockdown test removed).

### Verification
- `tests/Feature/Users` 64/64; `tests/Feature/Permissions` 276/278 (2 skips); full suite 634 tests, 631 passed (pre-existing `ExampleTest`, confirmed on a clean stash). Migration not run on the real DB.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-20 (OMS Permissions Task 4 — secure role management)

### Task
`RoleResource` («النظام» → «الأدوار»): view roles, create / edit / delete safe custom roles with grouped Arabic permission checkboxes; the 5 system roles visible but structurally protected (only `oms:sync-permissions` manages them).

### Result
- `RoleManagementService` (transactional): `canManageRole()` rejects system roles and the actor's own roles before any Super Admin branch (never via Gate); non-Super-Admins can only manage roles whose permissions they hold and that contain no protected permission; protected / duplicate names rejected; assigned roles cannot be deleted; permission cache cleared.
- `RolePolicy` registered explicitly (Spatie `Role` is outside `App\Models`); `RoleResource::canEdit()` / `canDelete()` call the service directly, so the `Gate::before` bypass cannot edit system roles. Permissions grouped per registry module; unregistered DB permissions shown under «صلاحيات مخصصة» and never dropped. No bulk or force-delete.

### Changed Files
- New service, policy, `RoleResource` (form, table, 4 pages), 6 role test files; `AppServiceProvider`.

### Verification
- Role tests 80/80; Permissions + Roles 356/358 (2 skips); financial directories 165/165; full suite 714 tests, 711 passed (pre-existing `ExampleTest`, 2 skips).

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-20 (OMS Permissions Task 4 — pre-commit authorization verification)

### Task
Verify that `RoleResource::canEdit()` / `canDelete()` require BOTH the operation ability (`roles.update` / `roles.delete`) AND `RoleManagementService::canManageRole()`, with tests for every combination.

### Result
- All four checks (`RoleResource`, `RolePolicy`, `RolesTable`, `EditRole` / `ViewRole` visibility) already combined both conditions; no production change needed.
- New `RoleOperationAndTargetSafetyTest` (8 tests): missing ability → 403 / hidden; ability alone cannot edit or delete a role above the actor's permissions; Super Admin still blocked on system roles; a rejected update / delete leaves the role unchanged.
- Two test-ordering bugs fixed in the test itself (assertions ran before `actingAs()`).

### Changed Files
- `tests/Feature/Roles/RoleOperationAndTargetSafetyTest.php` (new); `graphify-out/**`; docs.

### Verification
- New test 8/8 (44 assertions); `tests/Feature/Roles` 88/88 (201); `tests/Feature/Permissions` 276 passed + 2 pre-existing skips.
- Full suite 722 tests: 1 pre-existing `ExampleTest` failure, 1 pre-existing risky.

### Commit Hash
Not committed at the time.

---

### Date
2026-07-21 (OMS Task 6A — private attachment security foundation)

### Task
Audit attachment handling across the 5 financial workflows, then build the private-storage foundation only (no Resource cutover, no legacy-file migration), plus five mandatory security corrections.

### Result
- **Audit:** attachments were on the `public` disk with raw `Storage::url()` links — guessable and reachable without authentication; no soft-delete cascade to files; the standalone `AttachmentResource` used the `local` disk.
- **Foundation:** private `attachments` disk (`serve: false`, no URL); new `attachments.disk` column (default `public`, no backfill) with `Attachment::APPROVED_DISKS`; one authenticated route `GET /attachments/{attachment}/{mode}` (Filament `Authenticate`) authorizing via `Gate::authorize('view', $parent)` — the 5 existing parent policies reused.
- **Corrections:** `AttachmentResource` hardened (nav hidden, index / view only, all mutation `canX()` false); `AttachmentStorageService::isSafeRelativePath()` (no absolute / UNC / drive / null-byte / `..` paths); `safeDownloadName()` strips directories and control characters (custom splitter, since `basename()` ignores `\` on Linux); a missing or soft-deleted parent returns 404 before any policy check.
- The six orphan public files were untouched (hashes identical throughout).

### Changed Files
- `config/filesystems.php`, migration `2026_07_21_000001_add_disk_to_attachments_table.php`, `Attachment` model, `AttachmentStorageService`, `AttachmentController`, `routes/web.php`, `AttachmentResource` and its pages / table; 4 new attachment test files; 2 permissions tests updated for the hardened resource.

### Verification
- `tests/Feature/Attachments` 64/64; targeted Attachment / Permission / Role / User 633 passed, 3 skipped; financial suites 70/70.
- Full suite 848 tests: only the pre-existing `ExampleTest` failure. Real DB untouched (migration not run).

### Commit Hash
Committed as `add private attachment security foundation` (hash not recorded at the time).

---

### Date
2026-07-26 (OMS Task 7C.6 — attachment restore primitives: staged revalidation, three-phase activate/rollback/finalize lifecycle, crash-state inspector, Windows retry seam)

### Task
Safe attachment-restore primitives only (not wired to `RestoreCommand` / the orchestrator): staged revalidation, quarantine swap, rollback, finalization.

### Result
- `AttachmentStorageService` has no extension / MIME allowlist (only disk gating + path safety); restore is independent of the upload-time directory allowlist.
- New `app/Services/Restore/Attachments/` classes: paths (quarantine `attachments.pre_restore.{uuid}`, marker `….state.json`), swap handle / state / marker, read-only state inspector, staged-tree revalidator, `RestoreAttachmentActivationService` (`activate()` / `rollback()` / `finalize()`, exclusive lock required), `NativeAttachmentMoveRunner` (Windows bounded retry); 5 bounded exceptions; config `attachment_move_retry_attempts` / `_delay_ms` (5 / 200 ms).
- Gap fixed: `finalize()` now verifies the quarantine is really gone before reporting success.
- The revalidator takes the manifest file list explicitly (the orchestrator must supply it).

### Changed Files
- New restore attachment classes, contract, exceptions and tests; `config/oms.php` (two keys). No migration.

### Verification
- Targeted 64 passed, 1 skipped (Linux-only branch); `Restore|Backup|Attachment` 890 passed, 4 skipped, 0 failed.

### Commit Hash
Not committed at the time.

---

### Date
2026-07-26 (OMS Task 7C.6 crash-safety correction pass — signed/durable swap marker, write-ahead phase protocol, InterruptedDuringRollback state, validated attachment manifest value object)

### Task
Pre-commit crash-safety hardening of Task 7C.6.

### Result
- **Signed, durable marker:** `AttachmentSwapMarkerSigner` (HMAC-SHA256, context `oms-restore-attachment-swap-v1`, derived from `APP_KEY`); writer / reader reuse `RestoreProgressDurability` (temp → fsync → atomic rename); any integrity failure throws `RestoreAttachmentMarkerException`.
- **Write-ahead protocol:** 8 `AttachmentSwapPhase` values persisted before and after each destructive rename; distinct `marker_write_failed` / `marker_update_failed_after_mutation`.
- **State machine:** inspector = verified phase × directory facts; new `InterruptedDuringRollback`; `rollback()` resumes from `Activated` / `InterruptedDuringActivation` / `InterruptedDuringRollback`; discard path renamed `attachments.restore_discard.{uuid}`; every destructive method re-inspects lock and state at entry.
- New `RestoreAttachmentManifest` value object replaces the raw array input.

### Changed Files
- 36 files (29 added, 7 modified): the restore attachment classes and tests, `FakeRestoreProgressDurability` (additive hook), docs.

### Verification
- `--filter=Attachment` 323 passed, 3 pre-existing skips; `Restore|Backup` 738 passed, 1 order-dependent flaky failure (passes alone; fixed in the next entry).

### Commit Hash
Committed in the commit immediately following this entry (hash not recorded).

---

### Date
2026-07-26 (test-stability pass — stabilize backup restore regression tests)

### Task
Find and fix the root cause of the flaky `BackupCreationOrchestratorTest::test_creation_never_reaches_completed_when_pre_publish_verification_fails`.

### Result
- Test-isolation bug, not production: 7 methods of `RestoreAttachmentActivationServiceTest` acquired the real `BackupSubsystemLock` before their `try / finally`, so a failure could leak the `flock()` handle into later tests in the same process.
- Fixed by putting acquisition through assertions inside one `try` with `release()` in `finally`. No assertion weakened, nothing skipped. `once()` memoization reviewed: not involved.

### Changed Files
- `tests/Feature/Restore/Attachments/RestoreAttachmentActivationServiceTest.php` only.

### Verification
- Flaky test 10/10; `BackupCreationOrchestratorTest` 25/25; `Restore|Backup` 739 passed, 3 skipped, 0 failed in both default and random order.

### Commit Hash
Committed in the commit immediately following this entry (hash not recorded).

---

### Date
2026-07-26 (OMS Task 7C.7 — the real RestoreOrchestrator: full secure restore orchestration, compensation matrix, maintenance mode, mandatory safety backup, heartbeat/progress, stale-detection watchdog, recovery runbook)

### Task
Implement the real `RestoreOrchestrator` end to end (preflight → maintenance → mandatory full safety backup → decrypt / verify / stage → attachment activation → DB import → reconciliation → finalization → maintenance exit → terminal result), replacing `RestoreCommand`'s fail-closed placeholder.

### Result
- `RestoreOrchestrator` with an explicit Restored / RestoreFailed / RestorePartial compensation matrix; `RestoreMaintenanceMode` is the only `down` / `up` seam (tracks ownership); `RestoreTerminalResultWriter` centralizes terminal writes and tolerates a vanished row; `RestoreStaleDetector` + `oms:restore-watchdog` (every minute) are detection only.
- Two 7C.6 gaps closed additively: `AttachmentMoveRunner` container binding; `PreparedRestore` now carries the verified attachment manifest list.
- Operator recovery runbook written (now in AI_PROJECT_MEMORY → Operational Reference → Restore recovery runbook).

### Changed Files
- New orchestrator, maintenance-mode, terminal-writer, stale-detector, watchdog command and exceptions; `RestoreCommand`, `PreparedRestore`, `RestoreArchivePreparer`, `AppServiceProvider`, `BackupNotificationEvent`, `config/oms.php`, `bootstrap/app.php`; 4 new test files.

### Verification
- 7C.7 suite 44/44 (158 assertions); `Restore|Backup` 771 passed, 3 skipped, 0 failed in default and random order (one intermittent pre-existing flake traced to a hardcoded UUID shared by older test files).

### Commit Hash
Not committed at the time.

---

### Date
2026-07-27 (OMS Task 7C.7 final acceptance/hardening pass)

### Task
Acceptance / hardening of Task 7C.7 before commit: true long-phase heartbeats, a deterministic finalize-failure test, a real fix for the random-order flake, watchdog resilience to DB / cache outages, Graphify cleanup.

### Result
- `RestoreHeartbeat` threaded into every long phase through optional trailing `?callable $onTick` parameters (existing primitives unchanged); the `mysql` import uses `Process::start()` + a poll loop in `SymfonyProcessStreamInputRunner`.
- New `RestoreAttachmentLifecycle` interface enables a deterministic finalize-failure test.
- The hardcoded-UUID flake fixed (fresh UUID per test); `RestoreStaleDetector` / `RestoreWatchdogCommand` degrade gracefully when the DB or cache is unavailable.
- Found under stress: heartbeats exposed an intermittent Windows `rename()` failure in `RestoreProgressWriter` (4/10 runs) — fixed with a bounded Windows-only retry (config `progress_publish_retry_attempts` / `_delay_ms`), then 10/10 clean.
- Graphify output reverted to its pre-session state.

### Changed Files
- New `RestoreHeartbeat`, `RestoreAttachmentLifecycle`; `RestoreOrchestrator`, `RestoreTerminalResultWriter`, `RestoreProgressWriter`, `RestoreStaleDetector`, `RestoreWatchdogCommand`, the long-running backup / restore primitives (`$onTick`), `AppServiceProvider`, `config/oms.php`; new and updated restore tests.

### Verification
- Targeted 7C.7 suite 112/112 (350 assertions); the previously flaky test 10/10; `Restore|Backup` 782 passed, 3 skipped, 0 failed in default and two random orders.

### Commit Hash
Committed in the commit immediately following this entry (hash not recorded).

---

### Date
2026-07-27 (OMS Task 7C.9 — real local Windows/Laragon end-to-end backup/restore acceptance)

### Task
Real, destructive local acceptance of backup / restore on Windows / Laragon (real MySQL and disks): full backup, database-only / files-only / full restore cycles with independent markers and SHA-256 checks, failure and stale drills, smoke and integrity checks, cleanup, and one final full-suite run.

### Result
- One real E2E-only defect fixed: `RestoreMaintenanceMode::enter()` passed a `--message` option that this Laravel's `down` command does not have, so every real restore failed at preflight (invisible to the mocked tests).
- Windows limitation confirmed (not a defect): the detached launcher may not keep `oms:restore {uuid}` running; recovery = run it in the foreground for the same claimed row.
- All three restore scopes passed end to end through the real UI and orchestrator and reverted correctly; the corrupted-archive and stale-acknowledgment drills behaved as designed; a post-import partial failure was not injected (covered by 7C.7's deterministic tests).
- Temporary fixtures cleaned up; genuine backup / restore rows kept as evidence.

### Changed Files
- `RestoreMaintenanceMode` (option removed) and its unit test; docs. No migration.

### Verification
- Pre-flight confirmed `d686fe3` contained only `graphify-out/**`, a clean tree and a local DB.
- Financial integrity: 91/91 balance-guard tests; no dangling FKs or orphan lines; balances back to baseline after cleanup.
- Final full suite (the one allowed run): 1875 tests, 1869 passed, 0 failed, 6 pre-existing skips.

### Commit Hash
Not committed at the time.

---

### Date
2026-07-27 (OMS Task 8 — Financial & Database Integrity + Trial Balance Correctness Review)

### Task
Read-only integrity audit (FKs, uniqueness, numbering, indexes, multi-currency rules, orphans, persisted balances), a Trial Balance correctness review, a new read-only `oms:check-financial-integrity` command, and tests. Never call a multi-currency transaction unbalanced because raw amounts differ; never repair history silently.

### Result
- `debit_base` / `credit_base` are each line's own-currency amount (not a base-currency conversion); the checker replays `FinancialTransactionBalanceGuard`'s FX equation for 4-line exchanges.
- Real local DB clean (0 violations); `accounts.current_balance` reconciled with 0 discrepancies.
- Trial Balance "Phase 1" (period totals only) confirmed intentional; 14 new tests, including the 1000 → 2999.94 exchange case (no currency blending).
- Schema drift found (`bank_accounts`, `accounts.parent_id`, `transactions.bank_account_id` without migrations) — documented only (resolved in 8.1).
- `oms:check-financial-integrity` (`--json`, exit 0 / 1 / 2) with four checker collaborators; with approval: two supporting indexes and a shared 3-attempt retry-on-collision trait for the six transaction-number generators.

### Changed Files
- `GeneratesSequentialTransactionNumbers` trait, six Create pages, `app/Services/Integrity/*`, `CheckFinancialIntegrity` command, migration `2026_07_27_100000_add_supporting_indexes_for_financial_integrity.php`, new tests.

### Verification
- Task 8 suite 44/44; financial regression 234/234; real DB scan 0 violations; full suite 1919 tests, 1913 passed, 0 failed, 6 pre-existing skips.

### Commit Hash
Not committed at the time.

---

### Date
2026-07-28 (OMS Task 8.1 — Schema Drift + Dead Schema Reconciliation)

### Task
Classify from evidence the undocumented `bank_accounts`, `accounts.parent_id` and `transactions.bank_account_id` and reconcile them so `migrate:fresh` produces the intended schema.

### Result
- No code usage and no live data (the only historical `bank_accounts` row was purged by the 2026-07-06 reset); the creating migration was never in git → **DEAD_UNUSED**.
- Guarded migration `2026_07_28_100000_drop_dead_bank_accounts_schema.php` applied to local `oms` (pre-task dump taken); orphan `migrations` row removed; two dead checks removed from `DatabaseRelationshipIntegrityChecker`; stale note fixed in `OMS_Master_Reference.md`.
- Fresh install verified on a scratch database (real `oms` never `migrate:fresh`-ed). Two further drifts reported for later (handled in 8.2).

### Changed Files
- New drop migration; `DatabaseRelationshipIntegrityChecker`, `IntegrityTestFixtures`, its test; `OMS_Master_Reference.md`; local DB schema.

### Verification
- Focused suite 511 passed, 2 skipped; report authorization 58/58; integrity check OK (exit 0); smoke checks passed.

### Commit Hash
Not committed at the time; committed on 2026-07-28 as `08df090` remove dead bank account schema drift and `6ed42f9` refresh graphify after schema drift cleanup.

---

### Date
2026-07-28 (OMS Task 8.2 — Final Schema Drift Reconciliation)

### Task
Resolve `accounts.account_code` (NOT NULL live vs nullable fresh) and `accounts_type` (`nature`, `created_by`, `updated_by` without a migration) without changing data.

### Result
- `account_code` was always meant nullable (migration, form, every reader) → live column relaxed.
- `accounts_type.nature` unused and contradicts the documented "no account nature" design → DEAD_UNUSED, dropped.
- `accounts_type.created_by` / `updated_by` follow the `HasUserTracking` convention and hold data → ACTIVE_REQUIRED, reproduced as schema only.
- Migrations `2026_07_28_110000_make_account_code_nullable_on_accounts_table.php` and `2026_07_28_110001_reconcile_accounts_type_schema_drift.php`; a MySQL-only guard that broke SQLite tests was replaced by `Schema::getColumns()`. Zero data modified.

### Changed Files
- Two migrations; `OMS_Master_Reference.md`; local DB schema.

### Verification
- Focused 178/178 + 158/158; scratch-DB fresh install and rollback safe; integrity check OK (exit 0); counts and balances unchanged.

### Commit Hash
Not committed at the time; committed on 2026-07-28 as `5aea136` reconcile final account schema drift and `5063b86` refresh graphify after final account schema reconciliation.

---

### Date
2026-07-28 (OMS Task 8.3 — Accounts User-Tracking Schema Reconciliation)

### Task
Decide the status of `accounts.created_by` / `updated_by` (live, no migration) and make fresh installs match, without starting the Audit Log task.

### Result
- No code usage and always NULL, but classified ACTIVE_REQUIRED (matches `HasUserTracking` and the Audit Log plan).
- Guarded migration `2026_07_28_120000_reconcile_accounts_user_tracking_schema_drift.php` repairs only a missing column / FK (a no-op on local `oms`); `down()` deliberately irreversible. `Account` not wired to `HasUserTracking`.
- Scratch-DB scenarios passed, including `ON DELETE SET NULL`. Zero data modified.

### Changed Files
- One migration; `OMS_Master_Reference.md`.

### Verification
- Focused 178/178; fresh-vs-live `accounts` parity exact; integrity check OK (exit 0).

### Commit Hash
Not committed at the time.

---

### Date
2026-07-28 (Graphify hygiene — project-local `.graphifyignore`)

### Task
Stop Graphify from indexing runtime / uploaded / private / cached / secret-bearing paths and produce a deterministic, sensitive-path-free graph.

### Result
- New `.graphifyignore` (gitignore syntax, also honored by the post-commit hook): `storage/**`, `bootstrap/cache/**`, `public/storage/**`, `vendor/**`, `node_modules/**`, `.git/**`, `graphify-out/**`, `.env`, `.env.*`, `*.sql`; `resources/views/vendor/filament-panels/**` kept.
- `graphify update` does not prune newly excluded files from an existing `graph.json`; a clean rebuild needs the three outputs removed first (the dated snapshot also had to be synced).
- Two from-scratch rebuilds (`PYTHONHASHSEED=0`) byte-identical; zero sensitive-path hits; all legitimate sources still indexed.

### Changed Files
- `.graphifyignore` (new); `graphify-out/*`.

### Verification
- Determinism and sensitive-path checks as above; integrity check OK (exit 0). No PHPUnit (no code changed).

### Commit Hash
Not committed at the time.

---

### Date
2026-07-28 (OMS Task 9A — Audit Log design audit, read-only)

### Task
Read-only design phase for an immutable, searchable Audit Log.

### Result
- No audit package installed; `HasUserTracking` on 21 models (actor pointer only, not on `Account` / `AccountType`); no old / new value, security, attachment-access or export logging.
- Recommended a first-party hybrid (shared CRUD diffing + explicit domain calls): event taxonomy, `audit_events` schema, application-level immutability (no triggers), central redaction, bounded financial snapshots, system-actor typing, Super-Admin-only UI, an 8-phase plan (9B.1–9B.8).

### Changed Files
None.

### Verification
Read-only discovery only; clean tree.

### Commit Hash
N/A — no changes.

---

### Date
2026-07-28 (OMS Task 9B.1 — Audit Log foundation)

### Task
Build only the approved Audit Log foundation (schema, immutable model, redaction, bounding, actor context, REQUIRED vs BEST_EFFORT modes), with no wiring into existing code.

### Result
- Migration `2026_07_28_130000_create_audit_events_table.php` (append-only, stable `subject_type` alias never a FQCN, `actor_user_id` FK `ON DELETE SET NULL`); `AuditEvent` immutable at the Eloquent level (`AuditImmutableRecordException`).
- `AuditRedactor` (recursive segment-matched secret denylist; `encryption_key_id` the one exception); `AuditPayloadBounder` (1,000-char strings, 8,192 bytes per side by key dropping, `_truncated` marker); `AuditActorContext` (user / system / scheduler / queue / command); `AuditLogger::record()` (Required throws `AuditPersistenceException`, BestEffort logs and returns null; never opens its own transaction, so it commits / rolls back with the caller).
- Three enums and three exceptions. Two bugs in the new tests fixed.

### Changed Files
- Migration, model, `app/Services/Audit/*`, enums, exceptions, `tests/Feature/Audit`, `tests/Unit/Services/Audit`; `OMS_Master_Reference.md`. Local `audit_events` table created (empty).

### Verification
- Audit suites 53/53 (197 assertions); integrity check OK (exit 0); row counts unchanged.

### Commit Hash
Not committed at the time.

---

### Date
2026-07-28 (OMS Task 9B.2 — Audit Log: general CRUD integration)

### Task
Audit created / updated / deleted / restored events, in Required mode and atomic with the change, for eleven master-data models only (`Project`, `ProjectCost`, `Partner`, `PartnerType`, `ProjectSuper`, `ProjectStatus`, `BankType`, `FiscalYear`, `TransactionType`, `TransactionSuperType`, `Setting`).

### Result
- Key finding: no Filament write path runs in a DB transaction, so observers would fire after commit. Auditing is therefore owned by `AuditedCrudService`, which wraps the mutation and the audit insert in one `DB::transaction()`.
- `app/Services/Audit/Crud/`: `AuditedCrudService`, `AuditSubjectRegistry` (closed model → alias map; unregistered models throw), `AuditSubjectDefinition`, `AuditModelSnapshotter` + `AuditFieldDiff`, `SettingValuePolicy` (credential-shaped values `[REDACTED]`); Filament seam `AuditsRecordCreation`, `AuditsRecordUpdate`, `AuditedActions` (incl. a per-record `deleteBulk()`).
- Closed per-subject column allowlists; `created` = full snapshot, `updated` = changed fields only (no event if nothing audited changed), `deleted` = pre-delete snapshot, `restored` = post-restore snapshot. Seeders / fixtures write directly and produce no history.

### Changed Files
- New audit CRUD services, concerns and tests; 34 Filament files (11 Create, 11 Edit, 11 tables, `CostsRelationManager`). No migration, model or policy change.

### Verification
- `tests/Feature/Audit` 86/86 (486 assertions); Crud / Filament / Policies 117/117; Permissions 338 passed, 3 skipped (pre-existing); integrity check OK; `audit_events` still 0 rows.

### Commit Hash
Not committed at the time.

---

### Date
2026-07-28 (OMS Task 9B.2 review corrections — Setting semantic field, ProjectCost old-side relation label, Filament lifecycle regression proof)

### Task
Fix two review findings without weakening `AuditRedactor`, and prove the Filament wrappers keep the full lifecycle.

### Result
- `Setting` key renames now record both names via a field alias `key` → `setting_name` (`AuditSubjectDefinition::$fieldAliases`); values still `[REDACTED]` when credential-shaped.
- `ProjectCost.project_id` changes label each side from its own id (`Project::withTrashed()`, three columns only; memoized per action).
- Lifecycle traced against Filament sources: authorization, hooks, events, validation, relationship saving, notifications, bulk failure reporting and deselection all preserved; new `AuditedFilamentLifecycleTest` (7 tests).

### Changed Files
- `AuditSubjectDefinition`, `AuditModelSnapshotter`, `AuditSubjectRegistry`; audit tests; `OMS_Master_Reference.md`.

### Verification
- `tests/Feature/Audit` 98/98 (557 assertions); Crud / Filament / Policies 117/117; integrity check OK; registry still exactly the eleven models.

### Commit Hash
Not committed at the time.

---

### Date
2026-07-28 (Graphify cache-tracking hygiene — untrack `graphify-out/cache/**`)

### Task
Remove the tracked generated Graphify cache from git (it held absolute local paths, including backup file names).

### Result
- `graphify-out/cache/stat-index.json` had 1,511 keys, all absolute machine paths (695 under `storage\`), because Graphify keys that index on resolved paths.
- The cache is fully generated and nothing depends on it being tracked.
- `/graphify-out/cache/` added to `.gitignore`; `git rm --cached -r graphify-out/cache` (2,157 files untracked, kept on disk). No redaction or scrubber.

### Changed Files
- `.gitignore`; index removal of `graphify-out/cache/**`. No code or backup file touched.

### Verification
- `git check-ignore` matches; 0 tracked cache files; `graphify update .` works and leaves 0 untracked cache entries; integrity check OK. No PHPUnit.

### Commit Hash
Not committed at the time.

---

### Date
2026-07-28 (Graphify hygiene extension — untrack dated snapshots, exclude `.claude/**`, clean deterministic rebuild)

### Task
Untrack the dated `graphify-out/20*/` snapshots, stop indexing `.claude/**`, and rebuild deterministically.

### Result
- Dated snapshots are write-only backups made by Graphify before an overwrite; nothing reads them, and git history (75 graph commits) is better retention. They also carried the largest remaining leak (2,681 `storage/app` references and more).
- `/graphify-out/20*/` added to `.gitignore` (75 files untracked, kept on disk); `.claude/**` added to `.graphifyignore` (root `CLAUDE.md` still indexed).
- Two from-scratch rebuilds (`PYTHONHASHSEED=0`) byte-identical (820 files, 12,480 nodes).
- Reported, not applied: `graphify-out/.graphify_python` is tracked and holds a machine-local interpreter path.

### Changed Files
- `.gitignore`, `.graphifyignore`, regenerated `graphify-out/` root outputs.

### Verification
- 0 tracked snapshot / cache files; 0 sensitive-path hits in tracked outputs; coverage intact; integrity check OK. No PHPUnit.

### Commit Hash
Not committed at the time.

---

### Date
2026-08-01 (Root route redirect to the Filament Admin login)

### Task
Make `/` redirect to the Filament login on both the production host and the local `/oms/public` subdirectory, without hard-coding hosts, paths or `APP_URL`.

### Result
- Route name verified (`filament.admin.auth.login`); `GET /` now returns `redirect()->route('filament.admin.auth.login')`. A named-route redirect is built on the incoming request's own scheme, host and base path, so it works under both deployments.
- All other routes unchanged (125); `welcome.blade.php` left on disk, unrouted.
- Note: `APP_URL` here is `http://172.16.0.100/oms/public`, so tests must force the root URL (`URL::forceRootUrl('http://localhost')`), as the existing suite does.

### Changed Files
- `routes/web.php`; new `tests/Feature/Routing/RootRedirectTest.php`.

### Verification
- `RootRedirectTest` 3/3 (7 assertions), including the base-path case; `route:list` unchanged; integrity check OK.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-20 (تقرير الحركات المالية الشامل — multi-account filter, نوع البنك, all notes, expandable classifications, dual scrollbars, description de-duplication)

### Task
Six approved changes to `ComprehensiveFinancialTransactionsPage`: expandable classification rows (no new queries), synchronized top / bottom scrollbars, multiple account selection, `نوع البنك` per line, every linked notes source (labelled), and the fix for the double-rendered `transactions.description`.

### Result
- All six done; accounting fields and formulas untouched (the only change in `accumulateGrouped()` is the bucket key).
- Deliberate change: groups are keyed by ID, not display name (names are not unique), so two same-named classifications show as two rows; totals unchanged.
- Source notes are pre-fetched with ≤ 5 bounded `whereIn()` queries and merged after accumulation, so one-to-many sources never duplicate lines or totals. Expanding a classification costs 0 `transaction_lines` queries.

### Changed Files
- The comprehensive page, report service, Blade view, Excel and Word export services; new `ComprehensiveFinancialTransactionsReportServiceTest` (18) and `…PageTest` (10). No migration.

### Verification
- Report service 18/18, page 10/10, `tests/Feature/Reports` 100/100, with audit reports 122/122; Excel / Word exports read back (new columns present, double render gone); Blade and Alpine syntax checked.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-20 (تقرير الحركات المالية الشامل — expanded-classification presentation pass + targeted expand spinner)

### Task
Presentation only: a more compact, visually subordinate expanded classification table, a smaller currency pill, a tighter notes hierarchy, and a per-row loading spinner on the classification toggle.

### Result
- Done in the Blade view only. `.cft-subtable` overrides the inherited font / zebra / hover rules (same specificity, wins on source order — the block must stay below `.cft-table`); five new sub-table tokens for light and dark; `.cft-badge-xs`; scoped note styles (no truncation).
- The spinner uses `wire:target="toggleCategory('<key>')"` with the same argument as `wire:click`, so Livewire v4 scopes the loading state to the clicked row; `wire:loading.attr="disabled"` blocks double clicks. No new query.

### Changed Files
- `resources/views/filament/pages/comprehensive-financial-transactions-page.blade.php` only.

### Verification
- `ComprehensiveFinancialTransactionsPageTest` 10/10 (50 assertions); compiled Blade lint clean; Livewire parameterised targeting confirmed in the vendor source.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-22 (المصروفات العامة — removal of the `الجهة / المستفيد` field; new records store NULL, historical values preserved)

### Task
Audit, then remove the beneficiary field from the General Expense form: new records store NULL, historical beneficiaries survive edits, no migration, no report change.

### Result
- No migration needed: both `general_expenses.partner_id` and `transactions.partner_id` were already nullable (`ON DELETE SET NULL`), verified live; the form's `->required()` was the only enforcement.
- Create now writes `$data['partner_id'] ?? null` (a bare key would have thrown). Edit preserves the record's own value (read before the transaction, written to both tables) and ignores any submitted `partner_id`.
- Table column and view entry show `—` for NULL; the column is toggleable, hidden by default.
- Reports confirmed unaffected (no report reads either `partner_id`). One audit test was inverted to prove a submitted `partner_id` is ignored.

### Changed Files
- `GeneralExpenseForm`, `CreateGeneralExpense`, `EditGeneralExpense`, `GeneralExpensesTable`, `ViewGeneralExpense`; `GeneralExpenseAuditTest`; new `GeneralExpensePartnerOptionalTest` (10).

### Verification
- New test 10/10 (38 assertions); `tests/Feature/GeneralExpenses` 28/28; related audit / attachment / redirect suites all pass; live DB unchanged.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-22

### Task
Add a permanent `نوع البنك` column beside `الحساب` on the Transaction Lines list (`سطور المعاملات`): always visible, `—` when empty, no N+1.

### Result
- Column `account.bankType.name` (badge, placeholder `—`), not toggleable; display-only (not sortable or searchable, to avoid two-hop joins).
- `account.bankType` added to `TransactionLineResource::getEloquentQuery()` eager loads: a 25-row page runs 5 queries, 0 per row.

### Changed Files
- `TransactionLinesTable`, `TransactionLineResource`.

### Verification
- Manual checks on the live DB: eager loads, query count, values, null paths (nothing saved), column order and visibility, filters / sort / SoftDeletes intact. No dedicated test existed.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-22

### Task
Widen Transaction Lines global search to related transaction / classification / type / account / bank type / currency data, and make the lookup filters searchable — database-side, paginated, no N+1, no joins.

### Result
- Global search moved to table-level `->searchable([...])`, which also fixed a latent bug: Filament skips hidden columns, so `notes` (hidden by default) was never searchable.
- 14 entries: line description / notes, transaction number / reference / description, type and classification names, account code / name / bank type, currency code / name, plus closures for the Arabic `line_role` label (resolved to enum values in PHP) and exact numeric amounts (separators stripped; non-numeric terms add nothing). `fx_rate` deliberately excluded.
- Filters 2 → 8 (5 searchable); classification / type via scoped `whereHas`; the type list narrows to the classification being edited.

### Changed Files
- `TransactionLinesTable`; new `TransactionLinesTableSearchTest` (29); `graphify-out/`.

### Verification
- New test 29/29 (73 assertions); generated SQL: 0 joins, correlated `EXISTS`, SoftDeletes intact; every probe and filter matched an independent ground-truth query on live data; 0 per-row queries; related suites pass; integrity check OK.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-22

### Task
Add `transaction.notes` to the Transaction Lines global search if it is a real, used field.

### Result
- `transactions.notes` is user-entered (`ملاحظات` on the financial create pages) and shown by the comprehensive report, so it was added — one line; nothing else changed.

### Changed Files
- `TransactionLinesTable`; `TransactionLinesTableSearchTest` (+1 test); `graphify-out/`.

### Verification
- Search test 30/30 (75 assertions); SQL still 0 joins; live notes searches return exactly their transactions' lines.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-26

### Task
Make `OperationalDataCleanupService` the complete definition of an OMS operational reset (all operational data deleted; settings, lookups, users, permissions, migrations, audit and backup history kept), then stop with a pre-execution report.

### Result
- `accounts` and `partners` became operational (deleted), confirmed with the user; six tests inverted.
- Deletion order derived from the 102 live foreign keys: the three RESTRICT children of `accounts` (`transaction_lines`, `muwakha_families`, `muwakha_family_accounts`) are emptied first; a check walks every RESTRICT edge.
- The three Muwakha tables (operational) and `audit_events`, `backup_operations`, `notifications` (preserved) classified; all 46 live tables classified.
- Marker settings matched by the prefix `restore_e2e_20260727_`; orphan role / permission rows by morph class + `whereNotExists` (no hard-coded ids).
- Files: honours each attachment's disk and refuses to delete a file on a disk the backup does not cover; `--skip-files`; file deletion runs after the DB commit.
- `verify()` rewritten around expected deltas. Production guard unchanged; a TTY-only confirmation with `--force` to skip it.

### Changed Files
- `OperationalDataCleanupService`, `OperationalCleanupReport`, `CleanOperationalData` command; `CleanOperationalDataCommandTest` (20 → 31 tests). No schema or data change. `graphify` CLI was not available.

### Verification
- Read-only structural checks against live `oms`: 9/9 pass, zero writes. Live `audit()`: 1,409 rows to delete, 3 markers, 2 orphan role rows, 0 files.
- **Not proven:** the test file could not run on this machine (no dev dependencies / phpunit, no `pdo_sqlite`).

### Commit Hash
Not committed at the time.

---

### Date
2026-09-26 (execution)

### Task
Run the approved operational reset on the live production `oms` database behind a narrow one-time production gate, without weakening the global safeguard.

### Result
- Executed; post-apply verification PASSED: 1,409 operational rows removed; preserved tables intact; schema unchanged (46 tables).
- `ALLOWED_ENVIRONMENTS` unchanged; new per-invocation `--allow-production` unlocks only `production` and also requires a fresh (≤ 120 min), completed, verified, full backup (`assertProductionBackupIsFreshAndVerified()`). Each gate was proven to refuse on its own first.
- A verified full backup was taken first. Special cleanup matched the audit (3 markers, 2 orphan role rows). `audit_events` grew only by the backup's own two events. No files deleted (none existed).

### Changed Files
- `OperationalDataCleanupService`, `CleanOperationalData` command.

### Verification
- Independent read-only re-check: 18 emptied tables at 0, 21 preserved tables at expected counts, 0 markers / orphans, 46 tables before and after.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Search Batch 0: scope Attachments global search to the list's visibility rules, and make both project pickers search the code they display (after a read-only whole-application search audit).

### Result
- The audit's attachment "security" finding was wrong (Filament drops results without a viewable URL), but global search did show attachments of deleted / missing parents and wasted the 50-result limit; the user chose to apply the scope anyway. `AttachmentResource::getGlobalSearchEloquentQuery()` reuses `FinancialAttachmentRegistry::scopeViewableBy()`.
- `ProjectForm.project_super_id` and `ProjectCostForm.project_id` now `->searchable(['code', 'name'])`; still one server query, limit 50.

### Changed Files
- `AttachmentResource`, `ProjectForm`, `ProjectCostForm`; new `AttachmentGlobalSearchScopeTest` (6), `ProjectSelectCodeSearchTest` (10).

### Verification
- 6/6 and 10/10; both fail without the fix; `AttachmentRegistryTest` 41/41, `AttachmentResourceHardeningTest` 8/8.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Search Batch A: create one `ArabicSearch` helper and prove it on `سطور المعاملات`.

### Result
- `app/Support/Search/ArabicSearch.php`: NFC + harakat / tatweel / zero-width removal, Arabic-Indic / Persian digits → ASCII, whitespace collapse; alef variants (U+0623, U+0625, U+0622, U+0671) folded to U+0627 on both term and column (nested `REPLACE`); LIKE wildcards escaped with `ESCAPE '!'`; Arabic `line_role` labels matched after normalization (machine values only for Latin terms); strict amount parsing (`١٥٠٠` = `1500`).
- Identifiers (`transaction_number`, `reference`, `account_code`, currency code) cleaned but never alef-folded. Not folded by decision: ى/ي, ة/ه, ؤ/و, ئ/ي, ک/ك, ی/ي.
- One EXISTS per relation group: 6 per word instead of 13, no joins.
- Local before / after on 42 terms: 38 identical; `اداري` / `ادارية` 0 → 3 (intended); `%` 22 → 0; `_` 22 → 0 after the correction pass.
- Correction pass: the alef direction was already correct (the report wording was bidi-garbled); NFC added; Graphify regenerated on the final tree.

### Changed Files
- New `ArabicSearch` and `ArabicSearchTest`; `TransactionLinesTable` (4 grouped closures); `TransactionLinesTableSearchTest`.

### Verification
- `ArabicSearchTest` 63/63 (93 assertions); `TransactionLinesTableSearchTest` 47/47 (154); before-fix proof showed exactly the new tests failing; MySQL SQL shape: 0 joins, 6 EXISTS, terms only in bindings.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Search Batch B: apply `ArabicSearch` to Accounts, Projects, Partners, ProjectSupers, ProjectCosts, the eight lookup tables, and topbar global search for accounts and projects.

### Result
- Table-level closures: human text alef-folded; codes, emails and IBANs as identifiers; one EXISTS per relation, no joins.
- Accounts: code, name, IBAN (spaces ignored), account type, bank type. Projects: code, name, donor. Partners: name / city / country, email, phone (Arabic digits; spaces and `- + ( )` ignored). ProjectSupers: code, name. ProjectCosts: project code / name, account type.
- Lookups that already had a name search (account / bank types, currencies, transaction super types / types, project statuses, partner types, fiscal years) now use text semantics; no new search boxes.
- Global search for Accounts and Projects: every word must match the code or the name; SoftDeletes and `canView()` untouched.
- `ArabicSearch` gained `whereContainsCompactIdentifier()`, `phoneDigits()`, `whereContainsPhone()`, `words()`.
- Reported only: master / lookup pages have no `defaultSort` (fall back to `id asc`), unlike `defaultSort('id','desc')` on 9 resource tables.

### Changed Files
- `ArabicSearch`; 13 tables; `AccountResource`, `ProjectResource`; `ArabicSearchTest` (+15); new `MasterDataTableSearchTest` (34); `graphify-out/`.

### Verification
- `ArabicSearchTest` 78/78; `MasterDataTableSearchTest` 34/34 (140 assertions); before-fix proof without `git stash`; MySQL SQL shape checked.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Search Batch C1: real search and bounded project / partner filters on the five financial operation lists (صرف مبالغ التنفيذ, صرف مبالغ المشاريع, المبالغ المستلمة, التحويلات العامة, مصروفات عامة).

### Result
- Before: all five searched only `transaction_number`; project / partner filters preloaded every row.
- New `FinancialListSearch` (`transaction()`, `project()`, `ownPartner()`, `amounts()`): transaction number and line account codes (identifiers), partner / type / account / project / super names (text), exact amounts; all through EXISTS, never joins. Never searched: `fx_rate`, percentages, derived amounts, dates, IDs, `reference`.
- New `FinancialLookupFilters`: server-side search via `ArabicSearch`, ≤ 50 options, `preload(false)`, projects labelled `code - name`; each page's own filter semantics kept (incl. receipts' donors-only rule).

### Changed Files
- New `FinancialListSearch`, `FinancialLookupFilters`; the five list tables; new `FinancialListsSearchTest` (27); `graphify-out/`.

### Verification
- `FinancialListsSearchTest` 27/27 (235 assertions), 17 fail on the old code; MySQL: 0 joins, terms only in bindings, `order by id desc` unchanged. Pre-existing per-row N+1 noted for C2.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Batch C2: remove the display N+1 on the five financial lists and the ProjectCosts receipts relation manager.

### Result
- `إشعار مرفق` badge: `withExists('attachments')` in each resource's `getEloquentQuery()` and the relation manager (reads `attachments_exists`).
- Receipt debit / credit account columns: `transaction.lines.account` eager-loaded; new `ProjectCostReceiptsTable::debitAccountName()` / `creditAccountName()` pick from loaded lines with the unchanged rule.
- Query counts now flat in rows (e.g. receipts 17 / 37 / 62 → 14 / 14 / 14 for 1 / 5 / 10 rows; relation manager 10 / 28 / 53 → 7 / 5 / 5).

### Changed Files
- The five financial resources and tables, `ReceiptsRelationManager`; new `FinancialListsQueryCountTest` (6); `graphify-out/`.

### Verification
- Query-count test 6/6 (96 assertions); `FinancialListsSearchTest` 27/27; `RelationManagerAuthorizationTest` 8/8; MySQL renders show 0 column queries.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Search Batch D: Muwakha family list search and topbar global search, including the explicit person-name ى/ي fold.

### Result
- List search (three table-level closures, placeholder `ابحث في أسر المؤاخاة...`): martyr / guardian / account-holder names as person names; national IDs as identifiers (zeros kept, partial allowed); guardian phone; the CURRENT account's code, IBAN (compact) and bank type through one EXISTS (`withTrashed()`); card code and project code / name through one EXISTS on the project links. The account history table is not searched.
- `ArabicSearch` gained `normalizePersonName()`, `foldPersonNameSql()`, `whereContainsPersonName()`: U+0649 ى → U+064A ي on person names only (ة/ه still never folded; project / bank names not folded).
- Global search: martyr name, martyr national ID, card code (EXISTS); every word required; only card codes shown as detail (no ID or phone). Filters unchanged.
- Direction check: the fold direction was already correct; independent code-point tests added.

### Changed Files
- `ArabicSearch`, `MuwakhaFamiliesTable`, `MuwakhaFamilyResource`; `ArabicSearchTest`; new `MuwakhaFamilySearchTest`; `graphify-out/`.

### Verification
- `ArabicSearchTest` 84/84 (124 assertions); `MuwakhaFamilySearchTest` 23/23 (107); `MuwakhaFamilyResourceTest` 21/21; 15 of 22 new tests failed on the old code; MySQL: 0 joins, 4 EXISTS, 0 per-row queries.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Search Batch E: large lookup Selects on three reports and three financial forms move from "load every row, filter in the browser" to bounded, database-side, Arabic-aware search.

### Result
- Converted (`getSearchResultsUsing` + label resolvers, nothing preloaded, ≤ 50 results): comprehensive report `account_ids` (code / name / IBAN, `code - name`) and `project_id` (now `code - name`); donor report `donor_id` (donors only) and `project_id` (projects of the live donor); general projects page `project_id` / `donor_id` filters (snapshot rows); `partner_id` on the execution-payment, general-exchange and receipt forms (receipt: donors only).
- Label resolvers apply the old eligibility scope, so Filament's `in` validation still rejects ineligible / deleted / stale values. Short static lists left unchanged.
- `FinancialLookupFilters` gained an optional project scope and public `projectLabel()` / `partnerLabel()`.

### Changed Files
- `FinancialLookupFilters`; the comprehensive, donor and projects-general pages; three financial forms (`partner_id` only); new `LookupSelectSearchTest` (18); `graphify-out/`.

### Verification
- `LookupSelectSearchTest` 18/18 (128 assertions), 15 fail on the old code; directly affected page / export / form tests all pass; MySQL: 0 options shipped, one `LIMIT 50` query per search.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Search Batch F: final search consistency — المعاملات المالية list, noisy topbar global-search keys, Arabic-aware search on the remaining admin lists, placeholders.

### Result
- Transactions list: one table-level search over number / reference (identifiers), description / notes (text), type and partner names (EXISTS); placeholder `ابحث في المعاملات المالية...`.
- New trait `SearchesGloballyWithArabicSearch` (`globalSearchFields()` as `column => text|identifier`). Improved: Transactions, Users, Attachments (scope kept), Partners, Roles, the lookups, Currencies, ProjectSupers. Disabled: ExchangeRateHistories, AuditEvents, Permissions, Settings. Accounts, Projects, MuwakhaFamilies keep their own overrides.
- Lists: Attachments, Audit log, Users (role names via EXISTS), Roles, Permissions (Arabic label via `containsNormalized()`); Settings placeholder only.
- Sorting inventory only (unchanged).

### Changed Files
- New trait; 18 resources (global search only); 7 tables; new `RemainingSearchConsistencyTest` (17); `graphify-out/`.

### Verification
- `RemainingSearchConsistencyTest` 17/17 (79 assertions), 15 fail on the old code; 15 directly affected suites all pass; MySQL: 0 joins, terms only in bindings, no per-row queries.

### Commit Hash
Not committed at the time.

---

### Date
2026-09-28

### Task
Search Batch G: close the four gaps left by the final read-only search audit.

### Result
- Checked against the source after the Batch F commit `20ac78f`: all four findings still applied.
- الصفحة العامة للمشاريع: one table-level `ArabicSearch` over the snapshot's own columns (code as identifier, names as text; no relation); placeholder added.
- صرف مبلغ form `partner_id`: the Batch E server-side pattern (all partners, ≤ 50).
- Attachments `project` filter: `FinancialLookupFilters::project()` (same `whereHasMorph` condition).
- Permissions `roles.name`: `whereHas('roles')` with text semantics.

### Changed Files
- `ProjectsGeneralFinancialPage`, `ProjectCostBudgetsPaymentForm`, `AttachmentsTable`, `PermissionsTable`; new `FinalSearchGapsTest` (12); `graphify-out/`.

### Verification
- `FinalSearchGapsTest` 12/12 (64 assertions), 9 fail on the old code; 16 directly affected suites all pass; MySQL query counts flat across page sizes.

### Commit Hash
Not committed at the time.

---

### Date
2026-10-05

### Task
Build a complete, isolated, realistic OMS stress environment (`oms_stress`, ≥ 500,000 transaction lines), verify it, and benchmark the real application against it.

### Result
- Done on TEST/DEV as a separate diff from `f6ec0b8`.
- Small smoke build: 12,217 lines, 55/55 verifier checks at 0. Large build (seed 20261005, chunk 500): 561,831 active lines, 255,819 transactions, 284,618 audit events; 1,500 s; peak 324 MB; ~1.0 GB. Large verification: 55/55 checks, 0 violations.
- `oms` unchanged except one session row.
- Benchmarks (single runs on this laptop; moved here from the removed `docs/STRESS_ENVIRONMENT.md`):

  | Case | Time | Notes |
  |---|---:|---|
  | Comprehensive report, full range (561,706 lines), snapshot build | 301 s | worker peak 599 MB; queued streaming Excel 387 s |
  | Account statement (old page), busiest account — full / 12 m / 1 m | 36.0 / 23.7 / 12.0 s | full = 90 MB HTML |
  | Donor report, top donor, full | 20.9 s | 28 MB HTML |
  | Trial balance USD, full | 10.1 s | |
  | Transaction lines Arabic search | 36–41 s | one `COUNT(*)` with `REPLACE … LIKE`, full scan |
  | `oms:check-financial-integrity` | 460 s | journal checker 345 s |

- Stress-tooling bugs fixed during the work (e.g. PRNG int overflow, MySQL 65,535-placeholder cap, Muwakha child rows buffered before their family row).

### Changed Files
- New: `config/oms_stress.php`, `app/Console/Commands/Stress/*`, `app/Services/Stress/**` (guard, installer, generator, verifier), stress tests, `.env.stress.example`.
- Modified: `.gitignore` (`/.env.stress`, `/storage/stress/`), `AdminPanelProvider` (stress-only banner).

### Verification
- `tests/Feature/Stress` + snapshot page test 45/45 (498 assertions); `oms:stress:verify` 55 checks, 0 violations; live refusals under `local` confirmed.

### Commit Hash
Not committed at the time.

Follow-up (2026-10-05): the stress-only password was rotated for the 8 synthetic users in `oms_stress` only (it had appeared in a report); the new one is not recorded anywhere.
