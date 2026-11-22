<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\CancelLeave;
use App\Domain\Leave\Actions\DecideLeave;
use App\Domain\Leave\Actions\RequestLeave;
use App\Domain\Leave\Actions\ReturnFromLeave;
use App\Domain\Leave\Actions\SendLeaveReminders;
use App\Domain\Leave\Enums\ApprovalDecision;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\LeaveAlert;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Notifications\LeaveNeedsAttention;
use App\Domain\Leave\Support\LeaveApprovers;
use App\Domain\Leave\Support\LeaveBalance;
use App\Filament\Resources\LeaveRequests\Pages\ManageLeaveRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// 2026-06-01 is a Monday; the first day of every request below.
beforeEach(fn () => $this->travelTo(Date::parse('2026-05-25 10:00:00')));

/** An employee under a manager of the given role, with 20 days, plus an HR user and a superadmin. */
function workflowSetup(RoleName $managerRole = RoleName::Employee): array
{
    $manager = User::factory()->withRole($managerRole)->create();
    $setup = leaveSetup(manager: $manager);
    grantDays($setup['employee'], $setup['annual'], '20.0');

    return [...$setup, 'manager' => $manager, 'hr' => userWithRole(RoleName::Hr), 'root' => userWithRole(RoleName::Superadmin)];
}

function workflowRequest(array $setup, string $from = '2026-06-01', string $to = '2026-06-05'): LeaveRequest
{
    return resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], $from, $to));
}

function approveFully(array $setup, LeaveRequest $request): LeaveRequest
{
    resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);

    return resolve(DecideLeave::class)->handle($setup['hr'], $request, ApprovalStage::Hr, ApprovalDecision::Approved);
}

it('D-70 the approver is the manager, an HR user included, and the superadmins when there is none', function (): void {
    $setup = workflowSetup();
    $root = $setup['root'];
    $hrWithManager = User::factory()->withRole(RoleName::Hr)->create(['manager_id' => $setup['manager']->id]);
    $orphan = userWithRole(RoleName::Employee);

    expect(LeaveApprovers::approversOf($setup['employee'])->pluck('id')->all())->toBe([$setup['manager']->id])
        ->and(LeaveApprovers::approversOf($hrWithManager)->pluck('id')->all())->toBe([$setup['manager']->id])
        ->and(LeaveApprovers::approversOf($orphan)->pluck('id')->all())->toBe([$root->id]);

    $setup['manager']->forceFill(['banned_at' => now()])->save();

    expect(LeaveApprovers::approversOf($setup['employee'])->pluck('id')->all())->toBe([$root->id]);
})->group('D-70');

it('D-70 submitting tells the manager and every HR user, never the requester, by mail and in the app', function (): void {
    $setup = workflowSetup();
    $otherHr = userWithRole(RoleName::Hr);
    Notification::fake();

    $request = workflowRequest($setup);

    foreach ([$setup['manager'], $setup['hr'], $otherHr] as $person) {
        Notification::assertSentTo($person, LeaveNeedsAttention::class, fn (LeaveNeedsAttention $n, array $channels): bool => $n->alert === LeaveAlert::Submitted
            && $n->request->is($request)
            && $channels === ['mail', 'database']);
    }

    Notification::assertNotSentTo([$setup['employee'], $setup['root']], LeaveNeedsAttention::class);
})->group('D-70');

