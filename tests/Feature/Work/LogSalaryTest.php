<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\Salary;
use App\Domain\Work\Actions\CreateLog;
use App\Domain\Work\Actions\UpdateLog;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Filament\Pages\MyDay;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** loggingSetup() gives the employee a salary from 2000; these tests arrange their own, so start with none. */
function loggingSetupWithoutSalary(): array
{
    $setup = loggingSetup();
    DB::table('salaries')->where('user_id', $setup['employee']->id)->delete();

    return $setup;
}

function recordSalaryFor(int $userId, string $effectiveDate, SalaryStatus $status = SalaryStatus::Active): Salary
{
    return Salary::factory()->create(['user_id' => $userId, 'created_by' => $userId, 'effective_date' => $effectiveDate, 'status' => $status]);
}

it('D-92 an employee with no salary on record cannot log time', function (): void {
    ['employee' => $me, 'task' => $task] = loggingSetupWithoutSalary();

    try {
        resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('date')
            ->and($validationException->errors()['date'][0])->toContain('no salary on record', 'Ask HR');

        expect(Log::query()->count())->toBe(0);

        return;
    }

    $this->fail('The log was saved without a salary on record.');
})->group('D-92');

it('D-92 the salary must be effective on or before the log date, in any status', function (): void {
    ['employee' => $me, 'task' => $task] = loggingSetupWithoutSalary();
    recordSalaryFor($me->id, '2026-03-11');

    expect(fn () => resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-03-10')])))
        ->toThrow(ValidationException::class, 'no salary on record');

    recordSalaryFor($me->id, '2026-03-10', SalaryStatus::Pending);

    expect(resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-03-10')]))->exists)->toBeTrue();
})->group('D-92');

it('D-92 binds the superadmin too, because the rule is in the Action', function (): void {
    ['project' => $project, 'task' => $task] = loggingSetupWithoutSalary();
    $root = userWithRole(RoleName::Superadmin);
    $rootTask = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $task->technology_id, 'assignee_id' => $root->id]);

    expect(fn () => resolve(CreateLog::class)->handle($root, logData(['taskId' => $rootTask->id])))
        ->toThrow(ValidationException::class, 'no salary on record');

    recordSalaryFor($root->id, '2026-01-01');

    expect(resolve(CreateLog::class)->handle($root, logData(['taskId' => $rootTask->id]))->exists)->toBeTrue();
})->group('D-92');

it('D-92 moving a log to a date without a salary is refused, but correcting its hours is not', function (): void {
    ['employee' => $me, 'task' => $task] = loggingSetupWithoutSalary();
    recordSalaryFor($me->id, '2026-03-01');
    $log = resolve(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Date::parse('2026-03-10')]));

    expect(fn () => resolve(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'date' => Date::parse('2026-02-20')])))
        ->toThrow(ValidationException::class, 'no salary on record');

    // An old log on a day that lost its salary (a correction moved the date, D-83) can still be fixed in place.
    DB::table('salaries')->where('user_id', $me->id)->delete();
    $updated = resolve(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'date' => Date::parse('2026-03-10'), 'minutes' => 45]));

    expect($updated->duration_minutes)->toBe(45);
})->group('D-92');

it('D-92 My Day shows the refusal on the date field', function (): void {
    ['employee' => $me, 'task' => $task] = loggingSetupWithoutSalary();
    $this->actingAs($me);

    Livewire::test(MyDay::class)
        ->fillForm(['task_id' => $task->id, 'hours' => 1, 'minutes' => 0])
        ->call('save')
        ->assertHasFormErrors(['date']);

    expect(Log::query()->count())->toBe(0);
})->group('D-92');
