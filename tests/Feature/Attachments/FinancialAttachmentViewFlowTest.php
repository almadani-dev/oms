<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\ExecutionPayments\Pages\ViewExecutionPayment;
use App\Filament\Resources\GeneralExchanges\Pages\ViewGeneralExchange;
use App\Filament\Resources\GeneralExpenses\Pages\ViewGeneralExpense;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\ViewProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostReceipts\Pages\ViewProjectCostReceipt;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * OMS Task 6B: proves the secure-attachment-preview component's rendered
 * output on all five financial View pages - inline image preview, secure
 * view/download links, no raw '/storage/' URL, no Storage::url() output, a
 * clear empty state, safe non-image rendering, transitional public/private
 * disk support, and exclusion of soft-deleted attachments.
 *
 * Uses the same schema-only SQLite bootstrap and minimal-parent factory
 * helpers as AttachmentAccessTest (Task 6A), plus real Livewire::test() of
 * the ViewRecord pages themselves - genuinely exercising the new Blade
 * component rather than only the underlying route.
 */
class FinancialAttachmentViewFlowTest extends TestCase
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

    public static function viewPages(): array
    {
        return [
            'general_expenses' => ['general_expenses', ViewGeneralExpense::class],
            'general_exchanges' => ['general_exchanges', ViewGeneralExchange::class],
            'project_cost_budgets_payments' => ['project_cost_budgets_payments', ViewProjectCostBudgetsPayment::class],
            'project_cost_receipts' => ['project_cost_receipts', ViewProjectCostReceipt::class],
            'execution_payments' => ['execution_payments', ViewExecutionPayment::class],
        ];
    }

    /**
     * Four of the five Resources scope their getEloquentQuery() to
     * whereNotNull('transaction_id') (only ProjectCostReceiptResource does
     * not), so a parent record reachable through the real Livewire
     * ViewRecord page - not just through the AttachmentController route
     * directly - needs a real transaction_id, unlike Task 6A's
     * AttachmentAccessTest helpers which query the controller directly.
     */
    private function makeParent(string $module): object
    {
        return match ($module) {
            'general_expenses' => GeneralExpense::create(['amount' => 100, 'date' => '2026-07-01', 'transaction_id' => $this->makeTransaction()->id]),
            'general_exchanges' => GeneralExchange::create(['original_amount' => 100, 'final_amount' => 100, 'date' => '2026-07-01', 'transaction_id' => $this->makeTransaction()->id]),
            'project_cost_budgets_payments' => ProjectCostBudget::create(['project_cost_id' => $this->makeProjectCost()->id, 'transaction_id' => $this->makeTransaction()->id]),
            'project_cost_receipts' => ProjectCostReceipt::create([
                'project_cost_id' => ($cost = $this->makeProjectCost())->id,
                'amount' => 100,
                'currency_id' => $cost->currency_id,
                'date' => '2026-07-01',
            ]),
            'execution_payments' => ProjectCostBudgetsPayment::create(['amount' => 50, 'date' => '2026-07-01', 'transaction_id' => $this->makeTransaction()->id]),
        };
    }

    private function makeTransaction(): Transaction
    {
        $fiscalYear = FiscalYear::create(['name' => 'سنة '.uniqid(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة '.uniqid()]);

        return Transaction::create([
            'fiscal_year_id' => $fiscalYear->id,
            'transaction_type_id' => $transactionType->id,
            'transaction_number' => 'VIEW-'.uniqid(),
            'transaction_time' => now(),
        ]);
    }

    private function makeProjectCost(): ProjectCost
    {
        $super = ProjectSuper::create(['name' => 'مشروع رئيسي '.uniqid(), 'code_prefix' => 'V'.substr(uniqid(), -4)]);
        $status = ProjectStatus::create(['name' => 'نشط '.uniqid()]);
        $project = Project::create([
            'name' => 'مشروع تجريبي',
            'project_super_id' => $super->id,
            'project_status_id' => $status->id,
        ]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD', 'symbol' => 'USD']);

        return ProjectCost::create(['project_id' => $project->id, 'amount' => 1000, 'currency_id' => $currency->id]);
    }

    private function putAndAttach(string $attachableType, int $attachableId, string $disk, string $mime = 'image/jpeg', string $fileName = 'gen_1_20260701_100.jpg'): Attachment
    {
        // The directory choice is irrelevant to these rendering assertions.
        $path = 'general-expenses/'.$fileName;

        Storage::disk($disk)->put($path, 'fake-file-bytes');

        return Attachment::create([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
            'file_name' => $fileName,
            'file_path' => $path,
            'file_type' => $mime,
            'file_size' => 16,
            'disk' => $disk,
        ]);
    }

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    // =========================================================
    // Inline image preview + secure links
    // =========================================================

    #[DataProvider('viewPages')]
    public function test_image_attachment_renders_inline_with_secure_view_and_download_links(string $module, string $pageClass): void
    {
        $parent = $this->makeParent($module);
        $attachment = $this->putAndAttach(get_class($parent), $parent->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->userWithPermissions(["{$module}.view_any", "{$module}.view"]));

        $html = Livewire::test($pageClass, ['record' => $parent->getKey()])->html();

        $viewUrl = route('attachments.show', [$attachment->id, 'view']);
        $downloadUrl = route('attachments.show', [$attachment->id, 'download']);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString(e($viewUrl), $html);
        $this->assertStringContainsString(e($downloadUrl), $html);
        $this->assertStringContainsString('عرض بالحجم الكامل', $html);
        $this->assertStringContainsString('تنزيل', $html);
    }

    #[DataProvider('viewPages')]
    public function test_no_raw_storage_url_or_storage_facade_url_is_rendered(string $module, string $pageClass): void
    {
        $parent = $this->makeParent($module);
        $this->putAndAttach(get_class($parent), $parent->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->userWithPermissions(["{$module}.view_any", "{$module}.view"]));

        $html = Livewire::test($pageClass, ['record' => $parent->getKey()])->html();

        $this->assertStringNotContainsString('/storage/', $html);
    }

    #[DataProvider('viewPages')]
    public function test_non_image_attachment_renders_a_safe_file_card(string $module, string $pageClass): void
    {
        $parent = $this->makeParent($module);
        $attachment = $this->putAndAttach(get_class($parent), $parent->id, Attachment::DISK_ATTACHMENTS, mime: 'application/pdf', fileName: 'gen_1_20260701_100.pdf');

        $this->actingAs($this->userWithPermissions(["{$module}.view_any", "{$module}.view"]));

        $html = Livewire::test($pageClass, ['record' => $parent->getKey()])->html();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString($attachment->file_name, $html);
        $this->assertStringContainsString(e(route('attachments.show', [$attachment->id, 'download'])), $html);
    }

    #[DataProvider('viewPages')]
    public function test_no_attachment_shows_a_clear_arabic_empty_state(string $module, string $pageClass): void
    {
        $parent = $this->makeParent($module);

        $this->actingAs($this->userWithPermissions(["{$module}.view_any", "{$module}.view"]));

        $html = Livewire::test($pageClass, ['record' => $parent->getKey()])->html();

        $this->assertStringContainsString('لا يوجد مرفق حالي', $html);
    }

    #[DataProvider('viewPages')]
    public function test_legacy_public_disk_row_renders_through_the_secure_route(string $module, string $pageClass): void
    {
        $parent = $this->makeParent($module);
        $attachment = $this->putAndAttach(get_class($parent), $parent->id, Attachment::DISK_PUBLIC);

        $this->actingAs($this->userWithPermissions(["{$module}.view_any", "{$module}.view"]));

        $html = Livewire::test($pageClass, ['record' => $parent->getKey()])->html();

        $this->assertStringContainsString(e(route('attachments.show', [$attachment->id, 'view'])), $html);
        $this->assertStringNotContainsString('/storage/', $html);
    }

    #[DataProvider('viewPages')]
    public function test_soft_deleted_attachment_is_not_shown(string $module, string $pageClass): void
    {
        $parent = $this->makeParent($module);
        $attachment = $this->putAndAttach(get_class($parent), $parent->id, Attachment::DISK_ATTACHMENTS);
        $attachment->delete();

        $this->actingAs($this->userWithPermissions(["{$module}.view_any", "{$module}.view"]));

        $html = Livewire::test($pageClass, ['record' => $parent->getKey()])->html();

        $this->assertStringContainsString('لا يوجد مرفق حالي', $html);
        $this->assertStringNotContainsString($attachment->file_name, $html);
    }
}