it('D-70 an HR requester has no HR stage: only their manager is told, and the manager alone approves', function (): void {
    $manager = userWithRole(RoleName::Admin);
    $setup = leaveSetup(RoleName::Hr, $manager);
    grantDays($setup['employee'], $setup['annual'], '10.0');
    $otherHr = userWithRole(RoleName::Hr);
    $root = userWithRole(RoleName::Superadmin);
    Notification::fake();

    $request = resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', '2026-06-02'));

    Notification::assertSentTo($manager, LeaveNeedsAttention::class);
    Notification::assertNotSentTo([$setup['employee'], $otherHr, $root], LeaveNeedsAttention::class);

    expect(fn () => resolve(DecideLeave::class)->handle($otherHr, $request, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'no HR stage')
        ->and(fn () => resolve(DecideLeave::class)->handle($root, $request, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'no HR stage')
        ->and(fn () => resolve(DecideLeave::class)->handle($otherHr, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(AuthorizationException::class);

    $request = resolve(DecideLeave::class)->handle($manager, $request, ApprovalStage::Manager, ApprovalDecision::Approved);

    expect($request->status)->toBe(LeaveStatus::Approved)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(80);
})->group('D-70');

it('D-70 a non-HR requester still needs the HR stage after the manager', function (): void {
    $setup = workflowSetup();
    $request = workflowRequest($setup);

    $request = resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);

    expect($request->status)->toBe(LeaveStatus::Pending);
})->group('D-70');

it('D-70 a superadmin without a manager is approved when asking; anyone else without a manager waits', function (): void {
    $root = leaveSetup(RoleName::Superadmin);
    grantDays($root['employee'], $root['annual'], '10.0');
    $employee = leaveSetup();
    grantDays($employee['employee'], $employee['annual'], '10.0');
    Notification::fake();

    $own = resolve(RequestLeave::class)->handle($root['employee'], leaveData($root['annual'], '2026-06-01', '2026-06-02'));
    $other = resolve(RequestLeave::class)->handle($employee['employee'], leaveData($employee['annual'], '2026-06-01', '2026-06-02'));

    expect($own->status)->toBe(LeaveStatus::Approved)
        ->and($own->reviewed_by)->toBe($root['employee']->id)
        ->and($own->wasSelfApproved())->toBeTrue()
        ->and(LeaveBalance::balance($root['employee'], $root['annual']->id))->toBe(80)
        ->and(LeaveLedgerEntry::query()->where('user_id', $root['employee']->id)->where('entry_type', LedgerEntryType::Deduction)->count())->toBe(1)
        ->and($other->status)->toBe(LeaveStatus::Pending)
        ->and($other->wasSelfApproved())->toBeFalse()
        ->and(LeaveBalance::balance($employee['employee'], $employee['annual']->id))->toBe(100);
})->group('D-70');

it('D-69 a manager with the employee role decides the manager stage, and a stranger cannot', function (): void {
    $setup = workflowSetup(RoleName::Employee);
    $stranger = userWithRole(RoleName::Employee);
    $request = workflowRequest($setup);

    expect(fn () => resolve(DecideLeave::class)->handle($stranger, $request, ApprovalStage::Manager, ApprovalDecision::Approved))
        ->toThrow(AuthorizationException::class);

    resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);

    expect($request->fresh()->stageDecision(ApprovalStage::Manager)?->decision)->toBe(ApprovalDecision::Approved);
})->group('D-69');

it('D-71 a pending request is cancelled by its requester, HR or the superadmin before its first day', function (string $who): void {
    $setup = workflowSetup();
    $request = workflowRequest($setup);
    $actor = $who === 'requester' ? $setup['employee'] : $setup[$who];

    resolve(CancelLeave::class)->handle($actor, $request);

    expect($request->fresh()->status)->toBe(LeaveStatus::Cancelled);
})->with(['requester', 'hr', 'root'])->group('D-71');

it('D-71 an approved request is cancelled before its first day with the days returned', function (string $who): void {
    $setup = workflowSetup();
    $request = approveFully($setup, workflowRequest($setup));
    $actor = $who === 'requester' ? $setup['employee'] : $setup[$who];

    resolve(CancelLeave::class)->handle($actor, $request);

    expect($request->fresh()->status)->toBe(LeaveStatus::Cancelled)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(200);
})->with(['requester', 'hr', 'root'])->group('D-71');

it('D-71 from the first day HR and the requester cannot cancel approved leave, but the superadmin can', function (): void {
    $setup = workflowSetup();
    $request = approveFully($setup, workflowRequest($setup));
    $this->travelTo(Date::parse('2026-06-01 10:00:00'));

    expect(fn () => resolve(CancelLeave::class)->handle($setup['hr'], $request))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(CancelLeave::class)->handle($setup['employee'], $request))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(LeaveStatus::Approved);

    resolve(CancelLeave::class)->handle($setup['root'], $request);

    expect($request->fresh()->status)->toBe(LeaveStatus::Cancelled);
})->group('D-71');

it('D-71 nobody, the superadmin included, cancels a pending request once it has started', function (string $who): void {
    $setup = workflowSetup();
    $request = workflowRequest($setup);
    $this->travelTo(Date::parse('2026-06-01 10:00:00'));
    $actor = $who === 'requester' ? $setup['employee'] : $setup[$who];

    expect(fn () => resolve(CancelLeave::class)->handle($actor, $request))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(LeaveStatus::Pending)
        ->and($actor->can('cancel', $request))->toBeFalse();
})->with(['requester', 'hr', 'root'])->group('D-71');

it("D-71 an employee cannot cancel somebody else's leave", function (): void {
    $setup = workflowSetup();
    $request = workflowRequest($setup);

    resolve(CancelLeave::class)->handle(userWithRole(RoleName::Employee), $request);
})->group('D-71')->throws(AuthorizationException::class);

it('D-72 the approver is reminded N days before the first day, once', function (): void {
    $setup = workflowSetup();
    $request = workflowRequest($setup, '2026-06-03', '2026-06-04');
    Notification::fake();

    $this->travelTo(Date::parse('2026-05-31 09:00:00'));
    expect(resolve(SendLeaveReminders::class)->handle())->toBe(['reminded' => 0, 'escalated' => 0]);

    $this->travelTo(Date::parse('2026-06-01 09:00:00'));
    expect(resolve(SendLeaveReminders::class)->handle())->toBe(['reminded' => 1, 'escalated' => 0])
        ->and(resolve(SendLeaveReminders::class)->handle())->toBe(['reminded' => 0, 'escalated' => 0]);

    Notification::assertSentToTimes($setup['manager'], LeaveNeedsAttention::class, 1);
    Notification::assertSentTo($setup['manager'], LeaveNeedsAttention::class, fn (LeaveNeedsAttention $n): bool => $n->alert === LeaveAlert::Reminder);
    Notification::assertNotSentTo([$setup['hr'], $setup['root'], $setup['employee']], LeaveNeedsAttention::class);

    expect($request->fresh())->reminder_sent_at->not->toBeNull()->status->toBe(LeaveStatus::Pending);
})->group('D-72');

it('D-72 without a manager the reminder goes to HR and the superadmins', function (): void {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '5.0');
    $hr = userWithRole(RoleName::Hr);
    $root = userWithRole(RoleName::Superadmin);
    resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-03'));
    Notification::fake();

    $this->travelTo(Date::parse('2026-06-01 09:00:00'));
    resolve(SendLeaveReminders::class)->handle();

    Notification::assertSentTo([$hr, $root], LeaveNeedsAttention::class);
})->group('D-72');

