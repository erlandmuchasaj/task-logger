<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\EmploymentType;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\Department;
use App\Domain\People\Data\EmployeeData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Architecture');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** A user holding exactly one role (D-09). Feature tests seed roles and permissions first. */
function userWithRole(RoleName $role): User
{
    return User::factory()->withRole($role)->create();
}

/** Valid W-01 account data for a new employee in Albania. */
function employeeData(array $overrides = []): EmployeeData
{
    $country = Country::query()->where('code', 'AL')->first() ?? Country::factory()->albania()->create();

    $values = array_merge([
        'name' => 'Jane',
        'surname' => 'Doe',
        'email' => 'jane.doe@example.test',
        'role' => RoleName::Employee,
        'countryId' => $country->id,
        'departmentId' => Department::factory()->create()->id,
        'position' => 'Engineer',
        'managerId' => null,
        'employmentType' => EmploymentType::FullTime,
        'startDate' => now()->startOfDay(),
        'avatarPath' => null,
        'skills' => [],
    ], $overrides);

    return new EmployeeData(...$values);
}
