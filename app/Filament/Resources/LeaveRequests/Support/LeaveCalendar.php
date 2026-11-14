<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\Leave\Support\LeaveSpan;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\Work\Support\WorkCalendar;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\CalendarInput;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The month calendar on the Leave Requests page: bank holidays and leave in one grid (FR-34, D-44). Everybody
 * sees everybody's approved and taken leave; pending leave only where the leave request policy lets the viewer
 * see the request. It returns plain display data, never models, so nothing the viewer may not read (a colleague's
 * leave type or reason) can reach the browser.
 *
 * A request is shown on the days it is charged, per person: their country's working days that are not a bank
 * holiday there (BR-16, BR-17), so a Friday to Monday request does not paint the weekend.
 *
 * @phpstan-type Chip array{id: int|null, label: string, title: string, pending: bool, mine: bool}
 * @phpstan-type CalendarDay array{
 *     date: string, label: string, day: int, isToday: bool, isWorkingDay: bool,
 *     holidays: list<string>, leave: list<Chip>,
 * }
 * @phpstan-type AgendaRow array{kind: 'holiday'|'leave', id: int|null, label: string, dates: string, pending: bool, mine: bool}
 * @phpstan-type CalendarMonth array{
 *     first: CarbonImmutable, label: string, canRequest: bool,
 *     weeks: list<list<CalendarDay|null>>, agenda: list<AgendaRow>,
 * }
 */
