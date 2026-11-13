<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Clients\Actions\ComputeProjectBudget;
use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Models\ProjectMember;
use App\Domain\Clients\Models\ProjectTechnology;
use App\Domain\Clients\Support\ClientRates;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\Shared\Priority;
use App\Domain\Work\Enums\LogType;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for local development only: employees, customers with rates, projects with technologies and
 * members, tasks and a couple of months of logs. Rows are written through factories (not the actions) for
 * speed, so the seeder keeps the key rules itself: members only, the technology belongs to the project
 * (BR-02/BR-10), weekdays only, at most 8h a day, and the rate snapshot taken on the log date (BR-06).
 * Skipped when any customer already exists, so re-seeding never duplicates.
 */
class LocalDemoSeeder extends Seeder
{
    private const int EMPLOYEES = 12;

    private const int EXTRA_MANAGERS = 2;

    private const int CUSTOMERS = 6;

    private const int LOG_WEEKDAYS = 40;

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        if (Customer::query()->exists()) {
            $this->command?->info('LocalDemoSeeder skipped: demo data already present.');

            return;
        }

        $technologies = Technology::query()->get();
        $country = Country::query()->where('code', 'AL')->first() ?? Country::query()->first();

        if ($technologies->count() < 4 || $country === null) {
            $this->command?->warn('LocalDemoSeeder skipped: run CountrySeeder and TechnologySeeder first.');

            return;
        }

        DB::transaction(function () use ($technologies, $country): void {
            $creator = User::query()->where('email', 'admin@test.com')->first()
                ?? User::factory()->withRole(RoleName::Admin)->create(['country_id' => $country->id]);

            $employees = $this->employees($country);
            $managers = $this->managers($country)->push($creator);

            $projects = new EloquentCollection;

            foreach (range(1, self::CUSTOMERS) as $i) {
                $customer = Customer::factory()->create([
                    'created_by' => $creator->id,
                    'country_id' => $country->id,
                    'priority' => fake()->randomElement(Priority::cases()),
                    'currency_code' => fake()->randomElement(['EUR', 'EUR', 'USD']),
                ]);

                $customerTechnologies = $technologies->random(fake()->numberBetween(3, 4));

                foreach ($customerTechnologies as $technology) {
                    ClientTechnologyRate::query()->create([
                        'customer_id' => $customer->id,
                        'technology_id' => $technology->id,
                        'rate_minor' => fake()->numberBetween(20, 60) * 100,
                        'effective_from' => '2026-01-01',
                    ]);
                }

                foreach (range(1, fake()->numberBetween(1, 3)) as $_) {
                    $projects->push($this->project($customer, $creator, $managers, $customerTechnologies, $employees));
                }
            }

            $this->logs($projects);

            $budget = app(ComputeProjectBudget::class);
            $projects->each(fn (Project $project) => $budget->handle($project->refresh()));
        });

