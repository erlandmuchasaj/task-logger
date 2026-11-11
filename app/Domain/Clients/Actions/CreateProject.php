<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\ProjectData;
use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** W-02 step 3. Perm projects.create. The budget is computed, never typed (D-04). */
class CreateProject
{
    use ValidatesProject;

    public function __construct(
        private readonly SyncProjectParts $parts,
        private readonly ComputeProjectBudget $compute,
    ) {}

    public function handle(User $actor, ProjectData $data): Project
    {
        Gate::forUser($actor)->authorize('create', Project::class);

        if ($data->status !== ProjectStatus::Planning) {
            Gate::forUser($actor)->authorize('changeStatus', new Project);
        }

        if ($data->memberIds !== []) {
            Gate::forUser($actor)->authorize('manageMembers', new Project);
        }

        $customer = Customer::query()->findOrFail($data->customerId);
        $this->validateProject($data, $customer, null);

        return app(CauserResolver::class)->withCauser($actor, fn (): Project => DB::transaction(function () use ($actor, $data, $customer): Project {
            $project = Project::create([
                'created_by' => $actor->getKey(),
                'customer_id' => $customer->getKey(),
                'project_type' => $data->type,
                'name' => $data->name,
                'description' => $data->description,
                'manager_id' => $data->managerId,
                'start_date' => $data->startDate->toDateString(),
                'end_date' => $data->endDate?->toDateString(),
                'can_log_directly_to_project' => $data->canLogDirectly,
                'status' => $data->status,
                'color' => $data->color,
            ]);

            $this->parts->technologies($project, $data);
            $this->parts->members($project, $data->memberIds);
            $this->compute->handle($project);

            return $project->refresh();
        }));
    }
}
