<?php

declare(strict_types=1);

namespace App\Filament\Resources\Logs\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\LeaveSpan;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Support\TimesheetLocks;
use App\Domain\Work\Support\WorkCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The month calendar on the Logs page: the signed-in person's own logs, their country's bank holidays and
 * their approved or taken leave for one month, from its first to its last day. It returns plain display
 * data, never models, so nothing such as a rate snapshot can reach the browser (D-05).
 *
 * @phpstan-type CalendarEntry array{id: int, label: string, duration: string, canEdit: bool}
 * @phpstan-type CalendarDay array{
 *     date: string, label: string, day: int, isToday: bool, isFuture: bool, isWorkingDay: bool, canLog: bool,
 *     isMissing: bool, holidays: list<string>, leave: list<string>, totalMinutes: int, total: string,
 *     entries: list<CalendarEntry>,
 * }
 * @phpstan-type CalendarMonth array{
 *     first: CarbonImmutable, label: string, totalMinutes: int, total: string, locked: bool, blocked: bool,
 *     weeks: list<list<CalendarDay|null>>,
 * }
 * @phpstan-type DayContext array{
 *     workingDays: list<int>, holidays: array<string, list<string>>,
 *     leave: array<string, list<array{label: string, full: bool}>>,
 * }
 */
final class LogCalendar
{
    /** Months outside 2000 to 2099 are refused; the month comes from the browser (URL and Livewire state). */
    public static function parseMonth(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/D', $value) !== 1) {
            return null;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $value, 'UTC');

