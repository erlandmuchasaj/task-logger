<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Department;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Support\PersonDetailsQuery;
use App\Filament\Pages\ViewPerson;
use App\Filament\Resources\Employees\EmployeeResource;
use Livewire\Livewire;

/** A colleague with a department, a manager and a filled HR record. */
function colleague(array $attributes = []): User
{
    $department = Department::factory()->create(['name' => 'Design', 'color' => '#ef4444']);
    $manager = User::factory()->create(['name' => 'Mona', 'surname' => 'Ger']);
    $person = User::factory()->create([
        'name' => 'Col', 'surname' => 'League', 'position' => 'Designer', 'email' => 'col.league@example.test',
        'department_id' => $department->id, 'manager_id' => $manager->id, ...$attributes,
    ]);
    EmployeeProfile::factory()->create(['user_id' => $person->id, 'phone' => '+355691234567', 'university' => 'Polis University']);

    return $person;
}

it("D-89 an employee sees a colleague's name, title, department, manager and email, but not phone or university", function (RoleName $role): void {
    $colleague = colleague();
    $this->actingAs(userWithRole($role));

    Livewire::test(ViewPerson::class, ['user' => $colleague->id])
        ->assertOk()
        ->assertSee('Col League')
        ->assertSee('Designer')
        ->assertSee('Design')
        ->assertSee('Mona Ger')
        ->assertSee('col.league@example.test')
        ->assertDontSee('+355691234567')
        ->assertDontSee('Polis University')
        ->assertDontSee('Phone');
})->with([RoleName::Employee, RoleName::Assistant, RoleName::Finance])->group('D-89');

it('D-89 HR, the admin and the superadmin also see phone and university', function (RoleName $role): void {
    $colleague = colleague();
    $this->actingAs(userWithRole($role));

    Livewire::test(ViewPerson::class, ['user' => $colleague->id])
        ->assertSee('+355691234567')
        ->assertSee('Polis University');
})->with([RoleName::Hr, RoleName::Admin, RoleName::Superadmin])->group('D-89');

it('D-89 a person sees their own phone and university, and an empty one reads a dash', function (): void {
    $me = userWithRole(RoleName::Employee);
    EmployeeProfile::factory()->create(['user_id' => $me->id, 'phone' => null, 'university' => 'Open University']);
    $this->actingAs($me);

    Livewire::test(ViewPerson::class, ['user' => $me->id])
        ->assertSee('Phone')
        ->assertSee('Open University')
        ->assertSee('—');

    $details = PersonDetailsQuery::find($me, $me->id);

    expect($details->showsPrivate)->toBeTrue()
        ->and($details->phone)->toBeNull()
        ->and($details->university)->toBe('Open University');
})->group('D-89');

it('D-89 the domain query keeps private fields from a viewer who may not read them', function (): void {
    $colleague = colleague();

    $employee = PersonDetailsQuery::find(userWithRole(RoleName::Employee), $colleague->id);
    $hr = PersonDetailsQuery::find(userWithRole(RoleName::Hr), $colleague->id);

    expect($employee->showsPrivate)->toBeFalse()
        ->and($employee->phone)->toBeNull()
        ->and($employee->university)->toBeNull()
        ->and($employee->email)->toBe('col.league@example.test')
        ->and($employee->color)->toBe('#ef4444')
        ->and($hr->showsPrivate)->toBeTrue()
        ->and($hr->phone)->toBe('+355691234567');
})->group('D-89');

it('D-89 a deactivated, anonymised or deleted person has no profile', function (string $state): void {
    $colleague = colleague();
    match ($state) {
        'deactivated' => $colleague->forceFill(['banned_at' => now()])->save(),
        'anonymised' => $colleague->forceFill(['anonymized_at' => now()])->save(),
        'deleted' => $colleague->delete(),
    };

    $viewer = userWithRole(RoleName::Superadmin);
    $this->actingAs($viewer);

    expect(PersonDetailsQuery::find($viewer, $colleague->id))->toBeNull();

    $this->get('/app/hierarchy/'.$colleague->id)->assertNotFound();
})->with(['deactivated', 'anonymised', 'deleted'])->group('D-89');

it('D-89 an unknown id is a 404', function (): void {
    $this->actingAs(userWithRole(RoleName::Employee))->get('/app/hierarchy/999999')->assertNotFound();
})->group('D-89');

it('D-89 a person with no role is refused the profile page', function (): void {
    $colleague = colleague();
    $this->actingAs(User::factory()->create()->fresh());

    $this->get('/app/hierarchy/'.$colleague->id)->assertForbidden();
})->group('D-89');

it('D-89 the manager is not linked once they are no longer active', function (): void {
    $colleague = colleague();
    $manager = $colleague->manager;
    $viewer = userWithRole(RoleName::Employee);
    $this->actingAs($viewer);

    expect(PersonDetailsQuery::find($viewer, $colleague->id)->managerId)->toBe($manager->id);

    $manager->forceFill(['banned_at' => now()])->save();

    $details = PersonDetailsQuery::find($viewer, $colleague->id);

    expect($details->managerId)->toBeNull()
        ->and($details->managerName)->toBeNull();
})->group('D-89');

it('D-89 Open in Employees leads to a screen the viewer may open, and is hidden from an employee', function (): void {
    $colleague = colleague();

    $this->actingAs(userWithRole(RoleName::Employee));
    Livewire::test(ViewPerson::class, ['user' => $colleague->id])->assertActionHidden('openInEmployees');

    $this->actingAs(userWithRole(RoleName::Hr));
    Livewire::test(ViewPerson::class, ['user' => $colleague->id])
        ->assertActionVisible('openInEmployees')
        ->assertActionHasUrl('openInEmployees', EmployeeResource::getUrl('edit', ['record' => $colleague]));
})->group('D-89');

it('D-89 the profile page has a way back to the hierarchy', function (): void {
    $colleague = colleague();
    $this->actingAs(userWithRole(RoleName::Employee));

    Livewire::test(ViewPerson::class, ['user' => $colleague->id])
        ->assertActionHasUrl('back', url('/app/hierarchy'));
})->group('D-89');
