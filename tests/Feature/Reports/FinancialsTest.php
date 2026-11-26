<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\AddFixedCost;
use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Data\FixedCostData;
use App\Domain\Clients\Models\Project;
use App\Domain\Exports\Actions\RequestExport;
use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Jobs\RunExport;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\People\Models\Salary;
use App\Domain\Reports\Actions\BuildFinancials;
use App\Domain\Reports\Data\FinancialsRow;
use App\Domain\Reports\Support\WorkingMinutes;
use App\Domain\Shared\Money;
use App\Domain\Work\Models\Log;
use App\Filament\Pages\Financials;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** November 2026 in Albania: 21 weekdays less two public holidays (23 and 30 November) is 19 days, 152 hours. */
function albaniaNovember(): Country
{
    $country = Country::factory()->albania()->create();

    foreach (['2026-11-23', '2026-11-30'] as $date) {
        BankHoliday::factory()->create(['country_id' => $country->id, 'start_date' => $date, 'end_date' => $date]);
    }

    return $country;
}

/** A project of a EUR client, one employee in Albania paid 1520.00 a month (10.00 an hour in November 2026), no logs yet. */
function financialsWorld(): array
{
    $country = albaniaNovember();
    [$client, $tech] = clientWithRates(['Angular' => 2000], customer: ['currency_code' => 'EUR']);
    $project = resolve(CreateProject::class)->handle(userWithRole(RoleName::Superadmin), projectData($client, [$tech['Angular']->id => '10']));
    $employee = User::factory()->withRole(RoleName::Employee)->create(['country_id' => $country->id]);
    Salary::factory()->create(['user_id' => $employee->id, 'amount_minor' => 152_000, 'currency_code' => 'EUR', 'effective_date' => '2026-01-01']);

    return [$project->load('customer'), $employee, $tech['Angular']];
}

function financialLog(Project $project, User $employee, int $technologyId, string $date, int $minutes): void
{
    Log::factory()->create(['user_id' => $employee->id, 'project_id' => $project->id, 'technology_id' => $technologyId, 'date' => $date, 'duration_minutes' => $minutes]);
}

function financialsRow(Project $project, RoleName $role = RoleName::Finance): FinancialsRow
{
    return resolve(BuildFinancials::class)->handle(userWithRole($role), [$project])[$project->id];
}

it('D-91 counts the working time of a month without weekends and public holidays', function (): void {
    $minutes = (new WorkingMinutes)->forMonth(albaniaNovember(), '2026-11');

    expect($minutes)->toBe(152 * 60);
})->group('D-91');

it('D-91 prices a person-day at the monthly salary over the working time of that month', function (): void {
    [$project, $employee, $tech] = financialsWorld();
    resolve(AddFixedCost::class)->handle(userWithRole(RoleName::Superadmin), $project, new FixedCostData('Mgmt', now(), Money::of(5000, 'EUR')));
    financialLog($project, $employee, $tech->id, '2026-11-10', 120); // 2 h at 1520.00 / 152 h = 20.00

    $row = financialsRow($project);

    expect($row->employeeCosts->minor)->toBe(2000)
        ->and($row->fixedCosts->minor)->toBe(5000)
        ->and($row->costs()->minor)->toBe(7000)
        ->and($row->profitMinor())->toBe($project->budget_minor - 2000)
        ->and($row->loggedMinutes)->toBe(120);
})->group('D-91');

it('D-91 uses the salary in force on the day of the log', function (): void {
    [$project, $employee, $tech] = financialsWorld();
    Salary::factory()->create(['user_id' => $employee->id, 'amount_minor' => 304_000, 'currency_code' => 'EUR', 'effective_date' => '2026-11-15']);
    financialLog($project, $employee, $tech->id, '2026-11-10', 60); // 10.00 at the old salary
    financialLog($project, $employee, $tech->id, '2026-11-16', 60); // 20.00 after the rise

    expect(financialsRow($project)->employeeCosts->minor)->toBe(3000);
})->group('D-91');

it('D-91 shows no employee costs or profit when a salary is in another currency than the client', function (): void {
    [$project, $employee, $tech] = financialsWorld();
    Salary::factory()->create(['user_id' => $employee->id, 'amount_minor' => 152_000, 'currency_code' => 'USD', 'effective_date' => '2026-06-01']);
    financialLog($project, $employee, $tech->id, '2026-11-10', 60);

    $row = financialsRow($project);

    expect($row->employeeCosts)->toBeNull()->and($row->costs())->toBeNull()->and($row->profitMinor())->toBeNull()
        ->and($row->budget->minor)->toBe($project->budget_minor);
})->group('D-91', 'BR-12');

it('D-91 shows a loss as a negative profit instead of failing', function (): void {
    [$project, $employee, $tech] = financialsWorld(); // budget 200.00
    financialLog($project, $employee, $tech->id, '2026-11-10', 780);
    financialLog($project, $employee, $tech->id, '2026-11-11', 780); // 26 h at 10.00 = 260.00

    $row = financialsRow($project);

    expect($row->profitMinor())->toBe(-6000)->and($row->profitLabel())->toBe('-EUR 60.00')->and($row->profitMajor())->toBe('-60.00');

    $this->actingAs(userWithRole(RoleName::Finance));
    Livewire::test(Financials::class)->assertOk()->assertSee('-EUR 60.00');
})->group('D-91');

it('D-91 a project with no logs has zero costs and a profit equal to its budget', function (): void {
    [$project] = financialsWorld();

    $row = financialsRow($project);

    expect($row->employeeCosts->minor)->toBe(0)->and($row->profitMinor())->toBe($project->budget_minor)->and($row->hours())->toBe('0:00');
})->group('D-91');

