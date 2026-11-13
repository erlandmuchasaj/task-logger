<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\GrantAnnualLeave;
use App\Domain\MasterData\Models\Country;
use Illuminate\Database\Seeder;

/**
 * Creates the first superadmin only when SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD are set.
 * Outside production the superadmin is also placed in Albania with an earlier start date and granted its
 * annual leave, like the role logins. In production no HR data is invented: set country and start date on the employee record.
 */
final class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('task-logger.superadmin.email');
        $password = config('task-logger.superadmin.password');

        if (! $email || ! $password) {
            $this->command?->warn('SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD not set: no superadmin created.');

            return;
        }

        $attributes = ['name' => 'Super', 'surname' => 'Admin', 'password' => $password, 'email_verified_at' => now()];

        if (! app()->isProduction()) {
            $attributes['country_id'] = Country::query()->where('code', 'AL')->value('id');
            $attributes['start_date'] = '2025-01-01';
        }

        $user = User::firstOrCreate(['email' => $email], $attributes);

        app(AssignUserRole::class)->handle($user, RoleName::Superadmin);

        if (! app()->isProduction()) {
            app(GrantAnnualLeave::class)->handle($user);
        }
    }
}
