<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\Attachments\AttachmentResource;
use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\Attachments\Pages\ViewAttachment;
use App\Models\Attachment;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Attachments\FinancialAttachmentRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * OMS Task 6D: the secure, read-only financial attachment registry
 * (AttachmentResource re-enabled in the sidebar). Proves navigation/access
 * gating on attachments.view_any, parent-module scoping (a row is only listed/
 * viewable when the actor can view its parent operation), the Arabic display
 * columns from the approved denormalized parent fields, secure-route-only
 * preview/download, soft-deleted/missing-file safety, the structural read-only
 * guarantees kept from Task 6A, filters/search, and eager-loaded (no N+1)
 * polymorphic loading.
 *
 * Same schema-only SQLite bootstrap + Storage::fake() as the rest of the
 * Attachments suite; no real DB row or file is ever touched.
 */
class AttachmentRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        URL::forceRootUrl('http://localhost');

        Storage::fake('public');
        Storage::fake('attachments');
    }

    // =====================================================================
    // NAVIGATION / ACCESS
    // =====================================================================

    public function test_1_user_without_view_any_cannot_open_the_registry(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/attachments')->assertForbidden();
    }

    public function test_2_user_with_view_any_can_open_the_registry(): void
    {
        $this->actingAs($this->userWith(['attachments.view_any']));

        $this->get('/admin/attachments')->assertOk();
    }

    public function test_3_navigation_appears_with_view_any(): void
    {
        $this->actingAs($this->userWith(['attachments.view_any']));

        $this->get('/admin')->assertOk()->assertSee(AttachmentResource::getUrl(), false);
    }

    public function test_4_navigation_hidden_without_view_any(): void
    {
        // A harmless unrelated permission so the dashboard still loads.
        $this->actingAs($this->userWith(['projects.view_any']));

        $this->get('/admin')->assertOk()->assertDontSee(AttachmentResource::getUrl(), false);
    }

    public function test_5_super_admin_sees_the_registry(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/admin/attachments')->assertOk();
        $this->get('/admin')->assertOk()->assertSee(AttachmentResource::getUrl(), false);
    }

    public function test_6_direct_view_requires_attachments_view(): void
    {
        $receipt = $this->makeReceipt();
        $attachment = $this->attach($receipt);

        // Has the parent permission and view_any, but NOT attachments.view.
        $this->actingAs($this->userWith(['attachments.view_any', 'project_cost_receipts.view']));

        $this->get("/admin/attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_7_permission_from_another_module_does_not_grant_registry_access(): void
    {
        $this->actingAs($this->userWith(['general_expenses.view_any', 'general_expenses.view']));

        // general_expenses.* is a real financial permission but not
        // attachments.view_any, so the registry itself stays closed.
        $this->get('/admin/attachments')->assertForbidden();
    }

    // =====================================================================
    // PARENT-PERMISSION SCOPING
    // =====================================================================

    public function test_8_and_9_receipt_permission_sees_receipts_but_not_execution_payments(): void
    {
        $receiptAttachment = $this->attach($this->makeReceipt());
        $executionAttachment = $this->attach($this->makePayment());

        $this->actingAs($this->userWith(['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']));

        Livewire::test(ListAttachments::class)
            ->assertCanSeeTableRecords([$receiptAttachment])
            ->assertCanNotSeeTableRecords([$executionAttachment]);
    }

    public function test_10_general_expense_permission_sees_only_general_expense_rows(): void
    {
        $expenseAttachment = $this->attach($this->makeExpense());
        $receiptAttachment = $this->attach($this->makeReceipt());
        $exchangeAttachment = $this->attach($this->makeExchange());

        $this->actingAs($this->userWith(['attachments.view_any', 'attachments.view', 'general_expenses.view']));

        Livewire::test(ListAttachments::class)
            ->assertCanSeeTableRecords([$expenseAttachment])
            ->assertCanNotSeeTableRecords([$receiptAttachment, $exchangeAttachment]);
    }

    public function test_11_super_admin_sees_all_five_supported_types(): void
    {
        $rows = [
            $this->attach($this->makeReceipt()),
            $this->attach($this->makeBudget()),
            $this->attach($this->makePayment()),
            $this->attach($this->makeExpense()),
            $this->attach($this->makeExchange()),
        ];

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAttachments::class)->assertCanSeeTableRecords($rows);
    }

    public function test_12_view_any_only_sees_no_rows(): void
    {
        $rows = [
            $this->attach($this->makeReceipt()),
            $this->attach($this->makeExpense()),
        ];

        $this->actingAs($this->userWith(['attachments.view_any', 'attachments.view']));

        Livewire::test(ListAttachments::class)
            ->assertCanNotSeeTableRecords($rows)
            ->assertCountTableRecords(0);
    }

    public function test_13_direct_record_view_is_403_without_the_matching_parent_permission(): void
    {
        $executionAttachment = $this->attach($this->makePayment());

        // Full attachments.* plus a DIFFERENT parent permission - still 403,
        // because it is not execution_payments.view.
        $this->actingAs($this->userWith(['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']));

        $this->get("/admin/attachments/{$executionAttachment->id}")->assertForbidden();
    }

    public function test_14_crafted_action_cannot_open_an_unauthorized_attachment(): void
    {
        $executionAttachment = $this->attach($this->makePayment());

        $user = $this->userWith(['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']);
        $this->actingAs($user);

        // The authorization gate any crafted table/URL action must pass.
        $this->assertFalse(AttachmentResource::canView($executionAttachment));
        // And a genuine mount of the View page for that record is refused.
        $this->get("/admin/attachments/{$executionAttachment->id}")->assertForbidden();
    }

    // =====================================================================
    // DISPLAY
    // =====================================================================

    public static function operationTypeProvider(): array
    {
        return [
            'receipt' => [ProjectCostReceipt::class, 'مبلغ مستلم'],
            'budget' => [ProjectCostBudget::class, 'صرف مبلغ مشروع'],
            'payment' => [ProjectCostBudgetsPayment::class, 'صرف مبلغ تنفيذ'],
            'expense' => [GeneralExpense::class, 'مصروف عام'],
            'exchange' => [GeneralExchange::class, 'تحويل عام'],
        ];
    }

    #[DataProvider('operationTypeProvider')]
    public function test_15_arabic_operation_type_is_correct_for_all_five_models(string $type, string $label): void
    {
        $this->assertSame($label, FinancialAttachmentRegistry::labelFor($type));
    }

    public function test_16_operation_number_is_resolved_from_the_parent_transaction(): void
    {
        $receipt = $this->makeReceipt(transactionNumber: 'TXN-OPNUM-001');
        $attachment = $this->attach($receipt);

        $this->assertSame('TXN-OPNUM-001', FinancialAttachmentRegistry::operationNumber($attachment->fresh()));
        $this->assertStringContainsString('TXN-OPNUM-001', $this->viewHtml($attachment, ['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']));
    }

    public function test_17_project_is_shown_where_applicable_and_dashed_otherwise(): void
    {
        $receiptAttachment = $this->attach($this->makeReceipt(projectName: 'مشروع الإغاثة'));
        $expenseAttachment = $this->attach($this->makeExpense());

        $this->assertSame('مشروع الإغاثة', FinancialAttachmentRegistry::projectName($receiptAttachment->fresh()));
        $this->assertNull(FinancialAttachmentRegistry::projectName($expenseAttachment->fresh()));
    }

    public function test_18_amount_and_currency_are_correct_and_never_mixed(): void
    {
        $receiptAttachment = $this->attach($this->makeReceipt());
        $exchangeAttachment = $this->attach($this->makeExchange());

        // Single-currency receipt: its own amount + currency.
        $this->assertSame('100.00 USD', FinancialAttachmentRegistry::amountWithCurrency($receiptAttachment->fresh()));
        // Multi-currency exchange: post-fx final_amount + disbursement currency.
        $this->assertSame('90.00 USD', FinancialAttachmentRegistry::amountWithCurrency($exchangeAttachment->fresh()));
    }

    public function test_19_20_filename_mime_size_and_uploader_display_on_the_view_page(): void
    {
        $uploader = User::factory()->create(['name' => 'رافع الملف']);
        $receipt = $this->makeReceipt();
        $attachment = $this->attach($receipt, mime: 'application/pdf', fileName: 'invoice-visible.pdf');
        $attachment->forceFill(['created_by' => $uploader->id])->save();

        $html = $this->viewHtml($attachment, ['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']);

        $this->assertStringContainsString('invoice-visible.pdf', $html);
        $this->assertStringContainsString('application/pdf', $html);
        $this->assertStringContainsString('رافع الملف', $html);
        $this->assertStringContainsString('KB', $html);
    }

    public function test_21_private_disk_is_labeled_khass(): void
    {
        $this->assertSame('خاص', FinancialAttachmentRegistry::diskLabel(Attachment::DISK_ATTACHMENTS));
    }

    public function test_22_public_transitional_disk_is_labeled_safely(): void
    {
        $this->assertSame('عام انتقالي', FinancialAttachmentRegistry::diskLabel(Attachment::DISK_PUBLIC));
        $this->assertSame('قرص غير معروف', FinancialAttachmentRegistry::diskLabel('some_unknown_disk'));
    }

    public function test_23_and_24_image_preview_and_download_use_the_protected_route(): void
    {
        $receipt = $this->makeReceipt();
        $attachment = $this->attach($receipt, mime: 'image/jpeg', fileName: 'photo.jpg');

        $html = $this->viewHtml($attachment, ['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString(e(route('attachments.show', [$attachment->id, 'view'])), $html);
        $this->assertStringContainsString(e(route('attachments.show', [$attachment->id, 'download'])), $html);
    }

    public function test_25_and_26_no_raw_storage_url_or_stored_path_is_rendered(): void
    {
        $receipt = $this->makeReceipt();
        $attachment = $this->attach($receipt, path: 'execution-payments/secret-internal-path.jpg', fileName: 'friendly-name.jpg');

        $html = $this->viewHtml($attachment, ['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']);

        $this->assertStringNotContainsString('/storage/', $html);
        $this->assertStringNotContainsString('secret-internal-path', $html);
        $this->assertStringContainsString('friendly-name.jpg', $html);
    }

    public function test_27_pdf_non_image_renders_a_secure_file_card(): void
    {
        $receipt = $this->makeReceipt();
        $attachment = $this->attach($receipt, mime: 'application/pdf', fileName: 'statement.pdf');

        $html = $this->viewHtml($attachment, ['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('statement.pdf', $html);
        $this->assertStringContainsString(e(route('attachments.show', [$attachment->id, 'download'])), $html);
    }

    public function test_28_missing_file_shows_a_safe_state(): void
    {
        $receipt = $this->makeReceipt();
        // putFile: false -> the row exists but no bytes on disk.
        $attachment = $this->attach($receipt, putFile: false);

        $html = $this->viewHtml($attachment, ['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']);

        $this->assertStringContainsString('تعذر العثور على ملف المرفق', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_29_soft_deleted_attachment_shows_no_preview_or_download(): void
    {
        $receipt = $this->makeReceipt();
        $attachment = $this->attach($receipt);
        $attachment->delete();

        $html = $this->viewHtml($attachment, ['attachments.view_any', 'attachments.view', 'project_cost_receipts.view']);

        $this->assertStringContainsString('محذوف منطقيًا', $html);
        $this->assertStringNotContainsString(e(route('attachments.show', [$attachment->id, 'view'])), $html);
        $this->assertStringNotContainsString(e(route('attachments.show', [$attachment->id, 'download'])), $html);
    }

    // =====================================================================
    // FILTERS / SEARCH
    // =====================================================================

    public function test_30_operation_type_filter_works(): void
    {
        $receiptAttachment = $this->attach($this->makeReceipt());
        $expenseAttachment = $this->attach($this->makeExpense());

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAttachments::class)
            ->filterTable('attachable_type', ProjectCostReceipt::class)
            ->assertCanSeeTableRecords([$receiptAttachment])
            ->assertCanNotSeeTableRecords([$expenseAttachment]);
    }

    public function test_31_project_filter_works(): void
    {
        $project = $this->makeProject('مشروع المياه');
        $receiptInProject = $this->attach($this->makeReceipt(project: $project));
        $receiptElsewhere = $this->attach($this->makeReceipt());

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAttachments::class)
            ->filterTable('project', $project->id)
            ->assertCanSeeTableRecords([$receiptInProject])
            ->assertCanNotSeeTableRecords([$receiptElsewhere]);
    }

    public function test_32_disk_filter_works(): void
    {
        $privateAttachment = $this->attach($this->makeReceipt(), disk: Attachment::DISK_ATTACHMENTS);
        $publicAttachment = $this->attach($this->makeReceipt(), disk: Attachment::DISK_PUBLIC);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAttachments::class)
            ->filterTable('disk', Attachment::DISK_ATTACHMENTS)
            ->assertCanSeeTableRecords([$privateAttachment])
            ->assertCanNotSeeTableRecords([$publicAttachment]);
    }

    public function test_33_active_versus_deleted_filter_works(): void
    {
        $active = $this->attach($this->makeReceipt());
        $trashed = $this->attach($this->makeReceipt());
        $trashed->delete();

        $this->actingAs($this->superAdmin());

        // Default: active only.
        Livewire::test(ListAttachments::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$trashed]);

        // Only-trashed state of the TrashedFilter reveals the deleted row.
        Livewire::test(ListAttachments::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$trashed])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_34_filename_search_works(): void
    {
        $matching = $this->attach($this->makeReceipt(), fileName: 'unique-invoice-abc.pdf');
        $other = $this->attach($this->makeReceipt(), fileName: 'other-file.pdf');

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAttachments::class)
            ->searchTable('unique-invoice-abc')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_35_operation_number_search_works(): void
    {
        $matching = $this->attach($this->makeReceipt(transactionNumber: 'TXN-SEARCHME-777'));
        $other = $this->attach($this->makeReceipt(transactionNumber: 'TXN-OTHER-888'));

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAttachments::class)
            ->searchTable('SEARCHME-777')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    // =====================================================================
    // READ-ONLY GUARANTEES
    // =====================================================================

    public function test_36_and_37_no_create_or_edit_route_exists(): void
    {
        $attachment = $this->attach($this->makeReceipt());

        $this->actingAs($this->superAdmin());

        $this->get('/admin/attachments/create')->assertNotFound();
        $this->get("/admin/attachments/{$attachment->id}/edit")->assertNotFound();
        $this->assertSame(['index', 'view'], array_keys(AttachmentResource::getPages()));
    }

    public function test_38_39_40_every_mutation_ability_is_hard_denied_even_for_super_admin(): void
    {
        $attachment = $this->attach($this->makeReceipt());

        $this->actingAs($this->superAdmin());

        $this->assertFalse(AttachmentResource::canCreate());
        $this->assertFalse(AttachmentResource::canEdit($attachment));
        $this->assertFalse(AttachmentResource::canDelete($attachment));
        $this->assertFalse(AttachmentResource::canDeleteAny());
        $this->assertFalse(AttachmentResource::canRestore($attachment));
        $this->assertFalse(AttachmentResource::canRestoreAny());
        $this->assertFalse(AttachmentResource::canForceDelete($attachment));
        $this->assertFalse(AttachmentResource::canForceDeleteAny());
    }

    public function test_39_table_registers_no_bulk_or_mutation_actions(): void
    {
        $this->attach($this->makeReceipt());
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(ListAttachments::class);

        // Only the read-only row actions exist; no bulk actions at all.
        $component->assertTableActionExists('view');
        $component->assertTableActionExists('open');
        $component->assertTableActionExists('download');
        $component->assertTableBulkActionDoesNotExist('delete');
        $component->assertTableActionDoesNotExist('edit');
        $component->assertTableActionDoesNotExist('delete');
    }

    public function test_41_no_file_upload_form_exists_in_the_resource(): void
    {
        // The AttachmentForm class file (which held the only FileUpload) was
        // deleted; checked by file path rather than class_exists() so a stale
        // classmap entry cannot autoload a now-missing file.
        $this->assertFileDoesNotExist(
            app_path('Filament/Resources/Attachments/Schemas/AttachmentForm.php'),
        );

        // The resource must not DECLARE its own form() (the base Filament
        // Resource has a default one) - so it cannot host a FileUpload schema.
        $declaringClass = (new \ReflectionMethod(AttachmentResource::class, 'form'))
            ->getDeclaringClass()
            ->getName();

        $this->assertNotSame(
            AttachmentResource::class,
            $declaringClass,
            'AttachmentResource must not declare its own form()/FileUpload schema.',
        );
    }

    // =====================================================================
    // PERFORMANCE / REGRESSION
    // =====================================================================

    public function test_42_polymorphic_relations_are_eager_loaded_without_n_plus_one(): void
    {
        $superAdmin = $this->superAdmin();

        // One attachment of each of the five types.
        $this->attach($this->makeReceipt());
        $this->attach($this->makeBudget());
        $this->attach($this->makePayment());
        $this->attach($this->makeExpense());
        $this->attach($this->makeExchange());

        $small = $this->countTableQueries($superAdmin);

        // A second attachment of each type (row count doubled, types unchanged).
        $this->attach($this->makeReceipt());
        $this->attach($this->makeBudget());
        $this->attach($this->makePayment());
        $this->attach($this->makeExpense());
        $this->attach($this->makeExchange());

        $large = $this->countTableQueries($superAdmin);

        // The proof of no N+1 is that doubling the row count does not increase
        // the query count - the polymorphic parent (and its per-type nested
        // relations) load in a fixed number of batched queries regardless of
        // how many rows are on the page.
        $this->assertLessThanOrEqual(
            $small,
            $large,
            "Query count grew from {$small} to {$large} when rows doubled - polymorphic parent is not eager-loaded (N+1).",
        );
    }

    /**
     * The registry's supported-type list (UI layer) must stay identical to
     * AttachmentController::SUPPORTED_ATTACHABLE_TYPES (the Task 6A security
     * boundary for serving bytes). The controller was deliberately left
     * untouched; this guard catches any future drift between the two lists.
     */
    public function test_supported_types_match_the_secure_controller_allowlist(): void
    {
        $reflection = new ReflectionClass(\App\Http\Controllers\Attachments\AttachmentController::class);
        $controllerTypes = $reflection->getConstant('SUPPORTED_ATTACHABLE_TYPES');

        sort($controllerTypes);
        $registryTypes = FinancialAttachmentRegistry::supportedTypes();
        sort($registryTypes);

        $this->assertSame($controllerTypes, $registryTypes);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function viewHtml(Attachment $attachment, array $permissions): string
    {
        $this->actingAs($this->userWith($permissions));

        return Livewire::test(ViewAttachment::class, ['record' => $attachment->getKey()])->html();
    }

    private function countTableQueries(User $user): int
    {
        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListAttachments::class)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function usd(): Currency
    {
        return Currency::firstOrCreate(['code' => 'USD'], ['name' => 'دولار', 'symbol' => '$']);
    }

    private function makeProject(string $name = 'مشروع تجريبي'): Project
    {
        $super = ProjectSuper::create(['name' => 'رئيسي '.uniqid(), 'code_prefix' => 'P'.substr(uniqid(), -4)]);
        $status = ProjectStatus::create(['name' => 'نشط '.uniqid()]);

        return Project::create([
            'name' => $name,
            'project_super_id' => $super->id,
            'project_status_id' => $status->id,
        ]);
    }

    private function makeProjectCost(?Project $project = null): ProjectCost
    {
        $project ??= $this->makeProject();

        return ProjectCost::create([
            'project_id' => $project->id,
            'amount' => 1000,
            'currency_id' => $this->usd()->id,
        ]);
    }

    private function makeTransaction(string $transactionNumber = null): Transaction
    {
        $fiscalYear = FiscalYear::create(['name' => 'سنة '.uniqid(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة '.uniqid()]);

        return Transaction::create([
            'fiscal_year_id' => $fiscalYear->id,
            'transaction_type_id' => $transactionType->id,
            'transaction_number' => $transactionNumber ?? ('TXN-'.uniqid()),
            'transaction_time' => now(),
        ]);
    }

    private function makeReceipt(?Project $project = null, string $projectName = 'مشروع تجريبي', ?string $transactionNumber = null): ProjectCostReceipt
    {
        $cost = $this->makeProjectCost($project ?? $this->makeProject($projectName));

        return ProjectCostReceipt::create([
            'project_cost_id' => $cost->id,
            'amount' => 100,
            'currency_id' => $cost->currency_id,
            'date' => '2026-07-01',
            'transaction_id' => $this->makeTransaction($transactionNumber)->id,
        ]);
    }

    private function makeBudget(?Project $project = null): ProjectCostBudget
    {
        $cost = $this->makeProjectCost($project);

        return ProjectCostBudget::create([
            'project_cost_id' => $cost->id,
            'transaction_id' => $this->makeTransaction()->id,
            'original_amount' => 500,
            'final_amount' => 500,
            'source_currency_id' => $this->usd()->id,
            'disbursement_currency_id' => $this->usd()->id,
        ]);
    }

    private function makePayment(?Project $project = null): ProjectCostBudgetsPayment
    {
        $budget = $this->makeBudget($project);

        return ProjectCostBudgetsPayment::create([
            'project_cost_budget_id' => $budget->id,
            'amount' => 50,
            'currency_id' => $this->usd()->id,
            'date' => '2026-07-01',
            'transaction_id' => $this->makeTransaction()->id,
        ]);
    }

    private function makeExpense(): GeneralExpense
    {
        return GeneralExpense::create([
            'amount' => 100,
            'currency_id' => $this->usd()->id,
            'date' => '2026-07-01',
            'transaction_id' => $this->makeTransaction()->id,
        ]);
    }

    private function makeExchange(): GeneralExchange
    {
        return GeneralExchange::create([
            'original_amount' => 100,
            'final_amount' => 90,
            'source_currency_id' => $this->usd()->id,
            'disbursement_currency_id' => $this->usd()->id,
            'date' => '2026-07-01',
            'transaction_id' => $this->makeTransaction()->id,
        ]);
    }

    private function attach(
        Model $parent,
        string $disk = Attachment::DISK_ATTACHMENTS,
        string $mime = 'image/jpeg',
        string $fileName = 'invoice.jpg',
        bool $putFile = true,
        ?string $path = null,
    ): Attachment {
        $path ??= 'receipts/stored_'.uniqid().'.'.pathinfo($fileName, PATHINFO_EXTENSION);

        if ($putFile) {
            Storage::disk($disk)->put($path, 'fake-file-bytes');
        }

        return Attachment::create([
            'attachable_type' => get_class($parent),
            'attachable_id' => $parent->id,
            'file_name' => $fileName,
            'file_path' => $path,
            'file_type' => $mime,
            'file_size' => 2048,
            'disk' => $disk,
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function superAdmin(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => \App\Support\Permissions\PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(\App\Support\Permissions\PermissionRegistry::SUPER_ADMIN);

        return $user;
    }
}