it('D-91 two salary rows on the same date: the later row prices the day', function (): void {
    [$project, $employee, $tech] = financialsWorld();
    Salary::factory()->create(['user_id' => $employee->id, 'amount_minor' => 304_000, 'currency_code' => 'EUR', 'effective_date' => '2026-01-01']); // same date as the first
    financialLog($project, $employee, $tech->id, '2026-11-10', 60);

    expect(financialsRow($project)->employeeCosts->minor)->toBe(2000);
})->group('D-91');

it('D-91 counts a day with no salary on record as no cost', function (): void {
    [$project, $employee, $tech] = financialsWorld();
    financialLog($project, $employee, $tech->id, '2025-12-01', 60); // before the first salary

    expect(financialsRow($project)->employeeCosts->minor)->toBe(0);
})->group('D-91');

it('D-91 only finance and the superadmin may build the figures', function (RoleName $role, bool $allowed): void {
    [$project] = financialsWorld();
    $build = fn () => resolve(BuildFinancials::class)->handle(userWithRole($role), [$project]);

    $allowed ? expect($build())->toHaveKey($project->id) : expect($build)->toThrow(AuthorizationException::class);
})->group('D-91')->with([
    [RoleName::Finance, true], [RoleName::Superadmin, true],
    [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('D-91 finance needs neither salaries.view nor clients.view_rates to see the page', function (): void {
    $finance = userWithRole(RoleName::Finance);

    expect($finance->can(Permissions::SALARIES_VIEW))->toBeFalse()
        ->and($finance->can(Permissions::CLIENTS_VIEW_RATES))->toBeFalse()
        ->and($finance->can(Permissions::FINANCIALS_VIEW))->toBeTrue();
})->group('D-91');

it('D-91 the Financials page lists projects for finance and is closed to everyone else', function (): void {
    [$project, $employee, $tech] = financialsWorld();
    financialLog($project, $employee, $tech->id, '2026-11-10', 120);

    foreach ([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee] as $role) {
        $this->actingAs(userWithRole($role))->get('/app/financials')->assertForbidden();
    }

    $this->actingAs(userWithRole(RoleName::Finance))->get('/app/financials')->assertOk();
    Livewire::test(Financials::class)->assertCanSeeTableRecords([$project])->assertSee('20.00');
})->group('D-91');

it('D-91 only finance and the superadmin may export the financials', function (RoleName $role, bool $allowed): void {
    Queue::fake();
    $request = fn () => resolve(RequestExport::class)->handle(userWithRole($role), ExportType::Financials);

    $allowed ? expect($request()->exists)->toBeTrue() : expect($request)->toThrow(AuthorizationException::class);
})->group('D-91')->with([
    [RoleName::Finance, true], [RoleName::Superadmin, true],
    [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

/** @return array<string, string> the one data row of the financials CSV, keyed by heading */
function financialsCsvRow(): array
{
    Storage::fake('local');
    Queue::fake();
    $export = resolve(RequestExport::class)->handle(userWithRole(RoleName::Finance), ExportType::Financials);

    new RunExport($export->id)->handle();
    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Completed)->and($export->rows)->toBe(1);

    $lines = array_values(array_filter(preg_split('/\r?\n/', Str::chopStart(Storage::disk('local')->get($export->file_path), "\xEF\xBB\xBF"))));

    return array_combine(str_getcsv($lines[0], ',', '"', ''), str_getcsv($lines[1], ',', '"', ''));
}

it('D-91 the export file carries the same figures as the page', function (): void {
    [$project, $employee, $tech] = financialsWorld();
    resolve(AddFixedCost::class)->handle(userWithRole(RoleName::Superadmin), $project, new FixedCostData('Mgmt', now(), Money::of(5000, 'EUR')));
    financialLog($project, $employee, $tech->id, '2026-11-10', 125);

    $row = financialsRow($project);

    expect(financialsCsvRow())->toMatchArray([
        'Project' => $project->name,
        'Budget' => $row->budget->toMajorString(),
        'Employee costs' => $row->employeeCosts->toMajorString(),
        'Fixed costs' => '50.00',
        'Costs' => $row->costs()->toMajorString(),
        'Profit' => $row->profitMajor(),
        'Total hours' => '2:05',
    ]);
})->group('D-91');

it('D-91 the export leaves employee costs, costs and profit empty when a salary is in another currency', function (): void {
    [$project, $employee, $tech] = financialsWorld();
    Salary::factory()->create(['user_id' => $employee->id, 'amount_minor' => 152_000, 'currency_code' => 'USD', 'effective_date' => '2026-06-01']);
    financialLog($project, $employee, $tech->id, '2026-11-10', 60);

    expect(financialsCsvRow())->toMatchArray(['Employee costs' => '', 'Costs' => '', 'Profit' => '', 'Total hours' => '1:00']);
})->group('D-91', 'BR-12');

it('D-91 a person with no country is priced on the fallback of 160 hours a month', function (): void {
    expect((new WorkingMinutes)->forMonth(null, '2026-12'))->toBe(160 * 60); // December 2026 has 23 weekdays
})->group('D-91');

it('D-91 a weekend holiday moved to a working day takes that day out of the working time', function (): void {
    $moves = Country::factory()->movesWeekendHolidays()->create();
    $stays = Country::factory()->create();

    foreach ([$moves, $stays] as $country) {
        BankHoliday::factory()->create(['country_id' => $country->id, 'start_date' => '2026-11-28', 'end_date' => '2026-11-28']); // a Saturday
    }

    expect((new WorkingMinutes)->forMonth($moves, '2026-11'))->toBe(160 * 60) // observed on Monday 30 November
        ->and((new WorkingMinutes)->forMonth($stays, '2026-11'))->toBe(168 * 60);
})->group('D-91', 'D-62');
