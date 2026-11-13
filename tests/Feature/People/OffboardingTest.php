<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Settings\PrivacySettings;
use App\Domain\People\Actions\AnonymizeDueEmployees;
use App\Domain\People\Actions\ChangeOffboardingStatus;
use App\Domain\People\Actions\CreateOffboarding;
use App\Domain\People\Actions\CreatePerformanceReview;
use App\Domain\People\Actions\DeactivateEmployee;
use App\Domain\People\Actions\ReactivateEmployee;
use App\Domain\People\Actions\UpdateOffboarding;
use App\Domain\People\Data\OffboardingData;
use App\Domain\People\Data\PerformanceData;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Models\Performance;
use App\Domain\People\Models\Salary;
use App\Filament\Resources\Offboardings\Pages\ManageOffboardings;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

afterEach(fn () => Date::setTestNow());

function offboardingData(string $end = '2026-06-30', OffboardingStatus $status = OffboardingStatus::Pending): OffboardingData
{
    return new OffboardingData(Date::parse($end), false, false, 'Moving on', $status);
}

it('FR-16 W-10 superadmin, admin and hr manage offboardings; assistant and employee cannot', function (RoleName $role, bool $allowed): void {
    $employee = userWithRole(RoleName::Employee);
    $actor = userWithRole($role);

    if ($allowed) {
        expect(resolve(CreateOffboarding::class)->handle($actor, $employee, offboardingData()))->status->toBe(OffboardingStatus::Pending);
    } else {
        expect(fn () => resolve(CreateOffboarding::class)->handle($actor, $employee, offboardingData()))->toThrow(AuthorizationException::class);
    }
})->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, true], [RoleName::Assistant, false], [RoleName::Employee, false],
])->group('FR-16', 'W-10');

it('BR-28 FR-16 at most one non-cancelled offboarding per user, in the action and in the database', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $first = resolve(CreateOffboarding::class)->handle($hr, $employee, offboardingData());

    expect(fn () => resolve(CreateOffboarding::class)->handle($hr, $employee, offboardingData()))->toThrow(ValidationException::class, 'already has an offboarding')
        ->and(fn () => Offboarding::factory()->create(['user_id' => $employee->id]))->toThrow(QueryException::class);

    resolve(ChangeOffboardingStatus::class)->handle($hr, $first, OffboardingStatus::Cancelled);

    expect(resolve(CreateOffboarding::class)->handle($hr, $employee, offboardingData()))->status->toBe(OffboardingStatus::Pending);   // a cancelled one does not count
})->group('BR-28', 'FR-16');

it('D-45 status moves pending to in progress to completed or cancelled, and the final states stay final', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, userWithRole(RoleName::Employee), offboardingData());

    resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::InProgress);

    expect(fn () => resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::Pending))->toThrow(ValidationException::class, 'cannot move');

    resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::Completed);

    expect(fn () => resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::Cancelled))->toThrow(ValidationException::class)
        ->and(fn () => resolve(UpdateOffboarding::class)->handle($hr, $offboarding, offboardingData('2026-07-31')))->toThrow(ValidationException::class, 'no longer be changed')
        ->and(fn () => resolve(CreateOffboarding::class)->handle($hr, $offboarding->employee, offboardingData(status: OffboardingStatus::Cancelled)))->toThrow(ValidationException::class);
})->group('D-45');

it('ADR-0008 BR-28 completing deactivates the login through offboardings.manage, even for hr who cannot deactivate', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, $employee, offboardingData());

    expect(fn () => resolve(DeactivateEmployee::class)->handle($hr, $employee))->toThrow(AuthorizationException::class);   // not allowed directly

    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);
    resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::Completed);

    $ban = ActivityLog::query()->where('event', 'deactivated')->where('subject_id', $employee->id)->latest('id')->firstOrFail();

    expect($employee->refresh()->isBanned())->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $employee->id)->count())->toBe(0)
        ->and($ban->causer_id)->toBe($hr->id)
        ->and($ban->properties['offboarding_id'])->toBe($offboarding->id);
})->group('ADR-0008', 'BR-28');

it('W-10 creating an offboarding already completed deactivates the login too', function (): void {
    $employee = userWithRole(RoleName::Employee);

    resolve(CreateOffboarding::class)->handle(userWithRole(RoleName::Admin), $employee, offboardingData(status: OffboardingStatus::Completed));

    expect($employee->refresh()->isBanned())->toBeTrue();
})->group('W-10');

