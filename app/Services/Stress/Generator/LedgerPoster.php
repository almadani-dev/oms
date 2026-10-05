<?php

namespace App\Services\Stress\Generator;

use App\Models\TransactionLine;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Illuminate\Support\Collection;

/**
 * Writes one logical financial operation's transaction + lines, exactly in
 * the order the real Create page inserts them, with the real descriptions,
 * and applies the balance effect to the in-memory ledger ONCE — unless the
 * operation is soft-deleted, in which case (like the real delete path) its
 * effect is reversed, i.e. never applied.
 */
final class LedgerPoster
{
    public function __construct(private readonly StressBuildContext $ctx) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $lines  payload exactly as the production builder returned it
     * @param  array<string, string>  $purposes  production line purposes by role
     * @param  array{prefix: string, type_id: int, day: int, partner_id: ?int, notes: ?string, created_at: string, created_by: int, updated_at: string, updated_by: int, deleted_at: ?string, lines_created_at?: string}  $header
     * @return array{id: int, number: string}
     */
    public function post(array $header, array $lines, array $purposes, string $summary, bool $structuralGuard = true): array
    {
        // Every payload of the five guarded workflows passes the real
        // structural guard before it is buffered (the per-flow balance guard
        // is called by the flow). The opening-balance branch of CreateAccount
        // does not call it in production — it writes the same notes tag on
        // both lines — so it is not applied there either.
        if ($structuralGuard) {
            FinancialTransactionBalanceGuard::assertValidLinePayload(array_values($lines));
        }

        $transactionId = $this->ctx->nextId('transactions');
        $number = $this->ctx->nextTransactionNumber($header['prefix'], $this->ctx->calendar->year($header['day']));
        $linesCreatedAt = $header['lines_created_at'] ?? $header['created_at'];

        /** @var Collection<int, TransactionLine> $models */
        $models = collect();
        $rows = [];

        foreach ($lines as $line) {
            $this->ctx->accounts->assertUsable((int) $line['account_id'], (int) $line['currency_id'], $header['day']);

            $row = [
                'id' => $this->ctx->nextId('transaction_lines'),
                'transaction_id' => $transactionId,
                'account_id' => (int) $line['account_id'],
                'project_cost_id' => $line['project_cost_id'] ?? null,
                'currency_id' => (int) $line['currency_id'],
                'amount_currency' => round((float) $line['amount_currency'], 2),
                'fx_rate' => round((float) $line['fx_rate'], 6),
                'debit_base' => round((float) $line['debit_base'], 2),
                'credit_base' => round((float) $line['credit_base'], 2),
                'notes' => $line['notes'] ?? null,
                'description' => null,
                'line_role' => $line['line_role'],
                'created_by' => $line['created_by'] ?? null,
                'updated_by' => $line['updated_by'] ?? null,
                'created_at' => $linesCreatedAt,
                'updated_at' => $header['updated_at'],
                'deleted_at' => $header['deleted_at'],
            ];

            $model = new TransactionLine;
            $model->forceFill([
                'id' => $row['id'],
                'debit_base' => $row['debit_base'],
                'credit_base' => $row['credit_base'],
                'line_role' => $row['line_role'],
            ]);
            $model->setRelation('account', $this->ctx->accounts->model($row['account_id']));
            $model->setRelation('currency', $this->ctx->currencyModel($row['currency_id']));

            $row['description'] = $this->ctx->describer->describeLine($model, $purposes);
            $models->push($model);
            $rows[] = $row;
        }

        $this->ctx->writer->add('transactions', [
            'id' => $transactionId,
            'fiscal_year_id' => $this->ctx->fiscalYearFor($header['day']),
            'transaction_type_id' => $header['type_id'],
            'transaction_number' => $number,
            'transaction_time' => $this->ctx->calendar->date($header['day']).' 00:00:00',
            'partner_id' => $header['partner_id'],
            'reference' => null,
            'description' => $this->ctx->describer->describeTransaction($models, $summary),
            'notes' => $header['notes'],
            'created_by' => $header['created_by'],
            'updated_by' => $header['updated_by'],
            'created_at' => $header['created_at'],
            'updated_at' => $header['updated_at'],
            'deleted_at' => $header['deleted_at'],
        ]);

        foreach ($rows as $row) {
            $this->ctx->writer->add('transaction_lines', $row);

            if ($header['deleted_at'] === null) {
                $this->ctx->applyToLedger($row['account_id'], $row['debit_base'], $row['credit_base']);
            }
        }

        return ['id' => $transactionId, 'number' => $number];
    }
}
