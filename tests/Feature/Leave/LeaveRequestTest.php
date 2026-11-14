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
use App\Domain\Leave\Notifications\LeaveDecided;
use App\Domain\Leave\Support\LeaveBalance;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

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
    return resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], $from, $to));
}

it('BR-23 FR-29 rejects an overlap with a pending or approved request but not with a closed one', function (): void {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '20.0');
    $first = pendingRequest($setup, '2026-06-01', '2026-06-03');

    expect(fn (): LeaveRequest => pendingRequest($setup, '2026-06-03', '2026-06-04'))->toThrow(ValidationException::class, 'already have leave');

    $first->update(['status' => LeaveStatus::Rejected]);

    expect(pendingRequest($setup, '2026-06-03', '2026-06-04'))->status->toBe(LeaveStatus::Pending);
})->group('BR-23', 'FR-29');

it('BR-23 lets a morning and an afternoon half day share a date', function (): void {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '20.0');

    resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', start: DayPart::Am));
    resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', start: DayPart::Pm));

    expect(fn () => resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', start: DayPart::Am)))
        ->toThrow(ValidationException::class);
})->group('BR-23', 'BR-17');

it('BR-24 FR-29 cannot exceed the balance minus pending, and non-balance types are unrestricted', function (): void {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '3.0');

    pendingRequest($setup, '2026-06-01', '2026-06-02');   // 2.0 pending, 1.0 left

    expect(fn (): LeaveRequest => pendingRequest($setup, '2026-06-08', '2026-06-09'))->toThrow(ValidationException::class, 'Not enough balance')
        ->and(pendingRequest($setup, '2026-06-08', '2026-06-08'))->days_requested->toBe('1.0')
        ->and(resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['unpaid'], '2026-07-01', '2026-07-31')))->status->toBe(LeaveStatus::Pending);
})->group('BR-24', 'FR-29');

it('D-07 BR-20 FR-30 needs the manager and then HR; final approval deducts the balance', function (): void {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);
    expect($request->refresh()->status)->toBe(LeaveStatus::Pending)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(100);

    resolve(DecideLeave::class)->handle($setup['hr'], $request, ApprovalStage::Hr, ApprovalDecision::Approved, 'Enjoy');

    $deduction = LeaveLedgerEntry::query()->where('leave_request_id', $request->id)->sole();

    expect($request->refresh())->status->toBe(LeaveStatus::Approved)->reviewed_by->toBe($setup['hr']->id)
        ->and($deduction->entry_type)->toBe(LedgerEntryType::Deduction)
        ->and($deduction->days)->toBe('-2.0')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(80)
        ->and($request->approvals)->toHaveCount(2);
})->group('D-07', 'BR-20', 'FR-30');

