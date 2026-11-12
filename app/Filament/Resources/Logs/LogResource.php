<?php

declare(strict_types=1);

namespace App\Filament\Resources\Logs;

use App\Domain\Identity\Permissions;
use App\Domain\Work\Actions\DeleteLog;
use App\Domain\Work\Enums\LogType;
use App\Domain\Work\Models\Log;
use App\Filament\Resources\Logs\Pages\CreateLog;
use App\Filament\Resources\Logs\Pages\EditLog;
use App\Filament\Resources\Logs\Pages\ListLogs;
use App\Filament\Support\ActionErrors;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Logs. People with logs.view_any (superadmin, admin, assistant) see everyone's logs; everyone else sees
 * their own. Rate and amount columns need clients.view_rates (D-05).
 */
class LogResource extends Resource
{
    protected static ?string $model = Log::class;

    protected static ?string $slug = 'logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 20;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['user', 'project', 'task', 'technology'])
            ->when(! $user?->can(Permissions::LOGS_VIEW_ANY), fn (Builder $query) => $query->where('user_id', $user?->getKey()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        $seesAll = fn (): bool => (bool) auth()->user()?->can(Permissions::LOGS_VIEW_ANY);
        $seesRates = fn (): bool => (bool) auth()->user()?->can(Permissions::CLIENTS_VIEW_RATES);

        return $table
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('user.name')->label('Employee')->visible($seesAll)
                    ->formatStateUsing(fn (?string $state, Log $record): string => $record->user->getFilamentName())->searchable(['name' => 'users.name']),
                TextColumn::make('project.name')->label('Project')->sortable(),
                TextColumn::make('task.name')->label('Task')->placeholder('Direct')->limit(40),
                TextColumn::make('technology.name')->label('Technology'),
                TextColumn::make('duration_minutes')->label('Time')->sortable()
                    ->formatStateUsing(fn (mixed $state, Log $record): string => $record->duration()),
                TextColumn::make('log_type')->label('Type')->badge(),
                TextColumn::make('description')->limit(40)->toggleable(),
                TextColumn::make('rate_minor')->label('Rate/h')->visible($seesRates)
                    ->formatStateUsing(fn (mixed $state, Log $record): string => $record->rate()->format()),
                TextColumn::make('amount')->label('Amount')->visible($seesRates)
                    ->state(fn (Log $record): string => $record->amount()->format()),
            ])
            ->filters([
                Filter::make('period')->schema([
                    DatePicker::make('from')->native(false),
                    DatePicker::make('to')->native(false),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn ($q, $from) => $q->whereDate('date', '>=', $from))
                    ->when($data['to'] ?? null, fn ($q, $to) => $q->whereDate('date', '<=', $to))),
                SelectFilter::make('project')->relationship('project', 'name'),
                SelectFilter::make('user')->relationship('user', 'name')->visible($seesAll)->label('Employee'),
                SelectFilter::make('log_type')->options(LogType::class)->label('Type'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (Log $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->requiresConfirmation()
                    ->action(function (Log $record): void {
                        try {
                            app(DeleteLog::class)->handle(auth()->user(), $record);
                            Notification::make()->title('Log deleted')->success()->send();
                        } catch (ValidationException $e) {
                            ActionErrors::notify($e);
                        }
                    }),
            ])
            ->defaultSort('date', 'desc')
            ->paginationMode(PaginationMode::Simple)   // no COUNT(*) over millions of rows (NFR-08)
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLogs::route('/'),
            'create' => CreateLog::route('/create'),
            'edit' => EditLog::route('/{record}/edit'),
        ];
    }
}
