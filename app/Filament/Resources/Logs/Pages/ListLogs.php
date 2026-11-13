<?php

declare(strict_types=1);

namespace App\Filament\Resources\Logs\Pages;

use App\Domain\Exports\Enums\ExportType;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Support\ExportAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLogs extends ListRecords
{
    protected static string $resource = LogResource::class;

    protected function getHeaderActions(): array
    {
        return [ExportAction::make(ExportType::Logs), CreateAction::make()];
    }
}
