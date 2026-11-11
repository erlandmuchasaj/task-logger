<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Models\User;
use App\Domain\People\Data\EmployeeData;
use App\Domain\People\Data\HrRecordData;
use App\Domain\People\Data\SalaryData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-01 steps 2 to 5. Only the superadmin creates users (employees.create); there is no
 * self-registration. The new user gets a random password they never see and a set-password link.
 */
class CreateEmployee
{
    use ValidatesEmployee;

    public function __construct(
        private readonly AssignUserRole $assignRole,
        private readonly SyncSkills $syncSkills,
        private readonly UpdateHrRecord $updateHrRecord,
        private readonly RecordSalary $recordSalary,
        private readonly SendSetPasswordLink $sendLink,
    ) {}

    public function handle(User $actor, EmployeeData $data, ?HrRecordData $hrRecord = null, ?SalaryData $salary = null): User
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $this->validateEmployee($data, null, creating: true);

        $employee = app(CauserResolver::class)->withCauser($actor, fn (): User => DB::transaction(function () use ($actor, $data, $hrRecord, $salary): User {
            $employee = User::create([
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'password' => Str::random(40),
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

            if ($hrRecord !== null) {
                Gate::forUser($actor)->authorize('updateHrRecord', $employee);
                $this->updateHrRecord->persist($employee, $hrRecord);
            }

            if ($salary !== null) {
                $this->recordSalary->handle($actor, $employee, $salary);
            }

            return $employee;
        }));

        $this->sendLink->handle($employee);

        return $employee;
    }
}
