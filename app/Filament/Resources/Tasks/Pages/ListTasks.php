<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Domain\Exports\Enums\ExportType;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\ExportAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [ExportAction::make(ExportType::Tasks), CreateAction::make()];
    }
}