it('D-07 skips the manager stage when the employee has no manager', function (): void {
    $setup = leaveSetup();
    $hr = User::factory()->withRole(RoleName::Hr)->create();
    $root = User::factory()->withRole(RoleName::Superadmin)->create();
    grantDays($setup['employee'], $setup['annual'], '10.0');
    $request = pendingRequest($setup);

    expect(fn () => resolve(DecideLeave::class)->handle($hr, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(DecideLeave::class)->handle($root, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'no manager');

    resolve(DecideLeave::class)->handle($hr, $request, ApprovalStage::Hr, ApprovalDecision::Approved);

    expect($request->refresh()->status)->toBe(LeaveStatus::Approved);
})->group('D-07', 'FR-30');

it('D-07 HR cannot decide before the manager', function (): void {
    $setup = approvalSetup();

    expect(fn () => resolve(DecideLeave::class)->handle($setup['hr'], pendingRequest($setup), ApprovalStage::Hr, ApprovalDecision::Approved))
        ->toThrow(ValidationException::class, 'manager must decide');
})->group('D-07');

it('BR-21 a rejection needs a note and ends the request without touching the balance', function (): void {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    expect(fn () => resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Rejected))
        ->toThrow(ValidationException::class, 'needs a note');

    resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Rejected, 'Release week');

    expect($request->refresh())->status->toBe(LeaveStatus::Rejected)->review_note->toBe('Release week')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(100)
        ->and(fn () => resolve(DecideLeave::class)->handle($setup['hr'], $request, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'pending');
})->group('BR-21');

it('BR-20 FR-30 nobody decides their own request, root included', function (): void {
    $hr = User::factory()->withRole(RoleName::Hr)->create(['country_id' => leaveSetup()['country']->id]);
    $setup = leaveSetup();
    grantDays($hr, $setup['annual'], '5.0');
    $own = resolve(RequestLeave::class)->handle($hr, leaveData($setup['annual'], '2026-06-01'));

    expect(fn () => resolve(DecideLeave::class)->handle($hr, $own, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'own');

    $root = User::factory()->withRole(RoleName::Superadmin)->create(['country_id' => $hr->country_id]);
    grantDays($root, $setup['annual'], '5.0');
    $rootOwn = resolve(RequestLeave::class)->handle($root, leaveData($setup['annual'], '2026-06-01'));

    expect(fn () => resolve(DecideLeave::class)->handle($root, $rootOwn, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(ValidationException::class, 'own');
})->group('BR-20', 'FR-30');

it('FR-30 superadmin may decide at any stage; others need the stage permission and relationship', function (): void {
    $setup = approvalSetup();
    $root = User::factory()->withRole(RoleName::Superadmin)->create();
    $request = pendingRequest($setup);

    $stranger = User::factory()->withRole(RoleName::Admin)->create();   // has approve_direct_reports but is not the manager (D-28)
    $colleague = User::factory()->withRole(RoleName::Employee)->create();

    expect(fn () => resolve(DecideLeave::class)->handle($stranger, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(DecideLeave::class)->handle($colleague, $request, ApprovalStage::Manager, ApprovalDecision::Approved))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Hr, ApprovalDecision::Approved))->toThrow(AuthorizationException::class);

    resolve(DecideLeave::class)->handle($root, $request, ApprovalStage::Manager, ApprovalDecision::Approved);
    resolve(DecideLeave::class)->handle($root, $request, ApprovalStage::Hr, ApprovalDecision::Approved);

    expect($request->refresh()->status)->toBe(LeaveStatus::Approved);
})->group('FR-30', 'D-28');

it('BR-19 FR-31 cancelling pending changes nothing; cancelling approved writes a reversal', function (): void {
    $setup = approvalSetup();
    $pending = pendingRequest($setup);

    resolve(CancelLeave::class)->handle($setup['employee'], $pending);
    expect($pending->refresh()->status)->toBe(LeaveStatus::Cancelled)->and(LeaveLedgerEntry::query()->where('leave_request_id', $pending->id)->count())->toBe(0);

    $approved = pendingRequest($setup, '2026-06-08', '2026-06-09');
    resolve(DecideLeave::class)->handle($setup['manager'], $approved, ApprovalStage::Manager, ApprovalDecision::Approved);
    resolve(DecideLeave::class)->handle($setup['hr'], $approved, ApprovalStage::Hr, ApprovalDecision::Approved);

    expect(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(80);

    resolve(CancelLeave::class)->handle($setup['employee'], $approved);

    expect($approved->refresh()->status)->toBe(LeaveStatus::Cancelled)
        ->and(LeaveLedgerEntry::query()->where('leave_request_id', $approved->id)->where('entry_type', LedgerEntryType::Reversal)->sole()->days)->toBe('2.0')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(100)
        ->and(fn () => resolve(CancelLeave::class)->handle($setup['employee'], $approved))->toThrow(ValidationException::class);
})->group('BR-19', 'FR-31');

it('D-44 the requester, HR or the superadmin cancel; taken, rejected and cancelled are final', function (): void {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    expect(fn () => resolve(CancelLeave::class)->handle(User::factory()->withRole(RoleName::Employee)->create(), $request))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(CancelLeave::class)->handle($setup['manager'], $request))->toThrow(AuthorizationException::class);

    resolve(CancelLeave::class)->handle($setup['hr'], $request);
    expect($request->refresh()->status)->toBe(LeaveStatus::Cancelled);

    $request = pendingRequest($setup, '2026-07-01', '2026-07-01');

    $request->update(['status' => LeaveStatus::Taken]);

    expect(fn () => resolve(CancelLeave::class)->handle($setup['employee'], $request))->toThrow(ValidationException::class);
})->group('D-44', 'BR-19');

it("BR-19 FR-31 moves approved leave to taken after the end date in the employee's timezone, idempotently", function (): void {
    $setup = approvalSetup();
    $request = pendingRequest($setup, '2026-06-01', '2026-06-02');
    $request->update(['status' => LeaveStatus::Approved]);

    Date::setTestNow('2026-06-02 23:30:00');   // still the 2nd (or already the 3rd) in Tirane: UTC 23:30 is 01:30 on the 3rd, so it is over
    expect(resolve(MarkLeaveTaken::class)->handle())->toBe(1)
        ->and(resolve(MarkLeaveTaken::class)->handle())->toBe(0)
        ->and($request->refresh()->status)->toBe(LeaveStatus::Taken);

    $later = pendingRequest($setup, '2026-06-10', '2026-06-10');
    $later->update(['status' => LeaveStatus::Approved]);
    Date::setTestNow('2026-06-10 10:00:00');

    expect(resolve(MarkLeaveTaken::class)->handle())->toBe(0);   // the end date itself is not over yet
    Date::setTestNow();
})->group('BR-19', 'FR-31', 'BR-34');

it('BR-22 FR-33 the superadmin records a direct day off: approved, deducted, no approval needed', function (): void {
    $setup = leaveSetup();
    $root = User::factory()->withRole(RoleName::Superadmin)->create();
    grantDays($setup['employee'], $setup['sick'], '5.0');

    $request = resolve(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'Called in sick'));

    expect($request)->status->toBe(LeaveStatus::Approved)->created_by->toBe($root->id)->user_id->toBe($setup['employee']->id)->reviewed_by->toBe($root->id)
        ->and($request->approvals()->count())->toBe(0)
        ->and(LeaveBalance::balance($setup['employee'], $setup['sick']->id))->toBe(40);
})->group('BR-22', 'FR-33');

it('BR-22 BR-24 a direct day off needs a note, a balance type, and may override the balance only on request', function (): void {
    $setup = leaveSetup();
    $root = User::factory()->withRole(RoleName::Superadmin)->create();

    expect(fn () => resolve(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01')))->toThrow(ValidationException::class, 'note is required')
        ->and(fn () => resolve(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['unpaid'], '2026-06-01', reason: 'x')))->toThrow(ValidationException::class, 'PTO or sick')
        ->and(fn () => resolve(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'x')))->toThrow(ValidationException::class, 'Not enough balance');

    resolve(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'No show'), overrideBalance: true);

    expect(LeaveBalance::balance($setup['employee'], $setup['sick']->id))->toBe(-10)
        ->and(fn () => resolve(CreateDirectDayOff::class)->handle($root, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'again'), overrideBalance: true))
        ->toThrow(ValidationException::class, 'already have leave');   // BR-23 still holds
})->group('BR-22', 'BR-24');

