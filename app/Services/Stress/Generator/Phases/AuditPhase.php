<?php

namespace App\Services\Stress\Generator\Phases;

use App\Enums\AuditStatus;
use App\Enums\TransactionLineRole;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ExchangeRateHistory;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Models\MuwakhaFamilyProject;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Audit\Attachments\AttachmentAuditMetadata;
use App\Services\Audit\Attachments\AttachmentAuditRecorder;
use App\Services\Audit\AuditRecordRequest;
use App\Services\Audit\Crud\AuditModelSnapshotter;
use App\Services\Audit\Crud\AuditSubjectRegistry;
use App\Services\Audit\Financial\FinancialAccountRole;
use App\Services\Audit\Financial\FinancialAuditDiff;
use App\Services\Audit\Financial\FinancialAuditRecorder;
use App\Services\Audit\Financial\FinancialAuditSnapshotter;
use App\Services\Audit\Financial\FinancialAuditSubject;
use App\Services\Audit\Financial\FinancialAuditValue;
use App\Services\Audit\Security\SecurityAuditRecorder;
use App\Services\Audit\Security\SecurityAuditSubject;
use App\Services\Audit\Security\UserSecuritySnapshot;
use App\Services\Stress\Generator\Audit\StressAuditRowFactory;
use App\Services\Stress\Generator\StressBuildContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Coherent audit history for everything the build created — never filler.
 *
 * Payloads are produced by the REAL snapshotters (FinancialAuditSnapshotter,
 * AuditModelSnapshotter, AttachmentAuditMetadata) and labels by the real
 * subject definitions, from the rows now in the stress database; the
 * journal supplies what the final rows no longer show (pre-edit values,
 * deleting user, hard-removed links, opening-entry context). Rows go to a
 * stress-only staging table and are moved into audit_events in one
 * INSERT … SELECT ORDER BY created_at, so ids are chronological.
 */
final class AuditPhase
{
    private const STAGE = '_stress_audit_stage';

    private const CRUD = 'crud';

    /** @var array<string, int> */
    public array $counts = [];

    /** @var array<int, User> */
    private array $usersById = [];

    public function __construct(
        private readonly StressBuildContext $ctx,
        private readonly StressAuditRowFactory $rows,
        private readonly FinancialAuditSnapshotter $financial,
        private readonly AuditModelSnapshotter $crud,
        private readonly AuditSubjectRegistry $registry,
        private readonly AttachmentAuditMetadata $attachments,
    ) {
        foreach ($ctx->users as $user) {
            $this->usersById[$user->id] = $user;
        }
    }

    /**
     * @param  Closure(string): void  $log
     */
    public function run(Closure $log): void
    {
        $db = DB::connection($this->ctx->connection);
        $sqlite = $db->getDriverName() === 'sqlite';
        $db->statement('DROP TABLE IF EXISTS `'.self::STAGE.'`');
        // MySQL keeps an auto-increment id as the tie-breaker; SQLite (tests) uses rowid.
        $db->statement($sqlite
            ? 'CREATE TABLE `'.self::STAGE.'` AS SELECT * FROM `audit_events` WHERE 0'
            : 'CREATE TABLE `'.self::STAGE.'` LIKE `audit_events`');

        $this->logins();
        $this->crudHistory();
        $log('  CRUD/security history staged.');
        $this->financialHistory($log);
        $this->attachmentHistory();
        $this->ctx->writer->flushAll();

        $columns = '`uuid`, `event_category`, `event_action`, `subject_type`, `subject_key`, `subject_label`, `actor_user_id`, `actor_name`, `actor_email`, `actor_roles`, `actor_type`, `old_values`, `new_values`, `changed_fields`, `reason`, `correlation_id`, `ip_address`, `user_agent`, `route_name`, `http_method`, `status`, `created_at`';
        $db->statement('INSERT INTO `audit_events` ('.$columns.') SELECT '.$columns.' FROM `'.self::STAGE.'` ORDER BY `created_at`, '.($sqlite ? 'rowid' : '`id`'));
        $db->statement('DROP TABLE `'.self::STAGE.'`');
    }

    // ---------------------------------------------------------------- security

