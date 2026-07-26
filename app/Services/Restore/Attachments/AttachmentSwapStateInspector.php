<?php

namespace App\Services\Restore\Attachments;

use App\Services\Restore\Exceptions\RestoreAttachmentMarkerException;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — read-only inspection of an
 * attachment swap's current state, derived from deterministic UUID-derived
 * directory facts (live/quarantine/discard existence) combined with the
 * SIGNED attachment-swap marker's verified phase (see AttachmentSwapMarker/
 * AttachmentSwapMarkerReader). Never mutates anything, never repairs an
 * ambiguous combination.
 *
 * Trust rules, in order:
 *   1. A marker that fails signature/schema/UUID/phase verification (any
 *      RestoreAttachmentMarkerException) ALWAYS produces
 *      InconsistentNeedsManualReview — regardless of what the directories
 *      look like. A tampered/corrupt marker is never treated as absent.
 *   2. No marker file at all (a genuinely fresh restore) is NotActivated
 *      ONLY if neither quarantine nor discard exists — directory evidence
 *      of a mutation with no marker to explain it is itself impossible
 *      under this class's write-ahead protocol, so it is surfaced for
 *      manual review rather than assumed to mean "finished and finalized."
 *   3. A verified marker's phase is checked against the EXACT directory
 *      facts that phase's write-ahead protocol guarantees. Several phases
 *      accept more than one fact pattern because they are reachable from
 *      more than one prior state (e.g. RollbackStarted is written whether
 *      resuming from Activated or from InterruptedDuringActivation) — each
 *      accepted pattern resolves to its own correct, safely-resumable
 *      state; anything else is InconsistentNeedsManualReview.
 */
final class AttachmentSwapStateInspector
{
    public function __construct(private readonly string $disk = 'attachments')
    {
    }

    public function inspect(string $restoreUuid): AttachmentSwapState
    {
        $paths = new RestoreAttachmentPaths($this->disk);

        $liveExists = is_dir($paths->liveRoot());
        $quarantineExists = is_dir($paths->quarantineRoot($restoreUuid));
        $discardExists = is_dir($paths->rollbackDiscardRoot($restoreUuid));

        try {
            $marker = (new AttachmentSwapMarkerReader())->read($paths->markerPath($restoreUuid), $restoreUuid);
        } catch (RestoreAttachmentMarkerException) {
            return AttachmentSwapState::InconsistentNeedsManualReview;
        }

        if ($marker === null) {
            return ($quarantineExists || $discardExists)
                ? AttachmentSwapState::InconsistentNeedsManualReview
                : AttachmentSwapState::NotActivated;
        }

        return match ($marker->phase) {
            AttachmentSwapPhase::ActivationStarted => ($liveExists && ! $quarantineExists && ! $discardExists)
                ? AttachmentSwapState::NotActivated
                : AttachmentSwapState::InconsistentNeedsManualReview,

            AttachmentSwapPhase::LiveQuarantined => ($quarantineExists && ! $liveExists && ! $discardExists)
                ? AttachmentSwapState::InterruptedDuringActivation
                : AttachmentSwapState::InconsistentNeedsManualReview,

            AttachmentSwapPhase::Activated => ($quarantineExists && $liveExists && ! $discardExists)
                ? AttachmentSwapState::Activated
                : AttachmentSwapState::InconsistentNeedsManualReview,

            AttachmentSwapPhase::RollbackStarted => match (true) {
                $quarantineExists && $liveExists && ! $discardExists => AttachmentSwapState::Activated,
                $quarantineExists && ! $liveExists && ! $discardExists => AttachmentSwapState::InterruptedDuringActivation,
                default => AttachmentSwapState::InconsistentNeedsManualReview,
            },

            AttachmentSwapPhase::RollbackLiveDiscarded => match (true) {
                $quarantineExists && ! $liveExists && $discardExists => AttachmentSwapState::InterruptedDuringRollback,
                $quarantineExists && ! $liveExists && ! $discardExists => AttachmentSwapState::InterruptedDuringActivation,
                default => AttachmentSwapState::InconsistentNeedsManualReview,
            },

            AttachmentSwapPhase::RolledBack => (! $quarantineExists && $liveExists)
                ? AttachmentSwapState::RolledBack
                : AttachmentSwapState::InconsistentNeedsManualReview,

            AttachmentSwapPhase::FinalizationStarted => ($quarantineExists && $liveExists && ! $discardExists)
                ? AttachmentSwapState::Activated
                : AttachmentSwapState::InconsistentNeedsManualReview,

            AttachmentSwapPhase::Finalized => (! $quarantineExists && $liveExists && ! $discardExists)
                ? AttachmentSwapState::Finalized
                : AttachmentSwapState::InconsistentNeedsManualReview,
        };
    }
}
