<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\Work\Support\WorkCalendar;

/**
 * D-78: leave figures for the dashboard, in tenths of a day, for the one leave type named in
 * `task-logger.dashboard.leave_type`. "Remaining" is the same number My Leave calls available (balance minus
 * pending); "taken" is the approved or taken requests that start this year, which is the date their deduction
 * carries in the ledger, so a request cancelled later is not counted.
 */
final class LeaveOverview
{
    /** The days still open to the person, or null when the dashboard's leave type does not exist or is inactive. */
    public static function remaining(User $user): ?int
    {
        $type = self::type();

        return $type instanceof LeaveType ? LeaveBalance::available($user, (int) $type->getKey()) : null;
    }

    public static function takenThisYear(User $user): int
    {
        $type = self::type();

        if (! $type instanceof LeaveType) {
            return 0;
        }

        $year = WorkCalendar::today($user)->year;

        return Days::tenths((string) (LeaveRequest::query()
            ->where('user_id', $user->getKey())
            ->where('leave_type_id', $type->getKey())
            ->whereIn('status', [LeaveStatus::Approved, LeaveStatus::Taken])
            ->whereBetween('start_date', [$year.'-01-01', $year.'-12-31'])
            ->sum('days_requested') ?: '0'));
    }

    public static function typeLabel(): ?string
    {
        return self::type()?->label();
    }

    private static function type(): ?LeaveType
    {
        return LeaveType::query()
            ->where('name', (string) config('task-logger.dashboard.leave_type'))
            ->where('is_active', true)
            ->first();
    }
}
