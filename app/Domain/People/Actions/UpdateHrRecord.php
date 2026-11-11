<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Data\HrRecordData;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Support\Phones;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** Writes the private HR record and emergency contacts (D-14). Perm employees.update_hr_record. */
class UpdateHrRecord
{
    public function handle(User $actor, User $employee, HrRecordData $data): EmployeeProfile
    {
        Gate::forUser($actor)->authorize('updateHrRecord', $employee);

        return app(CauserResolver::class)->withCauser($actor, fn (): EmployeeProfile => DB::transaction(fn () => $this->persist($employee, $data)));
    }

    /** For callers that already authorised the actor (CreateEmployee). */
    public function persist(User $employee, HrRecordData $data): EmployeeProfile
    {
        $country = $employee->country()->value('code');

        $profile = EmployeeProfile::updateOrCreate(
            ['user_id' => $employee->getKey()],
            [
                'date_of_birth' => $data->dateOfBirth?->toDateString(),
                'phone' => Phones::toE164($data->phone, $country),
                'gender' => $data->gender,
                'address' => $data->address,
                'university' => $data->university,
                'field_of_study' => $data->fieldOfStudy,
                'marital_status' => $data->maritalStatus,
                'source_of_hire' => $data->sourceOfHire,
                'has_health_insurance' => $data->hasHealthInsurance,
                'description' => $data->description,
                'metadata' => $data->metadata === [] ? null : $data->metadata,
            ],
        );

        $this->syncContacts($employee, $data->contacts, $country);

        return $profile;
    }

    /** @param  list<array{id?: int|null, name: string, phone: string, relationship: string}>  $contacts */
    private function syncContacts(User $employee, array $contacts, ?string $country): void
    {
        $existing = $employee->emergencyContacts()->get()->keyBy('id');
        $keep = [];

        foreach ($contacts as $index => $row) {
            $attributes = [
                'name' => $row['name'],
                'phone' => Phones::toE164($row['phone'], $country, "contacts.{$index}.phone"),
                'relationship' => $row['relationship'],
            ];

            $contact = isset($row['id']) ? $existing->get($row['id']) : null;

            if ($contact instanceof EmergencyContact) {
                $contact->update($attributes);
                $keep[] = $contact->getKey();
            } else {
                $keep[] = $employee->emergencyContacts()->create($attributes)->getKey();
            }
        }

        $existing->reject(fn (EmergencyContact $contact): bool => in_array($contact->getKey(), $keep, true))->each->delete();
    }
}
