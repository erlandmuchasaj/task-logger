<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

enum ProjectStatus: string implements HasColor, HasLabel
{
    case Planning = 'planning';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planning => 'gray',
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Completed => 'info',
            self::Cancelled => 'danger',
        };
    }
}
