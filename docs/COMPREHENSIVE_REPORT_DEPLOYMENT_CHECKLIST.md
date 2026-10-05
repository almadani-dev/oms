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
