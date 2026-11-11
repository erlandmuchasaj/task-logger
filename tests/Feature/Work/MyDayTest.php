<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Support\LogChoices;
use App\Filament\Pages\MyDay;
use App\Filament\Resources\Logs\Pages\CreateLog;
use App\Filament\Resources\Logs\Pages\ListLogs;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('BR-08 a task with force_to_log is pinned for its assignee until it is completed or cancelled', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $task->update(['force_to_log' => true, 'status' => TaskStatus::InProgress]);

    expect(LogChoices::pinnedTasks($me)->pluck('id')->all())->toBe([$task->id]);

    foreach ([TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::InReview] as $open) {
        $task->update(['status' => $open]);
        expect(LogChoices::pinnedTasks($me))->toHaveCount(1, $open->value);
    }

    $task->update(['status' => TaskStatus::Completed]);
    expect(LogChoices::pinnedTasks($me))->toHaveCount(0);

    $task->update(['status' => TaskStatus::Cancelled]);
    expect(LogChoices::pinnedTasks($me))->toHaveCount(0);
})->group('BR-08');

it('BR-08 only the assignee sees the pin, and only tasks with force_to_log are pinned', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $other = userWithRole(RoleName::Employee);
    $task->update(['force_to_log' => true]);
    $plain = Task::factory()->create(['project_id' => $task->project_id, 'technology_id' => $task->technology_id, 'assignee_id' => $me->id, 'force_to_log' => false]);

    expect(LogChoices::pinnedTasks($other))->toHaveCount(0)
        ->and(LogChoices::pinnedTasks($me)->pluck('id')->all())->toBe([$task->id])
        ->and(LogChoices::taskOptions($me))->toHaveKeys([$task->id, $plain->id]);
})->group('BR-08');

it('BR-08 the pin stays every day: it does not depend on whether you logged yesterday', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $task->update(['force_to_log' => true]);

    foreach (['2026-03-10', '2026-03-11', '2026-06-01'] as $day) {
        $this->travelTo(Carbon::parse($day));
        expect(LogChoices::pinnedTasks($me))->toHaveCount(1);
    }
})->group('BR-08');

it('BR-08 pinned tasks are shown on My Day, and not-pinned or closed ones are not', function () {
    ['employee' => $me, 'task' => $task, 'project' => $project] = loggingSetup();
    $task->update(['force_to_log' => true, 'name' => 'Daily standup notes']);
    $closed = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $task->technology_id, 'assignee_id' => $me->id, 'force_to_log' => true, 'status' => TaskStatus::Completed, 'name' => 'Finished chore']);
    $this->actingAs($me);

    Livewire::test(MyDay::class)->assertSee('Pinned tasks')->assertSee('Daily standup notes')->assertDontSee('Finished chore');
    expect($closed->exists)->toBeTrue();
})->group('BR-08');

it('BR-08 submitting the day is never blocked by an unlogged pinned task', function () {
    ['employee' => $me, 'task' => $task, 'project' => $project, 'technology' => $tech] = loggingSetup();
    $task->update(['force_to_log' => true]);
    $this->actingAs($me);

    Livewire::test(MyDay::class)
        ->fillForm(['direct' => true, 'project_id' => $project->id, 'technology_id' => $tech->id, 'hours' => 1, 'minutes' => 0, 'log_type' => 'normal_hours'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Log::where('user_id', $me->id)->count())->toBe(1);
})->group('BR-08');

it('BR-08 a pinned task button prefills the form with that task', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $task->update(['force_to_log' => true]);
    $this->actingAs($me);

    Livewire::test(MyDay::class)->call('useTask', $task->id)->assertSet('data.task_id', $task->id)->assertSet('data.direct', false);

    // a task that is not pinned for you cannot be injected
    $other = userWithRole(RoleName::Employee);
    $this->actingAs($other);
    Livewire::test(MyDay::class)->call('useTask', $task->id)->assertSet('data.task_id', null);
})->group('BR-08');

