<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\Salary;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Salary> */
final class SalaryFactory extends Factory
{
    protected $model = Salary::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'amount_minor' => 150_000,
            'currency_code' => 'EUR',
            'effective_date' => '2026-01-01',
            'status' => SalaryStatus::Active,
        ];
    }
}
