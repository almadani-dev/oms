<?php

namespace App\Http\Controllers\Restores;

use App\Http\Controllers\Controller;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\RestoreProgressReader;
use Illuminate\Http\JsonResponse;

/**
 * OMS Task 7C.8 section H — the DB-independent private restore progress
 * endpoint. Reached only via a Laravel signed GET URL (the `signed` route
 * middleware, applied in routes/web.php) carrying nothing but the restore
 * UUID, an expiration, and a signature — there is no session-based or
 * Filament-panel authentication here at all, by design: this is the one
 * surface that must keep answering while Laravel maintenance mode is active,
 * `database.default` is unreachable, and DB-backed sessions/cache/auth are
 * all unavailable (see routes/web.php's ->withoutMiddleware() list and
 * bootstrap/app.php's preventRequestsDuringMaintenance() exception for how
 * that is actually guaranteed, not merely assumed).
 *
 * Reads ONLY the private signed progress.json via RestoreProgressReader —
 * which itself needs no database connection, no cache store, and no
 * session — and returns a small, fixed, sanitized field set. Deliberately
 * excluded from the response, even though RestoreProgressSnapshot carries
 * them: the requester's email, any archive/workspace/filesystem path, the
 * encryption key id, and anything resembling a raw exception — see the
 * class docblock list in RestoreProgressSnapshot itself for what it never
 * carries in the first place.
 */
class RestoreProgressPollController extends Controller
{
    public function show(string $uuid, RestoreProgressReader $reader): JsonResponse
    {
        try {
            $progress = $reader->read($uuid);
        } catch (RestoreProgressIntegrityException) {
            // Never a raw exception message — missing, oversized, unsigned,
            // tampered, and UUID-mismatched all collapse to the exact same
            // generic, sanitized response so none of those states can be
            // distinguished by a caller who only holds a valid signed URL
            // for a DIFFERENT restore.
            return response()->json(['status' => 'unavailable'], 404);
        }

        return response()->json([
            'status' => 'ok',
            'restore_uuid' => $progress->restoreUuid,
            'source_backup_uuid' => $progress->sourceBackupUuid,
            'scope' => $progress->scope,
            'requested_by_name' => $progress->requestedBy['name'] ?? null,
            'requested_at' => $progress->requestedAt,
            'phase' => $progress->phase,
            'last_heartbeat_at' => $progress->lastHeartbeatAt,
            'result' => $progress->result,
            'restore_failed_phase' => $progress->restoreFailedPhase,
            'error_summary' => $progress->errorSummary,
        ]);
    }
}
