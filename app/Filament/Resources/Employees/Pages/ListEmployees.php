<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Identity\Models\User;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Widgets\EmployeeStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    /** Predefined views over account status. "All" includes anonymised accounts, as the list always has. */
    public function getTabs(): array
    {
        $base = fn (): Builder => User::query();

        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => $base()->count()),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('banned_at')->whereNull('anonymized_at'))
                ->badge(fn (): int => $base()->whereNull('banned_at')->whereNull('anonymized_at')->count())
                ->badgeColor('success'),
            'inactive' => Tab::make('Inactive')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->whereNotNull('banned_at')->orWhereNotNull('anonymized_at')))
                ->badge(fn (): int => $base()->where(fn (Builder $q): Builder => $q->whereNotNull('banned_at')->orWhereNotNull('anonymized_at'))->count())
                ->badgeColor('warning'),
            'new' => Tab::make('New this month')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereBetween('start_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]))
                ->badge(fn (): int => $base()->whereBetween('start_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count())
                ->badgeColor('info'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [EmployeeStats::class];
    }
}
