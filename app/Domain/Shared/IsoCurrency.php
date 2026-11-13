<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A currency field must hold a code from the ISO 4217 table in {@see Money}, in any letter case. */
final class IsoCurrency implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Money::isIsoCurrency($value)) {
            $fail('Use a valid ISO 4217 currency code, for example EUR.');
        }
    }
}