        return $month instanceof CarbonImmutable ? $month : null;
    }

    /** A real calendar date as `Y-m-d`; `2026-02-30` is refused instead of rolling over to March. */
    public static function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * May this person add a log of their own on that date? Not for a future date (their own calendar day,
     * BR-34), and not where the policy says no: no logs.create_own, or a locked month without logs.edit_locked.
     */
    public static function canLogOn(User $user, CarbonImmutable $date): bool
    {
        return self::allowsLogging($user, $date, WorkCalendar::today($user)->toDateString());
    }

    /**
     * One month, Monday first. Cells outside the month are null so the grid lines up and no data from the
     * neighbouring months is fetched or shown.
     *
     * @return CalendarMonth
     */
    public static function month(User $user, CarbonImmutable $first): array
    {
        $first = $first->startOfMonth();
        $firstDate = $first->toDateString();
        $lastDate = $first->endOfMonth()->toDateString();
        $today = WorkCalendar::today($user)->toDateString();
        $startDate = $user->start_date?->toDateString();
        $context = self::context($user, $firstDate, $lastDate);

        // The user's own logs only, whatever logs.view_any says: the calendar is a personal view.
        /** @var array<string, list<Log>> $logsByDate */
        $logsByDate = [];
        // Projects are soft-deleted: a log keeps showing the name of a project that was archived since.
        $logs = Log::query()->with(['project' => fn (BelongsTo $project) => $project->withTrashed(), 'task'])
            ->where('user_id', $user->getKey())
            ->whereBetween('date', [$firstDate, $lastDate])
            ->orderBy('date')->orderBy('id')
            ->get();

        foreach ($logs as $log) {
            $logsByDate[$log->date->toDateString()][] = $log;
        }

        $cells = array_fill(0, $first->dayOfWeekIso - 1, null);
        $monthMinutes = 0;

        for ($day = $first; $day->toDateString() <= $lastDate; $day = $day->addDay()) {
            $date = $day->toDateString();
            $dayLogs = $logsByDate[$date] ?? [];
            $minutes = array_sum(array_map(fn (Log $log): int => $log->duration_minutes, $dayLogs));
            $monthMinutes += $minutes;

            $isWorkingDay = in_array($day->dayOfWeekIso, $context['workingDays'], true);
            $hasFullLeave = array_filter($context['leave'][$date] ?? [], fn (array $leave): bool => $leave['full']) !== [];
            $isHoliday = isset($context['holidays'][$date]);

            $cells[] = [
                'date' => $date,
                'label' => $day->format('j F Y'),
                'day' => $day->day,
                'isToday' => $date === $today,
                'isFuture' => $date > $today,
                'isWorkingDay' => $isWorkingDay,
                'canLog' => self::allowsLogging($user, $day, $today),
                // A nudge, not a rule: a past working day with nothing logged, nothing to excuse it, and not before they started.
                'isMissing' => $isWorkingDay && ! $isHoliday && ! $hasFullLeave && $date < $today
                    && $dayLogs === [] && ($startDate === null || $date >= $startDate),
                'holidays' => $context['holidays'][$date] ?? [],
                'leave' => array_map(fn (array $leave): string => $leave['label'], $context['leave'][$date] ?? []),
                'totalMinutes' => $minutes,
                'total' => self::format($minutes),
                'entries' => array_map(fn (Log $log): array => [
                    'id' => (int) $log->getKey(),
                    'label' => self::entryLabel($log),
                    'duration' => $log->duration(),
                    'canEdit' => $user->can('update', $log),
                ], $dayLogs),
            ];
        }

        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        $locked = TimesheetLocks::isLocked($first);

        return [
            'first' => $first,
            'label' => $first->format('F Y'),
            'totalMinutes' => $monthMinutes,
            'total' => self::format($monthMinutes),
            'locked' => $locked,
            'blocked' => $locked && ! $user->can(Permissions::LOGS_EDIT_LOCKED),
            'weeks' => array_chunk($cells, 7),
        ];
    }

    /**
     * The warning shown in the log modal when the chosen date is a bank holiday, a day off or leave. It takes
     * the raw date field state, which may carry a time part.
     */
    public static function notice(User $user, mixed $dateState): ?string
    {
        $raw = match (true) {
            $dateState instanceof DateTimeInterface => $dateState->format('Y-m-d'),
            is_string($dateState) => mb_substr($dateState, 0, 10),
            default => null,
        };

        $day = self::parseDate($raw);

        if (! $day instanceof CarbonImmutable) {
            return null;
        }

        $date = $day->toDateString();
        $context = self::context($user, $date, $date);
        $name = $day->format('j F Y');

        return match (true) {
            isset($context['holidays'][$date]) => $name.' is a bank holiday ('.implode(', ', $context['holidays'][$date]).').',
            ! in_array($day->dayOfWeekIso, $context['workingDays'], true) => $name.' is not one of your working days.',
            isset($context['leave'][$date]) => 'You are on leave on '.$name.' ('.implode(', ', array_map(fn (array $leave): string => $leave['label'], $context['leave'][$date])).').',
            default => null,
        };
    }

    private static function allowsLogging(User $user, CarbonImmutable $date, string $today): bool
    {
        return $date->toDateString() <= $today && $user->can('createOn', [Log::class, $date]);
    }

    /**
     * Working week, bank holidays and leave between two dates, inclusive. Leave is listed only on days it is
     * charged: a working day that is not a bank holiday (BR-16, BR-17), so a Friday to Monday request does not
     * paint the weekend.
     *
     * @return DayContext
     */
    private static function context(User $user, string $from, string $to): array
    {
        $country = $user->country_id !== null ? Country::query()->find($user->country_id) : null;
        $workingDays = $country instanceof Country ? $country->working_days : Country::defaultWorkingDays();

        /** @var array<string, list<string>> $holidays */
        $holidays = [];

        // Only the person's own country: a calendar of days that matter to them.
        if ($user->country_id !== null) {
            BankHoliday::query()
                ->where('country_id', $user->country_id)
                ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
                ->oldest('start_date')->orderBy('id')
                ->get(['name', 'start_date', 'end_date'])
                ->each(function (BankHoliday $holiday) use (&$holidays, $from, $to): void {
                    $end = min($holiday->end_date->toDateString(), $to);

                    for ($day = CarbonImmutable::parse(max($holiday->start_date->toDateString(), $from)); $day->toDateString() <= $end; $day = $day->addDay()) {
                        $holidays[$day->toDateString()][] = $holiday->name;
                    }
                });
        }

        /** @var array<string, list<array{label: string, full: bool}>> $leave */
        $leave = [];

        $requests = LeaveRequest::query()->with('leaveType')
            ->where('user_id', $user->getKey())
            ->whereIn('status', [LeaveStatus::Approved, LeaveStatus::Taken])
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->oldest('start_date')->orderBy('id')
            ->get();

        foreach ($requests as $request) {
            // The half days the request occupies, from the same code that checks for overlaps.
            $slots = new LeaveSpan($request->start_date, $request->end_date, $request->start_day_part, $request->end_day_part)->slots();

            /** @var array<string, list<string>> $parts */
            $parts = [];

            foreach ($slots as $slot) {
                [$date, $part] = explode(':', $slot, 2);

                if ($date >= $from && $date <= $to) {
                    $parts[$date][] = $part;
                }
            }

            foreach ($parts as $date => $dayParts) {
                $isCharged = in_array(CarbonImmutable::parse($date)->dayOfWeekIso, $workingDays, true) && ! isset($holidays[$date]);

                if (! $isCharged) {
                    continue;
                }

                $full = count($dayParts) === 2;
                $leave[$date][] = [
                    'label' => ($request->leaveType?->label() ?? 'Leave').($full ? '' : ' ('.mb_strtoupper($dayParts[0]).')'),
                    'full' => $full,
                ];
            }
        }

        return ['workingDays' => $workingDays, 'holidays' => $holidays, 'leave' => $leave];
    }

    /** "Project · Task", or "Project (direct)" for a log without a task. */
    private static function entryLabel(Log $log): string
    {
        $project = $log->project->name;

        return $log->isDirect() ? $project.' (direct)' : $project.' · '.$log->task->name;
    }

    /** "7:30", the same shape as Log::duration(). */
    private static function format(int $minutes): string
    {
        return intdiv($minutes, 60).':'.mb_str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
