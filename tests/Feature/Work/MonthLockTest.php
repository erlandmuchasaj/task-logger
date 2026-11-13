<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Work\Actions\CreateLog;
use App\Domain\Work\Actions\DeleteLog;
use App\Domain\Work\Actions\LockMonth;
use App\Domain\Work\Actions\UnlockMonth;
use App\Domain\Work\Actions\UpdateLog;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Models\TimesheetLock;
use App\Domain\Work\Support\TimesheetLocks;
use App\Filament\Resources\Logs\Pages\EditLog;
use App\Filament\Resources\TimesheetLocks\Pages\ManageTimesheetLocks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** An employee with a log in March 2026, March then locked by a superadmin. */
function lockedMarch(): array
{
    $setup = loggingSetup();
    $log = resolve(CreateLog::class)->handle($setup['employee'], logData(['taskId' => $setup['task']->id, 'date' => Date::parse('2026-03-10'), 'minutes' => 120]));
    $superadmin = userWithRole(RoleName::Superadmin);
    resolve(LockMonth::class)->handle($superadmin, Date::parse('2026-03-20'));

    return [...$setup, 'log' => $log, 'superadmin' => $superadmin];
}

it('BR-07 D-13 only the superadmin locks and unlocks a month', function (RoleName $role, bool $allowed): void {
    $lock = fn () => resolve(LockMonth::class)->handle(userWithRole($role), Date::parse('2026-03-15'));

    $allowed ? expect($lock()->isLocked())->toBeTrue() : expect($lock)->toThrow(AuthorizationException::class);
})->group('BR-07')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('BR-07 the lock is stored as the first day of the month and covers exactly that month', function (): void {
    resolve(LockMonth::class)->handle(userWithRole(RoleName::Superadmin), Date::parse('2026-03-20'));

    expect(TimesheetLock::query()->sole()->month->toDateString())->toBe('2026-03-01')
        ->and(TimesheetLocks::isLocked(Date::parse('2026-03-01')))->toBeTrue()
        ->and(TimesheetLocks::isLocked(Date::parse('2026-03-31')))->toBeTrue()
        ->and(TimesheetLocks::isLocked(Date::parse('2026-02-28')))->toBeFalse()
        ->and(TimesheetLocks::isLocked(Date::parse('2026-04-01')))->toBeFalse();
})->group('BR-07');

it('BR-07 an employee cannot create a log in a locked month', function (): void {
    ['employee' => $me, 'task' => $task] = lockedMarch();

    resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-03-12')]));
})->group('BR-07')->throws(AuthorizationException::class);

it('BR-07 an employee cannot edit or delete their own log in a locked month', function (): void {
    ['employee' => $me, 'task' => $task, 'log' => $log] = lockedMarch();

    expect(fn () => resolve(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'minutes' => 10, 'date' => Date::parse('2026-03-10')])))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(DeleteLog::class)->handle($me, $log))->toThrow(AuthorizationException::class)
        ->and($log->fresh()->duration_minutes)->toBe(120);
})->group('BR-07');

it('BR-07 an employee cannot move a log into a locked month, nor out of it', function (): void {
    ['employee' => $me, 'task' => $task, 'log' => $locked] = lockedMarch();
    $open = resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-04-10'), 'minutes' => 30]));

    expect(fn () => resolve(UpdateLog::class)->handle($me, $open, logData(['taskId' => $task->id, 'date' => Date::parse('2026-03-12'), 'minutes' => 30])))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(UpdateLog::class)->handle($me, $locked, logData(['taskId' => $task->id, 'date' => Date::parse('2026-04-12'), 'minutes' => 120])))->toThrow(AuthorizationException::class);
})->group('BR-07');

it('BR-07 other months stay open for employees', function (): void {
    ['employee' => $me, 'task' => $task] = lockedMarch();

    expect(resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-04-02')]))->exists)->toBeTrue()
        ->and(resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-02-27'), 'minutes' => 30]))->exists)->toBeTrue();
})->group('BR-07');

it("BR-07 an admin corrects an employee's log in a locked month, with before and after values recorded", function (RoleName $role): void {
    ['employee' => $me, 'task' => $task, 'log' => $log] = lockedMarch();
    $fixer = userWithRole($role);
    Activity::query()->delete();

    resolve(UpdateLog::class)->handle($fixer, $log, logData(['taskId' => $task->id, 'minutes' => 90, 'date' => Date::parse('2026-03-10'), 'description' => 'corrected']));

    expect($log->fresh()->duration_minutes)->toBe(90)->and($log->fresh()->user_id)->toBe($me->id);

    $entry = Activity::query()->where('event', 'locked_month_edit')->sole();
    expect($entry->causer_id)->toBe($fixer->id)
        ->and($entry->attribute_changes['old']['duration_minutes'])->toBe(120)
        ->and($entry->attribute_changes['attributes']['duration_minutes'])->toBe(90)
        ->and($entry->properties['month'])->toBe('2026-03')
        ->and($entry->properties['owner_id'])->toBe($me->id);

    // the ordinary model audit also has the before and after values
    $updated = Activity::query()->where('event', 'updated')->where('subject_type', $log->getMorphClass())->where('subject_id', $log->id)->sole();
    expect($updated->attribute_changes['old']['duration_minutes'])->toBe(120)->and($updated->attribute_changes['attributes']['duration_minutes'])->toBe(90);
})->group('BR-07', 'BR-32')->with([RoleName::Admin, RoleName::Superadmin]);

it('BR-07 an admin can delete in a locked month, recorded with the old values', function (): void {
    ['log' => $log] = lockedMarch();
    $admin = userWithRole(RoleName::Admin);
    Activity::query()->delete();

    resolve(DeleteLog::class)->handle($admin, $log);

    expect(Log::query()->find($log->id))->toBeNull();
    $entry = Activity::query()->where('event', 'locked_month_delete')->sole();
    expect($entry->causer_id)->toBe($admin->id)->and($entry->attribute_changes['old']['duration_minutes'])->toBe(120);
})->group('BR-07');

it('BR-07 an admin can add their own log in a locked month, recorded', function (): void {
    ['task' => $task, 'project' => $project] = lockedMarch();
    $admin = userWithRole(RoleName::Admin);
    $adminTask = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $task->technology_id, 'assignee_id' => $admin->id]);
    Activity::query()->delete();

    $log = resolve(CreateLog::class)->handle($admin, logData(['taskId' => $adminTask->id, 'date' => Date::parse('2026-03-05')]));

    expect($log->user_id)->toBe($admin->id)
        ->and(Activity::query()->where('event', 'locked_month_create')->sole()->properties['month'])->toBe('2026-03');
})->group('BR-07');