it('BR-22 only create_for_others holders record a direct day off', function (RoleName $role): void {
    $setup = leaveSetup();
    $actor = User::factory()->withRole($role)->create();

    expect(fn () => resolve(CreateDirectDayOff::class)->handle($actor, $setup['employee'], leaveData($setup['sick'], '2026-06-01', reason: 'x')))->toThrow(AuthorizationException::class);
})->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee])->group('BR-22');

it('BR-32 every leave write is audited with the actor', function (): void {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    expect(latestActivity()->causer_id)->not->toBeNull();
    resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);
    $approval = latestActivity();
    resolve(DecideLeave::class)->handle($setup['hr'], $request, ApprovalStage::Hr, ApprovalDecision::Approved);

    expect($approval->causer_id)->toBe($setup['manager']->id)
        ->and(ActivityLog::query()->where('subject_type', LeaveLedgerEntry::class)->where('causer_id', $setup['hr']->id)->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('subject_type', LeaveRequest::class)->where('event', 'updated')->exists())->toBeTrue();
})->group('BR-32');

it('D-57 the employee is told about the final approval and about a rejection, not about the manager step', function (): void {
    Notification::fake();
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);
    Notification::assertNothingSent();

    resolve(DecideLeave::class)->handle($setup['hr'], $request, ApprovalStage::Hr, ApprovalDecision::Approved);
    Notification::assertSentTo($setup['employee'], LeaveDecided::class, fn (LeaveDecided $n): bool => $n->toMail($setup['employee'])->subject === 'Your leave request was approved'
        && str_contains(implode("\n", $n->toMail($setup['employee'])->introLines), 'Annual Leave')
        && $n->via($setup['employee']) === ['mail', 'database']);

    $other = approvalSetup();
    $rejected = pendingRequest($other);
    resolve(DecideLeave::class)->handle($other['manager'], $rejected, ApprovalStage::Manager, ApprovalDecision::Rejected, 'Release week');
    Notification::assertSentTo($other['employee'], LeaveDecided::class, function (LeaveDecided $n) use ($other, $rejected): bool {
        $mail = $n->toMail($other['employee']);

        return $n->request->is($rejected)
            && $mail->subject === 'Your leave request was rejected'
            && str_contains(implode("\n", $mail->introLines), 'Release week');
    });
})->group('D-57');

