<?php

declare(strict_types=1);

namespace App\Domain\Exports\Support;

/** Spreadsheet formula injection: a text cell that starts with = + - @ is read as a formula by Excel, so it is prefixed with an apostrophe. */
final class CsvSafe
{
    public static function cell(string|int|float|null $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }
}
