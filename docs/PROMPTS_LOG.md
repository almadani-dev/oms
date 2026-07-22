# Prompts Log

### Date
2026-07-22 (OMS-wide CRUD redirect standard)

### Prompt
"Implement an OMS-wide CRUD redirect standard for all current Filament resources." Business rule: after a successful full-page Create → the new record's View page; Edit → the updated record's View page; Delete → the resource Index/List page. Keep success notifications working; do not weaken permissions, add fake permissions, bypass View authorization, or change financial/accounting/validation/persistence behavior. Read-only audit first (clean tree/HEAD, CLAUDE.md + reference/memory docs + Graphify), inventory every full-page Create/Edit/View/Delete flow, prefer a centralized low-duplication solution that resolves URLs through the Resource (no hard-coded `/admin/...`), add a View page only where one is safely missing (none were), keep modal/RelationManager CRUD on the parent page, add focused genuine Livewire/HTTP + structural tests, run only targeted suites (new CRUD tests, affected resources, ResourceHttpAuthorizationTest, AuthorizationAcceptanceTest, affected financial), never touch real DB/storage/migrations/`.env`, and report fully before any staging/commit.

### Purpose
Give every full-page resource a consistent, predictable post-operation destination (View after Create/Edit, Index after Delete) without duplicating redirect logic across dozens of pages and without altering any authorization or financial behavior.

### Result
Implemented as documented in the 2026-07-22 `TASKS_LOG.md`/`DECISIONS_LOG.md` entries: one shared concern `App\Filament\Concerns\RedirectsToResourceView` adopted by all 22 editable resources' 44 Create/Edit page classes; page-level Delete left on Filament's correct stock Index redirect; RelationManager/modal CRUD and the four read-only resources unchanged; no View pages needed adding (all 22 editable resources already had a View route). Two focused test files added — `CrudRedirectStandardTest` (genuine Livewire/HTTP) and `CrudRedirectStandardStructureTest` (structural). Targeted run: 402 total, 399 passed, 0 failed, 3 skipped, 0 risky, 1242 assertions. Committed as `standardize CRUD redirects across resources` after approval.

---

### Date
2026-07-22 (test-suite cleanup)

### Prompt
User requested a minimal test-suite cleanup after the final full-suite run (967 total, 963 passed, 1 failed, 3 skipped, 1 risky): remove only the obsolete default `ExampleTest` failure and identify/fix the single risky Users test with the smallest safe change — do not change application routes or add a fake `/` route to satisfy the default Laravel test, do not modify production behavior, inspect only the two test targets and directly required helpers, run targeted tests only, and hold before staging/commit. After approval: update only the CLAUDE.md-required docs with a minimal factual entry (recording only actual results, not predicted final counts), run `graphify update .`, stage only the ExampleTest deletion + RoleAssignmentSafetyTest fix + docs + stable curated `graphify-out/**` (never cache/temp/source/routes/migrations/db/storage/.env/creds/backups/vendor), commit as `clean obsolete test scaffolding`, and report the hash + post-commit hook output; no push, no full suite yet, no new task.

### Purpose
Bring the test suite to a clean state at the close of Attachment Security Task 6 by removing a stock Laravel scaffolding test that can never pass in a Filament-only app (without distorting production routing to satisfy it) and by giving the one risky Users test a real assertion — so future runs are not muddied by a known-irrelevant failure and a "no assertions" risky flag.

### Result
Deleted `tests/Feature/ExampleTest.php`; added `assertArrayHasKey('roles', $e->errors())` to `RoleAssignmentSafetyTest::test_submitted_super_admin_role_is_rejected_server_side`. Targeted `tests/Feature/Users/`: 64 passed, 0 failed, 0 skipped, 0 risky, 134 assertions. No production code, route, migration, database, or storage file changed. Committed as `clean obsolete test scaffolding`.

---

### Date
2026-07-22 (OMS Task 6D — secure financial attachment registry)

### Prompt
User requested, across two linked turns: (1) "OMS Task 6D: SECURE FINANCIAL ATTACHMENT REGISTRY" — rebuild and re-enable the standalone `AttachmentResource` as a secure read-only registry (`النظام` → `سجل المرفقات`, singular `مرفق`) that lets authorized users browse/search/filter/view attachment metadata, preview images inline, and securely open/download files, while never allowing upload/create/edit/delete/restore/force-delete/bulk mutation or exposing a raw path/disk/URL. A detailed spec: opening requires `attachments.view_any`; every listed/viewed row additionally requires `attachments.view` plus the matching parent-module `.view` (an explicit five-type → permission map: ProjectCostReceipt→project_cost_receipts.view, ProjectCostBudget→project_cost_budgets_payments.view, ProjectCostBudgetsPayment→execution_payments.view, GeneralExpense→general_expenses.view, GeneralExchange→general_exchanges.view); a normal user sees only their allowed types, Super Admin sees all five, a direct View URL 403s without the parent permission, preview/download stay behind `attachments.show`, no global all-attachments permission, and authorization is re-enforced on the View page (not just by hiding rows) via a focused service centralizing the type→permission mapping. Only the five approved financial types listed; unsupported Project/Transaction/Partner rows excluded and reported separately, never deleted/modified; soft-deleted rows available via an optional `المحذوفة` filter for audit metadata with no active preview/download. A full table design (Arabic columns: file name, operation type, operation/transaction number resolved from the real parent field, project or `—`, operation date, amount+currency from approved denormalized fields never mixing currencies, MIME, human size, storage status `خاص`/`عام انتقالي`/safe-unknown, uploaded-by, created date, status `فعال`/`محذوف منطقيًا`/`ملف مفقود`), read-only row actions (`عرض التفاصيل`/`عرض المرفق`/`تنزيل`, hidden for missing/soft-deleted/invalid-disk), search/filters (with a file-availability filter only if not expensive — otherwise omit and report why), N+1-free polymorphic eager loading via morphTo/morphWith, a rebuilt secure View page, preserved-and-tested structural read-only guarantees, and 45 numbered required test cases; targeted tests only (new registry tests + `tests/Feature/Attachments/` + `ResourceHttpAuthorizationTest` + `AuthorizationAcceptanceTest`); explicitly forbidden: weakening the Task 6A–6C private-storage/parent-policy protections, creating/modifying real Attachment rows or files, running migrations or the full suite, touching real storage/public/symlink, updating docs or running Graphify, staging, or committing until reviewed. (2) After the implementation report: "OMS Task 6D implementation report and the current amount/currency choice are approved" — keep `final_amount`+`disbursementCurrency` for the two multi-currency operations; do not change registry authorization, table design, filters, eager loading, or read-only guarantees; update only the five CLAUDE.md-required docs; run `graphify update .`, validate the generated JSON, stage only the approved implementation/tests/docs/stable curated `graphify-out/**` (never `graphify-out/cache/**`, temporary files, vendor, `.env`, backups, storage, `public/storage`, or credentials); show the staged diff and no-collateral confirmations; commit as `add secure financial attachment registry`; report the commit hash and post-commit Graphify hook output separately; no push, no new task.

### Purpose
Complete the attachment-security phase (6A foundation → 6B live cutover → 6C orphan cleanup → 6D) by giving authorized users a single, safe place to find and open financial attachments — without reopening any of the guarantees the earlier phases established: files still stream only through the authenticated `attachments.show` route under the parent record's own Policy, the registry itself scopes and re-checks per parent module so it can never surface metadata for a record the actor could not otherwise view, and the resource stays structurally incapable of mutating or uploading anything even for a Super Admin.

### Result
Implemented exactly as scoped — see `docs/TASKS_LOG.md` (2026-07-22 "OMS Task 6D" entry) for the full file list, design, and counts, and `docs/DECISIONS_LOG.md` (2026-07-22 "Task 6D" entry) for the multi-currency-amount, two-layer-authorization, and omitted-file-availability-filter reasoning. New `FinancialAttachmentRegistry` service + rebuilt Resource/Table/View page; `AttachmentForm` (the only `FileUpload`) deleted; three existing tests updated only where the navigation/supported-type behavior genuinely changed. 42 new registry tests (101 assertions) plus the full targeted set 274 tests → 271 passed, 0 failed, 3 skipped, 0 risky, 856 assertions. All tests ran on SQLite `:memory:` with `Storage::fake` — no real DB record or attachment file changed; the real `attachments` table (read-only inspection) holds 3 rows, all supported `GeneralExpense` on the private disk, 0 unsupported, untouched. Committed as `add secure financial attachment registry`.

---

### Date
2026-07-22 (OMS Task 6C — obsolete orphan public-file cleanup)

### Prompt
User requested "OMS Task 6C: REMOVE OBSOLETE ORPHANED PUBLIC ATTACHMENTS" — confirming the old financial records were intentionally deleted and the six orphaned public files under `storage/app/public/{execution-payments,payments}` are no longer needed. Explicit constraints: do not quarantine/migrate/preserve/attach the six files; do not delete the public disk, the `public/storage` symlink, or unrelated public files; do not run the full test suite; do not stage/commit until review. A detailed pre-flight (confirm clean tree; read-only verify the `attachments` table has no active or trashed rows referencing the six files; enumerate the exact six files and record each path/size/SHA-256 before deletion; confirm no unexpected files beyond the six and harmless control files), a scoped deletion (only the six verified orphans; never the disk root, symlink, `.gitignore`, private attachments, or any DB row; keep empty directories), an 8-point post-deletion verification, targeted-tests-only (`tests/Feature/Attachments/`, `FinancialAttachmentCutoverTest`, `FinancialAttachmentViewFlowTest`), a CLAUDE.md documentation update, and a final pre-commit report (deleted paths, hashes, DB-reference check, URL results, test counts, `git status --short`, `git diff --stat`, and a no-collateral-change confirmation) — with an explicit stop-and-report instruction if any DB row referenced the files or any unexpected file was present.

### Purpose
Complete the attachment-security cleanup by removing the last public exposure surface (the six pre-existing orphan files with no DB row) after Task 6A built the private-serving foundation and Task 6B cut the five live financial workflows over to it — closing out the phase so no legacy public attachment content and no quarantine/migration debt remains.

