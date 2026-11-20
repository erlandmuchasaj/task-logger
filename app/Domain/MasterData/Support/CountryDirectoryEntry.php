<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Support;

/** One country as the countries.dev lookup describes it (D-61). Every value has been checked, none is trusted. */
final readonly class CountryDirectoryEntry
{
    public function __construct(
        public string $name,
        public string $code,
        public ?string $prefix,
        public ?string $currencyCode,
        public ?string $language,
        public ?string $flag,
    ) {}

    /** @return array{name: string, code: string, prefix: ?string, currency_code: ?string, language: ?string, flag: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'code' => $this->code, 'prefix' => $this->prefix,
            'currency_code' => $this->currencyCode, 'language' => $this->language, 'flag' => $this->flag,
        ];
    }

    public function label(): string
    {
        return mb_trim(($this->flag ?? '').' '.$this->name.' ('.$this->code.')');
    }
}
