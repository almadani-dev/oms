<?php

namespace App\Services\Muwakha;

use App\Models\MuwakhaFamily;
use App\Support\Muwakha\MuwakhaReference;

/**
 * One family, flattened into exactly the fields both exports render.
 *
 * The field set is fixed HERE, once, rather than derived from the table's
 * visible columns — so toggling a column on screen changes the screen and
 * never the exported file, and the Excel and Word exports can never drift
 * apart from each other.
 *
 * Read-only value object: it holds already-resolved strings, so neither
 * export service performs a query or touches a relation while rendering.
 */
final class MuwakhaFamilyExportRow
{
    /**
     * @param  array<int, string>  $projects  "project — card" labels
     */
    private function __construct(
        public readonly string $martyrName,
        public readonly string $martyrNationalId,
        public readonly ?string $martyrDateOfBirth,
        public readonly ?string $martyrAge,
        public readonly ?string $martyrdomDate,
        public readonly int $childrenCount,
        public readonly string $guardianName,
        public readonly ?string $guardianNationalId,
        public readonly ?string $guardianDateOfBirth,
        public readonly string $guardianPhone,
        public readonly string $accountHolderName,
        public readonly ?string $accountCode,
        public readonly ?string $bankTypeName,
        public readonly ?string $currencyName,
        public readonly ?string $iban,
        public readonly array $projects,
        public readonly ?string $notes,
    ) {}

    public static function fromFamily(MuwakhaFamily $family): self
    {
        $age = $family->martyrAgeAtMartyrdom();

        return new self(
            martyrName: (string) $family->martyr_name,
            martyrNationalId: (string) $family->martyr_national_id,
            martyrDateOfBirth: $family->martyr_date_of_birth?->format('Y-m-d'),
            martyrAge: $age === null ? null : (string) $age,
            martyrdomDate: $family->martyrdom_date?->format('Y-m-d'),
            childrenCount: (int) $family->children_count,
            guardianName: (string) $family->guardian_name,
            guardianNationalId: $family->guardian_national_id,
            guardianDateOfBirth: $family->guardian_date_of_birth?->format('Y-m-d'),
            guardianPhone: (string) $family->guardian_phone,
            accountHolderName: (string) $family->account_holder_name,
            accountCode: $family->account?->account_code,
            bankTypeName: $family->account?->bankType?->name,
            // The linked Account's ACTUAL currency, in the one Muwakha display
            // convention — never a fixed code.
            currencyName: MuwakhaReference::currencyDisplayName($family->account?->currency),
            iban: $family->account?->iban,
            projects: $family->familyProjects
                ->map(fn ($link): string => $link->displayLabel())
                ->filter(fn (string $label): bool => $label !== '')
                ->values()
                ->all(),
            notes: $family->notes,
        );
    }

    /**
     * The linked projects as one cell value — "مؤاخاة كاف 2026 — G 10" per
     * line, so a family in several projects keeps each project's own card
     * code attached to that project rather than in a separate column.
     */
    public function projectsLabel(): string
    {
        return $this->projects === [] ? '—' : implode("\n", $this->projects);
    }

    /**
     * The exported column headers, in order. Shared by both services so the
     * two files always carry identical columns.
     *
     * @return array<int, string>
     */
    public static function headers(): array
    {
        return [
            'اسم الشهيد',
            'رقم هوية الشهيد',
            'تاريخ ميلاد الشهيد',
            'العمر عند الاستشهاد',
            'تاريخ الاستشهاد',
            'عدد الأبناء',
            'اسم الوصي',
            'رقم هوية الوصي',
            'تاريخ ميلاد الوصي',
            'رقم الجوال',
            'اسم صاحب الحساب',
            'رقم الحساب',
            'نوع البنك',
            'العملة',
            'IBAN',
            'مشاريع المؤاخاة',
            'ملاحظات',
        ];
    }

    /**
     * The row's values in the same order as headers().
     *
     * @return array<int, string>
     */
    public function values(): array
    {
        return [
            $this->martyrName,
            $this->martyrNationalId,
            $this->martyrDateOfBirth ?? '—',
            $this->martyrAge ?? '—',
            $this->martyrdomDate ?? '—',
            (string) $this->childrenCount,
            $this->guardianName,
            $this->guardianNationalId ?? '—',
            $this->guardianDateOfBirth ?? '—',
            $this->guardianPhone,
            $this->accountHolderName,
            $this->accountCode ?? '—',
            $this->bankTypeName ?? '—',
            $this->currencyName ?? '—',
            $this->iban ?? '—',
            $this->projectsLabel(),
            $this->notes ?? '—',
        ];
    }
}
