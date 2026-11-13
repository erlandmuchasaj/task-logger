<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\AddFixedCost;
use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Actions\DeleteClientRate;
use App\Domain\Clients\Actions\DeleteFixedCost;
use App\Domain\Clients\Actions\RecordClientRate;
use App\Domain\Clients\Actions\UpdateClientRate;
use App\Domain\Clients\Actions\UpdateFixedCost;
use App\Domain\Clients\Actions\UpdateProject;
use App\Domain\Clients\Data\FixedCostData;
use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Support\ClientRates;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

function superadmin(): User
{
    return userWithRole(RoleName::Superadmin);
}

it('BR-13 D-04 the budget is hours times the client rate: Angular 230h at 20 plus .NET 330h at 24', function (): void {
    [$client, $tech] = clientWithRates();

    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '230', $tech['.NET']->id => '330']));

    // 230 x 20.00 = 4,600.00 and 330 x 24.00 = 7,920.00
    expect($project->estimated_hours)->toBe('560.00')
        ->and($project->budget_minor)->toBe(460000 + 792000)
        ->and($project->budget()->equals(Money::of(1252000, 'EUR')))->toBeTrue();
})->group('BR-13');

it('BR-13 the rate used is the one effective on the project start date, not today', function (string $start, int $expected): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    ClientTechnologyRate::factory()->create(['customer_id' => $client->id, 'technology_id' => $tech['Angular']->id, 'rate_minor' => 2200, 'effective_from' => '2026-06-01']);

    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '100'], ['startDate' => Date::parse($start), 'endDate' => null]));

    expect($project->budget_minor)->toBe($expected);
})->group('BR-13')->with([
    'before the new rate' => ['2026-01-01', 100 * 2000],
    'the day before' => ['2026-05-31', 100 * 2000],
    'on the new rate date' => ['2026-06-01', 100 * 2200],
    'after' => ['2026-07-01', 100 * 2200],
]);

it('BR-12 each technology line rounds half up to the minor unit', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 333]);

    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '0.50']));

    expect($project->budget_minor)->toBe(167); // 333 x 0.5 = 166.5
})->group('BR-12', 'BR-13');

it('BR-13 a rate that applies from a later date never changes an earlier project', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '100']));

    resolve(RecordClientRate::class)->handle(superadmin(), $client, $tech['Angular']->id, 2500, Date::parse('2026-06-01'));

    expect($project->fresh()->budget_minor)->toBe(200000);
})->group('BR-13');

it("D-04 changing or adding an earlier rate recomputes the budget of the client's projects", function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '100']));
    $rate = $client->rates()->sole();

    resolve(UpdateClientRate::class)->handle(superadmin(), $rate, 2100, Date::parse('2025-01-01'));
    expect($project->fresh()->budget_minor)->toBe(210000);

    resolve(RecordClientRate::class)->handle(superadmin(), $client, $tech['Angular']->id, 2300, Date::parse('2025-12-01'));
    expect($project->fresh()->budget_minor)->toBe(230000);
})->group('BR-13');

it('D-04 a rate change that would leave a project without a rate is refused and rolled back', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '100']));

    expect(fn () => resolve(DeleteClientRate::class)->handle(superadmin(), $client->rates()->sole()))->toThrow(ValidationException::class)
        ->and($client->rates()->count())->toBe(1)
        ->and($project->fresh()->budget_minor)->toBe(200000);

    expect(fn () => resolve(UpdateClientRate::class)->handle(superadmin(), $client->rates()->sole(), 2000, Date::parse('2027-01-01')))->toThrow(ValidationException::class)
        ->and($client->rates()->sole()->effective_from->toDateString())->toBe('2025-01-01');
})->group('BR-13');

it('D-04 a project cannot start before its technologies have a rate', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000], '2026-03-01');

    resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '10'], ['startDate' => Date::parse('2026-01-01')]));
})->group('BR-13')->throws(ValidationException::class, 'No client rate is effective');

