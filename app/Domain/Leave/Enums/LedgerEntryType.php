<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use Filament\Support\Contracts\HasLabel;

enum LedgerEntryType: string implements HasLabel
{
    case Grant = 'grant';
    case Adjustment = 'adjustment';
    case CarryOver = 'carry_over';
    case Deduction = 'deduction';
    case Reversal = 'reversal';
    case Expiry = 'expiry';

    public function getLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
