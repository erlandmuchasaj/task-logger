<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Models\ProjectTechnology;
use App\Domain\Exports\Actions\RequestExport;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Capacity check (NFR-08, NFR-09, NFR-10; docs/09-scalability.md section 5)
|--------------------------------------------------------------------------
| Skipped unless RUN_PERFORMANCE_TESTS=1. Volume comes from PERF_LOGS (default 200000; the design ceiling is
| 1900000). Run:  RUN_PERFORMANCE_TESTS=1 PERF_LOGS=500000 php artisan test --filter=CapacityTest
| Timings are for the test environment (debug on, one process), so they are a conservative upper bound.
*/

/** Bulk-inserts $count logs spread over $employees, the projects and their technologies, and the last three years. */
function seedLogs(int $count, array $employeeIds, array $projectTechnologies): void
{
    $now = now()->toDateTimeString();
    $dateSpan = 3 * 365;
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        [$projectId, $technologyId] = $projectTechnologies[array_rand($projectTechnologies)];
        $rows[] = [
            'user_id' => $employeeIds[array_rand($employeeIds)],
            'date' => now()->subDays(random_int(0, $dateSpan))->toDateString(),
            'project_id' => $projectId, 'task_id' => null, 'technology_id' => $technologyId,
            'duration_minutes' => random_int(15, 480), 'log_type' => 'normal_hours', 'description' => 'Worked on '.$i,
            'rate_minor' => 2000, 'rate_currency' => 'EUR', 'created_at' => $now, 'updated_at' => $now,
        ];

        if (count($rows) === 2000) {
            DB::table('logs')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('logs')->insert($rows);
    }
}

/** @return array{ms: float, queries: int, status: int} */
function timeRequest(mixed $test, string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = hrtime(true);
    $response = $test->get($url);
    $ms = (hrtime(true) - $start) / 1e6;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return ['ms' => $ms, 'queries' => $queries, 'status' => $response->getStatusCode()];
}

function p95(array $values): float
{
    sort($values);

    return $values[(int) ceil(0.95 * count($values)) - 1];
}

it('NFR-08 NFR-09 NFR-10 stays inside the targets at volume', function (): void {
    $logs = (int) (getenv('PERF_LOGS') ?: 200_000);

    // 100 employees, 20 clients with 3 projects each, 3 technologies per project (docs/09-scalability.md)
    $employees = User::factory()->count(100)->withRole(RoleName::Employee)->create();
    $projectTechnologies = [];
    foreach (range(1, 20) as $n) {
        [$client, $techs] = clientWithRates(['Angular' => 2000, '.NET' => 2400, 'Ionic' => 1800]);
        foreach (range(1, 3) as $p) {
            $project = Project::factory()->create(['customer_id' => $client->id, 'can_log_directly_to_project' => true]);
            foreach ($techs as $tech) {
                ProjectTechnology::query()->create(['project_id' => $project->id, 'technology_id' => $tech->id, 'estimated_hours' => 100]);
                $projectTechnologies[] = [$project->id, $tech->id];
            }
        }
    }

    $seedStart = hrtime(true);
    seedLogs($logs, $employees->pluck('id')->all(), $projectTechnologies);
    $seedSeconds = (hrtime(true) - $seedStart) / 1e9;

    $me = $employees->first();
    foreach (range(1, 20) as $i) {
        Task::factory()->create([
            'project_id' => $projectTechnologies[$i][0], 'technology_id' => $projectTechnologies[$i][1], 'assignee_id' => $me->id, 'force_to_log' => $i % 4 === 0,
        ]);
    }

    $admin = userWithRole(RoleName::Admin);
    $results = [];

    foreach (['Log list (50 rows)' => [$admin, '/app/logs'], 'My Day' => [$me, '/app/my-day'], 'Estimated vs logged' => [$admin, '/app/estimate-vs-logged'], 'Own log list' => [$me, '/app/logs']] as $label => [$user, $url]) {
        $this->actingAs($user);
        timeRequest($this, $url);   // warm-up: opcache, route and view compilation
        $runs = array_map(fn (): array => timeRequest($this, $url), range(1, 15));

        $results[$label] = [
            'p95_ms' => round(p95(array_column($runs, 'ms'))), 'queries' => max(array_column($runs, 'queries')),
            'ok' => count(array_filter($runs, fn (array $r): bool => $r['status'] === 200)) === 15,
        ];
    }

    // NFR-10: the whole logs export, memory measured from a clean peak
    Storage::fake('local');
    $superadmin = userWithRole(RoleName::Superadmin);
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $exportStart = hrtime(true);
    $export = resolve(RequestExport::class)->handle($superadmin, ExportType::Logs)->fresh();
    $exportSeconds = (hrtime(true) - $exportStart) / 1e9;
    $exportPeakMb = round((memory_get_peak_usage() - $before) / 1048576, 1);
    $rowsExported = mb_substr_count((string) Storage::disk('local')->get($export->file_path), "\n") - 1;

    fwrite(STDOUT, "\n=== Capacity: {$logs} logs, 100 employees, 60 projects (seeded in ".round($seedSeconds, 1)."s) ===\n");
    foreach ($results as $label => $r) {
        fwrite(STDOUT, sprintf("%-22s p95 %4d ms   %2d queries\n", $label, $r['p95_ms'], $r['queries']));
    }

    fwrite(STDOUT, sprintf("%-22s %.1f s   +%.1f MB peak   %d rows\n\n", 'Logs export', $exportSeconds, $exportPeakMb, $rowsExported));

    foreach ($results as $label => $r) {
        expect($r['ok'])->toBeTrue("$label returned a non-200 response")
            ->and($r['p95_ms'])->toBeLessThanOrEqual(500, "$label p95 over 500 ms (NFR-08)")
            ->and($r['queries'])->toBeLessThanOrEqual(15, "$label ran more than 15 queries (NFR-09)");
    }

    expect($rowsExported)->toBe($logs)
        ->and($exportSeconds)->toBeLessThanOrEqual(300, 'export over 5 minutes (NFR-10)')
        ->and($exportPeakMb)->toBeLessThanOrEqual(256, 'export memory over 256 MB (NFR-10)');
})->group('performance')->skip(! getenv('RUN_PERFORMANCE_TESTS'), 'Set RUN_PERFORMANCE_TESTS=1 to run the capacity check.');
