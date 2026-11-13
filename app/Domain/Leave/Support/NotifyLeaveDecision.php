<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Notifications\LeaveDecided;
use Illuminate\Support\Facades\DB;

/** D-57: tell the employee about the final decision, after the transaction commits. */
final class NotifyLeaveDecision
{
    public static function send(User $actor, LeaveRequest $request): void
    {
        DB::afterCommit(function () use ($actor, $request): void {
            $employee = User::query()->find($request->user_id);

            if ($employee !== null && ! $employee->isBanned() && $employee->anonymized_at === null) {
                $employee->notify(new LeaveDecided($request, $actor));
            }
        });
    }
}
