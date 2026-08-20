<?php

namespace App\Services\Audit\Crud;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\ExchangeRateHistory;
use App\Models\FiscalYear;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Models\MuwakhaFamilyProject;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Setting;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Services\Audit\AuditRedactor;
use App\Services\Audit\Financial\FinancialAuditValue;
use App\Services\Audit\Exceptions\AuditSubjectNotRegisteredException;
use Illuminate\Database\Eloquent\Model;

/**
 * The hand-maintained map from an approved model class to its stable audit
 * subject alias and audited-field policy (OMS Task 9B.2).
 *
 * Registration is an explicit, closed allowlist and the ONLY way a model can
 * be audited as general CRUD: an unregistered class throws
 * AuditSubjectNotRegisteredException rather than falling back to a derived
 * alias, which is what structurally guarantees `subject_type` never holds a
 * PHP FQCN (a class rename must never orphan historical rows — see the
 * audit_events migration's docblock).
 *
 * The eleven general/master-data models approved in Task 9B.2 are here,
 * plus the four FINANCIAL MASTER-DATA models added in Task 9B.3 — Account,
 * AccountType, Currency, ExchangeRateHistory. Those four are ordinary master
 * data whose lifecycle is a single save, so they use this architecture
 * (event_category `crud`) rather than the workflow-shaped
 * App\Services\Audit\Financial path; they still get identical REQUIRED
 * atomicity, because AuditedCrudService wraps every mutation and its
 * AuditEvent in one transaction. Their financial semantics are preserved by
 * their value policies below: money and rates are stored as fixed-scale
 * DECIMAL STRINGS, never as floats.
 *
 * The five financial WORKFLOWS (project_cost_receipt, project_disbursement,
 * execution_payment, general_expense, general_exchange) are deliberately NOT
 * here — they are audited as `financial` events keyed by workflow alias, not
 * by model class, because two of them share model classes whose names are the
 * inverse of the workflow they serve (see
 * App\Services\Audit\Financial\FinancialAuditSubject).
 *
 * Transaction and TransactionLine are deliberately NOT here and must never
 * be: one logical financial action produces exactly one AuditEvent, written
 * by the source workflow and carrying the resulting transaction identifiers.
 * Registering either model would duplicate every financial event. Security
 * models (User/Role/Permission), Attachment and BackupOperation are likewise
 * absent — they belong to phases 9B.4+.
 */
