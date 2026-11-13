<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Data\ProjectData;
use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Enums\ProjectType;
use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Enums\EmploymentType;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Data\LeaveRequestData;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Data\EmployeeData;
use App\Domain\Work\Data\LogData;
use App\Domain\Work\Enums\LogType;
use App\Domain\Work\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    ->in('Feature', 'Performance');

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

/**
 * A client with an hourly rate per technology (SPEC example: .NET 24/h, Angular 20/h, Ionic 18/h).
 *
 * @param  array<string, int>  $rates  technology name => hourly rate in minor units
 * @return array{0: Customer, 1: array<string, Technology>}
 */
function clientWithRates(array $rates = ['Angular' => 2000, '.NET' => 2400, 'Ionic' => 1800], string $from = '2025-01-01', array $customer = []): array
{
    $client = Customer::factory()->create($customer);
    $technologies = [];

    foreach ($rates as $name => $minor) {
        $technologies[$name] = Technology::query()->firstOrCreate(['name' => $name]);
        ClientTechnologyRate::factory()->create([
            'customer_id' => $client->id, 'technology_id' => $technologies[$name]->id, 'rate_minor' => $minor, 'effective_from' => $from,
        ]);
    }

    return [$client, $technologies];
}

/** Valid project input for a client. $hours maps technology id to estimated hours. */
function projectData(Customer $client, array $hours, array $overrides = []): ProjectData
{
    $values = array_merge([
        'customerId' => $client->id,
        'type' => ProjectType::Standalone,
        'name' => 'Website',
        'description' => null,
        'managerId' => User::factory()->withRole(RoleName::Admin)->create()->id,
        'startDate' => Carbon::parse('2026-01-01'),
        'endDate' => Carbon::parse('2026-12-31'),
        'canLogDirectly' => false,
        'status' => ProjectStatus::Active,
        'color' => null,
        'technologies' => $hours,
        'memberIds' => [],
    ], $overrides);

    return new ProjectData(...$values);
}

/** Makes the app see an HTTP request from an IP and browser, optionally with an impersonation in the session. */
function asRequest(string $ip = '203.0.113.7', string $agent = 'Mozilla/5.0 (X11) TestBrowser/1.0', mixed $impersonatedBy = null): void
{
    $request = Request::create('/app/labels', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent]);

    $session = app('session')->driver();
    $session->start();
    $session->flush(); // each request has its own session in real life
    if ($impersonatedBy !== null) {
        $session->put(config('task-logger.impersonation.session_key'), $impersonatedBy);
    }
    $request->setLaravelSession($session);

    app()->instance('request', $request);
}

function latestActivity(string $event = 'created'): ActivityLog
{
    return ActivityLog::query()->where('event', $event)->latest('id')->firstOrFail();
}

/**
 * An employee who can log time: a client with an Angular rate, a project that allows direct logging with the
 * employee as member, and a task assigned to the employee.
 *
 * @return array{employee: User, client: Customer, project: Project, technology: Technology, task: Task}
 */
function loggingSetup(int $rateMinor = 2000, string $rateFrom = '2025-01-01', ?User $employee = null): array
{
    $employee ??= User::factory()->withRole(RoleName::Employee)->create();
    [$client, $tech] = clientWithRates(['Angular' => $rateMinor], $rateFrom);

    $project = app(CreateProject::class)->handle(
        User::factory()->withRole(RoleName::Superadmin)->create(),
        projectData($client, [$tech['Angular']->id => '100'], ['canLogDirectly' => true, 'memberIds' => [$employee->id], 'startDate' => Carbon::parse($rateFrom)]),
    )->load('customer');

    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech['Angular']->id, 'assignee_id' => $employee->id]);

    return ['employee' => $employee, 'client' => $client, 'project' => $project, 'technology' => $tech['Angular'], 'task' => $task];
}

/** A log on a task by default; pass projectId and technologyId (and taskId: null) for a direct log. */
function logData(array $overrides = []): LogData
{
    return new LogData(...array_merge([
        'date' => Carbon::parse('2026-03-10'),
        'minutes' => 120,
        'type' => LogType::NormalHours,
        'description' => 'Worked on it',
    ], $overrides));
}

/**
 * A country with a Monday to Friday week, an annual leave type that affects the balance (20 days a year from
 * 2020), a sick type, an unpaid type that does not, and a person in that country.
 *
 * @return array{country: Country, annual: LeaveType, sick: LeaveType, unpaid: LeaveType, employee: User}
 */
function leaveSetup(RoleName $role = RoleName::Employee, ?User $manager = null, string $startDate = '2024-01-01'): array
{
    $country = Country::query()->where('code', 'AL')->first() ?? Country::factory()->albania()->create();
    $annual = LeaveType::firstOrCreate(['name' => 'annual_leave'], ['affects_balance' => true]);
    $sick = LeaveType::firstOrCreate(['name' => 'sick_leave'], ['affects_balance' => true]);
    $unpaid = LeaveType::firstOrCreate(['name' => 'unpaid_leave'], ['affects_balance' => false]);

    CountryLeavePolicy::firstOrCreate(
        ['country_id' => $country->id, 'leave_type_id' => $annual->id, 'effective_from' => '2020-01-01'],
        ['days_per_year' => '20.0'],
    );

    $employee = User::factory()->withRole($role)->create(['country_id' => $country->id, 'manager_id' => $manager?->id, 'start_date' => $startDate]);

    return compact('country', 'annual', 'sick', 'unpaid', 'employee');
}

/** Adds days to someone's balance the way a grant would. */
function grantDays(User $user, LeaveType $type, string $days, string $on = '2026-01-01'): LeaveLedgerEntry
{
    return LeaveLedgerEntry::create([
        'user_id' => $user->id, 'leave_type_id' => $type->id, 'entry_type' => LedgerEntryType::Grant,
        'days' => $days, 'effective_on' => $on,
    ]);
}

function leaveData(LeaveType $type, string $from, ?string $to = null, DayPart $start = DayPart::Full, DayPart $end = DayPart::Full, ?string $reason = null): LeaveRequestData
{
    return new LeaveRequestData($type->id, Carbon::parse($from), Carbon::parse($to ?? $from), $start, $end, $reason);
}
