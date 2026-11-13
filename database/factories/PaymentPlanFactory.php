<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Enums\PaymentPlanType;
use App\Domain\MasterData\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentPlan> */
class PaymentPlanFactory extends Factory
{
    protected $model = PaymentPlan::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'type' => PaymentPlanType::DaysAfterInvoice, 'days' => 30];
    }
}
