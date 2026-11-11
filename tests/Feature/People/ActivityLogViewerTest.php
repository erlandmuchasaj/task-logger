<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\Technology;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('BR-32 only the superadmin can open the activity log', function (RoleName $role, int $status) {
    $this->actingAs(userWithRole($role))->get('/app/activity-log')->assertStatus($status);
})->group('BR-32')->with([
    [RoleName::Superadmin, 200], [RoleName::Admin, 403], [RoleName::Hr, 403], [RoleName::Assistant, 403], [RoleName::Employee, 403],
]);

it('BR-32 the superadmin sees who changed what, with before and after values', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $this->actingAs($actor);
    $technology = Technology::factory()->create(['name' => 'Ionic']);
    $technology->update(['name' => 'Ionic 8']);
    $activity = Activity::where('event', 'updated')->where('subject_id', $technology->id)->latest('id')->firstOrFail();

    Livewire::test(ListActivityLogs::class)->assertCanSeeTableRecords([$activity]);

    Livewire::test(ViewActivityLog::class, ['record' => $activity->getKey()])
        ->assertSee('Ionic 8')
        ->assertSee('Ionic')
        ->assertSee($actor->getFilamentName());
})->group('BR-32');

it('BR-32 the activity log is read-only', function () {
    $superadmin = userWithRole(RoleName::Superadmin);
    $activity = Activity::query()->firstOrFail();

    expect($superadmin->can('update', $activity))->toBeFalse()
        ->and($superadmin->can('delete', $activity))->toBeFalse()
        ->and($superadmin->can('create', Activity::class))->toBeFalse();
})->group('BR-32');
