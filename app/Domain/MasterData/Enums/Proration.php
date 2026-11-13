<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

enum Proration: string implements HasLabel
{
    case None = 'none';
    case ByStartDate = 'by_start_date';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }
}
