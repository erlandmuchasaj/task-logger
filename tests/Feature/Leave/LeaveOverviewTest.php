<?php

declare(strict_types=1);

use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\Leave\Support\LeaveOverview;
use App\Filament\Pages\MyLeave;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-06-10 12:00:00');
});

it('D-78 remaining leave is the same number My Leave calls available', function (): void {
    ['employee' => $employee, 'annual' => $annual] = leaveSetup();
    grantDays($employee, $annual, '20.0');
    LeaveRequest::factory()->create(['user_id' => $employee->id, 'leave_type_id' => $annual->id, 'days_requested' => '3.0', 'status' => LeaveStatus::Pending]);

    expect(LeaveOverview::remaining($employee))->toBe(LeaveBalance::available($employee, $annual->id))
        ->and(LeaveOverview::remaining($employee))->toBe(170)
        ->and(LeaveOverview::typeLabel())->toBe($annual->label());
})->group('D-78');

it('D-78 taken counts approved and taken requests that start this year, of my own, for the dashboard type only', function (): void {
    ['employee' => $employee, 'annual' => $annual, 'sick' => $sick] = leaveSetup();
    ['employee' => $colleague] = leaveSetup();

    $make = fn ($user, $type, string $days, LeaveStatus $status, string $start) => LeaveRequest::factory()->create([
        'user_id' => $user->id, 'leave_type_id' => $type->id, 'days_requested' => $days, 'status' => $status,
        'start_date' => $start, 'end_date' => $start,
    ]);

    $make($employee, $annual, '2.5', LeaveStatus::Approved, '2026-02-02');
    $make($employee, $annual, '1.0', LeaveStatus::Taken, '2026-03-02');
    $make($employee, $annual, '4.0', LeaveStatus::Pending, '2026-04-06');
    $make($employee, $annual, '4.0', LeaveStatus::Cancelled, '2026-04-13');
    $make($employee, $annual, '4.0', LeaveStatus::Rejected, '2026-04-20');
    $make($employee, $annual, '5.0', LeaveStatus::Approved, '2025-12-29');
    $make($employee, $sick, '2.0', LeaveStatus::Approved, '2026-05-04');
    $make($colleague, $annual, '9.0', LeaveStatus::Approved, '2026-05-04');

    expect(LeaveOverview::takenThisYear($employee))->toBe(35);
})->group('D-78');

it('D-78 shows no figure when the dashboard leave type is missing or inactive', function (): void {
    ['employee' => $employee, 'annual' => $annual] = leaveSetup();

    $annual->update(['is_active' => false]);

    expect(LeaveOverview::remaining($employee))->toBeNull()
        ->and(LeaveOverview::takenThisYear($employee))->toBe(0)
        ->and(LeaveOverview::typeLabel())->toBeNull();

    config(['task-logger.dashboard.leave_type' => 'no_such_type']);

    expect(LeaveOverview::remaining($employee))->toBeNull();
})->group('D-78');

it('D-88 the total is the balance plus the days taken this year, and pending requests do not change it', function (): void {
    ['employee' => $employee, 'annual' => $annual] = leaveSetup();
    grantDays($employee, $annual, '19.0');

    LeaveRequest::factory()->create(['user_id' => $employee->id, 'leave_type_id' => $annual->id, 'days_requested' => '2.0', 'status' => LeaveStatus::Approved, 'start_date' => '2026-02-02', 'end_date' => '2026-02-03']);
    LeaveRequest::factory()->create(['user_id' => $employee->id, 'leave_type_id' => $annual->id, 'days_requested' => '3.0', 'status' => LeaveStatus::Pending, 'start_date' => '2026-04-06', 'end_date' => '2026-04-08']);

    expect(LeaveOverview::total($employee, $annual->id))->toBe(210)
        ->and(LeaveOverview::dashboardTotal($employee))->toBe(210);

    $annual->update(['is_active' => false]);

    expect(LeaveOverview::dashboardTotal($employee))->toBeNull();
})->group('D-88');

it('D-88 My Leave shows balance, pending and total under each leave type', function (): void {
    ['employee' => $employee, 'annual' => $annual] = leaveSetup();
    grantDays($employee, $annual, '21.0');
    LeaveRequest::factory()->create(['user_id' => $employee->id, 'leave_type_id' => $annual->id, 'days_requested' => '2.0', 'status' => LeaveStatus::Pending]);

    $this->actingAs($employee);

    Livewire::test(MyLeave::class)->assertSee('Balance 21.0 · pending 2.0 · total 21.0');
})->group('D-88');
