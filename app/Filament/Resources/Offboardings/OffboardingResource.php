<?php

declare(strict_types=1);

namespace App\Filament\Resources\Offboardings;

use App\Domain\People\Actions\ChangeOffboardingStatus;
use App\Domain\People\Actions\UpdateOffboarding;
use App\Domain\People\Data\OffboardingData;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Filament\Resources\Offboardings\Pages\ManageOffboardings;
use App\Filament\Support\ActionErrors;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** Offboardings (W-10). Perms offboardings.view / offboardings.manage (superadmin, admin, hr). Completing one deactivates the login (ADR-0008). */
class OffboardingResource extends Resource
{
    protected static ?string $model = Offboarding::class;

    protected static ?string $slug = 'offboardings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 50;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee', 'creator']);
    }

    public static function canCreate(): bool
    {
        return false; // started with the header action
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    /** @return list<Component> */
    public static function factFields(): array
    {
        return [
            DatePicker::make('end_date')->label('Last day')->required()->native(false),
            Toggle::make('equipment_returned')->label('Equipment returned'),
            Toggle::make('resignation_letter_signed')->label('Resignation letter signed'),
            Textarea::make('description')->rows(3)->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.name')->label('Employee')
                    ->formatStateUsing(fn (?string $state, Offboarding $record): string => $record->employee->getFilamentName())
                    ->searchable(['name' => 'users.name']),
                TextColumn::make('end_date')->label('Last day')->date()->sortable(),
                TextColumn::make('status')->badge(),
                IconColumn::make('equipment_returned')->boolean()->label('Equipment'),
                IconColumn::make('resignation_letter_signed')->boolean()->label('Letter'),
                TextColumn::make('description')->limit(50)->toggleable(),
                TextColumn::make('creator.name')->label('Created by')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state, Offboarding $record): string => $record->creator->getFilamentName()),
            ])
            ->filters([SelectFilter::make('status')->options(OffboardingStatus::class)])
            ->recordActions([
                Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Offboarding $record): bool => ! $record->status->isFinal() && (bool) auth()->user()?->can('update', $record))
                    ->fillForm(fn (Offboarding $record): array => $record->only(['end_date', 'equipment_returned', 'resignation_letter_signed', 'description']))
                    ->schema(fn (Schema $schema): Schema => $schema->components(self::factFields())->columns(2))
                    ->action(function (Offboarding $record, array $data, Action $action): void {
                        try {
                            app(UpdateOffboarding::class)->handle(auth()->user(), $record, new OffboardingData(
                                CarbonImmutable::parse((string) $data['end_date']), (bool) $data['equipment_returned'],
                                (bool) $data['resignation_letter_signed'], $data['description'] ?? null,
                            ));
                            Notification::make()->title('Offboarding updated')->success()->send();
                        } catch (ValidationException $e) {
                            ActionErrors::notify($e);
                            $action->halt();
                        }
                    }),
                self::move('start', 'Start', OffboardingStatus::InProgress, Heroicon::OutlinedPlay, 'warning'),
                self::move('complete', 'Complete', OffboardingStatus::Completed, Heroicon::OutlinedCheckCircle, 'success',
                    'The person can no longer sign in. Their personal data is anonymised after the retention period.'),
                self::move('cancel', 'Cancel', OffboardingStatus::Cancelled, Heroicon::OutlinedXCircle, 'gray'),
            ])
            ->defaultSort('end_date', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    private static function move(string $name, string $label, OffboardingStatus $to, Heroicon $icon, string $color, ?string $warning = null): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)->requiresConfirmation()
            ->modalDescription($warning)
            ->visible(fn (Offboarding $record): bool => in_array($to, $record->status->next(), true) && (bool) auth()->user()?->can('changeStatus', $record))
            ->action(function (Offboarding $record) use ($to): void {
                try {
                    app(ChangeOffboardingStatus::class)->handle(auth()->user(), $record, $to);
                    Notification::make()->title('Offboarding '.strtolower($to->getLabel()))->success()->send();
                } catch (ValidationException $e) {
                    ActionErrors::notify($e);
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageOffboardings::route('/')];
    }
}
