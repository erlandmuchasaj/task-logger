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

    public static function isLocked(CarbonInterface $date): bool
    {
        return TimesheetLock::query()
            ->whereDate('month', self::monthOf($date)->toDateString())
            ->whereNull('unlocked_at')
            ->exists();
    }
}
