<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\TimesheetLock;
use App\Domain\Work\Support\TimesheetLocks;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** W-08 step 2. Perm timesheets.lock (superadmin). Locking a month again after an unlock reuses its row. */
final class LockMonth
{
    public function handle(User $actor, CarbonInterface $month): TimesheetLock
    {
        Gate::forUser($actor)->authorize('lock', TimesheetLock::class);

        $first = TimesheetLocks::monthOf($month)->toDateString();

        return resolve(CauserResolver::class)->withCauser($actor, function () use ($actor, $first): TimesheetLock {
            $lock = TimesheetLock::query()->whereDate('month', $first)->first() ?? new TimesheetLock(['month' => $first]);

            if ($lock->exists && $lock->isLocked()) {
                return $lock; // already locked: nothing to do
            }

            $lock->fill(['locked_by' => $actor->getKey(), 'locked_at' => now(), 'unlocked_by' => null, 'unlocked_at' => null])->save();

            return $lock;
        });
    }
}
