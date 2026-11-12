<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/** SPEC 5.6. */
enum ExpenseCategory: string implements HasLabel
{
    case Travel = 'travel';
    case Meals = 'meals';
    case Supplies = 'supplies';
    case Equipment = 'equipment';
    case Software = 'software';
    case Other = 'other';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }
}
