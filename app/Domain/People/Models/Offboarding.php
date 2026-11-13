<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\Shared\LogsBusinessActivity;
use Database\Factories\OffboardingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** W-10. Completing it deactivates the login (ADR-0008) and starts the anonymisation clock (BR-28). */
#[Fillable(['user_id', 'created_by', 'end_date', 'equipment_returned', 'resignation_letter_signed', 'description', 'status'])]
#[UseFactory(OffboardingFactory::class)]
class Offboarding extends Model
{
    /** @use HasFactory<OffboardingFactory> */
    use HasFactory, LogsBusinessActivity;

    protected function casts(): array
    {
        return [
            'end_date' => 'date',
            'equipment_returned' => 'boolean',
            'resignation_letter_signed' => 'boolean',
            'status' => OffboardingStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
