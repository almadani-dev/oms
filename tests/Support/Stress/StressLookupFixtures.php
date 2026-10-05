<?php

namespace Tests\Support\Stress;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the lookup rows oms:stress:create-database copies from the
 * development configuration (same names), plus the real permission/role
 * sync — the state a freshly created stress database starts from.
 */
trait StressLookupFixtures
{
    /**
     * The real migrations on the in-memory SQLite test database, minus the
     * two MySQL-only ones (same approach as the existing report tests).
     * Foreign keys are switched back ON afterwards so bulk insert ordering
     * is genuinely checked.
     */
    protected function migrateStressTestSchema(): void
    {
        $mysqlOnly = ['2026_06_24_000005_backfill_denormalized_currency_and_amounts', '2026_06_24_000009_add_supporting_indexes_for_financial_report'];

        DB::statement('PRAGMA foreign_keys = OFF');
        Artisan::call('migrate', [
            '--path' => collect(glob(base_path('database/migrations/*.php')))
                ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
                ->map(fn (string $path) => 'database/migrations/'.basename($path))
                ->values()->all(),
            '--force' => true,
        ]);
        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function seedStressLookups(): void
    {
        $this->migrateStressTestSchema();

        $now = now();
        $rows = fn (array $names, array $extra = []) => array_map(fn ($n) => ['name' => $n, 'created_at' => $now, 'updated_at' => $now] + $extra, $names);

        DB::table('currencies')->insert([
            ['name' => 'دولار امريكي', 'code' => 'USD', 'symbol' => 'USD', 'is_base' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'شيكل اسرائيلي', 'code' => 'ILS', 'symbol' => 'ILS', 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'يورو', 'code' => 'EUR', 'symbol' => 'EUR', 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('accounts_type')->insert($rows(['حساب الجمعية', 'حسابات الشركاء (جمعية)', 'حسابات الشركاء (شركات)', 'حسابات الموظفين', 'أرصدة افتتاحية', 'حسابات الشركاء (أفراد)', 'أفراد']));
        DB::table('bank_types')->delete();
        DB::table('bank_types')->insert($rows(['بنك فلسطين', 'البنك الاسلامي الفلسطيني', 'USDT', 'محفظة بال باي', 'محفظة جوال باي', 'كاش', 'بنك الإسكان', 'بنك القدس', 'فودافون كاش', 'محفظة مؤقت']));
        DB::table('partners_types')->insert($rows(['جمعية', 'فرد', 'وزارة']));
        DB::table('projects_status')->insert($rows(['غير مكتمل', 'مكتمل']));
        DB::table('projects_super')->delete();

        foreach (['ORPHANS' => 'ايتام', 'FOOD' => 'الطعام', 'WATER' => 'مياه', 'MID' => 'دواء', 'MUWAKHA' => 'مشروع المؤاخاة'] as $prefix => $name) {
            DB::table('projects_super')->insert(['name' => $name, 'code_prefix' => $prefix, 'code' => $prefix.'_001', 'created_at' => $now, 'updated_at' => $now]);
        }

        $supers = [];

        foreach (['مصاريف ادارية', 'مصاريف تشغيلية', 'استلام مبلغ', 'صرف مبلغ مشروع', 'قيود افتتاحية', 'مساعدات', 'تحويلات عامة'] as $name) {
            $supers[$name] = DB::table('transaction_super_types')->insertGetId(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach ([
            'رواتب' => 'مصاريف ادارية', 'كهرباء' => 'مصاريف ادارية', 'محروقات' => 'مصاريف تشغيلية', 'صيانة مركبات' => 'مصاريف تشغيلية',
            'مبلغ تنفيذ للمشروع' => 'مصاريف تشغيلية', 'استلام مبلغ' => 'استلام مبلغ', 'صرف مبلغ مشروع' => 'صرف مبلغ مشروع',
            'قيد افتتاحي' => 'قيود افتتاحية', 'مساعدة علاج' => 'مساعدات', 'مساعدة زواج' => 'مساعدات', 'تحويل بين الحسابات' => 'تحويلات عامة',
        ] as $type => $super) {
            DB::table('transactions_types')->insert(['name' => $type, 'transaction_super_type_id' => $supers[$super], 'created_at' => $now, 'updated_at' => $now]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionSyncService::class)->sync();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
