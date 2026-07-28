# OMS — NGO Organization Management System
## Master Reference Document (Single Source of Truth)

> **Purpose of this document:** A complete, self-contained reference so that any fresh chat with zero prior context can instantly understand the full picture of this project. Save this into the Claude Project knowledge base.

---

## 1. PROJECT OVERVIEW

### What it is
**OMS (Organization Management System)** — an Arabic, right-to-left (RTL) **financial management system for an NGO/charity** ("جمعية كاف"). It manages the full money lifecycle of charitable projects: planning project costs, receiving donor funds, disbursing budgets (with deductions and currency conversion), executing payments to beneficiaries, general expenses/exchanges, and producing financial control reports.

### Who it's for
- **Primary builder/owner:** Gayth (GitHub: `almadani-dev`, repo: `oms`).
- **End users:** NGO finance staff who record financial movements.
- **Reporting audience:** A non-technical manager who receives simple Arabic daily reports.

### Working method (IMPORTANT for future sessions)
- This chat (the **advisor**) discusses concepts in Arabic and writes **English prompts**.
- The English prompts are pasted into **Claude Code** (a separate tool inside VS Code/Laragon) which actually edits the codebase.
- A second AI tool, **Codex**, was also used independently to advance some work; Claude Code then **audited and corrected** Codex's output.
- The owner confirms details before each prompt, and commits to GitHub after each milestone.
- Prompts to Claude Code always instruct: **"If you need clarification, STOP and ASK — do not guess."**

### Tech stack
| Layer | Technology |
|---|---|
| Framework | Laravel 13.14 |
| Admin/UI | Filament v5 (5.6.7) |
| Auth/permissions | Spatie Permission v8 |
| PHP | 8.3.28 |
| DB | MySQL |
| Local env | Laragon (`http://oms.test` or `localhost/oms/public`) |
| Project path | `C:\laragon\www\oms` |
| PDF (planned) | mPDF (for Arabic PDF export) |

### Critical environment facts
- **OPcache in Laragon is THE critical performance fix.** Without it, PHP recompiles ~13,325 vendor files per request (~2s). DB queries themselves are only ~3ms. After enabling OPcache and removing `deferLoading()`, navigation became fast.
- Safe dev cache commands: `php artisan optimize:clear && php artisan filament:optimize && php artisan icons:cache`.
- **AVOID** `config:cache` / `route:cache` during dev — they cause stale 500 errors.
- Filament v5 specifics: `->rtl()` / `->locale()` **DO NOT EXIST**. Arabic RTL is achieved via `app.locale='ar'` + `dir="rtl"`. `Get`/`Set` import from `Filament\Schemas\Components\Utilities\Get` (NOT `Filament\Forms\Get`).

---

## 2. ARCHITECTURE

### High-level structure
The system is a Filament admin panel organized into navigation groups (in order):
1. **التقارير** (Reports) — *first group*
2. **المشاريع** (Projects)
3. **المالية** (Finance)
4. **الشركاء والبنوك** (Partners & Banks)
5. **الإعدادات** (Settings)
6. **النظام** (System)

### Database — core tables (~24, ALL use SoftDeletes)

**Lookups:** `fiscal_years`, `currencies`, `exchange_rate_histories`, `bank_types`, `projects_super`, `projects_status`, `partners_types`, `accounts_type`, `transaction_super_types`, `transactions_types`, `settings`.

**Currencies** (seeded): id 1 = USD (`is_base=1`), id 2 = ILS, id 3 = EUR.

**Project status** (seeded): id 1 = غير مكتمل (color `#ff0000`), id 2 = مكتمل (color `#05ff00`). Color is a stored hex via ColorPicker — rendered as an HTML pill, not a Filament palette name.

**Projects super** (seeded): id 8 = ايتام (prefix ORPHANS), id 9 = الطعام (prefix FOOD).

