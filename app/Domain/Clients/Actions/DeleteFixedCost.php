<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\FixedCost;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

class DeleteFixedCost
{
    public function __construct(private readonly ComputeProjectBudget $compute) {}

    public function handle(User $actor, FixedCost $cost): void
    {
        $project = $cost->project;
        Gate::forUser($actor)->authorize('manageFixedCosts', $project);

        app(CauserResolver::class)->withCauser($actor, fn () => DB::transaction(function () use ($cost, $project): void {
            $cost->delete();
            $this->compute->handle($project);
        }));
    }
}
