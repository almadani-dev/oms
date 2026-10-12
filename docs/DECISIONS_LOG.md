# Decisions Log

## Decision Template

### Date

### Decision

### Reason

### Impact

---

### Date
2026-10-12 (Donor Financial Report: data export vs statistics export)

### Decision
- **CSV = the financial movements only**; the projects table and the cost-item table go to the statistics (both are per-project / per-cost-item aggregates). User choice over "movements + cost items" and "all three tables".
- **One CSV row per ACCOUNTING LINE** (user correction of the earlier "one row per movement, lines folded"): the movement's columns repeat on each of its lines, so a movement's amount appears once per line — sum the debit / credit columns, not «المبلغ». A movement without an active line keeps one row with empty line cells (none on `oms_stress`).
- **CSV built synchronously** in the click request (no queue / polling): one donor per report; still ~13 s for the largest donor at line grain. User choice.
- **The report is kept server-side** (approved): an owner-scoped file store keyed by a locked token, not Livewire state and not the database cache (the largest report is ~18 MB serialized, above the cache table's value size); no migration. Raising Livewire's `payload.max_size` was rejected.
- **Screen paging, display only** (approved): movements, projects and cost items 50 rows per page (OMS convention), three independent pagers (First / Previous / Next / Last). Movements are cut into stored page files at «عرض» (read directly by number — the "keyset" of a fixed, immutable list; no database OFFSET, nothing re-queried); projects / cost items (≤ a few hundred rows) are sliced from the stored summary, the same arrays the statistics exports read whole, so screen and exports cannot drift. CSV / Excel / Word unchanged and always full-scope.
- Projects in the statistics: one row per project × currency (the screen stacks currencies in one cell); an absent figure is «—», as on screen.

### Reason
- Same model as the two other financial reports; the donor report cannot exceed ~22k lines on `oms_stress`.

### Impact
- Old `DonorFinancialReportExcelExportService` / `DonorFinancialReportWordExportService` removed (approved; zero callers after browser validation).

---

### Date
2026-10-11 (both financial reports: data export vs statistics export; Word statistics-only; single-sheet Excel; cleanup scope)

### Decision
- **CSV = detailed rows only; Excel and Word = the same statistics only** (user requirement), built from ONE shared model (`StatisticsReport`) so the formats cannot drift. Comprehensive statistics come from what the displayed report already holds (Excel = screen); Account Statement uses the «عرض» summary plus every covered account's summary.
- **Packaging (user choice):** a plain `.csv` up to `csv.plain_max_rows` = 100,000 planned rows, otherwise a ZIP of detail files (rejected: plain CSV whenever one file fits — up to ~1 GB — and always-ZIP).
- **Statistics Excel = exactly one worksheet «الإحصائيات»**, generated immediately (OpenSpout, no queue, no limit), sections in Word's order; autofilter only on the Account Statement account table (Excel allows one per sheet). Money cells stored rounded to 2 decimals (display only).
- **Word limit:** movement-based refusals dropped; Account Statement Word refused only above `word_max_summary_rows` = 2,000 summary rows (accounts + currencies); Comprehensive Word has no limit.
- **Audit:** formats `csv` / `xlsx` / `docx` unchanged; new `purpose` (`detail_data` / `statistics`) with an Arabic `purpose_label` that names no format.
- **Cleanup scope:** only proven-dead Word-detail code removed; the old detailed-Excel path is unwired but kept (already-queued workbooks complete) until the user decides.

### Reason
- Users could not tell what each export contained; the detail Excel duplicated the CSV.
- Word costs ≈ 0.7 ms and ≈ 28 KB per summary row (never per movement): 2,000 rows ≈ 1.3 s / ~105 MB, safe under a 128M limit; 5,000 rows took 5.4 s / 184 MB.

### Impact
- No financial calculation, balance, filter, ordering, SoftDelete or projection rule changed; CSV data rows byte-identical to before. Notices now point to Excel statistics and CSV data.

---

### Date
2026-10-10 (Comprehensive Financial Transactions «تصدير CSV»)

### Decision
- Exactly the 16 detail columns of the old Excel / Word (same order and values; exact decimals); no reduced "fast" format.
- Source: the real-time projection only; refused while not READY (no snapshot-based CSV — production cutover needs the projection READY first).
- Consistency: per-segment fingerprints re-read in a fresh snapshot opened after EVERY segment started, plus rows = the report's summaries; bounded rebuild (3 attempts).
- Texts and source notes read ONCE per window by id ranges; work units weighted by sparsity: inline ≤ 25,000 units, otherwise the `reports` job, one segment per 40,000 units, ≤ 8 workers; DEFLATE level 1 by default.
- Reuses the Account Statement CSV encoder / ZIP64 writer / snapshot helper / export store (store parameterized by base directory + config prefix). Re-fetchable by the owner until expiry, audited once.

### Reason
- The first design (texts joined per line, notes joined by time) took 98.8 s for 1.22M lines; reading each text once reached the ledger-text I/O floor. Mixed-time exports are excluded by proof, not by locking writers.

### Impact
- 1M lines 12.6–27.9 s (machine-state dependent; ≤ 5 s NOT met); 0 differences against the ledger.

---

### Date
2026-10-08 (Account Statement «تصدير CSV»)

### Decision
- ZIP of `ملخص_كشف_الحساب.csv` + movement file(s) ≤ 1,000,000 rows; UTF-8 BOM, comma, CRLF. *(Superseded on 2026-10-11: no summary file; a plain `.csv` up to `csv.plain_max_rows`.)*
- Running-balance identity = the ACCOUNT (as on screen); DEFLATE level 1 by each parallel writer; inline ≤ 25,000 units (movements + 5 × accounts), otherwise the `reports` job with 8 CLI segment workers.
- Re-fetchable until expiry, audited once at first delivery; failures logged, not audited («تعذر تجهيز ملف CSV»); `oms:purge-report-snapshots` also purges the account statement export store and abandoned work directories.

### Reason
- Measured floor: ledger-text reads cost 11.5 s per 1M movements on 8 connections (128 MB buffer pool); dropping texts to reach 5 s would remove financially useful information.
- The browser requested one download URL up to 4 times; a strictly one-time link broke the real download.

### Impact
- 1M movements 11.3 s; 0 differences against the ledger up to 1.22M rows.

---

### Date
2026-10-07 (local runtime: both sites served by their own FastCGI pools)

### Decision
- The LAN stress URL is a Host-matched vhost (`ServerName 172.16.0.100`) handing `.php` to mod_fcgid `php-cgi.exe` workers with `APP_ENV=stress`, `DB_DATABASE=oms_stress`, `LARAVEL_STORAGE_PATH` fixed per process.
- `oms.test` gets its own pool (`sites-enabled/05-oms-test-fcgid.conf`, no injected environment), loaded before Laragon's regenerated `auto.oms.test.conf`; a distinct wrapper command line keeps the pools apart.
- Export jobs declare `public ?int $timeout = null;` (PHP 8.2+ dynamic-property deprecation; effective timeout unchanged).

### Reason
- Threaded `mod_php` with runtime `.env` reading gave concurrent requests missing / foreign environment values (563 / 7,500 on `oms.test`).
- Rejected: `config:cache` (user-rejected; cannot isolate two environments), a separate port / hostname (unneeded), `php artisan serve` (not persistent), mod_proxy_fcgi (needs a separately supervised process on Windows); editing the auto file (Laragon overwrites it).

### Impact
- 0 identity errors in 20,000 concurrent probes; 0 runtime 404 / 500 in 3,600 real requests. Rollback: remove / rename the files, then Stop + Start Apache.

---

### Date
2026-10-07 (تقرير كشف الحساب — export mode from measured work units; JSON status polling)

### Decision
- Export mode from `units = movements + ceil(0.5 × accounts) + currencies`. *(Superseded on 2026-10-11: CSV carries the detail rows; Excel / Word carry statistics only.)*
- Background-export status polled from a small owner-scoped JSON endpoint instead of `wire:poll`.
- No-activity accounts stay in summaries and exports (analysed, unchanged).

### Reason
- Per-cell styling, not row count, made the browser case (276 movements, 1,767 accounts) block for 10–22 s; the old poll re-sent ~440 KB every 2 s.

### Impact
- The browser-case exports finished in 2.4–3.4 s; polling costs 53 bytes. The environment race was resolved the same day by the FastCGI isolation.

---

### Date
2026-10-07 (تقرير كشف الحساب — multi-account semantics, approved by the user)

### Decision
- Opening, running and closing balances are the account's ACTUAL balance; classification / type / project filters only choose the listed and totalled lines (option A).
- Empty Accounts filter = every non-deleted account matching the account types / bank types / currencies; at least one account-level filter required.
- Balance identity = the account; totals per account and account currency, never mixed; a foreign-currency line keeps the "mixed currencies" warning.
- The new project filter includes lines of soft-deleted cost items. Values of one filter = OR (IN); different filters = AND.

### Reason
- The user chose option A and "empty = all matching"; historical statements must not lose transactions because a lookup was deleted.

### Impact
- With a dimension filter, opening + debit − credit ≠ closing; the page and exports say so.

---

### Date
2026-10-07 (تقرير كشف الحساب — dedicated real-time projection with exact balance checkpoints)

### Decision
- `account_statement_lines` clustered by `(account_id, transaction_time, line_id)` plus exact daily, monthly (× type) and all-time checkpoints, maintained synchronously by `AccountStatementProjector` through `ComprehensiveReportProjector::syncTransactions()` / `removeTransaction()`.
- Running balance = monthly prefix + daily prefix + that day's lines; keyset pages via a MERGE / SCAN / PROJECT plan chosen from exact counts.

### Reason
- Rejected: optimised ledger queries (no `(account, time)` path; the opening query alone took 10 s), reusing `comprehensive_report_lines` (base amounts, different order, no checkpoints), per-row stored balances (a backdated line would rewrite up to 58,900 rows). One hook in the existing projector beats 17 new call sites and inherits its static guard.

### Impact
- ~20–45 ms median extra per financial write (worst 121 ms); ~360 MB at 1.22M lines; needs migrate + rebuild before the page works (the projector skips while its tables do not exist).

---

### Date
2026-10-05 (تقرير الحركات المالية الشامل — real-time reporting projection, monthly aggregates, keyset pager)

### Decision
- **Projection:** `comprehensive_report_lines` (IDs, filter / order keys, amounts; clustered PK `(transaction_time, transaction_id, line_id)`), written only by `ComprehensiveReportProjector` as the last ledger step inside each workflow's `DB::transaction()` (5 creates, 5 edits, 5 deletes, opening balance); re-derived from the ledger, refuses to run outside a transaction; the `Account` observer re-derives lines when `account_type_id` changes. No MySQL triggers, no queue.
- **Summaries:** whole months from exactly-maintained `comprehensive_report_monthly_totals` and `comprehensive_report_monthly_transactions` (distinct-transaction counts per currency / account-type signature); partial edge months and account / project / side filters from indexed line queries; mutable relations (classification, live cost items, soft-deleted accounts) resolved at query time.
- **Paging:** keyset First / Previous / Next / Last on the clustered key (no arbitrary jumps); the page reads the projection only while `status = ready`, otherwise the unchanged V2 snapshot path with a visible notice.

### Reason
- The user required immediate freshness, atomicity with the business write and no triggers; a static test fails if a ledger-writing file does not reference the projector.
- Raw aggregation was too slow at 561k lines (full-range `GROUP BY` 6.2 s, `COUNT(DISTINCT)` 3.6 s) against targets of < 1 s normal / ≤ 5 s worst at ≥ 1M.

### Impact
- Totals are exact bcmath sums (old float sums differed by ≤ 2.6e-5, never a displayed cent). Restore, cleanup and stress bulk loads rely on the rebuild / verify commands.

---

### Date
2026-10-05 (stress schema parity checked against a fresh migration of the current code)

### Decision
- `oms:stress:create-database` compares `oms_stress` with a temporary `oms_stress_schema_ref_<12 hex>` migrated from zero and always dropped; `oms` is never migrated by a stress command and is opened READ ONLY only to copy the 8 lookup tables.

### Reason
- The projection migration must not be applied to the development `oms` just so a stress build can run (user direction).

### Impact
- Only the marker table may differ; a fresh `oms_stress` no longer gets `oms`'s incremental-only index `transaction_lines_account_id_foreign`.

---

### Date
2026-10-05 (stale fixtures in `CleanOperationalDataCommandTest` repaired)

### Decision
- Test-only repairs: Muwakha fixture sets its NOT NULL columns, the audit fixture drops the nonexistent `updated_at`, and `--apply` calls pass `--no-interaction`.

### Reason
- Pre-existing defects (5 errors and a `confirm()` hang reproduced on clean `HEAD`) hid the cleanup coverage of the new projection tables; no application behaviour changed.

---

### Date
2026-10-04 (تقرير الحركات المالية الشامل — snapshot architecture, two-level keyset, `reports` queue, background Excel)

### Decision
- «عرض» builds one immutable owner-bound snapshot on the private `report_snapshots` disk behind `ComprehensiveFinancialTransactionsSnapshotRepository`; Livewire keeps only the snapshot id and page state; `manifest.json` is written last as the commit marker; expiry 240 min, purged hourly.
- Rows are read as a page of transactions keyed `(transaction_time, id)` (no join) plus those transactions' lines; no OFFSET.
- Snapshot builds and large exports run on a dedicated `reports` queue (`tries=1`, timeout 1800 s), with status in small private files and a 2 s page poll. *(The first same-day version built synchronously in the request.)*
- Above 2,000 lines Excel is written by a queued OpenSpout job and downloaded once via a signed, owner- and permission-checked route, audited at delivery; Word refused above 2,000 lines. *(Superseded on 2026-10-11 by CSV + statistics Excel / Word.)*

### Reason
- No Redis; row snapshots may not go into a table or the cache; production is single-node.
- A single keyset over the line tuple sorted across two tables and grew quadratically; the transaction order is exactly `transactions(transaction_time)`.
- A dedicated queue keeps backups and reports from delaying each other and away from restore's backups-queue cleanup; one try means a half-written build is never silently re-run.
- 2,000 lines was the largest measured size where the styled workbook / Word finished in 8–20 s; streaming still needs ~1.6 ms per line, so it runs in a worker.

### Impact
- Request state is O(1) in rows. Production needs a supervised `reports` worker, `schedule:run`, and `DB_QUEUE_RETRY_AFTER` above the longest job timeout (see AI_PROJECT_MEMORY → Operational Reference → Comprehensive report deployment checklist).

---

### Date
2026-09-26 (Muwakha bulk import — the importer adapts to the domain)

### Decision
- The one-time importer writes only through `MuwakhaFamilyService::create()`; `MuwakhaFamilyImportPreflight::rulesFor()` reproduces the form's validation field by field; `—` and `''` become `NULL`.
- Uniqueness only on `martyr_national_id` (`withTrashed()`) and `card_code` per project — never on a name, account number or card sequence (`G 1111` stays).
- `--actor` required for `--execute` (active, non-deleted user); dry run is the default; all 72 families or none in one `DB::transaction()`; messages stated inline.
- The importer's trim is accepted: two source `notes` lost a trailing space; the stored values were not edited back.

### Reason
- Any "helpful" normalization would destroy approved source anomalies; a CLI has no actor; a partial 72-family import is not correctable by hand.

### Impact
- The domain classes, resource, models and migrations are unchanged; every rule is enforced twice (readable preflight + untouched domain). If byte-exact `notes` are ever needed, fix `normalize()`, not the data.

---

### Date
2026-09-22 (`طرف الحساب` — the side is the line's own amount and only narrows an account selection)

### Decision
- Account Side filter All / Debit / Credit on the Comprehensive report: Debit = `debit_base > 0`, Credit = `credit_base > 0`, applied to the whole dataset, statistics, details and exports.
- Meaningful only with selected accounts: `normalizeAccountSide()` collapses it to `all` without an account (or with an unknown value); one side across the whole selected set.

### Reason
- Only the line amounts say which side a line is (`line_role`, type and texts do not); `> 0` because both columns are NOT NULL DEFAULT 0. A report-wide side would silently unbalance every currency total.

### Impact
- An unbalanced visible total is expected under a one-sided view; the default report is byte-identical; exports always state the side.

---

### Date
2026-09-21 (detail-table scroll synchronization)

### Decision
- Synchronized scroll containers use a value-based echo guard (`el._cftSyncedTo`, ±1 px) instead of a lock released on the next animation frame; `sync(source)` never writes to its source.
- Each control bar's ghost spacer = `content − (bodyWrap.clientWidth − bar.clientWidth)`, measured per bar.

### Reason
- Scroll events are delivered later than the assignment, so any time-based guard races and aborted smooth scrolls; the bars' tracks are narrower than the table, so a full-width spacer gave them a longer range and caused clamping corrections.

### Impact
- One arrow click completes its full travel with 0 backward steps; the bar range equals the table's on the main and nested tables.

---

### Date
2026-09-20 (expanded classification detail — one shared Blade partial)

### Decision
- The expanded classification renders the main detail table from one shared partial (`partials/comprehensive-financial-transactions-detail-table.blade.php`); the compact nested design and its CSS tokens were deleted.
- `$money` / `$detailColumnCount` come from the page's `@php` block via `@include`'s variable merge; each include keeps its own `x-data`; the nested table is pinned to its parent scroller's width (`clientWidth − 24`).

### Reason
- Two hand-maintained copies of a 16-column financial table would drift silently; a `min-width` inside a `colspan` cell would stretch the classification table instead of scrolling.

### Impact
- Load-bearing: the partial breaks if the page loses `$money` / `$detailColumnCount`; `24` = `.cft-detail-inner` padding doubled; `wire:click` / `wire:target` arguments must stay identical.

---

### Date
2026-08-19 (BUD/EXT optional deduction lines)

### Decision
- A 0% deduction produces NO transaction line; the zero-line guard is not weakened. BUD / EXT transactions carry 2, 3 or 4 lines; payloads keyed by role (`source` / `admin?` / `transfer?` / `destination`).
- `assertBalancedMultiCurrencyLines()` accepts nullable admin / transfer; `FinancialAccountGuard` unchanged (callers omit inactive specs); `['destination','source']` is a MULTI-currency role set.
- New rule: a percentage > 0 whose amount rounds to 0.00 is rejected on its field (`assertDeductionsAreRecordable()`).
- Guard errors surface as a danger notification; no historical rewrite, no migration.

### Reason
- Zero-valued lines corrupt a journal; positional access breaks with variable line counts; routing a 2-line FX disbursement to the single-currency check would flag correct rows.

### Impact
- A missing `LINE_ADMIN` / `LINE_TRANSFER` means a valid 0% deduction; pre-existing zero-line rows are untouched and still flagged by `JournalBalanceIntegrityChecker`.

---

### Date
2026-08-18 (Muwakha Family Account Statement — `كشف حساب الأسرة`)

### Decision
- The project filter traces two structured paths with correlated `EXISTS`: line-level `project_cost_id`, and the business record (`project_cost_budgets_payments` → budget → cost → project); never notes, names or family↔project links.
- Soft-deleted mapped Accounts are included (marked `— محذوف`); no new permission (reuses `muwakha_families.view` / `.export`); no balances and no cross-currency totals.
- A stale `.phpunit.result.cache` is evidence of nothing after an outage; rerun instead.

### Reason
- Execution payments write lines with `project_cost_id = NULL`, so line-level tracing alone would silently miss the family's money; omitting deleted Accounts would delete history.

---

### Date
2026-08-17 (Muwakha Family View — linked-accounts section and deleted current Account)

### Decision
- «الحسابات المرتبطة بالأسرة» stays a read-only `RepeatableEntry::table()` in the infolist, ordered `ملاحظات` → linked accounts → `مشاريع المؤاخاة`; each cell keeps `label()` but adds `hiddenLabel()`; widths total 100%; layout pinned by tests.
- Soft-deleted Accounts are hidden there by a display filter only (mapping kept, nothing restored); holder name read per mapping; account name links to the Accounts view only if `canView()`.
- A soft-deleted current Account is REPORTED, never described: `بيانات الحساب` shows only `الحساب الحالي محذوف` (display rule; mapping and `account_id` unchanged).
- Initial project links are collected on the Create form but written by `MuwakhaFamilyService::create()` inside its transaction; `update()` strips them; a forged row without a project is rejected.

### Reason
- Relation managers render after the infolist; showing payment details of a deleted account invites paying into it (user overruled the earlier choice); replaying links through the domain service gives one rollback boundary and identical validation.

---

### Date
2026-08-17 (FINAL — Muwakha historical family Accounts: immutability, exact reuse, ownership mapping)

### Decision
- A Muwakha Account is immutable; a change of material identity (canonical name, `أفراد`, currency, account number, bank type, normalized IBAN, holder name) resolves to a different Account. The service issues no Account UPDATE.
- Exact reuse first, strictly family-scoped through `muwakha_family_accounts` (ownership: family, account, `account_holder_name`; `UNIQUE(family, account)`; no history columns).
- Canonical name `أسرة الشهيد {martyr_name} - {currency} - ({account_code})` via `MuwakhaAccountIdentity::accountNameFor()`; minimal normalization (account number not normalized).
- An inactive or soft-deleted exact match fails safely; never revived or duplicated.
- *(Supersedes the same-day "currency change forks a new Account" decision, which also made the currency an operator choice validated server-side and displayed as `currencies.name`.)*

### Reason
- Historical lines reference `accounts.id`; rewriting an Account would print today's payment details against yesterday's money. Global matching could attach one family to another's ledger.

### Impact
- A family may own several Accounts over time (several per currency); `muwakha_families.account_id` is only the current destination; reports need no change.

---

### Date
2026-08-16 (OMS Muwakha Families — architecture)

### Decision
- The Muwakha root is a `ProjectSuper` (`مشروع المؤاخاة`), shipped by an idempotent data migration matched by name; reference data ships via migrations, not seeders.
- `accounts.account_code` is no longer UNIQUE (plain index).
- Payment details live only on `accounts` (holder name on the family / mapping); no `age` column (computed at martyrdom).
- Personal identifiers are redacted in audit payloads by a subject value policy (`changed_fields` kept); labels carry the martyr name only.
- Roles: Super Admin, Admin, Project Manager full incl. export; Viewer view-only without export; Accountant none.
- *(Superseded in part on 2026-08-17: one Account "for life" became the immutable-Account / ownership-mapping model.)*

### Reason
- `projects` has no parent column; a `ProjectSuper` root is structurally unselectable as a family project. No code treats `account_code` as an identifier.

### Impact
- The `account_code` UNIQUE index cannot be restored once duplicates exist (`down()` fails deliberately); `martyr_national_id` stays UNIQUE across soft-deleted rows and no Restore UI ships.

---

### Date
2026-07-30 (roadmap — the PDF/mPDF export phase is cancelled)

### Decision
- The "Excel + PDF (Arabic) export" roadmap item is CANCELLED; mPDF removed as planned technology; reports are approved with Excel / Word exports where implemented; no next phase is set.

### Reason
- The roadmap contradicted the verified code (`ReportExportFormat` had only `xlsx` / `docx`; no PDF package installed). History was annotated, not deleted.

---

### Date
2026-07-30 (OMS Task 9B.8 — Audit initiative accepted as COMPLETE with no code change)

### Decision
- No index or migration added despite two full-scan shapes; `pint --test` left failing (351 of 712 files, no `pint.json`); the simulated restore-replay test accepted, the real drill reclassified as a deployment item; the real-local `audit_events` drift (4 → 8) reported as external activity.

### Reason
- The plans did not prove an index necessary and every index costs writes on an append-only table; fixing 24 files would half-format the repo; no disposable MySQL harness exists; the extra rows came from real concurrent browser use.

### Impact
- Open non-code items: the Pint / formatting decision and the production restore drill. Capture a fresh audit baseline immediately before any re-run.

---

### Date
2026-07-30 (OMS Task 9B.7 — read-only Audit Log UI)

### Decision
- Categorical columns are read RAW (`AuditRawValue::string()`) and labelled through `AuditLabels`, falling back to the stored value (never "unknown"); the enum casts on `AuditEvent` stay.
- Access = the real `Super Admin` role alone (`AuditViewAuthorization`); no `audit.*` permission created.
- Mutation abilities hard-false in `AuditEventResource` (no Policy); only index / view pages, no bulk actions.
- No index added for `actor_type` / `event_action`-only filters.
- `AuditPayloadPresenter` returns plain text; escaping happens once in the renderer; nothing may call `->html()`.

### Reason
- A row written by a newer build would crash the page through an enum cast; `Gate::before` would bypass any Policy denial for a Super Admin; a permission would create a widening risk; escaping twice would misreport recorded values.

### Impact
- Delegating audit reading later means adding a permission deliberately and changing one method; `AuditLabelCoverageTest` keeps the label maps current.

---

### Date
2026-07-30 (OMS Task 9B.6 — backup and restore auditing)

### Decision
- Restore history survives a database replacement by replaying the existing signed progress journal into the restored `audit_events` (a non-fatal eighth reconciler step and again, idempotently, in `RestoreTerminalResultWriter`); `correlation_id` = restore UUID.
- Everything from `restore_reconciled` on, and `backup_completed` / `backup_failed` / `backup_deleted`, is `BestEffort` and cannot throw; the ledger probe sits inside the guard.
- Failure metadata comes from an exception's class and `reasonCode` only, never `getMessage()`.
- No upload, cancellation or separate confirmation event exists; `restore_interrupted` is written only by the stale acknowledgment.

### Reason
- Those states are written after irreversible changes; escalating an audit failure would falsify a good backup or restore. Driver errors can carry SQL or paths. The event vocabulary must match real code paths.

### Impact
- Replayed rows carry `replayed_after_database_replacement: true`; no migration and no second log.

---

### Date
2026-07-29 (OMS Task 9B.5 — report export and attachment auditing)

### Decision
- Report exports record `export_requested` only (never `export_completed`).
- Attachment access records `attachment.viewed` or `attachment.downloaded`, from the route's explicit `{mode}` (never headers).
- Attachment atomicity is claimed for the database only, not the filesystem.
- The parent key field is named `parent_id`; `AuditRedactor` got no new exception.

### Reason
- Serialization runs inside `streamDownload` after the response is returned, so success is never known; the `parent_key` name was redacted by the segment rule.

### Impact
- A rollback can orphan one new file but never lose a previous one; if `{mode}` is ever collapsed, the two actions collapse to one.

---

### Date
2026-07-29 (OMS Task 9B.4 — security and authentication auditing)

### Decision
- Login / failed / logout events are `BestEffort` in `AuthenticationAuditRecorder`; identity / privilege mutations are `Required` in `SecurityAuditRecorder` (asserted open transaction).
- A failed login is recorded against the resolved User (subject) or a generic unidentified subject, never the submitted email; the actor is always guest.
- De-duplication per attempt (cleared on `Attempting`), recorder bound as a singleton; subscriber methods named `on*`, never `handle*`.
- `UserSecuritySnapshot` is a closed six-field allowlist; a password change records only `password_changed: true`; role / permission arrays sorted, de-duplicated and re-indexed.
- Permission sync writes ONE `security.synced` event per run (even a no-op); no direct-user-permission path or password-reset listener was invented.

### Reason
- A Required auth event could lock every administrator out during an audit outage; the submitted email is attacker-controlled; Filament fires `Failed` twice and Laravel auto-discovers `handle*` methods.

### Impact
- Seeders and post-restore reconciliation now write one `permission_sync` event (accepted); model paths still write none.

---

### Date
2026-07-29 (OMS Task 9B.3 — financial auditing)

### Decision
- Financial subject aliases name the workflow (`project_cost_receipt`, `project_disbursement`, `execution_payment`, `general_expense`, `general_exchange`), never the model class; the Master Reference was corrected.
- One financial action = exactly one AuditEvent carrying `transaction_id` / `transaction_number`; `Transaction` / `TransactionLine` are never audited (pinned by a test).
- `FinancialAuditRecorder` never opens a transaction, fails closed outside one, and records before the success notification.
- Payloads are bounded scalars: money / percentages as 2-decimal strings, FX rates 6; role-keyed account fields; notes truncated to 255.
- `HasUserTracking` on `Account` / `AccountType` with no backfill; `Account` defaults `current_balance` to 0; an opening balance yields one Account event (`recordCreatedWithin()`).

### Reason
- Two workflows are named the inverse of their models; auditing ledger rows would produce 3–5 events per action; a nested transaction would leave an unaudited committed mutation.

### Impact
- If the audit insert fails, the source record, transaction, lines and balances roll back together.

---

### Date
2026-07-26 (OMS Task 7C.5 correction — database-connection policy)

### Decision
- Restore supports only the primary `database.default` connection; `DatabaseRestorer` fails closed with `database_connection_mismatch` otherwise, and `RestoreReconciler` always targets `database.default`.

### Reason
- Migrations, models and `DB::table()` all use the default connection; importing into an override would silently restore one database and reconcile another.

---

### Date
2026-07-27 (OMS Task 7C.8 — stale acknowledgment, launch path and test discipline)

### Decision
- The UI calls `RestoreLaunchService::launch()` directly instead of a self-HTTP POST; the signed POST route remains for external launches.
- `crashed_acknowledged` added to `ALLOWED_PHASES`, used only as `restore_failed_phase` and written only by `RestoreStaleAcknowledgmentService`.
- `RestoreRequestService` re-checks scope compatibility against a freshly read source record inside the lock.
- Test-only: the activation test's default mover uses `maxAttempts: 5` (as production); never run two `php artisan test` processes concurrently (`Storage::fake()` uses fixed paths).

### Reason
- All replay safety lives in the service; a self-request adds session / CSRF risk for nothing. A human-terminalized restore must be distinguishable from an engine failure.

---

### Date
2026-07-26 (OMS Task 7C.5 correction — durable reconciliation snapshots)

### Decision
- The signed `RestoreProgressSnapshot` gains an optional `reconciliationSnapshot` instead of a second persistence mechanism; no schema-version bump.

### Reason
- The progress file is already the durable, signed, bounded record independent of the database.

### Impact
- Metadata can be reconstructed even if the import wiped `backup_operations` before reconciliation.

---

### Date
2026-07-25 (OMS Task 7C.5 — streamed database restoration and post-import reconciliation)

### Decision
- `RestoreReconciler` depends on four injected interfaces (connection resetter, Artisan runner, metadata reconstructor, ephemeral cleaner).
- `BackupOperationSnapshot` / `RestoreOperationSnapshot` take no `status` parameter (forced Completed / Restoring).
- `RestoreEphemeralTablePolicy` deletes only `backups`-queue `jobs` rows with `reserved_at IS NULL`.

### Reason
- One ordering test can prove the 7-step sequence; a caller can never pass a stale dumped status; an in-flight job must never be dropped.

---

### Date
2026-07-25 (OMS Task 7C.4 — replay-safe restore launch and detached launcher)

### Decision
- `LinuxDetachedRestoreProcessLauncher` passes a plain argv array (`setsid --fork …`) to `proc_open()`, never a shell string.
- A tampered progress file maps to HTTP 409 with reason `restore_state_requires_review`.
- `oms.backup.restore.php_binary` defaults to `PHP_BINARY`.

### Reason
- Symfony Process hands array commands to `proc_open()` unmodified, so there is no injection surface.

### Impact
- Production MUST set `OMS_RESTORE_PHP_BINARY` to a CLI `php` (under php-fpm `PHP_BINARY` is the fpm binary). Callers should read the JSON `reason`, not only the status.

---

### Date
2026-07-25 (OMS Task 7C.4 correction pass)

### Decision
- The launch route is `POST /restores/{uuid}/launch` (no GET).
- The restore-activity gate for backup deletion lives inside `BackupDeletionService::eligibility()` (`restore_activity_in_progress`, memoized), not in `delete()`.
- New phase `launching` precedes `lock_acquired`; only `oms:restore` writes `lock_acquired`, after acquiring its lifetime lock.

### Reason
- A signed GET can be prefetched or crawled and skips CSRF; the badge and the delete must never disagree; `lock_acquired` must be literally true.

---

### Date
2026-07-25 (OMS Task 7C.3 — restore preflight, verification, extraction, staging)

### Decision
- `RestoreDiskSpaceEstimator` sums itemized consumers; the mandatory FULL safety backup always counts current DB size (`information_schema`, fallback `max(source, 50 MiB)`) and current attachments.
- `RestoreArchiveExtractor` writes only manifest-declared entries, re-checks size / hash / path safety, and re-verifies the full entry set against the open ZIP just before writing.
- `RestoreWorkspace::cleanup()` removes only its own `{uuid}/workspace/`; no age-based sweep; symlinks refused along the path.
- `RestoreActivityGuard` always reads the excluded UUID's progress file; only a valid, non-terminal one is ignored.

### Reason
- A flat multiplier was forbidden; extraction is a separate code path from verification; an exclusion must never turn tampered state into safe state.

---

### Date
2026-07-23 (OMS Task 7C.2 — independent subsystem lock)

### Decision
- The restore subsystem lock is a real OS `flock()` on `{restores disk}/.locks/subsystem.lock`, not `Cache::lock()`; the existing locked-exception vocabulary (HTTP 423) is reused.

### Reason
- `cache.default` is `database`, which a restore replaces; `flock()` is released by the OS if the holder dies.

---

### Date
2026-07-23 (OMS Task 7C.1 — restore foundation)

### Decision
- A restore's audit row reuses `backup_operations` (`type = restore`), with restore-specific context in one nullable bounded JSON column `restore_metadata` (`launch_nonce` is its own column).

### Reason
- 7B.1 already reserved the columns and status vocabulary; a second table would duplicate working machinery.

---

### Date
2026-07-23 (backup management — deletion-eligibility badge)

### Decision
- The badge uses a public `BackupDeletionService::eligibility()` value object that `delete()` also consumes; the last-known-good lookup is memoized per instance.

### Reason
- The badge and the real delete must never disagree; per-row lookups would reintroduce an N+1.

### Impact
- New deletion rules go into `eligibility()`; the "locked" state is a point-in-time peek.

---

### Date
2026-07-23 (OMS Task 7B.2 — Filament backup management page)

### Decision
- Shared `BackupAuthorization` (real `Super Admin` role AND the `backups.*` permission), re-checked inside every action.
- Manual backups default to `is_protected = false` (7B.1 defect corrected).
- The permission-count test is registry-driven, not a hardcoded number.

### Reason
- `Gate::before` alone would accept a permission granted to a lesser role; unconditional protection made manual backups undeletable, contradicting the spec.

---

### Date
2026-07-23 (OMS Task 7B.1 — backup core)

### Decision
- In-house backup (mysqldump + ZipArchive + libsodium secretstream + Laravel queue / cache), not `spatie/laravel-backup`.
- A candidate archive passes full content verification before publication / `completed`; scheduled dedup via a UNIQUE `deduplication_key`; a per-backup lock covers download / verify / restore through the streamed response.

### Reason
- No package covers the approved restore workflow; verify-after-publish left an unverified `completed` window; only the database constraint is atomic.

### Impact
- Verification, dedup and locking live only in `BackupCreationOrchestrator`, `BackupIntegrityVerifier`, `BackupRetentionService`, `BackupDownloadController`.

---

### Date
2026-07-22 (OMS-wide CRUD redirect standard)

### Decision
- Create / Edit → the record's View page (only if `canView()`, else Index) via one shared concern `RedirectsToResourceView` on all 44 Create / Edit pages; Delete keeps Filament's redirect to Index.

### Reason
- Filament's defaults were inconsistent; one concern cannot drift and is guarded by `CrudRedirectStandardStructureTest`.

### Impact
- Relation-manager / modal CRUD and the four read-only resources are intentional exceptions.

---

### Date
2026-07-22 (test-suite cleanup)

### Decision
- Deleted the stock `ExampleTest` (OMS has no `/` route) and fixed the risky `RoleAssignmentSafetyTest` by asserting the `roles` error.

### Reason
- Never add a fake route to satisfy scaffolding; expected-exception tests must assert on the exception.

---

### Date
2026-07-22 (OMS Task 6D — secure financial attachment registry)

### Decision
- Multi-currency operations show `final_amount` + `disbursementCurrency`; single-currency ones their own `amount` + `currency` (user-approved).
- Parent-permission scoping in `FinancialAttachmentRegistry`, enforced twice (`whereHasMorph` list scope + `canView()` 403); no file-availability filter.

### Reason
- Never blend currencies; hiding rows alone is not authorization; file existence is not a SQL predicate.

### Impact
- The supported-type list must stay identical to `AttachmentController::SUPPORTED_ATTACHABLE_TYPES` (guard test).

---

### Date
2026-07-22 (OMS Task 6C — obsolete orphan public-file cleanup)

### Decision
- Treated as satisfied without deletion: the six orphan files (present at `27969b5`) were already gone; no quarantine or legacy-migration tooling built.

### Reason
- `attachments` held 0 rows and all new attachments use the private disk; fabricating pre-deletion hashes would be dishonest.

### Impact
- Re-open the scope if a `disk = public` row ever appears (e.g. an old backup restored); missing `/storage/...` paths answer 403 here, so assert non-200, not 404.

---

### Date
2026-07-21 (OMS Task 6B — financial attachment cutover)

### Decision
- `AttachmentUploadService::store()` creates the row first, then names and moves the file with the real id, checking `move()`'s boolean return.
- A replacement is stored before the old row is soft-deleted; replacement wins over simultaneous removal.
- One shared `secure-attachment-preview` component on all 5 View and Edit pages, building only `attachments.show` routes from the id.

### Reason
- `MAX+1` ids race; the disk has `throw => false`; a failed replacement must leave the old attachment active; ten copies of raw `Storage::url()` display were the vulnerability.

---

### Date
2026-07-21 (authorization acceptance and permission management)

### Decision
- No `users.assign_roles` permission: role assignment stays `users.create` / `users.update` + privilege-subset validation; `users.assign_super_admin` stays separate.
- Permission sync requires the exact `Super Admin` role AND `permissions.sync` (`PermissionManagementService`).
- `PermissionResource` hard-overrides all 8 mutation `canX()` to false; no Create / Edit pages.
- `permissions.sync` lives in its own `system_permissions` group (`النظام والصلاحيات`).

### Reason
- No behavioural gap justified a new permission; `Gate::before` bypasses Policies for Super Admin; sync can reshape every other permission check.

---

### Date
2026-07-15 (operational-data cleanup tool)

### Decision
- Partner classification uses only `partners.is_donor`; non-donors are one preserved `unknown_unclassified` bucket, never deleted.
- Bulk deletion uses the query builder, not Eloquent `forceDelete()`.

### Reason
- The schema has no field distinguishing beneficiaries or vendors; the query builder ignores SoftDeletes scopes and skips snapshot-dirtying observers.

### Impact
- Deletion order is explicit and FK-verified (`DELETION_ORDER`).

---

### Date
2026-07-18 (positive-amount validation rules, approved by the user)

### Decision
- Every financial amount ≥ 0.01; `fx_rate` > 0 (an explicit 0 is rejected, not defaulted to 1); each percentage in [0, 100] and the combined total strictly < 100; over-budget execution stays a warning.

### Reason
- The five workflows had no server-side check; a 100% combined deduction would post a zero final amount.

### Impact
- `FinancialAmountGuard` is the single home of these rules; every financial write path calls it before its `DB::transaction()`.

---

### Date
2026-07-18

### Decision
- Server-side account validation (user-approved): the same account may sit on both sides in all 5 workflows; every submitted account must exist, not be soft-deleted, and match the expected account type / bank type / currency; Create requires active accounts; Edit lets an unchanged original account stay inactive but a replacement must be active; historical data is never modified.

### Reason
- `account_type_id` / `bank_type_id` are Livewire UI state only, and `Account::find($id)?->increment()` silently skipped balances for missing accounts.

### Impact
- `FinancialAccountGuard::assertAccounts()` is the single authority; financial write flows call it before `DB::transaction()` and use its returned `Account` objects for balance updates.

---

### Date
2026-07-16

### Decision
- Execution Payment's editable credit account must have the payment's currency (no FX fields, no new column; it stays on the `LINE_CREDIT` / `ExecutionSource` line).
- Edit hydrates the credit account from the payment's own saved `LINE_CREDIT` line, never from the budget's current destination line (fixes historical drift).

### Reason
- The flow posts both lines in one currency at `fx_rate = 1`; re-deriving from the budget silently moved historical credit lines and balances.

### Impact
- `ExecutionPaymentForm::validateCreditAccount()` hard-rejects other currencies; a cross-currency account needs a separately approved FX redesign.

---

### Date
2026-07-16

### Decision
- Financial-form layout convention: on `lg`+ the creditor section is right and the debtor section left, side by side (`Grid::make(['default' => 1, 'lg' => 2])`, credit first, native RTL grid, no custom CSS); below `lg` they stack, creditor first. Applied to General Expense, Receipt and Execution Payment forms; deliberately not to the multi-account Disbursement and General Exchange forms.

### Reason
- Consistent layout without custom styling; splitting the shared 2-column «الحسابات» section of the multi-account forms risked regressions for a cosmetic gain.

---

### Date
2026-07-15 (execution)

### Decision
- When the required pre-cleanup backup was missing, stopped and asked instead of using an older backup; then created a fresh `mysqldump --routines --triggers --single-transaction`, passing the password only via `MYSQL_PWD`.

### Reason
- "If the backup is missing, STOP"; credentials never appear in command lines or output.

---

### Date
2026-07-05

### Decision
- Trial Balance requires one `currency_id` and never blends currencies (defaults to the first currency when none is `is_base`); row balances stay uncolored, green / red only on the حالة الميزان badge.

### Reason
- `debit_base` / `credit_base` are the line's own-currency amount, never a base-currency conversion.

---

### Date
2026-07-06

### Decision
- Comprehensive report mappings: «تصنيف المعاملة» = `TransactionSuperType`, «نوع المعاملة» = `TransactionType`, «المشروع» only via `transaction_lines.project_cost_id → projects_costs → projects`. Detail rows may mix currencies; every summary is per currency.
- Donor Financial Report keeps three currency grains separate (cost, disbursement source, execution) and reads admin / transfer deductions from the `LINE_ADMIN` / `LINE_TRANSFER` lines, not stored percentages.

### Reason
- The only reliable relations; cross-currency aggregates are meaningless; deductions stay anchored to what was posted.

### Impact
- Missing type → «غير محدد», no project → «غير مرتبط بمشروع». The donor deduction join assumes one disbursement transaction per budget row.

---

### Date
2026-07-06

### Decision
- Fixed broken attachment images by replacing the stray empty `public/storage` directory with the `storage:link` symlink (user-confirmed); no code change.
- Reset all operational data by `TRUNCATE` of 16 approved tables (settings, lookups, auth and system tables kept), after a full `mysqldump`.

### Reason
- The plain directory made `storage:link` a no-op site-wide. The user wanted a clean slate for real data; an FK audit showed no kept table depended on a purged one.

### Impact
- Pre-2026-07-06 operational history exists only in that backup; attachment files were left on disk.

---

### Date
2026-07-06

### Decision
- Opening balances are a balanced transaction (`OPB-YYYY-XXXX`, type «قيد افتتاحي», super type «قيود افتتاحية») that always **debits** the new account and **credits** a per-currency clearing account «أرصدة افتتاحية» (`OPB-{CUR}`); own-currency `debit_base` / `credit_base` (fx_rate is metadata only); `current_balance` is display-only on the form; `project_cost_id = null`.

### Reason
- Accounts have no nature and are all debit-normal; the user approved these conventions; multiplying by fx_rate would break per-currency totals.

### Impact
- No negative opening balances; clearing accounts carry negative balances by design; soft-deleted lookup rows are restored, not duplicated.

---

### Date
2026-07-14

### Decision
- `transactions.description` is generated for all 6 financial flows by `TransactionDescriptionBuilder` (`دائن: … | مدين: … | ملخص العملية: ….`, credit first; currency from `transaction_lines.currency_id`); not user-editable.
- `transaction_lines` gained generated `description` and `line_role` (enum `TransactionLineRole`, 11 roles) assigned explicitly by each flow — never inferred; zero-amount lines keep `description = NULL`. `notes` / `LINE_*` stay the tags calculations use; moving readers to `line_role` needs a separately approved audit.

### Reason
- One canonical, searchable format; auditors need each line's own purpose, which could not be inferred.

### Impact
- Built inside each flow's `DB::transaction()`; a side with zero lines rolls back the operation.

---

### Date
2026-07-14

### Decision
- `TransactionResource` and `TransactionLineResource` are permanently **read-only audit resources** (create / edit routes removed, `can*` = false); the 6 financial flows are the only write paths. Lists sort newest-first.
- Fixed two migration bugs that broke any fresh `migrate`; deliberately left the MySQL-only backfill migration `2026_06_24_000005` untouched.

### Reason
- Raw create / edit bypassed double-entry and balance handling. Rewriting historical financial backfill SQL is a different, larger risk.

### Impact
- A full fresh `migrate` still fails on that backfill migration; tests migrate only what they need.

---

### Date
2026-07-15

### Decision
- Historical descriptions / roles are backfilled by `php artisan transactions:backfill-descriptions --apply`, classifying a transaction only by a direct `transaction_id` FK on its flow's record (or type «قيد افتتاحي»); `LINE_*` notes only map roles once the flow is known; unclassifiable rows are reported, never guessed.
- The three fields are shown only in the detail sections of four reports (Comprehensive, Account Statement, Project Financial Details, Donor), keeping each report's grain (nested blocks for record-grained reports); `notes` is never shown as the line description.

### Reason
- Reusing the live builders makes backfill byte-identical and idempotent; changing report grain would change what a row means.

### Impact
- Safe to re-run after imports; display-only, NULL shows «—».

---

### Date
2026-07-18

### Decision
- `FinancialTransactionBalanceGuard` compares money as integer minor units with exact equality — no tolerance; it was completed to its approved scope (positivity, required keys, role presence / direction, notes-tag authority) rather than weakening tests.
- **Correction:** `fx_rate` stays a required key with no 1.0 fallback; `EditProjectCostReceipt` now writes `'fx_rate' => 1` explicitly.

### Reason
- Every amount is built with `round(…, 2)`, so a one-cent difference is a real bug; a silent default would leave stored values unchecked.

---

### Date
2026-07-19

### Decision
- Permissions Task 1: `UserPolicy` returns `false` for everything (Super Admin only via `Gate::before`) until full user management lands; least-privilege defaults — Viewer gets no `reports.*`, Accountant exactly the 4 named financial reports, Project Manager report view without export; `execution_payments` gets the same operation set as `project_cost_budgets_payments`.
- `UserResourceLockdownTest` uses real HTTP requests with a test-only `config(['app.env' => 'local'])` (Filament's `Authenticate` admits a non-`FilamentUser` model only in `local`).

### Reason
- The open `UserResource` (any user could assign any role) was the critical risk; ambiguous scopes were read conservatively; both payment resources share one model.

### Impact
- When `UserPolicy` opens, Admin's defaults must be revisited deliberately; the two payment resources' data scopes must be resolved before separate protection.

---

### Date
2026-07-19 (corrections 1–3 — bootstrap admin seeding)

### Decision
- The real administrator account is not the seeder's `superadmin@oms.com`. `DatabaseSeeder::seedSuperAdmin()` returns early when any non-soft-deleted user holds `Super Admin` (checked by role, never email).
- Bootstrap credentials come only from `config('oms.bootstrap_admin.*')` (env `OMS_BOOTSTRAP_ADMIN_EMAIL` / `_NAME` / `_PASSWORD`; no default email / password); a missing value throws before any write, naming the variables only. The hardcoded email and password were removed.
- The bootstrap email is looked up `withTrashed()`: no row → create; active → assign role only; soft-deleted → restore, reset only the password, assign role.
- `oms:sync-permissions` only warns when no Super Admin exists; it never creates users.

### Reason
- No second administrator; no literal credential in source control; `env()` only inside config; `users.email` is unique, and an active account's password must never be overwritten.

### Impact
- A fresh install without a Super Admin needs both variables; if the only Super Admin is soft-deleted, the seeder bootstraps one.

---

### Date
2026-07-19 (correction 2)

### Decision
- `User::canAccessPanel()` returns exactly `! $this->trashed()` — no role, permission, email or ID check.

### Reason
- Authorization belongs in `Gate::before` / Policies / Resource `canX()` only; without `FilamentUser`, Filament locked every user out outside `local`.

---

### Date
2026-07-19 (OMS Permissions Task 2A)

### Decision
- Authorization for the 23 protected models is Policy classes only, found by naming convention (no Resource `canX()` overrides, no `Gate::policy()`), with the soft-delete rule built once into `AuthorizesCrud` (trashed records cannot be viewed / edited normally; restore needs permission). Read-only audit modules may still view trashed records.
- Navigation is proven by a source-level test (no `shouldRegisterNavigation()` overrides except `ProjectCostResource`) plus real HTTP 403 / 200.
- The pre-existing pairing is kept: `ProjectCostBudgetPolicy` enforces `project_cost_budgets_payments.*`, `ProjectCostBudgetsPaymentPolicy` enforces `execution_payments.*` ("MISLEADING NAME" docblocks + test).

### Reason
- Filament already routes abilities through `Gate::getPolicyFor()`; the `SoftDeletingScope` bypass serves `TrashedFilter`; the pairing matches the real Resource → model mapping, and renaming would need a data migration.

### Impact
- A new module needs one Policy using `AuthorizesCrud`; always check a Resource's `$model` before extending either payment policy.

---

### Date
2026-07-20 (OMS Permissions Task 2B)

### Decision
- Report-page access is a `canAccess()` override (`AuthorizesReportAccess`); every export method starts with `authorizeReportExport()` in addition to `->visible()`.
- Tests: real HTTP sidebar assertions; a test-only SQLite `FIELD()` emulation instead of changing report SQL; Livewire denials asserted with `->assertForbidden()`, never by catching `HttpException`.

### Reason
- `canAccess()` gives navigation hiding, URL 403 and per-request re-authorization; `->visible()` cannot stop a direct Livewire call.

### Impact
- Every new export method must call `$this->authorizeReportExport()` first.

---

### Date
2026-07-20 (OMS Permissions Task 4 — secure role management)

### Decision
- System-role protection is structural: `RoleResource::canEdit()` / `canDelete()` call `RoleManagementService::canManageRole()` directly, and every Edit / Delete action has an explicit `->visible()`; `RolePolicy` is registered explicitly with `Gate::policy()`.
- Permission assignment: one collapsible `CheckboxList` per `PermissionRegistry::groups()` module, flattened before calling `RoleManagementService`; no `->relationship()` saving.

### Reason
- `Gate::before` grants Super Admins everything before the Policy runs; Laravel cannot guess the policy for Spatie's `Role`; relationship saving would bypass the service.

### Impact
- New RoleResource actions must use these checks; pages reusing `RoleForm` must flatten `data.permissions.*`.

---

### Date
2026-07-21 (OMS Task 6A — private attachment security foundation)

### Decision
- Attachment disks resolve only through `Attachment::APPROVED_DISKS` (`public`, `attachments`) in `AttachmentStorageService::resolveDisk()`; other values are 404.
- `AttachmentResource` was hardened (hidden, index / view only, all mutation `canX()` = false) rather than deleted.
- `AttachmentController` returns 404 for a missing or soft-deleted parent before authorizing against it.

### Reason
- `attachments.disk` is a free string; the resource's `FileUpload` was a latent upload path, and `Gate::before` bypasses Policies; 404-before-authorize avoids leaking trashed status.

### Impact
- The controller's trashed check is intentionally redundant with `AuthorizesCrud::view()`; keep it.

---

### Date
2026-07-26 (OMS Task 7C.6 — attachment restore primitives)

### Decision
- Quarantine (`attachments.pre_restore.{uuid}`), rollback discard and the swap marker (`attachments.pre_restore.{uuid}.state.json`) are direct siblings of the live `attachments` root, never inside the quarantine or under the `restores` disk.
- `RestoreAttachmentRevalidator::revalidate()` takes an explicit expected file list and total bytes.

### Reason
- Anything inside the quarantine would move with it on `rename()`; same-filesystem renames are only guaranteed beside `attachments`; the marker alone distinguishes `NotActivated` from `Finalized`.

### Impact
- Marker files remain as audit artifacts; their cleanup is a later retention decision.

---

### Date
2026-07-26 (OMS Task 7C.6 crash-safety correction pass)

### Decision
- The swap marker is signed (HMAC-SHA256, context `oms-restore-attachment-swap-v1`) and durably written via `RestoreProgressDurability`; any read failure → `InconsistentNeedsManualReview`.
- Write-ahead phases (`RollbackStarted`, `RollbackLiveDiscarded`, `FinalizationStarted`) accept several explicit directory-fact patterns, all enumerated in `AttachmentSwapStateInspector::inspect()`.
- A marker-write failure right after a successful mutation throws `marker_update_failed_after_mutation` and attempts no corrective move.

### Reason
- The marker is safety-critical for recovery; one durability mechanism; a second automatic mutation through a failing bookkeeping path could lose a recoverable state.

---

### Date
2026-07-26 (test-stability pass)

### Decision
- Tests acquiring a real `BackupSubsystemLock` wrap everything from acquisition to release in one `try { … } finally { $handle->release(); }`.

### Reason
- The `flock()` lock is a process-wide OS resource; a leaked handle blocks unrelated tests.

---

### Date
2026-07-26 (OMS Task 7C.7 — restore_failed_phase stays within the fixed ALLOWED_PHASES vocabulary)

### Decision
- `RestoreOrchestrator` never invents `restore_failed_phase` strings; it reports the `ALLOWED_PHASES` value the restore had reached, pinned via `RestoreTerminalResultWriter::finish($failedPhaseOverride)`.

### Reason
- `create()` validates against the fixed list; an invented string would throw while recording a terminal failure.

### Impact
- `restore_failed_phase` = last phase reached; `error_summary` names the cause.

---

### Date
2026-07-26 → 2026-07-27 (OMS Task 7C.7 — orchestrator seams and hardening)

### Decision
- 7C.6 gaps closed additively: `AttachmentMoveRunner` bound in `AppServiceProvider`; defaulted trailing `PreparedRestore` parameters carry the already-verified attachment manifest; a `RestoreAttachmentLifecycle` interface exists only for a deterministic finalize-failure test.
- The watchdog deduplicates notifications with a cache cooldown (`watchdog_notification_cooldown_minutes`, 60), not a DB column; `RestoreProgressWriter` retries its final `rename()` on a bounded Windows-only schedule.

### Reason
- No re-verification of the archive and no trust in UI / `progress.json`; the watchdog must never mutate restore rows; the heartbeat exposed ~40% intermittent Windows rename failures.

### Impact
- Extend `PreparedRestore` the same way; Linux makes one rename attempt.

---

### Date
2026-07-27 (OMS Task 7C.9 — real E2E acceptance)

### Decision
- `RestoreMaintenanceMode::enter()` calls `down` with no options (this Laravel version has no `--message`).
- After E2E, only manually constructed drill rows / files were deleted; rows produced by the real UI and orchestrator were kept as acceptance evidence.

### Reason
- Every real restore failed at preflight on `--message`; a custom view would be feature expansion. Genuine pipeline rows are evidence, not clutter.

### Impact
- A future maintenance message must use `--render` / `--secret` / `--retry` / `--refresh` and a test against the real Artisan command.

---

### Date
2026-07-27 (OMS Task 8 — `debit_base`/`credit_base` are per-line own-currency values; multi-currency balance is the FX equation, never a raw cross-currency sum)

### Decision
- `JournalBalanceIntegrityChecker` treats `debit_base` / `credit_base` as own-currency amounts and validates 4-line multi-currency transactions with `FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines()`, never a cross-currency sum.
- Transaction numbers: the six identical generators became `GeneratesSequentialTransactionNumbers` (same format), with `retryOnTransactionNumberCollision()` (3 attempts, only on a `transaction_number` unique violation).

### Reason
- The columns are never FX-multiplied; the only numbering gap was an ungraceful failure racing for the first number of a new prefix.

### Impact
- Integrity code classifies by `line_role` set first; unknown shapes (including NULL roles) are bounded WARNINGs.

---

### Date
2026-07-27 → 2026-07-28 (OMS Tasks 8, 8.1, 8.2, 8.3 — schema drift)

### Decision
- Task 8 documented the live-only `bank_accounts`, `transactions.bank_account_id` and `accounts.parent_id` (user choice); Task 8.1 classified them **DEAD_UNUSED** and dropped them (`2026_07_28_100000_drop_dead_bank_accounts_schema.php`).
- Task 8.2: `accounts.account_code` is **nullable by design** (live `NOT NULL` relaxed); `accounts_type.nature` **DEAD_UNUSED** (dropped); `accounts_type.created_by` / `updated_by` **ACTIVE_REQUIRED** (reproduced). Task 8.3: `accounts.created_by` / `updated_by` **ACTIVE_REQUIRED** (guarded, idempotent `2026_07_28_120000`). Neither model is wired to `HasUserTracking` (deferred to Audit Log work).
- These reconciliation migrations have irreversible (no-op) `down()` (post-acceptance correction).

### Reason
- Zero code use and no data for the dead schema; the create migration (unchanged since initial commit `c40e0d8`) declares `account_code` nullable and all code guards empty values; nothing reads `nature`; the tracking columns match the active `HasUserTracking` convention. A rollback cannot tell added columns from pre-existing live drift.

### Impact
- Fresh-vs-live parity for `accounts` / `accounts_type` is exact.

---

### Date
2026-07-28 (OMS Tasks 9A / 9B.1 — Audit Log foundation)

### Decision
- A first-party Audit Log (`audit_events`, a CRUD-diffing layer, explicit domain calls), not a package.
- `AuditEvent` is append-only at the Eloquent level (hooks and `forceDelete()` throw `AuditImmutableRecordException`), not via DB grants or triggers.
- `AuditRedactor` matches exact names, suffixes (`_token` / `_secret`) and whole underscore segments — never substrings — with a narrow safe-exception list (`encryption_key_id` only).
- `AuditLogger::record()` opens no transaction of its own.

### Reason
- Packages cover only generic CRUD events; one shared DB user makes grant lockdown an operational change; substring matching over-redacts; `Required` audits must commit or roll back with the caller's transaction.

### Impact
- Revisit DB-grant lockdown with the production deployment; new safe exceptions need proof and a test.

---

### Date
2026-07-28 (OMS Task 9B.2 — general CRUD auditing is owned by a service, never by model observers)

### Decision
- General-CRUD audit events are written only by `AuditedCrudService`, which wraps the mutation and `AuditLogger::record()` in one `DB::transaction()`; no observer or global hook writes audit events, and no suppression switch exists.

### Reason
- No Filament write path runs in a transaction, so an observer would fire after commit; panel-wide transactions would change every financial flow; seeders / migrations / factories never pass through the service.

### Impact
- New audited write paths must go through the service; financial workflows call `AuditLogger` inside their own transaction.

---

### Date
2026-07-28 (OMS Task 9B.2 — audit payload policy)

### Decision
- Each audited subject declares a closed allowlist of business columns in `AuditSubjectRegistry` (no technical-field denylist).
- `settings.value` is fail-closed: stored only when neither key nor value looks credential-shaped, otherwise `[REDACTED]`. **Review correction (superseding "key stays redacted"):** `settings.key` is emitted as `setting_name` via `AuditSubjectDefinition::$fieldAliases`; `AuditRedactor` unchanged.
- A foreign key is stored as its scalar plus at most one bounded label (`project_id` → `project_label`). **Review correction (superseding "no label on the old side"):** each side is labelled from its own FK value (`withTrashed()`).

### Reason
- An allowlist fails safe; `settings` is free-form; a redacted key made renames unrecoverable; the model's relation reflects the NEW key, so the old side must be resolved from its own id.

### Impact
- Auditing a new column = migrate, then register; `$fieldAliases` is only for a business column whose name collides with the denylist (`settings.key` is the only one).

---

### Date
2026-07-28 (Graphify hygiene)

### Decision
- Graphify exclusions live in `.graphifyignore` (not `.gitignore`); `.claude/**` is excluded, root `CLAUDE.md` stays indexed.
- Only canonical root-level outputs are tracked (`graph.json`, `manifest.json`, `GRAPH_REPORT.md`, `cost.json`, `.graphify_labels.json`, `.graphify_*` markers); `/graphify-out/cache/` and `/graphify-out/20*/` are git-ignored and untracked (kept on disk). (An earlier same-day retention of dated snapshots was superseded.)
- A clean rebuild after new exclusions requires backing up and removing the three root outputs first; `graphify update .` (even `--force`) keeps stale nodes.

### Reason
- The cache's stat index is keyed by absolute machine paths and the dated snapshots carried thousands of storage / backup paths; Git history already versions the graph.

### Impact
- Left for a separate decision: the tracked `graphify-out/.graphify_python` holds one machine-local interpreter path.

---

### Date
2026-09-20 (تقرير الحركات المالية الشامل — notes, expansion keys, soft-deleted labels)

### Decision
- Source-record notes are pre-fetched with one bounded `whereIn('transaction_id', …)` per source table and merged after accumulation — never joined.
- Classification expansion is server-rendered (Livewire `$openCategoryKey`), notes expansion client-side (Alpine); buckets are keyed by lookup ID (`id-<n>` / `none`), names are display-only.
- `bank_types` is joined without a `deleted_at` guard; the five source-note tables filter `deleted_at`.
- The expand spinner uses parameterised `wire:target="toggleCategory('<key>')"`; the nested table has its own `--cft-sub-*` tokens and overrides by source order.

### Reason
- Two source tables are one-to-many (a join would multiply totals); names are not unique; a bank type is a historical label, a deleted source record is a deleted document.

### Impact
- Toggling issues 0 `transaction_lines` queries; same-named classifications now show separately. `.cft-subtable` CSS must stay below `.cft-table`, and `wire:click` / `wire:target` must keep identical arguments.

---

### Date
2026-09-21 (تقرير الحركات المالية الشامل — type expansion and scroll controls)

### Decision
- `$openTypeKey` is a separate property (independent of `$openCategoryKey`); rows expose `type_key` and expanded types filter by it, never by name.
- Scroll bars are `wire:ignore` with spacers observed; the nested width pin is CSS, not Alpine; `refresh()` re-probes bounds when either content width or viewport changes.
- Browser-layout / Livewire-lifecycle bugs are measured on the real authenticated page before naming a cause.

### Reason
- One shared key would collapse the other section; Livewire's morph strips runtime inline widths from surviving nodes (measured); harness-validated fixes had missed that cause.

### Impact
- Authentication is a hard boundary: ask the user to log in; never read `.env` or invent credentials.

---

### Date
2026-09-22 (المصروفات العامة — a general expense has no beneficiary; NULL, never the organization itself)

### Decision
- New General Expenses store `partner_id = NULL` on `general_expenses` and `transactions` (the select was removed); historical values are preserved on edit from the record, never from submitted data; the organization is not auto-filled.

### Reason
- NULL was already legal everywhere and nothing reads the value; `partners` models external counterparties.

### Impact
- No migration or report change; dropping the column is a separate decision.

---

### Date
2026-09-22 (Transaction Lines list — bank type column, search and filters)

### Decision
- «نوع البنك» is a display-only, always-visible column from `account.bankType.name` (eager-loaded; missing → «—»).
- Search is declared once at table level; `line_role` matches by Arabic label → enum values; amounts only by exact value for numeric terms; `fx_rate` excluded; filters grow from 2 to 8 via scoped `whereHas`.

### Reason
- Nested sort / search needs joins for no gain; Filament skips hidden columns in per-column search; the stored role is English; `fx_rate` would match almost every line.

---

### Date
2026-09-26 (operational reset: `accounts` and `partners` are operational data; the production safeguard stays; `notifications` is backup history)

### Decision
- `OperationalDataCleanupService` is the single definition of an operational reset: `accounts` and `partners` (donors included) are deleted, their lookups kept (user decision); the production guard is unchanged (`ALLOWED_ENVIRONMENTS` = local / development / testing, no bypass); `notifications` is preserved; an attachment on a disk the backup does not cover is never deleted (`apply()` fails closed; `--skip-files` = database only).

### Reason
- Accounts without their transactions leave unsupported balances; weakening the guard and deleting together removes reviewability; public-disk files are in no backup.

### Impact
- Deletion order is load-bearing and checked against `information_schema`. Open: production execution, AUTO_INCREMENT resets, public-disk backup coverage.

---

### Date
2026-09-28 (search Batch 0: Attachments global search uses the list's scope; picker search columns follow the displayed label; wildcard escaping deferred)

### Decision
- Attachments global search starts from `FinancialAttachmentRegistry::scopeViewableBy()` (user choice), keeping per-result `canView()`; a relationship Select searches every column its label shows (project pickers: `['code', 'name']`); LIKE escaping deferred to the shared helper; no migration, index, collation or generated column for search until latency requires it.

### Reason
- List and search should offer the same rows; no MySQL collation folds alef variants, leading-wildcard LIKE cannot use indexes, and tables are small.

---

### Date
2026-09-28 (search Batch A: Arabic search normalization rules, LIKE escape character, grouped relation EXISTS)

### Decision
1. Default normalization (`ArabicSearch`): NFC first (correction pass); strip harakat U+064B–U+0652, U+0670, tatweel U+0640 and zero-width / bidi marks; Arabic-Indic and Persian digits → ASCII; collapse whitespace (NBSP included); for human text only, fold alef variants U+0623 / U+0625 / U+0622 / U+0671 → U+0627.
2. Never folded by default: ى/ي, ة/ه, ؤ/و, ئ/ي, ک/ك, ی/ي (ى/ي only for person names, Batch D; ة/ه rejected). Combining hamza / madda are never stripped.
3. Human text gets column-side alef folding; identifiers (transaction numbers, references, account / currency codes) are compared as stored.
4. LIKE escaping uses `!` with an explicit `ESCAPE '!'`; amount terms are strict (plain digits / decimal or well-formed thousands groups; `٫` is a decimal point; signs, exponents and malformed grouping rejected); fields on one relation are grouped in one `whereHas`; the `line_role` machine-value match requires a Latin letter.

### Reason
- MySQL `utf8mb4_unicode_ci` folds neither alef variants, harakat nor tatweel; each excluded pair distinguishes real words; neither SQLite nor MySQL (`NO_BACKSLASH_ESCAPES`) has a reliable default escape; the old parser accepted `1e3` and `1,50,0`; EXISTS(A) OR EXISTS(B) = EXISTS(A OR B), proven on MySQL data.

### Impact
- `ArabicSearch` is the single normalizer for all later batches; no schema, collation or data change.

---

### Date
2026-09-28 (search Batch B: identifier kinds, phone matching, lookup selection, global search override)

### Decision
1. Three identifier behaviours built on `ArabicSearch::clean()`: plain (codes, email, currency code / symbol); compact (IBAN, spaces ignored both sides); phone (term digits vs the column with ` - + ( )` stripped; only terms made solely of digits and those characters; never `numeric()`; no country-code interpretation).
2. Lookup search follows field semantics, not current data: every free Arabic name gets text semantics (account / bank types, currencies, transaction super types / types, project statuses, partner types, fiscal-year names); no new search boxes.
3. Global search for accounts and projects overrides `applyGlobalSearchAttributeConstraints()`: every word must match the code or the name; the code is a result detail.

### Reason
- Identifiers and phones are stored in many formats; `numeric()` is for amounts. Fixing only lookups with a current miss made behaviour depend on today's data. Filament's default global search was a plain `LIKE` on the title only; the override keeps Filament's authorization and limits.

### Impact
- Sorting deliberately untouched (the `id asc` vs `id desc` split is recorded for a separate decision).

---

### Date
2026-09-28 (search Batch C1 + C2: financial list search scope, lookup filters, display N+1)

### Decision
1. Each financial list searches only what it shows (hidden toggleable columns included): transaction number, project and المشروع الرئيسي, the partner / donor as labelled, transaction type and line accounts where shown, and the page's own visible amounts by exact value. Never `fx_rate`, percentages, derived amounts or another record's figures.
2. Line accounts are searched across all lines of the operation's transaction, not by line tag.
3. Project and partner filters are bounded server-side lookups (`FinancialLookupFilters`) keeping each page's apply closure and eligibility scope; shared helpers `FinancialListSearch` / `FinancialLookupFilters`.
4. إشعار مرفق uses `withExists('attachments')` in the resources' `getEloquentQuery()` (and the receipts relation manager); receipt account columns read eager-loaded `transaction.lines.account` with the same selection rule (first live line with positive `debit_base` / `credit_base`, by id).

### Reason
- Useful search, not maximal fields; matching by tag would duplicate display rules; `SelectFilter::relationship()` cannot express multi-hop paths; the two measured N+1 sources only.

### Impact
- Constant query count per list; no accounting, form, report, schema or data change.

---

### Date
2026-09-28 (search Batch D: person-name ى/ي fold, current-account-only list search, Muwakha global search)

### Decision
1. ى (U+0649) → ي (U+064A) folds for person names only (martyr, guardian, account holder) via `ArabicSearch::normalizePersonName()` / `foldPersonNameSql()` / `whereContainsPersonName()`; ة/ه never.
2. The Muwakha families list searches only the family's CURRENT account, never historical `familyAccounts`.
3. National IDs, account codes and card codes are identifiers (digits normalized, leading zeros kept, partial match); the guardian phone uses phone semantics.
4. Muwakha global search: martyr name, martyr national ID, card code; the detail shows card code(s), never the national ID.

### Reason
- علي / على are typed either way only in names; search should match what the list shows; IDs are not numbers; card codes disambiguate without exposing personal identifiers.

---

### Date
2026-09-28 (search Batch E: server-side lookup Selects; the label resolver is the eligibility rule)

### Decision
- Large lookup Selects search on the server (`getSearchResultsUsing` + `getOptionLabelUsing(s)`, ≤ 50 results, nothing preloaded): the comprehensive report's account and project, the donor report's donor and project, the general report's project and donor, and the three financial forms' partner / donor Select.
- Each label resolver applies exactly the old option list's eligibility scope, so it is also the validation rule; small lookups keep static lists; project pickers are labelled `code - name`.

### Reason
- The account table grows with every Muwakha account and cannot fold Arabic client-side; without the scoped resolver the server-side eligibility check would be silently lost.

---

### Date
2026-09-28 (search Batch F + G: final search consistency)

### Decision
- Topbar global search goes through one trait, `SearchesGloballyWithArabicSearch`, with per-key semantics (`text` / `identifier`); disabled (`$isGloballySearchable = false`) for ExchangeRateHistories, AuditEvents, Permissions and Settings. Transactions are found globally by number and reference only; the Transactions list also searches notes. Machine keys keep plain search.
- The general projects report searches its snapshot's own denormalized columns; the budget-payment partner Select and the attachments project filter reuse `FinancialLookupFilters` (attachments label `code - name`); Permissions `roles.name` gets text semantics.

### Reason
- Same semantics as the list pages without 14 copies; noise keys cluttered every topbar search; snapshot rows should be searched by what they show.

### Impact
- Sorting unchanged; the sort convention remains an open product decision.

---

### Date
2026-10-05 (OMS stress environment)

### Decision
1. Stress data lives only in the separate local database `oms_stress` via a git-ignored `.env.stress` profile (`--env=stress`) with its own `LARAVEL_STORAGE_PATH`; every command is guarded by fixed allow / deny lists, a marker table and double confirmation.
2. The generator calls the current production builders and every payload passes the real financial guards; bulk inserts with explicit ids; `current_balance` written once from a cents ledger and re-verified independently; full audit history generated (backdated) through a staging table.
3. Schema parity may add only plain secondary indexes present in `oms` but not in a fresh migrate. *(Superseded later the same day: parity is checked against a fresh-migration reference.)*
4. Browser access via `php artisan serve` on 127.0.0.1:8081 under `APP_ENV=stress` with a stress-only banner; chunk size 500 (measured fastest; 5,000 exceeds MySQL's placeholder limit); slow screens measured, not optimized.

### Reason
- Production and the normal dev database must never receive synthetic data; no drift from what the application writes; realistic audit volume was required; machine-level web-server changes were not safe to automate.

### Impact
- Stress tooling and docs only, plus one environment-gated render hook.
