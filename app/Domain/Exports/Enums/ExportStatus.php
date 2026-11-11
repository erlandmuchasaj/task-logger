<?php

declare(strict_types=1);

namespace App\Domain\Exports\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

enum ExportStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Processing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Expired => 'gray',
        };
    }
}
