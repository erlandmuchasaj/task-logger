<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** D-74: the people a manager still manages: not deactivated, not anonymised (and not deleted). Those stay on record under their old manager. */
final class DirectReports
{
    /** @return Builder<User> */
    public static function activeOf(User $manager): Builder
    {
        return User::query()
            ->where('manager_id', $manager->getKey())
            ->whereNull('banned_at')
            ->whereNull('anonymized_at');
    }
}
