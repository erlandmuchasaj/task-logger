<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\MasterData\Models\Technology;
use Illuminate\Database\Seeder;

/** Starter technologies (skills and areas, D-01). Idempotent on `name`; extend through the UI. */
final class TechnologySeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'Laravel', 'PHP', 'Angular', 'React', 'Vue.js', 'Node.js', 'Flutter',
            'iOS', 'Android', 'DevOps', 'QA', 'UI/UX Design', 'Project Management',
        ];

        foreach ($names as $name) {
            Technology::firstOrCreate(['name' => $name]);
        }
    }
}
