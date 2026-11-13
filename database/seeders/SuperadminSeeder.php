<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

/** Creates the first superadmin only when SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD are set. */
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

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Super', 'surname' => 'Admin', 'password' => $password, 'email_verified_at' => now()],
        );

        app(AssignUserRole::class)->handle($user, RoleName::Superadmin);
    }
}
