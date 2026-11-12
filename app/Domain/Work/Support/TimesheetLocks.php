<?php

declare(strict_types=1);

namespace App\Domain\Work\Support;

use App\Domain\Work\Models\TimesheetLock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** D-13: which months are closed for time logging. */
final class TimesheetLocks
{
    public static function monthOf(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString())->startOfMonth();
    }

    /**
     * One query per request: the table holds at most a dozen months a year, and a table of 25 rows asks this per row
     * (NFR-09). The set is held by a scoped instance, so every request and queued job starts fresh, and TimesheetLock
     * flushes it whenever a lock is saved or deleted.
     */
    public static function isLocked(CarbonInterface $date): bool
    {
        return in_array(self::monthOf($date)->toDateString(), app(LockedMonths::class)->months(), true);
    }
}
