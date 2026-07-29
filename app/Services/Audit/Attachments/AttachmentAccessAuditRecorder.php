<?php

namespace App\Services\Audit\Attachments;

use App\Enums\AuditFailureMode;
use App\Enums\AuditStatus;
use App\Models\Attachment;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;
use Throwable;

/**
 * The single write path for reading a private attachment (OMS Task 9B.5):
 * the two REQUIRED success events on `event_category = attachment`, and the
 * one BEST-EFFORT denial event on `event_category = security`.
 *
 * Separate from AttachmentAuditRecorder on purpose. These events happen on a
 * plain authenticated GET (routes/web.php -> AttachmentController::show),
 * which has no business transaction to belong to — so this class deliberately
 * does NOT assert an open DB::transaction(), and AttachmentAuditRecorder
 * deliberately does. Neither can emit the other's events.
 *
 * VIEWED vs DOWNLOADED IS A REAL DISTINCTION IN THIS APPLICATION, NOT AN
 * INFERENCE FROM BROWSER HEADERS. The route itself carries the mode as a path
 * segment constrained to exactly `view|download`
 * (Route::get('/attachments/{attachment}/{mode}')->whereIn('mode', ...)), the
 * controller re-validates it against the same two literals, and it selects a
 * genuinely different response — Content-Disposition `inline` for a preview
 * versus `attachment` for a download. Two distinct actions are therefore an
 * accurate record of two distinct, explicitly requested operations. Nothing
 * here reads Accept, User-Agent, Sec-Fetch-Dest or any other header to guess
 * intent; if the mode segment were ever collapsed to one path, this must
 * collapse to a single `accessed` action rather than start guessing.
 *
 * REQUIRED, AND WRITTEN BEFORE THE BYTES. accessed() is called after
 * authorization succeeds and before AttachmentStorageService builds the
 * streamed response, in AuditFailureMode::Required: if the access cannot be
 * recorded, AuditPersistenceException propagates out of the controller and
 * the private file is NOT served. A private financial attachment that cannot
 * be accounted for is not read in this system.
 *
 * BEST-EFFORT FOR THE DENIAL, FOR THE OPPOSITE REASON. accessDenied()
 * describes a request that is ALREADY being refused; there is nothing left to
 * roll back and nothing useful to take down with it. Letting an audit-storage
 * outage convert a clean 403 into a 500 would weaken the denial and hand an
 * unauthorized caller a distinguishable response, so persistence failure is
 * swallowed (and logged, sanitized, by AuditLogger) and the 403 stands.
 */
final class AttachmentAccessAuditRecorder
{
    public const EVENT_CATEGORY = 'attachment';

    /**
     * A denial is a security event, not an attachment-lifecycle event: it
     * describes an actor being refused, and belongs with the rest of the
     * Task 9B.4 security trail an administrator reviews.
     */
    public const DENIED_EVENT_CATEGORY = 'security';

    public const DENIED_EVENT_ACTION = 'attachment_access_denied';

    /**
     * The request's {mode} segment mapped to the recorded action. A value
     * outside this map cannot reach here (the route and the controller both
     * constrain it), and would fail closed rather than invent an action.
     */
    private const ACTIONS = [
        'view' => 'viewed',
        'download' => 'downloaded',
    ];

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AuditActorResolver $actorResolver,
        private readonly AttachmentAuditMetadata $metadata,
    ) {}

    /**
     * Records one authorized access. Actor, IP, user agent, route name and
     * HTTP method all come from AuditActorResolver::resolve(), which attaches
     * genuine request metadata only when a real routed HTTP request exists —
     * nothing is fabricated for this event.
     *
     * @throws \App\Services\Audit\Exceptions\AuditPersistenceException when
     *         the event cannot be persisted, so the caller never serves the file
     */
    public function accessed(Attachment $attachment, string $mode): void
    {
        $action = self::ACTIONS[$mode] ?? null;

        if ($action === null) {
            return;
        }

        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: $action,
                actor: $this->actorResolver->resolve(),
                status: AuditStatus::Success,
                subjectType: AttachmentAuditRecorder::SUBJECT_TYPE,
                subjectKey: (string) $attachment->getKey(),
                subjectLabel: $this->metadata->label($attachment),
                newValues: $this->metadata->of($attachment, $action),
            ),
            AuditFailureMode::Required,
        );
    }

    /**
     * Records ONE event for a genuine authorization denial (403) on an
     * attachment whose row and supported parent this application had ALREADY
     * safely resolved from the numeric route id before the Gate ran.
     *
     * Nothing attacker-controlled is ever recorded: the only request input on
     * this route is a digits-only id (route-constrained, re-validated in the
     * controller) and a `view|download` literal. No path, no filename, no
     * disk, and no arbitrary lookup string can reach this payload.
     *
     * Deliberately never called for a 404 — a missing attachment, a missing
     * or soft-deleted parent, an unsupported attachable type, an unapproved
     * disk or an absent file are ordinary not-found outcomes, not denials,
     * and auditing them would let an unauthenticated-but-logged-in prober
     * write a row per guessed id.
     *
     * Never throws: see the class docblock — the denial must survive an audit
     * outage. AuditFailureMode::BestEffort covers a persistence failure; the
     * catch below covers the metadata lookups themselves, which touch the
     * same database that would already be failing in that scenario.
     */
    public function accessDenied(Attachment $attachment): void
    {
        try {
            $this->logger->record(
                new AuditRecordRequest(
                    eventCategory: self::DENIED_EVENT_CATEGORY,
                    eventAction: self::DENIED_EVENT_ACTION,
                    actor: $this->actorResolver->resolve(),
                    status: AuditStatus::Failure,
                    subjectType: AttachmentAuditRecorder::SUBJECT_TYPE,
                    subjectKey: (string) $attachment->getKey(),
                    subjectLabel: $this->metadata->label($attachment),
                    newValues: $this->metadata->of($attachment, self::DENIED_EVENT_ACTION),
                ),
                AuditFailureMode::BestEffort,
            );
        } catch (Throwable) {
            // Intentionally swallowed. The caller is in the middle of
            // refusing a request; converting that refusal into a 500 would
            // be a strictly worse outcome than an unrecorded denial.
        }
    }
}
