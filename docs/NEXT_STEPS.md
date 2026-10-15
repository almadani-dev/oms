# Next Steps

## Recommended Next Step (2026-10-13 — Donor Financial Report projection committed `4b9f8c8`, not pushed; unfinished work only)

Current open work only; history lives in TASKS_LOG. Deployment detail: AI_PROJECT_MEMORY → Operational Reference (Comprehensive report deployment checklist; Restore recovery runbook).

**1. Production rollout (each step needs explicit approval):**
- **R1:** production PHP must serve one request per process with no shared environment (the threaded `mod_php` race found locally on 2026-10-07). Run the read-only pre-deploy checks (EXPLAIN of the snapshot path queries, CLI `memory_limit`, current queue / scheduler state) first.
- Verified backup, short `down` window, `php artisan migrate --force`, `config:cache`, `queue:restart`, `up`; then `php artisan oms:rebuild-comprehensive-report-projection --verify` and `php artisan oms:rebuild-account-statement-projection --verify`, then the comprehensive `--parity` check. Until rebuilt, the comprehensive report uses its snapshot fallback and the Account Statement shows «قيد التهيئة»; financial writes keep working.
- A persistent Linux `reports` queue worker (Supervisor / systemd: boot auto-start, restart on failure, `timeout` / `retry_after`; `DB_QUEUE_RETRY_AFTER=3900`), a separate `backups` worker, and the scheduler running `oms:purge-report-snapshots`.
- **CSV speed:** the floor is ledger-text I/O through MySQL's buffer pool (128 MB here). Check production `innodb_buffer_pool_size`, cores, `max_connections` (+9 per large export), and `proc_open` + the CLI `php` on the worker before setting `OMS_AS_CSV_WORKERS` / `OMS_CFT_CSV_WORKERS`.
- Check production PHP `memory_limit` against Account Statement Word (2,000 summary rows ≈ 1.3 s / ~105 MB).
- Operator decision: a daily scheduled `oms:verify-comprehensive-report-projection` and `oms:verify-account-statement-projection`.
- A real production restore drill (a real `mysql` import on real infrastructure) is still outstanding; restore auditing is proven only by the simulated replacement in `RestoreAuditTest` (since 2026-07-30).

**2. Development `oms` (decision for the user):** neither projection is migrated there. To switch it on: `php artisan migrate`, then `php artisan oms:rebuild-comprehensive-report-projection --verify` and `php artisan oms:rebuild-account-statement-projection --verify` (additive tables, but it changes `oms`).

**3. Decisions for the user:**
- Remove the old detailed-Excel infrastructure, unreachable from the UI (list: TASKS_LOG 2026-10-11 final pre-commit), once no workbook queued before the change can remain.
- Whether `csv.plain_max_rows` (100,000) and `account_statement.word_max_summary_rows` (2,000) suit production.
- Whether the Comprehensive CSV should carry the time of day (today it mirrors the old Excel: date only).
- Whether failed CSV builds should get an audit event (today, as for all exports, only deliveries are audited).
- Whether a sparse dimension-filtered Account Statement export over many accounts (e.g. 1,811 rows across 3,432 accounts: 13.6 s) deserves a faster path.
- **On hold — business decision:** the comprehensive report (like the Trial Balance) excludes lines whose account is soft-deleted (`whereNull('a.deleted_at')`; 125 historical Muwakha lines in the stress data). Keep the behaviour unchanged until the user decides; the projection applies the rule at query time, so changing it later needs no rebuild.
- Repo formatting: `vendor/bin/pint --test` fails repo-wide (351 of 712 files on 2026-07-30; no `pint.json`). Either adopt a `pint.json` matching the code's style or schedule one formatting-only commit.
- Default sort convention: `id asc` fallback on master / lookup / admin lists vs `defaultSort('id','desc')` on transaction and financial lists (status not re-verified since 2026-09-28).
- `general_expenses.partner_id` is write-never since 2026-09-22; dropping it (and `general_exchanges.partner_id` / `الجهة`) is a separate, explicitly-scoped decision after a `partner_id` impact audit on production data.
- Whether account deletion should be blocked when the account has transaction lines (deleting an account with an opening balance leaves its OPB transaction in place).

