<?php

namespace App\Filament\Resources\MuwakhaFamilies\RelationManagers;

use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyProject;
use App\Services\Muwakha\MuwakhaFamilyProjectService;
use App\Support\Muwakha\MuwakhaReference;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Family <-> Muwakha project links, managed entirely from the Family resource
 * (the Project resource is deliberately untouched).
 *
 * Every write goes through MuwakhaFamilyProjectService, which re-validates
 * eligibility, duplicate linkage and card-code scoping SERVER-SIDE. The
 * filtered Select below is a convenience, never the enforcement: a crafted
 * Livewire request carrying an unrelated project id is rejected by the
 * service, not merely absent from the dropdown.
 */
class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'familyProjects';

    protected static ?string $title = 'مشاريع المؤاخاة';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')
                ->label('مشروع المؤاخاة')
                ->options(fn (): array => MuwakhaReference::eligibleProjectOptions())
                ->searchable()
                ->required()
                ->helperText('تظهر فقط المشاريع التابعة للمشروع الرئيسي «'.MuwakhaReference::PROJECT_SUPER_NAME.'».'),

            TextInput::make('card_code')
                ->label('رقم البطاقة')
                ->maxLength(100)
                ->helperText('اختياري. يجب ألا يتكرر ضمن نفس المشروع.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            ->recordTitleAttribute('card_code')
            ->emptyStateHeading('لا توجد مشاريع مرتبطة')
            ->emptyStateDescription('يمكن ربط الأسرة بمشروع مؤاخاة واحد أو أكثر.')
            ->columns([
                TextColumn::make('project.name')->label('المشروع')->sortable(),
                TextColumn::make('card_code')->label('رقم البطاقة')->placeholder('—')->searchable(),
                TextColumn::make('created_at')->label('تاريخ الربط')->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('ربط بمشروع')
                    ->using(function (array $data): Model {
                        /** @var MuwakhaFamily $family */
                        $family = $this->getOwnerRecord();

                        return app(MuwakhaFamilyProjectService::class)->link($family, $data);
                    })
                    ->successNotificationTitle('تم ربط الأسرة بالمشروع'),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (MuwakhaFamilyProject $record, array $data): Model => app(MuwakhaFamilyProjectService::class)
                        ->update($record, $data))
                    ->successNotificationTitle('تم تحديث الارتباط'),

                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('سيتم إلغاء ربط الأسرة بهذا المشروع فقط. لن تتأثر الأسرة ولا حسابها ولا باقي المشاريع.')
                    ->using(fn (MuwakhaFamilyProject $record): bool => app(MuwakhaFamilyProjectService::class)
                        ->unlink($record))
                    ->successNotificationTitle('تم إلغاء الارتباط'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->fetchSelectedRecords()
                        ->requiresConfirmation()
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            $service = app(MuwakhaFamilyProjectService::class);

                            $records->each(function (MuwakhaFamilyProject $record) use ($action, $service): void {
                                try {
                                    $service->unlink($record) || $action->reportBulkProcessingFailure();
                                } catch (Throwable $exception) {
                                    $action->reportBulkProcessingFailure();
                                    report($exception);
                                }
                            });
                        })
                        ->successNotificationTitle('تم إلغاء الارتباطات المحددة'),
                ]),
            ]);
    }
}
