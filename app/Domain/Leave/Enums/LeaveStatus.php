<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum LeaveStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Taken = 'taken';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved, self::Taken => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::Clock,
            self::Approved => Heroicon::Check,
            self::Taken => Heroicon::CheckBadge,
            self::Rejected => Heroicon::XMark,
            self::Cancelled => Heroicon::NoSymbol,
        };
    }
}
