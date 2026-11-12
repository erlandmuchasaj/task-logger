<?php

declare(strict_types=1);

namespace App\Domain\Reports\Actions;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Reports\Data\EstimateFilters;
use App\Domain\Reports\Data\EstimateLine;
use App\Domain\Reports\Data\EstimateProject;
use App\Domain\Reports\Data\EstimateReportResult;
use App\Domain\Shared\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * M7 reporting (SPEC 1.2): estimated against logged hours per project and technology, with logged work valued at
 * the rate that applied when it was logged (BR-06). Needs projects.view and logs.view_any (it shows everyone's
 * time); values need clients.view_rates (D-05, D-49). Money is grouped by currency and never converted (BR-12).
 * Everything is integer arithmetic in SQL and PHP: minutes, hundredths of an hour, minor units.
 */
class BuildEstimateReport
{
    public const PER_PAGE = 25;

    /**
     * One page of projects with their lines.
     *
     * @return LengthAwarePaginator<int, EstimateProject>
     */
    public function handle(User $actor, EstimateFilters $filters, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->build($actor, $filters, $page, $perPage)->projects;
    }

    /**
     * Totals over every project the filters match (not just the page).
     *
     * @return array{estimated_hundredths: int, logged_minutes: int, values: array<string, Money>}
     */
    public function totals(User $actor, EstimateFilters $filters): array
    {
        $report = $this->build($actor, $filters, 1, 1);

        return ['estimated_hundredths' => $report->estimatedHundredths, 'logged_minutes' => $report->loggedMinutes, 'values' => $report->values];
    }

    /**
     * The page and the totals from ONE pass over the logs: a single grouped query over every matching project
     * gives per-technology minutes and value, from which the page lines and the grand totals are both derived (NFR-08).
     */
    public function build(User $actor, EstimateFilters $filters, int $page = 1, int $perPage = self::PER_PAGE): EstimateReportResult
    {
        Gate::forUser($actor)->authorize('viewReport', Project::class);

        $seesValue = $actor->can(Permissions::CLIENTS_VIEW_RATES);

        $projects = $this->projects($filters)->with('customer')->orderBy('name')->orderBy('id')->paginate(min($perPage, 100), page: $page);
        $allIds = array_values($this->projects($filters)->pluck('projects.id')->map(fn ($id): int => (int) $id)->all());

        $logged = $this->logged($allIds, $filters);
        $pageIds = array_values($projects->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $lines = $this->lines($pageIds, $logged, $seesValue);

        $page = $projects->through(function (Project $project) use ($lines, $seesValue): EstimateProject {
            $projectLines = $lines[$project->getKey()] ?? [];

            return new EstimateProject(
                (int) $project->getKey(),
                $project->name,
                $project->customer->displayName(),
                $project->status->getLabel(),
                $projectLines,
                $seesValue ? Money::of(array_sum(array_map(fn (EstimateLine $l): int => $l->value === null ? 0 : $l->value->minor, $projectLines)), $project->customer->currency_code) : null,
            );
        });

        $estimated = $allIds === [] ? 0 : (int) DB::table('project_technology')->whereIn('project_id', $allIds)->sum(DB::raw('ROUND(estimated_hours * 100)'));
        $minutes = 0;
        $money = [];

        foreach ($logged as $row) {
            $minutes += $row['minutes'];
            $money[] = Money::of($row['value'], $row['currency']);
        }

        return new EstimateReportResult($page, $estimated, $minutes, $seesValue ? Money::groupByCurrency($money) : []);
    }

    /** @return Builder<Project> */
    private function projects(EstimateFilters $filters): Builder
    {
        return Project::query()
            ->when($filters->customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when($filters->projectId, fn (Builder $q, int $id) => $q->whereKey($id))
            ->when($filters->status, fn (Builder $q, $status) => $q->where('status', $status));
    }

    /**
     * Minutes and value per project and technology over all the given projects, in one grouped query.
     *
     * @param  list<int>  $projectIds
     * @return array<string, array{project: int, technology: int, minutes: int, value: int, currency: string}>
     */
    private function logged(array $projectIds, EstimateFilters $filters): array
    {
        if ($projectIds === []) {
            return [];
        }

        $logged = [];

        DB::table('logs')
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->when($filters->from, fn ($q, $from) => $q->whereDate('date', '>=', $from->toDateString()))
            ->when($filters->to, fn ($q, $to) => $q->whereDate('date', '<=', $to->toDateString()))
            ->selectRaw('project_id, technology_id, rate_currency, SUM(duration_minutes) as minutes, SUM((rate_minor * duration_minutes + 30) DIV 60) as value_minor')
            ->groupBy('project_id', 'technology_id', 'rate_currency')
            ->get()
            ->each(function ($row) use (&$logged): void {
                $logged[$row->project_id.'-'.$row->technology_id.'-'.$row->rate_currency] = [
                    'project' => (int) $row->project_id, 'technology' => (int) $row->technology_id,
                    'minutes' => (int) $row->minutes, 'value' => (int) $row->value_minor, 'currency' => (string) $row->rate_currency,
                ];
            });

        return $logged;
    }

    /**
     * @param  list<int>  $projectIds  the projects on this page
     * @param  array<string, array{project: int, technology: int, minutes: int, value: int, currency: string}>  $logged
     * @return array<int, list<EstimateLine>> by project id
     */
    private function lines(array $projectIds, array $logged, bool $seesValue): array
    {
        if ($projectIds === []) {
            return [];
        }

        $byTechnology = [];
        foreach ($logged as $row) {
            $key = $row['project'].'-'.$row['technology'];
            $byTechnology[$key]['minutes'] = ($byTechnology[$key]['minutes'] ?? 0) + $row['minutes'];
            $byTechnology[$key]['value'] = ($byTechnology[$key]['value'] ?? 0) + $row['value'];
            $byTechnology[$key]['currency'] = $row['currency'];
        }

        $lines = [];

        DB::table('project_technology as pt')
            ->join('technologies as t', 't.id', '=', 'pt.technology_id')
            ->whereIn('pt.project_id', $projectIds)
            ->orderBy('t.name')
            ->get(['pt.project_id', 'pt.technology_id', 'pt.estimated_hours', 't.name'])
            ->each(function ($row) use ($byTechnology, $seesValue, &$lines): void {
                $log = $byTechnology[$row->project_id.'-'.$row->technology_id] ?? null;

                $lines[(int) $row->project_id][] = new EstimateLine(
                    (int) $row->technology_id,
                    (string) $row->name,
                    EstimateLine::hundredthsOf((string) $row->estimated_hours),
                    $log['minutes'] ?? 0,
                    $seesValue && $log !== null ? Money::of($log['value'], $log['currency']) : null,
                );
            });

        return $lines;
    }
}
