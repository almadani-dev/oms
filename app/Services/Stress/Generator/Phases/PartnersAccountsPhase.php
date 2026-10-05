<?php

namespace App\Services\Stress\Generator\Phases;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Services\Stress\Generator\AccountFactory;
use App\Services\Stress\Generator\LedgerPoster;
use App\Services\Stress\Generator\StressBuildContext;
use App\Services\Stress\Generator\StressNames;
use App\Services\Validation\FinancialTransactionBalanceGuard;

/**
 * Partners (donor tiers, implementers, suppliers, exchange houses, a few
 * deleted entries) and the chart of accounts: organization accounts on
 * every valid bank/currency combination, deduction accounts, employees,
 * individuals, suppliers, and opening balances posted exactly like
 * CreateAccount's opening-entry branch.
 */
final class PartnersAccountsPhase
{
    /** Opening-entry purposes as written by CreateAccount::handleRecordCreation(). */
    private const OPENING_PURPOSES = [
        'opening_balance_target' => 'إثبات الرصيد الافتتاحي للحساب',
        'opening_balance_counterpart' => 'الطرف المقابل للقيد الافتتاحي',
    ];

    /** @var array<int, string> partner id => name */
    public array $partnerNames = [];

    /** @var array<int, int> currency id => clearing account id */
    private array $clearing = [];

    public function __construct(
        private readonly StressBuildContext $ctx,
        private readonly AccountFactory $factory,
        private readonly LedgerPoster $poster,
    ) {}

    public function run(): void
    {
        $this->partners();
        $this->organizationAccounts();
        $this->peopleAndSuppliers();
        $this->ctx->writer->flushAll();
    }

    private function partners(): void
    {
        $random = $this->ctx->random->fork('partners');
        $total = $this->ctx->scale->partners();
        $donors = $this->ctx->scale->donors();
        $deleted = max(1, (int) round($total * 0.02));
        $creators = ['admin' => 3, 'pm' => 2, 'pm_2' => 1];
        $last = $this->ctx->calendar->lastDay();

        for ($i = 0; $i < $total + $deleted; $i++) {
            $isDonor = $i < $donors;
            $isDeleted = $i >= $total;
            $kind = $isDonor ? 'donor' : $random->weighted(['implementer' => 40, 'supplier' => 45, 'exchanger' => 15]);

            $typeName = match (true) {
                $isDonor => (string) $random->weighted([StressNames::PARTNER_CHARITY => 60, StressNames::PARTNER_MINISTRY => 15, StressNames::PARTNER_PERSON => 25]),
                $kind === 'implementer' => (string) $random->weighted([StressNames::PARTNER_CHARITY => 80, StressNames::PARTNER_MINISTRY => 20]),
                default => StressNames::PARTNER_PERSON,
            };

            $name = match (true) {
                $typeName === StressNames::PARTNER_MINISTRY => $this->ctx->text->ministryName(),
                $typeName === StressNames::PARTNER_CHARITY => $this->ctx->text->charityName(),
                $kind === 'supplier' => $this->ctx->text->companyName(),
                $kind === 'exchanger' => 'صرافة '.$this->ctx->text->personName(),
                default => $this->ctx->text->personName(),
            };
            $name .= ' #'.($i + 1);

            // Half the established partners predate the dataset.
            $from = $random->chance(0.5) ? 0 : $this->ctx->calendar->randomDay($random, 0, (int) ($last * 0.85));
            $by = $this->ctx->user((string) $random->weighted($creators))->id;
            $at = $from === 0 ? '2021-12-'.$random->int(16, 30).' 11:00:00' : $this->ctx->calendar->timestamp($random, $from);
            $id = $this->ctx->nextId('partners');
            $deletedAt = $isDeleted ? $this->ctx->calendar->timestamp($random, min($last, $from + $random->int(1, 30))) : null;

            $this->ctx->writer->add('partners', [
                'id' => $id,
                'name' => $name,
                'partner_type_id' => $this->ctx->partnerTypes[$typeName],
                'is_donor' => $isDonor,
                'email' => $random->chance(0.5) ? 'partner'.$id.'@stress-oms.invalid' : null,
                'mobile_number' => $random->chance(0.6) ? $this->ctx->text->phone(20000 + $id) : null,
                'address' => $random->chance(0.5) ? $this->ctx->text->area() : null,
                'city' => $random->chance(0.6) ? $this->ctx->text->city() : null,
                'country' => $random->chance(0.6) ? $this->ctx->text->country() : null,
                'notes' => $this->ctx->text->note(0.2),
                'created_by' => $by,
                'updated_by' => $by,
                'created_at' => $at,
                'updated_at' => $deletedAt ?? $at,
                'deleted_at' => $deletedAt,
            ]);

            $this->partnerNames[$id] = $name;

            if ($isDeleted) {
                // Entered by mistake and deleted; never referenced anywhere.
                $this->ctx->journal->deleted('partners', $id, $by);

                continue;
            }

            $this->ctx->partnerFrom[$id] = $from;
            $this->ctx->partnerTypeName[$id] = $typeName;

            match ($kind) {
                'donor' => $this->ctx->donorWeights[$id] = match (true) {
                    $i < max(1, (int) round($donors * 0.06)) => 40.0,
                    $i < max(2, (int) round($donors * 0.30)) => 8.0,
                    default => 1.0,
                },
                'implementer' => $this->ctx->implementers[] = $id,
                'supplier' => $this->ctx->supplierPartners[] = $id,
                default => $this->ctx->exchangers[] = $id,
            };
        }
    }

