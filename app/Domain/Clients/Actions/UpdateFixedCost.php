<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\FixedCostData;
use App\Domain\Clients\Models\FixedCost;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final readonly class UpdateFixedCost
{
    use ManagesFixedCosts;

    public function __construct(private ComputeProjectBudget $compute) {}

    public function handle(User $actor, FixedCost $cost, FixedCostData $data): FixedCost
    {
        $project = $cost->project;
        Gate::forUser($actor)->authorize('manageFixedCosts', $project);
        $attributes = $this->fixedCostAttributes($project, $data);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): FixedCost => DB::transaction(function () use ($cost, $project, $attributes): FixedCost {
            $cost->update($attributes);
            $this->compute->handle($project);

            return $cost->refresh();
        }));
    }
}
