<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Support\ThisWeek;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // The week runs Monday 8 to Sunday 14 June 2026.
    $this->travelTo('2026-06-10 12:00:00');
});

it('D-78 lists active people who start this week, with name, job title and date only', function (): void {
    $viewer = User::factory()->withRole(RoleName::Employee)->create();

    User::factory()->create(['name' => 'Ada', 'surname' => 'Lovelace', 'position' => 'Engineer', 'start_date' => '2026-06-08']);
    User::factory()->create(['name' => 'Sun', 'surname' => 'Day', 'start_date' => '2026-06-14']);
    User::factory()->create(['name' => 'Early', 'start_date' => '2026-06-07']);
    User::factory()->create(['name' => 'Late', 'start_date' => '2026-06-15']);
    User::factory()->create(['name' => 'Banned', 'start_date' => '2026-06-09', 'banned_at' => now()]);
    User::factory()->create(['name' => 'Gone', 'start_date' => '2026-06-09', 'anonymized_at' => now()]);

    $joiners = ThisWeek::joiners($viewer);

    expect(array_column($joiners, 'name'))->toBe(['Ada Lovelace', 'Sun Day'])
        ->and($joiners[0]['position'])->toBe('Engineer')
        ->and($joiners[1]['position'])->toBeNull()
        ->and($joiners[0]['date']->toDateString())->toBe('2026-06-08')
        ->and(array_keys($joiners[0]))->toBe(['name', 'position', 'date']);
})->group('D-78');

it('D-78 D-75 announces a leaver only when the offboarding is in progress or completed and the last day is this week', function (): void {
    $viewer = User::factory()->withRole(RoleName::Employee)->create();

    $leaver = fn (string $name, OffboardingStatus $status, string $end, array $user = []) => Offboarding::factory()->create([
        'user_id' => User::factory()->create(['name' => $name, 'surname' => 'X', ...$user])->id,
        'status' => $status,
        'end_date' => $end,
    ]);

    $leaver('Going', OffboardingStatus::InProgress, '2026-06-12');
    $leaver('Gone', OffboardingStatus::Completed, '2026-06-09', ['position' => 'Designer']);
    $leaver('Pending', OffboardingStatus::Pending, '2026-06-12');
    $leaver('Cancelled', OffboardingStatus::Cancelled, '2026-06-12');
    $leaver('Next', OffboardingStatus::InProgress, '2026-06-15');
    $leaver('Anon', OffboardingStatus::InProgress, '2026-06-12', ['anonymized_at' => now()]);

    $leavers = ThisWeek::leavers($viewer);

    expect(array_column($leavers, 'name'))->toBe(['Gone X', 'Going X'])
        ->and($leavers[0]['position'])->toBe('Designer')
        ->and(array_keys($leavers[0]))->toBe(['name', 'position', 'date']);
})->group('D-78', 'D-75');

it('D-78 does not run a query per person', function (): void {
    $viewer = User::factory()->withRole(RoleName::Employee)->create();

    foreach (range(1, 6) as $i) {
        Offboarding::factory()->create([
            'user_id' => User::factory()->create(['start_date' => '2026-06-09'])->id,
            'status' => OffboardingStatus::InProgress,
            'end_date' => '2026-06-11',
        ]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    ThisWeek::joiners($viewer);
    ThisWeek::leavers($viewer);

    // Two time-zone lookups, one query for each list, and one for the leavers' employees: six people, still five queries.
    expect(DB::getQueryLog())->toHaveCount(5);
})->group('D-78');
