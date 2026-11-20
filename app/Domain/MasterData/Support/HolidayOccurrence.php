<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Support;

use App\Domain\MasterData\Models\Country;
use Carbon\CarbonImmutable;

/**
 * One bank holiday as it falls in a particular year (D-59). A repeating holiday yields one
 * occurrence per year, a one-off holiday yields its own dates. In a country that moves weekend
 * holidays (D-62) a holiday that fell on a non-working day also yields an observed occurrence
 * on the working day that replaces it.
 */
final readonly class HolidayOccurrence
{
    public function __construct(
        public int $holidayId,
        public string $name,
        public int $countryId,
        public ?Country $country,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public bool $observed = false,
    ) {}

    /** The name people read: "Summer Day", or "Summer Day (observed)" on the day that replaces a weekend one. */
    public function label(): string
    {
        return $this->observed ? $this->name.' (observed)' : $this->name;
    }

    /** The observed occurrence of this holiday: one working day that stands in for a weekend date (D-62). */
    public function observedOn(CarbonImmutable $day): self
    {
        return new self($this->holidayId, $this->name, $this->countryId, $this->country, $day, $day, observed: true);
    }

    /**
     * The days of this occurrence that fall between two dates, inclusive.
     *
     * @return list<string>
     */
    public function days(string $from, string $to): array
    {
        $days = [];
        $last = min($this->end->toDateString(), $to);

        for ($day = CarbonImmutable::parse(max($this->start->toDateString(), $from)); $day->toDateString() <= $last; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
