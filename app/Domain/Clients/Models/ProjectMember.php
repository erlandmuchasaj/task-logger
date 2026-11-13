<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\LogsBusinessActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A project member (D-12): only members may log directly to a project (M4). Row of `project_user`. */
#[Fillable(['project_id', 'user_id'])]
#[Table(name: 'project_user')]
final class ProjectMember extends Model
{
    use LogsBusinessActivity;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
