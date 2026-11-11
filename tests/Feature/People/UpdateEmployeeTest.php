<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Actions\UpdateEmployee;
use App\Domain\People\Actions\UpdateHrRecord;
use App\Domain\People\Data\EmployeeData;
use App\Domain\People\Data\HrRecordData;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Models\UserSkill;
use App\Domain\People\Support\ManagerChain;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function dataFor(User $user, array $overrides = []): EmployeeData
{
    return employeeData(array_merge([
        'name' => $user->name, 'surname' => $user->surname, 'email' => $user->email,
        'role' => RoleName::from($user->roles()->first()->name), 'countryId' => $user->country_id,
        'departmentId' => $user->department_id, 'position' => $user->position, 'managerId' => $user->manager_id,
        'employmentType' => $user->employment_type, 'startDate' => $user->start_date,
    ], $overrides));
}

it('FR-01 the superadmin updates account data, role and skills', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = app(CreateEmployee::class)->handle($actor, employeeData(['email' => 'e@example.test']));
    $tech = Technology::factory()->create();

    app(UpdateEmployee::class)->handle($actor, $employee, dataFor($employee, [
        'position' => 'Lead', 'role' => RoleName::Admin, 'skills' => [$tech->id => 6],
    ]));

    expect($employee->fresh()->position)->toBe('Lead')
        ->and($employee->roles()->pluck('name')->all())->toBe(['admin'])
        ->and($employee->skills()->pluck('level', 'technology_id')->all())->toBe([$tech->id => 6]);
})->group('FR-01');

it('FR-12 changing skills updates levels and removes dropped ones, with an audit trail', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    [$a, $b] = Technology::factory()->count(2)->create();
    UserSkill::create(['user_id' => $employee->id, 'technology_id' => $a->id, 'level' => 5]);
    UserSkill::create(['user_id' => $employee->id, 'technology_id' => $b->id, 'level' => 5]);
    Activity::query()->delete();

    app(UpdateEmployee::class)->handle($actor, $employee, dataFor($employee, ['skills' => [$a->id => 9]]));

    expect($employee->skills()->pluck('level', 'technology_id')->all())->toBe([$a->id => 9])
        ->and(Activity::where('subject_type', (new UserSkill)->getMorphClass())->pluck('event')->sort()->values()->all())->toBe(['deleted', 'updated']);
})->group('FR-12', 'BR-32');

it('FR-01 admin, hr, assistant and employee cannot update accounts', function (RoleName $role) {
    $employee = userWithRole(RoleName::Employee);

    app(UpdateEmployee::class)->handle(userWithRole($role), $employee, dataFor($employee, ['position' => 'Hacked']));
})->group('FR-01')->throws(AuthorizationException::class)->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('D-09 the last superadmin cannot be demoted, another one can', function () {
    $only = userWithRole(RoleName::Superadmin);

    expect(fn () => app(UpdateEmployee::class)->handle($only, $only, dataFor($only, ['role' => RoleName::Admin])))
        ->toThrow(ValidationException::class);

    $second = userWithRole(RoleName::Superadmin);
    app(UpdateEmployee::class)->handle($only, $second, dataFor($second, ['role' => RoleName::Admin]));

    expect($second->roles()->pluck('name')->all())->toBe(['admin']);
});

it('DERIVED a manager chain never loops', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();
    $b->update(['manager_id' => $a->id]);
    $c->update(['manager_id' => $b->id]);

    expect(ManagerChain::wouldCycle($a->id, $a->id))->toBeTrue()      // own manager
        ->and(ManagerChain::wouldCycle($a->id, $c->id))->toBeTrue()   // a -> c -> b -> a
        ->and(ManagerChain::wouldCycle($c->id, $a->id))->toBeFalse()
        ->and(ManagerChain::wouldCycle($a->id, null))->toBeFalse();
});

it('DERIVED the update action rejects a manager cycle', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $boss = userWithRole(RoleName::Admin);
    $report = userWithRole(RoleName::Employee);
    $report->update(['manager_id' => $boss->id]);

    app(UpdateEmployee::class)->handle($actor, $boss, dataFor($boss, ['managerId' => $report->id]));
})->throws(ValidationException::class);

