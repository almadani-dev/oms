<?php

namespace App\Services\Restore;

use App\Services\Backup\Contracts\SymlinkDetector;
use App\Services\Backup\NativeSymlinkDetector;
use App\Services\Restore\Contracts\ProcessStreamInputRunner;
use App\Services\Restore\Exceptions\RestoreDatabaseException;
use Throwable;

/**
 * OMS Task 7C.5 — imports an already-staged, already-verified SQL dump
 * (produced by RestoreArchivePreparer, staged under a PreparedRestore's own
 * RestoreWorkspace) into the configured MySQL connection via a streamed
 * `mysql` client import. Never called by anything yet — no route, command,
 * or orchestrator wires this up in this phase.
 *
 * Every re-check here (scope, staged dump identity, mysql client, database
 * connection) is independent of, and re-run after, whatever
 * RestorePreflightChecker/RestoreArchivePreparer already validated earlier
 * in the flow — time has passed, and this is the last gate before a real
 * mysql process is spawned against the real database.
 *
 * OMS Task 7C.5 correction pass — database-connection policy: restore is
 * supported only for the application's primary/default database connection.
 * `config('oms.backup.database_connection')` exists purely as a backup-
 * creation-side override (`DatabaseDumper` may dump a non-default
 * connection); restoring into that same override would let `mysql` import
 * into one physical database while `migrate`/Eloquent/`DB::table()` — every
 * one of which implicitly targets `config('database.default')` with no
 * `->on()` override anywhere in this codebase — reconcile a completely
 * different one, silently. resolveConnectionConfig() therefore fails closed
 * with `database_connection_mismatch` the moment the resolved backup
 * connection name differs from `database.default`, before any mysql client
 * path is even resolved. RestoreReconciler independently never accepts a
 * caller-supplied connection name at all — it always targets
 * `database.default` directly — so the two halves of a restore can never
 * silently disagree about which physical database they're operating on.
 */
final class DatabaseRestorer
{
    public function __construct(
        private readonly ProcessStreamInputRunner $processRunner,
        private readonly ?SymlinkDetector $symlinkDetector = null,
    ) {
    }

    /**
     * @throws RestoreDatabaseException
     */
    public function restore(PreparedRestore $prepared): void
    {
        if (! $prepared->selectedScope->includesDatabase()) {
            throw RestoreDatabaseException::scopeExcludesDatabase();
        }

        if ($prepared->stagedDumpRelativePath === null) {
            throw RestoreDatabaseException::stagedDumpMissing();
        }

        $dumpAbsolutePath = $prepared->workspace->stagedDumpPath();
        $this->assertStagedDumpSafe($dumpAbsolutePath, $prepared->workspace);

        $mysqlPath = $this->resolveMysqlClientPath();
        $connection = $this->resolveConnectionConfig();

        $command = [
            $mysqlPath,
            '--host='.(string) $connection['host'],
            '--port='.(string) $connection['port'],
            '--user='.(string) $connection['username'],
            '--default-character-set=utf8mb4',
            (string) $connection['database'],
        ];

        $env = [];
        $password = (string) ($connection['password'] ?? '');

        // Never passed as a CLI argument (visible in the process list) and
        // never logged — SymfonyProcessStreamInputRunner only ever returns
        // a bounded, MYSQL_PWD-sanitized stderr excerpt, and this array is
        // never persisted or included in any exception text.
        if ($password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        $timeoutSeconds = (float) config('oms.backup.restore.mysql_import_timeout', 3600);

        try {
            $result = $this->processRunner->run($command, $env, $timeoutSeconds, $dumpAbsolutePath);
        } catch (Throwable) {
            throw RestoreDatabaseException::processLaunchFailed();
        }

        if ($result->timedOut) {
            throw RestoreDatabaseException::importTimedOut();
        }

        if (! $result->isSuccessful()) {
            throw RestoreDatabaseException::importFailed($result->stderr);
        }
    }

    /**
     * Re-confirms the staged dump is a regular file, not a symlink/
     * junction/reparse point, and that its real absolute path is still
     * confined to this restore's own workspace — independent of whatever
     * RestoreArchiveExtractor already guaranteed when it originally staged
     * the file, since time has passed and nothing here re-derives its own
     * trust from that earlier check.
     */
    private function assertStagedDumpSafe(string $dumpAbsolutePath, RestoreWorkspace $workspace): void
    {
        $detector = $this->symlinkDetector ?? new NativeSymlinkDetector();

        if (! is_file($dumpAbsolutePath) || $detector->isLink($dumpAbsolutePath)) {
            throw RestoreDatabaseException::stagedDumpMissing();
        }

        $root = rtrim(str_replace('\\', '/', $workspace->absoluteRoot()), '/').'/';
        $candidate = str_replace('\\', '/', $dumpAbsolutePath);

        if (! str_starts_with($candidate, $root)) {
            throw RestoreDatabaseException::stagedDumpOutsideWorkspace();
        }
    }

    /**
     * Mirrors RestorePreflightChecker::assertMysqlClientAvailable() exactly
     * — the same Windows-local-dev limitation applies (is_executable() does
     * not reliably reflect runnability there), re-run here immediately
     * before this class actually spawns the process rather than trusting
     * preflight's earlier check to still hold.
     */
    private function resolveMysqlClientPath(): string
    {
        $path = trim((string) config('oms.backup.mysql_client_path', ''));

        if ($path === '' || ! is_file($path)) {
            throw RestoreDatabaseException::mysqlClientUnavailable();
        }

        if (PHP_OS_FAMILY !== 'Windows' && ! is_executable($path)) {
            throw RestoreDatabaseException::mysqlClientUnavailable();
        }

        return $path;
    }

    /**
     * @return array{host: string, port: int|string, database: string, username: string, password: string|null}
     */
    private function resolveConnectionConfig(): array
    {
        $connectionName = (string) (config('oms.backup.database_connection') ?: config('database.default'));
        $defaultConnectionName = (string) config('database.default');

        if ($connectionName !== $defaultConnectionName) {
            throw RestoreDatabaseException::databaseConnectionMismatch();
        }

        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            throw RestoreDatabaseException::databaseConnectionIncomplete();
        }

        foreach (['host', 'port', 'database', 'username'] as $key) {
            if (! array_key_exists($key, $connection) || (string) $connection[$key] === '') {
                throw RestoreDatabaseException::databaseConnectionIncomplete();
            }
        }

        return $connection;
    }
}
