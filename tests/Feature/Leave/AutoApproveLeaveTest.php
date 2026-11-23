<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\AutoApproveOverdueLeave;
use App\Domain\Leave\Actions\CancelLeave;
use App\Domain\Leave\Actions\DecideLeave;
use App\Domain\Leave\Actions\RequestLeave;
use App\Domain\Leave\Enums\ApprovalDecision;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Notifications\LeaveDecided;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\MasterData\Models\LeaveType;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

// The request below is for Wednesday 2026-06-03 and Thursday 2026-06-04; the employee is in Albania.
beforeEach(fn () => $this->travelTo(Date::parse('2026-05-25 10:00:00')));

/** @return array{employee: User, manager: User, hr: User, annual: LeaveType, unpaid: LeaveType} */
function autoApproveSetup(): array
{
    $manager = User::factory()->withRole(RoleName::Employee)->create();
    $setup = leaveSetup(manager: $manager);
    grantDays($setup['employee'], $setup['annual'], '20.0');

    return ['employee' => $setup['employee'], 'manager' => $manager, 'hr' => userWithRole(RoleName::Hr), 'annual' => $setup['annual'], 'unpaid' => $setup['unpaid']];
}

function pendingAutoApproveLeave(array $setup, string $from = '2026-06-03', string $to = '2026-06-04', string $type = 'annual'): LeaveRequest
{
    return resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup[$type], $from, $to));
}

it('D-84 a pending request is approved once its first day has passed, and not before', function (): void {
    $setup = autoApproveSetup();
    $request = pendingAutoApproveLeave($setup);
    Notification::fake();

    $this->travelTo(Date::parse('2026-06-03 13:00:00'));
    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 0, 'skipped' => 0])
        ->and($request->fresh()->status)->toBe(LeaveStatus::Pending);

    $this->travelTo(Date::parse('2026-06-04 01:00:00'));
    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 1, 'skipped' => 0]);

    $request = $request->fresh();
    expect($request->status)->toBe(LeaveStatus::Approved)
        ->and($request->reviewed_by)->toBeNull()
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($request->review_note)->toBe(AutoApproveOverdueLeave::NOTE)
        ->and($request->wasAutoApproved())->toBeTrue()
        ->and($request->wasSelfApproved())->toBeFalse()
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(180);
})->group('D-84');

it('D-84 the deduction is written once, dated the first day, and a second run changes nothing', function (): void {
    $setup = autoApproveSetup();
    pendingAutoApproveLeave($setup);
    $this->travelTo(Date::parse('2026-06-04 13:00:00'));

    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 1, 'skipped' => 0])
        ->and(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 0, 'skipped' => 0]);

    $entries = LeaveLedgerEntry::query()->where('entry_type', LedgerEntryType::Deduction)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->effective_on->toDateString())->toBe('2026-06-03')
        ->and($entries->first()->created_by)->toBeNull();
})->group('D-84');

it('D-84 the employee is told, and the message says nobody decided it', function (): void {
    $setup = autoApproveSetup();
    pendingAutoApproveLeave($setup);
    Notification::fake();

    $this->travelTo(Date::parse('2026-06-04 13:00:00'));
    resolve(AutoApproveOverdueLeave::class)->handle();

    Notification::assertSentToTimes($setup['employee'], LeaveDecided::class, 1);
    Notification::assertSentTo($setup['employee'], LeaveDecided::class, fn (LeaveDecided $n): bool => $n->isApproved()
        && ! $n->decidedBy instanceof User
        && $n->request->review_note === AutoApproveOverdueLeave::NOTE);
})->group('D-84');

it('D-84 a request the manager approved and HR did not is approved too, keeping the manager stage', function (): void {
    $setup = autoApproveSetup();
    $request = pendingAutoApproveLeave($setup);
    resolve(DecideLeave::class)->handle($setup['manager'], $request, ApprovalStage::Manager, ApprovalDecision::Approved);

    $this->travelTo(Date::parse('2026-06-04 13:00:00'));
    resolve(AutoApproveOverdueLeave::class)->handle();

    expect($request->fresh())->status->toBe(LeaveStatus::Approved)
        ->and($request->approvals()->count())->toBe(1);
})->group('D-84');

