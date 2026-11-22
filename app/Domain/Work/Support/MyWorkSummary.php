<?php

declare(strict_types=1);

namespace App\Domain\Work\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * D-78: the figures on a person's own dashboard. Every query is scoped to the given user and counts in integers
 * (tasks, minutes); "today" is the person's own day (BR-34), and a week runs Monday to Sunday.
 */
final class MyWorkSummary
{
    /** Assigned to the person and not completed or cancelled; deleted tasks are left out by the model. */
    public static function activeTasks(User $user): int
    {
        return Task::query()
            ->where('assignee_id', $user->getKey())
            ->whereNotIn('status', self::closedStatuses())
            ->count();
    }

    public static function minutesThisMonth(User $user): int
    {
        $today = WorkCalendar::today($user);

        return (int) Log::query()
            ->where('user_id', $user->getKey())
            ->whereBetween('date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])
            ->sum('duration_minutes');
    }

    /**
     * Minutes logged on each day of the current week, Monday first. Days with no log are present with 0.
     *
     * @return array<string, int> date (Y-m-d) => minutes
     */
    public static function minutesPerDayThisWeek(User $user): array
    {
        $monday = self::weekStart($user);
        $sunday = $monday->addDays(6);

        $logged = Log::query()
            ->where('user_id', $user->getKey())
            ->whereBetween('date', [$monday->toDateString(), $sunday->toDateString()])
            ->groupBy('date')
            ->selectRaw('date, sum(duration_minutes) as minutes')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [mb_substr((string) $row->date, 0, 10) => (int) $row->minutes]);

        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $monday->addDays($i)->toDateString();
            $days[$date] = (int) ($logged[$date] ?? 0);
        }

        return $days;
    }

    /**
     * Tasks created this week and assigned to the person, newest first (D-78). A task has no "assigned on" date, so
     * its creation is the moment it reached the person. The week is the person's own, so its edges are converted
     * from their calendar to the UTC the `created_at` column holds.
     *
     * @return Collection<int, Task>
     */
    public static function assignedThisWeek(User $user): Collection
    {
        $timezone = WorkCalendar::timezone($user);
        $monday = self::weekStart($user);

        return Task::query()
            ->with('project:id,name')
            ->where('assignee_id', $user->getKey())
            ->where('created_at', '>=', $monday->shiftTimezone($timezone)->utc())
            ->where('created_at', '<', $monday->addDays(7)->shiftTimezone($timezone)->utc())
            ->latest()
            ->get(['id', 'name', 'project_id', 'status', 'created_at']);
    }

    public static function weekStart(User $user): CarbonImmutable
    {
        return WorkCalendar::today($user)->startOfWeek(CarbonInterface::MONDAY);
    }

    /** "7:30", the same shape as Log::duration(). */
    public static function format(int $minutes): string
    {
        return intdiv($minutes, 60).':'.mb_str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** @return list<TaskStatus> */
    private static function closedStatuses(): array
    {
        return array_values(array_filter(TaskStatus::cases(), fn (TaskStatus $status): bool => $status->isClosed()));
    }
}
