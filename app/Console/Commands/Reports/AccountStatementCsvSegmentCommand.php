<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\AccountStatement\AccountStatementFilters;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvExport;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvLabels;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvPart;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvSegment;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Internal: one parallel segment of a «تصدير CSV» export, started only by
 * AccountStatementCsvExport (never by a user). Reads the projection and the
 * ledger (read-only, one consistent snapshot), writes the segment's CSV
 * records and its balances/totals as JSON next to them.
 *
 * Refuses a spec outside the export work root, and a database other than
 * the one the parent export runs on.
 */
class AccountStatementCsvSegmentCommand extends Command
{
    protected $signature = 'oms:account-statement-csv-segment {spec}';

    protected $description = 'Internal: write one segment of an account statement CSV export.';

    protected $hidden = true;

    public function handle(AccountStatementCsvSegment $segments): int
    {
        try {
            $spec = $this->spec((string) $this->argument('spec'));

            if (DB::connection()->getDatabaseName() !== $spec['database']) {
                throw new \RuntimeException('Segment worker connected to a different database than its export.');
            }

            $scope = AccountStatementScope::resolve(AccountStatementFilters::fromArray($spec['filters']));
            $labels = AccountStatementCsvLabels::load($scope);
            $part = new AccountStatementCsvPart($spec['out'], (bool) $spec['deflate']);
            $result = AccountStatementCsvSnapshot::run(fn () => $segments->write($scope, $labels, $spec['path'], $spec['from'], $spec['before'], $part));
            $result['part'] = $part->close();

            file_put_contents($spec['result'], json_encode($result, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $path): array
    {
        $root = realpath(AccountStatementCsvExport::root());
        $real = realpath($path);

        if ($root === false || $real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR.AccountStatementCsvExport::WORK_DIR_PREFIX)) {
            throw new \RuntimeException('Segment spec outside the export work directory.');
        }

        $spec = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
        $dir = dirname($real);

        foreach (['out', 'result'] as $key) {
            if (dirname((string) $spec[$key]) !== $dir && realpath(dirname((string) $spec[$key])) !== $dir) {
                throw new \RuntimeException('Segment output outside the export work directory.');
            }
        }

        return $spec;
    }
}