    private function organizationAccounts(): void
    {
        $random = $this->ctx->random->fork('org-accounts');
        $large = $this->ctx->scale->factor >= 0.5;
        $admin = $this->ctx->user('admin')->id;
        $openingTargets = [];

        foreach ($this->ctx->banks as $bankName => $bankId) {
            foreach ($this->currenciesFor($bankName) as $code) {
                $copies = match (true) {
                    ! $large => 1,
                    in_array($bankName, StressNames::WALLETS, true), $bankName === StressNames::USDT, $bankName === StressNames::CASH => 2,
                    $code === 'EUR' => 1,
                    default => 3,
                };

                for ($n = 1; $n <= $copies; $n++) {
                    $main = $n === 1;
                    $name = ($main ? 'حساب الجمعية الرئيسي' : 'حساب المشاريع '.$n).' - '.$bankName.' - '.$code;
                    $weight = ($main ? 10.0 : 3.0) * (in_array($bankName, ['بنك فلسطين', 'البنك الاسلامي الفلسطيني'], true) ? 3.0 : 1.0);
                    $id = $this->factory->create('org', StressNames::TYPE_ORG, $bankName, $code, $name, 0, $admin, $weight, at: '2021-12-31 10:00:00');
                    $openingTargets[] = [$id, $code, $bankName];
                }
            }
        }

        foreach (['USD', 'ILS', 'EUR'] as $code) {
            $this->factory->create('admin', StressNames::TYPE_ORG, StressNames::CASH, $code, 'إيرادات النسبة الإدارية - '.$code, 0, $admin);
            $this->factory->create('transfer', StressNames::TYPE_PARTNER_PERSON, StressNames::CASH, $code, 'عمولات الصرافة والتحويل - '.$code, 0, $admin);
        }

        foreach ($openingTargets as [$accountId, $code, $bankName]) {
            $amount = match ($code) {
                'USD' => $random->amount(120_000, 0.7, 5_000, 900_000),
                'ILS' => $random->amount(300_000, 0.7, 10_000, 2_500_000),
                default => $random->amount(60_000, 0.6, 3_000, 400_000),
            };
            $this->postOpening($accountId, $code, $bankName, $amount, $admin);
        }
    }

    /**
     * CreateAccount's opening-entry branch: one OPB transaction, target
     * debit + clearing credit, same currency, fx stored as metadata (1).
     */
    private function postOpening(int $accountId, string $code, string $bankName, float $amount, int $by): void
    {
        $currencyId = $this->ctx->currencyId($code);
        $clearing = $this->clearing[$currencyId] ??= $this->factory->create(
            pool: 'clearing',
            typeName: StressNames::TYPE_OPENING,
            bankName: $bankName,
            currencyCode: $code,
            name: CreateAccount::OPENING_CLEARING_ACCOUNT_NAME,
            fromDay: 0,
            createdBy: $by,
            code: 'OPB-'.$code,
            notes: 'حساب مقابل للقيود الافتتاحية (أُنشئ تلقائياً)',
            audited: false,
            at: '2021-12-31 10:00:00',
        );

        $lines = self::openingLines($accountId, $clearing, $currencyId, $amount, 1.0, $by);

        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, $currencyId);

        $at = '2021-12-31 10:00:00';
        $posted = $this->poster->post([
            'prefix' => 'OPB',
            'type_id' => $this->ctx->transactionTypes[StressNames::TX_OPENING],
            'day' => 0,
            'partner_id' => null,
            'notes' => 'تم إنشاء هذا القيد تلقائياً عند إنشاء الحساب.',
            'created_at' => $at,
            'created_by' => $by,
            'updated_at' => $at,
            'updated_by' => $by,
            'deleted_at' => null,
        ], $lines, self::OPENING_PURPOSES, 'تسجيل الرصيد الافتتاحي لحساب '.$this->ctx->accounts->get($accountId)['name'], structuralGuard: false);