### Result
Stop-and-report: the six files were found **already absent** from disk (only `.gitignore` remained under `storage/app/public`; neither orphan directory existed), so nothing was deleted and no pre-deletion hashes could be recorded (not fabricated). MySQL was initially down; after the user brought it up, read-only verification confirmed the `attachments` table is empty (0 rows referencing the paths active-or-trashed, 0 public/attachments-disk rows, 0 total). Old public URLs return HTTP 403 (serve no file; symlink intact — `/storage/.gitignore` → 200). Code posture from Task 6B re-confirmed (5 Forms → private `attachments` disk, 5 View pages → `attachments.show`, no `Storage::url()`/raw `/storage/`). Targeted tests 141/141 (400 assertions). Concluded no quarantine/legacy-migration tooling is necessary (empty table, private-only path). Documentation updated across all five CLAUDE.md-required docs; not staged, not committed — awaiting review. See `docs/TASKS_LOG.md` (2026-07-22 "OMS Task 6C" entry) and `docs/DECISIONS_LOG.md` (2026-07-22 entry) for full detail.

---

### Date
2026-07-21 (OMS Task 6B — financial Resource attachment cutover)

### Prompt
User requested, across two linked turns: (1) "OMS Task 6B: PRIVATE ATTACHMENT RESOURCE CUTOVER" — convert all five financial Resources' Create/Edit/View pages from the public-disk upload/display pattern to the private `attachments` infrastructure built in Task 6A, with a detailed spec: inspect the exact current attachment code in all 5 Forms/Create/Edit/View pages first and return a short implementation map before proceeding; build a central `AttachmentUploadService` that accepts only the saved parent model, temp path, an approved directory/prefix, transaction date, and amount (never a disk/directory/filename from request data), validates the temp path with the same principles as `AttachmentStorageService`, creates the Attachment row before generating the id-dependent final filename (never `MAX+1`), preserves the exact existing directory/prefix/filename-normalization scheme; implement the smallest safe filesystem-failure handling (never soft-delete the old attachment before a new one succeeds, clean up only a newly-created file/row on failure, never touch an old physical file); redesign the Edit form into three explicit concepts (secure current-attachment preview via the `attachments.show` route only, a clean non-prefilled replacement `FileUpload`, an explicit `remove_current_attachment` control) with defined precedence rules for keep/replace/remove/replace+remove; build one reusable secure preview Blade/schema component used on all 5 View and Edit pages (inline image, secure view/download links, safe non-image card, empty state, RTL, both themes, no raw path/URL); keep supporting transitional `disk = public` legacy rows through the existing protected controller; add parameterized tests under `tests/Feature/Attachments/` covering 41 specific numbered scenarios across create/edit/view/authorization/regression categories; run only a specified targeted test subset; explicitly forbidden: migrating legacy files, touching the six orphan files, running the new migration against the real database, creating/modifying real Attachment records, running the full suite, staging, committing, updating docs, or running Graphify until reviewed. (2) After reviewing the implementation report: "OMS Task 6B is approved. Perform the controlled local rollout, documentation update, Graphify refresh and commit" — verify the working tree contains only the approved Task 6B changes; create a full timestamped `mysqldump` backup of the real local MySQL database first (never printing the password, refusing to overwrite an existing backup, reporting only path/size/SHA-256); take a read-only pre-migration snapshot (row count, columns, migration status) plus record the six orphan files' paths/sizes/hashes without touching them; run only the one targeted `2026_07_21_000001_add_disk_to_attachments_table` migration (stop-and-report, no auto-restore, on any failure); verify post-migration state read-only (column type/default, row count still zero, orphan files byte-identical, no directory created under the real private disk, a focused Attachment model read check); run only the specified targeted test subset once; update only the docs `CLAUDE.md` requires with a specific list of facts to record and set `NEXT_STEPS` to Task 6C; run `graphify update .`; stage only the approved Task 6B code/tests/docs/graphify output (explicitly never the backup, `.env`, credentials, real storage files, or orphan files); commit as `move financial attachments to private storage`; report the commit hash and final state without re-running tests or starting Task 6C.

### Purpose
Close the second half of the vulnerability Task 6A's audit found and deliberately deferred: with the private-serving foundation already built, verified, and committed, the five live financial workflows themselves were still writing new uploads to the unauthenticated public disk and building raw public URLs on every View page. Task 6B makes the private disk the actual, live path for all new financial attachments — while keeping the change safely reversible (full backup before the one real schema change), keeping every legacy public-disk row still servable through the same secure route during the transition, and explicitly leaving the six pre-existing orphan files and any real legacy-file migration to a separate, later-approved Task 6C rather than acting on them opportunistically mid-task.

### Result
Implemented and verified exactly as scoped across both turns — see `docs/TASKS_LOG.md` (2026-07-21 "OMS Task 6B" entry) for the full file list, exact commands, and test counts, and `docs/DECISIONS_LOG.md` (three 2026-07-21 "Task 6B" entries) for the row-created-before-final-filename, replace-before-soft-delete, and shared-preview-component reasoning. One implementation-phase environment issue was found and fixed before the backup step could succeed (not scoped by the user, discovered during execution): the initial `mysqldump` invocation via PHP's `proc_open()` failed with a Windows Winsock socket-creation error because passing a partial `$_ENV`-derived array as the child process's environment dropped `SystemRoot`/`PATH` and other variables Winsock initialization needs on Windows — fixed by using `putenv('MYSQL_PWD=...')` in the parent process and passing `null` (inherit the full parent environment) to `proc_open()` instead. 77 new tests across 3 new files, all passing; targeted pre-commit suite (Attachments + the 5 financial Resource directories + `ResourceHttpAuthorizationTest` + `AuthorizationAcceptanceTest`) 300/303 (3 pre-existing unrelated skips, 0 failures). The real local MySQL database was backed up (150,837 bytes, SHA-256 recorded), the targeted migration ran successfully, and post-migration read-only verification confirmed the `disk` column matches the migration exactly, the Attachment row count remained 0, and all six orphan files were byte-for-byte unchanged before and after. No real Attachment record was ever created; no real attachment file was ever uploaded, moved, copied, renamed, or deleted. Committed as `move financial attachments to private storage`.

---

### Date
2026-07-21 (OMS Task 6A — private attachment security foundation)

