<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity;

/**
 * An audit entry. Adds the request context (D-38): `client_ip`, `user_agent` and `impersonator_id`,
 * the real person when someone acted as another user. The causer is then the user acted as.
 *
 * @property string|null $client_ip
 * @property string|null $user_agent
 * @property int|null $impersonator_id
 */
final class ActivityLog extends Activity
{
    /** @return BelongsTo<User, $this> */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id', 'id')
            ->where('causer_type', (new User)->getMorphClass());
    }
}
