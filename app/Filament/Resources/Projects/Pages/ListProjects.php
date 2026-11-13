<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Exports\Enums\ExportType;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\ExportAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [ExportAction::make(ExportType::Projects), CreateAction::make()];
    }
}
