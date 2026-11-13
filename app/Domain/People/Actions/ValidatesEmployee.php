<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Data\EmployeeData;
use App\Domain\People\Support\ManagerChain;
use Illuminate\Validation\ValidationException;

trait ValidatesEmployee
{
    private function validateEmployee(EmployeeData $data, ?User $existing, bool $creating): void
    {
        $errors = [];

        $emailTaken = User::query()->withTrashed()->where('email', $data->email)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->getKey()))->exists();

        if (! filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($emailTaken) {
            $errors['email'] = 'This email is already in use.';
        }

        if ($creating) {
            foreach (['countryId' => 'country', 'departmentId' => 'department', 'employmentType' => 'employment_type', 'startDate' => 'start_date', 'position' => 'position'] as $property => $field) {
                if (blank($data->{$property})) {
                    $errors[$field] = 'This field is required.';
                }
            }
        }

        if (ManagerChain::wouldCycle($existing?->getKey(), $data->managerId)) {
            $errors['manager_id'] = 'A person cannot manage themselves or their own manager.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
