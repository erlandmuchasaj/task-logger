<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Clients\Models\Project;
use App\Domain\Expenses\Enums\ExpenseCategory;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Shared\Money;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An expense claim for an employee (SPEC 5.6, D-52). The total is the sum of its lines, in one currency. */
#[Fillable(['user_id', 'created_by', 'project_id', 'category', 'description', 'number', 'receipt_path', 'notes', 'status'])]
#[UseFactory(ExpenseFactory::class)]
final class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    /** @return BelongsTo<User, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ExpenseLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class)->orderBy('date')->orderBy('id');
    }

    /** The sum of the lines in the expense's currency, or null for an expense without lines. */
    public function total(): ?Money
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        if ($lines->isEmpty()) {
            return null;
        }

        return Money::of((int) $lines->sum('amount_minor'), $lines->first()->currency_code);
    }

    protected function casts(): array
    {
        return ['category' => ExpenseCategory::class, 'status' => ExpenseStatus::class];
    }
}
