<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Settings\SecuritySettings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The rules for two-factor authentication (ADR-0005, D-26).
 *
 * - enabled: global switch. Off means nobody is challenged and nobody can enrol.
 * - required: every user must enrol. Only meaningful while enabled.
 * - grace: a user without 2FA has `grace_days` to enrol. The period starts when the requirement was
 *   switched on, or when the user was created if that is later [ASSUMED], so new hires also get it.
 *   The end date is computed from the current grace_days, so changing it applies immediately.
 */
class TwoFactorPolicy
{
    public function isEnabled(): bool
    {
        return (bool) $this->setting('two_factor_enabled', (bool) config('task-logger.two_factor.enabled'));
    }

    public function isRequired(): bool
    {
        return $this->isEnabled()
            && (bool) $this->setting('two_factor_required', (bool) config('task-logger.two_factor.required'));
    }

    public function graceDays(): int
    {
        return max(0, (int) $this->setting('two_factor_grace_days', (int) config('task-logger.two_factor.grace_days')));
    }

    public function requiredSince(): ?CarbonImmutable
    {
        $since = $this->setting('two_factor_required_since', null);

        return $since ? CarbonImmutable::parse($since) : null;
    }

    /** When this user's grace period ends, or null when 2FA is not required. */
    public function graceEndsAt(User $user): ?CarbonImmutable
    {
        if (! $this->isRequired()) {
            return null;
        }

        $created = CarbonImmutable::instance($user->created_at ?? now());
        $start = ($since = $this->requiredSince()) && $since->greaterThan($created) ? $since : $created;

        return $start->addDays($this->graceDays());
    }

    public function isInGracePeriod(User $user): bool
    {
        return $this->isRequired()
            && ! $user->hasTwoFactorEnabled()
            && now()->lessThan($this->graceEndsAt($user));
    }

    /** True once the grace period is over and the user still has no 2FA. */
    public function mustSetUpNow(User $user): bool
    {
        return $this->isRequired()
            && ! $user->hasTwoFactorEnabled()
            && now()->greaterThanOrEqualTo($this->graceEndsAt($user));
    }

    /**
     * The settings table or its rows may not exist yet (fresh checkout before migrate, artisan
     * during install): fall back to the config defaults instead of failing to boot the panel.
     */
    private function setting(string $property, mixed $default): mixed
    {
        try {
            return app(SecuritySettings::class)->{$property};
        } catch (Throwable) {
            return $default;
        }
    }
}
