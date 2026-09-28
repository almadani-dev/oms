<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\Attachments\AttachmentResource;
use App\Models\Attachment;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExpense;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Topbar global search over the attachment registry must offer exactly the
 * rows the registry list offers (FinancialAttachmentRegistry::scopeViewableBy):
 * allowed attachable types only, with a live parent, and never a trashed
 * attachment. Asserted both on the final results and on the base query, so
 * the scope is proven to live in SQL and not only in Filament's per-result
 * canView() filtering.
 */
class AttachmentGlobalSearchScopeTest extends TestCase
{
    use IntegrityTestFixtures;

    private const RECEIPT_ACCESS = ['attachments.view_any', 'attachments.view', 'project_cost_receipts.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_user_who_can_view_the_parent_module_finds_the_attachment(): void
    {
        $attachment = $this->attach($this->makeReceipt(), 'receipt-scan.jpg');

        $this->actingAs($this->userWith(self::RECEIPT_ACCESS));

        $this->assertTrue(AttachmentResource::canGloballySearch());
        $this->assertSame(['receipt-scan.jpg'], $this->resultTitles('receipt-scan'));
        $this->assertTrue($this->baseQueryContains($attachment));
    }

    public function test_b_a_user_without_the_parent_module_permission_does_not_find_it(): void
    {
        $receiptAttachment = $this->attach($this->makeReceipt(), 'receipt-scan.jpg');
        $expenseAttachment = $this->attach($this->makeExpense(), 'expense-scan.jpg');

        // Full registry access, but only the general-expenses parent module.
        $this->actingAs($this->userWith(['attachments.view_any', 'attachments.view', 'general_expenses.view']));

        $this->assertSame(['expense-scan.jpg'], $this->resultTitles('scan'));
        $this->assertFalse($this->baseQueryContains($receiptAttachment));
        $this->assertTrue($this->baseQueryContains($expenseAttachment));
    }

    public function test_b2_a_user_with_no_parent_module_permission_gets_an_empty_query(): void
    {
        $this->attach($this->makeReceipt(), 'receipt-scan.jpg');

        $this->actingAs($this->userWith(['attachments.view_any', 'attachments.view']));

        $this->assertSame([], $this->resultTitles('receipt-scan'));
        $this->assertSame(0, AttachmentResource::getGlobalSearchEloquentQuery()->count());
    }

    public function test_c_super_admin_still_finds_an_allowed_attachment(): void
    {
        $receiptAttachment = $this->attach($this->makeReceipt(), 'receipt-scan.jpg');
        $expenseAttachment = $this->attach($this->makeExpense(), 'expense-scan.jpg');

        $this->actingAs($this->superAdmin());

        $this->assertEqualsCanonicalizing(['receipt-scan.jpg', 'expense-scan.jpg'], $this->resultTitles('scan'));
        $this->assertTrue($this->baseQueryContains($receiptAttachment));
        $this->assertTrue($this->baseQueryContains($expenseAttachment));
    }

    public function test_d_a_soft_deleted_attachment_stays_excluded(): void
    {
        $attachment = $this->attach($this->makeReceipt(), 'receipt-scan.jpg');
        $attachment->delete();

        $this->actingAs($this->superAdmin());

        $this->assertSoftDeleted($attachment);
        $this->assertSame([], $this->resultTitles('receipt-scan'));
        $this->assertFalse($this->baseQueryContains($attachment));
    }

    /**
     * The case Filament's canView() alone did not cover: the actor holds the
     * parent-module permission, but the parent itself is soft-deleted, so the
     * registry list already hides the row and global search must as well.
     */
    public function test_e_an_attachment_whose_parent_is_soft_deleted_stays_excluded(): void
    {
        $receipt = $this->makeReceipt();
        $attachment = $this->attach($receipt, 'receipt-scan.jpg');
        $receipt->delete();

        $this->actingAs($this->userWith(self::RECEIPT_ACCESS));

        $this->assertSoftDeleted($receipt);
        $this->assertSame([], $this->resultTitles('receipt-scan'));
        $this->assertFalse($this->baseQueryContains($attachment));
    }

    // ---------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function resultTitles(string $search): array
    {
        return AttachmentResource::getGlobalSearchResults($search)
            ->map(fn (GlobalSearchResult $result): string => (string) $result->title)
            ->values()
            ->all();
    }

    private function baseQueryContains(Attachment $attachment): bool
    {
        return AttachmentResource::getGlobalSearchEloquentQuery()->whereKey($attachment->getKey())->exists();
    }

    private function usd(): Currency
    {
        return Currency::firstOrCreate(['code' => 'USD'], ['name' => 'دولار', 'symbol' => '$']);
    }

    private function newTransaction(): Transaction
    {
        return Transaction::create([
            'fiscal_year_id' => FiscalYear::create(['name' => 'سنة '.uniqid(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true])->id,
            'transaction_type_id' => TransactionType::create(['name' => 'نوع '.uniqid()])->id,
            'transaction_number' => 'TXN-'.uniqid(),
            'transaction_time' => now(),
        ]);
    }

    private function makeReceipt(): ProjectCostReceipt
    {
        $project = Project::create([
            'name' => 'مشروع تجريبي',
            'project_super_id' => ProjectSuper::create(['name' => 'رئيسي '.uniqid(), 'code_prefix' => 'P'.substr(uniqid(), -4)])->id,
            'project_status_id' => ProjectStatus::create(['name' => 'نشط '.uniqid()])->id,
        ]);

        $cost = ProjectCost::create(['project_id' => $project->id, 'amount' => 1000, 'currency_id' => $this->usd()->id]);

        return ProjectCostReceipt::create([
            'project_cost_id' => $cost->id,
            'amount' => 100,
            'currency_id' => $cost->currency_id,
            'date' => '2026-07-01',
            'transaction_id' => $this->newTransaction()->id,
        ]);
    }

    private function makeExpense(): GeneralExpense
    {
        return GeneralExpense::create([
            'amount' => 100,
            'currency_id' => $this->usd()->id,
            'date' => '2026-07-01',
            'transaction_id' => $this->newTransaction()->id,
        ]);
    }

    /**
     * Metadata row only — global search never touches the file, so no bytes
     * are written to any disk.
     */
    private function attach(Model $parent, string $fileName): Attachment
    {
        return Attachment::create([
            'attachable_type' => get_class($parent),
            'attachable_id' => $parent->id,
            'file_name' => $fileName,
            'file_path' => 'receipts/stored_'.uniqid().'.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 2048,
            'disk' => Attachment::DISK_ATTACHMENTS,
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }
}
