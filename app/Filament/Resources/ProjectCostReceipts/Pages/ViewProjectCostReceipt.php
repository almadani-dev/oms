<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ViewProjectCostReceipt extends ViewRecord
{
    protected static string $resource = ProjectCostReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('تفاصيل الاستلام')->columns(2)->schema([

                TextEntry::make('transaction.transaction_number')
                    ->label('رقم المعاملة'),

                TextEntry::make('projectCost.project.projectSuper.name')
                    ->label('المشروع الرئيسي'),

                TextEntry::make('projectCost.project.name')
                    ->label('المشروع'),

                TextEntry::make('projectCost.amount')
                    ->label('تكلفة المشروع')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('projectCost.currency.name')
                    ->label('عملة تكلفة المشروع'),

                TextEntry::make('transaction.transactionType.transactionSuperType.name')
                    ->label('تصنيف المعاملة'),

                TextEntry::make('transaction.transactionType.name')
                    ->label('نوع المعاملة'),

                TextEntry::make('transaction.partner.name')
                    ->label('الجهة المانحة'),

                TextEntry::make('transaction.fiscalYear.name')
                    ->label('السنة المالية'),

                TextEntry::make('amount')
                    ->label('المبلغ')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('date')
                    ->label('التاريخ')
                    ->date(),

                TextEntry::make('debit_account')
                    ->label('الحساب المدين')
                    ->state(function ($record) {
                        $account = $record->transaction
                            ?->lines()->with('account')->where('debit_base', '>', 0)->first()
                            ?->account;
                        return $account ? $account->account_code . ' - ' . $account->name : null;
                    }),

                TextEntry::make('credit_account')
                    ->label('الحساب الدائن')
                    ->state(function ($record) {
                        $account = $record->transaction
                            ?->lines()->with('account')->where('credit_base', '>', 0)->first()
                            ?->account;
                        return $account ? $account->account_code . ' - ' . $account->name : null;
                    }),

                TextEntry::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),

                TextEntry::make('createdBy.name')
                    ->label('أنشئ بواسطة'),

                TextEntry::make('updatedBy.name')
                    ->label('عدل بواسطة'),

            ]),

            Section::make('المرفقات')->schema([

                TextEntry::make('attachment_display')
                    ->label('صورة الإشعار')
                    ->html()
                    ->columnSpanFull()
                    ->state(function ($record) {
                        $attachment = $record->attachments()->first();

                        if (!$attachment) {
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
}
