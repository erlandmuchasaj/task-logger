<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ChangeProjectStatus;
use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Actions\DeleteProject;
use App\Domain\Clients\Actions\UpdateProject;
use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Actions\UpdateEmployee;
use App\Domain\Work\Models\Task;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\FixedCostsRelationManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function makeProject(?array $client = null, array $hours = ['Angular' => '10'], array $overrides = []): Project
{
    [$customer, $tech] = $client ?? clientWithRates(['Angular' => 2000, '.NET' => 2400]);
    $byId = [];
    foreach ($hours as $name => $h) {
        $byId[$tech[$name]->id] = $h;
    }

    return app(CreateProject::class)->handle(userWithRole(RoleName::Superadmin), projectData($customer, $byId, $overrides));
}

it('FR-19 superadmin and admin create a project with technologies, members and a generated slug', function (RoleName $role) {
    [$client, $tech] = clientWithRates();
    $member = userWithRole(RoleName::Employee);

    $project = app(CreateProject::class)->handle(userWithRole($role), projectData($client, [$tech['Angular']->id => '230'], ['name' => 'Big Website', 'memberIds' => [$member->id], 'canLogDirectly' => true]));

    expect($project->slug)->toBe('big-website')
        ->and($project->technologyLines()->sole()->estimated_hours)->toBe('230.00')
        ->and($project->members()->pluck('users.id')->all())->toBe([$member->id])
        ->and($project->can_log_directly_to_project)->toBeTrue();
})->group('FR-19')->with([RoleName::Superadmin, RoleName::Admin]);

it('FR-19 the slug stays stable when a project is renamed', function () {
    $project = makeProject();

    app(UpdateProject::class)->handle(userWithRole(RoleName::Admin), $project, projectData($project->customer, [$project->technologyLines()->value('technology_id') => '10'], ['name' => 'Renamed', 'managerId' => $project->manager_id]));

    expect($project->fresh()->name)->toBe('Renamed')->and($project->fresh()->slug)->toBe($project->slug);
})->group('FR-19');

