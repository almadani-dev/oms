# Deployment checklist — تقرير الحركات المالية الشامل (snapshot + background build + large Excel)

Prepared 2026-10-04. **Nothing in this file has been executed against production.**
No schema migration is required: snapshots, build status and prepared workbooks are private files; the queue uses the existing `jobs` / `failed_jobs` tables.

---

## 0. Before deploying (read-only, no production writes)

1. **Read-only EXPLAIN of the two batch queries** on production-sized data. Use a read-only DB user, or a session with `SET SESSION TRANSACTION READ ONLY;`.

   Transaction page. Expect `range` on `transactions_transaction_time_index`, **no filesort**:
   ```sql
   EXPLAIN SELECT t.id, t.transaction_time FROM transactions t
    WHERE t.deleted_at IS NULL
      AND t.transaction_time BETWEEN '<from> 00:00:00' AND '<to> 23:59:59'
      AND t.transaction_time >= '<cursor_time>'
      AND (t.transaction_time > '<cursor_time>' OR (t.transaction_time = '<cursor_time>' AND t.id > <cursor_id>))
    ORDER BY t.transaction_time, t.id LIMIT 1000;
   ```

   Line slice. Expect `transaction_lines` read through `transaction_lines_transaction_id_foreign` (ref/range on the ≤1000 ids). A filesort here is bounded by one batch and acceptable. It must **not** start with a full scan of `accounts` joined to all lines:
   ```sql
   EXPLAIN SELECT tl.id FROM transaction_lines tl
    JOIN transactions t ON t.id = tl.transaction_id
    JOIN accounts a ON a.id = tl.account_id
    WHERE tl.transaction_id IN (<≤1000 ids from the query above>)
      AND tl.deleted_at IS NULL AND t.deleted_at IS NULL AND a.deleted_at IS NULL
    ORDER BY t.transaction_time, t.id, tl.id LIMIT 1000;
   ```

   If the second plan starts from `accounts` on real data, stop and report. Do not add hints in production without review.

2. Confirm PHP CLI `memory_limit` ≥ 256M for the worker. Measured peaks: build ≈ 7.4 MB, streaming XLSX ≈ 1.2 MB.

3. Record the current values of `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER`, and whether `schedule:run` and any `queue:work` are running.

## 1. Queue worker (required)

The report build and the large Excel export run on the queue **`reports`**. Backups keep their own queue **`backups`**. Run one supervised worker per queue so neither waits behind the other.

- `.env` (production):
  ```
  QUEUE_CONNECTION=database
  DB_QUEUE_RETRY_AFTER=3900        # must exceed the longest job timeout (backups: 3600 s)
  OMS_CFT_QUEUE=reports            # default
  OMS_CFT_JOB_TIMEOUT=1800         # default
  ```
  Then `php artisan config:cache`.
- **Why `retry_after` changes:** today it is 90 s while backup jobs may run 3600 s. With exactly one worker per queue this has not caused duplicates. Any second worker on the same queue would re-run a job still in progress. Raising it removes that trap for both subsystems.
- Supervisor (`/etc/supervisor/conf.d/oms-queue.conf`). Adjust the path and user to the VPS:
  ```ini
  [program:oms-queue-reports]
  command=php /var/www/oms/artisan queue:work database --queue=reports --tries=1 --timeout=1800 --sleep=3 --max-time=3600 --memory=512
  user=www-data
  numprocs=1
  autostart=true
  autorestart=true
  stopwaitsecs=1810
  redirect_stderr=true
  stdout_logfile=/var/www/oms/storage/logs/queue-reports.log

  [program:oms-queue-backups]
  command=php /var/www/oms/artisan queue:work database --queue=backups --timeout=3600 --sleep=3 --max-time=7200 --memory=512
  user=www-data
  numprocs=1
  autostart=true
  autorestart=true
  stopwaitsecs=3610
  redirect_stderr=true
  stdout_logfile=/var/www/oms/storage/logs/queue-backups.log
  ```
  - Worker `--timeout` stays below `retry_after`.
  - `stopwaitsecs` lets a running job finish on restart.
  - The systemd equivalent is one unit per queue with `Restart=always`, the same command, and `TimeoutStopSec` = timeout + 10.
