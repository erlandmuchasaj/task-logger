<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use Filament\Support\Contracts\HasLabel;

enum ApprovalDecision: string implements HasLabel
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
