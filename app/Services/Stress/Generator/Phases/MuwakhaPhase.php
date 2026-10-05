<?php

namespace App\Services\Stress\Generator\Phases;

use App\Models\MuwakhaFamily;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Services\Stress\Generator\AccountFactory;
use App\Services\Stress\Generator\StressBuildContext;
use App\Services\Stress\Generator\StressNames;
use App\Services\Stress\Generator\StressRandom;

/**
 * Muwakha families following MuwakhaFamilyService's lifecycle:
 *  - create  -> one dedicated Account (type أفراد, canonical name), the
 *               family, the ownership mapping, initial project links;
 *  - replace -> a NEW Account + mapping, family repointed, old Account kept
 *               (a few old Accounts are later soft-deleted from Accounts);
 *  - delete  -> family soft-deleted, its project links hard-removed,
 *               Accounts and mappings untouched.
 * Card codes are unique per project; national ids are unique, synthetic.
 */
final class MuwakhaPhase
{
    /**
     * Per family: accounts with validity windows, and linked project ids
     * with the day each link existed from/until.
     *
     * @var array<int, array{accounts: array<int, array{id: int, from: int, until: ?int, cur: int}>, links: array<int, array{project: int, from: int, until: ?int}>}>
     */
    public array $families = [];

    /** @var array<int, array{0: string, 1: array<string, mixed>}> mapping/link rows waiting for their family row */
    private array $children = [];

    /** @var array<int, array<int, int>> project id => family ids ever linked */
    public array $familiesByProject = [];

    public function __construct(
        private readonly StressBuildContext $ctx,
        private readonly AccountFactory $factory,
    ) {}

