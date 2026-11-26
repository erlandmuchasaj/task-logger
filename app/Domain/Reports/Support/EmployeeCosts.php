<?php

declare(strict_types=1);

namespace App\Domain\Reports\Support;

use App\Domain\MasterData\Models\Country;
use App\Domain\People\Models\Salary;
use Illuminate\Support\Facades\DB;

/**
 * D-91: what the logged time of a project cost in salaries. Each person-day of logs costs the monthly salary in
 * force on that day times the minutes worked, divided by the working minutes of that month in the person's country,
 * rounded half up. A day with no salary on record costs nothing. All integer arithmetic (BR-12).
 */
final readonly class EmployeeCosts
{
    private WorkingMinutes $workingMinutes;

    public function __construct(?WorkingMinutes $workingMinutes = null)
    {
        $this->workingMinutes = $workingMinutes ?? new WorkingMinutes;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{minor: int, currencies: list<string>}> by project id
     */
    public function forProjects(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $days = DB::table('logs')
            ->join('users', 'users.id', '=', 'logs.user_id')
            ->whereNull('logs.deleted_at')
            ->whereIn('logs.project_id', $projectIds)
            ->groupBy('logs.project_id', 'logs.user_id', 'logs.date', 'users.country_id')
            ->get(['logs.project_id', 'logs.user_id', 'logs.date', 'users.country_id', DB::raw('SUM(logs.duration_minutes) as minutes')]);

        $salaries = Salary::query()->whereIn('user_id', $days->pluck('user_id')->unique()->all())->oldest('effective_date')->oldest('id')->get()->groupBy('user_id');
        $countries = Country::query()->whereIn('id', $days->pluck('country_id')->filter()->unique()->all())->get()->keyBy('id');

        $costs = [];

        foreach ($days as $day) {
            $date = mb_substr((string) $day->date, 0, 10);
            $salary = $salaries->get($day->user_id)?->last(fn (Salary $row): bool => $row->effective_date->toDateString() <= $date);

            if ($salary === null) {
                continue;
            }

            $month = $this->workingMinutes->forMonth($countries->get($day->country_id), mb_substr($date, 0, 7));
            $costs[$day->project_id]['minor'] = ($costs[$day->project_id]['minor'] ?? 0) + intdiv(2 * $salary->amount_minor * (int) $day->minutes + $month, 2 * $month);
            $costs[$day->project_id]['currencies'][$salary->currency_code] = $salary->currency_code;
        }

        return array_map(fn (array $cost): array => ['minor' => $cost['minor'], 'currencies' => array_values($cost['currencies'])], $costs);
    }
}
