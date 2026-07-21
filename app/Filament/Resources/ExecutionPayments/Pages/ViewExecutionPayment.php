<?php

namespace App\Filament\Resources\ExecutionPayments\Pages;

use App\Filament\Resources\ExecutionPayments\ExecutionPaymentResource;
use App\Filament\Resources\ExecutionPayments\Schemas\ExecutionPaymentForm;
use App\Models\ProjectCostBudgetsPayment;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ViewExecutionPayment extends ViewRecord
{
    protected static string $resource = ExecutionPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('المشروع والمبلغ المرصود')->columns(2)->schema([

                TextEntry::make('transaction.transaction_number')
                    ->label('رقم المعاملة'),

                TextEntry::make('projectCostBudget.projectCost.project.projectSuper.name')
                    ->label('المشروع الرئيسي'),

                TextEntry::make('projectCostBudget.projectCost.project.name')
                    ->label('المشروع'),

                TextEntry::make('projectCostBudget.projectCost.amount')
                    ->label('تكلفة المشروع')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('budget_amount')
                    ->label('المبلغ المرصود')
                    ->state(fn ($record) => (float) ($record->projectCostBudget?->final_amount ?? 0))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('budget_remaining')
                    ->label('المتبقي من المرصود')
                    ->state(fn ($record) => ExecutionPaymentForm::budgetRemaining($record->project_cost_budget_id))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('amount')
                    ->label('مبلغ التنفيذ')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('budget_currency')
                    ->label('عملة المرصود')
                    ->state(fn ($record) => $record->projectCostBudget?->disbursementCurrency?->name),

            ]),

            Section::make('الحسابات')->columns(2)->schema([

                TextEntry::make('beneficiary_account')
                    ->label('الحساب المدين (المستفيد)')
                    ->state(fn ($record) => self::accountLabel($record, ProjectCostBudgetsPayment::LINE_BENEFICIARY)),

                TextEntry::make('credit_account')
                    ->label('الحساب الدائن (الوجهة)')
                    ->state(fn ($record) => self::accountLabel($record, ProjectCostBudgetsPayment::LINE_CREDIT)),

            ]),

            Section::make('تفاصيل المعاملة')->columns(2)->schema([

                TextEntry::make('transaction.transactionType.transactionSuperType.name')
                    ->label('تصنيف المعاملة'),

                TextEntry::make('transaction.transactionType.name')
                    ->label('نوع المعاملة'),

                TextEntry::make('transaction.fiscalYear.name')
                    ->label('السنة المالية'),

                TextEntry::make('transaction.partner.name')
                    ->label('الجهة / المستفيد'),

                TextEntry::make('date')
                    ->label('تاريخ التنفيذ')
                    ->date(),

                TextEntry::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),

                TextEntry::make('createdBy.name')->label('أنشئ بواسطة'),
                TextEntry::make('updatedBy.name')->label('عدل بواسطة'),

            ]),

            Section::make('المرفقات')->schema([

                View::make('filament.components.secure-attachment-preview')
                    ->viewData(fn ($record) => ['attachment' => $record->attachments()->latest('id')->first()])
                    ->columnSpanFull(),

            ]),

        ]);
    }

    protected static function accountLabel(ProjectCostBudgetsPayment $record, string $tag): ?string
    {
        $line = $record->transaction
            ?->lines()->with('account')->where('notes', $tag)->first();

        $account = $line?->account;
        if (! $account) {
            return null;
        }

        return trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name);
    }
}
