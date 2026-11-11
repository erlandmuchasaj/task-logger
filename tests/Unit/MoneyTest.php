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

it('BR-12 formats using the currency exponent from ICU', function () {
    expect(Money::of(2450, 'EUR')->format())->toBe('EUR 24.50')
        ->and(Money::of(2450, 'JPY')->format())->toBe('JPY 2450')
        ->and(Money::of(5, 'EUR')->format())->toBe('EUR 0.05');
})->group('BR-12');