it('FR-19 hr, assistant and employee cannot create or update projects', function (RoleName $role) {
    [$client, $tech] = clientWithRates();
    $actor = userWithRole($role);
    $project = makeProject();

    expect(fn () => app(CreateProject::class)->handle($actor, projectData($client, [$tech['Angular']->id => '1'])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateProject::class)->handle($actor, $project, projectData($project->customer, [], ['managerId' => $project->manager_id])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteProject::class)->handle($actor, $project))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeProjectStatus::class)->handle($actor, $project, ProjectStatus::Completed))->toThrow(AuthorizationException::class);
})->group('FR-19')->with([RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('BR-10 a project can only use technologies the client has a rate for', function () {
    [$client] = clientWithRates(['Angular' => 2000]);
    $foreign = Technology::factory()->create(['name' => 'Cobol']);

    app(CreateProject::class)->handle(userWithRole(RoleName::Admin), projectData($client, [$foreign->id => '10']));
})->group('BR-10')->throws(ValidationException::class, 'rate for this client');

it('BR-10 estimated hours must be positive with at most two decimals', function (string $hours) {
    [$client, $tech] = clientWithRates(['Angular' => 2000]);

    app(CreateProject::class)->handle(userWithRole(RoleName::Admin), projectData($client, [$tech['Angular']->id => $hours]));
})->group('BR-10')->throws(ValidationException::class)->with(['0', '-5', '1.234', 'abc', '1000000']);

it('BR-10 a technology still used by a task cannot be removed from its project', function () {
    $project = makeProject(null, ['Angular' => '10', '.NET' => '5']);
    $angular = $project->technologyLines()->with('technology')->get()->first(fn ($l) => $l->technology->name === 'Angular');
    Task::factory()->create(['project_id' => $project->id, 'technology_id' => $angular->technology_id]);

    app(UpdateProject::class)->handle(userWithRole(RoleName::Admin), $project, projectData($project->customer, [
        $project->technologyLines()->with('technology')->get()->first(fn ($l) => $l->technology->name === '.NET')->technology_id => '5',
    ], ['managerId' => $project->manager_id]));
})->group('BR-10')->throws(ValidationException::class, 'still use');

it('BR-30 the project manager must hold the admin role', function (RoleName $role) {
    [$client, $tech] = clientWithRates();
    $notAdmin = userWithRole($role);

    app(CreateProject::class)->handle(userWithRole(RoleName::Superadmin), projectData($client, [$tech['Angular']->id => '1'], ['managerId' => $notAdmin->id]));
})->group('BR-30')->throws(ValidationException::class, 'admin role')->with([RoleName::Superadmin, RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('BR-30 a deactivated admin cannot be picked as manager', function () {
    [$client, $tech] = clientWithRates();
    $admin = userWithRole(RoleName::Admin);
    $admin->ban();

    app(CreateProject::class)->handle(userWithRole(RoleName::Superadmin), projectData($client, [$tech['Angular']->id => '1'], ['managerId' => $admin->id]));
})->group('BR-30')->throws(ValidationException::class);

it('BR-30 changing the manager to a non-admin is rejected on update too', function () {
    $project = makeProject();

    app(UpdateProject::class)->handle(userWithRole(RoleName::Admin), $project, projectData($project->customer, [$project->technologyLines()->value('technology_id') => '10'], ['managerId' => userWithRole(RoleName::Employee)->id]));
})->group('BR-30')->throws(ValidationException::class);

it('BR-30 a manager cannot lose the admin role while they manage a project', function () {
    $project = makeProject();
    $manager = $project->manager;

    expect(fn () => app(AssignUserRole::class)->handle($manager, RoleName::Employee))->toThrow(ValidationException::class, 'manages 1 project')
        ->and($manager->fresh()->roles()->pluck('name')->all())->toBe(['admin']);

    app(AssignUserRole::class)->handle($manager, RoleName::Admin); // same role: fine
});

it('BR-30 the employee screen also refuses it, and a reassigned manager can then change role', function () {
    $project = makeProject();
    $manager = $project->manager;
    $superadmin = userWithRole(RoleName::Superadmin);
    $data = employeeData(['name' => $manager->name, 'surname' => $manager->surname, 'email' => $manager->email, 'role' => RoleName::Employee]);

    expect(fn () => app(UpdateEmployee::class)->handle($superadmin, $manager, $data))->toThrow(ValidationException::class);

    $project->update(['manager_id' => userWithRole(RoleName::Admin)->id]);
    app(UpdateEmployee::class)->handle($superadmin, $manager, $data);

    expect($manager->fresh()->roles()->pluck('name')->all())->toBe(['employee']);
})->group('BR-30');

it('BR-31 a deactivated client gets no new projects', function () {
    [$client, $tech] = clientWithRates(['Angular' => 2000], '2025-01-01', ['is_active' => false]);

    app(CreateProject::class)->handle(userWithRole(RoleName::Admin), projectData($client, [$tech['Angular']->id => '1']));
})->group('BR-31')->throws(ValidationException::class, 'deactivated');

it('BR-31 existing projects of a deactivated client keep working', function () {
    $project = makeProject();
    $project->customer->update(['is_active' => false]);

    $updated = app(UpdateProject::class)->handle(userWithRole(RoleName::Admin), $project, projectData($project->customer, [$project->technologyLines()->value('technology_id') => '20'], ['managerId' => $project->manager_id, 'name' => 'Still editable']));

    expect($updated->name)->toBe('Still editable');
})->group('BR-31');

it('FR-19 the client of an existing project cannot change', function () {
    $project = makeProject();
    [$other, $tech] = clientWithRates(['Angular' => 1000]);

    app(UpdateProject::class)->handle(userWithRole(RoleName::Admin), $project, projectData($other, [$tech['Angular']->id => '1'], ['managerId' => $project->manager_id]));
})->throws(ValidationException::class, 'cannot be changed');

it('FR-19 the end date cannot be before the start date, also in the database', function () {
    [$client, $tech] = clientWithRates();

    expect(fn () => app(CreateProject::class)->handle(userWithRole(RoleName::Admin), projectData($client, [$tech['Angular']->id => '1'], ['endDate' => Carbon::parse('2025-12-31')])))
        ->toThrow(ValidationException::class);

    expect(fn () => Project::factory()->create(['start_date' => '2026-06-01', 'end_date' => '2026-05-01']))->toThrow(QueryException::class);
})->group('FR-19');

it('FR-19 members need projects.manage_members; status needs projects.change_status', function () {
    $project = makeProject();
    $admin = userWithRole(RoleName::Admin);
    $member = userWithRole(RoleName::Employee);
    $input = projectData($project->customer, [$project->technologyLines()->value('technology_id') => '10'], ['managerId' => $project->manager_id, 'memberIds' => [$member->id], 'status' => ProjectStatus::OnHold]);

    app(UpdateProject::class)->handle($admin, $project, $input);

    expect($project->fresh()->status)->toBe(ProjectStatus::OnHold)
        ->and($project->members()->pluck('users.id')->all())->toBe([$member->id]);
})->group('FR-19');

it('FR-19 a project with tasks cannot be deleted; an empty one can (soft delete)', function () {
    $project = makeProject();
    Task::factory()->create(['project_id' => $project->id]);

    expect(fn () => app(DeleteProject::class)->handle(userWithRole(RoleName::Admin), $project))->toThrow(ValidationException::class);

    $empty = makeProject(clientWithRates(['Angular' => 1000]), ['Angular' => '1']);
    app(DeleteProject::class)->handle(userWithRole(RoleName::Admin), $empty);

    expect(Project::find($empty->id))->toBeNull()->and(Project::withTrashed()->find($empty->id))->not->toBeNull();
})->group('FR-19');

it('D-05 everyone can read projects but only superadmin and admin see the budget', function (RoleName $role, bool $seesBudget) {
    $project = makeProject(null, ['Angular' => '100']);
    $project->update(['name' => 'Visible Project']);
    $user = userWithRole($role);
    $this->actingAs($user);

    expect($user->can('view', $project))->toBeTrue()
        ->and($user->can('viewBudget', $project))->toBe($seesBudget);

    $list = Livewire::test(ListProjects::class)->assertSee('Visible Project');
    $view = Livewire::test(ViewProject::class, ['record' => $project->getKey()])->assertSee('Visible Project');

    foreach ([$list, $view] as $page) {
        $seesBudget ? $page->assertSee('EUR 2000.00') : $page->assertDontSee('EUR 2000.00');
    }

    $html = $this->get("/app/projects/{$project->id}")->assertOk()->getContent();
    expect(str_contains($html, 'EUR 2000.00'))->toBe($seesBudget);
})->group('D-05')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('FR-21 the fixed costs tab is for the superadmin only', function (RoleName $role, bool $sees) {
    $project = makeProject();
    $this->actingAs(userWithRole($role));

    expect(FixedCostsRelationManager::canViewForRecord($project, ViewProject::class))->toBe($sees);
})->group('FR-21')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('FR-21 the superadmin adds a fixed cost on the project screen', function () {
    $project = makeProject();
    $this->actingAs(userWithRole(RoleName::Superadmin));

    Livewire::test(FixedCostsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->callAction(TestAction::make('create')->table(), ['name' => 'Management', 'date' => '2026-09-15', 'amount' => '100.00'])
        ->assertHasNoFormErrors();

    expect($project->fixedCosts()->sole()->amount_minor)->toBe(10000)
        ->and($project->fresh()->budget_minor)->toBe(20000 + 10000);
})->group('FR-21');

it('FR-19 employees and others without edit rights cannot open the project edit or create screens', function (RoleName $role) {
    $project = makeProject();
    $this->actingAs(userWithRole($role));

    $this->get("/app/projects/{$project->id}/edit")->assertForbidden();
    $this->get('/app/projects/create')->assertForbidden();
})->group('FR-19')->with([RoleName::Hr, RoleName::Assistant, RoleName::Employee]);
