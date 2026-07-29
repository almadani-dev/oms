<?php

namespace Tests\Feature\Audit\Financial;

use App\Filament\Resources\GeneralExchanges\Pages\CreateGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Pages\EditGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Tables\GeneralExchangesTable;
use App\Models\AuditEvent;
use App\Models\GeneralExchange;

/**
 * التحويلات العامة — GeneralExchangeResource / App\Models\GeneralExchange.
 *
 * Four accounts and two currencies with a genuine FX rate, collapsing into
 * one event whose rate is stored at full six-decimal scale as a string.
 */
class GeneralExchangeAuditTest extends FinancialAuditTestCase
{
    private const ALIAS = 'general_exchange';

    /**
     * @return array<string, mixed>
     */
    private function data(array $fx, array $overrides = []): array
    {
        return array_merge([
            'original_amount' => 1000,
            'source_currency_id' => $fx['currency']->id,
            'administrative_percentage' => 10,
            'transfer_percentage' => 10,
            'disbursement_currency_id' => $fx['altCurrency']->id,
            'fx_rate' => 3.75,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'partner_id' => $fx['partner']->id,
            'date' => '2026-07-18',
            'notes' => null,
            'source_account_id' => $fx['sourceAccount']->id,
            'source_account_type_id' => $fx['sourceAccount']->account_type_id,
            'source_bank_type_id' => $fx['sourceAccount']->bank_type_id,
            'admin_account_id' => $fx['adminAccount']->id,
            'admin_account_type_id' => $fx['adminAccount']->account_type_id,
            'admin_bank_type_id' => $fx['adminAccount']->bank_type_id,
            'transfer_account_id' => $fx['transferAccount']->id,
            'transfer_account_type_id' => $fx['transferAccount']->account_type_id,
            'transfer_bank_type_id' => $fx['transferAccount']->bank_type_id,
            'destination_account_id' => $fx['altDestinationAccount']->id,
            'destination_account_type_id' => $fx['altDestinationAccount']->account_type_id,
            'destination_bank_type_id' => $fx['altDestinationAccount']->bank_type_id,
            'exchange_image' => null,
        ], $overrides);
    }

    private function create(array $data): GeneralExchange
    {
        return $this->invoke(new CreateGeneralExchange, 'handleRecordCreation', [$data]);
    }

    private function update(GeneralExchange $record, array $data): GeneralExchange
    {
        return $this->invoke(new EditGeneralExchange, 'handleRecordUpdate', [$record, $data]);
    }

    public function test_create_writes_exactly_one_correct_financial_event(): void
    {
        $fx = $this->fixture();

        $exchange = $this->create($this->data($fx));

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'created');
        $new = $event->new_values;

        $this->assertSame('financial', $event->event_category);
        $this->assertSame(self::ALIAS, $new['operation_type']);
        $this->assertSame((string) $exchange->id, $event->subject_key);

        $this->assertSame($exchange->transaction_id, $new['transaction_id']);
        $this->assertStringStartsWith('EXT-', $new['transaction_number']);

        $this->assertSame('1000.00', $new['original_amount']);
        $this->assertSame('10.00', $new['administrative_percentage']);
        $this->assertSame('10.00', $new['transfer_percentage']);
        $this->assertSame('3.750000', $new['fx_rate']);
        $this->assertSame('3000.00', $new['final_amount']);

        // Two currencies never mixed: each carries its own id + code.
        $this->assertSame($fx['currency']->id, $new['source_currency_id']);
        $this->assertSame('USD', $new['source_currency_code']);
        $this->assertSame($fx['altCurrency']->id, $new['disbursement_currency_id']);
        $this->assertSame('ILS', $new['disbursement_currency_code']);

        foreach (['source' => 'مصدر', 'admin' => 'إداري', 'transfer' => 'تحويل', 'destination' => 'وجهة بعملة أخرى'] as $role => $name) {
            $this->assertSame($fx[$role === 'destination' ? 'altDestinationAccount' : $role.'Account']->id, $new[$role.'_account_id']);
            $this->assertStringContainsString($name, $new[$role.'_account_label']);
        }

        foreach ($new as $key => $value) {
            $this->assertIsNotArray($value, "Payload key [{$key}] must be a scalar.");
        }

        $this->assertSame(0, AuditEvent::whereIn('subject_type', ['transaction', 'transaction_line'])->count());
    }

    public function test_edit_records_accurate_old_and_new_values(): void
    {
        $fx = $this->fixture();
        $exchange = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        $this->update($exchange->fresh(), $this->data($fx, [
            'fx_rate' => 3.6,
            'administrative_percentage' => 5,
        ]));

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'updated');

        $this->assertEqualsCanonicalizing(
            ['fx_rate', 'administrative_percentage', 'final_amount'],
            $event->changed_fields,
        );

        $this->assertSame('3.750000', $event->old_values['fx_rate']);
        $this->assertSame('3.600000', $event->new_values['fx_rate']);
        $this->assertSame('10.00', $event->old_values['administrative_percentage']);
        $this->assertSame('5.00', $event->new_values['administrative_percentage']);
        $this->assertSame('3000.00', $event->old_values['final_amount']);
        $this->assertSame('3060.00', $event->new_values['final_amount']);

        // Unchanged accounts/currencies stay out of both sides.
        $this->assertArrayNotHasKey('source_account_id', $event->new_values);
        $this->assertArrayNotHasKey('disbursement_currency_id', $event->old_values);
    }

    public function test_a_no_op_edit_creates_no_event(): void
    {
        $fx = $this->fixture();
        $exchange = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        $this->update($exchange->fresh(), $this->data($fx));

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_delete_preserves_the_full_pre_delete_snapshot(): void
    {
        $fx = $this->fixture();
        $exchange = $this->create($this->data($fx));
        $transactionNumber = $exchange->transaction->transaction_number;

        AuditEvent::query()->delete();

        GeneralExchangesTable::deleteExchange($exchange->fresh());

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'deleted');
        $old = $event->old_values;

        $this->assertNull($event->new_values);
        $this->assertSame($transactionNumber, $old['transaction_number']);
        $this->assertSame('1000.00', $old['original_amount']);
        $this->assertSame('3.750000', $old['fx_rate']);
        $this->assertSame('USD', $old['source_currency_code']);
        $this->assertSame('ILS', $old['disbursement_currency_code']);
        $this->assertStringContainsString('مصدر', $old['source_account_label']);
        $this->assertNotNull(GeneralExchange::withTrashed()->find($exchange->id)->deleted_at);
    }
}
