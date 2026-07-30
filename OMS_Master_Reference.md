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
| Report exports | Excel (`xlsx`) and Word (`docx`) where implemented — **no PDF export exists and none is planned** |

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
- `accounts_type` has no `nature` (asset/liability/equity) concept — the system has none, every account is uniformly debit-normal (see "Important Notes" in `docs/AI_PROJECT_MEMORY.md`). A live-only `nature` enum + `created_by`/`updated_by` audit columns (no migration file) were reconciled in OMS Task 8.2, 2026-07-28: `nature` dropped (dead), `created_by`/`updated_by` kept and reproduced (matches the `HasUserTracking` audit-column convention used by 20+ other models). **`AccountType` was wired to `HasUserTracking` in OMS Task 9B.3, 2026-07-29** — forward-only, no backfill.
- `accounts.created_by`/`updated_by` (nullable FK→`users`, `ON DELETE SET NULL`). A live-only drift (no migration file) was reconciled in OMS Task 8.3, 2026-07-28 (schema only; behavior deferred to the Audit Log task). **`Account` was wired to `HasUserTracking` in OMS Task 9B.3, 2026-07-29**, so both columns are populated on every save from that point forward; the historically NULL rows are deliberately **not** backfilled — an invented actor would be a fabricated audit trail. A balance `increment()`/`decrement()` updates only `current_balance` in SQL and therefore never rewrites `updated_by`.

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

> **Resource/model naming warning (verified 2026-07-29, OMS Task 9B.3).** Two of these five workflows are named the *inverse* of the model class they actually write, and earlier revisions of this document stated the mapping wrongly. The authoritative mapping, read off the resource classes themselves, is:
>
> | Workflow | Resource class | Slug | **Model actually written** |
> |---|---|---|---|
> | المبالغ المستلمة | `ProjectCostReceiptResource` | `project-cost-receipts` | `ProjectCostReceipt` |
> | صرف مبلغ المشروع | `ProjectCostBudgetsPaymentResource` | `project-cost-budgets-disbursements` | **`ProjectCostBudget`** |
> | صرف مبالغ التنفيذ | `ExecutionPaymentResource` | `execution-payments` | **`ProjectCostBudgetsPayment`** |
> | المصروفات العامة | `GeneralExpenseResource` | `general-expenses` | `GeneralExpense` |
> | التحويلات العامة | `GeneralExchangeResource` | `general-exchanges` | `GeneralExchange` |
>
> There is **no `ExecutionPayment` model class**. Never derive one of these workflows from a class name — always confirm against `protected static ?string $model` on the resource.

1. **المبالغ المستلمة** (Receipts) — model `ProjectCostReceipt`, prefix `REC-YYYY-XXXX`. Debit + credit accounts (both manual, filtered by account_type + bank_type + currency = cost currency). Cascade: super → project → cost → currency auto-filled.
2. **صرف مبلغ المشروع** (Disbursement) — resource `ProjectCostBudgetsPaymentResource`, **model `ProjectCostBudget`**, slug `project-cost-budgets-disbursements`, prefix `BUD-YYYY-XXXX`. Saves to `project_cost_budgets` (`transaction_id` NOT NULL). 4 accounts (source credit + admin debit + transfer debit + destination debit); 3 inputs: admin %, transfer %, fx_rate; manual "احسب" button. Source/admin/transfer = cost currency; destination = disbursement currency. 4 lines tagged by notes-constants `LINE_SOURCE` / `LINE_ADMIN` / `LINE_TRANSFER` / `LINE_DESTINATION`.
3. **صرف مبالغ التنفيذ** (Execution payments) — resource `ExecutionPaymentResource`, **model `ProjectCostBudgetsPayment`**, slug `execution-payments`, prefix `PAY-YYYY-XXXX`. Draws from a disbursed budget; debit = beneficiary (manual), credit = destination account (auto from budget). Warns if over remaining. Lines tagged `LINE_BENEFICIARY` / `LINE_CREDIT`.
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

### Audit Log foundation (OMS Task 9B.1 — schema/service layer; wired to general CRUD only as of 9B.2, see below)
- New table `audit_events` (additive migration `2026_07_28_130000_create_audit_events_table.php`): `uuid` (unique), `event_category`/`event_action` (lowercase snake_case, validated), `subject_type` (a short **stable alias**, e.g. `account`/`general_exchange` — never a raw PHP class name) + `subject_key` + `subject_label`, `actor_user_id` (FK→`users`, `ON DELETE SET NULL`) + `actor_name`/`actor_email` snapshots + `actor_roles` (bounded JSON role-name array, never a permission dump) + `actor_type` (`user`/`system`/`scheduler`/`queue`/`command`), `old_values`/`new_values`/`changed_fields` (JSON, redacted + bounded), `reason`, `correlation_id`, `ip_address`/`user_agent`/`route_name`/`http_method` (interactive events only, never fabricated), `status` (`success`/`failure`), `created_at` only — **no `updated_at`/`deleted_at`/SoftDeletes**.
- `App\Models\AuditEvent` is application-level immutable: `update()`/`delete()`/`forceDelete()`/`replicate()` all throw `AuditImmutableRecordException`. There is still **no Filament UI and no audit permission** — see `docs/NEXT_STEPS.md` for the remaining phased rollout (9B.3+).
- `App\Services\Audit\AuditLogger::record()` is the intended single write gateway, with two explicit failure modes (`App\Enums\AuditFailureMode`): `Required` (persistence failure throws `AuditPersistenceException`, propagating into whatever `DB::transaction()` the caller already has open — no independent transaction is ever opened by the logger itself) and `BestEffort` (failure is caught, logged sanitized via `Log::error()`, returns `null`).
- `App\Services\Audit\AuditRedactor` centrally strips secrets (password/token/secret/key-material fields, recursively, whole-underscore-segment matched — never a bare substring match) before anything is bounded or persisted; `App\Services\Audit\AuditPayloadBounder` then caps every string to 1000 Unicode characters and each of `old_values`/`new_values` independently to 8192 encoded JSON bytes (dropping whole keys, never `substr()`-ing the encoded JSON, marking `_truncated: true` when it does).

