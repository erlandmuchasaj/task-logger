<?php

declare(strict_types=1);

namespace App\Domain\Identity\Settings;

use Spatie\LaravelSettings\Settings;

/** Two-factor authentication switches (ADR-0005, D-26). Edited by the superadmin only. */
final class SecuritySettings extends Settings
{
    /** Global switch: when off, nobody is challenged and nobody can enrol. */
    public bool $two_factor_enabled;

    /** When on (and enabled), every user must enrol once their grace period ends. */
    public bool $two_factor_required;

    public int $two_factor_grace_days;

    /** ISO 8601 moment the requirement was switched on; the grace period counts from here. */
    public ?string $two_factor_required_since = null;

    public static function group(): string
    {
        return 'security';
    }
}
