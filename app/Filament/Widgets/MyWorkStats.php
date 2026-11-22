<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveOverview;
use App\Domain\Work\Support\MyWorkSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** D-78: the signed-in person's own figures. Each figure shows only when the person may see that part of their own work. */
final class MyWorkStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'My work';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAny([
            Permissions::TASKS_VIEW_OWN,
            Permissions::LOGS_VIEW_OWN,
            Permissions::LEAVE_REQUESTS_VIEW_OWN,
        ]);
    }

    /** @return list<Stat> */
    protected function getStats(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $stats = [];

        if ($user->can(Permissions::TASKS_VIEW_OWN)) {
            $stats[] = Stat::make('Active tasks', MyWorkSummary::activeTasks($user))
                ->description('Assigned to you and not finished')
                ->icon('heroicon-o-clipboard-document-list');
        }

        if ($user->can(Permissions::LOGS_VIEW_OWN)) {
            $stats[] = Stat::make('Hours this month', MyWorkSummary::format(MyWorkSummary::minutesThisMonth($user)))
                ->description('Logged by you')
                ->icon('heroicon-o-clock');
        }

        if ($user->can(Permissions::LEAVE_REQUESTS_VIEW_OWN)) {
            $remaining = LeaveOverview::remaining($user);

            $stats[] = Stat::make('Leave remaining', $remaining === null ? '—' : Days::format($remaining).' days')
                ->description(LeaveOverview::typeLabel() ?? 'No leave type set up')
                ->icon('heroicon-o-sun');
        }

        return $stats;
    }
}
