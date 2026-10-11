# تقرير كشف الحساب — Architecture (2026-10-07)

Multi-account, multi-select, real-time Account Statement over a synchronously
maintained projection. The ledger (`transactions` / `transaction_lines`) stays
the only source of truth.

## 1. Business semantics (approved by the user, 2026-10-07)

- **Accounts:** one or more accounts in ONE statement. With no account picked,
  every non-deleted account matching the other account-level filters
  (account types / bank types / currencies) is covered. At least one
  account-level filter is required.
- **Balance identity = the account.** Every row shows its own account's running
  balance. Balances are never summed across accounts of different currencies;
  totals are per account and per account currency. A line whose currency
  differs from its account's currency raises the original "mixed currencies"
  warning, exactly as before.
- **Dimension filters (classification, transaction type, project) only choose
  the LISTED lines.** Opening, running and closing balances stay the account's
  ACTUAL balance (option A). The listed debit/credit totals cover only the
  listed lines, so opening + debit − credit ≠ closing when such a filter is
  active. The page and every export say so.
- **Filter algebra:** empty = no condition; several values of one filter = IN
  (OR); different filters = AND. Classification ∩ types = intersection.
- **Unchanged from the original report:**
  - Amount: `amount_currency`, on the debit side when `debit_base > 0`,
    otherwise the credit side. Balance = Σ debit − Σ credit.
  - Period: `transaction_time` from 00:00:00 of the start date to 23:59:59 of
    the end date.
  - Order: `(transaction_time, transaction_lines.id)`.
  - Soft deletes: lines and transactions that are soft-deleted are excluded;
    soft-deleted accounts cannot be selected; soft-deleted types still label
    their rows.
  - Default period: first of the current month → today.
- **New-filter rule:** the project filter includes the lines of the project's
  soft-deleted cost items, so history never disappears because a lookup was
  deleted later.

## 2. Derived structures (migration `2026_10_07_100000`, no FKs)

| Table | Grain | Purpose |
|---|---|---|
| `account_statement_lines` | one row per active line of an active transaction | IDs, time, type, project cost, line currency, statement debit/credit. Clustered PK `(account_id, transaction_time, line_id)`. Indexes: `line_id` unique, `transaction_id`, global order `(transaction_time, line_id, transaction_type_id)`, project `(project_cost_id, transaction_time, line_id)`. |
| `account_statement_daily_totals` | account × day × line currency | balance checkpoints |
| `account_statement_monthly_totals` | account × month × type × line currency | whole-month prefixes and type-filtered totals |
| `account_statement_account_totals` | account × line currency | all-time totals ("all-time minus suffix") |
| `account_statement_projection_state` | one row | `ready` / `building` / `invalid` / `missing`; only `ready` is read |

Labels (account, type, classification, project, currency), descriptions and
notes are never copied. They are joined from the ledger for the ≤ 50 visible
rows only. Renaming an account therefore rewrites nothing.

A fresh database (empty ledger) starts `ready`. Any existing ledger starts
`missing` until it is rebuilt.

## 3. Synchronous maintenance

`AccountStatementProjector` is reached only through
`ComprehensiveReportProjector::syncTransactions()` / `removeTransaction()`.
Every financial write path already calls that, as the last ledger step inside
its own `DB::transaction()`. The write paths are: receipt, general expense,
general exchange, project disbursement and execution payment (create / edit /
delete), and the opening balance on account creation.

The projector re-derives the transaction's rows from the ledger and moves the
daily, monthly and all-time totals by the exact difference (bcmath). Locks are
taken in a fixed order: lines, then daily, monthly and all-time totals, keys
sorted.

Any failure throws, so the business record, transaction, lines,
`current_balance`, both projections and the audit event roll back together.

The existing static test already fails if a ledger write site does not
reference the projector.

**Backdating** (create, date moved back or forward, account changed, amount
changed, delete) touches only the affected account × day, month and all-time
checkpoint rows. No later row is ever rewritten.

The Comprehensive report's own rebuild pass and its account-type
re-derivation do not touch the statement projection, which stores no account
attribute.

## 4. Reading

All reading lives in `app/Services/Reports/AccountStatement/`:

- `AccountStatementFilters` — normalized, applied filters (ID lists: integer,
  positive, unique, sorted).
- `AccountStatementScope` — resolves the filters per request. Accounts become a
  literal ID list of non-deleted accounts (sub-select only above 20,000).
  Classifications ∩ types become type IDs (deleted types included). Projects
  become cost IDs (deleted cost items included).
- `AccountStatementBalances` — exact balances:
  - **Balance before (time, line)** = Σ monthly(< month) + Σ daily(month start
    ≤ day < day) + that account's lines of that day before the line.
  - **Prefix at a day boundary:** read the prefix directly or as all-time −
    suffix, whichever side has fewer months.
  - **Type-filtered period totals:** whole months from the typed monthly
    totals, plus the partial edge months from the lines.
  - **Project-filtered totals:** read through the project index.
  - **Mixed currencies:** candidates come from the all-time totals, then the
    period is checked exactly for those accounts only.
