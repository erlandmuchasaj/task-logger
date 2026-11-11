<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Models\Salary;
use App\Domain\People\Models\UserSkill;
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
    'superadmin' => [RoleName::Superadmin, ['/app/directory', '/app/employees', '/app/employees/create', '/app/employees/{id}/edit', '/app/activity-log']],
    'admin' => [RoleName::Admin, ['/app/directory', '/app/employees', '/app/employees/{id}/edit']],
    'hr' => [RoleName::Hr, ['/app/directory', '/app/employees', '/app/employees/{id}/edit']],
    'assistant' => [RoleName::Assistant, ['/app/directory']],
    'employee' => [RoleName::Employee, ['/app/directory']],
]);

it('BR-29 the daily job that activates due salaries is scheduled', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description);

    expect($events)->toContain('salaries.activate-due');
});
