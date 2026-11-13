<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\DecideLeave;
use App\Domain\Leave\Enums\ApprovalDecision;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\BankHoliday;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Resources\LeaveRequests\Pages\ManageLeaveRequests;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

afterEach(fn () => Date::setTestNow());

function leaveWorld(): array
{
    $manager = User::factory()->withRole(RoleName::Admin)->create();
    $setup = leaveSetup(manager: $manager);
    $hr = User::factory()->withRole(RoleName::Hr)->create(['country_id' => $setup['country']->id]);
    $colleague = User::factory()->withRole(RoleName::Employee)->create(['country_id' => $setup['country']->id]);
    grantDays($setup['employee'], $setup['annual'], '10.0');
    grantDays($colleague, $setup['annual'], '10.0');

    return [...$setup, 'manager' => $manager, 'hr' => $hr, 'colleague' => $colleague];
}

it('FR-29 an employee requests leave from the Leave Requests page and sees only their own', function (): void {
    $world = leaveWorld();
    $theirs = LeaveRequest::factory()->create(['user_id' => $world['colleague']->id, 'created_by' => $world['colleague']->id, 'leave_type_id' => $world['annual']->id]);

    $this->actingAs($world['employee']);

    Livewire::test(ManageLeaveRequests::class)
        ->callAction('request', [
            'leave_type_id' => $world['annual']->id, 'start_date' => '2026-06-01', 'end_date' => '2026-06-03',
            'start_day_part' => 'full', 'end_day_part' => 'full', 'reason' => 'Holiday',
        ])
        ->assertHasNoActionErrors();

    $mine = LeaveRequest::query()->where('user_id', $world['employee']->id)->sole();

    Livewire::test(ManageLeaveRequests::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    expect($mine)->days_requested->toBe('3.0')->status->toBe(LeaveStatus::Pending);
})->group('FR-29');

it('FR-29 shows the reason when a request is refused', function (): void {
    $world = leaveWorld();
    $this->actingAs($world['employee']);

    Livewire::test(ManageLeaveRequests::class)
        ->callAction('request', [
            'leave_type_id' => $world['annual']->id, 'start_date' => '2026-06-06', 'end_date' => '2026-06-07',
            'start_day_part' => 'full', 'end_day_part' => 'full',
        ])
        ->assertNotified('The selected dates contain no working days.');

    expect(LeaveRequest::query()->count())->toBe(0);
})->group('FR-29');

it("D-07 D-28 FR-30 the manager sees reports' requests and the stage buttons appear in order", function (): void {
    $world = leaveWorld();
    $request = LeaveRequest::factory()->create([
        'user_id' => $world['employee']->id, 'created_by' => $world['employee']->id, 'leave_type_id' => $world['annual']->id,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-02', 'days_requested' => '2.0',
    ]);

    $this->actingAs($world['manager']);
    Livewire::test(ManageLeaveRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertTableActionVisible('managerApprove', $request)
        ->assertTableActionHidden('hrApprove', $request)
        ->callTableAction('managerApprove', $request);

    $this->actingAs($world['hr']);
    Livewire::test(ManageLeaveRequests::class)
        ->assertTableActionHidden('managerApprove', $request)
        ->assertTableActionVisible('hrApprove', $request)
        ->callTableAction('hrApprove', $request);

    expect($request->refresh()->status)->toBe(LeaveStatus::Approved);
})->group('D-07', 'D-28', 'FR-30');

it('BR-21 rejecting from the screen needs a note', function (): void {
    $world = leaveWorld();
    $request = LeaveRequest::factory()->create([
        'user_id' => $world['employee']->id, 'created_by' => $world['employee']->id, 'leave_type_id' => $world['annual']->id,
    ]);

    $this->actingAs($world['manager']);
    Livewire::test(ManageLeaveRequests::class)
        ->callTableAction('managerReject', $request, ['note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);

    expect($request->refresh()->status)->toBe(LeaveStatus::Pending);

    Livewire::test(ManageLeaveRequests::class)->callTableAction('managerReject', $request, ['note' => 'Release week']);

    expect($request->refresh())->status->toBe(LeaveStatus::Rejected)->review_note->toBe('Release week');
})->group('BR-21');

it('BR-20 nobody gets decision buttons on their own request', function (): void {
    $world = leaveWorld();
    $request = LeaveRequest::factory()->create([
        'user_id' => $world['hr']->id, 'created_by' => $world['hr']->id, 'leave_type_id' => $world['annual']->id,
    ]);

    $this->actingAs($world['hr']);
    Livewire::test(ManageLeaveRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertTableActionHidden('hrApprove', $request)
        ->assertTableActionVisible('cancel', $request);
})->group('BR-20');

it('W-06 BR-22 only the superadmin sees Record a day off, and it deducts the balance', function (): void {
    $world = leaveWorld();
    grantDays($world['employee'], $world['sick'], '3.0');

    $this->actingAs($world['hr']);
    Livewire::test(ManageLeaveRequests::class)->assertActionHidden('dayOff');

    $this->actingAs(userWithRole(RoleName::Superadmin));
    Livewire::test(ManageLeaveRequests::class)
        ->assertActionVisible('dayOff')
        ->callAction('dayOff', [
            'user_id' => $world['employee']->id, 'leave_type_id' => $world['sick']->id, 'start_date' => '2026-06-01', 'end_date' => '2026-06-01',
            'start_day_part' => 'full', 'end_day_part' => 'full', 'reason' => 'Did not come in', 'override_balance' => false,
        ])
        ->assertHasNoActionErrors();

    expect(LeaveRequest::query()->where('user_id', $world['employee']->id)->sole())->status->toBe(LeaveStatus::Approved);
})->group('W-06', 'BR-22');

it('FR-34 My Leave shows the balance and Leave Calendar shows holidays and leave', function (): void {
    $world = leaveWorld();
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Labour Day', 'start_date' => '2026-06-03', 'end_date' => '2026-06-03']);
    LeaveRequest::factory()->create([
        'user_id' => $world['employee']->id, 'created_by' => $world['employee']->id, 'leave_type_id' => $world['annual']->id,
        'start_date' => '2026-06-08', 'end_date' => '2026-06-09', 'status' => LeaveStatus::Approved,
    ]);
    $secret = User::factory()->withRole(RoleName::Employee)->create(['country_id' => $world['country']->id, 'name' => 'Hidden', 'surname' => 'Person']);
    LeaveRequest::factory()->create([
        'user_id' => $secret->id, 'created_by' => $secret->id, 'leave_type_id' => $world['annual']->id,
        'start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'status' => LeaveStatus::Approved,
    ]);
    $pending = User::factory()->withRole(RoleName::Employee)->create(['country_id' => $world['country']->id, 'name' => 'Waiting', 'surname' => 'Person']);
    LeaveRequest::factory()->create([
        'user_id' => $pending->id, 'created_by' => $pending->id, 'leave_type_id' => $world['annual']->id,
        'start_date' => '2026-06-11', 'end_date' => '2026-06-11', 'status' => LeaveStatus::Pending,
    ]);

    $this->actingAs($world['employee'])->get('/app/my-leave')->assertOk()->assertSee('10.0')->assertSee('Annual Leave');

    Date::setTestNow('2026-06-15');
    Livewire::test(LeaveCalendar::class)->set('month', '2026-06')
        ->assertSee('Labour Day')->assertSee($world['employee']->getFilamentName())
        ->assertSee('Hidden')          // D-44: colleagues see each other's approved leave
        ->assertDontSee('Waiting');    // but not their pending requests

    // HR also sees the pending ones.
    $this->actingAs($world['hr']);
    Livewire::test(LeaveCalendar::class)->set('month', '2026-06')->assertSee('Hidden')->assertSee('Waiting');
})->group('FR-34');

it('M5 the leave screens render for every role without N+1 queries', function (RoleName $role): void {
    $world = leaveWorld();
    foreach (range(1, 6) as $i) {
        $person = User::factory()->withRole(RoleName::Employee)->create(['country_id' => $world['country']->id, 'manager_id' => $world['manager']->id]);
        $day = Date::parse('2026-06-01')->addDays($i * 2);
        $request = LeaveRequest::factory()->create([
            'user_id' => $person->id, 'created_by' => $person->id, 'leave_type_id' => $world['annual']->id,
            'start_date' => $day->toDateString(), 'end_date' => $day->toDateString(),
        ]);
        resolve(DecideLeave::class)->handle($world['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);
    }

    $user = match ($role) {
        RoleName::Employee => $world['employee'],
        RoleName::Admin => $world['manager'],
        RoleName::Hr => $world['hr'],
        default => userWithRole($role),
    };
    $this->actingAs($user);

    foreach (['/app/leave-requests', '/app/leave-calendar'] as $url) {
        $this->get($url)->assertOk();
    }

    $this->get('/app/my-leave')->assertOk();
})->with([RoleName::Superadmin, RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee])->group('NFR-09');
