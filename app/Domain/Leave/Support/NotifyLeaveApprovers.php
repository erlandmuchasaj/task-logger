<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LeaveAlert;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Notifications\LeaveNeedsAttention;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** D-70: tell approvers and HR about a pending request, at once and after the transaction commits (never for a deactivated or anonymised person). */
final class NotifyLeaveApprovers
{
    /** @param  Collection<int, User>  $recipients */
    public static function send(LeaveRequest $request, LeaveAlert $alert, Collection $recipients): void
    {
        DB::afterCommit(function () use ($request, $alert, $recipients): void {
            foreach ($recipients as $recipient) {
                if (! $recipient->isBanned() && $recipient->anonymized_at === null) {
                    $recipient->notify(new LeaveNeedsAttention($request, $alert));
                }
            }
        });
    }
}
