<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Permissions;
use LogicException;

/**
 * Reads the role => permission map from config/permission.php ('roles') and validates it against
 * the constants in {@see Permissions}. The map is the single source of truth for the seeder and the
 * grid test (docs/SPEC.md 4.4).
 */
final class PermissionCatalog
{
    /** @return list<string> */
    public static function permissions(): array
    {
        return Permissions::all();
    }

    /** @return list<string> */
    public static function forRole(RoleName $role): array
    {
        /** @var array<string, list<string>> $map */
        $map = config('permission.roles');

        $held = $map[$role->value] ?? throw new LogicException("Role [{$role->value}] is missing from config/permission.php.");

        $unknown = array_diff($held, Permissions::all());
        if ($unknown !== []) {
            throw new LogicException('Unknown permissions for role '.$role->value.': '.implode(', ', $unknown));
        }

        return array_values(array_unique($held));
    }

    /**
     * Every role inherits the employee baseline (D-09).
     *
     * @return list<string>
     */
    public static function employeeBaseline(): array
    {
        return self::forRole(RoleName::Employee);
    }
}
