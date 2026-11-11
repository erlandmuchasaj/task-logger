<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\AddFixedCost;
use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Data\FixedCostData;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Shared\Money;
use App\Domain\Work\Models\Task;

/** Several clients, projects and tasks with every relation filled, so a lazy-loaded N+1 would throw (NFR-09). */
function populatedWork(): Project
{
    $last = null;
    foreach (range(1, 3) as $i) {
        [$client, $tech] = clientWithRates(["Tech{$i}A" => 2000, "Tech{$i}B" => 2400]);
        $project = app(CreateProject::class)->handle(
            userWithRole(RoleName::Superadmin),
            projectData($client, collect($tech)->mapWithKeys(fn ($t) => [$t->id => '40'])->all(), ['name' => "Project {$i}", 'memberIds' => [userWithRole(RoleName::Employee)->id]]),
        );
        app(AddFixedCost::class)->handle(userWithRole(RoleName::Superadmin), $project, new FixedCostData('Mgmt', now(), Money::of(5000, 'EUR')));
        Task::factory()->count(3)->create(['project_id' => $project->id, 'technology_id' => $project->technologyLines()->value('technology_id'), 'assignee_id' => userWithRole(RoleName::Employee)->id]);
        $last = $project;
    }

    return $last->load('customer');
}

it('M3 every clients, projects and tasks screen renders for the roles that may see it, without N+1 queries', function (RoleName $role, array $urls) {
    $project = populatedWork();
    $task = $project->tasks()->first();
    $this->actingAs(userWithRole($role));

    foreach ($urls as $url) {
        $this->get(strtr($url, ['{client}' => $project->customer_id, '{project}' => $project->id, '{task}' => $task->id]))->assertOk();
    }
})->with([
    'superadmin' => [RoleName::Superadmin, ['/app/clients', '/app/clients/create', '/app/clients/{client}', '/app/clients/{client}/edit', '/app/projects', '/app/projects/create', '/app/projects/{project}', '/app/projects/{project}/edit', '/app/tasks', '/app/tasks/create', '/app/tasks/{task}', '/app/tasks/{task}/edit']],
    'admin' => [RoleName::Admin, ['/app/clients', '/app/clients/{client}', '/app/clients/{client}/edit', '/app/projects', '/app/projects/create', '/app/projects/{project}', '/app/projects/{project}/edit', '/app/tasks', '/app/tasks/{task}', '/app/tasks/{task}/edit']],
    'assistant' => [RoleName::Assistant, ['/app/clients', '/app/clients/{client}', '/app/projects', '/app/projects/{project}', '/app/tasks', '/app/tasks/{task}']],
    'hr' => [RoleName::Hr, ['/app/clients', '/app/clients/{client}', '/app/projects', '/app/projects/{project}', '/app/tasks']],
    'employee' => [RoleName::Employee, ['/app/clients', '/app/clients/{client}', '/app/projects', '/app/projects/{project}', '/app/tasks']],
]);
