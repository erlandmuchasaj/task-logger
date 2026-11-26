<?php

declare(strict_types=1);

namespace App\Domain\Exports\Exporters;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Reports\Actions\BuildFinancials;
use App\Domain\Reports\Data\FinancialsRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** D-91: the Financials table as a file. The figures are worked out once for every project, in query(). */
final class FinancialsExporter implements Exporter
{
    /** @var array<int, FinancialsRow> */
    private array $figures = [];

    public function canExport(User $user): bool
    {
        return $user->can(Permissions::FINANCIALS_EXPORT) && $user->can(Permissions::FINANCIALS_VIEW);
    }

    public function columns(User $user): array
    {
        return [
            'name' => 'Project', 'client' => 'Client', 'currency' => 'Currency', 'budget' => 'Budget', 'costs' => 'Costs',
            'employee_costs' => 'Employee costs', 'fixed_costs' => 'Fixed costs', 'profit' => 'Profit',
            'total_hours' => 'Total hours', 'end_date' => 'Deadline',
        ];
    }

    /** @return Builder<*> */
    public function query(User $user, array $filters): Builder
    {
        $this->figures = resolve(BuildFinancials::class)->handle($user, Project::query()->with('customer')->get());

        return Project::query()->with('customer');
    }

    public function row(Model $record, User $user): array
    {
        /** @var Project $project */
        $project = $record;
        // A project created while the export runs has no precomputed figures yet.
        $figures = $this->figures[$project->getKey()] ?? $this->figuresFor($user, $project);

        return [
            'name' => $project->name,
            'client' => $project->customer->displayName(),
            'currency' => $project->customer->currency_code,
            'budget' => $figures->budget->toMajorString(),
            'costs' => $figures->costs()?->toMajorString(),
            'employee_costs' => $figures->employeeCosts?->toMajorString(),
            'fixed_costs' => $figures->fixedCosts->toMajorString(),
            'profit' => $figures->profit()?->toMajorString(),
            'total_hours' => $this->hours($figures->loggedMinutes),
            'end_date' => $project->end_date?->toDateString(),
        ];
    }

    private function figuresFor(User $user, Project $project): FinancialsRow
    {
        return resolve(BuildFinancials::class)->handle($user, [$project])[$project->getKey()];
    }

    private function hours(int $minutes): string
    {
        return intdiv($minutes, 60).':'.mb_str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