    /**
     * @param  array<int, array{id: int, super: ?string, approval: int, deleted: bool}>  $projects
     */
    public function run(array $projects): void
    {
        $random = $this->ctx->random->fork('muwakha');
        $muwakhaProjects = array_values(array_filter($projects, fn (array $p) => $p['super'] === 'MUWAKHA' && ! $p['deleted']));

        if ($muwakhaProjects === []) {
            return;
        }

        $first = min(array_column($muwakhaProjects, 'approval'));
        $last = $this->ctx->calendar->lastDay();
        $count = $this->ctx->scale->muwakhaFamilies();
        $cardCounters = [];
        $currencyNames = [];

        foreach ($this->ctx->currencies as $code => $currency) {
            $currencyNames[$code] = $currency['name'];
        }

        for ($i = 1; $i <= $count; $i++) {
            $created = $this->ctx->calendar->randomDay($random, $first, $last - 5);
            $by = $this->ctx->user($random->pick(['admin', 'admin', 'pm']))->id;
            $at = $this->ctx->calendar->timestamp($random, $created);
            $martyr = $this->ctx->text->maleName();
            $guardian = $this->ctx->text->femaleName();
            $holder = $random->chance(0.9) ? $guardian : $this->ctx->text->personName();
            $code = $random->chance(0.85) ? 'ILS' : 'USD';

            $accountId = $this->familyAccount($random, $martyr, $code, $currencyNames[$code], $created, $by, $at, $i);
            $familyId = $this->ctx->nextId('muwakha_families');
            $martyrdom = max(0, $created - $random->int(10, 600));

            $family = [
                'id' => $familyId,
                'martyr_name' => $martyr,
                'martyr_national_id' => $this->ctx->text->nationalId($i),
                'martyr_date_of_birth' => $random->chance(0.85) ? sprintf('%d-%02d-%02d', $random->int(1960, 2002), $random->int(1, 12), $random->int(1, 28)) : null,
                'martyrdom_date' => $this->ctx->calendar->date($martyrdom),
                'children_count' => (int) $random->weighted([0 => 8, 1 => 14, 2 => 20, 3 => 20, 4 => 15, 5 => 10, 6 => 7, 7 => 3, 9 => 3]),
                'guardian_name' => $guardian,
                'guardian_national_id' => $random->chance(0.8) ? $this->ctx->text->nationalId(5_000_000 + $i) : null,
                'guardian_date_of_birth' => $random->chance(0.7) ? sprintf('%d-%02d-%02d', $random->int(1965, 2004), $random->int(1, 12), $random->int(1, 28)) : null,
                'guardian_phone' => $this->ctx->text->phone($i),
                'account_holder_name' => $holder,
                'account_id' => $accountId,
                'notes' => $this->ctx->text->note(0.2),
                'created_by' => $by,
                'updated_by' => $by,
                'created_at' => $at,
                'updated_at' => $at,
                'deleted_at' => null,
            ];

            $this->families[$familyId] = ['accounts' => [['id' => $accountId, 'from' => $created, 'until' => null, 'cur' => $this->ctx->currencyId($code)]], 'links' => []];
            $this->mapping($familyId, $accountId, $holder, $at, $by);

            // Replacement: a brand-new Account, the old one kept for history.
            if ($random->chance(0.08) && $created + 60 < $last) {
                $replaceDay = $random->int($created + 30, $last - 1);
                $replaceAt = $this->ctx->calendar->timestamp($random, $replaceDay);
                $admin = $this->ctx->user('admin')->id;
                $newCode = $random->chance(0.9) ? $code : ($code === 'ILS' ? 'USD' : 'ILS');
                $newAccount = $this->familyAccount($random, $martyr, $newCode, $currencyNames[$newCode], $replaceDay, $admin, $replaceAt, 100_000 + $i);

                $this->ctx->accounts->closeFrom($accountId, $replaceDay);
                $this->families[$familyId]['accounts'][0]['until'] = $replaceDay;
                $this->families[$familyId]['accounts'][] = ['id' => $newAccount, 'from' => $replaceDay, 'until' => null, 'cur' => $this->ctx->currencyId($newCode)];
                $this->mapping($familyId, $newAccount, $holder, $replaceAt, $admin);

                $this->ctx->journal->createdOverrides[MuwakhaFamily::class][$familyId] = ['account_id' => $accountId];
                $this->ctx->journal->crudUpdate(MuwakhaFamily::class, $familyId, $replaceAt, $admin, ['account_id' => $accountId], ['account_id' => $newAccount]);
                $family['account_id'] = $newAccount;
                $family['updated_at'] = $replaceAt;
                $family['updated_by'] = $admin;

                // Some superseded Accounts are later removed from the chart.
                if ($random->chance(0.25) && $replaceDay + 10 < $last) {
                    $deleteDay = $random->int($replaceDay + 5, $last - 1);
                    $deleteAt = $this->ctx->calendar->timestamp($random, $deleteDay);
                    $this->ctx->accounts->markDeleted($accountId, $deleteDay);
                    $this->factory->pendingUpdates[$accountId] = ['deleted_at' => $deleteAt, 'updated_at' => $deleteAt, 'updated_by' => $admin];
                    $this->ctx->journal->deleted('accounts', $accountId, $admin);
                }
            }

            // Initial links to Muwakha projects already approved.
            $eligible = array_values(array_filter($muwakhaProjects, fn (array $p) => $p['approval'] <= $created));
            $eligible = $eligible === [] ? [$muwakhaProjects[0]] : $eligible;
            $linkCount = min(count($eligible), (int) $random->weighted([1 => 60, 2 => 30, 3 => 10]));
            $chosen = [];

            while (count($chosen) < $linkCount) {
                $p = $random->pick($eligible);
                $chosen[$p['id']] = $p;
            }

            $deleteFamily = $random->chance(0.02) && $created + 90 < $last;
            $familyDeleteDay = $deleteFamily ? $random->int($created + 60, $last - 1) : null;
            $familyDeleteAt = $deleteFamily ? $this->ctx->calendar->timestamp($random, $familyDeleteDay) : null;

            foreach ($chosen as $projectId => $project) {
                $linkDay = max($created, $project['approval']);
                $linkAt = max($at, $this->ctx->calendar->timestamp($random, $linkDay));
                $card = null;

                if ($random->chance(0.85)) {
                    $cardCounters[$projectId] = ($cardCounters[$projectId] ?? 0) + 1;
                    $card = 'C'.str_pad((string) $cardCounters[$projectId], 4, '0', STR_PAD_LEFT);
                }

                $linkId = $this->ctx->nextId('muwakha_family_projects');
                $row = [
                    'id' => $linkId,
                    'muwakha_family_id' => $familyId,
                    'project_id' => $projectId,
                    'card_code' => $card,
                    'created_at' => $linkAt,
                    'updated_at' => $linkAt,
                ];

                $this->familiesByProject[$projectId][] = $familyId;
                $this->families[$familyId]['links'][] = ['project' => $projectId, 'from' => $linkDay, 'until' => $familyDeleteDay];

                if ($deleteFamily) {
                    // MuwakhaFamilyService::delete() hard-removes links (audited).
                    $this->ctx->journal->removedLinks[$linkId] = ['row' => $row, 'at' => $familyDeleteAt, 'by' => $this->ctx->user('admin')->id, 'actor' => $by];
                } else {
                    $this->children[] = ['muwakha_family_projects', $row];
                    $this->ctx->journal->rowActors['muwakha_family_projects'][$linkId] = $by;
                }
            }

            if ($deleteFamily) {
                $family['deleted_at'] = $familyDeleteAt;
                $family['updated_at'] = max($family['updated_at'], $familyDeleteAt);
                $this->ctx->journal->deleted('muwakha_families', $familyId, $this->ctx->user('admin')->id);

                // No new payments to a deleted family.
                foreach ($this->families[$familyId]['accounts'] as $acc) {
                    if ($acc['until'] === null || $acc['until'] > $familyDeleteDay) {
                        $this->ctx->accounts->closeFrom($acc['id'], $familyDeleteDay);
                    }
                }
            }

            // Parent first: a child buffer that fills up flushes everything
            // ordered before it, so the family row must already be buffered.
            $this->ctx->writer->add('muwakha_families', $family);

            foreach ($this->children as [$table, $row]) {
                $this->ctx->writer->add($table, $row);
            }

            $this->children = [];
        }

        $this->ctx->writer->flushAll();
    }

