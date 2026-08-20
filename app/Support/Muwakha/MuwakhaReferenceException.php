<?php

namespace App\Support\Muwakha;

use RuntimeException;

/**
 * Thrown when a piece of reference data the Muwakha feature structurally
 * depends on cannot be resolved to exactly one live row.
 *
 * Deliberately fail-closed. Both of these lookups (the `أفراد` account type
 * and the `مشروع المؤاخاة` root ProjectSuper) are resolved BY NAME rather
 * than by a hard-coded id, precisely so the feature stays correct across
 * installations whose reference tables were populated by hand in different
 * orders. The cost of that choice is that a missing row — or, worse, two rows
 * with the same name — is possible, and both cases must stop the operation
 * rather than guess.
 *
 * Guessing here would be materially unsafe: picking "the first أفراد" when
 * two exist would attach a real beneficiary payment account to the wrong
 * lookup and only surface much later, in a financial report.
 *
 * The account CURRENCY is deliberately not among these. It is an operator
 * choice made per family, so an absent or unknown currency is a form-input
 * problem and is raised as a field-level ValidationException by
 * MuwakhaFamilyService, not as a reference-data failure.
 *
 * The message is Arabic because it is surfaced directly to OMS finance staff
 * in a Filament notification.
 */
final class MuwakhaReferenceException extends RuntimeException
{
    public static function accountTypeMissing(string $name): self
    {
        return new self(
            "تعذر إنشاء حساب الأسرة: لا يوجد نوع حساب باسم «{$name}» في البيانات المرجعية. "
            .'يرجى إضافته من إعدادات أنواع الحسابات ثم إعادة المحاولة.'
        );
    }

    public static function accountTypeAmbiguous(string $name, int $count): self
    {
        return new self(
            "تعذر إنشاء حساب الأسرة: يوجد {$count} أنواع حسابات باسم «{$name}». "
            .'يجب أن يكون هناك نوع واحد فقط بهذا الاسم قبل المتابعة.'
        );
    }

    public static function projectSuperMissing(string $name): self
    {
        return new self(
            "تعذر تحديد مشاريع المؤاخاة: لا يوجد مشروع رئيسي باسم «{$name}». "
            .'يرجى التأكد من تنفيذ ترحيلات البيانات المرجعية.'
        );
    }

    public static function projectSuperAmbiguous(string $name, int $count): self
    {
        return new self(
            "تعذر تحديد مشاريع المؤاخاة: يوجد {$count} مشاريع رئيسية باسم «{$name}». "
            .'يجب أن يكون هناك مشروع رئيسي واحد فقط بهذا الاسم قبل المتابعة.'
        );
    }
}