- On every deploy run `php artisan queue:restart`, so workers load the new code after their current job.
- **Compatibility with backups:**
  - Report jobs never use the `backups` queue, so `RestoreEphemeralTablePolicy` (which clears unstarted `backups` jobs) never touches them.
  - A restore replaces the `jobs` table. A report job that survives or reappears finds its snapshot directory gone and exits without work.
  - Workers do not process jobs in maintenance mode, so report builds wait during a restore. The page then shows "انتهت مهلة إعداد التقرير" after 30 min and the user presses "عرض" again.

## 2. Scheduler (required)

- The `cron` entry must exist; the backups already depend on it:
  ```
  * * * * * cd /var/www/oms && php artisan schedule:run >> /dev/null 2>&1
  ```
- `php artisan schedule:list` must show `oms:purge-report-snapshots` hourly. The command is synchronous, needs no queue, and is `withoutOverlapping()->onOneServer()`.

## 3. Storage

- Directories (created on first use, but create them with correct ownership up front):
  - `storage/app/private/report-snapshots` — snapshots, build status, prepared large workbooks
  - `storage/app/private/report-exports` — short-lived local temp files while a workbook is written
- Owner: the PHP-FPM user and the worker user must be the **same** user (e.g. `www-data`). Use mode 0700/0750; never web-served or symlinked.
- Capacity: ≈ 785 B per report line per live snapshot (150k lines ≈ 118 MB), plus ≈ 66 B per line for a prepared workbook until downloaded. Snapshots expire after 240 min; workbooks are removed after download or after 60 min.

## 4. Verification after deploy (no financial writes)

1. As a user with view + export permission, open the report, choose a short period and press "عرض". You should see "جاري إعداد التقرير..." and then the report. `storage/logs/queue-reports.log` shows the job processed.
2. Compare the summaries with the previous release for the same period. They must be identical.
3. Expand a classification and a transaction type, and change pages. The report must not reload.
4. Excel on a ≤ 2,000-line report: the styled file downloads directly. On a larger period: "جاري تجهيز ملف Excel..." and then a download. In both cases exactly one `export_requested` audit event is written per download.
5. Word above 2,000 lines shows the Arabic limit message and writes no audit event.
6. After 60–240 min, `php artisan oms:purge-report-snapshots` reports removals and the two directories shrink.
7. `failed_jobs` stays empty. If not, inspect it; report jobs are `tries=1` and never retried automatically.

## 5. Failure / retry policy

- Build and export jobs: `tries=1`, `failOnTimeout`, timeout 1800 s.
- A failure removes every partial file and shows an Arabic message. The user presses "عرض" or "تصدير Excel" again.
- A stuck job: pending > 30 min or processing heartbeat silent > 10 min is shown as expired.
- No job ever writes financial data.

## 6. Runtime limits

- The HTTP requests are now small: "عرض" ≈ 0.1–0.3 s, polls ≈ 40–200 ms, downloads stream a prepared file. No web request generates a large report or workbook.
- The styled Excel (≤ 2,000 lines) and Word (≤ 2,000 lines) still run inside the request. Measured on the dev laptop: ≤ ~20 s and ≤ ~125 MB. PHP-FPM `max_execution_time` ≥ 60 and `memory_limit` ≥ 256M keep a margin.
- Worker jobs are bounded by `--timeout=1800`.

## 7. Rollback

1. `git revert` the release (or redeploy the previous tag), then `php artisan config:cache` and `php artisan queue:restart`.
2. The previous release builds the report in-request and ignores the new directories. Afterwards delete `storage/app/private/report-snapshots` and `storage/app/private/report-exports`; they contain only regenerable report data.
3. The `reports` worker can be stopped (`supervisorctl stop oms-queue-reports`). No database rollback is needed; nothing in this change touches the schema or financial tables.

---

# Addendum (2026-10-05) — real-time reporting projection

Prepared 2026-10-05. **Nothing here has been executed against production.** This release **adds a schema migration**: `2026_10_05_100000_create_comprehensive_report_projection_tables` creates 5 new tables. It is additive only: no existing table, column, index or financial row changes, and there are no foreign keys.

## P1. What changes for users

- **"عرض" while the projection is READY (live mode):**
  - The summaries come from bounded aggregate queries on the projection.
  - Every page, classification and type page is a keyset walk of the projection. Its ~100 lines are rendered from the ledger itself.
  - No snapshot is built, nothing is queued, and there is nothing to wait for.
  - Every financial create, edit or delete is visible at the next "عرض", inside the same DB transaction that wrote it.
