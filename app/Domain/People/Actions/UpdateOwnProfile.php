<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Data\OwnProfileData;
use App\Domain\People\Support\Phones;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Lab404\Impersonate\Services\ImpersonateManager;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * D-77: a person changes their own name, email, phone, date of birth, university, address, gender and marital
 * status. Nobody can use it for someone else, the superadmin included, and not while another person is acting as
 * this one (D-47). A new email needs the current password. Role, manager, department, salary and every other HR
 * field are not reachable from here; the profile row is touched only for the fields above.
 */
final class UpdateOwnProfile
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, OwnProfileData $data): User
    {
        Gate::forUser($actor)->authorize('updateOwnProfile', $actor);

        throw_if(resolve(ImpersonateManager::class)->isImpersonating(), AuthorizationException::class, 'Nobody can change this profile while acting as this person.');

        $name = mb_trim($data->name);
        $surname = $data->surname === null || mb_trim($data->surname) === '' ? null : mb_trim($data->surname);
        $email = mb_trim($data->email);

        $this->validate($actor, $name, $email, $data);

        $phone = Phones::forProfile($actor, $data->phone, $actor->country()->value('code'));

        return resolve(CauserResolver::class)->withCauser($actor, fn (): User => DB::transaction(function () use ($actor, $name, $surname, $email, $phone, $data): User {
            $actor->update(['name' => $name, 'surname' => $surname, 'email' => $email]);

            $actor->profile()->updateOrCreate([], [
                'date_of_birth' => $data->dateOfBirth?->toDateString(),
                'phone' => $phone,
                'university' => $this->blankToNull($data->university),
                'address' => $this->blankToNull($data->address),
                'gender' => $data->gender,
                'marital_status' => $data->maritalStatus,
            ]);

            return $actor;
        }));
    }

    /** @throws ValidationException */
    private function validate(User $actor, string $name, string $email, OwnProfileData $data): void
    {
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter your name.']);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }

        if ($email !== $actor->email) {
            if (User::query()->where('email', $email)->whereKeyNot($actor->getKey())->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
            }

            if (! Hash::check((string) $data->currentPassword, $actor->password)) {
                throw ValidationException::withMessages(['currentPassword' => 'Enter your current password to change your email.']);
            }
        }

        if ($data->dateOfBirth instanceof CarbonInterface && $data->dateOfBirth->isFuture()) {
            throw ValidationException::withMessages(['date_of_birth' => 'The date of birth cannot be in the future.']);
        }
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : mb_trim($value);

        return $value === '' ? null : $value;
    }
}
