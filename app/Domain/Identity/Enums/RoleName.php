<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Role names are labels for permission bundles (SPEC 4.1). Application code must never
 * authorise on these; they exist for the seeder and for assigning a user's single role (D-09).
 */
enum RoleName: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Hr = 'hr';
    case Assistant = 'assistant';
    case Employee = 'employee';
}
