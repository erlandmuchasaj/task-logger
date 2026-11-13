<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\MasterData\Models\Technology;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Shared\Money;
use Database\Factories\ClientTechnologyRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An hourly rate for a client and technology from a date on. The rate for a date is the row with the
 * latest effective_from on or before it. The currency is the customer's.
 */
#[Fillable(['customer_id', 'technology_id', 'rate_minor', 'effective_from'])]
#[UseFactory(ClientTechnologyRateFactory::class)]
final class ClientTechnologyRate extends Model
{
    /** @use HasFactory<ClientTechnologyRateFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    public function money(): Money
    {
        return Money::of($this->rate_minor, $this->customer->currency_code);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Technology, $this> */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }

    protected function casts(): array
    {
        return ['rate_minor' => 'integer', 'effective_from' => 'date'];
    }
}
