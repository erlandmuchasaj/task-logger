<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\FixedCostData;
use App\Domain\Clients\Models\FixedCost;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** BR-14, W-02 step 5. Perm fixed_costs.manage (superadmin). Adds to the project budget. */
final readonly class AddFixedCost
{
    use ManagesFixedCosts;

    public function __construct(private ComputeProjectBudget $compute) {}

    public function handle(User $actor, Project $project, FixedCostData $data): FixedCost
    {
        Gate::forUser($actor)->authorize('manageFixedCosts', $project);
        $attributes = $this->fixedCostAttributes($project, $data);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): FixedCost => DB::transaction(function () use ($actor, $project, $attributes): FixedCost {
            $cost = $project->fixedCosts()->create([...$attributes, 'created_by' => $actor->getKey()]);
            $this->compute->handle($project);

            return $cost;
        }));
    }
}
