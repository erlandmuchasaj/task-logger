<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use Filament\Support\Contracts\HasLabel;

enum DayPart: string implements HasLabel
{
    case Full = 'full';
    case Am = 'am';
    case Pm = 'pm';

    public function getLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
