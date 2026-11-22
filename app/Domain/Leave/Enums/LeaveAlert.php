<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** D-70: why an approver or HR is told about a pending leave request. */
enum LeaveAlert: string implements HasColor, HasLabel
{
    case Submitted = 'submitted';
    case Reminder = 'reminder';
    case Escalated = 'escalated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Submitted => 'New leave request',
            self::Reminder => 'Leave request still pending',
            self::Escalated => 'Leave request starts today and is still pending',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'info',
            self::Reminder => 'warning',
            self::Escalated => 'danger',
        };
    }
}
