<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Leave\Enums\LeaveAlert;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\LeaveApprovers;
use App\Domain\Leave\Support\NotifyLeaveApprovers;
use App\Domain\Work\Support\WorkCalendar;

/**
 * D-72, the daily job for requests nobody has decided. It only sends messages and never changes the status
 * (approving what is still pending after the first day is the separate job of D-84). N days before the first day
 * (task-logger.leave_reminder.days_before) whoever still has a stage to decide gets one reminder (D-87); on the first day HR and the superadmins are told once. Both are claimed with a conditional update before sending, so a
 * second run, or two at once, sends nothing twice. After the first day the request shows as overdue (LeaveRequest::isOverdue).
 */
final class SendLeaveReminders
{
    /** @return array{reminded: int, escalated: int} */
    public function handle(): array
    {
        $daysBefore = (int) config('task-logger.leave_reminder.days_before');
        $counts = ['reminded' => 0, 'escalated' => 0];

        LeaveRequest::query()
            ->where('status', LeaveStatus::Pending)
            ->whereDate('start_date', '<=', now()->addDays($daysBefore + 1)->toDateString())
            ->where(fn ($query) => $query->whereNull('reminder_sent_at')->orWhereNull('escalated_at'))
            ->with(['user.country', 'leaveType', 'approvals'])
            ->each(function (LeaveRequest $request) use ($daysBefore, &$counts): void {
                $today = WorkCalendar::today($request->user);

                if ($request->hasStarted($today)) {
                    if ($request->escalated_at === null && $this->claim($request, 'escalated_at')) {
                        NotifyLeaveApprovers::send($request, LeaveAlert::Escalated, LeaveApprovers::hrAndSuperadminsOf($request->user));
                        $counts['escalated']++;
                    }

                    return;
                }

                $remindFrom = $request->start_date->subDays($daysBefore)->toDateString();

                if ($request->reminder_sent_at === null && $today->toDateString() >= $remindFrom && $this->claim($request, 'reminder_sent_at')) {
                    NotifyLeaveApprovers::send($request, LeaveAlert::Reminder, LeaveApprovers::reminderRecipients($request));
                    $counts['reminded']++;
                }
            });

        return $counts;
    }

    /** True for exactly one caller: the one whose update changed the row from "not sent" to "sent". */
    private function claim(LeaveRequest $request, string $column): bool
    {
        return LeaveRequest::query()
            ->whereKey($request->getKey())
            ->where('status', LeaveStatus::Pending)
            ->whereNull($column)
            ->update([$column => now()]) === 1;
    }
}
