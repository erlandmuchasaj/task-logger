<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\MasterData\Enums\Proration;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use Carbon\CarbonInterface;

/** BR-18: the yearly grant, prorated by start date for a joiner and rounded to the nearest half day. */
final class Entitlement
{
    /** In tenths of a day. */
    public static function grantTenths(CountryLeavePolicy $policy, CarbonInterface $startDate, bool $joinedThisYear): int
    {
        $full = Days::tenths((string) $policy->days_per_year);

        if (! $joinedThisYear || $policy->proration !== Proration::ByStartDate) {
            return $full;
        }

        $daysInYear = $startDate->isLeapYear() ? 366 : 365;
        $remaining = (int) $startDate->copy()->startOfDay()->diffInDays($startDate->copy()->endOfYear()->startOfDay()) + 1;

        // round(full * remaining / daysInYear / 5) half days, half up, on integers only.
        $halfDays = intdiv(2 * $full * $remaining + 5 * $daysInYear, 10 * $daysInYear);

        return $halfDays * 5;
    }
}
