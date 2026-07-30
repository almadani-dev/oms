# Decisions Log

## Decision Template

### Date

### Decision

### Reason

### Impact

---

### Date
2026-07-30 (OMS Task 9B.7 correction — the Audit Log UI reads categorical columns RAW; the domain enum casts stay)

### Decision
The read-only Audit Log UI reads all five categorical columns — `event_category`, `event_action`, `actor_type`, `subject_type`, `status` — as the **raw stored string**, through the new `App\Support\Audit\AuditRawValue::string()` (`getRawOriginal()`, with the raw attribute array as fallback), and labels them through `AuditLabels`, which maps a known value to Arabic and falls back to the stored string verbatim. `AuditEvent`'s `actor_type => AuditActorType` and `status => AuditStatus` casts were **left exactly as they are**.

### Reason
Both columns are declared `string(20)`/`string(10)` in the 9B.1 migration — the enum is a domain constraint on the WRITE path, not a storage constraint. A row written by a later build of this application, or restored from a backup taken by one, can therefore hold a value this build's enum does not declare. Eloquent resolves an enum cast on **attribute access**, so such a row hydrates fine and then throws a `ValueError` the moment the UI touches `$record->actor_type` — taking down the list page, the detail page, search, sorting and pagination for everyone, not just that row. An audit trail that becomes unreadable because the reader is older than the writer has failed at its only job. Removing the casts was rejected outright: they are what keeps every recorder under `App\Services\Audit` strictly typed, and weakening a domain invariant to fix a presentation bug is the wrong trade. A UI-only raw read fixes the actual failure at the actual boundary and leaves the write side untouched — a distinction the regression suite asserts directly, by proving in the same test that the model **still** throws on direct attribute access while the page **still** renders. An unknown `status` is rendered grey rather than green for the same honesty reason: this build cannot know that an undeclared status means success.

### Impact
Adding a case to `AuditActorType`/`AuditStatus` remains the correct way to introduce a new value, and doing so now only *improves* the label — it is no longer load-bearing for the page not to crash. The single seam for all of this is `AuditRawValue` plus the two backing-value maps in `AuditLabels`; the filter dropdowns still offer only known values (an unknown value is rendered verbatim in its column, it just cannot be pre-listed without a `SELECT DISTINCT` over a growing table). `AuditRedactor`, `AuditEvent`'s immutability hooks, the migration and the stored rows are all unaffected.

---

### Date
2026-07-30 (OMS Task 9B.7 — Audit Log access is the real `Super Admin` ROLE alone, with no `audit.*` permission created)

### Decision
`App\Support\Audit\AuditViewAuthorization::check()` is a plain `$user->hasRole(PermissionRegistry::SUPER_ADMIN)` comparison. It does **not** additionally require a permission, unlike `App\Support\Backup\BackupAuthorization` (`role AND backups.*`), and **no `audit.view`/`audit.manage` permission was added to `PermissionRegistry`**.

### Reason
`BackupAuthorization` needs both factors because `backups.*` permissions genuinely exist and could be granted to a lesser role, so the role check is what stops the permission alone from being sufficient. Here the situation is inverted: the whole point of Task 9B.7 §3 is that **no audit permission exists yet**. Adding one purely to satisfy a symmetric-looking two-factor check would create the exact widening risk the requirement forbids — a permission that a future role edit, permission sync, or "grant everything to Admin" convenience could hand to a non-Super-Admin. With no permission in existence, the role is not merely the primary factor; it is the only thing that can be checked, and that is strictly safer. The check is deliberately plain PHP so `Gate::before`'s Super-Admin bypass can never short-circuit it and no permission resolution can ever widen it. Proven with a fixture holding all 186 registered permissions and no Super Admin role: navigation hidden, list and view both 403.

### Impact
Task 9B.8 (or any later phase) that wants to delegate audit reading to a non-Super-Admin must add the permission deliberately and update this one method — a single, obvious, reviewable seam. Until then there is no audit permission for any tooling, sync, or role edit to grant by accident. `UserPolicy`, `Gate::before`, `canAccessPanel` and every existing role rule are untouched.

---

### Date
2026-07-30 (OMS Task 9B.7 — mutation abilities are hard-false in plain PHP, not denied through a Policy)

### Decision
`AuditEventResource` overrides `canCreate`/`canEdit`/`canDelete`/`canDeleteAny`/`canForceDelete`/`canForceDeleteAny`/`canRestore`/`canRestoreAny`/`canReplicate` to return `false` unconditionally, and no `AuditEventPolicy` was created.

### Reason
`Gate::before` (AppServiceProvider) grants a real Super Admin **every** ability. This resource is *only* reachable by a real Super Admin. A Policy whose mutation methods returned `false` would therefore be bypassed for 100% of the actors who can open the page — the denial would be decorative. The codebase already established exactly this reasoning for `PermissionResource` and `AttachmentResource`, and this follows it rather than inventing a parallel pattern. Structural absence reinforces it: only `index`/`view` pages are registered (so a create/edit URL is 404, not 403), `getRelations()` is empty, and `toolbarActions()`/`bulkActions()` are never called — which is what stops Filament rendering row-selection checkboxes at all, so there is no bulk-destructive surface to authorize. `AuditEvent`'s own `AuditImmutableRecordException` hooks remain the final backstop at the model layer.

### Impact
Three independent layers must all be removed before an audit row could ever be mutated through the UI: a page would have to be registered, a `canX()` override deleted, and the model's immutability hook removed. Adding an `AuditEventPolicy` later would be actively misleading and should not be done.

---

### Date
2026-07-30 (OMS Task 9B.7 — no index was added; two low-cardinality filters run without one, deliberately)

### Decision
No migration was written. The date-range, category, actor-user, subject-type and correlation-id filters use the five indexes the 9B.1 migration already created. The `actor_type` filter, and `event_action` used *without* a category, run without a dedicated index.

### Reason
Task 9B.7 §1 requires stopping and reporting only if the schema lacks an index **objectively required** for an approved filter, and prefers no schema change. `actor_type` has five possible values and `event_action` around thirty; a single-column index on either is far below the selectivity threshold at which a query planner would choose it over a scan, so adding one would be cost with no benefit — and every additional index is write amplification on an append-only table that only ever grows. `event_action` combined with `event_category` (the normal way an administrator narrows the list) already uses the composite `audit_events_category_action_idx` on its leading column. Text search (`subject_label`/`subject_key`/`actor_name`/`actor_email`) compiles to `LIKE %term%`, which no B-tree index can serve regardless; it is retained because §4 explicitly asks for it, and it only runs when an administrator actually types.

### Impact
The audit table can grow without a schema change. If real-world volume ever makes `actor_type`-only or `event_action`-only filtering slow, the fix is a composite index chosen from real query patterns then — not one guessed now. A full-text or trigram index for the search box is the separate, later decision if search becomes the bottleneck.

---

### Date
2026-07-30 (OMS Task 9B.7 — the payload presenter returns PLAIN TEXT and lets the renderer escape, rather than escaping itself)

### Decision
`AuditPayloadPresenter` returns raw, unescaped plain strings. The single consumer is Filament's `KeyValueEntry`, which writes both key and value through `e()`. Nothing in the resource namespace calls `->html()`, `->markdown()`, or returns an `HtmlString`.

### Reason
Task 9B.7 §7 asks the helper to "recursively escape all text". Escaping *inside* the helper and then rendering through a component that escapes again would double-encode: an audited value of `<script>` would display to the administrator as `&lt;script&gt;` — visibly wrong, and actively misleading in a forensic tool whose entire job is to report what was recorded. The security requirement is that markup never *executes*, and that is satisfied by escaping exactly once at the boundary that actually emits HTML. Splitting the responsibility this way also means the helper stays pure and unit-testable without an HTML context, and a future consumer cannot accidentally receive pre-escaped text it would escape again. `AuditEventRenderingTest` asserts the end result directly against the rendered HTML: `<script>alert(1)</script>` and `<img src=x onerror=alert(1)>` never appear unescaped, and `&lt;script&gt;` does.

### Impact
Any future component rendering audit payloads must escape its own output — which every stock Filament text component already does. The rule that makes this safe is the one stated in both class docblocks: nothing in this namespace may ever call `->html()`. A test would need to be added if a non-Filament consumer is introduced.

---

### Date
2026-07-30 (OMS Task 9B.7 — unknown categories/actions/aliases fall back to the STORED VALUE, never to "unknown")

### Decision
`AuditLabels::category()`/`action()`/`subject()`/`field()` return the stored string verbatim when it is not in their bounded Arabic map. Only a genuinely absent value (`null`/`''`) renders as `—`.

### Reason
`event_category`, `event_action` and `subject_type` are open snake_case strings in the schema — `AuditLogger` validates their shape, not a closed vocabulary — so a future phase will legitimately write values this file has not caught up with. Rendering those as "غير معروف" would destroy exactly the information an audit reader came for. Showing the raw value is always truthful and always identifiable. The detail view goes further and shows the Arabic label *next to* the raw value (`إنشاء (created)`), so a reader can cross-check the translation against what was actually recorded. `AuditLabelCoverageTest` then keeps the maps from silently rotting: it fails the moment a new `AuditSubjectRegistry` alias, subject enum case, or backup/restore action constant appears without a label.

### Impact
A new audited phase works in the UI on day one, unlabelled but fully readable, and the coverage test tells whoever adds it that a label is expected. The bounded maps also remain the reason no filter needs a `SELECT DISTINCT` over a growing audit table.

---

### Date
2026-07-30 (OMS Task 9B.6 — restore audit history survives a database replacement by reusing the existing signed progress journal, not a new log)

### Decision
No new external audit/recovery state was created. The authoritative restore lifecycle is replayed into the **restored** `audit_events` table from the already-existing signed restore progress journal (`RestoreProgressWriter` → `restores/{uuid}/progress.json`), by a new eighth non-fatal step at the end of `RestoreReconciler::reconcile()` and again — idempotently — by `RestoreTerminalResultWriter`. `correlation_id` is the restore's own UUID, so the pre- and post-replacement halves link without adding anything to the journal.

### Reason
A database-scope restore imports a full dump over the live schema, so every pre-import restore audit row is physically replaced. The journal already solved exactly this problem for Task 7C.5's metadata reconstruction and already satisfies every constraint this phase imposes on external state: it is private (0700 directory, 0600 files), HMAC-signed and refused if tampered with, and field-by-field bounded by `RestoreProgressSnapshot`, which by construction has nowhere to put a confirmation phrase, password, encryption key, raw command line, stack trace or unbounded exception text. Since 7C.5 it also carries the bounded `reconciliation_snapshot` — source backup, safety backup, requester identity and `confirmed_at` — written *before* the import. Building a second log would have duplicated a solved problem while creating a new sensitive-data surface and a new thing to keep signed and bounded.

