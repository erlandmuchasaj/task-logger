<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

/**
 * Leave days are decimal(4,1) in the database. Calculations run on integer tenths so no float ever
 * touches a balance (the same rule as money, BR-12).
 */
final class Days
{
    /** "12.5" becomes 125. */
    public static function tenths(string|int $decimal): int
    {
        $decimal = (string) $decimal;
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-')), 2, '0');
        $value = ((int) $whole) * 10 + (int) substr(str_pad($fraction, 1, '0'), 0, 1);

        return $negative ? -$value : $value;
    }

    /** 125 becomes "12.5". */
    public static function format(int $tenths): string
    {
        $sign = $tenths < 0 ? '-' : '';
        $tenths = abs($tenths);

        return $sign.intdiv($tenths, 10).'.'.($tenths % 10);
    }
}