        // The single `account` created event carries the opening identifiers
        // (AuditedCrudService::recordCreatedWithin in CreateAccount).
        $this->ctx->journal->openingContext[$accountId] = [
            'opening_transaction_id' => $posted['id'],
            'opening_transaction_number' => $posted['number'],
            'opening_balance' => $amount,
            'opening_balance_date' => '2022-01-01',
            'opening_balance_fx_rate' => 1.0,
        ];
    }

    /**
     * The two lines CreateAccount::createOpeningLines() inserts (restated
     * because the real method inserts directly; a parity test compares them).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function openingLines(int $accountId, int $clearingId, int $currencyId, float $amount, float $fxRate, ?int $by): array
    {
        return [
            [
                'account_id' => $accountId, 'currency_id' => $currencyId, 'amount_currency' => $amount, 'fx_rate' => $fxRate,
                'debit_base' => $amount, 'credit_base' => 0, 'notes' => 'قيد افتتاحي',
                'line_role' => TransactionLineRole::OpeningBalanceTarget->value, 'created_by' => $by, 'updated_by' => $by,
            ],
            [
                'account_id' => $clearingId, 'currency_id' => $currencyId, 'amount_currency' => $amount, 'fx_rate' => $fxRate,
                'debit_base' => 0, 'credit_base' => $amount, 'notes' => 'قيد افتتاحي',
                'line_role' => TransactionLineRole::OpeningBalanceCounterpart->value, 'created_by' => $by, 'updated_by' => $by,
            ],
        ];
    }

    private function peopleAndSuppliers(): void
    {
        $random = $this->ctx->random->fork('people-accounts');
        $last = $this->ctx->calendar->lastDay();
        $banks = [
            'ILS' => ['بنك فلسطين', 'البنك الاسلامي الفلسطيني', 'محفظة بال باي', 'محفظة جوال باي', 'بنك القدس'],
            'USD' => ['بنك فلسطين', 'البنك الاسلامي الفلسطيني', 'بنك الإسكان'],
            'EUR' => ['بنك فلسطين', 'البنك الاسلامي الفلسطيني'],
        ];

        $groups = [
            ['employee', StressNames::TYPE_EMPLOYEES, $this->ctx->scale->employees(), 0.70, 0.10],
            ['individual', StressNames::TYPE_INDIVIDUALS, $this->ctx->scale->individuals(), 0.80, 0.04],
            ['supplier', StressNames::TYPE_PARTNER_COMPANY, $this->ctx->scale->suppliers(), 0.75, 0.04],
        ];

        foreach ($groups as [$pool, $type, $count, $ilsShare, $deactivateShare]) {
            for ($i = 0; $i < $count; $i++) {
                // Guarantee every currency exists in every pool (EUR budgets
                // must be executable to an EUR beneficiary), then mostly ILS/USD.
                $code = match ($i) {
                    0 => 'ILS',
                    1 => 'USD',
                    2 => 'EUR',
                    default => $random->chance(0.04) ? 'EUR' : ($random->chance($ilsShare) ? 'ILS' : 'USD'),
                };
                $from = $i < 3 || $random->chance(0.55) ? 0 : $this->ctx->calendar->randomDay($random, 0, (int) ($last * 0.8));
                $name = $pool === 'supplier' ? $this->ctx->text->companyName() : $this->ctx->text->personName();
                $id = $this->factory->create(
                    pool: $pool,
                    typeName: $type,
                    bankName: $random->pick($banks[$code]),
                    currencyCode: $code,
                    name: $name.' - '.$code,
                    fromDay: $from,
                    createdBy: $this->ctx->user($random->pick(['admin', 'accountant']))->id,
                    weight: $random->chance(0.15) ? 6.0 : 1.0,
                    iban: $pool !== 'supplier' && $random->chance(0.2) ? $this->ctx->text->fakeIban(50000 + $i) : null,
                );

                if ($i >= 3 && $random->chance($deactivateShare)) {
                    $until = $random->int(min($last, $from + 120), $last);

                    if ($until < $last) {
                        $this->factory->deactivate($id, $until, $this->ctx->user('admin')->id);
                    }
                }
            }
        }
    }

    /** @return array<int, string> currencies a bank type may hold */
    private function currenciesFor(string $bankName): array
    {
        return match (true) {
            $bankName === StressNames::USDT => ['USD'],
            in_array($bankName, StressNames::WALLETS, true) => ['ILS'],
            default => ['USD', 'ILS', 'EUR'],
        };
    }
}
