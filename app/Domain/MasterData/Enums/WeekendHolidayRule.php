<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Enums;

use Filament\Support\Contracts\HasLabel;

/** What a country does with a bank holiday that falls on a non-working day (D-62). */
enum WeekendHolidayRule: string implements HasLabel
{
    case Stays = 'stays';
    case NextWorkingDay = 'next_working_day';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stays => 'Stays on the weekend',
            self::NextWorkingDay => 'Observed on the next free working day',
        };
    }
}
