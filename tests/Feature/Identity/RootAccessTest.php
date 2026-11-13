<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Customer;
use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Models\Export;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Identity\Support\PermissionCatalog;
use App\Domain\People\Models\Salary;
use App\Domain\Work\Actions\CreateLog;
use App\Domain\Work\Actions\DeleteLog;
use App\Domain\Work\Actions\UpdateLog;
use App\Domain\Work\Models\Log;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('D-41 root access is a permission held by the superadmin role only', function (): void {
    foreach (RoleName::cases() as $role) {
        expect(in_array(Permissions::SYSTEM_ROOT, PermissionCatalog::forRole($role), true))->toBe($role === RoleName::Superadmin, $role->value);
    }

    expect(Permission::query()->where('name', 'system.root')->exists())->toBeTrue();
})->group('D-41');

it('D-41 the superadmin is allowed any ability, including ones no policy defines', function (): void {
    $superadmin = userWithRole(RoleName::Superadmin);

    expect(Gate::forUser($superadmin)->allows('anything.at.all'))->toBeTrue()
        ->and(Gate::forUser($superadmin)->allows('update', Log::factory()->create()))->toBeTrue()
        ->and($superadmin->can('createOn', [Log::class, now()]))->toBeTrue();
})->group('D-41');

it('D-41 nobody else gets root: every other role is still decided by its permissions', function (RoleName $role): void {
    $user = userWithRole($role);

    expect(Gate::forUser($user)->allows('anything.at.all'))->toBeFalse()
        ->and($user->can(Permissions::SYSTEM_ROOT))->toBeFalse();
})->group('D-41')->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('D-41 root follows the permission, not the role name', function (): void {
    // A superadmin without the permission has no root (their other permissions still apply)...
    $superadmin = userWithRole(RoleName::Superadmin);
    Role::findByName('superadmin')->revokePermissionTo(Permissions::SYSTEM_ROOT);
    $superadmin->forgetCachedPermissions();

    expect(Gate::forUser($superadmin->fresh())->allows('anything.at.all'))->toBeFalse()
        ->and($superadmin->fresh()->can(Permissions::LABELS_MANAGE))->toBeTrue();

    // ...and any user given the permission directly has it, whatever their role.
    $employee = userWithRole(RoleName::Employee);
    $employee->givePermissionTo(Permissions::SYSTEM_ROOT);
    $employee->forgetCachedPermissions();

    expect(Gate::forUser($employee->fresh())->allows('anything.at.all'))->toBeTrue();
})->group('D-41');

it('D-41 the superadmin edits and deletes any log, in an open month, which no one else can', function (): void {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $log = resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));
    $superadmin = userWithRole(RoleName::Superadmin);

    $edited = resolve(UpdateLog::class)->handle($superadmin, $log, logData(['taskId' => $task->id, 'minutes' => 45, 'description' => 'root fix']));

    expect($edited->duration_minutes)->toBe(45)->and($edited->user_id)->toBe($me->id);

    resolve(DeleteLog::class)->handle($superadmin, $log);
    expect(Log::query()->find($log->id))->toBeNull();
})->group('D-41', 'D-39');

it("D-41 an employee still edits only their own logs, and an admin still not others' in an open month", function (): void {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $log = resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));

    foreach ([userWithRole(RoleName::Employee), userWithRole(RoleName::Admin)] as $other) {
        expect(fn () => resolve(UpdateLog::class)->handle($other, $log, logData(['taskId' => $task->id])))->toThrow(AuthorizationException::class)
            ->and(fn () => resolve(DeleteLog::class)->handle($other, $log))->toThrow(AuthorizationException::class);
    }

    expect(resolve(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'minutes' => 30]))->duration_minutes)->toBe(30);
})->group('D-41');

it('D-41 root does not override the data rules: a root edit still must follow BR-02, BR-04 and BR-06', function (): void {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $log = resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'minutes' => 60]));
    $superadmin = userWithRole(RoleName::Superadmin);

    expect(fn () => resolve(UpdateLog::class)->handle($superadmin, $log, logData(['taskId' => $task->id, 'minutes' => 25 * 60])))
        ->toThrow(ValidationException::class)
        ->and(fn () => resolve(UpdateLog::class)->handle($superadmin, $log, logData(['taskId' => null, 'projectId' => null])))
        ->toThrow(ValidationException::class);
})->group('D-41');

it('D-41 the audit trail stays read-only for root (BR-32)', function (): void {
    $superadmin = userWithRole(RoleName::Superadmin);
    $entry = ActivityLog::query()->firstOrFail();

    expect($superadmin->can('viewAny', ActivityLog::class))->toBeTrue()
        ->and($superadmin->can('view', $entry))->toBeTrue()
        ->and($superadmin->can('create', ActivityLog::class))->toBeFalse()
        ->and($superadmin->can('update', $entry))->toBeFalse()
        ->and($superadmin->can('delete', $entry))->toBeFalse();
})->group('D-41', 'BR-32');

it('D-41 salary history stays append-only for root (BR-29)', function (): void {
    $superadmin = userWithRole(RoleName::Superadmin);
    $salary = Salary::factory()->create();

    expect($superadmin->can('create', Salary::class))->toBeTrue()
        ->and($superadmin->can('view', $salary))->toBeTrue()
        ->and($superadmin->can('update', $salary))->toBeFalse()
        ->and($superadmin->can('delete', $salary))->toBeFalse();
})->group('D-41', 'BR-29');

it('D-41 users and clients are still never hard-deleted, not even by root', function (): void {
    $superadmin = userWithRole(RoleName::Superadmin);
    $client = Customer::factory()->create();
    $employee = userWithRole(RoleName::Employee);

    expect($superadmin->can('update', $client))->toBeTrue()
        ->and($superadmin->can('delete', $client))->toBeFalse()
        ->and($superadmin->can('delete', $employee))->toBeFalse()
        ->and($superadmin->can('forceDelete', $employee))->toBeFalse()
        ->and($superadmin->can('updateAccount', $employee))->toBeTrue();
})->group('D-41');

it('D-41 an export file stays with the person who requested it, even for root', function (): void {
    $other = userWithRole(RoleName::Assistant);
    $export = Export::query()->create(['user_id' => $other->id, 'type' => ExportType::Logs, 'status' => ExportStatus::Completed, 'file_path' => 'exports/x.csv', 'expires_at' => now()->addDay()]);
    $superadmin = userWithRole(RoleName::Superadmin);

    expect($superadmin->can('view', $export))->toBeFalse()
        ->and($superadmin->can('download', $export))->toBeFalse()
        ->and($other->can('view', $export))->toBeTrue();
})->group('D-41');

it('D-41 the before-gate never calls itself: checking the root permission through the Gate is safe', function (): void {
    $superadmin = userWithRole(RoleName::Superadmin);

    expect($superadmin->can(Permissions::SYSTEM_ROOT))->toBeTrue()
        ->and(User::factory()->create()->can(Permissions::SYSTEM_ROOT))->toBeFalse();
})->group('D-41');