it('D-32 completing is refused for yourself and for the last superadmin, and nothing changes', function (): void {
    $root = userWithRole(RoleName::Superadmin);
    $offboarding = Offboarding::factory()->create(['user_id' => $root->id, 'created_by' => $root->id]);

    expect(fn () => resolve(ChangeOffboardingStatus::class)->handle($root, $offboarding, OffboardingStatus::Completed))->toThrow(ValidationException::class, 'yourself');

    $hr = userWithRole(RoleName::Hr);   // not the superadmin, so the "last superadmin" guard is what stops it
    expect(fn () => resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::Completed))->toThrow(ValidationException::class, 'last superadmin')
        ->and($offboarding->refresh()->status)->toBe(OffboardingStatus::Pending)
        ->and($root->refresh()->isBanned())->toBeFalse();
})->group('D-32', 'ADR-0008');

it('BR-28 D-15 FR-16 anonymises two months after the end date: personal data goes, history stays', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('avatars/me.png', 'x');
    $hr = userWithRole(RoleName::Hr);
    $employee = User::factory()->withRole(RoleName::Employee)->create([
        'name' => 'Arta', 'surname' => 'Hoxha', 'email' => 'arta@example.test', 'avatar_path' => 'avatars/me.png', 'start_date' => '2024-01-01',
    ]);
    EmployeeProfile::factory()->create(['user_id' => $employee->id, 'phone' => '+355692123456', 'address' => 'Rruga 1', 'metadata' => ['t-shirt' => 'M']]);
    EmergencyContact::factory()->create(['user_id' => $employee->id, 'name' => 'Ana Hoxha', 'phone' => '+355692123457']);
    $salary = Salary::factory()->create(['user_id' => $employee->id]);
    $review = Performance::factory()->create(['user_id' => $employee->id]);
    asRequest('203.0.113.9', 'SecretBrowser/1.0');
    $employee->update(['position' => 'Engineer']);   // an audit entry with their old/new values, by themselves
    $log = ActivityLog::query()->where('subject_id', $employee->id)->where('subject_type', User::class)->latest('id')->firstOrFail();
    DB::table('activity_log')->where('id', $log->id)->update(['causer_type' => User::class, 'causer_id' => $employee->id, 'client_ip' => '203.0.113.9', 'user_agent' => 'SecretBrowser/1.0']);

    Date::setTestNow('2026-06-30 12:00:00');
    resolve(ChangeOffboardingStatus::class)->handle($hr, resolve(CreateOffboarding::class)->handle($hr, $employee, offboardingData('2026-06-30')), OffboardingStatus::Completed);

    Date::setTestNow('2026-08-29 12:00:00');
    expect(resolve(AnonymizeDueEmployees::class)->handle())->toBe(0);   // one day short

    Date::setTestNow('2026-08-30 12:00:00');
    expect(resolve(AnonymizeDueEmployees::class)->handle())->toBe(1)
        ->and(resolve(AnonymizeDueEmployees::class)->handle())->toBe(0);   // idempotent

    $employee->refresh();
    $profile = EmployeeProfile::query()->where('user_id', $employee->id)->sole();

    expect($employee)->name->toBe('Former employee #'.$employee->id)->surname->toBeNull()
        ->email->toBe('anonymized-'.$employee->id.'@invalid.example')->avatar_path->toBeNull()->anonymized_at->not->toBeNull()
        ->and($employee->isBanned())->toBeTrue()
        ->and($profile->phone)->toBeNull()->and($profile->address)->toBeNull()->and($profile->metadata)->toBeNull()
        ->and(EmergencyContact::query()->where('user_id', $employee->id)->count())->toBe(0)
        ->and(Storage::disk('public')->exists('avatars/me.png'))->toBeFalse()
        // history stays
        ->and(Salary::query()->find($salary->id))->not->toBeNull()->and(Performance::query()->find($review->id))->not->toBeNull()->and($employee->position)->toBe('Engineer');

    // no personal value is left in the audit trail, and the entry's IP and browser are cleared (D-38)
    $everything = json_encode(DB::table('activity_log')->get());
    $scrubbed = DB::table('activity_log')->where('id', $log->id)->first();

    expect($everything)->not->toContain('Arta')->not->toContain('Hoxha')->not->toContain('arta@example.test')->not->toContain('Ana Hoxha')->not->toContain('+355692123456')
        ->and($scrubbed->client_ip)->toBeNull()->and($scrubbed->user_agent)->toBeNull()->and($scrubbed->attribute_changes)->toBeNull()
        ->and(ActivityLog::query()->where('event', 'anonymized')->where('subject_id', $employee->id)->exists())->toBeTrue();
})->group('BR-28', 'D-15', 'FR-16', 'NFR-07');

