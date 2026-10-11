# Prompts Log

Reusable prompt patterns and short references to past prompts. What was done lives in TASKS_LOG; decisions in DECISIONS_LOG.

## Prompt Template

### Date

### Prompt

### Purpose

---

### Date
2026-10-11 (both financial reports: "SEPARATE DATA EXPORT FROM STATISTICS EXPORT" → "Word must ALSO become STATISTICS-ONLY" → "FINAL PRE-COMMIT — single-sheet statistics Excel + safe dead-code cleanup")

### Prompt
CSV = detail rows only (one plain CSV when enough, ZIP only when needed); Excel and Word = the same statistics only, Excel on one RTL worksheet; re-decide the Word limit from the statistics workload; Excel == Word with 0 differences; remove only proven-dead code.

### Purpose
- "If one CSV is enough, use one data CSV" → the user chose a plain CSV up to 100,000 rows and a ZIP above. "Before/after" on a noisy laptop → an interleaved A/B against an exact copy of the old code.
- "Do NOT keep a too-many-movements refusal just because it existed before" and "Audit references FIRST" (code, routes, config, views, tests, database, Graphify) before deleting anything.

---

### Date
2026-10-10 ("NEXT TASK — ULTRA-FAST CSV EXPORT FOR COMPREHENSIVE FINANCIAL TRANSACTIONS")

### Prompt
The Account Statement CSV idea on the comprehensive report: applied filters, all lines from the real-time projection, 16 detail columns, consistent under concurrent writes, measured floors and matrix, independent 0-difference verification.

### Purpose
- "If MySQL needs 12 seconds just to read the rows, say so clearly" → a per-column floor breakdown that exposed two avoidable costs before the physical floor.
- "Do not deliver a mixed-time export" → the start-marker / re-verify protocol proving one instant.

---

### Date
2026-10-08 ("NEXT TASK — ACCOUNT STATEMENT ULTRA-FAST CSV EXPORT")

### Prompt
«تصدير CSV» on the Account Statement: streamed from the projection, exact per-account running balance, Excel-friendly and formula-safe, 1M rows in ≤ 5 s as a stretch target with an A–F floor test.

### Purpose
- "If 5 seconds is physically impossible, report the real measured lower bound" and "Do not remove financially useful information just for speed".

---

### Date
2026-10-07 ("MAJOR TASK — REDESIGN THE ACCOUNT STATEMENT FOR MULTI-ACCOUNT REPORTING, EXTREME SPEED, AND HIGH FLEXIBILITY")

### Prompt
Multi-account statement with per-account running balances, multi-value filters with server-searchable pickers, 50-row keyset paging, < 1 s at ~1M rows, no queue for browsing; audit and baseline first; verifier, rebuild, parity and benchmark matrix.

### Purpose
- "Do NOT guess — ask only real business-semantics questions" produced the two questions that decided the design (actual vs filtered balance; empty Accounts = all matching).
- "Do not rewrite a million rows on a backdated write" → exact checkpoint totals instead of stored running balances.

---

### Date
2026-10-05 ("BUILD A COMPLETE, REALISTIC, FULL-SYSTEM OMS STRESS-TEST DATABASE")

### Prompt
Audit first; separate `oms_stress` with hard guards; ≥ 500,000 lines from real line shapes only; deterministic seed; small smoke build before large; STOP on any integrity failure; then benchmark the real application.

### Purpose
- "STOP on any failure" mattered twice: a schema-parity abort exposed a missing plain index, and a fail-clean large build exposed a flush-order bug the small scale could not reach.

---

### Date
2026-10-05 ("APPROVED — COMMIT V2 FIRST, THEN AUDIT + IMPLEMENT THE FINAL REAL-TIME REPORTING PROJECTION" / "NO MySQL triggers" / "ZERO differences" / "DO NOT MIGRATE OR MODIFY THE NORMAL `oms` DATABASE")

### Prompt
A synchronous projection of the comprehensive report maintained inside each business transaction; keyset pager; V2 snapshot kept for exports and fallback; the old report as oracle; one local commit only when every gate is green.

### Purpose
- "Benchmark direct aggregation first; add aggregate tables only if proven needed" and "the old report is the oracle" (a parity checker as part of the deliverable).
- "DO NOT MIGRATE `oms`" turned a blocked step into a design fix: stress schema parity against a fresh-migration reference.

---

### Date
2026-10-04 (comprehensive report scalability: "This is the FINAL architecture work" / "DO NOT GUESS" / "Do not fake an exact percentage" / "Do NOT silently start or install a production worker")

### Prompt
Bounded keyset reading, an immutable owner-bound snapshot, snapshot paging, queued build with status polling, OpenSpout large Excel, a measured Word limit and a deployment checklist; every visible behaviour preserved.