### Prompt
User requested, across three linked turns: (1) a read-only "OMS Task 6" attachment security audit — covering the `Attachment` model/migration/relationships, every `FileUpload` field and attachment-handling code in the 5 financial workflows, storage disks/directories/URL generation, every display/preview/download/delete code path, existing public-storage files vs. their DB `file_path` format, whether attachments are reachable without authentication, existing permissions/policies, soft-delete behavior for both the `Attachment` record and its physical file, export/report exposure, and existing test coverage — followed by a smallest-safe-change implementation plan (architecture/risks, affected files, permissions, private disk design, secure controller/route design, legacy-migration strategy with backup/rollback, per-Resource changes, test plan, migration-vs-Artisan-command decision, risk/phasing), explicitly not to be implemented yet; (2) "OMS Task 6A": implement only the approved foundation (private disk, `disk` column migration, storage resolver/service, secure authenticated controller+route, parent-record authorization reusing existing policies, safe inline/download serving, focused tests) — explicitly excluding the five-financial-Resource cutover and the real legacy-file migration, with mandatory audit corrections (inspect the standalone `AttachmentResource` and real DB rather than assume it out of scope; do not assume a private-disk `FileUpload` can display existing files without checking Filament's actual API; treat legacy public files as unprotected until their public copies are actually removed later, with a full backup/verify/quarantine plan; never let a database `disk` value select an arbitrary Laravel disk; never trust the stored `file_type` blindly, with specific private/no-store/nosniff headers required); (3) five explicit security corrections before approval — harden `AttachmentResource` to structurally read-only without deleting it or its permissions; add focused stored-file-path validation rejecting absolute/UNC/drive-letter/traversal/null-byte/empty paths; sanitize response filenames against directory segments and CR/LF/control-character header injection with a safe deterministic fallback; fix the controller so a missing-or-soft-deleted parent returns 404 without ever calling `Gate::authorize()` on it; and document (without implementing) that the 6 pre-existing orphan public files need a later quarantine-manifest-and-manual-review migration step. Throughout: no real attachment file may ever be moved/copied/renamed/deleted/quarantined, no migration may run against the real database, no real `Attachment` record may be mutated, and nothing may be staged or committed until explicitly approved at each checkpoint. A final fourth turn approved everything and requested: an explicit pre-commit security-verification checklist (15 numbered points) re-confirming every property of the finished design, documentation updates limited to what `CLAUDE.md`'s Project Memory Rules require, a `graphify update .` run, a final full re-verification pass, staging scoped exactly to the approved Task 6A changes (explicitly excluding any financial-Resource cutover and any sensitive/real-attachment file), and the actual commit.

### Purpose
Close the specific vulnerability the audit surfaced — every financial attachment (receipt/payment/expense proof image) was reachable by a guessable public URL with zero authentication or authorization — while keeping the change reversible and minimally invasive: build and fully verify the private-serving foundation first, prove it secure against a Super Admin and against crafted/traversal/injection input, and deliberately defer the higher-blast-radius steps (cutting the 5 live financial forms over to the new disk, and migrating/deleting any real legacy file) to explicitly separate, later-approved phases.

### Result
Implemented and verified exactly as scoped across all three turns — see `docs/TASKS_LOG.md` (2026-07-21 "OMS Task 6A" entry) for the full file list, exact commands, and test counts, and `docs/DECISIONS_LOG.md` (three 2026-07-21 "Task 6A" entries) for the disk-allowlist, `AttachmentResource`-hardening, and soft-deleted-parent-before-authorize reasoning. One genuine platform-dependent defect was found and fixed during the pre-commit verification pass itself (not by the user): PHP's native `basename()` only treats `\` as a directory separator on Windows, so the original filename-sanitization code would have silently leaked Windows-style directory segments into `Content-Disposition` on a Linux server — replaced with a custom OS-independent path-segment splitter and a dedicated regression test before commit. 64 new tests across 4 new files, plus 2 pre-existing, unrelated Permissions test files updated to reflect `AttachmentResource`'s new (approved) hardened behavior. Full suite 844/848 passed, matching the same pre-existing unrelated `ExampleTest` failure and risky test recorded in every prior entry — neither touched. No real attachment file was ever moved, copied, renamed, deleted, or quarantined; the real `attachments` database table remained at 0 rows with no `disk` column throughout (the new migration never ran against it); the five financial Resources' upload/display code was not touched. Committed as `add private attachment security foundation`.

---

### Date
2026-07-21 (final OMS authorization acceptance testing — Tasks 1–5)

### Prompt
User requested the final OMS authorization acceptance test pass across Permissions Tasks 1–5, explicitly as a testing task: audit existing coverage first (no duplicate tests), add only the smallest focused acceptance test file(s) for genuine gaps, use the real `PermissionSyncService` for test data (create one active user per system role, assign exactly one role each), verify system role integrity (five roles, exactly 155 permissions, exact default sets, idempotent sync), Filament panel access, real rendered sidebar navigation per role, direct URL access (200/403/404) for named representative resources/reports/system pages, create/update/delete authorization including structural protections that survive Super Admin, report access, export authorization (including no-permanent-file-storage), Users/Roles/Permissions management (including the `users.assign_roles` requirement), and cross-module negative leakage tests — run in a specified order, stop and report (not fix) any genuine defect found, and produce a detailed final report. No production authorization redesign, no financial-logic/Resource/Policy/`PermissionRegistry` changes merely to pass a test, no staging/commit, no real database writes.

### Purpose
Close out the OMS Permissions phase (Tasks 1→2A→2B→3→4→5, all previously implemented but never jointly acceptance-tested) with one final, independent pass proving the whole authorization surface behaves as designed end-to-end, before the phase is considered complete and safe to build further features (e.g. attachment security) on top of.

### Result
Audited ~80 existing test methods across `tests/Feature/{Permissions,Roles,Users,Reports}` and found coverage was strong; added exactly one new file, `tests/Feature/Permissions/AuthorizationAcceptanceTest.php` (22 tests, 200 assertions, all passing), covering only the confirmed gaps (absolute permission count, real per-role rendered navigation, representative view/edit HTTP routes, 404-not-403 for nonexistent create routes, actually-assigned-role mutation proofs, generic cross-module negative pairs, crafted Users form Super-Admin-assignment attempt). **No authorization defect was found.** The acceptance spec's `users.assign_roles` permission does not exist in `PermissionRegistry` — confirmed as the approved design (role assignment via `users.create`/`users.update` + privilege-subset validation, `users.assign_super_admin` separately gating Super Admin assignment), not a gap; no registry change made. Full suite 782/785, matching the pre-existing unrelated `ExampleTest` failure and risky Task 3 test recorded in every prior task's log — neither touched. User approved the result and requested documentation-only updates plus staging/commit of the new test file and generated `graphify-out/**` output. See `docs/TASKS_LOG.md` (2026-07-21 entry) for exact commands/counts and `docs/DECISIONS_LOG.md` for the `users.assign_roles` non-defect reasoning.

---

### Date
2026-07-21 (OMS Permissions Task 5 — read-only permission management)

### Prompt
User requested implementation of "OMS Permissions Task 5": a structurally read-only Filament `PermissionResource` (`النظام` → `الصلاحيات`) letting authorized users list/search/filter (by module and guard)/view every permission — Arabic label, technical name, associated roles — while never allowing manual create/edit/delete/force-delete/bulk actions or direct role-permission assignment from this page, including for a real Super Admin. Detailed constraints: add one new permission `permissions.sync` (`مزامنة الصلاحيات`, group `النظام والصلاحيات`), treat it as protected (`permissions.*` prefix), default it to Super Admin only, update registry/sync tests for the new total count; use `Spatie\Permission\Models\Permission` (no custom model unless proven necessary), a dedicated `PermissionPolicy` explicitly registered in `AppServiceProvider` (model lives outside `App\Models`), and an explicit note that Policy denial alone is insufficient because `Gate::before` bypasses Policies for Super Admin — `PermissionResource` must structurally disable every mutation route/action regardless; register only list/view routes, no Create/Edit pages, hard-override all `canX()` mutation methods to `false`; add a confirmation-gated header sync action calling the existing `PermissionSyncService` through a new small `PermissionManagementService` (never an Artisan shell-out), requiring authenticated actor + exact `Super Admin` role + `permissions.sync` ability all together, rejecting a non-Super-Admin even if manually granted `permissions.sync`, proven via a genuinely crafted request rather than assuming the hidden button is sufficient, and leaving all authorization/user tables unchanged on rejection; show an Arabic completion notification with the service's actual result keys; keep custom/legacy permissions visible and untouched; verify RoleResource compatibility with zero redesign. 42 specific test scenarios requested across policy/resource-access, structural read-only protection, display behavior, synchronization-action authorization, and regression categories, run in a specified verification order. No migrations, no financial-logic/report/UserResource/RoleResource-behavior changes, no real database writes, no staging/commit until reviewed — with an explicit final report format (files changed, permission count, policy map, structural protections, test counts, git status/diff --stat).

### Purpose
Complete the last remaining item of the original OMS permissions-phase plan (Tasks 1 → 2A → 2B → 3 → 4 → 5): give authorized operators visibility into the full permission catalog (technical names, Arabic labels, module grouping, which roles use each permission) without opening any path to accidentally or maliciously mutate permission data outside the code-controlled `PermissionRegistry` → `PermissionSyncService` pipeline, and let an authorized Super Admin safely re-run synchronization from the UI after a registry change instead of needing shell/SSH access to the server.

### Result
Implemented exactly as scoped — see `docs/TASKS_LOG.md` (2026-07-21 "Task 5" entry) for the full file list, commands, and counts, and `docs/DECISIONS_LOG.md` (three 2026-07-21 entries) for the exact-Super-Admin-role-check, structural-mutation-override, and new-permission-group reasoning. `PermissionRegistry` grew by exactly one permission (154 → 155); `RoleManagementService`'s pre-existing `permissions.`-prefix protection covered the new permission automatically, requiring zero changes to `RoleResource`/`RoleManagementService`/`RoleForm`. 39 new tests across 5 new files plus 1 new test each in 2 existing files, run in the requested order; full suite 760/763 excluding the same 3 pre-existing, unrelated gaps recorded in every prior task's log (`ExampleTest`, 2 `ResourceHttpAuthorizationTest` read-only-resource skips, 1 `tests/Feature/Users` risky flag — confirmed via `git status --short` to be outside every file this task touched). Not staged, not committed — diff and full verification shown for approval first.

---

### Date
2026-07-19 (OMS Permissions Task 2A — Filament Resource/RelationManager authorization)

### Prompt
User requested implementation of "OMS Permissions Task 2A": wire the Task 1 permission foundation into authorization for all existing Filament Resources and RelationManagers (report/export authorization explicitly deferred to Task 2B). Detailed constraints: use native Laravel Policies with Spatie permission checks, prefer a small reusable base policy/trait over duplicating CRUD logic per policy, verify Laravel/Filament policy auto-discovery via tests rather than assuming it, follow the standard `<module>.view_any/view/create/update/delete/restore` permission-name convention, keep `deleteAny`/`restoreAny` following `delete`/`restore`, never introduce a normal `force_delete` permission (Super-Admin-only via the existing `Gate::before` bypass), preserve `TransactionResource`/`TransactionLineResource`'s existing hardcoded read-only `canX()` overrides unchanged, leave `UserResource`/`UserPolicy` untouched (Task 3's job), protect a named list of 23 resources across Financial/Projects/Partners/Settings-reference-data/System groups, protect the 5 existing RelationManagers using the *related* model's permissions (explicitly: viewing a Project must not grant `project_costs.create/update/delete`), keep the existing `withoutGlobalScopes([SoftDeletingScope::class])` route-binding calls but enforce soft-delete behavior at the authorization layer (trashed record not normally editable/re-deletable/openable, restore requires its own permission and only applies to a trashed record, force-delete stays Super-Admin-only), use genuine HTTP tests under normal `APP_ENV=testing` (no `app.env=local` workaround) for direct-URL protection, use data-driven tests rather than one duplicated class per resource, add a dedicated policy-discovery-verification test for the two intentionally swapped model↔permission-prefix pairs (`ProjectCostBudget`↔`project_cost_budgets_payments`, `ProjectCostBudgetsPayment`↔`execution_payments`, confirmed during the Task 2 discovery audit), and update only the project-memory docs required by CLAUDE.md afterward. No migrations, no financial-logic changes, no staging/commit until reviewed.

### Purpose
Close the gap the Task 2 discovery audit found: zero authorization was wired to any of these 23 Filament Resources despite `PermissionRegistry` already defining and assigning every relevant `<module>.*` permission — meaning any authenticated non-Super-Admin user could currently view/edit/delete/create across every financial and reference-data resource in the panel, regardless of role. Doing this as Task 2A (Resources/RelationManagers only) keeps the diff reviewable and defers the separate, differently-shaped problem of report-page/export authorization to Task 2B rather than conflating the two.

### Result
Implemented as described in `docs/TASKS_LOG.md` (2026-07-19 "OMS Permissions Task 2A" entry) and `docs/DECISIONS_LOG.md` (four 2026-07-19 "OMS Permissions Task 2A" entries: Policy-only architecture with no Resource-level `canX()` duplication, the uniform soft-delete rule with its read-only-audit exception, the deliberately-swapped permission-prefix pair, and the navigation-visibility test substitution). One reusable `App\Policies\Concerns\AuthorizesCrud` trait + 23 Policy classes were added — zero Resource, RelationManager, Page, Form, Table, or financial-service file was modified, confirmed by `git status --short`/`git diff --stat` showing every change as a new untracked file. Filament's RelationManager-authorizes-against-the-related-model behavior was verified empirically (read from vendor source, then proven via `Livewire::test()` against all 5 existing RelationManagers) rather than assumed, per instructions. One test category (cold/post-request static `canViewAny()`/`shouldRegisterNavigation()` calls) proved environment-flaky for reasons unrelated to permission correctness and was replaced with an equally rigorous but more reliable substitute — documented as its own decision rather than silently worked around. 285 new test assertions across 5 new test files (2 unit-level, 3 feature-level with real HTTP/Livewire round trips) all pass; the entire `tests/Feature/Permissions` namespace (new + pre-existing) is 273/275 (2 expected skips); full suite is 513/516 (the same single pre-existing unrelated `ExampleTest` failure recorded in every prior task's log since 2026-07-15), with all 5 financial-workflow test namespaces passing unmodified. Not staged, not committed — diff and full verification shown for approval first.

