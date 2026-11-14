<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Carbon\CarbonImmutable;

/**
 * Month and date values that reach a calendar from the browser (URL query string, Livewire state and click
 * arguments). They are only ever read through these two methods, which refuse anything that is not a real value.
 */
final class CalendarInput
{
    /** Months outside 2000 to 2099 are refused; the month comes from the browser (URL and Livewire state). */
    public static function parseMonth(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/D', $value) !== 1) {
            return null;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $value, 'UTC');

        return $month instanceof CarbonImmutable ? $month : null;
    }

    /** A real calendar date as `Y-m-d`; `2026-02-30` is refused instead of rolling over to March. */
    public static function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
