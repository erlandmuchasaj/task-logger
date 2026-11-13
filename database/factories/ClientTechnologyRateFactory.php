<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Models\Customer;
use App\Domain\MasterData\Models\Technology;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClientTechnologyRate> */
final class ClientTechnologyRateFactory extends Factory
{
    protected $model = ClientTechnologyRate::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'technology_id' => Technology::factory(),
            'rate_minor' => 2400,
            'effective_from' => '2025-01-01',
        ];
    }
}
