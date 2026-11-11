<?php

declare(strict_types=1);

namespace App\Domain\Exports\Enums;

use App\Domain\Exports\Exporters\CustomersExporter;
use App\Domain\Exports\Exporters\Exporter;
use App\Domain\Exports\Exporters\LogsExporter;
use App\Domain\Exports\Exporters\ProjectsExporter;
use App\Domain\Exports\Exporters\TasksExporter;
use Filament\Support\Contracts\HasLabel;

/** BR-33: the superadmin exports all logs; the assistant exports projects, customers, tasks and logs. */
enum ExportType: string implements HasLabel
{
    case Logs = 'logs';
    case Projects = 'projects';
    case Clients = 'clients';
    case Tasks = 'tasks';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function exporter(): Exporter
    {
        return match ($this) {
            self::Logs => new LogsExporter,
            self::Projects => new ProjectsExporter,
            self::Clients => new CustomersExporter,
            self::Tasks => new TasksExporter,
        };
    }
}
