<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/** [ASSUMED D-19] Metadata only: no invoice is generated (BR-15). */
enum PaymentPlanType: string implements HasLabel
{
    case DaysAfterInvoice = 'days_after_invoice';
    case EndOfMonth = 'end_of_month';
    case Prepayment = 'prepayment';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }
}