    private function logins(): void
    {
        $random = $this->ctx->random->fork('logins');
        $calendar = $this->ctx->calendar;

        for ($day = 0; $day <= $calendar->lastDay(); $day++) {
            // Friday off; a little weekend work.
            $weekday = (int) date('N', strtotime($calendar->date($day)));
            $p = $weekday === 5 ? 0.02 : 0.14;

            foreach ($this->ctx->users as $key => $user) {
                if ($key === 'accountant_former' && $day >= \App\Services\Stress\Generator\StressNames::FORMER_ACCOUNTANT_UNTIL_DAY) {
                    continue;
                }

                if (! $random->chance($p)) {
                    continue;
                }

                $at = $calendar->timestamp($random, $day);
                $failed = $random->chance(0.03);

                // Same payloads as AuthenticationAuditRecorder: a failed
                // attempt is recorded with a guest actor.
                $this->stage(new AuditRecordRequest(
                    eventCategory: SecurityAuditRecorder::EVENT_CATEGORY,
                    eventAction: $failed ? 'login_failed' : 'login_success',
                    actor: $failed ? $this->ctx->guestActor($user) : $this->ctx->actor($user),
                    status: $failed ? AuditStatus::Failure : AuditStatus::Success,
                    subjectType: SecurityAuditSubject::Authentication->value,
                    subjectKey: (string) $user->id,
                    subjectLabel: UserSecuritySnapshot::label($user),
                    newValues: $failed
                        ? ['identified' => true, 'user_id' => $user->id, 'email' => $user->email, 'is_active' => (bool) $user->is_active]
                        : ['user_id' => $user->id, 'is_active' => (bool) $user->is_active, 'roles' => UserSecuritySnapshot::roleNames($user)],
                ), $at);
            }
        }
    }

    // ---------------------------------------------------------------- CRUD

    private function crudHistory(): void
    {
        $journal = $this->ctx->journal;

        $this->crudCreated(FiscalYear::query());
        $this->crudCreated(ExchangeRateHistory::withTrashed());
        $this->crudCreated(Partner::withTrashed());
        $this->crudCreated(Account::withTrashed()->whereIn('id', array_keys($journal->creators)), fn (Account $a) => $journal->creators[$a->id] ?? null, $journal->openingContext);
        $this->crudCreated(Project::withTrashed());
        $this->crudCreated(ProjectCost::query()->whereIn('id', array_keys($journal->auditedCosts) ?: [0]));
        $this->crudCreated(MuwakhaFamily::withTrashed());
        $this->crudCreated(MuwakhaFamilyAccount::query(), fn (Model $m) => $journal->rowActors['muwakha_family_accounts'][$m->id] ?? null);
        $this->crudCreated(MuwakhaFamilyProject::query(), fn (Model $m) => $journal->rowActors['muwakha_family_projects'][$m->id] ?? null);

        // Links that were hard-removed with their (soft-deleted) family.
        foreach ($journal->removedLinks as $id => $removed) {
            $link = (new MuwakhaFamilyProject)->newInstance([], true);
            $link->forceFill($removed['row']);
            $link->syncOriginal();

            $this->crudEvent('created', $link, $removed['row']['created_at'], $removed['actor'], newValues: $this->crud->snapshot($link));
            $this->crudEvent('deleted', $link, $removed['at'], $removed['by'], oldValues: $this->crud->snapshot($link));
        }

        foreach ($journal->crudUpdates as $class => $updates) {
            foreach (array_chunk($updates, 500) as $chunk) {
                $models = $class::withTrashed()->whereIn('id', array_column($chunk, 'id'))->get()->keyBy('id');

                foreach ($chunk as $update) {
                    $model = clone $models[$update['id']];
                    $model->setRawAttributes(array_merge($model->getAttributes(), $update['before'], ['deleted_at' => null]), true);
                    $model->forceFill($update['after']);
                    $diff = $this->crud->pendingDiff($model);

                    if ($diff->old === [] && $diff->new === []) {
                        continue;
                    }

                    $this->crudEvent('updated', $model, $update['at'], $update['by'], oldValues: $diff->old, newValues: $diff->new, changed: $diff->changed);
                }
            }
        }

        foreach (['partners' => Partner::class, 'projects' => Project::class, 'accounts' => Account::class, 'exchange_rate_histories' => ExchangeRateHistory::class, 'muwakha_families' => MuwakhaFamily::class] as $table => $class) {
            $deleted = $journal->deletedBy[$table] ?? [];

            foreach (array_chunk(array_keys($deleted), 500, true) as $ids) {
                foreach ($class::withTrashed()->whereIn('id', $ids)->get() as $model) {
                    $at = (string) $model->getRawOriginal('deleted_at');
                    $model->setRawAttributes(array_merge($model->getAttributes(), ['deleted_at' => null]), true);
                    $this->crudEvent('deleted', $model, $at, $deleted[$model->id], oldValues: $this->crud->snapshot($model));
                }
            }
        }
    }