- **Paging:** the pager offers الأولى / السابقة / التالية / الأخيرة. Arbitrary page numbers are not supported in live mode.
- **Fallback:** while the projection is not READY (not built yet, rebuilding from an untrusted state, or marked invalid by the verifier), the page uses the previous snapshot path unchanged. It shows the fallback notice, so the figures stay exact, just slower.
- **Exports:** they still write an immutable snapshot, built through the projection when it is READY.
  - Word and Excel up to 2,000 lines are built in the request.
  - Larger Excel files are built by the existing `reports` worker job, which first builds the snapshot.
  - Rows and figures are identical to the on-screen report.

## P2. Deploy order

1. **Take a verified backup** (as for every release).
2. **Brief maintenance window.** Every financial write now also writes the projection, so code and migration must arrive together:
   ```
   php artisan down
   git pull / deploy the release
   php artisan migrate --force          # creates the 5 projection tables (status: not built)
   php artisan config:cache
   php artisan queue:restart
   php artisan up
   ```
   Until the next step finishes, the report keeps working through the fallback path. From this moment, every financial write already maintains its own projection rows.
3. **Build the projection in place while the system is in use:**
   ```
   php artisan oms:rebuild-comprehensive-report-projection --verify
   ```
   - It is restartable: if it is interrupted, run it again with `--resume`.
   - It commits one window (500 transactions) or one month at a time.
   - Memory is bounded by one window or one month.
   - Live writes during the rebuild are safe: every step re-derives from the ledger and is idempotent, and the month aggregates are swapped under a shared lock.
   - Measured on the dev laptop:
     - 561,831 lines: rebuild 296 s, verifier 33 s;
     - 1,221,321 lines: rebuild 700 s (while another heavy job shared the CPU), verifier 79 s.
   - Measured size: 504 MB for 1.22M lines (≈ 0.43 KB per line, indexes included).
   - At the end the status is READY and the page switches to live mode.
4. **Check:**
   - `php artisan oms:verify-comprehensive-report-projection --parity --from=<first day of this month> --to=<today>` must report `line differences: 0 | monthly total differences: 0 | transaction count differences: 0` and `row differences: 0 | summary differences: 0`.
   - Open the report and press "عرض": no "جاري إعداد التقرير..." wait, no fallback notice.

## P3. Operations

- **Drift check (recommended daily, not scheduled automatically — operator decision):**
  - Run `php artisan oms:verify-comprehensive-report-projection`.
  - It is read-only on data. Any difference marks the projection INVALID, the page falls back, and the exit code is 1.
  - Repair with `php artisan oms:rebuild-comprehensive-report-projection --untrusted --verify`.
- **Health:** the single row of `comprehensive_report_projection_state` has `status` (`ready` / `building` / `invalid`), `phase`, `resume_after`, `rebuilt_at`, `verified_at` and `note`.
- **After any database restore:**
  - A backup taken after this release restores ledger and projection from the same consistent dump (`--single-transaction`).
  - A backup taken before it has no projection: restore's `migrate --force` recreates the tables empty, the status is "not built", and the page falls back.
  - Either way, run the verify command and rebuild if it reports differences.
- **Operational data cleanup** (`oms:clean-operational-data`) empties the 4 projection data tables together with the ledger. The state row is preserved.
- **Soft-deleted accounts:** their lines stay excluded, exactly as before. The rule is applied at query time only, so changing it later needs no rebuild.
- **An account's type is editable:** the `Account` observer re-derives that account's projected lines inside the edit's transaction.
- **Measured cost per financial write** (1.22M lines, dev laptop): +4–20 ms median and ≤ 41 ms p95, 12–14 extra queries.
- **Measured report speed** (1.22M lines):
  - "عرض" 0.23–0.79 s for normal filters, worst legitimate filter 1.5 s;
  - First / Previous / Next / Last 41–213 ms;
  - classification / type pages ≤ 0.19 s;
  - Livewire state ≤ 9.1 KB.

## P4. Rollback

- Redeploy the previous release. The new tables are then simply unused; leaving them is harmless.
- To remove them: `php artisan migrate:rollback --step=1`, from the release that contains the migration, before switching code.
- No financial data is touched either way.

---

# Addendum (2026-10-07) — PHP runtime prerequisite (both report changes)

## R1. Confirm the production PHP SAPI before deploying the report changes

**Not inspected yet:** there is no read-only access to production from the dev machine.

**Why it matters.** On the dev machine (Apache 2.4 WinNT MPM, threaded, PHP 8.3 TS as `mod_php`, config not cached), concurrent requests sometimes booted Laravel with a partially missing environment. Measured over 1,200 parallel probes:
- empty `APP_KEY`;
- `APP_ENV=production` fallback;
- sqlite driver instead of MySQL.

