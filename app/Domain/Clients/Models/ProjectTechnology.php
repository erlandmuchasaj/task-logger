<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\MasterData\Models\Technology;
use App\Domain\Shared\LogsBusinessActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One technology of a project with its estimated hours, e.g. Angular 230h. Row of `project_technology`. */
#[Fillable(['project_id', 'technology_id', 'estimated_hours'])]
#[Table(name: 'project_technology')]
final class ProjectTechnology extends Model
{
    use LogsBusinessActivity;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Technology, $this> */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }

    protected function casts(): array
    {
        return ['estimated_hours' => 'decimal:2'];
    }
}