### Audit Log — general CRUD integration (OMS Task 9B.2 — master-data models only)
- **Audited subjects introduced in this phase (11):** `project`, `project_cost`, `partner`, `partner_type`, `project_super`, `project_status`, `bank_type`, `fiscal_year`, `transaction_type`, `transaction_super_type`, `setting`. Every event uses `event_category = crud` and one of `created`/`updated`/`deleted`/`restored`. *(Task 9B.3 later added four financial master-data subjects to this same architecture — `account`, `account_type`, `currency`, `exchange_rate_history` — see the next section. `Transaction`/`TransactionLine` remain permanently unregistered.)*
- **Atomicity is owned by a service, not by observers.** No Filament write path in this application runs inside a database transaction (the panel never calls `->databaseTransactions()`; `Filament\Actions\Concerns\CanUseDatabaseTransactions` defaults to false), so an after-save observer would fire with the row already committed. `App\Services\Audit\Crud\AuditedCrudService` therefore wraps the business mutation **and** its `AuditLogger::record()` call — always `AuditFailureMode::Required` — in one `DB::transaction()`. They commit or roll back together, and nesting inside a caller's transaction is savepoint-safe.
- **Shared component:** `AuditSubjectRegistry` (closed model→alias allowlist; an unregistered class throws `AuditSubjectNotRegisteredException` rather than deriving an alias, which is what structurally guarantees no FQCN reaches `subject_type`), `AuditSubjectDefinition`, `AuditModelSnapshotter` + `AuditFieldDiff`, `SettingValuePolicy`, and `App\Services\Audit\AuditActorResolver` (the one place `auth()`/`request()` become an `AuditActorContext`; request metadata is attached only when a real routed HTTP request exists).
- **Filament seam:** `App\Filament\Concerns\AuditsRecordCreation`/`AuditsRecordUpdate` replace `handleRecordCreation()`/`handleRecordUpdate()` on the 11 Create/Edit pages; `App\Filament\Concerns\AuditedActions` returns stock `DeleteAction`/`DeleteBulkAction`/`CreateAction`/`EditAction` with only the process closure swapped via `->using()`, so notifications, confirmation modals, authorization and the create → View / edit → View / delete → List standard are untouched. `deleteBulk()` pins `fetchSelectedRecords()` so Filament's mass query-level delete can never bypass the models. A resource table's Create/Edit/View actions are plain page links and write nothing, so they are deliberately not wrapped; `CostsRelationManager` is the one in-place modal write path.
- **Payload policy:** a closed allowlist of business columns per subject, so `id`/`created_at`/`updated_at`/`deleted_at`/`created_by`/`updated_by`/`remember_token`/observer flags are excluded by construction. `created` = full snapshot; `updated` = only changed audited fields (**no event when nothing audited changed**); `deleted` = pre-delete snapshot with the primary key preserved; `restored` = post-restore snapshot. Date casts store plain `Y-m-d`.
- **Relationship snapshots:** a foreign key is always stored as its scalar, with one bounded label alongside it (`project_id` → `project_label`, `ProjectCost` only) — never a serialized relation or collection. **Each side is labelled from its own foreign key value**, resolved through `Project::withTrashed()->select(['id','code','name'])->find()`, never through the model's `project` relationship (which on the pre-change side already points at the new project). So a reassignment records `project_id` + `project_label` for both the previous and the new project, and a soft-deleted previous project still resolves. Lookups are memoized per logical action (`AuditModelSnapshotter::$labelCache`), and a foreign key that did not change triggers no lookup at all. `changed_fields` stays a list of semantic business fields (`project_id`) — a label is a readability snapshot, not a field a user changed.
- **Settings are fail-closed:** `settings.value` is `[REDACTED]` whenever the setting key is credential-shaped (judged by `AuditRedactor`'s own rules after separator normalization, so `mail.password` is caught) or the value is a PEM block or a long whitespace-free opaque token.
- **Semantic field aliasing (`settings.key` → `setting_name`):** `settings.key` is a setting's business identifier, but a bare `key` field is — correctly, globally — treated as secret-shaped by `AuditRedactor`, which would erase both sides of a rename. `AuditSubjectDefinition::$fieldAliases` therefore emits that column as `setting_name` in `old_values`/`new_values`/`changed_fields`; the raw name `key` never reaches the payload. This is a payload-key rename only and the single such alias in the codebase — values still pass `SettingValuePolicy` and the central redactor afterwards, so a secret-shaped setting records its **name** change while its **value** stays `[REDACTED]` on both sides. `AuditRedactor` was not weakened and no general `key` exception was added.
- **Duplicate prevention is structural:** auditing exists only in the service, so `HasUserTracking`, `ProjectObserver`/`ProjectCostObserver` (which only flip `ProjectFinancialSnapshot.is_dirty` on a different, unaudited table) and Filament lifecycle callbacks cannot manufacture a second event. For the same reason **no audit-suppression switch exists or is needed** — seeders, migrations, factories, permission sync and test fixtures write these tables directly and produce no audit history.

### Audit Log — financial integration (OMS Task 9B.3)

**The core rule: one logical financial action = exactly one `AuditEvent`.** A single receipt/disbursement/execution-payment/expense/exchange writes a `Transaction`, 2–4 `TransactionLine` rows, one source record and 2–4 account-balance movements. **Exactly one** event describes all of it and carries the resulting `transaction_id` + `transaction_number`, so the ledger can be followed from the event without duplicating its lines. `Transaction` and `TransactionLine` have **no observer and no registry entry, permanently** — registering either would duplicate every financial event.

**Workflow → alias → real write path** (`event_category = financial`; aliases follow the workflow, never the FQCN, precisely because of the crossed naming above):

| Alias | Workflow | Real write path (create / edit / delete) |
|---|---|---|
| `project_cost_receipt` | المبالغ المستلمة | `CreateProjectCostReceipt` / `EditProjectCostReceipt` / `ProjectCostReceiptsTable::deleteReceipt()` |
| `project_disbursement` | صرف مبلغ المشروع | `CreateProjectCostBudgetsPayment` / `EditProjectCostBudgetsPayment` / `ProjectCostBudgetsPaymentsTable::deletePayment()` |
| `execution_payment` | صرف مبالغ التنفيذ | `CreateExecutionPayment` / `EditExecutionPayment` / `ExecutionPaymentsTable::deletePayment()` |
| `general_expense` | المصروفات العامة | `CreateGeneralExpense` / `EditGeneralExpense` / `GeneralExpensesTable::deleteExpense()` |
| `general_exchange` | التحويلات العامة | `CreateGeneralExchange` / `EditGeneralExchange` / `GeneralExchangesTable::deleteExchange()` |

Each delete is a single static method that the Edit page's header action, the table row action and the table bulk action all call — so one deletion yields one event regardless of entry point.

- **Atomicity — the audit belongs to the workflow's own transaction.** Unlike `AuditedCrudService` (which owns its transaction because a CRUD mutation is a single save), `App\Services\Audit\Financial\FinancialAuditRecorder` **never opens one**. Every workflow already runs one `DB::transaction()` spanning the source record + `Transaction` + `TransactionLine`s + balance changes + attachment metadata; the recorder is called from inside it, always `AuditFailureMode::Required`, and **asserts `DB::transactionLevel() >= 1`, throwing `LogicException` otherwise**. If the audit insert fails, the source record, the transaction, every line and every balance roll back together — no partial financial operation can remain. The call is placed **before** the success `Notification`, so a rollback can never be reported to the user as a success. Global Filament transactions remain disabled.
- **Payload = bounded scalars only.** Never a `TransactionLine` array, an Eloquent model, a relation, a collection, a file, or a floating-point money value. Money/percentages/FX rates are fixed-scale **decimal strings** (`FinancialAuditValue`: money/percent 2 dp, rates 6 dp). Carried: `operation_type`, `transaction_id`, `transaction_number`, `fiscal_year_id`, `transaction_type_id`, `partner_id`, `date`, the workflow's amounts/percentages/`fx_rate`, currency ids + codes (`currency_id`/`source_currency_id`/`disbursement_currency_id`), `project_id`/`project_cost_id`/`project_cost_budget_id`, and one `<role>_account_id` + `<role>_account_label` pair per account. Free text (`notes`, and the expense's `description`) is carried **bounded to 255 characters** so a text-only edit stays auditable.
- **Account role vocabulary is closed** (`FinancialAccountRole`): `debit`, `credit`, `source`, `destination`, `admin`, `transfer`, `beneficiary`. The role is encoded in the payload key itself, so it can never drift from the account it describes; an unrecognised role throws.
- **Event behavior.** `created` → `old_values = null`, full bounded snapshot in `new_values`. `updated` → only the financial/business fields that actually changed, on both sides, with the **old and new label/code of every reassigned foreign key preserved** (a `_label`/`_code` satellite is carried when its `_id` changed, and never appears in `changed_fields`); `operation_type`/`transaction_id`/`transaction_number` are context, always carried, never reported as changed; **a no-op edit writes no event.** `deleted` → the full pre-delete snapshot (transaction number and account labels captured while everything was still intact) in `old_values`, `new_values = null`.
- **Financial master data uses the 9B.2 CRUD architecture** (`event_category = crud`), with the same REQUIRED atomicity: aliases `account`, `account_type`, `currency`, `exchange_rate_history`. Financial semantics are preserved by value policies — `accounts.current_balance` and `exchange_rate_histories.rate` are stored as decimal strings.
- **`HasUserTracking` is now ACTIVE on `Account` and `AccountType`** (the behavior Tasks 8.2/8.3 deliberately deferred after reconciling the columns). Forward-only: **no historical row is backfilled**. A balance `increment()`/`decrement()` writes only `current_balance` in SQL, so it never rewrites `updated_by` — the actor behind a balance movement lives on the financial event. `Account` also gained `protected $attributes = ['current_balance' => 0]`, mirroring the column default, because the form never submits that field.
- **Account creation with an opening balance yields exactly ONE `account` event.** That path also creates an opening `Transaction`, two `TransactionLine`s, a per-currency clearing `Account`, and its `AccountType`/`TransactionType`/`TransactionSuperType` lookups — none of which is separately audited (they are consequences of one user action, and auditing is wired at explicit call sites, not observers). The single event instead carries `opening_transaction_id`, `opening_transaction_number`, `opening_balance`, `opening_balance_date`, `opening_balance_fx_rate`. `AuditedCrudService::recordCreatedWithin()` exists solely for this case and also fails closed outside an open transaction.
- **Still not audited (later phases):** ~~users/roles/permissions and authentication (**9B.4**)~~ — done, see the next section; attachments, backup/restore, report exports, and the Audit UI remain. There is still no Filament audit screen and no audit permission.

