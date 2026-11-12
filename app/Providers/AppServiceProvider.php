<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\FixedCost;
use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Policies\ClientTechnologyRatePolicy;
use App\Domain\Clients\Policies\CustomerPolicy;
use App\Domain\Clients\Policies\FixedCostPolicy;
use App\Domain\Clients\Policies\ProjectPolicy;
use App\Domain\Clients\Support\ProjectManagerRoleGuard;
use App\Domain\Exports\Models\Export;
use App\Domain\Exports\Policies\ExportPolicy;
use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\ActivityPolicy;
use App\Domain\Identity\Support\ActivityContext;
use App\Domain\Identity\Support\RootAccess;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Policies\LeaveRequestPolicy;
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
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Models\Performance;
use App\Domain\People\Models\Salary;
use App\Domain\People\Policies\OffboardingPolicy;
use App\Domain\People\Policies\PerformancePolicy;
use App\Domain\People\Policies\SalaryPolicy;
use App\Domain\People\Policies\UserPolicy;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Models\TimesheetLock;
use App\Domain\Work\Policies\LogPolicy;
use App\Domain\Work\Policies\TaskPolicy;
use App\Domain\Work\Policies\TimesheetLockPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
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
        // BR-30: other modules may veto a role change (e.g. a project manager must stay an admin).
        $this->app->tag([ProjectManagerRoleGuard::class], 'role-change-guards');
        // One context per request: it caches the impersonator lookup for the audit trail (D-38).
        $this->app->scoped(ActivityContext::class);

        $this->app->when(AssignUserRole::class)->needs('$guards')->giveTagged('role-change-guards');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // D-41: root access for whoever holds system.root (the superadmin), except abilities that stay under their policy.
        Gate::before(fn (Authenticatable $user, string $ability, array $arguments = []): ?bool => RootAccess::check($user, $ability, $arguments));

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
        Gate::policy(ActivityLog::class, ActivityPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(ClientTechnologyRate::class, ClientTechnologyRatePolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(FixedCost::class, FixedCostPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Log::class, LogPolicy::class);
        Gate::policy(Export::class, ExportPolicy::class);
        Gate::policy(TimesheetLock::class, TimesheetLockPolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(Performance::class, PerformancePolicy::class);
        Gate::policy(Offboarding::class, OffboardingPolicy::class);

        // Catch N+1 queries early outside production (NFR-09).
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
