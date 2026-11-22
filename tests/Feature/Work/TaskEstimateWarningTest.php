<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Support\TechnologyEstimate;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Tasks\Pages\CreateTask as CreateTaskPage;
use App\Filament\Resources\Tasks\Pages\EditTask;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/** A project that estimates 100 hours of Angular, with $planned hours already spread over five tasks. */
function estimateProject(int $planned = 100): array
{
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(userWithRole(RoleName::Superadmin), projectData($client, [$tech['Angular']->id => '100']));

    foreach (range(1, 5) as $i) {
        Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech['Angular']->id, 'estimated_hours' => $planned / 5]);
    }

    return [$project, $tech['Angular']];
}

it('D-82 sums the tasks of one technology against the project estimate, in hundredths', function (): void {
    [$project, $angular] = estimateProject(100);

    expect(TechnologyEstimate::overrun($project->id, $angular->id, '0'))->toBeNull()          // exactly the estimate is fine
        ->and(TechnologyEstimate::overrun($project->id, $angular->id, '20'))->toMatchArray(['planned' => 12000, 'estimated' => 10000, 'technology' => 'Angular'])
        ->and(TechnologyEstimate::overrun($project->id, $angular->id, '0.01'))->toMatchArray(['planned' => 10001])
        ->and(TechnologyEstimate::notice($project->id, $angular->id, '20'))->toContain('120.00 h')->toContain('100.00 h')->toContain('Angular');
})->group('D-82');

it('D-82 leaves out cancelled tasks, deleted tasks and the task being edited, and ignores input it cannot read', function (): void {
    [$project, $angular] = estimateProject(100);
    $tasks = Task::query()->where('project_id', $project->id)->get();

    $tasks[0]->update(['status' => TaskStatus::Cancelled]);                 // 20 h leave the sum
    $tasks[1]->delete();                                                    // so do these 20 h

    expect(TechnologyEstimate::overrun($project->id, $angular->id, '20'))->toBeNull()                       // 60 + 20 = 80
        ->and(TechnologyEstimate::overrun($project->id, $angular->id, '50'))->toMatchArray(['planned' => 11000])
        ->and(TechnologyEstimate::overrun($project->id, $angular->id, '50', $tasks[2]->id))->toBeNull()     // the edited task's stored 20 h are replaced by 50
        ->and(TechnologyEstimate::overrun($project->id, $angular->id, '50', null, TaskStatus::Cancelled))->toBeNull() // a cancelled task adds nothing
        ->and(TechnologyEstimate::overrun($project->id, $angular->id, 'abc'))->toBeNull()
        ->and(TechnologyEstimate::overrun($project->id, $angular->id, '12.345'))->toBeNull()
        ->and(TechnologyEstimate::overrun($project->id, 999_999, '500'))->toBeNull();                       // not a technology of the project
})->group('D-82');

it('D-82 counts each technology on its own', function (): void {
    [$client, $tech] = clientWithRates(['Angular' => 2000, '.NET' => 2400]);
    $project = resolve(CreateProject::class)->handle(userWithRole(RoleName::Superadmin), projectData($client, [$tech['Angular']->id => '100', $tech['.NET']->id => '10']));
    Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech['Angular']->id, 'estimated_hours' => 90]);

    expect(TechnologyEstimate::overrun($project->id, $tech['.NET']->id, '10'))->toBeNull()
        ->and(TechnologyEstimate::overrun($project->id, $tech['Angular']->id, '10'))->toBeNull()
        ->and(TechnologyEstimate::overrun($project->id, $tech['Angular']->id, '11'))->not->toBeNull();
})->group('D-82');

it('D-82 the form warns as the hours go over, and the task is still created, with a notification', function (): void {
    [$project, $angular] = estimateProject(100);
    $this->actingAs(userWithRole(RoleName::Admin)); // not the superadmin

    $page = Livewire::test(CreateTaskPage::class)
        ->fillForm(['project_id' => $project->id, 'technology_id' => $angular->id, 'name' => 'The sixth', 'status' => 'todo', 'priority' => 'high', 'estimated_hours' => '0'])
        ->assertSchemaComponentHidden('estimateNotice')
        ->fillForm(['estimated_hours' => '20'])
        ->assertSchemaComponentVisible('estimateNotice');

    expect($page->instance()->getSchema('form')->getFlatComponents(withHidden: false)['estimateNotice']->getDescription())
        ->toContain('120.00 h')->toContain('100.00 h');

    $page->call('create')->assertHasNoFormErrors()->assertNotified('Technology estimate exceeded');

    expect(Task::query()->where('name', 'The sixth')->exists())->toBeTrue();
})->group('D-82');

it('D-82 stays quiet while the tasks fit the estimate', function (): void {
    [$project, $angular] = estimateProject(80);
    $this->actingAs(userWithRole(RoleName::Admin));

    Livewire::test(CreateTaskPage::class)
        ->fillForm(['project_id' => $project->id, 'technology_id' => $angular->id, 'name' => 'Fits', 'status' => 'todo', 'priority' => 'high', 'estimated_hours' => '20'])
        ->assertSchemaComponentHidden('estimateNotice')
        ->call('create')->assertHasNoFormErrors()->assertNotNotified('Technology estimate exceeded');

    expect(Task::query()->where('name', 'Fits')->exists())->toBeTrue();
})->group('D-82');

it('D-82 editing a task does not count it twice, and warns when the edit takes it over', function (): void {
    [$project, $angular] = estimateProject(100);
    $task = Task::query()->where('project_id', $project->id)->first();
    $this->actingAs(userWithRole(RoleName::Admin));

    Livewire::test(EditTask::class, ['record' => $task->getKey()])
        ->assertSchemaComponentHidden('estimateNotice')                    // 100 h of 100 h, with its own 20 h counted once
        ->fillForm(['estimated_hours' => '25'])
        ->assertSchemaComponentVisible('estimateNotice')
        ->call('save')->assertHasNoFormErrors()->assertNotified('Technology estimate exceeded');

    expect($task->fresh()->estimated_hours)->toBe('25.00');
})->group('D-82');

it('D-82 the project Tasks tab warns too, and still adds the task', function (): void {
    [$project, $angular] = estimateProject(100);
    $this->actingAs(userWithRole(RoleName::Admin));

    Livewire::test(TasksRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['technology_id' => $angular->id, 'name' => 'From the tab', 'status' => 'todo', 'priority' => 'high', 'estimated_hours' => '20'])
        ->assertSchemaComponentVisible('estimateNotice')
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Technology estimate exceeded');

    expect(Task::query()->where('name', 'From the tab')->exists())->toBeTrue();
})->group('D-82');
