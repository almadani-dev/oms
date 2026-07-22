<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\Currency;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for the financial attachment registry (OMS Task 6D).
 *
 * Maps each of the five supported attachable types to: its Arabic operation
 * label, the parent-module `.view` permission that governs it, the exact
 * relationships to eager-load for the display columns, and per-row accessors
 * that read only the already-approved denormalized parent fields (never
 * recomputing accounting values from transaction lines, never mixing
 * currencies).
 *
 * The supported-type list here is deliberately identical to
 * AttachmentController::SUPPORTED_ATTACHABLE_TYPES (the security choke point
 * from Task 6A). That controller keeps its own private allowlist as the
 * authorization boundary for actually serving bytes - this registry is the UI
 * layer only. AttachmentRegistryTest asserts the two lists stay in exact sync
 * so neither can drift without a failing test.
 */
class FinancialAttachmentRegistry
{
    /**
     * attachable FQCN => [label, permission, with].
     *
     * `with` is the list of parent relationships needed by the display
     * accessors below; it is fed to MorphTo::morphWith() so each polymorphic
     * type loads only what it needs, in one batched query per type.
     *
     * @return array<class-string, array{label: string, permission: string, with: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            ProjectCostReceipt::class => [
                'label' => 'مبلغ مستلم',
                'permission' => 'project_cost_receipts.view',
                'with' => ['transaction', 'projectCost.project', 'currency'],
            ],
            ProjectCostBudget::class => [
                'label' => 'صرف مبلغ مشروع',
                'permission' => 'project_cost_budgets_payments.view',
                'with' => ['transaction', 'projectCost.project', 'disbursementCurrency'],
            ],
            ProjectCostBudgetsPayment::class => [
                'label' => 'صرف مبلغ تنفيذ',
                'permission' => 'execution_payments.view',
                'with' => ['transaction', 'projectCostBudget.projectCost.project', 'currency'],
            ],
            GeneralExpense::class => [
                'label' => 'مصروف عام',
                'permission' => 'general_expenses.view',
                'with' => ['transaction', 'currency'],
            ],
            GeneralExchange::class => [
                'label' => 'تحويل عام',
                'permission' => 'general_exchanges.view',
                'with' => ['transaction', 'disbursementCurrency'],
            ],
        ];
    }

    /**
     * @return list<class-string>
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::definitions());
    }

    public static function isSupported(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::definitions());
    }

    public static function labelFor(?string $type): ?string
    {
        return self::definitions()[$type]['label'] ?? null;
    }

    public static function parentPermissionFor(?string $type): ?string
    {
        return self::definitions()[$type]['permission'] ?? null;
    }

    /**
     * Arabic operation-type options keyed by FQCN, for the operation-type
     * SelectFilter.
     *
     * @return array<class-string, string>
     */
    public static function typeOptions(): array
    {
        return array_map(static fn (array $def): string => $def['label'], self::definitions());
    }

    /**
     * The subset of supported types whose parent-view permission $user holds.
     * A real Super Admin holds all five (Gate::before grants every ability),
     * so this returns all five for that role without any special-casing here.
     *
     * @return list<class-string>
     */
    public static function allowedTypesFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $allowed = [];

        foreach (self::definitions() as $type => $definition) {
            if ($user->can($definition['permission'])) {
                $allowed[] = $type;
            }
        }

