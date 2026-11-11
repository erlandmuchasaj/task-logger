<?php

declare(strict_types=1);

namespace App\Filament\Resources\TimesheetLocks;

use App\Domain\Work\Actions\LockMonth;
use App\Domain\Work\Actions\UnlockMonth;
use App\Domain\Work\Models\TimesheetLock;
use App\Filament\Resources\TimesheetLocks\Pages\ManageTimesheetLocks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** W-08: the superadmin closes a month for time logging (D-13, BR-07) and can open it again. Perm timesheets.lock. */
class TimesheetLockResource extends Resource
{
    protected static ?string $model = TimesheetLock::class;

    protected static ?string $slug = 'timesheet-locks';

    protected static ?string $modelLabel = 'month lock';

    protected static ?string $navigationLabel = 'Timesheet locks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['locker', 'unlocker']);
    }

    public static function canCreate(): bool
    {
        return false; // months are locked with the "Lock a month" action
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('month')->date('F Y')->sortable(),
                TextColumn::make('status')->badge()
                    ->state(fn (TimesheetLock $record): string => $record->isLocked() ? 'Locked' : 'Open')
                    ->color(fn (string $state): string => $state === 'Locked' ? 'danger' : 'success'),
                TextColumn::make('locker.name')->label('Locked by')
                    ->formatStateUsing(fn (?string $state, TimesheetLock $record): string => $record->locker->getFilamentName()),
                TextColumn::make('locked_at')->dateTime(),
                TextColumn::make('unlocker.name')->label('Unlocked by')->placeholder('-')
                    ->formatStateUsing(fn (?string $state, TimesheetLock $record): ?string => $record->unlocker?->getFilamentName()),
                TextColumn::make('unlocked_at')->dateTime()->placeholder('-'),
            ])
            ->recordActions([
                Action::make('unlock')->icon(Heroicon::OutlinedLockOpen)->color('warning')
                    ->visible(fn (TimesheetLock $record): bool => $record->isLocked() && (bool) auth()->user()?->can('lock', TimesheetLock::class))
                    ->requiresConfirmation()->modalDescription('Employees can log and edit time in this month again. The change is recorded.')
                    ->action(function (TimesheetLock $record): void {
                        app(UnlockMonth::class)->handle(auth()->user(), $record);
                        Notification::make()->title('Month unlocked')->success()->send();
                    }),
                Action::make('relock')->label('Lock again')->icon(Heroicon::OutlinedLockClosed)
                    ->visible(fn (TimesheetLock $record): bool => ! $record->isLocked() && (bool) auth()->user()?->can('lock', TimesheetLock::class))
                    ->requiresConfirmation()
                    ->action(function (TimesheetLock $record): void {
                        app(LockMonth::class)->handle(auth()->user(), $record->month);
                        Notification::make()->title('Month locked')->success()->send();
                    }),
            ])
            ->defaultSort('month', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTimesheetLocks::route('/')];
    }
}
