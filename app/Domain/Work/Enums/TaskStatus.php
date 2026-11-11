<?php

declare(strict_types=1);

namespace App\Domain\Work\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/** SPEC 5.4 (the source says "etc.", so the set is extensible). No transition rules are defined. */
enum TaskStatus: string implements HasColor, HasLabel
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return Str::headline($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Backlog => 'gray',
            self::Todo => 'info',
            self::InProgress => 'warning',
            self::InReview => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** A closed task stops being pinned in My Day (BR-08, M4). */
    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
