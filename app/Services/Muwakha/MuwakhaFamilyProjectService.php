<?php

namespace App\Services\Muwakha;

use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyProject;
use App\Services\Audit\Crud\AuditedCrudService;
use App\Support\Muwakha\MuwakhaReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single audited write path for family <-> Muwakha project links.
 *
 * Every rule here is enforced SERVER-SIDE, independent of whatever the
 * Filament Select offered. A Select's options are Livewire component state
 * and can be replaced by a crafted request, so eligibility, duplicate linkage
 * and card-code scoping are all re-checked here before anything is written —
 * the database constraints behind them are the last line, not the first.
 */
final class MuwakhaFamilyProjectService
{
    public function __construct(private readonly AuditedCrudService $crud) {}

    /**
     * @param  array<string, mixed>  $data  project_id + optional card_code
     */
    public function link(MuwakhaFamily $family, array $data): MuwakhaFamilyProject
    {
        $projectId = $data['project_id'] ?? null;
        $cardCode = $this->normalizeCardCode($data['card_code'] ?? null);

        $this->assertEligibleProject($projectId);
        $this->assertNotAlreadyLinked($family, (int) $projectId);
        $this->assertCardCodeFreeInProject((int) $projectId, $cardCode);

        return DB::transaction(fn (): MuwakhaFamilyProject => $this->crud->create(new MuwakhaFamilyProject, [
            'muwakha_family_id' => $family->id,
            'project_id' => (int) $projectId,
            'card_code' => $cardCode,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MuwakhaFamilyProject $link, array $data): MuwakhaFamilyProject
    {
        // The project may be reassigned through the relation manager's edit
        // form, so it is re-validated exactly like a fresh link.
        $projectId = (int) ($data['project_id'] ?? $link->project_id);
        $cardCode = $this->normalizeCardCode($data['card_code'] ?? null);

        $this->assertEligibleProject($projectId);
        $this->assertNotAlreadyLinked($link->muwakhaFamily, $projectId, ignoreLinkId: $link->id);
        $this->assertCardCodeFreeInProject($projectId, $cardCode, ignoreLinkId: $link->id);

        return DB::transaction(fn (): MuwakhaFamilyProject => $this->crud->update($link, [
            'project_id' => $projectId,
            'card_code' => $cardCode,
        ]));
    }

    /**
     * Removes exactly one link. The family, its Account and every other link
     * are untouched.
     */
    public function unlink(MuwakhaFamilyProject $link): bool
    {
        return DB::transaction(fn (): bool => $this->crud->delete($link));
    }

    /**
     * An empty or whitespace-only card code is stored as NULL, never as an
     * empty string. Without this, two families with a blank card code in one
     * project would collide on UNIQUE(project_id, card_code) — '' is a value,
     * NULL is not — and the approved rule is that any number of families may
     * have no card code.
     */
    private function normalizeCardCode(mixed $cardCode): ?string
    {
        $value = trim((string) ($cardCode ?? ''));

        return $value === '' ? null : $value;
    }

    private function assertEligibleProject(mixed $projectId): void
    {
        if (! MuwakhaReference::isEligibleProject($projectId)) {
            throw ValidationException::withMessages([
                'project_id' => 'يجب اختيار مشروع تابع للمشروع الرئيسي «'.MuwakhaReference::PROJECT_SUPER_NAME.'».',
            ]);
        }
    }

    private function assertNotAlreadyLinked(?MuwakhaFamily $family, int $projectId, ?int $ignoreLinkId = null): void
    {
        if (! $family) {
            throw ValidationException::withMessages([
                'project_id' => 'تعذر تحديد الأسرة المرتبطة بهذا السجل.',
            ]);
        }

        $exists = MuwakhaFamilyProject::query()
            ->where('muwakha_family_id', $family->id)
            ->where('project_id', $projectId)
            ->when($ignoreLinkId, fn ($query) => $query->where('id', '!=', $ignoreLinkId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'project_id' => 'هذه الأسرة مرتبطة بهذا المشروع بالفعل.',
            ]);
        }
    }

    /**
     * A NULL card code is always free — multiple families in one project may
     * legitimately have none.
     */
    private function assertCardCodeFreeInProject(int $projectId, ?string $cardCode, ?int $ignoreLinkId = null): void
    {
        if ($cardCode === null) {
            return;
        }

        $taken = MuwakhaFamilyProject::query()
            ->where('project_id', $projectId)
            ->where('card_code', $cardCode)
            ->when($ignoreLinkId, fn ($query) => $query->where('id', '!=', $ignoreLinkId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'card_code' => 'رقم البطاقة «'.$cardCode.'» مستخدم بالفعل ضمن هذا المشروع.',
            ]);
        }
    }
}
