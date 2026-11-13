<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\MasterData\Models\Department;
use Illuminate\Database\Seeder;

/** Starter department tree. Idempotent on `name`; slugs come from sluggable. */
final class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'Engineering' => ['Backend', 'Frontend', 'Mobile', 'DevOps'],
            'Design' => [],
            'Quality Assurance' => [],
            'Project Management' => [],
            'Human Resources' => [],
            'Finance & Administration' => [],
            'Sales' => [],
        ];

        foreach ($tree as $name => $children) {
            $parent = Department::firstOrCreate(['name' => $name], ['is_active' => true]);

            foreach ($children as $child) {
                Department::firstOrCreate(['name' => $child], ['parent_id' => $parent->id, 'is_active' => true]);
            }
        }
    }
}
