<?php

namespace App\Services\Reports\DonorFinancialReport\Projection;

use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use Illuminate\Support\Facades\DB;

/**
 * The applied filters of one "تقرير الجهات المانحة", resolved live against
 * the authoritative project tables — exactly as DonorFinancialReportService
 * resolves them:
 *
 *  - the donor: a non-deleted partner flagged is_donor (else 404);
 *  - its projects after the project-level filters (status, type, project,
 *    approval_date range — never a movement date), non-deleted, id desc;
 *  - their non-deleted cost items (the projection is keyed by cost item, so
 *    a cost item moved to another project, or a project moved to another
 *    donor, needs no projection change);
 *  - the display-only currency filter, compared by code as the report does
 *    (a currency id without a row means no filter). It hides other-currency
 *    rows but never removes a project.
 *
 * A donor has at most a few hundred projects / cost items, so these two
 * small indexed reads replace nothing heavy and are repeated per request.
 */
final class DonorReportScope
{
    /**
     * @param  array<int, int>  $projectIds  id desc (the report's project order)
     * @param  array<int, int>  $costProjects  cost item id => project id (cost id asc)
     */
    private function __construct(
        public readonly Partner $donor,
        public readonly array $projectIds,
        public readonly array $costProjects,
        public readonly ?int $currencyId,
        public readonly ?string $currencyCode,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  date_from, date_to, project_status_id, project_super_id, project_id, currency_id
     */
    public static function resolve(int $donorId, array $filters): self
    {
        $donor = Partner::with('partnerType')->where('is_donor', true)->findOrFail($donorId);

        $projectIds = self::projects($donorId, $filters)
            ->orderBy('id', 'desc')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $costProjects = [];

        if ($projectIds !== []) {
            foreach (ProjectCost::query()->whereIn('project_id', $projectIds)->orderBy('id')->get(['id', 'project_id']) as $cost) {
                $costProjects[(int) $cost->id] = (int) $cost->project_id;
            }
        }

        $currencyCode = filled($filters['currency_id'] ?? null)
            ? DB::table('currencies')->where('id', $filters['currency_id'])->value('code')
            : null;

        return new self($donor, $projectIds, $costProjects, $currencyCode === null ? null : (int) $filters['currency_id'], $currencyCode);
    }

    /**
     * The donor's projects after the project-level filters (SoftDeletes
     * respected) — the report's fetchProjects() query, unordered.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function projects(int $donorId, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        return Project::query()
            ->where('donor_id', $donorId)
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($filters['project_status_id'] ?? null, fn ($q, $v) => $q->where('project_status_id', $v))
            ->when($filters['project_super_id'] ?? null, fn ($q, $v) => $q->where('project_super_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('approval_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('approval_date', '<=', $v));
    }

    /** @return array<int, int> */
    public function costIds(): array
    {
        return array_keys($this->costProjects);
    }

    /**
     * The cost items of the given projects only.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, int>
     */
    public function costIdsOf(array $projectIds): array
    {
        $wanted = array_flip($projectIds);

        return array_keys(array_filter($this->costProjects, fn (int $projectId): bool => isset($wanted[$projectId])));
    }

    /** The display-only currency filter keeps a currency id (by its code, as the report compares). */
    public function keepsCurrency(?int $currencyId): bool
    {
        return $this->currencyId === null || ($currencyId !== null && $currencyId === $this->currencyId);
    }
}
