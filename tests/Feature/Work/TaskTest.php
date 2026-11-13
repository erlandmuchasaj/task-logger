<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Models\ProjectTechnology;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\Technology;
use App\Domain\Shared\Priority;
use App\Domain\Work\Actions\AssignTask;
use App\Domain\Work\Actions\ChangeTaskStatus;
use App\Domain\Work\Actions\CreateTask;
use App\Domain\Work\Actions\DeleteTask;
use App\Domain\Work\Actions\UpdateTask;
use App\Domain\Work\Data\TaskData;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Notifications\TaskAssigned;
use App\Domain\Work\Support\TaskHierarchy;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Logs\Pages\CreateLog as CreateLogPage;
use App\Filament\Resources\Tasks\Pages\CreateTask as CreateTaskPage;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** A project with one technology, ready for tasks. */
function taskProject(array $projectOverrides = []): Project
{
    [$client, $tech] = clientWithRates(['Angular' => 2000]);
    $project = resolve(CreateProject::class)->handle(userWithRole(RoleName::Superadmin), projectData($client, [$tech['Angular']->id => '100'], $projectOverrides));

    return $project->load('customer');
}

function taskData(Project $project, array $o = []): TaskData
{
    return new TaskData(...array_merge([
        'name' => 'Build login',
        'description' => null,
        'projectId' => $project->id,
        'technologyId' => $project->technologyLines()->value('technology_id'),
        'status' => TaskStatus::Todo,
        'priority' => Priority::High,
        'estimatedHours' => '8',
    ], $o));
}

it('FR-22 W-03 superadmin and admin create a task with technology, estimate, assignee and labels', function (RoleName $role): void {
    $project = taskProject();
    $assignee = userWithRole(RoleName::Employee);
    $label = Label::factory()->create();

    $task = resolve(CreateTask::class)->handle(userWithRole($role), taskData($project, ['assigneeId' => $assignee->id, 'labelIds' => [$label->id], 'forceToLog' => true]));

    expect($task->assignee_id)->toBe($assignee->id)
        ->and($task->estimated_hours)->toBe('8.00')
        ->and($task->force_to_log)->toBeTrue()
        ->and($task->labels()->pluck('labels.id')->all())->toBe([$label->id])
        ->and($task->created_by)->not->toBeNull();
})->group('FR-22')->with([RoleName::Superadmin, RoleName::Admin]);

it('FR-22 hr, assistant and employee cannot create, update, assign or delete tasks', function (RoleName $role): void {
    $project = taskProject();
    $actor = userWithRole($role);
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id')]);

    expect(fn () => resolve(CreateTask::class)->handle($actor, taskData($project)))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(UpdateTask::class)->handle($actor, $task, taskData($project)))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(AssignTask::class)->handle($actor, $task, $actor->id))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(DeleteTask::class)->handle($actor, $task))->toThrow(AuthorizationException::class);
})->group('FR-22')->with([RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('BR-10 a task technology must be one of the project technologies', function (): void {
    $project = taskProject();
    $foreign = Technology::factory()->create(['name' => 'Cobol']);

    resolve(CreateTask::class)->handle(userWithRole(RoleName::Admin), taskData($project, ['technologyId' => $foreign->id]));
})->group('BR-10')->throws(ValidationException::class, 'technologies of this project');

it('BR-10 the database itself refuses a task whose technology is not in the project (composite key)', function (): void {
    $project = taskProject();
    $foreign = Technology::factory()->create(['name' => 'Cobol']);

    expect(fn () => Task::factory()->create(['project_id' => $project->id, 'technology_id' => $foreign->id]))->toThrow(QueryException::class);
})->group('BR-10');

it('BR-09 there is exactly one assignee column and no assignee pivot', function (): void {
    expect(Schema::hasColumn('tasks', 'assignee_id'))->toBeTrue()
        ->and(Schema::hasTable('task_user'))->toBeFalse()
        ->and(Schema::hasTable('task_assignees'))->toBeFalse();
})->group('BR-09');

it('BR-09 assigning replaces the assignee and null unassigns', function (): void {
    $project = taskProject();
    $admin = userWithRole(RoleName::Admin);
    [$a, $b] = [userWithRole(RoleName::Employee), userWithRole(RoleName::Employee)];
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id'), 'assignee_id' => $a->id]);

    resolve(AssignTask::class)->handle($admin, $task, $b->id);
    expect($task->fresh()->assignee_id)->toBe($b->id);

    resolve(AssignTask::class)->handle($admin, $task, null);
    expect($task->fresh()->assignee_id)->toBeNull();
})->group('BR-09');

it('BR-09 a task cannot be assigned to a deactivated user', function (): void {
    $project = taskProject();
    $gone = userWithRole(RoleName::Employee);
    $gone->ban();

    resolve(CreateTask::class)->handle(userWithRole(RoleName::Admin), taskData($project, ['assigneeId' => $gone->id]));
})->group('BR-09')->throws(ValidationException::class, 'active employee');

it('BR-11 the parent must be a task of the same project', function (): void {
    $project = taskProject();
    $other = taskProject();
    $foreignParent = Task::factory()->create(['project_id' => $other->id, 'technology_id' => $other->technologyLines()->value('technology_id')]);

    resolve(CreateTask::class)->handle(userWithRole(RoleName::Admin), taskData($project, ['parentId' => $foreignParent->id]));
})->group('BR-11')->throws(ValidationException::class, 'same project');

it('BR-11 a task can have a parent in the same project', function (): void {
    $project = taskProject();
    $parent = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id')]);

    $child = resolve(CreateTask::class)->handle(userWithRole(RoleName::Admin), taskData($project, ['parentId' => $parent->id]));

    expect($child->parent_id)->toBe($parent->id)->and($parent->children()->count())->toBe(1);
})->group('BR-11');

it('BR-11 the parent chain never loops', function (): void {
    $project = taskProject();
    $tech = $project->technologyLines()->value('technology_id');
    $a = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech]);
    $b = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech, 'parent_id' => $a->id]);
    $c = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech, 'parent_id' => $b->id]);

    expect(TaskHierarchy::wouldCycle($a->id, $a->id))->toBeTrue()
        ->and(TaskHierarchy::wouldCycle($a->id, $c->id))->toBeTrue()
        ->and(TaskHierarchy::wouldCycle($c->id, $a->id))->toBeFalse()
        ->and(TaskHierarchy::wouldCycle($a->id, null))->toBeFalse();

    expect(fn () => resolve(UpdateTask::class)->handle(userWithRole(RoleName::Admin), $a, taskData($project, ['parentId' => $c->id])))
        ->toThrow(ValidationException::class, 'ancestor');
})->group('BR-11');

