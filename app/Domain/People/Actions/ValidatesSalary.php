<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\People\Data\SalaryData;
use Illuminate\Validation\ValidationException;

trait ValidatesSalary
{
    /** @throws ValidationException */
    private function validateSalary(SalaryData $data): void
    {
        if ($data->amount->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The salary must be greater than zero.']);
        }
    }
}
