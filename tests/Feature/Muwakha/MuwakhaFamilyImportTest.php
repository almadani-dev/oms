<?php

namespace Tests\Feature\Muwakha;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Models\MuwakhaFamilyProject;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Muwakha\Import\MuwakhaFamilyImporter;
use App\Services\Muwakha\MuwakhaFamilyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use ReflectionClass;
use RuntimeException;

/**
 * The one-time Muwakha families bulk importer (`muwakha:import-families`).
 *
 * Deliberately narrow: only this importer. The family domain itself is already
 * covered by MuwakhaFamilyServiceTest / MuwakhaFamilyCreateProjectLinksTest and
 * is NOT re-tested here — the point of these tests is that the importer adapts
 * the source to that untouched domain rather than the other way round.
 *
 * Nothing here calls actingAs(): a real CLI run has no authenticated user, and
 * several of these tests exist precisely to prove the importer authenticates the
 * resolved --actor itself and gives the auth context back afterwards.
 */
class MuwakhaFamilyImportTest extends MuwakhaTestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    /* ===================== 1. dry run writes nothing ===================== */

    public function test_dry_run_persists_zero_records(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(),
            $this->sourceRow([
                'source_row' => 6,
                'martyr_national_id' => '0499999999',
                'card_code' => 'G 2',
            ]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id]);

        $this->assertSame(0, $exit);
        $this->assertNoMuwakhaWrites();

        $output = Artisan::output();
        $this->assertStringContainsString('REAL DATABASE WRITES: 0', $output);
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('Would create families: 2', $output);
        $this->assertStringContainsString('Would create accounts: 2', $output);
        $this->assertStringContainsString('Would create family-account mappings: 2', $output);
        $this->assertStringContainsString('Would create family-project links: 2', $output);
        $this->assertStringContainsString('ILS: 2', $output);
    }

    /** A dry run reports the resolved actor without writing anything. */
    public function test_dry_run_reports_the_resolved_actor(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();
        $actor = $this->actor();

        $this->runImport($this->writeSource([$this->sourceRow()]), $project, ['--actor' => $actor->email]);

        $output = Artisan::output();
        $this->assertStringContainsString((string) $actor->email, $output);
        $this->assertStringContainsString('#'.$actor->id, $output);
        $this->assertNoMuwakhaWrites();
    }

    public function test_dry_run_without_an_actor_still_reports_and_writes_nothing(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $exit = $this->runImport($this->writeSource([$this->sourceRow()]), $project);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('NOT SUPPLIED', Artisan::output());
        $this->assertNoMuwakhaWrites();
    }

    /* ============ 2. one martyr name, two national ids, two households ============ */

    public function test_the_same_martyr_name_with_different_national_ids_is_allowed(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        // The approved real-source case: one martyr, two wives, two households.
        // The second national id intentionally carries a leading slash.
        $path = $this->writeSource([
            $this->sourceRow([
                'source_row' => 30,
                'martyr_name' => 'وائل حسن سليم رجب',
                'martyr_national_id' => '910717941',
                'card_code' => 'G 30',
            ]),
            $this->sourceRow([
                'source_row' => 31,
                'martyr_name' => 'وائل حسن سليم رجب',
                'martyr_national_id' => '/910717941',
                'card_code' => 'G 31',
            ]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(2, MuwakhaFamily::count());

        // Both ids survive byte-for-byte — the slash is not stripped and the two
        // rows are not merged.
        $this->assertSame(
            ['/910717941', '910717941'],
            MuwakhaFamily::query()->orderBy('martyr_national_id')->pluck('martyr_national_id')->all(),
        );
        $this->assertSame(
            ['وائل حسن سليم رجب', 'وائل حسن سليم رجب'],
            MuwakhaFamily::query()->pluck('martyr_name')->all(),
        );
    }

    /* ===================== 3. duplicate national id ===================== */

    public function test_duplicate_martyr_national_id_within_the_source_is_rejected(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(['source_row' => 5, 'martyr_national_id' => '0412345678', 'card_code' => 'G 1']),
            $this->sourceRow(['source_row' => 6, 'martyr_national_id' => '0412345678', 'card_code' => 'G 2']),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();

        $output = Artisan::output();
        $this->assertStringContainsString('Duplicate martyr_national_id', $output);
        // BOTH rows are flagged — the importer never picks a winner.
        $this->assertStringContainsString('Ready: 0', $output);
        $this->assertStringContainsString('Errors: 2', $output);
    }

    /* ===================== 4. duplicate account code ===================== */

    public function test_duplicate_account_code_across_families_is_allowed(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(['source_row' => 5, 'account_code' => '5555', 'card_code' => 'G 1']),
            $this->sourceRow([
                'source_row' => 6,
                'martyr_national_id' => '0499999999',
                'account_code' => '5555',
                'card_code' => 'G 2',
            ]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(2, MuwakhaFamily::count());
        $this->assertSame(2, Account::where('account_code', '5555')->count());
    }

    /* ===================== 5. card code G 1111 ===================== */

    public function test_card_code_g_1111_is_imported_unchanged(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(['source_row' => 5, 'card_code' => 'G 1']),
            $this->sourceRow([
                'source_row' => 72,
                'martyr_national_id' => 'DUMMY-MUW-G1111',
                'card_code' => 'G 1111',
            ]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(0, $exit, Artisan::output());

        // Not renumbered to "G 72", not reformatted, and the DUMMY- national id
        // is preserved exactly as supplied.
        $this->assertSame(
            ['G 1', 'G 1111'],
            MuwakhaFamilyProject::query()->orderBy('card_code')->pluck('card_code')->all(),
        );
        $this->assertTrue(MuwakhaFamily::where('martyr_national_id', 'DUMMY-MUW-G1111')->exists());
    }

    /* ===================== 6. one bad row blocks the batch ===================== */

    public function test_a_single_bad_row_prevents_execution_of_the_entire_batch(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(['source_row' => 5, 'card_code' => 'G 1']),
            // The guardian phone is required by MuwakhaFamilyForm; «—» is the
            // spreadsheet placeholder and becomes NULL, so this row fails.
            $this->sourceRow([
                'source_row' => 6,
                'martyr_national_id' => '0499999999',
                'guardian_phone' => '—',
                'card_code' => 'G 2',
            ]),
            $this->sourceRow([
                'source_row' => 7,
                'martyr_national_id' => '0488888888',
                'card_code' => 'G 3',
            ]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();

        $output = Artisan::output();
        $this->assertStringContainsString('ABORTED before any write', $output);
        $this->assertStringContainsString('guardian phone', $output);
        $this->assertStringContainsString('Ready: 2', $output);
        $this->assertStringContainsString('Errors: 1', $output);
    }

    /* ===================== 7. the official domain write path ===================== */

    public function test_the_execute_path_goes_through_the_official_muwakha_family_service(): void
    {
        $reference = $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([$this->sourceRow(['martyr_name' => 'أحمد محمد', 'account_code' => '123456'])]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(0, $exit, Artisan::output());

        $family = MuwakhaFamily::sole();
        $account = Account::sole();

        // Every artefact below is written by MuwakhaFamilyService and by nothing
        // else in this feature: the canonical account name, the أفراد account
        // type, the ownership mapping, the project link and the audit trail.
        $this->assertSame(
            MuwakhaFamilyService::accountNameFor('أحمد محمد', $reference['currency']->name, '123456'),
            $account->name,
        );
        $this->assertSame($account->id, $family->account_id);
        $this->assertSame($reference['account_type']->id, $account->account_type_id);
        $this->assertSame($reference['currency']->id, $account->currency_id);
        $this->assertSame($reference['bank_type']->id, $account->bank_type_id);
        $this->assertTrue((bool) $account->is_active);
        $this->assertSame(1, MuwakhaFamilyAccount::where([
            'muwakha_family_id' => $family->id,
            'account_id' => $account->id,
            'account_holder_name' => 'فاطمة أحمد محمد',
        ])->count());
        $this->assertSame(1, MuwakhaFamilyProject::where([
            'muwakha_family_id' => $family->id,
            'project_id' => $project->id,
            'card_code' => 'G 1',
        ])->count());

        foreach (['account', 'muwakha_family', 'muwakha_family_account', 'muwakha_family_project'] as $subject) {
            $this->assertSame(
                1,
                AuditEvent::where(['subject_type' => $subject, 'event_action' => 'created'])->count(),
                'expected exactly one created AuditEvent for '.$subject,
            );
        }

        // The service's create path never touches the opening-balance branch.
        $this->assertSame(0, Transaction::count());

        // Structural: the importer depends on the domain service itself, not on
        // a parallel write path of its own.
        $constructor = (new ReflectionClass(MuwakhaFamilyImporter::class))->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertSame(
            MuwakhaFamilyService::class,
            (string) $constructor->getParameters()[0]->getType(),
        );
    }

    /* ===================== 8. all-or-nothing ===================== */

    public function test_a_failure_on_the_last_family_rolls_back_every_earlier_one(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        // A failure the preflight cannot see, raised from inside the service's
        // own write — the only honest way to exercise the outer transaction.
        MuwakhaFamily::created(function (MuwakhaFamily $family): void {
            if ($family->martyr_national_id === '0400000003') {
                throw new RuntimeException('simulated failure while writing the last family');
            }
        });

        $path = $this->writeSource([
            $this->sourceRow(['source_row' => 5, 'martyr_national_id' => '0400000001', 'card_code' => 'G 1']),
            $this->sourceRow(['source_row' => 6, 'martyr_national_id' => '0400000002', 'card_code' => 'G 2']),
            $this->sourceRow(['source_row' => 7, 'martyr_national_id' => '0400000003', 'card_code' => 'G 3']),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(2, $exit);
        // Families 1 and 2 committed nothing: no family, no account, no mapping,
        // no link and no audit row survives.
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('nothing was committed', Artisan::output());
    }

    /* ===================== 9. actor attribution ===================== */

    public function test_actor_attribution_is_written_for_every_row(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();
        $actor = $this->actor();

        $path = $this->writeSource([
            $this->sourceRow(['source_row' => 5, 'martyr_national_id' => '0400000001', 'card_code' => 'G 1']),
            $this->sourceRow(['source_row' => 6, 'martyr_national_id' => '0400000002', 'card_code' => 'G 2']),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $actor->id, '--execute' => true]);

        $this->assertSame(0, $exit, Artisan::output());

        foreach (MuwakhaFamily::all() as $family) {
            $this->assertSame($actor->id, $family->created_by);
            $this->assertSame($actor->id, $family->updated_by);
        }

        foreach (Account::all() as $account) {
            $this->assertSame($actor->id, $account->created_by);
        }

        $this->assertGreaterThan(0, AuditEvent::count());
        $this->assertSame(
            [$actor->id],
            AuditEvent::query()->distinct()->pluck('actor_user_id')->all(),
        );

        // The CLI auth context is handed back exactly as it was found.
        $this->assertFalse(Auth::check());
    }

    public function test_execute_without_an_actor_writes_nothing(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $exit = $this->runImport($this->writeSource([$this->sourceRow()]), $project, ['--execute' => true]);

        $this->assertSame(2, $exit);
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('--actor', Artisan::output());
    }

    public function test_an_inactive_actor_is_refused(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();
        $actor = User::factory()->create(['is_active' => false]);

        $exit = $this->runImport(
            $this->writeSource([$this->sourceRow()]),
            $project,
            ['--actor' => $actor->id, '--execute' => true],
        );

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('deactivated', Artisan::output());
    }

    /* ===== 10. existing database conflicts are found before any write ===== */

    public function test_existing_national_id_and_project_card_code_conflicts_abort_before_writes(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();
        $actor = $this->actor();

        // An already-imported family: the accidental-second-run scenario.
        $this->actingAs($actor);
        app(MuwakhaFamilyService::class)->create($this->familyData([
            'martyr_national_id' => '0400000001',
            MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                ['project_id' => $project->id, 'card_code' => 'G 1'],
            ],
        ]));
        Auth::logout();

        $path = $this->writeSource([
            // Same national id as the existing family.
            $this->sourceRow(['source_row' => 5, 'martyr_national_id' => '0400000001', 'card_code' => 'G 9']),
            // Fresh family, but a card code already taken in this project.
            $this->sourceRow(['source_row' => 6, 'martyr_national_id' => '0400000002', 'card_code' => 'G 1']),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $actor->id, '--execute' => true]);

        $this->assertSame(1, $exit);

        // Exactly the one pre-existing family remains — nothing new was written.
        $this->assertSame(1, MuwakhaFamily::count());
        $this->assertSame(1, Account::count());
        $this->assertSame(1, MuwakhaFamilyAccount::count());
        $this->assertSame(1, MuwakhaFamilyProject::count());

        $output = Artisan::output();
        $this->assertStringContainsString('already exists on muwakha_families', $output);
        $this->assertStringContainsString('already used within the target project', $output);
        $this->assertStringContainsString('ABORTED before any write', $output);
    }

    public function test_a_soft_deleted_family_still_reserves_its_national_id(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();
        $actor = $this->actor();

        $this->actingAs($actor);
        $service = app(MuwakhaFamilyService::class);
        $existing = $service->create($this->familyData(['martyr_national_id' => '0400000001']));
        $service->delete($existing);
        Auth::logout();

        $exit = $this->runImport(
            $this->writeSource([$this->sourceRow(['martyr_national_id' => '0400000001'])]),
            $project,
            ['--actor' => $actor->id, '--execute' => true],
        );

        $this->assertSame(1, $exit);
        $this->assertSame(0, MuwakhaFamily::count());
        $this->assertStringContainsString('soft-deleted', Artisan::output());
    }

    /* ===================== source rules and guards ===================== */

    public function test_the_em_dash_placeholder_becomes_null_in_nullable_fields(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow([
                'martyr_date_of_birth' => '—',
                'guardian_national_id' => '—',
                'guardian_date_of_birth' => '—',
                'iban' => '—',
                'notes' => '—',
            ]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(0, $exit, Artisan::output());

        $family = MuwakhaFamily::sole();
        $this->assertNull($family->martyr_date_of_birth);
        $this->assertNull($family->guardian_national_id);
        $this->assertNull($family->guardian_date_of_birth);
        $this->assertNull($family->notes);
        $this->assertNull(Account::sole()->iban);
    }

    public function test_a_row_count_other_than_the_expected_one_blocks_the_run(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $exit = Artisan::call('muwakha:import-families', [
            'file' => $this->writeSource([$this->sourceRow()]),
            '--project' => $project->code,
            '--actor' => $this->actor()->id,
            '--expect' => 72,
            '--execute' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('exactly 72 were expected', Artisan::output());
    }

    public function test_an_unknown_project_code_blocks_the_run(): void
    {
        $this->seedMuwakhaReference();
        $this->makeMuwakhaProject();

        $exit = Artisan::call('muwakha:import-families', [
            'file' => $this->writeSource([$this->sourceRow()]),
            '--project' => 'MUWAKHA_NOPE_999',
            '--actor' => $this->actor()->id,
            '--expect' => 1,
            '--execute' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('No project with the code', Artisan::output());
    }

    public function test_a_project_outside_the_muwakha_root_is_refused(): void
    {
        $this->seedMuwakhaReference();
        $unrelated = $this->makeUnrelatedProject();

        $exit = $this->runImport(
            $this->writeSource([$this->sourceRow()]),
            $unrelated,
            ['--actor' => $this->actor()->id, '--execute' => true],
        );

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('is not a Muwakha project', Artisan::output());
    }

    public function test_an_unknown_currency_code_or_bank_type_is_reported_per_row(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(['source_row' => 5, 'currency_code' => 'EGP', 'card_code' => 'G 1']),
            $this->sourceRow([
                'source_row' => 6,
                'martyr_national_id' => '0499999999',
                'bank_type_name' => 'بنك غير موجود',
                'card_code' => 'G 2',
            ]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();

        $output = Artisan::output();
        $this->assertStringContainsString('does not match any live OMS currency', $output);
        $this->assertStringContainsString('does not match any live bank type', $output);
    }

    public function test_an_egp_row_imports_once_the_currency_exists(): void
    {
        $this->seedMuwakhaReference();
        $this->seedCurrency('جنيه مصري', 'EGP', 'E£');
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(['source_row' => 5, 'currency_code' => 'ILS', 'card_code' => 'G 1']),
            $this->sourceRow([
                'source_row' => 6,
                'martyr_national_id' => '0499999999',
                'currency_code' => 'EGP',
                'card_code' => 'G 2',
            ]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(2, Account::count());
        $this->assertSame(
            1,
            Account::where('currency_id', $this->seedCurrency('جنيه مصري', 'EGP', 'E£')->id)->count(),
        );
    }

    public function test_a_martyrdom_date_in_the_future_is_rejected(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(['martyrdom_date' => now()->addDay()->toDateString()]),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('martyrdom date', Artisan::output());
    }

    public function test_a_martyrdom_date_before_the_date_of_birth_is_rejected(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->writeSource([
            $this->sourceRow(['martyr_date_of_birth' => '2000-01-01', 'martyrdom_date' => '1999-01-01']),
        ]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id, '--execute' => true]);

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();
    }

    public function test_a_negative_children_count_is_rejected(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $exit = $this->runImport(
            $this->writeSource([$this->sourceRow(['children_count' => -1])]),
            $project,
            ['--actor' => $this->actor()->id, '--execute' => true],
        );

        $this->assertSame(1, $exit);
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('children count', Artisan::output());
    }

    public function test_an_unmapped_source_key_is_warned_about_but_does_not_block(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        // The spreadsheet's computed "age at martyrdom" display column has no
        // model field, so it is ignored — visibly, never silently.
        $path = $this->writeSource([$this->sourceRow(['age_at_martyrdom' => 34])]);

        $exit = $this->runImport($path, $project, ['--actor' => $this->actor()->id]);

        $this->assertSame(0, $exit);
        $this->assertNoMuwakhaWrites();
        $this->assertStringContainsString('unmapped source key(s) ignored: age_at_martyrdom', Artisan::output());
    }

    public function test_a_missing_source_file_blocks_the_run(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $exit = Artisan::call('muwakha:import-families', [
            'file' => '/tmp/muwakha-import-does-not-exist.json',
            '--project' => $project->code,
            '--expect' => 1,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Source file not found', Artisan::output());
    }

    public function test_invalid_json_blocks_the_run(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $path = $this->tempPath();
        file_put_contents($path, '{not json');

        $exit = Artisan::call('muwakha:import-families', [
            'file' => $path,
            '--project' => $project->code,
            '--expect' => 1,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not valid JSON', Artisan::output());
    }

    /* ===================== helpers ===================== */

    /**
     * @param  array<string, mixed>  $options
     */
    private function runImport(string $path, Project $project, array $options = []): int
    {
        return Artisan::call('muwakha:import-families', array_merge([
            'file' => $path,
            '--project' => $project->code,
            // The real run expects exactly 72; the fixtures are small, so the
            // count guard is told what this fixture holds.
            '--expect' => count((array) json_decode((string) file_get_contents($path), true)),
        ], $options));
    }

    private function actor(): User
    {
        return User::factory()->create([
            'name' => 'مدخل البيانات',
            'email' => 'importer@oms.test',
        ]);
    }

    /**
     * One source row in the normalized shape the importer consumes. Defaults
     * mirror MuwakhaTestCase::familyData() so the two stay recognisable.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sourceRow(array $overrides = []): array
    {
        return array_merge([
            'source_row' => 5,
            'martyr_name' => 'أحمد محمد',
            'martyr_national_id' => '0412345678',
            'martyr_date_of_birth' => '1990-01-01',
            'martyrdom_date' => '2024-05-10',
            'children_count' => 3,
            'guardian_name' => 'فاطمة أحمد',
            'guardian_national_id' => '0498765432',
            'guardian_date_of_birth' => '1992-03-04',
            'guardian_phone' => '0599123456',
            'account_holder_name' => 'فاطمة أحمد محمد',
            'account_code' => '123456',
            'bank_type_name' => 'بنك فلسطين',
            'currency_code' => 'ILS',
            'iban' => null,
            'card_code' => 'G 1',
            'notes' => null,
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeSource(array $rows): string
    {
        $path = $this->tempPath();

        file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir().'/muwakha-import-'.bin2hex(random_bytes(8)).'.json';

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Not one family, account, ownership mapping, project link or audit row.
     */
    private function assertNoMuwakhaWrites(): void
    {
        $this->assertSame(0, MuwakhaFamily::withTrashed()->count());
        $this->assertSame(0, Account::withTrashed()->count());
        $this->assertSame(0, MuwakhaFamilyAccount::count());
        $this->assertSame(0, MuwakhaFamilyProject::count());
        $this->assertSame(0, AuditEvent::count());
    }
}