it('FR-22 the project of an existing task cannot change; the technology can, within the project', function (): void {
    $project = taskProject();
    $other = taskProject();
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id')]);
    $admin = userWithRole(RoleName::Admin);

    expect(fn () => resolve(UpdateTask::class)->handle($admin, $task, taskData($other)))->toThrow(ValidationException::class, 'cannot be changed');

    $second = Technology::factory()->create(['name' => '.NET']);
    ProjectTechnology::query()->create(['project_id' => $project->id, 'technology_id' => $second->id, 'estimated_hours' => 10]);
    resolve(UpdateTask::class)->handle($admin, $task, taskData($project, ['technologyId' => $second->id, 'name' => 'Renamed']));

    expect($task->fresh()->technology_id)->toBe($second->id)->and($task->fresh()->name)->toBe('Renamed');
})->group('FR-22');

it('BR-31 a deactivated client gets no new tasks, existing tasks stay editable', function (): void {
    $project = taskProject();
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id')]);
    $project->customer->update(['is_active' => false]);
    $admin = userWithRole(RoleName::Admin);

    expect(fn () => resolve(CreateTask::class)->handle($admin, taskData($project)))->toThrow(ValidationException::class, 'deactivated');

    resolve(UpdateTask::class)->handle($admin, $task, taskData($project, ['name' => 'Still editable']));
    expect($task->fresh()->name)->toBe('Still editable');
})->group('BR-31');

it('FR-22 estimated hours are validated', function (string $hours): void {
    resolve(CreateTask::class)->handle(userWithRole(RoleName::Admin), taskData(taskProject(), ['estimatedHours' => $hours]));
})->group('FR-22')->throws(ValidationException::class)->with(['abc', '1.234', '-1', '1000000']);

it('FR-22 a task with sub-tasks cannot be deleted; otherwise it is soft deleted', function (): void {
    $project = taskProject();
    $tech = $project->technologyLines()->value('technology_id');
    $parent = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech]);
    Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech, 'parent_id' => $parent->id]);
    $admin = userWithRole(RoleName::Admin);

    expect(fn () => resolve(DeleteTask::class)->handle($admin, $parent))->toThrow(ValidationException::class);

    $leaf = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech]);
    resolve(DeleteTask::class)->handle($admin, $leaf);
    expect(Task::query()->find($leaf->id))->toBeNull()->and(Task::withTrashed()->find($leaf->id))->not->toBeNull();
})->group('FR-22');