        $this->command?->info(sprintf(
            'LocalDemoSeeder: %d customers, %d projects, %d tasks, %d logs.',
            Customer::query()->count(), Project::query()->count(), Task::query()->count(), Log::query()->count(),
        ));
    }

    /** @return Collection<int, User> */
    private function employees(Country $country): Collection
    {
        return User::factory()
            ->count(self::EMPLOYEES)
            ->withRole(RoleName::Employee)
            ->create(['country_id' => $country->id, 'start_date' => '2025-06-01'])
            ->each(fn (User $user) => EmployeeProfile::factory()->create(['user_id' => $user->id]));
    }

    /**
     * Admins who manage projects (BR-30).
     *
     * @return Collection<int, User>
     */
    private function managers(Country $country): Collection
    {
        return User::factory()
            ->count(self::EXTRA_MANAGERS)
            ->withRole(RoleName::Admin)
            ->create(['country_id' => $country->id]);
    }

    /**
     * @param  Collection<int, User>  $managers
     * @param  Collection<int, Technology>  $technologies  the customer's rated technologies
     * @param  Collection<int, User>  $employees
     */
    private function project(Customer $customer, User $creator, Collection $managers, Collection $technologies, Collection $employees): Project
    {
        $status = fake()->randomElement([
            ProjectStatus::Active, ProjectStatus::Active, ProjectStatus::Active,
            ProjectStatus::Planning, ProjectStatus::OnHold, ProjectStatus::Completed,
        ]);

        $project = Project::factory()->create([
            'created_by' => $creator->id,
            'customer_id' => $customer->id,
            'manager_id' => $managers->random()->id,
            'name' => fake()->unique()->company().' '.fake()->randomElement(['Portal', 'App', 'Platform', 'CRM', 'Website', 'API']),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'can_log_directly_to_project' => fake()->boolean(40),
            'status' => $status,
            'color' => fake()->hexColor(),
        ]);

        $projectTechnologies = $technologies->random(fake()->numberBetween(2, $technologies->count()));

        foreach ($projectTechnologies as $technology) {
            ProjectTechnology::query()->create([
                'project_id' => $project->id,
                'technology_id' => $technology->id,
                'estimated_hours' => fake()->numberBetween(4, 40) * 10,
            ]);
        }

        $members = $employees->random(fake()->numberBetween(3, 5));

        foreach ($members as $member) {
            ProjectMember::query()->create(['project_id' => $project->id, 'user_id' => $member->id]);
        }

        foreach (range(1, fake()->numberBetween(5, 10)) as $_) {
            $task = $this->task($project, $creator, $projectTechnologies->random(), $members);

            if (fake()->boolean(25)) {
                foreach (range(1, fake()->numberBetween(1, 2)) as $__) {
                    $this->task($project, $creator, Technology::query()->find($task->technology_id), $members, $task);
                }
            }
        }

        return $project;
    }

    /** @param  Collection<int, User>  $members */
    private function task(Project $project, User $creator, Technology $technology, Collection $members, ?Task $parent = null): Task
    {
        return Task::factory()->create([
            'created_by' => $creator->id,
            'name' => rtrim(fake()->sentence(fake()->numberBetween(3, 6)), '.'),
            'project_id' => $project->id,
            'technology_id' => $technology->id,
            'parent_id' => $parent?->id,
            'assignee_id' => fake()->boolean(85) ? $members->random()->id : null,
            'status' => fake()->randomElement(TaskStatus::cases()),
            'priority' => fake()->randomElement(Priority::cases()),
            'estimated_hours' => fake()->numberBetween(1, 24),
            'due_date' => fake()->boolean(60) ? fake()->dateTimeBetween('-1 month', '+2 months')->format('Y-m-d') : null,
        ]);
    }

    /**
     * About LOG_WEEKDAYS weekdays of logs for every member of an active or completed project.
     *
     * @param  EloquentCollection<int, Project>  $projects
     */
    private function logs(EloquentCollection $projects): void
    {
        $loggable = $projects
            ->filter(fn (Project $p) => in_array($p->status, [ProjectStatus::Active, ProjectStatus::Completed], true))
            ->load(['customer', 'tasks', 'members'])
            ->keyBy('id');

        $projectsByUser = [];
        foreach ($loggable as $project) {
            foreach ($project->members as $member) {
                $projectsByUser[$member->id][] = $project->id;
            }
        }

        $rateCache = [];
        $days = $this->weekdays(self::LOG_WEEKDAYS);

        foreach ($projectsByUser as $userId => $projectIds) {
            foreach ($days as $day) {
                if (fake()->boolean(10)) {
                    continue; // a day off now and then
                }

                foreach ($this->splitDay(fake()->numberBetween(24, 32) * 15) as $minutes) {
                    $project = $loggable[fake()->randomElement($projectIds)];
                    $tasks = $project->tasks;
                    $mine = $tasks->where('assignee_id', $userId);
                    $direct = $project->can_log_directly_to_project && fake()->boolean(20);
                    $task = $direct ? null : ($mine->isNotEmpty() ? $mine->random() : $tasks->random());
                    $technologyId = $task->technology_id
                        ?? ProjectTechnology::query()->where('project_id', $project->id)->inRandomOrder()->value('technology_id');

                    $key = "{$project->customer_id}:{$technologyId}";
                    $rateCache[$key] ??= ClientRates::forDate($project->customer_id, $technologyId, $day)->rate_minor ?? 0;

                    Log::factory()->create([
                        'user_id' => $userId,
                        'date' => $day->toDateString(),
                        'project_id' => $project->id,
                        'task_id' => $task?->id,
                        'technology_id' => $technologyId,
                        'duration_minutes' => $minutes,
                        'log_type' => fake()->randomElement([
                            LogType::NormalHours, LogType::NormalHours, LogType::NormalHours, LogType::NormalHours,
                            LogType::ExtraHours, LogType::Others,
                        ]),
                        'description' => fake()->sentence(),
                        'rate_minor' => $rateCache[$key],
                        'rate_currency' => $project->customer->currency_code,
                    ]);
                }
            }
        }
    }

    /**
     * The last $count weekdays before today.
     *
     * @return list<CarbonImmutable>
     */
    private function weekdays(int $count): array
    {
        $days = [];
        $day = CarbonImmutable::today();

        while (count($days) < $count) {
            $day = $day->subDay();
            if ($day->isWeekday()) {
                $days[] = $day;
            }
        }

        return array_reverse($days);
    }

    /**
     * Splits a day's minutes into 1-3 chunks of whole quarter hours.
     *
     * @return list<int>
     */
    private function splitDay(int $total): array
    {
        $parts = fake()->numberBetween(1, 3);
        $chunks = [];

        for ($i = $parts; $i > 1; $i--) {
            $chunk = fake()->numberBetween(2, intdiv($total, 15) - 2 * ($i - 1)) * 15;
            $chunks[] = $chunk;
            $total -= $chunk;
        }

        $chunks[] = $total;

        return $chunks;
    }
}
