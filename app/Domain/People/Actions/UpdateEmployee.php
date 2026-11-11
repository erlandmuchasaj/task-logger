<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\People\Data\EmployeeData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Updates account-level data, role and skills. Perm employees.update (superadmin). */
class UpdateEmployee
{
    use ValidatesEmployee;

    public function __construct(
        private readonly AssignUserRole $assignRole,
        private readonly SyncSkills $syncSkills,
    ) {}

    public function handle(User $actor, User $employee, EmployeeData $data): User
    {
        Gate::forUser($actor)->authorize('updateAccount', $employee);
        $this->validateEmployee($data, $employee, creating: false);
        $this->guardLastSuperadmin($employee, $data->role);

        return app(CauserResolver::class)->withCauser($actor, fn (): User => DB::transaction(function () use ($employee, $data): User {
            $employee->update([
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'country_id' => $data->countryId,
                'department_id' => $data->departmentId,
                'position' => $data->position,
                'manager_id' => $data->managerId,
                'employment_type' => $data->employmentType,
                'start_date' => $data->startDate?->toDateString(),
                'avatar_path' => $data->avatarPath,
            ]);

            $this->assignRole->handle($employee, $data->role);
            $this->syncSkills->handle($employee, $data->skills);

            return $employee->refresh();
        }));
    }

    /** [DERIVED] The last superadmin cannot be demoted: nobody could manage the system afterwards. */
    private function guardLastSuperadmin(User $employee, RoleName $newRole): void
    {
        $isSuperadmin = $employee->roles()->where('name', RoleName::Superadmin->value)->exists();

        if (! $isSuperadmin || $newRole === RoleName::Superadmin) {
            return;
        }

        $others = User::role(RoleName::Superadmin->value)->whereKeyNot($employee->getKey())->whereNull('banned_at')->exists();

        if (! $others) {
            throw ValidationException::withMessages(['role' => 'The last superadmin cannot be demoted.']);
        }
    }
}
