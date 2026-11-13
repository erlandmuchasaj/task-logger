<?php

declare(strict_types=1);

namespace App\Domain\Exports\Exporters;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One export. Columns depend on who asks (BR-33, D-05): rate, budget and payment-plan columns need
 * clients.view_rates, so an assistant's export never contains them.
 */
interface Exporter
{
    /** May this user export this type? Checked again when the job runs. */
    public function canExport(User $user): bool;

    /**
     * Column key => heading, in order.
     *
     * @return array<string, string>
     */
    public function columns(User $user): array;

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<*>
     */
    public function query(User $user, array $filters): Builder;

    /**
     * Values keyed by column key.
     *
     * @return array<string, string|int|float|null>
     */
    public function row(Model $record, User $user): array;
}
