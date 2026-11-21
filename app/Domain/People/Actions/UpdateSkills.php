<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** D-65: sets a person's technologies and levels on their own, for HR, who may change skills but not the account. */
final readonly class UpdateSkills
{
    public function __construct(private SyncSkills $syncSkills) {}

    /** @param  array<int, int>  $skills  technology_id => level */
    public function handle(User $actor, User $employee, array $skills): void
    {
        Gate::forUser($actor)->authorize('updateSkills', $employee);

        resolve(CauserResolver::class)->withCauser($actor, fn () => DB::transaction(fn () => $this->syncSkills->handle($employee, $skills)));
    }
}
