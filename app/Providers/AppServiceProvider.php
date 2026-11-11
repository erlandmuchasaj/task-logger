<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\ActivityPolicy;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\MasterData\Models\Technology;
use App\Domain\MasterData\Policies\BankHolidayPolicy;
use App\Domain\MasterData\Policies\CountryLeavePolicyPolicy;
use App\Domain\MasterData\Policies\CountryPolicy;
use App\Domain\MasterData\Policies\DepartmentPolicy;
use App\Domain\MasterData\Policies\LabelPolicy;
use App\Domain\MasterData\Policies\LeaveTypePolicy;
use App\Domain\MasterData\Policies\PaymentPlanPolicy;
use App\Domain\MasterData\Policies\TechnologyPolicy;
use App\Domain\People\Models\Salary;
use App\Domain\People\Policies\SalaryPolicy;
use App\Domain\People\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Domain models live outside app/Models, so policies are registered explicitly.
        Gate::policy(Country::class, CountryPolicy::class);
        Gate::policy(CountryLeavePolicy::class, CountryLeavePolicyPolicy::class);
        Gate::policy(LeaveType::class, LeaveTypePolicy::class);
        Gate::policy(BankHoliday::class, BankHolidayPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Technology::class, TechnologyPolicy::class);
        Gate::policy(PaymentPlan::class, PaymentPlanPolicy::class);
        Gate::policy(Label::class, LabelPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Salary::class, SalaryPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);

        // Catch N+1 queries early outside production (NFR-09).
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
