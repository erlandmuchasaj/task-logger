<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Customer;
use App\Domain\Exports\Actions\PruneExports;
use App\Domain\Exports\Actions\RequestExport;
use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Jobs\RunExport;
use App\Domain\Exports\Models\Export;
use App\Domain\Exports\Support\CsvSafe;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\Work\Actions\CreateLog;
use App\Domain\Work\Models\Log;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Filament\Resources\Logs\Pages\ListLogs;
use App\Filament\Resources\Projects\Pages\ListProjects;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => Storage::fake('local'));

/** @return array<int, array<string, string>> rows keyed by the CSV heading */
function csvRows(Export $export): array
{
    $text = Storage::disk('local')->get($export->file_path);
    expect(substr($text, 0, 3))->toBe("\xEF\xBB\xBF"); // UTF-8 byte order mark for Excel

    $lines = array_map(fn (string $l) => str_getcsv($l, ',', '"', ''), array_filter(preg_split('/\r?\n/', substr($text, 3))));
    $headings = array_shift($lines);

    return array_map(fn (array $row) => array_combine($headings, $row), $lines);
}

function exportNow(User $actor, ExportType $type, array $filters = []): Export
{
    $export = app(RequestExport::class)->handle($actor, $type, $filters);

    return $export->fresh();
}

function twoLogs(): array
{
    $setup = loggingSetup(2000);
    $me = $setup['employee'];
    $me->update(['name' => 'Elira', 'surname' => 'Çela']);
    $a = app(CreateLog::class)->handle($me, logData(['taskId' => $setup['task']->id, 'date' => Carbon::parse('2026-03-10'), 'minutes' => 150, 'description' => 'Login page']));
    $b = app(CreateLog::class)->handle($me, logData(['taskId' => null, 'projectId' => $setup['project']->id, 'technologyId' => $setup['technology']->id, 'date' => Carbon::parse('2026-04-02'), 'minutes' => 45, 'description' => '=HYPERLINK("http://evil.test")']));

    return [...$setup, 'a' => $a, 'b' => $b];
}

it('BR-33 the superadmin exports all logs; the file has every column including rate and amount', function () {
    twoLogs();

    $export = exportNow(userWithRole(RoleName::Superadmin), ExportType::Logs);
    $rows = csvRows($export);

    expect($export->status)->toBe(ExportStatus::Completed)->and($export->rows)->toBe(2)->and($rows)->toHaveCount(2)
        ->and(array_keys($rows[0]))->toContain('Hourly rate', 'Currency', 'Amount', 'Employee', 'Client', 'Project', 'Task', 'Technology', 'Hours', 'Duration (h:mm)', 'Type');

    expect($rows[0])->toMatchArray([
        'Date' => '2026-03-10', 'Employee' => 'Elira Çela', 'Hours' => '2.50', 'Duration (h:mm)' => '2:30', 'Minutes' => '150',
        'Type' => 'normal_hours', 'Hourly rate' => '20.00', 'Currency' => 'EUR', 'Amount' => '50.00',
    ]);
})->group('BR-33');

it('BR-33 D-05 the assistant exports logs without any rate or amount column', function () {
    twoLogs();

    $headings = array_keys(csvRows(exportNow(userWithRole(RoleName::Assistant), ExportType::Logs))[0]);

    expect($headings)->not->toContain('Hourly rate')->not->toContain('Currency')->not->toContain('Amount')->toContain('Employee', 'Hours');
})->group('BR-33');

it('BR-33 only superadmin and assistant may export logs, projects, clients and tasks', function (RoleName $role, bool $allowed) {
    foreach (ExportType::cases() as $type) {
        $request = fn () => app(RequestExport::class)->handle(userWithRole($role), $type);

        $allowed ? expect($request()->exists)->toBeTrue() : expect($request)->toThrow(AuthorizationException::class);
    }
})->group('BR-33')->with([
    [RoleName::Superadmin, true], [RoleName::Assistant, true], [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Employee, false],
]);

it('BR-33 exports are queued, not built in the request', function () {
    Queue::fake();
    twoLogs();

    $export = app(RequestExport::class)->handle(userWithRole(RoleName::Superadmin), ExportType::Logs);

    Queue::assertPushed(RunExport::class, fn (RunExport $job) => $job->exportId === $export->id);
    expect($export->fresh()->status)->toBe(ExportStatus::Queued)->and($export->file_path)->toBeNull();
})->group('BR-33');

it('BR-33 permissions are checked again when the job runs', function () {
    Queue::fake();
    twoLogs();
    $assistant = userWithRole(RoleName::Assistant);
    $export = app(RequestExport::class)->handle($assistant, ExportType::Logs);

    $assistant->syncRoles([RoleName::Employee->value]); // loses logs.export before the worker runs
    app(Dispatcher::class);
    (new RunExport($export->id))->handle();

    expect($export->fresh()->status)->toBe(ExportStatus::Failed)->and($export->fresh()->error)->toContain('no longer allowed')->and($export->fresh()->file_path)->toBeNull();
})->group('BR-33');

