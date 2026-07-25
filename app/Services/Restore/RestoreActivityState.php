<?php

namespace App\Services\Restore;

/**
 * OMS Task 7C.2 — RestoreActivityGuard's result. Active and TamperedOrInvalid
 * are kept distinct (rather than collapsed into one "blocked" boolean) so a
 * later phase's UI/recovery flow can tell a healthy in-progress restore
 * apart from a state that genuinely needs manual investigation — but for
 * "may a new restore start," both must block identically.
 */
enum RestoreActivityState
{
    case Active;
    case TamperedOrInvalid;
    case Inactive;

    public function blocksNewRestore(): bool
    {
        return $this !== self::Inactive;
    }
}
