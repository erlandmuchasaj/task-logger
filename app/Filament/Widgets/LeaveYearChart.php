<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Support\LeaveOverview;
use Filament\Widgets\ChartWidget;

/** D-78: leave days taken this year against the days still available, for the dashboard leave type. */
final class LeaveYearChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Leave this year';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(Permissions::LEAVE_REQUESTS_VIEW_OWN);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        // Days are stored as tenths; the chart shows days, which is display only.
        return [
            'datasets' => [[
                'data' => [
                    round(LeaveOverview::takenThisYear($user) / 10, 1),
                    round(max(0, LeaveOverview::remaining($user) ?? 0) / 10, 1),
                ],
                'backgroundColor' => ['rgb(14, 165, 233)', 'rgb(245, 158, 11)'],
            ]],
            'labels' => ['Taken', 'Remaining'],
        ];
    }
}
