<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\Work\Actions\CreateLog;
use App\Domain\Work\Support\LogChoices;
use App\Filament\Resources\LeaveRequests\Pages\ManageLeaveRequests;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * @return list<string> the labels of every navigation item the signed-in user sees, groups flattened
 */
function navigationLabelsFor(RoleName $role): array
{
    test()->actingAs(userWithRole($role));
    Filament::setCurrentPanel('app');

    return collect(Filament::getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item): string => (string) $item->getLabel())
        ->values()
        ->all();
}

it('D-63 the employee sidebar holds only work and leave screens', function (): void {
    $labels = navigationLabelsFor(RoleName::Employee);

    expect($labels)->not->toContain('Projects')
        ->and($labels)->not->toContain('Clients')
        ->and($labels)->not->toContain('Bank holidays')
        ->and($labels)->not->toContain('Settings');
})->group('D-63');

it('D-63 an employee who types the address of a hidden screen is refused', function (string $url): void {
    $this->actingAs(userWithRole(RoleName::Employee));

    $this->get($url)->assertForbidden();
})->with([
    'projects' => '/app/projects',
    'new project' => '/app/projects/create',
    'clients' => '/app/clients',
    'new client' => '/app/clients/create',
    'bank holidays' => '/app/bank-holidays',
    'settings' => '/app/manage-settings',
])->group('D-63');

it('D-63 finance, HR and the assistant still reach projects and clients', function (RoleName $role): void {
    $this->actingAs(userWithRole($role));

    $this->get('/app/projects')->assertOk();
    $this->get('/app/clients')->assertOk();
})->with([RoleName::Finance, RoleName::Hr, RoleName::Assistant, RoleName::Admin])->group('D-63');

it('D-64 only the superadmin reaches Settings', function (RoleName $role, int $status): void {
    $this->actingAs(userWithRole($role));

    $this->get('/app/manage-settings')->assertStatus($status);
})->with([
    'superadmin' => [RoleName::Superadmin, 200],
    'admin' => [RoleName::Admin, 403],
    'hr' => [RoleName::Hr, 403],
    'assistant' => [RoleName::Assistant, 403],
    'finance' => [RoleName::Finance, 403],
])->group('D-64');

it('D-63 an employee still logs time to a project they are a member of, without any project permission', function (): void {
    $world = loggingSetup();
    $employee = $world['employee'];

    expect($employee->can('viewAny', Project::class))->toBeFalse()
        ->and(LogChoices::directProjectOptions($employee))->toHaveKey($world['project']->id);

    $log = resolve(CreateLog::class)->handle($employee, logData([
        'taskId' => null, 'projectId' => $world['project']->id, 'technologyId' => $world['technology']->id,
    ]));

    expect($log->exists)->toBeTrue();
    $this->actingAs($employee)->get('/app/logs/create')->assertOk();
})->group('D-63');

it('D-63 the employee still sees bank holidays on the Leave Requests calendar', function (): void {
    $world = leaveSetup();
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Founders Day', 'start_date' => '2026-06-08', 'end_date' => '2026-06-08']);
    $this->actingAs($world['employee']);

    $page = Livewire::test(ManageLeaveRequests::class)->set('activeTab', 'calendar')->set('calendarMonth', '2026-06');
    $holidays = collect($page->instance()->calendar()['weeks'])->flatten(1)->filter()->pluck('holidays')->flatten()->all();

    expect($holidays)->toContain('Founders Day');
    $this->get('/app/bank-holidays')->assertForbidden();
})->group('D-63');

/**
 * @return array<string, list<string>> navigation group label => item labels, for the signed-in user
 */
function navigationGroupsFor(RoleName $role): array
{
    test()->actingAs(userWithRole($role));
    Filament::setCurrentPanel('app');

    return collect(Filament::getNavigation())
        ->mapWithKeys(fn ($group): array => [(string) $group->getLabel() => collect($group->getItems())
            ->map(fn ($item): string => (string) $item->getLabel())->values()->all()])
        ->all();
}

it('the Tasks menu sits under Clients & projects for those who create tasks, and under Work for the rest', function (RoleName $role, string $group, string $other): void {
    $groups = navigationGroupsFor($role);

    expect($groups[$group])->toContain('Tasks')
        ->and($groups[$other] ?? [])->not->toContain('Tasks');
})->with([
    'superadmin' => [RoleName::Superadmin, 'Clients & projects', 'Work'],
    'admin' => [RoleName::Admin, 'Clients & projects', 'Work'],
    'assistant' => [RoleName::Assistant, 'Work', 'Clients & projects'],
    'employee' => [RoleName::Employee, 'Work', 'Clients & projects'],
]);
