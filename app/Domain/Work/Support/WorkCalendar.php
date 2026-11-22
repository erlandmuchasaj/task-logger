<?php

declare(strict_types=1);

namespace App\Domain\Work\Support;

use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

/** BR-34: dates are calendar dates in the user's country timezone (UTC when the user has no country). */
final class WorkCalendar
{
    public static function timezone(User $user): string
    {
        return (string) ($user->country()->value('timezone') ?: 'UTC');
    }

    public static function today(User $user): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone($user))->startOfDay()->shiftTimezone('UTC'); // a plain date, no time-zone shift later
    }
}
