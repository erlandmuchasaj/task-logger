<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\People\Actions\ChangeOffboardingStatus;
use App\Domain\People\Actions\CreateOffboarding;
use App\Domain\People\Actions\ReassignDirectReports;
use App\Domain\People\Actions\SendOffboardingThanks;
use App\Domain\People\Actions\UpdateOffboarding;
use App\Domain\People\Data\OffboardingData;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Notifications\ManagerChanged;
use App\Domain\People\Notifications\OffboardingStarted;
use App\Domain\People\Notifications\OffboardingThanks;
use App\Domain\People\Notifications\ReportsReassignedToYou;
use App\Filament\Resources\Offboardings\Pages\ManageOffboardings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-06-20 10:00:00'));
    Notification::fake();
});

/** A manager with people under them, who is in turn managed by `$boss`. */
function team(int $reports = 2, ?User $boss = null): array
{
    $boss ??= userWithRole(RoleName::Superadmin);
    $manager = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $boss->id]);
    $members = User::factory()->count($reports)->withRole(RoleName::Employee)->create(['manager_id' => $manager->id]);

    return [$manager, $members, $boss];
}

function leaving(string $end = '2026-06-30', OffboardingStatus $status = OffboardingStatus::Pending): OffboardingData
{
    return new OffboardingData(Date::parse($end), false, false, 'Moving on', $status);
}

it('D-74 an offboarding is refused while the person still has direct reports', function (OffboardingStatus $status): void {
    [$manager] = team(2);

    expect(fn () => resolve(CreateOffboarding::class)->handle(userWithRole(RoleName::Hr), $manager, leaving(status: $status)))
        ->toThrow(ValidationException::class, 'still has 2 direct reports')
        ->and(Offboarding::query()->count())->toBe(0)
        ->and($manager->refresh()->isBanned())->toBeFalse();
})->with([OffboardingStatus::Pending, OffboardingStatus::Completed])->group('D-74');

it('D-74 deactivated and anonymised reports do not block, and stay under their old manager', function (): void {
    [$manager, $members] = team(2);
    $members[0]->forceFill(['banned_at' => now()])->save();
    $members[1]->forceFill(['anonymized_at' => now()])->save();

    resolve(CreateOffboarding::class)->handle(userWithRole(RoleName::Hr), $manager, leaving());

    expect(Offboarding::query()->count())->toBe(1)
        ->and($members[0]->refresh()->manager_id)->toBe($manager->id);
})->group('D-74');

it('D-74 starting with a new manager moves every active report in one step and audits who did it', function (): void {
    [$manager, $members, $boss] = team(3);
    $hr = userWithRole(RoleName::Hr);

    resolve(CreateOffboarding::class)->handle($hr, $manager, leaving(), $boss);

    expect(User::query()->where('manager_id', $boss->id)->whereIn('id', $members->pluck('id'))->count())->toBe(3)
        ->and(ActivityLog::query()->where('subject_id', $members[0]->id)->where('event', 'updated')->latest('id')->first())
        ->causer_id->toBe($hr->id);
})->group('D-74');

it('D-74 an offboarding that fails after the move leaves the reports where they were', function (): void {
    $boss = userWithRole(RoleName::Superadmin);
    $hr = User::factory()->withRole(RoleName::Hr)->create(['manager_id' => $boss->id]);
    $report = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $hr->id]);

    // Completing your own offboarding is refused (D-32) after the reports were moved: all of it rolls back.
    expect(fn () => resolve(CreateOffboarding::class)->handle($hr, $hr, leaving(status: OffboardingStatus::Completed), $boss))
        ->toThrow(ValidationException::class, 'cannot deactivate yourself')
        ->and($report->refresh()->manager_id)->toBe($hr->id)
        ->and(Offboarding::query()->count())->toBe(0);
})->group('D-74');

it('D-74 hr and admin reassign; assistant and employee are refused', function (RoleName $role, bool $allowed): void {
    [$manager, $members, $boss] = team(2);
    $actor = userWithRole($role);

    if ($allowed) {
        expect(resolve(ReassignDirectReports::class)->handle($actor, $manager, $boss))->toBe(2);
    } else {
        expect(fn () => resolve(ReassignDirectReports::class)->handle($actor, $manager, $boss))->toThrow(AuthorizationException::class)
            ->and($members[0]->refresh()->manager_id)->toBe($manager->id);
    }
})->with([[RoleName::Hr, true], [RoleName::Admin, true], [RoleName::Assistant, false], [RoleName::Employee, false]])->group('D-74');

