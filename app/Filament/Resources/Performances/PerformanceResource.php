<?php

declare(strict_types=1);

namespace App\Filament\Resources\Performances;

use App\Domain\Identity\Permissions;
use App\Domain\People\Models\Performance;
use App\Filament\Resources\Performances\Pages\ManagePerformances;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Performance reviews (W-09). performances.view (superadmin, hr) reads all; a direct manager reads their reports'
 * reviews; an admin who is not the manager can add a review but sees only what the policy lets them read (BR-27).
 */
class PerformanceResource extends Resource
{
    protected static ?string $model = Performance::class;

    protected static ?string $slug = 'performances';

    protected static ?string $modelLabel = 'performance review';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 40;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['employee', 'reviewer'])
            ->when(! $user?->can(Permissions::PERFORMANCES_VIEW), fn (Builder $query) => $query
                ->whereHas('employee', fn (Builder $employee) => $employee->where('manager_id', $user?->getKey())));
    }

    public static function canCreate(): bool
    {
        return false; // reviews are added with the header action
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('review_date')->date()->sortable(),
                TextColumn::make('employee.name')->label('Employee')
                    ->formatStateUsing(fn (?string $state, Performance $record): string => $record->employee->getFilamentName())
                    ->searchable(['name' => 'users.name']),
                TextColumn::make('score')->badge()->sortable()
                    ->color(fn (int $state): string => $state >= 8 ? 'success' : ($state >= 5 ? 'warning' : 'danger')),
                TextColumn::make('notes')->limit(60)->wrap(),
                TextColumn::make('reviewer.name')->label('Reviewed by')
                    ->formatStateUsing(fn (?string $state, Performance $record): string => $record->reviewer->getFilamentName()),
            ])
            ->defaultSort('review_date', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePerformances::route('/')];
    }
}
