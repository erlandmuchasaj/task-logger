<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

enum ProjectType: string implements HasLabel
{
    case Standalone = 'standalone';
    case Recurring = 'recurring';
    case PayAsYouGo = 'pay_as_you_go';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }
}
