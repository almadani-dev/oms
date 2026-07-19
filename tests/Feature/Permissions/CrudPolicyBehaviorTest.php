<?php

namespace Tests\Feature\Permissions;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Attachment;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\ExchangeRateHistory;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Data-driven proof that the shared AuthorizesCrud trait enforces, for every
 * one of the 23 protected modules:
 *
 *  - view_any/view/create/update/delete/restore each require their OWN
 *    `<module>.<operation>` permission — none of them are granted by another
 *    (view does not grant create; create does not grant update/delete; etc);
 *  - a soft-deleted record cannot be normally viewed/updated/deleted again,
 *    and restore only ever applies to an already-trashed record;
 *  - force delete is never granted by any permission, for any module;
 *  - the two hard-locked audit modules (transactions, transaction_lines)
 *    stay fully immutable regardless of which permissions are granted.
 *
 * Uses bare (unsaved) model instances — SoftDeletes::trashed() only reads
 * the in-memory deleted_at attribute, so no factories/fixtures are needed
 * for models this test never persists. Permissions are created ad-hoc via
 * firstOrCreate rather than running the full oms:sync-permissions command,
 * since this test intentionally also grants permission names that
 * PermissionRegistry would never generate for a read-only module (proving
 * the read-only lock survives even a hypothetical misconfiguration).
 *
 * Uses the same schema-only SQLite approach as
 * SystemRoleDefaultPermissionsTest / BalanceGuardIntegrationTest.
 */
