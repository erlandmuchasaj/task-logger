<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\StartImpersonation;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\People\Actions\UpdateHrRecord;
use App\Domain\People\Actions\UpdateOwnProfile;
use App\Domain\People\Data\HrRecordData;
use App\Domain\People\Data\OwnProfileData;
use App\Domain\People\Enums\Gender;
use App\Domain\People\Enums\MaritalStatus;
use App\Domain\People\Models\EmployeeProfile;
use App\Filament\Pages\Auth\EditProfile;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** An employee in Albania, whose password is "password" (the factory default). */
function profileOwner(RoleName $role = RoleName::Employee): User
{
    $country = Country::query()->where('code', 'AL')->first() ?? Country::factory()->albania()->create();
    $user = userWithRole($role);
    $user->update(['country_id' => $country->id, 'name' => 'Original', 'surname' => 'Person', 'email' => 'original@example.test']);

    return $user->refresh();
}

function ownProfile(array $overrides = []): OwnProfileData
{
    return new OwnProfileData(...array_merge([
        'name' => 'Original',
        'surname' => 'Person',
        'email' => 'original@example.test',
    ], $overrides));
}

it('D-77 a person changes their own details and nothing else on the profile', function (RoleName $role): void {
    $user = profileOwner($role);
    resolve(UpdateHrRecord::class)->handle(userWithRole(RoleName::Superadmin), $user, new HrRecordData(fieldOfStudy: 'Physics', sourceOfHire: 'Referral', hasHealthInsurance: true));
    $manager = $user->manager_id;

    resolve(UpdateOwnProfile::class)->handle($user, ownProfile([
        'name' => ' Renamed ',
        'surname' => '',
        'phone' => '069 212 3456',
        'dateOfBirth' => CarbonImmutable::parse('1990-04-02'),
        'university' => 'Polis',
        'address' => 'Rruga 1, Tirana',
        'gender' => Gender::Female,
        'maritalStatus' => MaritalStatus::Married,
    ]));

    $fresh = $user->fresh();
    $profile = EmployeeProfile::query()->where('user_id', $user->id)->sole();

    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->surname)->toBeNull()
        ->and($fresh->manager_id)->toBe($manager)
        ->and($profile->phone)->toBe('+355692123456')
        ->and($profile->date_of_birth->toDateString())->toBe('1990-04-02')
        ->and($profile->university)->toBe('Polis')
        ->and($profile->gender)->toBe(Gender::Female)
        ->and($profile->marital_status)->toBe(MaritalStatus::Married)
        // The fields this action does not own are left as HR set them.
        ->and($profile->field_of_study)->toBe('Physics')
        ->and($profile->source_of_hire)->toBe('Referral')
        ->and($profile->has_health_insurance)->toBeTrue();
    expect(Activity::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->latest('id')->first()->causer_id)->toBe($user->id);
})->with([RoleName::Employee, RoleName::Assistant, RoleName::Hr, RoleName::Finance, RoleName::Admin, RoleName::Superadmin])->group('D-77');

it('D-77 nobody edits another person through it, the superadmin included', function (): void {
    $victim = profileOwner();

    $policy = Gate::forUser(userWithRole(RoleName::Superadmin));

    expect($policy->allows('updateOwnProfile', $victim))->toBeFalse()
        ->and(Gate::forUser(userWithRole(RoleName::Hr))->allows('updateOwnProfile', $victim))->toBeFalse()
        ->and(Gate::forUser($victim)->allows('updateOwnProfile', $victim))->toBeTrue();
})->group('D-77');

it('D-77 a person without the permission is refused', function (): void {
    $user = profileOwner();
    $user->roles()->detach();
    $user->forgetCachedPermissions();

    expect(fn () => resolve(UpdateOwnProfile::class)->handle($user->fresh(), ownProfile(['name' => 'Sneaky'])))->toThrow(AuthorizationException::class);
    expect($user->fresh()->name)->toBe('Original');
})->group('D-77');

it('D-77 D-47 nothing changes while someone is acting as this person', function (): void {
    $root = userWithRole(RoleName::Superadmin);
    $user = profileOwner();
    resolve(StartImpersonation::class)->handle($root, $user);

    expect(fn () => resolve(UpdateOwnProfile::class)->handle($user, ownProfile(['name' => 'Changed by an impersonator'])))->toThrow(AuthorizationException::class);
    expect($user->fresh()->name)->toBe('Original');
})->group('D-77', 'D-47');