**3b. Donor Financial Report real-time projection (2026-10-13, committed `4b9f8c8`, not pushed; TASKS_LOG 2026-10-13):**
- Production rollout per AI_PROJECT_MEMORY → Operational Reference (Donor report projection rollout). The dev `oms` database is not migrated (the page stays on the legacy path there).
- Optional proposal (measured): copy line description / role, transaction number / reference / description and movement notes into the projection to cut the donor 17 CSV from ~7.5 s to roughly 1–2 s (estimate: the text reads take ~6.5 s); costs extra storage and a sync in `transactions:backfill-descriptions`.
- Dead-code candidates once the projection path is proven in production: `DonorFinancialReportState` and its purge (legacy path only), the legacy branch of the page, the legacy CSV query (kept today as the parity oracle) — keep `DonorFinancialReportService` as the verifier oracle.
- Pre-existing gap noticed: the Account Statement projection tables are not classified in `OperationalDataCleanupService` (reported as unexpected tables); the donor tables are.
- Business decision: the screen and CSV show a line's `debit_base` / `credit_base` (base currency) next to the line's own currency code.
- Business decision: the disbursement summaries count budgets whose transaction is soft-deleted (`transaction_id IS NOT NULL` only), the movement list does not (`t.deleted_at IS NULL`).

**4. Separate, pre-existing issues:**
- `MuwakhaFamilyImportTest` fails 20 tests on this Windows machine: the import command resolves an absolute temp path as relative («Source file not found: C:\laragon\www\oms\C:\Users\…»).
- An unauthenticated `Accept: application/json` request to any Filament-authenticated route answers 500 instead of 401.
- Four other jobs assign an undeclared `$timeout` (PHP 8.2+ deprecation): `BuildComprehensiveFinancialTransactionsSnapshotJob`, `CreateBackupJob`, `RetentionCleanupJob`, `VerifyBackupIntegrityJob`. The one-line `public ?int $timeout = null;` declaration would fix each.
- **Follow-up audits, not done (recorded 2026-10-05):** `ProjectCosts/RelationManagers/BudgetsRelationManager` and `ReceiptsRelationManager` still expose plain Create / Edit / Delete actions that may bypass the ledger, balances and audit; `MuwakhaFamilyAccountStatementService`'s docblock says receipt lines carry `project_cost_id`, but the current write path stores NULL.
- **Guard-key refactor (deferred since 2026-08-19):** the three financial guards throw `ValidationException` keyed by domain field (`admin_account_id`, `lines`) while Filament binds by state path (`data.…`), so ~30 rejection points across the six financial workflows do not bind to their fields (BUD/EXT pages show them as a danger notification). Needs its own task.
- **Slow at the 1.22M-line stress scale (measured 2026-10-05, nothing optimized):** donor report full range (~21 s, 28 MB HTML), Arabic search on transaction lines (~35 s `COUNT(*)` full scan), `oms:check-financial-integrity` (~460 s, PHP-bound journal checker), comprehensive snapshot fallback size / worker memory (769 MB / 599 MB full range). Each needs its own audit and approval.
- Report calculations (e.g. the Donor Financial Report's deduction sums) still match `transaction_lines.notes` `LINE_*` tags; moving a calculation reader to `line_role` needs a full report-impact audit first. Do not change the `LINE_*` constants until then.
- The repo cannot run a full fresh `migrate` / `RefreshDatabase` on SQLite (MySQL-only SQL in `2026_06_24_000005` and `2026_06_24_000009`); rewriting them needs explicit approval.

**5. Optional:**
- Confirm double-click opening of the CSV in Excel on a PC whose Windows list separator is `;` (only `,` / en-US was tested).
- Re-run the two stress exports deleted by the agent on 2026-10-07 only if still needed (account 17 from 2024-10-01; account 1 from 2020-10-01).
- Filament re-evaluates the Account Statement form's option lists on every render (~100 ms per request).
- Measure the production size of the beneficiary-account cascade in the financial forms (all accounts of one type / bank / currency) before deciding on server-side search (status not re-verified since 2026-09-28).
- `transactions.reference` is not shown by the comprehensive report; `ViewTransactionLine` has no bank type (no infolist).
- Whether to wire `FinancialTransactionBalanceGuard`'s optional `$expectedNotesByRole` exact-tag check into the workflows with authoritative tags (status not re-verified since 2026-07-18).
- `FinancialAuditRecorder` and `AuditedCrudService::recordCreatedWithin()` throw `LogicException` outside an open transaction; any future queued-job or Artisan caller must open one.
- `ProjectCostBudgetsPaymentForm` and `GeneralExchangeForm` keep their 4 accounts in one unified section (not the credit/debit grid); revisit only as its own task if the accounting team wants the split.
- Cross-currency temporary credit account for execution payments: only if the business needs it, as a separately approved FX redesign (hard-rejected server-side today).
- Trial Balance Phase 3 (opening / closing balance columns) and an `is_base` uniqueness rule for currencies (the Trial Balance falls back to the first currency by id) — status not re-verified since 2026-07-15.
- Donor Financial Report invariants not enforced by the database: one budget row per disbursement transaction, and an execution payment's `currency_id` equal to its budget's `disbursement_currency_id`. Revisit if a batch-disbursement feature is added.
- Off-server copy of the 2026-07-06 database backup (the only copy of the pre-reset data; status not re-verified since 2026-07-15).
