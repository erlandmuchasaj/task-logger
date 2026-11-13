<?php

declare(strict_types=1);

namespace App\Domain\Clients\Support;

use App\Domain\Clients\Models\ClientTechnologyRate;
use Carbon\CarbonInterface;

/** Rate lookups. BR-06 (M4) reuses forDate() to snapshot the rate on a log. */
final class ClientRates
{
    /** The rate for a date: the row with the latest effective_from on or before it. */
    public static function forDate(int $customerId, int $technologyId, CarbonInterface $date): ?ClientTechnologyRate
    {
        return ClientTechnologyRate::query()
            ->where('customer_id', $customerId)
            ->where('technology_id', $technologyId)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * A client's technologies are the ones with at least one rate row (SPEC 5.3).
     *
     * @return list<int>
     */
    public static function technologyIds(int $customerId): array
    {
        return array_values(ClientTechnologyRate::query()->where('customer_id', $customerId)->distinct()->pluck('technology_id')
            ->map(fn ($id): int => (int) $id)->all());
    }
}
