<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\RequestLeave;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\LeaveType;

/*
|--------------------------------------------------------------------------
| Leave in a real browser
|--------------------------------------------------------------------------
| What a Livewire test cannot prove: the calendar's pointer events (click and drag), the modal that opens from them,
| and the row-action dropdown with its confirmation modals. The rules themselves are in tests/Feature/Leave.
| Today is Wednesday 11 March 2026, so 17 to 19 March is a clean Tuesday to Thursday in the future.
|
| Selectors are explicit on purpose: "Request leave" is both the modal heading and its submit button, and
| "Approve (manager)" is both a dropdown item and a modal heading, so a text press would hit the wrong one.
*/

beforeEach(function (): void {
    $this->travelTo('2026-03-11 10:00:00');
});

/** The Request leave modal's field ids and the one visible modal window (the notification bell has a hidden one). */
const LEAVE_TYPE_FIELD = '[id="mountedActionSchema0.leave_type_id"]';
const LEAVE_START_FIELD = '[id="mountedActionSchema0.start_date"]';
const LEAVE_END_FIELD = '[id="mountedActionSchema0.end_date"]';
const REJECT_NOTE_FIELD = '[id="mountedActionSchema0.note"]';
const OPEN_MODAL_SUBMIT = '.fi-modal-window:visible button[type=submit]';
const ROW_ACTIONS = 'button[aria-label="Actions"]';

/** A calendar day button as the blade renders it: `Request leave on 17 March 2026`. */
function leaveCalendarDay(string $label): string
{
    return '[aria-label="Request leave on '.$label.'"]';
}

/** The row action in the open dropdown, found by the action name Livewire is told to mount. */
function rowAction(string $name): string
{
    return '[wire\\:click^="mountAction(\''.$name.'\'"]';
}

/**
 * An employee in Albania with a manager and 20 days of annual leave.
 *
 * @return array{country: Country, annual: LeaveType, sick: LeaveType, unpaid: LeaveType, employee: User, manager: User}
 */
function employeeWithManager(string $days = '20.0'): array
{
    $manager = userWithRole(RoleName::Admin);
    $setup = leaveSetup(RoleName::Employee, $manager);
    grantDays($setup['employee'], $setup['annual'], $days);

    return [...$setup, 'manager' => $manager];
}

/** A pending request made through the real Action, the way the form would. */
function pendingLeave(User $employee, int $leaveTypeId, string $from, string $to, ?string $reason = null): LeaveRequest
{
    $type = LeaveType::query()->findOrFail($leaveTypeId);

    return resolve(RequestLeave::class)->handle($employee, leaveData($type, $from, $to, reason: $reason));
}

it('FR-29 FR-34 a click on a calendar day opens the request form for that day and the request lands on the calendar as pending', function (): void {
    $setup = employeeWithManager();
    $this->actingAs($setup['employee']);

    $page = visit('/app/leave-requests?tab=calendar')
        ->assertSee('March 2026')
        ->click(leaveCalendarDay('17 March 2026'))
        ->assertSee('Uses 1.0 days')
        ->assertValue(LEAVE_START_FIELD, '17/03/2026')
        ->assertValue(LEAVE_END_FIELD, '17/03/2026')
        ->select(LEAVE_TYPE_FIELD, (string) $setup['annual']->id)
        ->assertSee('20.0 available (Annual Leave)')
        ->click(OPEN_MODAL_SUBMIT)
        ->assertSee('Leave requested: 1.0 days');

    // The pending request is a dashed sky chip on the day, with the person's own marker.
    $page->assertPresent('button.border-dashed.border-sky-500[title*="Annual Leave"][title$="Pending"]');

    $request = LeaveRequest::query()->where('user_id', $setup['employee']->id)->sole();

    expect($request)
        ->status->toBe(LeaveStatus::Pending)
        ->days_requested->toBe('1.0')
        ->and($request->start_date->toDateString())->toBe('2026-03-17')
        ->and($request->end_date->toDateString())->toBe('2026-03-17');
})->group('browser', 'FR-29', 'FR-34');

it('FR-29 FR-34 BR-16 a drag across three weekdays opens the form for the range with its day summary', function (): void {
    $setup = employeeWithManager();
    $this->actingAs($setup['employee']);

    visit('/app/leave-requests?tab=calendar')
        ->assertSee('March 2026')
        ->drag(leaveCalendarDay('17 March 2026'), leaveCalendarDay('19 March 2026'))
        ->assertSee('Uses 3.0 days')
        ->assertValue(LEAVE_START_FIELD, '17/03/2026')
        ->assertValue(LEAVE_END_FIELD, '19/03/2026')
        ->select(LEAVE_TYPE_FIELD, (string) $setup['annual']->id)
        ->assertSee('Uses 3.0 days · 20.0 available (Annual Leave)')
        ->click(OPEN_MODAL_SUBMIT)
        ->assertSee('Leave requested: 3.0 days');

    $request = LeaveRequest::query()->where('user_id', $setup['employee']->id)->sole();

    expect($request)
        ->status->toBe(LeaveStatus::Pending)
        ->days_requested->toBe('3.0')
        ->and($request->start_date->toDateString())->toBe('2026-03-17')
        ->and($request->end_date->toDateString())->toBe('2026-03-19');
})->group('browser', 'FR-29', 'FR-34', 'BR-16');

