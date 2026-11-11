<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

enum EmploymentType: string implements HasLabel
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contractor = 'contractor';
    case Freelance = 'freelance';
    case Intern = 'intern';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }
}