    /**
     * @param  null|Closure(Model): ?int  $actorOf
     * @param  array<int, array<string, mixed>>  $context  model id => extra created payload (opening entry)
     */
    private function crudCreated($query, ?Closure $actorOf = null, array $context = []): void
    {
        $model = $query->getModel();
        $overrides = $this->ctx->journal->createdOverrides[$model::class] ?? [];

        $query->orderBy('id')->chunkById(500, function ($models) use ($actorOf, $overrides, $context): void {
            foreach ($models as $record) {
                $at = (string) $record->getRawOriginal('created_at');
                $by = $actorOf !== null ? $actorOf($record) : ($record->getRawOriginal('created_by') ?? null);

                if ($by === null) {
                    continue;
                }

                $state = clone $record;
                $state->setRawAttributes(array_merge($state->getAttributes(), $overrides[$record->id] ?? [], ['deleted_at' => null]), true);

                $newValues = $this->crud->snapshot($state);

                if (isset($context[$record->id])) {
                    $c = $context[$record->id];
                    $newValues = array_merge($newValues, [
                        'opening_transaction_id' => $c['opening_transaction_id'],
                        'opening_transaction_number' => $c['opening_transaction_number'],
                        'opening_balance' => FinancialAuditValue::money($c['opening_balance']),
                        'opening_balance_date' => FinancialAuditValue::date($c['opening_balance_date']),
                        'opening_balance_fx_rate' => FinancialAuditValue::rate($c['opening_balance_fx_rate']),
                    ]);
                }

                $this->crudEvent('created', $state, $at, (int) $by, newValues: $newValues);
            }
        }, 'id');
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int, string>|null  $changed
     */
    private function crudEvent(string $action, Model $model, string $at, int $by, ?array $oldValues = null, ?array $newValues = null, ?array $changed = null): void
    {
        $definition = $this->registry->definitionFor($model);

        $this->stage(new AuditRecordRequest(
            eventCategory: self::CRUD,
            eventAction: $action,
            actor: $this->ctx->actor($this->usersById[$by]),
            subjectType: $definition->alias,
            subjectKey: (string) $model->getKey(),
            subjectLabel: $definition->label($model),
            oldValues: $oldValues,
            newValues: $newValues,
            changedFields: $changed,
        ), $at);
    }

    // ---------------------------------------------------------------- financial

    /**
     * @param  Closure(string): void  $log
     */
    private function financialHistory(Closure $log): void
    {
        $flows = [
            'project_cost_receipts' => [ProjectCostReceipt::class, FinancialAuditSubject::ProjectCostReceipt, 'projectCostReceipt'],
            'project_cost_budgets' => [ProjectCostBudget::class, FinancialAuditSubject::ProjectDisbursement, 'projectDisbursement'],
            'project_cost_budgets_payments' => [ProjectCostBudgetsPayment::class, FinancialAuditSubject::ExecutionPayment, 'executionPayment'],
            'general_expenses' => [GeneralExpense::class, FinancialAuditSubject::GeneralExpense, 'generalExpense'],
            'general_exchanges' => [GeneralExchange::class, FinancialAuditSubject::GeneralExchange, 'generalExchange'],
        ];

        foreach ($flows as $table => [$class, $subject, $method]) {
            $edits = $this->ctx->journal->financialEdits[$table] ?? [];
            $deletes = $this->ctx->journal->deletedBy[$table] ?? [];
            $count = 0;

            $class::withTrashed()->orderBy('id')->chunkById(1000, function ($records) use ($subject, $method, $edits, $deletes, &$count): void {
                $transactions = Transaction::withTrashed()->whereIn('id', $records->pluck('transaction_id'))->get()->keyBy('id');
                $roles = $this->accountRoles($transactions->keys()->all(), $subject);

                foreach ($records as $record) {
                    $transaction = $transactions[$record->transaction_id];
                    $accounts = $roles[$record->transaction_id] ?? [];
                    $record->setRelation('transaction', $transaction);
                    $after = $this->financial->{$method}($record, $accounts);
                    $createdBy = (int) $record->getRawOriginal('created_by');
                    $createdAt = (string) $record->getRawOriginal('created_at');

                    $created = $after;

                    if (isset($edits[$record->id])) {
                        $before = clone $record;
                        $before->forceFill($edits[$record->id]['before']);
                        $before->setRelation('transaction', $transaction);
                        $created = $this->financial->{$method}($before, $accounts);
                    }

                    $this->financialEvent('created', $subject, $record, $created, $createdAt, $createdBy, newValues: $created);

                    if (isset($edits[$record->id])) {
                        $diff = FinancialAuditDiff::between($created, $after);

                        if (! $diff->isEmpty()) {
                            $this->financialEvent('updated', $subject, $record, $after, $edits[$record->id]['at'], $edits[$record->id]['by'], oldValues: $diff->old, newValues: $diff->new, changed: $diff->changed);
                        }
                    }

                    if (isset($deletes[$record->id])) {
                        $this->financialEvent('deleted', $subject, $record, $after, (string) $record->getRawOriginal('deleted_at'), $deletes[$record->id], oldValues: $after);
                    }

                    $count++;
                }
            }, 'id');

            $log(sprintf('  financial %s: %d records audited', $subject->value, $count));
        }
    }

    /**
     * @param  array<int, int>  $transactionIds
     * @return array<int, array<string, int>> transaction id => role => account id
     */
    private function accountRoles(array $transactionIds, FinancialAuditSubject $subject): array
    {
        $map = match ($subject) {
            FinancialAuditSubject::ProjectCostReceipt => [TransactionLineRole::ReceiptDestination->value => FinancialAccountRole::DEBIT, TransactionLineRole::FundingSource->value => FinancialAccountRole::CREDIT],
            FinancialAuditSubject::ExecutionPayment => [TransactionLineRole::Beneficiary->value => FinancialAccountRole::BENEFICIARY, TransactionLineRole::ExecutionSource->value => FinancialAccountRole::CREDIT],
            FinancialAuditSubject::GeneralExpense => [TransactionLineRole::Expense->value => FinancialAccountRole::DEBIT, TransactionLineRole::Source->value => FinancialAccountRole::CREDIT],
            default => [
                TransactionLineRole::Source->value => FinancialAccountRole::SOURCE,
                TransactionLineRole::Destination->value => FinancialAccountRole::DESTINATION,
                TransactionLineRole::AdministrativeDeduction->value => FinancialAccountRole::ADMIN,
                TransactionLineRole::TransferFee->value => FinancialAccountRole::TRANSFER,
            ],
        };

        $roles = [];

        // The Create pages pass roles in this order; keep it for identical payload key order.
        $order = array_values($map);

        foreach (DB::connection($this->ctx->connection)->table('transaction_lines')
            ->whereIn('transaction_id', $transactionIds)->orderBy('id')
            ->get(['transaction_id', 'account_id', 'line_role']) as $line) {
            if (isset($map[$line->line_role])) {
                $roles[$line->transaction_id][$map[$line->line_role]] = (int) $line->account_id;
            }
        }

        foreach ($roles as $tx => $byRole) {
            $sorted = [];

            foreach ($order as $role) {
                if (isset($byRole[$role])) {
                    $sorted[$role] = $byRole[$role];
                }
            }

            $roles[$tx] = $sorted;
        }

        return $roles;
    }

    /**
     * @param  array<string, mixed>  $labelSource
     */
    private function financialEvent(string $action, FinancialAuditSubject $subject, Model $record, array $labelSource, string $at, int $by, ?array $oldValues = null, ?array $newValues = null, ?array $changed = null): void
    {
        $this->stage(new AuditRecordRequest(
            eventCategory: FinancialAuditRecorder::EVENT_CATEGORY,
            eventAction: $action,
            actor: $this->ctx->actor($this->usersById[$by]),
            subjectType: $subject->value,
            subjectKey: (string) $record->getKey(),
            subjectLabel: FinancialAuditSnapshotter::subjectLabel($labelSource, $record),
            oldValues: $oldValues,
            newValues: $newValues,
            changedFields: $changed,
        ), $at);
    }

    // ---------------------------------------------------------------- attachments

    private function attachmentHistory(): void
    {
        $deletes = $this->ctx->journal->deletedBy['attachments'] ?? [];

        Attachment::withTrashed()->orderBy('id')->chunkById(500, function ($attachments) use ($deletes): void {
            foreach ($attachments as $attachment) {
                $by = (int) $attachment->getRawOriginal('created_by');

                $this->stage(new AuditRecordRequest(
                    eventCategory: AttachmentAuditRecorder::EVENT_CATEGORY,
                    eventAction: 'uploaded',
                    actor: $this->ctx->actor($this->usersById[$by]),
                    subjectType: AttachmentAuditRecorder::SUBJECT_TYPE,
                    subjectKey: (string) $attachment->id,
                    subjectLabel: $this->attachments->label($attachment),
                    newValues: $this->attachments->of($attachment, 'uploaded'),
                ), (string) $attachment->getRawOriginal('created_at'));

                if (isset($deletes[$attachment->id])) {
                    // Recorded just before the parent's financial "deleted" event.
                    $at = date('Y-m-d H:i:s', strtotime((string) $attachment->getRawOriginal('deleted_at')) - 1);

                    $this->stage(new AuditRecordRequest(
                        eventCategory: AttachmentAuditRecorder::EVENT_CATEGORY,
                        eventAction: 'deleted',
                        actor: $this->ctx->actor($this->usersById[$deletes[$attachment->id]]),
                        subjectType: AttachmentAuditRecorder::SUBJECT_TYPE,
                        subjectKey: (string) $attachment->id,
                        subjectLabel: $this->attachments->label($attachment),
                        oldValues: $this->attachments->of($attachment, 'deleted'),
                    ), $at);
                }
            }
        }, 'id');
    }

    private function stage(AuditRecordRequest $request, string $at): void
    {
        $row = $this->rows->row($request, $at);
        $key = $request->eventCategory.'.'.$request->eventAction;
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        $this->ctx->writer->add(self::STAGE, $row);
    }
}
