<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Reports\Actions\BuildEstimateReport;
use App\Domain\Reports\Data\EstimateFilters;
use App\Domain\Reports\Data\EstimateLine;
use App\Domain\Work\Models\Log;
use App\Filament\Pages\EstimateVsLogged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** A client with Angular at 20.00 and .NET at 24.00 an hour; a project estimating Angular 10 h and .NET 5 h. */
function reportProject(array $customer = []): array
{
    [$client, $tech] = clientWithRates(['Angular' => 2000, '.NET' => 2400], customer: $customer);
    $project = app(CreateProject::class)->handle(
        userWithRole(RoleName::Superadmin),
        projectData($client, [$tech['Angular']->id => '10', $tech['.NET']->id => '5'], ['name' => 'Portal '.fake()->unique()->numerify('####')]),
    );

    return [$project, $tech, $client];
}

function loggedMinutes(Project $project, int $technologyId, int $minutes, array $overrides = []): Log
{
    return Log::factory()->create(array_merge([
        'project_id' => $project->id, 'technology_id' => $technologyId, 'duration_minutes' => $minutes, 'rate_minor' => 2000, 'rate_currency' => 'EUR',
    ], $overrides));
}

function line(array $lines, string $technology): EstimateLine
{
    return collect($lines)->firstWhere('technology', $technology);
}

function reportFor(RoleName $role, ?EstimateFilters $filters = null, int $page = 1)
{
    return app(BuildEstimateReport::class)->handle(userWithRole($role), $filters ?? new EstimateFilters, $page);
}

it('M7 SPEC 1.2 compares estimated and logged hours per technology and prices each log at its own snapshot rate', function () {
    [$project, $tech] = reportProject();
    loggedMinutes($project, $tech['Angular']->id, 90);                                      // 1.5 h at 20.00 = 30.00
    loggedMinutes($project, $tech['Angular']->id, 45);                                      // 0.75 h = 15.00
    loggedMinutes($project, $tech['Angular']->id, 60, ['rate_minor' => 2500]);              // an older rate: 25.00, kept as logged (BR-06)
    loggedMinutes($project, $tech['.NET']->id, 20, ['rate_minor' => 2400]);                 // 20 min at 24.00 = 8.00

    $row = reportFor(RoleName::Admin)->first();
    $angular = line($row->lines, 'Angular');
    $dotnet = line($row->lines, '.NET');

    expect($row->project)->toStartWith('Portal')
        ->and($angular)->technology->toBe('Angular')->estimatedHundredths->toBe(1000)->loggedMinutes->toBe(195)
        ->and($angular->loggedHundredths())->toBe(325)
        ->and($angular->varianceHundredths())->toBe(-675)
        ->and($angular->percentUsed())->toBe(33)
        ->and($angular->value->minor)->toBe(3000 + 1500 + 2500)
        ->and($dotnet)->technology->toBe('.NET')->loggedMinutes->toBe(20)->and($dotnet->loggedHundredths())->toBe(33)
        ->and($dotnet->value->minor)->toBe(800)
        ->and($row->total())->estimatedHundredths->toBe(1500)->loggedMinutes->toBe(215)
        ->and($row->value->minor)->toBe(7800)->and($row->value->currency)->toBe('EUR')
        ->and(EstimateLine::hours(-675))->toBe('-6.75');
})->group('M7', 'BR-06', 'BR-12');

it('M7 a technology with no logs shows zero, over-use shows a positive difference', function () {
    [$project, $tech] = reportProject();
    loggedMinutes($project, $tech['Angular']->id, 12 * 60);

    $lines = reportFor(RoleName::Admin)->first()->lines;
    $angular = line($lines, 'Angular');
    $dotnet = line($lines, '.NET');

    expect($angular->varianceHundredths())->toBe(200)->and($angular->percentUsed())->toBe(120)
        ->and($dotnet->loggedMinutes)->toBe(0)->and($dotnet->value)->toBeNull()->and($dotnet->percentUsed())->toBe(0);
})->group('M7');

it('M7 the dates limit the logs counted but never the estimate; deleted logs do not count', function () {
    [$project, $tech] = reportProject();
    loggedMinutes($project, $tech['Angular']->id, 60, ['date' => '2026-02-10']);
    loggedMinutes($project, $tech['Angular']->id, 120, ['date' => '2026-03-10']);
    loggedMinutes($project, $tech['Angular']->id, 600, ['date' => '2026-03-11'])->delete();

    $march = line(reportFor(RoleName::Admin, new EstimateFilters(from: Carbon::parse('2026-03-01'), to: Carbon::parse('2026-03-31')))->first()->lines, 'Angular');
    $all = line(reportFor(RoleName::Admin)->first()->lines, 'Angular');

    expect($march->loggedMinutes)->toBe(120)->and($march->estimatedHundredths)->toBe(1000)
        ->and($all->loggedMinutes)->toBe(180);
})->group('M7');