class CrudPolicyBehaviorTest extends TestCase
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
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: bool}>
     */
    public static function crudModuleProvider(): array
    {
        return [
            'accounts' => [Account::class, 'accounts', true],
            'transactions (read-only)' => [Transaction::class, 'transactions', false],
            'transaction_lines (read-only)' => [TransactionLine::class, 'transaction_lines', false],
            'general_expenses' => [GeneralExpense::class, 'general_expenses', true],
            'general_exchanges' => [GeneralExchange::class, 'general_exchanges', true],
            'execution_payments (ProjectCostBudgetsPayment)' => [ProjectCostBudgetsPayment::class, 'execution_payments', true],
            'project_cost_budgets_payments (ProjectCostBudget)' => [ProjectCostBudget::class, 'project_cost_budgets_payments', true],
            'project_cost_receipts' => [ProjectCostReceipt::class, 'project_cost_receipts', true],
            'projects' => [Project::class, 'projects', true],
            'project_costs' => [ProjectCost::class, 'project_costs', true],
            'project_supers' => [ProjectSuper::class, 'project_supers', true],
            'partners' => [Partner::class, 'partners', true],
            'bank_types' => [BankType::class, 'bank_types', true],
            'currencies' => [Currency::class, 'currencies', true],
            'account_types' => [AccountType::class, 'account_types', true],
            'exchange_rate_histories' => [ExchangeRateHistory::class, 'exchange_rate_histories', true],
            'fiscal_years' => [FiscalYear::class, 'fiscal_years', true],
            'partner_types' => [PartnerType::class, 'partner_types', true],
            'transaction_types' => [TransactionType::class, 'transaction_types', true],
            'transaction_super_types' => [TransactionSuperType::class, 'transaction_super_types', true],
            'project_statuses' => [ProjectStatus::class, 'project_statuses', true],
            'settings' => [Setting::class, 'settings', true],
            'attachments' => [Attachment::class, 'attachments', true],
        ];
    }

    #[DataProvider('crudModuleProvider')]
    public function test_view_any_requires_its_own_permission(string $modelClass, string $module, bool $mutable): void
    {
        $none = $this->userWithPermissions([]);
        $granted = $this->userWithPermissions(["{$module}.view_any"]);

        $this->assertFalse($none->can('viewAny', $modelClass), "{$modelClass}: viewAny must be denied without permission.");
        $this->assertTrue($granted->can('viewAny', $modelClass), "{$modelClass}: viewAny must be allowed with permission.");
    }

    #[DataProvider('crudModuleProvider')]
    public function test_view_requires_its_own_permission_and_the_soft_delete_rule(string $modelClass, string $module, bool $mutable): void
    {
        $record = new $modelClass;
        $none = $this->userWithPermissions([]);
        $granted = $this->userWithPermissions(["{$module}.view"]);

        $this->assertFalse($none->can('view', $record), "{$modelClass}: view must be denied without permission.");
        $this->assertTrue($granted->can('view', $record), "{$modelClass}: view must be allowed with permission on a non-trashed record.");

        if (! method_exists($record, 'trashed')) {
            return;
        }

        $record->deleted_at = now();

        if ($mutable) {
            $this->assertFalse($granted->can('view', $record), "{$modelClass}: a trashed record must not be viewable through the normal View page.");
        } else {
            $this->assertTrue($granted->can('view', $record), "{$modelClass}: read-only audit resources may still show trashed history.");
        }
    }

    #[DataProvider('crudModuleProvider')]
    public function test_create_requires_its_own_permission_and_is_not_granted_by_view(string $modelClass, string $module, bool $mutable): void
    {
        $viewOnly = $this->userWithPermissions(["{$module}.view_any", "{$module}.view"]);
        $this->assertFalse($viewOnly->can('create', $modelClass), "{$modelClass}: view permission must not grant create.");

        $granted = $this->userWithPermissions(["{$module}.create"]);
        $this->assertSame($mutable, $granted->can('create', $modelClass), "{$modelClass}: create permission result mismatch.");
    }

    #[DataProvider('crudModuleProvider')]
    public function test_update_requires_its_own_permission_and_is_not_granted_by_create(string $modelClass, string $module, bool $mutable): void
    {
        $record = new $modelClass;
        $createOnly = $this->userWithPermissions(["{$module}.create"]);
        $this->assertFalse($createOnly->can('update', $record), "{$modelClass}: create permission must not grant update.");

        $granted = $this->userWithPermissions(["{$module}.update"]);
        $this->assertSame($mutable, $granted->can('update', $record), "{$modelClass}: update permission result mismatch.");
    }

    #[DataProvider('crudModuleProvider')]
    public function test_delete_requires_its_own_permission_and_the_soft_delete_rule(string $modelClass, string $module, bool $mutable): void
    {
        $record = new $modelClass;
        $updateOnly = $this->userWithPermissions(["{$module}.update"]);
        $this->assertFalse($updateOnly->can('delete', $record), "{$modelClass}: update permission must not grant delete.");

        $granted = $this->userWithPermissions(["{$module}.delete"]);
        $this->assertSame($mutable, $granted->can('delete', $record), "{$modelClass}: delete permission result mismatch.");
        $this->assertSame($mutable, $granted->can('deleteAny', $modelClass), "{$modelClass}: deleteAny permission result mismatch.");

        if (! ($mutable && method_exists($record, 'trashed'))) {
            return;
        }

        $record->deleted_at = now();
        $this->assertFalse($granted->can('delete', $record), "{$modelClass}: an already-trashed record cannot be deleted again.");
    }

    #[DataProvider('crudModuleProvider')]
    public function test_restore_requires_its_own_permission_and_only_applies_to_a_trashed_record(string $modelClass, string $module, bool $mutable): void
    {
        $record = new $modelClass;

        if (! method_exists($record, 'trashed')) {
            $this->markTestSkipped("{$modelClass} does not use SoftDeletes.");
        }

        $granted = $this->userWithPermissions(["{$module}.restore"]);

        $this->assertFalse($granted->can('restore', $record), "{$modelClass}: restore must be denied on a non-trashed record even with permission.");

        $record->deleted_at = now();
        $this->assertSame($mutable, $granted->can('restore', $record), "{$modelClass}: restore permission result mismatch on a trashed record.");
        $this->assertSame($mutable, $granted->can('restoreAny', $modelClass), "{$modelClass}: restoreAny permission result mismatch.");

        $none = $this->userWithPermissions([]);
        $this->assertFalse($none->can('restore', $record), "{$modelClass}: restore must require its own permission.");
    }

    #[DataProvider('crudModuleProvider')]
    public function test_force_delete_is_never_granted_by_any_permission(string $modelClass, string $module, bool $mutable): void
    {
        $record = new $modelClass;
        $everything = $this->userWithPermissions([
            "{$module}.view_any", "{$module}.view", "{$module}.create",
            "{$module}.update", "{$module}.delete", "{$module}.restore",
        ]);

        $this->assertFalse($everything->can('forceDelete', $record), "{$modelClass}: forceDelete must never be permission-granted.");
        $this->assertFalse($everything->can('forceDeleteAny', $modelClass), "{$modelClass}: forceDeleteAny must never be permission-granted.");
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
}
