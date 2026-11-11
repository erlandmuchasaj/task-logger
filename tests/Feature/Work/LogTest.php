<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\RecordClientRate;
use App\Domain\Clients\Actions\UpdateClientRate;
use App\Domain\Clients\Actions\UpdateProject;
use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\Technology;
use App\Domain\Work\Actions\CreateLog;
use App\Domain\Work\Actions\DeleteLog;
use App\Domain\Work\Actions\UpdateLog;
use App\Domain\Work\Data\LogData;
use App\Domain\Work\Enums\LogType;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

// ---- BR-02 -----------------------------------------------------------------------------------

it('BR-02 a task log takes project and technology from the task and belongs to the logging user', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();

    $log = app(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));

    expect($log->user_id)->toBe($me->id)
        ->and($log->project_id)->toBe($task->project_id)
        ->and($log->technology_id)->toBe($task->technology_id)
        ->and($log->task_id)->toBe($task->id)
        ->and($log->isDirect())->toBeFalse();
})->group('BR-02');

it('BR-02 a task log needs a task assigned to you', function (string $case) {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $someoneElse = userWithRole(RoleName::Employee);
    $task->update(['assignee_id' => $case === 'unassigned' ? null : $someoneElse->id]);

    app(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));
})->group('BR-02')->throws(ValidationException::class, 'assigned to you')->with(['assigned to someone else' => ['other'], 'unassigned' => ['unassigned'], 'missing' => ['other']]);

it('BR-02 a direct log needs a project that allows it, membership, and one of its technologies', function () {
    ['employee' => $me, 'project' => $project, 'technology' => $tech] = loggingSetup();

    $log = app(CreateLog::class)->handle($me, logData(['projectId' => $project->id, 'technologyId' => $tech->id]));

    expect($log->task_id)->toBeNull()->and($log->isDirect())->toBeTrue()->and($log->project_id)->toBe($project->id)->and($log->technology_id)->toBe($tech->id);
})->group('BR-02');

it('BR-02 a direct log is refused when the project does not allow it', function () {
    ['employee' => $me, 'project' => $project, 'technology' => $tech] = loggingSetup();
    $project->update(['can_log_directly_to_project' => false]);

    app(CreateLog::class)->handle($me, logData(['projectId' => $project->id, 'technologyId' => $tech->id]));
})->group('BR-02')->throws(ValidationException::class, 'allows logging time directly');

it('BR-02 D-12 a direct log is refused for someone who is not a member, even if they have a task there', function () {
    ['project' => $project, 'technology' => $tech] = loggingSetup();
    $outsider = userWithRole(RoleName::Employee);

    app(CreateLog::class)->handle($outsider, logData(['projectId' => $project->id, 'technologyId' => $tech->id]));
})->group('BR-02')->throws(ValidationException::class, 'not a member');

it('BR-02 a direct log needs one of the project technologies', function () {
    ['employee' => $me, 'project' => $project] = loggingSetup();
    $foreign = Technology::factory()->create(['name' => 'Cobol']);

    app(CreateLog::class)->handle($me, logData(['projectId' => $project->id, 'technologyId' => $foreign->id]));
})->group('BR-02')->throws(ValidationException::class, 'project\'s technologies');

it('BR-02 a log must be either a task log or a direct log', function () {
    ['employee' => $me] = loggingSetup();

    app(CreateLog::class)->handle($me, logData(['taskId' => null, 'projectId' => null]));
})->group('BR-02')->throws(ValidationException::class);

it('BR-02 the database refuses a log whose technology is not in its project', function () {
    ['project' => $project] = loggingSetup();
    $foreign = Technology::factory()->create(['name' => 'Cobol']);

    expect(fn () => Log::factory()->create(['project_id' => $project->id, 'technology_id' => $foreign->id]))->toThrow(QueryException::class);
})->group('BR-02');

it('BR-02 a technology used by a log cannot be removed from the project', function () {
    ['employee' => $me, 'project' => $project, 'technology' => $tech, 'client' => $client] = loggingSetup();
    app(CreateLog::class)->handle($me, logData(['projectId' => $project->id, 'technologyId' => $tech->id]));
    $other = ClientTechnologyRate::factory()->create(['customer_id' => $client->id, 'rate_minor' => 1000, 'effective_from' => '2025-01-01']);

    app(UpdateProject::class)->handle(userWithRole(RoleName::Admin), $project, projectData($client, [$other->technology_id => '10'], [
        'managerId' => $project->manager_id, 'startDate' => Carbon::parse('2025-01-01'), 'memberIds' => [$me->id], 'canLogDirectly' => true,
    ]));
})->group('BR-02')->throws(ValidationException::class, 'logged time still use');

// ---- BR-01 / BR-04 / BR-05 -------------------------------------------------------------------

it('BR-01 logs need no approval: no status or approver exists and the log is final when created', function () {
    foreach (['status', 'approved_by', 'approved_at', 'reviewed_by'] as $column) {
        expect(Schema::hasColumn('logs', $column))->toBeFalse($column);
    }
})->group('BR-01');

