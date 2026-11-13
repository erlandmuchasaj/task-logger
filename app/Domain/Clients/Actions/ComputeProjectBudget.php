<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Support\BudgetCalculator;
use App\Domain\Clients\Support\ProjectBudget;
use App\Domain\MasterData\Models\Technology;
use Illuminate\Validation\ValidationException;

/**
 * Recomputes and stores a project's estimated hours and budget snapshot (D-04). Call it inside the
 * transaction of whatever changed technologies, estimates, rates, fixed costs or the start date: it
 * throws when a technology has no rate on the start date, which rolls that change back.
 */
class ComputeProjectBudget
{
    public function __construct(private readonly BudgetCalculator $calculator) {}

    public function handle(Project $project): ProjectBudget
    {
        $project->unsetRelation('customer');
        $result = $this->calculator->calculate($project);

        if ($result->missingTechnologyIds !== []) {
            $names = Technology::query()->whereIn('id', $result->missingTechnologyIds)->pluck('name')->implode(', ');

            throw ValidationException::withMessages([
                'technologies' => "No client rate is effective on the project start date ({$project->start_date->toDateString()}) for: {$names}.",
            ]);
        }

        $project->forceFill([
            'estimated_hours' => $result->estimatedHours(),
            'budget_minor' => $result->budget->minor,
        ])->saveQuietly();

        return $result;
    }
}
