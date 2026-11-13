<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Support;

use App\Domain\Identity\Enums\EmploymentType;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\People\Data\EmployeeData;
use App\Domain\People\Data\HrRecordData;
use App\Domain\People\Data\SalaryData;
use App\Domain\People\Enums\Gender;
use App\Domain\People\Enums\MaritalStatus;
use App\Domain\Shared\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

/** Converts Filament form state to People DTOs and back, so the actions stay UI-free. */
final class FormMapper
{
    /**
     * Form state for an existing employee. Private values are only loaded when the viewer may see them.
     *
     * @return array<string, mixed>
     */
    public static function fill(User $employee, bool $withHrRecord): array
    {
        $employee->loadMissing(['roles', 'skills']);

        $state = [
            ...$employee->only(['name', 'surname', 'email', 'country_id', 'department_id', 'position', 'manager_id', 'avatar_path']),
            'role' => $employee->getRoleNames()->first(),
            'employment_type' => $employee->employment_type?->value,
            'start_date' => $employee->start_date?->toDateString(),
            'skills' => $employee->skills->map(fn ($s): array => ['technology_id' => $s->technology_id, 'level' => $s->level])->all(),
        ];

        if ($withHrRecord) {
            $profile = $employee->profile;
            $state['profile'] = [
                'date_of_birth' => $profile?->date_of_birth?->toDateString(),
                'phone' => $profile?->phone,
                'gender' => $profile?->gender?->value,
                'address' => $profile?->address,
                'university' => $profile?->university,
                'field_of_study' => $profile?->field_of_study,
                'marital_status' => $profile?->marital_status?->value,
                'source_of_hire' => $profile?->source_of_hire,
                'has_health_insurance' => $profile->has_health_insurance ?? false,
                'description' => $profile?->description,
                'metadata' => $profile->metadata ?? [],
            ];
            $state['contacts'] = $employee->emergencyContacts()->get()
                ->map(fn ($c): array => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'relationship' => $c->relationship])->all();
        }

        return $state;
    }

    /** @param  array<string, mixed>  $data */
    public static function employee(array $data): EmployeeData
    {
        $skills = [];
        foreach ((array) ($data['skills'] ?? []) as $row) {
            if (filled($row['technology_id'] ?? null)) {
                $skills[(int) $row['technology_id']] = (int) $row['level'];
            }
        }

        return new EmployeeData(
            name: (string) $data['name'],
            surname: $data['surname'] ?? null,
            email: (string) $data['email'],
            role: RoleName::from(self::value($data['role'])),
            countryId: filled($data['country_id'] ?? null) ? (int) $data['country_id'] : null,
            departmentId: filled($data['department_id'] ?? null) ? (int) $data['department_id'] : null,
            position: $data['position'] ?? null,
            managerId: filled($data['manager_id'] ?? null) ? (int) $data['manager_id'] : null,
            employmentType: filled($data['employment_type'] ?? null) ? EmploymentType::from(self::value($data['employment_type'])) : null,
            startDate: filled($data['start_date'] ?? null) ? CarbonImmutable::parse((string) $data['start_date']) : null,
            avatarPath: $data['avatar_path'] ?? null,
            skills: $skills,
        );
    }

    /** @param  array<string, mixed>  $data */
    public static function hrRecord(array $data): HrRecordData
    {
        $p = (array) ($data['profile'] ?? []);

        $contacts = [];
        foreach ((array) ($data['contacts'] ?? []) as $row) {
            $contacts[] = [
                'id' => $row['id'] ?? null,
                'name' => (string) $row['name'],
                'phone' => (string) $row['phone'],
                'relationship' => (string) $row['relationship'],
            ];
        }

        return new HrRecordData(
            dateOfBirth: filled($p['date_of_birth'] ?? null) ? CarbonImmutable::parse((string) $p['date_of_birth']) : null,
            phone: $p['phone'] ?? null,
            gender: filled($p['gender'] ?? null) ? Gender::from(self::value($p['gender'])) : null,
            address: $p['address'] ?? null,
            university: $p['university'] ?? null,
            fieldOfStudy: $p['field_of_study'] ?? null,
            maritalStatus: filled($p['marital_status'] ?? null) ? MaritalStatus::from(self::value($p['marital_status'])) : null,
            sourceOfHire: $p['source_of_hire'] ?? null,
            hasHealthInsurance: (bool) ($p['has_health_insurance'] ?? false),
            description: $p['description'] ?? null,
            metadata: array_map(strval(...), (array) ($p['metadata'] ?? [])),
            contacts: $contacts,
        );
    }

    /** @param  array<string, mixed>  $data  keys: amount, currency, effective_date, description */
    public static function salary(array $data): ?SalaryData
    {
        if (blank($data['amount'] ?? null)) {
            return null;
        }

        try {
            $amount = Money::fromMajor((string) $data['amount'], (string) ($data['currency'] ?? ''));
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw ValidationException::withMessages(['amount' => $invalidArgumentException->getMessage()]);
        }

        return new SalaryData($amount, CarbonImmutable::parse((string) $data['effective_date']), $data['description'] ?? null);
    }

    public static function countryCode(mixed $countryId): ?string
    {
        return filled($countryId) ? Country::query()->whereKey($countryId)->value('code') : null;
    }

    public static function currencyOf(mixed $countryId): ?string
    {
        return filled($countryId) ? Country::query()->whereKey($countryId)->value('currency_code') : null;
    }

    /**
     * Shows an action's validation errors on the right form fields; Filament only displays errors
     * keyed under the form's state path.
     *
     * @param  string  $prefix  state path of the salary fields when it is a nested form, else ''
     */
    public static function report(Component $page, ValidationException $e, string $prefix = 'data.'): void
    {
        foreach ($e->errors() as $key => $messages) {
            $path = match (true) {
                $key === 'phone' => $prefix.'profile.phone',
                $key === 'amount' || $key === 'effective_date' => $prefix.$key,
                default => $prefix.$key,
            };

            foreach ($messages as $message) {
                $page->addError($path, $message);
            }
        }

        Notification::make()->title('Could not save')->body(collect($e->errors())->flatten()->first())->danger()->send();
    }

    /** Filament gives enum-backed selects back as enum cases or as their raw value; accept both. */
    private static function value(mixed $state): string
    {
        return $state instanceof BackedEnum ? (string) $state->value : (string) $state;
    }
}