it('BR-04 hours and minutes become minutes; minutes must be 0 to 59', function () {
    expect(LogData::minutesFrom(2, 30))->toBe(150)->and(LogData::minutesFrom(0, 45))->toBe(45);

    expect(fn () => LogData::minutesFrom(1, 60))->toThrow(ValidationException::class)
        ->and(fn () => LogData::minutesFrom(1, -1))->toThrow(ValidationException::class)
        ->and(fn () => LogData::minutesFrom(-1, 0))->toThrow(ValidationException::class);
})->group('BR-04');

it('BR-04 a log must be longer than zero and not longer than 24 hours', function (int $minutes) {
    ['employee' => $me, 'task' => $task] = loggingSetup();

    app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'minutes' => $minutes]));
})->group('BR-04')->throws(ValidationException::class)->with([0, -5, 1441]);

it('BR-04 one person can log at most 24 hours on one date, across all their logs', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $log = fn (int $minutes, string $date = '2026-03-10') => app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'minutes' => $minutes, 'date' => Carbon::parse($date)]));

    $log(23 * 60);
    expect(fn () => $log(61))->toThrow(ValidationException::class, 'more than 24 hours');

    $log(60);                                      // exactly 24h is fine
    expect(fn () => $log(1))->toThrow(ValidationException::class);
    $log(8 * 60, '2026-03-11');                    // another date
    expect((int) Log::where('user_id', $me->id)->sum('duration_minutes'))->toBe(24 * 60 + 8 * 60);
})->group('BR-04');

it('BR-04 the 24 hour cap is per person', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $other = userWithRole(RoleName::Employee);
    $task2 = Task::factory()->create(['project_id' => $task->project_id, 'technology_id' => $task->technology_id, 'assignee_id' => $other->id]);

    app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'minutes' => 24 * 60]));
    $log = app(CreateLog::class)->handle($other, logData(['taskId' => $task2->id, 'minutes' => 24 * 60]));

    expect($log->exists)->toBeTrue();
})->group('BR-04');

it('BR-04 editing a log does not count it twice against the cap', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $log = app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'minutes' => 20 * 60]));

    $updated = app(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'minutes' => 24 * 60]));

    expect($updated->duration_minutes)->toBe(24 * 60);
})->group('BR-04');

it('BR-05 every log type is accepted', function (LogType $type) {
    ['employee' => $me, 'task' => $task] = loggingSetup();

    expect(app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'type' => $type, 'minutes' => 30]))->log_type)->toBe($type);
})->group('BR-05')->with(LogType::cases());

it('BR-05 the log types are exactly the four in the spec', function () {
    expect(array_map(fn (LogType $t) => $t->value, LogType::cases()))->toBe(['normal_hours', 'extra_hours', 'replacement_hours', 'others']);
})->group('BR-05');

// ---- BR-06 -----------------------------------------------------------------------------------

it('BR-06 the log copies the client rate effective on the log date, with the client currency', function () {
    ['employee' => $me, 'task' => $task, 'client' => $client, 'technology' => $tech] = loggingSetup(2000);
    ClientTechnologyRate::factory()->create(['customer_id' => $client->id, 'technology_id' => $tech->id, 'rate_minor' => 2500, 'effective_from' => '2026-03-01']);
    $log = fn (string $date) => app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'minutes' => 60, 'date' => Carbon::parse($date)]));

    expect($log('2026-02-28')->rate_minor)->toBe(2000)
        ->and($log('2026-03-01')->rate_minor)->toBe(2500)
        ->and($log('2026-03-15')->rate_currency)->toBe('EUR');
})->group('BR-06');

it('BR-06 a later rate change never alters existing logs', function () {
    ['employee' => $me, 'task' => $task, 'client' => $client, 'technology' => $tech] = loggingSetup(2000);
    $log = app(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));

    app(RecordClientRate::class)->handle(userWithRole(RoleName::Admin), $client, $tech->id, 9999, Carbon::parse('2026-01-01'));
    app(UpdateClientRate::class)->handle(userWithRole(RoleName::Admin), $client->rates()->where('effective_from', '2025-01-01')->sole(), 7777, Carbon::parse('2025-01-01'));

    expect($log->fresh()->rate_minor)->toBe(2000);
})->group('BR-06');

it('BR-06 with no rate on the log date, creation is blocked with a clear message', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup(2000, '2026-01-01');

    expect(fn () => app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Carbon::parse('2025-12-31')])))
        ->toThrow(ValidationException::class, 'no rate for Angular on Dec 31, 2025');

    expect(Log::count())->toBe(0);
})->group('BR-06');