---

### Date
2026-07-19 (correction 3 — no hardcoded bootstrap credential + soft-delete-safe seeding)

### Prompt
Final focused security correction before Task 1 could be committed: user flagged that `DatabaseSeeder` still contained a hardcoded bootstrap administrator email/password (`superadmin@oms.com` / `password123`) — explicitly unacceptable in source control for a permissions/security task — plus a real soft-delete edge case (unique `users.email` constraint could cause a duplicate-email failure if the bootstrap email already existed as a soft-deleted user). Required: introduce configuration-backed bootstrap admin settings (email/name/password) reading environment variables through config rather than directly, with no real credential ever in source; when an active Super Admin already exists, skip entirely and touch nothing; when none exists, require the configured email+password (fail clearly with a `RuntimeException`, no password exposed, no partial user created on failure) and handle a `withTrashed()` lookup by that email across three cases — no row (create), active row (promote only, no credential overwrite), soft-deleted row (restore + reset password, never a duplicate). 12 specific tests requested, synthetic test credentials only, no migrations, no redesign, no staging/commit.

### Purpose
Eliminate a real credential-in-source-control risk (a fixed password checked into git is a leak the moment the repository is cloned anywhere, independent of whether it's ever run) and make the seeder's bootstrap logic correct against the database's actual unique-email constraint, rather than something that would only work by coincidence on a clean database.

### Result
Implemented exactly as scoped — see `docs/TASKS_LOG.md` (2026-07-19 "correction 3" entry) for the full file list and verification, and `docs/DECISIONS_LOG.md` (three 2026-07-19 "correction 3" entries) for the config design, the `withTrashed()` three-branch handling, and the reasoning behind resetting the password only on restore (never on an already-active existing user). New `config/oms.php` holds `bootstrap_admin.{email,name,password}`, all `env()`-sourced with no default password; `DatabaseSeeder::seedSuperAdmin()` reads only that config, never `env()` directly, and throws a `RuntimeException` (env var names only, never a value) if required config is missing when no Super Admin exists yet. `.env.example` documents the three new optional variables as blank placeholders. `DatabaseSeederSuperAdminTest` fully rewritten (13 tests, up from 8) around a synthetic test-only fixture, covering every required case including a dedicated "exception message never contains the password" test. `grep -rn "password123|superadmin@oms.com" app/ database/seeders/ config/` confirms zero matches — no hardcoded credential remains anywhere. All permissions tests together: 38/38 passed, 466 assertions; full suite: 247/248 (the same single pre-existing unrelated `ExampleTest` failure recorded since 2026-07-15). Post-run tinker re-check confirmed the real local database remained completely untouched. Not staged, not committed — diff and full verification shown for approval first.

---

### Date
2026-07-19 (correction 2 — FilamentUser panel access + duplicate-Super-Admin-safe seeder)

### Prompt
Following the prior correction (real admin account identification + completed real HTTP tests), user identified two remaining focused issues before Task 1 could be committed. (1) The real HTTP tests required a test-only `config(['app.env' => 'local'])`, which masks a genuine production risk: Filament rejects every user in any non-`local` environment unless the User model implements `Filament\Models\Contracts\FilamentUser`. Required implementing `canAccessPanel(Panel $panel): bool` on `User` — entry-only (no resource permission checks, no Super Admin grant, no hardcoded email/ID/role, leaving all real authorization to `Gate::before`/Policies/Resources/Pages), equivalent to `! $this->trashed()` — and removing the workaround, with the real HTTP tests passing under the suite's normal `APP_ENV=testing`. (2) `DatabaseSeeder` still targets `superadmin@oms.com` while the real admin is `oms@oms.com`; required the smallest safe fix — skip the bootstrap-user creation entirely when any non-soft-deleted user already holds the exact `Super Admin` role, never modify an existing administrator, preserve clean-install bootstrap behavior otherwise (same hashing, no new credentials), and keep `oms:sync-permissions` creating zero users while still warning on zero Super Admins. Ten specific tests requested, no migrations, no redesign, no staging/commit.

### Purpose
Close a real production authorization gap (silent panel lockout in any non-local environment) rather than paper over it with a test-only environment override, and make the seeder genuinely safe to run against a database that already has a real administrator under a different email than its hardcoded bootstrap default — without ever risking a duplicate admin account or touching the real one.

### Result
Implemented exactly as scoped — see `docs/TASKS_LOG.md` (2026-07-19 "correction 2" entry) for the full file list and verification, and `docs/DECISIONS_LOG.md` (two 2026-07-19 "correction 2" entries) for the reasoning behind `canAccessPanel()`'s minimal scope and the role-based (not email-based) duplicate-admin check. `User` now implements `FilamentUser`; the `app.env` workaround is gone from `UserResourceLockdownTest`, whose 4 real-HTTP tests (403/200 on list/create/view/edit) pass unmodified under normal `APP_ENV=testing`. `DatabaseSeeder::seedSuperAdmin()` now skips bootstrap creation whenever any active user already holds `Super Admin`, verified by 8 new tests including an explicit "existing admin is not modified" assertion (email/name/password hash/`updated_at`/role set all unchanged) and a soft-deleted-doesn't-block-recovery case. 12 new tests total; all permissions-related tests together: 33/33 passed, 444 assertions; full suite: 242/243 (the same single pre-existing unrelated `ExampleTest` failure recorded since 2026-07-15). Post-run tinker re-check confirmed the real local database remained completely untouched. Not staged, not committed — diff and full verification shown for approval first.

---

### Date
2026-07-19 (correction — real admin account + completed real HTTP authorization tests)

### Prompt
Following the Task 1 permissions-foundation pass, user corrected that the real administrator account is `oms@oms.com`, not `superadmin@oms.com` (the email the prior pass had searched for when reporting no seeded Super Admin user existed) — explicitly noting that negative result must not be treated as proof no Super Admin exists. Requested: a read-only re-check (existence, not-soft-deleted, exact `Super Admin` role, `Gate::before` recognition, `UserResource` access) of `oms@oms.com` against the real local DB with zero writes and no new admin account created; inspecting and reporting `DatabaseSeeder`'s current admin-seeding behavior without changing it unless clearly required (report the proposed change first if so); keeping/adding a useful `oms:sync-permissions` warning for zero Super-Admin-role users while never creating a user from that command; and — explicitly — completing the real HTTP-level 403/200 authorization tests for `UserResource` that the prior pass had substituted with direct `canX()` assertions after hitting an environment issue. No staging or committing.

### Purpose
Correct a factual error before it could propagate (searching the wrong email and concluding "no Super Admin exists"), get a genuine end-to-end proof that a normal user is HTTP-403'd and the real admin isn't — not just a proxy assertion at the authorization-method level — and get an explicit, honest report on a real seeder/admin-email mismatch rather than silently working around or ignoring it.

### Result
Read-only tinker check (zero writes — only `where()`/`hasRole()`/`Gate::allows()`/`canViewAny()`/`canCreate()`, plus in-memory-only `Auth::setUser()`/`forgetUser()`) confirmed all 4 requested facts for `oms@oms.com`. Found and reported the `DatabaseSeeder` mismatch (`seedSuperAdmin()` hardcodes `superadmin@oms.com`) without changing it, with a proposed fix left pending approval — see `docs/DECISIONS_LOG.md`. While completing the real HTTP tests, found the actual root cause of the earlier 403s was not a session/`actingAs()` problem as first assumed, but `Filament\Http\Middleware\Authenticate`'s hardcoded rule that a user model without `FilamentUser` can only access the panel when `config('app.env') === 'local'` — true even for an authenticated Super Admin under `APP_ENV=testing`. Fixed with a test-only `config(['app.env' => 'local'])`, no production file touched. `UserResourceLockdownTest` now performs genuine `$this->get()` requests (403 for a normal user on list/create/view/edit, 200 for Super Admin on all four). Added the requested sync-command warning as a read-only headcount (`super_admin_user_count`), verified by a test asserting the command creates zero users. 21/21 permission-foundation tests pass (2 new), full suite 230/231 (same single pre-existing unrelated `ExampleTest` failure). Post-run tinker re-check confirmed the real local DB was untouched (user/permission/role counts identical to before). Not staged, not committed — see `docs/TASKS_LOG.md` (2026-07-19 correction entry) for exact verification output.

---

### Date
2026-07-19 (OMS permissions foundation — Task 1)

### Prompt
Following the same-day read-only roles/permissions audit (see the entry below), user requested implementing "Task 1" only: the permissions foundation. Explicit scope: `Gate::before` Super Admin bypass (role name exact match, `null` for everyone else, never bypass guests, no recursion via `can()`), a central `PermissionRegistry` reusable by sync/seeding/labels/tests using `module.action` naming (with the exact confirmed-inventory module list, operation sets per module category, and 6 report pages), an idempotent `oms:sync-permissions` command/service (create-only for permissions, never delete, only touch the 5 named system roles, never modify custom roles), the exact default permission matrix for the 5 system roles (explicit inclusion/exclusion rules per role), and — flagged as the critical immediate risk — locking the currently wide-open `UserResource` down to Super Admin only via real authorization (not navigation hiding), as a temporary measure pending the full user-management safety phase (Task 3). Explicitly out of scope: RoleResource/PermissionResource, protecting the other 24 resources, Filament Shield, any migration, is_active field, last-Super-Admin logic. Required 20 specific tests, refactoring (not deleting) the old coarse `DatabaseSeeder` permissions, documentation updates, and running the sync command against the local DB after tests pass — explicitly no staging/commit, wait for approval.

### Purpose
Close the most urgent gap the audit found (any authenticated user could manage users and assign any role, including a future Super Admin) with the smallest safe, fully-tested foundation, before building the larger granular-permission/RoleResource/PermissionResource work on top of it in later tasks.

### Result
Implemented exactly as scoped — see TASKS_LOG.md (2026-07-19 entry) for the full file list and verification, and DECISIONS_LOG.md (five 2026-07-19 entries) for the judgment calls made where the instructions were ambiguous (Viewer's report-permission exclusion, `execution_payments`'s operation set, the `UserPolicy` hardcoded-lockdown vs. granular-permission choice, and the `UserResourceLockdownTest` assertion strategy). One real environment issue was found and worked around during test-writing: full HTTP requests against Filament panel routes lose `actingAs()` in this environment (pre-existing, unrelated to this change — confirmed the whole prior suite already avoids this) — resolved by asserting directly against `UserResource::canX()`, the same authorization decision Filament's own code uses to produce a 403. 19/19 new tests pass; full suite 228/229 (the same single pre-existing unrelated `ExampleTest` failure recorded in every prior task since 2026-07-15). `oms:sync-permissions` run against the real local database after tests passed (154 permissions created, 5 system roles already present, per-role counts recorded) and confirmed idempotent on a second run. Not committed — diff and full verification shown for approval first.

---

### Date
2026-07-19 (OMS roles and permissions — read-only discovery and design)

### Prompt
User requested a read-only discovery-and-design pass opening the OMS roles-and-permissions phase — explicitly no file edits, no migrations, no seeding, no package installs, no commits. Ten phases requested: audit current authorization (User/HasRoles, Spatie tables, Super Admin mechanism, Gate::before, policies, Filament Shield, menu-only vs. real enforcement, direct-URL/action/export protection, existing user/role/permission management pages); a focused Filament inventory (resources, pages, relation managers, actions, exports, nav groups) with required permission operations per item; a 5-role default matrix (Super Admin/Admin/Accountant/Project Manager/Viewer) based on actual modules found; management-page designs for Users/Roles/Permissions with explicit safety rules (no self-elevation, non-Super-Admin can't touch Super Admin, last-active-Super-Admin protections); an authorization architecture recommendation (native Policies + Spatie + Gate::before, preferring existing packages, not recommending Filament Shield unless clearly justified); a naming convention; a seeding/sync design; explicit Super-Admin safety-layer breakdown; a focused test plan; a phased implementation plan; and a 14-section Arabic report as the only output, using Graphify/targeted file reads only (no full repo scan) and reporting any uncertainty explicitly rather than guessing.

### Purpose
Establish ground truth on exactly how much (or how little) real authorization exists today before designing or building anything, so the eventual implementation is scoped against confirmed facts — not assumptions — about this specific codebase's Filament resources, models, and existing (non-existent) enforcement.

### Result
Audit found `spatie/laravel-permission` installed and `User::HasRoles` wired, Spatie tables present, and a `DatabaseSeeder` that seeds a coarse permission/role grid and one Super Admin user — but confirmed **zero actual enforcement** anywhere: no `Gate::before`, no Policies, no Filament Shield, no resource/page authorization overrides except two hardcoded-`false` read-only locks on `Transactions`/`TransactionLines` (a pre-existing business rule, not a permission system). `UserResource` existed but was completely open to any authenticated user, including unrestricted role assignment — flagged as the critical immediate risk. Delivered the full 14-section Arabic report covering all 10 phases, including a confirmed inventory of 25 Filament resources across 5 nav groups (already including a `النظام` group matching the requested sidebar structure), 5 relation managers, and 6 custom report pages with export actions, plus one explicitly-flagged uncertainty (`ExecutionPaymentResource`/`ProjectCostBudgetsPaymentResource` share the same underlying model with near-identical query scoping — flagged for clarification before full resource protection, not guessed). No files modified, no `graphify update` run (nothing changed), nothing staged or committed, per instructions.

---

### Date
2026-07-18 (financial transaction balance guard — automated verification completion)

### Prompt
User requested completing the missing automated verification for the previously-implemented `FinancialTransactionBalanceGuard` (approved structure, not yet committed) without changing the approved accounting implementation: unit tests for the guard directly (an extensive named list of COMMON PAYLOAD / SINGLE-CURRENCY / MULTI-CURRENCY cases), focused integration tests for all 5 workflows (valid Create/Edit succeed; invalid payload rejected before mutation; counts/balances unchanged; Edit rejection leaves old lines/balances unchanged; an explicit `EditProjectCostReceipt` update-in-place regression), a specified 6-step test-run order with exact reported counts, targeted-search confirmation of 6 specific invariants (no naive cross-currency sum, validate-before-transaction in all 10 paths, exact-payload insert/update with no recalculation, no `<= 0.01` tolerance, no formula change, no delete/reversal change), documentation updates required by CLAUDE.md only, and an explicit "do not stage, do not commit, wait for approval" constraint.

### Purpose
Turn the previously-approved-but-unverified guard implementation into a fully test-backed, independently-confirmed change before it is ever committed — closing the gap between "the design is approved" and "the code actually does what was approved, provably, with tests that could not have been satisfied by weakening production code."

### Result
Writing the requested unit tests surfaced that the guard (as implemented in the prior pass) did not yet check several things the test matrix required — see `docs/DECISIONS_LOG.md` for why closing those gaps by extending the guard (not the workflow files) was the correct call. Added 34 unit tests + 22 integration tests (all passing), ran the 6-step sequence exactly as specified (34/34 → 22/22 → 26/26 → 53/53 → 69/69 → 206/207, the 1 full-suite failure being the same pre-existing unrelated `ExampleTest` seen in every prior task), and confirmed all 6 targeted-search invariants hold. See `docs/TASKS_LOG.md` (2026-07-18, this entry's task) for the exact commands and `docs/AI_PROJECT_MEMORY.md` for the full guard description (this pass also backfilled memory/task documentation for the two prior undocumented passes — the read-only discovery/design and the initial guard implementation — since neither had been logged yet). Not staged, not committed at this point — diff and full test results shown for approval first.

**Follow-up correction (same day, before commit):** the reviewer flagged that the guard let a *missing* `fx_rate` silently default to `1.0` — needed because `EditProjectCostReceipt`'s in-place update payload never set that key — as conflicting with the "no financial value silently assumed" requirement, and as capable of hiding a historically-corrupted stored `fx_rate`. Required correction: make `fx_rate` genuinely required everywhere with no fallback, and fix the one file with the actual gap (`EditProjectCostReceipt`) to explicitly write `fx_rate = 1`, plus tests proving the guard now rejects a missing `fx_rate`, proving a valid receipt Edit normalizes a corrupted stored `fx_rate` to `1`, and proving a rejected Edit still leaves it untouched. Implemented exactly as scoped — 2 more guard unit tests, 1 more integration test, both existing final counts re-verified (209/210, same 1 pre-existing unrelated failure) — approved, staged, and committed as `validate transaction balance with multi-currency support`. See `docs/DECISIONS_LOG.md` and `docs/TASKS_LOG.md` (both 2026-07-18 correction entries) for full detail.

---

### Date
2026-07-18 (server-side financial account validation)

### Prompt
User requested a focused read-only audit of server-side financial account validation across the 4 workflows that still relied mainly on Filament Select filtering (receipts, project cost budget payments, general expenses, general exchanges — execution payments already had a guard), asking for confirmed affected files, a per-field validation matrix, confirmed vulnerabilities with file/line references, business decisions needing confirmation, a minimal implementation plan, tests, and risk sizing — no changes yet. After reviewing the audit, user approved specific business rules (same account legally allowed on both sides of any operation, always; every account must exist/not-be-trashed/match type-bank-currency; Create requires every account active; Edit allows an unchanged historical account to stay inactive but requires a newly-selected replacement to be active; no historical data touched) and requested implementation: one reusable `FinancialAccountGuard` service with a focused `assertAccountMatches()` base method, wiring it into all 8 Create/Edit pages before any mutation, extending `ExecutionPaymentForm::validateCreditAccount()` only for active-account consistency, focused tests, and no staging/committing.

### Purpose
Close a real financial-integrity gap (a submitted account_id could bypass the rendered Select options entirely, and every balance-update call silently skipped on a missing/deleted account while still writing the transaction line) with the smallest safe, fully-tested change, in two phases (audit-then-approve) so the exact business rules — especially the Create-vs-Edit active-account split — were confirmed before any code changed.

### Result
Audit phase produced the affected-files/validation-matrix/vulnerabilities cited above (see `docs/TASKS_LOG.md` 2026-07-18 entry, not separately logged as its own task since no code changed in that phase). Implementation phase added `App\Services\Validation\FinancialAccountGuard` (`assertAccountMatches()`, batch `assertAccounts()`, `requireActiveOnChange()`), wired it into all 8 Create/Edit handlers before `DB::transaction()` (Edit pages restructured to fetch pre-mutation saved lines before the guard call, to compute per-role active requirements), replaced every `Account::find($data[...])?->increment/decrement()` with the guard-verified `Account` instance, and additively extended `ExecutionPaymentForm::validateCreditAccount()` with two optional active-account parameters. Added 60 new/targeted tests (150/151 full suite, 1 pre-existing unrelated failure), including a same-account-on-both-sides acceptance test per the approved rule. See `docs/TASKS_LOG.md`, `docs/AI_PROJECT_MEMORY.md`, and `docs/DECISIONS_LOG.md` (2026-07-18 entries) for full detail. Not committed, per instructions — diff shown for approval first.

---

### Date
2026-07-18 (positive-amount validation)

### Prompt
User requested a focused read-only audit of positive-amount validation across the 5 financial workflows (receipts, disbursements, execution payments, general expenses, general exchanges), asking for confirmed affected files, a per-field UI/server validation table, edge cases needing business decisions, a minimal implementation plan, tests, and risk sizing — no changes yet. After reviewing the plan, user approved specific business rules (amount ≥ 0.01; fx_rate > 0, no silent 0→1 fallback; percentages in [0,100] with combined < 100; derived amounts > 0; execution-payment over-budget behavior unchanged; other workflows' account/currency revalidation out of scope) and requested implementation: Filament form constraints, one reusable `FinancialAmountGuard` service, wiring it into all 10 Create/Edit pages before any mutation, removing the unsafe `fx_rate ?: 1` fallback in the two deduction/FX workflows only, focused tests, and a `git diff --stat` + summary before any commit (no commit without approval).

### Purpose
Close a real financial-integrity gap (zero/negative amounts, FX rates, and percentages could previously post into the double-entry ledger with no server-side check) with the smallest safe, fully-tested change, in two phases (audit-then-approve) so the exact business rules were confirmed before any code changed.

### Result
Audit phase produced the affected-files/validation table cited above (see `docs/TASKS_LOG.md` 2026-07-18 audit summary, not separately logged as a task since no code changed in that phase). Implementation phase added `App\Services\Validation\FinancialAmountGuard`, `minValue()`/`maxValue()` constraints on 5 Forms, guard calls in all 10 Create/Edit handlers before `DB::transaction()`, replaced the 4 unsafe `?: 1` fx_rate fallbacks on submitted data with `?? 1`, and added 33 new/targeted tests (104/105 full suite, 1 pre-existing unrelated failure). See `docs/TASKS_LOG.md`, `docs/AI_PROJECT_MEMORY.md`, and `docs/DECISIONS_LOG.md` (2026-07-18 entries) for full detail. Not committed, per instructions — diff shown for approval first.

---

## Prompt Template

### Date

### Prompt

### Purpose

### Result

---

### Date
2026-07-16 (General Exchange rearrangement)

### Prompt
User requested a UI-layout-only rearrangement of the General Exchange form (previously left unchanged by the general layout-standard task as a multi-account form): move "تفاصيل المعاملة" and "النسب والمبالغ" to a top row (تفاصيل المعاملة right, النسب والمبالغ left on desktop, تفاصيل المعاملة first on mobile), move the full "الحسابات" section underneath (full width, internal order/fields untouched), and keep "المرفقات" last. Explicit no-touch list (accounting logic, calculations, percentages, currencies, validation, live callbacks, account filtering, transaction lines, balances, descriptions, models, migrations, reports, exports) and explicit instruction not to modify `CreateGeneralExchange.php`/`EditGeneralExchange.php`.

### Purpose
Fix the General Exchange form's visual imbalance (large accounts section previously sitting beside/between the transaction sections) using the same RTL-aware native-Grid approach already applied to the other financial forms, without touching any of its multi-account calculation/deduction/fx logic.

### Result
Wrapped "تفاصيل المعاملة" (first, renders right) and "النسب والمبالغ" (second, renders left) in a `Grid::make(['default' => 1, 'lg' => 2])`; moved the unchanged "الحسابات" section underneath, full width; "المرفقات" stays last. Every field, callback, option query, and helper method in `GeneralExchangeForm.php` is byte-identical to before — only the top-level component tree was rearranged. 70 relevant existing tests pass; no dedicated form test exists for this page. See TASKS_LOG.md and AI_PROJECT_MEMORY.md (2026-07-16 General Exchange entries) for full detail. Not committed, per instructions.

---

### Date
2026-07-16 (UI layout standard)

### Prompt
User requested implementation of an approved responsive UI layout standard for credit/debit account sections across the OMS financial forms: desktop shows creditor right / debtor left side by side with equal width, mobile stacks creditor above debtor, using native Filament v5 responsive Grid columns (no custom CSS unless unavoidable) and verified against real RTL rendering rather than source order alone. Named General Expenses as the primary page to fix (currently debit-before-credit and visually unbalanced), plus Project Cost Receipts and Execution Payments (preserving the just-implemented editable credit-account cascade exactly), with an explicit multi-account-forms rule for the disbursement and general-exchange forms (source/credit first, don't force a two-column split, leave unchanged and report the reason if restructuring would be risky). Required the same mandatory pre-read/git-check/Graphify-first workflow, no commit/push, and a full verification + visual checklist.

### Purpose
Make the credit/debit account sections visually balanced and consistent across the financial forms without touching any accounting calculation, saved data, or field behavior.

### Result
Wrapped the credit and debit `Section`s of `GeneralExpenseForm.php` and `ProjectCostReceiptForm.php` in a shared `Grid::make(['default' => 1, 'lg' => 2])`, credit section reordered first (both were debit-first before); `ExecutionPaymentForm.php` only needed the `Grid` wrap since its credit section was already first. Multi-account forms (`ProjectCostBudgetsPaymentForm.php`, `GeneralExchangeForm.php`) were left unchanged with the reasoning documented, per the task's own escape hatch. 40 targeted tests + full suite (78/79, same pre-existing unrelated failure) confirm zero behavioral regression. See TASKS_LOG.md, AI_PROJECT_MEMORY.md, and DECISIONS_LOG.md (2026-07-16 layout entries) for full detail. Not committed, per instructions.

---

### Date
2026-07-16 (implementation)

### Prompt
Following a read-only audit of the Execution Payment credit-account flow (same-day, prior prompt), user approved implementation with explicit constraints: smallest safe change set, mandatory pre-read of CLAUDE.md/Master Reference/memory docs, `git status`/`migrate:status` checks first, stop-and-ask if code differed materially from the audit, no commit/push, exact field names and behavior for the new credit-account cascade, mandatory server-side re-validation independent of Select options (with Arabic `ValidationException` messages), exact Create/Edit handler responsibilities, 7 named required test scenarios with a specific list of assertions each, and a full verification checklist (lint, new tests, existing description/financial tests, full suite with pre-existing-failure separation, route inspection, `optimize:clear`, `graphify update .`, explicit list of commands NOT to run).

### Purpose
Implement the approved future behavior for `/admin/execution-payments/create`: move "الحساب الدائن" above "الحساب المدين (المستفيد)", make the credit account a real editable same-currency cascade (still auto-defaulting from the budget's destination account), and fix the pre-existing Edit drift bug identified during the audit — without a migration and without touching any of the explicitly listed protected files (models/enum/builders/disbursement flow/reports/snapshots).

### Result
Implemented exactly as scoped in 3 files (`ExecutionPaymentForm.php`, `CreateExecutionPayment.php`, `EditExecutionPayment.php`), no migration, no protected file touched. 7 new tests added and passing; existing description/financial suites (70/70) and the full suite (78/79, 1 pre-existing unrelated failure) confirmed unaffected. Routes unchanged. `optimize:clear` and `graphify update .` run. See TASKS_LOG.md, AI_PROJECT_MEMORY.md, and DECISIONS_LOG.md (2026-07-16 entries) for full detail. Not committed, per instructions.

---

### Date
2026-07-16 (audit)

### Prompt
User requested a read-only discovery and impact audit (no edits, no migrations, no commits, lowest-token-consumption, Graphify-first) of the Execution Payment (`صرف مبالغ التنفيذ`, `/admin/execution-payments/create`) credit/debit account flow, ahead of a planned change to move the credit-account section above the debit section and make the credit account user-editable (defaulting from the budget's destination account, same-currency only) while preserving balanced double-entry accounting and historical accuracy on Edit. Required a specific mandatory output format answering 27 numbered questions with direct code evidence, exact file paths, and a minimal-change recommendation — explicitly not to implement anything.

### Purpose
Establish, with verified code evidence rather than assumptions, exactly where the credit account is currently auto-selected, where (if anywhere) it is authoritatively stored, whether a migration would be needed to make it editable, and the safest currency-restriction rule — before any implementation prompt was issued.

### Result
Delivered the 13-section audit report: confirmed the credit account is currently a display-only, non-dehydrated field recomputed from `ProjectCostBudget::LINE_DESTINATION` on every Create/Edit save (not stored on the domain record); confirmed no migration is required since `transaction_lines.account_id` on the `LINE_CREDIT`/`execution_source` line is already the authoritative, mutable store; discovered a pre-existing Edit drift bug (historical credit account silently recomputed from the budget's *current* destination on every edit); recommended same-currency-only for the replacement account. This audit directly informed the same-day approved implementation prompt above.

---

### Date
2026-07-15

### Prompt
User requested a safe cleanup of the OMS development database: remove all operational/transactional/project/test data while preserving system configuration, donors, accounts (balances reset to 0), currencies, exchange-rate history, and users/roles/permissions. Explicit two-phase instructions: implement a dedicated `oms:clean-operational-data` Artisan command + service, run dry-run/audit only in this phase, and STOP for explicit approval before any `--apply` execution. Extensive mandatory safety rules: environment guard (local/development/testing only), confirmation-token + verified-backup-file requirements for apply, FK-safe explicit deletion order, soft-delete-aware (active+trashed) deletion, attachment file safety (only delete files deterministically linked to deleted DB rows, report orphans separately), no identity/AUTO_INCREMENT reset, and "stop and ask" if any relationship/classification is unclear rather than guessing.

### Purpose
Prepare the dev database for clean real-data entry without risking donor, accounting-configuration, or historical exchange-rate data, and without ever touching production/staging.

### Result
Implemented `OperationalDataCleanupService` + `OperationalCleanupReport` DTO + `CleanOperationalData` console command, all schema relationships verified by reading actual migrations/models (not guessed), 20 new tests, full test suite run, dry-run executed against the real dev DB confirming zero writes. Apply was not run. See TASKS_LOG.md and DECISIONS_LOG.md (2026-07-15 entries) for full detail.

---

### Date
2026-07-15 (execution)

### Prompt
User approved execution of the previously-designed cleanup, restating the full mandatory-preservation list, attachment-safety rules, exact preconditions to check, the exact backup file path to use, the exact apply command, a 21-point post-apply verification checklist, a required second idempotence run, and full documentation/reporting requirements. When the specified backup file was found missing, a follow-up prompt explicitly chose "create a fresh mysqldump now" at that exact path, with explicit sub-requirements (create directory if missing, use the configured connection without exposing credentials, verify success and non-empty size, stop if verification fails, then continue with the approved apply).

### Purpose
Actually execute the approved, previously dry-run-only cleanup against the real dev database, with a fresh pre-cleanup backup and full independent verification.

### Result
Backup created and verified (120,266 bytes). Apply executed successfully; post-apply verification (both the command's own and an independent 21-point manual check) passed. Second apply run confirmed idempotence (zero additional changes). See TASKS_LOG.md and AI_PROJECT_MEMORY.md (2026-07-15 execution entries) for full detail.

---

### Date
2026-07-05

### Prompt
User requested (in Plan Mode) implementation of Phase 1 of a new "ميزان المراجعة" (Trial Balance) Filament report page, following on from an earlier audit-only conversation. Key constraints specified: single required `currency_id` filter (no cross-currency blending, per the audit's finding on `debit_base`/`credit_base`), read-only, no exports, no opening/closing balance, reuse `AccountStatementPage`/`AccountStatementReportService` patterns, run `php artisan optimize:clear` after implementation (no migrations), and deliver a detailed verification report.

### Purpose
Give the accounting team a way to verify total debits equal total credits across all accounts of one currency for a given period.

### Result
Plan approved and implemented as described in TASKS_LOG.md (2026-07-05 entry) and DECISIONS_LOG.md (2026-07-05 entry). Full plan file: `C:\Users\laptop\.claude\plans\implement-phase-1-trial-wiggly-lark.md`.

---

### Date
2026-07-05

### Prompt
User requested (in Plan Mode) Phase 2 of Trial Balance: professional Excel and Word exports mirroring the quality of `AccountStatementExcelExportService`/`AccountStatementWordExportService`/`ProjectFinancialDetailsWordExportService`. Constraints: exports must use only the applied/displayed result (never live filter state), no frozen panes but keep autofilter, real XLSX/DOCX (no CSV/HTML), verify PhpSpreadsheet/PhpWord were already installed before writing any code, no changes to `TrialBalanceReportService`'s calculations, and a detailed verification report.

### Purpose
Let accountants/managers download the on-screen Trial Balance as a shareable, print-ready file instead of only viewing it in the browser.

### Result
Plan approved and implemented as described in TASKS_LOG.md (2026-07-05, second entry). Same plan file, updated in place: `C:\Users\laptop\.claude\plans\implement-phase-1-trial-wiggly-lark.md`.

---

### Date
2026-07-06

### Prompt
User requested Phase 1 of "تقرير الحركات المالية الشامل": a read-only comprehensive financial transactions (general journal) Filament page under التقارير, one row per transaction line, date-range required filters plus optional currency (multi), category, type, account, account type, and project filters, "عرض"-gated loading, per-currency/category/type summaries, no exports, no writes, no migrations, no use of accounts.current_balance.

### Purpose
Give accountants a full journal view of every accounting line in a period across all transaction sources, with balance status per currency.

### Result
Implemented directly (auto-agent mode, pre-approved) as described in TASKS_LOG.md (2026-07-06 entry) and DECISIONS_LOG.md (2026-07-06 entry).

---

### Date
2026-07-06

### Prompt
User requested a small summary-section enhancement to "تقرير الحركات المالية الشامل": rename "عدد الأسطر" wording to "عدد بنود القيود", show "عدد المعاملات" as distinct transaction IDs (explicitly not line count ÷ 2), and make the category/type sections "إحصائيات حسب تصنيف المعاملة" / "إحصائيات حسب نوع المعاملة" show distinct transaction counts with line-based debit/credit totals still grouped per currency. No export, no accounting-logic changes, no migrations.

### Purpose
Make the top statistics clearer for management/accounting: transactions counted once even when they have 2+ lines, with بنود القيود counted separately.

### Result
Implemented as described in TASKS_LOG.md (2026-07-06, second entry).

---

### Date
2026-07-06

### Prompt
User requested Phase 2 of "تقرير الحركات المالية الشامل": professional Excel (PhpSpreadsheet) and Word (PhpWord) exports mirroring the Trial Balance/Account Statement export quality. Constraints: export only the applied/displayed result (never live filter state), block export before "عرض" with a specific Arabic warning, real XLSX/DOCX, RTL, no frozen panes but keep autofilter, real numeric cells with #,##0.00, A4 portrait Tahoma Word document with footer/page numbers, per-currency separation everywhere, "الكل" for unset filters, sanitized filenames, no Maatwebsite Excel, verify packages in composer.json first.

### Purpose
Let accountants/managers download the on-screen comprehensive journal as shareable, print-ready official files.

### Result
Implemented as described in TASKS_LOG.md (2026-07-06, third entry).

---

### Date
2026-07-06

### Prompt
User requested a full reset of operational/business data in the `oms` database to start entering clean real data, with an explicit keep list (system/auth/settings/lookup tables) and an explicit purge list (accounts, partners, projects, transactions, and related financial tables). Hard constraints: no `migrate:fresh`, no dropped tables, no migration/code changes, no deleting settings/lookup/system/auth data, no deleting storage files yet, stop and ask if any table is unclear, show KEEP/PURGE lists and wait for approval before deleting, back up first (or provide the exact `mysqldump` command), use `FOREIGN_KEY_CHECKS=0`/`1` around the purge, reset AUTO_INCREMENT, include soft-deleted rows, then run `optimize:clear` and `reports:refresh-projects-financial`, and finally show row counts to confirm.

### Purpose
Let the user discard test/sample operational data and begin real production data entry without losing configuration, permissions, or historical schema.

### Result
Implemented as described in TASKS_LOG.md (2026-07-06, fourth entry) and DECISIONS_LOG.md (2026-07-06 entry). Backup taken at `storage/app/private/backups/oms_backup_2026-07-06.sql` before any deletion; user approved the lists via AskUserQuestion before the purge ran.

---

### Date
2026-07-06

### Prompt
User requested proper accounting support for account opening balances on account creation: optional opening_balance / opening_balance_date / opening_balance_fx_rate fields on the create form only, auto-generating a balanced "قيد افتتاحي" transaction (`OPB-YYYY-XXXX`) against a clearing account "أرصدة افتتاحية", with `project_cost_id = null` so project reports are unaffected. Hard constraints: read-only impact report first then STOP for approval, no report-formula/snapshot/project-report changes, no unrelated files, reuse existing numbering/balance patterns, STOP and ASK if account nature doesn't exist or the clearing account can't be created cleanly, DB::transaction throughout, current_balance stays display-only.

### Purpose
Let accountants enter real starting balances for accounts (post data-reset) through proper double-entry bookkeeping instead of hand-editing `current_balance`.

### Result
Impact report delivered; 4 decision points raised (no account nature exists → always-debit; fx_rate as metadata per existing own-currency base convention; new AccountType for clearing accounts; new super type "قيود افتتاحية") — user approved all recommended options. Implemented as described in TASKS_LOG.md (2026-07-06, fifth entry) and DECISIONS_LOG.md (2026-07-06, second entry).

---

### Date
2026-07-14

### Prompt
User requested automatic Arabic `transactions.description` generation across all 6 financial write flows (receipts, project disbursement, execution payments, general expenses, general exchanges, opening balance), with an exact stored format (`دائن: {credit} | مدين: {debit} | ملخص العملية: {summary}.`, credit before debit, `؛ ` for multiple accounts per side, English digits/2 decimals/thousands separators, currency from `transaction_lines.currency_id` not `accounts.currency_id`), one shared `TransactionDescriptionBuilder` service, per-flow Arabic summary templates with fallbacks, regeneration on edit, no second DB transaction, no migration, no historical backfill, and required a read-only impact report first (STOP for approval), then implementation with unit + targeted integration verification.

### Purpose
Make every financial transaction human-readable at a glance (list/search/audit/exports) without changing any accounting calculation, balance, or currency-handling logic.

### Result
Impact report delivered and approved with clarifications (currency must come from the line not the account; exact `حساب`-prefix dedup rule; explicit validation requiring both sides non-empty or throw; summary normalization rules). Implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-14 entries). Mid-implementation, `RefreshDatabase`-based testing surfaced two unrelated pre-existing migration bugs; user was shown a full read-only investigation (exact files, error, historical batch evidence, safe-fix proposal) before approving a minimal fix for each, and approved leaving a third, MySQL-only-SQL migration incompatibility untouched. All 15 unit tests + 6 real create-flow + 5 real edit-regeneration tinker verifications passed against real dev-DB data with zero residue (rolled-back transaction).

---

### Date
2026-07-14

### Prompt
Follow-up: user asked to close the remaining manual-edit bypass on the raw `TransactionResource` form — make `description` read-only there before final approval. After that small fix, user requested a further read-only audit (6 specific questions: navigation visibility, direct create access, whether create also builds balanced lines, independent header editing, effect of the description fix on a raw create, and whether the raw CRUD is actively needed) before approving any further change. Based on that audit's findings, user approved converting the whole resource into a strictly read-only audit resource: remove create/edit routes and actions, harden authorization (`can*` → `false`, not just hidden buttons), keep list/view/lines fully visible for audit, delete now-unreachable page classes if safe, and add `defaultSort('id', 'desc')` to both the transactions list and the lines relation table.

### Purpose
Prevent any path — form field, direct URL, or raw CRUD — from creating or mutating a `Transaction`/`TransactionLine` outside the 6 legitimate, balance-safe financial flows, while preserving 100% of this resource's genuine audit value (search, filter, view, inspect lines).

### Result
`description` made `disabled()->dehydrated(false)` on `TransactionForm` (same pattern as `current_balance` on `AccountForm`). Read-only audit answered all 6 questions directly from code (confirmed: bare/line-less/unbalanced create, independent header edits, no balance updates) — implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-14, second and third entries). `create`/`edit` routes removed, `CreateTransaction.php`/`EditTransaction.php` deleted (confirmed unreferenced), 8 `can*` methods hardcoded `false` on both `TransactionResource` and `LinesRelationManager`, all mutation actions removed from `ListTransactions`/`ViewTransaction`/`TransactionsTable`/`LinesRelationManager`, `defaultSort('id','desc')` added to both tables. Verified headlessly via reflection (real `Table` objects, `getFlatActions()`/`getDefaultSortColumn()`) rather than a full Livewire/HTTP mount, plus a full re-run of the six-flow tinker verification confirming zero impact on the 6 legitimate flows and `TransactionDescriptionBuilder`.

---

### Date
2026-07-14

### Prompt
User requested the line-level counterpart of the transaction-description feature: add nullable system-generated `transaction_lines.description` (exact format `{مدين|دائن}: حساب {name} ({line currency}) — {posted amount} | الغرض: {purpose}.`) and `transaction_lines.line_role` (stable English machine value from one centralized vocabulary, assigned explicitly by the authoritative flow — never inferred from account/side/order/description/notes), across all 6 financial flows, preserving `transaction_lines.notes`/`LINE_*` tags untouched, no historical backfill, no report/export changes, plus a mandated read-only impact report first (delivered and approved), then implementation including hardening the standalone raw `TransactionLineResource` (a line-level CRUD bypass the audit surfaced) into a read-only audit resource.

### Purpose
Make each individual ledger line self-explanatory for audit/search (which account moved and why) and give future reports a reliable machine-readable role instead of parsing Arabic notes tags — without touching any accounting amount, balance, or existing tag.

### Result
Implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-14 line-description entries). One mid-implementation deviation was raised and user-approved before proceeding: legitimately zero-amount deduction lines (0% admin/transfer) are skipped with NULL description instead of throwing, since the flows always create them and the literal "both sides zero → throw" rule would have rolled back every 0%-deduction disbursement/exchange. 33/33 unit tests passing; all 6 create + 5 edit flows verified against real dev data in a rolled-back transaction with zero residue.

---

### Date
2026-07-15

### Prompt
User requested two things in one task: (1) safely backfill the existing historical (pre-2026-07-14) transactions/transaction lines so `description`/`line_role` show correctly in reports, via a dedicated `--dry-run`/`--apply` Artisan command, with an explicit classification priority (direct FK on the authoritative domain record first, `notes` `LINE_*` tags only as supporting evidence after the flow is known, never account name/type/description-parsing/line-order/amount-similarity), explicit zero-amount-line handling (assign role, keep description NULL), a mandatory integrity snapshot proving only the 3 metadata columns change, and idempotence proven by a second `--apply` run; (2) display the three approved, permanently-named Arabic labels (`وصف العملية المالية` / `دور سطر القيد` / `وصف سطر القيد`) in the detailed financial-movement sections of 4 named reports (Comprehensive Financial Transactions, Account Statement, Project Financial Details, Donor Financial Report) — screen, Excel, and Word wherever each export already exists — explicitly excluding Trial Balance, summary cards, dashboard totals, and any percentage/collection-rate calculation, plus standardizing the same 3 labels on the transaction/transaction-line audit resources. Workflow constraints: use the current session (no clean-tree requirement beyond the already-completed prior feature), run the standard git/migrate-status checks first, use Graphify before broad source browsing, STOP and ask on any accounting-meaning ambiguity, do not commit/push, and produce one detailed final report covering every phase.

### Purpose
Close the historical-data gap left by the 2026-07-14 description/role feature (which deliberately excluded backfill pending an approved classification method) and make the new metadata actually visible where accountants read detailed financial movements, without risking any accounting figure, balance, or existing report calculation.

### Result
Implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-15 entries). No accounting-meaning ambiguity was hit — the 6 flows' authoritative domain-record FKs and the opening-balance transaction-type name were sufficient to classify all 10 dev-DB transactions deterministically (0 unclassified), so no STOP-and-ask was needed. `--dry-run`/`--apply`/idempotence-re-`--apply`/integrity-snapshot sequence all passed; 17 new backfill tests + 33 existing description/role-builder tests + full suite (52/52 relevant, 1 pre-existing unrelated failure) all passing; one real sample export per updated report/format (7 files) generated from live dev data and verified to re-open. Not committed, per instruction.

---

### Date
2026-07-20

### Prompt
"Implement OMS Permissions Task 2B: Custom Report Page and Export Authorization" — a structured task specifying: protect all 6 custom Filament report pages against unauthorized sidebar visibility, direct URL access, unauthorized Excel/Word export buttons, and direct Livewire/action invocation of export methods, using the pre-existing per-page `reports.<page>.view`/`reports.<page>.export` permissions already in `PermissionRegistry`; explicit page→permission map for all 6 pages; use the correct Filament v5 page-authorization method (not just `mount()`); export authorization must require both view and export permissions, protected in two layers (UI `visible()` + explicit server-side authorization inside the export method, "do not depend only on `visible()`"); preserve `ProjectFinancialDetailsPage`'s `shouldRegisterNavigation = false` and all existing export prerequisites/guard messages/snapshot behavior unchanged; a small reusable shared concern is acceptable but each page must explicitly declare its own permission names (no class-name-based magic mapping); data-driven real-HTTP + genuine Livewire/Filament action tests covering 14 numbered scenarios; verify the existing role permission matrix behaves as expected and report any discrepancy before changing it; explicit exclusions: no Resource Policy changes, no migrations, no package installs, no report calculation/query/filter/export-service/format changes; do not stage or commit, wait for approval; produce a detailed final report (files changed, permission map, exact protection mechanisms, test counts, git status/diff, confirmations).

### Purpose
Close the report-page/export gap explicitly deferred by Task 2A (which only covered Filament Resources/RelationManagers), so every custom report page and its exports are gated the same way the rest of the panel already is, using permissions that were already registered and role-assigned in Task 1 but never actually enforced anywhere.

### Result
Implemented as described in TASKS_LOG.md and DECISIONS_LOG.md (2026-07-20 "Task 2B" entries). The existing permission matrix (`SystemRoleDefaultPermissionsTest`) already matched every assumption in the prompt exactly (Accountant gets the 4 financial reports' view+export, Project Manager gets project report views without export, Viewer gets none, Admin gets everything except users/roles/permissions) — no discrepancy found, so no registry change was needed or made. 58 new tests (30 page-access + 28 export-authorization) all pass; full suite 574/574 excluding the same 1 pre-existing unrelated `ExampleTest` failure recorded since 2026-07-15. Not staged, not committed, per instruction.

---

### Date
2026-07-20 (OMS Permissions Task 4 — secure role management)

### Prompt
"Implement OMS Permissions Task 4: Secure Role Management" — create a Filament `RoleResource` under nav group `النظام` (label `الأدوار`) letting authorized users view roles, create custom roles, view role details, update safe custom roles, assign permissions via grouped Arabic checkboxes, and delete safe unused custom roles — while the five system roles stay visible-but-protected (not renameable/editable/deletable through this resource, blocked on direct Edit/Delete requests including for Super Admin, since `oms:sync-permissions` remains their sole authority). Detailed constraints: use Spatie `Role`/`Permission` directly (no custom Role model unless proven necessary), a dedicated `RolePolicy` explicitly registered if auto-discovery can't find it, a dedicated `RoleManagementService` as the sole mutation path, reuse `PermissionRegistry` for Arabic labels/grouping, no Filament Shield, no `PermissionResource` yet; exact `RoleManagementService` method signatures and rules (`isSystemRole`, `canManageRole`, `assignablePermissionNames`, transactional `createRole`/`updateRole`/`deleteRole` with specific validation/rejection rules); exact `RolePolicy` ability map with an explicit warning that `Gate::before` bypasses it for Super Admin so system-role blocking must be structural elsewhere; detailed Filament Resource/Form/Table requirements (grouped checkboxes, technical names visible, no `guard_name` field, non-Super-Admin sees only assignable permissions, no bulk/force-delete/clone/hierarchy); 42 named test scenarios across 8 categories; an 11-step verification order; and a 14-point final report before commit. No migrations, no financial-logic changes, no staging/commit until reviewed.

### Purpose
Close the last piece of the User/Role/Permission management surface still pending after Task 3 (secure `UserResource`) — give administrators a safe way to create custom role combinations without touching the five system roles' `oms:sync-permissions`-managed defaults, and without opening a path for privilege escalation via crafted requests or a real Super Admin's own `Gate::before` bypass.

### Result
Implemented as described in `docs/TASKS_LOG.md` and `docs/DECISIONS_LOG.md` (2026-07-20 "Task 4" entries): `App\Services\Roles\RoleManagementService`, `App\Policies\RolePolicy` (explicitly registered via `Gate::policy()`, since Spatie's `Role` model lives outside `App\Models` and Laravel's naming-convention auto-discovery never finds it there — traced `Gate::guessPolicyName()` directly to confirm), and a full `app/Filament/Resources/Roles/` resource (Resource/Form/Table/4 Pages) under `النظام` → `الأدوار`. System-role protection against a real Super Admin is structural (`RoleResource::canEdit()`/`canDelete()` call `RoleManagementService::canManageRole()` directly, bypassing Gate/Policy, and every action's `->visible()` calls those same overrides rather than Filament's default Gate-based action authorization) — documented as its own decision since it's the one rule `RolePolicy` alone can never enforce for Super Admin. Permission checkboxes are grouped one `CheckboxList` per `PermissionRegistry::groups()` module (Filament's `CheckboxList` has no built-in optgroup support, confirmed from its Blade source), with an additional `صلاحيات مخصصة` group for any DB `Permission` outside the registry, always merged into a role's options so an edit never silently drops an existing custom/unregistered permission. 80 new tests across 6 files (`RoleManagementServiceTest`, `RolePolicyTest`, `RoleResourceHttpTest`, `RoleResourceLivewireTest`, `SystemRoleProtectionTest`, `SyncCompatibilityTest`) all pass; full suite 711/714 excluding the same 1 pre-existing unrelated `ExampleTest` failure recorded since 2026-07-15 (confirmed identical on a clean `git stash`). No `PermissionResource` built (Task 5, still pending). Not staged, not committed, per instruction.
