<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\NotifyLeaveDecision;
use App\Domain\Work\Support\WorkCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-84, the twice-daily job for leave nobody decided in time. Once the first day has passed in the employee's own
 * calendar (the "Overdue" state of D-72) a pending request becomes approved, whatever stage it was waiting at. The
 * balance rule (BR-24) still holds: a request that no longer fits the balance stays pending for HR to decide.
 * Each request has its own transaction that locks the employee, then re-reads the request, so HR deciding at the
 * same moment wins and a second run does nothing. The approval writes no stage rows and no reviewer, which is how
 * `LeaveRequest::wasAutoApproved()` tells it apart. Deactivated and anonymised people are skipped.
 */
final class AutoApproveOverdueLeave
{
    use ValidatesLeave;

    public const string NOTE = 'Approved automatically: nobody decided before the first day had passed.';

    /** @return array{approved: int, skipped: int} skipped = left pending because the balance no longer covers it */
    public function handle(): array
    {
        $counts = ['approved' => 0, 'skipped' => 0];

        if (! (bool) config('task-logger.leave_auto_approve.enabled')) {
            return $counts;
        }

        LeaveRequest::query()
            ->where('status', LeaveStatus::Pending)
            ->whereDate('start_date', '<=', now()->toDateString())
            ->with('user.country')
            ->eachById(function (LeaveRequest $request) use (&$counts): void {
                $employee = $request->user;

                if ($employee->isBanned() || $employee->anonymized_at !== null) {
                    return;
                }

                if (! $request->isOverdue(WorkCalendar::today($employee))) {
                    return;
                }

                try {
                    $approved = DB::transaction(fn (): bool => $this->approve($request));
                } catch (ValidationException) {
                    $counts['skipped']++;

                    return;
                }

                if ($approved) {
                    $counts['approved']++;
                }
            });

        return $counts;
    }

    /** @throws ValidationException when the balance no longer covers the request */
    private function approve(LeaveRequest $request): bool
    {
        $employee = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
        $request = LeaveRequest::query()->with('leaveType')->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        if ($request->status !== LeaveStatus::Pending) {
            return false;
        }

        $this->writeDeduction($request, $employee, $request->leaveType, null);

        $request->update([
            'status' => LeaveStatus::Approved,
            'reviewed_by' => null,
            'reviewed_at' => now(),
            'review_note' => self::NOTE,
        ]);

        NotifyLeaveDecision::send(null, $request->refresh());

        return true;
    }
}
