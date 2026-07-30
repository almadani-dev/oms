<?php

namespace Tests\Feature\Audit\Ui;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Services\Audit\Attachments\AttachmentAuditRecorder;
use App\Services\Audit\BackupRestore\BackupAuditRecorder;
use App\Services\Audit\BackupRestore\BackupRestoreAuditSubject;
use App\Services\Audit\BackupRestore\RestoreAuditRecorder;
use App\Services\Audit\Crud\AuditSubjectRegistry;
use App\Services\Audit\Financial\FinancialAuditSubject;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Audit\Security\SecurityAuditSubject;
use App\Support\Audit\AuditLabels;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Keeps AuditLabels' bounded maps honest against the REAL writers (OMS Task
 * 9B.7 §4/§5). The maps exist so the UI never needs a `SELECT DISTINCT` over
 * a growing audit table for its filter options — that only stays true if they
 * actually cover what the recorders can emit, so this test fails the moment a
 * future subject alias or backup/restore action is registered without a label.
 */
class AuditLabelCoverageTest extends AuditTestCase
{
    public function test_every_registered_crud_subject_alias_has_a_label(): void
    {
        $aliases = app(AuditSubjectRegistry::class)->aliases();

        $this->assertNotEmpty($aliases);

        foreach ($aliases as $alias) {
            $this->assertArrayHasKey(
                $alias,
                AuditLabels::subjectOptions(),
                "AuditSubjectRegistry alias [{$alias}] has no Arabic label in AuditLabels.",
            );
        }
    }

    public function test_every_subject_enum_case_has_a_label(): void
    {
        $cases = array_merge(
            FinancialAuditSubject::cases(),
            SecurityAuditSubject::cases(),
            ReportExportSubject::cases(),
            BackupRestoreAuditSubject::cases(),
        );

        foreach ($cases as $case) {
            $this->assertArrayHasKey(
                $case->value,
                AuditLabels::subjectOptions(),
                "Subject alias [{$case->value}] has no Arabic label in AuditLabels.",
            );
        }
    }

    public function test_the_attachment_subject_alias_has_a_label(): void
    {
        $this->assertArrayHasKey(
            AttachmentAuditRecorder::SUBJECT_TYPE,
            AuditLabels::subjectOptions(),
        );
    }

    public function test_every_known_event_category_has_a_label(): void
    {
        $categories = [
            'crud',
            'financial',
            'security',
            'attachment',
            AttachmentAuditRecorder::EVENT_CATEGORY,
            ReportExportAuditRecorder::EVENT_CATEGORY,
            BackupRestoreAuditSubject::EVENT_CATEGORY,
        ];

        foreach (array_unique($categories) as $category) {
            $this->assertArrayHasKey(
                $category,
                AuditLabels::categoryOptions(),
                "Event category [{$category}] has no Arabic label in AuditLabels.",
            );
        }
    }

    public function test_every_backup_and_restore_action_constant_has_a_label(): void
    {
        $actions = [
            BackupAuditRecorder::ACTION_REQUESTED,
            BackupAuditRecorder::ACTION_COMPLETED,
            BackupAuditRecorder::ACTION_FAILED,
            BackupAuditRecorder::ACTION_DOWNLOADED,
            BackupAuditRecorder::ACTION_DOWNLOAD_DENIED,
            BackupAuditRecorder::ACTION_DELETE_REQUESTED,
            BackupAuditRecorder::ACTION_DELETED,
            RestoreAuditRecorder::ACTION_REQUESTED,
            RestoreAuditRecorder::ACTION_STARTED,
            RestoreAuditRecorder::ACTION_RECONCILED,
            RestoreAuditRecorder::ACTION_COMPLETED,
            RestoreAuditRecorder::ACTION_FAILED,
            RestoreAuditRecorder::ACTION_PARTIAL,
            RestoreAuditRecorder::ACTION_INTERRUPTED,
            ReportExportAuditRecorder::EVENT_ACTION,
        ];

        foreach ($actions as $action) {
            $this->assertArrayHasKey(
                $action,
                AuditLabels::actionOptions(),
                "Event action [{$action}] has no Arabic label in AuditLabels.",
            );
        }
    }

    public function test_every_actor_type_case_has_a_label(): void
    {
        foreach (AuditActorType::cases() as $case) {
            $this->assertArrayHasKey($case->value, AuditLabels::actorTypeOptions());
            $this->assertNotSame('—', AuditLabels::actorType($case));
        }
    }

    public function test_every_status_case_has_a_label(): void
    {
        foreach (AuditStatus::cases() as $case) {
            $this->assertNotSame('—', AuditLabels::status($case));
        }
    }

    /**
     * OMS Task 9B.6 left this explicitly open for 9B.7: a replayed restore row
     * and the deliberately message-free failure pair must be SURFACED, not
     * flattened into anonymous technical keys.
     */
    public function test_the_backup_restore_keys_9b6_asked_to_surface_are_labelled(): void
    {
        foreach (['replayed_after_database_replacement', 'failure_code', 'failure_category'] as $key) {
            $this->assertNotSame(
                $key,
                AuditLabels::field($key),
                "Payload key [{$key}] must have an Arabic label so the UI surfaces it explicitly.",
            );
        }
    }

    public function test_unknown_values_fall_back_to_the_stored_value_verbatim(): void
    {
        $this->assertSame('future_category', AuditLabels::category('future_category'));
        $this->assertSame('future_action', AuditLabels::action('future_action'));
        $this->assertSame('future_alias', AuditLabels::subject('future_alias'));
        $this->assertSame('future_field', AuditLabels::field('future_field'));

        $this->assertSame('—', AuditLabels::category(null));
        $this->assertSame('—', AuditLabels::action(''));
    }
}
