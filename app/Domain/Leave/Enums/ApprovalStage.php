<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use Filament\Support\Contracts\HasLabel;

enum ApprovalStage: string implements HasLabel
{
    case Manager = 'manager';
    case Hr = 'hr';

    public function getLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
