<?php

namespace App\Notifications;

use App\Enums\BackupScope;
use App\Enums\BackupType;
use App\Support\Backup\BackupLabels;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Persistent (database-only) notification for one backup/verification/
 * deletion outcome. Deliberately carries only primitive, already-sanitized
 * values captured at the moment of the event — never a live BackupOperation
 * reference, and never a secret: no encryption key, DB password, MYSQL_PWD,
 * absolute path, stored_path, or raw command/stack trace. `safeSummary` is
 * expected to already be sanitized by the caller (BackupCreationOrchestrator/
 * VerifyBackupIntegrityJob already do this before it ever reaches here).
 *
 * Not ShouldQueue: sent synchronously from inside a job that is already
 * running on the queue worker, so a second queued hop is unnecessary.
 */
class BackupOperationNotification extends Notification
{
    public function __construct(
        private readonly int $backupOperationId,
        private readonly string $backupUuid,
        private readonly BackupNotificationEvent $event,
        private readonly BackupType $type,
        private readonly BackupScope $scope,
        private readonly ?string $safeSummary = null,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title' => $this->event->title(),
            'event' => $this->event->value,
            'is_failure' => $this->event->isFailure(),
            'backup_operation_id' => $this->backupOperationId,
            'backup_uuid' => $this->backupUuid,
            'type' => $this->type->value,
            'type_label' => BackupLabels::type($this->type),
            'scope' => $this->scope->value,
            'scope_label' => BackupLabels::scope($this->scope),
            'summary' => $this->safeSummary,
            'occurred_at' => now()->toIso8601String(),
            'url' => $this->managementPageUrl(),
        ];
    }

    /**
     * Never lets a URL-resolution failure (e.g. an unusual console/test
     * context) break the notification itself.
     */
    private function managementPageUrl(): ?string
    {
        try {
            return \App\Filament\Pages\BackupManagementPage::getUrl();
        } catch (Throwable) {
            return null;
        }
    }
}
