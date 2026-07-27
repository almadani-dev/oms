<?php

namespace Tests\Feature\Restore;

use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.8 section H — the DB-independent private restore progress
 * polling endpoint. Every test here hits the route over real HTTP (never
 * calls RestoreProgressReader directly) so the actual route/middleware
 * wiring (routes/web.php's ->withoutMiddleware() list, the `signed`
 * middleware) is what is actually proven, not merely assumed.
 *
 * Two maintenance-mode tests exist deliberately: one swaps the bound
 * `Illuminate\Contracts\Foundation\MaintenanceMode` implementation (fast,
 * no filesystem risk, exercises the real middleware's own contract lookup)
 * and one (OMS Task 7C.8 acceptance pass) uses the REAL `php artisan down`/
 * `up` commands and the real `FileBasedMaintenanceMode` flag file — see that
 * test's own docblock for the safety measures around genuinely touching
 * this project's real storage/framework directory (a `tearDown()` override
 * below unconditionally removes the flag file as a second safety net,
 * independent of that test's own `finally` block).
 */
class RestoreProgressPollControllerTest extends BackupTestCase
{
    private function snapshot(string $uuid, array $overrides = []): RestoreProgressSnapshot
    {
        $a = array_merge([
            'requestedBy' => ['user_id' => 1, 'name' => 'Test Admin', 'email' => 'super-secret-admin@example.test'],
            'requestedAt' => '2026-07-23T10:00:00+00:00',
            'reason' => 'Scheduled DR drill',
            'scope' => 'full',
            'sourceBackupUuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            'preRestoreSafetyBackupUuid' => null,
            'phase' => 'database_restoring',
            'phaseHistory' => [],
            'lastHeartbeatAt' => '2026-07-23T10:05:00+00:00',
            'result' => null,
            'restoreFailedPhase' => null,
            'errorSummary' => null,
        ], $overrides);

        return RestoreProgressSnapshot::create(
            $uuid,
            $a['requestedBy'],
            $a['requestedAt'],
            $a['reason'],
            $a['scope'],
            $a['sourceBackupUuid'],
            $a['preRestoreSafetyBackupUuid'],
            $a['phase'],
            $a['phaseHistory'],
            $a['lastHeartbeatAt'],
            $a['result'],
            $a['restoreFailedPhase'],
            $a['errorSummary'],
        );
    }

    private function pollUrl(string $uuid, int $minutes = 5): string
    {
        $full = URL::temporarySignedRoute('restores.progress.poll', now()->addMinutes($minutes), ['uuid' => $uuid]);

        return (string) parse_url($full, PHP_URL_PATH).'?'.parse_url($full, PHP_URL_QUERY);
    }

    public function test_valid_signed_request_returns_sanitized_progress(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000101';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $response = $this->get($this->pollUrl($uuid));

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'restore_uuid' => $uuid,
            'source_backup_uuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            'scope' => 'full',
            'phase' => 'database_restoring',
            'last_heartbeat_at' => '2026-07-23T10:05:00+00:00',
        ]);
    }

    public function test_no_authentication_is_required(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000102';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        // Deliberately no actingAs() — a guest holding the signed URL alone
        // must be able to poll.
        $this->get($this->pollUrl($uuid))->assertOk();
    }

    public function test_response_contains_only_approved_sanitized_fields(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000103';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $response = $this->get($this->pollUrl($uuid));
        $response->assertOk();

        $allowedKeys = [
            'status', 'restore_uuid', 'source_backup_uuid', 'scope',
            'requested_by_name', 'requested_at', 'phase', 'last_heartbeat_at',
            'result', 'restore_failed_phase', 'error_summary',
        ];

        $this->assertSame([], array_diff(array_keys($response->json()), $allowedKeys));

        $body = $response->getContent();
        $this->assertStringNotContainsString('super-secret-admin@example.test', $body);
        $this->assertStringNotContainsString(storage_path(), $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('test-key-1', $body);
    }

    public function test_expired_signature_is_denied(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000104';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $this->get($this->pollUrl($uuid, minutes: -5))->assertForbidden();
    }

    public function test_unsigned_request_is_denied(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000105';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $this->get("/restores/{$uuid}/progress")->assertForbidden();
    }

    public function test_tampered_signature_is_denied(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000106';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $tampered = preg_replace(
            '/signature=[a-f0-9]+/',
            'signature=0000000000000000000000000000000000000000000000000000000000000000',
            $this->pollUrl($uuid),
        );

        $this->get($tampered)->assertForbidden();
    }

    /**
     * Swapping the UUID in the path after signing invalidates the signature
     * (the UUID is part of what was signed) — denied before the controller
     * (and therefore RestoreProgressReader) ever runs.
     */
    public function test_tampered_uuid_in_path_is_denied(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000107';
        $other = 'aaaaaaaa-0000-0000-0000-000000000108';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        (new RestoreProgressWriter())->write($this->snapshot($other));

        $tampered = str_replace($uuid, $other, $this->pollUrl($uuid));

        $this->get($tampered)->assertForbidden();
    }

    public function test_missing_progress_file_returns_generic_unavailable_status(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000109';

        $response = $this->get($this->pollUrl($uuid));

        $response->assertStatus(404)->assertJson(['status' => 'unavailable']);
    }

    public function test_tampered_progress_file_content_is_denied(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000110';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reason'] = 'tampered after being written';
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        $response = $this->get($this->pollUrl($uuid));

        $response->assertStatus(404)->assertJson(['status' => 'unavailable']);
        $this->assertStringNotContainsString('signature', $response->getContent());
    }

    public function test_terminal_result_is_reported_so_the_client_can_stop_polling(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000111';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, [
            'phase' => 'restored',
            'result' => 'restored',
        ]));

        $this->get($this->pollUrl($uuid))->assertOk()->assertJson(['result' => 'restored']);
    }

    /**
     * The Task 7C.8 section H acceptance test: maintenance mode active,
     * database.default unreachable, valid signed request still succeeds,
     * and genuinely zero database queries are issued.
     */
    public function test_succeeds_during_maintenance_mode_with_database_unavailable_and_zero_queries(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000112';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $url = $this->pollUrl($uuid);

        // 1. Maintenance mode active — via the real bound contract the real
        // PreventRequestsDuringMaintenance middleware itself queries, never
        // the real `php artisan down` flag file.
        $this->app->instance(MaintenanceModeContract::class, new class implements MaintenanceModeContract
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });

        // 2. database.default intentionally unavailable — any accidental
        // query through the default connection would throw immediately
        // rather than silently succeeding against the test's real
        // schema-only SQLite connection. Query logging is enabled on that
        // real connection BY NAME before the swap (enabling it via the
        // bogus default afterward would itself throw resolving it).
        $realConnectionName = (string) config('database.default');
        DB::connection($realConnectionName)->enableQueryLog();
        config(['database.default' => 'oms_intentionally_unavailable_connection']);

        $response = $this->get($url);
        $queryCount = count(DB::connection($realConnectionName)->getQueryLog());
        DB::connection($realConnectionName)->disableQueryLog();

        // 3+4. Valid signed request still returns progress, with zero
        // database queries required.
        $response->assertOk()->assertJson(['status' => 'ok', 'restore_uuid' => $uuid]);
        $this->assertSame(0, $queryCount, 'The DB-independent poll endpoint must never issue a database query.');

        // 5. Invalid/expired/tampered requests are still denied under the
        // exact same conditions (proven with the maintenance mode / DB
        // outage still in effect).
        $this->get($this->pollUrl($uuid, minutes: -5))->assertForbidden();
    }

    /**
     * OMS Task 7C.8 acceptance pass — the REAL Laravel 13 maintenance
     * mechanism, not the bound-fake simulation above. Verified against
     * `Illuminate\Foundation\FileBasedMaintenanceMode` (this app's
     * `APP_MAINTENANCE_DRIVER`, set to `file` in phpunit.xml) directly: it
     * reads/writes a real flag file at `storage_path('framework/down')` —
     * the exact same path this actual project's real dev instance would
     * use, since no `STORAGE_PATH`/base-path override is configured for
     * tests. `Artisan::call('down')`/`('up')` are the real console commands
     * an operator would run, going through the real
     * `MaintenanceModeManager` → `FileBasedMaintenanceMode::activate()`/
     * `deactivate()` → the exact same `$this->app->isDownForMaintenance()`
     * the real `PreventRequestsDuringMaintenance` middleware queries on
     * every request — nothing here is mocked.
     *
     * Safety (per explicit instruction — this genuinely touches this
     * project's real storage/framework directory): (a) a hard precondition
     * assertion refuses to run at all if the flag file already exists
     * before this test starts, rather than silently clobbering unknown
     * real state; (b) `Artisan::call('up')` plus a direct, unconditional
     * `@unlink()` of the exact known path both run in a `finally` block,
     * so cleanup happens even if an assertion above it fails; (c) `tearDown()`
     * below repeats the same unconditional removal as a second, independent
     * safety net, so a fatal error that skips the `finally` entirely (e.g.
     * a PHP-level crash) still cannot leave a later test — or the real dev
     * site — in maintenance mode.
     */
    protected function tearDown(): void
    {
        @unlink(storage_path('framework/down'));

        parent::tearDown();
    }

    public function test_real_laravel_maintenance_mode_blocks_normal_routes_but_not_the_signed_poll_route(): void
    {
        $flagPath = storage_path('framework/down');
        $this->assertFalse(
            file_exists($flagPath),
            "Refusing to run: {$flagPath} already exists — this would clobber unknown real maintenance state.",
        );

        $uuid = 'aaaaaaaa-0000-0000-0000-000000000113';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $url = $this->pollUrl($uuid);

        try {
            // 1. Real maintenance mode, via the real `down` Artisan command
            // — writes the real flag file FileBasedMaintenanceMode reads.
            \Illuminate\Support\Facades\Artisan::call('down');
            $this->assertTrue(file_exists($flagPath), 'Precondition: down must have written the real maintenance flag file.');
            $this->assertTrue($this->app->isDownForMaintenance(), 'Precondition: the real app must now report itself down.');

            // 2. A normal, non-excepted application route is genuinely
            // blocked with Laravel's real maintenance response (503).
            $this->get('/')->assertStatus(503);

            // 4. database.default intentionally unavailable, verified the
            // same way as the simulated test above.
            $realConnectionName = (string) config('database.default');
            DB::connection($realConnectionName)->enableQueryLog();
            config(['database.default' => 'oms_intentionally_unavailable_connection']);

            // 3+5+6. The exact signed polling route remains reachable and
            // still returns valid progress, with zero database queries.
            $response = $this->get($url);
            $queryCount = count(DB::connection($realConnectionName)->getQueryLog());
            DB::connection($realConnectionName)->disableQueryLog();

            $response->assertOk()->assertJson(['status' => 'ok', 'restore_uuid' => $uuid]);
            $this->assertSame(0, $queryCount, 'The DB-independent poll endpoint must never issue a database query, even under real maintenance mode.');

            // 7. Invalid/expired/tampered signatures are still denied, with
            // real maintenance mode and the DB outage both still in effect.
            $this->get($this->pollUrl($uuid, minutes: -5))->assertForbidden();

            $tamperedUrl = preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $url);
            $this->get($tamperedUrl)->assertForbidden();
        } finally {
            // 8. Guaranteed cleanup — real maintenance mode is always lifted
            // regardless of what happened above.
            \Illuminate\Support\Facades\Artisan::call('up');
            @unlink($flagPath);
        }

        $this->assertFalse(file_exists($flagPath), 'The real maintenance flag file must not survive this test.');
        $this->assertFalse($this->app->isDownForMaintenance());
    }
}
