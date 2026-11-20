<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Support;

use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one place that answers "which bank holidays fall between these dates" (BR-16, BR-25, D-59, D-62).
 * A repeating holiday keeps its day and month every year, from the year of its own date on until its
 * optional last year. In a country that moves weekend holidays (D-62) a holiday date on a non-working
 * day is observed on the next working day that is not a holiday and not already taken; that observed
 * day is worked out here, never stored.
 */
final class BankHolidays
{
    /**
     * How far before the window a weekend holiday can sit and still be observed inside it. An observed day
     * lands within a few days of its source, so a month is generous.
     */
    private const int OBSERVED_LOOKBACK_DAYS = 31;

    /**
     * Every occurrence that overlaps the window, oldest first: the holidays themselves, plus the observed
     * days of countries that move weekend holidays. Occurrences are not clipped: callers clip with
     * `HolidayOccurrence::days()`. Two queries (the holidays and their countries), whatever the number of years.
     *
     * @param  list<int>|null  $countryIds  null means every country
     * @return Collection<int, HolidayOccurrence>
     */
    public static function occurrences(?array $countryIds, string $from, string $to): Collection
    {
        $lookbackFrom = CarbonImmutable::parse($from)->subDays(self::OBSERVED_LOOKBACK_DAYS)->toDateString();
        $base = self::baseOccurrences($countryIds, $lookbackFrom, $to);

        /** @var array<int, list<HolidayOccurrence>> $byCountry */
        $byCountry = [];

        foreach ($base as $occurrence) {
            $byCountry[$occurrence->countryId][] = $occurrence;
        }

        $all = $base;

        foreach ($byCountry as $occurrences) {
            $country = $occurrences[0]->country;

            if ($country instanceof Country && $country->movesWeekendHolidays()) {
                array_push($all, ...self::observed($country, $occurrences, $lookbackFrom, $to));
            }
        }

        $inWindow = array_filter(
            $all,
            fn (HolidayOccurrence $occurrence): bool => $occurrence->start->toDateString() <= $to && $occurrence->end->toDateString() >= $from,
        );

        usort($inWindow, fn (HolidayOccurrence $a, HolidayOccurrence $b): int => [$a->start->toDateString(), $a->holidayId] <=> [$b->start->toDateString(), $b->holidayId]);

        return collect($inWindow);
    }

    /**
     * The holiday days of one country inside the window, keyed by date.
     *
     * @return array<string, true>
     */
    public static function dates(Country $country, string $from, string $to): array
    {
        $dates = [];

        foreach (self::occurrences([(int) $country->getKey()], $from, $to) as $occurrence) {
            foreach ($occurrence->days($from, $to) as $date) {
                $dates[$date] = true;
            }
        }

        return $dates;
    }

    /**
     * The holidays as entered, one occurrence per year for a repeating one, that overlap the window.
     *
     * @param  list<int>|null  $countryIds
     * @return list<HolidayOccurrence>
     */
    private static function baseOccurrences(?array $countryIds, string $from, string $to): array
    {
        $fromYear = CarbonImmutable::parse($from)->year;
        $toYear = CarbonImmutable::parse($to)->year;

        $holidays = BankHoliday::query()
            ->with('country')
            ->when($countryIds !== null, fn (Builder $query): Builder => $query->whereIn('country_id', $countryIds ?? []))
            ->where('start_date', '<=', $to)
            ->where(function (Builder $query) use ($from, $fromYear): void {
                $query->where(fn (Builder $oneOff): Builder => $oneOff->where('repeats_yearly', false)->where('end_date', '>=', $from))
                    // A range can start in December and end in January, so the year before the window counts.
                    ->orWhere(fn (Builder $repeating): Builder => $repeating->where('repeats_yearly', true)
                        ->where(fn (Builder $until): Builder => $until->whereNull('repeat_until_year')->orWhere('repeat_until_year', '>=', $fromYear - 1)));
            })
            ->get();

        /** @var list<HolidayOccurrence> $occurrences */
        $occurrences = [];

        foreach ($holidays as $holiday) {
            $start = CarbonImmutable::parse($holiday->start_date->toDateString());
            $end = CarbonImmutable::parse($holiday->end_date->toDateString());

            if (! $holiday->repeats_yearly) {
                $occurrences[] = self::occurrence($holiday, $start, $end);

                continue;
            }

            $firstYear = $start->year;
            $lastYear = min($holiday->repeat_until_year ?? $toYear, $toYear);

            for ($year = max($firstYear, $fromYear - 1); $year <= $lastYear; $year++) {
                $shifted = self::occurrence($holiday, $start->addYearsNoOverflow($year - $firstYear), $end->addYearsNoOverflow($year - $firstYear));

                if ($shifted->start->toDateString() <= $to && $shifted->end->toDateString() >= $from) {
                    $occurrences[] = $shifted;
                }
            }
        }

        return $occurrences;
    }

    /**
     * The observed days of one country (D-62). Walks the days in order. A holiday date on a non-working day
     * waits in a queue, once per date however many holidays share it. A working day that is not itself a
     * holiday takes the oldest waiting date and becomes the observed day of every holiday on it.
     *
     * @param  list<HolidayOccurrence>  $occurrences  the country's holidays as entered
     * @return list<HolidayOccurrence>
     */
    private static function observed(Country $country, array $occurrences, string $sweepFrom, string $to): array
    {
        /** @var array<string, list<HolidayOccurrence>> $byDate */
        $byDate = [];
        $first = $sweepFrom;

        foreach ($occurrences as $occurrence) {
            // A long range can start before the lookback, and every weekend day in it waits for a working day.
            $first = min($first, $occurrence->start->toDateString());
        }

        foreach ($occurrences as $occurrence) {
            foreach ($occurrence->days($first, $to) as $date) {
                $byDate[$date][] = $occurrence;
            }
        }

        if ($byDate === []) {
            return [];
        }

        $lastHoliday = max(array_keys($byDate));

        /** @var list<string> $waiting weekend holiday dates, oldest first */
        $waiting = [];
        /** @var list<HolidayOccurrence> $observed */
        $observed = [];

        for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $isWorkingDay = $country->isWorkingDay($day->dayOfWeekIso);

            if (isset($byDate[$date])) {
                if (! $isWorkingDay) {
                    $waiting[] = $date;
                }
            } elseif ($isWorkingDay && $waiting !== []) {
                foreach ($byDate[array_shift($waiting)] as $source) {
                    $observed[] = $source->observedOn($day);
                }
            }

            if ($waiting === [] && $date >= $lastHoliday) {
                break;
            }
        }

        return $observed;
    }

    private static function occurrence(BankHoliday $holiday, CarbonImmutable $start, CarbonImmutable $end): HolidayOccurrence
    {
        return new HolidayOccurrence(
            holidayId: (int) $holiday->getKey(),
            name: $holiday->name,
            countryId: $holiday->country_id,
            country: $holiday->country,
            start: $start,
            end: $end,
        );
    }
}
