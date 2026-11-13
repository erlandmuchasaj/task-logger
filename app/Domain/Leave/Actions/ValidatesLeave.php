<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Data\LeaveRequestData;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\Leave\Support\LeaveSpan;
use App\Domain\MasterData\Models\LeaveType;
use Illuminate\Validation\ValidationException;

/** Server-side rules shared by a leave request and a direct day off (BR-16, BR-17, BR-23, BR-24). */
trait ValidatesLeave
{
    /**
     * @return array{span: LeaveSpan, tenths: int, type: LeaveType}
     *
     * @throws ValidationException
     */
    protected function prepare(User $employee, LeaveRequestData $data): array
    {
        $type = LeaveType::query()->find($data->leaveTypeId);

        if ($type === null || ! $type->is_active) {
            throw ValidationException::withMessages(['leave_type_id' => 'Choose an active leave type.']);
        }

        if ($data->endDate->lt($data->startDate)) {
            throw ValidationException::withMessages(['end_date' => 'The end date cannot be before the start date.']);
        }

        $country = $employee->country;

        if ($country === null) {
            throw ValidationException::withMessages(['user_id' => 'This employee has no country, so working days and holidays are unknown.']);
        }

        $span = new LeaveSpan($data->startDate, $data->endDate, $data->startDayPart, $data->endDayPart);

        if ($span->calendarDays() > (int) config('task-logger.leave_max_request_days')) {
            throw ValidationException::withMessages(['end_date' => 'A leave request cannot cover more than '.config('task-logger.leave_max_request_days').' days.']);
        }

        $tenths = $span->chargedTenths($country);

        if ($tenths <= 0) {
            throw ValidationException::withMessages(['start_date' => 'The selected dates contain no working days.']);
        }

        return ['span' => $span, 'tenths' => $tenths, 'type' => $type];
    }

    /** BR-23: no two pending or approved (or already taken) requests of one person may overlap. */
    protected function assertNoOverlap(User $employee, LeaveSpan $span, ?int $exceptId = null): void
    {
        $clash = LeaveRequest::query()
            ->where('user_id', $employee->getKey())
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved, LeaveStatus::Taken])
            ->whereDate('start_date', '<=', $span->end->toDateString())
            ->whereDate('end_date', '>=', $span->start->toDateString())
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->get()
            ->first(fn (LeaveRequest $other): bool => $span->overlaps(
                new LeaveSpan($other->start_date, $other->end_date, $other->start_day_part, $other->end_day_part)
            ));

        if ($clash !== null) {
            throw ValidationException::withMessages(['start_date' => 'You already have leave in that period ('
                .$clash->start_date->toDateString().' to '.$clash->end_date->toDateString().').']);
        }
    }

    /** BR-24: a request cannot exceed the available balance (balance minus pending). */
    protected function assertBalance(User $employee, LeaveType $type, int $tenths, ?int $exceptId = null): void
    {
        if (! $type->affects_balance) {
            return;
        }

        $available = LeaveBalance::available($employee, (int) $type->getKey(), $exceptId);

        if ($tenths > $available) {
            throw ValidationException::withMessages(['days' => 'Not enough balance: '.Days::format($tenths).' days requested, '
                .Days::format(max($available, 0)).' available.']);
        }
    }

    /** Serialises everything that touches one person's balance, like the daily log cap does for logs. */
    protected function lockEmployee(User $employee): void
    {
        User::query()->whereKey($employee->getKey())->lockForUpdate()->first();
    }
}
