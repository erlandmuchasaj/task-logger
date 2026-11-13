<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Models\Salary;
use App\Domain\People\Models\UserSkill;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use Filament\Forms\Components\Select;
use Illuminate\Console\Scheduling\Schedule;

/** Several employees with every relation filled, so a lazy-loaded N+1 would throw (NFR-09). */
function populatedCompany(): User
{
    $first = null;
    foreach (range(1, 4) as $i) {
        $user = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $first?->id]);
        EmployeeProfile::factory()->create(['user_id' => $user->id]);
        EmergencyContact::factory()->create(['user_id' => $user->id]);
        Salary::factory()->create(['user_id' => $user->id]);
        UserSkill::create(['user_id' => $user->id, 'technology_id' => Technology::factory()->create()->id, 'level' => 5]);
        $first ??= $user;
    }

    return $first;
}

it('M2 every people screen renders for the roles that may see it, without N+1 queries', function (RoleName $role, array $urls) {
    $target = populatedCompany();
    $this->actingAs(userWithRole($role));

    foreach ($urls as $url) {
        $this->get(str_replace('{id}', (string) $target->id, $url))->assertOk();
    }
})->with([
    'superadmin' => [RoleName::Superadmin, ['/app/employees', '/app/employees/create', '/app/employees/{id}/edit', '/app/activity-log']],
    'admin' => [RoleName::Admin, ['/app/employees', '/app/employees/{id}/edit']],
    'hr' => [RoleName::Hr, ['/app/employees', '/app/employees/{id}/edit']],
]);

it('D-54 the Directory is removed', function () {
    $this->actingAs(userWithRole(RoleName::Employee))->get('/app/directory')->assertNotFound();
});

it('BR-29 the daily job that activates due salaries is scheduled', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description);

    expect($events)->toContain('salaries.activate-due');
});

it('D-48 source of hire and the contact relationship are dropdowns that keep an older free-text value', function () {
    $employee = userWithRole(RoleName::Employee);
    EmployeeProfile::factory()->create(['user_id' => $employee->id, 'source_of_hire' => 'Old fair']);
    EmergencyContact::factory()->create(['user_id' => $employee->id, 'relationship' => 'sister']);

    $this->actingAs(userWithRole(RoleName::Superadmin));

    Livewire\Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->assertFormFieldExists('profile.source_of_hire', fn (Select $field): bool => $field->getOptions()['Referral'] === 'Referral'
            && $field->getOptions()['Old fair'] === 'Old fair')
        ->assertSee('Grandparent')   // an option of the relationship dropdown
        ->assertFormSet(fn (array $state): bool => collect($state['contacts'])->first()['relationship'] === 'sister')
        ->assertFormSet(['profile.source_of_hire' => 'Old fair']);
})->group('D-48');
