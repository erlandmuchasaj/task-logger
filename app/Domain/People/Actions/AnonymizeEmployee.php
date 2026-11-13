<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * BR-28, D-15, D-46: replaces a former employee's personal data and keeps the business history (ids, logs, leave,
 * salary and performance rows). Runs without model events so the old values are not written to the audit trail
 * again; the audit entries that already hold personal values are scrubbed. Idempotent. A system action: the job
 * authorises it by selecting only completed offboardings past their retention date.
 */
class AnonymizeEmployee
{
    public function handle(User $employee): bool
    {
        if ($employee->anonymized_at !== null) {
            return false;
        }

        $oldEmail = $employee->email;
        $avatar = $employee->avatar_path;

        DB::transaction(function () use ($employee, $oldEmail): void {
            $id = $employee->getKey();
            $profileIds = EmployeeProfile::query()->where('user_id', $id)->pluck('id');
            $contactIds = EmergencyContact::query()->where('user_id', $id)->pluck('id');

            $employee->forceFill([
                'name' => 'Former employee #'.$id,
                'surname' => null,
                'email' => 'anonymized-'.$id.'@invalid.example',
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'avatar_path' => null,
                'app_authentication_secret' => null,
                'app_authentication_recovery_codes' => null,
                'email_verified_at' => null,
                'anonymized_at' => now(),
            ])->saveQuietly();

            DB::table('employee_profiles')->where('user_id', $id)->update([
                'date_of_birth' => null, 'phone' => null, 'gender' => null, 'address' => null, 'university' => null,
                'field_of_study' => null, 'marital_status' => null, 'source_of_hire' => null, 'has_health_insurance' => false,
                'description' => null, 'metadata' => null, 'updated_at' => now(),
            ]);
            DB::table('emergency_contacts')->where('user_id', $id)->delete();
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))->where('email', $oldEmail)->delete();

            // Audit entries about this person hold their old values: keep the entry, drop the personal content.
            DB::table('activity_log')
                ->where(function ($query) use ($id, $profileIds, $contactIds): void {
                    $query->where(fn ($q) => $q->where('subject_type', (new User)->getMorphClass())->where('subject_id', $id))
                        ->orWhere(fn ($q) => $q->where('subject_type', (new EmployeeProfile)->getMorphClass())->whereIn('subject_id', $profileIds))
                        ->orWhere(fn ($q) => $q->where('subject_type', (new EmergencyContact)->getMorphClass())->whereIn('subject_id', $contactIds));
                })
                ->update(['attribute_changes' => null, 'properties' => null]);

            // The IP address and browser of their own actions are personal data too (D-38).
            DB::table('activity_log')
                ->where(fn ($query) => $query->where(fn ($q) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id))->orWhere('impersonator_id', $id))
                ->update(['client_ip' => null, 'user_agent' => null]);

            activity()->performedOn($employee)->event('anonymized')->log('anonymized');
        });

        if ($avatar !== null) {
            Storage::disk('public')->delete($avatar);
        }

        return true;
    }
}