### Purpose
- Measure before and after with the same harness, against a frozen copy of the old code as oracle — the measurement caught two designs that passed every correctness test.
- "Report every visible difference honestly" → a cell-by-cell comparison caught two real defects (14-digit rounding, "=" text becoming a formula).

---

### Date
2026-09-26 (Muwakha 72-family import: implementation + dry run, then a separate execution authorization)

### Prompt
A one-time importer through the official write path only (`MuwakhaFamilyService::create()`), with approved source anomalies listed one by one; real execution authorized only in a third message, with the preflight re-run immediately before the write and before-counts / max ids recorded.

### Purpose
- "Derive the payload field names from the real code, do not guess them from this prompt"; "Report ALL errors, not the first"; "Do NOT choose an actor yourself".
- "Do not assume the audit_events delta" (+288 = 4 × 72) and a required post-import dry run proving duplicate protection.

---

### Date
2026-09-26 (destructive operational-data reset — audit-only Phase 1, deletion forbidden until confirmed)

### Prompt
Extend the existing cleanup service and command; explicit preserve / empty inventories; verified backup first; sixteen named test assertions; a pre-execution report, then STOP.

### Purpose
- "If anything differs from the audit below, STOP and ASK" — it caught omissions as well as contradictions.
- "Do not disable foreign-key safety globally" and "do not pretend filesystem deletion can be rolled back by a DB transaction" became checkable invariants and a fail-closed guard.

---

### Date
2026-09-28 (whole-application search: audit → Batches 0, A–G)

### Prompt
An audit-only study, then one batch at a time: `ArabicSearch` proved on one page first with an explicit do-not-normalize list; master data; financial lists; display N+1; Muwakha; large lookup Selects ("the current selected value must always display"); remaining gaps. Batch G re-checked its four findings against the committed tree (`20ac78f`) before editing.

### Purpose
- "Confirm the audit still matches the code" before each batch; "Do NOT mechanically make every field searchable"; "Measure the current query count first".

---

### Date
2026-09-22 (small report / list changes fenced by an audit: debit/credit side filter, transaction-lines search, `نوع البنك` column, removing a field)

### Prompt
Each brief opened with "AUDIT CURRENT IMPLEMENTATION FIRST", named the files and facts to confirm rather than assume, and said "If the architecture differs: STOP AND ASK". The field removal was "DO NOT MODIFY ANY CODE YET" until a full impact audit (live schema, exact records) was reviewed.

### Purpose
- Name the forbidden inference sources (e.g. never infer the side from `line_role`) and pre-authorize the weaker option ("report a field that would make every search worse").

---

### Date
2026-09-20 → 2026-09-21 (comprehensive report UI changes and the scroll-jitter diagnosis)

### Prompt
Audit-only first, then six approved UI / filter changes; expanded classification rendered "the SAME as the main table"; then "STOP guessing from code" — reproduce the jitter on the real authenticated page in Chrome and report evidence BEFORE modifying code.

### Purpose
- Specify the target by reference, not by attributes; keep a hard data / logic fence for presentation-only work.
- Require BEFORE evidence on the real page (not a synthetic harness) and do not claim a fix until the user's exact sequence is reproduced.

---

### Date
2026-08-19 (production bug: BUD / EXT zero percentages — diagnosis → impact audit → implementation)

### Prompt
Three fenced turns: "DIAGNOSIS ONLY … Do NOT change any code yet … Do NOT guess the cause", then an impact audit, then the fix; "Do not weaken the guard".

### Purpose
- The diagnosis-only fence showed the "device-specific" framing was wrong (the bug was input-specific).

---

### Date
2026-08-18 (recovery after a power outage — Muwakha Family Account Statement)

### Prompt
"Do NOT assume the previous process completed cleanly"; "Do not trust prior terminal output unless reproducible from the current on-disk state"; do not recreate working code; resume from the smallest test scope.

---

### Date
2026-08-16 → 2026-08-17 (Muwakha Families: read-only audit, implementation, then family-account currency, historical Account design and View-page refinements)

### Prompt
Audit whether `accounts` can represent beneficiary payment accounts before building; then tightly fenced refinements ("Do not redesign anything else … STOP AND ASK — DO NOT GUESS"), each with a numbered test list.

### Purpose
- "Do not guess from documentation if the actual code gives a different answer"; numbered test lists turn prose guarantees into checkable facts.

---

### Date
2026-07-30 (roadmap cleanup — remove the obsolete PDF export roadmap)

### Prompt
Documentation-only: remove stale PDF / mPDF plans, keep valid history marked CANCELLED or superseded; no next phase set.

---

### Date
2026-07-28 → 2026-07-30 (Audit Log: Task 9A design audit, 9B.1 foundation, 9B.2–9B.6 integrations, 9B.7 read-only UI + compatibility correction, 9B.8 final acceptance)

### Prompt
Read-only design first; then a foundation in isolation; then one surface per task (general CRUD, financial, users / roles / auth, attachments / exports, backup / restore), a read-only Super Admin UI, and a final acceptance with a coverage matrix. 9B.8 started from the accepted state `358b7d8` / `6481bda`; a worktree at `6061a1d` proved one failure pre-existing.

