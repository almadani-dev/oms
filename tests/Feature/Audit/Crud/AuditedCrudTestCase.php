<?php

namespace Tests\Feature\Audit\Crud;

use App\Models\AccountType;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\User;
use App\Services\Audit\Crud\AuditedCrudService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shared setup for the OMS Task 9B.2 general-CRUD audit integration tests.
 *
 * Same schema-only in-memory SQLite approach as Tests\Feature\Audit\
 * AuditTestCase and Tests\Feature\Crud\CrudRedirectStandardTest — every real
 * migration except the two pre-existing MySQL-only ones. Foreign key
 * enforcement is left at its real default (on), and fixtures are real rows,
 * so the relationship-label snapshots are exercised against genuine data.
 */
abstract class AuditedCrudTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function service(): AuditedCrudService
    {
        return app(AuditedCrudService::class);
    }

    protected function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<int, string>  $names
     */
    protected function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }

    protected function partnerType(string $name = 'نوع شريك'): PartnerType
    {
        return PartnerType::create(['name' => $name]);
    }

    protected function partner(string $name = 'شريك تجريبي'): Partner
    {
        return Partner::create(['name' => $name, 'partner_type_id' => $this->partnerType()->id]);
    }

    protected function projectSuper(string $prefix = 'EDU'): ProjectSuper
    {
        return ProjectSuper::create(['name' => 'برنامج التعليم', 'code_prefix' => $prefix]);
    }

    protected function projectStatus(string $name = 'قيد التنفيذ'): ProjectStatus
    {
        return ProjectStatus::create(['name' => $name]);
    }

    protected function project(string $name = 'مشروع تجريبي'): Project
    {
        return Project::create([
            'name' => $name,
            'project_super_id' => $this->projectSuper()->id,
            'project_status_id' => $this->projectStatus()->id,
            'approval_date' => '2026-03-01',
        ]);
    }

    protected function currency(string $code = 'USD'): Currency
    {
        return Currency::create(['name' => 'دولار', 'code' => $code, 'symbol' => '$']);
    }

    protected function accountType(string $name = 'نوع حساب'): AccountType
    {
        return AccountType::create(['name' => $name]);
    }

    protected function projectCost(Project $project): ProjectCost
    {
        return ProjectCost::create([
            'project_id' => $project->id,
            'account_type_id' => $this->accountType()->id,
            'currency_id' => $this->currency()->id,
            'amount' => 1500,
        ]);
    }
}