it('D-07 D-28 FR-30 the manager approves first, then HR, and the request ends approved', function (): void {
    $setup = employeeWithManager();
    $hr = userWithRole(RoleName::Hr);
    $request = pendingLeave($setup['employee'], $setup['annual']->id, '2026-03-17', '2026-03-18');

    $this->actingAs($setup['manager']);

    visit('/app/leave-requests')
        ->assertSee('Manager: waiting / HR: waiting')
        ->assertNotPresent(rowAction('hrApprove'))
        ->click(ROW_ACTIONS)
        ->click(rowAction('managerApprove'))
        ->click(OPEN_MODAL_SUBMIT)
        ->assertSee('Decision recorded')
        ->assertSee('Manager: Approved / HR: waiting');

    expect($request->refresh()->status)->toBe(LeaveStatus::Pending);

    // HR is a second person: a fresh page, signed in as them. The manager stage is closed, the HR stage is open.
    $this->actingAs($hr);

    visit('/app/leave-requests')
        ->assertSee('Manager: Approved / HR: waiting')
        ->assertNotPresent(rowAction('managerApprove'))
        ->click(ROW_ACTIONS)
        ->click(rowAction('hrApprove'))
        ->click(OPEN_MODAL_SUBMIT)
        ->assertSee('Manager: Approved / HR: Approved')
        ->assertSeeIn('.fi-badge', 'Approved');

    expect($request->refresh()->status)->toBe(LeaveStatus::Approved);
})->group('browser', 'D-07', 'D-28', 'FR-30');

it('BR-21 the manager cannot reject without a note, and a note rejects the request', function (): void {
    $setup = employeeWithManager();
    $request = pendingLeave($setup['employee'], $setup['annual']->id, '2026-03-17', '2026-03-18');

    $this->actingAs($setup['manager']);

    // The empty note is stopped by the browser's own required check, so the modal stays open and nothing is sent.
    $page = visit('/app/leave-requests')
        ->click(ROW_ACTIONS)
        ->click(rowAction('managerReject'))
        ->assertSee('Why is it rejected?')
        ->click(OPEN_MODAL_SUBMIT)
        ->assertVisible(REJECT_NOTE_FIELD)
        ->assertScript('document.querySelector(\'[id="mountedActionSchema0.note"]\').validity.valueMissing')
        ->assertDontSee('Request rejected');

    expect($request->refresh()->status)->toBe(LeaveStatus::Pending);

    $page
        ->type(REJECT_NOTE_FIELD, 'Release week')
        ->click(OPEN_MODAL_SUBMIT)
        ->assertSee('Request rejected')
        ->assertSeeIn('.fi-badge', 'Rejected');

    expect($request->refresh())
        ->status->toBe(LeaveStatus::Rejected)
        ->review_note->toBe('Release week');
})->group('browser', 'BR-21');

it("BR-20 an employee has no decision buttons on their own request and does not see anyone else's", function (): void {
    $setup = employeeWithManager();
    $colleague = leaveSetup()['employee'];
    grantDays($colleague, $setup['annual'], '20.0');

    pendingLeave($setup['employee'], $setup['annual']->id, '2026-03-17', '2026-03-18', 'My own trip');
    pendingLeave($colleague, $setup['annual']->id, '2026-03-24', '2026-03-25', 'Colleague secret trip');

    $this->actingAs($setup['employee']);

    visit('/app/leave-requests')
        ->assertSee('My own trip')
        ->assertDontSee('Colleague secret trip')
        ->click(ROW_ACTIONS)
        ->assertPresent(rowAction('cancel'))
        ->assertNotPresent(rowAction('managerApprove'))
        ->assertNotPresent(rowAction('managerReject'))
        ->assertNotPresent(rowAction('hrApprove'))
        ->assertNotPresent(rowAction('hrReject'));
})->group('browser', 'BR-20', 'FR-29');

it('BR-24 a request for more days than the balance is refused with a visible error and creates nothing', function (): void {
    $setup = employeeWithManager('2.0');
    $this->actingAs($setup['employee']);

    visit('/app/leave-requests?tab=calendar')
        ->assertSee('March 2026')
        ->drag(leaveCalendarDay('17 March 2026'), leaveCalendarDay('19 March 2026'))
        ->select(LEAVE_TYPE_FIELD, (string) $setup['annual']->id)
        ->assertSee('2.0 available (Annual Leave), not enough balance')
        ->click(OPEN_MODAL_SUBMIT)
        ->assertSee('Not enough balance: 3.0 days requested, 2.0 available.');

    expect(LeaveRequest::query()->count())->toBe(0);
})->group('browser', 'BR-24');
