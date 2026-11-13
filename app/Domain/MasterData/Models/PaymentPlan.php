<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\MasterData\Enums\PaymentPlanType;
use App\Domain\Shared\LogsBusinessActivity;
use Database\Factories\PaymentPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Client metadata only; no invoice is generated (BR-15). */
#[Fillable(['name', 'type', 'days', 'description'])]
#[UseFactory(PaymentPlanFactory::class)]
class PaymentPlan extends Model
{
    /** @use HasFactory<PaymentPlanFactory> */
    use HasFactory, LogsBusinessActivity;

    protected function casts(): array
    {
        return ['type' => PaymentPlanType::class, 'days' => 'integer'];
    }
}