it('FR-11 superadmin, admin and hr update the HR record; phones are stored as E.164', function (RoleName $role) {
    $employee = userWithRole(RoleName::Employee);
    $employee->update(['country_id' => Country::factory()->albania()->create()->id]);

    app(UpdateHrRecord::class)->handle(userWithRole($role), $employee, new HrRecordData(
        phone: '0692123456', address: '1 Rruga', hasHealthInsurance: true,
        contacts: [['name' => 'Ana', 'phone' => '+355 69 212 3457', 'relationship' => 'sister']],
    ));

    expect($employee->profile->phone)->toBe('+355692123456')
        ->and($employee->profile->address)->toBe('1 Rruga')
        ->and($employee->emergencyContacts()->sole()->phone)->toBe('+355692123457');
})->group('FR-11')->with([RoleName::Superadmin, RoleName::Admin, RoleName::Hr]);

it('FR-11 assistant and employee cannot update an HR record, not even their own', function (RoleName $role) {
    $actor = userWithRole($role);

    app(UpdateHrRecord::class)->handle($actor, $actor, new HrRecordData(address: 'mine'));
})->group('FR-11')->throws(AuthorizationException::class)->with([RoleName::Assistant, RoleName::Employee]);

it('FR-13 emergency contacts are added, updated and removed, each audited', function () {
    $actor = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $action = app(UpdateHrRecord::class);

    $action->handle($actor, $employee, new HrRecordData(contacts: [
        ['name' => 'Ana', 'phone' => '+355692123457', 'relationship' => 'sister'],
        ['name' => 'Ben', 'phone' => '+355692123458', 'relationship' => 'brother'],
    ]));
    [$ana, $ben] = $employee->emergencyContacts()->orderBy('id')->get()->all();
    Activity::query()->delete();

    $action->handle($actor, $employee, new HrRecordData(contacts: [
        ['id' => $ana->id, 'name' => 'Ana K.', 'phone' => '+355692123457', 'relationship' => 'sister'],
        ['name' => 'Cleo', 'phone' => '+355692123459', 'relationship' => 'spouse'],
    ]));

    expect($employee->emergencyContacts()->orderBy('id')->pluck('name')->all())->toBe(['Ana K.', 'Cleo'])
        ->and($employee->emergencyContacts()->whereKey($ben->id)->exists())->toBeFalse()
        ->and(Activity::where('subject_type', (new EmergencyContact)->getMorphClass())->pluck('event')->sort()->values()->all())
        ->toBe(['created', 'deleted', 'updated']);
})->group('FR-13', 'BR-32');

it('FR-11 an HR user changes the HR record on the Edit screen but not the account', function () {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $employee->update(['name' => 'Original', 'position' => 'Engineer']);
    $this->actingAs($hr);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->fillForm(['name' => 'Tampered', 'position' => 'CEO', 'profile.address' => '9 New Road'])
        ->call('save');

    expect($employee->fresh()->name)->toBe('Original')
        ->and($employee->fresh()->position)->toBe('Engineer')
        ->and(EmployeeProfile::where('user_id', $employee->id)->value('address'))->toBe('9 New Road');
})->group('FR-11');

it('FR-01 the superadmin edits the account on the Edit screen', function () {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $employee = userWithRole(RoleName::Employee);
    $employee->update(['position' => 'Engineer']);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->fillForm(['position' => 'Principal Engineer'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->fresh()->position)->toBe('Principal Engineer');
})->group('FR-01');

it('FR-11 the Edit screen of an HR user loads the private HR record', function () {
    $employee = userWithRole(RoleName::Employee);
    EmployeeProfile::factory()->create(['user_id' => $employee->id, 'address' => '5 Visible Lane']);
    $this->actingAs(userWithRole(RoleName::Hr));

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->assertSchemaStateSet(['profile.address' => '5 Visible Lane']);
})->group('FR-11');

it('FR-11 employee and assistant cannot open the Edit screen', function (RoleName $role) {
    $target = userWithRole(RoleName::Employee);

    $this->actingAs(userWithRole($role))->get("/app/employees/{$target->id}/edit")->assertForbidden();
})->group('FR-11')->with([RoleName::Employee, RoleName::Assistant]);