it('BR-32 label changes on a task are audited', function (): void {
    $project = taskProject();
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id')]);
    $label = Label::factory()->create();
    Activity::query()->delete();

    resolve(UpdateTask::class)->handle(userWithRole(RoleName::Admin), $task, taskData($project, ['labelIds' => [$label->id]]));

    expect(Activity::query()->where('event', 'labels_changed')->where('subject_id', $task->id)->exists())->toBeTrue();
})->group('BR-32');

// ---- BR-35 -----------------------------------------------------------------------------------

it('BR-35 the assignee changes the status of their own task', function (RoleName $role): void {
    $project = taskProject();
    $me = userWithRole($role);
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id'), 'assignee_id' => $me->id]);

    resolve(ChangeTaskStatus::class)->handle($me, $task, TaskStatus::InProgress);

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);
})->group('BR-35')->with([RoleName::Employee, RoleName::Hr, RoleName::Assistant]);

it('BR-35 someone else cannot change a task they are not assigned to', function (RoleName $role): void {
    $project = taskProject();
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id'), 'assignee_id' => userWithRole(RoleName::Employee)->id]);

    resolve(ChangeTaskStatus::class)->handle(userWithRole($role), $task, TaskStatus::Completed);
})->group('BR-35')->throws(AuthorizationException::class)->with([RoleName::Employee, RoleName::Hr, RoleName::Assistant]);

it('BR-35 superadmin and admin change the status of any task', function (RoleName $role): void {
    $project = taskProject();
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id'), 'assignee_id' => userWithRole(RoleName::Employee)->id]);

    resolve(ChangeTaskStatus::class)->handle(userWithRole($role), $task, TaskStatus::InReview);

    expect($task->fresh()->status)->toBe(TaskStatus::InReview);
})->group('BR-35')->with([RoleName::Superadmin, RoleName::Admin]);

it('BR-35 policy matrix: any, own, creator', function (): void {
    $project = taskProject();
    $tech = $project->technologyLines()->value('technology_id');
    $creator = userWithRole(RoleName::Admin);
    $assignee = userWithRole(RoleName::Employee);
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech, 'created_by' => $creator->id, 'assignee_id' => $assignee->id]);

    expect($assignee->can('changeStatus', $task))->toBeTrue()                         // own
        ->and($creator->can('changeStatus', $task))->toBeTrue()                       // creator (and tasks.change_status)
        ->and(userWithRole(RoleName::Employee)->can('changeStatus', $task))->toBeFalse()
        ->and(userWithRole(RoleName::Hr)->can('changeStatus', $task))->toBeFalse();

    // The creator rule needs tasks.create: a creator without it gets nothing from having created the task.
    $impostor = userWithRole(RoleName::Employee);
    $made = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech, 'created_by' => $impostor->id, 'assignee_id' => $assignee->id]);
    expect($impostor->can('changeStatus', $made))->toBeFalse();
})->group('BR-35');

// ---- visibility ------------------------------------------------------------------------------

it('D-05 BR-09 an employee sees only the tasks assigned to them; admin, assistant and superadmin see all', function (): void {
    $project = taskProject();
    $tech = $project->technologyLines()->value('technology_id');
    $me = userWithRole(RoleName::Employee);
    $mine = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech, 'assignee_id' => $me->id, 'name' => 'Mine']);
    $theirs = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $tech, 'assignee_id' => userWithRole(RoleName::Employee)->id, 'name' => 'Theirs']);

    $this->actingAs($me);
    Livewire::test(ListTasks::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
    $this->get("/app/tasks/{$theirs->id}")->assertNotFound();
    $this->get("/app/tasks/{$mine->id}")->assertOk();

    foreach ([RoleName::Admin, RoleName::Assistant, RoleName::Superadmin] as $role) {
        $this->actingAs(userWithRole($role));
        Livewire::test(ListTasks::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    $this->actingAs(userWithRole(RoleName::Hr)); // hr holds tasks.view_own only
    Livewire::test(ListTasks::class)->assertCanNotSeeTableRecords([$mine, $theirs]);
})->group('BR-09');

it('FR-23 the assignee changes status from the task list; the edit screen stays closed to them', function (): void {
    $project = taskProject();
    $me = userWithRole(RoleName::Employee);
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id'), 'assignee_id' => $me->id, 'status' => TaskStatus::Todo]);
    $this->actingAs($me);

    Livewire::test(ListTasks::class)
        ->assertTableActionVisible('changeStatus', $task)
        ->assertTableActionHidden('delete', $task)
        ->callTableAction('changeStatus', $task, ['status' => 'in_progress']);

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);
    $this->get("/app/tasks/{$task->id}/edit")->assertForbidden();
    $this->get('/app/tasks/create')->assertForbidden();
})->group('FR-23');

