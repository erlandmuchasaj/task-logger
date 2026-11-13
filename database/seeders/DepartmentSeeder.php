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

        /** Every department gets its own color; no two share one. */
        $colors = [
            'Engineering' => '#3b82f6',
            'Backend' => '#1d4ed8',
            'Frontend' => '#06b6d4',
            'Mobile' => '#6366f1',
            'DevOps' => '#0f766e',
            'Design' => '#ec4899',
            'Quality Assurance' => '#f59e0b',
            'Project Management' => '#8b5cf6',
            'Human Resources' => '#ef4444',
            'Finance & Administration' => '#22c55e',
            'Sales' => '#f97316',
        ];

        foreach ($tree as $name => $children) {
            $parent = Department::firstOrCreate(['name' => $name], ['is_active' => true]);
            $this->applyColor($parent, $colors[$name]);

            foreach ($children as $child) {
                $department = Department::firstOrCreate(['name' => $child], ['parent_id' => $parent->id, 'is_active' => true]);
                $this->applyColor($department, $colors[$child]);
            }
        }
    }

    /** Only fills a missing color, so a color an admin chose is never overwritten. */
    private function applyColor(Department $department, string $color): void
    {
        if ($department->color === null) {
            $department->update(['color' => $color]);
        }
    }
}
