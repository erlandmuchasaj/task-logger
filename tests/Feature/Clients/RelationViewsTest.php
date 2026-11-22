<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\LogsRelationManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Date;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** D-80: Projects tab on a client, Tasks tab on a project, Logs tab on a task. */
function relationProject(?array $client = null, array $overrides = []): Project
{
    [$customer, $tech] = $client ?? clientWithRates(['Angular' => 2000]);

    return resolve(CreateProject::class)->handle(userWithRole(RoleName::Superadmin), projectData($customer, [$tech['Angular']->id => '10'], $overrides));
}

function projectsTab(Customer $client): Testable
{
    return Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewCustomer::class]);
}

function tasksTab(Project $project): Testable
{
    return Livewire::test(TasksRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class]);
}

function logsTab(Task $task): Testable
{
    return Livewire::test(LogsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class]);
}

it('D-80 creates a project from the client page, with the client fixed, through CreateProject', function (RoleName $role): void {
    [$client, $tech] = clientWithRates();
    $manager = User::factory()->withRole(RoleName::Admin)->create();
    $this->actingAs(userWithRole($role));

    projectsTab($client)
        ->callAction(TestAction::make('create')->table(), [
            'project_type' => 'standalone', 'name' => 'From the client page', 'manager_id' => $manager->id,
            'start_date' => '2026-01-01', 'status' => 'active',
            'technologies' => [['technology_id' => $tech['Angular']->id, 'estimated_hours' => '10']],
        ])
        ->assertHasNoFormErrors();

    $project = Project::query()->where('name', 'From the client page')->sole();

    expect($project->customer_id)->toBe($client->id)
        ->and($project->budget_minor)->toBe(20000)
        ->and(latestActivity()->causer_id)->not->toBeNull();
})->group('D-80')->with([RoleName::Superadmin, RoleName::Admin]);

it('D-80 offers no New project button to people who may not create projects, or on a deactivated client', function (): void {
    [$client] = clientWithRates();

    $this->actingAs(userWithRole(RoleName::Hr));
    projectsTab($client)->assertActionHidden(TestAction::make('create')->table());

    $inactive = Customer::factory()->create(['is_active' => false]);
    $this->actingAs(userWithRole(RoleName::Admin));
    projectsTab($inactive)->assertActionHidden(TestAction::make('create')->table());
})->group('D-80');

it('D-80 shows the client projects and gates the budget column with clients.view_rates', function (): void {
    $project = relationProject();
    $other = relationProject(null, ['name' => 'Elsewhere']);

    $this->actingAs(userWithRole(RoleName::Superadmin));
    projectsTab($project->customer)
        ->assertCanSeeTableRecords([$project])->assertCanNotSeeTableRecords([$other])
        ->assertTableColumnVisible('budget_minor');

    $this->actingAs(userWithRole(RoleName::Hr));
    projectsTab($project->customer)->assertTableColumnHidden('budget_minor');
})->group('D-80', 'D-05');

it('D-80 adds and edits a task from the project page through CreateTask and UpdateTask', function (): void {
    $project = relationProject();
    $assignee = userWithRole(RoleName::Employee);
    $technologyId = $project->technologyLines()->value('technology_id');
    $this->actingAs(userWithRole(RoleName::Admin));

    tasksTab($project)
        ->callAction(TestAction::make('create')->table(), [
            'name' => 'Build login', 'technology_id' => $technologyId, 'status' => 'backlog', 'priority' => 'medium',
            'estimated_hours' => '4', 'assignee_id' => $assignee->id,
        ])
        ->assertHasNoFormErrors();

    $task = Task::query()->where('name', 'Build login')->sole();

    expect($task->project_id)->toBe($project->id)->and($task->assignee_id)->toBe($assignee->id);

    tasksTab($project)
        ->callAction(TestAction::make('edit')->table($task), [
            'name' => 'Build login page', 'technology_id' => $technologyId, 'status' => 'in_progress', 'priority' => 'high',
            'estimated_hours' => '6', 'assignee_id' => $assignee->id,
        ])
        ->assertHasNoFormErrors();

    expect($task->fresh()->name)->toBe('Build login page')->and($task->fresh()->project_id)->toBe($project->id);
})->group('D-80');

