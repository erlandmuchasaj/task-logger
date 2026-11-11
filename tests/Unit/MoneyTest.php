<?php

declare(strict_types=1);

use App\Domain\Shared\Money;

it('BR-12 stores integer minor units plus an ISO currency', function () {
    $money = Money::of(2450, 'eur');

    expect($money->minor)->toBe(2450)->and($money->currency)->toBe('EUR');
})->group('BR-12');

it('BR-12 rejects floats because money is never a float', function () {
    Money::of(24.5, 'EUR');
})->group('BR-12')->throws(TypeError::class);

it('BR-12 rejects invalid currency codes and negative amounts', function (string $currency, int $minor) {
    Money::of($minor, $currency);
})->group('BR-12')->throws(InvalidArgumentException::class)->with([
    'too short' => ['EU', 100],
    'digits' => ['E1R', 100],
    'negative' => ['EUR', -1],
]);

it('BR-12 adds and subtracts within one currency', function () {
    $sum = Money::of(1000, 'EUR')->add(Money::of(250, 'EUR'));

    expect($sum->minor)->toBe(1250)
        ->and($sum->subtract(Money::of(50, 'EUR'))->minor)->toBe(1200);
})->group('BR-12');

it('BR-12 never converts: mixing currencies throws', function () {
    Money::of(1000, 'EUR')->add(Money::of(1000, 'ALL'));
})->group('BR-12')->throws(InvalidArgumentException::class);

it('BR-12 reports group by currency without conversion', function () {
    $groups = Money::groupByCurrency([
        Money::of(1000, 'EUR'), Money::of(5000, 'ALL'), Money::of(250, 'EUR'),
    ]);

    expect(array_keys($groups))->toBe(['ALL', 'EUR'])
        ->and($groups['EUR']->minor)->toBe(1250)
        ->and($groups['ALL']->minor)->toBe(5000);
})->group('BR-12');

it('BR-12 formats using the ISO 4217 currency exponent', function () {
    expect(Money::of(2450, 'EUR')->format())->toBe('EUR 24.50')
        ->and(Money::of(2450, 'JPY')->format())->toBe('JPY 2450')
        ->and(Money::of(5, 'EUR')->format())->toBe('EUR 0.05');
})->group('BR-12');

it('BR-12 parses typed amounts into minor units without floats', function (string $input, string $currency, int $minor) {
    expect(Money::fromMajor($input, $currency)->minor)->toBe($minor);
})->group('BR-12')->with([
    ['1500', 'EUR', 150000],
    ['1500.5', 'EUR', 150050],
    ['1500,50', 'EUR', 150050],
    ['0.07', 'EUR', 7],
    ['1500', 'JPY', 1500],
    ['1.234', 'KWD', 1234],
    ['19.99', 'USD', 1999],
]);

it('BR-12 rejects malformed or too precise amounts', function (string $input, string $currency) {
    Money::fromMajor($input, $currency);
})->group('BR-12')->throws(InvalidArgumentException::class)->with([
    ['abc', 'EUR'], ['-5', 'EUR'], ['1.234', 'EUR'], ['10.5', 'JPY'], ['', 'EUR'],
]);

it('BR-12 minor units follow ISO 4217, not ICU: the Albanian lek has 2 decimals', function () {
    expect(Money::fractionDigits('ALL'))->toBe(2)
        ->and(Money::fractionDigits('EUR'))->toBe(2)
        ->and(Money::fractionDigits('JPY'))->toBe(0)
        ->and(Money::fractionDigits('ISK'))->toBe(0)
        ->and(Money::fractionDigits('KWD'))->toBe(3)
        ->and(Money::fractionDigits('CLF'))->toBe(4)
        ->and(Money::fromMajor('1200.50', 'ALL')->minor)->toBe(120050)
        ->and(Money::of(120050, 'ALL')->format())->toBe('ALL 1200.50');
})->group('BR-12');
