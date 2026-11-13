<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SPEC 2.3 "carry-over toggle". Carry-over is off by default (D-08); a country policy may turn it
 * on per country, and this is the global default those policies start from.
 */
final class LeaveSettings extends Settings
{
    public bool $carry_over_default;

    public static function group(): string
    {
        return 'leave';
    }
}
