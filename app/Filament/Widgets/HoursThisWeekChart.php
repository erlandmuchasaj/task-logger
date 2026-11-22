<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Work\Support\MyWorkSummary;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** D-78: the signed-in person's logged hours for each day of the current week, Monday to Sunday. */
final class HoursThisWeekChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Hours this week';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(Permissions::LOGS_VIEW_OWN);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $days = MyWorkSummary::minutesPerDayThisWeek($user);

        return [
            'datasets' => [[
                'label' => 'Hours',
                // A float appears only here, at the edge: the chart axis wants hours, the data is whole minutes.
                'data' => array_map(fn (int $minutes): float => round($minutes / 60, 2), array_values($days)),
            ]],
            'labels' => array_map(fn (string $date): string => CarbonImmutable::parse($date)->format('D d/m'), array_keys($days)),
        ];
    }
}