it('D-74 the new manager and each moved person get an email and an in-app notification, and nobody else', function (): void {
    [$manager, $members, $boss] = team(2);
    $bystander = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $boss->id]);

    resolve(ReassignDirectReports::class)->handle(userWithRole(RoleName::Hr), $manager, $boss);

    Notification::assertSentTo($boss, ReportsReassignedToYou::class, fn (ReportsReassignedToYou $notification, array $channels): bool => $channels === ['mail', 'database']
        && $notification->names === $members->map(fn (User $member): string => $member->getFilamentName())->values()->all());

    foreach ($members as $member) {
        Notification::assertSentTo($member, ManagerChanged::class, fn (ManagerChanged $notification, array $channels): bool => $channels === ['mail', 'database']
            && $notification->newManager === $boss->getFilamentName()
            && $notification->previousManager === $manager->getFilamentName());
    }

    Notification::assertNotSentTo($manager, ReportsReassignedToYou::class);
    Notification::assertNotSentTo($manager, ManagerChanged::class);
    Notification::assertNotSentTo($bystander, ManagerChanged::class);
    Notification::assertCount(3);
})->group('D-74');

it('D-74 starting an offboarding with a new manager tells them too, and a refusal tells nobody', function (): void {
    [$manager, $members, $boss] = team(2);
    $hr = userWithRole(RoleName::Hr);

    resolve(CreateOffboarding::class)->handle($hr, $manager, leaving(), $boss);

    Notification::assertSentTo($boss, ReportsReassignedToYou::class);
    Notification::assertSentTo($members[0], ManagerChanged::class);
    Notification::assertSentTo($members[1], ManagerChanged::class);

    // The same people, refused because the offboarding fails after the move: the transaction rolls back and so does the mail.
    Notification::fake();
    $own = User::factory()->withRole(RoleName::Hr)->create(['manager_id' => $boss->id]);
    $report = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $own->id]);

    expect(fn () => resolve(CreateOffboarding::class)->handle($own, $own, leaving(status: OffboardingStatus::Completed), $boss))
        ->toThrow(ValidationException::class);

    Notification::assertNotSentTo($report, ManagerChanged::class);
    Notification::assertNotSentTo($boss, ReportsReassignedToYou::class);
})->group('D-74');

it('D-74 a refused reassignment notifies nobody', function (): void {
    [$manager, $members, $boss] = team(2);

    expect(fn () => resolve(ReassignDirectReports::class)->handle(userWithRole(RoleName::Employee), $manager, $boss))->toThrow(AuthorizationException::class)
        ->and(fn () => resolve(ReassignDirectReports::class)->handle(userWithRole(RoleName::Hr), $manager, $manager))->toThrow(ValidationException::class);

    Notification::assertNothingSent();
})->group('D-74');

it('D-74 the new manager must be somebody who stays and cannot create a loop', function (): void {
    [$manager, $members, $boss] = team(2);
    $hr = userWithRole(RoleName::Hr);
    $deactivated = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $boss->id, 'banned_at' => now()]);
    $alsoLeaving = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $boss->id]);
    Offboarding::factory()->create(['user_id' => $alsoLeaving->id, 'status' => OffboardingStatus::Pending]);
    $underAReport = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $members[0]->id]);

    $reassign = fn (User $to): Closure => fn () => resolve(ReassignDirectReports::class)->handle($hr, $manager, $to);

    expect($reassign($manager))->toThrow(ValidationException::class, 'other than the person who is leaving')
        ->and($reassign($deactivated))->toThrow(ValidationException::class, 'deactivated')
        ->and($reassign($alsoLeaving))->toThrow(ValidationException::class, 'being offboarded too')
        ->and($reassign($members[1]))->toThrow(ValidationException::class, 'cannot become their manager')
        ->and($reassign($underAReport))->toThrow(ValidationException::class, 'cannot become their manager')
        ->and($members[0]->refresh()->manager_id)->toBe($manager->id);
})->group('D-74');

it('D-74 completing is refused if somebody was given to this manager after the offboarding started', function (): void {
    [$manager, , $boss] = team(0);
    $hr = userWithRole(RoleName::Hr);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, $manager, leaving());
    $newcomer = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $manager->id]);

    expect(fn () => resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::Completed))
        ->toThrow(ValidationException::class, 'still has 1 direct report')
        ->and($manager->refresh()->isBanned())->toBeFalse();

    resolve(ReassignDirectReports::class)->handle($hr, $manager, $boss);
    resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::Completed);

    expect($manager->refresh()->isBanned())->toBeTrue()
        ->and($newcomer->refresh()->manager_id)->toBe($boss->id);
})->group('D-74');

