<?php

namespace App\Services\Restore\Attachments;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — every state
 * AttachmentSwapStateInspector can determine from deterministic UUID-derived
 * filesystem facts (directory existence) combined with the SIGNED
 * attachment-swap marker's verified phase. Read-only by construction:
 * nothing that produces this enum ever repairs or mutates filesystem state
 * — see AttachmentSwapStateInspector's docblock. A marker that fails to
 * verify (missing signature, bad signature, UUID mismatch, unsupported
 * phase, malformed schema) NEVER produces any state below except
 * InconsistentNeedsManualReview — an untrustworthy marker is never treated
 * as equivalent to "no marker."
 */
enum AttachmentSwapState
{
    /**
     * No quarantine, no discard tree, and either no marker was ever written
     * for this restore UUID, or the marker records ActivationStarted while
     * the directory facts prove no destructive mutation actually took
     * effect yet (a crash between writing that marker and performing the
     * first rename) — both are safe to (re)start activate() from.
     */
    case NotActivated;

    /** Live attachments are the restored tree; quarantine holds the original, intact. */
    case Activated;

    /**
     * The process died between the two activation renames: the original
     * live tree was moved into quarantine, but the staged tree was never
     * moved into place — live attachments are currently missing.
     */
    case InterruptedDuringActivation;

    /**
     * The process died mid-rollback: the current (restored) live tree was
     * already moved aside to the deterministic discard path, but quarantine
     * has not yet been moved back into live — live attachments are
     * currently missing, quarantine is intact, and the discard tree holds
     * what was live a moment ago.
     */
    case InterruptedDuringRollback;

    /** rollback() completed: live attachments are the original pre-restore tree again. */
    case RolledBack;

    /** finalize() completed: quarantine was deleted; live attachments are the restored tree. */
    case Finalized;

    /**
     * The recorded marker phase and the actual directory layout disagree in
     * a way none of the above states account for. Never automatically
     * repaired — surfaced for manual review only.
     */
    case InconsistentNeedsManualReview;
}
