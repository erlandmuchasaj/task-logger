<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;

/**
 * Balance = the sum of the ledger (SPEC 5.5); expiry is itself a negative entry, so "non-expired" is
 * what the sum already says. Available = balance minus pending requests (BR-24). All in tenths of a day.
 */
final class LeaveBalance
{
    public static function balance(User|int $user, int $leaveTypeId): int
    {
        return Days::tenths((string) (LeaveLedgerEntry::query()
            ->where('user_id', self::id($user))
            ->where('leave_type_id', $leaveTypeId)
            ->sum('days') ?: '0'));
    }

    public static function pending(User|int $user, int $leaveTypeId, ?int $exceptRequestId = null): int
    {
        return Days::tenths((string) (LeaveRequest::query()
            ->where('user_id', self::id($user))
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', LeaveStatus::Pending)
            ->when($exceptRequestId, fn ($query) => $query->whereKeyNot($exceptRequestId))
            ->sum('days_requested') ?: '0'));
    }

    public static function available(User|int $user, int $leaveTypeId, ?int $exceptRequestId = null): int
    {
        return self::balance($user, $leaveTypeId) - self::pending($user, $leaveTypeId, $exceptRequestId);
    }

    private static function id(User|int $user): int
    {
        return $user instanceof User ? (int) $user->getKey() : $user;
    }
}
