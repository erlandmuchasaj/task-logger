<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Perm projects.delete. A soft delete: history survives. Refused while the project still has tasks [DERIVED]. */
class DeleteProject
{
    public function handle(User $actor, Project $project): void
    {
        Gate::forUser($actor)->authorize('delete', $project);

        if ($project->tasks()->exists()) {
            throw ValidationException::withMessages(['project' => 'This project still has tasks. Cancel the project instead of deleting it.']);
        }

        app(CauserResolver::class)->withCauser($actor, fn () => $project->delete());
    }
}
