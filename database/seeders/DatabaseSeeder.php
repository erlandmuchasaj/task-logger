<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            MasterDataSeeder::class,
            CountrySeeder::class,
            AlbaniaLeaveSeeder::class,  // 22-day policy and 2026 bank holidays
            DepartmentSeeder::class,
            TechnologySeeder::class,
            SuperadminSeeder::class,
            RoleUsersSeeder::class,     // {role}@test.com / 12345678, every environment (D-58)
            LocalDemoSeeder::class,     // dummy customers, projects, tasks, logs, users: local, or any environment with --force (D-58)
        ]);
    }
}
