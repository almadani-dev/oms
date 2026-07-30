<?php

namespace App\Services\Audit\BackupRestore;

use App\Services\Backup\Exceptions\BackupDeletionRejectedException;
use App\Services\Backup\Exceptions\BackupEncryptionException;
use App\Services\Backup\Exceptions\BackupEnvelopeCorruptException;
use App\Services\Backup\Exceptions\BackupEnvelopeUnsupportedVersionException;
use App\Services\Backup\Exceptions\BackupIntegrityException;
use App\Services\Backup\Exceptions\BackupKeyConfigurationException;
use App\Services\Backup\Exceptions\BackupLockedException;
use App\Services\Backup\Exceptions\BackupOperationException;
use App\Services\Backup\Exceptions\DatabaseDumpException;
use App\Services\Restore\Exceptions\RestoreArchiveExtractionException;
use App\Services\Restore\Exceptions\RestoreArchivePreparationException;
use App\Services\Restore\Exceptions\RestoreAttachmentFinalizationException;
use App\Services\Restore\Exceptions\RestoreAttachmentMarkerException;
use App\Services\Restore\Exceptions\RestoreAttachmentRollbackException;
use App\Services\Restore\Exceptions\RestoreAttachmentsException;
use App\Services\Restore\Exceptions\RestoreAttachmentSwapException;
use App\Services\Restore\Exceptions\RestoreAttachmentValidationException;
use App\Services\Restore\Exceptions\RestoreDatabaseException;
use App\Services\Restore\Exceptions\RestoreInsufficientDiskSpaceException;
use App\Services\Restore\Exceptions\RestoreMaintenanceModeException;
use App\Services\Restore\Exceptions\RestoreMetadataReconciliationException;
use App\Services\Restore\Exceptions\RestoreOrchestrationException;
use App\Services\Restore\Exceptions\RestorePreflightException;
use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\Exceptions\RestoreProgressWriteException;
use App\Services\Restore\Exceptions\RestoreReconciliationException;
use App\Services\Restore\Exceptions\RestoreRequestRejectedException;
use App\Services\Restore\Exceptions\RestoreStaleAcknowledgmentException;
use App\Services\Restore\Exceptions\RestoreWorkspaceException;
use Throwable;

/**
 * Turns a backup/restore failure into a GENERIC, bounded failure code and
 * category for an audit payload (OMS Task 9B.6 §4/§5).
 *
 * ---------------------------------------------------------------------------
 * AN EXCEPTION MESSAGE IS NEVER READ HERE. NOT EVEN A SANITIZED ONE.
 * ---------------------------------------------------------------------------
 * App\Support\Backup\BackupErrorSanitizer (used for
 * `backup_operations.error_summary` and for the signed progress file's
 * `error_summary`) strips this app's absolute paths and `MYSQL_PWD=...` and
 * bounds the length — which is the right guarantee for those two operational
 * records, but it is NOT sufficient for the audit trail: a `mysql`/`mysqldump`
 * driver error, a PDO QueryException, or a ZipArchive error can still carry SQL
 * text, bound values, a third-party absolute path, or table/column detail in
 * its message. This class therefore never calls getMessage(), never inspects
 * getTrace()/getTraceAsString(), and never stores any part of an exception's
 * text.
 *
 * Only two things are ever derived, both from structure rather than prose:
 *
 *  1. `failure_code` — the exception's OWN fixed `reasonCode` property where it
 *     has one. Almost every exception in App\Services\Restore\Exceptions and
 *     BackupDeletionRejectedException already carries exactly that: a
 *     hand-written, closed snake_case vocabulary set by a named constructor
 *     (e.g. RestoreOrchestrationException::safetyBackupNotVerified() ->
 *     'safety_backup_not_verified'). It is still re-validated below against a
 *     strict snake_case pattern and a length bound before use, so a future
 *     exception that put something else in that property can never widen what
 *     lands in an audit row.
 *  2. `failure_category` — matched from the exception CLASS (walking the
 *     `previous` chain, most specific class first), never from text.
 *
 * Anything unrecognized falls back to `unexpected_failure` / `unknown` rather
 * than guessing — an unclassified failure is honestly reported as
 * unclassified.
 */
final class BackupRestoreFailure
{
    public const UNKNOWN_CODE = 'unexpected_failure';

    public const UNKNOWN_CATEGORY = 'unknown';

    /**
     * Matches AuditLogger's own snake_case rule for event names — a
     * `reasonCode` that is not shaped like a stable machine identifier is
     * discarded rather than stored.
     */
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const MAX_CODE_LENGTH = 60;

    /**
     * Bounds how far the `previous` chain is walked — a pathological or
     * circular chain must never turn payload building into an unbounded loop.
     */
    private const MAX_CHAIN_DEPTH = 6;