final class LeaveCalendar
{
    /**
     * One month, Monday first. Cells outside the month are null so the grid lines up and no data from the
     * neighbouring months is fetched or shown. `$onlyMine` keeps the viewer's own leave and own country's holidays.
     *
     * @return CalendarMonth
     */
    public static function month(User $viewer, CarbonImmutable $first, bool $onlyMine = false): array
    {
        $first = $first->startOfMonth();
        $firstDate = $first->toDateString();
        $lastDate = $first->endOfMonth()->toDateString();
        $today = WorkCalendar::today($viewer)->toDateString();
        $seesEveryCountry = ! $onlyMine && $viewer->can(Permissions::LEAVE_REQUESTS_VIEW_ANY);
        $viewerWorkingDays = $viewer->country instanceof Country ? $viewer->country->working_days : Country::defaultWorkingDays();

        $requests = self::requests($viewer, $firstDate, $lastDate, $onlyMine);
        $holidays = self::holidays($viewer, $requests, $firstDate, $lastDate, $seesEveryCountry);
        $viewable = self::viewableIds($viewer, $requests);

        /** @var array<int, array<string, true>> $holidayDatesByCountry every holiday found, to know which days are charged */
        $holidayDatesByCountry = [];
        /** @var array<string, list<string>> $holidaysByDate only the holidays the viewer is shown */
        $holidaysByDate = [];
        /** @var list<array{kind: 'holiday'|'leave', id: int|null, label: string, dates: string, pending: bool, mine: bool, sort: string}> $agenda */
        $agenda = [];

        foreach ($holidays as $holiday) {
            $days = self::holidayDays($holiday, $firstDate, $lastDate);

            foreach ($days as $date) {
                $holidayDatesByCountry[$holiday->country_id][$date] = true;
            }

            if (! $seesEveryCountry && $holiday->country_id !== $viewer->country_id) {
                continue;
            }

            $label = $holiday->name.($seesEveryCountry ? ' ('.$holiday->country?->code.')' : '');

            foreach ($days as $date) {
                $holidaysByDate[$date][] = $label;
            }

            $agenda[] = [
                'kind' => 'holiday', 'id' => null, 'label' => $label, 'pending' => false, 'mine' => false,
                'dates' => self::dates($holiday->start_date, $holiday->end_date),
                'sort' => $holiday->start_date->toDateString().'0',
            ];
        }

        /** @var array<string, list<array{sort: array{int, string, int}, chip: Chip}>> $chipsByDate */
        $chipsByDate = [];

        foreach ($requests as $request) {
            $person = $request->user;
            $country = $person->country;
            $workingDays = $country instanceof Country ? $country->working_days : Country::defaultWorkingDays();
            $holidayDates = $person->country_id !== null ? ($holidayDatesByCountry[$person->country_id] ?? []) : [];
            $span = new LeaveSpan($request->start_date, $request->end_date, $request->start_day_part, $request->end_day_part);
            $mine = $request->user_id === $viewer->getKey();
            $canView = isset($viewable[(int) $request->getKey()]);
            $name = $person->getFilamentName();
            $painted = false;

            foreach ($span->chargedParts($workingDays, $holidayDates) as $date => $parts) {
                if ($date < $firstDate || $date > $lastDate) {
                    continue;
                }

                $painted = true;
                $marker = count($parts) === 2 ? '' : ' ('.mb_strtoupper($parts[0]).')';
                $label = $name.($canView ? ' · '.($request->leaveType?->label() ?? 'Leave') : '').$marker;

                $chipsByDate[$date][] = [
                    'sort' => [$mine ? 0 : 1, mb_strtolower($name), (int) $request->getKey()],
                    'chip' => [
                        'id' => $canView ? (int) $request->getKey() : null,
                        'label' => $label,
                        'title' => $label.' · '.$request->status->getLabel(),
                        'pending' => $request->status === LeaveStatus::Pending,
                        'mine' => $mine,
                    ],
                ];
            }

            if ($painted) {
                $agenda[] = [
                    'kind' => 'leave', 'id' => $canView ? (int) $request->getKey() : null, 'pending' => $request->status === LeaveStatus::Pending, 'mine' => $mine,
                    'label' => $name.($canView ? ' · '.($request->leaveType?->label() ?? 'Leave') : '').' · '.$request->status->getLabel(),
                    'dates' => self::dates($request->start_date, $request->end_date, $span->isSingleDay() ? $request->start_day_part : null),
                    'sort' => $request->start_date->toDateString().'1',
                ];
            }
        }

        $cells = array_fill(0, $first->dayOfWeekIso - 1, null);

        for ($day = $first; $day->toDateString() <= $lastDate; $day = $day->addDay()) {
            $date = $day->toDateString();
            $dayChips = $chipsByDate[$date] ?? [];
            usort($dayChips, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            $cells[] = [
                'date' => $date,
                'label' => $day->format('j F Y'),
                'day' => $day->day,
                'isToday' => $date === $today,
                'isWorkingDay' => in_array($day->dayOfWeekIso, $viewerWorkingDays, true),
                'holidays' => $holidaysByDate[$date] ?? [],
                'leave' => array_map(fn (array $entry): array => $entry['chip'], $dayChips),
            ];
        }

        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        usort($agenda, fn (array $a, array $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['label'], $b['label']));

        return [
            'first' => $first,
            'label' => $first->format('F Y'),
            'canRequest' => $viewer->can('create', LeaveRequest::class),
            'weeks' => array_chunk($cells, 7),
            'agenda' => array_map(fn (array $row): array => [
                'kind' => $row['kind'], 'id' => $row['id'], 'label' => $row['label'], 'dates' => $row['dates'], 'pending' => $row['pending'], 'mine' => $row['mine'],
            ], $agenda),
        ];
    }

    /**
     * The warning shown in the request modal for the chosen dates, or null when there is nothing to say. It takes
     * the raw form state, which may carry a time part or be half filled. It repeats what the server would refuse or
     * what is worth a second look; the server still decides (RequestLeave).
     */
    public static function notice(User $user, mixed $start, mixed $end, mixed $startPart = null, mixed $endPart = null): ?string
    {
        $span = self::span($start, $end, $startPart, $endPart);

        if (! $span instanceof LeaveSpan) {
            return null;
        }

        $country = $user->country;

        if (! $country instanceof Country) {
            return 'You have no country, so working days and holidays are unknown and this request would be refused.';
        }

        $from = $span->start->toDateString();
        $to = $span->end->toDateString();

        $holidays = BankHoliday::query()
            ->where('country_id', $country->getKey())
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->oldest('start_date')->orderBy('id')
            ->get(['name', 'start_date', 'end_date']);

        $holidayDates = [];

        foreach ($holidays as $holiday) {
            foreach (self::holidayDays($holiday, $from, $to) as $date) {
                $holidayDates[$date] = true;
            }
        }

        $notices = [];

        if ($span->chargedParts($country->working_days, $holidayDates) === []) {
            $notices[] = 'The selected dates contain no working days, so this request would be refused.';
        } else {
            // Only the first and last day: a weekend inside a longer request is normal and is simply not charged.
            foreach (array_unique([$from, $to]) as $date) {
                $day = CarbonImmutable::parse($date);

                if (! $country->isWorkingDay($day->dayOfWeekIso)) {
                    $notices[] = $day->format('j F Y').' is not one of your working days.';
                }
            }
        }

        if ($holidays->isNotEmpty()) {
            $notices[] = 'Bank holidays are not charged: '.$holidays->pluck('name')->unique()->implode(', ').'.';
        }

        // The same half-day check as ValidatesLeave::assertNoOverlap(), so a Saturday inside a Friday to Monday request counts.
        $clash = LeaveRequest::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved, LeaveStatus::Taken])
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->get()
            ->first(fn (LeaveRequest $other): bool => $span->overlaps(
                new LeaveSpan($other->start_date, $other->end_date, $other->start_day_part, $other->end_day_part)
            ));

        if ($clash instanceof LeaveRequest) {
            $notices[] = 'You already have leave in that period ('.self::dates($clash->start_date, $clash->end_date).', '.mb_strtolower($clash->status->getLabel()).').';
        }