it('BR-33 a deactivated requester gets no file', function () {
    Queue::fake();
    twoLogs();
    $superadmin = userWithRole(RoleName::Superadmin);
    $export = app(RequestExport::class)->handle($superadmin, ExportType::Logs);
    $superadmin->ban();

    (new RunExport($export->id))->handle();

    expect($export->fresh()->status)->toBe(ExportStatus::Failed);
})->group('BR-33');

it('BR-33 the job is idempotent: a finished export is not rebuilt', function () {
    twoLogs();
    $export = exportNow(userWithRole(RoleName::Superadmin), ExportType::Logs);
    $path = $export->file_path;

    (new RunExport($export->id))->handle();

    expect($export->fresh()->file_path)->toBe($path)->and(Storage::disk('local')->allFiles('exports'))->toHaveCount(1);
})->group('BR-33');

it('BR-33 filters: a date range and a project narrow the logs', function () {
    ['a' => $a, 'project' => $project] = twoLogs();
    $superadmin = userWithRole(RoleName::Superadmin);

    $march = csvRows(exportNow($superadmin, ExportType::Logs, ['date_from' => '2026-03-01', 'date_to' => '2026-03-31']));
    $none = exportNow($superadmin, ExportType::Logs, ['project_id' => 999999]);
    $project = csvRows(exportNow($superadmin, ExportType::Logs, ['project_id' => $project->id]));

    expect($march)->toHaveCount(1)->and($march[0]['Date'])->toBe('2026-03-10')
        ->and($none->rows)->toBe(0)
        ->and($project)->toHaveCount(2)
        ->and($a->exists)->toBeTrue();
})->group('BR-33');

it('BR-33 a deleted log is not exported', function () {
    ['a' => $a] = twoLogs();
    $a->delete();

    expect(exportNow(userWithRole(RoleName::Superadmin), ExportType::Logs)->rows)->toBe(1);
})->group('BR-33');

it('BR-33 cells that would be read as spreadsheet formulas are neutralised', function () {
    twoLogs();

    $rows = csvRows(exportNow(userWithRole(RoleName::Superadmin), ExportType::Logs));
    $formula = collect($rows)->firstWhere('Date', '2026-04-02');

    expect($formula['Description'])->toStartWith("'=HYPERLINK")
        ->and(CsvSafe::cell('+1 555'))->toBe("'+1 555")
        ->and(CsvSafe::cell('@cmd'))->toBe("'@cmd")
        ->and(CsvSafe::cell('-5 days'))->toBe("'-5 days")
        ->and(CsvSafe::cell('Normal text'))->toBe('Normal text')
        ->and(CsvSafe::cell(42))->toBe('42')
        ->and(CsvSafe::cell(null))->toBe('');
})->group('BR-33');

it('BR-33 a large export streams in chunks without losing rows', function () {
    ['employee' => $me, 'project' => $project, 'technology' => $tech] = loggingSetup();
    Log::factory()->count(1200)->sequence(fn ($s) => ['date' => Carbon::parse('2026-01-01')->addDays($s->index % 28)->toDateString()])->create([
        'user_id' => $me->id, 'project_id' => $project->id, 'technology_id' => $tech->id, 'duration_minutes' => 30,
    ]);

    $export = exportNow(userWithRole(RoleName::Superadmin), ExportType::Logs);

    expect($export->rows)->toBe(1200)->and(csvRows($export))->toHaveCount(1200);
})->group('BR-33');

it('BR-33 D-05 the projects export has the budget only for people who may see rates', function () {
    ['project' => $project] = loggingSetup(2000);
    $project->update(['name' => 'Visible Project']);

    $assistant = array_keys(csvRows(exportNow(userWithRole(RoleName::Assistant), ExportType::Projects))[0]);
    $superadmin = csvRows(exportNow(userWithRole(RoleName::Superadmin), ExportType::Projects))[0];

    expect($assistant)->not->toContain('Budget')->not->toContain('Currency')->toContain('Project', 'Client', 'Estimated hours')
        ->and($superadmin)->toHaveKeys(['Budget', 'Currency'])->and($superadmin['Budget'])->toBe('2000.00');
})->group('BR-33');

it('BR-33 D-05 the clients export has no payment plan or currency for the assistant', function () {
    $client = Customer::factory()->create(['company_name' => 'Acme', 'payment_plan_id' => PaymentPlan::factory()->create(['name' => 'Net30Secret'])->id]);

    $assistant = csvRows(exportNow(userWithRole(RoleName::Assistant), ExportType::Clients));
    $superadmin = csvRows(exportNow(userWithRole(RoleName::Superadmin), ExportType::Clients));

    expect(array_keys($assistant[0]))->not->toContain('Payment plan', 'Currency')
        ->and(json_encode($assistant))->not->toContain('Net30Secret')
        ->and($superadmin[0]['Payment plan'])->toBe('Net30Secret')
        ->and($client->exists)->toBeTrue();
})->group('BR-33');