### Impact
No migration, no schema change, no second log file, no custom encryption. `RestoreReconciler` gains one step whose position is load-bearing: after `migrate --force` (the table's schema exists again), after `oms:sync-permissions`/`permission:cache-reset` (which record nothing themselves, so post-restore permission synchronization cannot duplicate a restore event), and after `RestoreMetadataUpserter` has rebuilt the three `backup_operations` rows these events reference. `RestoreEphemeralTablePolicy` never touches `audit_events`, so nothing truncates them afterwards. Replayed rows carry `replayed_after_database_replacement: true` so a reconstructed row is honestly distinguishable from an original one.

---

### Date
2026-07-30 (OMS Task 9B.6 — the audit-replay step must never be able to fail a restore)

### Decision
Everything from `restore_reconciled` onwards is `BestEffort` and structurally cannot throw: the reconciler's replay step is deliberately **not** wrapped in its `step()` helper, and every post-boundary recorder method contains its own `try/catch` around the whole unit (ledger probe, requester lookup and payload build included, not just the insert).

### Reason
Each of those states is written after the database and/or the attachment directories have already changed irreversibly. Escalating an audit-storage failure there could only produce a *less* truthful outcome: aborting reconciliation would downgrade a genuinely successful restore to `RestorePartial`, and throwing out of the terminal writer would suppress the terminal record itself. The signed journal remains the authoritative terminal record regardless, and a `BestEffort` failure is still logged with a sanitized fingerprint by `AuditLogger`.

### Impact
A restore's correctness never depends on the audit table being writable after the import. Because the same idempotent replay runs from two independent points, a transient failure at the reconciler is not the only chance to record the history.

---

### Date
2026-07-30 (OMS Task 9B.6 — `backup_completed` is BestEffort, and the ledger probe belongs inside the guard)

### Decision
`backup_completed`, `backup_failed` and `backup_deleted` are `BestEffort`, the completion call sits structurally **outside** `BackupCreationOrchestrator::execute()`'s try/catch, and `BackupRestoreAuditLedger`'s existence probe runs **inside** each best-effort `try`.

### Reason
By the time completion is recorded, the verified archive has already been published under its final name and nothing in the code deletes it. A thrown `AuditPersistenceException` would be caught by the creation pipeline's own handler, which marks the operation `failed` — producing a row that lies about a perfectly good archive while leaving that archive on disk. This is not theoretical: the first implementation left the ledger probe outside the guard, and `test_a_completion_audit_outage_never_falsifies_a_published_backup` caught it — a dropped `audit_events` table threw a `QueryException` straight into that catch block. Task 9B.6 §6 forbids exactly this ("do not delete a valid completed backup merely because a completion audit insert fails", and never claim a rollback that did not happen).

### Impact
An audit outage can never falsify a completed backup or claim an already-unlinked archive still exists. The accountable half of each irreversible operation is instead the Required event written *before* it — `backup_requested` before the row exists, `backup_delete_requested` before the unlink — which is the only claim a non-atomic filesystem operation can honestly make.

---

### Date
2026-07-30 (OMS Task 9B.6 — failure metadata is derived from an exception's class and `reasonCode`, never from its message)

### Decision
`BackupRestoreFailure` produces only `failure_code` + `failure_category`, reading an exception's own fixed `reasonCode` property (re-validated against a strict snake_case pattern and a length bound) and matching its class against an ordered map. It never calls `getMessage()`, never reads a trace, and `BackupErrorSanitizer` is deliberately not reused for audit payloads.

### Reason
`BackupErrorSanitizer` is the right guard for `backup_operations.error_summary` and the progress journal — it strips this app's absolute paths and `MYSQL_PWD=` and bounds the length — but it is not sufficient for the audit trail: a `mysql`/`mysqldump` driver error, a PDO `QueryException` or a `ZipArchive` error can still carry SQL text, bound values, a third-party absolute path or schema detail. Nearly every exception in `App\Services\Restore\Exceptions` (and `BackupDeletionRejectedException`) already carries a hand-written closed `reasonCode` vocabulary set by named constructors, so accurate classification was available without touching prose at all.

### Impact
`RestoreTerminalResultWriter::finish()` gained an optional `?Throwable $cause` used solely for this classification (its message still never reaches the progress file or the row beyond the pre-existing `$errorSummary`), and `RestoreOrchestrator` threads the real deciding exception — including a maintenance-exit failure — into it. Payload assertions in both new test files prove no SQL, driver text or credential fragment ever appears.

---

### Date
2026-07-30 (OMS Task 9B.6 — three lifecycle states were deliberately not created because the paths do not exist)

### Decision
There is no restore-file upload/selection event, no cancellation event, and no separate "restore_confirmed" event. `oms:restore-watchdog` emits nothing. `restore_interrupted` exists and is written only by `RestoreStaleAcknowledgmentService::acknowledge()`, never by the engine.

### Reason
A restore in this application always reads an existing, completed+verified `BackupOperation` archive on the approved disk — nothing is ever uploaded or picked from the filesystem. There is no cancel path at all: `RestoreStaleAcknowledgmentService` is explicitly not resume/retry/rollback/repair, it only terminalizes a crashed restore after explicit human review, which is an *interruption*, not an engine failure. And the confirmation is part of the request: the two-step wizard validates both steps and the typed `RESTORE {uuid8}` phrase before `RestoreRequestService` is reached, so `restore_requested` carries the confirming actor and `confirmed_at` and a second event would describe a UI state that does not exist. The phrase itself is `dehydrated(false)` and never reaches `$data`, the row, or any payload.

### Impact
The event vocabulary matches the real implementation exactly. `restore_partial` was kept as its own action rather than folded into success or failure, because `BackupStatus::RestorePartial` is a genuinely distinct "destructive boundary crossed, manual review required" outcome the engine already produces.

---

### Date
2026-07-29 (OMS Task 9B.5 — report exports record `export_requested`, never `export_completed`)

### Decision
`ReportExportAuditRecorder` hardcodes a single action, `export_requested`, for all ten export paths. No completion event exists.

### Reason
Every one of the nine services in `app/Services/Reports` ends the same way: `return response()->streamDownload(function () use ($doc) { $writer->save('php://output'); }, $filename, [...]);`. The in-memory `Spreadsheet`/`PhpWord` object is built synchronously before the response is constructed, but the **serialization** — the step that can still exhaust memory or throw inside PhpSpreadsheet/PhpWord — runs inside the `streamDownload` callback, which Symfony invokes only after the response has been returned and the headers are already committed. The writer targets `php://output` directly, so no temporary file is produced at any point either, meaning there is not even an artifact whose existence could prove success. There is therefore no point in the current synchronous code at which successful generation is objectively known before the response is returned. `export_completed` would be a claim this code cannot support; `export_requested` records exactly what *is* true at that moment — an authorized actor requested this export, of this report, in this format, over these filters.

### Impact
An export that begins and then dies during serialization is recorded as requested, which is accurate, rather than as completed, which would be false. If exports are ever moved behind a queued job or a materialized temporary file, a genuine completion event becomes possible and should be **added** alongside this one, not substituted for it.

---

### Date
2026-07-29 (OMS Task 9B.5 — `viewed` and `downloaded` are separate actions because this application genuinely distinguishes them)

### Decision
An authorized private-attachment access records `attachment.viewed` or `attachment.downloaded`, not a single generic `accessed`.

### Reason
The distinction is explicit and application-level, not inferred. `routes/web.php` declares `/attachments/{attachment}/{mode}` with `->whereIn('mode', ['view','download'])`; `AttachmentController::show()` re-validates the segment against the same two literals with `abort_unless(in_array($mode, ['view','download'], true), 404)`; and the mode selects a materially different response — `Content-Disposition: inline` for a preview versus `attachment` for a download. Two distinct, explicitly requested operations are therefore two accurate actions. Crucially, nothing in the audit path reads `Accept`, `User-Agent`, `Sec-Fetch-Dest` or any other header to guess intent, which is what "do not invent download semantics from browser headers" rules out.

### Impact
The audit trail can answer "who previewed this payment proof" separately from "who took a copy of it". The dependency is documented at the top of `AttachmentAccessAuditRecorder`: if the `{mode}` segment is ever collapsed to a single path, these two actions must collapse to one accurate `accessed` action rather than start inferring intent from headers.

---

### Date
2026-07-29 (OMS Task 9B.5 — attachment atomicity is claimed for the database only, never for the filesystem)

### Decision
`AttachmentAuditRecorder` guarantees that an Attachment row and its audit event commit or roll back together, and the documentation says so in exactly those terms. It does **not** claim filesystem atomicity, and the pre-existing file move/delete ordering was left untouched.

### Reason
The audit insert is `Required` and joins the caller's already-open `DB::transaction()` (asserted with a `LogicException` below `transactionLevel() >= 1`), so the database half is genuinely atomic. The filesystem half is not and cannot be made so here: `AttachmentUploadService` performs its `$disk->move()` before the surrounding transaction commits, so any rollback — audit failure included — leaves that one new file orphaned on the private disk. That was already true of every pre-9B.5 rollback in these workflows, and "fixing" it would mean rewriting crash-safe file handling inside an audit task.

The §4 STOP condition ("if strict audit integration risks deleting the old file before the DB/audit commit is safe") was checked and does **not** apply: no path in this application deletes a prior attachment file from disk at all. A replacement stores the new file first and then soft-deletes the previous **row**; the five workflow delete methods soft-delete the row and explicitly keep the file "for audit". So a Required audit event can never cause an old file to be destroyed ahead of a commit — there is no code that destroys one.

### Impact
The guarantee that is documented is the guarantee that holds. A rollback can leave one orphaned new file (unchanged, pre-existing behavior); it can never lose a previous file, and it can never leave attachment metadata committed without its audit row.

---

### Date
2026-07-29 (OMS Task 9B.5 — the attachment payload field is `parent_id`, not `parent_key`)

### Decision
The attachment metadata payload names the parent's primary key `parent_id`. `AuditRedactor` was **not** given another `SAFE_EXCEPTIONS` entry.

### Reason
`AuditRedactor` matches on whole underscore-delimited segments, so `parent_key` has a `key` segment and was redacted to `[REDACTED]` — caught by the new tests, not by inspection. The value is an ordinary foreign key, not key material, and redacting it erases the single identifier that links an attachment event to its parent's own `financial` event. The two available fixes are to allowlist the field name globally or to pick a safe semantic name; the codebase already has a precedent for the second (`AuditSubjectRegistry` emits `settings.key` as `setting_name` for exactly this reason, rather than weakening the redactor for every other subject). Adding `parent_key` to `SAFE_EXCEPTIONS` would exempt that name for **every** future subject, which is a broader concession than the problem needs.

### Impact
The redactor's fail-closed segment rule stays as strict as it was in 9B.1–9B.4. Any future audit payload should follow the same rule: prefer an accurate non-colliding field name over an exception entry.

---

### Date
2026-07-29 (OMS Task 9B.4 — authentication events are BestEffort, in a class that physically cannot emit a Required event)

### Decision
`login_success`, `login_failed` and `logout` are recorded by `App\Services\Audit\Security\AuthenticationAuditRecorder`, which hardcodes `AuditFailureMode::BestEffort` and opens no transaction. Every identity/privilege **mutation** is recorded by a separate class, `SecurityAuditRecorder`, which hardcodes `AuditFailureMode::Required` and asserts an open caller transaction. Neither class can emit the other's mode.

### Reason
All three authentication events describe something that has already happened by the time Laravel dispatches them: `Login` fires after `SessionGuard` wrote the session, `Logout` after the session was cleared, `Failed` after the credentials were rejected. There is nothing left to roll back. Making them Required would be actively harmful in the two directions that matter most: a failed audit insert on `Logout` would throw out of Filament's `LogoutController` *after* the session was destroyed, leaving a user unable to complete a legitimate sign-out; and a failed insert on `Login`/`Failed` would throw after the session was regenerated, turning an audit-storage outage into a **login loop that locks every administrator out of the system — including the ones who would have to log in to repair the audit storage**. Splitting the two modes across two classes rather than passing a mode parameter means a later edit at a call site cannot quietly downgrade a security mutation's atomicity guarantee.

### Impact
An audit-storage outage degrades the authentication trail (a sanitized `Log::error()` line instead of a row) but never blocks login or logout — asserted by three tests that drop the `audit_events` table and confirm login still authenticates, logout still ends the session, and a failed login still fails. Conversely a failed audit on any user/role/permission mutation rolls the entire mutation back, pivots included.

---

### Date
2026-07-29 (OMS Task 9B.4 — a failed login is recorded against a resolved User, never against the submitted email string)

### Decision
`AuthenticationAuditSubscriber::onFailed()` reads **only** `$event->user` — the account Laravel's own user provider already resolved from the submitted credentials — and never touches `$event->credentials`. A matched account becomes the event's **subject** (`identified: true` + `user_id`/`email`/`is_active`); an unmatched attempt records a generic subject with `identified: false`, null `subject_key` and null `subject_label`. The **actor** is always `AuditActorContext::guest()` — real IP/user-agent/route, no identity — in both cases.

### Reason
`$event->credentials` carries the plaintext password *and* the raw, entirely attacker-controlled email string. Reading it at all is the risk; not reading it is the mitigation. Storing the submitted email would let anyone write arbitrary text — including text aimed at whoever later reads the audit log — into an immutable table simply by typing it into a public login form. Resolution is already performed by the auth provider, so consuming its result needs no second query on attacker-supplied input (which would itself add an unauthenticated per-attempt DB lookup). Separately, attributing the attempt to the matched account as the *actor* would be a fabricated identity claim: a failed attempt proves someone typed an email, never that the account owner was the one typing. This is also why `AuditActorContext` has no factory accepting a raw email string — the rule is enforced structurally, not by convention.

### Impact
An enumeration probe against a non-existent address produces a real, IP-bearing audit row with no attacker text in it. A wrong-password attempt against a real account is attributable to that account as a subject without ever implying the owner was responsible. A soft-deleted user's email does not resolve, so such an attempt records the generic unidentified subject — the deliberate fail-closed outcome.

---

### Date
2026-07-29 (OMS Task 9B.4 — authentication de-duplication is scoped to one ATTEMPT, not one request or a time window)

### Decision
`AuthenticationAuditRecorder` keeps a per-attempt "already recorded" set, cleared by the `Attempting` event and consumed by `Login`/`Failed`. It is bound as a container **singleton** in `AppServiceProvider`. The subscriber's methods are named `onAttempting`/`onLogin`/`onFailed`/`onLogout` — never `handle*`.

### Reason
Two independent duplicate sources exist, and both were found empirically, not assumed. (1) One logical login attempt can dispatch `Failed` **twice**: when credentials are valid but `canAccessPanel()` denies entry, `Illuminate\Auth\SessionGuard::attemptWhen()` fires `Failed` when its callback returns false, and `Filament\Auth\Pages\Login::authenticate()` (filament/filament v5.6.7, lines 151-160) then fires it again before throwing. `Attempting` is the correct window boundary because Laravel and Filament each dispatch it exactly once at the start of an attempt and **never between the duplicate pair** — so the pair collapses to one row while two genuine attempts in one request stay two. Request-object identity or a wall-clock window would both have been guesses. (2) Laravel's framework-level `EventServiceProvider` auto-discovers public `handle*`/`__invoke` methods under `app/Listeners` (`Illuminate\Events\DiscoverEvents`) and registers them **in addition to** an explicit `Event::subscribe()` mapping; with `handleLogin`/`handleLogout` names, `Event::getRawListeners()` showed both `["Class","method"]` and `"Class@method"` registered, and every login and logout wrote two identical rows. The singleton is required because `Dispatcher::subscribe()` registers handlers as `[Class, 'method']` and therefore re-resolves the subscriber from the container on every dispatched event — with a fresh instance per event, the de-duplication set would never survive from `Attempting` to `Failed`.

### Impact
One login attempt, one event; one logout, one event — including the panel-access-denial path, which is the case an administrator most needs to see. Renaming any subscriber method to `handleX` silently reintroduces the double-write, so `AuthenticationAuditTest` asserts exactly one registered listener per auth event as a permanent regression guard.

---

### Date
2026-07-29 (OMS Task 9B.4 — the User audit payload is a closed six-field allowlist, and a password change records one boolean)

### Decision
`UserSecuritySnapshot` reads exactly six fields — `user_id`, `name`, `email`, `is_active`, `roles`, `direct_permissions` — and nothing else. A password change is recorded as `"password_changed": true` and nothing else; `password` never appears in `changed_fields` either (the flag's own name is used). `password_changed` was added to `AuditRedactor::SAFE_EXCEPTIONS`; the bare name `password` was **not**. `direct_permissions` is Spatie's direct relation only, never the effective role-derived set.

### Reason
A closed allowlist cannot be out-argued the way a denylist can: `password`, `remember_token`, `email_verified_at`, session ids and reset tokens are never *read*, so no future field addition, cast change or `$hidden` regression can leak them. The redactor still runs afterwards as an independent second layer. `password_changed` needed an explicit exception because `AuditRedactor`'s whole-segment rule matches the `password` segment and would have erased the one safe fact while protecting nothing — the flag exists precisely so the credential never has to be represented. Storing a user's effective permission set was rejected on three grounds: it is the "full permission dump" the design forbids, it would routinely blow past `AuditPayloadBounder`'s 8 KB cap for a privileged user, and it duplicates information already implied by the role names on the same row.

### Impact
Every user event is small, bounded and provably credential-free (asserted by encoding the whole row and searching for the plaintext, both hashes and `$2y$`). A future field becomes auditable only by an explicit, reviewed addition to the allowlist.

---

### Date
2026-07-29 (OMS Task 9B.4 — role/permission name arrays are sorted, de-duplicated and re-indexed)

### Decision
Every role-name and permission-name array in a security payload passes through `SecurityNameDiff`, which de-duplicates, `sort()`s and `array_values()`-reindexes it. "Unchanged" means set equality, not array equality, and a no-op update writes no event.

### Reason
Three concrete failure modes. **Stability:** `syncRoles(['Admin','Accountant'])` and `syncRoles(['Accountant','Admin'])` are the same logical action and must produce byte-identical payloads, or a diff between two audit rows reflects submission order rather than a real privilege change. Spatie's own pivot reads (`$role->permissions()->pluck('name')`) come back in whatever order the database returns, which guarantees nothing. **JSON shape:** `array_diff` preserves original keys, so without `array_values()` the added/removed arrays would serialize as JSON *objects* (`{"1":"a"}`) instead of arrays — a permanent, un-fixable inconsistency on immutable rows. **Noise:** without set-equality comparison, re-saving a form would write an event on every submission. Sorting is deliberately byte-wise and not locale-aware: role and permission names are stable ASCII identifiers, and a collation-sensitive sort would make stored payloads depend on the server's locale.

### Impact
Audit diffs are meaningful and reproducible. Determinism is asserted directly by tests that submit the same set in two different orders against two records and compare the stored arrays byte-for-byte.

---

### Date
2026-07-29 (OMS Task 9B.4 — permission synchronisation is ONE summary event, and a no-op run still writes it)

### Decision
`PermissionSyncService::sync()` writes exactly one `security.synced` event on subject `permission_sync`, inside its existing `DB::transaction()`, in `AuditFailureMode::Required`. A completely idempotent run that changes nothing still writes its event. The payload explicitly records `permissions_removed: 0` and `obsolete_permissions_preserved: N` rather than leaving the non-destructive behavior implied. Actor attribution follows the real entry point: a terminal run is `actor_type = command` with null identity and null request metadata; the Filament header action records the real administrator.

### Reason
One run creates a row per registry permission (~186 today) and reconciles all five system roles' pivots. Per-row events would produce hundreds of rows describing a single administrative action — the exact duplication this phase forbids. The no-op rule is the accountability rule: executing a security-administration command that can rewrite five system roles' permissions is itself the accountable act, independently of whether the outcome changed. Recording `permissions_removed`/`obsolete_permissions_preserved` explicitly means an auditor reading one event can tell that nothing was revoked without knowing the implementation. Attribution is per entry point rather than a fixed `command` because labelling an administrator's UI click as a terminal command would be a factual error in the trail — the brief's `actor_type = command` requirement describes the command path, which is exactly what `AuditActorResolver` already yields there.

### Impact
Permission-sync history is one readable row per run. Because the audit insert is inside the service's own transaction, a failed audit rolls back every created permission and system role — verified by dropping `audit_events` and confirming the permission and role counts are unchanged.

**Known, accepted consequence — this narrows 9B.2's "seeders leave no audit trail" rule.** `PermissionSyncService::sync()` has two non-interactive callers besides the command and the UI action: `DatabaseSeeder::run()`, and `RestoreReconciler`, which runs `oms:sync-permissions` as its third reconciliation step (always **after** `migrate --force`, so `audit_events` is guaranteed to exist). Both now write one `permission_sync` event with `actor_type = command` and null identity/request metadata. This was accepted rather than suppressed: a seed and a post-restore reconciliation genuinely do rewrite the five system roles' permission sets, so recording it is the correct outcome, and adding a suppression switch would create exactly the "audit can be turned off" affordance the whole design avoids. 9B.2's rule still holds unchanged for every *model* path — seeders, migrations and factories writing Users/Roles/Settings/master data directly still produce no audit history, because auditing lives at explicit service call sites and not in observers. Verified green: `tests/Feature/Restore` + `tests/Feature/Console` + `tests/Feature/Commands` (347 passed) and `DatabaseSeederSuperAdminTest`.

---

### Date
2026-07-29 (OMS Task 9B.4 — no direct-user-permission write path was invented, and no password-reset flow was wired)

### Decision
Direct user permissions are captured in the snapshot and diffed into the single user event, and that contract is tested at the recorder level — but **no UI or service path for assigning/revoking a permission directly on a user was created**. Likewise no `PasswordReset` listener was wired.

### Reason
Both were listed in the brief's scope, and neither exists in this application. `UserForm` exposes roles only, and nothing anywhere in `app/` calls `givePermissionTo()`/`revokePermissionTo()`/`syncPermissions()` on a `User` (verified across the whole tree). `AdminPanelProvider` calls `->login()` but never `->passwordReset()`, so Laravel's `PasswordReset` event has no trigger at all. Adding a direct-permission assignment path to make the scope literally satisfiable would mean creating a new privilege-granting surface the application deliberately does not have — directly contrary to the same brief's instruction not to broaden any permission. Wiring a listener for an event that can never fire would be dead code presented as coverage.

### Impact
The capability is in place and asserted, so the moment a direct-permission path is added it is audited correctly as part of the same single user event — but the security surface is unchanged. An administrator resetting another user's password goes through `UserManagementService` and is already covered by the `user`/`updated` event's `password_changed` flag, which is the real flow this application has.

---

### Date
2026-07-29 (OMS Task 9B.3 — financial audit subject aliases are keyed on the WORKFLOW, never the model class)

### Decision
The five financial workflows are audited under `subject_type` values that name the workflow — `project_cost_receipt`, `project_disbursement`, `execution_payment`, `general_expense`, `general_exchange` (`App\Services\Audit\Financial\FinancialAuditSubject`) — resolved at each real write path, never derived from the model class or from documentation. `OMS_Master_Reference.md` was corrected in the same pass.

### Reason
Two of the five workflows are named the *inverse* of the model they write, and the project's own single-source-of-truth document had it backwards. Verified against the resource classes: `ProjectCostBudgetsPaymentResource` (slug `project-cost-budgets-disbursements`, "صرف مبلغ المشروع") has `$model = ProjectCostBudget::class`; `ExecutionPaymentResource` (slug `execution-payments`, "صرف مبالغ التنفيذ") has `$model = ProjectCostBudgetsPayment::class`; and there is **no `ExecutionPayment` model class anywhere**. A class-derived alias would therefore have labelled disbursements as payments and payments as budgets — a permanently wrong, permanently un-fixable label on immutable rows. It would also be ambiguous by construction: two resources over one model cannot be told apart by FQCN.

### Impact
`subject_type` describes what a user did, not which PHP class happened to be saved, and survives both a class rename and the existing naming inversion. `FinancialAuditSubject` is the closed enumeration of the five; adding a sixth workflow requires an explicit case. The Master Reference now carries a warning table with the verified mapping so the error cannot be reintroduced.

---

### Date
2026-07-29 (OMS Task 9B.3 — one logical financial action = exactly one AuditEvent; Transaction/TransactionLine are never audited)

### Decision
A single financial user action produces exactly one `AuditEvent`, written by the source workflow, carrying the resulting `transaction_id` and `transaction_number`. `Transaction` and `TransactionLine` get no observer, no generic CRUD wiring, and no `AuditSubjectRegistry` entry — permanently, not merely for this phase. `AuditCrudInfrastructureTest::test_unregistered_models_are_rejected` now pins both.

### Reason
One receipt writes 1 transaction + 2 lines + 1 source row + 2 balance movements; one disbursement writes 1 transaction + 4 lines + 1 source row + 4 balance movements. Auditing the ledger rows as well would produce 3–5 events per user action, none of which describes the action, all of which duplicate data that already lives in `transactions`/`transaction_lines` — and edit-time line replacement (`forceDelete()` + recreate) would additionally emit a delete+create pair per line on every edit. Storing the transaction identifiers instead makes the ledger followable from a single event at a fraction of the size. Registering the models is also the only realistic way a future change could reintroduce duplication, so it is guarded by a test rather than a comment.

### Impact
Event volume is proportional to user actions, not to double-entry line count. Reading an event tells you what happened and exactly where to find the ledger detail. Any future attempt to register `Transaction`/`TransactionLine` fails a test with an explanation.

---

### Date
2026-07-29 (OMS Task 9B.3 — the financial audit recorder never opens a transaction and fails closed outside one)

### Decision
`App\Services\Audit\Financial\FinancialAuditRecorder` does not wrap anything in `DB::transaction()`. Every entry point asserts `DB::transactionLevel() >= 1` and throws `LogicException` otherwise. All 15 financial call sites call it from inside the workflow's own already-open transaction, always with `AuditFailureMode::Required`, and always **before** the success `Notification`. `AuditedCrudService::recordCreatedWithin()` (added for the Account opening-balance path) applies the identical rule.

### Reason
This inverts `AuditedCrudService`'s design deliberately. That service owns its transaction because the mutation it wraps is a single `save()` on a path Filament does not wrap (the panel never enables `->databaseTransactions()`). A financial workflow is the opposite: it already opens one transaction spanning the source record, the `Transaction`, every `TransactionLine`, every balance increment/decrement and any attachment metadata. Opening a second, independent transaction inside that would give a failing REQUIRED audit nothing to roll back but itself — leaving a committed, unaudited financial mutation, exactly what this phase forbids. The `transactionLevel()` assertion turns a future mis-wiring into a loud programming error at the call site rather than a silently unaudited financial operation. Recording before the notification matters because `Notification::send()` flashes to the session and is not transactional: recording after it would flash "تم بنجاح" for an operation that then rolls back.

### Impact
If the audit insert fails, the source record, the transaction, every line and every account balance roll back together — proved by `FinancialAuditAtomicityTest` for create, edit and delete, including exact balance restoration and exact restoration of the previous transaction lines. Global Filament transactions remain disabled; no behavior outside the audit call changed.

---

### Date
2026-07-29 (OMS Task 9B.3 — financial payloads are bounded scalars; money and rates are decimal strings; notes are carried bounded rather than dropped)

### Decision
A financial audit payload is a flat map of small scalars: identifiers, fixed-scale decimal strings and bounded labels. Money and percentages are formatted to 2 decimals and FX rates to 6 (`FinancialAuditValue`), always as strings. Accounts appear as `<role>_account_id` + `<role>_account_label` pairs drawn from a closed role vocabulary (`FinancialAccountRole`: debit, credit, source, destination, admin, transfer, beneficiary); an unknown role throws. Free-text `notes` — and a general expense's `description` — **are** carried, truncated to 255 characters.

### Reason
A float in an audit payload is JSON-encoded with binary artefacts (`1234.5600000000001`) and silently loses trailing scale (`"1000.00"` → `1000`), both of which destroy a financial record's meaning; strict `!==` diffing over normalized strings also means `"1000.00"` vs `"1000.0"` cannot masquerade as a change. Encoding the role into the payload key rather than storing it as a separate field means a role can never drift away from the account it describes. On notes: the brief forbids "unbounded notes", not notes — and dropping them entirely would make a genuine notes-only or purpose-only edit produce **no event at all**, since the diff is computed over the payload. 255 characters is well inside `AuditPayloadBounder`'s own 1000-character per-string and 8192-byte per-column caps, so the trail stays bounded while remaining complete.

### Impact
Payloads are a few hundred bytes of readable scalars; no `TransactionLine` array, Eloquent model, relation, collection or file ever reaches `audit_events`. Historical rates and amounts are byte-exact. A text-only edit is auditable. Every workflow test asserts no payload value is an array and that no line-level key (`debit_base`, `credit_base`, `amount_currency`, `line_role`) is present.

---

### Date
2026-07-29 (OMS Task 9B.3 — HasUserTracking activated on Account/AccountType with no backfill; Account gains an explicit current_balance default)

### Decision
`Account` and `AccountType` now use `App\Traits\HasUserTracking`, populating `created_by`/`updated_by` on every save from 2026-07-29 forward. **No historical row is backfilled.** `Account` additionally declares `protected $attributes = ['current_balance' => 0]`.

### Reason
Tasks 8.2 and 8.3 reconciled these columns as schema only and explicitly deferred the behavior to the Audit Log work; this is that work. Backfilling was rejected outright: inventing a creator for a row whose actor is genuinely unknown is a fabricated audit trail, which is strictly worse than an honest NULL. The `$attributes` default exists because `AccountForm` renders `current_balance` as `disabled()->dehydrated(false)` (a balance may only move through balanced entries), so the field is never submitted — without the default, a freshly created `Account` holds NULL in memory while the stored row holds the column default 0.00, and the audit snapshot taken immediately after that insert would record the in-memory NULL rather than the real value. It changes no stored data: the INSERT now sends `0` explicitly instead of relying on the same DB default.

### Impact
New and edited accounts/account types record who acted; historical rows stay NULL and are visibly, honestly unattributed. A balance `increment()`/`decrement()` writes only `current_balance` in SQL, so it never rewrites `updated_by` — correct, because the actor behind a balance movement belongs on the financial workflow's event, not on the account row. Three pre-existing test suites that hand-pick migrations (`BackfillTransactionDescriptionsCommandTest`, `TransactionDescriptionBuilderTest`, `TransactionLineDescriptionBuilderTest`) needed `users` plus the two Task 8.2/8.3 reconciliation migrations added to their lists.

---

### Date
2026-07-29 (OMS Task 9B.3 — an opening balance produces one Account event, not two; AuditedCrudService::recordCreatedWithin())

### Decision
Creating an `Account` with an opening balance > 0 produces exactly one `account` `AuditEvent`, carrying `opening_transaction_id`, `opening_transaction_number`, `opening_balance`, `opening_balance_date` and `opening_balance_fx_rate`. The opening `Transaction`, its two `TransactionLine`s, the auto-created per-currency clearing `Account`, and the auto-created `AccountType`/`TransactionType`/`TransactionSuperType` lookup rows are **not** separately audited. A new method `AuditedCrudService::recordCreatedWithin()` supports this and, like the financial recorder, throws outside an open transaction.

### Reason
`AuditedCrudService::create()` records immediately after the insert — which on this path is *before* the opening entry exists, so its transaction number could not be carried, and adding a second event for the opening entry is the duplication this phase forbids. Restructuring `CreateAccount` so it owns the transaction and records once at the end is the only shape that yields one complete event. The side-effect rows go unaudited automatically rather than by suppression: auditing is wired at explicit call sites, never through model observers, so anything created by plain `::create()` simply produces nothing — the same property that already lets seeders, migrations and factories write freely (see the 9B.2 decision on why no suppression switch exists).

### Impact
One user action, one event, with the opening entry still traceable by number. The clearing account and lookup rows are reachable through that transaction rather than through events of their own. `recordCreatedWithin()` is deliberately narrow — one caller, documented as such — and cannot be used to record outside a caller-owned transaction.

---

### Date
2026-07-26 (OMS Task 7C.5 correction pass — database-connection policy)

### Decision
Restore is supported only for the application's primary/default database connection. `DatabaseRestorer` fails closed with `database_connection_mismatch` if the resolved backup/restore connection name (`config('oms.backup.database_connection') ?: config('database.default')`) differs from `config('database.default')`, and `RestoreReconciler` no longer accepts any caller-supplied connection name — it always targets `database.default` directly.

### Reason
`migrate`, every Eloquent model, and every `DB::table()` call in the metadata-upsert/ephemeral-cleanup steps have no `->on()` override anywhere in this codebase — they all implicitly operate on `config('database.default')`. `config('oms.backup.database_connection')` exists purely as a backup-CREATION-side override (so `DatabaseDumper` can dump a non-default connection). If a restore imported into that same override while reconciliation operated on `database.default`, the two halves would silently disagree about which physical database was actually being restored — exactly the "ambiguous mixed-connection restore" the review explicitly asked to close. The alternative (explicitly threading one named connection through every reconciliation step) was rejected: nothing in the current architecture actually needs or exercises a non-default application connection, and threading one through would add real complexity to close a risk this single-connection policy closes for free.

### Impact
A restore can never partially succeed against the wrong database. If `OMS_BACKUP_DB_CONNECTION` is ever set to something other than the application's real default connection, restore fails immediately and loudly (before any mysql client path is even resolved) rather than silently reconciling a database nothing was actually imported into. A focused test (`DatabaseRestorerTest::test_mismatched_backup_connection_is_rejected_before_any_process_runs`) proves no process is ever spawned in that case.

---

### Date
2026-07-27 (OMS Task 7C.8 — call RestoreLaunchService directly instead of a real self-HTTP POST)

### Decision
`BackupManagementPage::processRestoreRequest()` invokes `app(RestoreLaunchService::class)->launch($uuid, $nonce)` directly after creating the queued row, rather than performing a genuine outbound HTTP POST to the existing signed `restores.launch` route from inside the Livewire request.

### Reason
The task asked for "the existing POST signed route" to be used and explicitly offered an alternative: "or invoke a safe shared service path that preserves the exact same authorization + replay-safe semantics." `RestoreLaunchController` itself does nothing beyond two `abort_unless` checks (real Super Admin + `backups.restore`, both already re-checked in the Filament action via `BackupAuthorization::authorize()`) before delegating to `RestoreLaunchService::launch()` — the actual replay-safety (the nonce comparison, the atomic conditional UPDATE, the lock) lives entirely inside the service, not in the HTTP/signed-URL layer. A real self-HTTP call from within the same PHP process would need to either fabricate the current session/CSRF state for a synthetic request (fragile, untestable without a real HTTP server, and provides no additional security since the signature would just be generated and immediately consumed by the same process) or dispatch through the HTTP kernel as a sub-request (session/CSRF/cookie plumbing risk for zero real benefit). Calling the service directly reuses 100% of the authoritative claim/lock/progress-init/spawn logic with nothing duplicated, and is what the codebase's own existing tests (`RestoreLaunchServiceTest`) already treat as the service's real contract independent of the controller.

### Impact
The signed `POST /restores/{uuid}/launch` route remains the sole entry point for a genuinely external/API-driven launch request (still reachable, still tested by `RestoreLaunchControllerTest`, completely unmodified) — but the Filament UI takes the shorter, safer, more testable path through the shared service. `test_backup_management_page_never_references_the_launch_route_or_controller` (an existing 7C.4 guard) continues to pass unmodified, confirming the UI genuinely never references the route/controller string.

---

### Date
2026-07-27 (OMS Task 7C.8 — add `crashed_acknowledged` to `RestoreProgressSnapshot::ALLOWED_PHASES`)

### Decision
Added exactly one new value, `crashed_acknowledged`, to the previously fixed `RestoreProgressSnapshot::ALLOWED_PHASES` vocabulary — used only as `restore_failed_phase`, never as `phase`/a `phase_history` entry — written exclusively by the new `RestoreStaleAcknowledgmentService`.

### Reason
The task explicitly required the stale-acknowledgment action to write `restore_failed_phase: crashed_acknowledged` on success, so a human-terminalized restore is honestly distinguishable from one the orchestrator itself decided to fail at some real execution phase. `RestoreProgressSnapshot::create()` validates `restoreFailedPhase` against the exact same `ALLOWED_PHASES` list used for `phase`/`phase_history` (by design, a single fixed vocabulary — see the class's own docblock on why phases are locked in ahead of time), so satisfying this requirement was impossible without extending that list by one value. This is the one place this task touched the restore engine's own schema, and it is purely additive: an older progress file with no such value decodes exactly as before, no `schema_version` bump was needed, and no existing writer (`RestoreOrchestrator`, `RestoreCommand`, `RestoreTerminalResultWriter`) was changed or gained a new code path that could produce this value — only the new service does.

### Impact
`docs/RESTORE_RECOVERY.md` §2's "key fields to read" note was updated so an operator reading a terminal progress file knows `restore_failed_phase = crashed_acknowledged` means "a Super Admin used the UI acknowledgment action," not "the orchestrator failed at a phase literally named that" — the real failing phase is whatever `phase_history`'s last entry before the terminal one shows.

---

### Date
2026-07-27 (OMS Task 7C.8 acceptance pass — raise RestoreAttachmentActivationServiceTest's default mover retry margin)

### Decision
`RestoreAttachmentActivationServiceTest::service()`'s default `NativeAttachmentMoveRunner` (used by every test that doesn't inject its own `FakeAttachmentMoveRunner`) now uses `maxAttempts: 5` instead of `maxAttempts: 1`, keeping `retryDelayMs: 0`.

### Reason
A real default-order regression run failed once with `test_rollback_rejects_a_shared_handle` throwing `RestoreAttachmentSwapException::liveToQuarantineFailed()` — a test that asserts LOCK rejection behavior, not move-retry behavior. Traced to the exact cause: `RestoreAttachmentActivationService::activate()` catches `Throwable` from `$this->mover->move()` and always rethrows this one exception, and the test file's default mover had zero retry margin (`maxAttempts: 1`) versus the real production binding's config-driven default of 5 attempts (`AppServiceProvider`, `oms.backup.restore.attachment_move_retry_attempts`). Every "happy path" test in the file inherited this artificially fragile default, making it susceptible to the exact class of one-off Windows/Laragon transient rename contention `NativeAttachmentMoveRunner`'s own retry logic exists to absorb — confirmed by 10/10 clean re-runs of the specific test and 29/29 clean re-runs of the whole class after the fix, versus the single environmental failure before it (never reproduced again across two full `--order-by=random` regression runs either). Tests that need an EXACT, deterministic failure count already inject their own `FakeAttachmentMoveRunner` and are unaffected — this change only affects tests where a rename is expected to genuinely succeed.

### Impact
This is a test-file-only change with zero effect on production retry behavior (`AppServiceProvider`'s real binding was never `maxAttempts: 1` — only this one test helper was). Any FUTURE test file that constructs its own `NativeAttachmentMoveRunner` directly (rather than using this file's `service()` helper) should default to a retry count matching production, not `1`, for the same reason.

---

### Date
2026-07-27 (OMS Task 7C.8 acceptance pass — never run background and foreground `php artisan test` concurrently)

### Decision
Established as a hard testing-process rule for this codebase (not a code change): never run a background `php artisan test` invocation while a foreground one is also running, and never run two backgrounds simultaneously.

### Reason
While investigating a second wave of seemingly-flaky failures spanning `BackupManagementPageTest`, `RestoreWatchdogCommandTest`, `RestoreArchivePreparerTest`, `RestoreOrchestratorHeartbeatTest`, `RestoreStaleDetectorTest`, and `BackupNotificationTest` — none of which share any obvious relationship — the actual cause was traced to a background `php artisan test --filter="Restore|Backup"` run still executing while several foreground `php artisan test` commands were also being run in the same session. `Storage::fake()` in this codebase resolves to FIXED, non-per-process-randomized physical paths (`storage/framework/testing/disks/{disk}`) rather than a unique temp directory per process, so two concurrent PHPUnit processes genuinely corrupt each other's fake filesystem state (one process's `cleanDirectory()`/file writes racing the other's reads) and contend for the same real restore-subsystem `flock()` file. Every failure in that second wave was reproduced-then-explained by this and, critically, every single affected file/class came back 100% clean when re-run individually with zero concurrent test processes.

### Impact
No production or test code needed to change for this — it was a process discipline gap, not a defect. Documented here so a future session (human or AI) does not waste time root-causing what looks like flaky/order-dependent test behavior when the actual cause is simply two `php artisan test` processes running at the same time. The large `Restore|Backup` regression runs in this codebase should always be run one at a time, waited on to completion, before starting anything else that touches the database or `Storage::fake()` disks.

---

### Date
2026-07-27 (OMS Task 7C.8 acceptance pass — re-check scope compatibility against a freshly-read source record)

### Decision
`RestoreRequestService::createQueuedRestore()` now calls `RestoreScopeCompatibility::isCompatible()` a second time, inside the locked section, against `$freshSource->scope` (the just-re-fetched database row) — not only the pre-lock check against the caller's original, possibly-stale `$sourceBackup->scope`.

### Reason
Every OTHER eligibility field (`type`, `status`, `verified_at`, archive existence — all inside `assertSourceEligible()`) was already re-derived from a freshly-`fresh()`-fetched record at the exact moment the lock is held, closing the gap between "what the caller's in-memory object shows" and "what the database actually says right now." Scope compatibility was the one exception, checked only once, before the lock, against the caller-supplied instance. `scope` is effectively immutable in this codebase today (nothing currently updates it after creation), so the real-world risk was low — but the inconsistency with every sibling check was real, and the acceptance review specifically asked to confirm every eligibility condition is genuinely re-verified at execution time, not just visibility time.

### Impact
Two new tests (`test_eligibility_is_rechecked_against_a_fresh_read_not_the_callers_stale_instance`, `test_scope_compatibility_is_rechecked_against_a_fresh_read`) prove this by mutating the underlying database row directly — bypassing the caller's in-memory object entirely — and confirming the service still rejects. If `scope` ever becomes mutable in a future phase, this recheck is already in place rather than needing to be added reactively.

---

### Date
2026-07-26 (OMS Task 7C.5 correction pass — durable reconciliation snapshots)

### Decision
Extend the existing signed `RestoreProgressSnapshot` protocol with an optional, nullable `reconciliationSnapshot` field (a new bounded `RestoreReconciliationSnapshot` value object) rather than inventing a second, separate persistence mechanism for the source/safety/restore metadata snapshots a future orchestrator must capture before the database import runs.

### Reason
The progress-file protocol is already the one thing this architecture treats as durable and trustworthy independent of the live database (see `RestoreActivityGuard`'s own reasoning for why it never trusts the DB row alone). Reusing it — rather than adding a second signed file, a second signing key, or a second read/write/atomicity implementation — means the reconciliation snapshot inherits every guarantee already built and tested for progress.json (atomic durable writes, signature verification, bounded size, crash-safety) for free, and a single read gives an orchestrator both "what phase was this restore in" and "what do I need to reconstruct metadata with" in one already-verified payload. A schema_version bump was deliberately avoided — making the field optional/nullable and having the reader treat an absent key exactly like an explicit `null` keeps every progress file written by 7C.2/7C.4 code fully readable with no migration of on-disk state.

### Impact
`RestoreMetadataUpserter` can now be handed snapshots recovered from progress.json even if the database itself was replaced/wiped by the import and crashed before reconciliation ran — proven by `RestoreReconciliationSnapshotRecoveryTest`, which drops `backup_operations` entirely between writing the snapshot and reconstructing from it. `RestoreReconciliationSnapshot` deliberately does not duplicate the outer envelope's own fields (phase/result/last_heartbeat_at/error_summary stay exclusively on `RestoreProgressSnapshot`) — it only carries the three metadata snapshots the future orchestrator's own write-before-import step will supply.

---

### Date
2026-07-25 (OMS Task 7C.5 — streamed database restoration and post-import reconciliation services)

### Decision
`RestoreReconciler` depends on four interfaces with no default implementation (`RestoreDatabaseConnectionResetter`, `ArtisanCommandRunner`, `RestoreMetadataReconstructor`, `RestoreEphemeralTableCleaner`) rather than constructing any of its four collaborators itself, even though `ArtisanCommandRunner`/the metadata/ephemeral steps could safely run for real against the test suite's own migrated SQLite database.

### Reason
The one collaborator that genuinely cannot run for real in a test (`RestoreDatabaseConnectionResetter` — purging the test's own `:memory:` connection would destroy its entire schema) forced an interface anyway; making all four collaborators interfaces, injected with no default, is what lets a single shared order-log test spy prove the exact required 7-step cross-collaborator sequence and every failure's short-circuit behavior in one focused, fast test file, instead of needing a slower, real-DB-and-real-Artisan integration test to observe ordering indirectly.

### Impact
`RestoreReconcilerTest` never touches a real database purge, a real Artisan command, or a real metadata write — it is a pure ordering/failure-propagation test. `RestoreMetadataUpserter`/`RestoreEphemeralTablePolicy`'s own real-DB behavior is instead proven separately, each in its own dedicated test file. `AppServiceProvider` binds all four to their production implementations so a future orchestrator can resolve `RestoreReconciler` through the container with no further wiring.

---

### Date
2026-07-25 (OMS Task 7C.5 — streamed database restoration and post-import reconciliation services)

### Decision
`BackupOperationSnapshot`/`RestoreOperationSnapshot` do not accept `status` as a constructor parameter at all — `BackupOperationSnapshot` always represents an authoritative Completed+verified backup, and `RestoreOperationSnapshot` always represents a Restoring restore operation, baked into `RestoreMetadataUpserter`'s own upsert logic rather than left to whatever value a caller happens to pass in.

### Reason
The task's own required behavior is that a source/safety backup's status must be force-corrected to Completed regardless of whatever stale Running/Verifying state the imported SQL dump happens to contain, and a restore row's status must stay Restoring until a future orchestrator decides the terminal result. Making `status` a constructor parameter would have left open the possibility of a caller accidentally passing through the dump's own stale value — removing the parameter entirely makes that mistake structurally impossible rather than merely discouraged.

### Impact
Every snapshot this codebase can construct is automatically compliant with the forced-status rule; there is no code path, current or future, that can build a `BackupOperationSnapshot` claiming `Running`/`Verifying`/`Failed`, or a `RestoreOperationSnapshot` claiming a terminal result.

---

### Date
2026-07-25 (OMS Task 7C.5 — streamed database restoration and post-import reconciliation services)

### Decision
`RestoreEphemeralTablePolicy` deletes `jobs` rows only where `queue` is exactly the configured backups queue AND `reserved_at IS NULL` (not every row on that queue).

### Reason
A row with a non-null `reserved_at` is currently being processed by a queue worker — removing it out from under an in-flight worker would silently drop a job mid-execution rather than merely clearing a backlog of not-yet-started ones. The task's own wording ("pending rows") maps to "not yet claimed by a worker," which `reserved_at IS NULL` is the literal Laravel database-queue-driver definition of.

### Impact
A restore's reconciliation can never race a currently-running backup-queue job into silent data loss; only genuinely queued-but-unstarted backups-queue jobs are cleared, and every other queue's jobs (reserved or not) are left completely untouched.

---

### Date
2026-07-25 (OMS Task 7C.4 — replay-safe restore launch, global serialization, detached process launcher, `oms:restore` command shell)

### Decision
`LinuxDetachedRestoreProcessLauncher` spawns the child as a plain argv array passed directly to `proc_open()` (via Symfony `Process`) — never a shell string — with `setsid --fork` as the first two argv elements, rather than building any shell-escaped command line.

### Reason
Directly inspecting the installed `symfony/process` 7.4.13 source (`Process::start()`) showed that on this PHP/OS combination (no `--enable-sigchild` fallback path), an array command is handed to `proc_open()` completely unmodified — no `/bin/sh -c` wrapper, no `exec` prefix, no string interpolation anywhere — so there is no shell-injection surface to defend against in the first place, and no escaping is needed even for the one genuinely variable argument (the restore UUID, itself regex-validated before the array is even built). The task's fallback allowance ("a small validated shell wrapper is unavoidable... only the validated UUID may be variable") assumed a shell would be required for output redirection and backgrounding; that turned out to be unnecessary once `disableOutput()`'s `/dev/null`-file-descriptor behavior (confirmed in `UnixPipes::getDescriptors()`) was accounted for, and `setsid --fork` fully replaces the "background with `&`" idiom by forking and exiting on its own.

### Impact
There is no shell command string anywhere in the Linux (or Windows) launcher to audit for escaping bugs — the entire injection-surface question is moot by construction. If a future phase ever needs additional argv elements, they must stay in the array form; introducing a shell string later would reintroduce exactly the injection surface this design avoided, and should not be done without re-deriving this same Process-source analysis.

---

### Date
2026-07-25 (OMS Task 7C.4 — replay-safe restore launch, global serialization, detached process launcher, `oms:restore` command shell)

### Decision
The restore-activity re-check inside `RestoreLaunchService::launch()`'s critical section maps `RestoreActivityState::TamperedOrInvalid` to the same HTTP 409 conflict bucket as `RestoreActivityState::Active` (reason code `restore_state_requires_review` vs. `restore_already_active`), rather than inventing a distinct HTTP status for it.

### Reason
The task's explicit response table only names four buckets (202/409/423/403-401/500); a tampered progress file is not a "locked by another subsystem operation" case (that's specifically `BackupSubsystemLock::acquireExclusive()` returning `null`) and not a launcher/progress-write failure (nothing was attempted yet) — it is, from the caller's point of view, simply "this restore cannot be launched right now," which is exactly what 409 already communicates for the replay/already-claimed cases. Distinguishing the *reason* (`restore_state_requires_review`) in the JSON body, without inventing a new status code, keeps the four-bucket contract intact while still surfacing enough information for a future recovery-flow UI to tell the two cases apart.

### Impact
A future Super-Admin recovery/acknowledgment phase should read the JSON `reason` field (not the HTTP status alone) to distinguish "another restore is genuinely active" from "a stale/tampered progress file needs manual review" — both currently surface as 409 to any caller that only checks the status code.

---

### Date
2026-07-25 (OMS Task 7C.4 — replay-safe restore launch, global serialization, detached process launcher, `oms:restore` command shell)

### Decision
`config('oms.backup.restore.php_binary')` defaults to `PHP_BINARY` rather than being left empty/required like `oms.backup.mysql_client_path`.

### Reason
`PHP_BINARY` is a correct, sane default in the two contexts this repository's own tests and local `php artisan serve` development actually run in (a real CLI `php`/`php.exe`), which keeps the launcher usable out of the box without extra `.env` setup for local/testing use. It is explicitly documented in both the config comment and the Linux launcher's docblock that this is wrong for a real php-fpm production deployment (`PHP_BINARY` there resolves to the `php-fpm` master binary, not a CLI-invocable one) and **must** be overridden via `OMS_RESTORE_PHP_BINARY` before Task 7C ever goes live on the real Hostinger VPS.

### Impact
Deploying this launcher to the real production VPS without setting `OMS_RESTORE_PHP_BINARY` to a real CLI `php` binary path will cause every restore launch to fail closed at `php_binary_missing` (since a `php-fpm` binary is not `is_executable()`-appropriate for this purpose in general, and even if it were, would not run `artisan` as an ordinary CLI script) — this is a required production deployment step, not merely a nice-to-have.

---

### Date
2026-07-25 (OMS Task 7C.4 correction pass — POST instead of GET for the launch route)

### Decision
The signed restore-launch route was changed from `GET /restores/{uuid}/launch` to `POST /restores/{uuid}/launch`, with no GET route registered at that path at all.

### Reason
The endpoint mutates state (claims a queued row) and spawns an OS process — a signed GET is still a plain idempotent-looking URL from the browser's/any intermediary's point of view, so it can be triggered by prefetching (`<link rel="prefetch">`, browser speculative navigation), automated link scanners/crawlers following every href, or a user simply pasting/opening the URL out of curiosity. POST keeps the request out of every one of those categories by convention, and additionally keeps the normal `web` middleware group's CSRF verification active as a second, independent layer on top of the signed-URL check (verified directly against `PreventRequestForgery::handle()`: CSRF is skipped only for reading verbs GET/HEAD/OPTIONS or while genuinely `runningUnitTests()` — never for an ordinary POST outside tests).

### Impact
Any future phase that generates this signed URL (the request/confirmation UI, Task 7C.5+) must generate it for a POST request (e.g. a form submission or an explicit `fetch()`/`axios` POST), never a plain link/anchor tag — an `<a href>` would only ever produce a GET, which now 405s and can never launch anything.

---

### Date
2026-07-25 (OMS Task 7C.4 correction pass — restore-execution activity gate folded into `BackupDeletionService::eligibility()`, not a separate check in `delete()`)

### Decision
The new `RestoreActivityGuard::blocksOrdinaryOperations()` check for `BackupDeletionService` is implemented as one more rule inside `eligibility()` (`restore_activity_in_progress`, memoized via `once()`) rather than as a standalone check in `delete()` before `eligibility()` is called.

### Reason
An initial implementation added the check directly in `delete()`, immediately after acquiring the shared subsystem lock and before calling `eligibility()`. This broke two pre-existing tests (`BackupDeletionServiceTest::test_a_backup_that_is_the_source_of_a_non_terminal_restore_cannot_be_deleted` and `test_eligibility_and_delete_agree_a_restore_source_backup_is_blocked`) in a way that revealed a real design problem, not just a stale assertion: `eligibility()` (used for the management page's "إمكانية الحذف" badge) and `delete()` would have disagreed about *why* a restore-source backup is blocked — `eligibility()` would still say `restore_source_in_use` while `delete()` now said `locked` for the exact same row, violating this class's own established "the badge and the actual delete rejection can never disagree" guarantee (see the 2026-07-23 deletion-eligibility decision entry). Folding the check into `eligibility()` itself restores that guarantee: both callers now derive the same answer from the same rule evaluation, exactly like every other rule this class enforces (`active_status`, `protected`, `last_known_good`, etc.).

### Impact
A `Restoring`-status restore row now blocks deleting its OWN source backup via the new, broader `restore_activity_in_progress` reason rather than the older, narrower `restore_source_in_use` reason — a genuine, intentional broadening (any backup delete is blocked while any restore is claimed/running or has an active/tampered progress file, not only the specific backup that restore happens to be reading from). The two pre-existing tests were updated to expect this new, more accurate reason code for the `Restoring` case specifically; the other active statuses (queued/running/verifying/deleting), which the new gate does not match, still correctly surface `restore_source_in_use`. The restore-activity check is memoized per `BackupDeletionService` instance via `once()` (mirroring `lastKnownGoodId()`'s existing pattern) — required because the management page resolves one shared instance and calls `eligibility()` once per visible row; without memoization this reintroduced an N+1 (caught by the pre-existing `test_creator_relationship_is_eager_loaded_without_n_plus_one` bounded-query-count test).

---

### Date
2026-07-25 (OMS Task 7C.4 correction pass — `launching` vs `lock_acquired` progress phases)

### Decision
Added a new phase, `launching`, to `RestoreProgressSnapshot::ALLOWED_PHASES`, positioned immediately before `lock_acquired`. `RestoreLaunchService` (the parent web request) now writes `phase=launching` as the initial progress state; `phase=lock_acquired` is written ONLY by the `oms:restore` command itself, and only after it has genuinely acquired its own lifetime exclusive `BackupSubsystemLock`.

### Reason
The original implementation had `RestoreLaunchService` write `phase=lock_acquired` as the very first progress state, immediately after claiming the row — but at that exact moment nothing has acquired the lifetime lock yet: the parent only ever holds the short-lived *launch* lock (released once the child is spawned), and the detached child has not even started retrying for its own lock. Reporting `lock_acquired` during that gap would be actively misleading to any future consumer of the progress file (a status UI, a watchdog, a human debugging a stuck restore) — it is not merely a naming nitpick.

### Impact
Any future phase reading `phase` from a progress file to decide "has the lifetime lock genuinely been acquired" can now trust `lock_acquired` literally — it is only ever true once the `oms:restore` process itself holds that lock. A restore observed at `phase=launching` for longer than expected is diagnostically distinct from one observed at `phase=lock_acquired` for longer than expected (the former suggests the child never started or is still retrying the lock; the latter suggests it's stuck immediately after acquiring it, before even reaching the not-yet-implemented execution engine).

---

### Date
2026-07-25 (OMS Task 7C.3 — restore preflight, decryption/verification, safe extraction, private staging)

### Decision
`RestoreDiskSpaceEstimator` uses an itemized sum of real, potentially-coexisting disk consumers rather than a flat "3 × encrypted archive size" multiplier. **Corrected after initial review**: the mandatory pre-restore safety backup is always FULL regardless of the selected restore scope, so its terms (current database size, current attachments size, and the 3-copy archive/candidate/verification pipeline) are now summed unconditionally — never conditioned on the selected scope the way the source-side staging terms are. Current database size comes from a new injectable `CurrentDatabaseSizeEstimator` (production: an `information_schema.tables` aggregate query), not from the source backup's own size — falling back to `max(source_original_bytes, 50 MiB)` only when the real query is unavailable, never to zero and never to an implausibly tiny value.

### Reason
The task explicitly forbade a flat multiplier, and an initial review round correctly identified that scaling the safety backup off the SOURCE backup's own declared size was wrong: the current live system can be far larger (or smaller) than whichever backup is being restored from, and a database-only or files-only restore still triggers a FULL safety backup of the whole current system before anything is touched — so both current DB size and current attachments size must always contribute, regardless of what the user selected to restore. Itemizing named components (rather than a blind multiplier) keeps the formula auditable; the 50 MiB floor on the current-database fallback prevents a tiny/near-zero source-backup size from making that proxy implausibly small.

### Impact
The estimate is deliberately generous, dominated by CURRENT system scale rather than the source backup's size — a small old source backup being restored onto a since-grown live system will not underestimate space (proven by a dedicated test). If a real `information_schema` query ever proves too slow/unreliable in production, `CurrentDatabaseSizeEstimator` is the single seam to revise (e.g. caching the last known value) — `RestoreDiskSpaceEstimator` itself should not need to change.

---

### Date
2026-07-25 (OMS Task 7C.3 — restore preflight, decryption/verification, safe extraction, private staging)

### Decision
`RestoreArchiveExtractor` derives every entry it ever writes strictly from the manifest object already returned by a successful `BackupArchiveContentVerifier::verify()` call — never from `ZipArchive`'s own raw directory listing — and re-validates size/hash/path-safety a second time during extraction rather than trusting that prior verification pass alone.

### Reason
`BackupArchiveContentVerifier::verify()` already proves the ZIP's exact entry set and every declared hash match the manifest, but extraction is a separate, later code path against the same decrypted file — iterating only the manifest's declared components (rather than re-listing the ZIP) makes "an entry present in the ZIP but not in the manifest can never be written" true by construction, not by a second full-set comparison. The redundant per-entry size/hash re-check during streaming is deliberate defense in depth (matches the task's explicit ask for both a `statIndex()` size check and a streamed SHA-256 comparison), protecting against a corrupted central-directory entry whose metadata itself disagrees with the actual decompressed bytes.

### Impact
Any future manifest field addition that should be extracted must be added to `RestoreArchiveExtractor`'s explicit iteration (`dump`/`attachments.files` today) — a new component silently present in the ZIP will never be staged just because it exists there. `RestoreArchivePreparationException`/`RestoreArchiveExtractionException` reason codes (`decryption_failed`, `verification_failed`, `unexpected_entry`, `size_mismatch`, `hash_mismatch`, `byte_limit_exceeded`, etc.) are the stable identifiers later phases/tests should key off, not the sanitized message text.

**Correction (final review, same day)**: `BackupArchiveContentVerifier::verify()` runs in a separate earlier call against the same decrypted file — a genuine (if narrow) gap existed between "verified" and "about to be extracted." `RestoreArchiveExtractor::assertEntrySetMatchesManifest()` now independently rebuilds the manifest's FULL expected entry set (every declared component, not just the ones the currently selected restore scope needs) and compares it, sorted, against the currently open ZIP's actual listing — mirroring `BackupArchiveContentVerifier::verifyExactEntrySet()`'s own logic — immediately before any staged content is written, rejecting a mismatch in either direction outright. Every manifest-declared attachment path is also safety/duplicate-checked at this same earlier point, so an unsafe path is rejected even when the ZIP happens to contain a literally-matching entry name.

---

### Date
2026-07-25 (OMS Task 7C.3 — restore preflight, decryption/verification, safe extraction, private staging)

### Decision
`RestoreWorkspace` has no age-based stale-cleanup method at all; its `cleanup()` only ever removes the one `{restore_uuid}/workspace/` subtree it itself creates, never the restore's UUID directory or any sibling (`progress.json`, `progress.previous.json`, a future quarantine directory, or the disk-root `.locks`).

### Reason
The task explicitly excluded implementing stale-by-age workspace cleanup in this phase. Rather than adding an unused time-based sweep now, safety comes structurally from scope: because `cleanup()` can only ever touch its own restore UUID's own `workspace` subdirectory, it is physically incapable of deleting another restore's in-progress state or any progress/lock/quarantine file, regardless of when or how often it's called — proven directly by isolation tests rather than by a policy check that would otherwise need to consult `RestoreProgressReader` before every cleanup.

### Impact
When a later phase adds an actual stale-cleanup sweep (age-based, across multiple restore UUID directories), it must not be added inside `RestoreWorkspace` itself — that class's contract is "clean up exactly one restore's own workspace, nothing else." A stale sweep belongs in a separate, explicitly-scoped service that consults `RestoreActivityGuard`/`RestoreProgressReader` before ever calling `RestoreWorkspace::cleanup()` on a UUID it didn't just fail to prepare.

**Correction (final review, same day)**: `RestoreWorkspace` gained the same injectable `SymlinkDetector` seam already used by the Backup subsystem and `RestoreArchiveExtractor`. `prepare()` and every path-returning method now walk from the target path up to the restores-disk root, refusing to proceed if any existing component is a symlink/junction/reparse point — an attacker-placed symlink at `{uuid}` or `{uuid}/workspace` could otherwise silently redirect a textually-"safe" `SafeBackupPath`-validated relative path outside the workspace entirely, even though `SafeBackupPath` itself has no way to detect that (it only ever inspects the path string, never the live filesystem). Windows junction/reparse-point detection has the same documented limitation as every other `NativeSymlinkDetector` consumer in this codebase.

---

### Date
2026-07-25 (OMS Task 7C.3 correction — restore-activity exclusion must never suppress a tampered progress file)

### Decision
`RestoreActivityGuard::scanForNonTerminalProgress()`'s `$excludeRestoreUuid` handling was rewritten: the excluded directory's `progress.json` is now ALWAYS read and validated exactly like every other directory's; only a snapshot that is BOTH valid AND non-terminal AND for the excluded UUID is ever ignored. A read failure (malformed/unsigned/invalid-signature/schema-mismatch/UUID-mismatch) for the excluded UUID still marks `$sawInvalid` and still surfaces as `TamperedOrInvalid`, exactly as it would for any other directory.

### Reason
The original implementation (`if ($excludeRestoreUuid !== null && hash_equals(...)) { continue; }` placed BEFORE the read) skipped the excluded directory entirely — never attempting to read or validate its progress file at all. That meant a corrupted or tampered `progress.json` belonging to the very UUID being excluded would never be detected, silently behaving as if the state were clean. This is exactly the "exclusion turns corrupt state into safe" failure mode the guard must never allow, caught during a dedicated final-review pass before commit, not by an initial test (the initial test suite only proved the *positive* case — a valid active file being correctly excluded — and never a tampered one).

### Impact
Any future caller of `RestoreActivityGuard::isActive($excludeRestoreUuid)` can rely on: excluding a UUID only ever weakens the check for that UUID's own *genuinely valid, non-terminal* state, never for a corrupt/untrusted one. Six new tests in `RestoreActivityGuardTest` (`test_excluding_the_current_restore_uuid_does_not_suppress_its_own_tampered_progress_file` and siblings) pin this behavior down directly — any future refactor of this method must keep them passing.

---

### Date
2026-07-23 (OMS Task 7C.2 — independent lock, signed progress protocol, atomic progress storage, restore-activity dual gate)

### Decision
Restore's authoritative subsystem-wide lock is a real OS `flock()` on a fixed file (`{restores disk}/.locks/subsystem.lock`), not a database-backed `Cache::lock()` — and reuse the existing exception/HTTP-status vocabulary (`BackupLockedException`, `BackupIntegrityException`, `BackupDeletionRejectedException::locked()`, HTTP 423) for "the subsystem lock is unavailable" rather than introducing a new reason code or exception type for it.

### Reason
This app's `cache.default` is `database` in both production and local (confirmed in `bootstrap/app.php`'s own scheduler comment), so a `Cache::lock()`'s backing row lives inside the very database a restore replaces — it cannot be trusted to survive the operation it exists to serialize, independent of any TTL-expiry concern. A real `flock()` has no such dependency, and is released automatically by the OS if the holding process dies, which is exactly the crash-safety property this design needs (see `BackupSubsystemLockHandle`'s `isLive()`, which re-affirms the lock on its own file descriptor rather than merely checking `is_resource()`, and `RestoreActivityGuard`, which is what actually decides whether a stale state may be cleared — never this lock itself). Reusing the existing exception/status vocabulary rather than inventing new reason codes for this phase keeps the diff minimal: restore doesn't have a real launch mechanism yet (Task 7C.4+), so a dedicated "blocked by restore" reason code would be speculative right now, and every existing caller/test that already handles "this operation is currently locked" continues to work unchanged for the new cause too.

### Impact
When Task 7C.4+ builds the real restore launcher/orchestrator and the exclusive lock starts being genuinely held in practice, revisit whether operators need a more specific UI message ("blocked by an active restore" vs. "blocked by another backup operation") — today both surface identically. `runWithLockAlreadyHeld()`'s handle validation (live + Exclusive + matching path) is the one place a lock-bypass is possible at all; any future caller of it must go through `BackupSubsystemLock::validateHandle()` the same way — never trust a handle by type alone.

---

### Date
2026-07-23 (OMS Task 7C.1 — restore domain/schema/config/authorization foundation)

### Decision
Reuse the existing `backup_operations` table for a restore operation's own audit row (`type = BackupType::Restore`) instead of a new `restore_operations` table, and store every restore-specific field that doesn't fit the backup-shaped columns in one new nullable JSON column, `restore_metadata`, rather than several new narrow typed columns.

### Reason
The Task 7B.1 schema already reserved `source_backup_id`/`pre_restore_safety_backup_id` and the `restoring`/`restored`/`restore_failed` status vocabulary specifically for this — its own docblocks said so before any restore code existed. Building a second table would duplicate SoftDeletes/UUID/created_by/timestamp machinery that already works and is already tested, for no real benefit. A single bounded JSON column (capped via `BackupOperation::RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES`) covers requester-identity-snapshot/source-and-safety-UUID-snapshots/confirmation-timestamp/phase-history/sanitized-result without a migration per field, and was explicitly the smaller of two options weighed during the read-only planning phase (a second narrow-column migration was the original proposal; this replaced it after a review round).

### Impact
Any later 7C phase adding a new piece of restore-specific audit context should add a key to `restore_metadata`, not a new column, unless that data needs to be indexed/queried directly (the one exception already made is `launch_nonce`, kept as its own column specifically because Task 7C.4's atomic launch-claim needs it in a plain `WHERE` clause). `restore_metadata` is documented but not yet written by any code — the writer/bound-enforcement responsibility is explicitly deferred to Task 7C.2+, not built prematurely here.

---

### Date
2026-07-23 (Backup management page — deletion-eligibility UI clarification)

### Decision
Expose the deletion-eligibility decision as a new public `BackupDeletionService::eligibility()` method (returning a small `BackupDeletionEligibility` value object) rather than re-implementing any of the rejection rules inside the Filament page, and give `BackupDeletionRejectedException` one new `forReason(string $reasonCode)` dispatcher so `delete()` itself is refactored to consume the same `eligibility()` result instead of re-checking each rule inline a second time. Memoize the "last known-good" lookup per `BackupDeletionService` instance (via `once()`) and have the Filament page resolve one shared instance for the whole table render, rather than one per row.

### Reason
The task explicitly required the badge to "reflect the exact same rules used by the delete action" and forbade duplicating deletion rules in the Filament page — a second, separately-maintained copy of "is this backup protected / last-known-good / locked" would inevitably drift from `delete()`'s real behavior over time. Routing both `delete()` and the badge through one `eligibility()` method makes that impossible by construction — a rule change only ever needs to happen once. The per-instance memoization was necessary because `eligibility()` is called once per visible table row on every 10-second poll, and the existing "last known-good" check is a real, unindexed-by-row-count aggregate query — without memoization this would have re-introduced an N+1 the codebase already has a dedicated bounded-query-count regression test guarding against (`test_query_count_remains_bounded_as_row_count_increases`).

### Impact
Any future addition to `BackupDeletionService`'s rejection rules should be added to `eligibility()` (not `delete()` directly) so the Filament badge picks it up automatically — `delete()` now only adds the actual lock-and-perform-delete step on top of whatever `eligibility()` already decided. The "locked" state is a best-effort point-in-time peek (acquire-then-immediately-release), not a persisted flag — a badge showing "مسموح" can still occasionally lose a race to a concurrent download/verify/delete between page render and the next real delete attempt; this was accepted as consistent with the existing `BackupFileLock` design (also non-blocking/advisory at the row level), not treated as a new gap. The `is_protected` `TernaryFilter` (raw manual-flag filter) and its "محمية"/"غير محمية" labels were deliberately left unchanged, since the task's explicit scope was the table column and the details-modal field only.

---

### Date
2026-07-23 (OMS Task 7B.2 — Filament backup management page)

### Decision
Build the Filament management page's authorization on a shared `App\Support\Backup\BackupAuthorization` helper (real `Super Admin` role via `hasRole()` **and** the specific `backups.*` permission), re-checked explicitly inside every action's own closure — not only via `->visible()`/page `canAccess()`. Correct a genuine 7B.1 defect discovered while implementing deletion: `BackupCreationOrchestrator::enqueue()` set `is_protected = true` for every manual backup unconditionally; changed it to default `false`. Replace `AuthorizationAcceptanceTest`'s hardcoded `155`-permission-count assertion with a registry-driven one (no duplicates in `PermissionRegistry::names()`, every registry name synced exactly once, no unexpected extra permission, `backups.*` explicitly present) instead of a new hardcoded `161`.

### Reason
Gate::before already grants a real Super Admin every ability automatically, so checking a `backups.*` permission alone would not by itself guarantee "Super Admin only" if that permission were ever manually granted to a lesser role — the same reasoning `BackupDownloadController` already used in 7B.1, now centralized and reused rather than re-derived per action. The `is_protected = true` default for manual backups was flagged during design: Task 7B.2's own spec explicitly states "manual backups may be manually deleted unless protected" and "'never auto-deleted' does not mean never manually deletable," but the unconditional flag would have made every manual backup permanently undeletable through the new UI — a real contradiction, not a matter of interpretation. Retention's automatic protection for manual backups was never actually dependent on `is_protected` (`BackupRetentionService::mustKeep()` already has its own independent `type === Manual` check), so defaulting it to `false` closes the gap without weakening any existing guarantee. The `155`-count test's own docblock stated its purpose was an *absolute* (not merely relative) guarantee distinct from other tests' relative equality checks; a fixed literal is exactly the kind of assertion that goes stale the next time any module gains a permission — deriving correctness from the registry itself, plus explicit `backups.*` membership as a documented regression guard, gives the same guarantee without a new expiration date.

### Impact
`App\Support\Backup\BackupAuthorization` is now the one place "is this actor allowed to do X to backups" is decided outside the download controller (which keeps its own pre-existing, independently-correct check unchanged) — any future backup action should call it rather than re-deriving the Super-Admin-plus-permission rule inline. `BackupCreationOrchestrator::enqueue()`'s `is_protected` default is now `false` for manual backups; do not restore the old unconditional `true` without re-confirming this contradiction has been resolved differently. `AuthorizationAcceptanceTest::test_permission_registry_contains_exactly_155_permissions` no longer exists under that name (renamed `test_permission_registry_is_exactly_and_uniquely_synchronized`) — any future permission-count regression will surface as a missing/duplicate/unexpected-permission assertion failure, never a stale magic number. See `docs/TASKS_LOG.md` (2026-07-23 "Task 7B.2" entry) for the full file list and test counts.

---

### Date
2026-07-23 (OMS Task 7B.1 — backup core foundation)

### Decision
Build a focused, in-house OMS backup implementation (mysqldump + ZipArchive + a dedicated libsodium Secretstream envelope + Laravel queue/cache primitives) rather than installing `spatie/laravel-backup` or any other third-party backup package. A newly-created backup must pass the exact same full content verification as an already-published one — via one shared `BackupArchiveContentVerifier` implementation behind a narrow contract — **before** it is ever published or marked `completed`, and `verified_at` is stamped at that same moment. Scheduled backups are deduplicated by a database-enforced unique `deduplication_key`, not an application-level check alone. A second, per-backup Cache lock (distinct from the single global operation lock) protects a published archive from deletion while it is being downloaded, verified, or (later) restored.

### Reason
The Task 7A audit found no existing backup package, no restore-workflow equivalent in any mainstream Laravel backup package, and an approved design (typed double-confirmation restore, pre-restore safety backup, maintenance mode) that would have to be built by hand regardless of what created the archive — adopting a package would only cover roughly the backup-creation third of the real scope while adding an unverified Laravel-13/Filament-5/PHP-8.3 compatibility risk. Verifying only *after* publication (the original design) left a window where a corrupted-but-published archive could sit in the table as `completed` with no verification at all until a separate job happened to run; verifying the *candidate* first and publishing only on success closes that window structurally. An application-level "check then insert" for scheduled dedup is race-prone under two concurrent workers; the database's own UNIQUE constraint is the only mechanism that is actually atomic. A single global lock does not stop a download/verify/restore from racing a retention deletion of the *same* file while an unrelated backup is otherwise idle — a second, file-scoped lock is required for that specific race, and it must be held through the real content-transmission phase (via the `StreamedResponse` callback), not merely while the controller method executes, since a download response is only fully "in flight" once the client is actually receiving bytes.

### Impact
`App\Services\Backup\BackupCreationOrchestrator`, `BackupIntegrityVerifier`, `BackupRetentionService`, and `BackupDownloadController` are the only places these rules may live — any future backup-related code must reuse them rather than reimplementing verification, deduplication, or locking. `backups.restore`, `RestoreBackupJob`, and the Filament management page are explicitly out of scope for this phase (Task 7C / Task 7B.2) — do not add restore logic under the assumption Task 7B.1's structures already anticipate it beyond the vocabulary already present in the `BackupStatus`/`BackupType` enums and the `source_backup_id`/`pre_restore_safety_backup_id` columns. See `docs/TASKS_LOG.md` (2026-07-23 entry) for the full file list and `docs/AI_PROJECT_MEMORY.md` for the encryption-envelope, mysqldump-streaming, and permission-model detail.

---

### Date
2026-07-22 (OMS-wide CRUD redirect standard)

### Decision
Standardize post-operation navigation for every full-page Filament CRUD flow: successful Create → the created record's View page, successful Edit → the updated record's View page, successful page-level Delete → the resource Index. Implement it as a single shared concern, `App\Filament\Concerns\RedirectsToResourceView`, that overrides only `getRedirectUrl()` and is used by all 22 editable resources' 44 Create/Edit page classes. Redirect to View only when `canView($record)` passes; otherwise fall back to the resource Index.

### Reason
Filament v5.6.7's stock defaults were inconsistent with the required UX: Create already lands on View, but Edit returns `null` and stays on the edit form. Overriding 44 page classes individually would duplicate logic and could silently drift; one concern makes the rule uniform and future-proof (a new full-page resource that forgets it is caught by `CrudRedirectStandardStructureTest`). Resolving URLs through the Resource (`getUrl`) — never a hard-coded `/admin/...` path — preserves panel/tenant/route context. Gating the View redirect behind `canView` avoids sending a Create/Edit-only user to a predictable 403; the Index is the safe fallback, and the View page's own policy still runs on arrival, so authorization is never weakened or bypassed.

### Impact
Page-level Delete was deliberately NOT overridden: Filament already redirects `DeleteAction`/`ForceDeleteAction` on a record page to the resource Index via `InteractsWithRecord::getDefaultActionSuccessRedirectUrl()`, which already matches the standard — do not add a redundant delete override. RelationManager/modal CRUD (Currencies, ProjectCosts, Projects, Transactions) and the four read-only resources (Attachments, Permissions, TransactionLines, Transactions) are intentional exceptions and must stay unchanged. No View page has or needs a DeleteAction. No authorization, persistence, validation, notification, accounting, or financial payload behavior changed. Targeted tests: 402 total, 399 passed, 0 failed, 3 skipped, 0 risky, 1242 assertions (the 3 skips are the pre-existing read-only "no create route" skips).

---

### Date
2026-07-22 (test-suite cleanup)

### Decision
Deleted the stock Laravel `ExampleTest` rather than making it pass, and fixed the risky `RoleAssignmentSafetyTest` test by adding an assertion on the caught `ValidationException`'s `roles` error (not by adding a filler assertion).

### Reason
`ExampleTest` asserts `GET /` returns 200; OMS is a Filament-only admin app that intentionally has no user-facing `/` route, so satisfying the test would have required adding a fake route — changing production routing purely to appease default scaffolding, which is worse than deleting a test that verifies nothing about this app. The risky test's real purpose is that a crafted Super Admin role assignment is refused server-side; asserting the exception carries a `roles` error verifies exactly that outcome, so the test now proves something meaningful instead of relying on an empty catch.

### Impact
The default Laravel `/` test target is gone; do not re-add an `ExampleTest` or a `/` route to satisfy scaffolding. Any future "expected exception" test in this codebase should assert on the exception (message key / errors) rather than leaving an empty catch, to avoid the same PHPUnit "no assertions" risky flag.

---

### Date
2026-07-22 (OMS Task 6D — secure financial attachment registry)

### Decision
For the registry's single amount+currency column, the two multi-currency operations (`ProjectCostBudget` disbursement, `GeneralExchange`) display **`final_amount` + `disbursementCurrency`**; the three single-currency operations display their own `amount` + `currency`. Parent-permission scoping is centralized in `FinancialAttachmentRegistry` and enforced twice — as a `whereHasMorph('attachable', <allowed types>)` list scope AND as a per-record `AttachmentResource::canView()` check (403 on a direct View URL) — and the "file available vs missing" filter was deliberately **not** built.

### Reason
`final_amount`/`disbursementCurrency` is a denormalized, already-approved stored field (no recomputation from transaction lines), is the exact figure the ExecutionPayment View already uses to represent a budget ("المبلغ المرصود"/"عملة المرصود"), and keeps one amount with one currency so currencies are never blended — the alternative (`original_amount`+`sourceCurrency`) was offered and the user approved the final-amount choice. Enforcing authorization in two independent layers satisfies "do not rely only on hiding table rows": the list scope hides unauthorized rows, and `canView()` independently returns 403 on a crafted/direct View of a supported-but-unauthorized record, so neither layer alone is load-bearing. The file-availability *filter* was omitted because file existence lives on the filesystem, not in a SQL column — a filter would have to `stat()` every row's file across the whole dataset (unbounded per-row filesystem work), violating the no-N+1 constraint; existence is instead surfaced per visible row in the Status column and in open/download visibility, bounded to the current page.

### Impact
Any future change to the multi-currency amount presentation is a one-line edit in `FinancialAttachmentRegistry::amount()`/`currency()`, but must keep amount and currency from the same side (never mix). The supported-type list in `FinancialAttachmentRegistry` must stay identical to `AttachmentController::SUPPORTED_ATTACHABLE_TYPES` (the Task 6A security boundary, left untouched) — a guard test (`AttachmentRegistryTest::test_supported_types_match_the_secure_controller_allowlist`) fails on any drift. Do not add a global "all financial attachments" permission and do not add a filesystem-scanning filter without a bounded/indexed strategy.

---

### Date
2026-07-22 (OMS Task 6C — obsolete orphan public-file cleanup)

### Decision
Task 6C was treated as **satisfied without performing any deletion** once pre-flight found the six orphan public files already absent from disk, and **no quarantine/manifest or legacy-migration Artisan tooling was built** (the step Task 6A/6B's NEXT_STEPS had queued as 6C's scope). Only read-only verification, targeted tests, and documentation were done; per-file pre-deletion SHA-256/sizes were not invented for files that were already gone.

### Reason
The task's premise (six files on disk to delete, hashing each before deletion) no longer held: the files were present and byte-identical at commit `27969b5` (2026-07-21) but were removed from disk before this task ran, and — being git-ignored uploads — their removal is invisible to `git`. Deleting nothing and then reporting a "successful deletion" (or fabricating pre-deletion hashes) would have been dishonest, so the work was reframed to *verify and document the already-achieved end state*. The quarantine/legacy-migration tooling that Task 6A/6B anticipated is provably unnecessary now: the `attachments` table is empty (0 rows, verified with `withTrashed()`), so there are no `disk = public` legacy rows to migrate, and every current/future financial attachment already writes to the private `attachments` disk and is served only through the protected `attachments.show` route (Task 6B). Building migration tooling for a population that is empty and cannot grow via the public path would be dead code.

### Impact
There is no orphan-quarantine or legacy-file-migration command in the codebase, by design — if a future `disk = public` row ever appears (e.g. a restored old backup), that scope must be re-opened and re-approved rather than assumed to exist. The observed post-cleanup fact that any nonexistent `/storage/...` path returns **HTTP 403** (not 404) on this environment's Apache — while an existing file like `/storage/.gitignore` returns 200 — is the expected server behavior; a future check that the old public URLs "no longer serve a file" should assert non-200 / no file content, not specifically 404.

---

### Date
2026-07-21 (OMS Task 6B — financial Resource attachment cutover)

### Decision
`AttachmentUploadService::store()` creates the `Attachment` row first — with a temporary `file_name`/`file_path` — obtains its real database id, then builds the final deterministic filename and moves the file, then updates the row with the final metadata. Move failure is detected by checking `move()`'s boolean return value, not by catching an exception.

### Reason
The final filename format (`{prefix}_{id}_{Ymd}_{amount}.{ext}`) requires a real attachment id, and the task explicitly forbade inventing one via `MAX+1` (a classic race condition under concurrent uploads). Creating the row first and updating it once the id is known is the only race-free way to satisfy that constraint. Separately, the `attachments` disk is configured with `'throw' => false` (matching `AttachmentStorageService`'s own disks), so a real Flysystem-level move failure on that disk surfaces as `move()` returning `false`, not a thrown exception — code that only wrapped `move()` in a try/catch would silently miss this failure mode and leave a dangling `Attachment` row pointing at a file that was never actually written.

### Impact
Any future change to `AttachmentUploadService::store()` must preserve both properties: never derive the final id another way, and never assume a `throw`-catching pattern alone is sufficient for filesystem operations on this disk. Covered by `AttachmentUploadServiceTest::test_no_dangling_attachment_row_remains_when_the_move_fails` (Mockery-backed, since the real fake disk doesn't reproduce a `throw => false` failure deterministically) and `test_old_attachment_is_never_touched_by_a_failed_store_call`.

---

### Date
2026-07-21 (OMS Task 6B — financial Resource attachment cutover)

### Decision
A replacement upload is stored via `AttachmentUploadService::store()` **before** the previous active `Attachment` row is soft-deleted, in every one of the 5 Edit pages' `handleRecordUpdate()`. Removal (the `remove_current_attachment` checkbox) and replacement share the same "old row untouched until the new state is confirmed" ordering; when both are submitted together, replacement takes precedence and the old row is soft-deleted exactly once.

### Reason
Filesystem writes are not covered by `DB::transaction()`. If the old row were soft-deleted first and the new upload then failed (missing temp file, disk move failure, etc.), the record would be left with no active attachment at all — a strictly worse outcome than "the edit failed, try again" for an accounting document. Storing first means a failed replacement always leaves the previous, still-valid attachment active and unchanged; only a successful store is followed by soft-deleting the old row, guaranteeing at most a brief moment where two rows are technically active (never visible outside the same request) and never zero.

### Impact
This ordering must be preserved in every current and future Edit page that adopts `AttachmentUploadService`. Covered by `FinancialAttachmentCutoverTest::test_edit_failed_replacement_leaves_the_previous_attachment_active_and_unchanged` and `test_edit_replacement_takes_precedence_over_simultaneous_removal`, parameterized across all 5 resources.

---

### Date
2026-07-21 (OMS Task 6B — financial Resource attachment cutover)

### Decision
A single reusable Blade component (`resources/views/filament/components/secure-attachment-preview.blade.php`), rendered via `Filament\Schemas\Components\View`, is used identically on all 5 View pages and all 5 Edit pages (visible on Edit only when an active attachment exists, via Filament's automatic `?Model $record` closure injection — `null` on Create). It never receives or renders `Storage::url()`, a raw `file_path`, or an absolute filesystem path — only the Attachment's own id, from which it builds `route('attachments.show', [$id, 'view'|'download'])` server-side.

### Reason
Ten separate hand-built HTML blocks (one per View/Edit page) calling `Storage::disk('public')->url()` directly was both the literal vulnerability Task 6A/6B exist to close and, independently, needless duplication of the exact same three-state (image / non-image / empty) display logic. Centralizing it in one component makes "no raw storage URL anywhere in the 5 financial flows" a single-file property to verify (and `grep`-confirm) rather than ten.

### Impact
Any future financial Resource that displays an attachment should reuse this component rather than reimplementing display logic. If a new attachment-bearing Resource is added, wire it into the same `attachments.show` route via `AttachmentController::SUPPORTED_ATTACHABLE_TYPES` first, exactly as Task 6A's allowlist already requires.

---

### Date
2026-07-21 (final authorization acceptance testing)

### Decision
The absence of a separate `users.assign_roles` permission in `PermissionRegistry` is accepted as the current approved design, not a defect. Normal role assignment stays controlled by `users.create`/`users.update` plus `UserManagementService`'s actor-privilege-subset validation (`assignableRoleNames()`, `createUser()`/`updateUser()` server-side revalidation); `users.assign_super_admin` remains the separate, independently-checked permission specifically for assigning the Super Admin role. No permission was added or renamed.

### Reason
The final acceptance spec named `users.assign_roles` as an expected permission, but a direct check of `PermissionRegistry` confirmed it was never defined — role assignment has always been gated by the combination of `users.create`/`users.update` and the privilege-subset logic already fully covered by `RoleAssignmentSafetyTest`/`PrivilegeSubsetProtectionTest`. Adding a new permission to match spec wording, without a proven behavioral gap, would be a registry change made to satisfy documentation rather than to fix a real defect — explicitly out of scope for a testing-only task.

### Impact
`PermissionRegistry` is unchanged. Any future spec or documentation referencing `users.assign_roles` should be corrected to describe the actual mechanism (`users.create`/`users.update` + privilege-subset validation) rather than implying a dedicated permission exists.

---

### Date
2026-07-21

### Decision
The permission-synchronization action requires the actor's **exact** `Super Admin` role (via `hasRole()`) in addition to the `permissions.sync` ability, checked together in `PermissionManagementService::canSync()`/`sync()` — a non-Super-Admin manually granted `permissions.sync` is still rejected.

### Reason
Synchronization mutates central authorization data (which permissions/roles exist, and the five system roles' default permission sets), so a permission grant alone is not an adequate gate for it — unlike an ordinary `module.action` permission, this one action can reshape every other permission check in the system. `Gate::before` grants a real Super Admin every ability including `permissions.sync` automatically, but `hasRole()` is a plain relation check nothing can short-circuit, so it is the one condition that cannot be bypassed by accidentally (or maliciously) assigning `permissions.sync` to a lesser role.

### Impact
`PermissionManagementService::sync()` is the single place this combined rule lives; any future UI or command that wants to trigger synchronization must go through it rather than calling `PermissionSyncService::sync()` directly, or it will bypass this protection.

---

### Date
2026-07-21

### Decision
`PermissionResource` hard-overrides all 8 mutation `canX()` methods (`canCreate`/`canEdit`/`canDelete`/`canDeleteAny`/`canForceDelete`/`canForceDeleteAny`/`canRestore`/`canRestoreAny`) to unconditionally return `false`, rather than relying on `PermissionPolicy` denying those abilities.

### Reason
Same structural gap already documented for `RoleResource`/`RolePolicy`: `Gate::before` (`AppServiceProvider`) bypasses every Policy check for a real Super Admin actor, so `PermissionPolicy::create()`/`update()`/etc. returning `false` is never actually consulted for that actor via the normal `$user->can(...)` path. Since permission mutation must be impossible for *everyone*, including Super Admin (permission names/existence must only ever change through `PermissionRegistry` + `PermissionSyncService`), the resource-level override — which never calls Gate/`can()` at all — is the only guarantee that actually holds. No Create/Edit page classes were built at all (not just hidden), so the create/edit routes are genuine 404s.

### Impact
Any future change to `PermissionResource` must preserve these 8 overrides verbatim; removing one would silently reopen a mutation path for Super Admin regardless of what `PermissionPolicy` says. Covered by `PermissionResourceLivewireTest::test_structural_overrides_deny_mutation_even_for_super_admin`.

---

### Date
2026-07-21

### Decision
`permissions.sync` lives in its own new `PermissionRegistry` group (`system_permissions`, Arabic label `النظام والصلاحيات`) rather than being folded into the existing `permissions` module (`permissions.view_any`/`permissions.view`) or the existing `special` group (`صلاحيات خاصة`, currently only `users.assign_super_admin`).

### Reason
`permissions.view_any`/`permissions.view` are ordinary read-operation permissions generated by the same `view_any`/`view` operation pattern as every other module; `permissions.sync` is a structurally different, system-level action with no view/create/update/delete analogue, and the task explicitly specified `النظام والصلاحيات` as its group label, distinct from the existing `صلاحيات خاصة` group.

### Impact
`RoleForm`'s grouped-checkbox display (already driven generically by `PermissionRegistry::groups()`) shows `permissions.sync` under its own clearly-labelled section with no code change needed. Any future system-level (non-CRUD) permission should default to this same `system_permissions` group unless a more specific one is clearly warranted.

---

### Date
2026-07-15

### Decision
For the operational-data cleanup tool, partner/donor classification uses only the authoritative `partners.is_donor` boolean. All non-donor partners are treated as a single `unknown_unclassified` bucket, preserved and reported — never a deletion candidate in this phase, even though the task description anticipated separate association/beneficiary/vendor categories.

### Reason
Auditing the actual schema (`partners`/`partners_types` migrations, `Partner`/`PartnerType` models, `PartnerForm`) found no field distinguishing an association/system entity, operational beneficiary, or operational vendor from any other non-donor partner — `partner_type_id` only points to free-text business categories (e.g. "جمعية"/"فرد"/"وزارة") that the task instructions explicitly forbid inferring semantics from. Currently both existing partners are donors, so this is moot today, but the code must not guess a classification the schema doesn't support.

### Impact
No partner row is ever deleted by this tool, in dry-run or apply. If beneficiary/vendor entity types are added to the schema later (e.g. a new `entity_category` field), the service's `buildEntityClassification()` method is the single place to update.

---

### Date
2026-07-15

### Decision
Bulk operational-table deletion in `OperationalDataCleanupService` uses `DB::table()->delete()` / `->whereIn(...)->delete()` (query builder) instead of Eloquent `forceDelete()`.

### Reason
Two independent benefits: (1) the query builder ignores the SoftDeletes global scope, so one statement removes both active and soft-deleted rows without a separate `withTrashed()` pass; (2) it never fires the `Project`/`ProjectCost`/`ProjectCostBudget`/`ProjectCostBudgetsPayment`/`ProjectCostReceipt` Eloquent observers, whose only job is flipping `project_financial_snapshots.is_dirty` — pointless churn here since those snapshot rows are deleted in the same operation. Per task instructions to avoid firing business observers unnecessarily during mass maintenance deletion, without changing the observers' behavior for normal application use.

### Impact
Deletion order must be fully explicit and FK-verified by hand (documented in the service's `DELETION_ORDER` constant and the dry-run report), since it can no longer rely on Eloquent relationship cascades or model events to keep data consistent mid-deletion.

---

### Date
2026-07-18

### Decision
Approved business rules for positive-amount validation, confirmed by the user before implementation: (1) every financial amount must be ≥ 0.01; (2) `fx_rate` must be > 0, rejected (not silently converted to 1) when submitted as 0 or negative — the existing `?: 1` fallback is only safe for a genuinely-missing key, not an explicit 0; (3) each of the administrative/transfer percentages must be in [0, 100], and their **combined** total must be strictly < 100 (exactly 100 is invalid) — because a 100% combined deduction makes `amount_after_deductions` and `final_amount` zero, which must never post; (4) execution-payment over-budget submission stays a non-blocking warning, unchanged by this task; (5) server-side account/currency/type revalidation for the 4 workflows other than execution payments is a separate, later task.

### Reason
The same-day read-only audit found these 5 workflows had `numeric()`/`required()` as their only Filament-level protection, with **zero** independent server-side check — a directly-submitted Livewire request (or a future UI regression) could post a zero, negative, or percentage-annihilated financial entry into a double-entry ledger. The combined-percentage-strictly-less-than-100 rule specifically closes the "0 remaining amount posted as valid" edge case the audit flagged.

### Impact
`App\Services\Validation\FinancialAmountGuard` is now the single authoritative place these 6 rules live; any future financial write flow (or a rewrite of an existing one) must call it before its `DB::transaction()`, not just rely on Filament's `minValue()`/`maxValue()`. Historical rows already in the database were not validated retroactively and are unaffected.

---

### Date
2026-07-18

### Decision
Approved business rules for server-side financial account validation, confirmed by the user before implementation: (1) the same account is explicitly allowed on both sides of an operation (debit=credit, source=destination, etc.) across all 5 financial workflows — no distinct-account check exists or will be added, anywhere; (2) every submitted account must exist, not be soft-deleted, and match the expected account type/bank type/currency — always, on both Create and Edit; (3) on **Create**, every selected account must additionally be `is_active = true`; (4) on **Edit**, an account unchanged from the record's originally-saved account_id may remain inactive (a historical record may reference an account that was active when created but has since been deactivated), but if the user replaces it with a different account, that new account must be active — accounts are never silently swapped or reactivated by this validation; (5) existing historical data (including any currently-inactive or otherwise "invalid" account already referenced by a saved record) must not be modified, repaired, restored, reactivated, or deleted by this task.

### Reason
The same-day read-only audit found the 4 non-execution-payment workflows had zero independent server-side account validation — `account_type_id`/`bank_type_id` submitted alongside an `account_id` are pure Livewire UI state, never persisted, so a directly-submitted request could pair a real `account_id` with a mismatched type/bank/currency that the rendered Select would never have offered. The audit also found every balance-update call used `Account::find($id)?->increment/decrement(...)`, silently skipping the balance update (while still writing the transaction line) whenever `$id` pointed at a missing or soft-deleted account — closed by using the already-guard-verified `Account` object instead. The Create-vs-Edit active-account split specifically avoids two failure modes: allowing brand-new operations against a deliberately-deactivated account (Create), and breaking the ability to edit an old, still-valid record for an unrelated reason (date, notes) just because the real world account it references was closed sometime after creation (Edit).

### Impact
`App\Services\Validation\FinancialAccountGuard` is now the single authoritative place these rules live; any future financial write flow must call `assertAccounts()` before its `DB::transaction()` and use the returned `Account` objects for balance updates, not a fresh `Account::find($data[...])?->`. `ExecutionPaymentForm::validateCreditAccount()` (previously the only such guard in the codebase, with no active-account check at all) now accepts the same active-account behavior via two additive optional parameters, so all 5 financial workflows are consistent on this rule going forward. No historical data was touched — any currently-inactive account already referenced by a saved record remains exactly as-is and stays editable as long as it isn't replaced.

---

### Date
2026-07-16

### Decision
Execution Payment's editable credit-account replacement is restricted to the **same currency** as the execution payment (the selected budget's disbursement/destination currency). No exchange-rate/fx fields were added. No new database column was added — the actually-selected credit account continues to be stored only via `transaction_lines.account_id` on the line tagged `notes = ProjectCostBudgetsPayment::LINE_CREDIT` / `line_role = TransactionLineRole::ExecutionSource`.

### Reason
The audit found that `CreateExecutionPayment`/`EditExecutionPayment` write both the beneficiary and credit lines with one shared `$currencyId` and `fx_rate = 1` — there is no per-line currency-conversion story in this flow (unlike the disbursement flow's source→destination fx conversion). Allowing a different-currency replacement account would require introducing a second currency, a real `fx_rate`, and a second `amount_currency` per line — a materially larger redesign explicitly out of scope for "make the credit account editable." It would also violate the project-wide "never mix currencies" rule and the account-currency filter already enforced on the beneficiary side. Since `transaction_lines.account_id` on the `LINE_CREDIT` line is already a normal, mutable FK column, no migration is needed to make the account itself editable — only the currency restriction stops it from becoming a currency-mixing risk.

### Impact
`ExecutionPaymentForm::validateCreditAccount()` hard-rejects (via `ValidationException`, not just via Select filtering) any submitted `credit_account_id` whose `currency_id` doesn't equal `ExecutionPaymentForm::budgetCurrencyId($budgetId)`. If the business later genuinely needs a cross-currency temporary credit account (per the "later transfer back" scenario in the original request), that requires a separate, explicitly-approved fx-handling redesign of this flow — not a follow-up to this task.

---

### Date
2026-07-16

### Decision
Fixed the pre-existing Execution Payment Edit drift bug as part of the credit-account editability task, rather than treating it as a separate follow-up: `EditExecutionPayment::mutateFormDataBeforeFill()` now hydrates the credit-account cascade from the payment's own saved `LINE_CREDIT` transaction line, and `handleRecordUpdate()` no longer recomputes the credit account from `ExecutionPaymentForm::budgetDestinationLine()` at all.

### Reason
The same-day audit found that both methods previously re-derived the credit account from the *budget's current* destination line on every Edit — so editing an execution payment for any unrelated reason (e.g. only the date) after the underlying budget's destination account had been changed elsewhere would silently move the historical credit line onto a different account, with the balance reversal/reapplication following it. This is exactly the historical-account-preservation requirement in the approved business requirement (#8), and leaving the bug in place while adding a user-facing "override the credit account" feature would have made the drift risk worse, not better — the form's own reactive default (on a genuine `project_cost_budget_id` change) already provides the correct "apply new default only on real budget change" behavior, so no separate mechanism was needed once hydration stopped reading from the budget.

### Impact
Saving an Edit without touching the credit account (or the budget) now always preserves the exact historically-used account, regardless of what happens to the budget afterwards. Covered by `test_edit_initial_hydration_loads_saved_credit_line_not_current_budget_destination` and `test_edit_date_only_after_budget_destination_changed_elsewhere_preserves_historical_credit_account` in the new test file.

---

### Date
2026-07-16

### Decision
Adopted a project-wide OMS financial-form UI convention for credit/debit account section layout: on desktop/wide screens (≥`lg`), the creditor account section renders on the right and the debtor account section on the left, side by side in equal-width columns; on narrow/mobile/tablet screens (<`lg`), they stack vertically with the creditor section above the debtor section. Implemented via a plain `Filament\Schemas\Components\Grid::make(['default' => 1, 'lg' => 2])` wrapping the two account `Section`s, with the credit section placed first in source order — relying on native CSS Grid right-to-left auto-placement (the app already renders `dir="rtl"`) rather than any custom CSS.

### Reason
`GeneralExpenseForm.php` and `ProjectCostReceiptForm.php` previously rendered the debit and credit account sections as two separate full-width `Section`s stacked vertically, in debit-then-credit order — visually unbalanced and inconsistent with `ExecutionPaymentForm.php`'s already-corrected (same-day) credit-first order. The task explicitly required a native-Filament, CSS-Grid-based responsive solution (no bespoke CSS) and RTL-correct right/left placement verified against real rendering behavior, not just source order. CSS Grid's auto-placement algorithm places the first grid item on the right when the container's computed `direction` is `rtl`, which this app's `dir="rtl"` root already provides — so simply grouping the two sections into one 2-column grid, credit first, satisfies the requirement with zero custom styling.

### Impact
Applied to `GeneralExpenseForm.php`, `ProjectCostReceiptForm.php`, and `ExecutionPaymentForm.php` (all three already have a clear, unambiguous single credit/debit pair). **Deliberately not applied** to `ProjectCostBudgetsPaymentForm.php` (صرف مبلغ المشروع) and `GeneralExchangeForm.php` (التحويلات العامة): both have one credit/source account plus three debit/deduction/destination accounts (12–16 form fields) inside a single unified `Section::make('الحسابات')->columns(2)`, where each account's own type/bank-type/currency/account fields already rely on that shared 2-column auto-flow layout. Splitting that block apart into a distinct "credit right, debits following" grid would require restructuring the internal column flow of a Section that several *other* fields' positions implicitly depend on — a nontrivial layout rewrite with real visual-regression risk for a cosmetic-only task. Both forms already satisfy the substantive rule (source/credit account first in reading order, therefore already above all debit sections on mobile) through their existing field order, so the risk of a rewrite was judged to outweigh the purely cosmetic benefit of an explicit right/left split. If a future task wants this, it should be scoped and tested on its own, not bundled into a general layout-standardization pass.

---

### Date
2026-07-15 (execution)

### Decision
When the user-specified backup file (`storage/app/backups/oms_before_operational_cleanup.sql`) turned out not to exist at apply time, stopped and asked rather than substituting the older unrelated 2026-07-06 backup or proceeding without one. Once the user explicitly chose "create a fresh mysqldump now," created it with `mysqldump --routines --triggers --single-transaction`, passing the password only via the `MYSQL_PWD` environment variable (never as a command-line argument or in any printed output).

### Reason
The task's own mandatory rule ("If the file is missing or empty, STOP") and the standing rule against proceeding around a failed safety precondition. An old, unrelated backup would not actually protect today's data, and printing/echoing DB credentials would violate the "do not expose credentials" requirement even during a self-directed remediation step.

### Impact
None going forward — the backup now exists at the exact path required, is verified non-empty (120,266 bytes), and the apply proceeded only after that verification passed.

---

### Date
2026-07-05

### Decision
Trial Balance Phase 1 requires a single `currency_id` filter and never blends totals across currencies. If no currency is flagged `is_base`, the page falls back to the first currency by id rather than leaving the field blank. Per-row مدين/دائن balance stays plain text (uncolored); green/red is reserved exclusively for the overall حالة الميزان (متوازن/غير متوازن) badge.

### Reason
Audit traced `transaction_lines.debit_base`/`credit_base` write paths and found they always hold the line's own-currency amount, never a true company-base-currency conversion (even when `fx_rate != 1`). Summing them across accounts in different currencies would produce a meaningless blended total and a misleading balance verdict. The uncolored-row / colored-summary-badge split keeps "this account is in debit" visually distinct from "the ledger doesn't balance."

### Impact
No blended cross-currency Trial Balance is possible in Phase 1 — users must pick a currency to view its accounts. Opening/closing balance and exports deferred to later phases.


---

### Date
2026-07-06

### Decision
"تصنيف المعاملة" in the Comprehensive Financial Transactions report maps to `TransactionSuperType` (via `transactions_types.transaction_super_type_id`), "نوع المعاملة" maps to `TransactionType` (on the transaction, applied to all its lines), and "المشروع" is resolved only through `transaction_lines.project_cost_id → projects_costs.project_id → projects`. Unlike Trial Balance, the detail table may mix currencies, but every summary (per currency, per category, per type) keeps debit/credit totals separated per currency.

### Reason
These are the only reliable existing relations — no guessing. `debit_base`/`credit_base` remain own-currency amounts (see 2026-07-05 decision), so a journal-style listing can show mixed currencies per line, but any aggregation across lines must stay currency-scoped or it would blend meaningless totals.

### Impact
Lines whose transaction lacks a type/super type show "غير محدد"; lines without a project cost show "غير مرتبط بمشروع". Per-currency balance status can read "غير متوازن" for periods containing cross-currency transactions — expected behavior, not an error.

---

### Date
2026-07-06

### Decision
Fixed the broken "صورة الإشعار" attachment image by removing the stray empty `public/storage` directory and recreating it as a symlink (`php artisan storage:link`), rather than changing any application code.

### Reason
Investigation showed the existing code (`FileUpload` disk config, file-move-and-rename logic, `Storage::disk('public')->url()` in the view) already followed correct Laravel/Filament convention. The actual defect was that `public/storage` existed as a plain empty directory instead of a symlink, which silently made `php artisan storage:link` a no-op ("link already exists") and 404'd every attachment URL. Confirmed the directory was empty before removing it, and got explicit user confirmation before deleting it.

### Impact
All existing attachments (receipt images/PDFs, execution-payment and general-expense proofs, etc.) that were already correctly saved under `storage/app/public/...` are now servable at `/storage/...` again — this was a site-wide symlink outage, not specific to Project Cost Receipts. No code, migration, or data changed.

---

### Date
2026-07-06

### Decision
Reset all business/operational data via `TRUNCATE` on 16 explicitly-listed tables (accounts, partners, projects, projects_costs, transactions, transaction_lines, and their related cost/budget/receipt/attachment/snapshot/alert tables), keeping all settings, lookup tables, auth, and Laravel system tables untouched. Took a full `mysqldump` backup first and required explicit user approval of the keep/purge lists before executing.

### Reason
User wanted to start entering real production data from a clean slate without re-running migrations or touching schema/config/permissions. A full FK audit (`information_schema.KEY_COLUMN_USAGE`) confirmed no kept table depends on any purge table, so a single grouped truncate under `FOREIGN_KEY_CHECKS=0` is safe and consistent — no orphaned references are left in kept tables.

### Impact
All financial/operational history prior to 2026-07-06 is gone from the live database (recoverable only from `storage/app/private/backups/oms_backup_2026-07-06.sql`). AUTO_INCREMENT on all 16 tables restarts from 1. Uploaded files referenced by the now-empty `attachments` table were intentionally left on disk (not deleted).

---

### Date
2026-07-06

### Decision
Opening balances on account creation are recorded as a balanced transaction (`OPB-YYYY-XXXX`, type "قيد افتتاحي" under new super type "قيود افتتاحية") that always **debits** the new account and **credits** a per-currency clearing account "أرصدة افتتاحية" (own AccountType, code `OPB-{CUR}`). `debit_base`/`credit_base` follow the existing own-currency convention (fx_rate stored as line metadata only, never multiplied). `current_balance` became display-only on the account form. Opening lines carry `project_cost_id = null`.

### Reason
The schema has no account nature (asset/liability/equity) — `accounts_type` holds cash/bank/wallet/partner categories — and every existing financial page treats all accounts as debit-normal (debit = increment balance). Branching debit/credit by nature would invent accounting behavior the system doesn't have; the user chose the always-debit convention plus the own-currency base convention explicitly (4 options presented, all recommended options approved). Multiplying by fx_rate would have silently broken per-currency Trial Balance totals.

### Impact
Negative opening balances are not supported (form enforces min 0). Clearing accounts intentionally carry negative balances. Opening entries appear in Trial Balance / Account Statement / Comprehensive report (correct — they are real ledger entries) under their own "قيود افتتاحية" category, and can never appear in project reports or snapshots because those read only `project_cost_receipts`/`project_cost_budgets`/`project_cost_budgets_payments`. If soft-deleted lookup rows ("قيد افتتاحي" type, clearing account, its AccountType) are found, they are restored rather than duplicated.

---

### Date
2026-07-06

### Decision
"تقرير الجهات المانحة" (Donor Financial Report) keeps three currency grains fully separate rather than converting/blending them into one total: cost-side (`projects_costs`/`project_cost_receipts` currency), disbursement-source (`project_cost_budgets.source_currency_id`, also the currency of the tagged `LINE_ADMIN`/`LINE_TRANSFER` transaction lines), and execution (`project_cost_budgets.disbursement_currency_id`, matching `project_cost_budgets_payments.currency_id`). Admin/transfer deduction totals are read from the authoritative `transaction_lines` rows tagged with `ProjectCostBudget::LINE_ADMIN`/`LINE_TRANSFER` (written once, at disbursement time, by `CreateProjectCostBudgetsPayment`) rather than re-derived from the stored percentage columns.

### Reason
Same rationale as the 2026-07-05 Trial Balance decision: summing across currencies produces a meaningless blended figure. Reading deductions from the tagged transaction lines (instead of recomputing `original_amount * percentage`) keeps the report anchored to what was actually posted to the ledger, so it can never drift from the accounting records even if a budget row's stored percentage were edited after the fact.

### Impact
The report shows up to three separate per-currency tables (cost, disbursement-source, execution) instead of one blended summary — correct behavior for a donor whose projects span multiple currencies, not a defect. The deduction join assumes each disbursement transaction is created fresh and 1:1 with its `project_cost_budgets` row (confirmed true in the current `CreateProjectCostBudgetsPayment` code path) — if a future "batch disbursement" feature ever lets one transaction serve multiple budget rows, this join would need to be revisited (see NEXT_STEPS.md).

---

### Date
2026-07-14

### Decision
`transactions.description` is generated automatically by one shared service (`TransactionDescriptionBuilder`) for all 6 financial write flows, in the fixed format `دائن: {credit entries} | مدين: {debit entries} | ملخص العملية: {summary}.` — credit side always first, debit side always second, summary always last. Each entry's currency code comes from `transaction_lines.currency_id`, **never** `accounts.currency_id`. Users cannot edit this value through any of the 6 flows' forms.

### Reason
Credit-before-debit and one canonical format make the field usable for search/audit/exports without every reader needing to know each flow's internal line order. The line's own currency (not the account's) is authoritative because cross-currency flows (disbursement, general exchange) post a line in a currency that can legitimately differ from the account's home currency at `fx_rate != 1` — this mirrors the existing project rule that `debit_base`/`credit_base` are always in the line's own currency, never the account's (see 2026-07-05 Important Notes in AI_PROJECT_MEMORY.md).

### Impact
Every Create/Edit page for the 6 flows now calls `TransactionDescriptionBuilder::buildAndSave()` as the last step inside its existing `DB::transaction()`, using a short flow-specific Arabic summary built from final saved relations (with graceful fallbacks when an optional relation like partner is absent). If either side of a transaction ends up with zero lines, the builder throws and the whole flow rolls back — no transaction can be left without a description. Historical transactions were not backfilled (explicitly out of scope for this task) — only newly created/edited transactions get a generated description. The separate raw `TransactionResource` admin CRUD (`admin/transactions`) originally still let a user free-type `description` directly; this was closed the same day — see the later 2026-07-14 "read-only audit resource" decision below.

---

### Date
2026-07-14

### Decision
While adding `RefreshDatabase`-based tests for the description feature, two pre-existing, unrelated migration bugs were found and fixed (approved separately, mid-task, after a full read-only investigation): `2026_06_09_130009_create_exchange_rate_history_table.php` was restored to create the table under its originally-intended singular name (`exchange_rate_history`), and `2026_06_09_174625_rename_exchange_rate_history_to_exchange_rate_histories.php`'s rename was made idempotent (`Schema::hasTable()` guards). `2026_06_09_130012_create_accounts_table.php` had its duplicate `current_balance`/`iban` column definitions removed (already added correctly by the later `2026_06_09_193018_add_columns_to_accounts_table.php`). A third incompatibility — `2026_06_24_000005_backfill_denormalized_currency_and_amounts.php` using MySQL-only `UPDATE ... JOIN` raw SQL — was found and **deliberately left untouched**.

### Reason
Both fixed bugs meant a fresh `migrate`/`RefreshDatabase` run would abort immediately (before reaching any table this task's tests actually needed), because each pair's create-migration file had, at some point after the live dev DB was already migrated, been edited to directly include columns/names that a later migration also tries to add/rename — invisible on the live dev DB (both migrations already recorded `Ran` from their real historical batches) but fatal on any genuinely fresh database (new clone, CI, or `:memory:` test DB). The third issue (MySQL-only backfill SQL) is real, historically-significant financial-backfill logic — rewriting it to be cross-database-compatible was judged out of scope and a materially different kind of risk than a migration-consistency fix, so `TransactionDescriptionBuilderTest` instead migrates only the handful of structural migrations it needs, skipping that one entirely.

### Impact
Confirmed via `migrate:status` before and after that the dev DB's recorded migration batches (3, 4, 5, 14, 19) were unaffected — these edits changed only the migration **files**, not any live schema or data. Any future fresh install, CI run, or `RefreshDatabase`-based test now succeeds past these two points. The repo still cannot run a full fresh `migrate` end-to-end because of the untouched MySQL-only backfill migration — flagged in AI_PROJECT_MEMORY.md's Important Notes and NEXT_STEPS.md for future consideration.

---

### Date
2026-07-14

### Decision
`TransactionResource` (`admin/transactions`, "المعاملات المالية") is permanently a **read-only audit resource**: no create, edit, delete, restore, or force-delete on transactions or their lines, enforced at both the UI layer (no such actions/routes exist) and the authorization layer (`can*` methods hardcoded to `false`, not just hidden buttons). The 6 legitimate financial flows (receipts, disbursement, execution payments, general expenses, general exchanges, opening balance) remain the **only** paths that can create or edit a `Transaction`/`TransactionLine`. Transactions and their lines are displayed newest-first by default (`defaultSort('id', 'desc')` on both the resource's list table and `LinesRelationManager`'s table), while users can still click any column header to sort differently.

### Reason
A read-only audit was requested after making `description` read-only on this resource's form, and it surfaced a materially bigger problem than the field-level one: the raw create flow produced a bare `Transaction` with **zero lines**, no `DB::transaction()`, and no balance/currency handling — a direct bypass of the double-entry + account-balance-update guarantee every other write path in this system enforces (violates the CLAUDE.md rule "Every financial operation must remain balanced double-entry accounting"). Its edit flow also let header fields (`partner_id`, `fiscal_year_id`, `transaction_type_id`, `transaction_time`) be changed independently of the transaction's owning `ProjectCostReceipt`/`ProjectCostBudget`/etc. row, risking silent desync between a financial record and its ledger entry. `LinesRelationManager` had the same gap at the line level (manual line create/edit/delete with no balance check, no account-balance increment/decrement). This is the same category of issue as the `ProjectCostBudgetResource` ("المبالغ المرصودة") removed on 2026-07-06 for being "a legacy bypass of the real business flow" — the fix here is narrower (keep the resource for its genuine audit value: list/search/filter/view any transaction and its lines) rather than deleting it outright, since unlike that resource, this one's read-only capability is actively useful and was explicitly requested to be preserved.

### Impact
`admin/transactions/create` and `admin/transactions/{record}/edit` no longer resolve to any route (404). `CreateTransaction.php`/`EditTransaction.php` page classes were deleted as confirmed-unreferenced dead code. The navigation entry, list table (search/filters/columns), view page, and lines relation table all remain fully functional for audit — nothing was removed from what a reviewer can see, only what they can change. Verified headlessly via reflection (built the real `Table` objects and called `getFlatActions()`/`getDefaultSortColumn()` directly) that both tables expose exactly the expected read-only action set and sort order, and via tinker that every `can*` authorization check returns `false`. Re-ran the six-flow tinker verification afterward and got byte-identical results to before this change, confirming the 6 legitimate flows and `TransactionDescriptionBuilder` were completely unaffected.

---

### Date
2026-07-14

### Decision
`transaction_lines` gained two system-generated, additive-only metadata columns: `description` (one-line Arabic per-line explanation, exact format `{مدين|دائن}: حساب {name} ({line currency code}) — {posted amount} | الغرض: {purpose}.`) and `line_role` (stable English machine value from the new single-vocabulary string-backed enum `App\Enums\TransactionLineRole`, 11 roles, each with an Arabic UI label). Roles are assigned explicitly by the authoritative financial flow at line-creation time (and on the receipt edit flow's in-place line updates, enabling self-heal of pre-feature NULL roles) — never inferred from account name, debit/credit side, line order, description, or notes. Purposes are supplied per-flow (not per-role globally) because the same role legitimately carries different wording in different flows. Zero-amount lines (0% admin/transfer deduction placeholders) are skipped — `description` stays NULL — instead of throwing, matching how `transactions.description` already omits them (user-approved deviation from the strict draft spec after the audit showed throwing would roll back every 0%-deduction disbursement/exchange). `transaction_lines.notes` and all `LINE_*` constants remain completely untouched and stay the tags reports/edit flows match on; migrating readers to `line_role` requires a separately approved audit. No historical backfill; no DB enum; no index on `line_role` yet. The standalone `TransactionLineResource` (`admin/transaction-lines`) was also converted to a strictly read-only audit resource (routes reduced to index/view, page classes deleted, 8 `can*` → false) — it was a raw line-level CRUD bypass of double-entry integrity missed by the same-day `TransactionResource` hardening.

### Reason
The parent `transactions.description` explains the whole operation but not why each individual account moved; auditors reading a single ledger line (Account Statement, line tables) need the line's own business purpose. A machine-readable role assigned at creation time is the only reliable way to know a line's purpose without parsing Arabic text or notes tags — the audit confirmed receipts/expenses have no notes tag at all and opening-balance lines share one ambiguous tag, so inference was impossible anyway. Shared text formatting was extracted into the `FormatsTransactionText` trait (used by both builders) so transaction- and line-level descriptions can never drift apart; `TransactionDescriptionBuilder`'s output stayed byte-identical (its 15 exact-string tests prove it).

### Impact
All 6 create flows and all 5 edit flows now write `line_role` on every line and generate per-line descriptions inside their existing `DB::transaction()` (validation failure rolls back the whole financial operation — no partial metadata). Historical lines keep NULL in both columns; edited pre-feature receipts self-heal. New read-only columns (role badge with Arabic label, searchable description) appear on `admin/transaction-lines` and the transaction view's lines table; all reports/exports/snapshots deliberately unchanged in this phase. `admin/transaction-lines/create` and `.../{record}/edit` no longer exist as routes.

---

### Date
2026-07-15

### Decision
Historical (pre-2026-07-14) transactions and transaction lines belonging to a **deterministically classifiable** flow are now backfilled with canonical `description`/`line_role` values via `php artisan transactions:backfill-descriptions --apply`. A transaction is classified only by (1) a direct `transaction_id` foreign key on its flow's authoritative domain record, or (2) `transactions_types.name = 'قيد افتتاحي'` for opening balance (which has no domain record). `transaction_lines.notes` `LINE_*` tags are used only as supporting evidence to map a line to its role once the flow is already known this way — never to identify the flow itself, and never account name/type, description text, line order, or amount similarity. A transaction that cannot be classified this way is left completely untouched and reported as unclassified; none currently exist in the dev DB (0/10). The command is idempotent by construction (it only calls `->save()` when a computed value differs from what's stored) rather than via any separate diffing layer, reusing the exact same generation code (`TransactionDescriptionBuilder::build()` and a new public `TransactionLineDescriptionBuilder::describeLine()`) the 6 live flows already use, so a backfilled row is byte-identical to what a fresh create/edit of the same data would produce today.

### Reason
The prior 2026-07-14 decision explicitly deferred historical backfill because no approved, deterministic classification method existed yet for old rows. This task defines and implements that method. Reusing the live builders (rather than re-deriving formatting logic in the backfill command) guarantees the backfilled text can never drift from what the live flows generate, and guarantees idempotence for free via Eloquent's dirty-attribute check — no bespoke "already correct" comparison logic to get wrong. Reusing `TransactionLineDescriptionBuilder`'s existing zero-amount-skip behavior (rather than reimplementing it) automatically applies the same "0% admin/transfer deduction lines keep `description = NULL`" rule approved on 2026-07-14.

### Impact
All 10 active transactions / 24 active lines in the dev DB are now classified and backfilled (0 unclassified). `transactions:backfill-descriptions` is safe to re-run at any time (e.g. after future data imports) — a second run against already-canonical data updates 0 rows. Only `transactions.description`, `transaction_lines.line_role`, and `transaction_lines.description` are ever written; amounts, notes, `LINE_*` tags, balances, and record counts are provably unchanged (verified via a before/after snapshot of every listed field). If a future historical row genuinely cannot be classified (e.g. a manually-inserted transaction bypassing all 6 flows, which should not be possible given `TransactionResource`'s read-only hardening), it is skipped and reported by id/reason rather than guessed — a documented future review item, not a silent gap.

---

### Date
2026-07-15

### Decision
The three approved fields — `وصف العملية المالية` (`transactions.description`), `دور سطر القيد` (`transaction_lines.line_role`, resolved to its Arabic label), `وصف سطر القيد` (`transaction_lines.description`) — are added **only** to the detailed financial-movement sections of the four reports named in this task (Comprehensive Financial Transactions, Account Statement, Project Financial Details, Donor Financial Report), always alongside the report's existing fields, never replacing them. Where a report's existing grain is already one row per transaction line (Comprehensive, Account Statement), the fields are added as plain new columns/cells. Where a report's grain is one row per domain record or movement, not per line (Project Financial Details' receipts/budgets/payments; Donor Financial Report's movements), the parent description is added at that same grain and the movement's accounting lines are exposed via a nested/expandable widget (an HTML `<details>` block on screen, a nested nested table in Word, a wrapped multi-line summary column in Donor's Excel) rather than exploding the report into a new row-per-line grain. Trial Balance, all summary cards, dashboard totals, the Projects General Financial Report summary, percentage cards, and collection-rate calculations were not touched.

### Reason
The task explicitly required preserving each report's existing grain and calculations rather than assuming every report is (or should become) line-grained — Project Financial Details and the Donor Financial Report's movements section were already domain-record/movement-grained before this task, and forcing them to one-row-per-line would have doubled-to-quadrupled their row counts and changed what "one row" means to an existing reader, which is a bigger, riskier change than what was asked. The nested-widget approach shows every required field without that grain change. `transaction_lines.notes` is never used as the displayed line description (per explicit instruction) — the new `وصف سطر القيد` field always comes from `transaction_lines.description`, kept visually distinct from any pre-existing notes column.

### Impact
Every added field is display-only, sourced directly from the already-backfilled stored columns (never regenerated at render time, never parsed back into any calculation). NULL/unclassified values render as "—" everywhere, per the approved historical-display convention. `TransactionResource`'s `LinesRelationManager` reuse of `TransactionLineRole::labelFor()` is now duplicated (by necessity, since each report service is a separate, non-shared class per this codebase's existing convention) into `ComprehensiveFinancialTransactionsReportService`, `AccountStatementReportService`, `ProjectFinancialDetailsPage`, and `DonorFinancialReportService` — all via the same enum, so the Arabic label text can never drift between them. `DonorFinancialReportService`'s admin/transfer deduction **totals** still match on `transaction_lines.notes` `LINE_*` tags exactly as before (untouched) — the new `lines`/`line_role_label` arrays added to each movement are separate, purely-additive display data fetched by a new bulk query, not a replacement for that existing calculation logic.

---

### Date
2026-07-18

### Decision
`FinancialTransactionBalanceGuard` compares every monetary amount as **integer minor units** (`(int) round(((float) $value) * 100)`), with **exact equality** (`!==`) — never `abs($a - $b) <= 0.01` or any other float tolerance. A destination-line FX amount that is even one cent off from `round(amount_after_deductions * fx_rate, 2)` is rejected, not silently accepted.

### Reason
Explicit instruction: a one-cent imbalance is a real bug, not rounding noise, given that every production amount is already built via `round(..., 2)` at the exact point of construction (the same `round()` calls the guard independently re-derives from). A `<= 0.01` tolerance would mask exactly the class of bug this guard exists to catch — a future code change that quietly drifts the destination amount, the admin/transfer split, or the source amount by a cent or more, which a loose tolerance could hide for a long time in production before enough drift accumulated to trip it.

### Impact
All comparisons in the guard (single-currency sum-of-debits vs. sum-of-credits, multi-currency source-conservation-derived `amount_after_deductions`, and the destination FX equation) are integer comparisons on cent-scaled values, not float comparisons — eliminates float-equality flakiness as a side effect, on top of meeting the exact-cent requirement. Any future amount field added to a line payload must also be minor-units-comparable (i.e., a genuine 2-decimal-place currency amount) for this pattern to keep working correctly; `fx_rate` (6-decimal, a ratio, not a currency amount) is deliberately handled differently — compared as a plain positive float, then used to independently recompute the expected destination amount, which *is* then compared in minor units.

---

### Date
2026-07-18

### Decision
While writing the requested unit tests for `FinancialTransactionBalanceGuard`, several requested test cases (amount_currency positivity/match, fx_rate positivity and ==1-for-single-currency, missing-required-key, missing/duplicate role, wrong role-direction, notes-tag authority) had no corresponding guard behavior yet — the guard as it stood after the prior implementation pass only checked the single/multi-currency balance equations and the basic one-side-only/no-negative/no-double-zero structural rule. Rather than weaken the test cases to match the existing guard (explicitly forbidden by the task) or skip them, the guard itself was extended with these additional structural checks. This was treated as *completing the guard to its originally-approved design scope* (the read-only discovery phase's Phase 5 spec already called for role-direction and notes-tag-authority checks), not as "changing the approved accounting implementation" — no dollar amount, formula, or DB-write shape was touched; only defensive payload-structure validation was added.

### Reason
A test that can only pass by asserting a rejection the code doesn't actually perform is not a real test — it would give false confidence. The alternative (implementing these checks) closes real gaps the discovery-phase audit had already flagged as in-scope for the guard, using only data already present in every line payload of all 10 production workflows (`line_role`, `notes`, `amount_currency`, `debit_base`/`credit_base` — all already set by the existing, unmodified line-construction code), so no production workflow file needed to change to support the new checks.

### Impact
One structural constraint fell out of this: `fx_rate` could **not** be added to the guard's "required key" list, because `EditProjectCostReceipt`'s in-place `->update()` payload (unlike every other line-write payload in the codebase) legitimately never includes that key — receipts are always `fx_rate = 1` and that column is never rewritten on edit. The guard instead treats a missing `fx_rate` key as `1.0` for validation purposes (matching the column's true, unchanging value), and required-key enforcement covers `account_id`/`currency_id`/`amount_currency`/`debit_base`/`credit_base`/`line_role` only. The new optional `$expectedNotesByRole` parameter on `assertValidLinePayload()` is exercised only by unit tests in this pass — it is deliberately **not** wired into any of the 10 production call sites yet (wiring it in would touch the already-approved 10 files, out of scope for a test-completion task); a future task can opt individual workflows into exact notes-tag matching without any guard-side change.

**Correction (same day, before commit): this fx_rate-optional-with-1.0-default design was reversed.** It was flagged as conflicting with the actual approved requirement — no financial value may be silently assumed — and as a real risk: a historically-corrupted stored `fx_rate` on a receipt line would never be checked against its true value, only against the guard's assumed `1.0`, so a valid Edit could not (and, worse, would not) correct it either, since the payload never wrote a value for that column at all. The correct fix was not to weaken the "required key" rule but to close the actual gap it was routing around: `fx_rate` was restored to `REQUIRED_KEYS` (rejected outright if missing, no fallback anywhere in the guard), and `EditProjectCostReceipt::buildReceiptLineUpdates()` was changed to explicitly write `'fx_rate' => 1` on both its debit and credit update arrays — matching every other line-write payload in the codebase, which already did this. This is the smaller, more correct fix: one two-line addition to the one file with the actual gap, rather than a permanent structural exception in the guard. See the 2026-07-18 correction entries in `docs/TASKS_LOG.md` and `docs/AI_PROJECT_MEMORY.md` for the full detail and updated test counts.

---

### Date
2026-07-19

### Decision
`UserPolicy` locks every ability to a hardcoded `false` for Task 1, rather than checking a granular `users.*` permission. `App\Support\Permissions\PermissionRegistry` still defines `users.view_any`/`users.view`/`users.create`/etc. (so the registry, sync command, and role-default tests are already forward-complete), but no role — not even a future custom role explicitly given `users.create` — can use them yet; only the `Gate::before` Super Admin bypass reaches `UserResource` at all.

### Reason
Explicit instruction: "This task must establish the authorization foundation and immediately restrict UserResource to Super Admin only until the complete user-management phase is implemented" — the currently wide-open `UserResource` (any authenticated user could assign any role, including a future Super Admin, to any user) was flagged as the critical immediate risk driving this whole task, ahead of granular permissions. Task 3 (self-elevation guard, last-Super-Admin protection, `is_active` field, safe `UserForm`) has to land before opening `users.*` up to a real `Admin`-style role is safe.

### Impact
`PermissionRegistry::adminDefaults()` already excludes every `users.*`/`roles.*`/`permissions.*` permission from Admin's default set (belt-and-suspenders — even if the policy is loosened prematurely by a future edit, Admin wouldn't have been granted the permission anyway, by design). When Task 3 replaces `UserPolicy`'s hardcoded `false` with real `users.*` permission checks, `Admin`'s default permission set must be revisited deliberately (not just left excluded by omission) as part of that task, since today's exclusion is enforced twice (policy + registry) for defense in depth.

---

### Date
2026-07-19

### Decision
`Viewer`'s default permission set excludes every `reports.*` permission, even though report *pages* are arguably "operational modules" a viewing-only role might expect to see. Similarly, `Accountant` gets exactly the 4 explicitly-named financial reports (not `projects_general_financial`/`project_financial_details`), and `Project Manager` gets report *view* only, never *export*.

### Reason
The task instructions were explicit for Accountant/PM's report scoping, but ambiguous for Viewer ("Grant only view_any/view permissions for operational modules" — reports use a different naming pattern, `reports.<page>.view`, and weren't explicitly listed). Chose the more conservative, least-privilege reading rather than guess a broader one, consistent with the original design phase's Viewer principle ("no ... access by default unless separately granted") — matches this task's own instruction to avoid weakening/over-granting defaults.

### Impact
If Viewer is later found to need report visibility, it's a one-line addition to `PermissionRegistry::viewerDefaults()` (`foreach REPORT_PAGES as $page => $label { $permissions[] = "reports.{$page}.view"; }`), not a design change — flagged as a candidate follow-up, not implemented here since it wasn't clearly requested.

---

### Date
2026-07-19

### Decision
`execution_payments` was given the same operation set as `project_cost_budgets_payments` (`view_any/view/create/update/delete/restore`) in `PermissionRegistry`, even though the task's explicit "Full CRUD + SoftDeletes" module list didn't name it directly — it was called out separately under "Execution payments: Create separate permissions for: execution_payments, project_cost_budgets_payments."

### Reason
Confirmed via `ExecutionPaymentResource::$model` (read directly from source) that `ExecutionPaymentResource` and `ProjectCostBudgetsPaymentResource` share the exact same underlying `ProjectCostBudgetsPayment` model (`SoftDeletes`-enabled) with near-identical `whereNotNull('transaction_id')` query scoping — the earlier read-only audit (2026-07-19) had already flagged their precise data-scope relationship as needing clarification before full resource-protection. Giving `execution_payments` the same operation shape as its sibling is the only inference the confirmed inventory supports without guessing new facts; the task explicitly said not to change either resource's query/model/business logic in this task.

### Impact
No functional risk in Task 1 (permissions aren't wired into either resource's `canX()` yet). Before Task 2 protects these two resources, their exact data-scope relationship (are they mutually exclusive subsets of the same table, or overlapping?) must be resolved — assigning `execution_payments.*` and `project_cost_budgets_payments.*` as if they gate fully independent record sets could be wrong if a user could reach the same underlying row through either resource with different effective permissions.

---

### Date
2026-07-19

### Decision
`tests/Feature/Users/UserResourceLockdownTest.php` initially asserted directly against `UserResource::canViewAny()/canCreate()/canEdit()/canView()/canDelete()` rather than performing real HTTP requests (`$this->get('/admin/users')`) against the Filament panel routes.

### Reason
Traced (via `withoutExceptionHandling()` and a full stack trace) that in this environment, a full HTTP round-trip through the test client appeared to lose the `actingAs()` user before `Filament\Http\Middleware\Authenticate` ran, producing a 403 regardless of the acting user's actual role. Separately confirmed `route()`/`url()` bake this environment's `APP_URL` path prefix (`/oms/public`) into generated URLs, which the test client also mis-resolves.

### Impact
The test still verified the exact production authorization decision at the time — Filament's own `CanAuthorizeResourceAccess`/`CreateRecord`/`EditRecord`/`ViewRecord` call these same `canX()` methods inside `abort_unless(..., 403)` before rendering anything. **Correction (same day, before commit): the real root cause was found and the workaround was replaced with genuine HTTP tests.** The user flagged that item 5 of a follow-up request ("a normal authenticated user receives HTTP 403 on the real UserResource list/create/view/edit URLs") required completing real HTTP tests, not settling for the `canX()` proxy. Re-tracing with `withoutExceptionHandling()` found the actual cause was unrelated to sessions: `Filament\Http\Middleware\Authenticate::authenticate()` has a hardcoded rule — if the user model doesn't implement `Filament\Models\Contracts\FilamentUser`, the panel is only reachable when `config('app.env') === 'local'` (vendor source: `abort_if($user instanceof FilamentUser ? ... : (config('app.env') !== 'local'), 403)`). `App\Models\User` doesn't implement that interface, and PHPUnit runs with `APP_ENV=testing`, so *every* request — including an authenticated Super Admin — was 403ing before any Gate/Policy check ran; `actingAs()` was never actually broken. The fix is a test-only `config(['app.env' => 'local'])` in `UserResourceLockdownTest::setUp()` (documented in the test's class docblock, together with the pre-existing `URL::forceRootUrl()` fix) — no production file was touched, since implementing `FilamentUser` on `App\Models\User` would be a real behavior change outside this task's approved scope. `UserResourceLockdownTest` now issues real `$this->get('/admin/users')`/`/create`/`/{id}`/`/{id}/edit` requests and asserts `assertForbidden()`/`assertOk()` directly.

---

### Date
2026-07-19 (correction — real admin account + DatabaseSeeder finding)

### Decision
(1) Confirmed the real administrator account is `oms@oms.com` (not `superadmin@oms.com`, which a prior pass had searched for and wrongly treated as "no Super Admin exists"), via read-only queries only. (2) Left `database/seeders/DatabaseSeeder.php` unchanged despite finding its `seedSuperAdmin()` method hardcodes `User::firstOrCreate(['email' => 'superadmin@oms.com'], ...)` — a different email than the real admin account. (3) Added a read-only `super_admin_user_count` to `PermissionSyncService::sync()` and a warning in `oms:sync-permissions` when it's 0, without adding any user-creation logic to either.

### Reason
Explicit instructions: do not create another administrator user, do not change any real database user, do not modify the local database, and — regarding the seeder — "do not add a new administrator account merely because `superadmin@oms.com` does not exist; only change seeding behavior if the existing project design clearly requires it and report the proposed change before implementing it." The seeder mismatch is real and pre-existing (not introduced by this task — the only line ever touched in `seedSuperAdmin()` was a constant-reference change with the same value), but nothing in the current instructions clearly requires changing it, and doing so unilaterally risks either creating a duplicate admin account or silently repointing seeding at a specific real email address without sign-off. The zero-Super-Admin warning was requested explicitly as "useful" while explicitly forbidding user creation from the command — a read-only headcount check satisfies both.

### Impact
`DatabaseSeeder::run()` should **not** be executed against any environment where `oms@oms.com` is the intended admin, until this mismatch is resolved — running it today would create a second, independent `superadmin@oms.com` Super Admin account alongside the real one. Proposed fix for approval (not implemented): either make `seedSuperAdmin()`'s target email configurable (e.g. an env var, defaulting to the current hardcoded value for backward compatibility) or remove/guard the method entirely now that a real admin account already exists outside the seeder's knowledge. `oms:sync-permissions` is unaffected either way — it only touches permissions/roles, never users, and its new warning is purely informational (verified by a test asserting `User::count()` is unchanged after the command runs).

---

### Date
2026-07-19 (correction 2)

### Decision
`App\Models\User::canAccessPanel()` returns exactly `! $this->trashed()` — no permission check, no role check, no hardcoded email/ID. It answers only "can this user enter the panel at all", never "what can they do once inside".

### Reason
Explicit instruction, and the underlying principle already established across this task: authorization decisions belong in `Gate::before`/Policies/Resource `canX()` methods, in one place, not duplicated or partially reimplemented in the User model. Putting a role or permission check inside `canAccessPanel()` would create a second, parallel authorization path that could drift out of sync with the real one (e.g. a future role rename would need updating in two places instead of one). It would also change *today's* behavior for the 24 not-yet-protected resources (Task 2), since any authenticated user currently reaches them regardless of role — narrowing `canAccessPanel()` now, ahead of Task 2's actual per-resource protection, would produce an inconsistent, partially-protected state that's harder to reason about than "fully open until Task 2, fully gated after".

### Impact
This closes the real production risk the user flagged (Filament silently rejecting every user, including Super Admin, in any non-`local` environment when the model doesn't implement `FilamentUser`) without touching or duplicating any authorization logic. It does **not** by itself change who can do what inside the panel today — `UserResource` was already Super-Admin-only via `UserPolicy` before this change and still is; the other 24 resources were already open to any authenticated user in `local`/`testing` (since `app.env` was never `production` in any environment this task has run in) and remain exactly that open now, in every environment, until Task 2 adds their `canX()`/Policy protection. This is a strict correctness fix (production no longer silently locks everyone out), not a broadening of today's local/testing access.

---

### Date
2026-07-19 (correction 2)

### Decision
`DatabaseSeeder::seedSuperAdmin()` now skips creating `superadmin@oms.com` entirely whenever any non-soft-deleted user already holds the `Super Admin` role — via `User::role(PermissionRegistry::SUPER_ADMIN)->exists()`, checked first, with an early `return`. No email-specific check (e.g. `where('email', 'oms@oms.com')`) was added.

### Reason
Explicit requirement: check by role, not by a specific email — "check whether any non-soft-deleted User already has the exact role Super Admin", not "check whether `oms@oms.com` exists". Hardcoding the real admin's email into the seeder would just replace one hardcoded-email problem with another (what happens when the real admin's email changes, or a second environment has a different real admin email?). Checking by role is the generically correct condition: "does this system already have an administrator" is exactly what matters for deciding whether to bootstrap one.

### Impact
Any non-soft-deleted user holding `Super Admin` — under any email, created any way (this seeder, manually, a future `RoleResource`) — permanently prevents `superadmin@oms.com` from ever being created by this seeder again, which is the desired steady-state for a system that already has a real administrator. If the *only* Super Admin is later soft-deleted (e.g. accidentally, or through a future Task-3 safety bug), the seeder would then bootstrap `superadmin@oms.com` on its next run — arguably correct disaster-recovery behavior (a system with zero active administrators regains one), but worth being aware of: it is not a substitute for the last-Super-Admin deletion protection still pending in Task 3.

**Correction (same day, before commit): the hardcoded `superadmin@oms.com` email and `password123` were replaced entirely with `config('oms.bootstrap_admin.*')`, itself sourced only from `OMS_BOOTSTRAP_ADMIN_EMAIL`/`_NAME`/`_PASSWORD` environment variables — no default password, no literal credential anywhere in source control.** Flagged as unacceptable for a permissions/security task: a fixed password in a seeder file is a real credential leak the moment the repo is cloned anywhere, regardless of whether it's ever actually run. See the two new 2026-07-19 "correction 3" entries below for the config design and the soft-delete/`withTrashed()` handling this also required.

---

### Date
2026-07-19 (correction 3)

### Decision
Bootstrap admin credentials live in a new `config/oms.php` (`bootstrap_admin.email`/`.name`/`.password`), each sourced via `env()` with no default for `email`/`password` (only `name` defaults, to `'Super Admin'`, since a display name isn't sensitive). `DatabaseSeeder::seedSuperAdmin()` reads only `config('oms.bootstrap_admin.*')`, never `env()` directly. Missing email or password throws `RuntimeException` with a message that names the two environment variable *names* only — never a value — and this check runs before any database write.

### Reason
Explicit requirement, and standard Laravel convention: `env()` should only ever be called inside `config/*.php` files (config is cacheable via `config:cache`; a seeder calling `env()` directly would silently read `null` in any environment with a cached config, a well-known Laravel footgun). A missing credential must fail loudly rather than silently creating an admin with an empty/guessable password, or silently skipping bootstrap and leaving the system with no administrator at all and no clear error explaining why.

### Impact
Every environment that needs this seeder to actually bootstrap an administrator (a fresh install with no existing Super Admin) must set `OMS_BOOTSTRAP_ADMIN_EMAIL` and `OMS_BOOTSTRAP_ADMIN_PASSWORD` before running it — documented as blank placeholders in `.env.example` (a template, not a real credential). Any environment that already has a real Super Admin (this local environment, via `oms@oms.com`) never needs this configuration at all, since the existing early-exit guard runs first and never reaches the config check.

---

### Date
2026-07-19 (correction 3)

### Decision
`seedSuperAdmin()` looks up the configured bootstrap email with `User::withTrashed()->where('email', $email)->first()` and branches three ways: no row → `create()`; active row → `assignRole()` only, no field writes; soft-deleted row → `restore()` + reassign only the `password` field + `assignRole()`. No branch ever touches `name`/`email` on a pre-existing row (active or restored).

### Reason
`users.email` has a unique constraint (confirmed via the `0001_01_01_000000_create_users_table.php` migration), and Eloquent's default query scope excludes soft-deleted rows — so a naive `firstOrCreate(['email' => $email], [...])` would attempt an `INSERT` and hit that unique constraint whenever the configured email already exists as a soft-deleted user, rather than recovering it. Restoring is the correct recovery path (one row, one identity, its history/relationships intact) rather than erroring or silently doing nothing. Resetting only the password (not name/email, which already match) on restore is necessary because the prior password is unknown/unverifiable here — leaving it as-is would restore an account nobody can actually log into with the newly configured credential. Not resetting the password on an *active* existing user (the promote-only branch) is different: that account is already usable by whoever controls it, and silently overwriting their password would itself be a security regression — this branch only grants the role.

### Impact
An operator recovering from an accidentally-soft-deleted bootstrap admin gets their account back with the currently-configured `OMS_BOOTSTRAP_ADMIN_PASSWORD`, not a stale one — but this also means: if `OMS_BOOTSTRAP_ADMIN_PASSWORD` in the environment ever changes and the seeder is re-run while that email is soft-deleted, re-running restores the account with the *new* password, silently invalidating the old one. This is consistent with treating the config value as the single source of truth for that specific bootstrap identity, not a one-time-only value — worth noting if a future task adds a "rotate bootstrap password" workflow, since this seeder already effectively provides one (delete-then-reseed) as a side effect.

---

### Date
2026-07-19 (OMS Permissions Task 2A)

### Decision
Authorization for all 23 protected models is implemented purely as Policy classes discovered by Laravel's standard model→policy naming convention. No Resource or RelationManager file gets a `canX()` override, and no `Gate::policy()` registration was added anywhere.

### Reason
Verified (not assumed) that Filament's `HasAuthorization` trait already routes every standard ability (`viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny`) through `Gate::getPolicyFor($model)` when no explicit `canX()` override exists — exactly the same mechanism already proven working for `UserPolicy`/`UserResource` in Task 1, with zero registration needed. Adding per-Resource `canX()` overrides that just re-call the same Policy would be pure duplication with no behavioral difference, and the task explicitly asked not to add duplicated authorization in every Resource "unless Filament requires a focused Resource override for a specific business rule" — no such rule exists for the 23 ordinary modules (Transactions/TransactionLines already have their own pre-existing hardcoded overrides for their specific business rule, left untouched).

### Impact
Every future model needing standard CRUD-permission gating only needs one small Policy class using `AuthorizesCrud` (declaring its `permissionModule()`) — no Resource-file changes at all. If a genuinely resource-specific rule is ever needed (e.g. "cannot edit after fiscal year closes"), it belongs as a small addition inside that model's own Policy method, not a parallel Resource-level override, to avoid two authorization sources disagreeing.

---

### Date
2026-07-19 (OMS Permissions Task 2A)

### Decision
The soft-delete rule (a trashed record cannot be normally viewed/edited/deleted-again; restore requires permission and only ever applies to an already-trashed record) is built once into the shared `AuthorizesCrud` trait and applied uniformly to all mutable modules, rather than touching any Resource's existing `withoutGlobalScopes([SoftDeletingScope::class])` in `getRecordRouteBindingEloquentQuery()`. For the 2 read-only audit modules (Transactions/TransactionLines), `view()` stays permission-gated but does **not** deny a trashed record.

### Reason
Per instructions: do not remove the existing scope bypass (it may support Filament's `TrashedFilter` — confirmed present on 16 of the 23 resources — showing trashed rows in the list without needing to re-enable the global scope for the whole query). The actual risk it created — a trashed row being directly reachable via a hand-typed Edit/View URL even though it's hidden from the normal list — is closed at the authorization layer instead, which is the smaller, safer change and doesn't touch any Resource file. The read-only exemption for Transactions/TransactionLines reflects that they're audit trails, not user-editable data: nothing about them can be mutated regardless of trashed state (already hardcoded false), so there's no "normal edit of a trashed record" risk to close, and blocking `view()` would only remove legitimate audit visibility into what was deleted and when.

### Impact
Any resource whose model uses `SoftDeletes` automatically gets this rule the moment its Policy exists — no per-Resource opt-in needed. If a future Resource genuinely needs a dedicated "restore review" page that must show a trashed record via a normal View route, that page needs its own explicit authorization exception (not a blanket relaxation of this rule) — flagged here for whoever builds that page later. This was verified with a real HTTP test (`ExecutionPaymentBudgetDisbursementScopeTest::test_soft_deleted_execution_payment_cannot_be_opened_through_the_normal_edit_route`) rather than assumed.

---

### Date
2026-07-19 (OMS Permissions Task 2A)

### Decision
`ProjectCostBudgetPolicy` (model `App\Models\ProjectCostBudget`) enforces the `project_cost_budgets_payments` permission prefix, and `ProjectCostBudgetsPaymentPolicy` (model `App\Models\ProjectCostBudgetsPayment`) enforces the `execution_payments` prefix — i.e. each policy's permission prefix is the *opposite* of what its own class/model name would suggest by lexical similarity. Both policy classes carry explicit "MISLEADING NAME — READ BEFORE CHANGING" docblocks; a dedicated `PolicyDiscoveryTest` entry (with its own descriptive data-provider key) asserts this exact pairing.

### Reason
This is not a new decision — it is the pre-existing `PermissionRegistry` design from Task 1 (module keys `execution_payments`/`project_cost_budgets_payments` were already defined and assigned to the Accountant role before this task started), confirmed correct against the actual Resource→model mapping during the Task 2 discovery audit: `ExecutionPaymentResource`'s `$model` is `ProjectCostBudgetsPayment`, and `ProjectCostBudgetsPaymentResource`'s `$model` is the different `ProjectCostBudget`. Renaming the permission strings to match the model class names would require a data migration of every existing `permissions`/`role_has_permissions` row and is out of scope (and unnecessary — the strings work correctly, they're just non-obvious to a future reader relying on name similarity alone). Documenting this loudly in code was chosen over silently relying on the already-passing tests, since a future maintainer skimming class names alone (without running tests) is exactly the failure mode this guards against.

### Impact
Anyone adding a new ability to either policy, or extending `PermissionRegistry` with a new module, must re-check the actual Resource `$model` property rather than assume from the class/module name. `ProjectCosts/BudgetsRelationManager` (planned-budget rows, `transaction_id IS NULL`) shares `ProjectCostBudgetPolicy` with `ProjectCostBudgetsPaymentResource` (disbursement rows, `transaction_id IS NOT NULL`) since both use the same `ProjectCostBudget` model — this is expected today (no separate permission module was requested or exists for the two `transaction_id` states) but is the exact scenario the original Task 2 discovery audit flagged as a future risk if a standalone "planned budget" Resource is ever built on this same model — noted here again for whoever picks that up.

---

### Date
2026-07-19 (OMS Permissions Task 2A)

### Decision
Skipped writing a standalone HTTP-level test asserting "navigation link visibility equals `view_any` permission" via a cold or post-request static `canX()`/`shouldRegisterNavigation()` call. Replaced it with a source-level test confirming none of the 23 resources override `shouldRegisterNavigation()` (with one explicit, pre-existing, permission-independent exception: `ProjectCostResource`, hardcoded `$shouldRegisterNavigation = false` since it's reachable only via `Projects/CostsRelationManager`, never as a top-level nav item).

### Reason
Read Filament's own `HasNavigation::shouldRegisterNavigation()` source: it is `return static::$shouldRegisterNavigation;` — a static nav-eligibility toggle, not itself a permission check (the Panel combines this toggle with `canViewAny()` separately when building the real sidebar). Calling `canViewAny()` or `shouldRegisterNavigation()` directly against a Resource class outside of, or immediately after, an HTTP test request produced inconsistent results across a 23-item data provider in this environment (unrelated to Policy correctness — the exact same permission grants behave correctly through the real page-mount lifecycle, already proven by `test_index_is_blocked_without_permission_and_allowed_with_it`'s genuine per-user HTTP round trip). Rather than chase an environment-specific static-call quirk further, real HTTP 403/200 on the index page — the actual security boundary the task cares about — was kept as the authoritative proof, and the navigation check was narrowed to what's reliably and meaningfully verifiable: that the nav-eligibility toggle itself hasn't been quietly overridden somewhere.

### Impact
If Filament's nav-authorization wiring ever changes (e.g. a future Resource explicitly overrides `shouldRegisterNavigation()` for a new reason), this test will fail loudly and require updating the exception list — it does not silently pass. The task's own instructions already treat navigation-hiding as secondary ("Hiding navigation or buttons alone is insufficient") to the real HTTP-level protection, which this substitution still fully covers.

---

### Date
2026-07-20 (OMS Permissions Task 2B)

### Decision
Report-page authorization is implemented by overriding the static `canAccess()` method (via a shared `AuthorizesReportAccess` trait), not by adding a permission check inside each page's `mount()`. Export authorization is implemented as an explicit `authorizeReportExport()` call at the top of every export method, in addition to (not instead of) the header action's `->visible()`.

### Reason
Read Filament's `Filament\Pages\Concerns\CanAuthorizeAccess` (used by every `Filament\Pages\Page`): it wires `canAccess()` into `mountCanAuthorizeAccess()` **and** `hydrateCanAuthorizeAccess()` (the latter runs on every subsequent Livewire request for that component, not just the first) and into the static `Page::registerNavigationItems()`. Overriding `canAccess()` therefore gets navigation-hiding, direct-URL 403, and per-request re-authorization "for free," from one method, exactly the mechanism Filament ships for this — the task explicitly said not to rely on `mount()` alone when Filament provides a proper page access method. For exports specifically, `canAccess()` only re-checks the *view* permission on each request — it says nothing about the *export* permission, and the header action's `->visible()` only controls whether the button renders, not whether the underlying public Livewire method can be invoked directly. A user could hold view without export, so `authorizeReportExport()` independently checks both permissions inside the method body itself, proven by a real `Livewire::test()->call('exportMethod')->assertForbidden()` against a user who was never shown the button.

### Impact
Any future export method added to one of these 6 pages (or a 7th page reusing the trait) must call `$this->authorizeReportExport();` as its first statement — adding only a `->visible()` on the action is not sufficient and was explicitly flagged as insufficient by the task. `ProjectFinancialDetailsPage::mount(int|string $project)` still runs its own DB lookup *before* `mountCanAuthorizeAccess()` fires (Livewire calls a component's own `mount()` before its trait `mount*()` hooks) — an unauthorized direct-URL request still ends in a 403, but only after the lookup runs; this ordering is a Livewire/Filament framework property, not something this task's code controls, and is called out in the test file's docblock.

---

### Date
2026-07-20 (OMS Permissions Task 2B)

### Decision
Used real HTTP `assertSee()`/`assertDontSee()` against the Dashboard (`/admin`) sidebar to prove navigation visibility for the 5 nav-registered report pages, rather than falling back to a source-level reflection check (the approach Task 2A used for its 23 resources after finding cold `canViewAny()`/`shouldRegisterNavigation()` calls unreliable in this environment).

### Reason
Tried the HTTP-based sidebar check first specifically because Task 2A's docblock flagged it as previously unreliable; it turned out to work cleanly here (all 10 navigation-visibility assertions passed on the first fully-corrected run) — likely because each test method performs a genuinely fresh Laravel application boot (sqlite `:memory:`, no `RefreshDatabase`), so there was no stale static state to leak between assertions in this case. `ProjectFinancialDetailsPage` is the one exception: it declares no navigation label at all (`shouldRegisterNavigation = false` unconditionally), so there is no string to assert absent — that one page's navigation-disabled state is still verified via the same reflection technique Task 2A used, since it is the only technique that applies to a page with no nav item to render in the first place.

### Impact
If a future test in this same style (real HTTP navigation-visibility assertions) becomes flaky, that is new information about this environment worth re-investigating rather than assuming it will always fail the way Task 2A described — this task's result is contrary evidence, not a contradiction, since the two situations exercise different code paths (custom Pages vs. Resources) and different test files.

---

### Date
2026-07-20 (OMS Permissions Task 2B)

### Decision
Both new test files register a test-only SQLite emulation of MySQL's `FIELD()` function (via `PDO::sqliteCreateFunction()` in `setUp()`) rather than modifying `ProjectFinancialDetailsPage::loadProjectDetails()`'s `orderByRaw("FIELD(severity, 'critical', 'warning', 'note')")` query.

### Reason
This raw SQL is pre-existing, unmodified production code, and the task explicitly forbids changing report queries/calculations. It only surfaced as a blocker because any test that fully mounts this specific page (even as an *authorized* Super Admin, to prove the 200/OK path) executes this query, and SQLite has no built-in `FIELD()`. Registering the function on the test connection's PDO instance makes the existing query executable under the test database without touching a single line of application code — the same category of accommodation as this project's existing `mysqlOnly` migration-exclusion list in every other permissions test file's `setUp()`.

### Impact
Any future test that needs to fully mount `ProjectFinancialDetailsPage` (not just prove a 403) under SQLite must include this same `setUp()` snippet, or reuse/extract it into a shared test trait if a third such test file is ever added. The emulation only orders by first-match position among the 3 known severities (critical/warning/note) — sufficient for `ORDER BY`, not a general-purpose `FIELD()` implementation.

---

### Date
2026-07-20 (OMS Permissions Task 2B)

### Decision
Proved "a crafted Livewire request calling exportExcel()/exportWord() directly is rejected" using `Livewire::test($page)->call('exportMethod')->assertForbidden()` (letting the response bubble through Livewire's own testing response object), not by catching a thrown `HttpException` around the call.

### Reason
Traced `Livewire\Features\SupportTesting\RequestBroker::temporarilyDisableExceptionHandlingAndMiddleware()`: it calls `withoutExceptionHandling([HttpException::class, AuthorizationException::class])`, and Laravel's `$except` parameter means those two exception classes are *still* rendered normally into an HTTP response (not re-thrown) — only exceptions **outside** that list bubble raw to the test. A first attempt assuming the opposite (catching `HttpException`) produced 11 failures ("expected 403, none thrown") even though the `abort_unless()` calls were firing correctly. `Livewire\Features\SupportTesting\Testable::__call()` forwards any method it doesn't recognize (like `assertForbidden()`) straight to the captured `TestResponse`, which is exactly what a genuine Livewire component-update HTTP request/response cycle produces — this is the idiomatic, documented-by-source way to assert a Livewire action's HTTP outcome.

### Impact
Future tests asserting an `abort()`/`abort_unless()` inside a Livewire component method (page, action, or otherwise) reached via `Livewire::test()->call(...)` should use `->assertForbidden()`/`->assertStatus(403)`/etc. directly on the `Testable` chain, not a `try { ... } catch (HttpException $e)` block — the latter will silently never catch anything for exception classes Livewire's `RequestBroker` already special-cases (`HttpException`, `AuthorizationException`).

---

### Date
2026-07-20 (OMS Permissions Task 4 — secure role management)

### Decision
System-role protection for `RoleResource` is enforced structurally in `RoleResource::canEdit()`/`canDelete()` (calling `RoleManagementService::canManageRole()` directly, bypassing Gate/Policy) and in every table/page Edit/Delete action's explicit `->visible()` closure — never by relying on `RolePolicy::update()`/`delete()` returning `false`, and never by relying on Filament's default action-authorization resolution for the Edit/Delete row actions.

### Reason
`Gate::before` (AppServiceProvider) grants a real Super Admin every ability unconditionally, before Laravel ever reaches `RolePolicy::update()`/`delete()` — so a Policy method that correctly returns `false` for a system role is simply never consulted for that actor through the normal `$user->can('update', $role)`/Gate pipeline. Tracing Filament's `HasAuthorization`/`Page::getDefaultActionAuthorizationResponse()` confirmed the table's default `EditAction`/`DeleteAction` visibility also resolves through `Resource::getEditAuthorizationResponse()`/`getDeleteAuthorizationResponse()` — i.e. through the same Gate-based Policy path — so leaving those actions at their Filament defaults would have silently shown "Edit"/"Delete" for a system role to a Super Admin actor, even though the actual mutation would still be rejected by `RoleManagementService`. `RoleResource::canEdit()`/`canDelete()` were therefore written to call `canManageRole()` as plain PHP (no `Gate`/`can()` call inside it), which is what actually makes `EditRecord::authorizeAccess()` (`abort_unless(static::getResource()::canEdit(...), 403)`) 403 a direct system-role edit URL for a real Super Admin, and what makes the table/page action `->visible()` closures correctly hide those buttons for the same actor.

### Impact
Any future action added to `RoleResource`/`RolesTable`/its Pages that should be system-role-safe must call `RoleResource::canEdit()`/`canDelete()` (or `RoleManagementService::canManageRole()` directly) explicitly in its `->visible()`/`->action()` — adding a bare `EditAction::make()`/`DeleteAction::make()` without that explicit wiring would silently regress to showing/allowing the action for a Super Admin on a system role, since Filament's own default resolution path cannot be trusted for this specific rule. `RolePolicy::update()`/`delete()` are still correct and still tested directly (proving the Policy logic itself is right), but are understood to be advisory/defense-in-depth only for a real Super Admin actor, exactly like `UserPolicy::forceDelete()` in Task 3.

---

### Date
2026-07-20 (OMS Permissions Task 4 — secure role management)

### Decision
`RolePolicy` is registered explicitly via `Gate::policy(Role::class, RolePolicy::class)` in `AppServiceProvider::boot()`, rather than relying on Laravel's naming-convention policy auto-discovery.

### Reason
Traced Laravel's `Gate::guessPolicyName()` directly: for a class outside an `…\Models\…` namespace segment, it only ever guesses `{EachAncestorNamespaceSegment}\Policies\{Basename}Policy` for the model's *own* namespace tree — for `Spatie\Permission\Models\Role` that produces `Spatie\Policies\RolePolicy`, `Spatie\Permission\Policies\RolePolicy`, and `Spatie\Permission\Models\Policies\RolePolicy`, none of which is `App\Policies\RolePolicy`. This is different from `App\Models\User` → `App\Policies\UserPolicy`, which auto-discovers correctly because the model itself lives under `App\Models`. Confirmed by reading the exact guess algorithm rather than assuming Filament/Laravel "just finds it" for every model.

### Impact
`RolePolicyTest::test_role_model_resolves_to_the_explicitly_registered_role_policy()` asserts `Gate::getPolicyFor(Role::class)` returns a `RolePolicy` instance, proving the explicit registration actually took effect (mirrors `PolicyDiscoveryTest`'s role for ordinary auto-discovered models). If `spatie/laravel-permission`'s `Role` model is ever swapped for a project-owned subclass under `App\Models`, this explicit registration becomes redundant but harmless — no future change is required either way.

---

### Date
2026-07-20 (OMS Permissions Task 4 — secure role management)

### Decision
The permission-assignment UI is one collapsible `Section` + `CheckboxList` per `PermissionRegistry::groups()` module, each bound to a nested `permissions.{module}` form-state key — flattened to a single `list<string>` by the Create/Edit pages immediately before calling `RoleManagementService` — rather than one flat `CheckboxList` or a `->relationship()`-backed field.

### Reason
Filament's `CheckboxList` has no built-in option-grouping/optgroup support (confirmed by reading its Blade view — `@forelse ($options as $value => $label)` is a flat loop), so "grouped Arabic checkboxes" required either N separate components (one per module) or building custom grouped markup. N components each bound to a distinct nested state key is the smallest idiomatic Filament pattern that achieves real visual/functional grouping without custom Blade. A `->relationship()`-backed CheckboxList (Spatie's `permissions()` BelongsToMany) was explicitly rejected per the task's own instruction ("do not use automatic `->relationship()` saving if it bypasses RoleManagementService") — it would call `$relationship->sync()` directly from the form, skipping every validation/protected-permission/system-role check the service exists to enforce.

### Impact
Every Create/Edit page must remember to flatten `data.permissions.*` (`collect($data['permissions'] ?? [])->flatten()->unique()->values()->all()`) before passing it to the service — a future page reusing `RoleForm` without this step would silently submit a nested array instead of the flat list the service expects. Confirmed via a Livewire test that Filament's own `CheckboxList` "in" validation already rejects a submitted value outside the group's current `options()` (visible as error key `data.permissions.{module}.{index}`) — a first line of defense that runs even before `RoleManagementService::assignablePermissionNames()` is consulted a second time server-side.

---

### Date
2026-07-21 (OMS Task 6A — private attachment security foundation)

### Decision
An attachment's storage disk is resolved only through `Attachment::APPROVED_DISKS` (`['public', 'attachments']`), checked in one place (`AttachmentStorageService::resolveDisk()`) before every `Storage::disk()` call anywhere in the app — a stored `disk` value outside that list is treated as unavailable (404), never passed to `Storage::disk()`.

### Reason
`attachments.disk` is a plain database string column with no DB-level enum/check constraint. Trusting it directly would let a corrupted row, a future typo, or manual DB editing select an arbitrary Laravel filesystem disk (including ones never intended for attachment serving, like `s3` with production credentials, or `local`). An explicit allowlist checked at a single choke point makes "which disks can ever be read through this controller" a fact verifiable by reading one method, rather than an implicit property of wherever `Storage::disk($attachment->disk)` happens to be called.

### Impact
Adding a third approved disk (e.g. if S3 is ever adopted) requires updating `Attachment::APPROVED_DISKS` and nothing else in the authorization/serving path — `AttachmentController` and `AttachmentStorageService` never hardcode disk names beyond that constant. Grep confirms `Storage::disk($attachment->disk)` (the dynamic form) appears exactly once in `app/`, inside `resolveDisk()` itself.

---

### Date
2026-07-21 (OMS Task 6A — private attachment security foundation)

### Decision
The standalone `AttachmentResource` was hardened (hidden from navigation, only `index`/`view` routes, all 8 mutation `canX()` methods hard-overridden to `false`) rather than deleted, and its `attachments.*` permissions and `AttachmentPolicy` were left untouched.

### Reason
Its own `FileUpload` resolves to the pre-existing `local` disk (Laravel's default, `config('filesystems.default')`), which sits entirely outside the new `AttachmentController`'s authorization flow and is incidentally served by Laravel's built-in signed-URL route (`serve => true` on that disk) — a latent upload path that needed closing immediately. Deleting the resource outright would have been a larger, less reversible change than necessary for a "smallest safe change" security task, and would have discarded the existing `attachments.*` permission module and `AttachmentPolicy` for no security benefit (viewing a permission-gated read-only list was never the risk — uploading through it was). `PermissionResource` already established the exact pattern this reuses: `Gate::before` bypasses the underlying Policy's mutation denial for a real Super Admin, so a Policy-only fix would not have been sufficient — the hard `canX()` override on the Resource itself is what actually closes it for every actor.

### Impact
`AttachmentResource` remains reachable at `/admin/attachments` only via direct URL for a user holding `attachments.view_any`/`attachments.view` (never via sidebar); its Create/Edit routes return 404 for everyone including Super Admin, proven by dedicated tests rather than relying on generic provider skips. If a future phase decides this resource should support the 5 financial attachable types (currently only `Project`/`Transaction`/`Partner`, none of which have any real rows) or should route through the new private disk, that is a distinct, separately-scoped decision — not made here.

---

### Date
2026-07-21 (OMS Task 6A — private attachment security foundation)

### Decision
`AttachmentController` checks whether an attachment's parent record is missing or soft-deleted and returns 404 **before** ever calling `Gate::authorize('view', $parent)` — rather than letting the call reach the parent's own policy, which would also deny a trashed parent but via a 403.

### Reason
The parent policies' shared `AuthorizesCrud::view()` already denies a trashed record (`! $this->isTrashed($model)`), so relying on that alone would have produced 403 for a soft-deleted parent — indistinguishable, to the caller, from "authenticated but lacking permission." That conflation would leak one bit of information (trashed-status) to anyone who happens to already hold the module's `view` permission, even though the same conflation is otherwise harmless when checked purely as an internal policy detail elsewhere in the app (Filament's own View/Edit pages, which a user must already be mid-navigating a real, non-trashed record to reach). For a raw, guessable-numeric-id HTTP route, keeping "trashed" and "never existed" both resolve to 404 is a stricter, safer default.

### Impact
`AttachmentController`'s controller-level trashed-parent check is intentionally redundant with `AuthorizesCrud::view()`'s own trashed exclusion — both independently deny a trashed parent, by design, for two different reasons (information-leak avoidance here vs. general "don't operate on trashed records" elsewhere). Do not remove the controller-level check on the assumption that the policy already covers it; they serve different purposes even though the practical effect (denial) overlaps for an *authorized* user, and differs from the policy alone for an *unauthorized* user's ability to distinguish 403-vs-404.

---

### Date
2026-07-26 (OMS Task 7C.6 — attachment restore primitives)

### Decision
The attachment-swap state marker (`AttachmentSwapMarker`, phases `quarantined`/`activated`/`rolled_back`/`finalized`) is stored as a small JSON file at a **sibling path** of the quarantine directory (`attachments.pre_restore.{uuid}.state.json`), never inside the quarantine directory itself.

### Reason
`activate()`/`rollback()` both move the quarantine directory wholesale via `rename()`. Anything stored inside it would travel with it — a marker written to `{quarantine}/.state.json` after quarantining live attachments would get silently moved back into the live tree the moment `rollback()` restores it, contaminating restored attachment content with restore-internal bookkeeping. Storing it as an independent sibling file means every rename of the quarantine/live directories leaves the marker exactly where it is, so it reliably survives a crash at any point and is never mistaken for an attachment. It is also what makes `NotActivated` and `Finalized` distinguishable at all in `AttachmentSwapStateInspector` — both states leave an identical live-exists/no-quarantine directory layout, so without a persistent marker they would be structurally indistinguishable from filesystem facts alone.

### Impact
The marker file is a permanent audit artifact under `storage/app/private/` once an activation has ever been attempted for a given restore UUID — it is never deleted by `activate()`/`rollback()`/`finalize()` (only quarantine's own directory is deleted, by `finalize()`). Whether/when to eventually clean up marker files for long-finalized restores is left to a future recovery/retention phase (Task 7C.7+), not decided here.

---

### Date
2026-07-26 (OMS Task 7C.6 — attachment restore primitives)

### Decision
Quarantine, rollback-discard, and the swap marker are always placed as direct siblings of the live `attachments` disk root (`storage/app/private/attachments.pre_restore.{uuid}`, etc.) — never nested under the `restores` disk (`storage/app/private/restores/{uuid}/quarantine`), even though `RestoreWorkspace`'s own docblock (Task 7C.3) anticipated a "future quarantine directory" as a sibling of its own `workspace`/progress-file subtree.

### Reason
Two independent requirements ruled out nesting under the `restores` disk: (1) the task's explicit requirement that quarantine and live attachments be renamed on the **same filesystem** — the `restores` disk is a separate, independently-configured disk (see `config/filesystems.php`) with no guarantee of sharing a filesystem/mount with `attachments`, whereas a direct sibling of `attachments` itself is guaranteed to share one by construction; (2) `RestoreWorkspace::cleanup()` only ever removes its own `workspace` subtree, but nothing in the restore pipeline guarantees the *whole* `{uuid}` directory under `restores` survives for the entire window between activation and finalization — keeping attachment quarantine entirely independent of the `restores` disk's own lifecycle removes that coupling risk entirely.

### Impact
`RestoreAttachmentPaths` never reads or depends on `config('oms.backup.restore.disk')` at all — only `config('filesystems.disks.attachments.root')` (via `Storage::disk('attachments')->path('')`). A future `RestoreOrchestrator` must track quarantine state for attachments independently of however it tracks the `restores` disk's own per-UUID directory; the two are deliberately uncoupled.

---

### Date
2026-07-26 (OMS Task 7C.6 — attachment restore primitives)

### Decision
`RestoreAttachmentRevalidator::revalidate()` accepts an explicit `array $expectedFiles` (path/sha256/size per entry) and `int $expectedTotalBytes` parameter, rather than accepting `PreparedRestore` and reading the expected file list from it.

### Reason
`PreparedRestore::$manifestSummary` (built by `RestoreArchiveExtractor::buildManifestSummary()` in Task 7C.3) is deliberately bounded and explicitly excludes the full per-file attachment list, by design — carrying an unbounded per-file list on a value object meant to survive as small, loggable state was ruled out at that time. Since this phase's task scope forbids building the full `RestoreOrchestrator` or wiring these primitives to anything yet, the revalidator is written against the same shape `RestoreArchiveExtractor` itself already consumes (`manifest['attachments']['files']`) rather than inventing a dependency on a field `PreparedRestore` doesn't have.

### Impact
The future `RestoreOrchestrator` (Task 7C.7+) must supply the manifest's attachment file list to `activate()` itself — either by retaining it in-process from the same `BackupArchiveContentVerifier::verify()` call that already produced it during preparation, or by persisting a bounded subset (path/sha256/size only) to disk for crash-recovery scenarios where activation happens in a different process invocation than preparation. Which of those two the orchestrator uses is an open design question for that later phase, not decided here — flagged as a risk in `docs/TASKS_LOG.md`'s 2026-07-26 Task 7C.6 entry.

---

### Date
2026-07-26 (OMS Task 7C.6 crash-safety correction pass)

### Decision
The attachment-swap marker was upgraded from a plain, unsigned JSON file to a signed (HMAC-SHA256, purpose-derived from `APP_KEY` with its own distinct context `oms-restore-attachment-swap-v1`), durably-written (fsync'd, atomic-rename) value object — reusing the existing `RestoreProgressDurability` contract/`NativeRestoreProgressDurability` implementation rather than writing a second durability mechanism.

### Reason
The original implementation's own report already stated the marker is the ONLY thing distinguishing `NotActivated` from `Finalized` (both leave an identical live-exists/no-quarantine directory layout) — meaning its integrity is safety-critical to correct crash recovery, not diagnostic. An unsigned file is trivially editable by anything with filesystem access (a stray script, manual "fix", or a bug elsewhere) into claiming any phase, which could cause a future recovery flow to authorize `rollback()`/`finalize()` from a fabricated state. Reusing `RestoreProgressDurability` (rather than inventing a parallel durability implementation) keeps exactly one crash-safety discipline in this codebase to reason about and test, consistent with the project's own "don't introduce a second implementation of an already-solved problem" instinct.

### Impact
`AttachmentSwapStateInspector` now treats ANY marker-read failure (missing signature, invalid signature, UUID mismatch, unsupported phase, malformed schema, oversized) identically — `InconsistentNeedsManualReview` — never distinguishing "probably fine, just weird" from "actively tampered." This is deliberately conservative: a future recovery flow (Task 7C.7+) gets exactly one signal for "don't trust this restore's attachment state," never a partial-trust gradient.

---

### Date
2026-07-26 (OMS Task 7C.6 crash-safety correction pass)

### Decision
Several write-ahead marker phases (`RollbackStarted`, `RollbackLiveDiscarded`, `FinalizationStarted`) are accepted by `AttachmentSwapStateInspector` against MORE THAN ONE directory-fact pattern, each resolving to a different (but still correct and safe) state — rather than requiring one single fixed fact pattern per phase.

### Reason
These phases are each reachable from more than one prior state. `RollbackStarted` is written whether resuming from `Activated` (a normal fresh tree at live) or from `InterruptedDuringActivation` (no tree was ever restored to live at all, since activation crashed before its second rename) — the marker's job is only to say "about to attempt the quarantine-restoring move," not which of those two starting points led here. Similarly `RollbackLiveDiscarded` is reached either with a genuine discard tree present (a live tree really was moved aside) or without one (there was nothing to move aside, functionally identical to `InterruptedDuringActivation`). Requiring one exact fact pattern per phase would have forced inventing additional phases purely to distinguish starting points that don't actually change what needs to happen next, adding complexity without adding safety.

### Impact
Anyone extending this state machine must remember that phase alone does not always determine state — the directory facts at read time are load-bearing for these three phases specifically. Every accepted (phase, facts) combination is enumerated explicitly in `AttachmentSwapStateInspector::inspect()` (nested `match(true)` blocks) — no combination is inferred or defaulted; anything not explicitly listed there is `InconsistentNeedsManualReview`.

---

### Date
2026-07-26 (OMS Task 7C.6 crash-safety correction pass)

### Decision
A marker-durability failure occurring immediately AFTER a destructive filesystem mutation succeeded does NOT trigger an automatic corrective action (e.g. attempting to move the just-mutated tree back) — it throws a distinct `marker_update_failed_after_mutation` exception and leaves the filesystem exactly as the mutation left it.

### Reason
By the time a post-mutation marker write fails, something is already wrong with the marker-writing path itself (e.g. a full disk, a permissions change) that a same-process automatic "let me also try to undo the mutation" action would run through the exact same suspect write path, or would act on a filesystem state whose bookkeeping is already known to be unreliable. Compounding an already-abnormal situation with a second automatic mutation risks making a recoverable situation (both trees fully intact, just unrecorded) into a genuinely lost one. This is the concrete application of "do not blindly continue to the next mutation" and "preserve recoverable trees" from the correction request: the safest action when the bookkeeping itself is failing is to stop touching the filesystem at all and surface the problem.

### Impact
A marker-update-after-mutation failure always leaves both of that operation's trees exactly where the last successful mutation put them (verified by dedicated tests for all three lifecycle methods) and always reports `InconsistentNeedsManualReview` on the next inspection — this is intentionally the ONLY path in this class that can reach that terminal-looking-but-actually-safe state, and the future Task 7C.7+ recovery flow must treat it as "everything is physically fine, only the audit trail needs a human to confirm and re-stamp," not as data loss.

---

### Date
2026-07-26 (test-stability pass)

### Decision
Every test that acquires a real `BackupSubsystemLock` handle must wrap the ENTIRE span from acquisition through every subsequent operation (including `activate()`/`rollback()`/`finalize()` calls that can themselves throw) in a single `try { ... } finally { $handle->release(); }` — never acquire-then-operate-outside-the-guard-then-release-inside-it.

### Reason
A real `flock()`-backed lock has no per-test scoping of its own — it is a genuine OS resource shared across the entire PHPUnit process, at a fixed on-disk path that `Storage::fake()` does not meaningfully reset (it wipes directory contents, not an already-open file descriptor's lock). A handle leaked by ANY unguarded code path between acquisition and release therefore doesn't just fail its own test — it silently blocks every other test in the same process that needs the same lock, including tests in entirely unrelated files (`BackupCreationOrchestratorTest`, in the incident this decision responds to). This is exactly the class of bug the fix for `docs/TASKS_LOG.md`'s 2026-07-26 "test-stability pass" entry addressed: 7 methods called `activate()` (a real-filesystem-mutating call) before entering their `try` block.

### Impact
Any future test added to `tests/Feature/Restore/Attachments/RestoreAttachmentActivationServiceTest.php` (or any other test acquiring `BackupSubsystemLock` directly) must follow this pattern: acquire, then IMMEDIATELY open the `try`, do everything (including calls that are expected to succeed, not just the ones under test) inside it, and release only in `finally`. Code review of new tests in this area should treat "operation between acquire and try" as a defect on sight, not a style nit.

---

### Date
2026-07-26 (OMS Task 7C.7 — restore_failed_phase stays within the fixed ALLOWED_PHASES vocabulary)

### Decision
`RestoreOrchestrator` never invents new phase strings for `restore_failed_phase` (e.g. no `database_import`, `preflight_completed`, `staging_completed`, `attachments_activated`, `reconciliation_completed`). Every failure branch reports the value of `RestoreProgressSnapshot::ALLOWED_PHASES` the restore was actually AT when the failure occurred (e.g. `lock_acquired` for a preflight failure, `attachments_swapped`/`staging` for a database-import failure depending on scope, `reconciling` for a reconciliation failure, and the literal value `maintenance_disabled` specifically for a maintenance-exit failure).

### Reason
`RestoreProgressSnapshot::ALLOWED_PHASES` is explicitly documented as "fixed now so the progress-file schema is already fixed and cannot silently drift once [orchestration] is built" — every `create()` call validates `restore_failed_phase` against that same fixed list via `assertAllowed()`, so passing an invented string would throw `InvalidArgumentException` at the exact moment a terminal failure is being recorded, which is the worst possible place for an unexpected exception. A small `RestoreTerminalResultWriter::finish($failedPhaseOverride)` parameter was added so the literal failing phase can be pinned explicitly when a later, unrelated bookkeeping `advance()` call (writing `maintenance_disabled` on the way to a terminal write) would otherwise silently overwrite it with the wrong value.

### Impact
Anyone reading a terminal `restore_failed_phase` should treat it as "the last phase the restore reached before this failure," not as a dedicated per-failure-type enum — the accompanying (sanitized) `error_summary` text is what disambiguates the specific cause (e.g. the database-import-failure branches append an explicit sentence pointing at the safety backup for recovery). Any future phase added to the restore flow must go through `RestoreProgressSnapshot::ALLOWED_PHASES` first, never be introduced ad hoc in the orchestrator.

---

### Date
2026-07-26 (OMS Task 7C.7 — closing two 7C.6 integration gaps additively, not by rewriting)

### Decision
Two gaps discovered while wiring `RestoreOrchestrator` were closed with small, additive, backward-compatible changes rather than reworking the 7C.6 primitives themselves: (1) `App\Services\Restore\Contracts\AttachmentMoveRunner` was bound in `AppServiceProvider` for the first time (config-driven `NativeAttachmentMoveRunner`), since nothing had ever resolved `RestoreAttachmentActivationService` via the container before this task. (2) `PreparedRestore` gained two new trailing, defaulted constructor parameters (`attachmentManifestFiles`, `attachmentsTotalSizeBytes`), populated by `RestoreArchivePreparer::prepare()` directly from the already-verified archive manifest it already had in scope.

### Reason
The 7C.6 correction pass explicitly left "how does the future orchestrator source a `RestoreAttachmentManifest`" as an open question (see that entry's own docblock note: "the future RestoreOrchestrator (Task 7C.7+) recreates this object from the already-verified archive manifest immediately before activation"), but `PreparedRestore` — the only object `RestoreArchivePreparer::prepare()` returns to any caller — never actually carried that manifest data forward; only a bounded summary (`manifestSummary`, no per-file list) reached the caller. Rebuilding trust from scratch (e.g. re-opening/re-verifying the decrypted archive a second time in the orchestrator) would have duplicated already-correct, already-tested verification logic for no safety benefit. Adding the two fields is the minimal change that lets the orchestrator build a `RestoreAttachmentManifest` from data that was already fully authenticated by `BackupArchiveContentVerifier` moments earlier in the same call — never a second, independent trust source, and never accepted from the UI or `progress.json`.

### Impact
`PreparedRestore`'s constructor is not considered a stable, closed API — a future phase needing more already-verified manifest data forward should extend it the same way (new trailing, defaulted parameters), not by re-deriving trust independently elsewhere. The one other test file constructing `PreparedRestore` directly (`DatabaseRestorerTest`) needed no changes, confirming the defaults are genuinely backward compatible.

### Date
2026-07-26 (OMS Task 7C.7 — watchdog notification spam bounded via Cache, not a new column)

### Decision
`RestoreWatchdogCommand` deduplicates its own persistent notifications per (restore UUID, reason code) using a plain `Cache::has()`/`Cache::put()` cooldown key (`config('oms.backup.restore.watchdog_notification_cooldown_minutes')`, default 60), not a new `backup_operations` column or a new table.

### Reason
The watchdog runs every minute and is explicitly detection-only — it must never mutate a restore row (see its own docblock and `RestoreStaleDetector`'s "never mutates" contract). Recording "already notified" as a DB-persisted fact on the restore row itself would blur that line and risk being mistaken for restore state a future recovery flow might read as authoritative. A `Cache` key carries no such risk: it is explicitly disposable bookkeeping (losing it merely means one extra notification gets sent, never a correctness issue), and the underlying `oms.backup.restore.stale_after_minutes` mechanism plus `RestoreStaleDetector` re-evaluating from scratch every run mean the cooldown is purely a spam-prevention nicety, never a safety mechanism.

### Impact
If the application's cache store is ever cleared/flushed in production, the watchdog will simply re-notify once for every currently-stale restore on its next run — a harmless, self-correcting outcome, not a bug to chase.

---

### Date
2026-07-27 (OMS Task 7C.7 final hardening pass — RestoreProgressWriter Windows rename retry)

### Decision
`RestoreProgressWriter::write()`'s final publish step (`rename($tempPath, $finalPath)`) now retries on a small, bounded, Windows-only schedule (`renameWithRetry()`, new config `oms.backup.restore.progress_publish_retry_attempts`/`progress_publish_retry_delay_ms`, mirroring `NativeAttachmentMoveRunner`'s existing pattern exactly) instead of failing on the first attempt.

### Reason
Introducing `RestoreHeartbeat` (this same pass) made this exact rename() run far more frequently, in rapid succession, against the same `progress.json` path than it ever had before. Empirical stress-testing (10x `--order-by=random` runs of the two heaviest-I/O new test files) showed a real ~40% intermittent failure rate on this Windows/Laragon host, always with the identical sanitized `RestoreProgressWriteException::cannotPublish()` diagnostic — the same class of transient open-handle/AV-scanner interference already documented and retried for in `NativeAttachmentMoveRunner`, just never triggered here before because write frequency was too low to expose it.

### Impact
Any future feature that increases `RestoreProgressWriter::write()`'s call frequency further should re-run this same kind of `--order-by=random` stress test (not just a single clean run) before considering the change safe on Windows/Laragon — a single green run is not sufficient evidence given this class of flake's low-but-nonzero per-call failure rate. Linux/production is unaffected: `rename(2)` there is atomic and reliable, and `renameWithRetry()` makes exactly one attempt on that platform, identical to the pre-fix behavior.

### Date
2026-07-27 (OMS Task 7C.7 final hardening pass — RestoreAttachmentLifecycle interface introduced solely for deterministic testing)

### Decision
`RestoreOrchestrator` depends on a new `RestoreAttachmentLifecycle` interface (implemented by the unchanged, still-`final` `RestoreAttachmentActivationService`) instead of the concrete class directly.

### Reason
The acceptance pass required a deterministic test proving finalize()-failure compensation (RestorePartial, quarantine preserved, maintenance exit still attempted). `RestoreAttachmentActivationService` is `final` and forcing a real finalize() failure deterministically and cross-platform (e.g. via filesystem permissions) is not reliable enough to build a test on. The interface is the minimal seam needed — it changes no behavior in the real implementation and is never used to bypass any of that class's own safety rules (lock validation, state re-inspection, write-ahead marker protocol), all of which remain entirely inside `RestoreAttachmentActivationService` itself.

### Impact
A future reader should not mistake this interface for a sign that attachment lifecycle behavior is meant to be pluggable in production — there is exactly one bound implementation (`AppServiceProvider`), and the interface exists purely for the one test double (`FinalizeFailingAttachmentLifecycle`) that needs it.

---

### Date
2026-07-27 (OMS Task 7C.9 — dropped the unsupported `--message` option from the real `down` call rather than adding a custom maintenance view)

### Decision
`RestoreMaintenanceMode::enter()` now calls Laravel's real `down` Artisan command with no options at all (`$this->artisan->run('down', [])`), instead of trying to restore a custom Arabic maintenance message some other way (e.g. a new `--render` Blade view).

### Reason
Real E2E testing against the actual local Laragon environment proved every restore failed 100% of the time at preflight with `The "--message" option does not exist.` — this Laravel version's `down` command never had that option (it was replaced project-wide, several Laravel major versions ago, by `--render`, which points at a prerendered Blade view rather than accepting a free-text string). The maintenance message was never functionally required — it only affects what a visitor to the maintenance page sees during the brief restore window — so building a new Blade view to preserve the exact original Arabic text would be a real feature addition to fix what is fundamentally a bug in an unrelated 854-mocked-tests-blind-spot, and Task 7C.9's own instructions explicitly forbid feature expansion for a proven E2E defect. Dropping the option is the narrowest change that makes every real restore work again.

### Impact
Anyone re-adding a custom maintenance message in the future must use Laravel's real `--render`/`--secret`/`--retry`/`--refresh` option set (confirmed via `php artisan down --help` against the actual installed Laravel version, not assumed from memory or an older version's documentation) and must add a real, deterministic test against the actual Artisan command (not only a hand-rolled fake command-runner double, which is exactly what let this defect through undetected for the entire 7C.1–7C.8 phase).

### Date
2026-07-27 (OMS Task 7C.9 — kept every genuine backup/restore row from real UI actions as acceptance evidence; deleted only manually-constructed synthetic drill rows)

### Decision
After all Task 7C.9 E2E scenarios, cleanup hard-deleted only the `backup_operations` rows and files that were manually constructed in the database/filesystem to simulate a failure (the corrupted-archive preflight-failure drill, and the stale/crashed-restore drill) — every backup/safety-backup/restore row that was actually produced by clicking through the real Filament UI and running the real orchestrator (across the database-only, files-only, and full restore cycles, including the one restore attempt that failed on the real `--message` bug before it was fixed) was deliberately left in the live database.

### Reason
The task instructions explicitly required keeping "legitimate backup/restore history if needed for acceptance evidence" while removing only temporary fixtures. Rows produced by the real pipeline are genuine evidence that the real UI + real orchestrator worked correctly end to end (including the failed-then-fixed attempt, which is direct evidence of the defect found and its correction); the corrupted-archive and stale-crash rows were never produced by the real pipeline at all — they were rows I inserted directly into the database (and a deliberately corrupted archive copy) purely to exercise failure-handling code paths without risking a real backup or a real crashed process, so removing them afterward does not remove any genuine application history.

### Impact
A future reviewer of the local `oms` database's `backup_operations` table will see a real, legitimate trail of Task 7C.9's three successful restore cycles (plus the one instructive failed-then-fixed attempt) and should not mistake their presence for leftover test clutter needing further cleanup — they were kept intentionally, per instructions, as acceptance evidence.

---

### Date
2026-07-27 (OMS Task 8 — `debit_base`/`credit_base` are per-line own-currency values; multi-currency balance is the FX equation, never a raw cross-currency sum)

### Decision
The financial integrity checker's `JournalBalanceIntegrityChecker` treats `transaction_lines.debit_base`/`credit_base` as each line's own-currency amount (identical to `amount_currency`) and validates a 4-line multi-currency transaction (general exchange / budget disbursement) via `FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines()`'s real FX equation — never via `SUM(debit_base) = SUM(credit_base)` across the whole transaction.

### Reason
The task's own instructions offered a hypothetical example implying `debit_base`/`credit_base` might be a company-base-currency-normalized value (e.g. `credit_base = amount × fx_rate`) and explicitly warned not to assume this without checking the real code. Auditing every real write path (all six financial Create/Edit pages) and three independent pre-existing docblocks (`TrialBalanceReportService`, `TrialBalancePage`, `ComprehensiveFinancialTransactionsPage` — all stating "despite the column names") confirmed the opposite: `debit_base`/`credit_base` are never fx-multiplied: only the destination line of a 4-line exchange carries a real `fx_rate`, and even there `debit_base` equals `amount_currency` (the already-converted destination-currency amount), not `amount_currency × fx_rate` computed a second time. A raw `SUM(debit_base)=SUM(credit_base)` across a 4-line exchange would therefore silently sum two different currencies (exactly the `1000 USD != 3000 ILS` mistake the task explicitly forbade) and would falsely reject every legitimate multi-currency exchange whose FX rate isn't exactly 1.

### Impact
Any future financial-integrity or reporting code touching multi-currency transactions must classify by `line_role` set first (2-line single-currency vs. the 4-line `source`/`administrative_deduction`/`transfer_fee`/`destination` shape) and apply the matching invariant — never a blanket cross-currency sum. A transaction whose `line_role` set matches neither known shape (including every pre-2026-07-14 historical row where `line_role` is `NULL`) is reported as a bounded WARNING ("unclassified structure, requires manual review"), never guessed at as balanced or unbalanced. See `docs/AI_PROJECT_MEMORY.md` (2026-07-27, "OMS Task 8") for the full audit trail and `tests/Feature/Integrity/JournalBalanceIntegrityCheckerTest.php` for the proof (a valid 1000→2999.94 exchange passes; the same shape with a wrong FX conversion is caught; a naive raw-sum check is proven never used).

---

### Date
2026-07-27 (OMS Task 8 — schema/migration-history drift documented, not fixed)

### Decision
`bank_accounts` (plus `transactions.bank_account_id` and `accounts.parent_id`, both real FKs into it/itself) exist live in the local MySQL database with no corresponding migration file anywhere on disk — discovered while auditing FK coverage for Task 8. Per explicit user decision (offered three options: document only / add a reconciling migration now / ignore entirely), this is **documented only in this task; no migration was added and no schema was touched.**

### Reason
This drift is outside Task 8's named scope (financial integrity + Trial Balance), and reconstructing a migration for schema that already exists live carries its own risk of getting the historical column order/defaults subtly wrong without a clear, separate review of the real intent. The `migrations` DB table has 66 rows but only 63 files exist on disk; the other two missing files (`create_budget_lines_table`, `create_project_activities_table`) are harmless since both tables were later dropped by a migration that is still on disk — only the `bank_accounts` family is a live, unreproducible gap.

### Impact
A `migrate:fresh` on this project today would NOT recreate `bank_accounts`, `transactions.bank_account_id`, or `accounts.parent_id` — a future fresh install/CI setup would break at whichever migration/seeder first touches one of these. This should be tracked as its own explicitly-scoped follow-up task, not assumed fixed by Task 8. `tests/Support/Integrity/IntegrityTestFixtures.php::shimUndocumentedSchemaDrift()` adds a minimal test-only SQLite shim for these three items so Task 8's own relationship-integrity tests can still exercise the (real, already-correct) checks against them — this shim never touches the real migrations directory or the real database.

---

### Date
2026-07-27 (OMS Task 8 — transaction-number generation: shared trait + bounded retry, not a redesign)

### Decision
The six near-identical `generateTransactionNumber()` MAX+1 implementations (one per financial Create page, byte-identical) are deduplicated into one shared `App\Filament\Concerns\GeneratesSequentialTransactionNumbers` trait with the exact same logic/format, unchanged. Each page's `handleRecordCreation()` now wraps its existing `DB::transaction()` call in the trait's new `retryOnTransactionNumberCollision()` — a bounded 3-attempt retry that catches only a `UniqueConstraintViolationException` whose message mentions `transaction_number`, re-running the whole closure (which recomputes MAX fresh) on each attempt.

### Reason
The audit found `transaction_number` already carries a real DB UNIQUE constraint and `lockForUpdate()` already serializes concurrent creates against any EXISTING row matching a prefix — so no historical duplicate is possible today. The one real gap is the very first number under a brand-new prefix: `lockForUpdate()` cannot lock rows that don't exist yet, so two concurrent creates racing to be "the first REC-2026-" could both compute suffix 0001, and the loser would previously surface as an uncaught `QueryException` (ungraceful, but never a duplicate row — the DB constraint already prevented actual corruption). The task explicitly required "preserve the existing visible numbering format" and "do NOT redesign numbering unless objectively necessary" — a bounded retry closes the ungraceful-failure gap without changing the number format, the generator's own logic, or introducing a new numbering scheme.

### Impact
Any future financial Create page needing sequential transaction numbering should use this same trait rather than re-implementing `generateTransactionNumber()`/its own retry logic. `tests/Unit/Filament/Concerns/GeneratesSequentialTransactionNumbersTest.php` pins the retry contract directly (pure unit test, no DB): succeeds without retry on the happy path, retries only on a genuine `transaction_number` collision, rethrows immediately for any unrelated exception (including a `UniqueConstraintViolationException` for a different table's constraint), and rethrows after exhausting `maxAttempts`.

---

### Date
2026-07-28 (OMS Task 8.1 — schema/migration-history drift resolved)

### Decision
`bank_accounts`, `accounts.parent_id`, and `transactions.bank_account_id` — deferred by Task 8 (2026-07-27) as "documented only" pending its own dedicated task — are classified **DEAD_UNUSED** and have been removed from the real local `oms` database and from the current design. All three had zero application code usage (no model, relationship, `$fillable` entry, Filament resource/form field, factory, or seeder anywhere in the codebase) and zero non-null/live data both currently and in the earliest captured historical backup (2026-07-06). The single `bank_accounts` row that ever existed (created 2026-06-09, placeholder-looking data) had already been purged by the separate, user-approved 2026-07-06 operational data reset — not by this task.

### Reason
This is evidence-based, not assumption-based: `git log --diff-filter=A` across the whole repository history shows all 64 migration files ever added still match all 64 files currently on disk (nothing was ever deleted from git) — `create_bank_accounts_table` was never committed to this repository at all, unlike its two harmless siblings (`create_budget_lines_table`/`create_project_activities_table`, which were legitimately dropped later by a migration still on disk). No design document, form, or resource anywhere references a bank-account entity or an account hierarchy as a current or planned feature. Removal was authorized directly by the DEAD_UNUSED classification's own action rule (no separate stop-and-ask required, unlike LEGACY_WITH_DATA/AMBIGUOUS), subject to the safeguards already completed: a pre-task `mysqldump` backup outside the project directory, the exact planned SQL shown before execution, and full local/regression/integrity verification after.

### Impact
A new guarded migration (`2026_07_28_100000_drop_dead_bank_accounts_schema.php`) dropped `transactions.bank_account_id` (FK then column), `accounts.parent_id` (FK then column), and the `bank_accounts` table from the real local DB; the orphaned `migrations` table row for the never-committed `create_bank_accounts_table` file was deleted. `php artisan migrate:fresh` on a fresh install already never reproduced these three objects (no migration file ever defined them), so this change reconciles the *live local DB* to match what a fresh install has always produced — verified via an isolated scratch database. `DatabaseRelationshipIntegrityChecker` no longer checks these two relationships (12 → 10 checked); its test-only SQLite drift shim was removed as no longer necessary. Two unrelated, pre-existing schema drifts were discovered as a side effect of this task's fresh-vs-local comparison (`accounts.account_code` NOT NULL live vs. nullable fresh; `accounts_type` carrying a live `nature` enum + audit columns with no reproducing migration) — both are explicitly out of this task's named scope and were left untouched; see `docs/NEXT_STEPS.md` for the recommended follow-up.

---

### Date
2026-07-28 (OMS Task 8.2 — final schema drift reconciliation)

### Decision
`accounts.account_code` is confirmed **nullable by design** (not required) — the live `NOT NULL` constraint was the drift, and has been relaxed to match. `accounts_type.nature` is classified **DEAD_UNUSED** and has been dropped. `accounts_type.created_by`/`updated_by` are classified **ACTIVE_REQUIRED** (approved audit-metadata convention) and have been reproduced via migration, schema-only — `AccountType` was **not** wired to `App\Traits\HasUserTracking`.

### Reason
`account_code`: the original `create_accounts_table` migration (unchanged since the initial commit `c40e0d8`) has always declared `->nullable()->unique()`; the current `AccountForm` explicitly sets `->nullable()`; and every one of the ~20 code sites that read `account_code` (reports, exports, dropdowns, view pages) defensively guards `$account->account_code ? ... : ''`, proving the whole application was built assuming it can be empty. Zero null/empty/duplicate values exist in the real local data, so relaxing it is lossless.

`nature`: zero code reads or writes this column anywhere in the codebase — the only other "nature" occurrences are an unrelated Trial Balance report row-classification label (same key name, different concept, computed from a transaction balance, never from this column). `docs/AI_PROJECT_MEMORY.md` already documented, dated 2026-07-06 (predating this task's audit and therefore not written to justify this decision), that "the system has no account nature... every account is uniformly debit-normal." Live values (7 of 10 rows: `asset` + `created_by` NULL) are consistent with MySQL's implicit ENUM-backfill behavior when a `NOT NULL` column with no default is added to a table that already has rows, not deliberate business data entry.

`created_by`/`updated_by`: unlike `nature`, these match — column-for-column, FK-for-FK (`REFERENCES users(id) ON DELETE SET NULL`, nullable) — the exact audit-metadata convention that `App\Traits\HasUserTracking` actively provides to 20 other current models (`Currency`, `FiscalYear`, `Partner`, `TransactionType`, etc.). This is not a rejected design like `nature` — it reads as a table that simply never got wired into an already-adopted, still-active convention. Real historical data exists (3 `accounts_type` rows carry `created_by=1`) that dropping the column would destroy.

### Impact
Two new guarded migrations applied to the real local `oms` database: `2026_07_28_110000_make_account_code_nullable_on_accounts_table.php` (relaxes `accounts.account_code`; intentionally irreversible/no-op `down()` — a fresh install has always had it nullable, so forcing `NOT NULL` on rollback would impose a constraint no fresh install ever had, the same principle established for Task 8.1's migration) and `2026_07_28_110001_reconcile_accounts_type_schema_drift.php` (drops `nature`; adds `created_by`/`updated_by`; `down()` cleanly reverses the added columns but does not recreate `nature`, for the same reason). Verified via an isolated scratch database that `accounts_type` and `accounts.account_code` now match a fresh install exactly, and that rolling back both migrations on a fresh install resurrects neither dead schema nor a constraint that never existed. A materially larger, still out-of-scope drift was newly caught during this task's required fresh-vs-local comparison: `accounts.created_by`/`updated_by` (the sibling table) are also present live but absent from a fresh install — the identical drift class, never named in this task's scope, left untouched and recommended as its own follow-up in `docs/NEXT_STEPS.md`. `AccountType` remains unwired from `HasUserTracking` — starting that wiring (or the broader Audit Log feature it implies) was explicitly out of this task's scope.

**Post-acceptance correction (same day)**: the accepted implementation's `down()` for `2026_07_28_110001_reconcile_accounts_type_schema_drift.php` unconditionally dropped `created_by`/`updated_by` on rollback — safe on a fresh install, but wrong on the real local database, where those two columns already existed as live drift *before* this migration ever ran. A rollback there would have destroyed real pre-existing schema/data the migration never created. Corrected to make the entire `down()` irreversible (no-op), not just the `nature` half, since `up()` has no reliable way to distinguish "I added these columns" from "these already existed." Verified via two isolated scratch-DB scenarios (fresh install; simulated historical live drift with a real tracked row) that rollback in both cases now leaves everything — including real `created_by`/`updated_by` values — completely untouched.

---

### Date
2026-07-28 (OMS Task 8.3 — accounts user-tracking schema reconciliation)

### Decision
`accounts.created_by`/`accounts.updated_by` are classified **ACTIVE_REQUIRED** and have been reproduced via a guarded migration on the real local database (a complete no-op there, since both already existed correctly). `Account` is **not** wired to `App\Traits\HasUserTracking` — activating creator/updater tracking behavior is explicitly deferred to the future Task 9 Audit Log work.

### Reason
Unlike `accounts_type.created_by`/`updated_by` (which carried 3 real historical rows), `accounts.created_by`/`updated_by` have **zero** non-null values anywhere — not in the current 5 accounts, and not in a single one of the 16 accounts present in the earliest captured historical backup (2026-07-06). Taken alone, this would point toward DEAD_UNUSED. The decisive factor instead is architectural consistency: the column shape is column-for-column, FK-for-FK identical to the active `HasUserTracking` convention used by 20+ other current models, and to `accounts_type`'s own copies, reconciled as ACTIVE_REQUIRED one task ago for the identical reasoning ("a table that simply never got wired into an already-adopted convention, not a rejected design"). `Account` is also this system's single most central financial entity — by far the most likely candidate to need creator/updater tracking once a real Audit Log exists. Removing the columns now and almost certainly re-adding them for Task 9 would be pure churn. This task's own title and framing ("final schema-drift task *before* Audit Log") anticipates keeping them.

Wiring `HasUserTracking` onto `Account` today was considered and explicitly rejected as out of scope: this task's restrictions repeat "do not start Audit Log" four times, and activating real creator/updater tracking behavior — even via an already-proven-safe trait — is exactly the kind of behavior that future task is meant to own. Treating `accounts` differently from `accounts_type` (wiring one, not the other) with no compelling reason would also be an inconsistent, one-off exception.

### Impact
New guarded migration `2026_07_28_120000_reconcile_accounts_user_tracking_schema_drift.php`: per-column, it adds the column+FK only if the column is missing, or repairs only a missing FK if the column already exists without one (checked via Laravel's driver-agnostic `Schema::getForeignKeys()`) — so it can never duplicate a constraint/index name. `down()` is intentionally irreversible from the start (informed directly by the same-day `accounts_type` correction above) — the migration cannot tell whether it created the columns or whether they pre-existed as live drift, so rollback must never risk deleting real (or, once Task 9 populates them, historical) tracking data. Verified via two isolated scratch-DB scenarios (fresh install; simulated live drift recreated using the no-op `down()` itself, with a harmless real-valued row) that the migration is idempotent, non-duplicating, and fully rollback-safe, and via a third scratch check that the FK's `ON DELETE SET NULL` genuinely nulls the reference when the tracked user is deleted. Fresh-vs-live parity for `accounts` is now exact. No remaining named Account/AccountType schema drift exists. `docs/NEXT_STEPS.md` records that activating real `Account` creator/updater tracking is deferred to the future Task 9 Audit Log work.

---

### Date
2026-07-28 (OMS Task 9A — recommended Audit Log architecture)

### Decision
A first-party, in-house Audit Log (one `audit_events` table + a shared CRUD-diffing layer + explicit domain audit calls) is recommended over adopting a third-party audit/activity-log Composer package.

### Reason
No audit package is currently installed (confirmed directly against `composer.json`, not from memory). A pure CRUD-logging package would only cover generic model-change events — every other required category (financial-transaction semantics, security/role events, attachment view/download, backup/restore lifecycle, report exports, background/system actors) needs first-party domain events regardless of what ships in any such package, and none of the mainstream options has been vetted against this app's specific Laravel 13/Filament 5/PHP 8.3 combination. This mirrors the identical reasoning already applied and documented for the Task 7B backup engine (built in-house rather than adopting `spatie/laravel-backup`, for materially the same "a package would only cover a fraction of the real requirement" reasoning).

### Impact
No Composer dependency was added or will be added for the Audit Log feature. `App\Services\Audit\AuditLogger`/`AuditRedactor`/`AuditPayloadBounder`/`AuditActorContext` (built in Task 9B.1) are the permanent foundation later phases (9B.2+) must build directly on top of, not re-derive.

---

### Date
2026-07-28 (OMS Task 9B.1 — application-level immutability, no DB triggers)

### Decision
`App\Models\AuditEvent` enforces append-only behavior entirely at the Eloquent/application level (`updating`/`deleting`/`replicating` model-event hooks + an explicit `forceDelete()` override, all throwing `AuditImmutableRecordException`) — not via a database-level `REVOKE UPDATE, DELETE` grant or a DB trigger.

### Reason
This codebase's established pattern for "must never be mutated through the ordinary app" is already application-level and test-backed, not DB-enforced — see `PermissionResource`/`TransactionResource`'s structurally-hardcoded-`false` `canX()` methods, which survive even `Gate::before`'s Super Admin bypass by construction rather than by a database grant. A real `REVOKE` would need a per-purpose DB user (this app currently uses one shared DB user for everything, confirmed via `config('database.default')` being the only connection anything resolves), making a grant-level lockdown an operationally heavier change than this foundation phase's scope — and Task 9A's own design audit explicitly recommended it only as a later, defense-in-depth option for the eventual Hostinger VPS production deployment, not as part of the foundation.

### Impact
Every Eloquent mutation path on an existing `AuditEvent` row (`save()` after a dirty change, `update()`, `delete()`, `forceDelete()`, `replicate()`) throws `AuditImmutableRecordException` — proven directly by `AuditEventImmutabilityTest`. A future, explicitly-scoped maintenance task may still operate on `audit_events` via direct DB access (e.g. a real archival/retention job); ordinary application code must not, and nothing built in 9B.1 provides such a path. Revisit a DB-grant-level lockdown specifically as part of the future Hostinger production-deployment task, not before.

---

### Date
2026-07-28 (OMS Task 9B.1 — redaction matches whole underscore segments, never a bare substring)

### Decision
`App\Services\Audit\AuditRedactor` flags a field as sensitive by exact name, by suffix (`_token`/`_secret`), or by exact underscore-delimited **segment** match against a denylist (`key`, `secret`, `token`, `password`, `credential`, `cookie`, `authorization`, etc.) — never by a bare `str_contains()` substring check — with one explicit, narrowly-scoped safe-exception list (currently just `encryption_key_id`) for confirmed non-secret identifiers a segment rule would otherwise catch.

### Reason
Task 9A's design audit explicitly flagged the risk of over-redaction: a naive substring check (`str_contains($key, 'key')`) would also match a real, safe, already-in-use identifier — `App\Models\BackupOperation::encryption_key_id` identifies which key encrypted an archive, it is never the key material itself (see `App\Services\Backup\BackupKeyRing`). Whole-segment matching (splitting the field name on `_` and checking for an exact segment match) means `encryption_key_id` is genuinely caught by the `key` segment rule and must be explicitly allowlisted back — proving the safe-exception mechanism actually does something — while a real field like `bank_type_id` is never at risk in the first place (no segment of its name matches any denylist entry). No other business field in this codebase currently needs a safe exception; per Task 9A's explicit instruction, none was added speculatively (e.g. no `account_key` exception, since no such field exists anywhere).

### Impact
Adding a new safe exception in the future requires confirming (the same way `encryption_key_id` was confirmed here) that the field is a real, non-secret identifier already in active use elsewhere in the codebase — never added defensively/speculatively. `AuditRedactorTest::test_encryption_key_id_safe_exception_is_preserved` pins this down directly; any future safe exception should get the same kind of dedicated test.

---

### Date
2026-07-28 (OMS Task 9B.1 — AuditLogger never opens its own DB transaction)

### Decision
`App\Services\Audit\AuditLogger::record()` performs a single `AuditEvent::create()` call with no surrounding `DB::transaction()`/`DB::beginTransaction()` of its own.

### Reason
Task 9A's design audit's explicit failure-policy recommendation (confirmed and formalized as this task's approved `AuditFailureMode::Required` contract) requires that a future financial/security caller be able to invoke `record(..., AuditFailureMode::Required)` from *inside* its own existing `DB::transaction()`, so the business mutation and its required audit row commit — or roll back — as a single atomic unit. If `AuditLogger` opened its own nested transaction, Laravel's savepoint-based nested-transaction semantics would still make this work in the common case, but it would be an unnecessary, easy-to-regress implicit dependency on that nesting behavior rather than a structurally guaranteed property. Not opening any transaction at all makes the coupling unconditional and trivial to reason about.

### Impact
`AuditLoggerTransactionTest` proves this directly: a `record()` call inside a test-opened `DB::transaction()` that later throws rolls the audit row back with it, a normal commit persists it, and `DB::transactionLevel()` is unchanged immediately before/after a `record()` call. Any future 9B.2+ caller auditing a financial mutation in `Required` mode should call `record()` from inside its own existing transaction and can rely on this guarantee without adding any wrapping of its own.

---

### Date
2026-07-28 (Graphify hygiene — project-local `.graphifyignore`, not `.gitignore`)

### Decision
Sensitive/runtime-path exclusion for Graphify is configured via a new project-root `.graphifyignore` file — not by editing the application's own `.gitignore`.

### Reason
`.gitignore`'s purpose is git tracking, not graph-indexing scope, and this repository's actual `.gitignore` deliberately does not exclude the bulk of `storage/` (only `/storage/*.key` and `/storage/pail`) — widening it to cover `storage/app/public`/`storage/framework` would be a git-tracking-behavior change well outside this task's scope and risk, and was never needed: confirmed directly against the installed Graphify package's source (`graphify/detect.py`'s `_load_graphifyignore()`) that `.graphifyignore` is a first-class, purpose-built mechanism, merged with `.gitignore` using gitignore's own last-match-wins semantics, and honored identically by both `graphify update` (CLI) and the post-commit hook's detached rebuild (`graphify.watch._rebuild_code`) — the same underlying `detect()`/ignore-pattern code path either way.

### Impact
`.graphifyignore` can only ever exclude more than `.gitignore` already does, never re-include anything — a safe, additive, narrowly-scoped file. Any future path that needs to stay out of the graph (a new upload directory, a new cache location) should be added here, not by broadening `.gitignore`.

---

### Date
2026-07-28 (Graphify hygiene — `graphify update`/`--force` does not reliably prune stale nodes for newly-excluded files)

### Decision
A genuinely clean Graphify rebuild after adding new exclusions requires removing the existing `graph.json`/`manifest.json`/`GRAPH_REPORT.md` first (backed up beforehand) rather than relying on `graphify update .` or `graphify update . --force` alone against an existing graph.

### Reason
Empirically observed: running `graphify update .` with the new `.graphifyignore` in place against an existing `graph.json` left the 12 previously-indexed `storage/app/public/*` nodes in place unchanged (AST extraction correctly dropped to 804/1434 files, but the resulting merged graph still carried the old nodes forward). Re-running with `--force`/`GRAPHIFY_FORCE=1` did not fix it either — the run hit a separate "no topology changes detected — outputs left untouched" fast-path and skipped writing entirely. Only removing the three output files (so `graphify`'s own `backup_if_protected()`/merge logic had no prior `graph.json` to compare against or merge into) produced a truly fresh extraction that correctly omitted every newly-excluded path. This was independently reproduced twice (byte-identical SHA-256 hashes both times), confirming it is deterministic, not a fluke.

### Impact
Documented here as the correct procedure for any future Graphify exclusion change in this repository: back up `graph.json`/`manifest.json`/`GRAPH_REPORT.md` (and check the dated `graphify-out/<date>/` "curated backup" snapshot too — it can independently carry forward stale content from an intermediate attempt, exactly as happened here, since its own backup step only fires when a prior `graph.json` exists to snapshot from), delete the three top-level output files, then run `graphify update .` fresh. Simply adding `.graphifyignore` and re-running `update` is not sufficient on its own when a prior graph already exists.

---

### Date
2026-07-28 (OMS Task 9B.2 — general CRUD auditing is owned by a service, never by model observers)

### Decision
General-CRUD audit events for the eleven approved models are written exclusively by `App\Services\Audit\Crud\AuditedCrudService`, which opens its own `DB::transaction()` around **both** the business mutation and the `AuditLogger::record()` call. No `created`/`updated`/`deleted`/`restored` model observer, and no global model hook, writes an audit event.

### Reason
A read-only audit of the real write paths found that **no Filament write path in this application currently runs inside a database transaction**. `Filament\Pages\Concerns\CanUseDatabaseTransactions::hasDatabaseTransactions()` falls back to the panel's setting, and the OMS admin panel never calls `->databaseTransactions()`; `Filament\Actions\Concerns\CanUseDatabaseTransactions::$hasDatabaseTransactions` defaults to `false` and no action sets it. An `updated` observer would therefore fire with the row already committed, so a failing REQUIRED audit insert would leave a business mutation permanently un-audited — exactly what Task 9B.2 forbids. Enabling panel-wide transactions instead was rejected as a far larger blast radius: it would silently change the commit semantics of every financial flow in the application, none of which was in this phase's scope.

### Impact
Auditing and the mutation are atomic by construction, and nesting is savepoint-safe — a rollback of a caller's surrounding transaction discards the audit row too (both directions proven in `AuditedCrudAtomicityTest`). It also makes duplicate prevention structural rather than defensive: `HasUserTracking`, `ProjectObserver`/`ProjectCostObserver` and Filament lifecycle callbacks cannot manufacture a second event, because none of them can write one. The cost is that every new audited write path must be routed through the service or its Filament concerns explicitly; a model saved directly (seeder, migration, factory, `php artisan tinker`) produces no audit history. Later phases (9B.3+) that audit financial workflows already have their own `DB::transaction()` and should call `AuditLogger` from inside it directly, exactly as 9B.1 designed — they do not need this CRUD service.

---

### Date
2026-07-28 (OMS Task 9B.2 — no audit-suppression switch was built)

### Decision
No `withoutAuditing()`/`disableAuditing()` mechanism was introduced, for seeders, factories, tests, permission synchronisation, or anything else.

### Reason
It is unnecessary given the decision above. Because auditing lives only in `AuditedCrudService` and not in a model hook, code that deliberately writes these tables outside a real user action — `DatabaseSeeder::seedSettings()`, migrations, `UserFactory`, test fixtures, `PermissionSyncService` — simply never produces an audit event. Adding a suppression switch would have created a bypass primitive with no legitimate caller, and a permanent risk that a future HTTP path reaches for it.

### Impact
There is no way to turn auditing off for a real user action, because there is nothing to turn off. If a future phase ever genuinely needs bulk unaudited writes, it must be introduced then, explicitly scoped, `try`/`finally`-restored, and unavailable to HTTP flows — not inherited from this phase.

---

### Date
2026-07-28 (OMS Task 9B.2 — `settings.value` is fail-closed; the `settings.key` column stays redacted in payloads) — **the `settings.key` half of this was SUPERSEDED the same day by the `setting_name` alias decision below; the `settings.value` fail-closed policy still stands**

### Decision
`settings.value` is stored in an audit payload only when neither the setting's key nor its value looks credential-shaped; otherwise it is `[REDACTED]`. The key check reuses `AuditRedactor`'s own already-reviewed rules after normalizing separators (so `mail.password` and `stripe-secret` segment the same way a column name would), and the value check rejects PEM blocks and long whitespace-free opaque token/base64/hex strings. Separately, the literal `key` **column** is left to `AuditRedactor`'s existing `key` segment rule — i.e. it is masked inside `old_values`/`new_values` — and the real setting key is carried in `subject_label` instead, which is not run through the redactor.

### Reason
`settings` is a free-form key/value table: nothing in `app/` reads a fixed key (verified — there is no `Setting::` read anywhere), users create arbitrary keys through `SettingResource`, and `value` is a plain textarea. A per-key allowlist cannot be enumerated honestly, so fail-closed is the only defensible policy — the first time somebody adds an `smtp_password` row, its value must not enter the permanent audit trail. Loosening `AuditRedactor` so a bare `key` field survives redaction was rejected outright: that denylist is a security-reviewed 9B.1 component and weakening it would affect every future phase's payloads, not just settings.

### Impact
Renaming a setting's key is recorded as a real change (`changed_fields` contains `key`) with both sides masked, while the key itself remains legible in `subject_label` — accountability is preserved without a global redaction weakening. Over-redaction of a non-secret setting value is possible and accepted (a 40+ character whitespace-free value is masked even under an innocent key); under-redaction of a secret is not. See `docs/NEXT_STEPS.md` for the one residual gap: on a key rename, the pre-change key is not recoverable from the event.

---

### Date
2026-07-28 (OMS Task 9B.2 — audited-field allowlists, not technical-field denylists)

### Decision
Each audited subject declares a closed allowlist of real business columns in `AuditSubjectRegistry`. Technical columns are never filtered out after the fact.

### Reason
A denylist has to be maintained in step with every future migration; the first column somebody forgets to add lands in the permanent audit trail. An allowlist fails the safe way — a new column is simply not audited until it is deliberately registered. `AuditSubjectRegistry::TECHNICAL_FIELDS` still exists, but only as the assertion target for a test that proves no registration ever lets `created_at`/`updated_at`/`deleted_at`/`created_by`/`updated_by`/`remember_token`/`is_dirty` back in.

### Impact
`updated_by` in particular never appears in `changed_fields`, so `HasUserTracking`'s per-save rewrite adds no noise, and the event's own actor snapshot remains the single source of "who did this". Adding a column to an audited model is a deliberate two-step change: migrate, then register.

---

### Date
2026-07-28 (OMS Task 9B.2 — relationships are stored as FK + one bounded label, and only on the post-change side) — **SUPERSEDED the same day: both sides are now labelled, each from its own foreign key value. See the correction entry below. The FK-scalar-plus-bounded-label rule itself still stands.**

### Decision
A foreign key is always stored as its scalar. One bounded human-readable label may be stored alongside it under a derived key (`project_id` → `project_label`), and only `ProjectCost.project_id` uses this in 9B.2. On the pre-change side of an update, the label is deliberately omitted.

### Reason
Serializing a related model or collection would blow the 8 KiB payload bound, leak unrelated columns and risk N+1 queries. A single label per logical action is cheap and materially improves accountability, since a raw FK is unreadable once the referenced row is renamed or deleted. The old side omits the label because the relationship reachable from the model reflects its **current** foreign key — labelling the previous FK with it would be a plain factual error, and re-querying the old row would add a query per event for marginal value.

### Impact
`old_values` for an FK change carries the scalar alone; `new_values` carries scalar plus label. Any future subject that adds a relation label inherits the same rule automatically from `AuditModelSnapshotter`.

---

### Date
2026-07-28 (OMS Task 9B.2 review correction — `settings.key` is aliased to `setting_name`, superseding the earlier "key stays redacted" decision)

### Decision
`settings.key` is emitted into audit payloads under the safe semantic field name `setting_name`, via a new `AuditSubjectDefinition::$fieldAliases` map. The raw column name `key` is never supplied to `AuditLogger`. This **supersedes** the earlier 9B.2 decision to leave the column redacted and rely on `subject_label` alone. `AuditRedactor` is unchanged: no general `key` safe exception was added and no denylist rule was weakened.

### Reason
The original decision accepted a real accountability loss: renaming a setting's key produced `key: [REDACTED] → [REDACTED]`, so the previous key was unrecoverable from the event and `subject_label` only ever carried the post-change key. The review correctly rejected that trade. The fix has to sit in the Setting subject's own snapshot policy rather than in the global redactor, because a bare `key` field genuinely is secret-shaped for every other subject — this one column is an identifier that merely shares its name with the denylist. Renaming the payload key is the narrowest possible intervention: it changes what a field is called, never what passes redaction.

### Impact
`created`, `updated` and `deleted` Setting events now carry `setting_name`, and `changed_fields` contains `setting_name` when the column changed. `settings.value` is completely unaffected — it still passes `SettingValuePolicy` first and the central `AuditRedactor` after, so a rename of `smtp_password` to `mail.password` records both **names** while both **values** stay `[REDACTED]`. `$fieldAliases` is deliberately scoped as a collision remedy only: a future subject may use it when a legitimate business column's literal name collides with the denylist, never to smuggle a genuinely sensitive column past redaction. `settings.key` is the only alias in the codebase today.

---

### Date
2026-07-28 (OMS Task 9B.2 review correction — relation labels resolve from the foreign key VALUE, superseding the earlier "no label on the old side" decision)

### Decision
A relation-label resolver is handed the foreign key **value**, not the owning model, and both sides of an FK change are labelled from their own value. This **supersedes** the earlier 9B.2 decision to omit the label on the pre-change side. `changed_fields` still lists the semantic business field (`project_id`) and never the label key.

### Reason
The earlier decision was right about the hazard and wrong about the remedy. The hazard is real — `$cost->project` on the pre-change side already reflects the NEW foreign key, so labelling the old id with it would record a factual error — but omitting the label entirely just moved the accountability loss elsewhere: a reassignment recorded an old `project_id` that becomes unreadable the moment that project is renamed or retired, which is exactly when the audit trail matters. Resolving each side from its own id removes the hazard without the loss. `withTrashed()` is used because a cost line is very often reassigned away from a project that is then soft-deleted, and a bare id in that case is worthless.

### Impact
`old_values` and `new_values` each carry `project_id` plus their own `project_label`. Lookups select three columns (`id`, `code`, `name`) and are memoized per logical action in `AuditModelSnapshotter::$labelCache`, so a reassignment costs two bounded primary-key lookups and an update that does not touch `project_id` costs none. No related model or collection is ever serialized, and a soft-deleted previous project still resolves to a readable label. Any future subject that registers a relation label inherits this behavior automatically.

---

### Date
2026-07-28 (Graphify cache hygiene — `graphify-out/cache/**` is local generated cache and must never be tracked)

### Decision
`/graphify-out/cache/` was added to `.gitignore` and the 2157 previously-committed files under `graphify-out/cache/**` were removed from Git tracking with `git rm --cached -r` (index only — every file remains on disk). `.graphifyignore` was deliberately left unchanged. The tracked Graphify outputs remain exactly `graphify-out/graph.json`, `graphify-out/manifest.json`, `graphify-out/GRAPH_REPORT.md`, `graphify-out/cost.json`, the `.graphify_*` marker files, and the dated curated snapshots.

> **SUPERSEDED the same day, in part:** the clause above retaining *"and the dated curated snapshots"* no longer holds — `graphify-out/20*/` is now ignored and untracked too, and `.graphifyignore` did change (it gained `.claude/**`). See the extension decision below. Everything else in this entry stands.

### Reason
The trigger was three stale absolute paths to local database-backup `.sql` files inside `graphify-out/cache/stat-index.json`. A read-only audit of the installed Graphify source showed the problem is structural, not a one-off:

- `cache.py`'s own module docstring is "per-file extraction cache - skip unchanged files on re-run" — the whole directory is a pure speed optimisation.
- The stat index is keyed by `str(p.resolve())`, i.e. an **absolute machine path**, by design. The cache *hash* deliberately uses a path relative to root "so shared caches and CI work correctly", but the index key does not. A file that records absolute local paths as its primary keys can never be portable or safe to commit.
- Measured on the committed copy: **1511 keys, 100% absolute**, of which 695 were under `storage\` — 506 `storage\framework\testing`, 145 `storage\framework\views` (compiled Blade cache), 31 `storage\app\private` (including the 3 backup `.sql` names and `livewire-tmp` upload scratch files), 12 `storage\app\public` (uploaded financial-attachment filenames) — plus `.claude/settings.local.json`. The reported "three paths" were the visible corner of a much larger leak.
- Nothing depends on it being tracked: a cache miss is non-fatal (both `load_cached()` call sites in `extract.py` fall through to a fresh extraction when it returns `None`), `_ensure_stat_index()` starts from `{}` when the file is absent, `cache_dir()` does `mkdir(parents=True, exist_ok=True)`, and `_flush_stat_index()` writes atomically and swallows `OSError`. There is no CI in the repo, and the post-commit hook's only "cache" references are its own `~/.cache/graphify-rebuild.log`.

Redacting the three paths by hand was rejected outright: the file is regenerated on every run, so the leak would return on the next commit. A post-generation scrubber was rejected for the same reason — it would be a permanent moving part guarding a file that has no business being in version control at all. The durable fix is repository policy: the cache stays available locally and is never committed.

### Impact
`graphify update .` (verified, `PYTHONHASHSEED=0`) succeeds with the cache ignored and untracked, rewrites all 2157 local cache files, and produces **zero** untracked entries in `git status`. Graph content is unaffected — every Task 9B.1 foundation node and every Task 9B.2 CRUD-audit class remains indexed, 823 files stay covered, and the tracked outputs contain no `storage/**`, `public/storage/**`, `bootstrap/cache/**`, `node_modules`, `.env*`, `.sql`, or `livewire-tmp` reference. A fresh clone starts with a cold cache and simply re-extracts — slower once, never wrong. The one accepted trade-off is `cache/semantic/**` (14 entries), which Graphify deliberately leaves unversioned because "re-extraction costs LLM calls": a clone elsewhere would re-bill those if an LLM build were run there. Accepted, because this project's documented workflow is AST-only (`graphify update .`, no API cost), the local copies are untouched, and content-hashed semantic entries would be stale for most changed files anyway.

No real backup file was read, moved, modified or deleted at any point.

---

### Date
2026-07-28 (Graphify hygiene extension — dated snapshots untracked, `.claude/**` excluded from indexing; supersedes the snapshot-retention half of the entry above)

### Decision
Only the **canonical root-level** Graphify outputs are tracked. `/graphify-out/20*/` was added to `.gitignore` and all 75 files across the 15 dated snapshot directories were removed from Git tracking with `git rm --cached -r` (index only — all 75 remain on disk). Separately, `.claude/**` was added to `.graphifyignore` so local Claude Code state is never indexed. The repository-root `CLAUDE.md` is deliberately **not** excluded and remains indexed as project documentation.

This explicitly supersedes the earlier decision's retention of "the dated curated snapshots" as tracked output.

### Reason
The dated directories are created by `graphify.export.backup_if_protected()`, a pre-overwrite safety snapshot: it copies a fixed artifact list into `graphify-out/<today>/`, keeps "one folder per day, always the latest pre-overwrite state", never raises (a failure only warns), and is disabled entirely by `GRAPHIFY_NO_BACKUP=1`. It is **write-only** — all four call sites (`watch.py:847`, `__main__.py:3533/4704/4819`) invoke `_backup(out)` and discard the return value, and nothing in Graphify enumerates, reads or restores from a dated folder. Nothing in this repository depends on them either: no application code, no CI (there is none), no hook reference, and the only tracked mention anywhere is prose in `docs/TASKS_LOG.md`.

They were also the largest remaining leak of exactly the content the Task 9B.1 `.graphifyignore` work removed from the canonical outputs: because they are frozen copies taken *before* that work, the 15 directories still carried 2681 `storage/app` references, 85 `livewire-tmp`, 30 `.claude`, and 27 `.sql` across 42 files. Hand-redacting thousands of individual paths across frozen historical artifacts was never a serious option; the durable answer is that generated snapshots do not belong in version control at all.

Git history is a strictly better retention mechanism for the same information: **75 commits** touch `graphify-out/graph.json`, versus 15 daily snapshots, and each is tied to the exact source revision that produced it.

`.claude/**` was excluded because Graphify walked the git-ignored per-machine `.claude/settings.local.json` and recorded its filename in the tracked `manifest.json`. The whole directory is excluded rather than that one file so future local Claude runtime/config files are covered by default. `.claude/settings.json` (tracked project config) is also excluded from **indexing** — it is tool configuration, not project-authored source, so losing it from the graph costs nothing. Neither file's contents were read, and neither's git status changed.

### Impact
Tracked Graphify state is now exactly seven root-level files: `graph.json`, `manifest.json`, `GRAPH_REPORT.md` (the canonical outputs), `cost.json` and `.graphify_labels.json` (curated/cost metadata that is *not* regenerable — `.graphify_labels.json` holds human/skill-assigned community names, and its presence is what makes `backup_if_protected()` treat the graph as protected), plus the two `.graphify_*` markers. A clean deterministic rebuild (`PYTHONHASHSEED=0`, root outputs removed, run twice) produced **byte-identical** SHA-256 for all three canonical outputs, indexing 820 files with zero references to `storage/**`, `public/storage`, `bootstrap/cache`, `livewire-tmp`, uploaded attachment directories, `.sql`, `.claude/`, `settings.local.json`, `.env*`, `node_modules` or composer `vendor/` source — while every Task 9B.1 foundation node and Task 9B.2 CRUD-audit node, and all `app`/`tests`/`database`/`resources`/`config`/`routes` source (plus `CLAUDE.md`), remain indexed.

Local disk is unchanged: 75 snapshot files and 2162 cache files all remain. No real backup file, uploaded attachment, or Claude local-configuration file was read, modified, moved or deleted.

**One finding deliberately left for a separate decision:** `graphify-out/.graphify_python` is tracked and contains a single absolute machine-local interpreter path. It is a convenience probe only — the post-commit hook tries it second of four, and the path cannot exist on any other machine, so the probe simply fails through there. Untracking it (`/graphify-out/.graphify_python` in `.gitignore` + `git rm --cached`) is a zero-risk one-liner, but it was outside the two sources this task scoped, so it is reported rather than applied.
