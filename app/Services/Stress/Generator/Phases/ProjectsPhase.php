<?php

namespace App\Services\Stress\Generator\Phases;

use App\Models\Project;
use App\Services\Stress\Generator\StressBuildContext;
use App\Services\Stress\Generator\StressCalendar;
use App\Services\Stress\Generator\StressNames;
use App\Services\Stress\Generator\StressRandom;

/**
 * Projects (every existing super type plus projects without one, only the
 * two existing statuses) and their costs.
 *
 * Codes follow Project::boot(): PREFIX_YYYYMMDD_NNN, where NNN counts the
 * super type's projects (withTrashed) in insertion order. Every project is
 * created "غير مكتمل"; completion is a later audited edit applied once the
 * flow planner knows when execution finished.
 */
final class ProjectsPhase
{
    public const STATES = ['none', 'part_recv', 'full_recv', 'part_disb', 'full_disb', 'part_exec', 'full_exec'];

    /** @var array<int, array{id: int, super: ?string, approval: int, start: int, end: int, state: string, donor: ?int, name: string, deleted: bool, created_by: int}> */
    public array $projects = [];

    /** @var array<int, array{id: int, project: int, currency: int, cents: int, day: int}> */
    public array $costs = [];

    /** @var array<int, array<int, int>> project id => cost ids */
    public array $costsByProject = [];

    public function __construct(private readonly StressBuildContext $ctx) {}

    public function run(): void
    {
        $random = $this->ctx->random->fork('projects');
        $total = $this->ctx->scale->projects();
        $muwakha = min($this->ctx->scale->muwakhaProjects(), (int) floor($total / 4));
        $none = max(1, (int) round($total * 0.05));
        $rest = $total - $muwakha - $none;
        $last = $this->ctx->calendar->lastDay();

        $plan = array_merge(
            array_fill(0, $muwakha, 'MUWAKHA'),
            array_fill(0, $none, null),
        );

        foreach (['ORPHANS' => 0.26, 'FOOD' => 0.32, 'WATER' => 0.21, 'MID' => 0.21] as $prefix => $share) {
            $plan = array_merge($plan, array_fill(0, (int) round($rest * $share), $prefix));
        }

        // Approval days drive insertion order (ids grow with time).
        $specs = [];

        foreach ($plan as $super) {
            $specs[] = ['super' => $super, 'approval' => $this->ctx->calendar->randomDay($random, 0, $last - 10)];
        }

        usort($specs, fn (array $a, array $b) => $a['approval'] <=> $b['approval']);

        $sequence = [];
        $deletable = max(1, (int) round($total * 0.01));

        foreach ($specs as $index => $spec) {
            $super = $spec['super'];
            $approval = $spec['approval'];
            $superId = $super === null ? null : $this->ctx->supers[$super]['id'] ?? null;
            $super = $superId === null ? null : $super;
            $key = $superId ?? 0;
            $sequence[$key] = ($sequence[$key] ?? 0) + 1;

            $start = min($last, $approval + $random->int(0, 30));
            $end = $start + $random->int(90, 720);
            $age = $last - $approval;
            $state = (string) $random->weighted(match (true) {
                $age < 90 => ['none' => 30, 'part_recv' => 30, 'full_recv' => 10, 'part_disb' => 20, 'full_disb' => 5, 'part_exec' => 5, 'full_exec' => 0.01],
                $age < 365 => ['none' => 6, 'part_recv' => 15, 'full_recv' => 8, 'part_disb' => 18, 'full_disb' => 6, 'part_exec' => 32, 'full_exec' => 15],
                default => ['none' => 3, 'part_recv' => 7, 'full_recv' => 5, 'part_disb' => 8, 'full_disb' => 5, 'part_exec' => 30, 'full_exec' => 42],
            });

            $deleted = $super !== 'MUWAKHA' && $state === 'none' && $deletable > 0 && $random->chance(0.3);
            $deletable -= $deleted ? 1 : 0;

            $id = $this->ctx->nextId('projects');
            $by = $this->ctx->user($random->pick(['pm', 'pm_2', 'admin']))->id;
            $at = $this->ctx->calendar->timestamp($random, $approval);
            $name = $this->ctx->text->projectName($super, $sequence[$key]);
            $donor = $random->chance(0.95) ? $this->pickDonor($random, $approval) : null;
            $deletedAt = $deleted ? $this->ctx->calendar->timestamp($random, min($last, $approval + $random->int(5, 60))) : null;

            $this->ctx->writer->add('projects', [
                'id' => $id,
                'name' => $name,
                'code' => ($super ?? 'PRJ').'_'.str_replace('-', '', $this->ctx->calendar->date($approval)).'_'.str_pad((string) $sequence[$key], 3, '0', STR_PAD_LEFT),
                'approval_date' => $this->ctx->calendar->date($approval),
                'implementation_date' => $random->chance(0.8) ? $this->ctx->calendar->date(min($last, $start + $random->int(0, 15))) : null,
                'start_date' => $this->ctx->calendar->date($start),
                'end_date' => $end <= $last ? $this->ctx->calendar->date($end) : date('Y-m-d', strtotime(StressCalendar::END.' +'.($end - $last).' days')),
                'donor_project_name' => $random->chance(0.6) ? 'Synthetic donor project '.$id : null,
                'donor_id' => $donor,
                'project_super_id' => $superId,
                'project_status_id' => $this->ctx->statuses[StressNames::STATUS_INCOMPLETE],
                'notes' => $this->ctx->text->note(0.25),
                'created_by' => $by,
                'updated_by' => $by,
                'created_at' => $at,
                'updated_at' => $deletedAt ?? $at,
                'deleted_at' => $deletedAt,
            ]);

            if ($deleted) {
                $this->ctx->journal->deleted('projects', $id, $by);
            }

            $this->projects[$id] = [
                'id' => $id, 'super' => $super, 'approval' => $approval, 'start' => $start, 'end' => $end,
                'state' => $state, 'donor' => $donor, 'name' => $name, 'deleted' => $deleted, 'created_by' => $by,
            ];

            $this->costs($random, $id, $super, $approval, $by, $at);
        }

        $this->ctx->writer->flushAll();
    }

