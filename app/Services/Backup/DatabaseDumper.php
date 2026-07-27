<?php

namespace App\Services\Backup;

use App\Services\Backup\Contracts\ProcessRunner;
use App\Services\Backup\Exceptions\DatabaseDumpException;

/**
 * Produces a plain-text mysqldump of the application's configured database
 * connection at an absolute destination path. Never executes a real
 * mysqldump in tests — always goes through the injected ProcessRunner, so
 * the whole flow (argument list, env handling, exit-code/empty-output
 * validation, partial-file cleanup) is exercised with a fake process.
 */
final class DatabaseDumper
{
    public function __construct(private readonly ProcessRunner $processRunner)
    {
    }

    /**
     * OMS Task 7C.7 hardening pass — $onTick, when given, is invoked once
     * per streamed stdout chunk (throttling is the tick callback's own
     * responsibility — see RestoreHeartbeat) so a mandatory pre-restore
     * safety backup can keep a restore's signed progress heartbeat alive
     * for as long as `mysqldump` keeps producing output, which it does
     * continuously for any non-trivial dump — unlike the `mysql` import
     * direction (see ProcessStreamInputRunner's own docblock), stdout
     * activity is a genuine, real signal here, not something to work around.
     *
     * @throws DatabaseDumpException
     */
    public function dump(string $destinationAbsolutePath, ?callable $onTick = null): DumpResult
    {
        $connectionName = (string) (config('oms.backup.database_connection') ?: config('database.default'));
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            throw new DatabaseDumpException('The active database connection is not MySQL — cannot mysqldump it.');
        }

        $database = (string) ($connection['database'] ?? '');

        if ($database === '') {
            throw new DatabaseDumpException('The active database connection has no database name configured.');
        }

        $partialPath = $destinationAbsolutePath.'.partial';

        $command = array_filter([
            $this->resolveMysqldumpPath(),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set=utf8mb4',
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '3306'),
            '--user='.($connection['username'] ?? ''),
            $database,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $env = [];

        // Never pass the password as a CLI argument (visible in the process
        // list / Task Manager). MYSQL_PWD is read only by the mysqldump
        // process itself, from its own environment, and this array is never
        // logged by SymfonyProcessRunner (only stderr is captured/stored).
        $password = (string) ($connection['password'] ?? '');

        if ($password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        $handle = @fopen($partialPath, 'wb');

        if ($handle === false) {
            throw new DatabaseDumpException("Unable to open dump destination for writing: {$partialPath}");
        }

        try {
            $result = $this->processRunner->run(
                array_values($command),
                $env,
                (float) config('oms.backup.dump_timeout', 1800),
                static function (string $chunk) use ($handle, $onTick): void {
                    fwrite($handle, $chunk);
                    if ($onTick !== null) { $onTick(); }
                },
            );
        } finally {
            fclose($handle);
        }

        if (! $result->isSuccessful()) {
            $this->cleanup($partialPath);

            $reason = $result->timedOut ? 'mysqldump timed out' : "mysqldump exited with code {$result->exitCode}";

            throw new DatabaseDumpException(trim("{$reason}: {$result->stderr}"));
        }

        clearstatcache(true, $partialPath);
        $size = filesize($partialPath);

        if ($size === false || $size === 0) {
            $this->cleanup($partialPath);

            throw new DatabaseDumpException('mysqldump produced an empty dump file.');
        }

        if (! @rename($partialPath, $destinationAbsolutePath)) {
            $this->cleanup($partialPath);

            throw new DatabaseDumpException('Unable to finalize the database dump file.');
        }

        return new DumpResult(
            absolutePath: $destinationAbsolutePath,
            sizeBytes: $size,
            sha256: (string) hash_file('sha256', $destinationAbsolutePath),
        );
    }

    private function resolveMysqldumpPath(): string
    {
        $configured = trim((string) config('oms.backup.mysqldump_path', ''));

        return $configured !== '' ? $configured : 'mysqldump';
    }

    private function cleanup(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
