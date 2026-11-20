<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Support;

use App\Domain\Shared\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The optional country lookup on the "New country" form (D-61, ADR-0011). It asks countries.dev from the server,
 * sends only the typed name, and never breaks the form: a failure is logged and gives an empty answer.
 * The response is untrusted, so every value is checked before it can reach a form field.
 */
final class CountryDirectory
{
    private const string FIELDS = 'name,alpha2Code,callingCodes,currencies,languages,flag';

    /** @return list<CountryDirectoryEntry> */
    public static function search(string $term): array
    {
        $term = mb_substr(mb_trim($term), 0, 60);

        if (mb_strlen($term) < 2) {
            return [];
        }

        /** @var list<array{name: string, code: string, prefix: ?string, currency_code: ?string, language: ?string, flag: ?string}> $rows */
        $rows = Cache::remember(
            'country-directory:search:'.md5(mb_strtolower($term)),
            self::ttl(),
            fn (): array => array_map(fn (CountryDirectoryEntry $entry): array => $entry->toArray(), self::fetch('/name/'.rawurlencode($term))),
        );

        $entries = array_map(self::fromArray(...), $rows);

        foreach ($entries as $entry) {
            Cache::put(self::codeKey($entry->code), $entry->toArray(), self::ttl());
        }

        return $entries;
    }

    /** The code comes from the browser, so only two capital letters are accepted. */
    public static function find(string $code): ?CountryDirectoryEntry
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }

        /** @var array{name: string, code: string, prefix: ?string, currency_code: ?string, language: ?string, flag: ?string}|null $cached */
        $cached = Cache::get(self::codeKey($code));

        if (is_array($cached)) {
            return self::fromArray($cached);
        }

        $entry = self::fetch('/alpha/'.$code)[0] ?? null;

        if ($entry !== null && $entry->code === $code) {
            Cache::put(self::codeKey($code), $entry->toArray(), self::ttl());

            return $entry;
        }

        return null;
    }

    /** @return list<CountryDirectoryEntry> */
    private static function fetch(string $path): array
    {
        try {
            $response = self::client()->get($path, ['fields' => self::FIELDS]);

            if ($response->status() === 404) {
                return [];
            }

            if (! $response->successful()) {
                Log::warning('Country lookup failed.', ['status' => $response->status()]);

                return [];
            }

            $body = $response->json();
        } catch (Throwable $throwable) {
            Log::warning('Country lookup failed.', ['error' => $throwable->getMessage()]);

            return [];
        }

        if (! is_array($body)) {
            return [];
        }

        // /name answers with a list, /alpha with one object.
        $items = array_is_list($body) ? $body : [$body];
        $entries = [];

        foreach ($items as $item) {
            $entry = is_array($item) ? self::map($item) : null;

            if ($entry instanceof CountryDirectoryEntry) {
                $entries[] = $entry;
            }
        }

        return array_slice($entries, 0, 25);
    }

    /** @param  array<array-key, mixed>  $item */
    private static function map(array $item): ?CountryDirectoryEntry
    {
        $name = $item['name'] ?? null;
        $code = $item['alpha2Code'] ?? null;

        if (! is_string($name) || mb_trim($name) === '' || ! is_string($code) || preg_match('/^[A-Za-z]{2}$/', $code) !== 1) {
            return null;
        }

        $flag = $item['flag'] ?? null;

        return new CountryDirectoryEntry(
            name: mb_substr(mb_trim($name), 0, 255),
            code: mb_strtoupper($code),
            prefix: self::prefix($item['callingCodes'] ?? null),
            currencyCode: self::currency($item['currencies'] ?? null),
            language: self::language($item['languages'] ?? null),
            flag: is_string($flag) && mb_strlen($flag) <= 16 && preg_match('/^[^\x00-\x7F]+$/u', $flag) === 1 ? $flag : null,
        );
    }

    private static function prefix(mixed $callingCodes): ?string
    {
        $first = is_array($callingCodes) ? ($callingCodes[0] ?? null) : null;
        $digits = is_string($first) || is_int($first) ? preg_replace('/\D/', '', (string) $first) : null;

        return $digits !== null && $digits !== '' && mb_strlen($digits) <= 7 ? '+'.$digits : null;
    }

    private static function currency(mixed $currencies): ?string
    {
        foreach (is_array($currencies) ? $currencies : [] as $currency) {
            $code = is_array($currency) ? ($currency['code'] ?? null) : null;

            if (is_string($code) && preg_match('/^[A-Za-z]{3}$/', $code) === 1 && Money::isIsoCurrency($code)) {
                return mb_strtoupper($code);
            }
        }

        return null;
    }

    private static function language(mixed $languages): ?string
    {
        foreach (is_array($languages) ? $languages : [] as $language) {
            $code = is_array($language) ? ($language['iso639_1'] ?? null) : null;

            if (is_string($code) && preg_match('/^[a-z]{2}$/', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    /** @param  array{name: string, code: string, prefix: ?string, currency_code: ?string, language: ?string, flag: ?string}  $row */
    private static function fromArray(array $row): CountryDirectoryEntry
    {
        return new CountryDirectoryEntry($row['name'], $row['code'], $row['prefix'], $row['currency_code'], $row['language'], $row['flag']);
    }

    private static function client(): PendingRequest
    {
        return Http::baseUrl((string) config('task-logger.country_directory.base_url'))
            ->timeout((int) config('task-logger.country_directory.timeout'))
            ->acceptJson();
    }

    private static function codeKey(string $code): string
    {
        return 'country-directory:code:'.$code;
    }

    private static function ttl(): int
    {
        return (int) config('task-logger.country_directory.cache_hours') * 3600;
    }
}