    private function costs(StressRandom $random, int $projectId, ?string $super, int $approval, int $by, string $projectAt): void
    {
        $count = (int) $random->weighted([1 => 35, 2 => 30, 3 => 12, 4 => 10, 5 => 8, 6 => 2, 8 => 2, 12 => 1]);
        $last = $this->ctx->calendar->lastDay();

        for ($i = 0; $i < $count; $i++) {
            $code = $super === 'MUWAKHA'
                ? ($random->chance(0.7) ? 'ILS' : 'USD')
                : (string) $random->weighted(['USD' => 55, 'ILS' => 30, 'EUR' => 15]);

            $amount = match ($code) {
                'USD' => $random->amount(25_000, 1.0, 500, 800_000),
                'ILS' => $random->amount(80_000, 1.0, 2_000, 2_500_000),
                default => $random->amount(20_000, 1.0, 500, 600_000),
            };

            // Most costs are entered in the project form's repeater (no
            // separate audit event); the rest later through the audited
            // CostsRelationManager.
            $later = $i > 0 && $random->chance(0.3);
            $day = $later ? min($last, $approval + $random->int(1, 60)) : $approval;
            $at = $later ? $this->ctx->calendar->timestamp($random, $day) : $projectAt;
            $id = $this->ctx->nextId('projects_costs');

            $this->ctx->writer->add('projects_costs', [
                'id' => $id,
                'project_id' => $projectId,
                'account_type_id' => null,
                'amount' => $amount,
                'currency_id' => $this->ctx->currencyId($code),
                'notes' => $this->ctx->text->note(0.15),
                'created_by' => $by,
                'updated_by' => $by,
                'created_at' => $at,
                'updated_at' => $at,
                'deleted_at' => null,
            ]);

            if ($later) {
                $this->ctx->journal->auditedCosts[$id] = true;
            }

            $this->costs[$id] = ['id' => $id, 'project' => $projectId, 'currency' => $this->ctx->currencyId($code), 'cents' => (int) round($amount * 100), 'day' => $day];
            $this->costsByProject[$projectId][] = $id;
        }
    }

    private function pickDonor(StressRandom $random, int $day): ?int
    {
        $weights = array_filter(
            $this->ctx->donorWeights,
            fn (float $w, int $id) => ($this->ctx->partnerFrom[$id] ?? 0) <= $day,
            ARRAY_FILTER_USE_BOTH,
        );

        return $weights === [] ? null : (int) $random->weighted($weights);
    }

    /**
     * Records the completion edit (غير مكتمل -> مكتمل) for a project whose
     * execution finished on $day.
     */
    public function complete(int $projectId, int $day, int $by): array
    {
        $at = $this->ctx->calendar->timestamp($this->ctx->random, $day);
        $incomplete = $this->ctx->statuses[StressNames::STATUS_INCOMPLETE];
        $complete = $this->ctx->statuses[StressNames::STATUS_COMPLETE];

        $this->ctx->journal->crudUpdate(Project::class, $projectId, $at, $by, ['project_status_id' => $incomplete], ['project_status_id' => $complete]);
        $this->ctx->journal->createdOverrides[Project::class][$projectId] = ['project_status_id' => $incomplete];

        return ['project_status_id' => $complete, 'updated_at' => $at, 'updated_by' => $by];
    }
}
