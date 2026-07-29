<?php

namespace App\Services\Audit\Crud;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\ExchangeRateHistory;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Setting;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
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
