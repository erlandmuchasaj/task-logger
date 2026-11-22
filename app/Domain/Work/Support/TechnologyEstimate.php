<?php

declare(strict_types=1);

namespace App\Domain\Work\Support;

use App\Domain\Clients\Models\ProjectTechnology;
use App\Domain\Reports\Data\EstimateLine;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * D-82: do the estimated hours of a project's tasks of one technology add up to more than the project's own estimate for
 * that technology? Only a warning for the person saving a task; nothing is refused. Hours are summed in hundredths, as
 * integers. Cancelled and deleted tasks do not count, and sub-tasks count in full.
 */
final class TechnologyEstimate
{
    /**
     * The totals in hundredths of an hour when the tasks exceed the estimate, otherwise null. $taskEstimate is the
     * estimate of the task being saved ($exceptTaskId leaves its stored row out so an edit is not counted twice);
     * a value that is not a plain decimal of at most two digits gives null, as the save will refuse it anyway.
     *
     * @return array{planned: int, estimated: int, technology: string}|null
     */
    public static function overrun(int $projectId, int $technologyId, mixed $taskEstimate, ?int $exceptTaskId = null, mixed $status = null): ?array
    {
        $estimate = is_scalar($taskEstimate) ? mb_trim((string) $taskEstimate) : '';

        if ($estimate === '') {
            $estimate = '0';
        }

        if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $estimate) !== 1) {
            return null;
        }

        $line = ProjectTechnology::query()->where('project_id', $projectId)->where('technology_id', $technologyId)->with('technology')->first();

        if (! $line instanceof ProjectTechnology) {
            return null;
        }

        $others = (int) Task::query()
            ->where('project_id', $projectId)
            ->where('technology_id', $technologyId)
            ->where('status', '!=', TaskStatus::Cancelled)
            ->when($exceptTaskId !== null, fn ($query) => $query->whereKeyNot($exceptTaskId))
            ->sum(DB::raw('ROUND(estimated_hours * 100)'));

        $status = $status instanceof TaskStatus ? $status : TaskStatus::tryFrom(is_string($status) ? $status : '');
        $own = $status === TaskStatus::Cancelled ? 0 : EstimateLine::hundredthsOf($estimate);
        $planned = $others + $own;
        $estimated = EstimateLine::hundredthsOf((string) $line->estimated_hours);

        return $planned > $estimated ? ['planned' => $planned, 'estimated' => $estimated, 'technology' => $line->technology->name] : null;
    }

    /** The sentence shown to the person saving the task, or null when there is nothing to warn about. */
    public static function notice(int $projectId, int $technologyId, mixed $taskEstimate, ?int $exceptTaskId = null, mixed $status = null): ?string
    {
        $overrun = self::overrun($projectId, $technologyId, $taskEstimate, $exceptTaskId, $status);

        if ($overrun === null) {
            return null;
        }

        return 'The tasks on '.$overrun['technology'].' now add up to '.EstimateLine::hours($overrun['planned'])
            .' h, more than the '.EstimateLine::hours($overrun['estimated']).' h estimated for it on the project. The task can still be saved.';
    }
}
