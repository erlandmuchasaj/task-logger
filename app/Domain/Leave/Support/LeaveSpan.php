<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Leave\Enums\DayPart;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The calendar side of a leave request: which half days it occupies (overlap, BR-23) and how many
 * working days it costs (BR-16, BR-17). The first day takes `startPart`, the last day `endPart`; every
 * day in between is a full day. A single-day request carries one part, so `endPart` is ignored.
 */
final readonly class LeaveSpan
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public DayPart $endPart;

    public function __construct(CarbonInterface $start, CarbonInterface $end, public DayPart $startPart = DayPart::Full, DayPart $endPart = DayPart::Full)
    {
        $this->start = CarbonImmutable::parse($start->toDateString());
        $this->end = CarbonImmutable::parse($end->toDateString());
        $this->endPart = $this->start->equalTo($this->end) ? $startPart : $endPart;
    }

    public function isSingleDay(): bool
    {
        return $this->start->equalTo($this->end);
    }

    public function calendarDays(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /**
     * Occupied half days as "2026-05-04:am", counting weekends and holidays too, because overlap is about
     * the dates asked for, not the days charged.
     *
     * @return list<string>
     */
    public function slots(): array
    {
        $slots = [];

        for ($day = $this->start; $day->lessThanOrEqualTo($this->end); $day = $day->addDay()) {
            foreach ($this->partsOn($day) as $part) {
                $slots[] = $day->toDateString().':'.$part;
            }
        }

        return $slots;
    }

    public function overlaps(self $other): bool
    {
        return array_intersect($this->slots(), $other->slots()) !== [];
    }

    /** Working days charged, in tenths (10 = one day, 5 = a half day). */
    public function chargedTenths(Country $country): int
    {
        $holidays = $this->holidayDates($country);
        $tenths = 0;

        for ($day = $this->start; $day->lessThanOrEqualTo($this->end); $day = $day->addDay()) {
            if (! $country->isWorkingDay($day->dayOfWeekIso) || isset($holidays[$day->toDateString()])) {
                continue;
            }

            $tenths += count($this->partsOn($day)) === 2 ? 10 : 5;
        }

        return $tenths;
    }

    /** @return list<'am'|'pm'> */
    private function partsOn(CarbonImmutable $day): array
    {
        $part = match (true) {
            $this->isSingleDay() => $this->startPart,
            $day->equalTo($this->start) => $this->startPart,
            $day->equalTo($this->end) => $this->endPart,
            default => DayPart::Full,
        };

        return match ($part) {
            DayPart::Full => ['am', 'pm'],
            DayPart::Am => ['am'],
            DayPart::Pm => ['pm'],
        };
    }

    /**
     * Dates of the country's bank holidays inside the span. A holiday on a weekend is never shifted (D-08).
     *
     * @return array<string, true>
     */
    private function holidayDates(Country $country): array
    {
        $dates = [];

        BankHoliday::query()
            ->where('country_id', $country->getKey())
            ->whereDate('start_date', '<=', $this->end->toDateString())
            ->whereDate('end_date', '>=', $this->start->toDateString())
            ->get(['start_date', 'end_date'])
            ->each(function (BankHoliday $holiday) use (&$dates): void {
                for ($day = CarbonImmutable::parse($holiday->start_date->toDateString()); $day->lessThanOrEqualTo($holiday->end_date); $day = $day->addDay()) {
                    $dates[$day->toDateString()] = true;
                }
            });

        return $dates;
    }
}