it("D-46 the retention period is the setting, in months, from the end date, and counts in the employee's timezone", function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    resolve(PrivacySettings::class)->fill(['anonymize_after_months' => 6])->save();
    resolve(ChangeOffboardingStatus::class)->handle($hr, resolve(CreateOffboarding::class)->handle($hr, $employee, offboardingData('2026-01-31')), OffboardingStatus::Completed);

    Date::setTestNow('2026-07-30 12:00:00');
    expect(resolve(AnonymizeDueEmployees::class)->handle())->toBe(0);

    Date::setTestNow('2026-07-31 12:00:00');
    expect(resolve(AnonymizeDueEmployees::class)->handle())->toBe(1);
})->group('D-46', 'D-15');

it('D-46 never anonymises someone whose offboarding is not completed or who was reactivated', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $pending = userWithRole(RoleName::Employee);
    $cancelled = userWithRole(RoleName::Employee);
    $reactivated = userWithRole(RoleName::Employee);
    resolve(CreateOffboarding::class)->handle($hr, $pending, offboardingData('2025-01-01'));
    resolve(ChangeOffboardingStatus::class)->handle($hr, resolve(CreateOffboarding::class)->handle($hr, $cancelled, offboardingData('2025-01-01')), OffboardingStatus::Cancelled);
    resolve(ChangeOffboardingStatus::class)->handle($hr, resolve(CreateOffboarding::class)->handle($hr, $reactivated, offboardingData('2025-01-01')), OffboardingStatus::Completed);
    $reactivated->refresh()->unban();

    expect(resolve(AnonymizeDueEmployees::class)->handle())->toBe(0)
        ->and($pending->refresh()->anonymized_at)->toBeNull()->and($reactivated->refresh()->anonymized_at)->toBeNull();
})->group('D-46');

it('BR-28 an anonymised person is gone from pickers and cannot be reactivated, reviewed or offboarded again', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $root = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    resolve(ChangeOffboardingStatus::class)->handle($hr, resolve(CreateOffboarding::class)->handle($hr, $employee, offboardingData('2025-01-01')), OffboardingStatus::Completed);
    resolve(AnonymizeDueEmployees::class)->handle();

    expect($employee->refresh()->canAccessPanel(Filament::getDefaultPanel()))->toBeFalse()
        ->and(resolve(ReactivateEmployee::class)->handle($root, $employee)->isBanned())->toBeTrue()
        ->and(fn () => resolve(CreatePerformanceReview::class)->handle($root, $employee, new PerformanceData(5, now())))->toThrow(ValidationException::class, 'anonymised');
})->group('BR-28');

it('W-10 the Offboardings screen starts, edits and completes an offboarding', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $this->actingAs($hr);

    Livewire::test(ManageOffboardings::class)
        ->callAction('start', ['user_id' => $employee->id, 'status' => 'pending', 'end_date' => '2026-06-30', 'equipment_returned' => false, 'resignation_letter_signed' => true, 'description' => 'x'])
        ->assertHasNoActionErrors();

    $offboarding = Offboarding::query()->sole();

    Livewire::test(ManageOffboardings::class)
        ->assertCanSeeTableRecords([$offboarding])
        ->assertTableActionVisible('complete', $offboarding)
        ->callTableAction('edit', $offboarding, ['end_date' => '2026-07-15', 'equipment_returned' => true, 'resignation_letter_signed' => true, 'description' => 'y'])
        ->callTableAction('complete', $offboarding);

    expect($offboarding->refresh())->status->toBe(OffboardingStatus::Completed)->equipment_returned->toBeTrue()->end_date->toDateString()->toBe('2026-07-15')
        ->and($employee->refresh()->isBanned())->toBeTrue();

    Livewire::test(ManageOffboardings::class)->assertTableActionHidden('complete', $offboarding)->assertTableActionHidden('edit', $offboarding);
})->group('W-10');

it('FR-16 only offboardings.view holders open the screen; offboardings are never deleted, root included', function (): void {
    $this->actingAs(userWithRole(RoleName::Assistant))->get('/app/offboardings')->assertForbidden();
    $this->actingAs(userWithRole(RoleName::Employee))->get('/app/offboardings')->assertForbidden();
    $this->actingAs(userWithRole(RoleName::Admin))->get('/app/offboardings')->assertOk();

    $offboarding = Offboarding::factory()->create();
    expect(userWithRole(RoleName::Superadmin)->can('delete', $offboarding))->toBeFalse();
})->group('FR-16', 'D-41');

it('BR-32 offboarding writes are audited', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, userWithRole(RoleName::Employee), offboardingData());
    resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::InProgress);

    expect(latestActivity('updated'))->causer_id->toBe($hr->id)->subject_type->toBe(Offboarding::class);
})->group('BR-32');