        return $allowed;
    }

    /**
     * Whether $user may open a specific registry record. Requires the generic
     * attachments.view ability AND the parent-module permission for this
     * record's attachable type. Enforced again on the View page (not only by
     * hiding table rows) - see AttachmentResource::canView().
     *
     * Intentionally does NOT reject a soft-deleted Attachment: viewing a
     * trashed row's audit metadata is allowed (the View page still refuses to
     * render its preview/download - the file route 404s a trashed row).
     */
    public static function userCanView(?User $user, Attachment $attachment): bool
    {
        if ($user === null) {
            return false;
        }

        if (! self::isSupported($attachment->attachable_type)) {
            return false;
        }

        if (! $user->can('attachments.view')) {
            return false;
        }

        $permission = self::parentPermissionFor($attachment->attachable_type);

        return $permission !== null && $user->can($permission);
    }

    /**
     * Eager-load the polymorphic parent (with only the per-type relationships
     * the display needs) plus the uploader, in a fixed number of queries
     * regardless of row count.
     */
    public static function eagerLoad(Builder $query): Builder
    {
        return $query->with([
            'createdBy',
            'attachable' => static function (MorphTo $morphTo): void {
                $morphTo->morphWith(array_map(
                    static fn (array $definition): array => $definition['with'],
                    self::definitions(),
                ));
            },
        ]);
    }

    /**
     * Restrict a query to the five supported attachable types. Used by the
     * resource's base query so unsupported Project/Transaction/Partner rows
     * are never listed or bound through this financial registry.
     */
    public static function scopeSupported(Builder $query): Builder
    {
        return $query->whereIn('attachable_type', self::supportedTypes());
    }

    /**
     * Restrict a LIST query to rows $user may actually view: only the allowed
     * attachable types, and only rows whose parent record exists and is not
     * soft-deleted (whereHasMorph applies each parent's default SoftDelete
     * scope). One EXISTS subquery per allowed type - bounded, never per-row.
     * An actor with none of the five parent permissions sees nothing.
     */
    public static function scopeViewableBy(Builder $query, ?User $user): Builder
    {
        $allowed = self::allowedTypesFor($user);

        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHasMorph('attachable', $allowed);
    }

    // ---- per-row display accessors (parent expected already eager-loaded) --

    public static function operationNumber(Attachment $attachment): ?string
    {
        return $attachment->attachable?->transaction?->transaction_number;
    }

    public static function projectName(Attachment $attachment): ?string
    {
        $parent = $attachment->attachable;

        return match ($attachment->attachable_type) {
            ProjectCostReceipt::class, ProjectCostBudget::class => $parent?->projectCost?->project?->name,
            ProjectCostBudgetsPayment::class => $parent?->projectCostBudget?->projectCost?->project?->name,
            default => null,
        };
    }

    public static function operationDate(Attachment $attachment): ?Carbon
    {
        $parent = $attachment->attachable;

        if ($parent === null) {
            return null;
        }

        // ProjectCostBudget (disbursement) has no own date column; its View
        // page reads transaction.transaction_time ("تاريخ الصرف"). The other
        // four expose a real `date` column.
        if ($attachment->attachable_type === ProjectCostBudget::class) {
            return $parent->transaction?->transaction_time;
        }

        return $parent->date;
    }

    /**
     * The single approved denormalized amount for the row's operation. The two
     * multi-currency operations (disbursement, general exchange) use their
     * post-fx final_amount, which is expressed in the disbursement currency -
     * matching currency() below so the two never mix. The three single-
     * currency operations use their own amount column.
     */
    public static function amount(Attachment $attachment): ?string
    {
        $parent = $attachment->attachable;

        if ($parent === null) {
            return null;
        }

        return match ($attachment->attachable_type) {
            ProjectCostBudget::class, GeneralExchange::class => $parent->final_amount,
            default => $parent->amount,
        };
    }

    /**
     * The currency that goes with amount() above - disbursement currency for
     * the two multi-currency operations, the operation's own currency for the
     * three single-currency ones. Never blended.
     */
    public static function currency(Attachment $attachment): ?Currency
    {
        $parent = $attachment->attachable;

        if ($parent === null) {
            return null;
        }

        return match ($attachment->attachable_type) {
            ProjectCostBudget::class, GeneralExchange::class => $parent->disbursementCurrency,
            default => $parent->currency,
        };
    }

    public static function amountWithCurrency(Attachment $attachment): ?string
    {
        $amount = self::amount($attachment);

        if ($amount === null) {
            return null;
        }

        $currency = self::currency($attachment);
        $code = $currency?->code ?? $currency?->name;
        $formatted = number_format((float) $amount, 2);

        return $code !== null ? "{$formatted} {$code}" : $formatted;
    }

    /**
     * Human status of the stored disk, never exposing the raw disk name for an
     * unexpected value.
     */
    public static function diskLabel(?string $disk): string
    {
        return match ($disk) {
            Attachment::DISK_ATTACHMENTS => 'خاص',
            Attachment::DISK_PUBLIC => 'عام انتقالي',
            default => 'قرص غير معروف',
        };
    }
}