it('D-04 moving the start date reprices the project', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    ClientTechnologyRate::factory()->create(['customer_id' => $client->id, 'technology_id' => $tech['Angular']->id, 'rate_minor' => 2200, 'effective_from' => '2026-06-01']);
    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '100']));

    resolve(UpdateProject::class)->handle(superadmin(), $project, projectData($client, [$tech['Angular']->id => '100'], ['managerId' => $project->manager_id, 'startDate' => Date::parse('2026-07-01')]));

    expect($project->fresh()->budget_minor)->toBe(220000);
})->group('BR-13');

it('BR-14 D-04 fixed costs add to the budget: Management 100 EUR for September', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '10']));

    $cost = resolve(AddFixedCost::class)->handle(superadmin(), $project, new FixedCostData('Management', Date::parse('2026-09-17'), Money::of(10000, 'EUR')));

    expect($cost->date->toDateString())->toBe('2026-09-01') // stored as the first day of the month
        ->and($cost->currency_code)->toBe('EUR')
        ->and($project->fresh()->budget_minor)->toBe(20000 + 10000);
})->group('BR-14');

it('BR-14 a fixed cost must use the client currency', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '10']));

    resolve(AddFixedCost::class)->handle(superadmin(), $project, new FixedCostData('Mgmt', Date::parse('2026-09-01'), Money::of(10000, 'USD')));
})->group('BR-14')->throws(ValidationException::class, "client's currency");

it('BR-14 editing or deleting a fixed cost recomputes the budget', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '10']));
    $cost = resolve(AddFixedCost::class)->handle(superadmin(), $project, new FixedCostData('Mgmt', Date::parse('2026-09-01'), Money::of(10000, 'EUR')));

    resolve(UpdateFixedCost::class)->handle(superadmin(), $cost, new FixedCostData('Mgmt', Date::parse('2026-09-01'), Money::of(25000, 'EUR')));
    expect($project->fresh()->budget_minor)->toBe(20000 + 25000);

    resolve(DeleteFixedCost::class)->handle(superadmin(), $cost->fresh());
    expect($project->fresh()->budget_minor)->toBe(20000);
})->group('BR-14');

it('BR-14 only the superadmin manages fixed costs', function (RoleName $role, bool $allowed): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(superadmin(), projectData($client, [$tech['Angular']->id => '10']));
    $add = fn () => resolve(AddFixedCost::class)->handle(userWithRole($role), $project, new FixedCostData('Mgmt', Date::parse('2026-09-01'), Money::of(100, 'EUR')));

    $allowed ? expect($add()->exists)->toBeTrue() : expect($add)->toThrow(AuthorizationException::class);
})->group('BR-14')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('BR-06 foundation: the rate for a date is the latest effective_from on or before it', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    ClientTechnologyRate::factory()->create(['customer_id' => $client->id, 'technology_id' => $tech['Angular']->id, 'rate_minor' => 2500, 'effective_from' => '2026-04-01']);
    $on = fn (string $date) => ClientRates::forDate($client->id, $tech['Angular']->id, Date::parse($date))?->rate_minor;

    expect($on('2024-12-31'))->toBeNull()
        ->and($on('2025-01-01'))->toBe(2000)
        ->and($on('2026-03-31'))->toBe(2000)
        ->and($on('2026-04-01'))->toBe(2500)
        ->and($on('2030-01-01'))->toBe(2500);
})->group('BR-06');

it('FR-18 a rate is unique per technology and date, and positive', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $record = fn (int $minor, string $date) => resolve(RecordClientRate::class)->handle(superadmin(), $client, $tech['Angular']->id, $minor, Date::parse($date));

    expect(fn () => $record(2100, '2025-01-01'))->toThrow(ValidationException::class)
        ->and(fn () => $record(0, '2026-01-01'))->toThrow(ValidationException::class);
})->group('FR-18');

it('FR-18 only superadmin and admin manage rates', function (RoleName $role, bool $allowed): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $add = fn () => resolve(RecordClientRate::class)->handle(userWithRole($role), $client, $tech['Angular']->id, 2100, Date::parse('2026-01-01'));

    $allowed ? expect($add()->exists)->toBeTrue() : expect($add)->toThrow(AuthorizationException::class);
})->group('FR-18')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);