An empty key changes Livewire 4's endpoint hash, so valid Livewire requests get 404, and `MissingAppKeyException` produces 500s. Both new report pages poll or page concurrently, which makes this visible.

**Checks (read-only):**
- `php -r "echo PHP_SAPI;"` on the web runtime, or `phpinfo()` "Server API" from an admin-only check.
  - Must be **fpm-fcgi** or **cgi-fcgi**, i.e. one request per PHP process.
  - If it is **apache2handler** under a threaded MPM (WinNT / worker / event), stop and fix the runtime first.
- Whether production caches its config (`bootstrap/cache/config.php`). A cached config does not read `.env` per request.
- Whether one PHP process serves more than one Laravel environment. It must not.

---

# Addendum (2026-10-10) — «تصدير CSV» (not committed yet)

## C1. What it needs in production

- **The report projection READY.** CSV reads only the projection; while it is missing, rebuilding or invalid, the button answers «تصدير CSV غير متاح حالياً…» and Excel / Word keep working through the snapshot path. There is no snapshot fallback for CSV, so the projection must be built and verified before cutover (P2 above).
- **The `reports` worker** (section 1). Exports above `OMS_CFT_CSV_INLINE_MAX_UNITS` (default 25,000 work units) are built by `BuildComprehensiveFinancialTransactionsCsvExportJob` on queue `reports`, timeout `OMS_CFT_JOB_TIMEOUT` (1800 s). Smaller ones are built inside the click request (≈ ≤ 2 s measured).
- **`proc_open` enabled for the PHP CLI** that runs the worker. The job starts up to `OMS_CFT_CSV_WORKERS` (default 8) child processes `php artisan oms:comprehensive-csv-segment` with the SAME PHP binary and `--env`. Without `proc_open` (or on a non-MySQL connection) it falls back to one process — correct, but slower.
- **MySQL connections:** one large export uses up to 1 + 8 connections at once. Keep `max_connections` headroom for that.
- **`innodb_buffer_pool_size`:** the measured bottleneck on the dev laptop (128 MB pool vs ~1.1 GB of ledger + projection pages). A pool that holds `transaction_lines`, `transactions` and the projection is the single biggest speed lever. Do not change it without the DBA.
- **Disk:** the work directory lives under `export_temp_dir` (`storage/app/private/report-exports/cftcsv-*`); a full 1.22M-line export needs ≈ 100 MB (DEFLATE level 1) while it is built, then the package moves to the export disk (`report_snapshots`, `comprehensive-financial-transactions-csv-exports/{owner}/{id}/package.zip`).
- **Scheduler:** `oms:purge-report-snapshots` (section 2) also removes CSV packages after `export_temp_max_age_minutes` (60) and abandoned work directories.

## C2. Verification after deploy (no financial writes)

1. As a user with view + export permission: "عرض" a short period, click «تصدير CSV» → a ZIP (`ملخص_الحركات_المالية_الشاملة.csv`, `الحركات_001.csv`) downloads immediately.
2. "عرض" a long period → the page shows «جاري تجهيز ملف CSV في الخلفية…», `queue-reports.log` shows the job, the download starts by itself.
3. One `report_export.export_requested` audit event per package, `format = csv`, `report = comprehensive_financial_transactions`.

## C3. Rollback

Hide or remove the button (code rollback). The job, routes and store are additive: no schema change, no financial table touched.

---

## D. Data CSV vs statistics Excel (2026-10-11, both reports)

- **D1. Users see:**
  - «تصدير البيانات CSV»: detail rows only; one `.csv` up to 100,000 rows, otherwise a ZIP of detail files;
  - «تصدير الإحصائيات Excel» and «تصدير الإحصائيات Word»: the same statistics only, generated immediately.

  The Account Statement Word is refused above 2,000 summary rows. Check the production PHP `memory_limit` (~105 MB peak at that cap).
- **D2. Deploy:** no migration and no new queue. Restart every `reports` worker after deploying (`php artisan queue:restart`): a worker started before the deploy still runs the old CSV code (the one with the summary file).
- **D3. Verify after deploy (no financial writes):**
  - one small and one large CSV per report: no `ملخص_*.csv`, the expected file type;
  - one statistics workbook per report: no detail rows, the filters listed;
  - `report_export` events carry `purpose`.
- **D4. Rollback:** reverting the code restores the old buttons and exports; there is no data to undo.
