<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\CancelLeave;
use App\Domain\Leave\Actions\CreateDirectDayOff;
use App\Domain\Leave\Actions\DecideLeave;
use App\Domain\Leave\Actions\MarkLeaveTaken;
use App\Domain\Leave\Actions\RequestLeave;
use App\Domain\Leave\Enums\ApprovalDecision;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\LeaveBalance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** An employee with a manager, 10 days of annual leave, and an HR user. */
function approvalSetup(): array
{
    $manager = User::factory()->withRole(RoleName::Admin)->create();
    $setup = leaveSetup(manager: $manager);
    $hr = User::factory()->withRole(RoleName::Hr)->create();
    grantDays($setup['employee'], $setup['annual'], '10.0');

    return [...$setup, 'manager' => $manager, 'hr' => $hr];
}

function pendingRequest(array $setup, string $from = '2026-06-01', ?string $to = '2026-06-02'): LeaveRequest
{
    return app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], $from, $to));
}

it('BR-23 FR-29 rejects an overlap with a pending or approved request but not with a closed one', function () {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '20.0');
    $first = pendingRequest($setup, '2026-06-01', '2026-06-03');

    expect(fn () => pendingRequest($setup, '2026-06-03', '2026-06-04'))->toThrow(ValidationException::class, 'already have leave');

    $first->update(['status' => LeaveStatus::Rejected]);

    expect(pendingRequest($setup, '2026-06-03', '2026-06-04'))->status->toBe(LeaveStatus::Pending);
})->group('BR-23', 'FR-29');

it('BR-23 lets a morning and an afternoon half day share a date', function () {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '20.0');

    app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', start: DayPart::Am));
    app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', start: DayPart::Pm));

    expect(fn () => app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', start: DayPart::Am)))
        ->toThrow(ValidationException::class);
})->group('BR-23', 'BR-17');

it('BR-24 FR-29 cannot exceed the balance minus pending, and non-balance types are unrestricted', function () {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '3.0');

    pendingRequest($setup, '2026-06-01', '2026-06-02');   // 2.0 pending, 1.0 left

    expect(fn () => pendingRequest($setup, '2026-06-08', '2026-06-09'))->toThrow(ValidationException::class, 'Not enough balance')
        ->and(pendingRequest($setup, '2026-06-08', '2026-06-08'))->days_requested->toBe('1.0')
        ->and(app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['unpaid'], '2026-07-01', '2026-07-31')))->status->toBe(LeaveStatus::Pending);
})->group('BR-24', 'FR-29');

it('D-07 BR-20 FR-30 needs the manager and then HR; final approval deducts the balance', function () {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    app(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);
    expect($request->refresh()->status)->toBe(LeaveStatus::Pending)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(100);

    app(DecideLeave::class)->handle($setup['hr'], $request, ApprovalStage::Hr, ApprovalDecision::Approved, 'Enjoy');

    $deduction = LeaveLedgerEntry::where('leave_request_id', $request->id)->sole();

    expect($request->refresh())->status->toBe(LeaveStatus::Approved)->reviewed_by->toBe($setup['hr']->id)
        ->and($deduction->entry_type)->toBe(LedgerEntryType::Deduction)
        ->and($deduction->days)->toBe('-2.0')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(80)
        ->and($request->approvals)->toHaveCount(2);
})->group('D-07', 'BR-20', 'FR-30');

