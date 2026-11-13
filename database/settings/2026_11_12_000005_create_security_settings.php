<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('security.two_factor_enabled', (bool) config('task-logger.two_factor.enabled'));
        $this->migrator->add('security.two_factor_required', (bool) config('task-logger.two_factor.required'));
        $this->migrator->add('security.two_factor_grace_days', (int) config('task-logger.two_factor.grace_days'));
        $this->migrator->add('security.two_factor_required_since', null);
    }
};
