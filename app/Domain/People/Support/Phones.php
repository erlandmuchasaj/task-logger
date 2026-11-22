<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Validation\ValidationException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
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

    /** A valid mobile number of the given country (D-60). A number typed with its own `+` code is checked against that country too. */
    public static function isMobileOf(string $input, ?string $countryCode): bool
    {
        try {
            $number = new PhoneNumber($input, $countryCode);

            return $number->isValid()
                && ($countryCode === null || $number->isOfCountry($countryCode))
                && $number->isOfType(['mobile', 'fixed_line_or_mobile']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * E.164 of a mobile number of the country. National input works with or without the leading 0 (D-60).
     *
     * @throws ValidationException
     */
    public static function mobileToE164(?string $input, ?string $countryCode, ?string $countryName = null, string $field = 'phone'): ?string
    {
        $input = $input === null ? null : mb_trim($input);

        if ($input === null || $input === '') {
            return null;
        }

        if (! self::isMobileOf($input, $countryCode)) {
            throw ValidationException::withMessages([$field => self::mobileMessage($countryName, $countryCode)]);
        }

        return new PhoneNumber($input, $countryCode)->formatE164();
    }

    /**
     * The phone to store for a person's profile (D-60): a mobile of their country. A number that is already stored
     * stays as it is, so an older landline never blocks an edit of another field.
     *
     * @throws ValidationException
     */
    public static function forProfile(User $employee, ?string $input, ?string $countryCode, string $field = 'phone'): ?string
    {
        $input = $input === null ? null : mb_trim($input);

        if ($input === null || $input === '') {
            return null;
        }

        $stored = $employee->profile()->value('phone');

        if (is_string($stored) && self::isValid($input, $countryCode) && self::toE164($input, $countryCode) === $stored) {
            return $stored;
        }

        return self::mobileToE164($input, $countryCode, $employee->country()->value('name'), $field);
    }

    public static function mobileMessage(?string $countryName, ?string $countryCode = null): string
    {
        if ($countryName === null) {
            return 'Enter a valid mobile number with the country code.';
        }

        $example = self::exampleMobile($countryCode);

        return "Enter a mobile number for {$countryName}".($example === null ? '.' : ", e.g. {$example}.");
    }

    /** A sample national mobile number of the country, as the library knows it (for messages and placeholders). */
    public static function exampleMobile(?string $countryCode): ?string
    {
        if ($countryCode === null) {
            return null;
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $example = $util->getExampleNumberForType($countryCode, PhoneNumberType::MOBILE);

            return $example instanceof \libphonenumber\PhoneNumber ? $util->format($example, PhoneNumberFormat::NATIONAL) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What to show after the country's dial prefix: the national significant number of a number of that country,
     * or the E.164 number unchanged when it belongs elsewhere.
     */
    public static function forCountryField(?string $e164, ?string $countryCode): ?string
    {
        if ($e164 === null || $e164 === '' || $countryCode === null) {
            return $e164;
        }

        try {
            $number = new PhoneNumber($e164);

            if (! $number->isValid() || ! $number->isOfCountry($countryCode)) {
                return $e164;
            }

            return (string) $number->toLibPhoneObject()->getNationalNumber();
        } catch (Throwable) {
            return $e164;
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
