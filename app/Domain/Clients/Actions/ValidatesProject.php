<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\ProjectData;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Support\ClientRates;
use App\Domain\Clients\Support\ProjectManagerEligibility;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use Illuminate\Validation\ValidationException;

trait ValidatesProject
{
    /** @throws ValidationException */
    private function validateProject(ProjectData $data, Customer $customer, ?Project $existing): void
    {
        $errors = [];

        // BR-31: a deactivated client gets no new projects. Existing projects keep working.
        if (! $customer->is_active && $existing === null) {
            $errors['customer_id'] = 'This client is deactivated and cannot receive new projects.';
        }

        // BR-30: the manager must hold the admin role.
        if (! ProjectManagerEligibility::isEligible(User::query()->find($data->managerId))) {
            $errors['manager_id'] = 'The project manager must be an active user with the admin role.';
        }

        if ($data->endDate !== null && $data->endDate->lessThan($data->startDate)) {
            $errors['end_date'] = 'The end date cannot be before the start date.';
        }

        // BR-10: only the client's technologies can be chosen, each with positive estimated hours.
        $clientTechnologies = ClientRates::technologyIds($customer->getKey());
        foreach ($data->technologies as $technologyId => $hours) {
            if (! in_array($technologyId, $clientTechnologies, true)) {
                $errors['technologies'] = 'Only technologies with a rate for this client can be added to the project.';
                break;
            }

            if (! preg_match('/^\d{1,6}(\.\d{1,2})?$/', (string) $hours) || (float) $hours <= 0) {
                $errors['technologies'] = 'Estimated hours must be a positive number with at most 2 decimals.';
                break;
            }
        }

        if (array_diff($data->memberIds, User::query()->whereIn('id', $data->memberIds)->pluck('id')->all()) !== []) {
            $errors['members'] = 'Unknown project member.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A technology still used by a task cannot be removed from its project (BR-10).
     *
     * @param  list<int>  $keepTechnologyIds
     */
    private function assertTechnologiesNotInUse(Project $project, array $keepTechnologyIds): void
    {
        $used = $project->tasks()->withTrashed()->whereNotIn('technology_id', $keepTechnologyIds)->distinct()->pluck('technology_id');

        if ($used->isNotEmpty()) {
            $names = Technology::query()->whereIn('id', $used)->pluck('name')->implode(', ');

            throw ValidationException::withMessages(['technologies' => "Tasks still use: {$names}. Move or delete those tasks first."]);
        }
    }
}
