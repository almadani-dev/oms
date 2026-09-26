<?php

namespace App\Services\Muwakha\Import;

use App\Models\MuwakhaFamily;
use App\Services\Muwakha\MuwakhaFamilyService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The write half of the one-time Muwakha families importer: it replays an
 * already-preflighted report through the OFFICIAL domain write path and does
 * nothing else.
 *
 * IT ADDS NO DOMAIN LOGIC. Every family is created by
 * MuwakhaFamilyService::create() — the same call the Filament
 * CreateMuwakhaFamily page makes — so the Account, the family row, the
 * MuwakhaFamilyAccount mapping, the MuwakhaFamilyProject link, the card code,
 * the AuditEvents and the created_by/updated_by tracking are all produced by
 * the code that owns them. There is no DB::table() insert, no Model::create(),
 * no Account creation and no second accounting path anywhere in this class.
 *
 * ALL 72 OR NONE. One outer DB::transaction() wraps the whole batch. The
 * service opens its own transaction per family, which nests as a savepoint, so
 * a failure on the last family discards every earlier one: there is no state in
 * which some families are imported and some are not. That is why this class
 * deliberately has no "continue on error", no per-row try/catch and no
 * progress-resume option — either of those would produce exactly the partial
 * import this batch forbids.
 *
 * THE ACTOR IS REAL. A CLI process has no authenticated user, so `created_by`,
 * `updated_by` (App\Traits\HasUserTracking reads auth()->id()) and every
 * AuditEvent actor (App\Services\Audit\AuditActorResolver reads Auth::user())
 * would otherwise record nothing/guest. The resolved OMS user from the report
 * is therefore authenticated into the normal Laravel auth context for the
 * duration of the batch and removed again afterwards, so nothing leaks into a
 * later call in the same process. No fake user is invented and no id is
 * hard-coded — resolution happens in the preflight, from the users table.
 */
final class MuwakhaFamilyImporter
{
    public function __construct(private readonly MuwakhaFamilyService $families) {}

    /**
     * @return array<int, MuwakhaFamily>  created families, keyed by the source
     *                                    row's position in the file
     */
    public function import(MuwakhaFamilyImportReport $report): array
    {
        if (! $report->isImportable()) {
            // Fails closed. The command already refuses to get here, so this is
            // the guarantee that no future caller can reach the write path by
            // skipping the preflight verdict.
            throw MuwakhaFamilyImportException::reportNotImportable(
                implode(' ', $report->blockingReasons()),
            );
        }

        $actor = $report->actor;
        $previous = Auth::user();

        Auth::setUser($actor);

        try {
            return DB::transaction(function () use ($report): array {
                $created = [];

                foreach ($report->readyRows() as $row) {
                    $created[$row->index] = $this->families->create($row->requirePayload());
                }

                return $created;
            });
        } finally {
            $previous instanceof Authenticatable
                ? Auth::setUser($previous)
                : Auth::forgetUser();
        }
    }
}
