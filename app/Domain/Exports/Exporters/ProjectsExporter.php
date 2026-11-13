<?php

declare(strict_types=1);

namespace App\Domain\Exports\Exporters;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ProjectsExporter implements Exporter
{
    public function canExport(User $user): bool
    {
        return $user->can(Permissions::PROJECTS_EXPORT) && $user->can(Permissions::PROJECTS_VIEW);
    }

    public function columns(User $user): array
    {
        $columns = [
            'name' => 'Project', 'client' => 'Client', 'type' => 'Type', 'status' => 'Status', 'manager' => 'Manager',
            'start_date' => 'Start date', 'end_date' => 'Deadline', 'estimated_hours' => 'Estimated hours',
            'members' => 'Members', 'direct_logging' => 'Direct logging',
        ];

        if ($user->can(Permissions::CLIENTS_VIEW_RATES)) {
            $columns += ['currency' => 'Currency', 'budget' => 'Budget'];
        }

        return $columns;
    }

    /** @return Builder<*> */
    public function query(User $user, array $filters): Builder
    {
        return Project::query()->with(['customer', 'manager'])->withCount('memberships');
    }

    public function row(Model $record, User $user): array
    {
        /** @var Project $project */
        $project = $record;

        $row = [
            'name' => $project->name,
            'client' => $project->customer->displayName(),
            'type' => $project->project_type->value,
            'status' => $project->status->value,
            'manager' => $project->manager->getFilamentName(),
            'start_date' => $project->start_date->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'estimated_hours' => $project->estimated_hours,
            'members' => $project->memberships_count,
            'direct_logging' => $project->can_log_directly_to_project ? 'yes' : 'no',
        ];

        if ($user->can(Permissions::CLIENTS_VIEW_RATES)) {
            $row += ['currency' => $project->customer->currency_code, 'budget' => $project->budget()->toMajorString()];
        }

        return $row;
    }
}
