<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Filament\Widgets\HoursThisWeekChart;
use App\Filament\Widgets\LeaveYearChart;
use App\Filament\Widgets\MyWorkStats;
use App\Filament\Widgets\ThisWeekPanel;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-06-10 12:00:00');
});

it('D-78 the stats show my own figures and nothing of a colleague', function (): void {
    ['employee' => $me, 'annual' => $annual] = leaveSetup();
    grantDays($me, $annual, '20.0');
    $colleague = User::factory()->withRole(RoleName::Employee)->create();

    Task::factory()->create(['assignee_id' => $me->id]);
    Task::factory()->count(5)->create(['assignee_id' => $colleague->id]);
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-09', 'duration_minutes' => 150]);
    Log::factory()->create(['user_id' => $colleague->id, 'date' => '2026-06-09', 'duration_minutes' => 1000]);

    $this->actingAs($me);

    Livewire::test(MyWorkStats::class)
        ->assertSee('Active tasks')
        ->assertSee('2:30')
        ->assertDontSee('16:40')
        ->assertSee('20.0 days')
        ->assertSee('of 20.0 total');
})->group('D-78');

it('D-78 the leave stat shows a dash when the leave type does not exist', function (): void {
    $me = userWithRole(RoleName::Employee);
    $this->actingAs($me);

    Livewire::test(MyWorkStats::class)->assertSee('—');
})->group('D-78');

it('D-78 the charts carry my week of hours and my leave taken against remaining', function (): void {
    ['employee' => $me, 'annual' => $annual] = leaveSetup();
    grantDays($me, $annual, '20.0');
    Log::factory()->create(['user_id' => $me->id, 'date' => '2026-06-08', 'duration_minutes' => 90]);
    $this->actingAs($me);

    $hours = Livewire::test(HoursThisWeekChart::class)->assertOk();
    $leave = Livewire::test(LeaveYearChart::class)->assertOk();

    $dataOf = fn (object $component): array => (fn (): array => $this->getData())->call($component);

    expect($dataOf($hours->instance())['datasets'][0]['data'])->toBe([1.5, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0])
        ->and($dataOf($hours->instance())['labels'][0])->toBe('Mon 08/06')
        ->and($dataOf($leave->instance())['datasets'][0]['data'])->toBe([0.0, 20.0]);
})->group('D-78');

it('D-78 the week panel shows joiners, leavers and my due tasks', function (): void {
    $me = userWithRole(RoleName::Employee);
    User::factory()->create(['name' => 'Newcomer', 'surname' => 'Nina', 'start_date' => '2026-06-09']);
    Task::factory()->create(['assignee_id' => $me->id, 'name' => 'Ship the thing']);
    $this->actingAs($me);

    Livewire::test(ThisWeekPanel::class)
        ->assertSee('Newcomer Nina')
        ->assertSee('Ship the thing')
        ->assertSee('Nobody leaves this week.');
})->group('D-78');

it('D-78 a person with no role sees no widgets, and an employee sees all of them on the dashboard', function (): void {
    $this->actingAs(User::factory()->create()->fresh());
    expect(MyWorkStats::canView())->toBeFalse()
        ->and(HoursThisWeekChart::canView())->toBeFalse()
        ->and(LeaveYearChart::canView())->toBeFalse()
        ->and(ThisWeekPanel::canView())->toBeFalse();

    $this->actingAs(userWithRole(RoleName::Employee));
    $this->get('/app')->assertOk()
        ->assertSeeLivewire(MyWorkStats::class)
        ->assertSeeLivewire(HoursThisWeekChart::class)
        ->assertSeeLivewire(LeaveYearChart::class)
        ->assertSeeLivewire(ThisWeekPanel::class);
})->group('D-78');