### Audit Log — users, roles, permissions & authentication (OMS Task 9B.4)

**`event_category = security`.** Five stable aliases (`App\Services\Audit\Security\SecurityAuditSubject`): `user`, `role`, `permission`, `authentication`, `permission_sync`. `permission` is registered for vocabulary completeness and is deliberately **unwritten** — `PermissionResource` is structurally read-only, and the only code that creates a `Permission` row is `PermissionSyncService`, whose whole run is one `permission_sync` event. `User`, `Role` and `Permission` remain **absent from `AuditSubjectRegistry`**, so no general-CRUD path and no Spatie pivot write has an independent audit route.

**The real write paths (verified against the resource/page classes, not assumed).** Every UserResource/RoleResource mutation already funnelled through a service, so that is where auditing was wired — not into observers, which cannot see `syncRoles()`/`syncPermissions()` pivot writes at all and fire after the row is already committed:

| Alias | Actions | Real write path |
|---|---|---|
| `user` | `created` / `updated` / `deleted` / `restored` | `UserManagementService::createUser()` / `updateUser()` (incl. the `applySelfUpdate()` branch) / `deleteUser()` / `restoreUser()` — called by `CreateUser`, `EditUser`, `UsersTable`'s Delete/Restore actions and `EditUser`'s Delete header action |
| `role` | `created` / `updated` / `deleted` | `RoleManagementService::createRole()` / `updateRole()` / `deleteRole()` — called by `CreateRole`, `EditRole`, `RolesTable`'s Delete action and `EditRole`'s Delete header action |
| `permission_sync` | `synced` | `PermissionSyncService::sync()` — reached from both `oms:sync-permissions` and `ListPermissions`' `syncPermissions` header action (via `PermissionManagementService`) |
| `authentication` | `login_success` / `login_failed` / `logout` | `App\Listeners\Auth\AuthenticationAuditSubscriber`, registered once in `AppServiceProvider::boot()` |

