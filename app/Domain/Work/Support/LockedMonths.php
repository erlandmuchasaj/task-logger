<?php

declare(strict_types=1);

namespace App\Domain\Work\Support;

use App\Domain\Work\Models\TimesheetLock;

/** The first days of the months currently locked, loaded once per request (see TimesheetLocks::isLocked). */
final class LockedMonths
{
    /** @var list<string>|null */
    private ?array $months = null;

    /** @return list<string> */
    public function months(): array
    {
        return $this->months ??= array_values(TimesheetLock::query()
            ->whereNull('unlocked_at')
            ->get(['month'])
            ->map(fn (TimesheetLock $lock): string => $lock->month->toDateString())
            ->all());
    }

    public function flush(): void
    {
        $this->months = null;
    }
}
