<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Widgets;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\Salary;
use App\Domain\Shared\Money;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Headline numbers above the employee list. Anonymised accounts are left out of every figure.
 * The average salary is shown only to holders of salaries.view, and only over the current salaries
 * of active people in one currency (amounts in different currencies are never averaged together).
 */
class EmployeeStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    /** @return array<string, int> */
    protected function getColumns(): int|array|null
    {
        return ['md' => 3, 'xl' => count($this->getCachedStats())];
    }

    protected function getStats(): array
    {
        $total = $this->people()->count();
        $active = $this->people()->whereNull('banned_at')->count();

        $stats = [
            Stat::make('Total employees', number_format($total))
                ->description('Excludes anonymised accounts'),
            Stat::make('Active employees', number_format($active))
                ->description(number_format($total - $active).' deactivated')
                ->color('success'),
        ];

        if (auth()->user()?->can(Permissions::SALARIES_VIEW)) {
            $stats[] = $this->averageSalary();
        }

        $stats[] = $this->averageTenure();
        $stats[] = Stat::make('New this year', number_format(
            $this->people()->whereNull('banned_at')->whereYear('start_date', now()->year)->whereDate('start_date', '<=', now())->count()
        ))->description('Started since '.now()->startOfYear()->format('M j'));

        return $stats;
    }

    /** @return Builder<User> */
    private function people(): Builder
    {
        return User::query()->whereNull('anonymized_at');
    }

    private function averageSalary(): Stat
    {
        $byCurrency = Salary::query()
            ->where('status', SalaryStatus::Active)
            ->whereIn('user_id', $this->people()->whereNull('banned_at')->select('id'))
            ->groupBy('currency_code')
            ->selectRaw('currency_code, count(*) as people, avg(amount_minor) as average')
            ->orderByDesc('people')
            ->get();

        $main = $byCurrency->first();

        if ($main === null) {
            return Stat::make('Avg salary', '—')->description('No current salaries recorded');
        }

        $others = $byCurrency->count() - 1;
        $people = (int) $main->getAttribute('people');
        $description = number_format($people).' '.str('person')->plural($people).' paid in '.$main->getAttribute('currency_code')
            .($others > 0 ? ", {$others} other ".str('currency')->plural($others).' not included' : '');

        return Stat::make(
            'Avg salary',
            Money::of((int) round((float) $main->getAttribute('average')), (string) $main->getAttribute('currency_code'))->format(),
        )->description($description);
    }

    private function averageTenure(): Stat
    {
        $today = now()->startOfDay();
        $starts = $this->people()->whereNull('banned_at')->whereNotNull('start_date')
            ->whereDate('start_date', '<=', $today)->pluck('start_date');

        if ($starts->isEmpty()) {
            return Stat::make('Avg tenure', '—')->description('No start dates recorded');
        }

        $years = $starts->avg(fn (CarbonInterface $start): float => $start->diffInDays($today) / 365.25);

        return Stat::make('Avg tenure', number_format((float) $years, 1).' years')
            ->description('Active people with a start date');
    }
}
