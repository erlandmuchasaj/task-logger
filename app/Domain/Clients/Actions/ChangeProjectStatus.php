<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** Perm projects.change_status. SPEC defines no restrictions between statuses. */
class ChangeProjectStatus
{
    public function handle(User $actor, Project $project, ProjectStatus $status): Project
    {
        Gate::forUser($actor)->authorize('changeStatus', $project);

        return app(CauserResolver::class)->withCauser($actor, function () use ($project, $status): Project {
            $project->update(['status' => $status]);

            return $project->refresh();
        });
    }
}
