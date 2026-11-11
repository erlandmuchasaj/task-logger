<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankHolidays\Pages;

use App\Filament\Resources\BankHolidays\BankHolidayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBankHolidays extends ManageRecords
{
    protected static string $resource = BankHolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
