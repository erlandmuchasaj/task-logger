<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\ProjectData;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Perm projects.update. Members need projects.manage_members, a status change projects.change_status.
 * The client cannot change once a project exists [ASSUMED]: rates, budget and later logs are tied to it.
 */
final readonly class UpdateProject
{
    use ValidatesProject;

    public function __construct(
        private SyncProjectParts $parts,
        private ComputeProjectBudget $compute,
        private ChangeProjectStatus $changeStatus,
    ) {}

    public function handle(User $actor, Project $project, ProjectData $data): Project
    {
        Gate::forUser($actor)->authorize('update', $project);

        if ($data->customerId !== $project->customer_id) {
            throw ValidationException::withMessages(['customer_id' => 'The client of an existing project cannot be changed.']);
        }

        $memberIds = $project->memberships()->pluck('user_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $newMemberIds = collect($data->memberIds)->sort()->values()->all();
        if ($memberIds !== $newMemberIds) {
            Gate::forUser($actor)->authorize('manageMembers', $project);
        }

        $this->validateProject($data, $project->customer, $project);
        $this->assertTechnologiesNotInUse($project, array_keys($data->technologies));

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Project => DB::transaction(function () use ($actor, $project, $data, $memberIds, $newMemberIds): Project {
            $project->update([
                'project_type' => $data->type,
                'name' => $data->name,
                'description' => $data->description,
                'manager_id' => $data->managerId,
                'start_date' => $data->startDate->toDateString(),
                'end_date' => $data->endDate?->toDateString(),
                'can_log_directly_to_project' => $data->canLogDirectly,
                'color' => $data->color,
            ]);

            $this->parts->technologies($project, $data);
            if ($memberIds !== $newMemberIds) {
                $this->parts->members($project, $data->memberIds);
            }

            // The start date, technologies and estimates all feed the budget (D-04).
            $this->compute->handle($project);

            if ($data->status !== $project->status) {
                $this->changeStatus->handle($actor, $project, $data->status);
            }

            return $project->refresh();
        }));
    }
}
