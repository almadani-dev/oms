<?php

namespace Tests\Feature\Muwakha;

use App\Models\Account;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyProject;
use App\Support\Muwakha\MuwakhaReference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * §19 "Schema / Account number" (1-4) plus the structural guarantees the
 * Muwakha tables must carry.
 */
class MuwakhaSchemaTest extends MuwakhaTestCase
{
    /** #1 */
    public function test_accounts_account_code_is_no_longer_unique(): void
    {
        $unique = collect(Schema::getIndexes('accounts'))
            ->filter(fn (array $index): bool => $index['columns'] === ['account_code'] && $index['unique']);

        $this->assertTrue($unique->isEmpty(), 'accounts.account_code must no longer carry a UNIQUE index.');
    }

    /** #2 */
    public function test_accounts_account_code_keeps_a_plain_index(): void
    {
        $plain = collect(Schema::getIndexes('accounts'))
            ->filter(fn (array $index): bool => $index['columns'] === ['account_code'] && ! $index['unique']);

        $this->assertTrue($plain->isNotEmpty(), 'accounts.account_code must keep a non-unique index for lookups.');
    }

    /** #3 + #18 */
    public function test_two_accounts_may_share_the_same_account_code(): void
    {
        $reference = $this->seedMuwakhaReference();

        $first = Account::create([
            'account_code' => '123456',
            'name' => 'أسرة الشهيد أحمد',
            'account_type_id' => $reference['account_type']->id,
            'bank_type_id' => $reference['bank_type']->id,
            'currency_id' => $reference['currency']->id,
        ]);

        $second = Account::create([
            'account_code' => '123456',
            'name' => 'أسرة الشهيد محمد',
            'account_type_id' => $reference['account_type']->id,
            'bank_type_id' => $reference['bank_type']->id,
            'currency_id' => $reference['currency']->id,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('123456', $first->fresh()->account_code);
        $this->assertSame('123456', $second->fresh()->account_code);
    }

    /** #4 — unrelated Account behaviour is untouched. */
    public function test_account_defaults_and_soft_deletes_still_behave(): void
    {
        $reference = $this->seedMuwakhaReference();

        $account = Account::create([
            'account_code' => 'ACC-1',
            'name' => 'حساب عادي',
            'account_type_id' => $reference['account_type']->id,
            'bank_type_id' => $reference['bank_type']->id,
            'currency_id' => $reference['currency']->id,
        ]);

        // `current_balance` is mirrored in memory by Account::$attributes;
        // `is_active` is a database-level default only, so it is asserted
        // against the stored row. Both are pre-existing Account behaviour.
        $this->assertSame('0.00', (string) $account->current_balance);
        $this->assertTrue((bool) $account->fresh()->is_active);

        $account->delete();

        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
    }

    public function test_muwakha_reference_migration_created_the_root_project_super(): void
    {
        $root = $this->muwakhaRoot();

        $this->assertSame(MuwakhaReference::PROJECT_SUPER_NAME, $root->name);
        $this->assertSame('MUWAKHA', $root->code_prefix);
        $this->assertSame('MUWAKHA_001', $root->code);
    }

    public function test_muwakha_reference_migration_added_the_four_bank_types(): void
    {
        foreach (['بنك الإسكان', 'بنك القدس', 'فودافون كاش', 'محفظة مؤقت'] as $name) {
            $this->assertDatabaseHas('bank_types', ['name' => $name]);
            $this->assertSame(
                1,
                \App\Models\BankType::withTrashed()->where('name', $name)->count(),
                "Bank type [{$name}] must exist exactly once.",
            );
        }
    }

    public function test_martyr_national_id_is_unique_at_the_database_level(): void
    {
        $reference = $this->seedMuwakhaReference();

        $makeAccount = fn (string $name) => Account::create([
            'account_code' => '1',
            'name' => $name,
            'account_type_id' => $reference['account_type']->id,
            'bank_type_id' => $reference['bank_type']->id,
            'currency_id' => $reference['currency']->id,
        ]);

        MuwakhaFamily::create($this->familyRow($makeAccount('أ')->id));

        $this->expectException(QueryException::class);

        MuwakhaFamily::create($this->familyRow($makeAccount('ب')->id));
    }

    public function test_one_account_cannot_back_two_families(): void
    {
        $reference = $this->seedMuwakhaReference();

        $account = Account::create([
            'account_code' => '1',
            'name' => 'أسرة الشهيد أحمد',
            'account_type_id' => $reference['account_type']->id,
            'bank_type_id' => $reference['bank_type']->id,
            'currency_id' => $reference['currency']->id,
        ]);

        MuwakhaFamily::create($this->familyRow($account->id));

        $this->expectException(QueryException::class);

        MuwakhaFamily::create($this->familyRow($account->id, ['martyr_national_id' => '9999999999']));
    }

    public function test_family_project_link_uniqueness_constraints_exist(): void
    {
        $indexes = collect(Schema::getIndexes('muwakha_family_projects'));

        $familyProject = $indexes->first(
            fn (array $i): bool => $i['columns'] === ['muwakha_family_id', 'project_id'] && $i['unique'],
        );
        $projectCard = $indexes->first(
            fn (array $i): bool => $i['columns'] === ['project_id', 'card_code'] && $i['unique'],
        );

        $this->assertNotNull($familyProject, 'UNIQUE(muwakha_family_id, project_id) must exist.');
        $this->assertNotNull($projectCard, 'UNIQUE(project_id, card_code) must exist.');
    }

    public function test_multiple_null_card_codes_are_allowed_in_one_project(): void
    {
        $reference = $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $familyA = $this->makeFamilyRow($reference, 'أ', '1111111111');
        $familyB = $this->makeFamilyRow($reference, 'ب', '2222222222');

        MuwakhaFamilyProject::create([
            'muwakha_family_id' => $familyA->id,
            'project_id' => $project->id,
            'card_code' => null,
        ]);
        MuwakhaFamilyProject::create([
            'muwakha_family_id' => $familyB->id,
            'project_id' => $project->id,
            'card_code' => null,
        ]);

        $this->assertSame(2, MuwakhaFamilyProject::where('project_id', $project->id)->count());
    }

    /** #35 — age is computed at martyrdom, never stored. */
    public function test_martyr_age_is_not_a_column_and_is_computed_at_martyrdom(): void
    {
        $this->assertFalse(Schema::hasColumn('muwakha_families', 'age'));
        $this->assertFalse(Schema::hasColumn('muwakha_families', 'martyr_age'));

        $family = new MuwakhaFamily([
            'martyr_date_of_birth' => '1990-06-01',
            'martyrdom_date' => '2024-05-10',
        ]);

        // 1990-06-01 -> 2024-05-10 is 33 completed years (birthday not yet
        // reached in 2024), NOT 34, and never measured against today.
        $this->assertSame(33, $family->martyrAgeAtMartyrdom());
    }

    public function test_martyr_age_is_null_without_a_date_of_birth(): void
    {
        $family = new MuwakhaFamily(['martyrdom_date' => '2024-05-10']);

        $this->assertNull($family->martyrAgeAtMartyrdom());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function familyRow(int $accountId, array $overrides = []): array
    {
        return array_merge([
            'martyr_name' => 'أحمد محمد',
            'martyr_national_id' => '0412345678',
            'martyrdom_date' => '2024-05-10',
            'children_count' => 2,
            'guardian_name' => 'فاطمة',
            'guardian_phone' => '0599123456',
            'account_holder_name' => 'فاطمة أحمد',
            'account_id' => $accountId,
        ], $overrides);
    }

    /**
     * @param  array{account_type: mixed, currency: mixed, bank_type: mixed}  $reference
     */
    private function makeFamilyRow(array $reference, string $suffix, string $nationalId): MuwakhaFamily
    {
        $account = Account::create([
            'account_code' => '1',
            'name' => 'أسرة الشهيد '.$suffix,
            'account_type_id' => $reference['account_type']->id,
            'bank_type_id' => $reference['bank_type']->id,
            'currency_id' => $reference['currency']->id,
        ]);

        return MuwakhaFamily::create($this->familyRow($account->id, [
            'martyr_name' => 'شهيد '.$suffix,
            'martyr_national_id' => $nationalId,
        ]));
    }
}
