<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Actions\UpdateEmployee;
use App\Domain\People\Actions\UpdateHrRecord;
use App\Domain\People\Actions\UpdateSkills;
use App\Domain\People\Data\HrRecordData;
use App\Filament\Resources\Employees\Pages\CreateEmployee as CreateEmployeePage;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(fn () => Notification::fake());

it('D-65 HR edits the HR record and the skills of an employee and of another HR user', function (RoleName $target): void {
    $hr = userWithRole(RoleName::Hr);
    $person = userWithRole($target);
    $tech = Technology::factory()->create();

    resolve(UpdateHrRecord::class)->handle($hr, $person, new HrRecordData(address: '1 Rruga'));
    resolve(UpdateSkills::class)->handle($hr, $person, [$tech->id => 7]);

    expect($person->profile->address)->toBe('1 Rruga')
        ->and($person->skills()->pluck('level', 'technology_id')->all())->toBe([$tech->id => 7]);
})->with([RoleName::Employee, RoleName::Hr])->group('D-65');

it('D-65 HR cannot change the HR record or the skills of a superadmin', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $root = userWithRole(RoleName::Superadmin);
    $tech = Technology::factory()->create();

    expect(fn () => resolve(UpdateHrRecord::class)->handle($hr, $root, new HrRecordData(address: 'x')))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(UpdateSkills::class)->handle($hr, $root, [$tech->id => 5]))->toThrow(AuthorizationException::class)
        ->and($root->skills()->count())->toBe(0);
})->group('D-65');

it('D-65 the admin edits the HR record and the skills of a superadmin, but not the account', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $root = userWithRole(RoleName::Superadmin);
    $tech = Technology::factory()->create();

    resolve(UpdateHrRecord::class)->handle($admin, $root, new HrRecordData(address: 'Root street'));
    resolve(UpdateSkills::class)->handle($admin, $root, [$tech->id => 9]);

    expect($root->profile->address)->toBe('Root street')
        ->and($root->skills()->pluck('level', 'technology_id')->all())->toBe([$tech->id => 9])
        ->and(fn () => resolve(UpdateEmployee::class)->handle($admin, $root, employeeData(['role' => RoleName::Superadmin, 'email' => $root->email, 'position' => 'Owner'])))
        ->toThrow(AuthorizationException::class);
})->group('D-65');

it("D-65 the assistant, finance and employee cannot change anyone's skills", function (RoleName $role): void {
    $person = userWithRole(RoleName::Employee);
    $tech = Technology::factory()->create();

    resolve(UpdateSkills::class)->handle(userWithRole($role), $person, [$tech->id => 4]);
})->with([RoleName::Assistant, RoleName::Finance, RoleName::Employee])->group('D-65')->throws(AuthorizationException::class);

it("D-65 HR saves skills from the edit screen and cannot open a superadmin's", function (): void {
    $hr = userWithRole(RoleName::Hr);
    $person = userWithRole(RoleName::Employee);
    $person->update(['manager_id' => userWithRole(RoleName::Admin)->id]);

    $tech = Technology::factory()->create();
    $this->actingAs($hr);

    Livewire::test(EditEmployee::class, ['record' => $person->getRouteKey()])
        ->fillForm(['skills' => [['technology_id' => $tech->id, 'level' => 8]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($person->skills()->pluck('level', 'technology_id')->all())->toBe([$tech->id => 8]);

    $this->get('/app/employees/'.userWithRole(RoleName::Superadmin)->getRouteKey().'/edit')->assertForbidden();
})->group('D-65');

it('D-66 everybody but a superadmin needs a manager, when created and when updated', function (RoleName $role): void {
    $actor = userWithRole(RoleName::Superadmin);

    $errors = [];

    try {
        resolve(CreateEmployee::class)->handle($actor, employeeData(['role' => $role, 'managerId' => null]));
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('manager_id');

    $person = resolve(CreateEmployee::class)->handle($actor, employeeData(['role' => $role, 'email' => 'p@example.test']));

    expect(fn () => resolve(UpdateEmployee::class)->handle($actor, $person, employeeData([
        'role' => $role, 'email' => $person->email, 'managerId' => null,
    ])))->toThrow(ValidationException::class);
})->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee, RoleName::Finance])->group('D-66');

it('D-66 a superadmin may have no manager', function (): void {
    $actor = userWithRole(RoleName::Superadmin);

    $root = resolve(CreateEmployee::class)->handle($actor, employeeData(['role' => RoleName::Superadmin, 'managerId' => null, 'email' => 'root@example.test']));

    expect($root->manager_id)->toBeNull();
})->group('D-66');

it('D-66 the create screen asks for a manager unless the role is superadmin', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));

    Livewire::test(CreateEmployeePage::class)
        ->fillForm(['role' => RoleName::Employee->value, 'manager_id' => null])
        ->call('create')
        ->assertHasFormErrors(['manager_id' => 'required']);

    Livewire::test(CreateEmployeePage::class)
        ->fillForm(['role' => RoleName::Superadmin->value, 'manager_id' => null])
        ->call('create')
        ->assertHasNoFormErrors(['manager_id']);
})->group('D-66');

it('D-66 a manager chain still cannot loop', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $boss = userWithRole(RoleName::Admin);
    $report = userWithRole(RoleName::Employee);
    $report->update(['manager_id' => $boss->id]);

    resolve(UpdateEmployee::class)->handle($actor, $boss, employeeData([
        'role' => RoleName::Admin, 'email' => $boss->email, 'managerId' => $report->id,
    ]));
})->throws(ValidationException::class)->group('D-66');

it('D-67 the database refuses to delete or soft-delete user 1, and lets everyone else go', function (): void {
    $root = User::factory()->withRole(RoleName::Superadmin)->create(['id' => 1]);
    $other = User::factory()->withRole(RoleName::Admin)->create(['id' => 500]);

    expect(fn () => DB::table('users')->where('id', 1)->delete())->toThrow(QueryException::class, 'cannot be deleted')
        ->and(fn () => $root->delete())->toThrow(QueryException::class, 'cannot be deleted');

    $root->fresh()->update(['name' => 'Renamed']);
    $other->delete();

    expect($root->fresh()->name)->toBe('Renamed')->and(User::query()->whereKey(500)->exists())->toBeFalse();
})->group('D-67');