it('BR-03 unchecked lists your own tasks; checked lists only projects you may log to directly', function () {
    ['employee' => $me, 'task' => $task, 'project' => $project, 'client' => $client] = loggingSetup();
    $other = userWithRole(RoleName::Employee);
    [$c2, $t2] = clientWithRates(['Ionic' => 1500]);
    $noDirect = Project::factory()->create(['customer_id' => $client->id, 'can_log_directly_to_project' => false, 'name' => 'Tasks only']);
    $notMember = Project::factory()->create(['customer_id' => $client->id, 'can_log_directly_to_project' => true, 'name' => 'Not a member']);
    $inactiveClient = Customer::factory()->inactive()->create();
    $inactive = Project::factory()->create(['customer_id' => $inactiveClient->id, 'can_log_directly_to_project' => true, 'name' => 'Inactive client project']);
    foreach ([$noDirect, $inactive] as $p) {
        $p->memberships()->create(['user_id' => $me->id]);
    }

    expect(LogChoices::taskOptions($me))->toHaveKey($task->id)
        ->and(LogChoices::taskOptions($other))->toBe([])
        ->and(LogChoices::directProjectOptions($me))->toBe([$project->id => $project->name])
        ->and(array_keys(LogChoices::directProjectOptions($me)))->not->toContain($noDirect->id, $notMember->id, $inactive->id)
        ->and(LogChoices::directProjectOptions($other))->toBe([])
        ->and($c2->exists && $t2 !== [])->toBeTrue();
})->group('BR-03');

it('BR-03 closed tasks are not offered, except the one on the log being edited', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $task->update(['status' => TaskStatus::Completed]);

    expect(LogChoices::taskOptions($me))->toBe([])
        ->and(LogChoices::taskOptions($me, $task->id))->toHaveKey($task->id);
})->group('BR-03');

it('BR-03 the technologies offered for a direct log are the project\'s', function () {
    ['project' => $project, 'technology' => $tech] = loggingSetup();

    expect(LogChoices::projectTechnologyOptions($project->id))->toBe([$tech->id => $tech->name])
        ->and(LogChoices::projectTechnologyOptions(null))->toBe([])
        ->and(LogChoices::projectTechnologyOptions(999999))->toBe([]);
})->group('BR-03');

it('BR-03 My Day logs time on a task', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $this->actingAs($me);

    Livewire::test(MyDay::class)
        ->fillForm(['task_id' => $task->id, 'hours' => 2, 'minutes' => 30, 'log_type' => 'extra_hours', 'description' => 'Fixed the bug'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSee('2:30');

    $log = Log::sole();
    expect($log->task_id)->toBe($task->id)->and($log->duration_minutes)->toBe(150)->and($log->log_type->value)->toBe('extra_hours')
        ->and($log->date->toDateString())->toBe(now()->toDateString());
})->group('BR-03');

it('BR-03 My Day logs time directly to a project with the checkbox on', function () {
    ['employee' => $me, 'project' => $project, 'technology' => $tech] = loggingSetup();
    $this->actingAs($me);

    Livewire::test(MyDay::class)
        ->fillForm(['direct' => true, 'project_id' => $project->id, 'technology_id' => $tech->id, 'hours' => 0, 'minutes' => 45])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Log::sole())->task_id->toBeNull()->project_id->toBe($project->id)->duration_minutes->toBe(45);
})->group('BR-03');

it('BR-04 My Day shows the rule that was broken and saves nothing', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $this->actingAs($me);

    Livewire::test(MyDay::class)
        ->fillForm(['task_id' => $task->id, 'hours' => 0, 'minutes' => 0])
        ->call('save')
        ->assertHasErrors(['data.minutes']);

    Livewire::test(MyDay::class)
        ->fillForm(['task_id' => $task->id, 'hours' => 1, 'minutes' => 75])
        ->call('save')
        ->assertHasErrors();

    expect(Log::count())->toBe(0);
})->group('BR-04');

