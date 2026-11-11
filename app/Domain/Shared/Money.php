<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

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

    /**
     * Parses a decimal amount typed by a person ("1500.50") into minor units, without floats.
     * The number of decimals follows the currency (JPY 0, EUR 2, KWD 3).
     */
    public static function fromMajor(string $amount, string $currency): self
    {
        $digits = self::fractionDigits($currency);
        $amount = trim($amount);

        if (! preg_match('/^\d+(?:[.,]\d{1,'.max($digits, 1).'})?$/', $amount) || ($digits === 0 && preg_match('/[.,]/', $amount))) {
            throw new InvalidArgumentException("[{$amount}] is not a valid {$currency} amount.");
        }

        [$whole, $fraction] = array_pad(preg_split('/[.,]/', $amount) ?: [], 2, '');

        return new self((int) ($whole.str_pad($fraction, $digits, '0')), $currency);
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

    public function format(): string
    {
        $digits = self::fractionDigits($this->currency);

        $major = intdiv($this->minor, 10 ** $digits).($digits > 0
            ? '.'.str_pad((string) ($this->minor % (10 ** $digits)), $digits, '0', STR_PAD_LEFT)
            : '');

        return $this->currency.' '.$major;
    }

    /**
     * ISO 4217 minor-unit exponent (JPY 0, EUR 2, KWD 3). Taken from the ISO table, not from ICU: ICU
     * reports the Albanian lek (ALL) with 0 decimals while ISO 4217 says 2.
     */
    public static function fractionDigits(string $currency): int
    {
        return match (strtoupper($currency)) {
            'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' => 0,
            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' => 3,
            'CLF', 'UYW' => 4,
            default => 2,
        };
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
