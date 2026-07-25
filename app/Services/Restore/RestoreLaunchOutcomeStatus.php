<?php

namespace App\Services\Restore;

/**
 * OMS Task 7C.4 — RestoreLaunchService::launch()'s result category.
 * RestoreLaunchController maps these 1:1 onto the required HTTP responses
 * (Accepted -> 202, Conflict -> 409, Locked -> 423, Failed -> sanitized 500).
 */
enum RestoreLaunchOutcomeStatus
{
    case Accepted;
    case Conflict;
    case Locked;
    case Failed;
}
