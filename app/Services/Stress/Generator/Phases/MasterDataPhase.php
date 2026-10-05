<?php

namespace App\Services\Stress\Generator\Phases;

use App\Models\FiscalYear;
use App\Models\User;
use App\Services\Stress\Generator\StressBuildContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synthetic users (real role names only, one password hash supplied by the
 * operator's hidden prompt), fiscal years 2022–2026 (2026 active), monthly
 * exchange-rate history, and the base settings.
 */
final class MasterDataPhase
{
    /** role key => [display name, real role name, active] */
    private const USERS = [
        'super_admin' => ['مدير النظام (اختبار)', 'Super Admin', true],
        'admin' => ['مدير الإدارة المالية (اختبار)', 'Admin', true],
        'accountant' => ['المحاسب الأول (اختبار)', 'Accountant', true],
        'accountant_2' => ['المحاسب الثاني (اختبار)', 'Accountant', true],
        'pm' => ['منسق المشاريع (اختبار)', 'Project Manager', true],
        'pm_2' => ['منسقة المشاريع (اختبار)', 'Project Manager', true],
        'viewer' => ['المراجع (اختبار)', 'Viewer', true],
        'accountant_former' => ['محاسب سابق (اختبار)', 'Accountant', false],
    ];

    public function __construct(private readonly StressBuildContext $ctx) {}

    public function run(string $passwordHash): void
    {
        $this->users($passwordHash);
        $this->fiscalYears();
        $this->exchangeRates();
        $this->settings();
        $this->ctx->writer->flushAll();
    }

    private function users(string $passwordHash): void
    {
        $roles = DB::connection($this->ctx->connection)->table('roles')->where('guard_name', 'web')->pluck('id', 'name');
        $created = '2021-12-15 09:00:00';

        foreach (self::USERS as $key => [$name, $role, $active]) {
            if (! isset($roles[$role])) {
                throw new RuntimeException("Role [{$role}] is missing; was PermissionSyncService run by create-database?");
            }

            $id = $this->ctx->nextId('users');
            $this->ctx->writer->add('users', [
                'id' => $id,
                'name' => $name,
                'email' => 'stress.'.$key.'@stress-oms.invalid',
                'email_verified_at' => $created,
                'password' => $passwordHash,
                'is_active' => $active,
                'remember_token' => null,
                'created_at' => $created,
                'updated_at' => $active ? $created : '2025-07-01 09:00:00',
                'deleted_at' => null,
            ]);
            $this->ctx->writer->add('model_has_roles', [
                'role_id' => (int) $roles[$role],
                'model_type' => User::class,
                'model_id' => $id,
            ]);
        }

        $this->ctx->writer->flushAll();

        $byEmail = User::on($this->ctx->connection)->get()->keyBy('email');

        foreach (array_keys(self::USERS) as $key) {
            $this->ctx->users[$key] = $byEmail['stress.'.$key.'@stress-oms.invalid'];
        }
    }

    private function fiscalYears(): void
    {
        $admin = $this->ctx->user('super_admin')->id;

        foreach ([2022, 2023, 2024, 2025, 2026] as $year) {
            $id = $this->ctx->nextId('fiscal_years');
            $created = ($year - 1).'-12-20 10:00:00';
            $closed = $year < 2026 ? ($year + 1).'-01-02 10:00:00' : null;

            $this->ctx->writer->add('fiscal_years', [
                'id' => $id,
                'name' => (string) $year,
                'start_date' => $year.'-01-01',
                'end_date' => $year.'-12-31',
                'is_active' => $year === 2026,
                'notes' => null,
                'created_by' => $admin,
                'updated_by' => $admin,
                'created_at' => $created,
                'updated_at' => $closed ?? $created,
                'deleted_at' => null,
            ]);

            $this->ctx->fiscalYears[$year] = $id;

            // Each year was opened active and closed when the next one began.
            if ($closed !== null) {
                $this->ctx->journal->createdOverrides[FiscalYear::class][$id] = ['is_active' => true];
                $this->ctx->journal->crudUpdate(FiscalYear::class, $id, $closed, $admin, ['is_active' => true], ['is_active' => false]);
            }
        }
    }

    private function exchangeRates(): void
    {
        $random = $this->ctx->random->fork('rates');
        $admin = $this->ctx->user('admin')->id;
        $usd = $this->ctx->currencyId('USD');
        $walk = [$this->ctx->currencyId('ILS') => 3.20, $this->ctx->currencyId('EUR') => 0.89];
        $bounds = [$this->ctx->currencyId('ILS') => [3.15, 3.95], $this->ctx->currencyId('EUR') => [0.84, 0.98]];
        $deletedLeft = 3;

        foreach ($this->ctx->calendar->months() as $ym => [$firstDay]) {
            $this->ctx->rates[$ym][$usd] = 1.0;

            foreach ($walk as $currencyId => $rate) {
                [$min, $max] = $bounds[$currencyId];
                $rate = round(min($max, max($min, $rate * (1 + $random->normal(0.002, 0.012)))), 4);
                $walk[$currencyId] = $rate;
                $this->ctx->rates[$ym][$currencyId] = $rate;

                $at = $this->ctx->calendar->timestamp($random, $firstDay);
                $this->ctx->writer->add('exchange_rate_histories', $this->rateRow($currencyId, $rate, $ym.'-01', $at, $admin, null));

                // A handful of mistyped rates, entered and deleted the same day.
                if ($deletedLeft > 0 && $random->chance(0.03)) {
                    $deletedLeft--;
                    $id = $this->ctx->nextId('exchange_rate_histories');
                    $row = $this->rateRow($currencyId, round($rate * 10, 4), $ym.'-01', $at, $admin, CarbonImmutable::parse($at)->addMinutes(7)->format('Y-m-d H:i:s'), $id);
                    $this->ctx->writer->add('exchange_rate_histories', $row);
                    $this->ctx->journal->deleted('exchange_rate_histories', $id, $admin);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rateRow(int $currencyId, float $rate, string $date, string $at, int $by, ?string $deletedAt, ?int $id = null): array
    {
        return [
            'id' => $id ?? $this->ctx->nextId('exchange_rate_histories'),
            'currency_id' => $currencyId,
            'rate' => $rate,
            'date' => $date,
            'created_by' => $by,
            'updated_by' => $by,
            'created_at' => $at,
            'updated_at' => $deletedAt ?? $at,
            'deleted_at' => $deletedAt,
        ];
    }

    private function settings(): void
    {
        // Same five keys DatabaseSeeder defines; synthetic organization name.
        $rows = [
            ['organization_name', 'منظمة تجريبية لاختبار الضغط (بيانات وهمية)', 'general', 'The name of the organization'],
            ['base_currency', 'USD', 'finance', 'Default base currency code'],
            ['fiscal_year_start', '01-01', 'finance', 'Fiscal year start date (MM-DD)'],
            ['timezone', 'Asia/Jerusalem', 'general', 'Application timezone'],
            ['date_format', 'Y-m-d', 'general', 'Default date display format'],
        ];

        foreach ($rows as [$key, $value, $group, $description]) {
            $this->ctx->writer->add('settings', [
                'id' => $this->ctx->nextId('settings'),
                'key' => $key,
                'value' => $value,
                'group' => $group,
                'description' => $description,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => '2021-12-15 09:00:00',
                'updated_at' => '2021-12-15 09:00:00',
                'deleted_at' => null,
            ]);
        }
    }
}
