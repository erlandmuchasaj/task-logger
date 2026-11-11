<?php

declare(strict_types=1);

namespace App\Domain\Work\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/** BR-05. */
enum LogType: string implements HasLabel
{
    case NormalHours = 'normal_hours';
    case ExtraHours = 'extra_hours';
    case ReplacementHours = 'replacement_hours';
    case Others = 'others';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }
}
