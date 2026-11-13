<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\GrantAnnualLeave;
use App\Domain\MasterData\Models\Country;
use Exception;
use Illuminate\Database\Seeder;

/**
 * One login per role for development: {role}@test.com / 12345678. Idempotent on email.
 * Never runs in production, so these weak credentials cannot reach a live database.
 * Each login is placed in Albania with an earlier start date and granted its annual leave, so My Leave
 * shows the full policy straight after seeding (needs CountrySeeder and AlbaniaLeaveSeeder first).
 */
final class RoleUsersSeeder extends Seeder
{
    public const string PASSWORD = '12345678';

    /**
     * @throws Exception
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('RoleUsersSeeder skipped: test logins are never created in production.');

            return;
        }

        $albaniaId = Country::query()->where('code', 'AL')->value('id');

        foreach (RoleName::cases() as $role) {
            $user = User::firstOrCreate(
                ['email' => "{$role->value}@test.com"],
                [
                    'name' => ucfirst($role->value),
                    'surname' => 'Test',
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                    'country_id' => $albaniaId,
                    'start_date' => '2025-01-01',
                ],
            );

            app(AssignUserRole::class)->handle($user, $role);
        }

        app(GrantAnnualLeave::class)->handle();
    }
}