        return $notices === [] ? null : implode(' ', $notices);
    }

    /**
     * "Uses 3.0 days · 12.5 available (Annual Leave)": what the chosen dates cost and what the person has left,
     * from the same code that validates the request. Null while the dates are not valid or cost nothing.
     */
    public static function summary(User $user, mixed $leaveTypeId, mixed $start, mixed $end, mixed $startPart = null, mixed $endPart = null): ?string
    {
        $span = self::span($start, $end, $startPart, $endPart);
        $country = $user->country;

        if (! $span instanceof LeaveSpan || ! $country instanceof Country) {
            return null;
        }

        $tenths = $span->chargedTenths($country);

        if ($tenths <= 0) {
            return null;
        }

        $summary = 'Uses '.Days::format($tenths).' days';
        $type = is_numeric($leaveTypeId) ? LeaveType::query()->find((int) $leaveTypeId) : null;

        if ($type instanceof LeaveType && $type->affects_balance) {
            $available = LeaveBalance::available($user, (int) $type->getKey());
            $summary .= ' · '.Days::format(max($available, 0)).' available ('.$type->label().')'.($tenths > $available ? ', not enough balance' : '');
        }

        return $summary;
    }

    /**
     * Approved and taken leave of everybody, and pending leave the viewer may see (D-44), for the dates between
     * `$from` and `$to`. A request whose person was deleted is left out.
     *
     * @return Collection<int, LeaveRequest>
     */
    private static function requests(User $viewer, string $from, string $to, bool $onlyMine): Collection
    {
        $visiblePending = LeaveRequestResource::visibleTo($viewer, LeaveRequest::query())
            ->where('status', LeaveStatus::Pending)->select('leave_requests.id');

        return LeaveRequest::query()->with(['user.country', 'leaveType'])
            ->where(fn (Builder $query) => $query->whereIn('status', [LeaveStatus::Approved, LeaveStatus::Taken])->orWhereIn('id', $visiblePending))
            ->when($onlyMine, fn (Builder $query) => $query->where('user_id', $viewer->getKey()))
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->oldest('start_date')->orderBy('id')
            ->get()
            ->filter(fn (LeaveRequest $request): bool => $request->getRelation('user') instanceof User)
            ->values();
    }

    /**
     * The holidays of the days shown: the viewer's own country and the countries of the people on the calendar
     * (their charged days depend on them), or every country for people who see all leave.
     *
     * @param  Collection<int, LeaveRequest>  $requests
     * @return Collection<int, BankHoliday>
     */
    private static function holidays(User $viewer, Collection $requests, string $from, string $to, bool $seesEveryCountry): Collection
    {
        $countryIds = $requests->toBase()->map(fn (LeaveRequest $request): ?int => $request->user->country_id)
            ->push($viewer->country_id)->filter()->unique()->values()->all();

        return BankHoliday::query()
            ->when($seesEveryCountry, fn (Builder $query) => $query->with('country'))
            ->unless($seesEveryCountry, fn (Builder $query) => $query->whereIn('country_id', $countryIds))
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->oldest('start_date')->orderBy('id')
            ->get();
    }

    /**
     * Which of these requests the viewer may open: the same rule as the table and the policy's `view`.
     *
     * @param  Collection<int, LeaveRequest>  $requests
     * @return array<int, true>
     */
    private static function viewableIds(User $viewer, Collection $requests): array
    {
        if ($requests->isEmpty()) {
            return [];
        }

        return array_fill_keys(
            LeaveRequestResource::visibleTo($viewer, LeaveRequest::query())->whereKey($requests->modelKeys())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            true,
        );
    }

    /**
     * The days of a holiday that fall between two dates, inclusive.
     *
     * @return list<string>
     */
    private static function holidayDays(BankHoliday $holiday, string $from, string $to): array
    {
        $days = [];
        $end = min($holiday->end_date->toDateString(), $to);

        for ($day = CarbonImmutable::parse(max($holiday->start_date->toDateString(), $from)); $day->toDateString() <= $end; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /** "3 Jun", "8 Jun - 9 Jun" or, for one half day, "3 Jun (AM)". */
    private static function dates(CarbonInterface $start, CarbonInterface $end, ?DayPart $part = null): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('j M').($part instanceof DayPart && $part !== DayPart::Full ? ' ('.mb_strtoupper($part->value).')' : '');
        }

        return $start->format('j M').' - '.$end->format('j M');
    }

    /** The dates and day parts of the modal as a span, or null when they are not (yet) a valid one: half filled, backwards or absurdly long. */
    private static function span(mixed $start, mixed $end, mixed $startPart, mixed $endPart): ?LeaveSpan
    {
        $from = self::date($start);
        $to = self::date($end);

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $to->lt($from)) {
            return null;
        }

        $span = new LeaveSpan($from, $to, self::part($startPart), self::part($endPart));

        return $span->calendarDays() > (int) config('task-logger.leave_max_request_days') ? null : $span;
    }

    /** A date field's state: `Y-m-d`, possibly with a time part, or a date object. */
    private static function date(mixed $state): ?CarbonImmutable
    {
        $raw = match (true) {
            $state instanceof DateTimeInterface => $state->format('Y-m-d'),
            is_string($state) => mb_substr($state, 0, 10),
            default => null,
        };

        return CalendarInput::parseDate($raw);
    }

    private static function part(mixed $state): DayPart
    {
        return $state instanceof DayPart ? $state : (is_string($state) ? DayPart::tryFrom($state) : null) ?? DayPart::Full;
    }
}
