<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\ProjectData;
use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Models\ProjectMember;

/** Writes a project's technology lines and members, one audited row at a time. */
class SyncProjectParts
{
    public function technologies(Project $project, ProjectData $data): void
    {
        $existing = $project->technologyLines()->get()->keyBy('technology_id');

        foreach ($data->technologies as $technologyId => $hours) {
            $row = $existing->get($technologyId);
            $row === null
                ? $project->technologyLines()->create(['technology_id' => $technologyId, 'estimated_hours' => $hours])
                : $row->update(['estimated_hours' => $hours]);
        }

        $existing->reject(fn ($row): bool => array_key_exists($row->technology_id, $data->technologies))->each->delete();
    }

    /** @param  list<int>  $memberIds */
    public function members(Project $project, array $memberIds): void
    {
        $existing = $project->memberships()->get()->keyBy('user_id');

        foreach ($memberIds as $userId) {
            if (! $existing->has($userId)) {
                $project->memberships()->create(['user_id' => $userId]);
            }
        }

        $existing->reject(fn (ProjectMember $row): bool => in_array($row->user_id, $memberIds, true))->each->delete();
    }
}
