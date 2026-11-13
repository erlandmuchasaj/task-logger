<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/** Label, colour and icon are read by every badge, toggle button and filter that shows the type. */
enum EmploymentType: string implements HasColor, HasIcon, HasLabel
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

    public function getColor(): string
    {
        return match ($this) {
            self::FullTime => 'success',
            self::PartTime => 'info',
            self::Contractor, self::Freelance => 'warning',
            self::Intern => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::FullTime => Heroicon::UserGroup,
            self::PartTime => Heroicon::Clock,
            self::Contractor => Heroicon::Briefcase,
            self::Freelance => Heroicon::GlobeAlt,
            self::Intern => Heroicon::AcademicCap,
        };
    }
}
