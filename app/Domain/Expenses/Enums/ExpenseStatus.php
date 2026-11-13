<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/** SPEC 5.6. Transitions: D-52 (rejected goes back to draft to be edited and resubmitted). */
enum ExpenseStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reimbursed = 'reimbursed';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Reimbursed => 'success',
        };
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved => [self::Reimbursed],
            self::Rejected => [self::Draft],
            self::Reimbursed => [],
        };
    }

    /** Header, lines and receipt can change, and the expense can be deleted, only while it is a draft (D-52). */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