it('D-07 skips the manager stage when the employee has no manager', function () {
    $setup = leaveSetup();
    $hr = User::factory()->withRole(RoleName::Hr)->create();
    $root = User::factory()->withRole(RoleName::Superadmin)->create();
    grantDays($setup['employee'], $setup['annual'], '10.0');
    $request = pendingRequest($setup);

    expect(fn () => app(DecideLeave::class)->handle($hr, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DecideLeave::class)->handle($root, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'no manager');

    app(DecideLeave::class)->handle($hr, $request, ApprovalStage::Hr, ApprovalDecision::Approved);

    expect($request->refresh()->status)->toBe(LeaveStatus::Approved);
})->group('D-07', 'FR-30');

it('D-07 HR cannot decide before the manager', function () {
    $setup = approvalSetup();

    expect(fn () => app(DecideLeave::class)->handle($setup['hr'], pendingRequest($setup), ApprovalStage::Hr, ApprovalDecision::Approved))
        ->toThrow(ValidationException::class, 'manager must decide');
})->group('D-07');

it('BR-21 a rejection needs a note and ends the request without touching the balance', function () {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    expect(fn () => app(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Rejected))
        ->toThrow(ValidationException::class, 'needs a note');

    app(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Rejected, 'Release week');

    expect($request->refresh())->status->toBe(LeaveStatus::Rejected)->review_note->toBe('Release week')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(100)
        ->and(fn () => app(DecideLeave::class)->handle($setup['hr'], $request, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'pending');
})->group('BR-21');

it('BR-20 FR-30 nobody decides their own request, root included', function () {
    $hr = User::factory()->withRole(RoleName::Hr)->create(['country_id' => leaveSetup()['country']->id]);
    $setup = leaveSetup();
    grantDays($hr, $setup['annual'], '5.0');
    $own = app(RequestLeave::class)->handle($hr, leaveData($setup['annual'], '2026-06-01'));

    expect(fn () => app(DecideLeave::class)->handle($hr, $own, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'own');

    $root = User::factory()->withRole(RoleName::Superadmin)->create(['country_id' => $hr->country_id]);
    grantDays($root, $setup['annual'], '5.0');
    $rootOwn = app(RequestLeave::class)->handle($root, leaveData($setup['annual'], '2026-06-01'));

    expect(fn () => app(DecideLeave::class)->handle($root, $rootOwn, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'own');
})->group('BR-20', 'FR-30');

it('FR-30 superadmin may decide at any stage; others need the stage permission and relationship', function () {
    $setup = approvalSetup();
    $root = User::factory()->withRole(RoleName::Superadmin)->create();
    $request = pendingRequest($setup);

    $stranger = User::factory()->withRole(RoleName::Admin)->create();   // has approve_direct_reports but is not the manager (D-28)
    $colleague = User::factory()->withRole(RoleName::Employee)->create();

    expect(fn () => app(DecideLeave::class)->handle($stranger, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DecideLeave::class)->handle($colleague, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(AuthorizationException::class);

    app(DecideLeave::class)->handle($root, $request, ApprovalStage::Manager, ApprovalDecision::Approved);
    app(DecideLeave::class)->handle($root, $request, ApprovalStage::Hr, ApprovalDecision::Approved);

    expect($request->refresh()->status)->toBe(LeaveStatus::Approved);
})->group('FR-30', 'D-28');

it('BR-19 FR-31 cancelling pending changes nothing; cancelling approved writes a reversal', function () {
    $setup = approvalSetup();
    $pending = pendingRequest($setup);

    app(CancelLeave::class)->handle($setup['employee'], $pending);
    expect($pending->refresh()->status)->toBe(LeaveStatus::Cancelled)->and(LeaveLedgerEntry::where('leave_request_id', $pending->id)->count())->toBe(0);

    $approved = pendingRequest($setup, '2026-06-08', '2026-06-09');
    app(DecideLeave::class)->handle($setup['manager'], $approved, ApprovalStage::Manager, ApprovalDecision::Approved);
    app(DecideLeave::class)->handle($setup['hr'], $approved, ApprovalStage::Hr, ApprovalDecision::Approved);
    expect(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(80);

    app(CancelLeave::class)->handle($setup['employee'], $approved);

    expect($approved->refresh()->status)->toBe(LeaveStatus::Cancelled)
        ->and(LeaveLedgerEntry::where('leave_request_id', $approved->id)->where('entry_type', LedgerEntryType::Reversal)->sole()->days)->toBe('2.0')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(100)
        ->and(fn () => app(CancelLeave::class)->handle($setup['employee'], $approved))->toThrow(ValidationException::class);
})->group('BR-19', 'FR-31');

it('D-44 the requester, HR or the superadmin cancel; taken, rejected and cancelled are final', function () {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    expect(fn () => app(CancelLeave::class)->handle(User::factory()->withRole(RoleName::Employee)->create(), $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelLeave::class)->handle($setup['manager'], $request))->toThrow(AuthorizationException::class);

    app(CancelLeave::class)->handle($setup['hr'], $request);
    expect($request->refresh()->status)->toBe(LeaveStatus::Cancelled);

    $request = pendingRequest($setup, '2026-07-01', '2026-07-01');

    $request->update(['status' => LeaveStatus::Taken]);

    expect(fn () => app(CancelLeave::class)->handle($setup['employee'], $request))->toThrow(ValidationException::class);
})->group('D-44', 'BR-19');

it('BR-19 FR-31 moves approved leave to taken after the end date in the employee\'s timezone, idempotently', function () {
    $setup = approvalSetup();
    $request = pendingRequest($setup, '2026-06-01', '2026-06-02');
    $request->update(['status' => LeaveStatus::Approved]);

    Carbon::setTestNow('2026-06-02 23:30:00');   // still the 2nd (or already the 3rd) in Tirane: UTC 23:30 is 01:30 on the 3rd, so it is over
    expect(app(MarkLeaveTaken::class)->handle())->toBe(1)
        ->and(app(MarkLeaveTaken::class)->handle())->toBe(0)
        ->and($request->refresh()->status)->toBe(LeaveStatus::Taken);

    $later = pendingRequest($setup, '2026-06-10', '2026-06-10');
    $later->update(['status' => LeaveStatus::Approved]);
    Carbon::setTestNow('2026-06-10 10:00:00');

    expect(app(MarkLeaveTaken::class)->handle())->toBe(0);   // the end date itself is not over yet
    Carbon::setTestNow();
})->group('BR-19', 'FR-31', 'BR-34');

it('BR-22 FR-33 the superadmin records a direct day off: approved, deducted, no approval needed', function () {
    $setup = leaveSetup();
    $root = User::factory()->withRole(RoleName::Superadmin)->create();
    grantDays($setup['employee'], $setup['sick'], '5.0');

    $request = app(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'Called in sick'));

    expect($request)->status->toBe(LeaveStatus::Approved)->created_by->toBe($root->id)->user_id->toBe($setup['employee']->id)->reviewed_by->toBe($root->id)
        ->and($request->approvals()->count())->toBe(0)
        ->and(LeaveBalance::balance($setup['employee'], $setup['sick']->id))->toBe(40);
})->group('BR-22', 'FR-33');

it('BR-22 BR-24 a direct day off needs a note, a balance type, and may override the balance only on request', function () {
    $setup = leaveSetup();
    $root = User::factory()->withRole(RoleName::Superadmin)->create();

    expect(fn () => app(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01')))->toThrow(ValidationException::class, 'note is required')
        ->and(fn () => app(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['unpaid'], '2026-06-01', reason: 'x')))->toThrow(ValidationException::class, 'PTO or sick')
        ->and(fn () => app(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'x')))->toThrow(ValidationException::class, 'Not enough balance');

    app(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'No show'), overrideBalance: true);

    expect(LeaveBalance::balance($setup['employee'], $setup['sick']->id))->toBe(-10)
        ->and(fn () => app(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'again'), overrideBalance: true))
        ->toThrow(ValidationException::class, 'already have leave');   // BR-23 still holds
})->group('BR-22', 'BR-24');

it('BR-22 only create_for_others holders record a direct day off', function (RoleName $role) {
    $setup = leaveSetup();
    $actor = User::factory()->withRole($role)->create();

    expect(fn () => app(CreateDirectDayOff::class)->handle($actor, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'x')))->toThrow(AuthorizationException::class);
})->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee])->group('BR-22');

it('BR-32 every leave write is audited with the actor', function () {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    expect(latestActivity()->causer_id)->not->toBeNull();
    app(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);
    $approval = latestActivity();
    app(DecideLeave::class)->handle($setup['hr'], $request, ApprovalStage::Hr, ApprovalDecision::Approved);

    expect($approval->causer_id)->toBe($setup['manager']->id)
        ->and(ActivityLog::where('subject_type', LeaveLedgerEntry::class)->where('causer_id', $setup['hr']->id)->exists())->toBeTrue()
        ->and(ActivityLog::where('subject_type', LeaveRequest::class)->where('event', 'updated')->exists())->toBeTrue();
})->group('BR-32');
