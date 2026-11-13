<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Identity\Permissions;
use App\Domain\Work\Enums\LogType;
use App\Domain\Work\Models\Log;
use App\Filament\Resources\Logs\LogResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The person's timesheet on their employee page (read-only). Visible to holders of logs.view_any;
 * rates and amounts only with clients.view_rates, as on the Logs screen.
 */
final class TimesheetsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Timesheets';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can(Permissions::LOGS_VIEW_ANY);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $seesRates = fn (): bool => (bool) auth()->user()?->can(Permissions::CLIENTS_VIEW_RATES);

        return $table
            ->heading('Timesheets')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['project', 'task', 'technology']))
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('project.name')->label('Project')->sortable()->searchable(),
                TextColumn::make('task.name')->label('Task')->placeholder('Direct')->limit(40)->searchable(),
                TextColumn::make('technology.name')->label('Technology')->toggleable(),
                TextColumn::make('duration_minutes')->label('Time')->alignEnd()->sortable()
                    ->formatStateUsing(fn (mixed $state, Log $record): string => $record->duration())
                    ->summarize(Summarizer::make()->label('Total')
                        ->using(fn ($query): string => sprintf('%dh %02dm', intdiv((int) $query->sum('duration_minutes'), 60), (int) $query->sum('duration_minutes') % 60))),
                TextColumn::make('log_type')->label('Type')->badge(),
                TextColumn::make('description')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount')->label('Amount')->alignEnd()->visible($seesRates)
                    ->state(fn (Log $record): string => $record->amount()->format()),
            ])
            ->filters([
                SelectFilter::make('project')->relationship('project', 'name')->searchable()->preload(),
                SelectFilter::make('log_type')->label('Type')->options(LogType::class),
                Filter::make('period')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('date', '<=', $d))),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (Log $record): string => LogResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Log $record): bool => (bool) auth()->user()?->can('update', $record)),
            ])
            ->defaultSort('date', 'desc')
            ->emptyStateHeading('No time logged yet');
    }
}
