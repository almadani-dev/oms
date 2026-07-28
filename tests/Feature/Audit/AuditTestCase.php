<?php

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Shared setup for OMS Task 9B.1 audit-foundation tests: schema-only SQLite
 * :memory:, every real migration except the two pre-existing MySQL-only ones
 * (`UPDATE ... JOIN` raw SQL SQLite cannot execute) — the same established
 * approach as Tests\Feature\Backup\BackupTestCase. Foreign key enforcement
 * is left at its real config('database.connections.sqlite.foreign_key_constraints')
 * default (true) — unlike BackupTestCase, this suite deliberately relies on
 * a real `ON DELETE SET NULL` firing for the actor_user_id FK test.
 */
abstract class AuditTestCase extends TestCase
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
    }
}
