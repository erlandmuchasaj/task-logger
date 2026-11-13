<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Work\Support\WorkCalendar;

/** BR-19: an approved request becomes taken once its end date has passed in the employee's country. Idempotent. */
final class MarkLeaveTaken
{
    /** @return int requests moved to taken */
    public function handle(): int
    {
        $moved = 0;

        LeaveRequest::query()
            ->where('status', LeaveStatus::Approved)
            ->whereDate('end_date', '<=', now()->addDay()->toDateString())
            ->with('user.country')
            ->each(function (LeaveRequest $request) use (&$moved): void {
                if (WorkCalendar::today($request->user)->greaterThan($request->end_date)) {
                    $request->update(['status' => LeaveStatus::Taken]);
                    $moved++;
                }
            });

        return $moved;
    }
}