### Purpose
- "Audit actual write paths, not model names assumed from documentation"; the audit insert must join the workflow's existing transaction.
- "Classify every failure" and reproduce it before the change to prove whether it is pre-existing.

---

### Date
2026-07-28 (Graphify hygiene: sensitive-path exclusion, cache untracking, dated snapshots and `.claude/**`)

### Prompt
Audit the installed Graphify source first; exclude uploaded files and compiled caches; untrack `graphify-out/cache/**` and the dated snapshots by repository policy (no hand-redaction, no scrubber); two byte-identical from-scratch rebuilds.

---

### Date
2026-07-27 → 2026-07-28 (Task 8 financial & database integrity review; 8.1–8.3 schema-drift reconciliation)

### Prompt
Read-only integrity audit first (never compare amounts across currencies; the balance rule comes from the real guard); then evidence-first classification of each drifted object (ACTIVE_REQUIRED / LEGACY_WITH_DATA / DEAD_UNUSED / AMBIGUOUS), backup first, exact SQL shown first, rollback safety for live data. 8.2 committed as `5aea136` with Graphify follow-up `5063b86`; 8.3 started from that HEAD.

### Purpose
- "Do not assume either 'old and unused' or 'actively required'" — decide from code, git history and live data.

---

### Date
2026-07-23 → 2026-07-27 (backup & restore: Task 7A audit, 7B.1–7B.2 backup core and page, 7C.1–7C.9 restore engine, UI and real local acceptance)

### Prompt
A read-only architecture audit, then one isolated layer per task (lock and signed progress, preflight / decrypt / stage, replay-safe launch, streamed import and reconciliation, attachment swap, orchestrator, Filament UI, real Windows / Laragon end-to-end), each followed by an "approved in principle, do not commit yet" correction pass.

### Purpose
- Build destructive capability layer by layer with nothing user-reachable until the end; zero tolerance for "passes on rerun"; prove against the real environment, not only mocks.

---

### Date
2026-07-21 → 2026-07-22 (attachment security: Task 6A private foundation, 6B cutover, 6C orphan cleanup, 6D read-only registry; CRUD redirect standard; test-suite cleanup)

### Prompt
Audit first; build and verify the private-serving foundation before cutting the live forms over; delete only the confirmed orphan files; a structurally read-only registry. Redirects: View after Create / Edit, Index after Delete. Remove only the impossible stock test; no fake `/` route.

---

### Date
2026-07-19 → 2026-07-21 (roles & permissions: read-only discovery, Tasks 1, 2A, 2B, 4, 5, three security corrections, final acceptance)

### Prompt
Discovery without edits first; then a foundation (`Gate::before` Super Admin, `PermissionRegistry`), Resource / RelationManager policies, report and export authorization, secure role management, read-only permission management, and an end-to-end acceptance pass.

### Purpose
- A negative search result is not proof (the real admin had a different email); never keep a hardcoded bootstrap credential in source control.

---

### Date
2026-07-18 (financial validation: positive amounts, server-side account validation, balance-guard verification)

### Prompt
Read-only audit with a per-field matrix and business decisions first; then the smallest server-side guards, tested so they cannot be satisfied by weakening production code.

---

### Date
2026-07-16 (UI layout standard for credit / debit sections; General Exchange rearrangement; Execution Payment credit-account audit and implementation)

### Prompt
Layout only, verified against real RTL rendering; for the credit account, a read-only audit first, then the approved change without a migration and without touching the listed protected files.

---

### Date
2026-07-14 → 2026-07-15 (system-generated transaction and line descriptions, `line_role`, read-only raw transactions, historical backfill)

### Prompt
Exact Arabic description formats on all six financial flows; `line_role` from one vocabulary, assigned by the authoritative flow and never inferred; close the raw-CRUD bypass; backfill history with `--dry-run` / `--apply` and an explicit classification priority.

---

### Date
2026-07-06 → 2026-07-15 (operational data resets and the `oms:clean-operational-data` command)

### Prompt
Explicit keep / purge lists; no `migrate:fresh`, no dropped tables; dry run / audit first, STOP before execution; a fresh backup and a numbered post-apply verification checklist.

---

### Date
2026-07-06 (account opening balances)

### Prompt
Optional opening-balance fields on create only, posting a balanced «قيد افتتاحي» (`OPB-YYYY-XXXX`) against a «أرصدة افتتاحية» clearing account; read-only impact report first.

---

### Date
2026-07-05 → 2026-07-06 (Trial Balance and تقرير الحركات المالية الشامل: phase 1 pages, summary wording, phase 2 Excel / Word exports)

### Prompt
Read-only report pages with a required currency (no cross-currency blending); exports use only the applied / displayed result, blocked before «عرض».
