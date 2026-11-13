<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Expenses\Enums\ExpenseCategory;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Expense> */
final class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'created_by' => fn (array $attributes) => $attributes['user_id'],
            'category' => ExpenseCategory::Travel,
            'description' => 'Client visit',
            'number' => 'TMP-'.Str::random(12),
            'status' => ExpenseStatus::Draft,
        ];
    }
}
