<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\EmploymentType;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Models\Salary;
use App\Domain\People\Models\UserSkill;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Work\Models\Log;
use Cog\Contracts\Ban\Bannable as BannableContract;
use Cog\Laravel\Ban\Traits\Bannable;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lab404\Impersonate\Models\Impersonate;
use SensitiveParameter;
use Spatie\Activitylog\Models\Concerns\CausesActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * Salary and leave balance are not columns here (D-03). Exactly one role per user (D-09),
 * assigned through AssignUserRole. Authorisation uses permissions, never role names.
 */
#[Fillable([
    'name', 'surname', 'email', 'password', 'country_id', 'department_id', 'position',
    'manager_id', 'employment_type', 'start_date', 'avatar_path',
])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements BannableContract, FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use Bannable, CausesActivity, HasFactory, HasRoles, Impersonate, LogsBusinessActivity, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'employment_type' => EmploymentType::class,
            'start_date' => 'date',
            'anonymized_at' => 'datetime',
            'banned_at' => 'datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /** The password hash is fillable but never written to the activity log. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['password'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName(static::class);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $enabling = $secret !== null;

        $this->forceFill(['app_authentication_secret' => $secret])->save();

        // Audit the event only; secrets are never logged (they are not in $fillable, so the model
        // trait ignores them).
        activity()->performedOn($this)->event($enabling ? 'two_factor_enabled' : 'two_factor_disabled')
            ->log($enabling ? 'two_factor_enabled' : 'two_factor_disabled');
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        $codes = $this->getAttributeValue('app_authentication_recovery_codes');

        return is_array($codes) ? array_map(strval(...), $codes) : null;
    }

    /** @param  ?array<string>  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->app_authentication_secret);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->isBanned() && $this->anonymized_at === null;
    }

    /** D-47: only holders of employees.impersonate may act as someone else. Read by lab404/laravel-impersonate. */
    public function canImpersonate(): bool
    {
        return $this->hasPermissionTo(Permissions::EMPLOYEES_IMPERSONATE);
    }

    /** D-47: never a deactivated or anonymised account, and never another root user (that would gain nothing and hide who acted). */
    public function canBeImpersonated(): bool
    {
        return ! $this->isBanned() && $this->anonymized_at === null && ! $this->hasPermissionTo(Permissions::SYSTEM_ROOT);
    }

    public function getFilamentName(): string
    {
        return trim($this->name.' '.$this->surname);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /** @return HasMany<User, $this> */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /** @return HasOne<EmployeeProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    /** @return HasMany<EmergencyContact, $this> */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    /** @return HasMany<UserSkill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(UserSkill::class);
    }

    /** @return BelongsToMany<Technology, $this> */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class)->withPivot('level');
    }

    /** @return HasMany<Salary, $this> */
    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class);
    }

    /** @return HasMany<Log, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(Log::class);
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * The salary in force today: the ledger keeps exactly one active row per user (D-19).
     *
     * @return HasOne<Salary, $this>
     */
    public function currentSalary(): HasOne
    {
        return $this->hasOne(Salary::class)->where('status', SalaryStatus::Active);
    }

    /**
     * Get all activity logs for the given user
     *
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'causer_id', 'id')
            ->where('causer_type', (new User)->getMorphClass());
    }
}