it('BR-33 the tasks export lists tasks with their labels and assignee', function () {
    ['task' => $task] = loggingSetup();
    $task->labels()->attach(Label::factory()->create(['name' => 'Bug']));

    $rows = csvRows(exportNow(userWithRole(RoleName::Assistant), ExportType::Tasks));

    expect($rows)->toHaveCount(1)->and($rows[0]['Labels'])->toBe('Bug')->and($rows[0]['Assignee'])->not->toBe('');
})->group('BR-33');

it('BR-33 D-38 the finished export is audited with the original request context', function () {
    twoLogs();
    $admin = userWithRole(RoleName::Admin);
    $assistant = userWithRole(RoleName::Assistant);
    $this->actingAs($assistant);
    asRequest(ip: '198.51.100.77', agent: 'ExportBrowser/2', impersonatedBy: $admin->id);
    Queue::fake();
    $export = app(RequestExport::class)->handle($assistant, ExportType::Logs);

    asRequest(ip: '203.0.113.1', agent: 'Worker/1');   // a later, different request context (the queue worker)
    (new RunExport($export->id))->handle();

    $entry = Activity::where('event', 'exported')->where('subject_id', $export->id)->sole();
    expect($entry->causer_id)->toBe($assistant->id)
        ->and($entry->client_ip)->toBe('198.51.100.77')
        ->and($entry->user_agent)->toBe('ExportBrowser/2')
        ->and($entry->impersonator_id)->toBe($admin->id)
        ->and($entry->properties['type'])->toBe('logs')->and($entry->properties['rows'])->toBe(2);
})->group('BR-32', 'BR-33');

it('BR-33 you can download your own completed export and nobody else\'s', function () {
    twoLogs();
    $owner = userWithRole(RoleName::Superadmin);
    $export = exportNow($owner, ExportType::Logs);
    $other = userWithRole(RoleName::Superadmin);

    expect($owner->can('download', $export))->toBeTrue()
        ->and($other->can('download', $export))->toBeFalse()
        ->and($other->can('view', $export))->toBeFalse();

    $this->actingAs($owner);
    Livewire::test(ListExports::class)->assertCanSeeTableRecords([$export])->callTableAction('download', $export)->assertFileDownloaded();

    $this->actingAs($other);
    Livewire::test(ListExports::class)->assertCanNotSeeTableRecords([$export]);
})->group('BR-33');

it('BR-33 an assistant who loses the right can no longer download an old export', function () {
    twoLogs();
    $assistant = userWithRole(RoleName::Assistant);
    $export = exportNow($assistant, ExportType::Logs);

    $assistant->syncRoles([RoleName::Employee->value]);
    $assistant->forgetCachedPermissions();

    expect($assistant->fresh()->can('download', $export))->toBeFalse();
})->group('BR-33');

it('BR-33 files expire: the daily job deletes them and marks the export expired, idempotently', function () {
    twoLogs();
    $export = exportNow(userWithRole(RoleName::Superadmin), ExportType::Logs);
    $path = $export->file_path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->travel(config('task-logger.exports.keep_days') + 1)->days();

    expect($export->fresh()->isDownloadable())->toBeFalse()
        ->and(app(PruneExports::class)->handle())->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($export->fresh()->status)->toBe(ExportStatus::Expired)
        ->and(app(PruneExports::class)->handle())->toBe(0);
})->group('BR-33');

it('BR-33 the export and prune jobs are scheduled', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description);

    expect($events)->toContain('exports.prune');
})->group('BR-33');

it('BR-33 the Export button shows on the list screens only for people who may export', function (RoleName $role, bool $sees) {
    $this->actingAs(userWithRole($role));

    foreach ([ListLogs::class, ListProjects::class] as $page) {
        $component = Livewire::test($page);
        $sees ? $component->assertActionVisible('export') : $component->assertActionHidden('export');
    }
})->group('BR-33')->with([
    [RoleName::Superadmin, true], [RoleName::Assistant, true], [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Employee, false],
]);

it('BR-33 the Export button queues an export for the chosen dates', function () {
    twoLogs();
    $this->actingAs(userWithRole(RoleName::Superadmin));

    Livewire::test(ListLogs::class)->callAction('export', ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'])->assertHasNoActionErrors();

    $export = Export::sole();
    expect($export->filters)->toEqual(['date_from' => '2026-04-01', 'date_to' => '2026-04-30'])->and($export->rows)->toBe(1);
})->group('BR-33');

it('BR-33 the Exports screen is for people who can export; the file never sits in a public path', function () {
    $this->actingAs(userWithRole(RoleName::Employee))->get('/app/exports')->assertForbidden();
    $this->actingAs(userWithRole(RoleName::Assistant))->get('/app/exports')->assertOk();

    twoLogs();
    $export = exportNow(userWithRole(RoleName::Superadmin), ExportType::Logs);
    expect($export->file_path)->toStartWith('exports/')->and(config('task-logger.exports.disk'))->toBe('local')
        ->and(config('filesystems.disks.local.root'))->not->toContain('public');
})->group('BR-33');
