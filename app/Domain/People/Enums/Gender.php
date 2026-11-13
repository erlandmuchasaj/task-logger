<?php

declare(strict_types=1);

namespace App\Domain\People\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

enum Gender: string implements HasLabel
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
    case Undisclosed = 'undisclosed';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }
}
