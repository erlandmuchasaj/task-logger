<?php

declare(strict_types=1);

namespace App\Filament\Resources\TimesheetLocks\Pages;

use App\Domain\Work\Actions\LockMonth;
use App\Filament\Resources\TimesheetLocks\TimesheetLockResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

final class ManageTimesheetLocks extends ManageRecords
{
    protected static string $resource = TimesheetLockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lock')->label('Lock a month')->icon(Heroicon::OutlinedLockClosed)
                ->schema([
                    DatePicker::make('month')->label('Any day in the month to lock')->required()->default(now()->subMonth()),
                ])
                ->modalDescription('Employees can no longer create, edit or delete logs in that month. Export the logs first (W-08).')
                ->action(function (array $data): void {
                    resolve(LockMonth::class)->handle(auth()->user(), CarbonImmutable::parse((string) $data['month']));
                    Notification::make()->title('Month locked')->success()->send();
                }),
        ];
    }
}
