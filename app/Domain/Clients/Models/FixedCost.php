<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Shared\Money;
use Database\Factories\FixedCostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fixed cost for a project in a month, e.g. Management, 100 EUR, September (BR-14). */
#[Fillable(['project_id', 'name', 'date', 'amount_minor', 'currency_code', 'created_by'])]
#[UseFactory(FixedCostFactory::class)]
final class FixedCost extends Model
{
    /** @use HasFactory<FixedCostFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    public function money(): Money
    {
        return Money::of($this->amount_minor, $this->currency_code);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['date' => 'date', 'amount_minor' => 'integer'];
    }
}