it('D-74 the Start form asks for the new manager only when there are reports, and the row action moves them', function (): void {
    [$manager, $members, $boss] = team(2);
    $hr = userWithRole(RoleName::Hr);
    $this->actingAs($hr);

    Livewire::test(ManageOffboardings::class)
        ->callAction('start', ['user_id' => $manager->id, 'status' => 'pending', 'end_date' => '2026-06-30', 'equipment_returned' => false, 'resignation_letter_signed' => false])
        ->assertHasActionErrors(['new_manager_id' => 'required']);

    Livewire::test(ManageOffboardings::class)
        ->callAction('start', ['user_id' => $manager->id, 'new_manager_id' => $boss->id, 'status' => 'pending', 'end_date' => '2026-06-30', 'equipment_returned' => false, 'resignation_letter_signed' => false])
        ->assertHasNoActionErrors();

    expect($members[0]->refresh()->manager_id)->toBe($boss->id);

    [$second, $team] = team(1, $boss);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, userWithRole(RoleName::Employee), leaving());
    $offboarding->update(['user_id' => $second->id]);

    Livewire::test(ManageOffboardings::class)
        ->assertTableActionVisible('reassign', $offboarding)
        ->callTableAction('reassign', $offboarding, ['new_manager_id' => $boss->id])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('reassign', $offboarding);

    expect($team[0]->refresh()->manager_id)->toBe($boss->id);
})->group('D-74');

it('D-75 the manager and HR are told when an offboarding starts; nobody else is', function (): void {
    [$manager, , $boss] = team(0);
    $hr = userWithRole(RoleName::Hr);
    $otherHr = userWithRole(RoleName::Hr);
    $colleague = userWithRole(RoleName::Employee);

    resolve(CreateOffboarding::class)->handle($hr, $manager, leaving());

    Notification::assertSentTo([$boss, $hr, $otherHr], OffboardingStarted::class, fn (OffboardingStarted $n, array $channels): bool => $channels === ['mail', 'database']);
    Notification::assertNotSentTo([$manager, $colleague], OffboardingStarted::class);
    Notification::assertSentTimes(OffboardingStarted::class, 3);
})->group('D-75');

it('D-75 with no manager the superadmins are told instead, and a deactivated manager is skipped', function (): void {
    $root = userWithRole(RoleName::Superadmin);
    $otherRoot = userWithRole(RoleName::Superadmin);
    $hr = userWithRole(RoleName::Hr);

    resolve(CreateOffboarding::class)->handle($hr, $root, leaving());
    Notification::assertSentTo([$otherRoot, $hr], OffboardingStarted::class);
    Notification::assertNotSentTo($root, OffboardingStarted::class);

    Notification::fake();
    $deactivatedBoss = User::factory()->withRole(RoleName::Employee)->create(['banned_at' => now()]);
    $employee = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $deactivatedBoss->id]);

    resolve(CreateOffboarding::class)->handle($hr, $employee, leaving());
    Notification::assertNotSentTo($deactivatedBoss, OffboardingStarted::class);
    Notification::assertSentTo([$root, $otherRoot], OffboardingStarted::class);
})->group('D-75');

it('D-75 only people who may open Offboardings get the link, and the description never travels', function (): void {
    [$manager, , $boss] = team(0, userWithRole(RoleName::Employee));
    $hr = userWithRole(RoleName::Hr);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, $manager, leaving());
    $notification = new OffboardingStarted($offboarding);

    $forHr = json_encode($notification->toDatabase($hr), JSON_UNESCAPED_SLASHES);
    $forManager = json_encode($notification->toDatabase($boss), JSON_UNESCAPED_SLASHES);

    expect($forHr)->toContain('/app/offboardings')
        ->and($forManager)->not->toContain('offboardings')->not->toContain('Moving on')
        ->and($forManager)->toContain('Jun 30, 2026')
        ->and($notification->toMail($boss)->actionUrl)->toBeNull()
        ->and($notification->toMail($hr)->actionUrl)->toContain('/app/offboardings');
})->group('D-75');

it('D-75 changing or cancelling an offboarding does not notify again', function (): void {
    [$manager, , $boss] = team(0);
    $hr = userWithRole(RoleName::Hr);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, $manager, leaving());
    Notification::fake();

    resolve(UpdateOffboarding::class)->handle($hr, $offboarding, leaving('2026-07-10'));
    resolve(ChangeOffboardingStatus::class)->handle($hr, $offboarding, OffboardingStatus::Cancelled);

    Notification::assertNothingSent();
})->group('D-75');

it('D-76 the thank-you goes out on the last day, once, however often the job runs', function (): void {
    $employee = userWithRole(RoleName::Employee);
    Offboarding::factory()->create(['user_id' => $employee->id, 'end_date' => '2026-06-30', 'status' => OffboardingStatus::Pending]);

    $this->travelTo(Date::parse('2026-06-29 09:00:00'));
    expect(resolve(SendOffboardingThanks::class)->handle())->toBe(0);

    $this->travelTo(Date::parse('2026-06-30 09:00:00'));
    expect(resolve(SendOffboardingThanks::class)->handle())->toBe(1)
        ->and(resolve(SendOffboardingThanks::class)->handle())->toBe(0);

    $this->travelTo(Date::parse('2026-07-01 09:00:00'));
    resolve(SendOffboardingThanks::class)->handle();

    Notification::assertSentToTimes($employee, OffboardingThanks::class, 1);
    Notification::assertSentTo($employee, OffboardingThanks::class, fn (OffboardingThanks $n, array $channels): bool => $channels === ['mail']);
})->group('D-76');

