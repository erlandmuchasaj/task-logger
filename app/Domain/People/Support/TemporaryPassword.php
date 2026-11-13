<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use Illuminate\Support\Str;

/**
 * D-53: a readable one-off password emailed to the person, who is told to change it after the first
 * sign-in. Letters and digits only (no symbols to mistype), always with upper, lower and a digit so it
 * meets Password::defaults().
 */
final class TemporaryPassword
{
    public static function generate(int $length = 16): string
    {
        do {
            $password = Str::password($length, symbols: false);
        } while (! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password) || ! preg_match('/\d/', $password));

        return $password;
    }
}
