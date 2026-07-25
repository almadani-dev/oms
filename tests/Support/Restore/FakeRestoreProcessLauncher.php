<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\RestoreProcessLauncher;
use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use App\Services\Restore\RestoreProcessLaunchResult;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic test double for RestoreProcessLauncher — never spawns a
 * real process. Records every UUID it was asked to launch (in call order)
 * and, when configured to fail, throws the given RestoreProcessLaunchException
 * instead of returning a result.
 */
final class FakeRestoreProcessLauncher implements RestoreProcessLauncher
{
    /** @var list<string> */
    public array $launchedUuids = [];

    /** @var list<bool> whether the restore's progress.json already existed at the moment launch() was called, in call order */
    public array $progressExistedAtCallTime = [];

    private ?RestoreProcessLaunchException $failWith = null;

    public function failNextWith(RestoreProcessLaunchException $exception): void
    {
        $this->failWith = $exception;
    }

    public function launch(string $restoreUuid): RestoreProcessLaunchResult
    {
        $this->launchedUuids[] = $restoreUuid;
        $this->progressExistedAtCallTime[] = Storage::disk('restores')->exists("{$restoreUuid}/progress.json");

        if ($this->failWith !== null) {
            $exception = $this->failWith;
            $this->failWith = null;

            throw $exception;
        }

        return new RestoreProcessLaunchResult('fake', null);
    }
}