it('D-76 a cancelled offboarding sends nothing, even on its last day', function (): void {
    $employee = userWithRole(RoleName::Employee);
    Offboarding::factory()->create(['user_id' => $employee->id, 'end_date' => '2026-06-30', 'status' => OffboardingStatus::Cancelled]);

    $this->travelTo(Date::parse('2026-06-30 09:00:00'));
    resolve(SendOffboardingThanks::class)->handle();

    Notification::assertNothingSent();
})->group('D-76');

it('D-76 a last day that moves sends on the new day only', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, $employee, leaving('2026-06-30'));
    resolve(UpdateOffboarding::class)->handle($hr, $offboarding, leaving('2026-07-03'));
    Notification::fake();

    $this->travelTo(Date::parse('2026-06-30 09:00:00'));
    expect(resolve(SendOffboardingThanks::class)->handle())->toBe(0);

    $this->travelTo(Date::parse('2026-07-03 09:00:00'));
    expect(resolve(SendOffboardingThanks::class)->handle())->toBe(1);
})->group('D-76');

it('D-76 moving the date after the email went out never sends a second one', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $offboarding = resolve(CreateOffboarding::class)->handle($hr, $employee, leaving('2026-06-30'));

    $this->travelTo(Date::parse('2026-06-30 09:00:00'));
    resolve(SendOffboardingThanks::class)->handle();
    resolve(UpdateOffboarding::class)->handle($hr, $offboarding, leaving('2026-07-02'));

    $this->travelTo(Date::parse('2026-07-02 09:00:00'));
    resolve(SendOffboardingThanks::class)->handle();

    Notification::assertSentToTimes($employee, OffboardingThanks::class, 1);
    expect($offboarding->refresh()->thanks_sent_at)->not->toBeNull();
})->group('D-76');

it('D-76 a completed offboarding still thanks the deactivated person; an anonymised one gets nothing', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $leaver = userWithRole(RoleName::Employee);
    $gone = userWithRole(RoleName::Employee);
    resolve(CreateOffboarding::class)->handle($hr, $leaver, leaving('2026-06-30', OffboardingStatus::Completed));
    Offboarding::factory()->create(['user_id' => $gone->id, 'end_date' => '2026-06-30', 'status' => OffboardingStatus::Completed]);
    $gone->forceFill(['anonymized_at' => now()])->save();

    $this->travelTo(Date::parse('2026-06-30 09:00:00'));
    resolve(SendOffboardingThanks::class)->handle();

    expect($leaver->refresh()->isBanned())->toBeTrue();
    Notification::assertSentTo($leaver, OffboardingThanks::class);
    Notification::assertNotSentTo($gone, OffboardingThanks::class);
})->group('D-76');

it("D-76 the last day is the person's own calendar day", function (): void {
    $employee = userWithRole(RoleName::Employee);
    $employee->update(['country_id' => Country::factory()->albania()->create()->id]);
    Offboarding::factory()->create(['user_id' => $employee->id, 'end_date' => '2026-06-30', 'status' => OffboardingStatus::InProgress]);

    // 23:30 UTC on 29 June is already 30 June in Tirane (UTC+2 in summer).
    $this->travelTo(Date::parse('2026-06-29 23:30:00'));
    expect(resolve(SendOffboardingThanks::class)->handle())->toBe(1);
})->group('D-76');

it('D-76 a person thanked for an earlier, later cancelled offboarding is not thanked again', function (): void {
    $employee = userWithRole(RoleName::Employee);
    $first = Offboarding::factory()->create(['user_id' => $employee->id, 'end_date' => '2026-06-30', 'status' => OffboardingStatus::Pending]);

    $this->travelTo(Date::parse('2026-06-30 09:00:00'));
    resolve(SendOffboardingThanks::class)->handle();
    $first->update(['status' => OffboardingStatus::Cancelled]);
    Offboarding::factory()->create(['user_id' => $employee->id, 'end_date' => '2026-07-02', 'status' => OffboardingStatus::Pending]);

    $this->travelTo(Date::parse('2026-07-02 09:00:00'));
    expect(resolve(SendOffboardingThanks::class)->handle())->toBe(0);
})->group('D-76');

it('D-76 the job is on the schedule at 09:00 Europe/Tirane', function (): void {
    $event = collect(resolve(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'people.offboarding-thanks');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('Europe/Tirane');
})->group('D-76');
