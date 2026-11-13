<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Support;

use App\Domain\Clients\Data\CustomerData;
use App\Domain\Clients\Models\Customer;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\Shared\IsoCurrency;
use App\Domain\Shared\Priority;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** The client form and its mapping to CustomerData. The payment plan is shown only with clients.view_rates (D-05). */
final class CustomerForm
{
    public static function configure(Schema $schema, ?Customer $record): Schema
    {
        $canSeeRates = (bool) auth()->user()?->can('viewRates', Customer::class);

        return $schema->components([
            Section::make('Contact')->columns(2)->schema([
                TextInput::make('name')->label('Contact name')->required()->maxLength(255),
                TextInput::make('surname')->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255),
                TextInput::make('phone')->tel()->placeholder('+355 69 123 4567'),
                TextInput::make('company_name')->label('Company')->maxLength(255),
                FileUpload::make('avatar_path')->label('Logo or avatar')->image()->avatar()->disk('public')->directory('customers')->maxSize(2048),
                Textarea::make('address')->columnSpanFull(),
            ]),
            Section::make('Account')->columns(2)->schema([
                Select::make('country_id')->label('Country')
                    ->options(fn (): array => Country::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required()->live()
                    ->afterStateUpdated(function (mixed $state, callable $set) use ($record): void {
                        if (! $record?->currencyIsLocked()) {
                            $set('currency_code', Country::query()->whereKey($state)->value('currency_code'));
                        }
                    }),
                Select::make('priority')->options(Priority::class)->required(),
                TextInput::make('currency_code')->label('Currency (ISO 4217)')->length(3)->alpha()->rules([new IsoCurrency])->required()
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state ? mb_strtoupper($state) : null)
                    ->disabled($record?->currencyIsLocked() ?? false)->dehydrated()
                    ->helperText(($record?->currencyIsLocked() ?? false) ? 'Fixed: this client has rates or logged work.' : 'Defaults to the country currency.'),
                Select::make('payment_plan_id')->label('Payment plan')
                    ->options(fn (): array => PaymentPlan::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->visible($canSeeRates)
                    ->helperText('Metadata only: no invoice is generated.'),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function fill(Customer $customer): array
    {
        return [
            ...$customer->only(['name', 'surname', 'email', 'phone', 'company_name', 'avatar_path', 'address', 'country_id', 'currency_code', 'payment_plan_id']),
            'priority' => $customer->priority->value,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function data(array $data, ?Customer $existing): CustomerData
    {
        $priority = $data['priority'];

        return new CustomerData(
            name: (string) $data['name'],
            surname: $data['surname'] ?? null,
            email: (string) $data['email'],
            countryId: (int) $data['country_id'],
            priority: $priority instanceof Priority ? $priority : Priority::from((string) $priority),
            phone: $data['phone'] ?? null,
            address: $data['address'] ?? null,
            companyName: $data['company_name'] ?? null,
            // Without clients.view_rates the payment plan is not on the form: keep the stored one.
            paymentPlanId: array_key_exists('payment_plan_id', $data) ? (filled($data['payment_plan_id']) ? (int) $data['payment_plan_id'] : null) : $existing?->payment_plan_id,
            currencyCode: $data['currency_code'] ?? $existing?->currency_code,
            avatarPath: $data['avatar_path'] ?? null,
        );
    }
}
