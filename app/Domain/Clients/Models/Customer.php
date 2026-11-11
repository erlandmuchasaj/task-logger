<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Shared\Priority;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client (table `customers`, UI label Clients, D-23). Priority is per client (D-02). Currency
 * defaults to the country's and is fixed once rates or logs exist (D-18). Payment plans are metadata
 * only (BR-15).
 */
#[Fillable([
    'created_by', 'name', 'surname', 'email', 'country_id', 'phone', 'address', 'company_name',
    'priority', 'payment_plan_id', 'currency_code', 'avatar_path', 'is_active',
])]
#[UseFactory(CustomerFactory::class)]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, LogsBusinessActivity, SoftDeletes;

    protected function casts(): array
    {
        return ['priority' => Priority::class, 'is_active' => 'boolean'];
    }

    /**
     * The currency is fixed once rates exist (D-18). M4 extends this with "or logs exist".
     */
    public function currencyIsLocked(): bool
    {
        return $this->rates()->exists();
    }

    public function displayName(): string
    {
        return $this->company_name ?: trim($this->name.' '.$this->surname);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<PaymentPlan, $this> */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    /** @return HasMany<ClientTechnologyRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ClientTechnologyRate::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
