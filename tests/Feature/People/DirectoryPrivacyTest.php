<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Models\UserSkill;
use App\Domain\People\Support\Directory;
use App\Filament\Resources\Directory\Pages\ListDirectory;
use Livewire\Livewire;

/** A fully populated colleague with distinctive private values we can search the output for. */
function colleague(): User
{
    $user = User::factory()->withRole(RoleName::Employee)->create([
        'name' => 'Marta', 'surname' => 'Hoxha', 'email' => 'marta.private@example.test',
        'position' => 'Backend Engineer', 'employment_type' => 'full_time', 'start_date' => '2024-03-04',
        'department_id' => Department::factory()->create(['name' => 'Platform'])->id,
    ]);
    EmployeeProfile::factory()->create([
        'user_id' => $user->id, 'phone' => '+355692123456', 'date_of_birth' => '1990-07-21',
        'address' => '12 Secret Street', 'university' => 'Hidden University', 'source_of_hire' => 'SecretReferral',
        'description' => 'PrivateNote', 'metadata' => ['locker' => 'LOCKER-4711-PRIVATE'],
    ]);
    EmergencyContact::factory()->create(['user_id' => $user->id, 'name' => 'Ana Contact', 'phone' => '+355692123457']);
    UserSkill::create(['user_id' => $user->id, 'technology_id' => Technology::factory()->create(['name' => 'Laravel'])->id, 'level' => 8]);

    return $user;
}

const PRIVATE_VALUES = [
    'marta.private@example.test', '+355692123456', '1990-07-21', '12 Secret Street', 'Hidden University',
    'SecretReferral', 'PrivateNote', 'LOCKER-4711-PRIVATE', 'Ana Contact', '+355692123457', '2024-03-04', 'full_time', 'Full Time',
];

it('BR-26 the directory query selects only the public columns', function () {
    colleague();

    $columns = collect(Directory::COLUMNS);
    expect($columns->all())->toBe(['id', 'name', 'surname', 'position', 'department_id', 'avatar_path']);

    foreach (Directory::query()->get() as $user) {
        expect(array_keys($user->getAttributes()))->each->toBeIn(Directory::COLUMNS);
        expect(array_keys($user->department?->getAttributes() ?? []))->each->toBeIn(['id', 'name']);
    }
})->group('BR-26');

it('BR-26 private fields never appear in the directory for people without the HR record permission', function (RoleName $role) {
    $colleague = colleague();
    $this->actingAs(userWithRole($role));

    $page = Livewire::test(ListDirectory::class)->assertSee('Marta')->assertSee('Hoxha')->assertSee('Backend Engineer')
        ->assertSee('Platform')->assertSee('Laravel 8');

    foreach (PRIVATE_VALUES as $value) {
        $page->assertDontSee($value);
    }

    $html = $this->get('/app/directory')->assertOk()->getContent();
    foreach (PRIVATE_VALUES as $value) {
        expect($html)->not->toContain($value);
    }

    expect($colleague->exists)->toBeTrue();
})->group('BR-26')->with([RoleName::Employee, RoleName::Assistant]);

it('BR-26 the directory is the only people screen for employee and assistant', function (RoleName $role) {
    $this->actingAs(userWithRole($role));

    $this->get('/app/employees')->assertForbidden();
    $this->get('/app/directory')->assertOk();
})->group('BR-26')->with([RoleName::Employee, RoleName::Assistant]);

it('BR-26 nobody, not even a superadmin, gets private columns from the directory', function () {
    colleague();
    $this->actingAs(userWithRole(RoleName::Superadmin));

    $html = $this->get('/app/directory')->assertOk()->getContent();
    foreach (['marta.private@example.test', '+355692123456', '12 Secret Street'] as $value) {
        expect($html)->not->toContain($value);
    }
})->group('BR-26');

it('D-14 an employee has no right over their own HR record by default', function () {
    $employee = userWithRole(RoleName::Employee);

    expect($employee->can('viewHrRecord', $employee))->toBeFalse()
        ->and($employee->can('updateHrRecord', $employee))->toBeFalse()
        ->and($employee->can('view', $employee))->toBeFalse();
});

it('D-14 and ADR-0009 superadmin, admin and hr may view the HR record', function (RoleName $role, bool $allowed) {
    $target = colleague();

    expect(userWithRole($role)->can('viewHrRecord', $target))->toBe($allowed);
})->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, true],
    [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('the directory hides deactivated and anonymised people', function () {
    $active = User::factory()->create(['name' => 'ActiveAnn']);
    $banned = User::factory()->create(['name' => 'BannedBob']);
    $banned->ban();
    User::factory()->create(['name' => 'GoneGary', 'anonymized_at' => now()]);

    $names = Directory::query()->pluck('name')->all();

    expect($names)->toContain('ActiveAnn')->not->toContain('BannedBob')->not->toContain('GoneGary')
        ->and($active->exists)->toBeTrue();
});

it('everyone with the directory permission can open it', function (RoleName $role) {
    $this->actingAs(userWithRole($role))->get('/app/directory')->assertOk();
})->with(RoleName::cases());
