<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\TimesheetLock;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** D-13: the superadmin can unlock a month; it is logged (who and when are stored on the lock and in the activity log). */
final class UnlockMonth
{
    public function handle(User $actor, TimesheetLock $lock): TimesheetLock
    {
        Gate::forUser($actor)->authorize('lock', TimesheetLock::class);

        if (! $lock->isLocked()) {
            return $lock;
        }

        return resolve(CauserResolver::class)->withCauser($actor, function () use ($actor, $lock): TimesheetLock {
            $lock->update(['unlocked_by' => $actor->getKey(), 'unlocked_at' => now()]);

            return $lock;
        });
    }
}
