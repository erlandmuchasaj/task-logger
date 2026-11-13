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
 * Edits a log. Your own log while its month is open; any user's log in a locked month for people with
 * logs.edit_locked (month-close corrections, BR-07), recorded with before and after values. The rate is
 * copied again only when the date, project or technology changes; later rate changes never alter a log.
 */
final class UpdateLog
{
    use ValidatesLog;

    public function handle(User $actor, Log $log, LogData $data): Log
    {
        Gate::forUser($actor)->authorize('update', $log);

        if ($data->date->toDateString() !== $log->date->toDateString()) {
            Gate::forUser($actor)->authorize('createOn', [Log::class, $data->date]); // moving into a locked month needs the same right
        }

        $owner = $log->user;
        $target = $this->resolveTarget($owner, $data);
        $this->validateBasics($owner, $data, $target['project'], $log);

        $changesPricing = $data->date->toDateString() !== $log->date->toDateString()
            || $target['project']->getKey() !== $log->project_id
            || $target['technology_id'] !== $log->technology_id;
        $rate = $changesPricing ? $this->snapshotRate($target['project'], $target['technology_id'], $data) : [];

        $lockedBefore = TimesheetLocks::isLocked($log->date);
        $lockedAfter = TimesheetLocks::isLocked($data->date);
        $before = $log->only(['date', 'duration_minutes', 'log_type', 'description', 'project_id', 'task_id', 'technology_id', 'rate_minor', 'rate_currency']);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Log => DB::transaction(function () use ($owner, $log, $data, $target, $rate, $lockedBefore, $lockedAfter, $before): Log {
            User::query()->whereKey($owner->getKey())->lockForUpdate()->first();
            $this->validateBasics($owner, $data, $target['project'], $log);

            $log->update([
                'date' => $data->date->toDateString(),
                'project_id' => $target['project']->getKey(),
                'task_id' => $target['task']?->getKey(),
                'technology_id' => $target['technology_id'],
                'duration_minutes' => $data->minutes,
                'log_type' => $data->type,
                'description' => $data->description,
                ...$rate,
            ]);

            if ($lockedBefore || $lockedAfter) {
                activity()->performedOn($log)->event('locked_month_edit')
                    ->withChanges(['old' => $before, 'attributes' => $log->only(array_keys($before))])
                    ->withProperties(['month' => TimesheetLocks::monthOf($lockedBefore ? $before['date'] : $log->date)->format('Y-m'), 'owner_id' => $log->user_id])
                    ->log('locked_month_edit');
            }

            return $log->refresh();
        }));
    }
}
