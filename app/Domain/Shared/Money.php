<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;
use NumberFormatter;

/**
 * Money as integer minor units plus an ISO 4217 currency (BR-12, SPEC rule 4). Never floats.
 * No currency conversion exists in v1: arithmetic across currencies throws.
 */
final readonly class Money
{
    public string $currency;

    public function __construct(public int $minor, string $currency)
    {
        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code [{$currency}].");
        }

        if ($minor < 0) {
            throw new InvalidArgumentException('Money amounts are unsigned minor units.');
        }

        $this->currency = $currency;
    }

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /**
     * Reports group by currency and never convert (BR-12).
     *
     * @param  iterable<self>  $amounts
     * @return array<string, self> keyed by currency code
     */
    public static function groupByCurrency(iterable $amounts): array
    {
        $groups = [];

        foreach ($amounts as $amount) {
            $groups[$amount->currency] = isset($groups[$amount->currency])
                ? $groups[$amount->currency]->add($amount)
                : $amount;
        }

        ksort($groups);

        return $groups;
    }

    public function format(string $locale = 'en'): string
    {
        // ISO 4217 minor-unit exponent comes from ICU (JPY 0, EUR 2, KWD 3).
        $digits = (new NumberFormatter($locale.'@currency='.$this->currency, NumberFormatter::CURRENCY))
            ->getAttribute(NumberFormatter::FRACTION_DIGITS);

        $major = intdiv($this->minor, 10 ** $digits).($digits > 0
            ? '.'.str_pad((string) ($this->minor % (10 ** $digits)), $digits, '0', STR_PAD_LEFT)
            : '');

        return $this->currency.' '.$major;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} and {$other->currency}: no conversion in v1 (BR-12)."
            );
        }
    }
}
