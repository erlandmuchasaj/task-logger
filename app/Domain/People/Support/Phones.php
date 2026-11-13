<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use Illuminate\Validation\ValidationException;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/** Phones are stored in E.164 (SPEC 5.0), validated with propaganistas/laravel-phone. */
final class Phones
{
    public static function isValid(string $input, ?string $countryCode = null): bool
    {
        try {
            return new PhoneNumber($input, $countryCode)->isValid();
        } catch (Throwable) {
            return false;
        }
    }

    /** @throws ValidationException */
    public static function toE164(?string $input, ?string $countryCode, string $field = 'phone'): ?string
    {
        $input = $input === null ? null : mb_trim($input);

        if ($input === null || $input === '') {
            return null;
        }

        if (! self::isValid($input, $countryCode)) {
            throw ValidationException::withMessages([$field => 'The phone number is not valid. Include the country code, e.g. +355 69 123 4567.']);
        }

        return new PhoneNumber($input, $countryCode)->formatE164();
    }
}
