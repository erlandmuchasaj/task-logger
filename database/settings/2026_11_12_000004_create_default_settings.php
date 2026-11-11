<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('privacy.anonymize_after_months', (int) config('task-logger.anonymize_after_months'));
        $this->migrator->add('leave.carry_over_default', (bool) config('task-logger.leave_carry_over_default'));
    }
};
