<?php

namespace App\Services\Audit\Financial;

use App\Enums\AuditFailureMode;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single write path for `event_category = financial` audit events
 * (OMS Task 9B.3), and the enforcement point for this phase's two core
 * rules.
 *
 * RULE 1 — one logical financial action = exactly one AuditEvent.
 * A single user action in one of the five financial workflows writes a
 * Transaction, two-to-four TransactionLines, one source record and two-to-
 * four account balance updates. Exactly ONE event describes all of it,
 * carrying the resulting `transaction_id`/`transaction_number` so the ledger
 * can be followed without duplicating its lines. There is deliberately no
 * Transaction or TransactionLine observer, and neither model is registered
 * in the general-CRUD AuditSubjectRegistry — the only way one of those rows
 * can be audited is through the source workflow's own single event, which is
 * what makes duplicates structurally impossible rather than merely unlikely.
 *
 * RULE 2 — the audit insert belongs to the CALLER's transaction.
 * Unlike App\Services\Audit\Crud\AuditedCrudService, which owns its
 * transaction because the general-CRUD mutation it wraps is a single save,
 * this class NEVER opens one. Each financial workflow already runs inside
 * its own DB::transaction() spanning the source record, the Transaction, the
 * TransactionLines, the account balance changes and any attachment metadata;
 * opening a second, independent transaction here would let a REQUIRED audit
 * failure roll back nothing but itself. Instead every entry point asserts a
 * transaction is genuinely open and fails closed (LogicException) if it is
 * not — a programming error, caught at the call site, never a silently
 * unaudited financial mutation.
 *
 * Combined with AuditFailureMode::Required, that gives the guarantee this
 * phase is about: if the audit row cannot be persisted, the source record,
 * the transaction, its lines and every account balance roll back with it,
 * and no partial financial operation remains.
 */
final class FinancialAuditRecorder
{
    public const EVENT_CATEGORY = 'financial';

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly FinancialAuditSnapshotter $snapshotter,
        private readonly AuditActorResolver $actorResolver,
    ) {}

    public function snapshots(): FinancialAuditSnapshotter
    {
        return $this->snapshotter;
    }

    /**
     * @param  array<string, mixed>  $snapshot  the post-create bounded snapshot
     */
    public function created(FinancialAuditSubject $subject, Model $record, array $snapshot): void
    {
        $this->record(
            subject: $subject,
            record: $record,
            action: 'created',
            oldValues: null,
            newValues: $snapshot,
            changedFields: null,
            label: FinancialAuditSnapshotter::subjectLabel($snapshot, $record),
        );
    }

    /**
     * Writes nothing when no financial/business field actually changed — a
     * form re-save that touches nothing is not an auditable state change.
     *
     * @param  array<string, mixed>  $before  snapshot taken before any mutation
     * @param  array<string, mixed>  $after  snapshot taken after every mutation
     */
    public function updated(FinancialAuditSubject $subject, Model $record, array $before, array $after): void
    {
        $diff = FinancialAuditDiff::between($before, $after);

        if ($diff->isEmpty()) {
            $this->assertInsideCallerTransaction('updated');

            return;
        }

        $this->record(
            subject: $subject,
            record: $record,
            action: 'updated',
            oldValues: $diff->old,
            newValues: $diff->new,
            changedFields: $diff->changed,
            label: FinancialAuditSnapshotter::subjectLabel($after, $record),
        );
    }

    /**
     * $snapshot must have been captured BEFORE the deletion, while the
     * source record, its transaction and its lines were all still intact —
     * that pre-delete snapshot (transaction number, account labels, amounts)
     * is the only remaining description of what was removed.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function deleted(FinancialAuditSubject $subject, Model $record, array $snapshot): void
    {
        $this->record(
            subject: $subject,
            record: $record,
            action: 'deleted',
            oldValues: $snapshot,
            newValues: null,
            changedFields: null,
            label: FinancialAuditSnapshotter::subjectLabel($snapshot, $record),
        );
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int, string>|null  $changedFields
     */
    private function record(
        FinancialAuditSubject $subject,
        Model $record,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?array $changedFields,
        ?string $label,
    ): void {
        $this->assertInsideCallerTransaction($action);

        $key = $record->getKey();

        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: $action,
                actor: $this->actorResolver->resolve(),
                subjectType: $subject->value,
                subjectKey: $key === null ? null : (string) $key,
                subjectLabel: $label,
                oldValues: $oldValues,
                newValues: $newValues,
                changedFields: $changedFields,
            ),
            AuditFailureMode::Required,
        );
    }

    /**
     * Fails closed rather than quietly writing an audit row that could
     * survive a rolled-back financial mutation. Every call site in the five
     * workflows records from inside its own already-open DB::transaction();
     * a violation here is a wiring bug, not a runtime condition.
     */
    private function assertInsideCallerTransaction(string $action): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(sprintf(
                'A financial audit event (%s.%s) must be recorded from inside the workflow\'s own open DB::transaction().',
                self::EVENT_CATEGORY,
                $action,
            ));
        }
    }
}