final class AuditSubjectRegistry
{
    /**
     * Technical/audit-plumbing columns that must never appear in a payload
     * or in changed_fields. Not itself the enforcement mechanism — every
     * definition's $auditedFields is a closed allowlist, so these are
     * excluded by construction — but asserted against in tests so a future
     * registration cannot quietly let one back in.
     *
     * `updated_by` in particular: HasUserTracking rewrites it on every save,
     * and the actor snapshot on the event already records who acted, so
     * including it would add a changed field to literally every update.
     */
    public const TECHNICAL_FIELDS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by',
        'remember_token',
        'is_dirty',
    ];

    /**
     * Muwakha family columns whose VALUES must never reach an audit payload.
     * Real personal identifiers, redacted by the `muwakha_family` subject's
     * value policy while `changed_fields` still records that they changed.
     */
    public const MUWAKHA_PRIVATE_FIELDS = [
        'martyr_national_id',
        'guardian_national_id',
        'guardian_phone',
    ];

    /** @var array<class-string<Model>, AuditSubjectDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly SettingValuePolicy $settingValuePolicy) {}

    public function isRegistered(Model|string $model): bool
    {
        return array_key_exists($this->classOf($model), $this->definitions());
    }

    public function definitionFor(Model|string $model): AuditSubjectDefinition
    {
        $class = $this->classOf($model);

        return $this->definitions()[$class]
            ?? throw AuditSubjectNotRegisteredException::forModel($class);
    }

    public function aliasFor(Model|string $model): string
    {
        return $this->definitionFor($model)->alias;
    }

    /**
     * @return array<class-string<Model>, string>
     */
    public function aliases(): array
    {
        return array_map(
            static fn (AuditSubjectDefinition $definition): string => $definition->alias,
            $this->definitions(),
        );
    }

    /**
     * @param  Model|class-string<Model>  $model
     * @return class-string<Model>
     */
    private function classOf(Model|string $model): string
    {
        return $model instanceof Model ? $model::class : $model;
    }

    /**
     * @return array<class-string<Model>, AuditSubjectDefinition>
     */
    private function definitions(): array
    {
        return $this->definitions ??= $this->build();
    }

    /**
     * @return array<class-string<Model>, AuditSubjectDefinition>
     */
    private function build(): array
    {
        $definitions = [
            new AuditSubjectDefinition(
                modelClass: Project::class,
                alias: 'project',
                auditedFields: [
                    'name',
                    'code',
                    'approval_date',
                    'implementation_date',
                    'start_date',
                    'end_date',
                    'donor_project_name',
                    'donor_id',
                    'project_super_id',
                    'project_status_id',
                    'notes',
                ],
                labelResolver: static fn (Project $project): ?string => self::joinCodeAndName($project->code, $project->name),
            ),

            new AuditSubjectDefinition(
                modelClass: ProjectCost::class,
                alias: 'project_cost',
                auditedFields: [
                    'project_id',
                    'account_type_id',
                    'amount',
                    'currency_id',
                    'notes',
                ],
                labelResolver: static fn (ProjectCost $cost): ?string => self::joinParts([
                    self::projectLabel($cost->project_id),
                    $cost->amount !== null ? (string) $cost->amount : null,
                ]),
                // The one relationship snapshot this phase takes: a cost
                // line is meaningless in an audit trail without knowing
                // which project it belonged to, and the FK alone is not
                // readable once the project itself is renamed or deleted.
                // Resolved from the FOREIGN KEY VALUE, never from the
                // model's `project` relationship — on the pre-change side of
                // a reassignment that relationship already points at the new
                // project, so labelling the old id with it would be a plain
                // factual error.
                relationLabels: [
                    'project_id' => static fn (mixed $projectId): ?string => self::projectLabel($projectId),
                ],
            ),

            new AuditSubjectDefinition(
                modelClass: Partner::class,
                alias: 'partner',
                auditedFields: [
                    'name',
                    'partner_type_id',
                    'is_donor',
                    'email',
                    'mobile_number',
                    'address',
                    'city',
                    'country',
                    'notes',
                ],
                labelResolver: static fn (Partner $partner): ?string => $partner->name,
            ),

            new AuditSubjectDefinition(
                modelClass: PartnerType::class,
                alias: 'partner_type',
                auditedFields: ['name', 'notes'],
                labelResolver: static fn (PartnerType $type): ?string => $type->name,
            ),

            new AuditSubjectDefinition(
                modelClass: ProjectSuper::class,
                alias: 'project_super',
                auditedFields: ['name', 'code_prefix', 'code', 'notes'],
                labelResolver: static fn (ProjectSuper $super): ?string => self::joinCodeAndName($super->code, $super->name),
            ),

            new AuditSubjectDefinition(
                modelClass: ProjectStatus::class,
                alias: 'project_status',
                auditedFields: ['name', 'color', 'notes'],
                labelResolver: static fn (ProjectStatus $status): ?string => $status->name,
            ),

            new AuditSubjectDefinition(
                modelClass: BankType::class,
                alias: 'bank_type',
                auditedFields: ['name', 'notes'],
                labelResolver: static fn (BankType $type): ?string => $type->name,
            ),

            new AuditSubjectDefinition(
                modelClass: FiscalYear::class,
                alias: 'fiscal_year',
                auditedFields: ['name', 'start_date', 'end_date', 'is_active', 'notes'],
                labelResolver: static fn (FiscalYear $year): ?string => $year->name,
            ),

            new AuditSubjectDefinition(
                modelClass: TransactionType::class,
                alias: 'transaction_type',
                auditedFields: ['transaction_super_type_id', 'name', 'notes'],
                labelResolver: static fn (TransactionType $type): ?string => $type->name,
            ),

            new AuditSubjectDefinition(
                modelClass: TransactionSuperType::class,
                alias: 'transaction_super_type',
                auditedFields: ['name', 'notes'],
                labelResolver: static fn (TransactionSuperType $type): ?string => $type->name,
            ),

            new AuditSubjectDefinition(
                modelClass: Setting::class,
                alias: 'setting',
                auditedFields: ['key', 'group', 'value', 'description'],
                labelResolver: static fn (Setting $setting): ?string => self::settingReference($setting),
                valuePolicy: fn (string $field, mixed $value, array $row): mixed => $field === 'value'
                    ? $this->settingValuePolicy->sanitize($row['key'] ?? null, $value)
                    : $value,
                // `settings.key` is a setting's business identifier, not key
                // material — but a bare `key` field is (correctly, globally)
                // treated as secret-shaped by AuditRedactor, which would
                // erase both sides of a rename. It is emitted under the safe
                // semantic name `setting_name` instead, so rename history
                // survives without weakening the redactor for any other
                // subject. `settings.value` is unaffected: it still passes
                // SettingValuePolicy above and the central redactor after.
                fieldAliases: ['key' => 'setting_name'],
            ),

            // ---- OMS Task 9B.3 — financial master data ----------------------

            new AuditSubjectDefinition(
                modelClass: Account::class,
                alias: 'account',
                auditedFields: [
                    'account_code',
                    'name',
                    'account_type_id',
                    'bank_type_id',
                    'currency_id',
                    'current_balance',
                    'is_active',
                    'iban',
                    'notes',
                ],
                labelResolver: static fn (Account $account): ?string => self::joinParts([$account->account_code, $account->name]),
                relationLabels: [
                    'account_type_id' => static fn (mixed $id): ?string => AccountType::withTrashed()
                        ->select(['id', 'name'])->find($id)?->name,
                    'currency_id' => static fn (mixed $id): ?string => Currency::withTrashed()
                        ->select(['id', 'code'])->find($id)?->code,
                ],
                // `current_balance` is never editable through AccountForm (it
                // is disabled + dehydrated(false)) and only ever moves via
                // balanced entries, so it adds no noise to an update. It is
                // carried anyway because the balance at the moment of a
                // DELETION is the single most important fact about a removed
                // account — as a decimal string, never a float.
                valuePolicy: static fn (string $field, mixed $value, array $row): mixed => $field === 'current_balance'
                    ? FinancialAuditValue::money($value)
                    : $value,
            ),

            new AuditSubjectDefinition(
                modelClass: AccountType::class,
                alias: 'account_type',
                auditedFields: ['name', 'notes'],
                labelResolver: static fn (AccountType $type): ?string => $type->name,
            ),

            new AuditSubjectDefinition(
                modelClass: Currency::class,
                alias: 'currency',
                auditedFields: ['name', 'code', 'symbol', 'is_base', 'notes'],
                labelResolver: static fn (Currency $currency): ?string => self::joinParts([$currency->code, $currency->name]),
            ),

            new AuditSubjectDefinition(
                modelClass: ExchangeRateHistory::class,
                alias: 'exchange_rate_history',
                auditedFields: ['currency_id', 'rate', 'date'],
                labelResolver: static fn (ExchangeRateHistory $history): ?string => self::joinParts([
                    Currency::withTrashed()->select(['id', 'code'])->find($history->currency_id)?->code,
                    $history->date?->format('Y-m-d'),
                ]),
                relationLabels: [
                    'currency_id' => static fn (mixed $id): ?string => Currency::withTrashed()
                        ->select(['id', 'code'])->find($id)?->code,
                ],
                // The rate is the whole point of this record: stored at its
                // full six-decimal scale as a string, so no float rounding can
                // ever alter a historical rate in the audit trail.
                valuePolicy: static fn (string $field, mixed $value, array $row): mixed => $field === 'rate'
                    ? FinancialAuditValue::rate($value)
                    : $value,
            ),

            // ---- OMS Muwakha Families (مشروع المؤاخاة) ---------------------
            //
            // Ordinary master data whose lifecycle is a single save, so both
            // use this architecture (event_category `crud`) and inherit its
            // REQUIRED atomicity. Creating a family also creates its Account,
            // which produces its own separate `account` event — two events for
            // one submission, describing two genuinely different subjects.
            // That is deliberate and is NOT the financial "one action = one
            // event" rule, which governs a single ledger operation.
            //
            // PRIVACY: `martyr_national_id`, `guardian_national_id` and
            // `guardian_phone` are real personal identifiers and their VALUES
            // are never stored in an audit payload. They are replaced with
            // AuditRedactor::MARKER by the subject value policy below.
            //
            // Change tracking is fully preserved: `changed_fields` is derived
            // from the model's own getDirty() BEFORE any value policy runs
            // (AuditModelSnapshotter::pendingDiff), so the trail still records
            // exactly WHICH of these fields changed and when — only the before
            // and after values are withheld. The live `muwakha_families` row
            // remains the authoritative source for the actual identifiers.
            //
            // Done as a subject value policy rather than by adding
            // `national_id`/`phone` to AuditRedactor's global denylist,
            // following the precedent set by SettingValuePolicy and by the
            // `setting_name`/`parent_id` aliases: solve it inside the subject
            // rather than broadening a global rule that every other subject
            // also has to live with.

            new AuditSubjectDefinition(
                modelClass: MuwakhaFamily::class,
                alias: 'muwakha_family',
                auditedFields: [
                    'martyr_name',
                    'martyr_national_id',
                    'martyr_date_of_birth',
                    'martyrdom_date',
                    'children_count',
                    'guardian_name',
                    'guardian_national_id',
                    'guardian_date_of_birth',
                    'guardian_phone',
                    'account_holder_name',
                    'account_id',
                    'notes',
                ],
                // The subject label deliberately carries the martyr NAME only.
                // Including the national id would have written the very
                // identifier the value policy below withholds into
                // `subject_label`, which is displayed in the audit list.
                labelResolver: static fn (MuwakhaFamily $family): ?string => self::joinParts([
                    'أسرة الشهيد',
                    $family->martyr_name,
                ]),
                relationLabels: [
                    'account_id' => static fn (mixed $id): ?string => self::accountLabel($id),
                ],
                valuePolicy: static fn (string $field, mixed $value, array $row): mixed => in_array(
                    $field,
                    self::MUWAKHA_PRIVATE_FIELDS,
                    true,
                ) && $value !== null
                    ? AuditRedactor::MARKER
                    : $value,
            ),

            new AuditSubjectDefinition(
                modelClass: MuwakhaFamilyProject::class,
                alias: 'muwakha_family_project',
                auditedFields: [
                    'muwakha_family_id',
                    'project_id',
                    'card_code',
                ],
                labelResolver: static fn (MuwakhaFamilyProject $link): ?string => self::joinParts([
                    self::muwakhaFamilyLabel($link->muwakha_family_id),
                    self::projectLabel($link->project_id),
                    $link->card_code,
                ]),
                relationLabels: [
                    'muwakha_family_id' => static fn (mixed $id): ?string => self::muwakhaFamilyLabel($id),
                    'project_id' => static fn (mixed $id): ?string => self::projectLabel($id),
                ],
            ),

            // Durable family <-> Account ownership. Registered so a new
            // mapping is audited like any other domain row; it is never
            // updated or deleted by the application, so in practice only
            // `created` events appear. `account_holder_name` is ordinary
            // business data, not a personal identifier of the redacted kind
            // (it is displayed in the families table and both exports), so no
            // value policy applies here.
            new AuditSubjectDefinition(
                modelClass: MuwakhaFamilyAccount::class,
                alias: 'muwakha_family_account',
                auditedFields: [
                    'muwakha_family_id',
                    'account_id',
                    'account_holder_name',
                ],
                labelResolver: static fn (MuwakhaFamilyAccount $mapping): ?string => self::joinParts([
                    self::muwakhaFamilyLabel($mapping->muwakha_family_id),
                    self::accountLabel($mapping->account_id),
                ]),
                relationLabels: [
                    'muwakha_family_id' => static fn (mixed $id): ?string => self::muwakhaFamilyLabel($id),
                    'account_id' => static fn (mixed $id): ?string => self::accountLabel($id),
                ],
            ),
        ];

        $indexed = [];

        foreach ($definitions as $definition) {
            $indexed[$definition->modelClass] = $definition;
        }

        return $indexed;
    }

    /**
     * A bounded label for one project id. Reads only the three columns the
     * label needs (never hydrating or serializing the model into a payload)
     * and uses withTrashed() so a cost line whose project was soft-deleted
     * — the normal case when auditing a deletion — still records a readable
     * project reference instead of a bare id. Callers memoize
     * (AuditModelSnapshotter::$labelCache), so one logical action performs
     * at most one lookup per distinct project.
     */
    private static function projectLabel(mixed $projectId): ?string
    {
        if ($projectId === null) {
            return null;
        }

        $project = Project::withTrashed()
            ->select(['id', 'code', 'name'])
            ->find($projectId);

        return $project === null ? null : self::joinCodeAndName($project->code, $project->name);
    }

    /**
     * A bounded "code — name" label for one account id, resolved withTrashed()
     * so a family's account still reads legibly in a deletion snapshot. Reads
     * only the three columns the label needs.
     */
    private static function accountLabel(mixed $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $account = Account::withTrashed()
            ->select(['id', 'account_code', 'name'])
            ->find($accountId);

        return $account === null ? null : self::joinParts([$account->account_code, $account->name]);
    }

    /**
     * A bounded label for one Muwakha family id. withTrashed() because a link
     * removal is frequently audited while the owning family is already
     * soft-deleted.
     */
    private static function muwakhaFamilyLabel(mixed $familyId): ?string
    {
        if ($familyId === null) {
            return null;
        }

        $family = MuwakhaFamily::withTrashed()
            ->select(['id', 'martyr_name'])
            ->find($familyId);

        // Name only — never the national id. A label is displayed text, and
        // this one must not reintroduce the identifier the `muwakha_family`
        // value policy withholds.
        return $family === null
            ? null
            : self::joinParts(['أسرة الشهيد', $family->martyr_name]);
    }

    private static function settingReference(Setting $setting): ?string
    {
        $key = $setting->key;

        if ($key === null || $key === '') {
            return null;
        }

        return $setting->group ? $key.' ('.$setting->group.')' : $key;
    }

    private static function joinCodeAndName(?string $code, ?string $name): ?string
    {
        return self::joinParts([$code, $name]);
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private static function joinParts(array $parts): ?string
    {
        $clean = array_values(array_filter(
            array_map(static fn (?string $part): string => trim((string) $part), $parts),
            static fn (string $part): bool => $part !== '',
        ));

        return $clean === [] ? null : implode(' — ', $clean);
    }
}
