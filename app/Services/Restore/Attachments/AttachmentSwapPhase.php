<?php

namespace App\Services\Restore\Attachments;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — the fixed, closed set of
 * phases the signed attachment-swap marker may ever record. A write-ahead
 * phase is durably persisted BEFORE the destructive filesystem mutation it
 * describes is attempted, and the resulting phase is durably persisted
 * immediately AFTER that mutation succeeds and before the next one is ever
 * attempted — see RestoreAttachmentActivationService.
 *
 * No "prepared" phase exists separately from ActivationStarted: activate()
 * is always the first call in this lifecycle, so there is nothing for a
 * distinct pre-activation phase to distinguish that ActivationStarted itself
 * (written before the very first destructive rename) does not already cover.
 *
 * AttachmentSwapMarkerReader only ever accepts one of these exact string
 * values (via tryFrom()) — anything else is treated as an unsupported,
 * untrustworthy phase, never coerced or guessed.
 */
enum AttachmentSwapPhase: string
{
    case ActivationStarted = 'activation_started';
    case LiveQuarantined = 'live_quarantined';
    case Activated = 'activated';
    case RollbackStarted = 'rollback_started';
    case RollbackLiveDiscarded = 'rollback_live_discarded';
    case RolledBack = 'rolled_back';
    case FinalizationStarted = 'finalization_started';
    case Finalized = 'finalized';
}