it('BR-06 editing keeps the snapshot unless the date, project or technology changes', function () {
    ['employee' => $me, 'task' => $task, 'client' => $client, 'technology' => $tech] = loggingSetup(2000);
    $log = app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'minutes' => 60, 'date' => Carbon::parse('2026-02-10')]));
    ClientTechnologyRate::factory()->create(['customer_id' => $client->id, 'technology_id' => $tech->id, 'rate_minor' => 3000, 'effective_from' => '2026-03-01']);

    $edited = app(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'minutes' => 90, 'date' => Carbon::parse('2026-02-10')]));
    expect($edited->rate_minor)->toBe(2000);

    $moved = app(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'minutes' => 90, 'date' => Carbon::parse('2026-03-10')]));
    expect($moved->rate_minor)->toBe(3000);
})->group('BR-06');

it('BR-06 BR-12 the log value is hours times the snapshot rate in integer minor units, half up', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup(2000);

    $log = app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'minutes' => 150])); // 2.5h x 20.00

    expect($log->amount()->minor)->toBe(5000)->and($log->amount()->currency)->toBe('EUR')->and($log->rate()->minor)->toBe(2000);

    $odd = Log::factory()->make(['rate_minor' => 333, 'duration_minutes' => 30, 'rate_currency' => 'EUR']); // 166.5 -> 167
    expect($odd->amount()->minor)->toBe(167);
})->group('BR-06', 'BR-12');

// ---- dates, clients, permissions -------------------------------------------------------------

it('BR-34 a log cannot be dated in the future, judged in the user\'s own timezone', function () {
    $this->travelTo(Carbon::parse('2026-03-10 23:30:00', 'UTC'));
    $albania = Country::factory()->albania()->create();           // Europe/Tirane, UTC+1: it is already 11 March there
    ['employee' => $inTirana, 'task' => $task] = loggingSetup(2000, '2025-01-01', userWithRole(RoleName::Employee));
    $inTirana->update(['country_id' => $albania->id]);
    $utcUser = userWithRole(RoleName::Employee);
    $task2 = Task::factory()->create(['project_id' => $task->project_id, 'technology_id' => $task->technology_id, 'assignee_id' => $utcUser->id]);
    $on11th = fn (User $u, Task $t) => app(CreateLog::class)->handle($u, logData(['taskId' => $t->id, 'date' => Carbon::parse('2026-03-11')]));

    expect($on11th($inTirana, $task)->exists)->toBeTrue()
        ->and(fn () => $on11th($utcUser, $task2))->toThrow(ValidationException::class, 'future date');
})->group('BR-34');

it('D-11 back-dating is allowed while the month is open', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();

    expect(app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Carbon::parse('2025-06-01')]))->exists)->toBeTrue();
})->group('BR-06');

it('BR-31 a deactivated client gets no new logs, but existing logs stay editable', function () {
    ['employee' => $me, 'task' => $task, 'client' => $client] = loggingSetup();
    $log = app(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));
    $client->update(['is_active' => false]);

    expect(fn () => app(CreateLog::class)->handle($me, logData(['taskId' => $task->id, 'date' => Carbon::parse('2026-03-11')])))->toThrow(ValidationException::class, 'deactivated');

    expect(app(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'minutes' => 30]))->duration_minutes)->toBe(30);
})->group('BR-31');

it('D-18 a client with logged work keeps its currency', function () {
    ['employee' => $me, 'task' => $task, 'client' => $client] = loggingSetup();
    $client->rates()->delete();   // no rates left, but logged work remains below
    expect($client->fresh()->currencyIsLocked())->toBeFalse();

    ClientTechnologyRate::factory()->create(['customer_id' => $client->id, 'technology_id' => $task->technology_id, 'rate_minor' => 2000, 'effective_from' => '2025-01-01']);
    app(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));
    $client->rates()->delete();

    expect($client->fresh()->currencyIsLocked())->toBeTrue();
})->group('D-18');

it('FR-24 everyone can log their own time; creation is a permission, not a role', function (RoleName $role) {
    $user = userWithRole($role);
    ['task' => $task] = loggingSetup(employee: $user);

    expect(app(CreateLog::class)->handle($user, logData(['taskId' => $task->id]))->user_id)->toBe($user->id);
})->group('BR-02')->with(RoleName::cases());

it('FR-26 you can edit and delete your own log in an open month, but not someone else\'s', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $other = userWithRole(RoleName::Employee);
    $log = app(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));

    expect(app(UpdateLog::class)->handle($me, $log, logData(['taskId' => $task->id, 'minutes' => 45]))->duration_minutes)->toBe(45)
        ->and(fn () => app(UpdateLog::class)->handle($other, $log, logData(['taskId' => $task->id])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteLog::class)->handle($other, $log))->toThrow(AuthorizationException::class);

    app(DeleteLog::class)->handle($me, $log);
    expect(Log::find($log->id))->toBeNull()->and(Log::withTrashed()->find($log->id))->not->toBeNull();
})->group('BR-02');

it('D-39 even an admin cannot edit another person\'s log in an open month', function () {
    ['employee' => $me, 'task' => $task] = loggingSetup();
    $log = app(CreateLog::class)->handle($me, logData(['taskId' => $task->id]));

    expect(fn () => app(UpdateLog::class)->handle(userWithRole(RoleName::Admin), $log, logData(['taskId' => $task->id])))->toThrow(AuthorizationException::class);
});
