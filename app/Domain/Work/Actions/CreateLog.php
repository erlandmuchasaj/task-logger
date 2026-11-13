<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Data\LogData;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Support\TimesheetLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-03 step 3 and W-04. A person logs their own time; no approval (BR-01). Needs logs.create_own, and
 * for a locked month also logs.edit_locked (BR-07). The client's rate is copied onto the log (BR-06).
 */
final class CreateLog
{
    use ValidatesLog;

    public function handle(User $actor, LogData $data): Log
    {
        Gate::forUser($actor)->authorize('createOn', [Log::class, $data->date]);

        $target = $this->resolveTarget($actor, $data);
        $this->validateBasics($actor, $data, $target['project'], null);
        $rate = $this->snapshotRate($target['project'], $target['technology_id'], $data);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Log => DB::transaction(function () use ($actor, $data, $target, $rate): Log {
            // Serialise this person's logging so the 24 hour cap holds under concurrent requests.
            User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            $this->validateBasics($actor, $data, $target['project'], null);

            $log = Log::query()->create([
                'user_id' => $actor->getKey(),
                'date' => $data->date->toDateString(),
                'project_id' => $target['project']->getKey(),
                'task_id' => $target['task']?->getKey(),
                'technology_id' => $target['technology_id'],
                'duration_minutes' => $data->minutes,
                'log_type' => $data->type,
                'description' => $data->description,
                ...$rate,
            ]);

            if (TimesheetLocks::isLocked($data->date)) {
                activity()->performedOn($log)->event('locked_month_create')
                    ->withChanges(['attributes' => $log->only(['date', 'duration_minutes', 'log_type', 'project_id', 'task_id'])])
                    ->withProperties(['month' => TimesheetLocks::monthOf($data->date)->format('Y-m')])
                    ->log('locked_month_create');
            }

            return $log;
        }));
    }
}
