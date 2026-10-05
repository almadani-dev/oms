<?php

namespace App\Services\Stress\Generator;

use App\Models\Account;

/**
 * Creates synthetic account rows (always single-currency, always with an
 * existing account type and bank type) and registers them for selection.
 * `current_balance` is written as 0 here and set ONCE at the end of the
 * build from the ledger.
 */
final class AccountFactory
{
    private int $codeSequence = 1000;

    /** @var array<string, int> "donor|partnerId|currencyId" => account id */
    private array $donorAccounts = [];

    public function __construct(private readonly StressBuildContext $ctx) {}

    /**
     * @return int account id
     */
    public function create(string $pool, string $typeName, string $bankName, string $currencyCode, string $name, int $fromDay, int $createdBy, float $weight = 1.0, ?string $code = null, ?string $iban = null, ?string $notes = null, bool $audited = true, ?string $at = null, bool $defer = false): int
    {
        $id = $this->ctx->nextId('accounts');
        $typeId = $this->ctx->accountTypes[$typeName];
        $bankId = $this->ctx->banks[$bankName];
        $currencyId = $this->ctx->currencyId($currencyCode);
        $code ??= (string) (++$this->codeSequence);
        $at ??= $fromDay === 0
            ? '2021-12-'.str_pad((string) $this->ctx->random->int(16, 30), 2, '0', STR_PAD_LEFT).' 10:00:00'
            : $this->ctx->calendar->timestamp($this->ctx->random, $fromDay);

        $row = [
            'id' => $id,
            'account_code' => $code,
            'name' => $name,
            'account_type_id' => $typeId,
            'bank_type_id' => $bankId,
            'currency_id' => $currencyId,
            'current_balance' => 0,
            'is_active' => true,
            'iban' => $iban,
            'notes' => $notes,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
            'created_at' => $at,
            'updated_at' => $at,
            'deleted_at' => null,
        ];

        if ($defer) {
            $this->deferred[$id] = $row;
        } else {
            $this->ctx->writer->add('accounts', $row);
        }

        $this->ctx->accounts->register($id, $pool, $typeId, $bankId, $currencyId, $name, $code, $fromDay, $weight);

        if ($audited) {
            $this->ctx->journal->creators[$id] = $createdBy;
        }

        return $id;
    }

    /**
     * A donor's funding account in $currency, opened the first time that
     * donor funds something in that currency (so it exists before use).
     */
    public function donorAccount(int $partnerId, string $partnerName, int $currencyId, int $day): int
    {
        $key = 'donor|'.$partnerId.'|'.$currencyId;

        if (isset($this->donorAccounts[$key])) {
            // Another cost of the same donor may be funded earlier than the
            // one that first needed this account: open it earlier.
            $this->ctx->accounts->extendFrom($this->donorAccounts[$key], max($this->ctx->partnerFrom[$partnerId] ?? 0, $day - 2));

            return $this->donorAccounts[$key];
        }

        $type = match ($this->ctx->partnerTypeName[$partnerId] ?? null) {
            StressNames::PARTNER_PERSON => StressNames::TYPE_PARTNER_PERSON,
            StressNames::PARTNER_MINISTRY => StressNames::TYPE_PARTNER_COMPANY,
            default => StressNames::TYPE_PARTNER_CHARITY,
        };
        $code = $this->ctx->currencyCode($currencyId);
        $from = max($this->ctx->partnerFrom[$partnerId] ?? 0, $day - $this->ctx->random->int(0, 5));
        $bank = $code === 'ILS' ? $this->ctx->random->pick(['بنك فلسطين', 'البنك الاسلامي الفلسطيني']) : $this->ctx->random->pick(['بنك فلسطين', 'البنك الاسلامي الفلسطيني', 'بنك القدس']);

        return $this->donorAccounts[$key] = $this->create(
            pool: 'donor:'.$partnerId,
            typeName: $type,
            bankName: $bank,
            currencyCode: $code,
            name: $partnerName.' - '.$code,
            fromDay: min($from, $day),
            createdBy: $this->ctx->user('admin')->id,
            defer: true,
        );
    }

    /**
     * Writes the deferred donor accounts once planning has fixed the first
     * day each one is needed; created_at follows that day.
     */
    public function flushDeferred(): void
    {
        foreach ($this->deferred as $id => $row) {
            $from = $this->ctx->accounts->get($id)['from'];
            $at = $this->ctx->calendar->timestamp($this->ctx->random, $from);
            $row['created_at'] = $at;
            $row['updated_at'] = $at;
            $this->ctx->writer->add('accounts', $row);
        }

        $this->deferred = [];
    }

    /** @var array<int, array<string, mixed>> */
    private array $deferred = [];

    /**
     * Deactivation is an audited edit of `is_active` (AccountForm); the
     * account keeps every historical line.
     */
    public function deactivate(int $id, int $day, int $by): void
    {
        $at = $this->ctx->calendar->timestamp($this->ctx->random, $day);
        $this->ctx->accounts->closeFrom($id, $day);
        $this->ctx->journal->crudUpdate(Account::class, $id, $at, $by, ['is_active' => true], ['is_active' => false]);
        $this->ctx->journal->createdOverrides[Account::class][$id]['is_active'] = true;
        $this->pendingUpdates[$id] = ['is_active' => false, 'updated_at' => $at, 'updated_by' => $by];
    }

    /** @var array<int, array<string, mixed>> account id => final column values set after insert */
    public array $pendingUpdates = [];
}
