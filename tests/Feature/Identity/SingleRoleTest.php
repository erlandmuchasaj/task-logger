<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use Spatie\Activitylog\Models\Activity;

it('D-09 a user holds exactly one role, assigning another replaces it', function (): void {
    $user = userWithRole(RoleName::Employee);

    resolve(AssignUserRole::class)->handle($user, RoleName::Admin);

    expect($user->roles()->pluck('name')->all())->toBe(['admin']);
});

it('BR-32 audits a role change with old and new values', function (): void {
    $user = userWithRole(RoleName::Employee);
    Activity::query()->delete();

    resolve(AssignUserRole::class)->handle($user, RoleName::Hr);

    $activity = Activity::query()->where('event', 'role_changed')->sole();
    expect($activity->subject_id)->toBe($user->id)
        ->and($activity->attribute_changes['old']['roles'])->toBe(['employee'])
        ->and($activity->attribute_changes['attributes']['roles'])->toBe(['hr']);
})->group('BR-32');

it('assigning the same role again is a no-op', function (): void {
    $user = userWithRole(RoleName::Hr);
    Activity::query()->delete();

    resolve(AssignUserRole::class)->handle($user, RoleName::Hr);

    expect(Activity::query()->count())->toBe(0)->and($user->roles()->count())->toBe(1);
});
