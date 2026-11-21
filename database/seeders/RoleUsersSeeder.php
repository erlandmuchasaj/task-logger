<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PermissionCatalog;
use App\Domain\Leave\Actions\GrantAnnualLeave;
use App\Domain\MasterData\Models\Country;
use Exception;
use Illuminate\Database\Seeder;

/**
 * One login per role for development: {role}@test.com / 12345678. Idempotent on email.
 * Runs in every environment, production included (D-58), so change these passwords after the first deploy.
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

        $this->assignManagers();

        app(GrantAnnualLeave::class)->handle();
    }

    /**
     * D-66: everybody but the superadmin has a manager. The staff roles report to the first superadmin, the employee login to the admin login.
     * Only an empty manager is filled, so a hierarchy set by hand is never rewritten.
     */
    private function assignManagers(): void
    {
        $root = User::query()->where('email', config('task-logger.superadmin.email'))->first()
            ?? User::query()->where('email', RoleName::Superadmin->value.'@test.com')->firstOrFail();
        $admin = User::query()->where('email', RoleName::Admin->value.'@test.com')->firstOrFail();

        foreach (RoleName::cases() as $role) {
            if (PermissionCatalog::roleHoldsRoot($role)) {
                continue;
            }

            User::query()->where('email', "{$role->value}@test.com")->whereNull('manager_id')
                ->update(['manager_id' => $role === RoleName::Employee ? $admin->getKey() : $root->getKey()]);
        }
    }
}
