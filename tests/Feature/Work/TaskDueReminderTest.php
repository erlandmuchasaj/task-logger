<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\Country;
use App\Domain\Work\Actions\AssignTask;
use App\Domain\Work\Actions\SendTaskDueReminders;
use App\Domain\Work\Actions\UpdateTask;
use App\Domain\Work\Data\TaskData;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Notifications\TaskDueSoon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(Date::parse('2026-06-10 10:00:00'));
});

/** A task due on $dueDate for an employee; the project and technology come from loggingSetup. */
function dueTask(array $overrides = [], array $setup = []): Task
{
    $setup = $setup ?: loggingSetup();

    return Task::factory()->create(array_merge([
        'project_id' => $setup['project']->id,
        'technology_id' => $setup['technology']->id,
        'assignee_id' => $setup['employee']->id,
        'due_date' => '2026-06-11',
    ], $overrides));
}

it('D-81 reminds the assignee one day before the due date, once', function (): void {
    $task = dueTask();
    $assignee = $task->assignee;

    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 1])
        ->and(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 0]); // run twice

    Notification::assertSentToTimes($assignee, TaskDueSoon::class, 1);
    expect($task->fresh()->due_reminder_sent_at)->not->toBeNull();
})->group('D-81');

it('D-81 does not remind before the window or after the due date', function (): void {
    $setup = loggingSetup();
    dueTask(['due_date' => '2026-06-12'], $setup); // two days away: too early
    dueTask(['due_date' => '2026-06-09'], $setup); // already overdue

    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 0]);
    Notification::assertNothingSent();

    $this->travelTo(Date::parse('2026-06-11 10:00:00'));
    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 1]); // the 12th is now one day away
})->group('D-81');

it('D-81 reminds on the due date itself when an earlier run was missed', function (): void {
    dueTask(['due_date' => '2026-06-10']);

    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 1]);
})->group('D-81');

it('D-81 leaves tasks that are in review, completed or cancelled alone', function (TaskStatus $status): void {
    dueTask(['status' => $status]);

    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 0]);
    Notification::assertNothingSent();
})->with([TaskStatus::InReview, TaskStatus::Completed, TaskStatus::Cancelled])->group('D-81');

it('D-81 reminds for backlog, to do and in progress', function (TaskStatus $status): void {
    dueTask(['status' => $status]);

    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 1]);
})->with([TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::InProgress])->group('D-81');

it('D-81 skips a task without an assignee, and a deactivated or anonymised assignee', function (): void {
    $setup = loggingSetup();
    dueTask(['assignee_id' => null], $setup);

    $banned = userWithRole(RoleName::Employee);
    $banned->forceFill(['banned_at' => now()])->save();
    dueTask(['assignee_id' => $banned->id], $setup);

    $anonymised = userWithRole(RoleName::Employee);
    $anonymised->forceFill(['anonymized_at' => now()])->save();
    dueTask(['assignee_id' => $anonymised->id], $setup);

    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 0]);
    Notification::assertNothingSent();
})->group('D-81');

it("D-81 counts the day in the assignee's own timezone", function (): void {
    $this->travelTo(Date::parse('2026-06-10 20:00:00')); // already the 11th in Auckland, still the 10th in UTC
    $auckland = Country::factory()->create(['timezone' => 'Pacific/Auckland']);
    $setup = loggingSetup(employee: userWithRole(RoleName::Employee));
    $setup['employee']->forceFill(['country_id' => $auckland->id])->save();

    $local = dueTask(['due_date' => '2026-06-12'], $setup);
    $utc = dueTask(['due_date' => '2026-06-12'], loggingSetup());

    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 1]);
    expect($local->fresh()->due_reminder_sent_at)->not->toBeNull()
        ->and($utc->fresh()->due_reminder_sent_at)->toBeNull();
})->group('D-81');

it('D-81 gives a task a new reminder when its due date or assignee changes', function (): void {
    $setup = loggingSetup();
    $task = dueTask([], $setup);
    $admin = userWithRole(RoleName::Admin);

    resolve(SendTaskDueReminders::class)->handle();
    expect($task->fresh()->due_reminder_sent_at)->not->toBeNull();

    $data = fn (array $o = []): TaskData => new TaskData(...array_merge([
        'name' => $task->name,
        'description' => null,
        'projectId' => $task->project_id,
        'technologyId' => $task->technology_id,
        'status' => TaskStatus::Todo,
        'priority' => $task->priority,
        'estimatedHours' => '8',
        'assigneeId' => $task->assignee_id,
        'dueDate' => Date::parse('2026-06-11'),
    ], $o));

    // The same date and person keep the claim.
    resolve(UpdateTask::class)->handle($admin, $task->fresh(), $data());
    expect($task->fresh()->due_reminder_sent_at)->not->toBeNull();

    // A new date clears it, and the job reminds again.
    resolve(UpdateTask::class)->handle($admin, $task->fresh(), $data(['dueDate' => Date::parse('2026-06-12')]));
    expect($task->fresh()->due_reminder_sent_at)->toBeNull();
    $this->travelTo(Date::parse('2026-06-11 10:00:00'));
    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 1]);

    // A new assignee clears it too.
    $other = userWithRole(RoleName::Employee);
    resolve(AssignTask::class)->handle($admin, $task->fresh(), $other->id);
    expect($task->fresh()->due_reminder_sent_at)->toBeNull();
    expect(resolve(SendTaskDueReminders::class)->handle())->toBe(['reminded' => 1]);
    Notification::assertSentTo($other, TaskDueSoon::class);
})->group('D-81');

it('D-81 carries no rate or amount in the reminder', function (): void {
    $task = dueTask();
    $assignee = $task->assignee;
    $notification = new TaskDueSoon($task);

    $mail = mb_strtolower(json_encode($notification->toMail($assignee)->toArray(), JSON_THROW_ON_ERROR));
    $database = mb_strtolower(json_encode($notification->toDatabase($assignee), JSON_THROW_ON_ERROR));

    expect($notification->via($assignee))->toBe(['mail', 'database'])
        ->and($mail)->not->toContain('rate')->not->toContain('2000')->not->toContain('budget')
        ->and($database)->not->toContain('rate')->not->toContain('2000')->not->toContain('budget');
})->group('D-81');