it('D-80 an employee sees only their own tasks on the project page and cannot add or edit', function (): void {
    $project = relationProject();
    $technologyId = $project->technologyLines()->value('technology_id');
    $me = userWithRole(RoleName::Employee);
    $mine = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $technologyId, 'assignee_id' => $me->id]);
    $theirs = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $technologyId]);
    $this->actingAs($me);

    tasksTab($project)
        ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($mine));
})->group('D-80', 'BR-09');

it('D-80 the assignee logs time from the task page, for themselves, through CreateLog', function (): void {
    $setup = loggingSetup();
    $task = $setup['task'];
    $this->actingAs($setup['employee']);

    logsTab($task)
        ->callAction(TestAction::make('create')->table(), ['date' => Date::today()->toDateString(), 'hours' => 2, 'minutes' => 30, 'log_type' => 'normal_hours', 'description' => 'Pairing'])
        ->assertHasNoFormErrors();

    $log = Log::query()->sole();

    expect($log->task_id)->toBe($task->id)->and($log->user_id)->toBe($setup['employee']->id)
        ->and($log->duration_minutes)->toBe(150)->and($log->rate_minor)->toBe(2000);
})->group('D-80', 'BR-06');

it('D-80 a log from the task page obeys the 24-hour rule and reports it on the form', function (): void {
    $setup = loggingSetup();
    $this->actingAs($setup['employee']);

    logsTab($setup['task'])
        ->callAction(TestAction::make('create')->table(), ['date' => Date::today()->toDateString(), 'hours' => 24, 'minutes' => 0, 'log_type' => 'normal_hours'])
        ->callAction(TestAction::make('create')->table(), ['date' => Date::today()->toDateString(), 'hours' => 1, 'minutes' => 0, 'log_type' => 'normal_hours'])
        ->assertHasFormErrors(['minutes']);

    expect(Log::query()->count())->toBe(1);
})->group('D-80', 'BR-04');

it('D-80 offers Log time only to the assignee of an open task', function (): void {
    $setup = loggingSetup();
    $task = $setup['task'];

    $this->actingAs(userWithRole(RoleName::Admin));
    logsTab($task)->assertActionHidden(TestAction::make('create')->table());

    $this->actingAs($setup['employee']);
    logsTab($task)->assertActionVisible(TestAction::make('create')->table());

    $task->update(['status' => TaskStatus::Completed]);
    logsTab($task->fresh())->assertActionHidden(TestAction::make('create')->table());
})->group('D-80');

it('D-80 the task logs follow the Logs screen: everyone for logs.view_any, otherwise your own, rates only with view_rates', function (): void {
    $setup = loggingSetup();
    $task = $setup['task'];
    $colleague = userWithRole(RoleName::Employee);
    $mine = Log::factory()->create(['user_id' => $setup['employee']->id, 'task_id' => $task->id, 'project_id' => $setup['project']->id, 'technology_id' => $setup['technology']->id]);
    $theirs = Log::factory()->create(['user_id' => $colleague->id, 'task_id' => $task->id, 'project_id' => $setup['project']->id, 'technology_id' => $setup['technology']->id]);

    $this->actingAs($setup['employee']);
    logsTab($task)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs])
        ->assertTableColumnHidden('rate_minor')->assertTableColumnHidden('user.name');

    $this->actingAs(userWithRole(RoleName::Assistant));
    logsTab($task)->assertCanSeeTableRecords([$mine, $theirs])->assertTableColumnVisible('user.name')->assertTableColumnHidden('rate_minor');

    $this->actingAs(userWithRole(RoleName::Superadmin));
    logsTab($task)->assertTableColumnVisible('rate_minor');
})->group('D-80', 'D-05');
