<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Domain\Exports\Enums\ExportType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\ExportAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [ExportAction::make(ExportType::Clients), CreateAction::make()];
    }
}
