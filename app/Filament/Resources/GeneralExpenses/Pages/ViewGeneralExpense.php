<?php

namespace App\Filament\Resources\GeneralExpenses\Pages;

use App\Filament\Resources\GeneralExpenses\GeneralExpenseResource;
use App\Models\GeneralExpense;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ViewGeneralExpense extends ViewRecord
{
    protected static string $resource = GeneralExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('تفاصيل المصروف')->columns(2)->schema([

                TextEntry::make('transaction.transaction_number')
                    ->label('رقم المعاملة'),

                TextEntry::make('amount')
                    ->label('المبلغ')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('currency.name')
                    ->label('العملة'),

                TextEntry::make('partner.name')
                    ->label('الجهة / المستفيد')
                    ->state(fn ($record) => $record->partner?->name ?? $record->transaction?->partner?->name),

                TextEntry::make('date')
                    ->label('التاريخ')
                    ->date(),

            ]),

            Section::make('تفاصيل المعاملة')->columns(2)->schema([

                TextEntry::make('transaction.transactionType.transactionSuperType.name')
                    ->label('تصنيف المعاملة'),

                TextEntry::make('transaction.transactionType.name')
                    ->label('نوع المعاملة'),

                TextEntry::make('transaction.fiscalYear.name')
                    ->label('السنة المالية'),

            ]),

            Section::make('الحسابات')->columns(2)->schema([

                TextEntry::make('debit_account')
                    ->label('الحساب المدين')
                    ->state(fn ($record) => self::accountLabel($record, 'debit')),

                TextEntry::make('credit_account')
                    ->label('الحساب الدائن')
                    ->state(fn ($record) => self::accountLabel($record, 'credit')),

            ]),

            Section::make('ملاحظات')->columns(2)->schema([

                TextEntry::make('description')
                    ->label('الوصف')
                    ->columnSpanFull(),

                TextEntry::make('notes')
                    ->label('الملاحظات')
                    ->columnSpanFull(),

                TextEntry::make('createdBy.name')->label('أنشئ بواسطة'),
                TextEntry::make('updatedBy.name')->label('عدل بواسطة'),

            ]),

            Section::make('المرفقات')->schema([

                TextEntry::make('attachment_display')
                    ->label('صورة الإشعار')
                    ->html()
                    ->columnSpanFull()
                    ->state(function ($record) {
                        $attachment = $record->attachments()->first();

                        if (! $attachment) {
                            return '<span class="text-gray-500">لا يوجد إشعار مرفق</span>';
                        }

                        $url = Storage::disk('public')->url($attachment->file_path);

                        if (str_contains($attachment->file_type ?? '', 'image')) {
                            return '<img src="' . e($url) . '" style="max-width:100%;border-radius:8px;" />';
                        }

                        return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer"
                            style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#3b82f6;color:#fff;border-radius:6px;text-decoration:none;">
                            تحميل المرفق (PDF)
                        </a>';
                    }),

            ]),

        ]);
    }

    /**
     * $role: 'debit' -> line with debit_base > 0, 'credit' -> line with credit_base > 0.
     */
    protected static function accountLabel(GeneralExpense $record, string $role): ?string
    {
        $lines = $record->transaction?->lines()->with('account')->get();

        $line = $role === 'debit'
            ? $lines?->first(fn ($l) => (float) $l->debit_base > 0)
            : $lines?->first(fn ($l) => (float) $l->credit_base > 0);

        $account = $line?->account;
        if (! $account) {
            return null;
        }

        return trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name);
    }
}