- `AccountStatementRows` — keyset pages in canonical order. A plan is chosen
  once per "عرض" from exact counts:
  - **MERGE:** one sub-select per account on its clustered PK range, `UNION ALL`,
    `LIMIT 50`.
  - **SCAN:** walk the global order index, with the account and type filters
    evaluated in the index.
  - **PROJECT:** read through the project index.
- `AccountStatementReport`:
  - `summarize()` — "عرض" only; O(accounts), never O(lines).
  - `accountSummaries()` — 25 accounts per page.
  - `page()` — First / Next / Previous / Last / redraw of ≤ 50 rows.
  - `rows()` — exports, in chunks of 1,000.

  Running balance of a row = balance before the row + its debit − its credit.

**Pager.** Pages are aligned on multiples of 50 from the first row. Last
fetches only the remainder (reverse keyset), so Previous from Last lands
exactly where Next would. No OFFSET is used.

**Livewire state.** Only the applied filters, small display labels, the
summary (one entry per currency), the page cursors `[time, line]`, the page
numbers and the 25-account summary page are kept in state. Rows are never
stored.

## 5. Exports

All exports use the same applied filters, the same `rows()` generator, and
cover every matching row — not the visible page.

**Mode is chosen from estimated WORK, not the movement count**
(`AccountStatementExportPlan`, polish of 2026-10-07):

```
units = movements + ceil(0.5 × covered accounts) + currencies
```

The weight 0.5 is conservative: on stress, a summary row cost 0.25–0.45× a
movement row in every renderer.

| Units | Excel | Word |
|---|---|---|
| ≤ 2,000 | styled, inline | inline |
| 2,001 – 8,000 | streaming, inline | refused |
| > 8,000 | queued job; the page polls a 53-byte JSON status endpoint (`reports.account-statement.export-status`, owner-scoped, both permissions, no report query) and starts the signed download when ready | refused |

The older row-count table below is superseded by these unit caps.

| Rows | Excel | Word |
|---|---|---|
| ≤ 2,000 | styled PhpSpreadsheet, inline | PhpWord, inline |
| 2,001 – 20,000 | OpenSpout streaming, inline | refused (never truncated) |
| > 20,000 | OpenSpout streaming, queued job on `reports`; the page polls and starts a signed one-time download | refused |

The download is audited at delivery. All thresholds are in
`config/oms.php` → `reports.account_statement`.

Only exports above 20,000 rows need `queue:work`. Browsing never does.

### 5.0 Data vs statistics (2026-10-11 — supersedes the Excel rows of the tables above)

- **«تصدير البيانات CSV»** holds the movements only (§5.1 without the summary file). A single file of at most `csv.plain_max_rows` (100,000) planned movements is delivered as a plain `كشف_الحساب_<ts>.csv`. Larger exports are a ZIP of `حركات_كشف_الحساب.csv` / `حركات_NNN.csv`.
- **«تصدير الإحصائيات Excel»** (`AccountStatementStatisticsExcelExportService`, OpenSpout through `Excel\StatisticsWorkbook`) holds statistics only, and is always written inside the click (≤ 2 s at 4,997 accounts):
  - ONE worksheet «الإحصائيات» (final pre-commit, same day): title and export time, period, every applied filter, counts, notes, the per-currency summary, then every covered account.
- The styled / streaming / background detail-Excel modes are no longer reachable from the page; the code is kept until the user decides on its removal.
- Audit `purpose`: `detail_data` (CSV), `statistics` (Excel / Word).
- **«تصدير الإحصائيات Word»** (addendum, same day): the same statistics from the same model (`AccountStatementStatistics` → `Word\StatisticsDocument`). It is refused only above `word_max_summary_rows` = 2,000 summary rows (accounts + currencies), never on movements. The movement-based Word cap of the tables above no longer applies.

### 5.1 «تصدير CSV» (2026-10-08)

A third export button sits right beside «تصدير Excel». It is meant for very
large statements. Excel and Word are unchanged.

**Package.** One ZIP named `كشف_الحساب_YYYYMMDD_HHMMSS.zip`:
- `ملخص_كشف_الحساب.csv` holds one row per covered account. Zero-activity
  accounts are kept, exactly like the screen.
- `حركات_كشف_الحساب.csv` holds every listed movement in canonical order. Above
  `csv.max_rows_per_file` (1,000,000) rows it becomes `حركات_001.csv`,
  `حركات_002.csv`, … cut at day boundaries. Rows are never truncated.

