<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\GrantAnnualLeave;
use App\Domain\People\Data\EmployeeData;
use App\Domain\People\Data\HrRecordData;
use App\Domain\People\Data\SalaryData;
use App\Domain\People\Notifications\WelcomeEmployee;
use App\Domain\People\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-01 steps 2 to 5. Only the superadmin creates users (employees.create); there is no
 * self-registration. The new user gets a temporary password in a welcome email and is asked to change
 * it after the first sign-in (D-53, replacing the set-password link the account used to receive).
 */
final readonly class CreateEmployee
{
    use ValidatesEmployee;

    public function __construct(
        private AssignUserRole $assignRole,
        private SyncSkills $syncSkills,
        private UpdateHrRecord $updateHrRecord,
        private RecordSalary $recordSalary,
    ) {}

    public function handle(User $actor, EmployeeData $data, ?HrRecordData $hrRecord = null, ?SalaryData $salary = null): User
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $this->validateEmployee($data, null, creating: true);

        $password = TemporaryPassword::generate();

        $employee = resolve(CauserResolver::class)->withCauser($actor, fn (): User => DB::transaction(function () use ($actor, $data, $hrRecord, $salary, $password): User {
            $employee = User::query()->create([
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'password' => $password,
                'country_id' => $data->countryId,
                'department_id' => $data->departmentId,
                'position' => $data->position,
                'manager_id' => $data->managerId,
                'employment_type' => $data->employmentType,
                'start_date' => $data->startDate?->toDateString(),
                'avatar_path' => $data->avatarPath,
            ]);

            // D-53: the emailed password is temporary; the first sign-in asks for a new one.
            $employee->forceFill(['must_change_password' => true])->saveQuietly();

            $this->assignRole->handle($employee, $data->role);
            $this->syncSkills->handle($employee, $data->skills);

            if ($hrRecord instanceof HrRecordData) {
                Gate::forUser($actor)->authorize('updateHrRecord', $employee);
                $this->updateHrRecord->persist($employee, $hrRecord);
            }

            if ($salary instanceof SalaryData) {
                $this->recordSalary->handle($actor, $employee, $salary);
            }

            return $employee;
        }));

        // W-01 step 4: the grant for the year of joining (due now when the start date has passed, else the job writes it).
        resolve(GrantAnnualLeave::class)->grantFor($employee->load('country'));

        $employee->notify(new WelcomeEmployee($password));

        return $employee;
    }
}
