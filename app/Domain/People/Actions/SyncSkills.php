<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Models\UserSkill;
use Illuminate\Validation\ValidationException;

/** Sets a user's technologies and levels (D-01). Levels run from the configured min to max (1-10). */
final class SyncSkills
{
    /** @param  array<int, int>  $skills  technology_id => level */
    public function handle(User $employee, array $skills): void
    {
        $min = (int) config('task-logger.technology_level_min');
        $max = (int) config('task-logger.technology_level_max');

        foreach ($skills as $level) {
            if ($level < $min || $level > $max) {
                throw ValidationException::withMessages(['skills' => "Skill levels run from {$min} to {$max}."]);
            }
        }

        $known = Technology::query()->whereIn('id', array_keys($skills))->pluck('id')->all();
        if (count($known) !== count($skills)) {
            throw ValidationException::withMessages(['skills' => 'Unknown technology.']);
        }

        $existing = $employee->skills()->get()->keyBy('technology_id');

        foreach ($skills as $technologyId => $level) {
            $row = $existing->get($technologyId);
            $row?->update(['level' => $level]) ?? $employee->skills()->create(['technology_id' => $technologyId, 'level' => $level]);
        }

        // reject() by technology id: Eloquent's except() would filter by the model primary key instead.
        $existing->reject(fn (UserSkill $row): bool => array_key_exists($row->technology_id, $skills))->each->delete();
    }
}