**Movement columns (14):**
- التاريخ / الوقت
- رقم الحركة
- نوع المعاملة
- تصنيف المعاملة
- الوصف
- البيان / ملاحظات السطر
- دور سطر القيد
- وصف سطر القيد
- المشروع
- الحساب
- العملة
- مدين
- دائن
- الرصيد الجاري

**Summary columns:** الحساب, العملة, الرصيد الافتتاحي, إجمالي المدين, إجمالي الدائن, الرصيد الختامي, عدد الحركات.

**Encoding** (`Csv\AccountStatementCsvFormat`):
- UTF-8 with a BOM, comma delimiter, CRLF line endings, RFC 4180 quoting.
- Money is plain numbers (`-1234.50`), written from integer cents or the
  DECIMAL string, never through a float.
- Text cells starting with `= + - @`, a tab or a carriage return get a `'`
  prefix. Numeric cells are never touched.
- Null text becomes an empty cell. Line role and line description use the
  screen's `—` convention.

**Reading** (`Csv\AccountStatementCsvSegment`):
- The period is cut at day boundaries into segments, planned from the monthly
  checkpoint counts (refined to days only when needed).
- Each segment is one streamed query, unbuffered on MySQL, over the
  projection:
  - The `SCAN` path walks the global order index.
  - The `PRIMARY` path is used for a few accounts holding a small share of the
    period.
  - The query LEFT JOINs `transaction_lines` and `transactions` for the row's
    own texts. Every other label comes from maps loaded once
    (`Csv\AccountStatementCsvLabels`), with no per-row lookups.

**Running balance:**
- An integer-cents map `account_id => balance`, started from
  `AccountStatementBalances::prefixByAccount()` at the segment's first day.
- Every line of the covered accounts moves it. Classification / type / project
  filters only choose the listed rows (approved rule A). The ledger texts of
  hidden lines are not read.
- Memory grows with the number of accounts, not with the number of movements.

**Parallelism:**
- The queued job runs up to `csv.workers` (8) segments as separate CLI
  processes (`oms:account-statement-csv-segment`, hidden, refuses specs
  outside the work directory and any other database). Each process has its
  own connection and consistent snapshot.
- A direct (in-request) build runs one process in one snapshot.
- Each segment writes its piece already ZIP-encoded
  (`Csv\AccountStatementCsvPart`: raw DEFLATE level 1 with a sync flush, or
  stored). `Csv\AccountStatementCsvZip` concatenates the pieces into one entry
  per file:
  - each byte is copied once;
  - CRC-32s are combined mathematically;
  - names carry the UTF-8 flag;
  - ZIP64 records are written only when needed.

**Proof before delivery.** For every account:
- segment k's closing balance equals segment k+1's opening balance;
- the first opening and the last closing equal the account summary;
- the listed lines, debit and credit equal the account summary.

A difference means a ledger write landed between the parallel snapshots. The
export is rebuilt (`csv.attempts` = 2) and never delivered inconsistent.

**Delivery:**
- Up to `csv.inline_max_units` (25,000 = movements + 5 × accounts, ≤ ~3 s
  measured), the ZIP is built inside the click and the browser is sent to the
  signed download.
- Above that, the `BuildAccountStatementCsvExportJob` runs on `reports`. The
  page polls the same JSON status endpoint as the Excel export, which hands out
  the signed CSV URL.
- Both paths use `reports.account-statement.csv` (Filament auth, signature,
  both permissions, owner only, CSV exports only).
- The package can be fetched again until it expires, because a browser may
  request one download several times. It is audited once, at its first
  delivery: format `csv`, applied filters, `exported_movements`,
  `exported_accounts`, `movement_files`, `package_bytes`,
  `generation_seconds`, `delivery`.
- Expired packages and abandoned work directories are removed by
  `oms:purge-report-snapshots`.

**Duplicate clicks:**
- one CSV export per user at a time (cache lock);
- a background export in progress on the page is not queued twice.

## 6. Operations

```
php artisan oms:rebuild-account-statement-projection [--resume] [--untrusted] [--window=500] [--verify]
php artisan oms:verify-account-statement-projection [--window=2000] [--balance-samples=200]
```

**Rebuild** reads the ledger only and is restart-safe.
- Pass 1 re-derives lines in transaction-ID windows.
- Pass 2 recomputes the checkpoints per account window in SQL.

**Verifier** recomputes everything from the LEDGER with its own SQL: lines,
daily, monthly and all-time totals. It also samples balances (engine vs.
direct ledger sums). On any difference it marks the projection `invalid`, and
the page then refuses to show a statement.

**Rollback concept.** The tables are additive and derived.
- Reverting the code leaves them unused.
- `migrate:rollback` of this single migration drops them.
- A drifted projection is fixed by a rebuild.

## 7. Benchmarks

See `docs/TASKS_LOG.md` (2026-10-07 entry) for the full measured matrix.
