<?php

declare(strict_types=1);

use App\Domain\Exports\Actions\PruneExports;
use App\Domain\Leave\Actions\AutoApproveOverdueLeave;
use App\Domain\Leave\Actions\ExpireCarryOver;
use App\Domain\Leave\Actions\GrantAnnualLeave;
use App\Domain\Leave\Actions\MarkLeaveTaken;
use App\Domain\Leave\Actions\SendLeaveReminders;
use App\Domain\People\Actions\ActivateDueSalaries;
use App\Domain\People\Actions\AnonymizeDueEmployees;
use App\Domain\People\Actions\SendOffboardingThanks;
use App\Domain\Work\Actions\SendTaskDueReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// BR-18: hourly so 1 January is caught in every country's own timezone; each run is idempotent (NFR-13).
Schedule::call(fn () => resolve(GrantAnnualLeave::class)->handle())
    ->name('leave.grant')
    ->hourly()
    ->withoutOverlapping();

Schedule::call(fn () => resolve(ExpireCarryOver::class)->handle())
    ->name('leave.expire-carry-over')
    ->hourly()
    ->withoutOverlapping();

// BR-19: approved leave becomes taken after its end date.
Schedule::call(fn () => resolve(MarkLeaveTaken::class)->handle())
    ->name('leave.mark-taken')
    ->hourly()
    ->withoutOverlapping();

// D-72: remind the approver, then escalate to HR and the superadmins, about leave nobody has decided. Idempotent.
Schedule::call(fn () => resolve(SendLeaveReminders::class)->handle())
    ->name('leave.remind-pending')
    ->dailyAt((string) config('task-logger.leave_reminder.run_at'))
    ->timezone((string) config('task-logger.leave_reminder.timezone'))
    ->withoutOverlapping();

// D-84: leave nobody decided before its first day passed is approved, twice a day. Idempotent, and a run never overlaps the last.
Schedule::call(fn () => resolve(AutoApproveOverdueLeave::class)->handle())
    ->name('leave.auto-approve')
    ->cron(sprintf('0 %d,%d * * *', config('task-logger.leave_auto_approve.first_run_hour'), config('task-logger.leave_auto_approve.second_run_hour')))
    ->timezone((string) config('task-logger.leave_auto_approve.timezone'))
    ->withoutOverlapping();

Schedule::call(fn () => resolve(ActivateDueSalaries::class)->handle())
    ->name('salaries.activate-due')
    ->daily()
    ->withoutOverlapping();

Schedule::call(fn () => resolve(PruneExports::class)->handle())
    ->name('exports.prune')
    ->daily()
    ->withoutOverlapping();

// BR-28 / NFR-07: anonymise people whose completed offboarding is past its retention period. Idempotent.
Schedule::call(fn () => resolve(AnonymizeDueEmployees::class)->handle())
    ->name('people.anonymize')
    ->daily()
    ->withoutOverlapping();

// D-76: on a person's last working day, thank them by email, once. Idempotent.
Schedule::call(fn () => resolve(SendOffboardingThanks::class)->handle())
    ->name('people.offboarding-thanks')
    ->dailyAt((string) config('task-logger.offboarding_thanks.run_at'))
    ->timezone((string) config('task-logger.offboarding_thanks.timezone'))
    ->withoutOverlapping();

// D-81: remind the assignee the day before a task that is still being worked on is due, once. Idempotent.
Schedule::call(fn () => resolve(SendTaskDueReminders::class)->handle())
    ->name('work.task-due-reminders')
    ->dailyAt((string) config('task-logger.task_due_reminder.run_at'))
    ->timezone((string) config('task-logger.task_due_reminder.timezone'))
    ->withoutOverlapping();
