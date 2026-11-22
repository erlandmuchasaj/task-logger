<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Support\MyWorkSummary;

beforeEach(function (): void {
    // A Wednesday: the week runs Monday 8 to Sunday 14 June 2026.
    $this->travelTo('2026-06-10 12:00:00');
});

it('D-78 counts only my tasks that are not completed or cancelled', function (): void {
    $me = User::factory()->withRole(RoleName::Employee)->create();
    $other = User::factory()->withRole(RoleName::Employee)->create();

    Task::factory()->create(['assignee_id' => $me->id, 'status' => TaskStatus::Todo]);
    Task::factory()->create(['assignee_id' => $me->id, 'status' => TaskStatus::InProgress]);
    Task::factory()->create(['assignee_id' => $me->id, 'status' => TaskStatus::Completed]);
    Task::factory()->create(['assignee_id' => $me->id, 'status' => TaskStatus::Cancelled]);
    Task::factory()->create(['assignee_id' => $me->id, 'status' => TaskStatus::Todo])->delete();
    Task::factory()->create(['assignee_id' => $other->id, 'status' => TaskStatus::Todo]);

    expect(MyWorkSummary::activeTasks($me))->toBe(2);
})->group('D-78');

it('D-78 adds up this month of my own logs only, in whole minutes', function (): void {
    $me = User::factory()->withRole(RoleName::Employee)->create();
    $other = User::factory()->withRole(RoleName::Employee)->create();

    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-01', 'duration_minutes' => 90]);
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-30', 'duration_minutes' => 45]);
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-05-31', 'duration_minutes' => 600]);
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-07-01', 'duration_minutes' => 600]);
    Log::factory()->create(['user_id' => $other->id, 'date' => '2026-06-05', 'duration_minutes' => 480]);

    expect(MyWorkSummary::minutesThisMonth($me))->toBe(135)
        ->and(MyWorkSummary::format(135))->toBe('2:15')
        ->and(MyWorkSummary::format(0))->toBe('0:00');
})->group('D-78');

it('D-78 gives seven days Monday to Sunday with zeros where nothing was logged', function (): void {
    $me = User::factory()->withRole(RoleName::Employee)->create();
    $other = User::factory()->withRole(RoleName::Employee)->create();

    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-08', 'duration_minutes' => 60]);
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-08', 'duration_minutes' => 30]);
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-14', 'duration_minutes' => 120]);
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-07', 'duration_minutes' => 999]);
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-15', 'duration_minutes' => 999]);
    Log::factory()->create(['user_id' => $other->id, 'date' => '2026-06-09', 'duration_minutes' => 480]);

    $days = MyWorkSummary::minutesPerDayThisWeek($me);

    expect(array_keys($days))->toBe(['2026-06-08', '2026-06-09', '2026-06-10', '2026-06-11', '2026-06-12', '2026-06-13', '2026-06-14'])
        ->and(array_values($days))->toBe([90, 0, 0, 0, 0, 0, 120]);
})->group('D-78');

it("D-78 BR-34 the week starts on the Monday of the person's own calendar", function (): void {
    // 23:30 UTC on Sunday 14 June is already Monday 15 June in Tirane (UTC+2 in summer).
    $this->travelTo('2026-06-14 23:30:00');

    $tirane = User::factory()->create(['country_id' => Country::factory()->albania()->create()->id]);
    $unplaced = User::factory()->create();

    expect(MyWorkSummary::weekStart($tirane)->toDateString())->toBe('2026-06-15')
        ->and(MyWorkSummary::weekStart($unplaced)->toDateString())->toBe('2026-06-08');
})->group('D-78', 'BR-34');

it('D-78 lists the tasks created this week and assigned to me, newest first, with the project loaded', function (): void {
    $me = User::factory()->withRole(RoleName::Employee)->create();
    $other = User::factory()->withRole(RoleName::Employee)->create();

    Task::factory()->create(['assignee_id' => $me->id, 'created_at' => '2026-06-08 09:00:00', 'name' => 'Monday']);
    $latest = Task::factory()->create(['assignee_id' => $me->id, 'created_at' => '2026-06-14 23:59:00', 'name' => 'Sunday']);
    Task::factory()->create(['assignee_id' => $me->id, 'created_at' => '2026-06-07 23:59:00', 'name' => 'Last week']);
    Task::factory()->create(['assignee_id' => $me->id, 'created_at' => '2026-06-15 00:00:00', 'name' => 'Next week']);
    Task::factory()->create(['assignee_id' => $other->id, 'created_at' => '2026-06-09 09:00:00', 'name' => 'Not mine']);

    $tasks = MyWorkSummary::assignedThisWeek($me);

    expect($tasks->pluck('name')->all())->toBe(['Sunday', 'Monday'])
        ->and($tasks->first()->relationLoaded('project'))->toBeTrue()
        ->and($tasks->first()->is($latest))->toBeTrue();
})->group('D-78');

it("D-78 BR-34 the edges of the week for assigned tasks follow the person's own timezone", function (): void {
    // Monday 8 June starts at 22:00 UTC on Sunday 7 June in Tirane (UTC+2 in summer).
    $tirane = User::factory()->create(['country_id' => Country::factory()->albania()->create()->id]);
    $unplaced = User::factory()->create();

    foreach ([$tirane, $unplaced] as $user) {
        Task::factory()->create(['assignee_id' => $user->id, 'created_at' => '2026-06-07 22:30:00', 'name' => 'Edge']);
    }

    expect(MyWorkSummary::assignedThisWeek($tirane)->pluck('name')->all())->toBe(['Edge'])
        ->and(MyWorkSummary::assignedThisWeek($unplaced))->toBeEmpty();
})->group('D-78', 'BR-34');
