<?php

namespace Tests\Feature\Audit\Crud;

use App\Models\AuditEvent;
use App\Models\PartnerType;
use App\Models\ProjectStatus;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\User;
use App\Services\Audit\Crud\AuditSubjectRegistry;
use App\Services\Audit\Exceptions\AuditImmutableRecordException;
use App\Services\Audit\Exceptions\AuditSubjectNotRegisteredException;

/**
 * OMS Task 9B.2 — the shared general-CRUD audit infrastructure itself:
 * alias stability, the closed registration allowlist, subject-label bounding,
 * technical-field exclusion, and the no-op/one-event rules.
 */
class AuditCrudInfrastructureTest extends AuditedCrudTestCase
{
    private function registry(): AuditSubjectRegistry
    {
        return app(AuditSubjectRegistry::class);
    }

    public function test_the_approved_models_map_to_their_stable_aliases(): void
    {
        $this->assertSame([
            // Task 9B.2 — general/master data.
            \App\Models\Project::class => 'project',
            \App\Models\ProjectCost::class => 'project_cost',
            \App\Models\Partner::class => 'partner',
            \App\Models\PartnerType::class => 'partner_type',
            \App\Models\ProjectSuper::class => 'project_super',
            \App\Models\ProjectStatus::class => 'project_status',
            \App\Models\BankType::class => 'bank_type',
            \App\Models\FiscalYear::class => 'fiscal_year',
            \App\Models\TransactionType::class => 'transaction_type',
            \App\Models\TransactionSuperType::class => 'transaction_super_type',
            \App\Models\Setting::class => 'setting',
            // Task 9B.3 — financial master data.
            \App\Models\Account::class => 'account',
            \App\Models\AccountType::class => 'account_type',
            \App\Models\Currency::class => 'currency',
            \App\Models\ExchangeRateHistory::class => 'exchange_rate_history',
            // Muwakha families — family register + its project links.
            \App\Models\MuwakhaFamily::class => 'muwakha_family',
            \App\Models\MuwakhaFamilyProject::class => 'muwakha_family_project',
            \App\Models\MuwakhaFamilyAccount::class => 'muwakha_family_account',
        ], $this->registry()->aliases());
    }

    public function test_no_alias_is_ever_a_class_name(): void
    {
        foreach ($this->registry()->aliases() as $alias) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $alias);
            $this->assertStringNotContainsString('\\', $alias);
            $this->assertStringNotContainsString('App', $alias);
        }
    }

    /**
     * Security and out-of-phase models must not be auditable through the
     * generic CRUD path by accident.
     *
     * Transaction and TransactionLine are the load-bearing entries here and
     * must stay unregistered permanently, not just for one phase: one logical
     * financial action produces exactly one AuditEvent, written by the source
     * workflow and carrying the resulting transaction identifiers (OMS Task
     * 9B.3). Registering either would duplicate every financial event.
     */
    public function test_unregistered_models_are_rejected(): void
    {
        foreach ([Transaction::class, TransactionLine::class, User::class, AuditEvent::class] as $class) {
            $this->assertFalse($this->registry()->isRegistered($class), $class.' must not be registered.');

            try {
                $this->registry()->definitionFor($class);
                $this->fail("Expected AuditSubjectNotRegisteredException for {$class}.");
            } catch (AuditSubjectNotRegisteredException $e) {
                $this->assertStringContainsString($class, $e->getMessage());
            }
        }
    }

    public function test_auditing_an_unregistered_model_throws_instead_of_inventing_an_alias(): void
    {
        $this->actingAsSuperAdmin();

        $this->expectException(AuditSubjectNotRegisteredException::class);

        $this->service()->create(new Transaction, [
            'transaction_number' => 'X-0001',
            'transaction_time' => now(),
        ]);
    }

    public function test_no_definition_audits_a_technical_field(): void
    {
        foreach ($this->registry()->aliases() as $class => $_alias) {
            $definition = $this->registry()->definitionFor($class);

            foreach (AuditSubjectRegistry::TECHNICAL_FIELDS as $technical) {
                $this->assertNotContains(
                    $technical,
                    $definition->auditedFields,
                    "{$class} must not audit the technical field {$technical}.",
                );
            }
        }
    }

    public function test_subject_label_is_bounded_to_255_characters(): void
    {
        $this->actingAsSuperAdmin();

        $long = str_repeat('ن', 400);
        $this->service()->create(new PartnerType, ['name' => $long]);

        $event = AuditEvent::sole();

        $this->assertSame(255, mb_strlen((string) $event->subject_label));
    }

    public function test_a_no_op_update_creates_no_event(): void
    {
        $this->actingAsSuperAdmin();

        // Created directly (not through the service), so no event exists yet:
        // model-level writes outside AuditedCrudService are never audited.
        $status = ProjectStatus::create(['name' => 'ثابت']);
        $this->assertSame(0, AuditEvent::count());

        $this->service()->update($status, ['name' => 'ثابت']);

        $this->assertSame(0, AuditEvent::count());
    }

    /**
     * HasUserTracking rewrites updated_by on every real save, and the event's
     * own actor snapshot already records who acted — so it must never show up
     * as a changed business field.
     */
    public function test_updated_by_is_maintained_but_never_audited(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $status = ProjectStatus::create(['name' => 'قبل']);
        $status->forceFill(['updated_by' => null])->saveQuietly();

        $this->service()->update($status, ['name' => 'بعد']);

        $event = AuditEvent::sole();

        $this->assertSame(['name'], $event->changed_fields);
        $this->assertArrayNotHasKey('updated_by', $event->old_values);
        $this->assertArrayNotHasKey('updated_by', $event->new_values);
        $this->assertSame($actor->id, $status->fresh()->updated_by, 'updated_by must still be maintained.');
    }

    public function test_one_logical_action_creates_exactly_one_event(): void
    {
        $this->actingAsSuperAdmin();

        $type = $this->service()->create(new PartnerType, ['name' => 'أ']);
        $this->assertSame(1, AuditEvent::count());

        $this->service()->update($type, ['name' => 'ب']);
        $this->assertSame(2, AuditEvent::count());

        $this->service()->delete($type);
        $this->assertSame(3, AuditEvent::count());

        $this->service()->restore($type);
        $this->assertSame(4, AuditEvent::count());

        $this->assertSame(
            ['created', 'updated', 'deleted', 'restored'],
            AuditEvent::orderBy('id')->pluck('event_action')->all(),
        );
    }

    public function test_every_crud_event_uses_the_crud_category(): void
    {
        $this->actingAsSuperAdmin();

        $setting = $this->service()->create(new Setting, ['key' => 'timezone', 'value' => 'Asia/Gaza', 'group' => 'general']);
        $this->service()->update($setting, ['value' => 'Asia/Hebron']);
        $this->service()->delete($setting);

        $this->assertSame(['crud'], AuditEvent::distinct()->pluck('event_category')->all());
    }

    public function test_crud_written_audit_events_remain_immutable(): void
    {
        $this->actingAsSuperAdmin();

        $this->service()->create(new PartnerType, ['name' => 'ثابت']);
        $event = AuditEvent::sole();

        $this->expectException(AuditImmutableRecordException::class);

        $event->update(['reason' => 'tampered']);
    }
}