it('D-57 the decision lands in the in-app notification drawer and a banned employee is not told', function (): void {
    $setup = approvalSetup();
    $request = pendingRequest($setup);

    resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Rejected, 'Release week');

    expect($setup['employee']->notifications)->toHaveCount(1)
        ->and($setup['employee']->notifications->first()->data['title'])->toBe('Your leave request was rejected');

    Livewire::withoutLazyLoading();
    Livewire::actingAs($setup['employee'])->test(DatabaseNotifications::class)
        ->set('isOpen', true)
        ->assertSee('Your leave request was rejected');

    Notification::fake();
    $setup['employee']->ban();
    resolve(DecideLeave::class)->handle($setup['manager'], pendingRequest($setup), ApprovalStage::Manager, ApprovalDecision::Rejected, 'No');
    Notification::assertNothingSent();
})->group('D-57');

it('D-44 approved leave that has already started can only be cancelled by HR or the superadmin, not by its owner', function (): void {
    $this->travelTo(Date::parse('2026-06-15 10:00:00'));
    $setup = approvalSetup();
    $past = pendingRequest($setup, '2026-06-10', '2026-06-11');
    $past->update(['status' => LeaveStatus::Approved]);

    $future = pendingRequest($setup, '2026-06-22', '2026-06-23');
    $future->update(['status' => LeaveStatus::Approved]);

    expect(fn () => resolve(CancelLeave::class)->handle($setup['employee'], $past))->toThrow(AuthorizationException::class)
        ->and($setup['employee']->can('cancel', $past))->toBeFalse()
        ->and($setup['hr']->can('cancel', $past))->toBeTrue()
        ->and(userWithRole(RoleName::Superadmin)->can('cancel', $past))->toBeTrue()
        ->and($setup['employee']->can('cancel', $future))->toBeTrue(); // not started yet: still theirs to take back

    resolve(CancelLeave::class)->handle($setup['hr'], $past);

    expect($past->refresh()->status)->toBe(LeaveStatus::Cancelled);
})->group('D-44');
