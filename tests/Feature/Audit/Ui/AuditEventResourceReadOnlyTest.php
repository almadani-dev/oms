<?php

namespace Tests\Feature\Audit\Ui;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Models\AuditEvent;
use App\Services\Audit\Exceptions\AuditImmutableRecordException;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Livewire\Livewire;

/**
 * OMS Task 9B.7 §2/§8 — the UI never mutates an AuditEvent and never creates
 * one by being used.
 */
class AuditEventResourceReadOnlyTest extends AuditUiTestCase
{
    public function test_only_index_and_view_pages_are_registered(): void
    {
        $this->assertSame(['index', 'view'], array_keys(AuditEventResource::getPages()));
    }

    public function test_no_create_edit_or_delete_route_exists(): void
    {
        $event = $this->makeEvent();

        $this->actingAs($this->superAdmin());

        // 404, not 403 — the pages were never registered at all.
        $this->get(self::LIST_URL.'/create')->assertNotFound();
        $this->get($this->viewUrl($event).'/edit')->assertNotFound();
    }

    public function test_every_mutation_ability_is_hard_false_even_for_super_admin(): void
    {
        $event = $this->makeEvent();

        $this->actingAs($this->superAdmin());

        $this->assertFalse(AuditEventResource::canCreate());
        $this->assertFalse(AuditEventResource::canEdit($event));
        $this->assertFalse(AuditEventResource::canDelete($event));
        $this->assertFalse(AuditEventResource::canDeleteAny());
        $this->assertFalse(AuditEventResource::canForceDelete($event));
        $this->assertFalse(AuditEventResource::canForceDeleteAny());
        $this->assertFalse(AuditEventResource::canRestore($event));
        $this->assertFalse(AuditEventResource::canRestoreAny());
        $this->assertFalse(AuditEventResource::canReplicate($event));
    }

    public function test_the_resource_registers_no_relation_managers(): void
    {
        $this->assertSame([], AuditEventResource::getRelations());
    }

    public function test_the_table_offers_no_destructive_or_bulk_action(): void
    {
        $this->makeEvent();

        $this->actingAs($this->superAdmin());

        $table = Livewire::test(ListAuditEvents::class)->instance()->getTable();

        $recordActionNames = array_keys($table->getFlatRecordActions());
        $this->assertSame(['view'], $recordActionNames);

        foreach ($table->getFlatRecordActions() as $action) {
            $this->assertNotInstanceOf(DeleteAction::class, $action);
        }

        $this->assertSame([], $table->getToolbarActions());

        foreach ($table->getFlatActions() as $action) {
            $this->assertNotInstanceOf(DeleteBulkAction::class, $action);
            $this->assertNotInstanceOf(BulkAction::class, $action);
        }

        // No bulk actions means Filament renders no row-selection checkboxes.
        $this->assertFalse($table->isSelectionEnabled());
    }

    public function test_no_column_is_inline_editable(): void
    {
        $this->makeEvent();

        $this->actingAs($this->superAdmin());

        $table = Livewire::test(ListAuditEvents::class)->instance()->getTable();

        foreach ($table->getColumns() as $column) {
            $this->assertFalse(
                method_exists($column, 'isEditable') && $column->isEditable(),
                "Column [{$column->getName()}] must not be inline editable.",
            );
        }
    }

    public function test_the_audit_event_model_stays_immutable(): void
    {
        $event = $this->makeEvent();

        $event->reason = 'tampered';
        $this->expectException(AuditImmutableRecordException::class);
        $event->save();
    }

    public function test_the_audit_event_model_cannot_be_deleted(): void
    {
        $event = $this->makeEvent();

        $this->expectException(AuditImmutableRecordException::class);
        $event->delete();
    }

    // ---- §8 no recursive auditing -------------------------------------------

    public function test_opening_the_list_page_creates_no_audit_event(): void
    {
        $this->makeEvent();

        $this->actingAs($this->superAdmin());

        $before = AuditEvent::count();
        $this->get(self::LIST_URL)->assertOk();

        $this->assertSame($before, AuditEvent::count());
    }

    public function test_opening_a_detail_page_creates_no_audit_event(): void
    {
        $event = $this->makeEvent();

        $this->actingAs($this->superAdmin());

        $before = AuditEvent::count();
        $this->get($this->viewUrl($event))->assertOk();
        Livewire::test(ViewAuditEvent::class, ['record' => $event->getKey()])->assertOk();

        $this->assertSame($before, AuditEvent::count());
    }

    public function test_filtering_searching_and_paginating_create_no_audit_event(): void
    {
        foreach (range(1, 15) as $i) {
            $this->makeEvent(['subject_key' => (string) $i, 'subject_label' => "سجل {$i}"]);
        }

        $this->actingAs($this->superAdmin());

        $before = AuditEvent::count();

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.event_category.value', 'crud')
            ->set('tableSearch', 'سجل 3')
            ->call('sortTable', 'created_at', 'asc')
            ->call('gotoPage', 2, 'page')
            ->assertOk();

        $this->assertSame($before, AuditEvent::count());
    }

    public function test_no_class_in_the_resource_namespace_references_the_audit_logger(): void
    {
        $files = array_merge(
            glob(app_path('Filament/Resources/AuditEvents/*.php')) ?: [],
            glob(app_path('Filament/Resources/AuditEvents/*/*.php')) ?: [],
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            // Comments are stripped first: the classes' own docblocks
            // deliberately NAME the write paths they must never call, and a
            // raw string search would flag that documentation as a violation.
            $code = self::codeWithoutComments((string) file_get_contents($file));

            foreach (['AuditLogger', 'AuditRecordRequest', 'AuditsRecordCreation', 'AuditsRecordUpdate', 'AuditedActions', 'AuditRecorder'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    basename($file).' must not reference '.$forbidden.' — viewing the audit log must never write to it.',
                );
            }
        }
    }

    private static function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
