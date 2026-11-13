<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentPlans\Pages;

use App\Filament\Resources\PaymentPlans\PaymentPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePaymentPlans extends ManageRecords
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