**Core:**
- `partners` — has `is_donor`; id 1 = "جمعية كاف".
- `accounts` — `account_code` (nullable by design — see below), `currency_id`, `bank_type_id`, `current_balance` (STORED, updated via increment/decrement — never re-summed). (`parent_id` existed live with no migration file and zero usage; removed in OMS Task 8.1, 2026-07-28 — see docs/DECISIONS_LOG.md.)
- `accounts.account_code` is nullable by design (original migration + `AccountForm` + every read site's `$account->account_code ? ... : ''` guard all agree); a live-only `NOT NULL` drift was reconciled to match in OMS Task 8.2, 2026-07-28.
- `accounts_type` has no `nature` (asset/liability/equity) concept — the system has none, every account is uniformly debit-normal (see "Important Notes" in `docs/AI_PROJECT_MEMORY.md`). A live-only `nature` enum + `created_by`/`updated_by` audit columns (no migration file) were reconciled in OMS Task 8.2, 2026-07-28: `nature` dropped (dead), `created_by`/`updated_by` kept and reproduced (matches the `HasUserTracking` audit-column convention used by 20+ other models, though `AccountType` itself is not yet wired to it).
- `accounts.created_by`/`updated_by` (nullable FK→`users`, `ON DELETE SET NULL`) exist but hold zero non-null values today (unbroken NULL since the earliest captured data) — `Account` is not wired to `HasUserTracking` and no current write path populates them. A live-only drift (no migration file) was reconciled in OMS Task 8.3, 2026-07-28: schema reproduced (matches the same convention as `accounts_type`'s copies), but activating creator/updater tracking behavior is deliberately deferred to the future Audit Log task — not started here.

**Projects (5 tables):**
- `projects` — auto code `PREFIX_YYYYMMDD_001`; columns: `name`, `code`, `project_super_id`, `donor_id`, `project_status_id`, `approval_date`, `implementation_date`, `start_date`, `end_date`, `donor_project_name` (free text), `notes`. NOTE: `budget_amount`, `currency_id`, `approved_by` were dropped by later migrations.
- `projects_costs` — `project_id`, `account_type_id` (nullable), `amount`, `currency_id` (nullable), `notes`.
- `project_cost_budgets` — the disbursement record (see denormalized columns below).
- `project_cost_budgets_payments` — execution payments drawn from a budget.
- `project_cost_receipts` — money received against a project cost.

**Finance:**
- `transactions`, `transaction_lines` (`debit_base`, `credit_base`, `fx_rate`, `currency_id`, `account_id`, `project_cost_id`, `amount_currency`, `notes`, + since 2026-07-14: system-generated `description` nullable text + `line_role` nullable string(50) — additive metadata only, NULL on historical rows, never parsed for accounting).
- `general_expenses`, `general_exchanges`, `attachments` (polymorphic).

### Accounting model (double-entry) — [DECISION]
Every financial operation = **1 transaction + ≥2 transaction_lines**, all inside a `DB::transaction()`. `SUM(debit_base) == SUM(credit_base)`. Asset increase = debit; Revenue increase = credit. Account balances are updated via increment/decrement and **reversed on edit/delete**.

### The five financial pages (all have CRUD + view + list + attachments)
All under the **المالية** group. Transaction numbers use `MAX+1` (including trashed rows, with `lockForUpdate`, 4-digit zero-padded) to avoid duplicates.

1. **المبالغ المستلمة** (Receipts) — model `ProjectCostReceipt`, prefix `REC-YYYY-XXXX`. Debit + credit accounts (both manual, filtered by account_type + bank_type + currency = cost currency). Cascade: super → project → cost → currency auto-filled.
2. **صرف مبلغ المشروع** (Disbursement) — model `ProjectCostBudgetsPayment`, slug `project-cost-budgets-disbursements`, prefix `BUD-YYYY-XXXX`. Saves to `project_cost_budgets` (`transaction_id` NOT NULL). 4 accounts (source credit + admin debit + transfer debit + destination debit); 3 inputs: admin %, transfer %, fx_rate; manual "احسب" button. Source/admin/transfer = cost currency; destination = disbursement currency. 4 lines tagged by notes-constants `LINE_SOURCE` / `LINE_ADMIN` / `LINE_TRANSFER` / `LINE_DESTINATION`.
3. **صرف مبالغ التنفيذ** (Execution payments) — model `ExecutionPayment` (a 2nd resource over the same `ProjectCostBudgetsPayment`/payments model), slug `execution-payments`, prefix `PAY-YYYY-XXXX`. Draws from disbursed budget; debit = beneficiary (manual), credit = destination account (auto from budget). Warns if over remaining. Beneficiary line tagged `LINE_BENEFICIARY`.
4. **مصروفات عامة** (General expenses) — model `GeneralExpense`, slug `general-expenses`, prefix `GEN-YYYY-XXXX`. No project/percentages; 2 accounts same currency.
5. **التحويلات العامة** (General exchanges) — model `GeneralExchange`, slug `general-exchanges`, prefix `EXT-YYYY-XXXX`. Like disbursement (4 accounts + 3 percentages + احسب) but with no project. 4 lines via notes-tags.

### Bank types system
`bank_types` table (بنك فلسطين / محافظ / كاش) + `bank_type_id` on `accounts`. Cascade in account selection: account_type → bank_type → currency (auto display) → account (filtered by all three). Resource lives under الإعدادات as "أنواع البنوك".

### Data flow (money lifecycle)
```
Project created (with cost lines, each having amount+currency)
        ↓
Receipt (REC) — donor money received against a project cost
        ↓
Disbursement / Budget (BUD) — money allocated, deductions applied
   (admin% + transfer%), converted via fx_rate → final_amount
        ↓
Execution payment (PAY) — money paid to beneficiaries from a budget
```
Each step writes a balanced transaction with ≥2 lines; account balances move accordingly.

---

## 3. KEY DECISIONS & THE REASONING

### Performance rules (applied to every feature) — [DECISION]
- Eager loading via `getEloquentQuery()` + `->with([...])` — no N+1.
- Large selects: `searchable() + preload(false) + optionsLimit(50)`; small lookups: `preload(true)`.
- Cascading selects use `pluck('name','id')`, fetching only needed columns.
- **No `deferLoading()`** (it slowed navigation).
- Indexes via `constrained()` (auto-creates single-column FK index — no duplicate `->index()`).

### Display conventions (every list/view page) — [DECISION]
- **Currency column: ALWAYS visible** (never toggleable) — currency is essential accounting info.
- Other detailed/secondary columns: `->toggleable(isToggledHiddenByDefault: true)` (hidden by default, user can reveal).
- Default sort: `->defaultSort('id', 'desc')` (newest first).

### App-wide soft delete — [DECISION]
SoftDeletes added everywhere (incl. User, Setting, ExchangeRateHistory, TransactionLine). **ALL** ForceDelete/Restore actions removed from 49 files. Kept `DeleteAction` + `TrashedFilter` (audit-viewable, **NO restore by design**). The 5 financial resources: `forceDelete()` → `delete()` for record/transaction/lines, but **KEEP balance reversal**. **Exception:** edit-time line REPLACEMENT uses `forceDelete()` (to avoid trash clutter). On delete, attachments keep the physical file but soft-delete the record. Balances stay safe because `current_balance` is stored, not re-summed. All queries exclude soft-deleted rows.

### Currency denormalization (3 batches) — [DECISION]
**Problem:** Currency was only authoritative in `transaction_lines.currency_id`; every list/report had to walk `transaction.lines` and match by notes-tag — slow and unclear.

**Solution:** Store currency (and computed amounts) directly on the financial tables.

- **Batch 1 (structure + backfill):** Added `currency_id` to `project_cost_receipts`, `project_cost_budgets_payments`, `general_expenses`. Added `source_currency_id` + `disbursement_currency_id` + `amount_after_deductions` to `project_cost_budgets`. **RENAMED `amount_after_percentages` → `final_amount`** (the old name was misleading — it actually stored the post-fx final amount). Backfilled ALL old rows (incl. trashed) from the transaction lines. Result: 0 NULL currencies.
- **Batch 2 (write):** Create/Edit now write the new columns (8 files). Verified 16/16.
- **Batch 3 (read):** List/View/Report now read from the new columns (faster, no line-walks).

**End state:** `project_cost_budgets` now structurally mirrors `general_exchanges` — both carry `original_amount`, `amount_after_deductions`, `final_amount`, `source_currency_id`, `disbursement_currency_id`.

**Why the rename was safe:** only a 4-row table at the time; the misleading name (`amount_after_percentages`) was paid down as "clarity debt" to match `general_exchanges`.

### Two-grain report structure — [DECISION, critical]
A latent bug existed: in the old report, `total_net` (الصافي) was in the **source/cost currency (before fx)** while `total_executed` (المنفّذ) was in the **destination/execution currency (after fx)**. Subtracting them when fx ≠ 1 subtracted two different currencies — wrong.

**Fix — the two-grain rule (MUST be preserved everywhere):**
- **Cost-side figures** (التكلفة، المقبوض، الفائض/العجز): grouped by **COST currency**.
- **Execution-side figures** (المرصود، المنفّذ، نسبة الإنجاز): grouped by **DISBURSEMENT currency**.
- **Currencies are NEVER mixed across grains.** Empty currencies render as a dash (—).

When asked how to structure rows, the chosen approach was **"Split into two grains"** — keep one cost-side table grouped by cost currency, and a separate execution-side table grouped by disbursement currency. (Rejected: adding disbursement-currency to the row key (caused double-counting), and keeping one row with a tag (still ambiguous with multiple currencies).)

### General report architecture: snapshots, not live — [DECISION]
**Goal:** main report must load < 500ms even with **thousands of projects**.

**Rejected:** live SQL calculation on every page request (fine for tiny data, but won't scale; the owner explicitly prioritized speed at thousands of projects).

**Chosen:** precomputed **snapshot tables**. Heavy calc runs in a refresh service/command; the Filament page reads directly from the snapshot. A normalized `currency_totals` child table makes currency filtering and summary cards fast (chosen from the start, NOT left optional).

### Refresh strategy: smart + instant — [DECISION]
The owner wanted updates to feel **instant** but not force waiting. Decision **"د" (all methods together)**:
- **Observers** set a **dirty flag** (`is_dirty`) on data change → enables cheap incremental refresh.
- A manual **"تحديث التقرير"** button (uses `--force`, full recalc).
- An **hourly scheduled** refresh as a safety net.

The **dirty flag** = "this project's data changed; its snapshot is stale," so the refresh recomputes only changed projects instead of all of them.

*Note on current state:* the manual button currently uses `--force` (recalcs all active projects every click). The observers/incremental (`is_dirty`) path exists in schema but was left dormant — to be wired when a cron incremental refresh is added.

### Percentage rounding: TRUNCATE, not round — [DECISION]
Percentages are **truncated** (floor-based: `floor($value * 100) / 100`), **NOT** rounded. Example: 224.995 → **224.99** (not 225.00). This is a confirmed system-wide convention. (Initially round-half-up was used, then explicitly switched to truncation at the owner's request.)

### Active projects = non-deleted only — [DECISION]
The general report shows **all non-soft-deleted projects**, regardless of status. Status (مكتمل / غير مكتمل) is shown as a badge. Soft-deleted projects are EXCLUDED and actively REMOVED from snapshots (orphan cleanup).

### donor_name source — [DECISION]
Use `Partner.name` via the `donor` relation (NOT the free-text `donor_project_name`).

### Metric changes (latest work) — [DECISION]
- **"المتبقي للاستلام" → "الفائض/العجز":** formula flipped from `planned − received` to **`received − planned`**. Surplus (received > planned) = positive (green); deficit = negative (red); zero = neutral. Stays on cost-side grain. (Reason: the old formula showed a surplus as a confusing negative.)
- **Execution percentages consolidated to ONE:** deleted "نسبة التنفيذ من التكلفة" (execution ÷ planned); kept/renamed "نسبة التنفيذ من الصرف" → **"نسبة الإنجاز المالي"** = `execution_paid ÷ final_amount`. On execution-side grain. Div-by-zero (final = 0/null) → dash. Truncated. (Reason: execution comes out of disbursement, not directly from planned cost; one clear metric is enough.)
  - *Earlier* the decision had been to show BOTH percentages; this was later reversed to a single "نسبة الإنجاز المالي."
- **Removed "المؤشر المالي" (financial_indicator) entirely** (قيد التنفيذ / مكتمل مالياً / etc.) — not used.
- **Removed "السلامة المالية" (financial_safety_indicator) entirely** (خطر مالي / يحتاج مراجعة / ملاحظات / سليم) — including its column/badge/filter. Kept the **risk filter** ("مشاريع فيها مخاطر فقط") and the **criticals-first sort** (so `has_critical_alerts` stays). Dropped `has_notes` (no reader).
- **Alerts reduced from ~22 rules to EXACTLY 2** (see below).

### Alerts reduced to 2 rules — [DECISION]
Originally ~22–26 alert rules were designed/built. The owner chose to keep only two critical rules:
1. **"عجز في المبلغ المستلم"** — fires when الفائض/العجز (`received − planned`) is **negative** for a currency. Severity: critical.
2. **"مبلغ التنفيذ أكبر من مبلغ الصرف"** — fires when `execution_paid > final_amount` for a currency. Severity: critical.

All other rules deleted. (Advisor flagged that deleting accounting-integrity alerts like "قيد محاسبي غير متوازن" loses oversight, and recommended keeping ~3 or tiering them; owner chose 2.)

---

## 4. CONVENTIONS & RULES

### Always do
- **Currency column always visible** on every list/view page.
- **Default sort `id desc`** (newest first) everywhere.
- **Eager-load** all relationship columns (no N+1).
- **Exclude soft-deleted** rows at every query level.
- **Truncate** percentages (`floor($v*100)/100`), never round.
- Wrap every financial write in `DB::transaction()`; keep `SUM(debit_base)=SUM(credit_base)`.
- Update account balances via increment/decrement; **reverse** on edit/delete.
- Transaction numbers: `MAX+1` including trashed, `lockForUpdate`, 4-digit padded.
- Commit to GitHub before each significant change (clean restore point).
- In Claude Code prompts: instruct **"STOP and ASK, don't guess"**; ask for an **impact report FIRST** before editing on risky changes.
- After Codex does work, **audit it** (Codex tends to miss CSV export wiring, leave refresh buttons as no-ops, and skip orphan cleanup).

### Never do
- Never use `deferLoading()`.
- Never use `config:cache` / `route:cache` in dev.
- Never use Filament v5 `->rtl()` / `->locale()` (don't exist).
- Never make the currency column toggleable.
- Never mix currencies across the two grains.
- Never keep ForceDelete/Restore actions (soft-delete is audit-only, no restore by design).
- Never re-sum account balances (they're stored).

### Naming conventions
- Project code: `PREFIX_YYYYMMDD_001` (e.g. `FOOD_20260601_004`).
- Transaction number prefixes: `REC-`, `BUD-`, `PAY-`, `GEN-`, `EXT-` + `YYYY-XXXX`.
- Transaction line role tags (in `notes`): `LINE_SOURCE`, `LINE_ADMIN`, `LINE_TRANSFER`, `LINE_DESTINATION`, `LINE_BENEFICIARY`. These stay the authoritative tags that edit flows and reports match on — untouched by the 2026-07-14 `line_role` feature.
- Structured line roles (in `transaction_lines.line_role`, via `App\Enums\TransactionLineRole`, since 2026-07-14): `funding_source`/`receipt_destination` (receipt), `source`/`administrative_deduction`/`transfer_fee`/`destination` (disbursement + general exchange; `source` also = expense credit line), `beneficiary`/`execution_source` (execution payment), `expense` (expense debit line), `opening_balance_target`/`opening_balance_counterpart` (opening balance). Assigned explicitly at line creation by the 6 flows — never inferred from account/side/order/description/notes; each has an Arabic UI label via `arabicLabel()`.
- System-generated descriptions: `transactions.description` = `دائن: {credits} | مدين: {debits} | ملخص العملية: {summary}.` (`TransactionDescriptionBuilder`); `transaction_lines.description` = `{مدين|دائن}: حساب {name} ({line currency code}) — {posted amount} | الغرض: {purpose}.` (`TransactionLineDescriptionBuilder`). Both use the line's own `currency_id` (never the account's), English digits, 2 decimals, thousands separators, exactly one final period; shared formatting lives in the `FormatsTransactionText` trait. Zero-amount deduction lines (0% admin/transfer) keep `description = NULL` by design.
- **Permanent display terminology (since 2026-07-15, use everywhere — Filament pages, reports, exports, docs):** `transactions.description` → **وصف العملية المالية**; `transaction_lines.description` → **وصف سطر القيد**; `transaction_lines.line_role` (resolved via `TransactionLineRole::labelFor()`) → **دور سطر القيد**. Never use ambiguous alternatives (`الوصف`, `وصف القيد`, `دور السطر`, `وصف المعاملة`). NULL/unclassified values render as **"—"**.
- Historical backfill: `php artisan transactions:backfill-descriptions --dry-run|--apply` (idempotent; `--transaction-id=`/`--chunk=200` optional) classifies a historical transaction only via a direct `transaction_id` FK on its flow's authoritative domain record, or `transactions_types.name = 'قيد افتتاحي'` for opening balance; `notes` `LINE_*` tags are used only to map lines to roles once the flow is already known. Reuses the live builders so backfilled text is identical to a fresh create/edit. Safe to re-run after future data imports.
- Class/method names in English; all visible UI labels in Arabic.
- Toggleable hidden columns: `->toggleable(isToggledHiddenByDefault: true)`.

### Code locations
- Report models: `app/Models/Reports/`.
- Report service: `app/Services/Reports/ProjectsGeneralFinancialReportService.php`.
- Alerts generator: `app/Services/Reports/ProjectsFinancialAlertsGenerator.php`.
- Refresh command: `app/Console/Commands/RefreshProjectsFinancialReport.php`.
- General report page: `app/Filament/Pages/ProjectsGeneralFinancialPage.php`.
- Details page: `app/Filament/Pages/ProjectFinancialDetailsPage.php`.
- Details blade: `resources/views/filament/pages/project-financial-details-page.blade.php`.

---

## 5. WHAT'S DONE

### Core system (completed)
- All ~24 tables with SoftDeletes; double-entry accounting model.
- All 5 financial pages (CRUD + view + list + attachments): Receipts, Disbursement, Execution payments, General expenses, General exchanges.
- Bank types system + cascade in account selection.
- App-wide soft-delete conversion (49 files; no restore by design).
- OPcache performance fix; perf + display conventions applied.
- Automatic Arabic descriptions (2026-07-14): `transactions.description` (all 6 flows, `TransactionDescriptionBuilder`) + per-line `transaction_lines.description` and structured `line_role` (`TransactionLineDescriptionBuilder`, `TransactionLineRole` enum). Both raw audit resources (`admin/transactions`, `admin/transaction-lines`) hardened to strictly read-only (index/view only, `can*` → false) — the 6 flows are the only write paths.
- Historical backfill + report display (2026-07-15): `php artisan transactions:backfill-descriptions` backfilled all deterministically-classifiable historical transactions/lines (idempotent, re-runnable). The 3 approved fields now display in the detailed movement sections of Comprehensive Financial Transactions, Account Statement, Project Financial Details, and Donor Financial Report (screen + every existing Excel/Word export) — additive only, no calculation/total/balance changed. Trial Balance and all summary cards untouched.

### Currency denormalization (completed, 3 batches)
- `currency_id` added to receipts, payments, general_expenses.
- `source_currency_id` + `disbursement_currency_id` + `amount_after_deductions` added to `project_cost_budgets`.
- `amount_after_percentages` renamed to `final_amount`.
- All rows backfilled (incl. trashed); 0 NULL currencies.
- Create/Edit write the columns; List/View/Report read from them.
- `project_cost_budgets` now mirrors `general_exchanges` structurally.

### Per-page column work (completed)
- Disbursement, execution, expenses, exchanges, receipts list pages all got detailed toggleable columns + the always-visible currency column(s) + `defaultSort('id','desc')`.
- Execution payments: 3 currency columns — عملة مبلغ التنفيذ **always visible**; عملة تكلفة المشروع + عملة المبلغ المرصود toggleable/hidden.
- Receipt form: debit & credit accounts filtered to the **project cost currency** (can't pick a mismatched-currency account); disabled currency display field added.
- Project cost repeater + RelationManager: removed نوع الحساب (account_type); show amount + currency + notes only.

### Old "تقارير المشاريع" report (completed, superseded)
- Live two-grain report (cost table grouped by cost currency + execution table grouped by disbursement currency), per-currency totals, 4 independent date-range filters (approval/implementation/start/end), plus super/status/fiscal-year/currency filters. Exactly 2 SQL queries. Fixed the `only_full_group_by` ORDER BY error (removed `projects_costs.id` secondary sort). **This is a separate, older live report that does NOT use the snapshot tables — it is out of scope for snapshot changes and was deliberately left untouched.**

### General Financial Report (snapshot-based) — completed batches
- **Batch 1 (structure):** 3 report tables — `project_financial_snapshots` (descriptive fields + 11 per-currency JSON maps + indicator/count/flag fields + `is_dirty` + `calculated_at` + `data_hash`), `project_financial_snapshot_currency_totals` (normalized per-currency rows, unique `(project_id, currency_id)` as `pfsct_project_currency_unique`), `project_financial_alerts`. 3 models under `app/Models/Reports/`. 8 new supporting indexes added (8 skipped as already existing). Idempotent index migration.
- **Batch 2 (calc engine):** `ProjectsGeneralFinancialReportService` (`calculateForProject`, `calculateAndStore`) + `reports:refresh-projects-financial` command (`--project_id`, `--force`, `--chunk=100`; default refreshes missing/dirty). All aggregate GROUP-BY-currency queries; no N+1; soft-delete excluded; percentages truncated. Verified all metrics exact against project 16. Refresh ran in ~0.27s.
- **Batch 3 (alerts):** alert generation (originally ~22 rules) + safety indicator + counts. (Later reduced — see below.)
- **Codex advanced + audit:** Codex built page/details/export; Claude Code audited and **fixed**: built the missing **CSV export** (UTF-8 BOM, Arabic headers for all columns, `->chunk(200)` streaming, currencies kept separate, reads from snapshot), fixed the **refresh button no-op** (now passes `--force`), and added **orphan snapshot cleanup** (`removeOrphanSnapshots()` deletes snapshot/totals/alerts for projects no longer active). Re-verified project 16 (all values exact).
- **Details page:** full sections — project data, financial summary by currency, completion %, alerts/risks table, cost lines, receipts, budgets/disbursements, execution payments, deductions.
- **UI redesign of details page:** full-width (removed the 1500px cap), light + dark mode via CSS-variable token set (`.project-report-page` light defaults, `.dark .project-report-page` overrides), section accent bars, signed coloring for الفائض/العجز (green surplus / red deficit / neutral zero).

### Metric/alert refactor (completed)
- Renamed/flipped **المتبقي للاستلام → الفائض/العجز** (`received − planned`, colored).
- Deleted **نسبة التنفيذ من التكلفة**; kept/renamed **نسبة التنفيذ من الصرف → نسبة الإنجاز المالي** (`execution_paid ÷ final_amount`, div-by-zero → dash, truncated). Dropped the stored `execution_pct_of_planned` columns via migration.
- Deleted the orphaned `execution_pct_of_planned_over_100` alert (count 22 → 21); negated the two `remaining_to_receive` alert comparisons so they stayed byte-identical after the sign flip.
- Removed **المؤشر المالي** and **السلامة المالية** entirely (latest task; kept the risk-only filter + criticals-first sort, dropped `has_notes`).
- Alerts reduced to the **2 critical rules** (عجز في المبلغ المستلم؛ مبلغ التنفيذ أكبر من مبلغ الصرف).

### Operational data cleanup tool — implemented, dry-run only (2026-07-15)
- New `php artisan oms:clean-operational-data {--dry-run|--apply --confirmation=DELETE-OMS-OPERATIONAL-DATA --backup-file=... [--skip-files]}` + `App\Services\Maintenance\OperationalDataCleanupService` (+ `OperationalCleanupReport` DTO). Purpose: permanently wipe operational/transactional/project data from the **development** database so it can start clean, while preserving system configuration, donors, accounts (balances reset to 0, definitions untouched), currencies, full exchange-rate history, fiscal years, and users/roles/permissions.
- Environment guard: refuses outside `local`/`development`/`testing`. Apply additionally refuses without the exact confirmation token and a verified non-empty `--backup-file`. No production-force bypass exists.
- Deletes, active + soft-deleted (force), FK-safe child-before-parent, in one `DB::transaction()`: `project_financial_alerts` → `project_financial_snapshot_currency_totals` → `project_financial_snapshots` → `transaction_lines` → `project_cost_budgets_payments` → `project_cost_receipts` → `project_cost_budgets` → `general_expenses` → `general_exchanges` → `transactions` → `projects_costs` → `projects` → linked attachments → resets every `accounts.current_balance` to 0.
- Uses `DB::table()->delete()` (not Eloquent) for the bulk deletes — covers active+trashed in one statement and avoids firing the `Project`/`ProjectCost`/`ProjectCostBudget`/`ProjectCostBudgetsPayment`/`ProjectCostReceipt` observers unnecessarily (they only flip `project_financial_snapshots.is_dirty`, moot since those rows are deleted in the same operation).
- Donor classification is strictly `partners.is_donor` — no other field distinguishes association/beneficiary/vendor entities in this schema, so every non-donor partner is preserved and reported as unclassified (see DECISIONS_LOG.md 2026-07-15).
- Attachment files: DB rows deleted in-transaction; physical files (on the `public` disk) deleted only after commit, matched by exact `file_path`. Files with no matching row are reported as orphans, never deleted.
- Numbering (`transaction_number`, project codes) is derived from `MAX()`/`count()` over existing rows, not AUTO_INCREMENT or a sequence table — naturally restarts at 001 per prefix once rows are gone; no explicit reset implemented.
- **Status: EXECUTED 2026-07-15.** `--apply` ran successfully (backup: `storage/app/backups/oms_before_operational_cleanup.sql`), re-run a second time to confirm idempotence (zero additional changes). All 12 operational tables now empty (active+trashed); all 15 accounts reset to a 0.00 balance with definitions unchanged; 2 donors, users/roles/permissions, currencies, exchange-rate history, all reference tables, `bank_accounts`, and `migrations` all confirmed unchanged. Exactly 6 attachment files were deleted; the 6 pre-existing orphan files (706,133 bytes) remain untouched, as does the `public/storage` symlink. Full detail in TASKS_LOG.md / AI_PROJECT_MEMORY.md (2026-07-15 execution entries).

### Daily Arabic reports produced (for the manager)
- `/mnt/user-data/outputs/report_wed_thu_20260610_11.txt`
- `/mnt/user-data/outputs/report_sat_sun_mon_20260614_16.txt`
- `/mnt/user-data/outputs/report_tue_wed_20260616_17.txt`
- Clone/setup guide: `/mnt/user-data/outputs/OMS_Project_Summary.md`

---

## 6. WHAT'S PENDING / NEXT

- **Excel + PDF (Arabic) export** for the general report — both **general export and per-project export**. mPDF approved for Arabic PDF. (Only CSV is built so far.)
- **Observers / instant dirty-flag refresh** — schema (`is_dirty`) exists but observers are not wired; the manual button currently uses `--force` (full recalc). To make the "instant + smart" refresh real, add observers on Project/Cost/Receipt/Budget/Payment to set `is_dirty=true` on save/delete, and an hourly incremental schedule.
- **Verify the latest cleanup end-to-end** (removal of financial_indicator + financial_safety_indicator + reduction to 2 alerts) renders cleanly on both the general page and details page with no empty gaps, and that project 16 produces exactly the expected alerts.
- **Double-entry bookkeeping issue noted earlier:** a receipt was generating only one transaction line (debit only) instead of two (debit + credit). Possible resolution: add a revenue/credit account field to the form, or configure a default credit account in system settings. (Open.)
- **Decide whether to physically rename the `remaining_to_receive` DB column** (it now holds الفائض/العجز values). Currently only the display labels were renamed; the physical column kept its name to avoid a wide ripple through the JSON map, currency-totals table, and code references. Functional-only — rename optional.
- **Possibly retire the old live "تقارير المشاريع" report** if the new general report fully supersedes it (left in place for now).
- **Operational data cleanup — DONE (2026-07-15).** `oms:clean-operational-data --apply` executed successfully against the real dev DB with full 21-point verification passing (see WHAT'S DONE above). The database is now empty of operational data and ready for real data entry. Next: visually verify empty-state pages and fresh-record creation in the browser (see NEXT_STEPS.md), and decide the fate of the 6 orphan attachment files and the `bank_accounts` legacy table (both report-only, untouched).

---

## 7. GOTCHAS & LESSONS LEARNED

### Environment / framework gotchas
- **OPcache is mandatory** for acceptable speed in Laragon — the bottleneck was PHP recompiling vendor files, NOT the DB.
- **Filament v5 has no `->rtl()` / `->locale()`** — use `app.locale='ar'` + `dir="rtl"`.
- **`Get`/`Set` import path** in v5 is `Filament\Schemas\Components\Utilities\Get`, not `Filament\Forms\Get`.
- **Don't use `deferLoading()`** — it hurt navigation speed.
- **Don't `config:cache`/`route:cache` in dev** — stale 500s.

### Bugs hit and fixed
- **`only_full_group_by` error:** ORDER BY referenced `projects_costs.id` not in GROUP BY. Fix: order only by grouped/aggregated columns (e.g. `project_code`, `currency_id`); use `MAX(...)` if a tiebreaker is truly needed.
- **Duplicate `transaction_number`:** solved with `MAX+1` including trashed rows + `lockForUpdate` + zero-padding.
- **`project_cost_budget_id` NOT NULL / MySQL not running / `Transaction::transactionLines` undefined:** all encountered and resolved during the build.
- **Report currency-mismatch bug:** subtracting cost-currency net from execution-currency executed when fx ≠ 1. Fixed by the two-grain structure + denormalized currencies.
- **MySQL 64-char identifier limit:** forced short custom index names (`pfsct_project_currency_unique`, `pfsct_currency_id_fk`, `pcbp_budget_currency_index`). Functionally identical.
- **Misleading column name:** `amount_after_percentages` actually stored the post-fx final amount → renamed to `final_amount`.

### Working-with-AI lessons
- **Codex misses things:** it tends to leave CSV export unwired, refresh buttons as no-ops, and skip orphan cleanup. **Always audit Codex output** with Claude Code (verify numbers against known-correct values, check soft-delete exclusion, check conventions).
- **Always pre-audit risky changes:** before sign flips / deletions, get a written **impact report** (which alert rules, stored columns, observers, exports are affected). A sign flip silently inverts any alert condition that reads the value — those comparisons must be negated to keep behavior identical.
- **Stored vs on-the-fly matters:** if a metric is stored as a snapshot column, changing its formula requires a **backfill/migration**, or old rows keep stale values.
- **Don't trust stated counts:** the prompt said "26 alert rules" but the real count was 22 — Claude Code should find the real rules in code, not rely on the number.

### Known-correct verification data (PROJECT 16 — the single active project)
The DB dump (`oms__3_.sql`) has **6 of 7 projects soft-deleted**; only **project 16** is active: "مشروع تجريب التقارير", code `FOOD_20260601_004`, super = الطعام, status = غير مكتمل, donor = جمعية كاف. Its figures (use these to verify any future report change):

| Metric | USD | ILS |
|---|---|---|
| planned (التكلفة المخططة) | 85,000 | 20,000 |
| received (المقبوض) | 35,000 | — |
| الفائض/العجز (received − planned) | −50,000 (عجز) | −20,000 (عجز) |
| budget_original (الصرف الأصلي) | 45,000 | 6,000 |
| budget_after_deductions (بعد الخصومات) | 36,000 | 6,000 |
| budget_final (الصرف النهائي) | 22,000 | 48,000 |
| deductions (الخصومات) | 9,000 | 0 |
| execution_paid (المدفوع تنفيذياً) | 60,000 | 44,999 |
| remaining_execution (رصيد التنفيذ) | −38,000 | 3,001 |
| نسبة الإنجاز المالي (exec ÷ final, truncated) | 272.72% | 93.74% |

> Note: under the **old** "remaining_to_receive = planned − received" the USD value was +50,000; after the **sign flip** to "received − planned" it is **−50,000** (a deficit, shown red). `رصيد التنفيذ` was deliberately left unflipped at −38,000.

**Expected alerts for project 16 under the final 2-rule set:**
- عجز في المبلغ المستلم — USD (received 35,000 < planned 85,000)
- عجز في المبلغ المستلم — ILS (received 0 < planned 20,000)
- مبلغ التنفيذ أكبر من مبلغ الصرف — USD (execution 60,000 > final 22,000)

The data intentionally contains real problems (negative execution balance, execution > final, deficits) so alerts can be validated.

---

## APPENDIX — Snapshot table column reference

### `project_financial_snapshots`
Descriptive: `project_id` (unique), `project_code`, `project_name`, `project_super_id`, `project_super_name`, `donor_id`, `donor_name`, `project_status_id`, `project_status_name`, `approval_date`, `implementation_date`, `start_date`, `end_date`.

Per-currency JSON maps (e.g. `{"USD":85000,"ILS":20000}`): `planned_by_currency`, `received_by_currency`, `remaining_to_receive_by_currency` (now holds الفائض/العجز), `budget_original_by_currency`, `budget_after_deductions_by_currency`, `budget_final_by_currency`, `execution_paid_by_currency`, `remaining_execution_by_currency`, `deductions_by_currency`, `execution_pct_of_final_by_currency` (نسبة الإنجاز المالي). *(`execution_pct_of_planned_by_currency` was dropped.)*

Counts/flags: `alerts_count`, `critical_alerts_count`, `warning_alerts_count`, `notes_count`, `most_severe_alert_title`, `has_critical_alerts`, `has_warning_alerts` (kept for risk filter/sort), *(`has_notes` dropped; `financial_indicator` and `financial_safety_indicator` removed)*.

Refresh/perf: `is_dirty`, `calculated_at`, `data_hash`, timestamps.

### `project_financial_snapshot_currency_totals`
`project_id`, `currency_id`, `currency_code`, `planned`, `received`, `remaining_to_receive` (الفائض/العجز), `budget_original`, `budget_after_deductions`, `budget_final`, `execution_paid`, `remaining_execution`, `deductions_total`, `execution_pct_of_final`. Unique `(project_id, currency_id)`.

### `project_financial_alerts`
`project_id`, `severity` (critical|warning|note — now only critical used), `title`, `message`, `currency_id`, `currency_code`, `amount`, `reference_type`, `reference_id`, `meta` (json), `calculated_at`, timestamps.

---

*End of master reference. Keep this updated as the single source of truth.*