it('D-84 a type that does not touch the balance is approved with no deduction', function (): void {
    $setup = autoApproveSetup();
    pendingAutoApproveLeave($setup, type: 'unpaid');

    $this->travelTo(Date::parse('2026-06-04 13:00:00'));

    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 1, 'skipped' => 0])
        ->and(LeaveLedgerEntry::query()->where('entry_type', LedgerEntryType::Deduction)->count())->toBe(0);
})->group('D-84');

it('D-84 BR-24 still holds: a request the balance no longer covers stays pending and is counted as skipped', function (): void {
    $setup = autoApproveSetup();
    $request = pendingAutoApproveLeave($setup);
    grantDays($setup['employee'], $setup['annual'], '-19.0', '2026-02-01');
    Notification::fake();

    $this->travelTo(Date::parse('2026-06-04 13:00:00'));

    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 0, 'skipped' => 1])
        ->and($request->fresh()->status)->toBe(LeaveStatus::Pending)
        ->and(LeaveLedgerEntry::query()->where('entry_type', LedgerEntryType::Deduction)->count())->toBe(0);

    Notification::assertNothingSent();
})->group('D-84', 'BR-24');

it('D-84 one request that does not fit does not stop the others', function (): void {
    $short = autoApproveSetup();
    pendingAutoApproveLeave($short);
    grantDays($short['employee'], $short['annual'], '-19.0', '2026-02-01');
    $fine = autoApproveSetup();
    $ok = pendingAutoApproveLeave($fine);

    $this->travelTo(Date::parse('2026-06-04 13:00:00'));

    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 1, 'skipped' => 1])
        ->and($ok->fresh()->status)->toBe(LeaveStatus::Approved);
})->group('D-84');

it('D-84 rejected, cancelled and already approved requests are left alone', function (): void {
    $setup = autoApproveSetup();
    $rejected = pendingAutoApproveLeave($setup, '2026-06-03', '2026-06-03');
    resolve(DecideLeave::class)->handle($setup['manager'], $rejected, ApprovalStage::Manager, ApprovalDecision::Rejected, 'Not now');
    $approved = pendingAutoApproveLeave($setup, '2026-06-08', '2026-06-08');
    resolve(DecideLeave::class)->handle($setup['manager'], $approved, ApprovalStage::Manager, ApprovalDecision::Approved);
    resolve(DecideLeave::class)->handle($setup['hr'], $approved, ApprovalStage::Hr, ApprovalDecision::Approved);

    $cancelled = pendingAutoApproveLeave($setup, '2026-06-10', '2026-06-10');
    resolve(CancelLeave::class)->handle($setup['employee'], $cancelled);

    $this->travelTo(Date::parse('2026-06-12 13:00:00'));

    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 0, 'skipped' => 0])
        ->and($rejected->fresh()->status)->toBe(LeaveStatus::Rejected)
        ->and($cancelled->fresh()->status)->toBe(LeaveStatus::Cancelled)
        ->and($approved->fresh()->wasAutoApproved())->toBeFalse();
})->group('D-84');

it('D-84 a deactivated employee is skipped', function (): void {
    $setup = autoApproveSetup();
    $request = pendingAutoApproveLeave($setup);
    $setup['employee']->forceFill(['banned_at' => now()])->save();

    $this->travelTo(Date::parse('2026-06-04 13:00:00'));

    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 0, 'skipped' => 0])
        ->and($request->fresh()->status)->toBe(LeaveStatus::Pending);
})->group('D-84');

it('D-84 the job can be switched off, which brings back D-72', function (): void {
    $setup = autoApproveSetup();
    $request = pendingAutoApproveLeave($setup);
    config(['task-logger.leave_auto_approve.enabled' => false]);

    $this->travelTo(Date::parse('2026-06-04 13:00:00'));

    expect(resolve(AutoApproveOverdueLeave::class)->handle())->toBe(['approved' => 0, 'skipped' => 0])
        ->and($request->fresh()->status)->toBe(LeaveStatus::Pending)
        ->and($request->fresh()->isOverdue(Date::now()->toImmutable()))->toBeTrue();
})->group('D-84', 'D-72');

it('D-84 is scheduled twice a day in the configured timezone and never overlaps itself', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => $event->description === 'leave.auto-approve');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 1,13 * * *')
        ->and($event->timezone)->toBe('Europe/Tirane')
        ->and($event->withoutOverlapping)->toBeTrue();
})->group('D-84');
