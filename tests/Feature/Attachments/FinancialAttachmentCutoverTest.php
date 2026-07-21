<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\EditExecutionPayment;
use App\Filament\Resources\GeneralExchanges\Pages\CreateGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Pages\EditGeneralExchange;
use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Attachment;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Enums\TransactionLineRole;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * OMS Task 6B: proves the Create/Edit page cutover to the private
 * 'attachments' disk for all five financial Resources, using the same
 * reflection-based direct page-handler invocation already established in
 * this codebase (see ExecutionPaymentCreditAccountTest) - handleRecordCreation
 * / handleRecordUpdate / mutateFormDataBeforeFill take their data/record as
 * plain arguments and do not depend on a full Livewire mount.
 *
 * Every case is built from the exact fixture shapes already used by the
 * existing per-resource account-validation tests, so the financial/balance
 * behavior itself is exercised unchanged - only the attachment field is new.
 */
class FinancialAttachmentCutoverTest extends TestCase
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

        $this->actingAs(User::factory()->create());

        Storage::fake('public');
        Storage::fake('attachments');
    }

    // =========================================================
    // Shared fixture builders
    // =========================================================

    private function currency(string $code = 'USD'): Currency
    {
        return Currency::create(['name' => $code, 'code' => $code, 'symbol' => $code]);
    }

    private function account(string $name, Currency $currency, AccountType $type, BankType $bankType, float $balance = 0): Account
    {
        return Account::create([
            'account_code' => $name, 'name' => $name, 'account_type_id' => $type->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id,
            'current_balance' => $balance, 'is_active' => true,
        ]);
    }

    private function fiscalYear(): FiscalYear
    {
        return FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
    }

    private function partner(bool $isDonor = false): Partner
    {
        $type = PartnerType::create(['name' => 'نوع شريك']);

        return Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $type->id, 'is_donor' => $isDonor]);
    }

    private function project(): Project
    {
        $super = ProjectSuper::create(['name' => 'مشروع رئيسي '.uniqid()]);
        $status = ProjectStatus::create(['name' => 'نشط '.uniqid()]);

        return Project::create([
            'name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id,
        ]);
    }

    /**
     * One scenario per financial Resource: the Create page class, Edit page
     * class, approved directory, approved prefix, upload field name, and
     * callables that build valid $data for create/update and locate the
     * resulting parent model.
     */
    public static function resourceScenarios(): array
    {
        return [
            'project_cost_receipts' => ['project_cost_receipts', 'receipts', 'receive', 'receipt_image'],
            'project_cost_budgets_payments' => ['project_cost_budgets_payments', 'payments', 'pay', 'payment_image'],
            'execution_payments' => ['execution_payments', 'execution-payments', 'pay', 'payment_image'],
            'general_expenses' => ['general_expenses', 'general-expenses', 'gen', 'expense_image'],
            'general_exchanges' => ['general_exchanges', 'general-exchanges', 'ext', 'exchange_image'],
        ];
    }

    /**
     * Builds a valid parent record + one active attachment for the given
     * resource key, returning [$record, $createPage, $editPage, $imageField].
     */
    private function buildRecordWithAttachment(string $resourceKey): array
    {
        Storage::disk('attachments')->put('livewire-tmp/first.jpg', 'first-bytes');

        return match ($resourceKey) {
            'project_cost_receipts' => $this->createProjectCostReceipt('livewire-tmp/first.jpg'),
            'project_cost_budgets_payments' => $this->createProjectCostBudgetsPayment('livewire-tmp/first.jpg'),
            'execution_payments' => $this->createExecutionPayment('livewire-tmp/first.jpg'),
            'general_expenses' => $this->createGeneralExpense('livewire-tmp/first.jpg'),
            'general_exchanges' => $this->createGeneralExchange('livewire-tmp/first.jpg'),
        };
    }

    // ---- per-resource Create invocations --------------------------------

    private function createProjectCostReceipt(?string $imagePath): array
    {
        $currency = $this->currency();
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType = BankType::create(['name' => 'نوع بنك']);
        $debit = $this->account('مدين', $currency, $accountType, $bankType, 0);
        $credit = $this->account('دائن', $currency, $accountType, $bankType, 5000);
        $fiscalYear = $this->fiscalYear();
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partner = $this->partner(isDonor: true);
        $project = $this->project();
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        $data = [
            'project_cost_id' => $projectCost->id,
            'amount' => 250,
            'partner_id' => $partner->id,
            'date' => '2026-07-18',
            'transaction_super_type_id' => null,
            'transaction_type_id' => $transactionType->id,
            'fiscal_year_id' => $fiscalYear->id,
            'notes' => null,
            'debit_account_id' => $debit->id,
            'debit_account_type_id' => $debit->account_type_id,
            'debit_bank_type_id' => $debit->bank_type_id,
            'credit_account_id' => $credit->id,
            'credit_account_type_id' => $credit->account_type_id,
            'credit_bank_type_id' => $credit->bank_type_id,
            'receipt_image' => $imagePath,
        ];

        $page = new CreateProjectCostReceipt();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);
        $record = $method->invoke($page, $data);

        return [$record, ProjectCostReceipt::class];
    }

    private function createProjectCostBudgetsPayment(?string $imagePath): array
    {
        $currency = $this->currency();
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType = BankType::create(['name' => 'نوع بنك']);
        $source = $this->account('مصدر', $currency, $accountType, $bankType, 5000);
        $admin = $this->account('إداري', $currency, $accountType, $bankType);
        $transfer = $this->account('تحويل', $currency, $accountType, $bankType);
        $destination = $this->account('وجهة', $currency, $accountType, $bankType);
        $fiscalYear = $this->fiscalYear();
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partner = $this->partner();
        $project = $this->project();
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        $data = [
            'project_cost_id' => $projectCost->id,
            'original_amount' => 1000,
            'administrative_percentage' => 10,
            'transfer_percentage' => 10,
            'disbursement_currency_id' => $currency->id,
            'fx_rate' => 1,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $transactionType->id,
            'fiscal_year_id' => $fiscalYear->id,
            'partner_id' => $partner->id,
            'date' => '2026-07-18',
            'notes' => null,
            'source_account_id' => $source->id,
            'source_account_type_id' => $source->account_type_id,
            'source_bank_type_id' => $source->bank_type_id,
            'admin_account_id' => $admin->id,
            'admin_account_type_id' => $admin->account_type_id,
            'admin_bank_type_id' => $admin->bank_type_id,
            'transfer_account_id' => $transfer->id,
            'transfer_account_type_id' => $transfer->account_type_id,
            'transfer_bank_type_id' => $transfer->bank_type_id,
            'destination_account_id' => $destination->id,
            'destination_account_type_id' => $destination->account_type_id,
            'destination_bank_type_id' => $destination->bank_type_id,
            'payment_image' => $imagePath,
        ];

        $page = new CreateProjectCostBudgetsPayment();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);
        $record = $method->invoke($page, $data);

        return [$record, ProjectCostBudget::class];
    }

    private function createExecutionPayment(?string $imagePath): array
    {
        $currency = $this->currency();
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType = BankType::create(['name' => 'نوع بنك']);
        $creditA = $this->account('دائن-A', $currency, $accountType, $bankType, 5000);
        $beneficiary = $this->account('مستفيد', $currency, $accountType, $bankType, 0);
        $fiscalYear = $this->fiscalYear();
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partner = $this->partner();
        $project = $this->project();
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        $transaction = Transaction::create([
            'fiscal_year_id' => $fiscalYear->id,
            'transaction_type_id' => $transactionType->id,
            'transaction_number' => 'BUD-'.uniqid(),
            'transaction_time' => now(),
        ]);

        TransactionLine::create([
            'transaction_id' => $transaction->id,
            'account_id' => $creditA->id,
            'currency_id' => $currency->id,
            'amount_currency' => 1000,
            'fx_rate' => 1,
            'debit_base' => 1000,
            'credit_base' => 0,
            'notes' => ProjectCostBudget::LINE_DESTINATION,
            'line_role' => TransactionLineRole::Destination->value,
        ]);

        $budget = ProjectCostBudget::create([
            'project_cost_id' => $projectCost->id,
            'transaction_id' => $transaction->id,
            'original_amount' => 1000,
            'amount_after_deductions' => 1000,
            'source_currency_id' => $currency->id,
            'disbursement_currency_id' => $currency->id,
            'final_amount' => 1000,
        ]);

        $data = [
            'project_cost_budget_id' => $budget->id,
            'amount' => 100,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $transactionType->id,
            'fiscal_year_id' => $fiscalYear->id,
            'partner_id' => $partner->id,
            'date' => '2026-07-16',
            'notes' => null,
            'beneficiary_account_id' => $beneficiary->id,
            'beneficiary_account_type_id' => $beneficiary->account_type_id,
            'beneficiary_bank_type_id' => $beneficiary->bank_type_id,
            'credit_account_id' => $creditA->id,
            'credit_account_type_id' => $creditA->account_type_id,
            'credit_bank_type_id' => $creditA->bank_type_id,
            'payment_image' => $imagePath,
        ];

        $page = new CreateExecutionPayment();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);
        $record = $method->invoke($page, $data);

        return [$record, ProjectCostBudgetsPayment::class];
    }

    private function createGeneralExpense(?string $imagePath): array
    {
        $currency = $this->currency();
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType = BankType::create(['name' => 'نوع بنك']);
        $debit = $this->account('مدين', $currency, $accountType, $bankType, 0);
        $credit = $this->account('دائن', $currency, $accountType, $bankType, 5000);
        $fiscalYear = $this->fiscalYear();
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partner = $this->partner();

        $data = [
            'amount' => 250,
            'currency_id' => $currency->id,
            'partner_id' => $partner->id,
            'date' => '2026-07-18',
            'transaction_super_type_id' => null,
            'transaction_type_id' => $transactionType->id,
            'fiscal_year_id' => $fiscalYear->id,
            'description' => null,
            'notes' => null,
            'debit_account_id' => $debit->id,
            'debit_account_type_id' => $debit->account_type_id,
            'debit_bank_type_id' => $debit->bank_type_id,
            'credit_account_id' => $credit->id,
            'credit_account_type_id' => $credit->account_type_id,
            'credit_bank_type_id' => $credit->bank_type_id,
            'expense_image' => $imagePath,
        ];

        $page = new CreateGeneralExpense();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);
        $record = $method->invoke($page, $data);

        return [$record, GeneralExpense::class];
    }

    private function createGeneralExchange(?string $imagePath): array
    {
        $currency = $this->currency();
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType = BankType::create(['name' => 'نوع بنك']);
        $source = $this->account('مصدر', $currency, $accountType, $bankType, 5000);
        $admin = $this->account('إداري', $currency, $accountType, $bankType);
        $transfer = $this->account('تحويل', $currency, $accountType, $bankType);
        $destination = $this->account('وجهة', $currency, $accountType, $bankType);
        $fiscalYear = $this->fiscalYear();
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partner = $this->partner();

        $data = [
            'original_amount' => 1000,
            'source_currency_id' => $currency->id,
            'administrative_percentage' => 10,
            'transfer_percentage' => 10,
            'disbursement_currency_id' => $currency->id,
            'fx_rate' => 1,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $transactionType->id,
            'fiscal_year_id' => $fiscalYear->id,
            'partner_id' => $partner->id,
            'date' => '2026-07-18',
            'notes' => null,
            'source_account_id' => $source->id,
            'source_account_type_id' => $source->account_type_id,
            'source_bank_type_id' => $source->bank_type_id,
            'admin_account_id' => $admin->id,
            'admin_account_type_id' => $admin->account_type_id,
            'admin_bank_type_id' => $admin->bank_type_id,
            'transfer_account_id' => $transfer->id,
            'transfer_account_type_id' => $transfer->account_type_id,
            'transfer_bank_type_id' => $transfer->bank_type_id,
            'destination_account_id' => $destination->id,
            'destination_account_type_id' => $destination->account_type_id,
            'destination_bank_type_id' => $destination->bank_type_id,
            'exchange_image' => $imagePath,
        ];

        $page = new CreateGeneralExchange();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);
        $record = $method->invoke($page, $data);

        return [$record, GeneralExchange::class];
    }

    // =========================================================
    // CREATE FLOW - all five resources
    // =========================================================

    #[DataProvider('resourceScenarios')]
    public function test_create_flow_stores_exactly_one_private_attachment_with_the_approved_scheme(
        string $resourceKey,
        string $directory,
        string $prefix,
        string $imageField,
    ): void {
        [$record, $modelClass] = $this->buildRecordWithAttachment($resourceKey);

        $this->assertInstanceOf($modelClass, $record);
        $this->assertSame(1, Attachment::count());

        $attachment = Attachment::first();
        $this->assertSame($modelClass, $attachment->attachable_type);
        $this->assertSame($record->id, $attachment->attachable_id);
        $this->assertSame(Attachment::DISK_ATTACHMENTS, $attachment->disk);
        $this->assertStringStartsWith($directory.'/'.$prefix.'_'.$attachment->id.'_', $attachment->file_path);
        $this->assertNotEmpty($attachment->file_type);
        $this->assertGreaterThan(0, $attachment->file_size);

        Storage::disk('attachments')->assertExists($attachment->file_path);
        Storage::disk('public')->assertMissing($attachment->file_path);

        // The pseudo-upload field is never persisted onto the financial record.
        $this->assertArrayNotHasKey($imageField, $record->fresh()->getAttributes());
    }

    // =========================================================
    // EDIT FLOW - all five resources
    // =========================================================

    #[DataProvider('resourceScenarios')]
    public function test_edit_without_replacement_or_removal_keeps_the_attachment_unchanged(
        string $resourceKey,
        string $directory,
        string $prefix,
        string $imageField,
    ): void {
        [$record] = $this->buildRecordWithAttachment($resourceKey);
        $original = Attachment::first();

        $this->invokeUpdate($resourceKey, $record, [$imageField => null, 'remove_current_attachment' => false]);

        $this->assertSame(1, Attachment::count());
        $this->assertFalse($original->fresh()->trashed());
    }

    #[DataProvider('resourceScenarios')]
    public function test_edit_replacement_stores_new_attachment_and_soft_deletes_the_previous_one(
        string $resourceKey,
        string $directory,
        string $prefix,
        string $imageField,
    ): void {
        [$record] = $this->buildRecordWithAttachment($resourceKey);
        $original = Attachment::first();

        Storage::disk('attachments')->put('livewire-tmp/second.jpg', 'second-bytes');

        $this->invokeUpdate($resourceKey, $record, [$imageField => 'livewire-tmp/second.jpg', 'remove_current_attachment' => false]);

        $this->assertTrue($original->fresh()->trashed());
        Storage::disk('attachments')->assertExists($original->file_path); // physical file retained

        $active = Attachment::whereNull('deleted_at')->get();
        $this->assertCount(1, $active); // exactly one active attachment remains
        $this->assertNotSame($original->id, $active->first()->id);
    }

    #[DataProvider('resourceScenarios')]
    public function test_edit_removal_soft_deletes_the_attachment_and_retains_the_file(
        string $resourceKey,
        string $directory,
        string $prefix,
        string $imageField,
    ): void {
        [$record] = $this->buildRecordWithAttachment($resourceKey);
        $original = Attachment::first();

        $this->invokeUpdate($resourceKey, $record, [$imageField => null, 'remove_current_attachment' => true]);

        $this->assertTrue($original->fresh()->trashed());
        Storage::disk('attachments')->assertExists($original->file_path);
        $this->assertSame(0, Attachment::whereNull('deleted_at')->count());
    }

    #[DataProvider('resourceScenarios')]
    public function test_edit_replacement_takes_precedence_over_simultaneous_removal(
        string $resourceKey,
        string $directory,
        string $prefix,
        string $imageField,
    ): void {
        [$record] = $this->buildRecordWithAttachment($resourceKey);
        $original = Attachment::first();

        Storage::disk('attachments')->put('livewire-tmp/second.jpg', 'second-bytes');

        $this->invokeUpdate($resourceKey, $record, [$imageField => 'livewire-tmp/second.jpg', 'remove_current_attachment' => true]);

        $this->assertTrue($original->fresh()->trashed());
        $active = Attachment::whereNull('deleted_at')->get();
        $this->assertCount(1, $active);
    }

    #[DataProvider('resourceScenarios')]
    public function test_edit_failed_replacement_leaves_the_previous_attachment_active_and_unchanged(
        string $resourceKey,
        string $directory,
        string $prefix,
        string $imageField,
    ): void {
        [$record] = $this->buildRecordWithAttachment($resourceKey);
        $original = Attachment::first();

        try {
            // A temp path that was never actually uploaded - the service
            // rejects it before anything is soft-deleted.
            $this->invokeUpdate($resourceKey, $record, [$imageField => 'livewire-tmp/never-uploaded.jpg', 'remove_current_attachment' => false]);
            $this->fail('Expected a RuntimeException for a missing temporary file.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($original->fresh()->trashed());
        $this->assertSame(1, Attachment::count());
        Storage::disk('attachments')->assertExists($original->file_path);
    }

    private function invokeUpdate(string $resourceKey, Model $record, array $overrides): Model
    {
        [$editPageClass, $baseData] = match ($resourceKey) {
            'project_cost_receipts' => [EditProjectCostReceipt::class, [
                'project_cost_id' => $record->project_cost_id,
                'amount' => (float) $record->amount,
                'date' => $record->date,
                'notes' => null,
                'partner_id' => $record->transaction->partner_id,
                'transaction_type_id' => $record->transaction->transaction_type_id,
                'fiscal_year_id' => $record->transaction->fiscal_year_id,
                'debit_account_id' => $record->transaction->lines()->where('debit_base', '>', 0)->first()->account_id,
                'debit_account_type_id' => $record->transaction->lines()->where('debit_base', '>', 0)->first()->account->account_type_id,
                'debit_bank_type_id' => $record->transaction->lines()->where('debit_base', '>', 0)->first()->account->bank_type_id,
                'credit_account_id' => $record->transaction->lines()->where('credit_base', '>', 0)->first()->account_id,
                'credit_account_type_id' => $record->transaction->lines()->where('credit_base', '>', 0)->first()->account->account_type_id,
                'credit_bank_type_id' => $record->transaction->lines()->where('credit_base', '>', 0)->first()->account->bank_type_id,
            ]],
            'project_cost_budgets_payments' => [EditProjectCostBudgetsPayment::class, [
                'project_cost_id' => $record->project_cost_id,
                'original_amount' => (float) $record->original_amount,
                'administrative_percentage' => (float) $record->administrative_percentage,
                'transfer_percentage' => (float) $record->transfer_percentage,
                'disbursement_currency_id' => $record->disbursement_currency_id,
                'fx_rate' => (float) $record->fx_rate,
                'notes' => null,
                'partner_id' => $record->transaction->partner_id,
                'transaction_type_id' => $record->transaction->transaction_type_id,
                'fiscal_year_id' => $record->transaction->fiscal_year_id,
                'date' => $record->transaction->transaction_time->toDateString(),
                'source_account_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_SOURCE)->first()->account_id,
                'source_account_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_SOURCE)->first()->account->account_type_id,
                'source_bank_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_SOURCE)->first()->account->bank_type_id,
                'admin_account_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_ADMIN)->first()->account_id,
                'admin_account_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_ADMIN)->first()->account->account_type_id,
                'admin_bank_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_ADMIN)->first()->account->bank_type_id,
                'transfer_account_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_TRANSFER)->first()->account_id,
                'transfer_account_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_TRANSFER)->first()->account->account_type_id,
                'transfer_bank_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_TRANSFER)->first()->account->bank_type_id,
                'destination_account_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_DESTINATION)->first()->account_id,
                'destination_account_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_DESTINATION)->first()->account->account_type_id,
                'destination_bank_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudget::LINE_DESTINATION)->first()->account->bank_type_id,
            ]],
            'execution_payments' => [EditExecutionPayment::class, [
                'project_cost_budget_id' => $record->project_cost_budget_id,
                'amount' => (float) $record->amount,
                'notes' => null,
                'partner_id' => $record->transaction->partner_id,
                'transaction_type_id' => $record->transaction->transaction_type_id,
                'fiscal_year_id' => $record->transaction->fiscal_year_id,
                'date' => $record->transaction->transaction_time->toDateString(),
                'beneficiary_account_id' => $record->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY)->first()->account_id,
                'beneficiary_account_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY)->first()->account->account_type_id,
                'beneficiary_bank_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY)->first()->account->bank_type_id,
                'credit_account_id' => $record->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_CREDIT)->first()->account_id,
                'credit_account_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_CREDIT)->first()->account->account_type_id,
                'credit_bank_type_id' => $record->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_CREDIT)->first()->account->bank_type_id,
            ]],
            'general_expenses' => [EditGeneralExpense::class, [
                'amount' => (float) $record->amount,
                'currency_id' => $record->currency_id,
                'partner_id' => $record->partner_id,
                'date' => $record->date,
                'description' => null,
                'notes' => null,
                'transaction_type_id' => $record->transaction->transaction_type_id,
                'fiscal_year_id' => $record->transaction->fiscal_year_id,
                'debit_account_id' => $record->transaction->lines()->where('debit_base', '>', 0)->first()->account_id,
                'debit_account_type_id' => $record->transaction->lines()->where('debit_base', '>', 0)->first()->account->account_type_id,
                'debit_bank_type_id' => $record->transaction->lines()->where('debit_base', '>', 0)->first()->account->bank_type_id,
                'credit_account_id' => $record->transaction->lines()->where('credit_base', '>', 0)->first()->account_id,
                'credit_account_type_id' => $record->transaction->lines()->where('credit_base', '>', 0)->first()->account->account_type_id,
                'credit_bank_type_id' => $record->transaction->lines()->where('credit_base', '>', 0)->first()->account->bank_type_id,
            ]],
            'general_exchanges' => [EditGeneralExchange::class, [
                'original_amount' => (float) $record->original_amount,
                'source_currency_id' => $record->source_currency_id,
                'administrative_percentage' => (float) $record->administrative_percentage,
                'transfer_percentage' => (float) $record->transfer_percentage,
                'disbursement_currency_id' => $record->disbursement_currency_id,
                'fx_rate' => (float) $record->fx_rate,
                'notes' => null,
                'partner_id' => $record->partner_id,
                'transaction_type_id' => $record->transaction->transaction_type_id,
                'fiscal_year_id' => $record->transaction->fiscal_year_id,
                'date' => $record->date,
                'source_account_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_SOURCE)->first()->account_id,
                'source_account_type_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_SOURCE)->first()->account->account_type_id,
                'source_bank_type_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_SOURCE)->first()->account->bank_type_id,
                'admin_account_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_ADMIN)->first()->account_id,
                'admin_account_type_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_ADMIN)->first()->account->account_type_id,
                'admin_bank_type_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_ADMIN)->first()->account->bank_type_id,
                'transfer_account_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_TRANSFER)->first()->account_id,
                'transfer_account_type_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_TRANSFER)->first()->account->account_type_id,
                'transfer_bank_type_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_TRANSFER)->first()->account->bank_type_id,
                'destination_account_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_DESTINATION)->first()->account_id,
                'destination_account_type_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_DESTINATION)->first()->account->account_type_id,
                'destination_bank_type_id' => $record->transaction->lines()->where('notes', GeneralExchange::LINE_DESTINATION)->first()->account->bank_type_id,
            ]],
        };

        $page = new $editPageClass();
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record->fresh(), array_merge($baseData, $overrides));
    }
}
