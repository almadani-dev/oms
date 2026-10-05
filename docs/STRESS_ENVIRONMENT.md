# OMS stress environment (`oms_stress`)

Synthetic, multi-year, full-system data for performance testing. **Local development only.**

## Absolute rules

- Stress data lives **only** in the separate local MySQL database `oms_stress`. Never in `oms`, never on a shared or production server.
- Nothing generates stress data automatically: not `DatabaseSeeder`, not a migration, not a composer/deploy script, not application boot. Only the explicit `oms:stress:*` commands below, run by hand.
- `.env.stress`, `storage/stress/`, database dumps, generated attachments, exports and snapshots are never committed (`.gitignore` covers `.env.stress` and `storage/stress/`).
- The stress user password is typed at a hidden prompt during the build, stored only as a hash inside `oms_stress`, and never written to a file, log, doc or Git.

## Hard guards (every command)

Defined in `config/oms_stress.php` (fixed lists, not read from `.env`) and enforced by `App\Services\Stress\StressEnvironmentGuard`:

1. No APP_ENV source (resolved environment, `config('app.env')`, process/server/env vars) may be `production`; the environment must be `local`, `stress` or `testing`. Writing commands require `stress` (or `testing`).
2. Config must not be cached.
3. `--database` must be exactly `oms_stress`; `oms` and system schemas are forbidden; outside the stress profile the app's own default database can never be the target, and under the stress profile it must be the target.
4. The MySQL host must be `127.0.0.1`/`localhost`/`::1`, and the live server's `@@hostname` must equal this machine's hostname.
5. `--confirm=oms_stress` always; reset also needs `--confirm-reset=oms_stress`.
6. Every command except create-database requires the `_stress_meta` marker table (naming `oms_stress`) inside the target.
7. After connecting, `SELECT DATABASE()` must equal `oms_stress` before anything is written.
8. Writing commands require `storage_path()` to be exactly `storage/stress` (so attachments, report snapshots, exports, logs and caches never touch the normal `storage/`).

## One-time setup: `.env.stress`

1. Copy your own local `.env` to `.env.stress` (same DB username/password, same APP_KEY).
2. Change only these keys (see `.env.stress.example`):

   ```
   APP_ENV=stress
   DB_DATABASE=oms_stress
   LARAVEL_STORAGE_PATH=C:\laragon\www\oms\storage\stress
   ```

3. Never commit `.env.stress` (it is git-ignored).

## Commands (always with `--env=stress`)

```
php artisan --env=stress oms:stress:audit --scale=large          # read-only pre-flight (also allowed without --env)
php artisan --env=stress oms:stress:create-database --database=oms_stress --confirm=oms_stress
php artisan --env=stress oms:stress:build  --database=oms_stress --confirm=oms_stress --scale=small --seed=20261005
php artisan --env=stress oms:stress:verify --database=oms_stress --confirm=oms_stress
php artisan --env=stress oms:stress:reset  --database=oms_stress --confirm=oms_stress --confirm-reset=oms_stress
```

- `create-database`: creates the empty database, runs the real migrations, checks schema parity with a fresh migration of the current code (see below), copies the 8 lookup tables from `oms` (read-only session) (currencies, account/bank/partner types, project statuses/supers, transaction super types/types), runs the real `PermissionSyncService`, prepares `storage/stress/`, writes the marker. No operational data.
  - **Schema parity is checked against the CURRENT code, not `oms`.** A temporary local database `oms_stress_schema_ref_<12 hex>` is created and migrated from zero with the current migrations. `oms_stress` is compared with it under the same strict rules: the marker table is the only allowed difference, and any other missing, extra or different table aborts. The reference is then dropped, including on failure.
  - The reference follows the same rules as the stress target: write environment only, never `production`, the same loopback base connection, and the live server hostname must be this machine. Its name must match the fixed pattern and can never be `oms`, a forbidden name or an approved stress target.
  - A reference left behind by a killed process is dropped by the next `create-database` and by `reset`.
  - `oms` is never migrated by any stress command. It is opened only through a READ ONLY session, to copy the 8 lookup tables.
  - Since 2026-10-05, a freshly created `oms_stress` no longer gets `oms`'s incremental-only plain index `transaction_lines_account_id_foreign`: its schema is exactly what the migrations create.
- `build`: refuses a non-empty database. Scales: `small` (~10k lines), `medium` (~100k), `large` (~535k, ≥ 500k active), or `--lines=N`. Same seed + code + schema → same logical dataset. Fail-clean: on error, reset and rebuild.
- `verify`: read-only. Exits non-zero if any check has violations. **Do not run a larger build after a failed verify.**
- `reset`: drops `oms_stress` and deletes `storage/stress/`. Never `migrate:fresh`, never `oms`.
- **After every `build`, rebuild the comprehensive report projection.** The generator bulk-inserts the ledger, so it bypasses the workflows that keep the projection current:
  ```
  php artisan --env=stress oms:rebuild-comprehensive-report-projection --verify
  ```
  Until that finishes, the report uses its fallback (snapshot) path.