it('FR-22 the Create screen creates a task for an admin, then the Edit screen updates it', function (): void {
    $project = taskProject();
    $assignee = userWithRole(RoleName::Employee);
    $this->actingAs(userWithRole(RoleName::Admin));

    Livewire::test(CreateTaskPage::class)
        ->fillForm([
            'project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id'), 'name' => 'From the form',
            'status' => 'todo', 'priority' => 'high', 'estimated_hours' => '12', 'assignee_id' => $assignee->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $task = Task::query()->where('name', 'From the form')->sole();
    expect($task->assignee_id)->toBe($assignee->id);

    Livewire::test(EditTask::class, ['record' => $task->getKey()])
        ->fillForm(['name' => 'Edited on screen'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($task->fresh()->name)->toBe('Edited on screen');
})->group('FR-22');

it('D-55 the assignee gets an email with the task details and links to the task and to log hours', function (): void {
    Notification::fake();
    $project = taskProject();
    $actor = userWithRole(RoleName::Admin);
    $assignee = userWithRole(RoleName::Employee);

    $task = resolve(CreateTask::class)->handle($actor, taskData($project, ['assigneeId' => $assignee->id, 'description' => 'Use the SSO flow', 'dueDate' => now()->addWeek()]));

    Notification::assertSentTo($assignee, TaskAssigned::class, function (TaskAssigned $n) use ($assignee, $task, $actor): bool {
        $mail = $n->toMail($assignee);
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        return $mail->subject === 'New task assigned: Build login'
            && $mail->actionUrl === TaskResource::getUrl('view', ['record' => $task], panel: 'app')
            && str_contains($text, LogResource::getUrl('create', ['task' => $task->id], panel: 'app'))
            && str_contains($text, $task->project->name)
            && str_contains($text, 'Use the SSO flow')
            && str_contains($text, 'High')
            && str_contains($text, $actor->getFilamentName());
    });
    Notification::assertCount(1);
});

it('D-55 reassigning emails only the new assignee; unchanged, removed or self-assigned tasks send nothing', function (): void {
    Notification::fake();
    $project = taskProject();
    $admin = userWithRole(RoleName::Admin);
    $a = userWithRole(RoleName::Employee);
    $b = userWithRole(RoleName::Employee);

    $task = resolve(CreateTask::class)->handle($admin, taskData($project));          // unassigned
    Notification::assertNothingSent();

    resolve(AssignTask::class)->handle($admin, $task, $a->id);
    resolve(AssignTask::class)->handle($admin, $task->fresh(), $a->id);              // unchanged
    resolve(UpdateTask::class)->handle($admin, $task->fresh(), taskData($project, ['assigneeId' => $b->id]));
    resolve(AssignTask::class)->handle($admin, $task->fresh(), null);                // removed
    resolve(AssignTask::class)->handle($admin, $task->fresh(), $admin->id);          // self-assigned

    Notification::assertSentToTimes($a, TaskAssigned::class, 1);
    Notification::assertSentToTimes($b, TaskAssigned::class, 1);
    Notification::assertNotSentTo($admin, TaskAssigned::class);
});

it('D-55 the task page offers "Log hours" to its assignee, and the log form opens with the task chosen', function (): void {
    $project = taskProject();
    $assignee = userWithRole(RoleName::Employee);
    $task = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id'), 'assignee_id' => $assignee->id]);

    $this->actingAs(userWithRole(RoleName::Admin));
    Livewire::test(ViewTask::class, ['record' => $task->getKey()])->assertActionHidden('logHours');

    $this->actingAs($assignee);
    Livewire::test(ViewTask::class, ['record' => $task->getKey()])
        ->assertActionVisible('logHours')
        ->assertActionHasUrl('logHours', LogResource::getUrl('create', ['task' => $task->id]));

    $this->get(LogResource::getUrl('create', ['task' => $task->id]))->assertOk();
    Livewire::withQueryParams(['task' => $task->id])->test(CreateLogPage::class)
        ->assertSchemaStateSet(['task_id' => $task->id, 'direct' => false]);

    // Someone else's task is never preselected.
    $other = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id')]);
    Livewire::withQueryParams(['task' => $other->id])->test(CreateLogPage::class)
        ->assertSchemaStateSet(['task_id' => null]);
});
