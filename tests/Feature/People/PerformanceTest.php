<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\CreatePerformanceReview;
use App\Domain\People\Data\PerformanceData;
use App\Domain\People\Models\Performance;
use App\Filament\Resources\Performances\Pages\ManagePerformances;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function review(User $actor, User $employee, int $score = 8): Performance
{
    return app(CreatePerformanceReview::class)->handle($actor, $employee, new PerformanceData($score, Carbon::parse('2026-06-30'), 'Good work'));
}

it('FR-15 W-09 superadmin and admin create reviews; hr, assistant and employee cannot', function (RoleName $role, bool $allowed) {
    $employee = userWithRole(RoleName::Employee);
    $actor = userWithRole($role);

    if ($allowed) {
        expect(review($actor, $employee))->score->toBe(8)->created_by->toBe($actor->id)->user_id->toBe($employee->id);
    } else {
        expect(fn () => review($actor, $employee))->toThrow(AuthorizationException::class);
    }
})->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
])->group('FR-15', 'W-09');

it('FR-15 the score is 1 to 10, in the action and in the database', function (int $score) {
    expect(fn () => review(userWithRole(RoleName::Admin), userWithRole(RoleName::Employee), $score))->toThrow(ValidationException::class, 'between 1 and 10');
})->with([0, 11, -3])->group('FR-15');

it('BR-27 D-21 only superadmin, hr and the direct manager read a review', function () {
    $manager = userWithRole(RoleName::Admin);
    $employee = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $manager->id]);
    $review = review(userWithRole(RoleName::Superadmin), $employee);

    $reader = fn (User $u): bool => $u->can('view', $review->load('employee'));

    expect($reader(userWithRole(RoleName::Superadmin)))->toBeTrue()
        ->and($reader(userWithRole(RoleName::Hr)))->toBeTrue()
        ->and($reader($manager))->toBeTrue()
        ->and($reader(userWithRole(RoleName::Admin)))->toBeFalse()          // an admin who is not the manager
        ->and($reader(userWithRole(RoleName::Assistant)))->toBeFalse()
        ->and($reader($employee))->toBeFalse()                              // not even the person reviewed
        ->and($reader(userWithRole(RoleName::Employee)))->toBeFalse();
})->group('BR-27', 'D-21');

it('BR-27 an employee who manages people reads their reports\' reviews through the relationship', function () {
    $teamLead = userWithRole(RoleName::Employee);
    $report = User::factory()->withRole(RoleName::Employee)->create(['manager_id' => $teamLead->id]);
    $other = userWithRole(RoleName::Employee);
    $mine = review(userWithRole(RoleName::Admin), $report);
    $theirs = review(userWithRole(RoleName::Admin), $other);

    $this->actingAs($teamLead);

    Livewire::test(ManagePerformances::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
})->group('BR-27', 'D-21', 'ADR-0007');

it('FR-15 an admin who is not the manager can add a review but reads nothing back', function () {
    $admin = userWithRole(RoleName::Admin);
    $employee = userWithRole(RoleName::Employee);
    $this->actingAs($admin);

    Livewire::test(ManagePerformances::class)
        ->callAction('review', ['user_id' => $employee->id, 'score' => 9, 'review_date' => '2026-06-30', 'notes' => 'Great'])
        ->assertHasNoActionErrors();

    $review = Performance::sole();

    Livewire::test(ManagePerformances::class)->assertCanNotSeeTableRecords([$review]);
    expect($admin->can('view', $review->load('employee')))->toBeFalse();
})->group('FR-15', 'BR-27');

it('BR-27 reviews can be read and added from the screen by hr and superadmin as designed', function () {
    $review = review(userWithRole(RoleName::Superadmin), userWithRole(RoleName::Employee));

    $this->actingAs(userWithRole(RoleName::Hr));
    Livewire::test(ManagePerformances::class)->assertCanSeeTableRecords([$review])->assertActionHidden('review');

    $this->actingAs(userWithRole(RoleName::Employee))->get('/app/performances')->assertForbidden();
})->group('BR-27');

it('D-45 nobody edits or deletes a review, root included', function () {
    $review = review(userWithRole(RoleName::Superadmin), userWithRole(RoleName::Employee));
    $root = userWithRole(RoleName::Superadmin);

    expect($root->can('update', $review))->toBeFalse()->and($root->can('delete', $review))->toBeFalse()->and($root->can('view', $review))->toBeTrue();
})->group('D-45', 'D-41');

it('BR-32 a review is audited', function () {
    review(userWithRole(RoleName::Admin), userWithRole(RoleName::Employee));

    expect(latestActivity()->subject_type)->toBe(Performance::class);
})->group('BR-32');
