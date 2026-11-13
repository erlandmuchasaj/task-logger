<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * One login per role for development: {role}@test.com / 12345678. Idempotent on email.
 * Never runs in production, so these weak credentials cannot reach a live database.
 */
final class RoleUsersSeeder extends Seeder
{
    public const string PASSWORD = '12345678';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('RoleUsersSeeder skipped: test logins are never created in production.');

            return;
        }

        foreach (RoleName::cases() as $role) {
            $user = User::firstOrCreate(
                ['email' => "{$role->value}@test.com"],
                [
                    'name' => ucfirst($role->value),
                    'surname' => 'Test',
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                ],
            );

            app(AssignUserRole::class)->handle($user, $role);
        }
    }
}
