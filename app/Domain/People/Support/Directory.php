<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public profile (D-14, BR-26): name, surname, position, department, skills and avatar, and nothing
 * else. Every directory screen, resource and export must build on this query so private columns
 * (email, country, manager, employment type, start date) are never selected for people who lack
 * employees.view_hr_record.
 */
final class Directory
{
    /** Public columns of `users`. `id` and `department_id` are needed to load the relations. */
    public const COLUMNS = ['id', 'name', 'surname', 'position', 'department_id', 'avatar_path'];

    /** @return Builder<User> */
    public static function query(): Builder
    {
        return User::query()
            ->select(self::COLUMNS)
            ->whereNull('banned_at')
            ->whereNull('anonymized_at')
            ->with(['department:id,name', 'technologies:id,name'])
            ->orderBy('name')
            ->orderBy('surname');
    }
}
