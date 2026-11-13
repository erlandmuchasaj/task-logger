<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AssignUserRole;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\Technology;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Filament\Resources\Labels\Pages\ManageLabels;
use Filament\Actions\CreateAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Support\ActivityBuffer;

it('BR-32 D-38 an audited write records the client IP and user agent', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $this->actingAs($actor);
    asRequest();

    Technology::factory()->create(['name' => 'Ionic']);

    $entry = latestActivity();
    expect($entry->client_ip)->toBe('203.0.113.7')
        ->and($entry->user_agent)->toBe('Mozilla/5.0 (X11) TestBrowser/1.0')
        ->and($entry->causer_id)->toBe($actor->id)
        ->and($entry->impersonator_id)->toBeNull();
})->group('BR-32');

it('BR-32 D-38 when an admin acts as another user, the entry names both', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $employee = userWithRole(RoleName::Employee);
    $this->actingAs($employee);          // the user being acted as
    asRequest(impersonatedBy: $admin->id); // the person really acting

    $technology = Technology::factory()->create(['name' => 'Angular']);
    $technology->update(['name' => 'Angular 19']);

    foreach (['created', 'updated'] as $event) {
        $entry = ActivityLog::query()->where('event', $event)->where('subject_type', $technology->getMorphClass())->where('subject_id', $technology->id)->sole();
        expect($entry->causer_id)->toBe($employee->id)
            ->and($entry->impersonator_id)->toBe($admin->id)
            ->and($entry->impersonator->is($admin))->toBeTrue();
    }
})->group('BR-32');

it('BR-32 D-38 the same holds for entries written by hand and for role changes', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $employee = userWithRole(RoleName::Employee);
    $this->actingAs($employee);
    asRequest(impersonatedBy: $admin->id);

    activity()->performedOn($employee)->event('custom')->log('custom');
    resolve(AssignUserRole::class)->handle($employee, RoleName::Hr);

    foreach (['custom', 'role_changed'] as $event) {
        expect(latestActivity($event))->impersonator_id->toBe($admin->id)->client_ip->toBe('203.0.113.7');
    }
})->group('BR-32');

it('BR-32 D-38 a change made through the panel by an impersonating admin is attributed to both', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $superadmin = userWithRole(RoleName::Superadmin);
    $this->actingAs($superadmin)->withSession([config('task-logger.impersonation.session_key') => $admin->id]);

    Livewire::test(ManageLabels::class)->callAction(CreateAction::class, ['name' => 'Impersonated label'])->assertHasNoFormErrors();

    $entry = ActivityLog::query()->where('event', 'created')->where('subject_id', Label::query()->where('name', 'Impersonated label')->value('id'))->sole();
    expect($entry->causer_id)->toBe($superadmin->id)->and($entry->impersonator_id)->toBe($admin->id);
})->group('BR-32');

it('D-38 nobody impersonating means no impersonator', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    asRequest();

    Label::factory()->create();

    expect(latestActivity()->impersonator_id)->toBeNull();
});

it('D-38 a stale or forged impersonator id never breaks the audited write', function (mixed $forged): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    asRequest(impersonatedBy: $forged);

    $label = Label::factory()->create();

    expect($label->exists)->toBeTrue()
        ->and(latestActivity()->impersonator_id)->toBeNull();
})->with(['unknown user' => [999999], 'text' => ['abc'], 'array-like' => ['1; drop table users'], 'negative' => [-4]]);

it('D-38 an entry with no request context (console or queue) has no IP or user agent', function (): void {
    asRequest(ip: '', agent: '');
    $this->actingAs(userWithRole(RoleName::Superadmin));

    Label::factory()->create();

    expect(latestActivity())->client_ip->toBeNull()->user_agent->toBeNull();
});

it('D-38 a very long user agent is truncated, not rejected', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    asRequest(agent: str_repeat('A', 5000));

    Label::factory()->create();

    expect(mb_strlen(latestActivity()->user_agent))->toBe(1000);
});

it('D-38 IPv6 addresses are stored', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    asRequest(ip: '2001:db8::1');

    Label::factory()->create();

    expect(latestActivity()->client_ip)->toBe('2001:db8::1');
});

it('D-38 stamping also works when activity buffering is on', function (): void {
    config(['activitylog.buffer.enabled' => true]);
    $this->actingAs(userWithRole(RoleName::Superadmin));
    asRequest(impersonatedBy: userWithRole(RoleName::Admin)->id);

    Label::factory()->create(['name' => 'Buffered']);
    resolve(ActivityBuffer::class)->flush();

    $entry = ActivityLog::query()->where('event', 'created')->where('subject_type', (new Label)->getMorphClass())->latest('id')->firstOrFail();
    expect($entry->client_ip)->toBe('203.0.113.7')->and($entry->impersonator_id)->not->toBeNull();
});

it('D-38 deleting the impersonator keeps the entry and clears the link', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $this->actingAs(userWithRole(RoleName::Superadmin));
    asRequest(impersonatedBy: $admin->id);
    Label::factory()->create();
    $entry = latestActivity();

    DB::table('users')->where('id', $admin->id)->delete(); // users are soft-deleted in the app; this proves the database rule

    expect($entry->fresh()->impersonator_id)->toBeNull();
});

it('D-38 a soft-deleted impersonator is still named', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $this->actingAs(userWithRole(RoleName::Superadmin));
    asRequest(impersonatedBy: $admin->id);
    Label::factory()->create();
    $admin->delete();

    expect(latestActivity()->impersonator->is($admin))->toBeTrue();
});

it('D-38 the superadmin sees the impersonator, IP and user agent, and can filter to impersonated changes', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $employee = userWithRole(RoleName::Employee);
    $superadmin = userWithRole(RoleName::Superadmin);

    $this->actingAs($employee);
    asRequest(ip: '198.51.100.23', agent: 'ImpersonatedBrowser/9', impersonatedBy: $admin->id);
    Label::factory()->create(['name' => 'Done on behalf']);
    $impersonated = latestActivity();

    $this->actingAs($superadmin);
    asRequest(ip: '192.0.2.50', agent: 'DirectBrowser/1');
    Label::factory()->create(['name' => 'Done directly']);
    $direct = latestActivity();

    Livewire::test(ListActivityLogs::class)
        ->set('tableRecordsPerPage', 100)
        ->assertCanSeeTableRecords([$impersonated, $direct])
        ->assertSee($admin->getFilamentName())
        ->assertSee('198.51.100.23')
        ->filterTable('impersonated', true)
        ->assertCanSeeTableRecords([$impersonated])
        ->assertCanNotSeeTableRecords([$direct])
        ->filterTable('impersonated', false)
        ->assertCanSeeTableRecords([$direct])
        ->assertCanNotSeeTableRecords([$impersonated]);

    Livewire::test(ViewActivityLog::class, ['record' => $impersonated->getKey()])
        ->assertSee($admin->getFilamentName())
        ->assertSee('198.51.100.23')
        ->assertSee('ImpersonatedBrowser/9');
})->group('BR-32');

it('D-38 only the superadmin can see this context', function (RoleName $role): void {
    $this->actingAs(userWithRole($role))->get('/app/activity-log')->assertForbidden();
})->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('D-38 the activity log migration runs after the users table it references', function (): void {
    $files = collect(glob(database_path('migrations/*.php')))->map(fn ($f): string => basename($f))->sort()->values();

    expect($files->search(fn ($f): bool => str_contains($f, 'create_activity_log_table')))
        ->toBeGreaterThan($files->search(fn ($f): bool => str_contains($f, 'create_users_table')));
});
