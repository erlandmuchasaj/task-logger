<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Enums\ProjectType;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Shared\Money;
use App\Domain\Work\Models\Task;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * No priority (D-02). `estimated_hours` and `budget_minor` are computed snapshots (D-04), written only
 * by ComputeProjectBudget. The manager must hold the admin role (BR-30).
 */
#[Fillable([
    'created_by', 'customer_id', 'project_type', 'name', 'description', 'manager_id', 'start_date',
    'end_date', 'can_log_directly_to_project', 'status', 'color',
])]
#[UseFactory(ProjectFactory::class)]
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasSlug;
    use LogsBusinessActivity;
    use SoftDeletes;

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug')->doNotGenerateSlugsOnUpdate();
    }

    public function budget(): Money
    {
        return Money::of($this->budget_minor, $this->customer->currency_code);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProjectTechnology, $this> */
    public function technologyLines(): HasMany
    {
        return $this->hasMany(ProjectTechnology::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user')->withTimestamps();
    }

    /** @return HasMany<ProjectMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<FixedCost, $this> */
    public function fixedCosts(): HasMany
    {
        return $this->hasMany(FixedCost::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'can_log_directly_to_project' => 'boolean',
            'estimated_hours' => 'decimal:2',
            'budget_minor' => 'integer',
        ];
    }
}