The commands are `$hidden` (not shown in `php artisan list`).

## What the build generates

All through the current production code paths where they exist:

- Line payloads come from the **current** Filament Create pages' own builders (receipts, disbursements 2/3/4 lines, execution payments, general expenses, general exchanges), invoked in memory; amounts from `GeneralExchangeForm::deriveAmounts()`; every payload passes the real `FinancialAmountGuard` and `FinancialTransactionBalanceGuard`; descriptions come from the real description builders.
- `accounts.current_balance` is set once at the end from the generated ledger (SUM(debit_base) − SUM(credit_base) over active lines, in cents) and verified independently.
- Audit history is built from the real snapshotters (`FinancialAuditSnapshotter`, `AuditModelSnapshotter`, `AttachmentAuditMetadata`) with the real redactor/bounder, backdated to the business time, and inserted in chronological id order.
- Project financial snapshots are produced by the real `reports:refresh-projects-financial --force`.
- Not generated (runtime only): sessions, cache, jobs, failed jobs, report snapshots/build statuses, exports, notifications, backup operations.

## Switching the app to the stress database and back

- CLI (commands, queue worker): add `--env=stress`, e.g. `php artisan --env=stress queue:work database --queue=reports`. Without `--env=stress` the app uses the normal `.env` and `oms`.
- Browser — **no machine-level change needed**: start a separate local server under the stress profile (PowerShell):

  ```powershell
  $env:APP_ENV = 'stress'; php artisan serve --host=127.0.0.1 --port=8081; Remove-Item Env:APP_ENV
  ```

  Open `http://127.0.0.1:8081/admin`. Every page shows a red banner «بيئة اختبار الضغط (oms_stress) — بيانات وهمية فقط». The server binds to 127.0.0.1 only (not reachable from the LAN). Stop it with Ctrl+C to return; the normal site (`.env` / `oms`) is never affected.
- For reports built in the background (Comprehensive report, large Excel), also run the stress worker in another terminal: `php artisan --env=stress queue:work database --queue=reports --timeout=1900`.
- Optional alternative: a dedicated Laragon virtual host for this checkout with `SetEnv APP_ENV stress` (needs a hosts-file entry / Apache restart, so it is not automated).
- Do not run `config:cache` while switching profiles (the stress guard refuses a cached config).
- Login: the synthetic users are `stress.<role>@stress-oms.invalid` (`super_admin`, `admin`, `accountant`, `accountant_2`, `pm`, `pm_2`, `viewer`; `accountant_former` is inactive). Their password is generated by `oms:stress:build --generate-password` and shown once at the end of the build — it is never stored in clear text anywhere.

## Verified large build (2026-10-05, seed 20261005, chunk 500)

> Synthetic data only. Nothing in `oms_stress` is, or may ever become, production data.

Build: 1,500 s on this laptop, peak PHP memory 324 MB (financial flows 619 s, audit history 791 s, project snapshots 74 s; 498 s of it inside multi-row INSERTs).

| Table | Total | Active |
|---|---:|---:|
| transaction_lines | 567,292 | **561,831** |
| transactions | 255,819 | 253,279 |
| audit_events | 284,618 | — |
| project_cost_budgets_payments (execution) | 85,605 | 84,571 |
| general_expenses | 52,752 | 52,000 |
| project_cost_receipts | 50,006 | 49,748 |
| project_cost_budgets (disbursements) | 47,092 | 46,916 |
| general_exchanges | 20,320 | 20,000 |
| projects_costs | 7,437 | 7,437 |
| projects | 3,001 | 2,971 |
| accounts | 2,359 | 2,340 |
| muwakha_families / accounts / project links | 1,200 / 1,298 / 1,773 | 1,177 / — / — |
| partners | 918 | 900 |
| attachments (files, 3.4 MB total) | 3,848 | 3,814 |
| project financial snapshots / currency totals / alerts | 2,971 / 6,916 / 1,811 | — |

- MySQL size ≈ 1.0 GB (audit_events 437 MB, transaction_lines 300 MB, transactions 164 MB).
- Period 2022-01-01 .. 2026-10-05; lines USD 254,833 / ILS 243,606 / EUR 63,392.
- Disbursement shapes: 2 lines 16,423, 3 lines 18,869, 4 lines 11,800. Projects: غير مكتمل 2,172, مكتمل 829; every super type present.
- Audit: financial created 255,775 / updated 7,601 / deleted 2,540; CRUD created 12,048 / updated 952 / deleted 125; attachment 3,848 uploaded / 34 deleted; 1,694 login events.
- Busiest accounts: ~28k lines each (main USD bank accounts).

