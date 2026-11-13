<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use App\Domain\Shared\LogsBusinessActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of `technology_user`: a technology and the user's level in it, 1 to 10 (D-01). */
#[Fillable(['user_id', 'technology_id', 'level'])]
#[Table(name: 'technology_user')]
final class UserSkill extends Model
{
    use LogsBusinessActivity;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Technology, $this> */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }

    protected function casts(): array
    {
        return ['level' => 'integer'];
    }
}
