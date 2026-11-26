<?php

declare(strict_types=1);

namespace App\Domain\Reports\Actions;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Data\FinancialsRow;
use App\Domain\Reports\Support\EmployeeCosts;
use App\Domain\Shared\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * D-91: budget, costs and profit per project, from grouped queries (logs, fixed costs and person-days) plus one holiday lookup per country and month. The caller
 * passes projects with their `customer` loaded. Needs `financials.view`, which is its own permission: a person
 * without `salaries.view` or `clients.view_rates` still sees these totals, never a salary or a rate.
 */
final class BuildFinancials
{
    /**
     * @param  iterable<Project>  $projects
     * @return array<int, FinancialsRow> by project id
     */
    public function handle(User $actor, iterable $projects): array
    {
        Gate::forUser($actor)->authorize('viewFinancials', Project::class);

        $projects = collect($projects);
        $ids = array_values($projects->map(fn (Project $project): int => (int) $project->getKey())->all());

        if ($ids === []) {
            return [];
        }

        $minutes = DB::table('logs')->whereNull('deleted_at')->whereIn('project_id', $ids)->groupBy('project_id')->selectRaw('project_id, SUM(duration_minutes) as minutes')->pluck('minutes', 'project_id');
        $fixed = DB::table('fixed_costs')->whereIn('project_id', $ids)->groupBy('project_id')->selectRaw('project_id, SUM(amount_minor) as minor')->pluck('minor', 'project_id');
        $salaries = (new EmployeeCosts)->forProjects($ids);

        return $projects->mapWithKeys(function (Project $project) use ($minutes, $fixed, $salaries): array {
            $currency = $project->customer->currency_code;
            $cost = $salaries[$project->getKey()] ?? ['minor' => 0, 'currencies' => []];
            $sameCurrency = array_diff($cost['currencies'], [$currency]) === [];

            return [$project->getKey() => new FinancialsRow(
                (int) $project->getKey(),
                $project->budget(),
                Money::of((int) ($fixed[$project->getKey()] ?? 0), $currency),
                $sameCurrency ? Money::of($cost['minor'], $currency) : null,
                (int) ($minutes[$project->getKey()] ?? 0),
            )];
        })->all();
    }
}
