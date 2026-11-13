<?php

declare(strict_types=1);

namespace App\Domain\Exports\Exporters;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Work\Models\Log;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The list of logs, for finance (W-08 step 1). Filters: date_from, date_to, project_id, user_id. */
final class LogsExporter implements Exporter
{
    public function canExport(User $user): bool
    {
        return $user->can(Permissions::LOGS_EXPORT) && $user->can(Permissions::LOGS_VIEW_ANY);
    }

    public function columns(User $user): array
    {
        $columns = [
            'date' => 'Date', 'employee' => 'Employee', 'client' => 'Client', 'project' => 'Project', 'task' => 'Task',
            'technology' => 'Technology', 'hours' => 'Hours', 'duration' => 'Duration (h:mm)', 'minutes' => 'Minutes',
            'type' => 'Type', 'description' => 'Description',
        ];

        if ($user->can(Permissions::CLIENTS_VIEW_RATES)) {
            $columns += ['rate' => 'Hourly rate', 'currency' => 'Currency', 'amount' => 'Amount'];
        }

        return $columns;
    }

    /** @return Builder<*> */
    public function query(User $user, array $filters): Builder
    {
        return Log::query()
            ->with(['user', 'project.customer', 'task', 'technology'])
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('date', '<=', $to))
            ->when($filters['project_id'] ?? null, fn (Builder $q, $id) => $q->where('project_id', $id))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id));
    }

    public function row(Model $record, User $user): array
    {
        /** @var Log $log */
        $log = $record;

        $row = [
            'date' => $log->date->toDateString(),
            'employee' => $log->user->getFilamentName(),
            'client' => $log->project->customer->displayName(),
            'project' => $log->project->name,
            'task' => $log->task?->name,
            'technology' => $log->technology->name,
            'hours' => number_format($log->duration_minutes / 60, 2, '.', ''),
            'duration' => $log->duration(),
            'minutes' => $log->duration_minutes,
            'type' => $log->log_type->value,
            'description' => $log->description,
        ];

        if ($user->can(Permissions::CLIENTS_VIEW_RATES)) {
            $row += ['rate' => $log->rate()->toMajorString(), 'currency' => $log->rate_currency, 'amount' => $log->amount()->toMajorString()];
        }

        return $row;
    }
}
