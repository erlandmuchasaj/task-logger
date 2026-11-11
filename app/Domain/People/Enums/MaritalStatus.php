<?php

declare(strict_types=1);

namespace App\Domain\People\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

enum MaritalStatus: string implements HasLabel
{
    case Single = 'single';
    case Married = 'married';
    case Divorced = 'divorced';
    case Widowed = 'widowed';
    case Other = 'other';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }
}
