<?php

namespace App\Services\Backup;

use App\Models\BackupOperation;
use App\Models\User;
use App\Notifications\BackupNotificationEvent;
use App\Notifications\BackupOperationNotification;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Resolves who should be persistently notified about one backup/
 * verification/deletion outcome and sends it. Recipient rule (per the OMS
 * Task 7B.2 design): a manually-created operation (created_by set) notifies
 * only that initiating user, if still active; a scheduler-created operation
 * (created_by null) notifies every active Super Admin. Never notifies a
 * normal user just because a `backups.*` permission was manually granted to
 * them — recipients are always resolved from `created_by` or the Super
 * Admin role, never from a permission check.
 */
final class BackupNotifier
{
    public function notify(BackupOperation $operation, BackupNotificationEvent $event, ?string $safeSummary = null): void
    {
        $recipients = $this->resolveRecipients($operation);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new BackupOperationNotification(
            backupOperationId: $operation->id,
            backupUuid: $operation->uuid,
            event: $event,
            type: $operation->type,
            scope: $operation->scope,
            safeSummary: $safeSummary,
        ));
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(BackupOperation $operation): Collection
    {
        if ($operation->created_by !== null) {
            $initiator = User::query()
                ->whereKey($operation->created_by)
                ->where('is_active', true)
                ->get();

            return $initiator;
        }

        try {
            return User::role(PermissionRegistry::SUPER_ADMIN)
                ->where('is_active', true)
                ->get();
        } catch (RoleDoesNotExist) {
            // Practically unreachable in a real environment (the Super
            // Admin role is a bootstrap invariant), but a scheduled backup
            // outcome must never crash the queue worker over this.
            return new Collection();
        }
    }
}