- **Required vs BestEffort.** Everything that mutates identity or privilege is `AuditFailureMode::Required` and recorded by `SecurityAuditRecorder`, which — like `FinancialAuditRecorder` and unlike `AuditedCrudService` — **never opens a transaction** and throws `LogicException` when `DB::transactionLevel() < 1`. Each service method already wrapped its model save, its Spatie pivot sync and its last-active-Super-Admin `lockForUpdate()` in one `DB::transaction()`; the audit insert simply joins it, so a failed audit rolls back the user row, the password, the activation change and every `model_has_roles`/`role_has_permissions` pivot together. The three authentication events are `BestEffort` and live in a physically separate class (`AuthenticationAuditRecorder`) that cannot emit a Required event: all three describe something already irreversible when Laravel dispatches them, and making them Required would let an audit-storage outage block a legitimate logout or create a **login loop locking out the administrators who would have to repair the audit storage**.
- **One logical action = one event.** A single UserResource submission that renames a user, deactivates them, resets their password and replaces both roles writes **one** `security.updated` event carrying all four — never one per field, never one per pivot row. Replacing a role's entire permission set is likewise one event. A permission-sync run creates ~186 permission rows and reconciles five roles' pivots and still writes **one** summary event. A no-op update (nothing changed) writes **nothing**; a no-op *sync* still writes its event, because running that security-administration command is itself the accountable act.
- **User payload = closed allowlist of six fields** (`UserSecuritySnapshot`): `user_id`, `name`, `email`, `is_active`, `roles`, `direct_permissions`. Not a filtered `toArray()` — `password`, `remember_token`, `email_verified_at`, session ids and reset tokens are never *read*, so they cannot leak. `direct_permissions` is Spatie's **direct** relation only, never the effective (role-derived) set, which would be the forbidden full permission dump. Role/permission names are read through the relation **query builders**, never the loaded relations or Spatie's cache, so a before/after pair reflects two real database states.
- **Password handling.** The only thing ever recorded is `"password_changed": true` — no plaintext, no old hash, no new hash, no confirmation field, and `password` never appears in `changed_fields` either (the flag's own name is used). `password_changed` was added to `AuditRedactor::SAFE_EXCEPTIONS`: without it the global `password` segment rule would redact the one safe fact while protecting nothing. The bare field name `password` is **not** excepted and stays redacted for every subject.
- **Role/permission diffs are deterministic** (`SecurityNameDiff`): every array is de-duplicated, `sort()`ed and `array_values()`-reindexed, so `['Admin','Accountant']` and `['Accountant','Admin']` produce byte-identical payloads and "unchanged" means set equality. Stored as `roles`/`permissions` (before on `old_values`, after on `new_values`) plus `roles_added`/`roles_removed`/`permissions_added`/`permissions_removed`. Reindexing matters concretely: `array_diff` preserves keys, so without it these would serialize as JSON *objects*.
- **Failed-login email policy.** The listener reads **only `$event->user`** — the account Laravel's own user provider already resolved from the submitted credentials. `$event->credentials` (which holds the plaintext password *and* the raw submitted email) is never touched. A matched account is recorded as the **subject** with `identified: true` + `user_id`/`email`/`is_active`; an unmatched attempt records a generic subject with `identified: false`, null `subject_key`/`subject_label`, and no attacker-supplied text anywhere. The actor is always `AuditActorContext::guest()` — real IP/user-agent/route, no identity — because a failed attempt proves someone *typed* an email, never that the account owner was typing.
- **Duplicate prevention for authentication.** One logical attempt can dispatch `Failed` **twice**: when credentials are valid but `canAccessPanel()` denies entry, `SessionGuard::attemptWhen()` fires it and `Filament\Auth\Pages\Login` then fires it again (filament/filament v5.6.7, `Login::authenticate()` lines 151-160). The de-duplication window is one **attempt** — opened by the `Attempting` event, which is dispatched exactly once at the start of every attempt and never between the duplicate pair — so the pair collapses to one row while two genuine attempts stay two. This requires `AuthenticationAuditRecorder` to be a **container singleton** (registered in `AppServiceProvider`), because Laravel's `Dispatcher::subscribe()` re-resolves the subscriber for every dispatched event.
- **Subscriber method names are load-bearing.** They are `onAttempting`/`onLogin`/`onFailed`/`onLogout`, deliberately **not** `handle*`. Laravel's framework-level `EventServiceProvider` auto-discovers public `handle*`/`__invoke` methods under `app/Listeners` and registers them on top of any explicit registration; with `handle*` names every login and logout wrote **two identical rows**. (Found and fixed during this phase; `AuthenticationAuditTest` now asserts exactly one registered listener per auth event.)
- **Deliberately not subscribed:** `Authenticated` (fires on every authenticated request — would write a row per page view), `Validated` (fires before the panel-access check), `CurrentDeviceLogout`/`OtherDeviceLogout` (no trigger exists in this application). **There is no password-reset flow at all** — `AdminPanelProvider` calls `->login()` but never `->passwordReset()` — so no `PasswordReset` event is wired; an administrator resetting a user's password goes through `UserManagementService` and is covered by the single `user`/`updated` event.
- **No direct-user-permission write path exists.** `UserForm` exposes roles only, and nothing in `app/` calls `givePermissionTo()`/`revokePermissionTo()`/`syncPermissions()` on a `User`. The snapshot, diff and one-event payload for direct permissions are implemented and tested (`SecurityAuditPayloadPolicyTest`) so such a path is audited correctly the moment one exists — but this phase deliberately did **not** invent one, which would mean adding a privilege-granting surface the application does not have.
- **Permission-sync summary payload:** `status`, `permissions_created`, `permissions_found`, `permissions_removed` (always `0` — the service never deletes), `obsolete_permissions_preserved` (rows outside `PermissionRegistry` a destructive implementation would have dropped), `final_permission_count`, `registry_permission_count`, `roles_created`, `roles_found`, `roles_affected` (the five system roles only — custom roles are never touched), `role_permission_counts`, `super_admin_user_count`, plus a fresh `correlation_id` per run. No guard name, config value or environment detail. **Actor attribution is honest per entry point:** a terminal run records `actor_type = command` with null identity and null request metadata; the Filament header action records the real administrator (`actor_type = user`).
- **The one place a non-interactive run now produces audit history.** 9B.2's rule was that seeders/migrations/factories write these tables directly and leave no audit trail. That still holds for every model path, but `PermissionSyncService::sync()` is called by `DatabaseSeeder::run()` and by `RestoreReconciler` (as `oms:sync-permissions`, always **after** its `migrate --force` step, so `audit_events` is guaranteed to exist), and both now write one `permission_sync` event with `actor_type = command` and null identity. This is deliberate and correct: a seed or a post-restore reconciliation really does rewrite the five system roles' permission sets, which is exactly the accountable act this event exists to record. Verified green across `tests/Feature/Restore` + `tests/Feature/Console` + `tests/Feature/Commands` (347 passed) and `DatabaseSeederSuperAdminTest`.
- **No authorization was broadened.** `Gate::before`'s Super Admin bypass, `UserPolicy` (including its permanently-false `forceDelete`), `RolePolicy`, `PermissionPolicy`, `RoleResource::canEdit()/canDelete()`, `canAccessPanel()` and every `UserManagementService`/`RoleManagementService` safety rule are unchanged. A rejected action writes **no** audit event — the rejection happens inside the same transaction the event would have been written in. No `audit.view` permission and no Audit UI were created.

### Audit Log — attachments & report exports (OMS Task 9B.5)

Two categories: **`event_category = attachment`** (subject alias `attachment` for every event) and **`event_category = report_export`** (subject alias = the report). A denied private-file access is recorded under **`event_category = security`** as `attachment_access_denied`, alongside the rest of the 9B.4 security trail.

**Attachment write paths (verified against the real Create/Edit/Table code, not documentation).**

| Action | Where it is recorded | Mode |
|---|---|---|
| `uploaded` | `AttachmentUploadService::store()` — the single choke point all ten upload call sites (5 Create + 5 Edit pages) already go through | Required |
| `replaced` | Same method, when the Edit page passes its outgoing attachment as the new `replacing:` argument | Required |
| `deleted` | An explicit `AttachmentAuditRecorder::deleted()` immediately **before** each soft delete — the 5 Edit pages' `remove_current_attachment` branch and the 5 tables' `delete*()` methods | Required |
| `viewed` / `downloaded` | `AttachmentController::show()`, after authorization and after every 404 check, **before** the response is built | Required |
| `attachment_access_denied` (`security`) | `AttachmentController::show()`, in the `catch` around `Gate::authorize()`; the `AuthorizationException` is always re-thrown unchanged | BestEffort |

- **`viewed` and `downloaded` are two real, explicitly requested operations**, not an inference. The route is `/attachments/{attachment}/{mode}` with `->whereIn('mode', ['view','download'])`; the controller re-validates against the same two literals and selects `Content-Disposition: inline` versus `attachment`. Nothing reads `Accept`, `User-Agent` or `Sec-Fetch-Dest`. **If the mode segment is ever collapsed to one path, these must collapse to a single `accessed` action rather than start guessing.**
- **Required access policy: no audit row, no bytes.** The access event is written before `AttachmentStorageService::toResponse()` runs, so an audit-storage failure throws and the private financial attachment is not served. Conversely the 403 is BestEffort: letting an audit outage turn a clean denial into a 500 would weaken the denial and hand an unauthorized caller a distinguishable response.
- **404s are never audited** — unknown id, soft-deleted attachment, missing/soft-deleted parent, unsupported attachable type, unapproved disk, absent file. Auditing them would let a logged-in prober write one row per guessed id. Only the single unambiguous "authenticated, authorized-for-nothing" 403 is recorded, and only with identifiers this application had **already** resolved from the digits-only route id.
- **Payload = closed metadata list**: `attachment_id`, `operation`, `parent_subject` (a Task 9B.3 workflow alias), `parent_id`, `parent_label`, `transaction_number`, `file_name`, `mime_type`, `file_size`. Never `file_path`, never the disk name, never the Filament temporary upload path, never a signed URL or token, never one byte of content. It is built as a closed list, not filtered from a wider one.
  - **`parent_id`, not `parent_key`** — `AuditRedactor` is segment-based and would redact any field with a bare `key` segment, erasing the identifier that links this event to its parent's `financial` event. Same resolution as the `setting` subject's `setting_name` alias: pick a safe semantic name rather than weaken the redactor globally.
  - **The original client filename is not recorded, because it does not exist.** No financial form calls `preserveFilenames()`, so Filament stores the upload under a generated name and the browser-supplied one is gone before any application code sees it. `file_name` is the final deterministic stored name (`gen_12_20260105_500.pdf`), passed through `AttachmentStorageService::safeDownloadName()` — the same sanitizer that guards `Content-Disposition` — before the central redactor/bounder.
  - **Ordering-independent by construction.** Every lookup in `AttachmentAuditMetadata` uses `withTrashed()`, because the five workflow delete paths soft-delete the `Transaction` *before* they reach the attachment; without it a pre-delete snapshot would silently lose its transaction number.
- **Atomicity, stated honestly.** `AttachmentAuditRecorder` never opens a transaction and throws `LogicException` below `transactionLevel() >= 1`; all fifteen real call sites are already inside the workflow's own `DB::transaction()`, so the Attachment row and its audit event commit or roll back **together**. That covers the database. It does **not** cover the filesystem: `AttachmentUploadService` moves the uploaded file to its final path before the surrounding transaction commits, so a rollback leaves that one new file orphaned on the private disk — already true of every pre-9B.5 rollback in these workflows, and deliberately unchanged. The pre-existing crash-safe ordering is untouched: a replacement stores the new file first and only then soft-deletes the previous **row**, and **no path in this application ever deletes a prior file from disk** (both replacement and workflow deletion keep it for audit), so a Required audit event can never cause an old file to be destroyed ahead of a commit.
- **One logical file action = one event.** A replacement is ONE `replaced` event carrying both sides in `old_values`/`new_values`, never an `uploaded` plus a `deleted`; `changed_fields` lists only `attachment_id`/`file_name`/`mime_type`/`file_size` (the parent is identical on both sides) and omits any of those that did not actually change. There is no `Attachment` observer and no `Attachment` entry in `AuditSubjectRegistry`, so an Attachment save/delete has **no independent audit path** — duplicates are structurally impossible. A financial operation carrying a file legitimately produces exactly one `financial` event **and** one `attachment` event; the financial event is never duplicated.

**Report exports.** Six pages, ten export paths, all verified from the pages themselves:

| Alias (`ReportExportSubject`) | Page | Formats |
|---|---|---|
| `account_statement` | `AccountStatementPage` | xlsx, docx |
| `trial_balance` | `TrialBalancePage` | xlsx, docx |
| `donor_financial_report` | `DonorFinancialReportPage` | xlsx, docx |
| `comprehensive_financial_transactions` | `ComprehensiveFinancialTransactionsPage` | xlsx, docx |
| `projects_general_financial` | `ProjectsGeneralFinancialPage` | xlsx |
| `project_financial_details` | `ProjectFinancialDetailsPage` | docx |

- Each alias is the page's own `reportViewPermission()`/`reportExportPermission()` **stem** (`reports.<alias>.view|export`) — the one identifier a report already owns and that survives a class rename. Only `xlsx` and `docx` exist as `ReportExportFormat` cases; **no export service in this codebase produces CSV or PDF**, so neither was added as speculative vocabulary.
- **The action is `export_requested`, not `export_completed`, and that is a deliberate accuracy decision.** All nine export services end in `response()->streamDownload(fn () => $writer->save('php://output'), …)`: the in-memory `Spreadsheet`/`PhpWord` object is built synchronously, but the **serialization** — the step that can still exhaust memory or throw inside PhpSpreadsheet/PhpWord — runs inside the callback, which Symfony invokes only after the response is returned and the headers are committed. No temporary file is produced at any point, so there is also no artifact whose existence could prove success. There is no point in the current synchronous code where successful generation is objectively known before the response is returned, so `export_completed` would be a claim the code cannot support. **If exports ever move behind a queued job or a materialized temporary file, a genuine completion event becomes possible and should be ADDED, not substituted.**
- **Placement.** After `AuthorizesReportAccess::authorizeReportExport()` (so a 403 writes nothing) **and** after the page's own `canExport()`/"عرض" gate (so a blocked export that returns `null` and only shows a warning notification is not recorded as an export). Required mode, so an audit failure throws and no financial file is generated or delivered. The two pages without a submission gate (`projects_general_financial`, `project_financial_details`) have none to wait for — the first always exports every snapshot row, the second is driven entirely by its `{project}` route parameter.
- **Ordinary report page views, filter changes and pagination are NOT audited** — only an export request is.
- **Payload = filters only, ids plus short labels**, e.g. `report`, `report_label`, `format`, `exported_at`, `report_submitted`, and the page's applied* snapshot (`date_from`/`date_to`, `account_id`+`account_label`, `currency_id`+`currency_code`, `donor_id`+`donor_name`, `project_id`, `include_zero_accounts`, bounded counts). Never rows, never a result dataset, never file content, never a generated path (none exists). `ReportExportAuditRecorder::boundFilters()` accepts scalars and **flat** lists of scalars and drops anything nested — a report page holds its rows as `array<int, array<string, mixed>>`, so passing `$this->rows` or `$this->report` is structurally impossible, not merely discouraged.
- **Duplicate prevention is structural.** The shared `AuthorizesReportAccess` trait records nothing — it only checks permissions; the event is written exclusively by the page-specific export method, once. Nothing is recorded inside the export services or inside the `streamDownload` callback, so a response callback can never add a second event.
- **No authorization was changed.** `authorizeReportExport()`, every `canExport()` gate, every report permission and all Excel/Word generation behavior are byte-for-byte unchanged; `AttachmentController`'s allowlist, its parent-policy delegation and every 404 rule are unchanged apart from the added audit calls.

### Audit Log — backup & restore (OMS Task 9B.6)

One category, **`event_category = backup_restore`**, with two subject aliases: **`backup`** (an archive's creation/download/deletion) and **`restore`** (a restore operation's lifecycle). The third candidate alias `backup_operation` is deliberately unused and undefined — it would only name the shared `backup_operations` table both live in, and every real event is unambiguously about one or the other. `correlation_id` is always the operation's own UUID.

**Backup entry points and where each event is recorded.**

| Action | Where it is recorded | Mode |
|---|---|---|
| `backup_requested` | `BackupCreationOrchestrator::enqueue()` — the ONLY place a `BackupOperation` row is ever created, inside a new `DB::transaction()` wrapping the insert | Required |
| `backup_completed` | `BackupCreationOrchestrator::execute()`, structurally **outside** its try/catch, after the archive is generated, encrypted, verified as an unpublished candidate and published | BestEffort |
| `backup_failed` | Same method's `catch`, plus `CreateBackupJob::failed()` as the queue-level safety net | BestEffort |
| `backup_downloaded` | `BackupDownloadController::show()`, after authorization/404/423 checks and **before** the `StreamedResponse` is built | Required |
| `backup_download_denied` | Same controller, on a genuine 403 for an already-authenticated caller whose UUID resolves to a real row | BestEffort |
| `backup_delete_requested` | `BackupDeletionService::delete()` after every eligibility rule passes, and `BackupRetentionService::deleteOperation()` under the per-backup lock — both immediately **before** the irreversible unlink | Required |
| `backup_deleted` | Both of the same services, after the archive was handled and the row soft-deleted | BestEffort |

- **Duplication is structurally impossible for the request event**, because the Filament manual action, `oms:backup` (by hand or from the two `bootstrap/app.php` scheduler entries) and `RestoreOrchestrator`'s mandatory pre-restore safety backup all funnel through `enqueue()` and the outer layers record nothing. A scheduled duplicate that loses the `deduplication_key` unique race has its whole transaction rolled back, so the winner keeps exactly one event. Every other lifecycle state is guarded by `BackupRestoreAuditLedger` (at most one row per `correlation_id` + `event_action`), which is what makes `CreateBackupJob`'s three retries and repeated retention runs unable to duplicate a state.
- **Filesystem work is not database-atomic, so the semantics say only what is true.** `backup_requested` and `backup_delete_requested` are Required because both are written *before* the thing they describe becomes real — no queued archive and no unlink can happen unaccountably. `backup_completed`/`backup_failed`/`backup_deleted` are BestEffort *because* the filesystem has already changed: escalating them would let an audit outage mark a fully published, verified archive as `failed` (the creation pipeline's own catch block would do exactly that), or claim a deletion did not happen after the file was already gone. Task 9B.6 §6 forbids both. **A valid completed backup is never deleted because a completion audit insert failed.** The ledger probe itself sits *inside* the best-effort guard for the same reason — it queries the very table whose unavailability the path must tolerate.
- **Download: no audit row, no bytes.** Authorization runs first, unchanged. The Required event is written while both the shared subsystem lock and the per-backup file lock are held, and those are released explicitly if the write throws (their normal release lives in the response callback, which then never runs). Ordinary 404s (unknown uuid, non-completed, unapproved disk, unsafe path, missing file) and every 423 lock conflict record **nothing**, so a logged-in prober cannot write a row per guessed identifier; a 403 stays a 403 under a total audit outage.
- **Backup payload = bounded row metadata only**: `operation_uuid`, `backup_type`, `scope`, `status`, `components` (`database`, `private_attachments`), `requested_at`/`started_at`/`completed_at`/`verified_at`/`failed_at`, `archive_size_bytes`, `original_size_bytes`, `attachment_file_count`, `checksum_sha256`, `manifest_version`, `encryption_key_id`, `encryption_method`, `is_protected`, plus `delete_trigger`/`archive_file_removed`/`retention_keep_count` on deletion events and `failure_code`/`failure_category` on failures. **`components` reports no public-files component because the real archive has none** (manifest + dump + private attachments only). Never the encryption key, `APP_KEY`, `.env`, database credentials, SQL, `stored_path` or any other path (absolute, relative or temporary), process output, a stack trace, or an exception message.

**Restore entry points.**

| Action | Where it is recorded | Mode |
|---|---|---|
| `restore_requested` | `RestoreRequestService::createQueuedRestore()`, inside its existing `DB::transaction()` | Required |
| `restore_started` | `RestoreLaunchService::claim()`, inside the same transaction as the atomic conditional claim UPDATE | Required |
| `restore_reconciled` | A new eighth, deliberately non-fatal step at the end of `RestoreReconciler::reconcile()` (database/full scopes only) | BestEffort |
| `restore_completed` / `restore_failed` / `restore_partial` | `RestoreTerminalResultWriter::finish()` — the single funnel every orchestrator branch already goes through | BestEffort |
| `restore_failed` (launch) | `RestoreLaunchService::markFailed()` — a claimed restore whose progress journal or process spawn failed | BestEffort |
| `restore_interrupted` | `RestoreStaleAcknowledgmentService::acknowledge()` — the explicit human "this crashed restore is stopped" review | BestEffort |

- **The confirmation IS the request.** The two-step Filament wizard validates both steps and the typed `RESTORE {uuid8}` phrase *before* `RestoreRequestService` is reached, and that phrase is `dehydrated(false)` so it never reaches `$data`, the row, or any payload. `restore_requested` therefore carries the confirming actor and `confirmed_at`; a separate "restore_confirmed" event would describe a UI state that does not exist.
- **Not audited because the path does not exist:** there is no restore-file upload or file-selection step (a restore always reads an existing, verified archive on the approved disk) and there is no cancellation path at all (`RestoreStaleAcknowledgmentService` is explicitly not resume/retry/rollback/repair). `oms:restore-watchdog` is detection-only and mutates nothing, so it emits nothing.
- **`restore_partial` is not an invention** — `BackupStatus::RestorePartial` is the engine's existing, distinct "destructive boundary crossed, manual review required" outcome, and folding it into success or failure would misreport it.

**How restore audit history survives the database being replaced.** A database-scope restore imports a full dump over the live schema, so the `audit_events` table the pre-restore events were written to is physically replaced. **No new external log was created for this.** The existing signed restore progress journal (`RestoreProgressWriter` → `restores/{uuid}/progress.json`) is reused: it is private (0700 dir / 0600 files), HMAC-signed and refused if tampered with, field-by-field bounded by `RestoreProgressSnapshot` (which by construction cannot hold a confirmation phrase, password, key, raw command line, stack trace or unbounded exception text), and since Task 7C.5 it already carries the bounded `reconciliation_snapshot` — source backup, safety backup, and the restore's own requester identity and `confirmed_at` — written *before* the import runs.

- After the import, `RestoreReconciler`'s final step replays `restore_requested` + `restore_started` + `restore_reconciled` into the **restored** table, from that journal. Its position is load-bearing: after `migrate --force` (so the table's schema exists), after `oms:sync-permissions`/`permission:cache-reset` (which record nothing themselves, so permission reconciliation cannot duplicate a restore event) and after `RestoreMetadataUpserter` rebuilt the three `backup_operations` rows these events reference. `RestoreEphemeralTablePolicy` never touches `audit_events`.
- `RestoreTerminalResultWriter` re-attempts the same idempotent replay before writing its terminal event, which covers the one case reconciliation cannot: an import that succeeded but reconciliation that failed before reaching the replay step. An interrupted restore stays recoverable and auditable through the stale-acknowledgment path.
- **Idempotency:** every replayed/terminal state goes through the ledger's `(correlation_id, event_action)` check, so running the replay twice, running both replay points, or re-running a recovery adds nothing. Replayed rows carry `replayed_after_database_replacement: true`, so a reconstructed row is honestly distinguishable from an original one. `correlation_id` = the restore UUID — the same value on both sides of the replacement, so nothing had to be added to the journal to carry it.
- **Everything from `restore_reconciled` onwards is BestEffort and cannot throw**, because each is written after the database and/or attachment directories have already changed irreversibly. Escalating there could only produce a worse and less truthful outcome: aborting reconciliation would downgrade a genuinely successful restore to `RestorePartial`, and throwing out of the terminal writer would suppress the terminal record itself. The signed journal remains the authoritative terminal record.
- **Restore payload:** `restore_uuid`, `source_backup_uuid`, `pre_restore_safety_backup_uuid`, `scope`, `components`, `requested_by` (bounded `user_id`/`name`/`email` snapshot), `requested_at`/`confirmed_at`/`started_at`/`completed_at`/`failed_at`, `source_archive_size_bytes`, `source_archive_checksum_sha256`, `source_manifest_version`, `encryption_key_id`, `status`, `phase`, `failed_phase`, `recovery_state`, `reconciliation` status flags, `acknowledged_by`/`acknowledged_at` on an interruption, and `failure_code`/`failure_category`. Never archive contents, SQL, a dump, a key, credentials, any path, the launch nonce, a confirmation phrase, process output, a stack trace, or an exception message. The field is named exactly `encryption_key_id` because that is the one name `AuditRedactor` allowlists — any other name with a `key` segment would be redacted.

**Failure metadata is derived from structure, never from prose.** `BackupRestoreFailure` reads only an exception's own fixed `reasonCode` property (re-validated against a strict snake_case pattern and length bound) and matches its **class** against an ordered map for a category; it never calls `getMessage()` or touches a trace. `BackupErrorSanitizer` (which still produces `error_summary` for the operation row and the journal, unchanged) is deliberately **not** reused for audit payloads — it strips paths and `MYSQL_PWD=` but can still leave SQL, bound values or driver text in place. `RestoreTerminalResultWriter::finish()` gained an optional `?Throwable $cause` used for this classification only.

**Actor policy.** A real authenticated interactive request always wins (`actor_type = user`, with genuine IP/route metadata): the Filament create/delete/restore/acknowledge actions and the download route. Otherwise, a `daily`/`weekly` backup **request** is `actor_type = scheduler` with no invented user (`BackupType::isScheduled()` — the two scheduler entries are the only producers of those types), and everything else is `actor_type = command`, carrying the initiating/requesting user only where the architecture genuinely passes one (`created_by`, or the journal's requester snapshot when that user id still resolves in the database being written to). Completion/failure of a scheduled backup is deliberately **not** `scheduler` — the queue worker that ran it decided that outcome, not the schedule. Retention never invents a user.

**No authorization was broadened.** The Backup/Restore page stays real-Super-Admin-only (`BackupAuthorization`'s two-part role + permission check), every download/delete/restore/acknowledge restriction, every eligibility rule, retention windows and scheduled times, encryption fail-closed behavior, manifest/checksum verification and every restore safety check are unchanged. No new route, no `audit.view` permission, no Audit Log UI.

### Audit Log — the read-only UI (OMS Task 9B.7)

The first surface that **reads** the trail phases 9B.1–9B.6 built. Nothing about it can write.

| | |
|---|---|
| Navigation | `النظام` → **سجل التدقيق** (icon `o-clipboard-document-list`, sort **5** — after المستخدمون 1, الأدوار / سجل المرفقات 2, الصلاحيات 3, النسخ الاحتياطي والاستعادة 4) |
| Route | `/admin/audit-events` (list) and `/admin/audit-events/{record}` (view) |
| Resource | `App\Filament\Resources\AuditEvents\AuditEventResource` (model `App\Models\AuditEvent`) |
| Pages | `ListAuditEvents`, `ViewAuditEvent` — **and nothing else** |
| Table / infolist | `Tables\AuditEventsTable`, `Schemas\AuditEventInfolist` |
| Support | `App\Support\Audit\AuditViewAuthorization`, `AuditLabels`, `AuditPayloadPresenter` |

**Access is the real `Super Admin` ROLE alone.** `AuditViewAuthorization::check()` is a plain `hasRole(PermissionRegistry::SUPER_ADMIN)` comparison that never calls `Gate`/`can()`, so it can be neither short-circuited by the Super-Admin `Gate::before` bypass nor widened by a permission. It deliberately has **no permission second factor** (unlike `BackupAuthorization`'s role + `backups.*`) because **Task 9B.7 registers no `audit.*` permission at all** — with none in existence, there is nothing a future role edit or permission sync could grant by accident. `canViewAny()`/`canView()` delegate here; Filament resolves `canAccess()` from `canViewAny()` for navigation registration, page mount and every Livewire hydration, so hiding the sidebar entry and refusing the direct URL are one single check. `UserPolicy`, `Gate::before`, `canAccessPanel` and every existing role rule are untouched.

**Read-only in four independent layers.** (1) Only `index`/`view` pages are registered, so a create/edit URL is **404**, not 403. (2) Every mutation ability — `canCreate`, `canEdit`, `canDelete`, `canDeleteAny`, `canForceDelete`, `canForceDeleteAny`, `canRestore`, `canRestoreAny`, `canReplicate` — is hard-overridden to `false` in plain PHP, **not** through a Policy: `Gate::before` grants a real Super Admin every ability, so a Policy denial would be bypassed for precisely the actor this resource exists for (the same reasoning `PermissionResource` and `AttachmentResource` already document). (3) `getRelations()` is empty and `toolbarActions()`/`bulkActions()` are never called at all, which is what stops Filament rendering row-selection checkboxes — there is no bulk-destructive surface to authorize. (4) `AuditEvent` itself still throws `AuditImmutableRecordException` on update, delete, force-delete and replicate. There is **no export and no import** of any kind.

**Viewing never grows the trail.** No class in `App\Filament\Resources\AuditEvents` references `AuditLogger`, `AuditRecordRequest`, or the `AuditsRecordCreation`/`AuditsRecordUpdate`/`AuditedActions` concerns — asserted by a test that strips comments before searching, so the classes may still document the write paths they must never call. Opening the list, opening a detail page, filtering, searching, sorting and paginating each leave `AuditEvent::count()` unchanged.

**List columns.** Visible: وقت الحدث (`created_at`), المنفذ (`actor_name`, with `actor_email` as its description), نوع المنفذ, التصنيف, الإجراء, النتيجة, نوع السجل, السجل المتأثر (`subject_label`). Toggleable and hidden by default: معرّف السجل (`subject_key`), بريد المنفذ, معرّف الارتباط, عنوان IP, المسار, نوع الطلب, معرّف الحدث (`uuid`). Default order is `created_at desc, id desc` — Filament appends the primary-key tiebreaker itself when the default direction is `desc`. Search covers `subject_label`, `subject_key`, `actor_name`, `actor_email`.

**Filters — every one backed by an index that already existed.** Date from/to (`audit_events_created_at_idx`), التصنيف (`audit_events_category_action_idx` leading column), الإجراء, نوع المنفذ, المستخدم المنفذ (`audit_events_actor_user_id_idx`), نوع السجل (`audit_events_subject_idx` leading column), and معرّف الارتباط as an **exact** match (`audit_events_correlation_id_idx`). **No migration was written and no index was added** — the two filters with no dedicated index (`actor_type`, and `event_action` used without a category) are 5- and ~30-value columns where a single-column index would be too unselective for a planner to use, so the §1 STOP condition did not trigger.

**Performance rules actually enforced.** The list query `select()`s only the seventeen columns it can render, so **`old_values`, `new_values` and `changed_fields` are never fetched to draw a list row**. Actor and subject columns read the row's own snapshot, so the `actor` relation is never eager-loaded and no per-row subject lookup occurs. Every label is a static array lookup — no per-row permission or registry query. Filter options come from bounded static maps, never a `SELECT DISTINCT` over the audit table. The date filter uses sargable half-open range comparisons rather than `whereDate()` (which compiles to `strftime`/`DATE()` and would disqualify the index), and the actor filter applies an explicit `where('actor_user_id', …)` instead of `SelectFilter`'s default `whereHas` EXISTS subquery — which also keeps it working for an actor whose user row has since been deleted. Pagination is SQL `limit`/`offset`. No polling, no dashboard counters, **no caching of any kind** (nothing that could leak rows between users).

**Detail view — eight Arabic sections**, all reading stored snapshots: بيانات الحدث, المنفذ, السجل المتأثر, بيانات الطلب, القيم القديمة, القيم الجديدة, الحقول التي تغيرت, معلومات الارتباط والتتبع. A section is hidden rather than shown empty when its underlying columns are all null — so "no request metadata was recorded" (a `system`/`scheduler`/`queue` actor, for which `AuditActorContext` never fabricates any) is visually distinct from "this field was null". The actor renders from `actor_name`/`actor_email`/`actor_roles`/`actor_type` even when `actor_user_id` is null or its user row was deleted; the subject renders from `subject_type`/`subject_key`/`subject_label` **without ever loading the subject model**. A historical relation is never re-resolved to its current value.

**Payload rendering is plain text, escaped exactly once.** `AuditPayloadPresenter` flattens a decoded payload into a flat `array<string,string>` (nested keys become `parent · child`, list items `#1`, `#2`) for Filament's `KeyValueEntry`, which writes both key and value through `e()`. Nothing in the namespace calls `->html()` or `->markdown()`, nothing linkifies a stored URL, and no view re-loads a model — so a hidden model attribute cannot leak in through a fresh lookup. Escaping deliberately happens **only** at the rendering boundary: doing it in both places would display an audited `<script>` as `&lt;script&gt;`, which is wrong in a forensic tool. **Decimal strings pass through byte-for-byte** (`'1500.00'` stays `'1500.00'` — never cast to float), `AuditRedactor::MARKER` stays visibly `[REDACTED]`, and `null` / `''` / `[]` each get their own distinct Arabic rendering because those are three different facts in an audit trail. `AuditRedactor` was not weakened and no stored payload was modified.

**Unknown future values render safely — in every categorical field, without exception.** `event_category`, `event_action` and `subject_type` are open snake_case strings in the schema (`AuditLogger` validates only their shape), and `actor_type`/`status` are plain varchars too even though the model casts them to `AuditActorType`/`AuditStatus`. All five are therefore read by the UI as the **raw stored string** via `App\Support\Audit\AuditRawValue` (`getRawOriginal()`, so no cast is ever resolved) and labelled by `AuditLabels`, which falls back to the **stored value verbatim** — never to "غير معروف" — with the detail view showing the Arabic label next to the raw value (`إنشاء (created)`). This is what keeps a row written by a LATER build readable by this one: reading `$record->actor_type` on such a row throws a `ValueError`, which would otherwise break the list, the detail view, search, sorting and pagination. **The domain enum casts are deliberately left intact** — every write path keeps its strict typing — and an unknown `status` renders in neutral grey rather than as a green "نجاح". `AuditLabelCoverageTest` fails the moment a new `AuditSubjectRegistry` alias, subject enum case, category or backup/restore/report action constant appears without a label. The three keys Task 9B.6 asked this phase to **surface rather than flatten** — `replayed_after_database_replacement`, `failure_code`, `failure_category` — each carry an explicit Arabic label.

---

### Audit Log — FINAL ACCEPTANCE (OMS Task 9B.8) — **the Audit initiative is `COMPLETE`**

Closing acceptance for Tasks 9B.1–9B.7. **No production or test code was changed — zero fixes were required.** The Audit system is accepted as complete and release-ready at the code level.

**The final event contract.** Six categories and nothing else: `crud`, `financial`, `security`, `attachment`, `report_export`, `backup_restore`. **27 actions:** `created`, `updated`, `deleted`, `restored`; `synced`, `login_success`, `login_failed`, `logout`, `attachment_access_denied`; `uploaded`, `replaced`, `viewed`, `downloaded`; `export_requested`; `backup_requested`, `backup_completed`, `backup_failed`, `backup_downloaded`, `backup_download_denied`, `backup_delete_requested`, `backup_deleted`; `restore_requested`, `restore_started`, `restore_reconciled`, `restore_completed`, `restore_failed`, `restore_partial`, `restore_interrupted`. **34 subject aliases:** 15 from `AuditSubjectRegistry` (`project`, `project_cost`, `partner`, `partner_type`, `project_super`, `project_status`, `bank_type`, `fiscal_year`, `transaction_type`, `transaction_super_type`, `setting`, `account`, `account_type`, `currency`, `exchange_rate_history`), 5 financial workflows, 5 security, 6 report exports, `attachment`, `backup`, `restore`.

**A PHP FQCN can never reach `subject_type` — structurally, not by convention.** Every alias comes from a closed enum (`FinancialAuditSubject`, `SecurityAuditSubject`, `ReportExportSubject`, `BackupRestoreAuditSubject`) or from `AuditSubjectRegistry`'s hand-maintained allowlist, which **throws** `AuditSubjectNotRegisteredException` rather than deriving a fallback from a class name. A class rename therefore cannot orphan historical rows.

**Coverage matrix (category → integration point / failure mode / transaction ownership / duplicate prevention / primary test).**

| Category | Actions | Subject alias(es) | Integration point | Failure mode | Transaction ownership | Duplicate prevention | Primary test |
|---|---|---|---|---|---|---|---|
| `crud` | created, updated, deleted, restored | 15 registry aliases | `AuditedCrudService` via `AuditsRecordCreation`/`AuditsRecordUpdate`/`AuditedActions` | Required | service wraps mutation + event in one transaction | closed registry; `Transaction`/`TransactionLine` deliberately unregistered | `AuditedCrudServiceTest`, `AuditedCrudAtomicityTest` |
| `financial` | created, updated, deleted, restored | 5 workflow aliases | `FinancialAuditRecorder` from the 5 Create/Edit pages + tables | Required | inside each workflow's existing `DB::transaction()` | one event per logical action, carrying transaction identifiers | `ProjectCostReceiptAuditTest` (+4), `FinancialAuditAtomicityTest` |
| `security` | created, updated, deleted, restored | `user`, `role` | `SecurityAuditRecorder` via `UserManagementService`/`RoleManagementService` | Required | service transaction | one event per service call | `UserSecurityAuditTest`, `RoleSecurityAuditTest` |
| `security` | synced | `permission_sync` | `PermissionSyncService` | Required | sync transaction | one event per **run**, counts only — never per permission | `PermissionSyncAuditTest` |
| `security` | login_success, login_failed, logout | `authentication` | `AuthenticationAuditSubscriber` → `AuthenticationAuditRecorder` | **BestEffort** | none (post-hoc) | per-**attempt** `claim()` window reset by `Attempting` | `AuthenticationAuditTest` |
| `security` | attachment_access_denied | `attachment` | `AttachmentAccessAuditRecorder::accessDenied` | **BestEffort** + catch | none | genuine 403 only, **never** a 404 | `AttachmentAccessAuditTest` |
| `attachment` | uploaded, replaced, deleted | `attachment` | `AttachmentAuditRecorder` via `AttachmentUploadService` | Required | asserts an open transaction | one event per upload service call | `AttachmentWriteAuditTest` |
| `attachment` | viewed, downloaded | `attachment` | `AttachmentAccessAuditRecorder::accessed` via `AttachmentController` | Required, **before any byte is served** | none | route `{mode}` constrained to `view\|download` | `AttachmentAccessAuditTest` |
| `report_export` | export_requested | 6 report aliases | each page's own export method | Required | none | shared `AuthorizesReportAccess` trait records nothing | `ReportExportAuditTest` |
| `backup_restore` | 7 backup actions | `backup` | `BackupCreationOrchestrator`, `CreateBackupJob`, `BackupDownloadController`, `BackupDeletionService`, `BackupRetentionService` | Required pre-irreversible; **BestEffort** post-irreversible | `enqueue()` transaction | `BackupRestoreAuditLedger` on `(correlation_id, category, action)` | `BackupAuditTest` |
| `backup_restore` | 7 restore actions | `restore` | `RestoreRequestService`, `RestoreLaunchService`, `RestoreReconciler`, `RestoreTerminalResultWriter`, `RestoreStaleAcknowledgmentService` | Required for requested/started; non-throwing from `restore_reconciled` onwards | atomic launch-claim transaction | ledger + signed-journal replay idempotency | `RestoreAuditTest` |

**Actions never overstate what is known.** `export_requested` (never `export_completed`) is justified against all nine services in `app/Services/Reports`: the writer targets `php://output` inside the `streamDownload` callback, which Symfony invokes only after the response is returned and headers are committed, so nothing in the synchronous path knows generation succeeded and no temp file exists to prove it. `backup_requested`/`backup_delete_requested`/`restore_requested` are likewise honest pre-irreversible records, and `restore_interrupted` stays distinct from `restore_failed`.

**Sensitive-data acceptance: PASS.** Every secret-shaped occurrence in `app/Services/Audit/**` and `app/Support/Audit/**` is a **docblock** (the sole exception being the Arabic display label for `password_changed`). `AuditLogger` never logs an exception, its message or its `previous` chain — only a class name, a SQLSTATE-validated code and a hash fingerprint. `BackupRestoreFailure` reads a re-validated `reasonCode` **property** plus an `instanceof` match, never text. `UserSecuritySnapshot` is a closed six-field allowlist, so a password/token is never *read* rather than denylisted. `loginFailed()` records only the user Laravel's provider resolved, so the **submitted email is never stored**. `ReportExportAuditRecorder::boundFilters()` drops all nested values, structurally barring result rows. All 8 real local rows were scanned read-only and are **CLEAN**.

**Index review: no schema change needed and none made.** `EXPLAIN` over eleven real production query shapes confirmed all six required lookups are index-served. Two full-scan shapes are recorded without action: an unfiltered first page (an artifact of an 8-row table) and an `event_action`-only filter (that column is the composite index's *second* member and cannot be seeked alone) — neither proven necessary by a plan, and a permanent write cost on an append-only table was judged unjustified.

**Test results.** Focused groups 11/11 green. **Full suite: 2337 tests, 8528 assertions, 0 failures, 0 errors, 6 skipped, 1002.9 s, sequential.** All six skips are pre-existing/environment-gated (2 Windows symlink-permission, 1 Linux-only path, 3 create-page datasets for the routeless `transactions`/`transaction_lines`/`attachments` resources) — none Audit-related. Isolated migration acceptance on a disposable database: 68 DONE / 0 FAIL, seeders exit 0, permission sync 162 = 162 with zero drift and zero audit permissions.

**Two known non-defects, both proven rather than asserted.** `tests/Feature/Reports` returns process exit code 1 while all 72 tests pass — reproduced identically at `6061a1d` (before report-export audit integration), so it is pre-existing. `vendor/bin/pint --test` fails repo-wide (351 of 712 PHP files on a clean tree, no `pint.json`) and was deliberately not fixed, as reformatting would be a broad change outside this phase's files.

**Local database counts are timestamped acceptance snapshots, not permanent invariants.** At this phase's snapshot the real local database held `audit_events` **8**, users 5, roles 7, permissions 186, transactions 9, transaction_lines 26, backup_operations 13, attachments 10 (3 live), accounts 6. The `audit_events` count rose from 4 to 8 mid-acceptance through **legitimate live application traffic** (Livewire and `attachments.show` requests from real browsers), which was reviewed and accepted; the table is append-only and **no AuditEvent may ever be deleted, edited, reset or backfilled**. Because the trail grows whenever the system is used, a later reading above 8 is normal and is not a regression — any figure recorded here or in `docs/` describes what was true at that moment, never a value the database must still match.

**Deployment-only acceptance item.** A **real production restore drill** remains outstanding as an operations item, not a code defect: no disposable MySQL restore harness exists in this project (the suite is SQLite) and none was built. Restore audit behaviour is proven by the honest simulation in `RestoreAuditTest` (every `audit_events` row raw-deleted, signed journal untouched, so the replay can only have used the journal) — 30 passed / 320 assertions.

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

> **AUDIT INITIATIVE (Tasks 9B.1 – 9B.8): `COMPLETE` as of 2026-07-30.** Final acceptance passed every gate with zero code changes; full suite 2337 tests / 8528 assertions / 0 failures / 0 errors. See "Audit Log — FINAL ACCEPTANCE (OMS Task 9B.8)" in §2. Awaiting review and commit; **not pushed**.
>
> **Reports and their exports are working and approved — no report or export task is pending.** Two non-code items remain outstanding: the repo-wide `vendor/bin/pint --test` failure (351 of 712 PHP files, no `pint.json`) needs its own decision — adopt a `pint.json` matching the real style, or make one repo-wide formatting commit that changes nothing else — and a **real production restore drill** remains an operations acceptance item, not a code defect. No next system phase is currently set; the items below are open candidates, none of them started or scheduled.

- ~~**Excel + PDF (Arabic) export** for the general report — both general export and per-project export; mPDF approved for Arabic PDF.~~ **CANCELLED 2026-07-30.** The reports are working and approved, and their exports are delivered in **Excel (`xlsx`) and Word (`docx`) where implemented**. **No PDF/mPDF work is planned**, no mPDF package is installed, and no export service in this codebase produces PDF. This is no longer a roadmap item.
- **Observers / instant dirty-flag refresh** — schema (`is_dirty`) exists but observers are not wired; the manual button currently uses `--force` (full recalc). To make the "instant + smart" refresh real, add observers on Project/Cost/Receipt/Budget/Payment to set `is_dirty=true` on save/delete, and an hourly incremental schedule.
- **Verify the latest cleanup end-to-end** (removal of financial_indicator + financial_safety_indicator + reduction to 2 alerts) renders cleanly on both the general page and details page with no empty gaps, and that project 16 produces exactly the expected alerts.
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
