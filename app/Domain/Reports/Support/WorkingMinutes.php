<?php

declare(strict_types=1);

namespace App\Domain\Reports\Support;

use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Support\BankHolidays;
use Carbon\CarbonImmutable;

/**
 * D-91: the working time of one month for a country, in minutes. A working day is a day of the country's working
 * week that is not a bank holiday; each counts the configured hours. Holidays come from `BankHolidays`, so a
 * weekend holiday moved to a working day (D-62) is already handled.
 */
final class WorkingMinutes
{
    /** @var array<string, int> */
    private array $cache = [];

    /** @param  string  $month  "2026-11" */
    public function forMonth(?Country $country, string $month): int
    {
        $key = ($country?->getKey() ?? 0).'|'.$month;

        return $this->cache[$key] ??= $this->count($country, $month);
    }

    private function count(?Country $country, string $month): int
    {
        $fallback = (int) config('task-logger.financials.fallback_hours_per_month') * 60;

        if (! $country instanceof Country) {
            return $fallback;
        }

        $first = CarbonImmutable::parse($month.'-01');
        $last = $first->endOfMonth();
        $holidays = BankHolidays::dates($country, $first->toDateString(), $last->toDateString());
        $days = 0;

        for ($day = $first; $day <= $last; $day = $day->addDay()) {
            if ($country->isWorkingDay($day->dayOfWeekIso) && ! isset($holidays[$day->toDateString()])) {
                $days++;
            }
        }

        $minutes = $days * (int) config('task-logger.financials.hours_per_working_day') * 60;

        return $minutes > 0 ? $minutes : $fallback;
    }
}
