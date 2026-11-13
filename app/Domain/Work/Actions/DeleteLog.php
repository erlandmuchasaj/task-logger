<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Support\TimesheetLocks;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** Soft-deletes a log: your own while the month is open, anyone's in a locked month with logs.edit_locked (BR-07). */
final class DeleteLog
{
    public function handle(User $actor, Log $log): void
    {
        Gate::forUser($actor)->authorize('delete', $log);

        $locked = TimesheetLocks::isLocked($log->date);
        $snapshot = $log->only(['date', 'duration_minutes', 'log_type', 'description', 'project_id', 'task_id', 'technology_id', 'rate_minor', 'rate_currency']);

        resolve(CauserResolver::class)->withCauser($actor, function () use ($log, $locked, $snapshot): void {
            $log->delete();

            if ($locked) {
                activity()->performedOn($log)->event('locked_month_delete')
                    ->withChanges(['old' => $snapshot])
                    ->withProperties(['month' => TimesheetLocks::monthOf($log->date)->format('Y-m'), 'owner_id' => $log->user_id])
                    ->log('locked_month_delete');
            }
        });
    }
}
