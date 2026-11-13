<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\CustomerData;
use App\Domain\Clients\Models\Customer;
use App\Domain\MasterData\Models\Country;
use App\Domain\People\Support\Phones;
use App\Domain\Shared\Money;
use Illuminate\Validation\ValidationException;

trait ValidatesCustomer
{
    /**
     * @return array{currency: string, phone: ?string}
     *
     * @throws ValidationException
     */
    private function validateCustomer(CustomerData $data, ?Customer $existing): array
    {
        $errors = [];

        if (! filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $country = Country::query()->find($data->countryId);
        if ($country === null) {
            throw ValidationException::withMessages(['country_id' => 'Choose a country.']);
        }

        // D-18: the currency defaults to the country's and is fixed once rates or logs exist.
        $currency = mb_strtoupper($data->currencyCode ?: $country->currency_code);
        if (! Money::isIsoCurrency($currency)) {
            $errors['currency_code'] = 'Use a valid ISO 4217 currency code, for example EUR.';
        } elseif ($existing instanceof Customer && $currency !== $existing->currency_code && $existing->currencyIsLocked()) {
            $errors['currency_code'] = 'The currency cannot change once the client has rates or logged work.';
        }

        $phone = null;
        try {
            $phone = Phones::toE164($data->phone, $country->code);
        } catch (ValidationException $validationException) {
            $errors['phone'] = $validationException->errors()['phone'][0];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['currency' => $currency, 'phone' => $phone];
    }
}
