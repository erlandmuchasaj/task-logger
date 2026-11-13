<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Permissions;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\BankHoliday;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * FR-34 Leave Calendar: bank holidays and leave in one month grid (a server-rendered component, no calendar
 * plugin). Holidays: your country's, or every country's for people who see all leave. Leave: approved and taken leave of
 * everybody (D-44); pending requests only the ones the leave request policy lets you see.
 */
final class LeaveCalendar extends Page
{
    public string $month = '';

    protected string $view = 'filament.pages.leave-calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Leave Calendar';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permissions::BANK_HOLIDAYS_VIEW);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->month = CarbonImmutable::now()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->first()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->first()->addMonth()->format('Y-m');
    }

    public function today(): void
    {
        $this->month = CarbonImmutable::now()->format('Y-m');
    }

    public function first(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month) ?: CarbonImmutable::now()->startOfMonth();
    }

    /**
     * Weeks of days from Monday; each day lists its holidays and leave.
     *
     * @return list<list<array{date: CarbonImmutable, inMonth: bool, holidays: list<string>, leave: list<array{label: string, pending: bool}>}>>
     */
    public function weeks(): array
    {
        $user = auth()->user();
        $first = $this->first();
        $from = $first->startOfWeek();
        $to = $first->endOfMonth()->endOfWeek();
        $seesAll = (bool) $user?->can(Permissions::LEAVE_REQUESTS_VIEW_ANY);

        $holidays = BankHoliday::query()->with('country')
            ->unless($seesAll, fn ($query) => $query->where('country_id', $user?->country_id))
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        // D-44: everybody sees approved and taken leave; pending requests only where the policy lets them.
        $visiblePending = LeaveRequestResource::visibleTo($user, LeaveRequest::query())->where('status', LeaveStatus::Pending)->select('leave_requests.id');

        $leave = LeaveRequest::query()
            ->with('user')
            ->where(fn ($query) => $query->whereIn('status', [LeaveStatus::Approved, LeaveStatus::Taken])->orWhereIn('id', $visiblePending))
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        $weeks = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $weeks[(int) floor($day->diffInDays($from) / 7)][] = [
                'date' => $day,
                'inMonth' => $day->month === $first->month,
                'holidays' => array_values($holidays
                    ->filter(fn (BankHoliday $h): bool => $day->betweenIncluded($h->start_date->startOfDay(), $h->end_date->startOfDay()))
                    ->map(fn (BankHoliday $h): string => $h->name.($seesAll ? ' ('.$h->country->code.')' : ''))->all()),
                'leave' => array_values($leave
                    ->filter(fn (LeaveRequest $r): bool => $day->betweenIncluded($r->start_date->startOfDay(), $r->end_date->startOfDay()))
                    ->map(fn (LeaveRequest $r): array => ['label' => $r->user->getFilamentName(), 'pending' => $r->status === LeaveStatus::Pending])->all()),
            ];
        }

        return array_values($weeks);
    }
}