it('D-72 on the first day a pending request is escalated to HR and the superadmins once, never approved', function (): void {
    $setup = workflowSetup();
    $request = workflowRequest($setup, '2026-06-03', '2026-06-04');
    Notification::fake();

    $this->travelTo(Date::parse('2026-06-03 09:00:00'));

    expect(resolve(SendLeaveReminders::class)->handle())->toBe(['reminded' => 0, 'escalated' => 1])
        ->and(resolve(SendLeaveReminders::class)->handle())->toBe(['reminded' => 0, 'escalated' => 0]);

    Notification::assertSentToTimes($setup['hr'], LeaveNeedsAttention::class, 1);
    Notification::assertSentToTimes($setup['root'], LeaveNeedsAttention::class, 1);
    Notification::assertSentTo($setup['hr'], LeaveNeedsAttention::class, fn (LeaveNeedsAttention $n): bool => $n->alert === LeaveAlert::Escalated);
    Notification::assertNotSentTo([$setup['manager'], $setup['employee']], LeaveNeedsAttention::class);

    expect($request->fresh())->status->toBe(LeaveStatus::Pending)->escalated_at->not->toBeNull();
})->group('D-72');

it('D-72 decided requests are left alone', function (): void {
    $setup = workflowSetup();
    approveFully($setup, workflowRequest($setup, '2026-06-03', '2026-06-04'));
    Notification::fake();

    $this->travelTo(Date::parse('2026-06-03 09:00:00'));

    expect(resolve(SendLeaveReminders::class)->handle())->toBe(['reminded' => 0, 'escalated' => 0]);
    Notification::assertNothingSent();
})->group('D-72');

