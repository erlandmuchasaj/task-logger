<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\TimesheetLock;

/** Many logs, tasks and people, so a lazy-loaded N+1 on any M4 screen would throw (NFR-09). */
function populatedLogs(): Log
{
    ['employee' => $me, 'task' => $task, 'project' => $project, 'technology' => $tech] = loggingSetup();
    $task->update(['force_to_log' => true]);
    foreach (range(1, 12) as $i) {
        Log::factory()->create([
            'user_id' => $i % 3 === 0 ? userWithRole(RoleName::Employee)->id : $me->id,
            'project_id' => $project->id, 'technology_id' => $tech->id, 'task_id' => $i % 2 === 0 ? $task->id : null,
            'date' => now()->subDays($i % 5)->toDateString(), 'duration_minutes' => 30 + $i,
        ]);
    }

    TimesheetLock::query()->create(['month' => '2025-01-01', 'locked_by' => userWithRole(RoleName::Superadmin)->id, 'locked_at' => now()]);

    return Log::query()->with('user')->where('user_id', $me->id)->firstOrFail();
}

it('M4 every logging screen renders for the roles that may see it, without N+1 queries', function (RoleName $role, array $urls): void {
    $ownLog = populatedLogs();
    $this->actingAs($role === RoleName::Employee ? $ownLog->user : userWithRole($role));

    foreach ($urls as $url) {
        $this->get(strtr($url, ['{log}' => $ownLog->id]))->assertOk();
    }
})->with([
    'superadmin' => [RoleName::Superadmin, ['/app/my-day', '/app/logs', '/app/logs/create', '/app/exports', '/app/timesheet-locks', '/app/tasks', '/app/projects', '/app/clients']],
    'admin' => [RoleName::Admin, ['/app/my-day', '/app/logs', '/app/logs/create']],
    'assistant' => [RoleName::Assistant, ['/app/my-day', '/app/logs', '/app/exports']],
    'hr' => [RoleName::Hr, ['/app/my-day', '/app/logs', '/app/logs/create']],
    'employee' => [RoleName::Employee, ['/app/my-day', '/app/logs', '/app/logs/create', '/app/logs/{log}/edit']],
]);
