# Tasks Log

## Task Template

### Date

### Task

### Result

### Changed Files

### Verification

### Commit Hash

---

### Date
2026-07-29 (OMS Task 9B.5 — attachment & report-export audit integration)

### Task
Audit the real attachment paths (upload, replacement, deletion, authorized private access, and genuine 403 denials) and the real financial report exports, under two new categories `attachment` and `report_export` plus one new `security` action — with REQUIRED atomicity inside each caller's existing `DB::transaction()` for writes, Required-before-response for private file access, BestEffort for denials, bounded metadata and filter payloads that can never carry a path/disk/temp path/signed URL/token/file content/report row, one logical action = exactly one event, and no change to any existing authorization, 404 behavior, submission gate or Excel/Word generation. Explicitly out of scope: ordinary report page views, pagination/filtering, 404 attachment lookups, per-row export auditing, backup/restore (9B.6) and the Audit Log UI.

### Result
**The read-only audit answered the five open questions the brief asked, and two answers changed the design.**

1. **View and download are genuinely distinct**, so both are recorded. `routes/web.php` declares `/attachments/{attachment}/{mode}` with `->whereIn('mode', ['view','download'])`, the controller re-validates against the same two literals, and the mode selects `Content-Disposition: inline` versus `attachment`. Nothing infers intent from a browser header. The dependency is documented so the actions collapse to one accurate `accessed` if that segment ever collapses.
2. **Metadata becomes authoritative in exactly one place** — `AttachmentUploadService::store()`, after its `$disk->move()` and its finalizing `update()`. All ten upload call sites (5 Create + 5 Edit pages) already funnel through it, so that is the single audit choke point for `uploaded`/`replaced`. Whether a write is an upload or a replacement is stated by the caller via a new `replacing:` argument, never guessed.
3. **Every one of the fifteen write call sites is already inside a `DB::transaction()`** (verified in all five Create pages, all five Edit pages and all five `Table::delete*()` methods).
4. **Ten export paths across six pages**, all `response()->streamDownload(…)`, none producing a temporary file.
5. **Nothing in this application ever deletes a prior attachment file from disk.** A replacement stores the new file first and soft-deletes the previous **row**; the five workflow delete methods soft-delete the row and explicitly keep the file "for audit". The §4 STOP condition therefore did **not** trigger, and the crash-safe ordering was left untouched.

**New layer (5 source classes).** `app/Services/Audit/Attachments/`: `AttachmentAuditMetadata` (the closed metadata list + the replacement diff; all lookups `withTrashed()` because the workflow delete paths soft-delete the `Transaction` *before* reaching the attachment), `AttachmentAuditRecorder` (Required writes — never opens a transaction, throws `LogicException` below `transactionLevel() >= 1`), `AttachmentAccessAuditRecorder` (Required `viewed`/`downloaded`, BestEffort `security.attachment_access_denied`, physically unable to emit the other class's events). `app/Services/Audit/Reports/`: `ReportExportSubject` (six aliases, each taken from the page's own permission stem), `ReportExportFormat` (`xlsx`/`docx` only — **no export service in this codebase produces CSV or PDF**, so neither was invented), `ReportExportAuditRecorder`.

**A real redaction collision was found by the new tests, not by inspection.** The payload's parent identifier was originally `parent_key`, which `AuditRedactor`'s (correct, global) `key`-segment rule redacted to `[REDACTED]` — erasing the one field linking an attachment event to its parent's `financial` event. Renamed to `parent_id`, following the existing `setting_name` precedent in `AuditSubjectRegistry`, rather than adding a `SAFE_EXCEPTIONS` entry that would weaken the redactor for every future subject.

**Reported rather than fabricated:** the **original client filename does not exist in this application**. No financial form calls `preserveFilenames()`, so Filament stores the upload under a generated name and the browser-supplied one is gone before any application code — including `AttachmentUploadService` — ever sees it. `file_name` is the final deterministic stored name, sanitized through the same `safeDownloadName()` that guards `Content-Disposition`; no `original_file_name` was invented.

**Export semantics: `export_requested`, not `export_completed`.** All nine export services build the document synchronously but serialize inside the `streamDownload` callback, which Symfony invokes only after the response is returned and headers are committed; the writer targets `php://output`, so no temporary file exists to prove success either. There is no point in the current synchronous code where successful generation is objectively known before the response returns, so a completion claim would be unsupported. Documented so a future queued/materialized implementation **adds** a completion event rather than substituting one.

**Duplicate prevention is structural in all four directions the brief listed.** The shared `AuthorizesReportAccess` trait records nothing (only the page-specific export method does, once); nothing is recorded inside an export service or a `streamDownload` callback; uploads/replacements have exactly one origin (`AttachmentUploadService`); and there is no `Attachment` observer and no `Attachment` entry in `AuditSubjectRegistry`, so an Attachment save/delete has no independent audit route. A financial operation carrying a file produces exactly one `financial` event **plus** one `attachment` event — the financial event is never duplicated.

**Nothing was broadened.** `authorizeReportExport()`, every `canExport()`/"عرض" gate, every report permission, all Excel/Word generation, `AttachmentController`'s allowlist and parent-policy delegation, and all of its 404 rules are unchanged apart from the added audit calls. No Audit UI, no `audit.view` permission.

### Changed Files
- **New (6 source):** `app/Services/Audit/Attachments/{AttachmentAuditMetadata,AttachmentAuditRecorder,AttachmentAccessAuditRecorder}.php`, `app/Services/Audit/Reports/{ReportExportSubject,ReportExportFormat,ReportExportAuditRecorder}.php`
- **New (3 test):** `tests/Feature/Audit/Attachments/{AttachmentWriteAuditTest,AttachmentAccessAuditTest}.php`, `tests/Feature/Audit/Reports/ReportExportAuditTest.php`
- **Modified — attachment layer:** `app/Services/Attachments/AttachmentUploadService.php` (recorder injection, new optional `replacing:` parameter, previous-metadata snapshot, one `uploaded`/`replaced` event after `refresh()`), `app/Http/Controllers/Attachments/AttachmentController.php` (recorder injection, `catch (AuthorizationException)` → BestEffort denial + re-throw, Required access event before the response)
- **Modified — five Edit pages** (`EditProjectCostReceipt`, `EditProjectCostBudgetsPayment`, `EditExecutionPayment`, `EditGeneralExpense`, `EditGeneralExchange`): replacement branch passes `replacing:`; removal branch records `deleted` before the soft delete; `storeAttachment()` gained a `?Attachment $replacing` parameter (four of them)
- **Modified — five Tables** (`ProjectCostReceiptsTable`, `ProjectCostBudgetsPaymentsTable`, `ExecutionPaymentsTable`, `GeneralExpensesTable`, `GeneralExchangesTable`): `deleted` recorded before each attachment soft delete, inside the existing transaction
- **Modified — six report pages:** `AccountStatementPage`, `TrialBalancePage`, `DonorFinancialReportPage` (also gained a public `$appliedFilters` snapshot, cleared by `clearResults()`), `ComprehensiveFinancialTransactionsPage`, `ProjectsGeneralFinancialPage`, `ProjectFinancialDetailsPage`
- **Modified — test:** `tests/Feature/Attachments/AttachmentUploadServiceTest.php` (service resolved from the container; the three success-path tests now open a transaction, matching all ten real call sites — failure-path tests deliberately do not, since they never reach the audit call)
- **Unmodified, deliberately:** every Policy, `AuthorizesReportAccess`, `AttachmentStorageService`, `FinancialAttachmentRegistry`, `AttachmentResource` (already create/edit/delete-proof), `AuditSubjectRegistry`, `AuditRedactor`, all nine export services, all five Create pages.

### Verification
Focused suites only, run strictly sequentially (never concurrent, never the full suite):
- New suites: `AttachmentWriteAuditTest` **10 passed / 94 assertions**, `AttachmentAccessAuditTest` **13 passed / 54 assertions**, `ReportExportAuditTest` **22 passed / 139 assertions**.
- Regression — `tests/Feature/Audit` + `tests/Feature/Attachments` + `tests/Feature/Reports`: **529 passed / 2418 assertions, 0 failed**, covering 9B.1–9B.4 plus the Task 6A private-attachment authorization suite and the Task 2B export-authorization/gating suite unchanged.
- Regression — the five financial workflow suites (`ExecutionPayments`, `GeneralExchanges`, `GeneralExpenses`, `ProjectCostBudgetsPayments`, `ProjectCostReceipts`): **70 passed / 285 assertions**.
- Forced-audit-failure (the `audit_events` table dropped so the insert raises a real driver error) proved: a create-with-attachment rolls back the `Attachment` row **and** the `GeneralExpense` row; a private attachment is **not** served (the request does not return 200); and an export throws `AuditPersistenceException` instead of delivering a file. The BestEffort mirror proved a 403 stays a 403 under the same outage. The fail-closed no-transaction case is covered too.
- No-event cases proved: a financial operation without a file, an ordinary report page view, a filter change, an export blocked by the "عرض" gate, an unauthorized export, and every 404 attachment path (unknown id, soft-deleted attachment, missing physical file, unsupported attachable type, guest).
- Real local DB (read-only, no test data created): `audit_events` **0** / attachments 9 (7 trashed) / transactions 8 / transaction_lines 22 / general_expenses 3 / general_exchanges 3 / accounts 5 / users 5, sum of `current_balance` **0.00** — identical before and after every check. Attachment files on the private disk: **8**, unchanged.
- `php artisan oms:check-financial-integrity` → **`Result: OK`, exit 0** (relationships 10/0 orphans, 8 transactions/0 duplicate numbers, 5 checked/0 unbalanced, 0 invalid FX, 0 currency mismatches, 0 balance mismatches).
- HTTP smoke (against the running local vhost): `/admin/login` → **200**; `/attachments/9/view`, `/attachments/9/download`, `/admin/account-statement`, `/admin/trial-balance`, `/admin/donor-financial-report` → **302 → `http://oms.test/admin/login`** while unauthenticated. `audit_events` still **0** afterwards, confirming unauthenticated probing writes nothing.

### Commit Hash
(pending — stopped before commit as instructed)

---

### Date
2026-07-29 (OMS Task 9B.4 — users, roles, permissions & authentication audit)

### Task
Audit every real security write path — User create/update/delete/restore, activation/deactivation, administrator password changes, role assignment/removal/replacement, direct user permissions, role permission management, `oms:sync-permissions`, and login success/failure/logout — under `event_category = security`, with REQUIRED atomicity inside each caller's existing `DB::transaction()` for mutations, BestEffort for authentication, one logical action = exactly one event, stable aliases (never FQCNs), strict credential redaction, and no broadening of any existing authorization rule. Required inspecting the **real** resources/services/auth flow rather than assuming standard Filament paths.

### Result
**The real write paths were already centralised in services**, which is where auditing was wired: `UserManagementService` (createUser / updateUser incl. its `applySelfUpdate()` branch / deleteUser / restoreUser), `RoleManagementService` (createRole / updateRole / deleteRole), and `PermissionSyncService::sync()` (reached identically by `oms:sync-permissions` and the `ListPermissions` `syncPermissions` header action via `PermissionManagementService`). Observers were rejected on the same grounds as 9B.2 and for one additional, decisive reason: `syncRoles()`/`syncPermissions()` write Spatie **pivot** rows that an Eloquent model observer cannot see at all. Each service method already owned a `DB::transaction()` spanning the model save, the pivot sync and the last-active-Super-Admin `lockForUpdate()`, so the REQUIRED audit insert simply joined it.

New layer `app/Services/Audit/Security/` (5 classes): `SecurityAuditSubject` (5 aliases — `user`, `role`, `permission`, `authentication`, `permission_sync`), `SecurityNameDiff` (deterministic sorted/de-duplicated/re-indexed before-after-added-removed name diffs), `UserSecuritySnapshot` (closed six-field allowlist), `SecurityAuditRecorder` (the REQUIRED gateway — never opens a transaction, throws `LogicException` below `transactionLevel() >= 1`), and `AuthenticationAuditRecorder` (the BestEffort gateway, physically unable to emit a Required event). Plus `app/Listeners/Auth/AuthenticationAuditSubscriber`, registered once in `AppServiceProvider::boot()`.

**Two real defects were found and fixed during the build, both caught by the new tests rather than assumed away.** (1) **Duplicate listener registration** — Laravel's framework-level `EventServiceProvider` auto-discovers public `handle*`/`__invoke` methods under `app/Listeners` and registers them *in addition to* an explicit `Event::subscribe()` mapping. With `handleLogin`/`handleLogout` names, every login and every logout wrote **two identical audit rows** (confirmed by dumping `Event::getRawListeners()`, which showed both `["Class","method"]` and `"Class@method"` registered). Methods were renamed to `onAttempting`/`onLogin`/`onFailed`/`onLogout`, and a regression test now asserts exactly one registered listener per auth event. (2) **Duplicate `Failed` dispatch** — when credentials are valid but `canAccessPanel()` denies entry, `Illuminate\Auth\SessionGuard::attemptWhen()` fires `Failed` when its callback returns false and `Filament\Auth\Pages\Login::authenticate()` (v5.6.7, lines 151-160) fires it again before throwing. De-duplication is scoped to one **attempt**, opened by the `Attempting` event — which is dispatched exactly once at the start of every attempt and never between the duplicate pair — rather than to a request or a time window, so two genuine attempts in one request still produce two events. This required binding `AuthenticationAuditRecorder` as a container **singleton**, because `Dispatcher::subscribe()` registers handlers as `[Class, 'method']` and therefore re-resolves the subscriber on every dispatched event.

**Findings reported rather than worked around.** There is **no direct-user-permission write path** in this application: `UserForm` exposes roles only, and nothing in `app/` calls `givePermissionTo()`/`revokePermissionTo()`/`syncPermissions()` on a `User` (verified across the whole tree). The snapshot, diff and single-event payload for direct permissions are implemented and tested at the recorder level (`SecurityAuditPayloadPolicyTest`) so such a path is audited correctly the moment one is added — but none was invented, since that would mean creating a privilege-granting surface the application does not currently have. Likewise there is **no password-reset flow**: `AdminPanelProvider` calls `->login()` but never `->passwordReset()`, so no `PasswordReset` event was wired; an administrator resetting a user's password goes through `UserManagementService` and is covered by the single `user`/`updated` event.

`AuditRedactor::SAFE_EXCEPTIONS` gained exactly one entry, `password_changed` — the boolean flag that exists precisely so the credential never has to be represented; without it the global `password` segment rule would have redacted the one safe fact while protecting nothing. The bare field name `password` was **not** excepted and stays redacted for every subject. `AuditActorResolver` gained `forUser()`/`forGuest()` for the two authentication cases where `Auth::user()` is unreliable at dispatch time (`Login` fires before `setUser()`; `Logout` fires after the session is cleared).

**No authorization was broadened.** `Gate::before`, `UserPolicy` (including its permanently-false `forceDelete`), `RolePolicy`, `PermissionPolicy`, `RoleResource::canEdit()/canDelete()`, `canAccessPanel()` and every service safety rule are byte-for-byte unchanged. A rejected action writes no event, because the rejection happens inside the same transaction the event would have been written in — asserted for system-role edits, assigned-role deletions and the last-active-Super-Admin guard. No `audit.view` permission and no Audit UI were created.

### Changed Files
- **New (6 source):** `app/Services/Audit/Security/{SecurityAuditSubject,SecurityNameDiff,UserSecuritySnapshot,SecurityAuditRecorder,AuthenticationAuditRecorder}.php`, `app/Listeners/Auth/AuthenticationAuditSubscriber.php`
- **New (6 test):** `tests/Feature/Audit/Security/{SecurityAuditTestCase,UserSecurityAuditTest,RoleSecurityAuditTest,PermissionSyncAuditTest,AuthenticationAuditTest,SecurityAuditAtomicityTest,SecurityAuditPayloadPolicyTest}.php`
- **Modified — security services:** `app/Services/Users/UserManagementService.php` (constructor injection + 5 audit call sites incl. the self-update branch), `app/Services/Roles/RoleManagementService.php` (constructor + 3 call sites, pre-delete snapshot), `app/Services/Permissions/PermissionSyncService.php` (constructor + `recordAudit()` inside the existing transaction; `superAdminUserCount()` now computed once instead of twice)
- **Modified — audit layer:** `app/Services/Audit/AuditActorResolver.php` (`forUser()`/`forGuest()`), `app/Services/Audit/AuditRedactor.php` (`password_changed` safe exception)
- **Modified — wiring:** `app/Providers/AppServiceProvider.php` (`Event::subscribe(AuthenticationAuditSubscriber::class)` + `AuthenticationAuditRecorder` singleton)
- **Unmodified, deliberately:** every Policy, `AuditSubjectRegistry`, `UserResource`/`RoleResource`/`PermissionResource` and all their pages/forms/tables, `PermissionManagementService`, `SyncPermissions` command, `PermissionRegistry`.

### Verification
Focused suites only, run strictly sequentially (never concurrent, never the full suite):
- New security-audit suites: **81 passed / 388 assertions** (`UserSecurityAuditTest` 19, `SecurityAuditPayloadPolicyTest` 12, `AuthenticationAuditTest` 19, `RoleSecurityAuditTest` + `SecurityAuditAtomicityTest` 21, `PermissionSyncAuditTest` 9, and 1 shared case).
- Regression — `tests/Feature/Users` + `tests/Feature/Roles` + `tests/Feature/Permissions`: **493 tests, 490 passed, 3 skipped, 0 failed** (the 3 skips are pre-existing data-driven skips in `CrudPolicyBehaviorTest`/`ResourceHttpAuthorizationTest` for non-SoftDeletes models and the read-only audit resource).
- Regression — `tests/Feature/Audit` + `tests/Feature/Crud`: **318 passed / 1741 assertions**, confirming 9B.1/9B.2/9B.3 and the create→View / edit→View / delete→List redirect standard are untouched.
- Forced-audit-failure (the `audit_events` table dropped so the insert raises a real driver error) proved rollback of: user creation incl. its role pivots; user update incl. rename, deactivation, password and role replacement; user soft delete; user restore; role creation incl. its permission pivots; role rename + permission replacement; role deletion; and the entire permission-sync run. The mirror case (an `AuditEvent` never surviving a caller's rolled-back transaction) and the fail-closed no-transaction case are also covered.
- Real local DB (read-only, no test data created): users 5 (with trashed) / roles 7 / permissions 186 / `model_has_roles` 4 / `model_has_permissions` 0 / `role_has_permissions` 599 / `audit_events` **0** — identical before and after every check.
- `php artisan oms:check-financial-integrity` → **`Result: OK`, exit 0**.
- HTTP smoke (temporary `artisan serve` on port 8391, then stopped): `/admin/login` → **200**; `/admin/users`, `/admin/roles`, `/admin/permissions`, `/admin/users/create` → **302 → `/admin/login`** while unauthenticated. `audit_events` still 0 afterwards, confirming ordinary browsing writes nothing.

### Commit Hash
(pending — stopped before commit as instructed)

---

### Date
2026-07-29 (OMS Task 9B.3 — financial audit integration)

### Task
Integrate REQUIRED-mode auditing for the five financial workflows (Project Cost Receipt / Project disbursement / Execution Payment / General Expense / General Exchange) and the four financial master-data models (Account, AccountType, Currency, ExchangeRateHistory), under one governing rule: **one logical financial action = exactly one `AuditEvent`**, with no separate `Transaction`/`TransactionLine` auditing, the source event carrying the resulting transaction identifiers, and the audit insert running inside each workflow's own existing `DB::transaction()` so a failed audit rolls the entire financial operation back. Required auditing the **actual write paths**, not model names taken from documentation.

### Result
**The real mapping was materially different from the documentation, in exactly the way the brief anticipated.** `OMS_Master_Reference.md` stated that "صرف مبلغ المشروع" is model `ProjectCostBudgetsPayment` and that "صرف مبالغ التنفيذ" is model `ExecutionPayment`. Read off the resource classes, the truth is the inverse: `ProjectCostBudgetsPaymentResource` (slug `project-cost-budgets-disbursements`) writes **`ProjectCostBudget`**, `ExecutionPaymentResource` (slug `execution-payments`) writes **`ProjectCostBudgetsPayment`**, and **no `ExecutionPayment` model class exists at all**. The Master Reference was corrected, with a warning table added. This is the direct justification for keying `subject_type` on a workflow alias rather than an FQCN.

New layer `app/Services/Audit/Financial/` (7 classes): `FinancialAuditSubject` (the five stable aliases), `FinancialAccountRole` (closed role vocabulary — debit/credit/source/destination/admin/transfer/beneficiary), `FinancialAuditValue` (decimal-string/date/id/bounded-text normalizers), `FinancialAuditLabeller` (memoized, `withTrashed()`, column-limited label lookups), `FinancialAuditFieldNames` (the `_id` ↔ `_label`/`_code` satellite contract plus the context-field list), `FinancialAuditSnapshotter` (one bounded-payload builder per workflow), `FinancialAuditDiff` (context / satellite / business-field rules), and `FinancialAuditRecorder` (the single `event_category = financial` write gateway).

`FinancialAuditRecorder` **never opens a transaction** and asserts `DB::transactionLevel() >= 1`, throwing `LogicException` otherwise — the structural guarantee that an audit row can never survive a rolled-back financial mutation. All 15 financial call sites (5 create pages, 5 edit pages, 5 static table delete methods) record from inside the existing `DB::transaction()`, before the success `Notification` so a rollback is never reported as success. Each workflow's delete is a single static method shared by the Edit header action, the table row action and the table bulk action, so one deletion produces one event from any entry point.

Financial master data went through the existing 9B.2 CRUD architecture (`event_category = crud`) with financial value policies (`accounts.current_balance` and `exchange_rate_histories.rate` stored as decimal strings). `HasUserTracking` was activated on `Account` and `AccountType` — forward-only, **no backfill**. `Account` also gained `protected $attributes = ['current_balance' => 0]` mirroring the column default, because `AccountForm` never submits that field (`disabled()->dehydrated(false)`), which would otherwise have recorded a NULL balance on create while the stored row held 0.00. Account creation *with* an opening balance produces exactly one `account` event carrying `opening_transaction_id`/`opening_transaction_number`/`opening_balance`/`opening_balance_date`/`opening_balance_fx_rate` — the opening `Transaction`, its two lines, the auto-created clearing `Account` and the auto-created lookup rows are deliberately **not** separately audited; `AuditedCrudService::recordCreatedWithin()` was added for that one case and fails closed outside an open transaction.

Three pre-existing test suites needed migration-list updates (not behavior changes) because activating `HasUserTracking` means `Account`/`AccountType` inserts now write `created_by`/`updated_by`: `BackfillTransactionDescriptionsCommandTest`, `TransactionDescriptionBuilderTest`, `TransactionLineDescriptionBuilderTest` each hand-pick migrations and were missing `users` plus the two Task 8.2/8.3 reconciliation migrations. `AuditCrudInfrastructureTest` was updated to expect the four new aliases and now pins `Transaction`/`TransactionLine` (rather than `Account`) as the models that must stay permanently unregistered.

### Changed Files
- **New (7 source):** `app/Services/Audit/Financial/{FinancialAuditSubject,FinancialAccountRole,FinancialAuditValue,FinancialAuditLabeller,FinancialAuditFieldNames,FinancialAuditSnapshotter,FinancialAuditDiff,FinancialAuditRecorder}.php`
- **New (8 test):** `tests/Feature/Audit/Financial/{FinancialAuditTestCase,ProjectCostReceiptAuditTest,ProjectDisbursementAuditTest,ExecutionPaymentAuditTest,GeneralExpenseAuditTest,GeneralExchangeAuditTest,FinancialAuditAtomicityTest,FinancialMasterDataAuditTest,FinancialAuditRegressionTest}.php`
- **Modified — financial workflows (15 call sites across 15 files):** the 5 `Create*`/5 `Edit*` pages and the 5 `*Table` static delete methods for ProjectCostReceipts, ProjectCostBudgetsPayments, ExecutionPayments, GeneralExpenses, GeneralExchanges.
- **Modified — audit layer:** `app/Services/Audit/Crud/AuditSubjectRegistry.php` (4 new definitions + docblock), `app/Services/Audit/Crud/AuditedCrudService.php` (`recordCreatedWithin()`).
- **Modified — models:** `app/Models/Account.php`, `app/Models/AccountType.php`.
- **Modified — financial master-data Filament wiring:** Accounts (Create/Edit/Table), AccountTypes (Create/Edit/Table), Currencies (Create/Edit/Table + `ExchangeRateHistoryRelationManager`), ExchangeRateHistories (Create/Edit/Table).
- **Modified — tests:** `tests/Feature/Audit/Crud/AuditCrudInfrastructureTest.php`, `tests/Feature/Commands/BackfillTransactionDescriptionsCommandTest.php`, `tests/Unit/Services/TransactionDescriptionBuilderTest.php`, `tests/Unit/Services/TransactionLineDescriptionBuilderTest.php`.
- **No migration, no schema change.**

### Verification
Focused suites only, run strictly sequentially (never concurrently):
- `tests/Feature/Audit` — **150 passed, 1089 assertions**.
- `tests/Feature/{ProjectCostReceipts,ProjectCostBudgetsPayments,ExecutionPayments,GeneralExpenses,GeneralExchanges}` — **70 passed, 285 assertions**.
- `tests/Unit/Services/Transaction{,Line}DescriptionBuilderTest` + `tests/Feature/{Crud,Integrity,Commands}` — **183 passed, 524 assertions**.
- `tests/Feature/{Permissions,Reports}` — **410 passed, 3 skipped, 1949 assertions**.

Forced-audit-failure rollback (audit table dropped mid-flight) proved, for the receipt workflow: create rolls back the receipt + transaction + all lines + both balances; edit restores the previous lines and balances exactly and leaves the replacement account untouched; delete leaves the record, the transaction, the lines and the balances completely unchanged; a surrounding business failure discards the event; and no transaction is ever left open. The same proof was run for the Account opening-balance path (both accounts and the opening entry roll back).

**Real local database: NOT VERIFIED — the MySQL80 Windows service is Stopped and starting it requires elevation this session does not have.** `oms:check-financial-integrity`, the `audit_events`/financial row-count and balance comparison, and the `/admin/login` + financial-page smoke checks are therefore all outstanding and must be run before commit. No write of any kind was attempted against the real database.

### Commit Hash
(not committed — pending review)

---

### Date
2026-07-27 (OMS Task 7C.8 acceptance pass — attachment-test retry-margin fix, real Laravel maintenance-mode test, stale-acknowledgment dual-gate proof, request-serialization proof, eligibility fresh-read fix, live browser walkthrough)

### Task
A focused, pre-commit acceptance pass on the just-implemented Task 7C.8, required before any commit: (1) reproduce and stabilize the one default-order regression failure with a genuine zero-failure bar (10x/class/related-classes/default/2-randomized, all must be 0 failed/0 errors); (2) prove the DB-independent progress endpoint survives the REAL Laravel maintenance mechanism, not a fake; (3) prove stale acknowledgment terminalizes both the DB and signed-progress gates with focused tests; (4) prove `RestoreRequestService`'s check-then-create critical section is genuinely serialized against a true concurrent race, not merely sequential calls; (5) confirm/harden every server-side restore-eligibility recheck at execution time; (6) perform a live local browser walkthrough; (7) correct a docs error describing 7C.9 as Hostinger deployment; (8) confirm graphify-out has no stray changes; (9) final targeted+regression verification, then commit implementation and graphify separately.

### Result
**Root cause found and fixed**: `RestoreAttachmentActivationServiceTest::service()`'s default `NativeAttachmentMoveRunner` used `maxAttempts: 1, retryDelayMs: 0` (zero retry margin) versus production's config-driven default of 5 attempts — raised to `maxAttempts: 5` (retryDelayMs stays 0, zero cost). **A separate, genuine testing-process mistake was found and corrected**: a background full-suite run was still executing while foreground `php artisan test` commands were also run, and this codebase's `Storage::fake()` disks use fixed, non-per-process paths — two concurrent PHPUnit processes genuinely corrupted each other's fake filesystem/lock state, fully explaining a second wave of seemingly-unrelated failures across `BackupManagementPageTest`/`RestoreWatchdogCommandTest`/`RestoreArchivePreparerTest`/`RestoreOrchestratorHeartbeatTest`/`RestoreStaleDetectorTest`/`BackupNotificationTest`. No production defect — corrected by never running test processes concurrently for the remainder of the pass. New tests: `RestoreProgressPollControllerTest::test_real_laravel_maintenance_mode_blocks_normal_routes_but_not_the_signed_poll_route` (real `Artisan::call('down')`/`FileBasedMaintenanceMode`, with a hard pre-check + `finally` + `tearDown()` double-safety-net cleanup of the real `storage/framework/down` flag file); 5 new `RestoreStaleAcknowledgmentServiceTest` dual-gate tests; `RestoreRequestServiceTest::test_two_truly_concurrent_requests_never_create_two_rows` (genuine lock contention, not sequential calls) plus 2 fresh-read eligibility tests. One real (small) production fix: `RestoreRequestService::createQueuedRestore()` now also re-checks scope compatibility against the freshly-fetched source record inside the locked section (previously only checked the caller's possibly-stale in-memory instance). Live browser walkthrough performed against the real Laragon instance with a throwaway Super Admin + throwaway backup fixture (both created via tinker, both fully deleted afterward, verified via before/after counts) — confirmed the full Step 1 → wrong-phrase-rejected → correct-phrase → Step 2 → cancel flow renders and behaves correctly in Arabic/RTL, with zero restore rows created after cancellation. `docs/NEXT_STEPS.md`'s two 7C.9 descriptions corrected from "Hostinger deployment" to "real local end-to-end restore testing on Windows/Laragon." `graphify-out` confirmed to have zero changes.

### Changed Files
- Modified: `app/Services/Restore/RestoreRequestService.php` (fresh-read scope re-check); `tests/Feature/Restore/Attachments/RestoreAttachmentActivationServiceTest.php` (retry-margin fix); `tests/Feature/Restore/RestoreProgressPollControllerTest.php` (real maintenance-mode test + `tearDown()`); `tests/Feature/Restore/RestoreStaleAcknowledgmentServiceTest.php` (5 new dual-gate tests); `tests/Feature/Restore/RestoreRequestServiceTest.php` (3 new tests: true concurrency + 2 fresh-read); `docs/NEXT_STEPS.md` (7C.9 scope correction, ×2)
- No migration, no schema change.

### Verification
1. Section 1 (attachment fix): specific test 10/10 clean; whole class 29/29; 9 directly-interacting attachment-state classes together 114/112 passed/2 pre-existing skips/0 failed.
2. Targeted 7C.8+attachment-fix suite (strictly sequential, zero concurrent test processes): **162 tests, 162 passed, 535 assertions**.
3. Broader `Restore|Backup` regression, default order: **854 tests, 851 passed, 0 failed, 0 errors, 3 skipped, 2207 assertions** — clean.
4. `--order-by=random --random-order-seed=111222`: **854/851/0 failed/3 skipped** — clean.
5. `--order-by=random --random-order-seed=998877`: **854/851/0 failed/3 skipped** — clean.
6. `php -l` clean on every changed/new PHP file. `git diff --check` clean.
7. Live browser walkthrough performed and cleaned up (see `docs/AI_PROJECT_MEMORY.md` for exactly what was verified).
8. `git status --short` confirms exactly the files listed above changed, plus the original 7C.8 file set from the entry above; `graphify-out/` untouched.

### Commit Hash

---

### Date
2026-07-27 (OMS Task 7C.8 — Filament restore UI: two-step confirmation, DB-independent signed progress polling, stale/tampered banners, explicit crash acknowledgment)

### Task
Expose the already-verified restore engine (7C.1–7C.7) to the Super Admin via `BackupManagementPage`: a row action with a two-step (typed `RESTORE {uuid8}`, then a final danger-styled) confirmation that creates a queued restore and launches it through the existing replay-safe `RestoreLaunchService`; a DB-independent signed progress-polling endpoint reachable during maintenance mode and a database outage; restore rows correctly labeled in the history table; a stale/crashed-restore banner with an explicit Super-Admin-only acknowledgment action; and a tampered-progress manual-review message. No restore engine architecture changes except one narrow, additive schema extension called out explicitly below.

### Result
New: `App\Services\Restore\RestoreRequestService` (the only place a queued restore row is created — re-validates source eligibility/scope compatibility/activity state under `BackupSubsystemLock::acquireExclusive()`, inside one `DB::transaction()`); `App\Services\Restore\RestoreStaleAcknowledgmentService` (terminalizes a genuinely stale restore's signed progress file + DB row after human review — eligibility requires the exact `stale_heartbeat` detector reason AND that the exclusive lock is currently free; writes `restore_failed_phase = crashed_acknowledged`); `App\Http\Controllers\Restores\RestoreProgressPollController` (`GET /restores/{uuid}/progress`, `signed`-middleware-only, zero DB queries, reads only the signed `progress.json`); `App\Support\Restore\RestoreScopeCompatibility` and `RestorePhaseLabels` (Arabic labels for the REAL `RestoreProgressSnapshot::ALLOWED_PHASES` vocabulary, confirmed from `RestoreOrchestrator`/`RestoreCommand` directly — several phase names in the task brief do not actually exist in this codebase and were not used). `RestoreProgressSnapshot::ALLOWED_PHASES` gained `crashed_acknowledged` (a `restore_failed_phase`-only value). `BackupManagementPage` gained: `restoreAction()` (a single Filament Wizard-based action, two steps, `dehydrated(false)` typed-confirmation field so the phrase never reaches persisted state), `staleAcknowledgmentAction()` (same two-step shape), `resolvedRestoreActivityState()` (per-render memoization fixing a real N+1 caught during verification), and read-model methods (`activeRestoreViewData()`, `staleRestoreViewData()`, `restoreTamperedState()`) the Blade view uses to render an Alpine-driven live-progress widget (fetches the DB-independent polling endpoint directly, never through Livewire) plus the stale/tampered banners. `routes/web.php` gained the one new GET route, stripped of every DB-touching `web`-group middleware member. `bootstrap/app.php` gained one exact-path `preventRequestsDuringMaintenance()` exception. `config/oms.php` gained `progress_poll_url_ttl_hours`.

### Changed Files
- Created: `app/Services/Restore/RestoreRequestService.php`; `app/Services/Restore/RestoreStaleAcknowledgmentService.php`; `app/Services/Restore/Exceptions/RestoreRequestRejectedException.php`; `app/Services/Restore/Exceptions/RestoreStaleAcknowledgmentException.php`; `app/Http/Controllers/Restores/RestoreProgressPollController.php`; `app/Support/Restore/RestoreScopeCompatibility.php`; `app/Support/Restore/RestorePhaseLabels.php`; `tests/Feature/Restore/RestoreRequestServiceTest.php`; `tests/Feature/Restore/RestoreStaleAcknowledgmentServiceTest.php`; `tests/Feature/Restore/RestoreProgressPollControllerTest.php`; `tests/Feature/Restore/RestoreManagementUiTest.php`
- Modified: `app/Filament/Pages/BackupManagementPage.php`; `resources/views/filament/pages/backup-management-page.blade.php`; `app/Services/Restore/RestoreProgressSnapshot.php` (`crashed_acknowledged` added to `ALLOWED_PHASES`); `routes/web.php`; `bootstrap/app.php`; `config/oms.php`; `tests/Feature/Backup/BackupManagementPageTest.php` (4 obsolete "restore not implemented" guard tests updated to assert the new real behavior; query-count threshold raised 20→35 with the N+1 fix documented); `docs/RESTORE_RECOVERY.md` (new §1a/§1b)
- No migration, no schema change beyond the additive `ALLOWED_PHASES` value (not a DB column).

### Verification
1. Targeted 7C.8 suite: **124 tests, 124 passed, 387 assertions** (`RestoreProgressPollControllerTest` 11, `RestoreRequestServiceTest` 12, `RestoreStaleAcknowledgmentServiceTest` 12, `RestoreManagementUiTest` 25, `BackupManagementPageTest` 64).
2. Broader `Restore|Backup` regression, default order: **845 tests, 841 passed, 1 error, 3 skipped, 2158 assertions**. The 1 error (`RestoreAttachmentActivationServiceTest::test_rollback_rejects_a_shared_handle`, a real filesystem `rename()`-during-quarantine call this task's code never touches) passed 29/29 immediately afterward in isolation and did not reproduce in either randomized run below — this codebase's already-documented class of transient Windows/Laragon rename contention (see the 2026-07-27 hardening-pass entry above), not a regression this task introduced.
3. `--order-by=random`: **845 tests, 842 passed, 0 failed, 3 skipped** — clean.
4. `--order-by=random --random-order-seed=424242`: **845 tests, 842 passed, 0 failed, 3 skipped** — clean.
5. `php -l` clean on every changed/new PHP file. `git diff --check` clean.
6. `git status --short` confirms exactly the files listed above changed; no unrelated file touched.
7. Not committed — `graphify update .` not yet run, per instructions to stop before committing.

### Commit Hash

---

### Date
2026-07-26 (OMS Task 7C.5 correction pass — durable reconciliation snapshots, full backups-queue purge, single-connection policy, metadata completeness, stream-capture re-verification)

### Task
Six-point focused correction pass on the just-implemented Task 7C.5, approved in principle but not yet committed: (1) extend the signed `RestoreProgressSnapshot` protocol with an optional, bounded reconciliation-snapshot section so the three authoritative `backup_operations` rows can be reconstructed from the progress file alone if the process crashes after the database import and before metadata reconstruction; (2) correct the backups-queue cleanup policy to delete ALL rows on that queue (not just unreserved ones), since a reserved-but-unfinished row could otherwise become redeliverable and replay pre-restore transport work; (3) close the ambiguous-connection risk between `DatabaseRestorer`'s import target and `migrate`/Eloquent/`DB::table()`'s implicit target; (4) review metadata reconstruction completeness against the actual `backup_operations` schema (deleted_at, launch_nonce, manifest_version, dangling-FK safety); (5) re-verify (not merely re-assert) that `SymfonyProcessStreamInputRunner`'s stderr capture is genuine despite `disableOutput()`, and prove bounded memory/no-deadlock behavior for large stdout/stderr; (6) re-run only directly affected tests, then commit.

### Result
(1) New `App\Services\Restore\Metadata\RestoreReconciliationSnapshot` bundles the existing `BackupOperationSnapshot`×2 + `RestoreOperationSnapshot`, each gaining bounded, fixed-key-order `toArray()`/`fromArray()` (exact-key-set validation, re-runs every field through the existing `create()` validation — never trusts a decoded array's shape). `RestoreProgressSnapshot` gained an optional, nullable `reconciliationSnapshot` constructor parameter (default `null` — every existing call site is unaffected) serialized as `reconciliation_snapshot` in `toCanonicalArray()`; `RestoreProgressReader::parse()` reconstructs it via `RestoreReconciliationSnapshot::fromArray()` when present, treating an absent key exactly like an explicit `null` — no `schema_version` bump needed, and no change to signature verification (which already re-serializes the raw decoded body, never a value rebuilt from this class's current shape). A new integration test proves the actual point: write a reconciliation snapshot into progress.json, drop `backup_operations` entirely, re-migrate an EMPTY table (simulating the exact post-import state), read the progress file back, and successfully reconstruct all three authoritative rows via `RestoreMetadataUpserter` — using only the signed file, never the (by-then-empty) database. (2) `RestoreEphemeralTablePolicy::clearBackupsQueueJobs()` (renamed from `clearBackupsQueuePendingJobs()`) now deletes every row on the exact configured backups queue regardless of `reserved_at` — reserved and delayed jobs included; every other queue is still completely untouched. (3) `DatabaseRestorer::resolveConnectionConfig()` now fails closed with a new `database_connection_mismatch` reason the moment the resolved backup connection name differs from `config('database.default')`, before the mysql client path is even resolved; `RestoreReconciler::reconcile()` no longer accepts a caller-supplied connection name at all — it always resets `config('database.default')` directly, the exact same connection `migrate`/every Eloquent model/every `DB::table()` call in the metadata/ephemeral steps already implicitly target. Chosen policy: **restore is supported only for the application's primary/default database connection** (the "Preferred" option) — `oms.backup.database_connection` remains a backup-creation-side-only override; restoring into it would let `mysql` import one physical database while every other reconciliation step operated on a different one. (4) `BackupOperationSnapshot` gained a `manifestVersion` field (bounded 0–65535, matching the `unsignedSmallInteger` column) now persisted on every source/safety upsert — the one genuinely missing authoritative column found on review (`deduplication_key` was deliberately NOT added — a forward-looking scheduling-concurrency guard, not backward-facing audit data, and restoring it risks a real unique-constraint collision against post-restore scheduling activity). New focused assertions confirm `deleted_at` is cleared for a previously-trashed source AND restore row, the safety backup is forced `Completed` AND stamped `verified_at` (not just `Completed`), and both FK targets on a reconstructed restore row resolve to real, currently-existing rows (not merely equal numbers). (5) Re-verified directly against `Process::buildCallback()`'s source that `disableOutput()` only skips Process's OWN internal stdout/stderr buffering — the callback passed to `run()` is still invoked with every chunk from both pipes, which is also what prevents a full-pipe-buffer deadlock; two new tests prove this empirically: 30MB of child STDOUT produces no meaningful growth in this process's own memory (nothing is ever buffered), and 40 rounds of interleaved 200KB STDOUT+STDERR writes complete well within a 15s timeout (no deadlock). The non-zero-exit test was also strengthened to assert non-empty, genuinely useful diagnostic content, not merely a length bound.

### Changed Files
- Created: `app/Services/Restore/Metadata/RestoreReconciliationSnapshot.php`; `tests/Unit/Services/Restore/Metadata/RestoreReconciliationSnapshotTest.php`; `tests/Feature/Restore/RestoreReconciliationSnapshotRecoveryTest.php`
- Modified: `app/Services/Restore/RestoreProgressSnapshot.php` (optional `reconciliationSnapshot` field); `app/Services/Restore/RestoreProgressReader.php` (parses the new optional field); `app/Services/Restore/Metadata/{BackupOperationSnapshot,RestoreOperationSnapshot}.php` (`toArray()`/`fromArray()`; `BackupOperationSnapshot` also gained `manifestVersion`); `app/Services/Restore/RestoreMetadataUpserter.php` (persists `manifest_version`); `app/Services/Restore/RestoreEphemeralTablePolicy.php` (full backups-queue purge); `app/Services/Restore/DatabaseRestorer.php` (connection-mismatch guard); `app/Services/Restore/RestoreReconciler.php` (no caller-supplied connection name); `app/Services/Restore/Exceptions/RestoreDatabaseException.php` (`databaseConnectionMismatch()`); `app/Services/Restore/SymfonyProcessStreamInputRunner.php` (docblock clarification only — behavior was already correct); `tests/Feature/Restore/{RestoreEphemeralTablePolicyTest,DatabaseRestorerTest,RestoreReconcilerTest,RestoreMetadataUpserterTest,RestoreProgressWriterReaderTest}.php`; `tests/Unit/Services/Restore/{RestoreProgressSnapshotTest,SymfonyProcessStreamInputRunnerIntegrationTest,Metadata/BackupOperationSnapshotTest,Metadata/RestoreOperationSnapshotTest}.php`
- No migration, no schema change.

### Verification
1. Every new/corrected test file passes individually (see totals below).
2. Targeted (`tests/Feature/Restore` + `tests/Unit/Services/Restore` + `tests/Feature/Backup` + `tests/Unit/Services/Backup` + `tests/Feature/Console/RestoreCommandTest.php`) → **586 total, 584 passed, 0 failed, 2 skipped (pre-existing 7B.1 OS-level symlink tests, unrelated), 1359 assertions**.
3. `php -l` clean on every changed/new PHP file. `git diff --check` clean (staged temporarily to check the new untracked files, then unstaged again).
4. No real `mysql`/`mysqldump`/real database purge/real Artisan command run outside their own dedicated real-behavior test files; every cross-cutting order/failure test still uses fakes.
5. `git status --short` confirms exactly the files listed above changed.

### Commit Hash
See below.

---

### Date
2026-07-25 (OMS Task 7C.5 — streamed database restoration and post-import reconciliation services, NOT wired to any command/orchestrator)

### Task
Implement Phase 7C.5 only: the database-import and post-import-reconciliation *services* — a streamed-stdin process seam, `DatabaseRestorer` (real `mysql` import argv/env/failure handling), a post-import Laravel DB-connection purge/reconnect step, and `RestoreReconciler` (the exact required order: connection reset → `migrate --force` → `oms:sync-permissions` → `permission:cache-reset` → metadata reconstruction → selective ephemeral-table cleanup → `queue:restart`), plus the bounded metadata snapshot value objects and upsert logic the reconciler's metadata step needs. Explicitly NOT connected to `RestoreCommand`, `RestoreLaunchService`, or any orchestrator — no maintenance mode, no safety-backup orchestration, no attachment swap, no `RestoreOrchestrator`, no migration/schema change.

### Result
See `docs/AI_PROJECT_MEMORY.md` (2026-07-25 "Task 7C.5" entry) for full design/reasoning. Summary: `App\Services\Restore\Contracts\ProcessStreamInputRunner` + `SymfonyProcessStreamInputRunner` stream a staged SQL dump into a child process's stdin without ever loading it into a PHP string (verified against the installed symfony/process 7.4.13 source — a `resource` passed to `setInput()` is never coerced to a string). `DatabaseRestorer` re-validates scope/staged-dump-safety/mysql-client/connection-completeness immediately before building a plain mysql argv array (`--host=`/`--port=`/`--user=`/`--default-character-set=utf8mb4`/database — no `--password`, no shell string), puts the password only in the `MYSQL_PWD` process environment when non-empty, and wraps every failure mode in a sanitized `RestoreDatabaseException`. `LaravelRestoreDatabaseConnectionResetter` purges the possibly-stale pre-import PDO and proves the fresh connection usable via a real `select 1`. `RestoreReconciler` runs its 7 required steps via four interface-typed collaborators (`RestoreDatabaseConnectionResetter`, new `ArtisanCommandRunner`, new `RestoreMetadataReconstructor`, new `RestoreEphemeralTableCleaner`), each step throwing a distinct sanitized `RestoreReconciliationException` reasonCode on failure — nothing after a failed step runs, and it is deliberately not one outer `DB::transaction()`. `BackupOperationSnapshot`/`RestoreOperationSnapshot` (new, under `App\Services\Restore\Metadata`) are bounded, `create()`-validated value objects with no arbitrary-array constructor and no field for a password/key/nonce/confirmation phrase; `RestoreMetadataUpserter` upserts source → safety → restore in that order (each its own small transaction), resolving FKs by UUID (never a trusted old numeric ID) and `created_by` against the freshly-migrated `users` table (present → preserved, absent → `null` + identity snapshot in `restore_metadata`). `RestoreEphemeralTablePolicy` removes only non-reserved `jobs` rows on the exact configured backups queue, preserves `failed_jobs`/`job_batches`/`notifications`/every other queue, and truncates `cache`/`cache_locks`/`sessions` only when the configured table exists.

### Changed Files
- Created: `app/Services/Restore/{SymfonyProcessStreamInputRunner,DatabaseRestorer,LaravelRestoreDatabaseConnectionResetter,LaravelArtisanCommandRunner,RestoreMetadataUpserter,RestoreEphemeralTablePolicy,RestoreReconciler}.php`; `app/Services/Restore/Contracts/{ProcessStreamInputRunner,RestoreDatabaseConnectionResetter,ArtisanCommandRunner,RestoreMetadataReconstructor,RestoreEphemeralTableCleaner}.php`; `app/Services/Restore/Exceptions/{RestoreDatabaseException,RestoreMetadataReconciliationException,RestoreReconciliationException}.php`; `app/Services/Restore/Metadata/{BackupOperationSnapshot,RestoreOperationSnapshot}.php`; `tests/Support/Restore/{RestoreReconciliationOrderLog,FakeArtisanCommandRunner,FakeRestoreDatabaseConnectionResetter,FakeRestoreMetadataReconstructor,FakeRestoreEphemeralTableCleaner,FakeProcessStreamInputRunner}.php`; `tests/Unit/Services/Restore/SymfonyProcessStreamInputRunnerIntegrationTest.php`; `tests/Unit/Services/Restore/Metadata/{BackupOperationSnapshotTest,RestoreOperationSnapshotTest}.php`; `tests/Feature/Restore/{DatabaseRestorerTest,LaravelRestoreDatabaseConnectionResetterTest,RestoreReconcilerTest,RestoreMetadataUpserterTest,RestoreEphemeralTablePolicyTest}.php`
- Modified: `app/Providers/AppServiceProvider.php` (container bindings for the 5 new interfaces — nothing currently resolves `DatabaseRestorer`/`RestoreReconciler` anywhere)
- No migration, no schema change.

### Verification
1. Every new test file passes individually (see totals below).
2. Targeted (`tests/Feature/Restore` + `tests/Unit/Services/Restore` + `tests/Feature/Backup` + `tests/Unit/Services/Backup` + `tests/Feature/Console/RestoreCommandTest.php`) → **560 total, 558 passed, 0 failed, 2 skipped (pre-existing 7B.1 OS-level symlink tests, unrelated), 1304 assertions**. `tests/Feature/Permissions/SyncPermissionsCommandTest.php` also re-run clean (10/10) since `AppServiceProvider` changed.
3. `php -l` clean on every changed/new PHP file. `git diff --check` clean (staged temporarily to check the new untracked files, then unstaged again — no commit made).
4. No real `mysql`/`mysqldump` was ever invoked by any test (every case goes through a fake `ProcessStreamInputRunner`, except the stream-runner's own integration test, which only ever spawns `PHP_BINARY`); no real database was destructively imported; the connection-reset test purges/reconnects a throwaway file-backed secondary SQLite connection, never the suite's own `:memory:` connection.
5. `git status --short` confirms exactly the files listed above changed; nothing was committed.

### Commit Hash
Not committed — awaiting review.

---

### Date
2026-07-25 (OMS Task 7C.4 — replay-safe restore launch, global serialization, detached process launcher, `oms:restore` command shell)

### Task
Implement Phase 7C.4 only of the approved OMS Task 7C restore architecture: a signed, doubly-authorized launch endpoint for an already-created, fully validated queued restore row; a `RestoreLaunchService` that runs the whole claim/serialize/spawn critical section under one short-lived exclusive `BackupSubsystemLock`; an atomic conditional-UPDATE claim that consumes `launch_nonce` and can never spawn twice on replay; an injectable `RestoreProcessLauncher` contract with real Linux (`setsid --fork`) and Windows production implementations; and the `php artisan oms:restore {uuid}` command shell, which — since the destructive execution engine does not exist yet — always fails closed to a terminal, sanitized `RestoreFailed` state rather than ever leaving a permanently active restore. No restore request/confirmation UI, no maintenance mode, no database import, and no `RestoreOrchestrator` were added.

**Same-day correction pass** (before commit, five required fixes): (1) the launch route is POST, not GET, since a signed GET could be triggered by prefetching/link-scanning/accidental navigation; (2) a new `RestoreActivityGuard::blocksOrdinaryOperations()` gate closes the parent-launch-to-child-lock-acquisition handoff race — every ordinary backup-subsystem operation (create/retention/verify/delete/download) now consults it, still holding the shared subsystem lock, before its own existing Cache/per-backup lock; (3) progress-phase semantics corrected — the parent now writes `phase=launching` (a new vocabulary entry), and only the `oms:restore` child ever writes `phase=lock_acquired`, and only after it has genuinely acquired the lifetime exclusive lock; (4) the previously-skipped bounded-retry test was replaced with a real cross-process test (a second, genuine external PHP process holds the lock, signals readiness, then releases); (5) both platform launchers now set an explicit `cwd` of `base_path()` rather than inheriting whatever directory the web server process happened to start in.

### Result
See `docs/AI_PROJECT_MEMORY.md` (2026-07-25 "Task 7C.4" entry) for full design/reasoning. Summary: `App\Services\Restore\RestoreLaunchService::launch(uuid, nonce)` validates every OMS Task 7C.1 precondition on the queued row (type/status/`started_at`/nonce match/verified source backup/`operation_reason`/bounded `restore_metadata` requester+`confirmed_at` snapshot/no forbidden metadata key), then — only after that — acquires `BackupSubsystemLock::acquireExclusive()` and, while holding it: re-runs `RestoreActivityGuard::isActive($uuid)` excluding the row's own UUID (any other active DB restore, active progress file, or *tampered* file for the row's own UUID still blocks), performs the atomic conditional `UPDATE ... WHERE id=? AND type='restore' AND status='queued' AND started_at IS NULL AND launch_nonce=?` (exactly one row affected wins the claim; zero means replay/lost race → conflict, no progress write, no spawn), builds and writes the initial signed `RestoreProgressSnapshot` (`phase = 'launching'` — corrected, see below) via the existing `RestoreProgressWriter`, then calls the injected `RestoreProcessLauncher::launch($uuid)` — releasing the lock in every path via `finally`. A progress-write failure or a launcher failure both move the DB row to `RestoreFailed` (sanitized `error_summary`, `launch_nonce` never restored) and best-effort write a terminal signed `restore_failed` progress snapshot, without ever throwing back through the failure path. `App\Http\Controllers\Restores\RestoreLaunchController` (`POST /restores/{uuid}/launch` — corrected from GET, see below — route name `restores.launch`, `signed` + Filament `Authenticate` middleware) mirrors `BackupDownloadController`'s two-layer authorization exactly (explicit `hasRole(SUPER_ADMIN)` + `can('backups.restore')`, never relying on `Gate::before` alone) and maps outcomes to HTTP 202/409/423/500 — no Filament UI link/button/URL generator was added anywhere, so the route is reachable only by a signed URL a later phase will generate. `RestoreProcessLauncher` (contract) has two production implementations selected by a pure `RestoreProcessLauncherFactory::forOsFamily()`: `LinuxDetachedRestoreProcessLauncher` spawns `[setsid, --fork, php, artisan, 'oms:restore', uuid]` as a direct `proc_open()` argv array (verified against the installed symfony/process 7.4.13 source: no shell, no injection surface) with `disableOutput()` attaching stdout/stderr to real `/dev/null` file descriptors (no pipe survives back to the web request) and an explicit `cwd=base_path()` — `setsid --fork` gives the child a new session so it survives the PHP-FPM/web request ending; `WindowsDetachedRestoreProcessLauncher` is the local Laragon-only best-effort equivalent (no `setsid` analogue, documented as non-authoritative). Both fail closed (`RestoreProcessLaunchException` with a fixed reason code) on an invalid UUID, missing/non-executable configured binary, or a `proc_open` failure — checked via separately-testable `buildArgv()`/`buildProcess()` methods that never spawn anything. `php artisan oms:restore {uuid}` (`RestoreCommand`) validates the UUID, verifies the row is `type=Restore`/`status=Restoring`/`started_at` present/`launch_nonce` consumed, reads and requires a valid non-terminal signed progress file, then acquires the lifetime exclusive `BackupSubsystemLock` with a short bounded retry (parent launch request may still hold it briefly), re-verifies row+progress after acquiring the lock, writes an intermediate non-terminal `phase=lock_acquired` progress update (the ONLY place this phase is ever written, and only now that the lock is genuinely held), and — because the real execution engine (OMS Task 7C.7) does not exist yet — always writes a terminal sanitized `restore_failed` progress snapshot (`restore_failed_phase=lock_acquired`) and moves the DB row to `RestoreFailed` before releasing the lock and exiting non-zero. Never uses the database queue; never acquires the Cache lock.

**Restore-execution activity gate** (correction #2): `RestoreActivityGuard::blocksOrdinaryOperations(): bool` is deliberately NOT the same test as `isActive()`/`blocksNewRestore()` — it blocks only on a genuinely claimed/running restore (`status = Restoring` specifically, never a merely `Queued`, unlaunched request) OR a valid non-terminal signed progress file OR a malformed/tampered one (fail-closed, same "needs manual review" semantics as the launch-time guard). Wired into `BackupCreationOrchestrator::run()`, `BackupRetentionService::run()`, `BackupIntegrityVerifier::verify()`, `BackupDownloadController::show()` (all: checked immediately after acquiring the shared subsystem lock, releasing it and rejecting via each service's own existing bounded/sanitized failure path — `BackupLockedException`/`BackupIntegrityException`/HTTP 423 — before the existing Cache/per-backup lock is ever touched), and `BackupDeletionService::eligibility()` (not a separate inline check in `delete()` — folded into `eligibility()`'s own rule set as a new `restore_activity_in_progress` reason, memoized via `once()` to avoid an N+1 across the management page's per-row badge computation, so `eligibility()` and `delete()` can never disagree, exactly like every other rule this class enforces). `BackupCreationOrchestrator::runWithLockAlreadyHeld()` deliberately never performs this check — the mandatory pre-restore safety backup is intentionally executed *inside* the active restore itself, under its own already-validated exclusive handle, and would otherwise be permanently unable to run its own safety backup.

### Changed Files
- Created: `app/Services/Restore/{RestoreLaunchService,RestoreLaunchOutcome,RestoreLaunchOutcomeStatus,RestoreProcessLaunchResult,RestoreProcessLauncherFactory,LinuxDetachedRestoreProcessLauncher,WindowsDetachedRestoreProcessLauncher}.php`; `app/Services/Restore/Contracts/RestoreProcessLauncher.php`; `app/Services/Restore/Exceptions/RestoreProcessLaunchException.php`; `app/Http/Controllers/Restores/RestoreLaunchController.php`; `app/Console/Commands/RestoreCommand.php`; `tests/Support/Restore/FakeRestoreProcessLauncher.php`; `tests/Feature/Restore/{RestoreLaunchServiceTest,RestoreLaunchControllerTest}.php`; `tests/Unit/Services/Restore/RestoreProcessLauncherTest.php`; `tests/Feature/Console/RestoreCommandTest.php`; `tests/Feature/Backup/RestoreActivityOrdinaryOperationGateTest.php`
- Modified: `routes/web.php` (signed `restores.launch` route, POST); `app/Providers/AppServiceProvider.php` (binds `RestoreProcessLauncher` via `RestoreProcessLauncherFactory::forOsFamily(PHP_OS_FAMILY)`); `config/oms.php` (new `oms.backup.restore.{php_binary,linux_setsid_path,launch_lock_retry_timeout_seconds,launch_lock_retry_interval_ms}`); `app/Services/Restore/RestoreActivityGuard.php` (`blocksOrdinaryOperations()`); `app/Services/Restore/RestoreProgressSnapshot.php` (`launching` added to `ALLOWED_PHASES`); `app/Services/Backup/{BackupCreationOrchestrator,BackupRetentionService,BackupIntegrityVerifier,BackupDeletionService}.php` + `app/Http/Controllers/Backups/BackupDownloadController.php` (restore-activity gate wiring); `app/Services/Backup/Exceptions/BackupDeletionRejectedException.php` (`restore_activity_in_progress` reason); `tests/Feature/Backup/BackupManagementPageTest.php` (the pre-existing `test_no_restore_route_exists` guard was necessarily updated to `test_only_the_signed_restore_launch_route_exists` — Task 7C.4 explicitly adds the one launch route this phase requires); `tests/Feature/Backup/BackupDeletionServiceTest.php` (two pre-existing tests updated: a `Restoring`-status restore-source row is now intercepted by the new, broader `restore_activity_in_progress` gate before the older, narrower `restore_source_in_use` rule is ever reached — a genuine, intentional broadening, not a regression)
- No migration, no schema change (`restore_metadata`/`launch_nonce` columns already existed from Task 7C.1).

### Verification
1. Every new/corrected test file passes individually (see totals below).
2. Targeted (`tests/Feature/Restore` + `tests/Feature/Backup` + `tests/Unit/Services/Restore` + `tests/Feature/Console/RestoreCommandTest.php`) → **418 total, 417 passed, 0 failed, 1 skipped, 957 assertions**. The 1 skip is pre-existing (7B.1 OS-level symlink test, unrelated) — the bounded-retry test is no longer skipped; it now runs for real via a genuine second external PHP process.
3. `php -l` clean on every changed/new PHP file. `git diff --check` clean (no whitespace errors).
4. No real destructive restore process was ever spawned by any test — HTTP/service tests bind `FakeRestoreProcessLauncher`; launcher unit tests only ever call the spawn-free `buildArgv()`/`buildProcess()`. The one real cross-process test spawns a small standalone PHP script that only ever takes a real OS `flock()` on the exact lock file `BackupSubsystemLock` uses — never `oms:restore`, never Laravel, never a real database connection.
5. `git status --short` confirms exactly the files listed above changed.

### Commit Hash
See below — committed together with the corrections in this pass.

---

### Date
2026-07-25 (OMS Task 7C.3 — restore preflight, decryption/verification, safe extraction, private staging)

### Task
Implement Phase 7C.3 only of the approved OMS Task 7C restore architecture: a non-destructive preflight checker (source-backup validity, scope compatibility, encryption/key resolvability, mysql-client/database-connection presence, filesystem compatibility, restore-activity gate), a conservative itemized disk-space estimator, a private per-restore workspace abstraction, decryption + full content re-verification reusing existing crypto/archive code unchanged, and safe manifest-driven extraction into that workspace. Explicitly excluded: restore launch, restore execution, maintenance mode, database import, attachment live-directory swapping, any UI, and any orchestration.

### Result
See `docs/AI_PROJECT_MEMORY.md` (2026-07-25 "Task 7C.3" entry) for full design/reasoning. Summary: new `App\Services\Restore\RestorePreflightChecker` (+ `RestorePreflightResult`) validates the source backup, scope compatibility, the encrypted archive's cleartext header/key resolvability (via a new `SecretstreamEnvelope::peekKeyId()` that reuses the existing private header-parsing code — no decryption), mysql-client/database-connection presence (DB/full scope only), same-filesystem compatibility (new `Contracts\FilesystemIdentity`/`NativeFilesystemIdentity` seam, `stat()->dev` on Linux, drive/UNC-root comparison on Windows with a documented limitation), and the restore-activity gate (`RestoreActivityGuard::isActive()` extended with an optional `$excludeRestoreUuid` — additive, default-null, preserves all existing callers) — then a new `RestoreDiskSpaceEstimator` (+ `RestoreDiskSpaceEstimate`) computes a conservative itemized byte requirement (never a flat multiplier) and rejects insufficient space before any workspace directory exists. A new `RestoreWorkspace` provides a UUID-validated, escape-proof path abstraction under `storage/app/private/restores/{uuid}/workspace/`, scoped so its `cleanup()` can only ever remove that one subtree — structurally unable to touch sibling `progress.json`/`progress.previous.json`/`.locks`/a future quarantine directory. A new `RestoreArchivePreparer` orchestrates preflight → workspace → `SecretstreamEnvelope::decryptFile()` → `BackupArchiveContentVerifier::verify()` (both reused unchanged) → `RestoreArchiveExtractor::extract()`, cleaning the workspace up best-effort on any failure (never masking the original failure with a secondary cleanup error) and leaving it in place (cleanup responsibility transferred) on success, returning a bounded `PreparedRestore` value object. `RestoreArchiveExtractor` never uses `ZipArchive::extractTo()` — it independently re-derives the FULL archive's expected entry set from the verified manifest and compares it against the currently open ZIP's actual listing immediately before writing any staged content (closing the "verified vs. about to extract" gap), then iterates only the already-verified manifest's own declared components, validates every attachment path with the existing `SafeBackupPath`, rejects duplicate normalized paths/symlink or special ZIP entries (Unix external-attribute check)/declared-size mismatches/an existing symlink anywhere in the destination path, streams each entry in 1 MiB chunks while hashing and enforcing a manifest-declared cumulative byte budget, and closes every stream in every path.

**Post-approval correction pass** (same day, before commit): a final focused review caught and fixed four real defects before anything was committed: (1) the disk-space estimator originally scaled the mandatory pre-restore safety backup off the SOURCE backup's own size and only counted current live attachments when the selected scope included files — corrected so the safety backup (always full, regardless of selected scope) is now sized from a new injectable `CurrentDatabaseSizeEstimator` (production: `MysqlInformationSchemaDatabaseSizeEstimator`, an `information_schema.tables` aggregate query) plus the real current `attachments` disk size, both included unconditionally; (2) `RestoreActivityGuard`'s new `$excludeRestoreUuid` parameter had a real bug — it skipped reading the excluded UUID's progress file ENTIRELY rather than only suppressing a valid non-terminal result, meaning a tampered progress file for the excluded UUID would have silently passed as safe; fixed so the file is still read and validated, and only a genuinely valid non-terminal snapshot for that exact UUID is ignored; (3) `RestoreArchiveExtractor` now independently re-validates the full manifest-declared entry set against the ZIP's actual listing before extracting anything (see above), closing a verify-vs-extract TOCTOU gap; (4) `RestoreWorkspace` gained the same `SymlinkDetector` seam already used elsewhere, checking every existing path component between the restores-disk root and a resolved target for a symlink/junction before creating or returning it.

### Changed Files
- Created: `app/Services/Restore/{RestorePreflightResult,RestorePreflightChecker,RestoreDiskSpaceEstimate,RestoreDiskSpaceEstimator,RestoreWorkspace,RestoreExtractionResult,RestoreArchiveExtractor,PreparedRestore,RestoreArchivePreparer,NativeFilesystemIdentity,MysqlInformationSchemaDatabaseSizeEstimator}.php`; `app/Services/Restore/Contracts/{FilesystemIdentity,CurrentDatabaseSizeEstimator}.php`; `app/Services/Restore/Exceptions/{RestorePreflightException,RestoreInsufficientDiskSpaceException,RestoreWorkspaceException,RestoreArchivePreparationException,RestoreArchiveExtractionException}.php`; `tests/Support/Restore/{FakeFilesystemIdentity,FakeCurrentDatabaseSizeEstimator}.php`; `tests/Unit/Services/Restore/{RestoreDiskSpaceEstimatorTest,RestoreArchiveExtractorTest}.php`; `tests/Feature/Restore/{RestoreWorkspaceTest,RestorePreflightCheckerTest,RestoreArchivePreparerTest}.php`
- Modified: `app/Services/Backup/SecretstreamEnvelope.php` (added `peekKeyId()`, reusing the existing private header-parsing method — no duplicated crypto logic); `app/Services/Restore/RestoreActivityGuard.php` (`isActive()` gained an optional `$excludeRestoreUuid` parameter — additive, and corrected during final review so it can never suppress a genuinely tampered progress file); `app/Providers/AppServiceProvider.php` (bound `FilesystemIdentity` → `NativeFilesystemIdentity`); `tests/Feature/Restore/RestoreActivityGuardTest.php` (6 new exclusion/tamper tests)
- No migration, no schema change, no config key added (all disk-space/mysql-client/restore-disk config keys already existed from 7C.1).

### Verification
1. Every new/corrected Restore test file passes individually (see totals below).
2. Targeted (`tests/Unit/Services/Restore` + `tests/Feature/Restore` + `tests/Unit/Services/Backup` + `tests/Feature/Backup`) → **433 total, 431 passed, 0 failed, 2 skipped (pre-existing 7B.1 OS-level symlink tests, unrelated), 984 assertions**.
3. `php -l` clean on every changed/new PHP file. `git diff --check` clean (no whitespace errors).
4. No real backup/restore created against a real database; every test uses the schema-only SQLite `:memory:` harness (`BackupTestCase`) plus faked `backups`/`attachments`/`restores` disks and a `FakeProcessRunner` — no real `mysqldump`/`mysql` ever executed (the new `CurrentDatabaseSizeEstimator` is always faked in tests, never given a real MySQL connection). Fixture archives were built through the real `BackupCreationOrchestrator`/`SecretstreamEnvelope`/`BackupArchiveBuilder` (per the task's "use actual fixture archives" instruction), never hand-mocked crypto.
5. `git status --short` / `git diff --name-status` confirm exactly the files listed above changed (9 modified, 20 new, 0 deleted, across app+tests+docs).
6. Not independently unit-tested: `RestoreArchivePreparer`'s cleanup-failure-never-masks-original-exception guard (`cleanupBestEffort()`). Empirically confirmed on this Windows/Laragon host that `unlink()` succeeds even against an open file handle, so `Storage::deleteDirectory()` cannot be deterministically forced to fail here to prove the guard under real failure — the same class of Windows-testability gap already documented for the pre-existing real-symlink-creation tests. The fix itself (wrap `$workspace->cleanup()` in try/catch, swallow, always rethrow the original exception) was verified by code review and by the existing passing tests that prove a *successful* cleanup does not interfere with original-exception propagation.

### Commit Hash
`c6cfa07` implement secure restore preflight and staging

---

### Date
2026-07-23 (OMS Task 7C.2 — independent lock, signed progress protocol, atomic progress storage, restore-activity dual gate)

### Task
Implement Phase 7C.2 only of the approved OMS Task 7C restore architecture: the independent backup-subsystem filesystem lock (shared/exclusive), the pre-restore-safety-backup internal lock-bypass path, the signed restore-progress protocol with crash-safe atomic writes, and the dual restore-activity gate. Explicitly excluded: restore launch controller/routes, launch nonce claiming, detached process launcher, restore command, archive extraction, database/attachment restore, maintenance mode, watchdog, recovery acknowledgment, and any Filament restore UI.

### Result
See `docs/AI_PROJECT_MEMORY.md` (2026-07-23 "Task 7C.2" entry) for full design/reasoning. Summary: new `BackupSubsystemLock`/`BackupSubsystemLockHandle`/`LockMode` (real `flock()`, never a database-backed Cache lock); all five ordinary backup-subsystem operations (creation, retention, verification, deletion, download) now hold the shared lock for their full duration before their existing Cache/per-backup locks, in the exact required order; `BackupCreationOrchestrator` gained `runWithLockAlreadyHeld()` (validated live/exclusive/matching-path handle, no second lock acquisition, shared `execute()` pipeline with `run()`) for the future pre-restore safety backup; new `App\Services\Restore\*` signed progress-file protocol (`RestoreProgressSnapshot`/`Signer`/`Writer`/`Reader`) with atomic temp-file-then-rename writes and a bounded, validated schema; new `RestoreActivityGuard` implementing the DB-row-OR-signed-progress-file dual gate.

### Changed Files
- Created: `app/Services/Backup/{LockMode,BackupSubsystemLockHandle,BackupSubsystemLock}.php`; `app/Services/Restore/{RestoreProgressSnapshot,RestoreProgressSigner,RestoreProgressWriter,RestoreProgressReader,RestoreActivityState,RestoreActivityGuard}.php`; `app/Services/Restore/Exceptions/RestoreProgressIntegrityException.php`; `tests/Unit/Services/Backup/BackupSubsystemLockTest.php`; `tests/Unit/Services/Restore/{RestoreProgressSnapshotTest,RestoreProgressSignerTest}.php`; `tests/Feature/Restore/{RestoreProgressWriterReaderTest,RestoreActivityGuardTest}.php`
- Modified: `app/Services/Backup/{BackupCreationOrchestrator,BackupRetentionService,BackupIntegrityVerifier,BackupDeletionService}.php`; `app/Http/Controllers/Backups/BackupDownloadController.php`; `tests/Feature/Backup/{BackupTestCase,BackupCreationOrchestratorTest,BackupRetentionServiceTest,BackupIntegrityVerifierTest,BackupDeletionServiceTest,BackupDownloadControllerTest}.php`

### Verification
Targeted only (no full suite): `tests/Feature/Backup` + `tests/Unit/Services/Backup` + `tests/Unit/Services/Restore` + `tests/Feature/Restore` → **347 total, 345 passed, 0 failed, 2 skipped (pre-existing 7B.1 OS-level symlink tests, unrelated), 856 assertions** (Backup subset 287 unchanged by the final-review corrections; Restore subset 60 re-run after them, 60/60 passed, incl. 3 new UUID-scan tests). `git diff --check` clean. No real backup/restore created, no real database touched beyond the schema-only SQLite test harness. Final-review corrections: `RestoreProgressWriter` fsync wording (`fsync()` is core PHP since 8.1 and is genuinely called on this 8.3 runtime) + explicit Linux-atomic / Windows-replace-in-place documentation; `RestoreActivityGuard` now skips non-UUID directories (a stray non-UUID directory can no longer create a false permanent "tampered" block).

### Commit Hash
`a7ea0a3` implement restore locking and progress protocol (+ `1ce0e47` refresh graphify after restore locking and progress protocol)

---

### Date
2026-07-23 (OMS Task 7C.2 durability hardening — restore progress fsync gate + parent-directory sync)

### Task
One focused hardening correction to `RestoreProgressWriter` before starting 7C.3: do not replace the current valid `progress.json` unless the new temp file has been durably synchronized as far as the production platform supports; add best-effort parent-directory sync after a successful rename so the rename survives an OS crash on Linux.

### Result
`RestoreProgressWriter` now enforces a durability gate before the rename — `fwrite` fully succeeds, `fflush` succeeds, and (where `fsync` exists) the temp-file `fsync` succeeds — otherwise it throws a sanitized `RestoreProgressWriteException`, preserves the current valid file untouched, and cleans up the temp file. After a successful rename it `fsync`es the containing directory (best-effort; unsupported on Windows). File sync and directory sync go through a new injectable `App\Services\Restore\Contracts\RestoreProgressDurability` seam (production `NativeRestoreProgressDurability`, test `FakeRestoreProgressDurability`). `progress.previous.json` remains a best-effort recovery copy that can never abort the write or be treated as authoritative. No lock behavior, progress schema, activity guard, or other 7C.2 functionality changed.

### Changed Files
- Created: `app/Services/Restore/Contracts/RestoreProgressDurability.php`; `app/Services/Restore/NativeRestoreProgressDurability.php`; `app/Services/Restore/Exceptions/RestoreProgressWriteException.php`; `tests/Support/Restore/FakeRestoreProgressDurability.php`
- Modified: `app/Services/Restore/RestoreProgressWriter.php`; `tests/Feature/Restore/RestoreProgressWriterReaderTest.php`

### Verification
Directly-affected restore tests only: `tests/Feature/Restore/RestoreProgressWriterReaderTest` → 21/21 passed; full restore subset `tests/Feature/Restore` + `tests/Unit/Services/Restore` → **66 total, 66 passed, 0 failed, 94 assertions** (60 prior + 6 new durability tests: sync-failure prevents rename & preserves current file, temp cleanup after durability failure, dir-sync never called on temp-sync failure, dir-sync runs only after a successful rename, dir-sync failure doesn't corrupt the published file, native path publishes end-to-end). `php -l` clean; `git diff --check` clean. No real backup/restore/database touched.

### Commit Hash
_(not committed at time of writing — see "harden restore progress durability")_

---

### Date
2026-07-23 (OMS Task 7C.1 — restore domain/schema/config/authorization foundation)

### Task
Implement Phase 7C.1 only of the approved OMS Task 7C restore architecture (per the three-turn read-only audit + two delta-plan correction rounds): restore domain/schema/configuration/authorization/deletion-eligibility foundation. Explicitly excluded from this phase: locking, progress files, launch routes/controllers, restore commands, archive extraction, database/attachment restore, and any restore UI action.

### Result
See `docs/AI_PROJECT_MEMORY.md` (2026-07-23 "Task 7C.1" entry) for the full design/reasoning. Summary: `backup_operations` gained two additive columns (`restore_metadata` JSON, `launch_nonce` string) rather than a new table; `BackupType::Restore`/`BackupStatus::RestorePartial` added; `BackupOperation` gained `isRestoreOperation()` plus the `RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES` bound constant; `backups.restore` permission registered (Super-Admin-only by construction, registration alone never authorizes); new private `restores` disk + `config('oms.backup.restore')` foundation section; `BackupDeletionService` gained the `restore_source_in_use` eligibility rule; a real pre-existing bug in `BackupDeletionRejectedException::forReason()` (silently coercing any unrecognized reason code to `unexpected_failure`) was found and fixed as part of wiring the new rule through end-to-end.

### Changed Files
- Created: `database/migrations/2026_07_23_150000_add_restore_columns_to_backup_operations_table.php`; `tests/Unit/Enums/{BackupTypeTest,BackupStatusTest}.php`; `tests/Unit/Support/Backup/BackupLabelsTest.php`
- Modified: `app/Enums/{BackupType,BackupStatus}.php`; `app/Models/BackupOperation.php`; `app/Support/Permissions/PermissionRegistry.php`; `config/filesystems.php`; `config/oms.php`; `app/Services/Backup/BackupDeletionService.php`; `app/Services/Backup/Exceptions/BackupDeletionRejectedException.php`; `app/Support/Backup/BackupLabels.php`; `app/Filament/Pages/BackupManagementPage.php`; `tests/Feature/Backup/{BackupPermissionsAndModelTest,BackupDeletionServiceTest}.php`

### Verification
Targeted only (no full suite): `tests/Feature/Backup` + `tests/Unit/Services/Backup` + `tests/Unit/Enums` + `tests/Unit/Support/Backup` + `SystemRoleDefaultPermissionsTest` + `SyncPermissionsCommandTest` → **291 total, 289 passed, 0 failed, 2 skipped (pre-existing 7B.1 OS-level symlink tests, unrelated), 1189 assertions**. No migration run against the real database, no real backup/restore created. Post-implementation review corrected `isRestoreSourceInUse()` to match every active `BackupStatus` (not only `Restoring`) and confirmed no other duplicated reason-code mapping exists in the backup deletion subsystem beyond the one already fixed.

### Commit Hash
_(not committed — awaiting review)_

---

### Date
2026-07-23 (Backup management page — deletion-eligibility UI clarification)

### Task
Clarify the backup management table's "الحماية" column, which showed only the raw `is_protected` flag and was misleading for backups that are undeletable for other reasons (last known-good, active status, referenced pre-restore, or a transient file lock). Rename it to "إمكانية الحذف" and show a single badge reflecting the real deletion-eligibility decision, reusing `BackupDeletionService`'s own rules (no duplicated logic). Rename the details modal's `is_protected` field values to explicitly say "يدويًا" (manual) since that field now only describes manual protection, not overall deletability.

### Result
Added `App\Services\Backup\BackupDeletionEligibility` (a small read-only `allowed`/`reasonCode` value object) and a new public `BackupDeletionService::eligibility(BackupOperation $operation)` method that mirrors `delete()`'s exact rule order (already_deleted → active_status → protected → last_known_good → referenced_pre_restore → locked), including a best-effort lock peek (acquire-then-release) for the "locked" state, which can otherwise only be observed at actual delete time. `delete()` itself now calls `eligibility()` and throws via a new `BackupDeletionRejectedException::forReason(string $reasonCode)` factory — the rejection reasons are derived from one shared source, never duplicated between the service and the UI. `BackupDeletionService::lastKnownGoodId()` is memoized per instance via Laravel's `once()` helper; `BackupManagementPage::table()` resolves one `BackupDeletionService` instance and reuses it across all row-column closures so the last-known-good query runs once per table render, not once per row (verified via the pre-existing bounded-query-count test, which continued to pass unchanged). The table's `deletion_eligibility` column (label "إمكانية الحذف") shows "مسموح" (success), "ممنوع — آخر نسخة ناجحة" (danger), "ممنوع — محمية يدويًا" (danger), or "ممنوع — قيد الاستخدام" (warning), with a tooltip reusing the page's existing `deletionRejectionMessage()` Arabic text. The details modal's `is_protected` `TextEntry` keeps its "الحماية" label but its values are now "محمية يدويًا"/"غير محمية يدويًا". No deletion rule changed; no migration; `is_protected`'s `TernaryFilter` was left untouched (out of the requested scope).

### Changed Files
- Created: `app/Services/Backup/BackupDeletionEligibility.php`
- Modified: `app/Services/Backup/BackupDeletionService.php` (new `eligibility()`/`isLocked()`/memoized `lastKnownGoodId()`, `delete()` refactored to reuse `eligibility()`); `app/Services/Backup/Exceptions/BackupDeletionRejectedException.php` (new `forReason()` factory); `app/Filament/Pages/BackupManagementPage.php` (renamed/replaced table column, renamed modal field values, two new label/color helper methods)
- Modified tests: `tests/Feature/Backup/BackupDeletionServiceTest.php` (4 new eligibility/delete-parity tests), `tests/Feature/Backup/BackupManagementPageTest.php` (replaced the stale `test_protected_status_is_displayed`; added badge-label, delete/badge-parity, and reflection-based details-modal-value tests)

### Verification
`php artisan test tests/Feature/Backup` — 174 total, 173 passed, 0 failed, 1 skipped (pre-existing, unrelated OS-level symlink test), 499 assertions. `graphify update .` run after the code change.

### Commit Hash
(pending — not yet committed)

---

### Date
2026-07-23 (OMS Task 7B.2 — Filament backup management page)

### Task
Build the Super-Admin-only Filament management surface for the OMS Task 7B.1 backup core: overview statistics, manual-backup/verify/download/delete actions, a backup operations table with status polling, and persistent database notifications. Explicitly excludes Restore (no job/action/permission/route — a static informational notice only; Restore is OMS Task 7C).

### Result
New custom page `App\Filament\Pages\BackupManagementPage` (`النظام` → `النسخ الاحتياطي والاستعادة`, slug `backup-management`, nav sort 4) implementing `HasTable`. Page and every action require `App\Support\Backup\BackupAuthorization::check()` — a real `Super Admin` role **and** the matching `backups.*` permission — re-verified explicitly inside each action's own closure, not only via `->visible()`/`canAccess()`. Four overview stat cards (`App\Filament\Widgets\BackupOverviewWidget`, lazy-loading disabled since its queries are cheap bounded aggregates) backed by `App\Services\Backup\BackupOverviewStatsService` (last successful backup, next scheduled run, total active completed size, latest failed operation) and `App\Services\Backup\BackupScheduleCalculator` (fixed daily 02:00 / Friday 02:30 Asia/Gaza schedule mirroring `bootstrap/app.php`, DST-safe via `CarbonImmutable` day-by-day arithmetic). Header action "إنشاء نسخة احتياطية" validates the encryption configuration via `BackupKeyRing` without ever exposing it, guards accidental duplicate submission with a short per-user Cache lock, then calls the existing `BackupCreationOrchestrator::enqueue()` + `CreateBackupJob::dispatch()` (queued, never synchronous). Row action "فحص السلامة" dispatches `VerifyBackupIntegrityJob`, guarded against a duplicate in-flight request via a Cache flag keyed to the backup's UUID (cleared by the job itself, success or failure). Row action "تنزيل" links only to the existing named `backups.download` route (UUID only, never `stored_path`). Row action "حذف" requires typing `DELETE` in a danger-styled modal and delegates to a new `App\Services\Backup\BackupDeletionService`, which independently re-authorizes and rejects deleting an `is_protected`, currently-active (queued/running/verifying/deleting/restoring), "last known-good" (most recent completed+verified), or referenced-`pre_restore` backup — reusing the existing per-backup `BackupFileLock`, soft-deleting metadata only after the physical file is safely handled (including an already-missing file or an unsafe/traversal path, neither of which is ever allowed to touch an unrelated location), and restoring the row's prior status on any mid-delete failure rather than leaving it stuck at `deleting`. Read-only "التفاصيل" row action shows safe metadata only (never `stored_path`, absolute paths, or the encryption key itself — only its non-secret key ID) via `Infolists\Components\TextEntry`, with its own `beforeFormFilled()` authorization check (schema/data is populated at mount time, before any submit step, so this runs earlier than a plain `->action()` closure would). Table eager-loads `createdBy`, polls every 10 seconds, and includes all required Arabic-labelled columns/filters/search (type, scope, status, creator including "النظام" for scheduler-created rows, size via new `App\Support\Backup\BackupSizeFormatter`, integrity/protected status, a `TrashedFilter` for soft-deleted audit metadata) — verified via a bounded query-count test.

**Corrected pre-existing 7B.1 defect** (flagged and approved before implementation): `BackupCreationOrchestrator::enqueue()` previously set `is_protected = true` unconditionally for every manual backup, which would have made this task's own manual-delete feature permanently unusable for any manual backup — contradicting the approved spec's explicit "manual backups may be manually deleted unless protected" statement. Changed the default to `false`; `BackupRetentionService::mustKeep()`'s pre-existing, independent `type === Manual` check already fully covers automatic retention protection for manual backups, so this closes the gap without weakening anything.

Notification infrastructure did not exist at all before this task: added the standard Laravel `notifications` table migration and enabled `->databaseNotifications()` on `AdminPanelProvider` (`App\Models\User`'s existing `Notifiable` trait needed no change). New `App\Notifications\BackupOperationNotification` (+ `BackupNotificationEvent` enum) and `App\Services\Backup\BackupNotifier` resolve recipients — a manually-created operation's initiating `created_by` user if still active, or every active Super Admin for a scheduler-created (`created_by` null) operation, never a normal user merely holding a manually-granted `backups.*` permission — and are wired into both `CreateBackupJob` (success in `handle()`, failure in `failed()`) and `VerifyBackupIntegrityJob` (`handle()`'s success/catch paths). New `App\Support\Backup\BackupErrorSanitizer` (the same scrubbing `BackupCreationOrchestrator` already applied — strips `storage_path()`/`base_path()` roots and any `MYSQL_PWD=...` value) is additionally applied inside `CreateBackupJob::failed()`, since that lifecycle hook can receive a raw exception the orchestrator never sanitized.

Also corrected `tests/Feature/Permissions/AuthorizationAcceptanceTest.php`'s `test_permission_registry_contains_exactly_155_permissions` (confirmed via `git stash` to already fail at HEAD, unrelated to this task's own changes — a hardcoded literal that predated Task 7B.1's 6 `backups.*` permissions): replaced with `test_permission_registry_is_exactly_and_uniquely_synchronized`, which derives correctness from `PermissionRegistry::names()` itself (no duplicates, every name synced exactly once, no unexpected extra permission) plus an explicit structural check that all six `backups.*` permissions remain registered — no new hardcoded total.

### Changed Files
- Created: `app/Filament/Pages/BackupManagementPage.php`; `app/Filament/Widgets/BackupOverviewWidget.php`; `resources/views/filament/pages/backup-management-page.blade.php`
- Created: `app/Services/Backup/{BackupScheduleCalculator,BackupOverviewStats,BackupOverviewStatsService,BackupDeletionResult,BackupDeletionService,BackupNotifier}.php`; `app/Services/Backup/Exceptions/BackupDeletionRejectedException.php`
- Created: `app/Support/Backup/{BackupAuthorization,BackupLabels,BackupSizeFormatter,BackupErrorSanitizer}.php`
- Created: `app/Notifications/{BackupNotificationEvent,BackupOperationNotification}.php`
- Created: `database/migrations/2026_07_23_090000_create_notifications_table.php`
- Modified: `app/Jobs/CreateBackupJob.php`, `app/Jobs/VerifyBackupIntegrityJob.php` (notification wiring + sanitization); `app/Providers/Filament/AdminPanelProvider.php` (`->databaseNotifications()`); `app/Services/Backup/BackupCreationOrchestrator.php` (`is_protected` default correction)
- Created (4 test files): `tests/Feature/Backup/{BackupDeletionServiceTest,BackupNotificationTest,BackupManagementPageTest}.php`; `tests/Unit/Services/Backup/BackupScheduleCalculatorTest.php`
- Modified: `tests/Feature/Backup/BackupCreationOrchestratorTest.php` (one test updated for the `is_protected` correction); `tests/Feature/Permissions/AuthorizationAcceptanceTest.php` (durable permission-count assertion)

### Verification
Targeted only (no full suite): `tests/Feature/Backup` + `tests/Unit/Services/Backup` → **238 total, 236 passed, 0 failed, 2 skipped (pre-existing 7B.1 OS-level symlink tests, unrelated), 0 risky, 658 assertions**. Corrected permission tests (`AuthorizationAcceptanceTest`, `SyncPermissionsCommandTest`, `SystemRoleDefaultPermissionsTest`, `BackupPermissionsAndModelTest`) → **45 total, 45 passed, 0 failed, 0 skipped, 0 risky, 838 assertions**. No real backup was created, no real `mysqldump` was executed, no real migration was applied to MySQL (schema-only SQLite `:memory:` throughout), no real permission sync ran against the real database, and no real database row, attachment, or backup archive was changed.

### Commit Hash
_(to be filled after commit — `add secure backup management page`)_

---

### Date
2026-07-23 (OMS Task 7B.1 — encrypted backup core foundation)

### Task
Implement the foundation layer of the OMS backup system (audited read-only in Task 7A): private backup storage, versioned libsodium-encrypted archive creation, full pre-publication content verification, database-enforced scheduled deduplication, per-backup file locking, a secure download endpoint, and the dedicated `backups` queue/permission module. Explicitly out of scope: Restore (Task 7C, an independent CLI process), the Filament management page (Task 7B.2), and any real backup/mysqldump/migration execution.

### Result
New `backup_operations` table + `App\Models\BackupOperation` (SoftDeletes, UUID, nullable-unique `deduplication_key`) + 3 backed enums (`BackupType`/`BackupScope`/`BackupStatus`). New private `backups` filesystem disk (never symlinked, `serve => false`, same shape as the existing `attachments` disk). Archive = ZIP (`manifest.json` + `database/dump.sql` + `files/attachments/**`) encrypted with a dedicated versioned envelope (`SecretstreamEnvelope`, libsodium XChaCha20-Poly1305 Secretstream — magic bytes, version, key_id, Secretstream header, length-prefixed authenticated chunk frames terminated by exactly one `TAG_FINAL`). `BackupKeyRing` reads the active/previous encryption keys from `config('oms.backup.encryption')` and fails closed on any missing/invalid/wrong-length key or unrecognized key ID. `BackupCreationOrchestrator` builds an unpublished `.enc.partial` candidate, decrypts it back out, and runs it through the shared `BackupArchiveContentVerifier` (behind the `App\Services\Backup\Contracts\BackupArchiveContentVerifier` interface — the concrete implementation is `final`) — manifest schema/version/UUID/type/scope, dump SHA-256, every attachment SHA-256, exact entry-set match — before atomically publishing, setting `status=completed`, and stamping `verified_at` in the same step; any failure leaves no completed row, no published file, and no plaintext artifact, with a sanitized `error_summary`. `BackupIntegrityVerifier` re-runs the identical shared verification against an already-published backup. Scheduled (`daily`/`weekly`) backups compute a deterministic Asia/Gaza-derived `deduplication_key`, enforced by the table's own UNIQUE constraint (`enqueue()` catches the constraint violation and returns the existing row rather than racing a SELECT-then-INSERT check). A per-backup `Cache` lock (`BackupFileLock`, distinct from the single global `oms-backup-operation` lock) protects a published archive from deletion while it's being downloaded/verified: `BackupDownloadController` (`GET /backups/{uuid}/download`, Super-Admin-`hasRole()`-and-`backups.download`-gated) holds it through the actual `StreamedResponse` transmission callback and returns HTTP 423 if unavailable; `BackupRetentionService` skips (never force-deletes) a locked row and reports it in `inUseOperationIds`. `DatabaseDumper` streams `mysqldump` stdout straight to `dump.sql.partial` via an injectable `ProcessRunner`; the production `SymfonyProcessRunner` uses `Process::start()` with no callback plus `Process::getIterator()` (default flags, not `ITER_KEEP_OUTPUT`) — verified directly against the installed `symfony/process` v7.4.13 source, since `disableOutput()` and `getIterator()` are mutually exclusive (the latter throws `LogicException` when output is disabled). `MYSQL_PWD` only ever reaches the child process's environment; stderr is bounded to 4KB and sanitized; `ProcessRunResult` structurally cannot carry a SQL body. `AttachmentCollector`/`BackupArchiveBuilder` reject every symlink unconditionally via an injectable `SymlinkDetector` (production `NativeSymlinkDetector`; tests use a deterministic `FakeSymlinkDetector` that needs no OS symlink privileges). New `PermissionRegistry` module `backups` (`view_any/view/create/download/verify/delete`, no `restore` yet), Super-Admin-only by construction. Three new scheduler entries (daily 02:00, weekly Fri 02:30, retention 03:00, all explicit `Asia/Gaza`, `withoutOverlapping()->onOneServer()`) targeting the new `backups` queue.

### Changed Files
- Created (29): `app/Services/Backup/**` (services, `Contracts/`, `Exceptions/`, `Support/`)
- Created: `app/Models/BackupOperation.php`; `app/Enums/{BackupType,BackupScope,BackupStatus}.php`; `app/Jobs/{CreateBackupJob,VerifyBackupIntegrityJob,RetentionCleanupJob}.php`; `app/Console/Commands/{CreateBackup,BackupRetentionCommand}.php`; `app/Http/Controllers/Backups/BackupDownloadController.php`
- Created: `database/migrations/2026_07_22_100000_create_backup_operations_table.php`
- Modified: `config/oms.php` (new `backup` section), `config/filesystems.php` (new `backups` disk), `bootstrap/app.php` (3 scheduler entries), `routes/web.php` (download route), `app/Support/Permissions/PermissionRegistry.php` (new `backups` module), `app/Providers/AppServiceProvider.php` (container bindings for `ProcessRunner`/`SecretstreamEnvelope`/`SymlinkDetector`/`BackupArchiveContentVerifier`), `.env.example` (new `OMS_BACKUP_*`/`OMS_MYSQLDUMP_PATH`/`OMS_MYSQL_CLIENT_PATH` placeholders, no real values)
- Created (18 test files): `tests/Unit/Services/Backup/**`, `tests/Feature/Backup/**`, `tests/Support/Backup/{FakeProcessRunner,FakeSymlinkDetector}.php`

### Verification
Targeted only (no full suite): `tests/Unit/Services/Backup` + `tests/Feature/Backup` → **143 total, 141 passed, 0 failed, 2 skipped, 0 risky, 411 assertions** (the 2 skips are the two OS-level real-symlink tests, gracefully skipped where this Windows environment doesn't permit creating symlinks without elevation — the deterministic fake-detector symlink-rejection tests covering the same rule passed). Directly-affected permission tests re-run clean: `SyncPermissionsCommandTest` + `SystemRoleDefaultPermissionsTest` → **16 passed, 0 failed, 429 assertions**. Real-runner integration tests (`SymfonyProcessRunnerIntegrationTest`) exercise the actual `SymfonyProcessRunner` against real `PHP_BINARY` child processes (never `mysqldump`) — multi-megabyte incremental stdout, >4KB bounded stderr, non-zero exit, and enforced timeout, all passing. No real backup was created, no real `mysqldump` was executed, no real migration was applied to MySQL (every test runs on schema-only SQLite `:memory:`), no real database row or storage file was changed, and the two pre-existing manual `.sql` dumps under `storage/app/private/backups`/`storage/app/backups` were confirmed untouched throughout.

### Commit Hash
_(to be filled after commit — `add encrypted backup core foundation`)_

---

### Date
2026-07-22 (OMS-wide CRUD redirect standard)

### Task
Standardize full-page Filament CRUD redirects across all current resources: successful Create → created record's View page; successful Edit → updated record's View page; successful page-level Delete → resource Index. Centralized, no hard-coded admin URLs, authorization checked before redirecting to View, resource Index as the safe fallback, RelationManager/modal CRUD and read-only resources left unchanged.

### Result
Added one shared concern `App\Filament\Concerns\RedirectsToResourceView` (overrides only `getRedirectUrl()`; resolves `$resource::getUrl('view'|'index', ...)`; redirects to View only when `canView($record)`, else Index). Applied it to all 22 editable resources' 44 Create/Edit page classes (import + `use` only — no other change). Page-level Delete was already correct (Filament `InteractsWithRecord::getDefaultActionSuccessRedirectUrl()` → Index) and intentionally left unchanged. No View page has/needs a DeleteAction. All 22 editable resources already had a registered View route, so no View pages were added. Read-only resources (Attachments, Permissions, TransactionLines, Transactions) and RelationManager/modal CRUD (Currencies, ProjectCosts, Projects, Transactions) are intentional exceptions. No authorization, persistence, validation, notification, accounting, or financial payload behavior changed.

### Changed Files
- Created: `app/Filament/Concerns/RedirectsToResourceView.php`
- Created: `tests/Feature/Crud/CrudRedirectStandardTest.php` (genuine Livewire/HTTP), `tests/Feature/Crud/CrudRedirectStandardStructureTest.php` (structural)
- Modified (44): every `Create*`/`Edit*` page under `app/Filament/Resources/*/Pages/` (import + `use RedirectsToResourceView;`, +3 lines each)

### Verification
Targeted suites only (no full suite): `tests/Feature/Crud`, `ResourceHttpAuthorizationTest`, `AuthorizationAcceptanceTest`, `Roles`, `Users`, `GeneralExpenses`, `GeneralExchanges`, `ExecutionPayments`, `ProjectCostBudgetsPayments`, `ProjectCostReceipts` → **402 total, 399 passed, 0 failed, 3 skipped, 0 risky, 1242 assertions** (the 3 skips are the pre-existing read-only "no create route" skips). New CRUD tests alone: 88 passed (75 structural + 13 functional). All tests use in-memory SQLite; real MySQL data, storage, migrations, and `.env` were untouched.

### Commit Hash
Committed as `standardize CRUD redirects across resources` (see `git log -1 --format=%H`).

---

### Date
2026-07-22

### Task
Minimal test-suite cleanup: remove the obsolete stock Laravel `ExampleTest` failure and fix the one pre-existing risky Users test. Test-only — no production behavior, routes, or the default Laravel `/` test target changed.

### Result
Deleted `tests/Feature/ExampleTest.php` — it was the unmodified Laravel scaffolding test asserting `GET /` returns 200, but this is a Filament-only app that intentionally defines no user-facing `/` route, so it could never pass and had no OMS value; it was **not** replaced with a filler assertion and **no** production `/` route was added. Fixed `RoleAssignmentSafetyTest::test_submitted_super_admin_role_is_rejected_server_side`, which PHPUnit flagged risky ("did not perform any assertions") because on the expected path the `ValidationException` was caught by an empty block and the `$this->fail()` never ran: added one meaningful assertion — `assertArrayHasKey('roles', $e->errors())` — verifying the crafted Super Admin assignment is rejected on the `roles` field (matching `UserManagementService`'s `ValidationException::withMessages(['roles' => …])`). No authorization logic or production code was weakened or touched.

### Changed Files
- `tests/Feature/ExampleTest.php` (deleted)
- `tests/Feature/Users/RoleAssignmentSafetyTest.php` (one test method: added `roles`-error assertion)
- `graphify-out/**` (regenerated via `graphify update .`)

### Verification
- Targeted Users tests (`tests/Feature/Users/`): **64 passed, 0 failed, 0 skipped, 0 risky, 134 assertions** (the affected file `RoleAssignmentSafetyTest`: 6 passed, 11 assertions, 0 risky). Full suite **not** run in this task.
- No production code, route, migration, database, or storage file changed (only two test files + regenerated Graphify output).

### Commit Hash
_(to be filled after commit — `clean obsolete test scaffolding`)_

---

### Date
2026-07-22

### Task
OMS Task 6D: rebuild and re-enable the standalone `AttachmentResource` as a secure, read-only financial attachment registry (`النظام` → `سجل المرفقات`). Browse/search/filter/view attachment metadata, preview images inline, and securely open/download files — never any upload/create/edit/delete/restore/force-delete/bulk mutation, never a raw path/disk/URL. Keep every Task 6A structural read-only override and the Task 6A–6C private-storage/parent-policy protections. Targeted tests only; no full suite, no real DB/file changes, no stage/commit until review.

### Result
Added `App\Services\Attachments\FinancialAttachmentRegistry` (single source of truth mapping each of the five supported attachable types to its Arabic operation label, parent-module `.view` permission, per-type eager-load relationships, and display accessors) and rebuilt the resource around it. **Re-enabled in the sidebar** (`shouldRegisterNavigation` back to default true; labels: nav/plural `سجل المرفقات`, singular `مرفق`). **Read-only preserved:** all eight `canX()` still hard-false (verified even as Super Admin), only `index`/`view` routes, no Create/Edit page, `AttachmentForm` (the only `FileUpload`) deleted, no bulk actions. **Two-layer authorization:** opening the registry requires `attachments.view_any` (unchanged `AttachmentPolicy`); every listed/viewed row additionally requires `attachments.view` **and** the parent-module `.view` for its type — the list is scoped via `whereHasMorph('attachable', <actor's allowed types>)` (one EXISTS subquery per allowed type, requiring an existing non-trashed parent; none of the five parent perms ⇒ `1=0`, no rows), and `AttachmentResource::canView()` re-enforces the same rule on the View page so a direct URL to a supported-but-unauthorized record returns **403** (not merely a hidden row) and also gates the table `ViewAction` against crafted calls. Super Admin sees all five via `Gate::before`. **Only the five approved financial types** are ever listed/bound (`getEloquentQuery()` `whereIn` supported types) — unsupported `Project`/`Transaction`/`Partner` rows are excluded and 404 on direct URL; a guard test asserts the registry's supported list stays byte-identical to `AttachmentController::SUPPORTED_ATTACHABLE_TYPES` (controller untouched). **Preview/download remain protected** through the existing `attachments.show` route + its parent Policy (the shared `secure-attachment-preview` component); missing files (Status `ملف مفقود`), soft-deleted rows (audit metadata via the `المحذوفة` filter, safe notice, no links) and invalid-disk rows never expose a file link or the stored path. Amount/currency: single-currency ops use their own `amount`+`currency`; the two multi-currency ops (`ProjectCostBudget`, `GeneralExchange`) use `final_amount`+`disbursementCurrency` (never mixed) — approved; see DECISIONS_LOG. Operation number = parent `transaction.transaction_number`; date = `date` except `ProjectCostBudget` which has no own date column and uses `transaction.transaction_time`. Polymorphic parent + project chain + currency + transaction + uploader are eager-loaded via `MorphTo::morphWith` per type (no N+1, proven by a query-count test). **The file-availability filter was intentionally omitted** to avoid unbounded per-row filesystem work (existence can't be a SQL predicate); it is still surfaced per-row in the Status column and drives open/download visibility, bounded to the visible page. Real `attachments` table (read-only inspection): now 3 rows (0 at Task 6C — real app data added since), all `GeneralExpense` on the private `attachments` disk (2 trashed, 1 active), 0 public, 0 unsupported — nothing modified.

### Changed Files
- `app/Services/Attachments/FinancialAttachmentRegistry.php` (new)
- `app/Filament/Resources/Attachments/AttachmentResource.php` (nav re-enabled, labels, `canView` override, supported-type + eager-load base query, `form()` removed; all `canX` still false)
- `app/Filament/Resources/Attachments/Tables/AttachmentsTable.php` (registry columns/filters/actions + per-user scoping)
- `app/Filament/Resources/Attachments/Pages/ViewAttachment.php` (metadata infolist + secure preview + trashed/missing handling)
- `app/Filament/Resources/Attachments/Schemas/AttachmentForm.php` (deleted — removed the only `FileUpload`)
- `tests/Feature/Attachments/AttachmentRegistryTest.php` (new, 42 tests)
- `tests/Feature/Attachments/AttachmentResourceHardeningTest.php` (nav-now-registered + supported-type view access)
- `tests/Feature/Permissions/ResourceHttpAuthorizationTest.php` (nav opt-out list: only ProjectCostResource now)
- `tests/Feature/Permissions/AuthorizationAcceptanceTest.php` (attachments now a normal `attachments.view_any`-gated nav resource)
- `graphify-out/**` (regenerated via `graphify update .`)

### Verification
- New `AttachmentRegistryTest`: **42 passed, 101 assertions**, 0 failed/skipped/risky.
- Full targeted set (`tests/Feature/Attachments` + `ResourceHttpAuthorizationTest` + `AuthorizationAcceptanceTest`): **274 tests → 271 passed, 0 failed, 3 skipped, 0 risky, 856 assertions** (the 3 skips are the pre-existing "no create route" skips for transactions/transaction_lines/attachments). Full suite **not** run; unrelated Roles/Users/Reports suites **not** run.
- Tests run on SQLite `:memory:` under `APP_ENV=testing` with `Storage::fake` — no real DB record or real attachment file changed; no migration run.

### Commit Hash
_(to be filled after commit — `add secure financial attachment registry`)_

---

### Date
2026-07-22

### Task
OMS Task 6C: remove the six obsolete, orphaned public attachment files previously identified under `storage/app/public/{execution-payments,payments}` (no matching DB row). User confirmed the old financial records were intentionally deleted and the six files are no longer needed — not to be quarantined, migrated, preserved, or attached to any record. Read-only DB verification first; delete only the exact six verified orphans; never touch the public disk root, `public/storage`, the symlink, `.gitignore`, any private attachment, or any DB row. Then post-deletion verification, targeted tests only, docs. No stage/commit until review.

### Result
**No deletion was performed — the six orphan files were already absent from disk when this task ran.** Pre-flight found `storage/app/public` containing only the Laravel control file `.gitignore` (14 bytes); neither the `execution-payments/` nor the `payments/` directory exists (verified two independent ways: Bash `find` and PowerShell `Get-ChildItem -Recurse -Force`). The public disk root was confirmed as `storage_path('app/public')` in `config/filesystems.php` (not a mis-mapped path). The six files were present and byte-for-byte unchanged as of the last commit `27969b5` (2026-07-21, per the Task 6B entry below) and were never git-tracked (uploaded files, git-ignored), so their subsequent removal from disk does not appear in `git status`; the user's Task 6C confirmation states they were intentionally removed. Because the files were already gone, per-file pre-deletion sizes/SHA-256 could **not** be recorded by this task (not fabricated); prior docs record only an aggregate 706,133 bytes for the six orphans (2026-07-15 entry in `AI_PROJECT_MEMORY.md`). MySQL was down at first (connection refused) and the read-only DB check was deferred until the user brought it up, then run. Read-only DB verification (via `php artisan tinker`, `withTrashed()`): **0** rows referencing `execution-payments` or `payments` (active or trashed), **0** rows on the `public` disk, **0** rows on the `attachments` disk, **0** total rows in the `attachments` table — the table is empty, consistent with the intentional data deletion. Post-deletion verification: old public URLs return **HTTP 403 Forbidden** (live `curl` against `http://172.16.0.100/oms/public/storage/{execution-payments,payments}/probe.jpg`) — note the server returns 403, not the 404 the task wording anticipated; this is Apache's uniform response for any nonexistent file under `/storage/` (an existing file, `/storage/.gitignore`, returns 200, proving the `public/storage` symlink is intact and static serving is live). No private attachment, symlink, `.gitignore`, or public-storage structure was modified (nothing was deleted or written outside `docs/`). Code posture re-confirmed unchanged from Task 6B: all 5 financial Forms upload to `->disk('attachments')` (private); all 5 View pages render the shared `secure-attachment-preview` component, which links only via `route('attachments.show', ...)`; grep found no `Storage::url()` or raw `/storage/` in any `app/**/*.php` financial-display path (only a comment in `AttachmentController` stating it must not be used) or in any `resources/**/*.blade.php`. No quarantine or legacy-migration tooling is necessary: the `attachments` table is empty (no `disk = public` rows to migrate) and every current/future financial attachment already uses the private disk + protected `attachments.show` route.

### Changed Files
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md`, `docs/NEXT_STEPS.md` (documentation only)
- No application code, migration, database row, storage file, symlink, or `.gitignore` changed.

### Verification
- Working tree clean at start (`git status --short` empty, branch `main`).
- `storage/app/public` contents: only `.gitignore` (14 bytes); the two orphan directories do not exist (Bash + PowerShell).
- Public disk root confirmed `storage_path('app/public')` (`config/filesystems.php:43`).
- Read-only DB (tinker, `withTrashed()`): 0 orphan-path rows, 0 public-disk rows, 0 attachments-disk rows, 0 total rows.
- Old public URLs: HTTP 403 (serve no file); `/storage/.gitignore` → 200 (symlink intact).
- Targeted tests: `php artisan test tests/Feature/Attachments` → **141 passed, 400 assertions** (includes `FinancialAttachmentCutoverTest` + `FinancialAttachmentViewFlowTest`, both in that directory). Full suite not run, per instructions.
- 5 Forms → `disk('attachments')`; 5 View pages → `secure-attachment-preview` / `attachments.show`; no `Storage::url()`/`/storage/` in financial display (grep).

### Commit Hash
Not committed — awaiting review (no staging).

---

### Date
2026-07-21

### Task
OMS Task 6B: cut all five financial Resources (Project Cost Receipts, Project Cost Budget Disbursements, Execution Payments, General Expenses, General Exchanges) over from the public disk to the private `attachments` disk built in Task 6A — new uploads write only to the private disk with `disk = attachments`; images/PDFs preview securely inline on the View pages via server-built `attachments.show` routes; Edit pages support keep/replace/remove without ever prefilling a private path into `FileUpload`; old physical files are retained on soft delete; `disk = public` legacy rows remain supported through the same protected controller during the transition. Then, on approval: controlled local rollout of the real `disk`-column migration against the real local MySQL database (full backup first), documentation, Graphify refresh, and commit — explicitly not the Task 6C legacy-file quarantine/migration.

### Result
Added `App\Services\Attachments\AttachmentUploadService` (centralizes the upload/rename/move logic previously duplicated in all 10 Create/Edit page classes; directory/prefix allowlisted; reuses `AttachmentStorageService::isSafeRelativePath()`; row created first so its real id can be used in the deterministic `{prefix}_{id}_{Ymd}_{amount}.{ext}` filename; disk `throw => false` means move failure is checked via return value, not exception; a failed move force-deletes the just-created row, a post-move DB failure deletes only the newly-moved file and force-deletes the row, the old attachment is never touched by a failed call) and `resources/views/filament/components/secure-attachment-preview.blade.php` (one reusable component: inline image preview, "عرض بالحجم الكامل"/"تنزيل" links built server-side from the Attachment id only, safe file-card for non-images, Arabic empty state, no raw path/URL ever rendered — used identically in all 5 View and all 5 Edit pages). Updated all 5 Forms (`FileUpload` → `disk('attachments')` + `visibility('private')`, plus the new preview component and a `remove_current_attachment` checkbox, both visible only on Edit with an active attachment), all 5 Create pages (delegate to the new service), all 5 Edit pages (`mutateFormDataBeforeFill` no longer prefills the private path; `handleRecordUpdate` implements keep/replace/remove/replace-with-simultaneous-removal per the approved precedence rules), and all 5 View pages (hand-built `Storage::disk('public')->url()` HTML replaced by the shared component). 77 new tests (17 direct `AttachmentUploadService` failure/scheme tests, 30 parameterized Create/Edit tests across all 5 resources via `ReflectionMethod` on the real page handlers, 30 real `Livewire::test()` View-page rendering tests across all 5 resources), all passing. `grep` confirmed zero remaining `Storage::disk('public')`/`->url(`/raw `/storage/` references in the 5 financial Resource directories. After approval: created a full `mysqldump` backup (`C:\laragon\backups\oms\oms_before_attachment_disk_20260721_143806.sql`, 150,837 bytes, SHA-256 `e23e2c92589e7196cffa8023814c94df225f748437864ce7114aba759dfc2871`), ran only the targeted `2026_07_21_000001_add_disk_to_attachments_table` migration against the real local MySQL database, and verified post-migration: `disk varchar(32) default 'public'` exists exactly as the migration specifies, Attachment row count still 0, all six pre-existing orphan files under `storage/app/public/{execution-payments,payments}` byte-for-byte unchanged (SHA-256 compared before/after), no directory created under the real private attachments disk. One coverage gap flagged (not silently claimed as covered): the post-move DB-update-failure cleanup branch is implemented and code-reviewed but not deterministically unit-tested in this task, since SQLite in the test environment doesn't enforce the constraint that would trigger it and mocking `Attachment::update()` itself was judged too invasive for this task's scope.

### Changed Files
- `app/Services/Attachments/AttachmentUploadService.php` (new)
- `resources/views/filament/components/secure-attachment-preview.blade.php` (new)
- `app/Filament/Resources/ProjectCostReceipts/{Schemas/ProjectCostReceiptForm.php, Pages/{CreateProjectCostReceipt.php, EditProjectCostReceipt.php, ViewProjectCostReceipt.php}}`
- `app/Filament/Resources/ProjectCostBudgetsPayments/{Schemas/ProjectCostBudgetsPaymentForm.php, Pages/{CreateProjectCostBudgetsPayment.php, EditProjectCostBudgetsPayment.php, ViewProjectCostBudgetsPayment.php}}`
- `app/Filament/Resources/ExecutionPayments/{Schemas/ExecutionPaymentForm.php, Pages/{CreateExecutionPayment.php, EditExecutionPayment.php, ViewExecutionPayment.php}}`
- `app/Filament/Resources/GeneralExpenses/{Schemas/GeneralExpenseForm.php, Pages/{CreateGeneralExpense.php, EditGeneralExpense.php, ViewGeneralExpense.php}}`
- `app/Filament/Resources/GeneralExchanges/{Schemas/GeneralExchangeForm.php, Pages/{CreateGeneralExchange.php, EditGeneralExchange.php, ViewGeneralExchange.php}}`
- `tests/Feature/Attachments/AttachmentUploadServiceTest.php` (new, 17 tests)
- `tests/Feature/Attachments/FinancialAttachmentCutoverTest.php` (new, 30 tests)
- `tests/Feature/Attachments/FinancialAttachmentViewFlowTest.php` (new, 30 tests)
- `database/migrations/2026_07_21_000001_add_disk_to_attachments_table.php` (already existed from Task 6A; applied to the real local database in this task)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
Pre-commit targeted run: `tests/Feature/Attachments` (all 7 files including the 3 new ones) + the five financial Resource test directories + `ResourceHttpAuthorizationTest` + `AuthorizationAcceptanceTest` — 303 tests, 300 passed, 3 skipped (pre-existing, unrelated), 0 failed, 1040 assertions. Full suite was explicitly not run (per task instruction) either before or after the commit. Real database: `attachments` table was 0 rows / no `disk` column before, and 0 rows / `disk varchar(32) default 'public'` after — confirmed via read-only queries both times; no user/role/permission/financial table touched by the migration (schema-only `ALTER TABLE ADD COLUMN`); the migration status log confirms only `2026_07_21_000001_add_disk_to_attachments_table` was newly marked as run. No real `Attachment` record was ever created; no real attachment file was ever uploaded, moved, copied, renamed, or deleted; the six orphan files were confirmed byte-for-byte unchanged (SHA-256 compared before and after the migration).

### Commit Hash
`move financial attachments to private storage` (hash recorded after commit — see below)

---

### Date
2026-07-21

### Task
Run the final OMS authorization acceptance test pass for Permissions Tasks 1–5 (system role integrity, Filament panel access, sidebar navigation, direct URL access, create/update/delete operations, report access, export authorization, Users/Roles/Permissions management, cross-module negative tests). Testing only — no production authorization redesign unless a genuine defect was proven; no financial-logic/UserResource/RoleResource/PermissionResource/policy/`PermissionRegistry` changes merely to make a test pass; no staging/commit until reviewed; no writes to the real local database.

### Result
Audited existing coverage across all ~80 test methods in `tests/Feature/{Permissions,Roles,Users,Reports}` before writing anything new. Coverage was strong; added exactly one new file, `tests/Feature/Permissions/AuthorizationAcceptanceTest.php` (22 tests, 200 assertions, all passing), covering only the genuine gaps the audit found — see `docs/AI_PROJECT_MEMORY.md` (2026-07-21 entry) for the full gap list. Test data was created entirely through the real `PermissionSyncService` (one active, non-deleted user per system role, exactly one role each) — never a hardcoded role/permission matrix; the file reuses `ResourceHttpAuthorizationTest::resourceProvider()` and `ReportPageAccessTest::reportProvider()` rather than inventing a second map. **No authorization defect was found anywhere in the tested surface.** Confirmed the acceptance spec's `users.assign_roles` permission does not exist in `PermissionRegistry` and is not a defect — role assignment is controlled by `users.create`/`users.update` + `UserManagementService`'s privilege-subset logic (already fully tested), and `users.assign_super_admin` remains the separate, independently-enforced permission for assigning Super Admin. This is the confirmed, approved design; no registry change was made.

### Changed Files
- `tests/Feature/Permissions/AuthorizationAcceptanceTest.php` (new, 22 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
Ran in the requested order: new `AuthorizationAcceptanceTest` (22/22, 200 assertions), all `tests/Feature/Permissions` (339/341, 2 pre-existing unrelated skips), all `tests/Feature/Roles` (88/88), all `tests/Feature/Users` (64/64, 1 pre-existing unrelated risky), all `tests/Feature/Reports` (58/58), relevant financial authorization tests — GeneralExpenses/GeneralExchanges/ExecutionPayments/ProjectCostBudgetsPayments/ProjectCostReceipts (70/70), full `php artisan test` (782/785 — the 3 gaps are the same pre-existing unrelated `ExampleTest` failure, 2 pre-existing skips, and 1 pre-existing risky flag recorded in every prior task's log, confirmed untouched by this task). No migration run. No write against the real local database — every test uses the in-memory SQLite test database already configured in `phpunit.xml`.

### Commit Hash
`add final authorization acceptance coverage` (hash recorded after commit — see below)

---

### Date
2026-07-21

### Task
OMS Permissions Task 5: build a structurally read-only Filament `PermissionResource` (list/search/filter/view every `Permission`, see its Arabic label/module/associated roles) plus one protected, confirmation-based synchronization action that safely re-invokes the existing `PermissionSyncService` — never allowing manual permission create/edit/delete, even for a real Super Admin. Add one new registered permission, `permissions.sync`, defaulted to Super Admin only, with an additional exact-`Super Admin`-role requirement on the sync action independent of the permission grant.

### Result
Added `App\Policies\PermissionPolicy` (registered explicitly in `AppServiceProvider`, viewAny/view map to `permissions.view_any`/`permissions.view`, every mutation ability hard-`false`), `App\Services\Permissions\PermissionManagementService` (thin authorization wrapper: authenticated actor + exact `Super Admin` role + `permissions.sync` ability, all required, before delegating to the untouched `PermissionSyncService::sync()`), and a new `App\Filament\Resources\Permissions\PermissionResource` directory (`PermissionResource`, `Pages\ListPermissions`/`ViewPermission`, `Tables\PermissionsTable`, `Schemas\PermissionInfolist`) registering only `index`/`view` routes and hard-overriding all 8 mutation `canX()` methods to `false` — the structural guarantee that survives `Gate::before`'s Super-Admin Policy bypass. `PermissionRegistry` gained `permissions.sync` (`مزامنة الصلاحيات`, new `system_permissions` group labelled `النظام والصلاحيات`) and a `moduleForPermission()` helper; total registered permissions: 154 → 155. `RoleManagementService`'s pre-existing `permissions.`-prefix protection already covers the new permission with zero changes needed there. Table shows technical name/Arabic label/module/guard/roles-count/role-names/registered-or-custom status with matching filters, eager-loads roles to avoid N+1, and never hides a legacy/custom `Permission` row (shown under `صلاحيات مخصصة`). The sync header action is UX-hidden via `->visible()` for non-Super-Admins and, independently and more importantly, rejected server-side by `PermissionManagementService::sync()` even for a non-Super-Admin manually granted `permissions.sync` — proven via a raw crafted `mountAction()`/`callMountedAction()` Livewire call (Filament's own `isDisabled()`/`isHidden()` folding already blocks it at that layer) and, independently, a direct service-level test with no UI involved at all.

### Changed Files
- `app/Support/Permissions/PermissionRegistry.php` (added `permissions.sync` + `system_permissions` group + `moduleForPermission()`)
- `app/Policies/PermissionPolicy.php` (new)
- `app/Providers/AppServiceProvider.php` (registered `Gate::policy(Permission::class, PermissionPolicy::class)`)
- `app/Services/Permissions/PermissionManagementService.php` (new)
- `app/Filament/Resources/Permissions/PermissionResource.php` (new)
- `app/Filament/Resources/Permissions/Pages/ListPermissions.php` (new)
- `app/Filament/Resources/Permissions/Pages/ViewPermission.php` (new)
- `app/Filament/Resources/Permissions/Tables/PermissionsTable.php` (new)
- `app/Filament/Resources/Permissions/Schemas/PermissionInfolist.php` (new)
- `tests/Feature/Permissions/PermissionPolicyTest.php` (new, 8 tests)
- `tests/Feature/Permissions/PermissionResourceHttpTest.php` (new, 8 tests)
- `tests/Feature/Permissions/PermissionResourceLivewireTest.php` (new, 11 tests)
- `tests/Feature/Permissions/PermissionSyncActionTest.php` (new, 8 tests)
- `tests/Feature/Permissions/PermissionManagementServiceTest.php` (new, 4 tests)
- `tests/Feature/Permissions/SystemRoleDefaultPermissionsTest.php` (+1 test: `permissions.sync` role-default proof)
- `tests/Feature/Permissions/SyncPermissionsCommandTest.php` (+1 test: `permissions.sync` created/assigned-only-to-Super-Admin proof)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
Ran in the approved order: `PermissionPolicyTest` (8/8), `PermissionResourceHttpTest` (8/8), `PermissionResourceLivewireTest` (11/11), `PermissionSyncActionTest` (8/8), `PermissionManagementServiceTest` (4/4), `SyncPermissionsCommandTest` + `SystemRoleDefaultPermissionsTest` + `SyncCompatibilityTest` (20/20), full `RoleResource` compatibility set (84/84), all `tests/Feature/Roles` (88/88), all `tests/Feature/Users` (64/64, 1 pre-existing risky unrelated), all `tests/Feature/Permissions` (317/319, 2 pre-existing skips unrelated), relevant financial test directories — ExecutionPayments/GeneralExpenses/GeneralExchanges/ProjectCostReceipts/ProjectCostBudgetsPayments (70/70), full `php artisan test` (760/763 — the 3 gaps are the same pre-existing unrelated `ExampleTest` failure, `ResourceHttpAuthorizationTest`'s 2 pre-existing `transactions`/`transaction_lines` read-only skips, and the pre-existing `tests/Feature/Users` risky flag, all confirmed via `git status --short` to be in files this task never touched). No migration run. No write against the real local database — every test uses the in-memory SQLite test database already configured in `phpunit.xml`.

### Commit Hash
Not committed — awaiting explicit approval, per task instructions.

---

### Date
2026-07-15

### Task
Build a safe, environment-gated Artisan command + service to permanently wipe operational/transactional data from the OMS development database while preserving system configuration, donors, accounts, currencies, exchange-rate history, and users/roles/permissions. Phase 1 only: implement, test, and dry-run — apply must not run without explicit approval.

### Result
Implemented `App\Services\Maintenance\OperationalDataCleanupService` (+ `OperationalCleanupReport` DTO) and `php artisan oms:clean-operational-data --dry-run` / `--apply --confirmation=... --backup-file=...` / `--skip-files`. Environment guard restricts to `local`/`development`/`testing`; apply refuses without the exact confirmation token `DELETE-OMS-OPERATIONAL-DATA` and a verified non-empty backup file. Deletion is child-before-parent (12 tables + linked attachments + account-balance reset to 0), inside one `DB::transaction()`, using `DB::table()->delete()` to cover active+soft-deleted rows in one statement and to avoid firing the 5 project-snapshot-dirtying Eloquent observers unnecessarily. Donor classification uses only `partners.is_donor` (no other authoritative field exists); all non-donor partners are preserved and reported as unclassified. Attachment files are deleted only by exact `file_path` match to a deleted attachment row, after the DB transaction commits; unmatched files in known attachment directories are reported as orphans, never deleted. Ran `--dry-run` against the real dev DB: 20 preserved tables/2 users/5 roles/24 permissions/15 accounts (11 non-zero balances)/2 donors/0 exchange-rate-history rows untouched; 13 operational tables with rows (10+2 transactions, 24+4 transaction_lines, 1+1 projects, etc.) proposed for deletion; 8 linked attachment DB rows (6 files present, 2 missing), 6 orphan files (706133 bytes, likely leftovers from the 2026-07-06 manual TRUNCATE reset) reported only; found and reported one unexpected legacy table `bank_accounts` (0 rows, no model) — confirmed zero writes via before/after row-count and balance comparison.

### Changed Files
- `app/Services/Maintenance/OperationalDataCleanupService.php` (new)
- `app/Services/Maintenance/OperationalCleanupReport.php` (new)
- `app/Console/Commands/CleanOperationalData.php` (new)
- `tests/Feature/Commands/CleanOperationalDataCommandTest.php` (new, 20 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
`php -l` clean on all 4 new/changed PHP files. New test suite: 20/20 passing (67 assertions) against a selectively-migrated SQLite `:memory:` schema. Full `php artisan test`: 71/72 (the 1 failure, `ExampleTest`, is pre-existing/unrelated — hits `/`, a route this Filament app never defines). `php artisan optimize:clear` ran clean. `php artisan oms:clean-operational-data --dry-run` run against the real dev DB and confirmed zero writes (row counts and account balances identical before/after via direct tinker query). `--apply` was never run against the real dev DB, per instructions.

### Commit Hash
Not committed — awaiting explicit approval for the apply phase before any commit.

---

### Date
2026-07-16

### Task
Following the same-day read-only audit of the Execution Payment (`صرف مبالغ التنفيذ`) credit-account flow, implement the approved enhancement: move the "الحساب الدائن" section above "الحساب المدين (المستفيد)" on `/admin/execution-payments/create`, replace the display-only credit account with a real editable account-type → bank-type → currency → account cascade (defaulting from the selected budget's destination account, but user-overridable to any same-currency account before saving), and fix the pre-existing Edit drift bug where the historical credit account could be silently overwritten by the budget's current destination account.

### Result
Changed 3 files: `ExecutionPaymentForm.php` (section reorder, new `credit_account_type_id`/`credit_bank_type_id`/`credit_currency`/`credit_account_id` cascade replacing `credit_account_display`, new `applyCreditDefaults()` helper wired into the `project_cost_budget_id` Select's `afterStateUpdated`, new `validateCreditAccount()` server-side guard throwing `ValidationException` with Arabic messages), `CreateExecutionPayment.php` (uses the validated submitted credit account instead of always recomputing from the budget destination), `EditExecutionPayment.php` (hydrates the credit cascade from the payment's own saved `LINE_CREDIT` line instead of the budget's current destination — fixing the drift bug — and no longer recomputes the credit account from the budget in `handleRecordUpdate()`). No migration, no model change: the actually-selected account is still persisted only via `transaction_lines.account_id` on the `LINE_CREDIT`/`execution_source` line, exactly as before. Cross-currency replacement accounts are rejected server-side, not just hidden by Select options. `TransactionLineRole`, `TransactionDescriptionBuilder`, `TransactionLineDescriptionBuilder`, the disbursement flow, reports, and financial snapshots were not touched. See `docs/AI_PROJECT_MEMORY.md` (2026-07-16 entry) for full detail and `docs/DECISIONS_LOG.md` for the currency-restriction and drift-fix reasoning.

### Changed Files
- `app/Filament/Resources/ExecutionPayments/Schemas/ExecutionPaymentForm.php`
- `app/Filament/Resources/ExecutionPayments/Pages/CreateExecutionPayment.php`
- `app/Filament/Resources/ExecutionPayments/Pages/EditExecutionPayment.php`
- `tests/Feature/ExecutionPayments/ExecutionPaymentCreditAccountTest.php` (new, 7 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set)

### Verification
`php -l` clean on all 4 changed/new PHP files. New test suite: 7/7 passing (32 assertions) against a selectively-migrated SQLite `:memory:` schema (same pattern as `CleanOperationalDataCommandTest`), covering untouched-default create, manual-override create, cross-currency rejection (zero mutation), Edit hydration from the saved line (not the drifted budget destination), Edit-with-unrelated-field-change preserving the historical account, Edit manual account change (single active `LINE_CREDIT` line, correct balance reversal/reapplication, updated descriptions), and the reactive default on a genuine budget change. Existing description/line-description/financial regression suites (`TransactionDescriptionBuilderTest`, `TransactionLineDescriptionBuilderTest`, `BackfillTransactionDescriptionsCommandTest`, `CleanOperationalDataCommandTest`): 70/70 passing, unaffected. Full `php artisan test`: 78/79 (the 1 failure, `ExampleTest`, is pre-existing/unrelated — hits `/`, a route this Filament app never defines). `route:list` confirmed the 4 execution-payments routes (index/create/view/edit) are unchanged. `php artisan optimize:clear` ran clean. `graphify update .` ran clean (AST-only, no API cost).

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-18

### Task
Implement the approved positive-amount validation change, following the same-day read-only audit of all 5 financial workflows (project cost receipts, project cost budget disbursements, execution payments, general expenses, general exchanges) that found no server-side protection against zero/negative amounts, FX rates, or deduction percentages. Add matching Filament UI constraints, a reusable independent server-side guard, wire it into all 10 Create/Edit page handlers before any mutation, remove the unsafe silent `fx_rate ?: 1` fallback on submitted data in the two deduction/FX workflows, and add targeted tests.

### Result
Added `->minValue(0.01)` to all 5 amount fields (`ProjectCostReceiptForm.amount`, `ExecutionPaymentForm.amount`, `GeneralExpenseForm.amount`, `ProjectCostBudgetsPaymentForm.original_amount`, `GeneralExchangeForm.original_amount`), `->minValue(0)->maxValue(100)` to both percentage fields in `ProjectCostBudgetsPaymentForm`/`GeneralExchangeForm`, and `->minValue(0.000001)` to both `fx_rate` fields. New `App\Services\Validation\FinancialAmountGuard` (static methods, Arabic `ValidationException` messages) is called in all 10 Create/Edit `handleRecordCreation`/`handleRecordUpdate` methods, before `DB::transaction()` opens in every case — simple workflows call `assertSimpleAmount()`, the two deduction/FX workflows call the composed `assertDisbursementInputs()` covering the original amount, both percentages (individually and combined < 100), the FX rate, and both derived amounts. Replaced `(float) ($data['fx_rate'] ?: 1)` with `(float) ($data['fx_rate'] ?? 1)` at the 4 submitted-data sites in `CreateProjectCostBudgetsPayment`/`EditProjectCostBudgetsPayment`/`CreateGeneralExchange`/`EditGeneralExchange` handlers — an explicitly-submitted `0` now reaches the guard and is rejected instead of silently becoming `1`. Display-only `?: 1` fallbacks (Edit hydration reading saved records, the forms' "احسب" live-preview button) and `deriveAmounts()`'s own internal formula were deliberately left untouched, per instructions. Execution-payment over-budget behavior stays a non-blocking warning; account/currency/type revalidation for the other 4 workflows stays out of scope.

### Changed Files
- `app/Services/Validation/FinancialAmountGuard.php` (new)
- `app/Filament/Resources/ProjectCostReceipts/Schemas/ProjectCostReceiptForm.php`
- `app/Filament/Resources/ProjectCostReceipts/Pages/CreateProjectCostReceipt.php`
- `app/Filament/Resources/ProjectCostReceipts/Pages/EditProjectCostReceipt.php`
- `app/Filament/Resources/ExecutionPayments/Schemas/ExecutionPaymentForm.php`
- `app/Filament/Resources/ExecutionPayments/Pages/CreateExecutionPayment.php`
- `app/Filament/Resources/ExecutionPayments/Pages/EditExecutionPayment.php`
- `app/Filament/Resources/GeneralExpenses/Schemas/GeneralExpenseForm.php`
- `app/Filament/Resources/GeneralExpenses/Pages/CreateGeneralExpense.php`
- `app/Filament/Resources/GeneralExpenses/Pages/EditGeneralExpense.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Schemas/ProjectCostBudgetsPaymentForm.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/CreateProjectCostBudgetsPayment.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/EditProjectCostBudgetsPayment.php`
- `app/Filament/Resources/GeneralExchanges/Schemas/GeneralExchangeForm.php`
- `app/Filament/Resources/GeneralExchanges/Pages/CreateGeneralExchange.php`
- `app/Filament/Resources/GeneralExchanges/Pages/EditGeneralExchange.php`
- `tests/Unit/Services/Validation/FinancialAmountGuardTest.php` (new, 19 tests)
- `tests/Feature/GeneralExpenses/GeneralExpenseAmountValidationTest.php` (new, 3 tests)
- `tests/Feature/GeneralExchanges/GeneralExchangeAmountValidationTest.php` (new, 4 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set)

### Verification
New targeted tests: 33/33 passing (74 assertions) — `FinancialAmountGuardTest` (19: zero/negative/valid amount, fx_rate, individual and combined percentages, derived amounts) + `GeneralExpenseAmountValidationTest` (3: zero/negative amount rejected before any DB write, valid amount accepted — representative simple workflow) + `GeneralExchangeAmountValidationTest` (4: combined percentages = 100 rejected, zero/negative fx_rate rejected, valid values accepted — representative deduction/FX workflow, all rejections confirmed zero-mutation via before/after `Transaction`/`TransactionLine`/model counts and unchanged account balances) + existing `ExecutionPaymentCreditAccountTest` (7, unaffected). Full `php artisan test`: 104/105 (the 1 failure, `ExampleTest`, is pre-existing/unrelated — hits `/`, a route this Filament app never defines).

### Commit Hash
Not committed — awaiting explicit approval.

---

### Date
2026-07-18

### Task
Implement the approved server-side financial account validation change, following the same-day read-only audit of the 4 workflows that relied only on Filament Select filtering for account validity (project cost receipts, project cost budget disbursements, general expenses, general exchanges — execution payments already had `ExecutionPaymentForm::validateCreditAccount()`). Add a reusable `FinancialAccountGuard`, wire it into all 8 Create/Edit page handlers before any mutation, extend the execution-payment validator with the same active-account behavior for consistency, and add targeted tests.

### Result
New `App\Services\Validation\FinancialAccountGuard`: `assertAccountMatches()` (existence/not-trashed, account_type_id, bank_type_id, currency, optional `$requireActive`), batch `assertAccounts(array $specs): array` (verified `Account` models keyed by role), and `requireActiveOnChange(?int $original, $submitted): bool`. Called in all 8 Create/Edit handlers before `DB::transaction()` opens. Edit pages now fetch the record's pre-mutation saved lines *before* the guard call (moved out of the transaction closure) specifically to compute, per account role, whether the submitted account_id differs from the historically-saved one — `require_active` is `true` only when it does, so an untouched historical account may stay inactive while a newly-selected replacement must be active; every account is required active on Create. Every `Account::find($data['xxx_account_id'])?->increment/decrement(...)` balance-update call was replaced with the guard-verified `Account` instance (no `?->` needed). Approved rule confirmed and preserved: the same account may be used on multiple roles (debit=credit, source=destination, etc.) — no distinct-account check exists or was added. `ExecutionPaymentForm::validateCreditAccount()` gained two new optional named parameters (`$requireActiveCredit`/`$requireActiveBeneficiary`, default `true`) purely additively — its existing 4 checks and messages are untouched; `CreateExecutionPayment.php` needed no change (defaults match Create semantics), `EditExecutionPayment.php` now computes the same old-line-based flags. No migration, no accounting-formula change, no historical data touched.

### Changed Files
- `app/Services/Validation/FinancialAccountGuard.php` (new)
- `app/Filament/Resources/ProjectCostReceipts/Pages/CreateProjectCostReceipt.php`
- `app/Filament/Resources/ProjectCostReceipts/Pages/EditProjectCostReceipt.php`
- `app/Filament/Resources/GeneralExpenses/Pages/CreateGeneralExpense.php`
- `app/Filament/Resources/GeneralExpenses/Pages/EditGeneralExpense.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/CreateProjectCostBudgetsPayment.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/EditProjectCostBudgetsPayment.php`
- `app/Filament/Resources/GeneralExchanges/Pages/CreateGeneralExchange.php`
- `app/Filament/Resources/GeneralExchanges/Pages/EditGeneralExchange.php`
- `app/Filament/Resources/ExecutionPayments/Schemas/ExecutionPaymentForm.php`
- `app/Filament/Resources/ExecutionPayments/Pages/EditExecutionPayment.php`
- `tests/Unit/Services/Validation/FinancialAccountGuardTest.php` (new, 15 tests)
- `tests/Feature/GeneralExpenses/GeneralExpenseAccountValidationTest.php` (new, 9 tests)
- `tests/Feature/GeneralExchanges/GeneralExchangeAccountValidationTest.php` (new, 7 tests)
- `tests/Feature/ProjectCostReceipts/ProjectCostReceiptAccountValidationTest.php` (new, 6 tests)
- `tests/Feature/ProjectCostBudgetsPayments/ProjectCostBudgetsPaymentAccountValidationTest.php` (new, 6 tests)
- `tests/Feature/ExecutionPayments/ExecutionPaymentCreditAccountTest.php` (extended, +4 tests)
- `tests/Feature/GeneralExpenses/GeneralExpenseAmountValidationTest.php` (fixture fix: added account_type_id/bank_type_id to baseData, now required by the new guard)
- `tests/Feature/GeneralExchanges/GeneralExchangeAmountValidationTest.php` (same fixture fix)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set)

### Verification
New/updated targeted tests: 60/60 passing — `FinancialAccountGuardTest` (15, unit-level: existence/soft-delete/type/bank/currency/active mismatches, batch validation, same-account-both-sides, `requireActiveOnChange`), `GeneralExpenseAccountValidationTest` (9), `GeneralExchangeAccountValidationTest` (7, dual-currency destination + same-account-all-4-roles), `ProjectCostReceiptAccountValidationTest` (6), `ProjectCostBudgetsPaymentAccountValidationTest` (6), plus 4 new tests added to `ExecutionPaymentCreditAccountTest` (now 11 total) covering the active-account behavior. Full `php artisan test`: 150/151 (the 1 failure, `ExampleTest`, is pre-existing/unrelated — hits `/`, a route this Filament app never defines).

### Commit Hash
Not committed — awaiting explicit approval.

---

### Date
2026-07-16

### Task
Implement the approved responsive UI layout standard for credit/debit account sections across the OMS financial forms: desktop/wide screens show the creditor section on the right and the debtor section on the left, side by side with equal widths; narrow/mobile screens stack them vertically with the creditor section above the debtor section. Apply to General Expenses (primary page, currently debit-before-credit and visually unbalanced), Project Cost Receipts, and Execution Payments (preserving the recently-implemented editable credit-account cascade exactly). Assess the two multi-account forms (disbursement, general exchange) and only restructure them if it can be done without a risky rewrite.

### Result
Wrapped the credit and debit `Section`s of `GeneralExpenseForm.php` and `ProjectCostReceiptForm.php` in a shared `Grid::make(['default' => 1, 'lg' => 2])`, reordering the credit section to appear first in source order (both previously had debit first, stacked as two separate full-width cards). `ExecutionPaymentForm.php` already had the credit section first from the same-day credit-account-editable task, so only the `Grid` wrap was added. On desktop (≥lg breakpoint) this produces two equal-width columns; because the app renders `dir="rtl"`, native CSS Grid RTL auto-placement puts the first schema child (credit) on the right and the second (debit) on the left with no custom CSS. On narrow screens (<lg) the grid collapses to one column, preserving credit-above-debit source order. `ProjectCostBudgetsPaymentForm.php` (صرف مبلغ المشروع) and `GeneralExchangeForm.php` (التحويلات العامة) were left unchanged — see Decisions Log. No field names, options queries, live/afterStateUpdated callbacks, dehydration flags, validation, Create/Edit handlers, models, or migrations were touched in any file.

### Changed Files
- `app/Filament/Resources/GeneralExpenses/Schemas/GeneralExpenseForm.php`
- `app/Filament/Resources/ProjectCostReceipts/Schemas/ProjectCostReceiptForm.php`
- `app/Filament/Resources/ExecutionPayments/Schemas/ExecutionPaymentForm.php`
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
`php -l` clean on all 3 changed files. `php artisan view:cache` compiled all Blade templates with no errors, then `php artisan view:clear`. Targeted regression: `ExecutionPaymentCreditAccountTest` + `TransactionDescriptionBuilderTest` + `TransactionLineDescriptionBuilderTest` — 40/40 passing, confirming the credit-account cascade, defaults, validation, and description generation are all unaffected by the layout change. Full `php artisan test`: 78/79 (the 1 failure, `ExampleTest`, is the same pre-existing/unrelated failure as before this task — hits `/`, a route this Filament app never defines). `php artisan optimize:clear` ran clean. `graphify update .` ran clean (AST-only). No migration was run.

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-16

### Task
Rearrange the General Exchange form (`التحويلات العامة`, `/admin/general-exchanges/create`/`{record}/edit`) layout only: top area with "تفاصيل المعاملة" (right) and "النسب والمبالغ" (left) side by side on desktop / stacked (تفاصيل المعاملة first) on mobile; full-width "الحسابات" section underneath, internally unchanged; "المرفقات" last. No accounting logic, calculations, validation, or field behavior changes.

### Result
In `GeneralExchangeForm.php`: wrapped "تفاصيل المعاملة" and "النسب والمبالغ" in a new top-level `Grid::make(['default' => 1, 'lg' => 2])`, with "تفاصيل المعاملة" placed first in source order (renders on the right on desktop via native RTL CSS Grid auto-placement, since the app already renders `dir="rtl"`; stacks first/above on narrow screens). Moved "الحسابات" (full width, 4-account block: مصدر دائن، نسبة إدارية مدين، تحويل مدين، وجهة مدين) to sit underneath that row — its 16 fields, options queries, `afterStateUpdated`/`live()` callbacks, and `dehydrated()` flags are byte-identical to before, just relocated as a whole block. "المرفقات" remains last, unchanged. Old order was النسب والمبالغ → الحسابات → تفاصيل المعاملة → المرفقات (with الحسابات sitting between/beside the two transaction sections); new order is [تفاصيل المعاملة | النسب والمبالغ] → الحسابات → المرفقات. Only the top-level component tree changed; every field name, calculation, validation rule, currency/account filter, and helper method (`calculate()`, `deriveAmounts()`, `clearAmounts()`, `currencyName()`, `accountOptions()`) is untouched. `CreateGeneralExchange.php`/`EditGeneralExchange.php` were not modified.

### Changed Files
- `app/Filament/Resources/GeneralExchanges/Schemas/GeneralExchangeForm.php`
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)

### Verification
`php -l` clean. `php artisan view:cache` compiled all Blade templates with no errors, then `php artisan view:clear`. No dedicated `GeneralExchangeForm` test exists, so ran the closest relevant existing coverage: `CleanOperationalDataCommandTest` (exercises General Exchange rows), `BackfillTransactionDescriptionsCommandTest`, `TransactionDescriptionBuilderTest`, `TransactionLineDescriptionBuilderTest` — 70/70 passing. `php artisan optimize:clear` ran clean. `graphify update .` ran clean (AST-only). No migration was run.

### Commit Hash
Not committed — per task instructions, no commit was requested.

---

### Date
2026-07-15 (execution)

### Task
Execute the previously approved operational-data cleanup apply against the real dev database, per explicit user approval.

### Result
Precondition re-check: git clean (only prior implementation work unstaged), all 61 migrations `Ran`, dry-run counts matched the approved report exactly. The specified backup file did not exist yet — created it via `mysqldump --routines --triggers --single-transaction` (credentials passed only via `MYSQL_PWD` env var, never printed) to `storage/app/backups/oms_before_operational_cleanup.sql` (120,266 bytes), verified non-empty, then proceeded per the user's explicit instruction.

Ran `php artisan oms:clean-operational-data --apply --confirmation=DELETE-OMS-OPERATIONAL-DATA --backup-file="C:\laragon\www\oms\storage\app\backups\oms_before_operational_cleanup.sql"` at 12:21:16. Deleted (all now 0 active+trashed): 2 projects, 3 projects_costs, 1 project_cost_budget, 1 project_cost_budgets_payment, 3 project_cost_receipts, 2 general_expenses, 1 general_exchange, 12 transactions, 28 transaction_lines, 8 attachments, 1 project_financial_snapshot, 2 project_financial_snapshot_currency_totals, 0 project_financial_alerts. Reset all 15 accounts' `current_balance` to 0.00 (was 11 non-zero). Deleted exactly 6 physical attachment files (1,474,023 bytes), 0 failures; left the 6 pre-existing orphan files (706,133 bytes) untouched; `receipts/`/`general-expenses/` directories now empty but not removed; `public/storage` symlink untouched. Post-apply verification (service-internal fingerprint diff): **PASSED**. Independently re-verified all 21 checklist items via tinker + filesystem inspection: donors=2, accounts=15 (definitions unchanged), non-zero balances=0, users=2, roles=5, permissions=24, currencies=3, exchange_rate_histories=0 (unchanged), all reference tables unchanged, migrations=61, `bank_accounts`=0/untouched — all matched exactly.

Re-ran the identical `--apply` command a second time at 12:22:27 to verify idempotence: 0 additional files deleted, all counts identical, verification PASSED again. Confirmed via filesystem: still exactly 6 orphan files totaling 706,133 bytes, byte-identical to the first run.

Ran `php artisan optimize:clear` and `graphify update .` afterward (no further app-code changes).

### Changed Files
- Database only (`oms` MySQL database — not git-tracked): 12 operational tables emptied, 15 account balances reset to 0, 8 attachment rows removed.
- Filesystem: 6 attachment files deleted under `storage/app/public/{receipts,general-expenses,payments,execution-payments}/`.
- New: `storage/app/backups/oms_before_operational_cleanup.sql` (backup, git-ignored, not committed).
- `graphify-out/**` (regenerated).
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`, `OMS_Master_Reference.md` (this entry set).

### Verification
21-point manual checklist (donors, accounts, balances, users/roles/permissions, currencies, exchange-rate history, reference tables, migrations, bank_accounts, operational-table zero counts, orphan-file preservation) all confirmed independently, in addition to the command's own built-in post-apply verification (PASSED on both runs). Second apply run confirmed full idempotence (zero additional changes).

### Commit Hash
Not committed — no commit was requested for this data-only operation.

---

### Date
2026-07-05

### Task
Audit + implement Phase 1 of "ميزان المراجعة" (Trial Balance) report: read-only Filament page showing per-account debit/credit totals and balance status for a selected currency and date range.

### Result
Implemented and browser-verified. Page appears under "التقارير" (after "تقرير كشف الحساب"), scoped to a single required currency to avoid blending `debit_base`/`credit_base` across currencies (see Important Notes in AI_PROJECT_MEMORY.md). No opening/closing balance, no exports — period movement only, per approved Phase 1 scope.

### Changed Files
- `app/Services/Reports/TrialBalanceReportService.php` (new)
- `app/Filament/Pages/TrialBalancePage.php` (new)
- `resources/views/filament/pages/trial-balance-page.blade.php` (new)

### Verification
- `php -l` on both PHP files: no syntax errors.
- `php artisan route:list` confirms `GET admin/trial-balance` registered.
- Tinker cross-checks against real data: service totals matched a manual `DB::table` aggregate exactly (USD: debit=245,400.00, credit=390,000.00); confirmed soft-deleted transactions/lines are excluded (totals changed materially when the guard was removed in an isolated check); confirmed `include_zero_accounts=false` correctly drops zero-movement accounts; confirmed an account_type filter with no matching accounts returns a clean empty result.
- Browser (Playwright, logged in as the seeded dev superadmin): page loads with correct defaults (currency defaults to the `is_base` currency, dates default to start-of-month/today, toggle ON); pre-submit empty state renders; changing any filter reverts to the empty state; submitting renders summary cards and table matching the tinker-verified numbers; switching currency to ILS shows a fully disjoint account set (no cross-currency bleed); no console or `storage/logs/laravel.log` errors during the session.
- `php artisan optimize:clear` run after implementation, per task instructions. No migrations run.

### Commit Hash
(not committed yet)

---

### Date
2026-07-05

### Task
Phase 2 of "ميزان المراجعة" (Trial Balance): add "تصدير Excel" and "تصدير Word" export buttons that export exactly the applied/displayed report result.

### Result
Implemented and verified (tinker + browser). Both `phpoffice/phpspreadsheet` and `phpoffice/phpword` were already installed (confirmed in `composer.json` before writing any code). Exports read only the `applied*`/summary/`rows` properties snapshotted in `TrialBalancePage::showReport()` — never live filter state — so changing a filter after "عرض" without re-submitting blocks export with a warning notification, exactly as required. No change to any debit/credit/balance calculation.

### Changed Files
- `app/Services/Reports/TrialBalanceReportService.php` (additive only: `currency_code` and `account_type_label` added to the returned array for export labels/filenames; no calculation changed)
- `app/Filament/Pages/TrialBalancePage.php` (added `getHeaderActions()`, `exportExcel()`, `exportWord()`, `canExport()`, two new applied-snapshot properties)
- `app/Services/Reports/TrialBalanceExcelExportService.php` (new)
- `app/Services/Reports/TrialBalanceWordExportService.php` (new)

### Verification
- `php -l` on all four files: no syntax errors.
- Tinker: generated the Excel/Word exports directly from `TrialBalanceReportService::generate()` output (USD, wide date range) and re-opened them with PhpSpreadsheet/ZipArchive readers — confirmed real `.xlsx` (sheet title "ميزان المراجعة", RTL true, `getFreezePane()` null, autofilter range present) and real `.docx` (valid zip with `word/document.xml`, contains the title text and `bidi` RTL markup). Repeated with an account-type filter that matches zero accounts — both exports still generate cleanly with the "لا توجد بيانات ضمن الفترة المحددة" message and correct zeroed summary.
- Browser (Playwright, seeded dev superadmin): both header buttons render; clicking either before pressing "عرض" shows the exact warning notification text and blocks export; pressing "عرض" then changing a filter without resubmitting re-blocks export with the same warning; submitting properly and downloading both files succeeds with filenames `trial-balance-USD-2020-01-01-2030-12-31.xlsx`/`.docx`; no console errors. One unrelated Windows view-cache race (`rename ... Access is denied`) appeared in `storage/logs/laravel.log` on Filament's Dashboard sidebar component — confirmed unrelated to any Trial Balance file (grep for "TrialBalance" in the log found nothing) and self-resolved on the next request.
- `php artisan optimize:clear` run after implementation. No migrations run.

### Commit Hash
(not committed yet)


---

### Date
2026-07-06

### Task
Phase 1 of "تقرير الحركات المالية الشامل" (Comprehensive Financial Transactions report): new read-only Filament report page listing every transaction line in a period, one row per line, with per-currency/per-category/per-type summaries. No exports in this phase.

### Result
Implemented and verified. New service + page + Blade view following the Trial Balance pattern exactly (hasSubmitted gate, applied-filter snapshot, clearResults on any filter change). Data source is one joined read-only query over `transaction_lines` → `transactions` → `accounts`, with left joins to `accounts_type`, `currencies`, `transactions_types`, `transaction_super_types`, `projects_costs`/`projects` (project via `transaction_lines.project_cost_id`), and `users` (creator via `transactions.created_by`). All summaries are grouped per currency — no blended multi-currency total exists anywhere. `accounts.current_balance` untouched; no accounting write logic touched.

### Changed Files
- `app/Services/Reports/ComprehensiveFinancialTransactionsReportService.php` (new)
- `app/Filament/Pages/ComprehensiveFinancialTransactionsPage.php` (new)
- `resources/views/filament/pages/comprehensive-financial-transactions-page.blade.php` (new)

### Verification
- `php -l` on both PHP files: no syntax errors.
- `php artisan route:list` confirms `GET admin/comprehensive-financial-transactions` registered.
- Tinker smoke test against real data (wide date range): 66 lines / 23 transactions / 2 currencies returned; currency summaries, category summaries (3 super types), and row shape all correct; rows sorted oldest→newest by transaction_time, then transaction id, then line id; lines without a project cost show "غير مرتبط بمشروع".
- Note: per-currency status can legitimately show "غير متوازن" when a transaction's debit and credit legs are in different currencies — this reflects the ledger, not a report defect.
- `php artisan optimize:clear` run after implementation. No migrations run.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Enhancement to "تقرير الحركات المالية الشامل" summary section: renamed "عدد القيود"→"عدد المعاملات" and "عدد الأسطر المحاسبية"→"عدد بنود القيود" in the general summary, retitled the category/type sections to "إحصائيات حسب تصنيف المعاملة" / "إحصائيات حسب نوع المعاملة", and switched their count column from line count to distinct transaction count ("عدد المعاملات").

### Result
Implemented and verified. `accumulateGrouped()` now also tracks distinct transaction IDs per category/type bucket and `finalizeGrouped()` exposes `transaction_count` per bucket (never line_count/2, never per-line double counting). Financial debit/credit totals remain line-based and grouped per currency — no blended multi-currency total. Currency summary, filters, detail table, and all accounting logic untouched. Read-only throughout; `accounts.current_balance` untouched.

### Changed Files
- `app/Services/Reports/ComprehensiveFinancialTransactionsReportService.php` (distinct transaction_count per category/type bucket)
- `resources/views/filament/pages/comprehensive-financial-transactions-page.blade.php` (labels, section titles, transaction_count column)

### Verification
- `php -l` clean; `php artisan optimize:clear` run. No migrations run.
- Tinker against real data: 68 lines / 24 distinct transactions; "صرف مبلغ مشروع" shows tx=9 from 34 lines (multi-line transactions counted once); per-bucket tx counts sum to 24 = overall count = independent `DB::table(...)->distinct()->count('t.id')` cross-check; per-currency totals unchanged from Phase 1 logic.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Phase 2 of "تقرير الحركات المالية الشامل": add "تصدير Excel" and "تصدير Word" header buttons that export exactly the applied/displayed report result.

### Result
Implemented and verified. Both `phpoffice/phpspreadsheet` (^5.8) and `phpoffice/phpword` (^1.4) were already installed (confirmed in composer.json before writing any code; Maatwebsite Excel not used). Exports read only the `applied*`/summary/`rows` properties snapshotted in `showReport()` — never live filter state — so changing a filter after "عرض" (which triggers `clearResults()`) blocks export with the warning "يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير" until "عرض" is pressed again. Both exports include: report info with applied filters (unset filters shown as "الكل"), general summary (عدد المعاملات distinct / عدد بنود القيود / عدد العملات الظاهرة), per-currency summary with متوازن/غير متوازن status, category and type statistics (one row per bucket + currency; distinct transaction count written once per bucket), and the full detail table in page order. No blended multi-currency total anywhere. No calculation changes; no accounting write logic touched.

### Changed Files
- `app/Services/Reports/ComprehensiveFinancialTransactionsExcelExportService.php` (new)
- `app/Services/Reports/ComprehensiveFinancialTransactionsWordExportService.php` (new)
- `app/Filament/Pages/ComprehensiveFinancialTransactionsPage.php` (added `getHeaderActions()`, `exportExcel()`, `exportWord()`, `canExport()` — no other changes)

### Verification
- `php -l` on all three files: no syntax errors. `php artisan optimize:clear` run; route still registered. No migrations run.
- Tinker round-trip on real data (68 lines / 24 tx) and on an empty 1990 range: both files regenerate and re-open cleanly. XLSX verified: sheet "الحركات المالية", `getRightToLeft()=true`, `getFreezePane()=NULL` (no frozen rows), autofilter `A46:L114` on the detail table, numeric cell J47 is a real double with `#,##0.00` format, correct Content-Type and sanitized filename `comprehensive-financial-transactions-{from}-{to}.xlsx`. DOCX verified: valid zip with `word/document.xml`, `bidi` RTL markup, Tahoma default, all six sections present, footer with OMS + export datetime + `{PAGE}/{NUMPAGES}` fields. Empty-result exports contain "لا توجد حركات مالية ضمن الفترة المحددة" plus title/filters/summaries.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Reset all operational/business data in the `oms` database so real data entry can start clean, while preserving settings, lookup tables, auth (users/roles/permissions), and Laravel system tables. User specified exact keep/purge lists and required: no `migrate:fresh`, no dropped tables, no code/migration changes, backup first, show keep/purge lists for approval before deleting, reset AUTO_INCREMENT, include soft-deleted rows, run `optimize:clear` and `reports:refresh-projects-financial` after.

### Result
Inspected all 41 tables in the database and cross-checked against the user's keep list (25 tables) — matched exactly, no unclear tables. Queried `information_schema.KEY_COLUMN_USAGE` and confirmed no kept table has a foreign key into any purge table (purge-table FKs only point to kept tables or self-reference within the purge set), so truncating all 16 purge tables together under `FOREIGN_KEY_CHECKS=0` is safe. Presented both lists with row counts to the user for approval before touching data. User approved and asked me to run the backup. Took a full `mysqldump` backup (DB has an empty local root password, so no credential was read/exposed), verified it non-empty and containing `INSERT INTO` statements for key tables, then truncated the 16 purge tables (TRUNCATE resets AUTO_INCREMENT and removes all rows including soft-deleted). Ran `php artisan optimize:clear` and `php artisan reports:refresh-projects-financial` (0 refreshed, as expected with 0 projects). Confirmed final row counts: all 16 purge tables at 0, all 25 keep tables unchanged.

### Changed Files
None (data-only change; no code, no migrations). New file: `storage/app/private/backups/oms_backup_2026-07-06.sql` (pre-purge backup, not committed to git).

### Verification
- Row counts before and after purge captured via `DB::table($t)->count()` for all 41 tables.
- FK audit via `information_schema.KEY_COLUMN_USAGE` confirmed safe purge order/grouping.
- Backup file confirmed non-empty (169 KB) with grep-verified `INSERT INTO` rows for `transactions`, `projects`, `accounts`.
- Post-purge: all 16 purge tables show 0 rows; all 25 keep tables show their original row counts (`settings`=5, `roles`=5, `permissions`=24, `users`=2, `currencies`=3, etc.).
- `php artisan optimize:clear` completed successfully; `php artisan reports:refresh-projects-financial` ran with 0 refreshed/skipped/removed (correct, since `projects` is now empty).

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Add optional opening balance support when creating a new account, as a balanced double-entry opening transaction (never a manual `current_balance` write), without touching any report formula, project report logic, or snapshot calculation.

### Result
Implemented after a read-only impact report and explicit user approval of 4 flagged decisions: (1) no account nature exists in the schema, so the target account is always debited (matches the system-wide debit-normal convention); (2) `debit_base`/`credit_base` = own-currency amount per the existing convention — the user-entered fx_rate is stored on the lines as metadata only, never multiplied in; (3) clearing accounts "أرصدة افتتاحية" get their own new AccountType of the same name, one clearing account per currency with code `OPB-{CUR}`; (4) transaction type "قيد افتتاحي" created under a new super type "قيود افتتاحية". Flow: `CreateAccount::handleRecordCreation` strips the 3 virtual form fields; if opening_balance ≤ 0 it creates the account normally; otherwise one `DB::transaction()` creates the account → resolves fiscal year from the opening date (fallback: active FY; ValidationException if none) → firstOrCreate/restore-if-trashed lookups → `OPB-YYYY-XXXX` number via the same withTrashed+lockForUpdate MAX-suffix generator used by the other financial pages → transaction with description "قيد افتتاحي للحساب: {name}" → 2 balanced lines with `project_cost_id = null` → `increment`/`decrement` balance updates. `current_balance` is now `disabled()->dehydrated(false)` in the form (display-only on create and edit); the opening fields are visible on create only.

### Changed Files
- `app/Filament/Resources/Accounts/Schemas/AccountForm.php` (current_balance display-only; new create-only "الرصيد الافتتاحي" section with opening_balance / opening_balance_date / opening_balance_fx_rate)
- `app/Filament/Resources/Accounts/Pages/CreateAccount.php` (handleRecordCreation + opening transaction/lines/clearing-account/numbering logic)

### Verification
- `php -l` clean on both files; `php artisan optimize:clear` run; `php artisan test` passes (only example tests exist).
- Tinker end-to-end inside an outer rolled-back transaction (DB left untouched, 0 accounts / 0 transactions after): no-opening-balance create makes 0 transactions; 1500 USD opening → account balance 1500.00, tx OPB-2026-0001 with type "قيد افتتاحي" under "قيود افتتاحية", 2 lines D=1500/C=1500 both `project_cost_id=NULL`, clearing account OPB-USD (type "أرصدة افتتاحية") balance −1500; second USD account → OPB-2026-0002 and the same clearing account reused (balance −3500); ILS account with fx_rate 3.6 → separate OPB-ILS clearing account, `credit_base=700` (own currency, fx stored as metadata only).
- TrialBalanceReportService: balanced=true for both USD and ILS with opening entries present.
- `reports:refresh-projects-financial` with opening entries present: 0 snapshots created/changed — confirmed the snapshot service reads only `project_cost_receipts`/`project_cost_budgets`/`project_cost_budgets_payments`, never `transaction_lines`, so opening entries cannot affect project reports.
- No report/snapshot/service file was modified.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Implement "تقرير الجهات المانحة" (Donor Financial Report): a read-only Filament report page showing one donor, its linked projects, and the full cost/disbursement/execution financial picture between the organization and that donor, plus Excel and Word exports. Followed with a full audit (no code changes) before docs/commit.

### Result
Implemented and audited. New service + page + Blade view + 2 export services, following the established Trial Balance / Comprehensive Financial Transactions pattern (hasSubmitted gate, applied-filter snapshot, `clearResults()` on any filter change, exports read the on-screen snapshot only). `donor_id` required (`partners.is_donor = true` only); projects scoped via `projects.donor_id`; optional date_from/date_to filter `projects.approval_date` only; project_status_id/project_super_id/project_id are project filters; `currency_id` is display-only (hides non-matching rows/groups, never excludes a project). Three currency grains never mixed: cost-side (planned/received/surplus from `projects_costs`/`project_cost_receipts`), disbursement-source (original/admin-deduction/transfer-deduction/after-deductions from `project_cost_budgets.source_currency_id` + `transaction_lines` tagged `ProjectCostBudget::LINE_ADMIN`/`LINE_TRANSFER`), and execution (final/execution-paid/remaining from `project_cost_budgets.disbursement_currency_id` + `project_cost_budgets_payments`). "Real" disbursements = `transaction_id IS NOT NULL`, matching `ProjectsReportPage`'s existing convention. No existing report, snapshot, observer, model, migration, or accounting write logic was modified.

### Changed Files
- `app/Filament/Pages/DonorFinancialReportPage.php` (new)
- `app/Services/Reports/DonorFinancialReportService.php` (new)
- `app/Services/Reports/DonorFinancialReportExcelExportService.php` (new)
- `app/Services/Reports/DonorFinancialReportWordExportService.php` (new)
- `resources/views/filament/pages/donor-financial-report-page.blade.php` (new)

### Verification
- Full code audit against the checklist: file scope (`git status`/`git diff --stat` confirmed only the 5 files above are new, nothing else modified), page registration (auto-discovered via `AdminPanelProvider`'s existing `discoverPages()`, nav group التقارير, label تقرير الجهات المانحة, no manual registration needed), filter behavior, query correctness (soft-deletes excluded at every join level; no N+1 — all aggregates bulk-fetched before the per-project loop; no fan-out double counting since each disbursement transaction is 1:1 with its `project_cost_budgets` row, traced through `CreateProjectCostBudgetsPayment`), admin/transfer deduction tagging (confirmed `ProjectCostBudget::LINE_ADMIN`/`LINE_TRANSFER` constants are the same ones written by the real disbursement-creation code, not the differently-worded `GeneralExchange` constants), currency separation, Blade RTL/dark-light/empty-states/sign-coloring, and both export services (verified pure renderers of the passed-in `$report` array, no recalculation).
- `php -l` on all 4 new PHP files: no syntax errors.
- `php artisan optimize:clear`: succeeded.
- `php artisan route:list --path=donor-financial-report`: confirmed `GET admin/donor-financial-report` registered.
- Tinker smoke test against real dev data (1 donor, 1 linked project, 2 `projects_costs` rows in USD/ILS, 0 receipts): `DonorFinancialReportService::generate()` ran without error; correctly returned unmerged per-currency cost rows (planned 20,000 USD / 40,000 ILS, received 0 in both) — no cross-currency blending.
- Dev DB currently has 0 `project_cost_budgets` and 0 `project_cost_budgets_payments` rows, so the disbursement-source, deductions, final-disbursement, execution-paid, and remaining-execution sections could only be verified by code review, not by live numeric comparison — full numeric validation is pending real disbursement data (see NEXT_STEPS.md).
- No migrations run.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Fix broken image icon for "صورة الإشعار" on the Project Cost Receipt view page (`/admin/project-cost-receipts/{id}`).

### Result
Diagnosed as environmental, not a code bug. Inspected `ProjectCostReceiptForm` (FileUpload field, disk `public`, directory `receipts`), `CreateProjectCostReceipt`/`EditProjectCostReceipt` (file-move-and-rename logic into `Attachment.file_path`), and `ViewProjectCostReceipt`'s infolist (`Storage::disk('public')->url($attachment->file_path)`) — all already correct per Filament/Laravel convention, so no application code was changed. Root cause: `public/storage` was a real, empty directory rather than a symlink, so `php artisan storage:link` had previously silently failed ("link already exists") and every attachment URL 404'd. Confirmed the directory was empty (`find -mindepth 1` → 0 entries), removed it, and re-ran `php artisan storage:link`, which created the correct symlink to `storage/app/public`. Verified via tinker against receipt #1's real attachment row: `Storage::disk('public')->url()` now returns a URL whose target file `Storage::disk('public')->exists()` confirms is present, and `readlink -f public/storage` now resolves to `storage/app/public`.

### Changed Files
- None (application code unchanged)
- Filesystem only: removed the stray empty `public/storage` directory and recreated it as a symlink via `php artisan storage:link` (user-confirmed before deletion)

### Verification
- `stat`/`readlink -f public/storage` before fix: real empty directory, not a symlink.
- `php artisan storage:link` before fix: `ERROR The [...\public\storage] link already exists.`
- `find public/storage -mindepth 1 | wc -l` → 0, confirming the directory held nothing before removal.
- After `rmdir` + `php artisan storage:link`: command succeeded ("link has been connected to [...storage/app/public]"); `readlink -f public/storage` now resolves to `storage/app/public`.
- Tinker: `ProjectCostReceipt::find(1)->attachments()->first()` → `file_path = receipts/receive_2_20260706_15000.jpeg`, `Storage::disk('public')->url()` → `http://localhost/storage/receipts/receive_2_20260706_15000.jpeg`, `Storage::disk('public')->exists()` → true.
- No migrations run, no accounting/transaction logic touched.

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Remove the old "المبالغ المرصودة" Filament resource (`/admin/project-cost-budgets`) from the sidebar and routing, without deleting the `project_cost_budgets` table, the `ProjectCostBudget` model, or touching any accounting/disbursement/execution-payment logic.

### Result
Confirmed via `graphify query` + grep that `App\Filament\Resources\ProjectCostBudgets\ProjectCostBudgetResource` (auto-discovered by `AdminPanelProvider::discoverResources()`) was the only class registering `admin/project-cost-budgets` / "المبالغ المرصودة", and that every file under its namespace (`Pages/{List,Create,View,Edit}ProjectCostBudget`, `RelationManagers/PaymentsRelationManager`, `Schemas/ProjectCostBudgetForm`, `Schemas/ProjectCostBudgetInfolist`, `Tables/ProjectCostBudgetsTable`) was referenced only from within that same namespace — no other resource, page, service, or test depended on any of them. Traced the actual data flow and found this resource was a plain generic CRUD directly on `project_cost_budgets` (no transaction lines, no balance updates) — genuinely obsolete, since the real disbursement flow (`ProjectCostBudgetsPaymentResource` / "صرف مبلغ المشروع") already creates every real `project_cost_budgets` row itself inside a balanced `DB::transaction()`. Deleted the entire `app/Filament/Resources/ProjectCostBudgets/` directory (`git rm -r`), then ran `php artisan optimize:clear`, `php artisan filament:optimize`, `php artisan icons:cache`, and `graphify update .`.

### Changed Files
- `app/Filament/Resources/ProjectCostBudgets/ProjectCostBudgetResource.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Pages/ListProjectCostBudgets.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Pages/CreateProjectCostBudget.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Pages/ViewProjectCostBudget.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Pages/EditProjectCostBudget.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/RelationManagers/PaymentsRelationManager.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Schemas/ProjectCostBudgetForm.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Schemas/ProjectCostBudgetInfolist.php` (deleted)
- `app/Filament/Resources/ProjectCostBudgets/Tables/ProjectCostBudgetsTable.php` (deleted)

### Verification
- `php artisan route:list --path=project-cost-budgets` → only the 4 `project-cost-budgets-disbursements` routes remain (the different, untouched resource); no plain `project-cost-budgets` route.
- `php artisan route:list --path=project-cost-budgets-disbursements` → all 4 CRUD routes for "صرف مبلغ المشروع" (`ProjectCostBudgetsPaymentResource`) still registered.
- `php artisan route:list` filtered for `execution-payment` → all 4 CRUD routes for "صرف مبالغ التنفيذ" still registered.
- Tinker: `class_exists(App\Models\ProjectCostBudget::class)` → true; `Schema::hasTable('project_cost_budgets')` → true; `ProjectCostBudget::count()` unaffected (still reflects the pre-existing dev-data purge, 0 rows) — model, table, and data untouched.
- No migrations run, no accounting/transaction/receipt/disbursement/execution-payment logic touched. `ProjectCostBudgetsPaymentResource.php` was only read, never edited (its one comment mentioning `ProjectCostBudgetResource` is a data-provenance note, not a navigation reference, so left as-is per task scope).

### Commit Hash
(not committed yet)

---

### Date
2026-07-06

### Task
Remove the old "تقارير المشاريع" Filament page (`/admin/projects-report-page`) completely from navigation and routing, without touching the current general financial report page, project financial snapshot tables/services, or any accounting logic.

### Result
Confirmed via `graphify query` + grep that `App\Filament\Pages\ProjectsReportPage` (auto-discovered by `AdminPanelProvider::discoverPages()`, no custom route, no manual registration) was the only class registering that URL/label, had no dedicated DB table or migration (it only read `projects`/`projects_costs`/`project_cost_budgets` etc. read-only, correlated subqueries, no writes), and its Blade view (`resources/views/filament/pages/projects-report-page.blade.php`) was used exclusively by this page. No other code, test, or route referenced either file. Deleted both files (`git rm`), then ran `php artisan optimize:clear`, `php artisan filament:optimize`, `php artisan icons:cache`, and `graphify update .`.

### Changed Files
- `app/Filament/Pages/ProjectsReportPage.php` (deleted)
- `resources/views/filament/pages/projects-report-page.blade.php` (deleted)

### Verification
- `php artisan route:list --path=projects-report-page` → no matching routes (confirmed removed).
- `php artisan route:list --path=admin` filtered for report pages → `account-statement`, `comprehensive-financial-transactions`, `donor-financial-report`, `project-financial-details/{project}`, `projects-general-financial-page`, and `trial-balance` all still registered and unaffected.
- `php -l app/Providers/Filament/AdminPanelProvider.php` → no syntax errors (panel provider itself untouched; page removal relies on Filament's directory auto-discovery, so no edit was needed there).
- No migrations run, no DB tables/data touched, no accounting/transaction/receipt/budget/export logic touched.

### Commit Hash

---

### Date
2026-07-14

### Task
Add automatic Arabic `transactions.description` generation to all 6 financial write flows (receipts, project disbursement, execution payments, general expenses, general exchanges, opening balance), via one shared formatter service. Exact format: `دائن: {credit entries} | مدين: {debit entries} | ملخص العملية: {summary}.`, credit before debit, one line, English digits/thousands separators/2 decimals, currency from `transaction_lines.currency_id` (never `account.currency`).

### Result
Implemented and verified. New `app/Services/Transactions/TransactionDescriptionBuilder.php::buildAndSave()` reads a transaction's final active lines (eager-loaded `account`→`withTrashed()` + `currency`), groups credit before debit preserving line-id order, formats/normalizes, throws if either side is empty (rolls back the caller's existing `DB::transaction()`). Each of the 11 Create/Edit pages (opening balance has no edit flow) now builds its own short Arabic summary from final saved relations and calls the builder as the last step inside its existing transaction boundary, before the success notification; edit flows call it again after lines are reversed/rebuilt. `transactions.notes`, `transaction_lines.notes`/`LINE_*` tags, all balance-update/currency/accounting-formula code, and attachment behavior are untouched. No migration (`description` column pre-existed). No historical backfill (explicitly out of scope). Also fixed two unrelated pre-existing migration bugs discovered while adding `RefreshDatabase`-based tests (approved separately mid-task — see DECISIONS_LOG.md 2026-07-14 entries); a third MySQL-only migration incompatibility was found and deliberately left untouched.

### Changed Files
- `app/Services/Transactions/TransactionDescriptionBuilder.php` (new)
- `app/Filament/Resources/ProjectCostReceipts/Pages/CreateProjectCostReceipt.php`, `EditProjectCostReceipt.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/CreateProjectCostBudgetsPayment.php`, `EditProjectCostBudgetsPayment.php`
- `app/Filament/Resources/ExecutionPayments/Pages/CreateExecutionPayment.php`, `EditExecutionPayment.php`
- `app/Filament/Resources/GeneralExpenses/Pages/CreateGeneralExpense.php`, `EditGeneralExpense.php`
- `app/Filament/Resources/GeneralExchanges/Pages/CreateGeneralExchange.php`, `EditGeneralExchange.php`
- `app/Filament/Resources/Accounts/Pages/CreateAccount.php`
- `database/migrations/2026_06_09_130009_create_exchange_rate_history_table.php` (unrelated pre-existing bug fix)
- `database/migrations/2026_06_09_174625_rename_exchange_rate_history_to_exchange_rate_histories.php` (unrelated pre-existing bug fix)
- `database/migrations/2026_06_09_130012_create_accounts_table.php` (unrelated pre-existing bug fix)
- `tests/Unit/Services/TransactionDescriptionBuilderTest.php` (new, 15 tests)

### Verification
- `php -l` on all 14 changed/new PHP files: no syntax errors.
- `tests/Unit/Services/TransactionDescriptionBuilderTest.php`: 15/15 passing, 23 assertions — covers single/multiple credit and debit entries with `؛` separation, currency sourced from the line (not the account, including a deliberately mismatched-currency-account case), `حساب` prefix add/no-duplicate, English digits/thousands/2-decimal formatting, credit-before-debit-before-summary ordering, summary normalization (whitespace collapse, repeated-period collapse, exactly one trailing period), soft-deleted account still renders its name, soft-deleted lines excluded, zero-value lines excluded, missing-credit/missing-debit throws, `buildAndSave` persists to `transactions.description`. Runs against a minimal hand-migrated SQLite schema (not `RefreshDatabase` — see DECISIONS_LOG.md) with `PRAGMA foreign_keys = OFF`.
- Full existing suite (`php artisan test`): 16/17 passing; the 1 failure (`Tests\Feature\ExampleTest`) is the default Laravel welcome-page scaffold test hitting `/` (404, since `resources/views/welcome.blade.php` doesn't exist in this admin-only app) — confirmed pre-existing and unrelated to this change.
- Targeted tinker verification (real dev DB, wrapped in `DB::beginTransaction()`/`DB::rollBack()`, zero residue — transaction count 10 before and after): invoked each flow's real `handleRecordCreation`/`handleRecordUpdate` via reflection with real accounts/currencies/projects/partners. All 6 creates + 5 edits (all except opening balance, which has no edit flow) produced correctly formatted descriptions: credit first, debit second, summary last, correct account names/currency codes/posted amounts, multi-account `؛` separation and mixed-currency handling on the disbursement/exchange flows, correct old→new value swap on every edit (changed amount + changed account both reflected), and the "already starts with حساب" de-dup rule confirmed live against a deliberately `حساب`-prefixed test account name.
- `php artisan migrate:status` checked before and after both migration-file edits: all affected migrations still show their original `Ran` batch numbers (3/4/5/14/19) — confirms zero live DB operations, only the migration **files** changed.
- `php artisan optimize:clear` and `graphify update .` run after implementation.

### Commit Hash
(not committed yet)

---

### Date
2026-07-14

### Task
Close the remaining manual-edit bypass on the raw `TransactionResource` ("المعاملات المالية", `admin/transactions`): first made `description` read-only there, then — after a follow-up read-only audit found it also allowed bare/unbalanced transaction creation and header edits independent of the owning financial record — converted the whole resource into a strictly read-only audit resource (no create/edit/delete/restore/force-delete on transactions or their lines), with authorization-layer hardening, not just hidden buttons. Also applied `defaultSort('id', 'desc')` to both its list table and the lines relation table.

### Result
Implemented and verified. `TransactionResource::getPages()` now only registers `index`/`view`; `create`/`edit` routes no longer exist. `CreateTransaction.php`/`EditTransaction.php` deleted (confirmed unreferenced elsewhere). `TransactionResource` overrides all 8 `can*` authorization methods (`canCreate`, `canEdit`, `canDelete`, `canDeleteAny`, `canRestore`, `canRestoreAny`, `canForceDelete`, `canForceDeleteAny`) to return `false`. `ListTransactions`/`ViewTransaction` no longer have header actions. `TransactionsTable` keeps only `ViewAction` + `defaultSort('id','desc')` (dropped `EditAction`, the `BulkActionGroup`/`DeleteBulkAction`). `LinesRelationManager` keeps its read-only columns/search/sort + `defaultSort('id','desc')` but has zero header/record actions and the same 8 `can*` overrides (as `protected` instance methods, matching `InteractsWithRelationshipTable`'s signatures). `TransactionForm`'s `description` field stays `disabled()->dehydrated(false)` (from the prior same-day fix, unchanged). Navigation entry "المعاملات المالية" stays visible; `TrashedFilter` kept (read-only filter, not a mutation). The 6 legitimate financial flows, `TransactionDescriptionBuilder`, and all accounting/balance/currency logic were not touched.

### Changed Files
- `app/Filament/Resources/Transactions/TransactionResource.php` (pages reduced to index/view; 8 `can*` overrides added)
- `app/Filament/Resources/Transactions/Pages/ListTransactions.php` (removed `CreateAction` header action)
- `app/Filament/Resources/Transactions/Pages/ViewTransaction.php` (removed `EditAction` header action)
- `app/Filament/Resources/Transactions/Tables/TransactionsTable.php` (removed `EditAction`/bulk delete; added `defaultSort('id','desc')`)
- `app/Filament/Resources/Transactions/RelationManagers/LinesRelationManager.php` (removed all header/record mutation actions; added `defaultSort('id','desc')` + 8 `can*` overrides)
- `app/Filament/Resources/Transactions/Schemas/TransactionForm.php` (from the prior fix in this same task — `description` already `disabled()->dehydrated(false)`)
- `app/Filament/Resources/Transactions/Pages/CreateTransaction.php` (deleted, unreachable after route removal)
- `app/Filament/Resources/Transactions/Pages/EditTransaction.php` (deleted, unreachable after route removal)

### Verification
- `php -l` on all 5 changed/kept PHP files: no syntax errors.
- `php artisan route:list --path=transactions`: before → `index`, `create`, `view`, `edit` (4 routes); after → `index`, `view` only (2 routes). `admin/transactions/create` and `admin/transactions/{record}/edit` are no longer registered.
- Headless `Table` inspection via reflection (no full Livewire/HTTP mount needed): `TransactionsTable::configure()` → `getFlatActions()` returns exactly one `Filament\Actions\ViewAction`, `getDefaultSortColumn()`/`Direction()` → `id`/`desc`. `LinesRelationManager::table()` → `getFlatActions()` returns 0 actions, same `id`/`desc` default sort. `ListTransactions`/`ViewTransaction` → `getHeaderActions()` both return `[]`.
- Tinker: `TransactionResource::canCreate()`/`canEdit()`/`canDelete()`/`canDeleteAny()`/`canRestore()`/`canRestoreAny()`/`canForceDelete()`/`canForceDeleteAny()` all return `false`; `shouldRegisterNavigation()` → `true`; `getNavigationLabel()` → "المعاملات المالية" unchanged; `LinesRelationManager::canCreate()` → `false`.
- Re-ran the six-flow tinker verification (rolled back, zero residue, transaction count 10 before/after): byte-identical generated descriptions to the prior verification — confirms the 6 legitimate flows and `TransactionDescriptionBuilder` are completely unaffected by this resource-level change.
- Re-ran `tests/Unit/Services/TransactionDescriptionBuilderTest.php`: 15/15 still passing.
- `php artisan optimize:clear` and `graphify update .` run after implementation. `git diff --check` clean (only a benign CRLF-normalization notice from Git, not a real whitespace error).

### Commit Hash
(not committed yet)

---

### Date
2026-07-14

### Task
Add automatic per-line Arabic `transaction_lines.description` and structured machine-readable `transaction_lines.line_role` across all 6 financial write flows (the line-level counterpart of the same-day `transactions.description` feature), with one centralized role vocabulary, a shared text formatter, no changes to accounting logic/notes/LINE_* tags, no historical backfill — plus hardening the standalone raw `TransactionLineResource` into a read-only audit resource (a line-level integrity bypass surfaced by this task's audit).

### Result
Implemented and verified. New migration adds `description` (nullable text) + `line_role` (nullable string(50)) after `notes` — ran cleanly on the dev DB (single ALTER, no data touched). New `App\Enums\TransactionLineRole` (11 string-backed roles + `arabicLabel()`/`labelFor()`). New `App\Services\Transactions\TransactionLineDescriptionBuilder::buildAndSaveForTransaction()` builds `{مدين|دائن}: حساب {name} ({line currency}) — {posted amount} | الغرض: {purpose}.` per active line, ordered by id, eager-loading account (withTrashed) + currency, throwing inside the caller's DB::transaction() on missing/unknown role, missing purpose, both-sides-positive, negative side, or missing account/currency; zero-amount lines (0% deduction placeholders) are skipped with NULL description (user-approved). Shared trait `FormatsTransactionText` now backs both description builders; `TransactionDescriptionBuilder` output stayed byte-identical. All 6 create + 5 edit flows assign `line_role` explicitly at every line write and call the line builder just before the parent description builder; the receipt edit flow's in-place line updates also set roles (self-heal for pre-feature receipts). `TransactionLineResource` is now read-only: index/view routes only, Create/Edit page classes deleted (confirmed unreferenced), 8 `can*` → false, no mutation actions, `defaultSort('id','desc')`, new columns: line_role Arabic-label badge + searchable/limited description (+notes toggleable); the view form shows both new fields disabled. `LinesRelationManager` gained the same two read-only columns (mutation hardening untouched). Reports/exports/snapshots untouched.

### Changed Files
- `database/migrations/2026_07_14_120000_add_description_and_line_role_to_transaction_lines_table.php` (new)
- `app/Enums/TransactionLineRole.php` (new)
- `app/Services/Transactions/Support/FormatsTransactionText.php` (new trait)
- `app/Services/Transactions/TransactionLineDescriptionBuilder.php` (new)
- `app/Services/Transactions/TransactionDescriptionBuilder.php` (uses the trait; API/output unchanged)
- `app/Models/TransactionLine.php` (fillable += description, line_role)
- `app/Filament/Resources/ProjectCostReceipts/Pages/CreateProjectCostReceipt.php` + `EditProjectCostReceipt.php`
- `app/Filament/Resources/ProjectCostBudgetsPayments/Pages/CreateProjectCostBudgetsPayment.php` + `EditProjectCostBudgetsPayment.php`
- `app/Filament/Resources/ExecutionPayments/Pages/CreateExecutionPayment.php` + `EditExecutionPayment.php`
- `app/Filament/Resources/GeneralExpenses/Pages/CreateGeneralExpense.php` + `EditGeneralExpense.php`
- `app/Filament/Resources/GeneralExchanges/Pages/CreateGeneralExchange.php` + `EditGeneralExchange.php`
- `app/Filament/Resources/Accounts/Pages/CreateAccount.php`
- `app/Filament/Resources/TransactionLines/TransactionLineResource.php` (read-only hardening)
- `app/Filament/Resources/TransactionLines/Pages/ListTransactionLines.php` + `ViewTransactionLine.php` (header actions removed)
- `app/Filament/Resources/TransactionLines/Pages/CreateTransactionLine.php` + `EditTransactionLine.php` (deleted)
- `app/Filament/Resources/TransactionLines/Tables/TransactionLinesTable.php` (ViewAction only, defaultSort, new columns)
- `app/Filament/Resources/TransactionLines/Schemas/TransactionLineForm.php` (view-only line_role/description fields)
- `app/Filament/Resources/Transactions/RelationManagers/LinesRelationManager.php` (2 new read-only columns)
- `tests/Unit/Services/TransactionLineDescriptionBuilderTest.php` (new)

### Verification
- `php -l` on all changed PHP files: no syntax errors.
- `php artisan test tests/Unit/Services`: 33/33 passing (15 existing TransactionDescriptionBuilder exact-string tests — proves byte-identical parent output — + 18 new TransactionLineDescriptionBuilder tests covering both side formats, line-vs-account currency, prefix dedup, digits/separators/decimals, one-final-period + whitespace normalization, soft-deleted account label, soft-deleted line exclusion, zero-line skip, both-positive/negative/missing-role/unknown-role/missing-purpose throws, all 11 roles, notes+parent-description untouched, cross-currency disbursement and exchange).
- Rolled-back tinker verification against real dev-DB data (reflection-invoked real `handleRecordCreation`/`handleRecordUpdate`): all 6 create flows + all 5 edit flows produced correct roles and descriptions (verified side label, `حساب`-prefixed account name, line currency code, posted amount 2dp/thousands, approved purpose wording, one final period, one line); receipt edit self-heal from deliberately-NULLed roles confirmed; disbursement edit with 0% transfer confirmed the zero line keeps NULL description while the other 3 lines render; cross-currency disbursement (USD→ILS) and exchange (USD→EUR) show each line's own currency. Zero residue after rollback: transaction/line/account counts, all account balances, all line notes, and all existing transaction descriptions byte-identical before/after.
- `php artisan route:list --path=transaction-lines`: only `index` + `view` remain (create/edit routes gone).
- `php artisan optimize:clear` + `graphify update .` run after implementation.
- Known limitation (pre-existing, unchanged): full `RefreshDatabase` still blocked by the MySQL-only `2026_06_24_000005_backfill_denormalized_currency_and_amounts.php`; both unit suites use the schema-only SQLite pattern instead. The pre-existing `Tests\Feature\ExampleTest` welcome-page failure also remains (unrelated scaffold test).

### Commit Hash
(not committed yet)

---

### Date
2026-07-15

### Task
Backfill the existing classified transactions/transaction lines so historical data shows the same `description`/`line_role` metadata as the 2026-07-14 live feature, then surface the three approved fields (`وصف العملية المالية`, `دور سطر القيد`, `وصف سطر القيد`) in all detailed financial-movement report sections (Comprehensive Financial Transactions, Account Statement, Project Financial Details, Donor Financial Report) and standardize their labels on the transaction/transaction-line audit resources.

### Result
**Phase 1 (audit).** Confirmed via `TransactionResource`'s own docblock and code trace that every `Transaction` is created only by the 6 legitimate flows — no other write path exists — so classification only needed to identify *which* of the 6 flows produced a given transaction, never guess. Classification priority implemented exactly as specified: (1) a direct `transaction_id` FK on the flow's authoritative domain record (`ProjectCostReceipt`, `ProjectCostBudget`, `ProjectCostBudgetsPayment`, `GeneralExpense`, `GeneralExchange`); (2) `transactions_types.name = 'قيد افتتاحي'` for opening balance (the only flow with no domain record of its own — `Account` stores no `transaction_id`). `transaction_lines.notes` `LINE_*` tags are used only as supporting evidence to map a line to its role *after* the flow is already identified via (1)/(2) — never to identify the flow. Two-line flows (receipt, execution payment, general expense, opening balance) assign roles purely by debit/credit position (always exactly one non-zero debit + one non-zero credit line by construction); four-line flows (disbursement, general exchange) map by their existing `LINE_SOURCE`/`LINE_ADMIN`/`LINE_TRANSFER`/`LINE_DESTINATION` notes tags. Dev DB audit: 10 active transactions / 24 active lines, all 24 lines `line_role IS NULL`, 6 transactions `description IS NULL` (created before the description feature) and 4 opening-balance transactions already had a *non-canonical* older description format (`"قيد افتتاحي للحساب: {name}"` vs. the current `"تسجيل الرصيد الافتتاحي لحساب {name}"`) — correctly flagged for regeneration since it differs from what the live builder produces today. 0 unclassified transactions, 0 unclassified lines, 2 legitimate zero-amount lines (0% admin/transfer deduction placeholders on one disbursement/exchange pair).

**Phase 2 (backfill command).** New `php artisan transactions:backfill-descriptions` (`--dry-run` / `--apply`, mutually exclusive; usage shown and no writes if neither given; optional `--transaction-id=`/`--chunk=200`). New `App\Services\Transactions\Backfill\TransactionFlowClassifier` (classification + per-flow role/purpose/summary resolution, reusing the exact approved wording already live in the 6 Create pages) and `ClassifiedTransaction` DTO, both under a new `app/Services/Transactions/Backfill/` namespace — no changes to the 6 live flows themselves. Minimal additive refactor to `TransactionLineDescriptionBuilder`: extracted a new public `describeLine()` method (same validation/generation logic, callable without saving) so the backfill command reuses the exact same code path as the live flows instead of re-deriving it; `buildAndSaveForTransaction()`'s behavior is unchanged (verified by the existing 18 tests still passing byte-for-byte). The command classifies each active transaction (bulk-fetching all 5 domain tables + eager-loading lines per chunk — no N+1), assigns `line_role`, computes `transaction_lines.description` via `describeLine()` (zero-amount lines always resolve to `NULL`) and `transactions.description` via the existing `TransactionDescriptionBuilder::build()`, and only calls `->save()` (in `--apply` mode, each transaction inside its own `DB::transaction()`) when a value actually differs from what's stored — Eloquent's dirty-check means an already-canonical row issues no UPDATE at all, making the command naturally idempotent. Never touches soft-deleted rows (default Eloquent scopes), amounts, notes, balances, or creates/deletes any row.

**Execution.** `--dry-run`: 10/10 transactions classified (opening_balance=4, disbursement=1, execution_payment=1, general_expense=2, general_exchange=1, receipt=1), 0 unclassified, 0 failed, 10 transactions would update (including the 4 opening-balance ones with stale descriptions), 24 lines would update, 2 zero-amount lines correctly seen. Spot-checked generated text for 4 representative transactions (opening balance, disbursement, general exchange with its 2 zero-amount deduction lines, receipt) — all matched the approved wording exactly. `--apply` run 1: identical counts, all persisted. `--apply` run 2 (idempotence check): 0 transactions updated, 0 lines updated, 10/24 unchanged — proves idempotence. Integrity snapshot (captured before run 1, compared after run 2): transaction count, line count, account count, every `accounts.current_balance`, and every transactions/transaction_lines field listed in the task's "unchanged fields" list (id, transaction_number, transaction_time, fiscal_year_id, transaction_type_id, partner_id, reference, notes, deleted_at / id, transaction_id, account_id, currency_id, amount_currency, debit_base, credit_base, fx_rate, notes, deleted_at) were byte-identical before/after.

**Phase 3 (report display).** Added the three fields to the detailed movement sections of all four reports — additively, never replacing an existing field, never changing a calculation/total/balance:
- **Comprehensive Financial Transactions**: added `transaction_description`/`line_role_label`/`line_description` to the service's per-line row array (dash-normalized for NULL); screen table gained 3 new columns (line-clamped preview + tooltip for the two long-text fields, badge for role); Excel gained 3 new columns (M–O, wrapped/fixed-width, existing A–L unchanged); Word's previously flat 11-column detail table was restructured into one block per transaction (header line + وصف العملية المالية paragraph + an 8-column line-detail subtable including the 2 new fields) — the only structural (not just additive) change in this task, made because a 14-column flat table would not fit A4 portrait.
- **Account Statement**: existing `description` column (already `transactions.description`) relabeled "الوصف" → "وصف العملية المالية" (same data, no logic change); added `line_role_label`/`line_description` as 2 new columns/cells across the screen table, Excel (J–K), and Word.
- **Project Financial Details**: this report's grain is one row per domain record (receipt/budget/payment), not one row per line, so per the task's own grain-preservation rule the parent description was added as a same-grain column and each row's active transaction lines were attached as a nested collapsible `<details>` widget on screen (and a nested Word subtable) rather than exploding the report's row grain. `costs` (no transaction) and `deductions` (a computed breakdown of an already-shown budget) were left untouched. No Excel export exists for this report (only Word) — confirmed via `Glob`, so none was added.
- **Donor Financial Report**: the `movements` array (already a merged receipt+budget+payment timeline) gained `transaction_description` + a `lines` array per movement; screen table and Word got a per-movement expandable/nested line-detail widget; Excel (which is genuinely one-row-per-movement, not one-row-per-line) got 2 new columns — `وصف العملية المالية` and a wrapped multi-line "{role}: {description}" summary per accounting line, keeping the existing movement grain rather than exploding it into a new row-per-line worksheet. All existing donor totals, the collection percentage, and every deduction calculation were left untouched — confirmed unchanged by re-reading `buildCostSummary`/`buildDisbSourceSummary`/`buildDisbFinalSummary`, none of which were touched.
Trial Balance, all summary cards, dashboard totals, the Projects General Financial Report summary, percentage cards, and collection-rate calculations were explicitly not touched, per task scope.

**Audit resource labels.** Standardized on `TransactionForm` (`description` → "وصف العملية المالية"), `LinesRelationManager`, `TransactionLineForm`, and `TransactionLinesTable` (`line_role` → "دور سطر القيد", `description` → "وصف سطر القيد"). Both resources remain strictly read-only (confirmed via `route:list`: index/view only, no create/edit/delete routes) and default-sorted `id desc`, unchanged from the 2026-07-14 hardening.

### Changed Files
- `app/Console/Commands/BackfillTransactionDescriptions.php` (new)
- `app/Services/Transactions/Backfill/TransactionFlowClassifier.php` (new)
- `app/Services/Transactions/Backfill/ClassifiedTransaction.php` (new)
- `app/Services/Transactions/Backfill/TransactionClassificationException.php` (new)
- `app/Services/Transactions/TransactionLineDescriptionBuilder.php` (additive `describeLine()` extraction; `buildAndSaveForTransaction()` behavior unchanged)
- `tests/Feature/Commands/BackfillTransactionDescriptionsCommandTest.php` (new, 17 tests)
- `app/Services/Reports/ComprehensiveFinancialTransactionsReportService.php`, `ComprehensiveFinancialTransactionsExcelExportService.php`, `ComprehensiveFinancialTransactionsWordExportService.php`
- `resources/views/filament/pages/comprehensive-financial-transactions-page.blade.php`
- `app/Services/Reports/AccountStatementReportService.php`, `AccountStatementExcelExportService.php`, `AccountStatementWordExportService.php`
- `resources/views/filament/pages/account-statement-page.blade.php`
- `app/Filament/Pages/ProjectFinancialDetailsPage.php`, `app/Services/Reports/ProjectFinancialDetailsWordExportService.php`
- `resources/views/filament/pages/project-financial-details-page.blade.php`
- `app/Services/Reports/DonorFinancialReportService.php`, `DonorFinancialReportExcelExportService.php`, `DonorFinancialReportWordExportService.php`
- `resources/views/filament/pages/donor-financial-report-page.blade.php`
- `app/Filament/Resources/Transactions/Schemas/TransactionForm.php`, `app/Filament/Resources/Transactions/RelationManagers/LinesRelationManager.php`
- `app/Filament/Resources/TransactionLines/Schemas/TransactionLineForm.php`, `app/Filament/Resources/TransactionLines/Tables/TransactionLinesTable.php`

### Verification
- `php -l` on every changed/new PHP file: no syntax errors. `php artisan view:cache` (precompiles all Blade, including the 4 changed pages): succeeded with no errors, then `view:clear` to leave dev state as found.
- `php artisan test`: 52/52 relevant tests passing (17 new backfill tests + 33 existing description/role-builder tests + 2 pre-existing unrelated), plus the one pre-existing unrelated `ExampleTest` `/` 404 failure (confirmed via `git stash`/re-run to already fail identically with zero of this task's changes applied).
- Backfill dry-run/apply/re-apply/integrity-snapshot sequence against real dev data as described above under Result.
- Tinker smoke test generating one real sample export per updated report/format (Comprehensive Excel+Word, Account Statement Excel+Word, Project Financial Details Word, Donor Excel+Word — 7 files) directly from each report service's live output against real dev data; every file re-opened successfully (`PhpSpreadsheet::load()` for `.xlsx`, `ZipArchive::open()` + `word/document.xml` presence check + `PHPWord::load()` for `.docx`) and printed sample field values confirming `transaction_description`/`line_role_label`/`line_description` are populated correctly end-to-end.
- `php artisan route:list --path=transactions` / `--path=transaction-lines`: confirmed still only `index`/`view` routes on both resources (no regression to the 2026-07-14 read-only hardening).
- `php artisan optimize:clear` and `graphify update .` run after implementation. `git diff --check`: clean.

### Commit Hash
(not committed yet)

---

### Date
2026-07-18

### Task
Read-only discovery/design (Phase 1), followed by approved focused implementation, followed by this completion pass: build automated test coverage for the previously-implemented `App\Services\Validation\FinancialTransactionBalanceGuard` and its 10-file wiring, without changing the approved accounting implementation. Cover the guard directly with unit tests (malformed payloads only, never by weakening production code), add focused integration tests for all 5 financial workflows proving the guard is correctly wired (valid Create/Edit still succeed; any rejection happens before mutation; counts/balances unchanged; Edit rejection leaves old lines/balances untouched), add an explicit `EditProjectCostReceipt` regression (it updates existing lines in place rather than recreating them), run the full test suite in a specified order, and confirm by targeted search that no naive cross-currency `SUM()` check exists, every one of the 10 paths validates before `DB::transaction()`, every path inserts/updates the exact validated payload with no recalculation, no `<= 0.01` tolerance exists anywhere, and no accounting formula or delete/reversal logic was touched.

### Result
While writing the unit tests it became clear the guard (as it stood after the prior implementation pass) did not yet check several things the requested test matrix explicitly required: `amount_currency` positivity/match-to-active-side, `fx_rate` positivity (and `== 1` for single-currency lines), missing-required-key detection, missing/duplicate `line_role`, wrong role-direction (a line's actual debit/credit side vs. its role's expected side), and matching an authoritative `notes` tag. These are payload-structure/consistency rules, not accounting formulas — closing them is "completing the guard," not "changing the accounting implementation" — so `FinancialTransactionBalanceGuard.php` was extended. This extension needed **zero changes** to any of the 10 production Create/Edit files — confirmed by running their existing 47 tests before writing a single new test, and again after. Then added: 34 unit tests (`FinancialTransactionBalanceGuardTest`, hand-built line arrays via two small private factories, no database) covering every COMMON PAYLOAD / SINGLE-CURRENCY / MULTI-CURRENCY case in the request, including a same-currency example proving role-direction is a genuinely independent check from the balance-sum check (numerically balanced but swapped roles); 22 integration tests (`BalanceGuardIntegrationTest`, one class per workflow, real reflection-invoked `handleRecordCreation`/`handleRecordUpdate`/`mutateFormDataBeforeFill` against the same selectively-migrated SQLite schema pattern as the existing `*AccountValidationTest`/`ExecutionPaymentCreditAccountTest` files) proving valid Create/Edit still complete end-to-end with the guard now in the pipeline, and that rejection (triggered via the earliest-reachable upstream guard — a zero amount for the 3 single-currency workflows, combined percentages of 100% for the 2 multi-currency workflows, since the balance guard's own math is provably unreachable from valid user input by design — it re-verifies what the same already-validated inputs just produced, as a defense against a future code regression, not against bad input) leaves transaction/line counts and every account balance completely unchanged; plus 2 dedicated `EditProjectCostReceipt` regression tests proving the exact validated update payload lands on the same `TransactionLine` row IDs (not recreated) and that a rejected edit leaves both existing lines' `account_id`/`currency_id`/`amount_currency`/`debit_base`/`credit_base`/`line_role` completely untouched (no partial update). Ran the 6-step sequence requested (guard unit tests → new integration tests → existing amount-validation tests → existing account-validation tests → existing five-workflow tests → full suite) — see Verification below for exact counts. Targeted-search confirmation (Phase 4 of the request): grepped `app/` for any `debit_base`/`credit_base` sum-equality pattern outside the guard (none found — only two unrelated `=== 0.0 && === 0.0` null-line checks in description builders); grepped all 10 files and confirmed every `FinancialTransactionBalanceGuard::assert*` call's line number is lower than that file's `DB::transaction(` line number; grepped and confirmed all 10 files insert/update via the literal validated array (`TransactionLine::create($line + [...])` in 9 files, `?->update($lineUpdates[...])` in `EditProjectCostReceipt`) with no recomputation in between; grepped for `abs(` in the guard (zero matches) and for `<= 0.01` anywhere touched (only pre-existing, unrelated `FinancialAmountGuard` minimum-amount wording); grepped and confirmed the `round($original * $adminPct / 100, 2)` / `round($afterDeduct * $fxRate, 2)` formula lines are byte-identical to before, in all 4 locations; confirmed zero diff on any `*Table.php` delete/reversal method (`git diff --stat`/`git status` against those files: empty).

**Correction (same day, before commit):** the first version of the `fx_rate` fix above defaulted a *missing* `fx_rate` to `1.0` rather than requiring it, specifically because `EditProjectCostReceipt`'s in-place `->update()` payload never included that key. This was flagged as conflicting with the approved requirement that no financial value be silently assumed, and as a real risk: a historically-corrupted stored `fx_rate` (not actually `1`) would never be checked against its true stored value, only against the guard's assumed `1.0`. Fixed by restoring `fx_rate` to `FinancialTransactionBalanceGuard::REQUIRED_KEYS` (rejected outright if missing — in both `assertValidLinePayload()` and, independently, `assertBalancedSingleCurrencyLines()`, with no fallback anywhere left in the guard) and by adding `'fx_rate' => 1` explicitly to both the debit and credit arrays in `EditProjectCostReceipt::buildReceiptLineUpdates()` — the same explicit value every other line-write payload in the codebase already carried. Confirmed by grep that this was the *only* one of the 10 workflow files missing an explicit `fx_rate` in its validated payload. A side effect (now covered by a dedicated test): a valid receipt Edit now normalizes any historically-corrupted stored `fx_rate` back to `1`, since the update payload always writes it explicitly; a rejected Edit still leaves the old line — corrupted `fx_rate` included — completely untouched, since validation happens before any mutation. Added 2 more guard unit tests (missing-`fx_rate` rejection, in both `assertValidLinePayload()` and `assertBalancedSingleCurrencyLines()`) and 1 more `ProjectCostReceipts` integration test (corrupt-then-reject-then-valid-normalizes); extended two existing integration tests to also assert on `fx_rate`. No accounting formula, balance-update formula, migration, or delete/reversal logic changed by this correction — same as the pass it corrects. Committed together with this documentation update.

### Changed Files
- `app/Services/Validation/FinancialTransactionBalanceGuard.php` (extended — required-keys including `fx_rate`, `amount_currency`/`fx_rate` value checks with no fallback, role presence/uniqueness/direction, optional notes-tag matching; no change to the balance equations themselves)
- `app/Filament/Resources/ProjectCostReceipts/Pages/EditProjectCostReceipt.php` (correction only: `'fx_rate' => 1` added explicitly to both update payload arrays)
- `tests/Unit/Services/Validation/FinancialTransactionBalanceGuardTest.php` (new, 36 tests)
- `tests/Feature/ProjectCostReceipts/BalanceGuardIntegrationTest.php` (new, 7 tests, incl. the update-in-place and fx_rate-normalization regression tests)
- `tests/Feature/ExecutionPayments/BalanceGuardIntegrationTest.php` (new, 4 tests)
- `tests/Feature/GeneralExpenses/BalanceGuardIntegrationTest.php` (new, 4 tests)
- `tests/Feature/ProjectCostBudgetsPayments/BalanceGuardIntegrationTest.php` (new, 4 tests)
- `tests/Feature/GeneralExchanges/BalanceGuardIntegrationTest.php` (new, 4 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set — also backfills documentation for the two prior undocumented passes: the read-only design/discovery and the initial guard implementation, both same-day and previously uncommitted)
- No other production workflow file (`app/Filament/Resources/*/Pages/Create*.php`/`Edit*.php`) was touched — the other 9 files carrying the original implementation's diff are unchanged since that pass.

### Verification
Exact command sequence and final results, in the requested order (after the correction above):
1. `php artisan test tests/Unit/Services/Validation/FinancialTransactionBalanceGuardTest.php` → **36/36 passed, 36 assertions**.
2. `php artisan test tests/Feature/ProjectCostReceipts/BalanceGuardIntegrationTest.php` → **7/7 passed, 48 assertions**.
3. `php artisan test tests/Feature/ProjectCostReceipts tests/Feature/ExecutionPayments tests/Feature/GeneralExpenses tests/Feature/ProjectCostBudgetsPayments tests/Feature/GeneralExchanges` (every test file for the 5 workflows, old and new together) → **70/70 passed, 285 assertions**.
4. `php artisan test` (full suite) → **209/210 passed, 554 assertions; 1 failure**: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (expects 200 on `/`, gets 404) — confirmed pre-existing and unrelated: the file is untouched by `git status`/`git diff`, and every prior task's log entry back to 2026-07-15 records this exact same single failure on this exact same test.
- `php -l` clean on the guard file, `EditProjectCostReceipt.php`, and all 6 test files.
- Targeted-search verification (Phase 4 of the original request, re-confirmed after the correction) all hold: no naive cross-currency sum, validate-before-transaction in all 10 files, exact-payload insert/update with no recomputation in all 10 files, zero `abs()`/`<= 0.01` tolerance in the guard, formula lines byte-identical, zero diff on any delete/reversal method. `fx_rate` is now confirmed explicit in every one of the 10 validated payloads (grep), with no default/fallback anywhere left in `FinancialTransactionBalanceGuard.php`.
- `php artisan optimize:clear` and `graphify update .` run after implementation and again after the correction.

### Commit Hash
`validate transaction balance with multi-currency support` — see `git log` for the hash.

---

### Date
2026-07-19

### Task
Implement OMS permissions foundation (Task 1 of the roles-and-permissions phase, following the 2026-07-19 read-only audit — see PROMPTS_LOG). Build the permission registry, `Gate::before` Super Admin bypass, an idempotent `oms:sync-permissions` command, and immediately restrict the currently wide-open `UserResource` to Super Admin only, as an emergency lockdown pending the full safe user-management phase (Task 3). Explicitly out of scope: RoleResource/PermissionResource, protecting the other 24 resources, Filament Shield, any migration, last-Super-Admin deletion logic.

### Result
Added `App\Support\Permissions\PermissionRegistry` — the single source of truth for all 154 permission names (`module.action` convention), their Arabic labels, module grouping, and `defaultPermissionsForRole()` for the 5 system roles, built strictly from the confirmed inventory (19 SoftDelete-CRUD modules + `users` = 20, 2 CRUD-without-SoftDelete modules, 3 read-only modules including `permissions`, `roles` module, 6 report pages × view/export, `users.assign_super_admin`). Added `App\Services\Permissions\PermissionSyncService` + `php artisan oms:sync-permissions`: creates missing permissions and the 5 system roles inside one `DB::transaction()`, reconciles each system role's permissions to the registry's defaults on every run, never deletes a permission, never touches any role outside the 5 system-role names, clears the Spatie permission cache at the end. Added a central `Gate::before` in `AppServiceProvider::boot()` — bypasses only for `hasRole('Super Admin')`, returns `null` (not `false`) otherwise so normal Spatie checks still run, never bypasses a guest, calls only `hasRole()` (no `can()`) to avoid recursion. Added `App\Policies\UserPolicy` — every ability hardcoded `false` (an explicit temporary lockdown, not a real permission check); Laravel auto-discovers it for `App\Models\User` via the standard `App\Models\X` → `App\Policies\XPolicy` convention, so `UserResource` and its 4 Pages needed **zero code changes** — confirmed by reading Filament's own `HasAuthorization`/`CanAuthorizeResourceAccess` source: `canViewAny()`/`canCreate()`/`canEdit()`/`canView()` all delegate to the policy once one exists, and every resource page's `mount()` already calls `abort_unless(canX(), 403)`. Refactored `DatabaseSeeder` to call `PermissionSyncService::sync()` instead of its old inline coarse `"{action} {module}"` grid + per-role `syncPermissions()` calls; the old coarse permissions (e.g. `"view finance"`) are left in the database untouched — confirmed via tinker against the real local DB after running the command (178 total permissions = 154 new + 24 old coarse ones still present, 0 deleted). `TransactionResource`/`TransactionLineResource`'s hardcoded `canCreate()/canEdit()/canDelete() = false` overrides never call `Gate`, so they are unaffected by the bypass — added a regression test proving this explicitly for a Super Admin.

A real environment issue was hit and resolved during test-writing (documented in AI_PROJECT_MEMORY.md): full HTTP round-trips via `$this->get('/admin/users')` don't preserve `actingAs()` in this environment (traced to `Filament\Http\Middleware\Authenticate` seeing the panel guard as unauthenticated on the fresh kernel dispatch, via `withoutExceptionHandling()`), and separately `route()`/`url()` bake the local `APP_URL` (`.../oms/public`) into generated URLs that the test client then mis-resolves. This is pre-existing and unrelated to this change — the entire prior 209-test suite never issues a real HTTP GET against a Filament panel route, for the same reason. `UserResourceLockdownTest` instead asserts directly against `UserResource::canViewAny()/canCreate()/canEdit()/canView()/canDelete()` — the exact same authorization decision Filament's `abort_unless(canX(), 403)` uses in production.

### Changed Files
- `app/Support/Permissions/PermissionRegistry.php` (new)
- `app/Services/Permissions/PermissionSyncService.php` (new)
- `app/Console/Commands/SyncPermissions.php` (new — `oms:sync-permissions`)
- `app/Policies/UserPolicy.php` (new — temporary Super-Admin-only lockdown)
- `app/Providers/AppServiceProvider.php` (added `Gate::before` Super Admin bypass in `boot()`)
- `database/seeders/DatabaseSeeder.php` (refactored: old coarse permission/role seeding replaced with `PermissionSyncService::sync()`; old permissions not deleted)
- `tests/Feature/Permissions/SuperAdminGateBypassTest.php` (new, 4 tests)
- `tests/Feature/Permissions/SyncPermissionsCommandTest.php` (new, 6 tests)
- `tests/Feature/Permissions/SystemRoleDefaultPermissionsTest.php` (new, 5 tests)
- `tests/Feature/Users/UserResourceLockdownTest.php` (new, 4 tests)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)
- No migration added. No existing Filament Resource/Page other than `UserResource`'s authorization (via the new Policy, not a file edit) was touched.

### Verification
1. New permission-foundation tests: `php artisan test --filter="SuperAdminGateBypassTest|SyncPermissionsCommandTest|SystemRoleDefaultPermissionsTest|UserResourceLockdownTest"` → **19/19 passed, 420 assertions**.
2. Full suite: `php artisan test` → **228/229 passed, 974 assertions; 1 failure**: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` — confirmed pre-existing/unrelated, the same single failure recorded in every prior task's log entry back to 2026-07-15.
3. `php artisan oms:sync-permissions` run against the real local database (after tests passed): 1st run — 154 permissions created, 0 found, 5 roles found (0 created — already present from an earlier partial seed), permissions assigned: Super Admin 154, Admin 140, Accountant 48, Project Manager 20, Viewer 46. 2nd run (idempotency check) — 0 permissions created / 154 found, 0 roles created / 5 found, identical per-role counts.
4. Post-sync tinker check against the real local DB: 178 total permissions (154 new + 24 pre-existing coarse ones, 0 deleted); 5 roles total; noted the local DB has no actual `superadmin@oms.com` user yet (the 5 roles pre-existed from an earlier partial seed run, but `DatabaseSeeder::run()` — which creates that user — has not been executed here) — informational only, out of this task's scope.
5. `php -l` clean on all 8 new/changed PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction — real admin email + completed real HTTP authorization tests)

### Task
Correction to the same-day permissions-foundation task, before commit. The prior pass's local-DB check for a seeded Super Admin user searched for `superadmin@oms.com` (the seeder's hardcoded email) and reported "no" — user corrected this: the actual administrator account is `oms@oms.com`, and the earlier negative result must not be treated as proof no Super Admin exists. Requested: (1) a read-only re-check of `oms@oms.com` (existence, not-soft-deleted, exact `Super Admin` role, `Gate::before` recognition, `UserResource` access) against the real local DB, with no writes and no new admin account; (2) inspect and report `DatabaseSeeder`'s current admin-seeding behavior without changing it unless clearly required (and report first if so); (3) a useful `oms:sync-permissions` warning when zero users hold the Super Admin role, without ever creating a user from that command; (4) completing the previously-punted real HTTP-level 403/200 tests for `UserResource` (the prior pass had substituted direct `canX()` assertions after hitting an environment quirk) — not staged, not committed.

### Result
Read-only tinker check against the real local DB (zero writes: only `User::withTrashed()->where(...)->first()`, `hasRole()`, `Gate::allows()`, `UserResource::canViewAny()/canCreate()`, `Auth::setUser()`/`forgetUser()` — the latter two are in-process only, no DB/session write) confirmed all 4 requested facts: `oms@oms.com` exists and is not soft-deleted; it holds the exact role `Super Admin` (and only that role); `Gate::before` recognizes it (an arbitrary, unregistered permission string was allowed — proof the bypass, not a coincidental real permission, is what granted it); `UserResource::canViewAny()`/`canCreate()` both return true for it. `DatabaseSeeder::seedSuperAdmin()` was inspected and found unchanged from before this task's first pass (only the `assignRole()` argument was ever a constant-reference change, same value) — it hardcodes `User::firstOrCreate(['email' => 'superadmin@oms.com'], ...)`, a **different** email than the real `oms@oms.com` admin. This is a pre-existing mismatch, not introduced by this task: running the seeder as-is today would create a second, independent Super Admin account rather than recognizing `oms@oms.com`. Per instructions, this was **not changed** — reported only (see the new DECISIONS_LOG entry for the proposed fix, pending approval).

Found the real root cause of the earlier HTTP-testing environment quirk (previously worked around, not fixed): `Filament\Http\Middleware\Authenticate::authenticate()` has a hardcoded rule, independent of any role/permission — if the user model doesn't implement `Filament\Models\Contracts\FilamentUser`, the panel is only reachable when `config('app.env') === 'local'` (vendor source: `abort_if($user instanceof FilamentUser ? ... : (config('app.env') !== 'local'), 403)`). `App\Models\User` doesn't implement that interface, and PHPUnit runs with `APP_ENV=testing`, so *every* request — including an authenticated Super Admin — was 403ing before Gate/Policy ever ran; this had nothing to do with sessions or `actingAs()`. Forcing `config(['app.env' => 'local'])` in the test's `setUp()` (test-only, zero production files touched) reaches the actual authorization layer. Rewrote `UserResourceLockdownTest` to issue real `$this->get('/admin/users')` /`/create`/`/{id}`/`/{id}/edit` requests and assert `assertForbidden()`/`assertOk()` directly, replacing the prior `canX()`-only assertions. Added the requested sync-command warning: `PermissionSyncService::sync()` now also returns a read-only `super_admin_user_count` (via `Role::where('name', 'Super Admin')->first()?->users()->count()`, no write), and `oms:sync-permissions` prints an Arabic warning when it's 0 — the command still never creates or assigns a user.

### Changed Files
- `app/Services/Permissions/PermissionSyncService.php` (added read-only `super_admin_user_count` to `sync()`'s return; no new writes)
- `app/Console/Commands/SyncPermissions.php` (prints a warning when `super_admin_user_count === 0`)
- `tests/Feature/Users/UserResourceLockdownTest.php` (rewritten: real HTTP `$this->get()` + `assertForbidden()`/`assertOk()` instead of direct `canX()` assertions, with the `app.env`/`URL::forceRootUrl` fix documented in its class docblock)
- `tests/Feature/Permissions/SyncPermissionsCommandTest.php` (2 new tests: warns when 0 Super Admin users and creates none; no warning once one exists)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md` (this entry set)
- `database/seeders/DatabaseSeeder.php` — **not changed** in this correction (inspected and reported only, per instructions)
- No migration added. No administrator credentials added, changed, or exposed. No write to the real local database.

### Verification
1. Read-only tinker check against the real local DB (see Result above) — all 4 requested facts confirmed, zero writes.
2. Permission-foundation + UserResource suite: `php artisan test --filter="SuperAdminGateBypassTest|SyncPermissionsCommandTest|SystemRoleDefaultPermissionsTest|UserResourceLockdownTest"` → **21/21 passed, 416 assertions** (19 from the first pass + 2 new warning tests), including the 3 now-real-HTTP `UserResourceLockdownTest` cases (non-Super-Admin 403 on list/create/view/edit; Super Admin 200 on all 4).
3. Full suite: `php artisan test` → **230/231 passed, 970 assertions; 1 failure** — the same pre-existing/unrelated `ExampleTest` failure recorded in every prior task's log entry back to 2026-07-15.
4. Post-run tinker re-check against the real local DB: user count, `oms@oms.com`/`superadmin@oms.com` presence, permission count (178), and role count (5) all identical to before this correction — confirms no write occurred (all new HTTP tests run against the isolated `:memory:` SQLite test schema, never the real database).
5. `php -l` clean on all changed/new PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction 2 — FilamentUser panel access + duplicate-Super-Admin-safe seeder)

### Task
Two focused corrections to the same-day permissions-foundation work, before commit. (1) The prior correction's real HTTP tests required a test-only `config(['app.env' => 'local'])` because `App\Models\User` doesn't implement `Filament\Models\Contracts\FilamentUser` — flagged as masking a real production issue (Filament rejects every user in any non-`local` environment without that interface). Required implementing `FilamentUser::canAccessPanel()` on `User` (entry-only, no permission logic, no hardcoded emails/IDs/roles, equivalent to `! $this->trashed()`) and removing the test workaround, with the real HTTP tests still passing under normal `APP_ENV=testing`. (2) `DatabaseSeeder::seedSuperAdmin()` still targets `superadmin@oms.com` while the real admin is `oms@oms.com` — required the smallest safe fix: skip bootstrap-user creation entirely when any non-soft-deleted user already holds the `Super Admin` role, never modify an existing administrator, preserve clean-install behavior otherwise, and keep `oms:sync-permissions` creating zero users. No migrations, no redesign, no staging/commit.

### Result
`App\Models\User` now `implements Filament\Models\Contracts\FilamentUser` with `canAccessPanel(Panel $panel): bool { return ! $this->trashed(); }` — panel entry no longer depends on `config('app.env')` in any environment; all actual authorization (who can do what once inside) remains entirely with `Gate::before` + `App\Policies\UserPolicy` + future Resource/Page policies, unchanged. Removed `config(['app.env' => 'local'])` from `UserResourceLockdownTest::setUp()`; all 4 of its real-HTTP tests (403 for a normal user on list/create/view/edit, 200 for Super Admin) pass unmodified under the suite's normal `APP_ENV=testing`. `DatabaseSeeder::seedSuperAdmin()` now opens with `if (User::role(PermissionRegistry::SUPER_ADMIN)->exists()) { return; }` — `User::role()` is Spatie's query scope and inherits the model's default SoftDeletes scope, so a soft-deleted former Super Admin correctly does **not** block bootstrap creation, while any active one (regardless of email) does. When no Super Admin exists, behavior is byte-identical to before (same `firstOrCreate(['email' => 'superadmin@oms.com'], ...)`, same `Hash::make('password123')`, same `assignRole()`) — no new credentials introduced. Confirmed via a dedicated test that an existing `oms@oms.com` Super Admin is left completely untouched (email, name, password hash, `updated_at`, and role set all unchanged) after running the full seeder.

### Changed Files
- `app/Models/User.php` (implements `FilamentUser`, adds `canAccessPanel()`)
- `database/seeders/DatabaseSeeder.php` (`seedSuperAdmin()` now skips bootstrap creation if any non-soft-deleted Super Admin already exists)
- `tests/Feature/Users/UserResourceLockdownTest.php` (removed the `app.env` workaround; docblock rewritten to explain the real fix)
- `tests/Feature/Users/UserFilamentAccessTest.php` (new, 4 tests — implements-contract, panel access across 4 environments, soft-deleted rejection, confirms `app.env` stays `testing`)
- `tests/Feature/Permissions/DatabaseSeederSuperAdminTest.php` (new, 8 tests — empty-DB bootstrap, hashed password, idempotent re-run, existing-admin-blocks-bootstrap, existing-admin-untouched, soft-deleted-doesn't-block, sync-permissions warning behavior both ways)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md` (this entry set)
- No migration added. No real database user created, modified, or deleted. No credentials exposed.

### Verification
1. `UserResourceLockdownTest` alone → **4/4 passed, 10 assertions** (no `app.env` override, normal `APP_ENV=testing`).
2. `DatabaseSeederSuperAdminTest` alone → **8/8 passed, 20 assertions**.
3. `SyncPermissionsCommandTest` alone → unchanged, still passing (covered in the combined run below).
4. All permissions-related tests together (`SuperAdminGateBypassTest|SyncPermissionsCommandTest|SystemRoleDefaultPermissionsTest|UserResourceLockdownTest|UserFilamentAccessTest|DatabaseSeederSuperAdminTest`) → **33/33 passed, 444 assertions**.
5. Full suite: `php artisan test` → **242/243 passed, 998 assertions; 1 failure** — the same pre-existing/unrelated `ExampleTest` failure recorded in every prior task's log entry back to 2026-07-15.
6. Post-run tinker re-check against the real local DB: user count (2), `oms@oms.com` present with `Super Admin` role, `superadmin@oms.com` absent, 178 permissions, 5 roles — all identical to before this correction, confirming zero writes to the real database.
7. `grep -rn "app.env.*local" tests/` confirms the only remaining match is the explanatory docblock comment in `UserResourceLockdownTest.php`, not a config override.
8. `php -l` clean on all changed/new PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (correction 3 — no hardcoded bootstrap credential + soft-delete-safe seeding)

### Task
Final focused security correction to the same-day permissions-foundation work, before commit. Flagged: `DatabaseSeeder` still contained a hardcoded bootstrap administrator email/password (`superadmin@oms.com` / `password123`) — unacceptable in source control for a permissions/security task — plus an unhandled soft-delete edge case (`users.email` is unique; if the bootstrap email already existed as a soft-deleted user, `firstOrCreate()` would hit a duplicate-email constraint instead of recovering it). Required: a small config-backed bootstrap-admin setting (email/name/password, all from environment variables, no default password), reading env through config rather than directly; when an active Super Admin already exists, skip entirely; when none exists, require the config (fail with a clear `RuntimeException`, no password exposed, no partial user) and handle three cases via `withTrashed()` lookup by the configured email — no existing row (create), active existing row (promote only, no credential overwrite), soft-deleted existing row (restore + reset password, no duplicate row). 12 specific tests requested. No migrations, no redesign, no staging/commit.

### Result
Added `config/oms.php` with a single `bootstrap_admin` array (`email`/`name`/`password`, all `env()`-sourced, `password` has no default — `name` defaults to `'Super Admin'` since it isn't sensitive). `DatabaseSeeder::seedSuperAdmin()` rewritten: (1) unchanged early-exit guard when any active Super Admin already exists; (2) reads `config('oms.bootstrap_admin.*')`, throws `RuntimeException` with a message naming only the required env var names (never a value) if email or password is blank, before touching the database; (3) `User::withTrashed()->where('email', $email)->first()` to find any prior row under that email; (4) no row → `User::create()` + `Hash::make($password)` + `assignRole()`; (5) active row → `assignRole()` only, zero field writes (name/email/password/`updated_at` all untouched — confirmed by test); (6) soft-deleted row → `restore()` + reassign `password` (freshly hashed) + `save()` + `assignRole()`, never a second `INSERT`. Confirmed via `grep -rn "password123|superadmin@oms.com" app/ database/seeders/ config/` → zero matches. `.env.example` documents the three new optional `OMS_BOOTSTRAP_ADMIN_*` variables (all blank placeholders, no real value, matching the existing pattern for other optional/sensitive vars like `AWS_SECRET_ACCESS_KEY`). `DatabaseSeederSuperAdminTest` fully rewritten around a synthetic `bootstrap-admin@test.local` / `Correct-Horse-Battery-Staple-1` test fixture (13 tests, none touching the real database) covering every required case, including a dedicated test that the exception message never contains the configured password string.

### Changed Files
- `config/oms.php` (new — `bootstrap_admin.{email,name,password}`, all `env()`-sourced)
- `database/seeders/DatabaseSeeder.php` (`seedSuperAdmin()` rewritten: config-driven, `withTrashed()` lookup, restore-not-duplicate, `RuntimeException` on missing config)
- `.env.example` (added `OMS_BOOTSTRAP_ADMIN_EMAIL`/`_NAME`/`_PASSWORD`, all blank)
- `tests/Feature/Permissions/DatabaseSeederSuperAdminTest.php` (rewritten, 13 tests — was 8)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/PROMPTS_LOG.md` (this entry set)
- No migration added. `.env` (the real local environment file) was **not** read or modified — only `.env.example`, a template with no real values. No real database user created, modified, or deleted. No credential exposed anywhere (source, test output, or exception messages).

### Verification
1. `DatabaseSeederSuperAdminTest` alone → **13/13 passed, 42 assertions**.
2. `SyncPermissionsCommandTest` alone → **8/8 passed, 171 assertions**.
3. `UserResourceLockdownTest` alone → **4/4 passed, 10 assertions**.
4. All permissions-related tests together (`SuperAdminGateBypassTest|SyncPermissionsCommandTest|SystemRoleDefaultPermissionsTest|UserResourceLockdownTest|UserFilamentAccessTest|DatabaseSeederSuperAdminTest`) → **38/38 passed, 466 assertions**.
5. Full suite: `php artisan test` → **247/248 passed, 1020 assertions; 1 failure** — the same pre-existing/unrelated `ExampleTest` failure recorded in every prior task's log entry back to 2026-07-15.
6. `grep -rn "password123|superadmin@oms.com" app/ database/seeders/ config/` → no matches (confirms no hardcoded bootstrap credential remains in source).
7. Post-run tinker re-check against the real local DB: user count (2), `oms@oms.com` present with `Super Admin`, `superadmin@oms.com` absent, 178 permissions, 5 roles — all identical to before this correction, confirming zero writes to the real database.
8. `php -l` clean on all changed/new PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-19 (OMS Permissions Task 2A — Filament Resource/RelationManager authorization)

### Task
Protect all existing Filament Resources and RelationManagers using the Task 1 permission foundation (`PermissionRegistry`, `PermissionSyncService`, `Gate::before` Super Admin bypass). Explicitly scoped to Resource/RelationManager CRUD authorization only — custom report-page/export authorization is deferred to Task 2B. Must not redesign Task 1, install Filament Shield, add migrations, or touch financial formulas/queries/posting logic/forms/table layouts. Must respect the confirmed correction from the Task 2 discovery audit: `ExecutionPaymentResource`'s model is `App\Models\ProjectCostBudgetsPayment` (permission prefix `execution_payments`) and `ProjectCostBudgetsPaymentResource`'s model is the *different* `App\Models\ProjectCostBudget` (permission prefix `project_cost_budgets_payments`) — the two do not share a table, so there is no row overlap, but the prefix-to-model mapping is intentionally non-obvious and had to be documented in code, not just in a report.

### Result
Added a reusable `App\Policies\Concerns\AuthorizesCrud` trait (viewAny/view/create/update/delete/deleteAny/restore/restoreAny hardcoded to a single `<module>.<operation>` permission check; forceDelete/forceDeleteAny hardcoded `false` unconditionally — force delete stays Super-Admin-only via the existing `Gate::before` bypass, never a normal permission) and 23 concrete Policy classes (one per protected model), each declaring only its `permissionModule()` string — Laravel's standard model→policy auto-discovery convention (already proven working for `UserPolicy` in Task 1) wires them in with zero explicit registration. `TransactionPolicy`/`TransactionLinePolicy` additionally override `mutable(): false`, so create/update/delete/restore stay hardcoded `false` regardless of any permission grant — mirroring, not replacing, `TransactionResource`/`TransactionLineResource`'s own pre-existing hardcoded `canX()` overrides (both layers now independently enforce the same read-only-audit rule). `ProjectCostBudgetPolicy`/`ProjectCostBudgetsPaymentPolicy` carry explicit docblocks warning that their permission prefixes are deliberately swapped relative to what their class names suggest, per the confirmed Task 2 discovery mapping. Soft-delete rule (built into the shared trait, applied uniformly): `view()`/`update()`/`delete()` deny a trashed record for ordinary (mutable) resources — `view()` stays permission-gated but trashed-blind for the 2 read-only audit resources, since viewing trashed audit history is the point; `restore()`/`restoreAny()` require `<module>.restore` AND only ever apply to an already-trashed record. No Resource or RelationManager source file needed any authorization code added — confirmed empirically (not assumed) that Filament resolves RelationManager `canCreate()`/`canEdit()`/`canDelete()`/`canView()` against the *related* model's own policy (read from `vendor/filament/filament/.../InteractsWithRelationshipTable.php::getAuthorizationResponse()`, then proven via `Livewire::test()` against all 5 existing RelationManagers), so protecting each model once via its Policy automatically protects both its standalone Resource and every RelationManager built on it — e.g. viewing a Project (`projects.view`) does not grant `project_costs.create` via `Projects/CostsRelationManager`, because that RelationManager's authorization checks against `ProjectCostPolicy`, not `ProjectPolicy`.

### Changed Files
- `app/Policies/Concerns/AuthorizesCrud.php` (new — shared CRUD/soft-delete/force-delete-lockout trait)
- `app/Policies/{Account,AccountType,Attachment,BankType,Currency,ExchangeRateHistory,FiscalYear,GeneralExchange,GeneralExpense,Partner,PartnerType,Project,ProjectCost,ProjectCostBudget,ProjectCostBudgetsPayment,ProjectCostReceipt,ProjectStatus,ProjectSuper,Setting,Transaction,TransactionLine,TransactionSuperType,TransactionType}Policy.php (23 new files — one per protected model; see the model→policy→permission-prefix map in this session's final report)
- `tests/Unit/Policies/PolicyDiscoveryTest.php` (new, 24 tests — proves `Gate::getPolicyFor()` resolves every one of the 23 models to its intended Policy class and `permissionModule()`, with dedicated emphasis on the two swapped-prefix models; no DB needed)
- `tests/Feature/Permissions/CrudPolicyBehaviorTest.php` (new, 161 assertions across a 23-module data provider — view_any/view/create/update/delete/restore each require their own permission and are never granted by another; soft-delete rules; force-delete never grantable; read-only modules stay immutable regardless of permission)
- `tests/Feature/Permissions/ResourceHttpAuthorizationTest.php` (new, 68 tests — real HTTP 403/200 on index/create across all 23 protected resources; navigation-eligibility source-level check with the one documented `ProjectCostResource` exception; Super Admin access-all)
- `tests/Feature/Permissions/ExecutionPaymentBudgetDisbursementScopeTest.php` (new, 5 tests — the `execution_payments`/`project_cost_budgets_payments` pair specifically: each permission grants only its own workflow via real HTTP; a real numeric-id collision across the two physically separate tables never cross-exposes; a budget-only id 404s via the execution-payments route; a soft-deleted execution payment is blocked on its normal Edit route)
- `tests/Feature/Permissions/RelationManagerAuthorizationTest.php` (new, 8 tests via `Livewire::test()` — proves all 4 CRUD-capable RelationManagers (Projects/Costs, ProjectCosts/Budgets, ProjectCosts/Receipts, Currencies/ExchangeRateHistory) authorize against their related model's own permission module, independent of the parent resource's permissions)
- No migration added. No Resource, RelationManager, Page, Form, Table, or financial service/query file was modified. No custom report-page or export authorization implemented (deferred to Task 2B, per instructions).

### Verification
1. `PolicyDiscoveryTest` alone → **24/24 passed, 49 assertions**.
2. `CrudPolicyBehaviorTest` alone → **161/161 passed, 435 assertions**.
3. `ResourceHttpAuthorizationTest` alone → **68/68 passed, 157 assertions, 2 skipped** (the 2 skips are the read-only Transaction/TransactionLine resources' nonexistent create route, by design).
4. `ExecutionPaymentBudgetDisbursementScopeTest` alone → **5/5 passed, 24 assertions**.
5. `RelationManagerAuthorizationTest` alone → **8/8 passed, 24 assertions**.
6. Entire `tests/Feature/Permissions` namespace together (new + all pre-existing: `SuperAdminGateBypassTest`, `SyncPermissionsCommandTest`, `SystemRoleDefaultPermissionsTest`, `DatabaseSeederSuperAdminTest`) → **273/275 passed, 1092 assertions, 2 skipped** (same 2 as above).
7. `tests/Feature/Users/UserResourceLockdownTest.php` alone (untouched by this task) → **4/4 passed, 10 assertions** — `UserResource`/`UserPolicy` lockdown confirmed unaffected.
8. Full suite: `php artisan test` → **513/516 passed, 1709 assertions; 1 failure** — the same pre-existing/unrelated `ExampleTest` failure (hits `/`, a route this Filament app never defines) recorded in every prior task's log entry back to 2026-07-15; 2 skipped (same as above). All 5 financial-workflow test namespaces (`ExecutionPayments`, `ProjectCostBudgetsPayments`, `GeneralExpenses`, `GeneralExchanges`, `ProjectCostReceipts`) passed unmodified within this run, confirming zero impact on financial create/edit/delete logic.
9. `git status --short` / `git diff --stat`: every change is a **new, untracked file** — zero modifications to any existing tracked file (confirms no Resource/RelationManager/financial source was touched).
10. `php -l` clean on all 29 new PHP files.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-20 (OMS Permissions Task 2B — custom report page and export authorization)

### Task
Protect all six custom Filament report pages (`AccountStatementPage`, `TrialBalancePage`, `DonorFinancialReportPage`, `ComprehensiveFinancialTransactionsPage`, `ProjectsGeneralFinancialPage`, `ProjectFinancialDetailsPage`) against unauthorized sidebar visibility, direct URL access, unauthorized Excel/Word export buttons, and direct Livewire/action invocation of export methods — using the pre-existing `reports.<page>.view`/`reports.<page>.export` permissions already registered in `PermissionRegistry`. Explicitly out of scope: Task 2A Resource Policies, User/Role/Permission management pages, any report calculation/query/filter/export-service/format change, migrations, package installs.

### Result
Added one reusable trait, `App\Filament\Pages\Concerns\AuthorizesReportAccess`, `use`d by all 6 report pages. Each page explicitly declares its own `reportViewPermission()`/`reportExportPermission()` strings (no name-based mapping). The trait's `canAccess(): bool` overrides Filament's `Concerns\CanAuthorizeAccess::canAccess()`, which every `Filament\Pages\Page` already wires into `mountCanAuthorizeAccess()`/`hydrateCanAuthorizeAccess()` (abort 403 on initial mount **and** on every subsequent Livewire request for that component) and into the static `Page::registerNavigationItems()` (checked after `shouldRegisterNavigation()`, so navigation visibility tracks the same permission automatically). `authorizeReportExport()` is called as the literal first statement of every export method (`exportExcel()`/`exportWord()`/`exportXlsx()`, 11 methods across the 6 pages), `abort_unless`-ing on both the view **and** export permission — independent of `canAccess()` having already run for this request and independent of the header action's `visible()` state, so a crafted Livewire request calling the export method directly is rejected before any file is generated. UI layer: every export header `Action` gained `->visible(fn (): bool => $this->canExportReport())`. `ProjectFinancialDetailsPage::$shouldRegisterNavigation = false` was left completely untouched — `Page::registerNavigationItems()` checks that flag before `canAccess()`, so this page stays permanently absent from the sidebar regardless of who is signed in, while its direct `{project}` route is now fully permission-gated. No report calculation, query, filter, export-service class, filename, Excel/Word formatting, Arabic label, or navigation label/group/icon/sort was changed; no Task 2A Resource Policy was touched; no migration was added.

### Changed Files
- `app/Filament/Pages/Concerns/AuthorizesReportAccess.php` (new — shared canAccess()/authorizeReportExport() trait)
- `app/Filament/Pages/AccountStatementPage.php` (permission declarations, `->visible()` on both export actions, `authorizeReportExport()` in `exportExcel()`/`exportWord()`)
- `app/Filament/Pages/TrialBalancePage.php` (same pattern)
- `app/Filament/Pages/DonorFinancialReportPage.php` (same pattern)
- `app/Filament/Pages/ComprehensiveFinancialTransactionsPage.php` (same pattern)
- `app/Filament/Pages/ProjectsGeneralFinancialPage.php` (same pattern, single `exportXlsx()` action)
- `app/Filament/Pages/ProjectFinancialDetailsPage.php` (same pattern, single `exportWord()` action; `shouldRegisterNavigation = false` preserved unchanged)
- `tests/Feature/Reports/ReportPageAccessTest.php` (new, 30 tests — real HTTP 403/200 for all 6 pages' direct routes, navigation `assertSee`/`assertDontSee` against the Dashboard sidebar for the 5 nav-registered pages, cross-report permission isolation, Super Admin access-all, `ProjectFinancialDetailsPage`'s permanently-disabled navigation flag)
- `tests/Feature/Reports/ReportExportAuthorizationTest.php` (new, 28 tests — `assertActionHidden`/`assertActionVisible` UI-layer proof, `Livewire::test()->call('exportMethod')->assertForbidden()` server-layer bypass proof for both permission-separation directions, `Notification::assertNotified()` proving the exact pre-existing `canExport()` warning text is unchanged for all 4 filter-driven reports, `hasSubmitted`-resets-on-filter-change regression, Super Admin export invocation)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)
- No migration added. No report calculation/query/filter/export-service/format file was modified. No Task 2A Resource Policy was modified. No User/Role/Permission management page was built (remains pending).

### Verification
1. `php -l` clean on all 7 new/changed PHP files (the trait + 6 pages).
2. `ReportPageAccessTest` alone → **30/30 passed, 44 assertions**.
3. `ReportExportAuthorizationTest` alone → **28/28 passed, 77 assertions**.
4. Full `php artisan test` → **574 tests, 571 passed, 1830 assertions, 1 failure, 2 skipped** — the 1 failure is the same pre-existing/unrelated `ExampleTest` (hits `/`, a route this Filament app never defines) recorded in every prior task log entry back to 2026-07-15; the 2 skips are the pre-existing Task 2A read-only-resource skips. All existing Task 1/2A permissions tests, existing report-adjacent tests, and existing financial-workflow tests passed unmodified within this run.
5. `git status --short` / `git diff --stat`: only the trait file, the 6 page files, the 2 new test files, the 4 docs files, and `graphify-out/**` changed — zero modification to any Resource Policy, export service, report service, financial form/table, or migration.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-20 (OMS Permissions Task 3 — secure user management)

### Task
Replace the Task-1 hardcoded `UserPolicy` (Super-Admin-only via `Gate::before`) with granular `users.*` permissions and a full safety layer for `UserResource`: `users.is_active` migration, `UserManagementService` as the authoritative mutation layer, privilege-subset target protection (`canManageUser`), safe role assignment, self-protection, last-active-Super-Admin locking, safe single-record delete/restore with no force-delete/bulk actions anywhere, and `is_active`-aware `DatabaseSeeder`/`oms:sync-permissions` recovery logic. Explicitly out of scope: `RoleResource`/`PermissionResource` (Task 4/5), Task 2A Resource Policies, Task 2B report authorization, any financial logic.

### Result
Added `database/migrations/2026_07_20_000000_add_is_active_to_users_table.php` (`boolean('is_active')->default(true)`, no index). `User` gained the `is_active` boolean cast, added it to `#[Fillable(...)]`, added `protected $attributes = ['is_active' => true]` (needed because Eloquent doesn't reflect a DB column default onto an in-memory instance after `create()` — this was the root cause of a cascading 403 discovered while writing this task's own tests), and `canAccessPanel()` is now `! trashed() && is_active`. New `App\Services\Users\UserManagementService` is the single authoritative mutation layer, wrapping every mutation in `DB::transaction()`: `canManageUser(actor, target)` is a full effective-privilege-subset comparison (target must not hold the exact `Super Admin` role, must hold no protected permission — `users.assign_super_admin` exact or any `roles.*`/`permissions.*` prefix — and every one of the target's effective permissions, role-derived + direct via `getAllPermissions()`, must also be held by the actor) — gating name/email/password/is_active changes, not just role edits; a Super Admin actor always passes. `assignableRoleNames(actor)` is the separate, narrower "which new role may be added" check, applied only to *added* roles on an already-manageable target. Self-edit is a fully separate path that never calls `canManageUser`: `roles`/`is_active` absent from submitted data preserves the current value; present-and-different rejects with an Arabic `ValidationException` (crafted-request self-elevation defense), while name/email/password remain freely self-editable. The last-active-Super-Admin check performs a real locking read (`lockForUpdate()->get(['users.id'])`, counted in PHP, inside the same transaction as the mutation) rather than a locked `count()`, and only runs on the branch that could reduce the active count. `restoreUser()` never touches `is_active`; the only place in the codebase allowed to reactivate a user as part of a restore is `DatabaseSeeder::seedSuperAdmin()`'s bootstrap-recovery path, which bypasses the service entirely. `UserPolicy` was rewritten to granular `users.*` checks + `canManageUser`/self logic (`forceDelete`/`forceDeleteAny` stay hardcoded `false`, documented as defense-in-depth only, since `Gate::before` bypasses the Policy for a real Super Admin — the actual guarantee is that `UserResource` never registers a `ForceDeleteAction`/`ForceDeleteBulkAction` at all). `UsersTable`/`EditUser` wire their single-record `DeleteAction`/`RestoreAction` directly to the service (never `$record->delete()`/`$record->restore()`), with `->visible()` hiding self/last-active-admin for UX only; all bulk actions (`DeleteBulkAction`/`RestoreBulkAction`) were removed — single-record delete/restore only. `UserForm`'s roles `Select` no longer uses `->relationship()` (the service owns `syncRoles()`), options are `assignableRoleNames(actor)` merged with the record's current roles; password no longer pre-hashes via `dehydrateStateUsing(bcrypt(...))` (the model's `'password' => 'hashed'` cast is the single hashing point), gained a non-dehydrated `password_confirmation` sibling validated via `->confirmed()`. `DatabaseSeeder::seedSuperAdmin()`'s guard is now `is_active`-aware and its recovery logic has 4 branches (no user / soft-deleted / inactive-but-present / already-active), and `PermissionSyncService::superAdminUserCount()` likewise counts only active Super Admins (still read-only, never creates/updates a user).

### Changed Files
- `database/migrations/2026_07_20_000000_add_is_active_to_users_table.php` (new)
- `app/Models/User.php` (`is_active` cast/fillable/model-default, `canAccessPanel()`)
- `app/Services/Users/UserManagementService.php` (new — authoritative mutation layer)
- `app/Policies/UserPolicy.php` (granular `users.*` + `canManageUser`/self logic, replacing the Task-1 hardcoded lockdown)
- `app/Filament/Resources/Users/Schemas/UserForm.php` (roles `Select` without `->relationship()`, `is_active` toggle, password confirmation, single-hash password)
- `app/Filament/Resources/Users/Pages/CreateUser.php` (`handleRecordCreation` → service)
- `app/Filament/Resources/Users/Pages/EditUser.php` (`handleRecordUpdate` → service, `mutateFormDataBeforeFill` for roles, header `DeleteAction` → service)
- `app/Filament/Resources/Users/Tables/UsersTable.php` (`is_active` column, `TrashedFilter`, single-record `DeleteAction`/`RestoreAction` → service, no bulk actions)
- `app/Filament/Resources/Users/UserResource.php` (`getRecordRouteBindingEloquentQuery()` override for trashed-record routes)
- `database/seeders/DatabaseSeeder.php` (`is_active`-aware guard + 4-branch bootstrap recovery)
- `app/Services/Permissions/PermissionSyncService.php` (`is_active`-aware `superAdminUserCount()`)
- `tests/Feature/Users/UserResourceLockdownTest.php` (deleted — superseded by the files below)
- `tests/Feature/Users/UserPanelAccessTest.php`, `UserResourceAuthorizationTest.php`, `PrivilegeSubsetProtectionTest.php`, `SuperAdminProtectionTest.php`, `SelfProtectionTest.php`, `RoleAssignmentSafetyTest.php`, `UserFormBehaviorTest.php` (new)
- `tests/Feature/Permissions/DatabaseSeederSuperAdminTest.php`, `SyncPermissionsCommandTest.php` (extended with `is_active`-aware cases)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/NEXT_STEPS.md` (this entry set)
- No `RoleResource`/`PermissionResource` created. No Task 2A Resource Policy or Task 2B report-authorization file modified. No financial logic touched.

### Verification
1. `php -l` clean on all new/changed PHP files (migration, model, service, policy, form, pages, table, resource, seeder, sync service).
2. `tests/Feature/Users` alone → **64/64 passed, 133 assertions**.
3. `tests/Feature/Permissions` alone (Task 1/2A/2B + seeder/sync extensions) → **278 tests, 276 passed, 1103 assertions, 2 skipped, 0 failed**.
4. Full `php artisan test` → **634 tests, 631 passed, 1960 assertions, 1 failure, 2 skipped** — the 1 failure is the same pre-existing/unrelated `ExampleTest` (hits `/`, a route this Filament app never defines) recorded in every prior task log entry back to 2026-07-15, confirmed to fail identically on a clean `git stash` of this task's changes (see below). All financial-workflow tests passed unmodified within this run.
5. Confirmed the `ExampleTest` failure pre-exists on `main`: `git stash -u`, re-ran `php artisan test --filter=ExampleTest` (same 404 failure on the clean baseline), then `git stash pop` to restore all Task 3 changes — verified restored via `git status --short`.
6. `git status --short` / `git diff --stat`: only the files listed above changed; no unrelated financial/report/resource file touched.
7. The new migration was never run against the real local database — only against the in-memory SQLite test database already configured in `phpunit.xml` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), inside each test's own `setUp()`, exactly like every other test in this suite.
8. Attempted a read-only check of the real local database (`oms@oms.com` existence, `users.is_active` column presence) via `php artisan tinker`; the local MySQL server was not running/reachable in this environment, so no connection could be made — this itself confirms no read or write occurred against it during this task.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-20 (OMS Permissions Task 4 — secure role management)

### Task
Create a Filament `RoleResource` (`النظام` → `الأدوار`) allowing authorized users to view roles, create/edit/delete safe custom roles, and assign permissions through grouped Arabic checkboxes — while the five system roles remain visible but structurally protected (not renameable/editable/deletable through this resource, including for a real Super Admin, since `oms:sync-permissions` remains their sole authority). Explicitly out of scope: `PermissionResource` (Task 5), `UserResource` redesign, financial logic, report authorization, migrations, Filament Shield.

### Result
Added `App\Services\Roles\RoleManagementService` (`isSystemRole`, `canManageRole`, `assignablePermissionNames`, `createRole`/`updateRole`/`deleteRole`, all transactional) as the single authoritative mutation layer, mirroring `UserManagementService`'s architecture. `canManageRole()` checks system-role status and actor-self-assignment unconditionally before any Super-Admin branch (using only `hasRole()`/permission-name arrays — never `Gate`/`can()` — so it is never bypassed by `Gate::before`), then requires every permission the role currently holds to be both non-protected and held by the actor for a non-Super-Admin. Protected permissions: exact `users.assign_super_admin`, or any name prefixed `roles.`/`permissions.` (exact/prefix match only). `createRole`/`updateRole`/`deleteRole` each require the matching `roles.*` ability, re-fetch the role fresh, reject protected/duplicate names, revalidate every submitted permission against `assignablePermissionNames()`, reject a role currently assigned to any user on delete, and call `PermissionRegistrar::forgetCachedPermissions()`. New `App\Policies\RolePolicy` (`viewAny`/`view`/`create` = ability only; `update`/`delete` = ability + `canManageRole()`) is explicitly registered via `Gate::policy(Role::class, RolePolicy::class)` in `AppServiceProvider::boot()`, since Spatie's `Role` model lives outside `App\Models` and is never found by Laravel's naming-convention auto-discovery (confirmed by tracing `Gate::guessPolicyName()`). Because `Gate::before` bypasses `RolePolicy` entirely for a real Super Admin, the actual system-role protection is structural: `RoleResource::canEdit()`/`canDelete()` call `RoleManagementService::canManageRole()` directly (bypassing Gate/Policy) alongside a plain ability check, and every table/page Edit/Delete action's `->visible()` calls those same overridden methods rather than relying on Filament's default action-authorization (which resolves through the Gate and would otherwise inherit the bypass). New `app/Filament/Resources/Roles/` (`RoleResource`, `Schemas/RoleForm`, `Tables/RolesTable`, `Pages/{ListRoles,CreateRole,ViewRole,EditRole}`) under nav group `النظام`, label `الأدوار`. Permission checkboxes are one collapsible Section + `CheckboxList` per `PermissionRegistry::groups()` module, bound to nested `permissions.{module}` form state and flattened to a single list by the Create/Edit pages before reaching the service (deliberately not `->relationship()`-backed); any DB `Permission` outside the registry appears under an additional `صلاحيات مخصصة` group labelled by its technical name and is always merged into a role's options so editing never silently drops it. Table shows name, system/custom badge, users/permissions counts, timestamps; View/Edit/Delete actions only (no bulk, no force-delete); system roles show View only.

### Changed Files
- `app/Services/Roles/RoleManagementService.php` (new)
- `app/Policies/RolePolicy.php` (new)
- `app/Providers/AppServiceProvider.php` (explicit `Gate::policy(Role::class, RolePolicy::class)` registration)
- `app/Filament/Resources/Roles/RoleResource.php` (new)
- `app/Filament/Resources/Roles/Schemas/RoleForm.php` (new)
- `app/Filament/Resources/Roles/Tables/RolesTable.php` (new)
- `app/Filament/Resources/Roles/Pages/ListRoles.php`, `CreateRole.php`, `ViewRole.php`, `EditRole.php` (new)
- `tests/Feature/Roles/RoleManagementServiceTest.php` (new, 28 tests)
- `tests/Feature/Roles/RolePolicyTest.php` (new, 9 tests)
- `tests/Feature/Roles/RoleResourceHttpTest.php` (new, 8 tests)
- `tests/Feature/Roles/RoleResourceLivewireTest.php` (new, 8 tests)
- `tests/Feature/Roles/SystemRoleProtectionTest.php` (new, 23 tests)
- `tests/Feature/Roles/SyncCompatibilityTest.php` (new, 4 tests)
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md` (this entry set)
- No migration added. No `PermissionResource` created. No `UserResource`/`UserManagementService` file modified. No financial or report-authorization file modified.

### Verification
Run in the requested order:
1. `RoleManagementServiceTest` alone → **28/28 passed, 44 assertions**.
2. `RolePolicyTest` alone → **9/9 passed, 12 assertions**.
3. `RoleResourceHttpTest` alone → **8/8 passed, 14 assertions**.
4. `RoleResourceLivewireTest` alone → **8/8 passed, 25 assertions**.
5. `SystemRoleProtectionTest` alone → **23/23 passed, 46 assertions**.
6. `SyncCompatibilityTest` alone → **4/4 passed, 16 assertions**.
7. `tests/Feature/Permissions` + `tests/Feature/Roles` together → **358 tests, 356 passed, 1260 assertions, 2 skipped** (same 2 pre-existing Task 2A skips).
8. `tests/Feature/Users` + `tests/Unit/Policies` (existing Task 1–3 suites) → **88/88 passed, 182 assertions, 1 risky** (pre-existing, unrelated to this task).
9. All financial workflow directories (`ExecutionPayments`, `GeneralExchanges`, `GeneralExpenses`, `ProjectCostBudgetsPayments`, `ProjectCostReceipts`, `Reports`, `Commands`) → **165/165 passed, 532 assertions**.
10. Full `php artisan test` → **714 tests, 711 passed, 2117 assertions, 1 failure, 2 skipped** — the 1 failure is the same pre-existing/unrelated `ExampleTest` (hits `/`, a route this Filament app never defines) recorded in every prior task log entry back to 2026-07-15; confirmed to also fail identically on a clean `git stash` of this task's changes.
11. `php -l` clean on all 11 new/changed PHP files.
12. `git status --short` / `git diff --stat`: only the files listed above changed; no financial/report/UserResource file touched.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-20 (OMS Permissions Task 4 — pre-commit authorization verification)

### Task
Verify, before any commit, that `RoleResource::canEdit()`/`canDelete()` require **both** the operation ability (`roles.update`/`roles.delete`) **and** `RoleManagementService::canManageRole()` — not `canManageRole()` alone — and add explicit tests proving every combination of the two checks, including the Super-Admin-eligible-custom-role case and the rejected-operation-leaves-data-unchanged case.

### Result
Read the exact current implementations of `RoleResource::canEdit()`/`canDelete()`, `RolePolicy::update()`/`delete()`, and the Edit/Delete `->visible()` closures in `RolesTable`, `EditRole`, and `ViewRole` — all four already combined `$user->can('roles.update'|'roles.delete')` with `canManageRole()`/`canManageRole() && ! $record->users()->exists()`; **no production-code correction was needed**. Added `tests/Feature/Roles/RoleOperationAndTargetSafetyTest.php` (8 tests) proving explicitly: (1) canManageRole()-satisfied-but-missing-`roles.update` → 403 on the Edit URL; (2) same actor doesn't see the Edit action (table + View page); (3) canManageRole()-satisfied-but-missing-`roles.delete` → Delete hidden and independently rejected by the service; (4) `roles.update` alone does not permit editing a role whose permissions exceed the actor's; (5) `roles.delete` alone does not permit deleting such a role; (6) an actor holding both the ability and a satisfied `canManageRole()` can edit and delete an eligible custom role end-to-end; (7) Super Admin can edit/delete an eligible custom role but is still 403'd on every system role's direct Edit URL and structurally blocked by `canEdit()`/`canDelete()`; (8) a rejected update-then-delete attempt (ability present, `canManageRole()` failing) leaves the role's name, permission pivots, and user pivots completely unchanged. Two ordering bugs were found and fixed while writing tests 6 and 7: `RoleResource::canEdit()/canDelete()` read `auth()->user()` internally, so asserting their return value **before** calling `$this->actingAs($actor)` in a test always evaluates against no authenticated user — moved the assertions after `actingAs()` in both tests.

### Changed Files
- `tests/Feature/Roles/RoleOperationAndTargetSafetyTest.php` (new, 8 tests)
- `graphify-out/**` (regenerated via `graphify update .`)
- `docs/TASKS_LOG.md` (this entry)
- No production file changed — `RoleResource.php`, `RolePolicy.php`, `RolesTable.php`, `EditRole.php`, `ViewRole.php` were read and confirmed already correct, not modified.

### Verification
1. `RoleOperationAndTargetSafetyTest` alone → **8/8 passed, 44 assertions** (2 test-ordering bugs found and fixed during this pass, both in the test file only).
2. `RolePolicyTest` + `RoleResourceHttpTest` + `RoleResourceLivewireTest` + `SystemRoleProtectionTest` together → **48/48 passed, 97 assertions**.
3. `tests/Feature/Roles` (all Role tests, including the new file) → **88/88 passed, 201 assertions**.
4. `tests/Feature/Permissions` (all Permissions tests) → **278 tests, 276 passed, 1103 assertions, 2 skipped** (same 2 pre-existing Task 2A skips).
5. Full `php artisan test` → **722 tests, 719 passed, 2161 assertions, 1 failure, 2 skipped, 1 risky** — the 1 failure is the same pre-existing/unrelated `ExampleTest`; the 1 risky test is likewise pre-existing.
6. `git status --short` / `git diff --stat`: only the new test file, `graphify-out/**`, and this doc entry changed — zero modification to `RoleResource.php`/`RolePolicy.php`/`RolesTable.php`/`EditRole.php`/`ViewRole.php`/any other production file.
7. No migration run. No real database written to (SQLite in-memory test DB only, as with every other test in this suite). No `PermissionResource` created.

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-21 (OMS Task 6A — private attachment security foundation)

### Task
Three-part task, run across a read-only audit and two implementation passes, all under explicit "do not migrate/move/copy/delete any real attachment file, do not run the new migration against the real database, do not touch the five financial Resources yet" constraints: (1) audit the existing polymorphic `Attachment` model/relationships, all 5 financial workflows' `FileUpload` fields, storage disks/URLs, every display/download/delete code path, real DB/storage state, unauthenticated-access exposure, permissions, soft-delete behavior, export/report exposure, and existing test coverage, then design a smallest-safe-change plan; (2) implement the approved plan's foundation only — private `attachments` disk, a transitional `disk` column, an `AttachmentStorageService`, a single authenticated `AttachmentController` route, parent-policy reuse, and focused tests — explicitly excluding the five-Resource cutover and the legacy-file migration; (3) apply five mandatory security corrections found necessary before approval (harden the standalone `AttachmentResource`, validate stored file paths defensively, sanitize response filenames, fix soft-deleted-parent handling to avoid a 403-vs-404 information leak, and document the six orphaned public files as requiring a later quarantined migration) — then finalize, re-verify, document, and commit.

### Result
**Audit**: confirmed the 5 financial workflows (`ProjectCostReceipt`, `ProjectCostBudget` disbursements, `ProjectCostBudgetsPayment` execution payments, `GeneralExpense`, `GeneralExchange`) each create a polymorphic `Attachment` row via a near-identical `storeAttachment()` in their Create/Edit pages, uploading to the `public` disk and displaying via raw `Storage::disk('public')->url()` HTML — meaning any attachment URL was directly guessable and reachable with zero authentication or authorization once known; no soft-delete cascade existed from a trashed parent (or a soft-deleted `Attachment` itself) to its physical file; the standalone `AttachmentResource` (nav `النظام`) had zero real rows but its own `FileUpload` resolved to the pre-existing `local` disk, itself incidentally served unauthenticated by Laravel's built-in `serve => true` signed-URL route.

**Foundation**: new private `attachments` disk (`storage/app/private/attachments`, `visibility: private`, `serve: false`, no `url` key, never symlinked); new `disk` column on `attachments` (default `'public'`, so every existing row keeps its true location without a backfill statement) with `Attachment::APPROVED_DISKS = ['public', 'attachments']` as the single allowlist `AttachmentStorageService::resolveDisk()` ever checks before calling `Storage::disk()`; one authenticated route (`GET /attachments/{attachment}/{mode}`, numeric id + `view`/`download` only, Filament's own `Authenticate::class` middleware — chosen over the bare `auth` alias specifically because this app has no route literally named `login`, only the panel-scoped one, and because it also runs the same `canAccessPanel()` check every other admin request goes through) whose controller authorizes strictly via `Gate::authorize('view', $parent)` against each attachment's actual parent record — reusing the 5 existing per-model policies unchanged, no new `attachments.*`-style permission introduced for this route.

**Corrections**: `AttachmentResource` now mirrors `PermissionResource`'s exact structural pattern — hidden from navigation, only `index`/`view` routes registered, `CreateAttachment`/`EditAttachment` page classes deleted, all 8 mutation `canX()` methods hard-`false` (survives `Gate::before`'s Super-Admin bypass), table/list/view stripped of every Edit/Delete/bulk action — while `attachments.*` permissions and `AttachmentPolicy` stayed untouched, so `viewAny`/`view` still work as before. `AttachmentStorageService::isSafeRelativePath()` added as a defense-in-depth pre-check (rejects absolute Unix/Windows/UNC/drive-letter paths, null bytes, empty values, and any `..` segment after splitting on both `/` and `\`) before any stored `file_path` reaches `Storage`. `safeDownloadName()` strips directory segments and every C0/C1 control character (CR/LF specifically, for header-injection safety) from the stored `file_name`, falling back to a deterministic `attachment-{id}.{ext}` name — and, found and fixed during this same pass, uses a custom `lastPathSegment()` splitter rather than PHP's native `basename()`, because `basename()` only treats `\` as a separator on Windows and would have silently let a `..\..\folder\evil.pdf`-shaped stored filename leak its directory segments straight into `Content-Disposition` on a Linux server. Controller flow corrected so a missing-or-soft-deleted parent returns 404 **before** `Gate::authorize()` is ever called (previously it fell through to the reused policy and produced 403), so "trashed" and "never existed" are indistinguishable to any caller and no policy-level 403 can imply "it exists but you can't see it." Two pre-existing, unrelated Permissions test files (`AuthorizationAcceptanceTest`, `ResourceHttpAuthorizationTest`) encoded the *old* `AttachmentResource` behavior (visible in nav, has a create route) and were updated to expect the new, approved hardened behavior — not a regression, a necessary consequence of the hardening.

The six pre-existing orphan files under `storage/app/public/{execution-payments,payments}` (no matching `Attachment` DB row — real DB `attachments` table has 0 rows) were **not** touched at any point; re-hashed identical before and after every phase of this task.

### Changed Files
- `config/filesystems.php` — new `attachments` disk.
- `database/migrations/2026_07_21_000001_add_disk_to_attachments_table.php` — new `disk` column, default `public`.
- `app/Models/Attachment.php` — `DISK_PUBLIC`/`DISK_ATTACHMENTS`/`APPROVED_DISKS` constants, `disk` added to `$fillable`.
- `app/Services/Attachments/AttachmentStorageService.php` — new (disk resolution, path validation, MIME resolution, filename sanitization, streamed response).
- `app/Http/Controllers/Attachments/AttachmentController.php` — new (the only route that serves attachment bytes).
- `routes/web.php` — new `attachments.show` route.
- `app/Filament/Resources/Attachments/AttachmentResource.php` — hardened (nav hidden, `canX()` hard-`false`, routes reduced to index/view).
- `app/Filament/Resources/Attachments/Pages/ListAttachments.php`, `ViewAttachment.php` — header mutation actions removed.
- `app/Filament/Resources/Attachments/Pages/CreateAttachment.php`, `EditAttachment.php` — deleted.
- `app/Filament/Resources/Attachments/Tables/AttachmentsTable.php` — `EditAction`/bulk-delete removed.
- `tests/Feature/Attachments/AttachmentAccessTest.php`, `AttachmentPathSafetyTest.php`, `AttachmentFilenameSanitizationTest.php`, `AttachmentResourceHardeningTest.php` — new (combined 64 tests; see Verification below).
- `tests/Feature/Permissions/AuthorizationAcceptanceTest.php`, `ResourceHttpAuthorizationTest.php` — updated to expect `AttachmentResource`'s new hardened behavior (nav-hidden, no create route) alongside the pre-existing `ProjectCostResource` exception.
- The five financial Resources' Schemas/Create/Edit/View pages were **not** touched — still `disk('public')`, still raw `Storage::url()`; the cutover is explicitly deferred to Task 6B.

### Verification
1. `tests/Feature/Attachments/*` alone → **64/64 passed**.
2. Targeted (`Attachment|Permission|Role|User` filter) → **636 tests, 633 passed, 0 failed, 3 skipped, 1 risky** (skips: the two pre-existing read-only-resource create-route skips plus the new intentional `attachments` create-route skip; risky is the same pre-existing, unrelated case recorded since earlier tasks).
3. Financial suites (`ExecutionPayments`, `GeneralExchanges`, `GeneralExpenses`, `ProjectCostBudgetsPayments`, `ProjectCostReceipts`) → **70/70 passed**.
4. Full suite: `php artisan test` → **848 tests, 844 passed, 1 failed, 3 skipped, 1 risky** — the 1 failure is the same pre-existing/unrelated `ExampleTest` (`GET /` expects 200, gets 404), recorded in every prior task's log entry back to 2026-07-15; not touched.
5. Real DB verified unaffected at every checkpoint: `attachments` table row count 0, no `disk` column present (migration never ran against it).
6. The six orphan files under `storage/app/public/{execution-payments,payments}` re-hashed identical (MD5) before and after every phase.
7. `git status --short` / `git diff --stat` confirmed at each checkpoint that only the files listed above changed — zero modification to any of the five financial Resources' Schemas/Create/Edit/View pages.

### Commit Hash
Committed as `add private attachment security foundation` — see `git log -1 --format=%H` for the exact hash.

---

### Date
2026-07-26 (OMS Task 7C.6 — attachment restore primitives: staged revalidation, three-phase activate/rollback/finalize lifecycle, crash-state inspector, Windows retry seam)

### Task
Implement OMS Task 7C.6 only — safe attachment restoration primitives (staged pre-activation revalidation, quarantine swap, rollback, finalization) — explicitly NOT connected to `RestoreCommand` and NOT the full `RestoreOrchestrator`. Required: first report what `AttachmentStorageService` currently allows/validates; a three-explicit-phase lifecycle (`activate()`/`rollback()`/`finalize()`, never one irreversible call); exclusive-lock enforcement on all three destructive methods with no second lock acquired; a deterministic UUID-derived quarantine layout outside the live attachments root; complete staged-tree revalidation immediately before the first live rename; a Linux authoritative rename sequence with Windows bounded retry via an injectable move seam; a read-only crash-state inspector; focused tests only; no migration, no full-suite run, no commit.

### Result
**AttachmentStorageService finding**: it has no extension/MIME allowlist at all — only `Attachment::APPROVED_DISKS` disk gating and relative-path traversal safety. The only "mutable upload allowlist" in the Attachments namespace is `AttachmentUploadService::ALLOWED_DIRECTORIES`/`ALLOWED_PREFIXES` (new-upload-time directory/prefix rule, never consulted by `AttachmentStorageService` or anything in the restore pipeline) — so restore was already structurally independent of it before this phase; confirmed and proven in a new dedicated test.

**New classes** (`app/Services/Restore/Attachments/`): `RestoreAttachmentPaths` (deterministic sibling-of-live-root layout: quarantine `attachments.pre_restore.{uuid}`, rollback-discard `attachments.rollback_discard.{uuid}`, marker `attachments.pre_restore.{uuid}.state.json`), `AttachmentSwapHandle` (bounded, crash-reconstructable proof-of-activation value object), `AttachmentSwapState` (6-case enum: `NotActivated`/`Activated`/`InterruptedDuringActivation`/`RolledBack`/`Finalized`/`InconsistentNeedsManualReview`), `AttachmentSwapMarker` (small JSON audit record — the only thing that makes `NotActivated` and `Finalized` distinguishable, since both otherwise leave an identical directory layout), `AttachmentSwapStateInspector` (read-only, no automatic repair), `RestoreAttachmentRevalidator` (full staged-tree revalidation against a manifest file list — exact set, uniqueness, symlink/special-file rejection, size/hash/total-byte match, case-insensitive compound-safe denylist), `RestoreAttachmentActivationService` (`activate()`/`rollback()`/`finalize()`), `NativeAttachmentMoveRunner` (production `AttachmentMoveRunner`, Linux single attempt / Windows bounded retry+backoff via an injectable rename-attempt seam).

**New contract**: `App\Services\Restore\Contracts\AttachmentMoveRunner`.

**New exceptions** (`app/Services/Restore/Exceptions/`): `RestoreAttachmentsException`, `RestoreAttachmentValidationException`, `RestoreAttachmentSwapException`, `RestoreAttachmentRollbackException`, `RestoreAttachmentFinalizationException` — all bounded, sanitized, reasonCode-carrying, never a raw path/content/secret.

**Config**: `config/oms.php` gained `oms.backup.restore.attachment_move_retry_attempts`/`attachment_move_retry_delay_ms` (defaults 5/200ms, env-overridable).

**A real gap caught before any test was written against it**: `finalize()`'s recursive symlink-safe delete suppresses individual unlink/rmdir errors by design (best-effort traversal), which meant a silently-failed deletion (e.g. an open file handle) would still let `finalize()` report success. Fixed by adding an explicit post-delete `file_exists()` check that throws `RestoreAttachmentFinalizationException::deletionFailed()` if the quarantine path is still present — proven with a dedicated test that holds an open read handle on a quarantined file to deterministically force the deletion to fail.

**Known interface-contract note**: `RestoreAttachmentRevalidator::revalidate()` takes an explicit `$expectedFiles`/`$expectedTotalBytes` parameter rather than consuming `PreparedRestore` directly, because `PreparedRestore::$manifestSummary` (Task 7C.3) is deliberately bounded and excludes the per-file attachment list. The future `RestoreOrchestrator` (Task 7C.7+) must supply the manifest's attachment file list itself (retained in-process from `BackupArchiveContentVerifier::verify()`, or read back from a persisted subset) — this was a design decision made to keep this phase's primitives decoupled from how the orchestrator eventually sources that list; see `docs/DECISIONS_LOG.md`.

### Changed Files
- `config/oms.php` — two new config keys.
- `app/Services/Restore/Attachments/RestoreAttachmentPaths.php` — new.
- `app/Services/Restore/Attachments/AttachmentSwapHandle.php` — new.
- `app/Services/Restore/Attachments/AttachmentSwapState.php` — new.
- `app/Services/Restore/Attachments/AttachmentSwapMarker.php` — new.
- `app/Services/Restore/Attachments/AttachmentSwapStateInspector.php` — new.
- `app/Services/Restore/Attachments/RestoreAttachmentRevalidator.php` — new.
- `app/Services/Restore/Attachments/RestoreAttachmentActivationService.php` — new.
- `app/Services/Restore/Attachments/NativeAttachmentMoveRunner.php` — new.
- `app/Services/Restore/Contracts/AttachmentMoveRunner.php` — new.
- `app/Services/Restore/Exceptions/RestoreAttachmentsException.php` — new.
- `app/Services/Restore/Exceptions/RestoreAttachmentValidationException.php` — new.
- `app/Services/Restore/Exceptions/RestoreAttachmentSwapException.php` — new.
- `app/Services/Restore/Exceptions/RestoreAttachmentRollbackException.php` — new.
- `app/Services/Restore/Exceptions/RestoreAttachmentFinalizationException.php` — new.
- `tests/Support/Restore/FakeAttachmentMoveRunner.php` — new.
- `tests/Unit/Services/Restore/Attachments/RestoreAttachmentPathsTest.php` — new.
- `tests/Unit/Services/Restore/Attachments/RestoreAttachmentRevalidatorTest.php` — new.
- `tests/Unit/Services/Restore/Attachments/AttachmentSwapStateInspectorTest.php` — new.
- `tests/Unit/Services/Restore/Attachments/NativeAttachmentMoveRunnerTest.php` — new.
- `tests/Feature/Restore/Attachments/RestoreAttachmentActivationServiceTest.php` — new.
- `tests/Unit/Services/Attachments/AttachmentStorageServiceRestorePolicyTest.php` — new.
- No existing production file was modified except `config/oms.php` (additive keys only). `AttachmentStorageService`, `AttachmentUploadService`, `RestoreArchiveExtractor`, `RestoreWorkspace`, `PreparedRestore`, `BackupSubsystemLock(Handle)` were read but not changed.
- No migration. No `RestoreCommand`/orchestrator wiring.

### Verification
1. `php -l` on every new/changed PHP file — all clean.
2. `git diff --check` — no whitespace errors.
3. Targeted suite (`Restore|AttachmentSwapState|NativeAttachmentMoveRunner|AttachmentStorageServiceRestorePolicy` filter): **65 tests, 64 passed, 0 failed, 1 skipped (Linux-only branch on this Windows host), 122 assertions**.
4. Broader regression (`Restore|Backup|Attachment` filter): **894 tests, 890 passed, 0 failed, 4 skipped, 2222 assertions** — no regression.
5. Full suite was NOT run (out of scope per task instructions).

### Commit Hash
Not committed — awaiting explicit approval, per instructions.

---

### Date
2026-07-26 (OMS Task 7C.6 crash-safety correction pass — signed/durable swap marker, write-ahead phase protocol, InterruptedDuringRollback state, validated attachment manifest value object)

### Task
A same-day, pre-commit crash-safety review/correction pass on the just-implemented Task 7C.6, requested before any commit: (1) harden `AttachmentSwapMarker` into a signed, tamper-evident marker (purpose-derived HMAC from `APP_KEY`, distinct context, strict bounded schema, fixed allowed phases only); (2) make marker writes crash-safe/durable, reusing `RestoreProgressDurability` rather than inventing a new durability implementation; (3) make the swap state machine write-ahead/crash-consistent with explicit durable phases before and after every destructive transition; (4) fix the `NotActivated` vs `Finalized` ambiguity so a marker/facts disagreement or a tampered marker always yields `InconsistentNeedsManualReview`, never a guessed state; (5) rename the rollback-discard path to a clearer deterministic name and cover the four listed crash windows (A–D) including a new distinct interrupted-rollback state; (6) replace the raw manifest array input to `RestoreAttachmentRevalidator` with a small immutable validated value object; (7) confirm all three destructive methods re-inspect lock + marker + filesystem facts at entry rather than trusting only an earlier handle; (8) fix finalization marker ordering so `finalized` is never recorded before quarantine deletion is verified complete; (9) add focused crash-window/marker-integrity tests; (10)-(11) report exact file counts and run only directly affected tests + `php -l` + `git diff --check`, then commit, run `graphify update .`, and commit that separately.

### Result
**Signed/durable marker**: `AttachmentSwapMarker` is now a strict immutable value object (`create()` — validated UUID, real `AttachmentSwapPhase` enum, required timestamp); new `AttachmentSwapMarkerSigner` (HMAC-SHA256, context `oms-restore-attachment-swap-v1`, purpose-derived from `APP_KEY`, distinct from `RestoreProgressSigner`'s own context); new `AttachmentSwapMarkerWriter`/`AttachmentSwapMarkerReader` reusing the exact `RestoreProgressDurability`/`NativeRestoreProgressDurability` seam already established for restore progress files (temp file → fwrite → fflush → fsync → close → atomic rename → best-effort parent-directory fsync; a failed write never replaces the previous valid marker). The reader verifies signature, exact bounded schema, UUID match, and phase validity before trusting anything; `read()` returns `null` only when no marker file exists at all, and throws `RestoreAttachmentMarkerException` (new) for every other integrity failure.

**Write-ahead phase protocol**: 8 fixed phases (`ActivationStarted`/`LiveQuarantined`/`Activated`/`RollbackStarted`/`RollbackLiveDiscarded`/`RolledBack`/`FinalizationStarted`/`Finalized`, new `AttachmentSwapPhase` backed enum) are persisted before each destructive rename and again after each success, before the next mutation. A marker-write failure before any mutation reports `marker_write_failed`; a marker-update failure immediately after a mutation reports a distinct `marker_update_failed_after_mutation` and the method stops rather than continuing to the next step.

**State machine**: `AttachmentSwapStateInspector` rewritten around verified-marker-phase × directory-facts, with a new `InterruptedDuringRollback` state (quarantine intact, live missing, discard populated — the process died between moving the restored tree aside and restoring quarantine). `rollback()` now accepts resuming from `Activated`, `InterruptedDuringActivation`, or `InterruptedDuringRollback`. The discard path is renamed `attachments.rollback_discard.{uuid}` → `attachments.restore_discard.{uuid}` (naming only, same layout/guarantees). `activate()`/`rollback()`/`finalize()` all independently re-inspect the lock and real current state at entry — never trusting only an `AttachmentSwapHandle` from an earlier call.

**Validated manifest value object**: new `RestoreAttachmentManifest::fromManifestFiles()` — validates every entry once (safe/unique normalized path, real 64-hex SHA-256, bounded non-negative size, bounded non-negative total) and exposes only bounded accessors; `RestoreAttachmentRevalidator::revalidate()` now requires this object, never a raw array.

**Finalization ordering**: unchanged from the original pass's own fix (already verified quarantine-gone before writing `finalized`) — re-confirmed correct under the new marker/phase model and covered by a new dedicated test forcing the marker-update-after-deletion failure path.

### Changed Files
Exact `git diff --name-status` (36 files: 29 added, 7 modified, 0 deleted):

**Added (29)**:
- `app/Services/Restore/Attachments/{AttachmentSwapHandle,AttachmentSwapMarker,AttachmentSwapMarkerReader,AttachmentSwapMarkerSigner,AttachmentSwapMarkerWriter,AttachmentSwapPhase,AttachmentSwapState,AttachmentSwapStateInspector,NativeAttachmentMoveRunner,RestoreAttachmentActivationService,RestoreAttachmentManifest,RestoreAttachmentPaths,RestoreAttachmentRevalidator}.php` (13)
- `app/Services/Restore/Contracts/AttachmentMoveRunner.php` (1)
- `app/Services/Restore/Exceptions/{RestoreAttachmentFinalizationException,RestoreAttachmentMarkerException,RestoreAttachmentRollbackException,RestoreAttachmentSwapException,RestoreAttachmentValidationException,RestoreAttachmentsException}.php` (6)
- `tests/Feature/Restore/Attachments/RestoreAttachmentActivationServiceTest.php` (1)
- `tests/Support/Restore/FakeAttachmentMoveRunner.php` (1)
- `tests/Unit/Services/Attachments/AttachmentStorageServiceRestorePolicyTest.php` (1)
- `tests/Unit/Services/Restore/Attachments/{AttachmentSwapMarkerWriterReaderTest,AttachmentSwapStateInspectorTest,NativeAttachmentMoveRunnerTest,RestoreAttachmentManifestTest,RestoreAttachmentPathsTest,RestoreAttachmentRevalidatorTest}.php` (6)

**Modified (7)**:
- `config/oms.php` (unchanged this pass — same two keys as the original 7C.6 pass)
- `docs/AI_PROJECT_MEMORY.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`, `docs/TASKS_LOG.md`
- `tests/Support/Restore/FakeRestoreProgressDurability.php` — additive `onSyncFile` per-call-index hook (backward compatible, existing callers unaffected) so a test can make e.g. only the 2nd marker write fail.

### Verification
1. `php -l` on every new/changed PHP file — all clean.
2. `git diff --check` — no whitespace errors.
3. Targeted attachment suite (`--filter=Attachment`): **326 tests, 323 passed, 0 failed, 3 skipped (pre-existing), 770 assertions**.
4. Broader regression (`--filter="Restore|Backup"`): **742 tests, 738 passed, 1 failed, 3 skipped, 1768 assertions** — the 1 failure (`BackupCreationOrchestratorTest::test_creation_never_reaches_completed_when_pre_publish_verification_fails`) passes in isolation (re-run: 1/1 passed) and touches no file this pass changed — pre-existing test-order flakiness, not a regression.
5. Full suite was NOT run (out of scope per instructions).

### Commit Hash
See the commit immediately following this entry.

---

### Date
2026-07-26 (test-stability pass — stabilize backup restore regression tests)

### Task
Investigate and stabilize the one flaky failure (`BackupCreationOrchestratorTest::test_creation_never_reaches_completed_when_pre_publish_verification_fails`) flagged by the previous correction pass's broader regression run, without starting Task 7C.7. Reproduce with the smallest test combination, identify leaked shared state, fix the root cause (not the assertion, not by skipping/reordering), specifically review `once()` memoization, then verify with repeated runs, the full failing class, the same broader regression subset, and a randomized-order run.

### Result
**Root cause (test-isolation bug, not a production bug)**: 7 methods in the newly-added `tests/Feature/Restore/Attachments/RestoreAttachmentActivationServiceTest.php` called `RestoreAttachmentActivationService::activate()` — which performs real filesystem renames while holding a real `BackupSubsystemLock` exclusive `flock()` — **before** entering the `try { ... } finally { $handle->release(); }` block that releases it. Since PHPUnit runs the whole suite in one PHP process and `Storage::fake('restores')` reuses the identical fixed on-disk path every test (wiping directory *contents* only, never closing an already-open OS lock handle), a handle leaked by an assertion or an unexpected throw in one of those 7 methods could make `BackupSubsystemLock::acquireShared()` fail in a later, unrelated test in the same process — exactly the acquisition `BackupCreationOrchestrator::run()` depends on. Two full regression runs of the exact same filter produced 1 failure and then 0 failures respectively before the fix, confirming genuine non-deterministic (timing-dependent) flakiness rather than a fixed order dependency — consistent with a rare real transient condition (e.g. a Windows AV/file-handle hiccup during one of the test's real `rename()`/`fopen()` calls) occasionally triggering an assertion failure or exception in the unguarded window.

**`once()` memoization reviewed**: the only `once()` usage anywhere in the backup/restore subsystem is `BackupDeletionService::restoreActivityBlocks()`/`lastKnownGoodId()`, both already documented as correctly scoped per-instance (`once()` keys on the calling object's identity) — neither is a candidate for cross-test/cross-instance leakage, and neither is used by `BackupCreationOrchestrator`.

**Fix**: restructured all 7 vulnerable methods so the entire span from `exclusiveHandle()` acquisition through the `activate()` call to the final assertions is inside one `try` block, with `$handle->release()` as the only statement in `finally` — no code path between acquisition and the guard can now throw without still releasing the lock. No production code was touched; `BackupSubsystemLock`/`BackupSubsystemLockHandle` behaved exactly as designed throughout (a real `flock()` correctly blocking a second acquisition while held) — the defect was solely in the test's own acquire/release discipline, not reordering, not weakening any assertion, not skipping the test.

### Changed Files
- `tests/Feature/Restore/Attachments/RestoreAttachmentActivationServiceTest.php` — the only file changed (76 insertions, 58 deletions; restructured try/finally boundaries in 7 test methods, no assertions weakened, no tests removed/skipped/reordered).

### Verification
1. `php -l` on the changed file — clean.
2. `git diff --check` — no whitespace errors.
3. The specific previously-flaky test run **10/10 times consecutively** — all passed.
4. `BackupCreationOrchestratorTest` alone — **25/25 passed**.
5. Combined `RestoreAttachmentActivationServiceTest|BackupCreationOrchestratorTest` filter (the two classes most directly implicated) — **54/54 passed**.
6. Broader `Restore|Backup` regression, default order — **742 tests, 739 passed, 0 failed, 3 skipped, 1769 assertions**.
7. Same broader regression, `--order-by=random` — **742 tests, 739 passed, 0 failed, 3 skipped, 1769 assertions**.
8. Full suite was NOT run (out of scope per instructions).

### Commit Hash
See the commit immediately following this entry (`graphify update .` was NOT run — no production code changed).

---

### Date
2026-07-26 (OMS Task 7C.7 — the real RestoreOrchestrator: full secure restore orchestration, compensation matrix, maintenance mode, mandatory safety backup, heartbeat/progress, stale-detection watchdog, recovery runbook)

### Task
Implement OMS Task 7C.7: the highest-risk integration phase of the Restore engine — a real `RestoreOrchestrator` that executes an already-claimed restore operation end to end (preflight → maintenance mode → mandatory full pre-restore safety backup → decrypt/verify/stage → attachment activation → database import → reconciliation → attachment finalization → maintenance exit → terminal result), replacing `RestoreCommand`'s permanent fail-closed placeholder. Required: an explicit phase-aware failure/compensation matrix (Restored/RestoreFailed/RestorePartial), correct maintenance-mode ownership tracking, the database-row-may-disappear-mid-import design honored structurally, a detection-only stale-restore watchdog, and an operator recovery runbook — all without rewriting any working 7C.1–7C.6 primitive except where a genuine integration gap required it.

### Result
See the corresponding `docs/AI_PROJECT_MEMORY.md` entry (2026-07-26, OMS Task 7C.7) for full technical detail. Summary: `RestoreOrchestrator` implements the exact required sequence and compensation matrix; `RestoreMaintenanceMode` is the sole `artisan down`/`up` seam with correct ownership semantics; `RestoreTerminalResultWriter` centralizes every terminal signed-progress + DB-row write, always re-fetching the restore row fresh by UUID and tolerating its absence; `RestoreStaleDetector`/`oms:restore-watchdog` (scheduled every minute) is detection-only, never mutates/retries/resumes; `RestoreCommand` now calls the real orchestrator instead of `failClosed()`, with its permanent Task 7C.4 claim/lock shape completely untouched. Two small, justified gaps in already-built 7C.6 primitives were closed (never rewritten): `AttachmentMoveRunner` was never bound in the container, and `PreparedRestore` never carried the verified attachment manifest file list forward — both fixed additively with backward-compatible defaults. `docs/RESTORE_RECOVERY.md` (new) is the operator runbook.

### Changed Files
- `app/Services/Restore/RestoreOrchestrator.php` (new) — the execution engine.
- `app/Services/Restore/RestoreMaintenanceMode.php` (new), `app/Services/Restore/Contracts/MaintenanceModeInspector.php` (new), `app/Services/Restore/LaravelMaintenanceModeInspector.php` (new).
- `app/Services/Restore/RestoreTerminalResultWriter.php` (new).
- `app/Services/Restore/RestoreStaleDetector.php` (new), `app/Services/Restore/StaleRestoreObservation.php` (new).
- `app/Console/Commands/RestoreWatchdogCommand.php` (new).
- `app/Services/Restore/Exceptions/RestoreMaintenanceModeException.php` (new), `app/Services/Restore/Exceptions/RestoreOrchestrationException.php` (new).
- `app/Console/Commands/RestoreCommand.php` — `failClosed()` removed; `handle()` now calls `RestoreOrchestrator::orchestrate()`.
- `app/Services/Restore/PreparedRestore.php` — two new trailing constructor parameters (`attachmentManifestFiles`, `attachmentsTotalSizeBytes`), defaulted for backward compatibility.
- `app/Services/Restore/RestoreArchivePreparer.php` — populates the two new `PreparedRestore` fields from the already-verified manifest.
- `app/Providers/AppServiceProvider.php` — binds `AttachmentMoveRunner` (config-driven `NativeAttachmentMoveRunner`) and `MaintenanceModeInspector`.
- `app/Notifications/BackupNotificationEvent.php` — added `RestoreSucceeded`/`RestoreFailed`/`RestorePartial`/`RestoreStale` cases with Arabic titles.
- `config/oms.php` — added `oms.backup.restore.watchdog_notification_cooldown_minutes`.
- `bootstrap/app.php` — scheduled `oms:restore-watchdog` every minute.
- `docs/RESTORE_RECOVERY.md` (new) — operator recovery runbook.
- Tests (new): `tests/Feature/Restore/RestoreOrchestratorTest.php` (18 tests), `tests/Unit/Services/Restore/RestoreMaintenanceModeTest.php` (5), `tests/Feature/Restore/RestoreStaleDetectorTest.php` (5), `tests/Feature/Console/RestoreWatchdogCommandTest.php` (4), `tests/Support/Restore/FakeMaintenanceModeController.php`.

### Verification
1. `php -l` on every changed/new PHP file — clean.
2. `git diff --check` — no whitespace errors.
3. Targeted 7C.7 suite (`RestoreOrchestratorTest` + `RestoreMaintenanceModeTest` + `RestoreStaleDetectorTest` + `RestoreWatchdogCommandTest` + `RestoreCommandTest`) — **44 tests, 44 passed, 0 failed, 158 assertions**.
4. Broader `Restore|Backup` regression, default order — **774 tests, 771 passed, 0 failed, 3 skipped (pre-existing, unrelated), 1895 assertions**.
5. Same broader regression, `--order-by=random` — **774 tests, 771 passed, 0 failed, 3 skipped, 1895 assertions**. A first random-order attempt showed one intermittent failure (`RestoreAttachmentActivationServiceTest::test_rollback_restores_the_original_attachments`); investigated and confirmed pre-existing and unrelated to this task — it passes 100% reliably in isolation (3/3 random-order reruns of its own file) and the flake traces to a hardcoded UUID (`aaaaaaaa-1111-1111-1111-111111111111`) reused across several **pre-existing** test files this task never touched (`RestoreWorkspaceTest`, `AttachmentSwapStateInspectorTest`, `AttachmentSwapMarkerWriterReaderTest`, `RestoreAttachmentPathsTest`) whose quarantine/marker sibling paths (outside `Storage::fake()`'s cleaned disk root) can collide under certain random interleavings — the same class of issue the 2026-07-26 "test-stability pass" previously fixed for a lock leak, not something this task introduced (confirmed via grep: none of the 4 new 7C.7 test files reference this UUID). A second random-order run of the full filter passed cleanly with identical totals to default order.
6. Full application suite was NOT run (out of scope per instructions).

### Commit Hash
Not committed — awaiting review, per instructions.

---

### Date
2026-07-27 (OMS Task 7C.7 final acceptance/hardening pass)

### Task
Perform a focused acceptance/hardening pass on the just-implemented Task 7C.7 before committing: (1) implement TRUE long-phase heartbeats (not just phase-transition writes) so a healthy long-running restore is never misclassified stale, with an honest timer-driven mechanism for the otherwise-silent `mysql` import specifically; (2) add a deterministic RestoreOrchestrator test proving finalize()-failure compensation, introducing the smallest justified seam if the concrete 7C.6 service can't be failed deterministically; (3) properly fix (not just accept a rerun of) the pre-existing random-order flake tied to a duplicated hardcoded UUID; (4) review and hardened RestoreStaleDetector/the watchdog for database/cache unavailability during a restore, with focused tests; (5) clean any Graphify contamination before the main commit; (6) final acceptance verification (targeted + default + randomized regression, twice) before committing main 7C.7 and Graphify output separately.

### Result
See the corresponding `docs/AI_PROJECT_MEMORY.md` entry (2026-07-27) for full technical detail. Summary: new `RestoreHeartbeat` (Carbon-based, testable via `Carbon::setTestNow()`) threaded into every long phase via new trailing optional `?callable $onTick` parameters on the existing primitives (never rewriting their own logic), including a genuine `Process::start()` + poll-loop mechanism in `SymfonyProcessStreamInputRunner` for the `mysql` import specifically (verified against the installed symfony/process source that `isRunning()` alone never enforces the timeout). New `RestoreAttachmentLifecycle` interface (implemented by the unchanged `RestoreAttachmentActivationService`) enables a deterministic finalize()-failure test via a delegating test double. The original hardcoded-UUID flake's two affected test files now generate a fresh UUID per test instead of relying solely on their own cleanup discipline. `RestoreStaleDetector`/`RestoreWatchdogCommand` now degrade gracefully under database/cache unavailability instead of crashing. A SECOND, more consequential flake was found and fixed during this pass's own stress-testing: heartbeats increased `RestoreProgressWriter::write()`'s call frequency enough to expose a real intermittent Windows `rename()` failure (~40% failure rate over 10 stress runs) — fixed with a bounded Windows-only retry mirroring `NativeAttachmentMoveRunner`'s existing pattern, verified at 10/10 clean afterward. Graphify tracked output (`graphify-out/*`) was reverted to its pre-session state; no untracked Graphify cache artifacts were present.

### Changed Files
- `app/Services/Restore/RestoreHeartbeat.php` (new).
- `app/Services/Restore/Attachments/RestoreAttachmentLifecycle.php` (new) — implemented by `RestoreAttachmentActivationService` (modified: `implements` clause + optional `$onTick` param threaded to `RestoreAttachmentRevalidator::revalidate()`).
- `app/Services/Restore/RestoreOrchestrator.php` — heartbeats wired into every long phase; depends on `RestoreAttachmentLifecycle` instead of the concrete class; `restore_failed_phase` pinning fix for the maintenance-exit-failure case.
- `app/Services/Restore/RestoreTerminalResultWriter.php` — new `$failedPhaseOverride` parameter.
- `app/Services/Restore/RestoreProgressWriter.php` — `renameWithRetry()` (Windows-only bounded retry).
- `app/Services/Restore/RestoreStaleDetector.php` — Carbon-based timestamps; DB/disk-scan failures individually caught and degraded gracefully.
- `app/Console/Commands/RestoreWatchdogCommand.php` — outer bounded try/catch; best-effort Cache calls.
- `app/Services/Restore/Attachments/RestoreAttachmentRevalidator.php`, `app/Services/Restore/RestoreArchiveExtractor.php`, `app/Services/Backup/SecretstreamEnvelope.php`, `app/Services/Backup/DatabaseDumper.php`, `app/Services/Backup/BackupCreationOrchestrator.php`, `app/Services/Restore/RestoreArchivePreparer.php`, `app/Services/Restore/DatabaseRestorer.php`, `app/Services/Restore/SymfonyProcessStreamInputRunner.php`, `app/Services/Restore/Contracts/ProcessStreamInputRunner.php`, `app/Services/Restore/RestoreReconciler.php` — all gained a new trailing, defaulted `?callable $onTick = null` parameter; every existing caller unaffected.
- `app/Providers/AppServiceProvider.php` — binds `RestoreAttachmentLifecycle`.
- `config/oms.php` — new `progress_publish_retry_attempts`/`progress_publish_retry_delay_ms`.
- Tests (new): `tests/Feature/Restore/RestoreOrchestratorHeartbeatTest.php` (6), finalization-failure test added to `RestoreOrchestratorTest.php`, DB/cache-unavailability tests added to `RestoreStaleDetectorTest.php`/`RestoreWatchdogCommandTest.php`, `tests/Support/Restore/FinalizeFailingAttachmentLifecycle.php`, `tests/Support/Restore/RestoreOrchestratorTestFixtures.php` (shared trait extracted from `RestoreOrchestratorTest`).
- Tests (modified): `tests/Feature/Restore/Attachments/RestoreAttachmentActivationServiceTest.php`, `tests/Unit/Services/Restore/Attachments/AttachmentSwapStateInspectorTest.php` (fixed UUID constants → per-test `Str::uuid()`), `tests/Support/Restore/FakeProcessStreamInputRunner.php` (tick simulation support).

### Verification
1. `php -l` on every changed/new PHP file — clean.
2. `git diff --check` — no whitespace errors.
3. Targeted 7C.7 suite (heartbeat + orchestrator + stale detector/watchdog + previously-flaky classes + `RestoreMaintenanceModeTest` + `RestoreCommandTest`) — **112 tests, 112 passed, 350 assertions**.
4. Previously-failing test (`RestoreAttachmentActivationServiceTest::test_rollback_restores_the_original_attachments`) run 10 consecutive times — 10/10 passed.
5. Broader `Restore|Backup` regression, default order — **785 tests, 782 passed, 0 failed, 3 skipped, 1954 assertions**.
6. Same regression, `--order-by=random` (default seed) — identical totals, 0 failed.
7. Same regression, `--order-by=random --random-order-seed=987654` — identical totals, 0 failed.
8. (Diagnostic, not part of final acceptance) 10x `--order-by=random` stress runs of the two heaviest-I/O files caught the `RestoreProgressWriter` rename flake (4/10 failed before the fix) and confirmed its fix (10/10 clean after).
9. Full application suite was NOT run (out of scope per instructions).

### Commit Hash
See the commit immediately following this entry.

---

### Date
2026-07-27 (OMS Task 7C.9 — real local Windows/Laragon end-to-end backup/restore acceptance)

### Task
Perform the final acceptance phase for Task 7C: real, local, destructive backup/restore testing on Windows/Laragon against the actual local MySQL database and private filesystem disks (not `Storage::fake()`/mocks) — pre-flight safety checks, an independent emergency baseline, controlled E2E fixtures, a real manual full backup, database-only/files-only/full restore cycles each with independent before/after markers and SHA-256 verification, a safe pre-irreversible-boundary failure drill, a documented review of post-DB-import/partial coverage, a safe stale/crash acknowledgment drill, a post-restore application smoke check, an existing-guards-only financial integrity check, cleanup of every temporary fixture, and exactly one final full application test-suite run.

### Result
See `docs/AI_PROJECT_MEMORY.md` (2026-07-27, "OMS Task 7C.9") for full technical detail. One real, proven, E2E-only defect was found and fixed: `RestoreMaintenanceMode::enter()` called the real Artisan `down` command with a `--message` option that does not exist in this Laravel version, causing every real restore to fail 100% of the time at preflight — invisible to all 854 prior mocked tests since the test fake never validated real Artisan option signatures. Fixed by removing the unsupported option (no new view/feature added). One real, documented Windows-only operational limitation was confirmed (not a defect): the detached restore-process launcher does not reliably keep running independently of the launching web request in this local Laragon environment; the safe, verified recovery is to re-run `php artisan oms:restore {uuid}` in the foreground for the same already-claimed row, which completes the exact same real orchestrator. All three restore scopes (database-only, files-only, full) were run end-to-end through the real Filament UI and real orchestrator, each with independent before/after markers, and all reverted correctly. A safe corrupted-archive preflight-failure drill and a safe stale/crash-acknowledgment drill were both completed without touching any real backup or killing any real process; a genuine post-DB-import/partial failure was deliberately NOT injected against the real environment (would require an uncontrolled crash or a new production-only hook) and is instead documented as already covered by 7C.7's existing deterministic `RestoreOrchestratorTest` coverage. Post-restore smoke check and an existing-guards-only financial integrity check both passed cleanly. All temporary fixtures (test GeneralExpense record + balances, Setting markers, synthetic failure-drill rows/files, temporary Super Admin) were cleaned up via the real application services/UI, while every genuine backup/restore row produced by an actual UI action was kept as acceptance evidence. Final full application suite: **1875 tests, 1869 passed, 0 failed, 0 errors, 6 skipped, 5659 assertions** — all skips pre-existing and individually documented/environment-conditional.

### Changed Files
- `app/Services/Restore/RestoreMaintenanceMode.php` — removed the unsupported `--message` option from the `down` Artisan call; updated docblock.
- `tests/Unit/Services/Restore/RestoreMaintenanceModeTest.php` — updated the one test that asserted the old (incorrect) `--message` behavior.
- `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`, `docs/RESTORE_RECOVERY.md` — this task's documentation.
- No other application code changed. No migrations. No new views/features/schema.
- Real local database/filesystem state: multiple genuine `backup_operations` rows (backups + safety backups + restores) created via the real pipeline across Sections C–F and I remain in the live database as acceptance evidence (see the corresponding `AI_PROJECT_MEMORY.md` entry for the exact list); all Task 7C.9-specific temporary fixtures (test expense/transaction/balances, Setting markers, synthetic failure-drill rows, temporary Super Admin) were removed during cleanup.

### Verification
1. Pre-flight: working tree clean, both prior commits present, `d686fe3`'s files confirmed 100% `graphify-out/**`, APP_ENV=local, DB host=127.0.0.1, no PHPUnit/queue-worker/orphan process running, maintenance mode OFF, no stale locks.
2. Independent emergency baseline created outside the project tree (`mysqldump` + attachments copy + SHA-256 manifest); kept pending formal acceptance.
3. Real manual full backup created via UI + real queue worker; `verified_at` populated, encrypted archive confirmed on disk with matching size.
4. Database-only restore: real UI + real orchestrator (via foreground `oms:restore` after the Windows detached-launcher limitation was confirmed) — marker correctly reverted, safety backup verified, maintenance exited, Super Admin usable, FK relationships correct, queue restart signal set.
5. Files-only restore: attachment content correctly reverted byte-for-byte (SHA-256 match), DB control marker confirmed untouched, no quarantine/discard residue, safety backup verified, maintenance exited.
6. Full restore: DB marker and attachment content both correctly reverted together; post-import UUID-based FK reconciliation confirmed directly.
7. Pre-irreversible-boundary failure drill: clean `RestoreFailed` (never `RestorePartial`), DB/attachments unchanged, no auto-retry, signed terminal progress, `RestoreActivityGuard` Inactive afterward.
8. Post-DB-import/partial: documented as covered by existing 7C.7 `RestoreOrchestratorTest` coverage — no new injection performed.
9. Stale/crash drill: watchdog detection-only confirmed; live-lock and tampered-progress both correctly blocked acknowledgment; valid stale restore terminalized via the real UI acknowledgment flow with full operator-recovery metadata recorded; `RestoreActivityGuard` Inactive only once both gates were terminal.
10. Post-restore smoke check: dashboard/Projects/Accounts/Trial Balance (`متوازن`)/Backup Management all loaded correctly; secure attachment controller served the file; direct filesystem URL returned 403; restore-action eligibility correct.
11. Financial integrity: `BalanceGuardIntegrationTest` (all 5 resources) + `FinancialTransactionBalanceGuardTest` — **91 tests, 91 passed, 218 assertions**; zero dangling backup FK references; zero broken Account relationships; zero orphaned TransactionLine rows.
12. Cleanup verified: account balances back to exact baseline, 0 leftover Setting markers, active users back to baseline count, maintenance mode UP, no orphan processes, no residue directories, `RestoreActivityGuard` Inactive.
13. Final full application suite (the one required run): **1875 tests, 1869 passed, 0 failed, 0 errors, 6 skipped, 5659 assertions**, run once as instructed.

### Commit Hash
Not committed — awaiting review, per instructions.

---

### Date
2026-07-27 (OMS Task 8 — Financial & Database Integrity + Trial Balance Correctness Review)

### Task
High-risk financial/data-integrity phase: read-only audit of FK enforcement, uniqueness, transaction-number generation, indexes, multi-currency accounting rules, account/currency integrity, orphan detection, and persisted-balance consistency; a focused correctness review of the Trial Balance page/exports for multi-currency handling; a new read-only `php artisan oms:check-financial-integrity` command; safe automated tests; and one real read-only scan against the local DB. Explicit instruction: never call a multi-currency transaction unbalanced merely because raw amounts in different currencies differ, and never silently repair historical data.

### Result
See `docs/AI_PROJECT_MEMORY.md` (2026-07-27, "OMS Task 8") for full technical detail. Summary: audit found `transaction_lines.debit_base`/`credit_base` are each line's own-currency amount (never a company-base-currency conversion, despite the column names) — confirmed against every real write path and three pre-existing docblocks — so the integrity checker replays `FinancialTransactionBalanceGuard`'s real FX equation for 4-line multi-currency transactions rather than a raw cross-currency `SUM(debit_base)=SUM(credit_base)`. Real local DB preflight was clean (0 orphans/duplicates/mismatches across 4 transactions, 8 lines, 3 accounts); `accounts.current_balance` is a real persisted column, reconciled with 0 discrepancies. Trial Balance's current "Phase 1" design (period-only totals, no opening/closing balance) was confirmed as an intentional, already-documented 2026-07-05 decision, not a defect — 14 new tests fill its previously-zero calculation-coverage gap, including the canonical 1000→2999.94 multi-currency exchange case proving neither currency's report ever blends the other's raw amount. A real, unplanned schema/migration-history drift was found (`bank_accounts`, `accounts.parent_id`, `transactions.bank_account_id` live in the DB with no corresponding migration file) — per explicit user decision, documented only, no code change. Implemented the full read-only integrity checker (`app/Services/Integrity/*`, four focused collaborators) and `oms:check-financial-integrity` (`--json`, exit 0/1/2). Per explicit user approval: added two proven-missing supporting indexes and bounded (3-attempt) retry-on-collision hardening for the six duplicated transaction-number generators, deduplicated into one shared trait. Attachment single-active-attachment rule: still never formally approved — deferred, no schema change.

### Changed Files
- `app/Filament/Concerns/GeneratesSequentialTransactionNumbers.php` (new) — shared `generateTransactionNumber()` + `retryOnTransactionNumberCollision()`.
- `app/Filament/Resources/{ProjectCostReceipts,ProjectCostBudgetsPayments,GeneralExpenses,GeneralExchanges,ExecutionPayments,Accounts}/Pages/Create*.php` — wrap the existing `DB::transaction()` call in the new retry trait; per-file duplicated `generateTransactionNumber()` removed (now inherited).
- `app/Services/Integrity/{FinancialIntegrityChecker,DatabaseRelationshipIntegrityChecker,TransactionNumberIntegrityChecker,JournalBalanceIntegrityChecker,AccountCurrencyIntegrityChecker,IntegrityCheckReport,IntegrityViolation}.php` (all new).
- `app/Console/Commands/CheckFinancialIntegrity.php` (new) — `oms:check-financial-integrity`.
- `database/migrations/2026_07_27_100000_add_supporting_indexes_for_financial_integrity.php` (new) — additive-only; `transaction_lines(account_id, currency_id)`, `transactions(transaction_time)`; applied to the real local DB.
- Tests (new): `tests/Support/Integrity/IntegrityTestFixtures.php`; `tests/Feature/Integrity/{DatabaseRelationshipIntegrityCheckerTest,TransactionNumberIntegrityCheckerTest,JournalBalanceIntegrityCheckerTest,AccountCurrencyIntegrityCheckerTest}.php`; `tests/Feature/Commands/CheckFinancialIntegrityCommandTest.php`; `tests/Feature/Reports/TrialBalanceReportServiceTest.php`; `tests/Unit/Filament/Concerns/GeneratesSequentialTransactionNumbersTest.php`.
- Docs: this entry plus `docs/AI_PROJECT_MEMORY.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`.

### Verification
1. `php -l` on every changed/new PHP file — clean.
2. Targeted Task 8 suite (Integrity checkers + command + Trial Balance + numbering trait) — **44 tests, 44 passed, 108 assertions**.
3. Broader financial-workflow regression (6 modified Create pages + Reports + Commands) — **234 tests, 234 passed, 700 assertions**.
4. Real local DB scan: `php artisan oms:check-financial-integrity` — 12 relationships / 4 transactions / 3 live journal transactions / 6 lines / 3 accounts checked, **0 violations**, exit 0.
5. Real Trial Balance run against every local currency: USD 3 accounts debit=credit=125000 balanced; ILS/EUR no accounts, trivially balanced.
6. Final full application suite (the one required run): **1919 tests, 1913 passed, 0 failed, 0 errors, 6 skipped, 5767 assertions** — exactly the 44 new tests added on top of the prior 1875/1869 baseline; same 6 pre-existing skips, none new.

### Commit Hash
Not committed — awaiting review, per instructions.

---

### Date
2026-07-28 (OMS Task 8.1 — Schema Drift + Dead Schema Reconciliation)

### Task
Determine from evidence (not assumption) whether `bank_accounts`, `accounts.parent_id`, and `transactions.bank_account_id` — live in the local MySQL DB with real FK constraints but with no migration file on disk — are ACTIVE_REQUIRED, LEGACY_WITH_DATA, DEAD_UNUSED, or AMBIGUOUS, per Task 8's own 2026-07-27 deferred decision, then act accordingly (restore/create migration, stop-and-report, or safely remove) so `migrate:fresh` produces the intended current schema.

### Result
Pre-task `mysqldump` backup created outside the project directory. Read-only audit (code, git history, live DB) found zero application code usage of all three objects (no model, relationship, `$fillable` entry, resource/form field, factory, or seeder — only the Task 8 integrity checker's defensive FK check and its test shim) and zero non-null/live data currently and in the earliest captured historical backup (2026-07-06); the single `bank_accounts` row that ever existed (2026-06-09, placeholder-looking data) was already purged by the separate, user-approved 2026-07-06 operational data reset. Git history confirmed `create_bank_accounts_table` was never committed to this repo at all (all 64 migration files ever added still match all 64 on disk — nothing was ever deleted). Classified **DEAD_UNUSED** for all three (unanimous evidence). Applied a new guarded migration (`2026_07_28_100000_drop_dead_bank_accounts_schema.php`) to the real local `oms` database dropping `transactions.bank_account_id`, `accounts.parent_id`, and `bank_accounts`; removed the now-orphaned `migrations` table row for the never-existing `create_bank_accounts_table` file. Cleaned the two now-dead relationship checks out of `DatabaseRelationshipIntegrityChecker`, removed the now-unnecessary SQLite drift shim from `IntegrityTestFixtures`, updated the affected test's assertions, and corrected one stale schema claim in `OMS_Master_Reference.md`. Verified via an isolated scratch database (`oms_task81_freshcheck`, created and dropped on the same MySQL server, real `oms` DB never touched by `migrate:fresh`) that a clean install no longer differs from the reconciled local DB on `accounts`/`transactions`. Discovered (report-only, out of this task's named scope) two pre-existing, unrelated drifts as a side effect of the fresh-vs-local schema comparison: `accounts.account_code` NOT NULL live vs. nullable fresh, and `accounts_type` carrying a live `nature` enum + audit columns with no reproducing migration — both recommended as their own future tasks. Focused regression: 511/513 passed (2 pre-existing skips) + 58/58 report-authorization tests. Real local DB: `migrate:status` all 64 `Ran`; `php artisan oms:check-financial-integrity` — **Result: OK, exit code 0** (10 relationships checked, was 12). Real smoke checks (Trial Balance, Account Statement, Account/Transaction Eloquent loads, `/admin/login` HTTP 200) all passed against the reconciled real data. Working tree contains only the intended changes.

### Changed Files
- New: `database/migrations/2026_07_28_100000_drop_dead_bank_accounts_schema.php`
- Modified: `app/Services/Integrity/DatabaseRelationshipIntegrityChecker.php` (removed 2 dead relationship entries); `tests/Support/Integrity/IntegrityTestFixtures.php` (removed `shimUndocumentedSchemaDrift()` + unused imports); `tests/Feature/Integrity/DatabaseRelationshipIntegrityCheckerTest.php` (assertion count 12→10, dropped dead `bank_account_id` reference); `OMS_Master_Reference.md` (removed stale `parent_id` claim from the `accounts` schema summary)
- Local DB schema change (not a code file): dropped `bank_accounts` table, `accounts.parent_id`, `transactions.bank_account_id`, and their FKs/indexes from the real local `oms` database; deleted 1 orphaned `migrations` table row

### Verification
1. Pre-task backup: `C:\Users\laptop\oms_db_backups\oms_pre_task8.1_20260728_100905.sql`.
2. Focused suite: `tests/Feature/Integrity`, `CheckFinancialIntegrityCommandTest`, `tests/Feature/Reports`, `tests/Feature/GeneralExchanges`, `tests/Feature/ProjectCostBudgetsPayments`, `GeneratesSequentialTransactionNumbersTest`, `tests/Unit/Services` — **511/513 passed, 2 skipped**.
3. `ReportPageAccessTest` + `ReportExportAuthorizationTest` — **58/58 passed**.
4. Isolated fresh-install check on scratch DB `oms_task81_freshcheck` (created/dropped on the same MySQL server, real `oms` DB never targeted by `migrate:fresh`) — 64/64 migrations ran cleanly; `bank_accounts`/`accounts.parent_id`/`transactions.bank_account_id` confirmed absent.
5. Real local DB: `migrate:status` — all 64 `Ran`. `php artisan oms:check-financial-integrity` — **Result: OK, exit code 0** (0 relationship/numbering/balance/FX/currency-mismatch/persisted-balance violations).
6. Real (read-only, not rolled back) smoke checks against reconciled local data: `TrialBalanceReportService`/`AccountStatementReportService` both executed cleanly; `Account`/`Transaction` Eloquent queries with relationships loaded cleanly; `/admin/login` → HTTP 200; `/admin/accounts` → HTTP 302 (correct auth redirect).
7. `git status`/`git diff --stat` confirmed the working tree contains only the intended changes.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).

---

### Date
2026-07-28 (OMS Task 8.2 — Final Schema Drift Reconciliation)

### Task
Resolve the two remaining schema differences Task 8.1 discovered and deferred as out-of-scope: `accounts.account_code` (`NOT NULL` live vs. nullable fresh) and `accounts_type` (`nature`/`created_by`/`updated_by` live with no reproducing migration). Determine the approved current schema from evidence (code, git history, live data, docs) and make fresh installations reproduce it exactly, without modifying historical financial data.

### Result
Read-only audit found the original `create_accounts_table` migration (unchanged since the initial commit) has always declared `account_code` `->nullable()->unique()`; `AccountForm` explicitly sets `->nullable()`; and every read site across the codebase defensively guards `$account->account_code ? ... : ''` — the live `NOT NULL` was the drift. `accounts_type.nature` has zero code usage and `docs/AI_PROJECT_MEMORY.md` (2026-07-06, predating this audit) already documents "no account nature" as the current design — classified DEAD_UNUSED. `accounts_type.created_by`/`updated_by` match the exact audit-metadata convention (`App\Traits\HasUserTracking`) used by 20 other current models and carry real historical data (3 rows) — classified ACTIVE_REQUIRED, reproduced as schema only (no model wiring, to avoid starting the Audit Log task). Two guarded migrations applied to the real local DB. A real cross-database bug was found mid-task (a MySQL-only `information_schema` guard broke the SQLite test suite — 173/178 errored) and fixed with Laravel's driver-agnostic `Schema::getColumns()`. Fresh-vs-local comparison on an isolated scratch database (never the real `oms` DB) confirmed `accounts_type` and `accounts.account_code` now match exactly; rollback-safety check confirmed neither migration resurrects dead/never-existed constraints on a fresh install. Zero data modified. Focused regression: 178/178 + 158/158 passed. Real local `oms:check-financial-integrity` — Result: OK, exit code 0.

### Changed Files
- New: `database/migrations/2026_07_28_110000_make_account_code_nullable_on_accounts_table.php`; `database/migrations/2026_07_28_110001_reconcile_accounts_type_schema_drift.php`
- Modified: `OMS_Master_Reference.md` (account_code/accounts_type schema notes updated); `docs/AI_PROJECT_MEMORY.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`
- Local DB schema change (not a code file): `accounts.account_code` relaxed to nullable; `accounts_type.nature` dropped; `accounts_type.created_by`/`updated_by` added with FKs to `users`

### Verification
1. Pre-task backup: `C:\Users\laptop\oms_db_backups\oms_pre_task8.2_20260728_103748.sql`.
2. Focused suite: `tests/Feature/Integrity`, `CheckFinancialIntegrityCommandTest`, `tests/Feature/Reports`, `FinancialAccountGuardTest`, all 5 `BalanceGuardIntegrationTest` suites, 4 account-validation tests, `ExecutionPaymentCreditAccountTest`, `GeneratesSequentialTransactionNumbersTest` — **178/178 passed**.
3. `tests/Feature/Crud`, `TransactionDescriptionBuilderTest`, `TransactionLineDescriptionBuilderTest`, `CleanOperationalDataCommandTest`, `BackfillTransactionDescriptionsCommandTest` — **158/158 passed**.
4. Isolated fresh-install + rollback-safety check on scratch DB `oms_task82_freshcheck` (real `oms` DB never targeted by `migrate:fresh`) — 66/66 migrations clean; rollback of both new migrations confirmed no dead/never-existed schema resurrected.
5. Real local DB: `migrate:status` — both new migrations `Ran`. `php artisan oms:check-financial-integrity` — **Result: OK, exit code 0**.
6. Real (read-only) smoke checks: `Account`/`AccountType` Eloquent loads, `TrialBalanceReportService`/`AccountStatementReportService` both executed cleanly; `/admin/login` → HTTP 200; `/admin/accounts`, `/admin/account-types` → HTTP 302 (correct auth redirect). Transaction/line counts and balances confirmed byte-identical before/after.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).

---

### Date
2026-07-28 (OMS Task 8.3 — Accounts User-Tracking Schema Reconciliation)

### Task
Resolve the last remaining named drift Task 8.2 discovered and deferred: `accounts.created_by`/`updated_by` exist live with no migration file. Determine whether they are part of the approved current Account design (ACTIVE_REQUIRED / DEAD_UNUSED / AMBIGUOUS) and make fresh installations match the intended live schema safely, without starting the Audit Log task.

### Result
Read-only audit found zero code usage on `Account` (the `created_by`/`updated_by` writes inside `CreateAccount.php` are all for the opening-balance `Transaction`/`TransactionLine` rows, never the `Account` row) and zero non-null values, both currently (0 of 5) and in the earliest captured historical backup (0 of 16) — an unbroken always-NULL history. Classified **ACTIVE_REQUIRED** anyway (not DEAD_UNUSED): the column shape exactly matches the active `HasUserTracking` convention used by 20+ models and `accounts_type`'s already-reconciled twin, and removing now would likely mean re-adding for the upcoming Task 9 Audit Log. Applied a guarded migration (`2026_07_28_120000_reconcile_accounts_user_tracking_schema_drift.php`) that repairs only a missing column or missing FK, never duplicating either — on the real local DB both already existed correctly, so `up()` was a complete no-op. `down()` is intentionally irreversible, mirroring Task 8.2's `accounts_type` precedent. `Account` was **not** wired to `HasUserTracking` — schema reconciled only, behavioral tracking explicitly deferred to Task 9. Isolated scratch-DB scenarios (fresh install + simulated live drift, real `oms` never targeted) both passed, including a direct proof that the FK's `ON DELETE SET NULL` nulls the tracking reference when the referenced user is deleted. Fresh-vs-live parity confirmed exact for `accounts`. Zero data modified. Focused regression: 178/178 passed (unchanged, since no application code changed). Real local `oms:check-financial-integrity` — Result: OK, exit code 0.

### Changed Files
- New: `database/migrations/2026_07_28_120000_reconcile_accounts_user_tracking_schema_drift.php`
- Modified: `OMS_Master_Reference.md` (accounts.created_by/updated_by schema note added); `docs/AI_PROJECT_MEMORY.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`
- Local DB schema change: none (both columns/FKs already existed correctly on the real `oms` database; migration recorded as `Ran` with zero actual DDL executed)

### Verification
1. Pre-task backup: `C:\Users\laptop\oms_db_backups\oms_pre_task8.3_20260728_110404.sql`.
2. Focused suite: `tests/Feature/Integrity`, `CheckFinancialIntegrityCommandTest`, `tests/Feature/Reports`, `FinancialAccountGuardTest`, all 5 `BalanceGuardIntegrationTest` suites, 4 account-validation tests, `ExecutionPaymentCreditAccountTest`, `GeneratesSequentialTransactionNumbersTest` — **178/178 passed**.
3. Isolated scratch-DB scenarios (real `oms` never targeted by `migrate:fresh`/rollback): Scenario A (fresh install) — columns/FK/indexes correct, rollback no-op, no error. Scenario B (simulated live drift, via the no-op `down()` itself) — re-running the migration against pre-existing columns/FK produced zero duplicates and preserved a harmless row's tracking values byte-identical through a second rollback. A third scratch check proved `ON DELETE SET NULL` end-to-end (deleting the referenced user nulled both columns).
4. Fresh-vs-live parity: isolated scratch database — `accounts`' full `SHOW CREATE TABLE` now matches the real local DB exactly.
5. Real local DB: `migrate:status` — new migration `Ran`. `php artisan oms:check-financial-integrity` — **Result: OK, exit code 0**. 0 non-null `created_by`/`updated_by` before and after; `current_balance` sum and all row counts unchanged.
6. Real (read-only) smoke checks: `/admin/login` → HTTP 200; `/admin/accounts`, `/admin/accounts/create` → HTTP 302 (correct auth redirect); `Account` Eloquent loads, `AccountForm` schema build, `TrialBalanceReportService`, `AccountStatementReportService` all executed cleanly.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).

---

### Date
2026-07-28 (Graphify hygiene — project-local `.graphifyignore`)

### Task
Stop Graphify from indexing runtime-generated/uploaded/private/cached/backup/secret-bearing paths — specifically `storage/app/public/**` (uploaded attachment filenames) and `storage/framework/views/**` (compiled Blade cache) — discovered as a pre-existing issue during the Task 9B.1 lineage check. Determine the correct project-local exclusion mechanism, configure it narrowly, and produce a verified-deterministic, sensitive-path-free rebuild. No application code changes, no PHPUnit run, no push, no starting 9B.2.

### Result
Confirmed via the installed Graphify package's own source (`graphify/detect.py`) that a project-root `.graphifyignore` file (gitignore syntax, merged with `.gitignore`, honored by both `graphify update` and the post-commit hook) is the correct mechanism. Added `.graphifyignore` excluding `storage/**`, `bootstrap/cache/**`, `public/storage/**`, `vendor/**`, `node_modules/**`, `.git/**`, `graphify-out/**`, `.env`, `.env.*`, `*.sql`, while explicitly preserving `resources/views/vendor/filament-panels/**`. Found and worked around a real `graphify update`/`--force` limitation: neither reliably prunes nodes for newly-excluded files against an existing `graph.json` (a "no topology changes — outputs left untouched" fast-path can skip the rewrite entirely); a genuinely clean rebuild required removing the three regenerated output files first (backed up beforehand, restored/synced after) with no existing file to merge against. Also found and fixed a second-order copy of the same staleness: Graphify's own "curated graph" dated-snapshot backup (`graphify-out/2026-07-28/`) had captured the pre-fix, still-sensitive graph during an intermediate attempt and was not being refreshed by later runs (the backup step only fires when a prior `graph.json` exists to snapshot) — manually synced to match the final clean output. Verified deterministic: two independent from-scratch rebuilds (`PYTHONHASHSEED=0`) produced byte-identical SHA-256 hashes for `graph.json`/`manifest.json`/`GRAPH_REPORT.md`. Confirmed zero references to any sensitive path and full retention of every Task 9B.1 node and all legitimate source directories.

### Changed Files
- New: `.graphifyignore`
- Modified: `graphify-out/graph.json`, `graphify-out/manifest.json`, `graphify-out/GRAPH_REPORT.md`, `graphify-out/.graphify_labels.json`, `graphify-out/2026-07-28/graph.json`, `graphify-out/2026-07-28/manifest.json`, `graphify-out/2026-07-28/GRAPH_REPORT.md`, `graphify-out/2026-07-28/.graphify_labels.json`
- Modified (docs): `docs/AI_PROJECT_MEMORY.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`

### Verification
1. Two independent `graphify update .` from-scratch rebuilds (`PYTHONHASHSEED=0`): byte-identical SHA-256 for `graph.json`/`manifest.json`/`GRAPH_REPORT.md`.
2. Sensitive-path search (JSON + Markdown): zero hits for `storage/app/public`, `storage/app/private`, `storage/framework`, `storage/logs`, `public/storage`, `.env`, `*.sql`, composer `vendor/`, `node_modules`, uploaded `.jpg`/`.jpeg`/`.png` filenames.
3. All 12 Task 9B.1 nodes/classes confirmed present; `app/Services/Audit` (47), `database/migrations` (219), `tests/Feature/Audit` (53), `tests/Unit/Services/Audit` (26), `resources/views/vendor/filament-panels` (33), `config/` (12), `routes/` (2) all confirmed still indexed.
4. `git diff --check` — exit 0 (CRLF-normalization notices only).
5. `php artisan oms:check-financial-integrity` — **Result: OK, exit code 0**.
6. Full application suite intentionally not run, per instructions.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).

---

### Date
2026-07-28 (OMS Task 9A — Audit Log design audit, read-only)

### Task
A read-only design and scope-definition phase for a production-ready, immutable, searchable Audit Log — determine what audit capability already exists, evaluate architecture options, define the event taxonomy, propose a schema, and recommend an implementation phase plan. Explicit: do not implement, modify code/data, create migrations, run Graphify, or start Monitoring/Performance/Security work.

### Result
Verified directly against `composer.json` that no audit/activity-log package is installed. Found `App\Traits\HasUserTracking` wired to 21 models (actor-pointer only, no history) but not `Account`/`AccountType`; zero old/new-value capture, security-event, attachment-access, or report-export logging anywhere. Recommended a first-party hybrid architecture (shared CRUD diffing + explicit domain audit calls) over adopting a Composer package, mirroring the Task 7B backup engine's own in-house rationale. Delivered a full 22-point report: event taxonomy (MUST_AUDIT/OPTIONAL/DO_NOT_AUDIT per category), a proposed `audit_events` schema, an application-level immutability design (no DB triggers), a central redaction policy, a financial-event bounded-snapshot payload policy, background/system actor typing, Super-Admin-only Filament UI design, and an 8-phase rollout plan (9B.1–9B.8).

### Changed Files
None — read-only design phase, no code/schema/data changes.

### Verification
Read-only discovery commands only (`composer.json` inspection, targeted grep/Graphify queries, `git status`/`git log`). No tests run (none needed — no code changed). `git status` confirmed clean working tree throughout.

### Commit Hash
N/A — no changes made.

---

### Date
2026-07-28 (OMS Task 9B.1 — Audit Log foundation)

### Task
Implement only the Task 9A-approved Audit Log foundation: schema, immutable model, centralized redaction, payload bounding, actor/request context, strict (REQUIRED) vs best-effort (BEST_EFFORT) persistence modes, and focused tests. Explicit: do not wire into any existing model/controller/command, do not activate `HasUserTracking` on `Account`/`AccountType`, no Filament UI/permissions, no full suite run, no Graphify, no commit/push, no starting 9B.2.

### Result
Built the complete foundation layer with no application integration: `audit_events` migration (append-only, no `updated_at`/`deleted_at`/SoftDeletes, `subject_type` as a stable hand-maintained alias never a raw FQCN, `actor_user_id` FK→`users` `ON DELETE SET NULL`); `App\Models\AuditEvent` (Eloquent-level immutable via `updating`/`deleting`/`replicating` hooks + an explicit `forceDelete()` override, all throwing `AuditImmutableRecordException`; uuid always freshly generated, never caller-supplied); `App\Services\Audit\AuditRedactor` (recursive, whole-segment-matched secret denylist with one narrowly-scoped safe exception, `encryption_key_id`); `App\Services\Audit\AuditPayloadBounder` (1000-Unicode-char string cap, independent 8192-byte cap per `old_values`/`new_values` via deterministic key-dropping — never `substr()` on encoded JSON — with an explicit `_truncated` marker; safe DateTime/BackedEnum/resource/closure normalization); `App\Services\Audit\AuditActorContext` (typed factories for `user`/`system`/`scheduler`/`queue`/`command` actors — structurally enforces the approved login-failure rule that only a real, already-resolved `User` model, never a raw string, can populate an actor email); `App\Services\Audit\AuditLogger::record()` (`AuditFailureMode::Required` throws `AuditPersistenceException` on failure and preserves the original throwable; `AuditFailureMode::BestEffort` catches, logs sanitized via `Log::error()`, returns `null`; never opens its own DB transaction, so a future caller inside an existing financial `DB::transaction()` gets true commit/rollback coupling — proven directly by a dedicated transaction test). Three new enums (`AuditActorType`, `AuditStatus`, `AuditFailureMode`) and three new exceptions (`AuditImmutableRecordException`, `AuditPersistenceException`, `AuditValidationException`). No AppServiceProvider bindings were needed — every 9B.1 class is a concrete, container-autowireable dependency with no interface substitution point yet.

Two test bugs were found and fixed during the first focused-suite run (both pre-existing-test defects, not production-code defects): a redaction test asserted nested-array access on a key (`credentials`) that the redactor correctly redacts as a whole subtree (segment-matched), and a payload-bounder stress test used a key-differentiation scheme that collided after the bounder's own 191-char key truncation, silently collapsing 2000 test entries into 1.

### Changed Files
- New: `database/migrations/2026_07_28_130000_create_audit_events_table.php`; `app/Enums/AuditActorType.php`; `app/Enums/AuditStatus.php`; `app/Enums/AuditFailureMode.php`; `app/Models/AuditEvent.php`; `app/Services/Audit/AuditActorContext.php`; `app/Services/Audit/AuditRecordRequest.php`; `app/Services/Audit/AuditRedactor.php`; `app/Services/Audit/AuditPayloadBounder.php`; `app/Services/Audit/AuditLogger.php`; `app/Services/Audit/Exceptions/AuditImmutableRecordException.php`; `app/Services/Audit/Exceptions/AuditPersistenceException.php`; `app/Services/Audit/Exceptions/AuditValidationException.php`; `tests/Feature/Audit/AuditTestCase.php`; `tests/Feature/Audit/AuditEventsMigrationTest.php`; `tests/Feature/Audit/AuditEventImmutabilityTest.php`; `tests/Feature/Audit/AuditActorContextTest.php`; `tests/Feature/Audit/AuditLoggerTest.php`; `tests/Feature/Audit/AuditLoggerTransactionTest.php`; `tests/Unit/Services/Audit/AuditRedactorTest.php`; `tests/Unit/Services/Audit/AuditPayloadBounderTest.php`
- Modified: `OMS_Master_Reference.md`, `docs/AI_PROJECT_MEMORY.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`
- Local DB schema change: new `audit_events` table created on the real local `oms` database (empty — 0 rows)

### Verification
1. Pre-task backup: `C:\laragon\backups\oms\oms_before_audit_9b1_20260728_115843.sql`.
2. Focused suite: `tests/Feature/Audit`, `tests/Unit/Services/Audit` — **53/53 passed, 197 assertions** (two test-file bugs found and fixed mid-task, both in the new test files, no production code affected).
3. Real local DB: `migrate:status` — new migration `Ran`. `audit_events` confirmed empty (0 rows) after migration. `accounts` (5), `users` (5), `transactions` (8) row counts confirmed unchanged.
4. Real local `oms:check-financial-integrity` — **Result: OK, exit code 0**.
5. Real smoke check: `/admin/login` → HTTP 200.
6. Full application suite intentionally not run, per instructions.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).

---

### Date
2026-07-28 (OMS Task 9B.2 — Audit Log: general CRUD integration)

### Task
Wire the accepted 9B.1 audit foundation to the real application write paths of eleven general/master-data models only — `Project`, `ProjectCost`, `Partner`, `PartnerType`, `ProjectSuper`, `ProjectStatus`, `BankType`, `FiscalYear`, `TransactionType`, `TransactionSuperType`, `Setting` — with `created`/`updated`/`deleted`/`restored` events in strict `AuditFailureMode::Required` mode, guaranteed atomic with their business mutation. No financial workflow model, no `Transaction`/`TransactionLine`, no `User`/`Role`/`Permission`, no auth listeners, no attachments/exports, no backup/restore integration, no audit UI, no audit permissions, no schema change, no `HasUserTracking` on `Account`/`AccountType`. No Graphify run, no commit, no push, no 9B.3.

### Result
**Targeted read-only audit first** established the decisive constraint: **no Filament write path in this application runs inside a database transaction**. The panel never calls `->databaseTransactions()`, so `Filament\Pages\Concerns\CanUseDatabaseTransactions::hasDatabaseTransactions()` is false for every Create/Edit page, and `Filament\Actions\Concerns\CanUseDatabaseTransactions` defaults to false for every Action. A model observer would therefore fire with the business row already committed — the exact design 9B.2 forbids. Auditing is consequently owned by a service that wraps the mutation and the audit insert in one `DB::transaction()`, not by observers.

The same audit also established: all 11 resources use Filament's stock Create/Edit/Delete handlers (no custom overrides); on a resource page a table's `CreateAction`/`EditAction`/`ViewAction` are plain links to the Create/Edit/View pages (`Filament\Resources\Pages\Page::getDefaultActionUrl`) and perform no write; the only in-place modal write path is `CostsRelationManager` (Projects → تكاليف المشروع) for `ProjectCost`; no restore action exists anywhere in the UI (OMS removed every Restore/ForceDelete action by design); `astrotomic/laravel-translatable` is installed but **no target model uses it**, so every Arabic display value is a plain column; and `ProjectObserver`/`ProjectCostObserver` only flip `ProjectFinancialSnapshot.is_dirty` on a different, unaudited table.

**Built** (`app/Services/Audit/Crud/`): `AuditedCrudService` (owns the transaction; `create`/`createViaRelationship`/`update`/`delete`/`restore`), `AuditSubjectRegistry` (closed model→alias allowlist; unregistered classes throw `AuditSubjectNotRegisteredException` instead of falling back to a derived alias, which is what structurally guarantees no FQCN ever reaches `subject_type`), `AuditSubjectDefinition`, `AuditModelSnapshotter` + `AuditFieldDiff`, `SettingValuePolicy`; plus `app/Services/Audit/AuditActorResolver.php` and `app/Services/Audit/Exceptions/AuditSubjectNotRegisteredException.php`. **Filament seam** (`app/Filament/Concerns/`): `AuditsRecordCreation`, `AuditsRecordUpdate` (replace `handleRecordCreation()`/`handleRecordUpdate()` identically) and `AuditedActions` (stock actions with only their process closure swapped via `->using()`; `deleteBulk()` pins `fetchSelectedRecords()` so Filament's mass query-level delete branch can never bypass the models).

Aliases: `project`, `project_cost`, `partner`, `partner_type`, `project_super`, `project_status`, `bank_type`, `fiscal_year`, `transaction_type`, `transaction_super_type`, `setting`; category `crud` for every event. Each subject declares a **closed allowlist of business columns**, so `id`/`created_at`/`updated_at`/`deleted_at`/`created_by`/`updated_by`/`remember_token`/observer flags are excluded by construction. `created` = full snapshot, `updated` = changed audited fields only (no event when nothing audited changed), `deleted` = pre-delete snapshot with the primary key preserved, `restored` = post-restore snapshot. Date casts store plain `Y-m-d`. `ProjectCost` stores `project_id` plus one bounded `project_label`; no relation object or collection is ever serialized. `settings.value` uses a fail-closed policy (credential-shaped key — judged by `AuditRedactor`'s own rules after separator normalization so `mail.password` is caught — or PEM/long-opaque value ⇒ `[REDACTED]`); the `key` column is masked in the payload by the foundation's existing `key` segment rule and stays legible in `subject_label`.

Duplicate prevention is structural (auditing exists only in the service), so no suppression switch was built or needed — seeders, migrations, factories, permission sync and test fixtures write these tables directly and produce no audit history.

### Changed Files
- New: `app/Services/Audit/Crud/AuditedCrudService.php`, `AuditSubjectRegistry.php`, `AuditSubjectDefinition.php`, `AuditModelSnapshotter.php`, `AuditFieldDiff.php`, `SettingValuePolicy.php`; `app/Services/Audit/AuditActorResolver.php`; `app/Services/Audit/Exceptions/AuditSubjectNotRegisteredException.php`; `app/Filament/Concerns/AuditsRecordCreation.php`, `AuditsRecordUpdate.php`, `AuditedActions.php`.
- New tests: `tests/Feature/Audit/Crud/AuditedCrudTestCase.php`, `AuditCrudInfrastructureTest.php`, `AuditedCrudServiceTest.php`, `AuditedCrudAtomicityTest.php`, `SettingAuditPolicyTest.php`, `AuditedFilamentCrudTest.php`.
- Modified (34 Filament files): the 11 `Create*` pages (+`AuditsRecordCreation`), the 11 `Edit*` pages (+`AuditsRecordUpdate`, `DeleteAction::make()` → `AuditedActions::delete()`), the 11 resource `*Table` classes (`DeleteBulkAction::make()` → `AuditedActions::deleteBulk()`), and `app/Filament/Resources/Projects/RelationManagers/CostsRelationManager.php` (all four write actions).
- Modified docs: `OMS_Master_Reference.md`, `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`.
- **No migration, no schema change, no model change, no policy change.**

### Verification
1. Focused suite `tests/Feature/Audit` — **86/86 passed, 486 assertions** (53 pre-existing 9B.1 + 33 new). New coverage: alias stability and no-FQCN, unregistered-model rejection (`Transaction`/`Account`/`User`/`AuditEvent`), technical-field exclusion, 255-char label bounding, no-op update, one-event-per-logical-action, event immutability, per-model create/update/delete/restore content, actor snapshot, `updated_by` never in `changed_fields`, the Project dirty-flag observer producing no noise, FK+bounded-label relationship handling, Setting key/value redaction and 8 KiB/1000-char bounding, four forced-REQUIRED-audit-failure rollbacks (create/update/delete/restore) and the surrounding-business-transaction rollback, plus Filament regression (create→View, edit→View, delete→List, notifications, bulk delete one-event-per-record, relation-manager CRUD, policies still enforced).
2. Regression: `tests/Feature/Crud` + `tests/Unit/Filament` + `tests/Unit/Policies` — **117/117 passed**. `tests/Feature/Permissions` — **341 tests, 338 passed, 3 skipped** (both skip sites pre-existing and by design: `CrudPolicyBehaviorTest` for non-SoftDeletes models, `ResourceHttpAuthorizationTest` for read-only audit resources).
3. `php -l` clean on every new and modified file. PHPUnit never run concurrently.
4. Real local DB: `audit_events` still **0 rows** — no artificial audit events were created; all target/business row counts unchanged (`projects` 0, `projects_costs` 0, `partners` 2, `partners_types` 3, `projects_super` 4, `projects_status` 2, `bank_types` 6, `fiscal_years` 1, `transactions_types` 11, `transaction_super_types` 7, `settings` 8, `transactions` 8, `transaction_lines` 22, `accounts` 5, `users` 5). No migration was run.
5. Real local `oms:check-financial-integrity` — **Result: OK, exit code 0**.
6. Real smoke check: `/admin/login` → HTTP 200; `/admin/partner-types`, `/admin/settings`, `/admin/projects`, `/admin/fiscal-years`, `/admin/bank-types` → 302 to `/admin/login` (correct unauthenticated behavior).
7. Full application suite intentionally not run, per instructions. No Graphify run.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).

---

### Date
2026-07-28 (OMS Task 9B.2 review corrections — Setting semantic field, ProjectCost old-side relation label, Filament lifecycle regression proof)

### Task
Task 9B.2 accepted in principle. Correct two audit-accountability defects found in review, without weakening the central `AuditRedactor` and without redesigning Filament: (1) a `Setting` key rename lost the previous key, because `settings.key` is treated as secret-shaped by the global redactor; (2) a `ProjectCost.project_id` change labelled only the new side, leaving the old side with a bare foreign key. Then review the complete diff section by section, confirm the Filament wrappers preserve authorization/hooks/validation/relationship saving/notifications/redirects/bulk failure reporting/deselection, and run only the directly relevant tests. No full suite, no Graphify, no commit, no push, no 9B.3.

### Result
**1. `settings.key` → `setting_name`.** Added `AuditSubjectDefinition::$fieldAliases` (column name → emitted audit field name) and `auditFieldName()`. `AuditModelSnapshotter` now assembles every row keyed by real **column** names — which is what value policies and relation-label lookups need — and renames to semantic audit field names once, as the final step, for `old_values`, `new_values` and `changed_fields` alike. `Setting` registers `['key' => 'setting_name']`. The raw field name `key` is never supplied to `AuditLogger`. `AuditRedactor` is untouched and no general `key` safe exception was added; `settings.value` still passes `SettingValuePolicy` and then the central redactor, so renaming `smtp_password` to `mail.password` records both **names** while both **values** stay `[REDACTED]`.

**2. `ProjectCost` old-side relation label.** Relation-label resolvers now receive the foreign key **value** instead of the owning model — `AuditSubjectDefinition::relationLabel(string $foreignKey, mixed $foreignKeyValue)` — so each side of a diff is labelled from its own id rather than from a relationship object that already reflects the new one. `AuditSubjectRegistry::projectLabel()` resolves through `Project::withTrashed()->select(['id','code','name'])->find()`, so a cost line reassigned away from a since-retired project still records a readable old label, and only three columns are ever read (no model is serialized). `AuditModelSnapshotter::$labelCache` memoizes per logical action, and a foreign key absent from the changed set triggers no lookup at all. `changed_fields` deliberately still lists `project_id` only — a label is a readability snapshot attached to its FK, not a field a user changed.

**3. Filament lifecycle review.** Traced every wrapper against the vendor sources and found **no behavior lost**: `authorizeAccess()` and action authorization run before the replaced closures; `beforeValidate`/`afterValidate`/`beforeCreate`/`afterCreate`/`beforeSave`/`afterSave` and the `RecordCreated`/`RecordUpdated`/`RecordSaved` events all live in the page around `handleRecord*`, not inside it; validation (`$this->form->getState()`) precedes the handler; `saveRelationships()` still runs after it (and is a no-op for these 11 resources — every relationship on their forms is a BelongsTo `Select`, written as an FK column on the record itself, so the audited snapshot inside the transaction is already complete); `DeleteAction`'s `if (! $result) failure()` still works because the swapped closure returns the same `bool`; `AuditedActions::deleteBulk()` mirrors Filament's own per-record failure reporting including the report-only-the-first-exception rule; `deselectRecordsAfterCompletion()` comes from `DeleteBulkAction::setUp()` and is not overridden. One genuinely uncovered area — hooks, validation-blocks-the-event, bulk failure reporting, deselection, BelongsTo capture — got a new `AuditedFilamentLifecycleTest` (7 tests).

### Changed Files
- Modified: `app/Services/Audit/Crud/AuditSubjectDefinition.php` (added `$fieldAliases`/`auditFieldName()`; `relationLabel()` now takes the FK value; label bounding centralized in one `bound()` helper), `app/Services/Audit/Crud/AuditModelSnapshotter.php` (column-keyed assembly + final alias pass; per-side relation labelling; `$labelCache`), `app/Services/Audit/Crud/AuditSubjectRegistry.php` (`projectReference()` → `projectLabel(mixed $projectId)` using `withTrashed()`; `Setting` gains `fieldAliases`).
- Modified tests: `tests/Feature/Audit/Crud/AuditedCrudServiceTest.php`, `tests/Feature/Audit/Crud/SettingAuditPolicyTest.php`.
- New test: `tests/Feature/Audit/Crud/AuditedFilamentLifecycleTest.php`.
- Modified docs: `OMS_Master_Reference.md`, `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md` (two superseding entries plus supersession notes on the two originals), `docs/NEXT_STEPS.md`.
- **No production Filament file, model, policy, migration or schema was changed by this correction pass.**

### Verification
1. `tests/Feature/Audit` — **98/98 passed, 557 assertions** (was 86/486; +12 tests: 4 Setting semantic-name, 1 ProjectCost soft-deleted old label, 1 unrelated-field-no-labelling, plus the 7-test lifecycle file, with 1 pre-existing ProjectCost test rewritten).
2. `tests/Feature/Crud` + `tests/Unit/Filament` + `tests/Unit/Policies` — **117/117 passed**.
3. `php -l` clean; `git diff --check` clean. Full suite not run; PHPUnit never run concurrently.
4. Real local DB: `audit_events` still **0 rows**; no migration run; `graphify-out/` untouched.
5. `php artisan oms:check-financial-integrity` — **Result: OK, exit code 0**.
6. No excluded 9B.3+ integration introduced — `AuditSubjectRegistry` still registers exactly the same eleven models, proven by an exact-map assertion plus explicit rejection tests for `Transaction`/`Account`/`User`/`AuditEvent`.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).

---

### Date
2026-07-28 (Graphify cache-tracking hygiene — untrack `graphify-out/cache/**`)

### Task
Remove the tracked generated Graphify cache from version control. Trigger: `graphify-out/cache/stat-index.json` held three stale absolute paths naming real local database-backup `.sql` files under `storage/app/.../backups/`. Required a read-only audit of the installed Graphify source first to confirm the cache is fully generated and not required in Git, then the durable repository-policy fix (`.gitignore` + `git rm --cached`) rather than redacting the three paths or adding a scrubber. Explicitly forbidden: touching the real backup files, deleting the local cache from disk, starting Task 9B.3, pushing, running PHPUnit.

### Result
The audit confirmed the cache is fully generated, self-recreating and safe to untrack — and that the leak was materially broader than reported. `graphify-out/cache/stat-index.json` contained **1511 keys, 100% of them absolute machine paths**, because `cache.py` keys the index on `str(p.resolve())` by design (the cache *hash* is deliberately relative "so shared caches and CI work correctly"; the index key is not). Breakdown of the committed copy: 506 `storage\framework\testing`, 497 `app`, 169 `tests`, 145 `storage\framework\views`, 71 `database`, 44 `resources`, 31 `storage\app\private` (the 3 backup `.sql` names plus `livewire-tmp` upload scratch files), 12 `config`, 12 `storage\app\public` (uploaded financial-attachment filenames), 6 `bootstrap`, 6 `docs`, 2 `.claude`, 2 `public`, 2 `routes`. So 695 of the 1511 keys pointed under `storage\` — precisely the runtime/uploaded content the Task 9B.1 `.graphifyignore` work removed from `graph.json`/`manifest.json`/`GRAPH_REPORT.md`, but which the stat cache was never in scope to clean.

Nothing depends on the cache being tracked: a miss is non-fatal (both `load_cached()` call sites in `extract.py` fall through to fresh extraction on `None`), `_ensure_stat_index()` starts empty when the file is absent, `cache_dir()` creates its own directories, `_flush_stat_index()` is best-effort and swallows `OSError`, the repo has no CI, and the post-commit hook's only "cache" mentions are its own `~/.cache/graphify-rebuild.log`. The only tracked references to `graphify-out/cache` anywhere in the repository are historical `docs/PROMPTS_LOG.md` entries that already instructed never to commit it — so this change restores the project's own long-standing stated intent.

Applied: added `/graphify-out/cache/` to `.gitignore` (line 16) and ran `git rm --cached -r graphify-out/cache`, removing 2157 files from the index while leaving all 2157 on disk. `.graphifyignore` unchanged. No hand-redaction, no scrubber.

### Changed Files
- Modified: `.gitignore` (one added line: `/graphify-out/cache/`).
- Untracked (index removal only, files retained on disk): 2157 files under `graphify-out/cache/**` — 2142 `cache/ast/v0.9.1/*.json`, 14 `cache/semantic/*.json`, 1 `cache/stat-index.json`.
- Modified docs: `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md`, `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`. `OMS_Master_Reference.md` deliberately untouched (it contains no Graphify content).
- **No application code, test, migration, schema or real backup file was touched.**

### Verification
1. `git check-ignore -v graphify-out/cache/stat-index.json` → `.gitignore:16:/graphify-out/cache/`. Same rule matches a `cache/ast/` entry.
2. `git ls-files graphify-out/cache` → **0** tracked files. `git status --porcelain --untracked-files=all | grep '^?? graphify-out/cache'` → **0** (correctly ignored, not merely unlisted). 2157 files still present on disk.
3. Tracked outputs that remain: `graph.json`, `manifest.json`, `GRAPH_REPORT.md`, `cost.json`, the `.graphify_*` markers, and the dated curated snapshots.
4. Functional check, `PYTHONHASHSEED=0 graphify update .` — succeeded (exit 0, 822/822 files, 12464 nodes / 31641 edges / 502 communities), rewrote all 2157 local cache files, produced **zero** untracked cache entries, and regenerated `stat-index.json` locally (ignored). Every Task 9B.1 foundation node and every Task 9B.2 CRUD-audit class remained indexed; 823 files covered; tracked outputs showed 0 hits for `storage/app`, `storage/framework`, `public/storage`, `bootstrap/cache`, `node_modules`, `.env`, `.sql`, `livewire-tmp`. No full rebuild was requested or required.
5. The tracked graph outputs that this verification run rewrote were restored to HEAD (`git restore`), so the review diff contains only the hygiene change; `git diff -- graphify-out` is empty and the content is byte-identical ignoring line endings (`core.autocrlf = true`). The post-commit hook will regenerate them into a separate Graphify commit, per the established two-commit pattern.
6. `git diff --check` — clean. `php artisan oms:check-financial-integrity` — **Result: OK, exit code 0**. PHPUnit deliberately not run.
7. Real backup files were never read, moved, modified or deleted.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).

---

### Date
2026-07-28 (Graphify hygiene extension — untrack dated snapshots, exclude `.claude/**`, clean deterministic rebuild)

### Task
Extend the accepted cache-untracking work to the two remaining tracked sensitive-path sources: (1) the historical dated Graphify snapshots under `graphify-out/20*/`, and (2) local `.claude/**` state being indexed into the tracked `manifest.json`. Required a read-only audit of whether the snapshots are generated and whether anything depends on them being tracked; the durable `.gitignore` + `git rm --cached` policy fix; a `.graphifyignore` rule excluding local Claude state but never the root `CLAUDE.md`; and a clean deterministic rebuild (`PYTHONHASHSEED=0`, outputs backed up outside the repo, root outputs removed, two runs, byte-identical SHA-256). Explicitly forbidden: touching real backup files or uploaded attachments, reading Claude local config contents, adding `.claude` files to git, running PHPUnit, starting Task 9B.3, pushing. The previously approved cache work had to be preserved, not reset.

### Result
**Dated snapshots are fully generated and write-only.** `graphify.export.backup_if_protected()` copies a fixed artifact list into `graphify-out/<today>/` before an overwrite, keeps one folder per day, never raises, and is disabled by `GRAPHIFY_NO_BACKUP=1`. All four call sites (`watch.py:847`, `__main__.py:3533/4704/4819`) call `_backup(out)` and **discard the return value**; nothing in Graphify enumerates, reads or restores a dated folder. No application code, hook or CI depends on them (the repo has no CI; the hook never references them; the only tracked mention is prose in `docs/TASKS_LOG.md`). Git history is strictly better retention: **75 commits** touch `graphify-out/graph.json` versus 15 daily snapshots, each tied to the source revision that produced it.

They were also the largest remaining leak: as frozen pre-exclusion copies they still carried 2681 `storage/app` references, 85 `livewire-tmp`, 30 `.claude` and 27 `.sql` across 42 of the 75 files.

Applied: `/graphify-out/20*/` added to `.gitignore` (verified with `git check-ignore --no-index` to match only dated directories — no root-level output file begins with `20`), and `git rm --cached -r graphify-out/20*` removed all 75 files from the index with all 75 retained on disk. `.claude/**` added to `.graphifyignore`; root `CLAUDE.md` deliberately not excluded and confirmed still indexed.

Clean deterministic rebuild: root outputs backed up outside the repository, the three generated root files removed, `PYTHONHASHSEED=0 graphify update .` run twice as two independent from-scratch rebuilds (the three files removed again between runs so neither merged into a prior graph). Both runs produced 820 files, 12480 nodes, 31657 edges, 512 communities and **byte-identical SHA-256** for `graph.json`, `manifest.json` and `GRAPH_REPORT.md`. Indexed-file count fell 823 → 821 → 820 exactly as the `.claude` exclusions predict.

### Changed Files
- Modified: `.gitignore` (+1 line, `/graphify-out/20*/`, alongside the already-approved `/graphify-out/cache/`), `.graphifyignore` (+`.claude/**` with an explanatory comment).
- Regenerated: `graphify-out/graph.json`, `graphify-out/manifest.json`, `graphify-out/GRAPH_REPORT.md` (deterministic clean rebuild).
- Untracked (index removal only, all files retained on disk): 75 files across 15 `graphify-out/20*/` directories — this pass; plus the 2157 `graphify-out/cache/**` files from the previous, preserved pass.
- Modified docs: `docs/AI_PROJECT_MEMORY.md`, `docs/TASKS_LOG.md`, `docs/DECISIONS_LOG.md` (earlier snapshot-retention clause explicitly superseded), `docs/NEXT_STEPS.md`, `docs/PROMPTS_LOG.md`.
- **No application code, test, migration, schema, real backup, uploaded attachment or Claude local-config file was touched.**

### Verification
1. `git ls-files "graphify-out/20*"` → **0**. `git ls-files graphify-out/cache` → **0**. `git check-ignore -v` resolves both to `.gitignore:16` and `.gitignore:17`.
2. On disk: 75 snapshot files across 15 directories and 2162 cache files all still present.
3. Deterministic rebuild, two independent from-scratch runs, `PYTHONHASHSEED=0` — `graph.json` `e80630da7d6c14d3…`, `manifest.json` `bdaf93e835964a29…`, `GRAPH_REPORT.md` `66bc5e3cca36c229…` — identical across both runs.
4. Tracked-file leak check over the seven remaining tracked `graphify-out` files: **0 hits** for `storage/app`, `storage/framework`, `storage/logs`, `public/storage`, `bootstrap/cache`, `livewire-tmp`, uploaded attachment directories, `.sql` filenames, `.claude/`, `settings.local.json`, `.env*`, `node_modules`, composer `vendor/` source. Legitimate source identifiers survive and are distinguished from path leakage (`BackupCreationOrchestrator` 649, `RestoreCommand` 384, `AttachmentStorageService` 264, `AuditedCrudService` 387); `resources/views/vendor/**` remains indexed (33 references).
5. Coverage intact: every Task 9B.1 foundation node and every Task 9B.2 CRUD-audit node present; 820 indexed files (`app` 480, `tests` 167, `database` 71, `resources` 44, `public` 30, `config` 12, `docs` 6, `routes` 2, `bootstrap` 2, plus root `CLAUDE.md`, `README.md`, `composer.json`, `package.json`, `artisan`, `vite.config.js`, `OMS_Master_Reference.md`).
6. `git diff --check` — clean. `php artisan oms:check-financial-integrity` — **Result: OK, exit code 0**. PHPUnit deliberately not run.
7. **One finding reported, not applied** (outside this task's two scoped sources): `graphify-out/.graphify_python` is tracked and holds a single absolute machine-local interpreter path. It is only the hook's second of four interpreter probes and cannot resolve on another machine. Untracking it is a zero-risk one-liner awaiting your decision.

### Commit Hash
Not committed — awaiting review, per instructions (STOP before commit).