it('BR-06 My Day explains when the client has no rate for the date', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup(2000, '2026-01-01');
    $this->actingAs($me);

    Livewire::test(MyDay::class)
        ->fillForm(['date' => '2025-12-01', 'task_id' => $task->id, 'hours' => 1, 'minutes' => 0])
        ->call('save')
        ->assertHasErrors(['data.date']);

    expect(Log::count())->toBe(0);
})->group('BR-06');

it('BR-04 My Day shows the day total and the logs of the chosen day', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $this->actingAs($me);
    $logs = collect([90, 30])->map(fn (int $m) => Log::factory()->create(['user_id' => $me->id, 'project_id' => $task->project_id, 'technology_id' => $task->technology_id, 'task_id' => $task->id, 'date' => now()->toDateString(), 'duration_minutes' => $m, 'description' => "worked {$m}"]));
    $elsewhere = Log::factory()->create(['user_id' => $me->id, 'project_id' => $task->project_id, 'technology_id' => $task->technology_id, 'date' => '2026-01-05', 'duration_minutes' => 600, 'description' => 'other day']);

    Livewire::test(MyDay::class)
        ->assertSee('Logged on this day: 2h 0m of 24h')
        ->assertCanSeeTableRecords($logs)
        ->assertCanNotSeeTableRecords([$elsewhere])
        ->set('data.date', '2026-01-05')
        ->assertSee('Logged on this day: 10h 0m of 24h')
        ->assertCanSeeTableRecords([$elsewhere]);
})->group('BR-04');

it('FR-25 every role can open My Day, because logging time is own-time', function (RoleName $role) {
    $this->actingAs(userWithRole($role))->get('/app/my-day')->assertOk();
})->with(RoleName::cases());

it('FR-26 the Logs list shows everyone\'s logs to superadmin, admin and assistant, and only your own to others', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $mine = Log::factory()->create(['user_id' => $me->id, 'project_id' => $task->project_id, 'technology_id' => $task->technology_id, 'description' => 'mine']);
    $theirs = Log::factory()->create(['user_id' => userWithRole(RoleName::Employee)->id, 'project_id' => $task->project_id, 'technology_id' => $task->technology_id, 'description' => 'theirs']);

    $this->actingAs($me);
    Livewire::test(ListLogs::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);

    foreach ([RoleName::Superadmin, RoleName::Admin, RoleName::Assistant] as $role) {
        $this->actingAs(userWithRole($role));
        Livewire::test(ListLogs::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    $this->actingAs(userWithRole(RoleName::Hr)); // hr holds logs.view_own only
    Livewire::test(ListLogs::class)->assertCanNotSeeTableRecords([$mine, $theirs]);
})->group('BR-01');

it('D-05 the Logs list shows rate and amount only to people who may see rates', function (RoleName $role, bool $sees) {
    ['task' => $task] = loggingSetup();
    Log::factory()->create(['user_id' => userWithRole(RoleName::Employee)->id, 'project_id' => $task->project_id, 'technology_id' => $task->technology_id, 'rate_minor' => 3333, 'duration_minutes' => 60]);
    $this->actingAs(userWithRole($role));

    $page = Livewire::test(ListLogs::class);
    $sees ? $page->assertSee('EUR 33.33') : $page->assertDontSee('EUR 33.33')->assertDontSee('Rate/h');
})->group('BR-06')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Assistant, false],
]);

it('FR-24 the full Create Log screen also works, with the same rules', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $this->actingAs($me);

    Livewire::test(CreateLog::class)
        ->fillForm(['task_id' => $task->id, 'date' => '2026-03-10', 'hours' => 1, 'minutes' => 30, 'log_type' => 'normal_hours'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Log::sole()->duration_minutes)->toBe(90);

    Livewire::test(CreateLog::class)
        ->fillForm(['task_id' => $task->id, 'date' => '2026-03-10', 'hours' => 23, 'minutes' => 0])
        ->call('create')
        ->assertHasErrors(['data.minutes']);
})->group('BR-04');