it('BR-07 assistant and hr have no way into a locked month', function (RoleName $role): void {
    ['task' => $task, 'log' => $log] = lockedMarch();
    $user = userWithRole($role);

    expect(fn () => resolve(UpdateLog::class)->handle($user, $log, logData(['taskId' => $task->id])))->toThrow(AuthorizationException::class)
        ->and($user->can('createOn', [Log::class, Date::parse('2026-03-12')]))->toBeFalse();
})->group('BR-07')->with([RoleName::Assistant, RoleName::Hr]);

it('D-13 unlocking reopens the month for employees and is recorded; locking again reuses the row', function (): void {
    ['employee' => $me, 'task' => $task, 'superadmin' => $superadmin] = lockedMarch();
    $lock = TimesheetLock::query()->sole();
    Activity::query()->delete();

    resolve(UnlockMonth::class)->handle($superadmin, $lock);

    expect($lock->fresh()->isLocked())->toBeFalse()
        ->and($lock->fresh()->unlocked_by)->toBe($superadmin->id)
        ->and($lock->fresh()->unlocked_at)->not->toBeNull()
        ->and(Activity::query()->where('subject_type', $lock->getMorphClass())->where('event', 'updated')->exists())->toBeTrue()
        ->and(resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-03-12')]))->exists)->toBeTrue();

    resolve(LockMonth::class)->handle($superadmin, Date::parse('2026-03-01'));

    expect(TimesheetLock::query()->count())->toBe(1)
        ->and($lock->fresh()->isLocked())->toBeTrue()
        ->and($lock->fresh()->unlocked_at)->toBeNull();
})->group('BR-07');

it('D-13 locking an already locked month does nothing, and only the superadmin can unlock', function (): void {
    ['superadmin' => $superadmin] = lockedMarch();
    $lock = TimesheetLock::query()->sole();
    $lockedAt = $lock->locked_at;

    resolve(LockMonth::class)->handle($superadmin, Date::parse('2026-03-02'));

    expect(TimesheetLock::query()->count())->toBe(1)->and($lock->fresh()->locked_at->equalTo($lockedAt))->toBeTrue()
        ->and(fn () => resolve(UnlockMonth::class)->handle(userWithRole(RoleName::Admin), $lock))->toThrow(AuthorizationException::class);
})->group('BR-07');

it('BR-07 policy matrix for a log in a locked and an open month', function (): void {
    ['employee' => $me, 'log' => $locked, 'task' => $task] = lockedMarch();
    $open = resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-05-04'), 'minutes' => 30]));
    $admin = userWithRole(RoleName::Admin);

    expect($me->can('update', $locked))->toBeFalse()->and($me->can('delete', $locked))->toBeFalse()
        ->and($me->can('update', $open))->toBeTrue()->and($me->can('delete', $open))->toBeTrue()
        ->and($admin->can('update', $locked))->toBeTrue()->and($admin->can('delete', $locked))->toBeTrue()
        ->and($admin->can('update', $open))->toBeFalse()->and($admin->can('delete', $open))->toBeFalse();
})->group('BR-07');

it('BR-07 the Edit screen is closed to an employee for a locked month and open for an admin', function (): void {
    ['employee' => $me, 'log' => $log] = lockedMarch();

    $this->actingAs($me)->get("/app/logs/{$log->id}/edit")->assertForbidden();

    $this->actingAs(userWithRole(RoleName::Admin));
    Livewire::test(EditLog::class, ['record' => $log->getKey()])
        ->fillForm(['hours' => 1, 'minutes' => 15])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($log->fresh()->duration_minutes)->toBe(75);
})->group('BR-07');

it('W-08 the superadmin locks a month from the screen; others cannot open it', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));

    Livewire::test(ManageTimesheetLocks::class)
        ->callAction('lock', ['month' => '2026-02-14'])
        ->assertHasNoActionErrors();

    $lock = TimesheetLock::query()->sole();
    expect($lock->month->toDateString())->toBe('2026-02-01');

    Livewire::test(ManageTimesheetLocks::class)
        ->assertTableActionVisible('unlock', $lock)
        ->callTableAction('unlock', $lock);

    expect($lock->fresh()->isLocked())->toBeFalse();

    foreach ([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee] as $role) {
        $this->actingAs(userWithRole($role))->get('/app/timesheet-locks')->assertForbidden();
    }
})->group('BR-07');