it('D-72 a pending request is overdue after its first day, and only then', function (): void {
    $setup = workflowSetup();
    $request = workflowRequest($setup, '2026-06-03', '2026-06-04');
    $approved = approveFully($setup, workflowRequest($setup, '2026-06-10', '2026-06-11'));
    $today = fn (string $date) => Date::parse($date)->toImmutable();

    expect($request->isOverdue($today('2026-06-03')))->toBeFalse()
        ->and($request->isOverdue($today('2026-06-04')))->toBeTrue()
        ->and(LeaveRequest::query()->overdue($today('2026-06-03'))->count())->toBe(0)
        ->and(LeaveRequest::query()->overdue($today('2026-06-04'))->pluck('id')->all())->toBe([$request->id])
        ->and($approved->isOverdue($today('2026-06-20')))->toBeFalse();
})->group('D-72');

it('D-73 coming back on day three returns the remaining days to the balance and keeps the request approved', function (): void {
    $setup = workflowSetup();
    $request = approveFully($setup, workflowRequest($setup));   // Mon to Fri, 5 days
    $this->travelTo(Date::parse('2026-06-03 10:00:00'));

    $request = resolve(ReturnFromLeave::class)->handle($setup['root'], $request, Date::parse('2026-06-03'));

    expect($request->status)->toBe(LeaveStatus::Approved)
        ->and($request->end_date->toDateString())->toBe('2026-06-02')
        ->and($request->days_requested)->toBe('2.0')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(180)
        ->and(LeaveLedgerEntry::query()->where('user_id', $setup['employee']->id)->where('entry_type', LedgerEntryType::Reversal)->where('leave_request_id', $request->id)->sole()->days)->toBe('3.0');
})->group('D-73');

it('D-73 refuses a return date outside the leave, and a request that is not approved', function (): void {
    $setup = workflowSetup();
    $request = approveFully($setup, workflowRequest($setup));
    $pending = workflowRequest($setup, '2026-06-15', '2026-06-16');

    expect(fn () => resolve(ReturnFromLeave::class)->handle($setup['root'], $request, Date::parse('2026-06-01')))->toThrow(ValidationException::class, 'after the first day')
        ->and(fn () => resolve(ReturnFromLeave::class)->handle($setup['root'], $request, Date::parse('2026-06-06')))->toThrow(ValidationException::class, 'after the first day')
        ->and(fn () => resolve(ReturnFromLeave::class)->handle($setup['root'], $pending, Date::parse('2026-06-16')))->toThrow(ValidationException::class, 'approved')
        ->and($request->fresh()->days_requested)->toBe('5.0');
})->group('D-73');

it('D-73 HR can shorten leave too, but never their own', function (): void {
    $setup = workflowSetup();
    $request = approveFully($setup, workflowRequest($setup));
    $hrLeave = leaveSetup(RoleName::Hr, $setup['manager']);
    grantDays($hrLeave['employee'], $hrLeave['annual'], '10.0');
    $own = resolve(RequestLeave::class)->handle($hrLeave['employee'], leaveData($hrLeave['annual'], '2026-06-08', '2026-06-12'));
    $own = resolve(DecideLeave::class)->handle($setup['manager'], $own, ApprovalStage::Manager, ApprovalDecision::Approved);

    expect(resolve(ReturnFromLeave::class)->handle($setup['hr'], $request, Date::parse('2026-06-03'))->days_requested)->toBe('2.0')
        ->and(fn () => resolve(ReturnFromLeave::class)->handle($hrLeave['employee'], $own, Date::parse('2026-06-10')))->toThrow(AuthorizationException::class)
        ->and($own->fresh()->days_requested)->toBe('5.0');
})->group('D-73');

it('D-73 the manager and the employee cannot shorten leave', function (string $who): void {
    $setup = workflowSetup();
    $request = approveFully($setup, workflowRequest($setup));
    $actor = $who === 'requester' ? $setup['employee'] : $setup[$who];

    resolve(ReturnFromLeave::class)->handle($actor, $request, Date::parse('2026-06-03'));
})->with(['requester', 'manager'])->group('D-73')->throws(AuthorizationException::class);

it('D-72 the Overdue filter lists only pending requests whose first day has passed', function (): void {
    $setup = workflowSetup();
    $overdue = workflowRequest($setup, '2026-06-03', '2026-06-04');
    $upcoming = workflowRequest($setup, '2026-06-15', '2026-06-16');
    $this->travelTo(Date::parse('2026-06-05 10:00:00'));
    $this->actingAs($setup['hr']);

    Livewire::test(ManageLeaveRequests::class)
        ->filterTable('overdue', true)
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$upcoming]);
})->group('D-72');