### Verification (`oms:stress:verify`): 55 checks, 0 violations

Referential (lines, transactions, business rows, projects/costs, accounts, partners, Muwakha, audit actors, attachments), soft-delete coherence, uniqueness (transaction numbers, project codes, national ids, card codes, links, audit uuids), the real `FinancialTransactionBalanceGuard` re-run on every active transaction, line currency = account currency, no 0/0 or negative lines, business rows = ledger, execution ≤ budget, `current_balance` = ledger (SQL and an independent PHP cents sum), project report totals = totals recomputed from the ledger, the existing `oms:check-financial-integrity`, attachment files present, and report coverage.

Schema parity note (2026-10-05 build of 561k lines): at that time parity was checked against `oms`, and the plain index `transaction_lines_account_id_foreign (account_id)` that only the incrementally migrated `oms` has was added to `oms_stress`. Since the switch to a fresh-migration reference, it is no longer added.

### Performance (single runs on this laptop; measured, not optimized)

Comprehensive Financial Transactions (real page + real `reports` worker, `--env=stress`):

| Range | Lines | Build | Lines/s | Worker peak | Snapshot on disk | Excel (streaming, queued) |
|---|---:|---:|---:|---:|---:|---:|
| 1 month | 14,194 | 9.6 s | 1,483 | 109 MB | 19 MB | 46 s |
| 3 months | 49,673 | 28.5 s | 1,743 | 174 MB | 69 MB | 127 s |
| 12 months | 196,159 | 109 s | 1,800 | 435 MB | 270 MB | 467 s |
| Full range | 561,706 | 301 s | 1,866 | 599 MB | 769 MB | 387 s (59 MB file) |

Livewire state ≈ 1.6 KB in every case. After the snapshot exists, page navigation, classification expand/page and type expand/page take 0.06–0.48 s with **0 transaction_lines queries**. Word is refused above 2,000 lines. The report excludes lines of soft-deleted accounts (existing `whereNull('a.deleted_at')`), so the full range shows 125 fewer lines than the active ledger.

Other screens (first value warm unless noted):

| Screen | Time | DB time | Notes |
|---|---:|---:|---|
| Trial balance USD — full / 12 m / 1 m | 10.1 / 7.7 / 1.4 s | 7.4 / 6.7 / 0.4 s | |
| Account statement, busiest account — full / 12 m / 1 m | 36.0 / 23.7 / 12.0 s | 6.3 / 14.8 / 10.2 s | full = 90 MB HTML; two whole-account queries ≈ 4.3 s each even for 1 month |
| Donor report, top donor, full | 20.9 s | 7.1 s | 28 MB HTML |
| Projects general financial, first page | 1.8 s warm (24 s cold, view compile) | 0.4 s | |
| Project financial details, biggest project | 4.0 s | 0.4 s | |
| Muwakha family statement, busiest family, full | 3.2 s | 0.4 s | |
| Transaction lines list / transactions list / execution payments / audit events (first page) | 3.5 / 2.1 / 1.7 / 1.2 s | | |
| Transaction lines Arabic search | 36–41 s | 35 s in one `COUNT(*)` with `REPLACE … LIKE` | full scan |
| `oms:check-financial-integrity` | 460 s | | Journal checker 345 s (300 s PHP), account balance GROUP BY 62 s |

## Current dataset: 1M+ build (2026-10-05, `--lines=1150000`, seed 20261005, chunk 500)

This replaces the 561k build described above (`reset` → `create-database` with fresh-migration schema parity → `build`).

- **Volume:** 1,233,324 transaction lines (**1,221,321 active**), 555,923 transactions, about 616k audit events, 5,043 accounts, 6,446 projects, 2,578 Muwakha families.
- **Build:** 2,420 s, peak 616 MB.
- **`oms:stress:verify`:** all checks passed, 0 violations, including `oms:check-financial-integrity`, account balances and project totals; 1,622 s.
- **Comprehensive report projection:** `oms:rebuild-comprehensive-report-projection` in 700 s. `oms:verify-comprehensive-report-projection` found 0 differences in 79 s. The projection takes 504 MB.
- The fixed stress login password was set again afterwards through `UserManagementService`. It is stored only as its hash; the generated one was never shown.
- Report benchmarks on this dataset are in `docs/TASKS_LOG.md` (2026-10-05, real-time reporting projection).

## Return to normal OMS

Stop the stress server (Ctrl+C) and any `--env=stress` worker; run commands without `--env=stress`. `oms`, `.env` and `storage/` are never used by the stress profile.
