<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use Filament\Support\Contracts\HasLabel;

enum LeaveStatus: string implements HasLabel
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
}