it('D-77 a changed email needs the current password and must be new', function (): void {
    $user = profileOwner();
    $taken = userWithRole(RoleName::Employee);
    $action = resolve(UpdateOwnProfile::class);

    expect(fn () => $action->handle($user, ownProfile(['email' => 'new@example.test'])))->toThrow(ValidationException::class);
    expect(fn () => $action->handle($user, ownProfile(['email' => 'new@example.test', 'currentPassword' => 'wrong-password'])))->toThrow(ValidationException::class);
    expect(fn () => $action->handle($user, ownProfile(['email' => $taken->email, 'currentPassword' => 'password'])))->toThrow(ValidationException::class);
    expect($user->fresh()->email)->toBe('original@example.test');

    $action->handle($user, ownProfile(['email' => 'new@example.test', 'currentPassword' => 'password']));
    expect($user->fresh()->email)->toBe('new@example.test');

    // An unchanged email needs no password.
    $action->handle($user->fresh(), ownProfile(['email' => 'new@example.test', 'name' => 'Again']));
    expect($user->fresh()->name)->toBe('Again');
})->group('D-77');

it("D-77 D-60 the phone must be a mobile of the person's country and the date of birth cannot be in the future", function (): void {
    $user = profileOwner();
    $action = resolve(UpdateOwnProfile::class);

    expect(fn () => $action->handle($user, ownProfile(['phone' => '04 222 3456'])))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($user, ownProfile(['phone' => '+44 7911 123456'])))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($user, ownProfile(['dateOfBirth' => CarbonImmutable::now()->addDay()])))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($user, ownProfile(['name' => ' '])))->toThrow(ValidationException::class);
    expect(EmployeeProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->group('D-77', 'D-60');

it('D-77 the profile page saves the details through the action and keeps role and manager read-only', function (): void {
    Filament::setCurrentPanel('app');
    $user = profileOwner();
    $before = [$user->manager_id, $user->position, $user->employment_type];
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->assertSee('Back to dashboard')
        ->assertFormSet(['name' => 'Original', 'email' => 'original@example.test'])
        ->fillForm(['name' => 'Renamed', 'phone' => '069 212 3456', 'university' => 'Polis', 'gender' => Gender::Male->value, 'role_label' => 'Superadmin', 'position' => 'CEO'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->profile->phone)->toBe('+355692123456')
        ->and($fresh->profile->gender)->toBe(Gender::Male)
        ->and([$fresh->manager_id, $fresh->position, $fresh->employment_type])->toBe($before)
        ->and($fresh->hasRole(RoleName::Employee->value))->toBeTrue();
})->group('D-77');

it('D-77 the profile page shows a rule the action refuses on the field', function (): void {
    Filament::setCurrentPanel('app');
    $user = profileOwner();
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['phone' => '04 222 3456'])
        ->call('save')
        ->assertHasErrors(['data.phone']);

    expect($user->fresh()->profile)->toBeNull();
})->group('D-77');

it('D-77 the profile page shows a stored phone without the dial prefix the field already has', function (): void {
    Filament::setCurrentPanel('app');
    $user = profileOwner();
    resolve(UpdateOwnProfile::class)->handle($user, ownProfile(['phone' => '069 212 3456']));
    $this->actingAs($user->fresh());

    Livewire::test(EditProfile::class)->assertFormSet(['phone' => '692123456']);
})->group('D-77', 'D-60');

it('D-90 a person sets, keeps and removes their own photo, and the top bar uses it', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('avatars/me.png', 'png');
    $user = profileOwner();

    expect($user->getFilamentAvatarUrl())->toBeNull();

    resolve(UpdateOwnProfile::class)->handle($user, ownProfile(['avatarPath' => 'avatars/me.png']));
    $user = $user->fresh();

    expect($user->avatar_path)->toBe('avatars/me.png')
        ->and($user->getFilamentAvatarUrl())->toBe(Storage::disk('public')->url('avatars/me.png'));

    resolve(UpdateOwnProfile::class)->handle($user, ownProfile(['avatarPath' => 'avatars/me.png', 'name' => 'Renamed']));
    expect($user->fresh()->avatar_path)->toBe('avatars/me.png');

    resolve(UpdateOwnProfile::class)->handle($user->fresh(), ownProfile());
    expect($user->fresh()->avatar_path)->toBeNull();
})->group('D-90');

it('D-90 an employee cannot take a file that is not an uploaded avatar as their photo', function (string $path): void {
    Storage::fake('public');
    Storage::disk('public')->put('customers/logo.png', 'png');
    $user = profileOwner();

    expect(fn () => resolve(UpdateOwnProfile::class)->handle($user, ownProfile(['avatarPath' => $path])))
        ->toThrow(ValidationException::class);

    expect($user->fresh()->avatar_path)->toBeNull();
})->with([
    'another folder' => 'customers/logo.png',
    'a missing file' => 'avatars/missing.png',
    'a path that climbs out' => 'avatars/../customers/logo.png',
])->group('D-90');

it('D-90 the profile page saves the photo through the action', function (): void {
    Storage::fake('public');
    Filament::setCurrentPanel('app');
    $user = profileOwner();
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->assertSee('Your role')
        ->fillForm(['avatar_path' => [UploadedFile::fake()->image('me.png')]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->avatar_path)->toStartWith('avatars/');
    Storage::disk('public')->assertExists($user->fresh()->avatar_path);
})->group('D-90');