    private function familyAccount(StressRandom $random, string $martyr, string $code, string $currencyName, int $day, int $by, string $at, int $sequence): int
    {
        $accountCode = (string) $random->int(100000, 999999999);
        $bank = $code === 'ILS'
            ? $random->pick(['بنك فلسطين', 'البنك الاسلامي الفلسطيني', 'محفظة بال باي', 'محفظة جوال باي', 'بنك القدس', 'بنك الإسكان'])
            : $random->pick(['بنك فلسطين', 'البنك الاسلامي الفلسطيني']);

        return $this->factory->create(
            pool: 'muwakha',
            typeName: StressNames::TYPE_INDIVIDUALS,
            bankName: $bank,
            currencyCode: $code,
            name: MuwakhaFamilyService::accountNameFor($martyr, $currencyName, $accountCode),
            fromDay: $day,
            createdBy: $by,
            code: $accountCode,
            iban: $random->chance(0.4) ? $this->ctx->text->fakeIban($sequence) : null,
            at: $at,
        );
    }

    private function mapping(int $familyId, int $accountId, string $holder, string $at, int $by): void
    {
        $id = $this->ctx->nextId('muwakha_family_accounts');
        $this->children[] = ['muwakha_family_accounts', [
            'id' => $id,
            'muwakha_family_id' => $familyId,
            'account_id' => $accountId,
            'account_holder_name' => $holder,
            'created_at' => $at,
            'updated_at' => $at,
        ]];
        $this->ctx->journal->rowActors['muwakha_family_accounts'][$id] = $by;
    }

    /**
     * A family linked to $projectId whose CURRENT account on $day is in
     * $currencyId and still active; null when none qualifies.
     */
    public function beneficiary(StressRandom $random, int $projectId, int $currencyId, int $day): ?int
    {
        $candidates = [];

        foreach ($this->familiesByProject[$projectId] ?? [] as $familyId) {
            foreach ($this->families[$familyId]['links'] as $link) {
                if ($link['project'] !== $projectId || $link['from'] > $day || ($link['until'] !== null && $day >= $link['until'])) {
                    continue;
                }

                foreach ($this->families[$familyId]['accounts'] as $acc) {
                    if ($acc['cur'] === $currencyId && $acc['from'] <= $day && ($acc['until'] === null || $day < $acc['until'])
                        && $this->ctx->accounts->usable($acc['id'], $day)) {
                        $candidates[] = $acc['id'];
                    }
                }
            }
        }

        return $candidates === [] ? null : $random->pick($candidates);
    }
}
