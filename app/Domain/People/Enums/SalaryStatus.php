<?php

declare(strict_types=1);

namespace App\Domain\People\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/**
 * [ASSUMED D-19] pending: effective date is in the future. active: in force or the latest row.
 * superseded: replaced by a later active row.
 */
enum SalaryStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Superseded => 'gray',
        };
    }
}
