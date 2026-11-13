<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            MasterDataSeeder::class,
            CountrySeeder::class,
            DepartmentSeeder::class,
            TechnologySeeder::class,
            SuperadminSeeder::class,
            RoleUsersSeeder::class,     // {role}@test.com / 12345678, never in production
            //            LocalDemoSeeder::class,     // dummy customers, projects, tasks, logs, users: local only
        ]);
    }
}
