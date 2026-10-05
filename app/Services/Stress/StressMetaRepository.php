<?php

namespace App\Services\Stress;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stress-only `_stress_meta` key/value table: the marker every stress
 * command requires, plus build bookkeeping (seed, scale, phase timings).
 *
 * It exists only inside a stress database. It is created here, directly,
 * never by a migration, and is not part of the business schema.
 */
class StressMetaRepository
{
    public function __construct(private readonly string $connection) {}

    public function install(string $database): void
    {
        $table = $this->table();

        if (! Schema::connection($this->connection)->hasTable($table)) {
            Schema::connection($this->connection)->create($table, function (Blueprint $t): void {
                $t->string('key', 100)->primary();
                $t->longText('value')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        $this->put(StressEnvironmentGuard::MARKER_KEY, $database);
        $this->put('created_at', now()->toIso8601String());
    }

    public function put(string $key, mixed $value): void
    {
        DB::connection($this->connection)->table($this->table())->updateOrInsert(
            ['key' => $key],
            ['value' => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );
    }

    public function get(string $key): ?string
    {
        return DB::connection($this->connection)->table($this->table())->where('key', $key)->value('value');
    }

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return DB::connection($this->connection)->table($this->table())->pluck('value', 'key')->all();
    }

    private function table(): string
    {
        return (string) config('oms_stress.marker_table');
    }
}