    /**
     * Class => category, evaluated in this exact order with `instanceof`, so a
     * specific subclass is always matched before a broader base class — the
     * three envelope/key exceptions before BackupEncryptionException (their
     * real parent), and BackupOperationException last among the backup ones
     * since the creation pipeline wraps the others in it.
     *
     * Every attachment exception is listed individually and deliberately:
     * RestoreAttachmentsException is `final` and is NOT a base class (each of
     * the six extends RuntimeException directly), so one entry could never
     * cover the rest.
     *
     * @var array<class-string<Throwable>, string>
     */
    private const CATEGORIES = [
        // ---- restore ------------------------------------------------------
        RestorePreflightException::class => 'preflight',
        RestoreInsufficientDiskSpaceException::class => 'disk_space',
        RestoreMaintenanceModeException::class => 'maintenance_mode',
        RestoreArchiveExtractionException::class => 'archive_preparation',
        RestoreArchivePreparationException::class => 'archive_preparation',
        RestoreWorkspaceException::class => 'archive_preparation',
        RestoreDatabaseException::class => 'database_import',
        RestoreMetadataReconciliationException::class => 'reconciliation',
        RestoreReconciliationException::class => 'reconciliation',
        RestoreProgressWriteException::class => 'progress_journal',
        RestoreProgressIntegrityException::class => 'progress_journal',
        RestoreProcessLaunchException::class => 'process_launch',
        RestoreRequestRejectedException::class => 'request_rejected',
        RestoreStaleAcknowledgmentException::class => 'stale_acknowledgment',
        RestoreOrchestrationException::class => 'orchestration',
        RestoreAttachmentSwapException::class => 'attachments',
        RestoreAttachmentRollbackException::class => 'attachments',
        RestoreAttachmentFinalizationException::class => 'attachments',
        RestoreAttachmentMarkerException::class => 'attachments',
        RestoreAttachmentValidationException::class => 'attachments',
        RestoreAttachmentsException::class => 'attachments',

        // ---- backup -------------------------------------------------------
        BackupKeyConfigurationException::class => 'encryption_configuration',
        BackupEnvelopeCorruptException::class => 'archive_envelope',
        BackupEnvelopeUnsupportedVersionException::class => 'archive_envelope',
        BackupEncryptionException::class => 'encryption',
        BackupIntegrityException::class => 'integrity_verification',
        DatabaseDumpException::class => 'database_dump',
        BackupLockedException::class => 'lock_conflict',
        BackupDeletionRejectedException::class => 'deletion_rejected',
        BackupOperationException::class => 'backup_pipeline',
    ];

    /**
     * Fallback codes for the exceptions that carry no `reasonCode` of their
     * own. Same ordering rule as CATEGORIES.
     *
     * @var array<class-string<Throwable>, string>
     */
    private const CLASS_CODES = [
        BackupKeyConfigurationException::class => 'encryption_key_configuration_invalid',
        BackupEnvelopeCorruptException::class => 'archive_envelope_corrupt',
        BackupEnvelopeUnsupportedVersionException::class => 'archive_envelope_version_unsupported',
        BackupEncryptionException::class => 'archive_encryption_failed',
        BackupIntegrityException::class => 'archive_verification_failed',
        DatabaseDumpException::class => 'database_dump_failed',
        BackupLockedException::class => 'backup_subsystem_locked',
        BackupOperationException::class => 'backup_pipeline_failed',
        RestoreProgressWriteException::class => 'progress_write_failed',
        RestoreProgressIntegrityException::class => 'progress_unreadable',
    ];

    /**
     * The two payload keys every failure event carries, and the only failure
     * information any of them ever carries.
     *
     * $explicitCode lets a caller that already holds a fixed, non-exception
     * reason code (RestoreLaunchService's launcher/progress reason codes, for
     * instance) supply it directly; it is validated exactly like a
     * `reasonCode` read off an exception.
     *
     * @return array{failure_code: string, failure_category: string}
     */
    public static function payload(?Throwable $exception, ?string $explicitCode = null): array
    {
        return [
            'failure_code' => self::code($exception, $explicitCode),
            'failure_category' => self::category($exception),
        ];
    }

    public static function code(?Throwable $exception, ?string $explicitCode = null): string
    {
        $explicit = self::sanitizeCode($explicitCode);

        if ($explicit !== null) {
            return $explicit;
        }

        foreach (self::chain($exception) as $link) {
            $code = self::sanitizeCode(self::reasonCodeOf($link));

            if ($code !== null) {
                return $code;
            }
        }

        foreach (self::chain($exception) as $link) {
            foreach (self::CLASS_CODES as $class => $code) {
                if ($link instanceof $class) {
                    return $code;
                }
            }
        }

        return self::UNKNOWN_CODE;
    }

    public static function category(?Throwable $exception): string
    {
        foreach (self::chain($exception) as $link) {
            foreach (self::CATEGORIES as $class => $category) {
                if ($link instanceof $class) {
                    return $category;
                }
            }
        }

        return self::UNKNOWN_CATEGORY;
    }

    /**
     * Reads a `reasonCode` PROPERTY only — never a method call, so nothing
     * with side effects can be triggered while building an audit payload.
     */
    private static function reasonCodeOf(Throwable $exception): ?string
    {
        if (! property_exists($exception, 'reasonCode')) {
            return null;
        }

        /** @var mixed $value */
        $value = $exception->reasonCode;

        return is_string($value) ? $value : null;
    }

    private static function sanitizeCode(?string $code): ?string
    {
        if ($code === null || $code === '' || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            return null;
        }

        return preg_match(self::CODE_PATTERN, $code) === 1 ? $code : null;
    }

    /**
     * @return list<Throwable>
     */
    private static function chain(?Throwable $exception): array
    {
        $chain = [];
        $seen = [];
        $current = $exception;

        while ($current !== null && count($chain) < self::MAX_CHAIN_DEPTH) {
            $id = spl_object_id($current);

            if (isset($seen[$id])) {
                break;
            }

            $seen[$id] = true;
            $chain[] = $current;
            $current = $current->getPrevious();
        }

        return $chain;
    }
}
