<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Shared\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an expense. `amount_minor` is quantity x unit price, computed on the server (BR-12). */
#[Fillable(['expense_id', 'description', 'quantity', 'unit_price_minor', 'amount_minor', 'currency_code', 'date'])]
class ExpenseLine extends Model
{
    use LogsBusinessActivity;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'amount_minor' => 'integer', 'date' => 'date'];
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency_code);
    }

    public function unitPrice(): Money
    {
        return Money::of($this->unit_price_minor, $this->currency_code);
    }
}