it('M7 D-05 only clients.view_rates holders get values; assistant sees hours only', function () {
    [$project, $tech] = reportProject();
    loggedMinutes($project, $tech['Angular']->id, 60);

    $assistant = userWithRole(RoleName::Assistant);
    $row = app(BuildEstimateReport::class)->handle($assistant, new EstimateFilters)->first();
    $totals = app(BuildEstimateReport::class)->totals($assistant, new EstimateFilters);

    $angular = line($row->lines, 'Angular');

    expect($angular->loggedMinutes)->toBe(60)->and($angular->value)->toBeNull()->and($row->value)->toBeNull()
        ->and($totals['values'])->toBe([])->and($totals['logged_minutes'])->toBe(60)
        ->and(reportFor(RoleName::Superadmin)->first()->value->minor)->toBe(2000);
})->group('M7', 'D-05');

it('M7 D-49 needs projects.view and logs.view_any: hr and employees are refused', function (RoleName $role, bool $allowed) {
    if ($allowed) {
        expect(reportFor($role))->not->toBeNull();
    } else {
        expect(fn () => reportFor($role))->toThrow(AuthorizationException::class);
    }
})->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Assistant, true], [RoleName::Hr, false], [RoleName::Employee, false],
])->group('M7', 'D-49');

it('M7 BR-12 groups values by currency and never converts', function () {
    [$eur, $tech] = reportProject();
    [$usd, $tech2] = reportProject(['currency_code' => 'USD']);
    loggedMinutes($eur, $tech['Angular']->id, 60);
    loggedMinutes($usd, $tech2['Angular']->id, 60, ['rate_currency' => 'USD', 'rate_minor' => 3000]);

    $totals = app(BuildEstimateReport::class)->totals(userWithRole(RoleName::Admin), new EstimateFilters);

    expect(array_keys($totals['values']))->toBe(['EUR', 'USD'])
        ->and($totals['values']['EUR']->minor)->toBe(2000)->and($totals['values']['USD']->minor)->toBe(3000)
        ->and($totals['estimated_hundredths'])->toBe(3000)->and($totals['logged_minutes'])->toBe(120);
})->group('M7', 'BR-12');

it('M7 filters by client, project and status, and paginates projects with totals over all of them', function () {
    [$one] = reportProject();
    [$two, , $clientTwo] = reportProject();
    $two->update(['status' => ProjectStatus::Completed]);
    foreach (range(1, 26) as $i) {
        Project::factory()->create(['customer_id' => $clientTwo->id, 'name' => sprintf('Bulk %02d', $i)]);
    }
    $admin = userWithRole(RoleName::Admin);
    $report = app(BuildEstimateReport::class);

    expect($report->handle($admin, new EstimateFilters(customerId: $clientTwo->id))->total())->toBe(27)
        ->and($report->handle($admin, new EstimateFilters(customerId: $clientTwo->id), 2)->count())->toBe(2)   // 27 projects, 25 on a page
        ->and($report->handle($admin, new EstimateFilters(projectId: $one->id))->total())->toBe(1)
        ->and($report->handle($admin, new EstimateFilters(status: ProjectStatus::Completed))->total())->toBe(1)
        ->and($report->totals($admin, new EstimateFilters(customerId: $clientTwo->id))['estimated_hundredths'])->toBeGreaterThanOrEqual(1500);
})->group('M7', 'NFR-11');

it('M7 the report page opens for roles that may and not for those who may not, without N+1 queries', function () {
    [$project, $tech] = reportProject();
    foreach (range(1, 5) as $i) {
        loggedMinutes($project, $tech['Angular']->id, 30 * $i);
    }

    foreach ([RoleName::Superadmin, RoleName::Admin, RoleName::Assistant] as $role) {
        $this->actingAs(userWithRole($role))->get('/app/estimate-vs-logged')->assertOk();
    }
    foreach ([RoleName::Hr, RoleName::Employee] as $role) {
        $this->actingAs(userWithRole($role))->get('/app/estimate-vs-logged')->assertForbidden();
    }
})->group('M7', 'NFR-09');

it('M7 the page shows hours to everyone allowed and prices only to rate holders', function () {
    [$project, $tech] = reportProject();
    loggedMinutes($project, $tech['Angular']->id, 90);

    $this->actingAs(userWithRole(RoleName::Admin));
    Livewire::test(EstimateVsLogged::class)->assertSee('Portal')->assertSee('1.50')->assertSee('EUR 30.00');

    $this->actingAs(userWithRole(RoleName::Assistant));
    Livewire::test(EstimateVsLogged::class)->assertSee('1.50')->assertDontSee('EUR 30.00')->assertDontSee('Logged value');
})->group('M7', 'D-05');
