<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Support\ClientRates;
use App\Domain\Identity\Models\User;
use App\Domain\Work\Data\LogData;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Support\WorkCalendar;
use Illuminate\Validation\ValidationException;

trait ValidatesLog
{
    /**
     * BR-02: a task log or a direct log. Returns the project, the task and the technology to store.
     * Rules are checked for the owner of the log, who is the actor when creating.
     *
     * @return array{project: Project, task: ?Task, technology_id: int}
     *
     * @throws ValidationException
     */
    private function resolveTarget(User $owner, LogData $data): array
    {
        if ($data->taskId !== null) {
            $task = Task::query()->with('project.customer')->find($data->taskId);

            // (a) a task log: the task is assigned to the logging user; project and technology come from it.
            if ($task === null || $task->assignee_id !== $owner->getKey()) {
                throw ValidationException::withMessages(['task_id' => 'You can only log time on tasks assigned to you.']);
            }

            return ['project' => $task->project, 'task' => $task, 'technology_id' => $task->technology_id];
        }

        // (b) a direct log: the project allows it, the user is a member, and the technology is the project's.
        $project = $data->projectId !== null ? Project::query()->with('customer')->find($data->projectId) : null;

        if ($project === null || ! $project->can_log_directly_to_project) {
            throw ValidationException::withMessages(['project_id' => 'Choose a project that allows logging time directly.']);
        }

        if (! $project->memberships()->where('user_id', $owner->getKey())->exists()) {
            throw ValidationException::withMessages(['project_id' => 'You are not a member of this project.']);
        }

        if ($data->technologyId === null || ! $project->technologyLines()->where('technology_id', $data->technologyId)->exists()) {
            throw ValidationException::withMessages(['technology_id' => 'Choose one of the project\'s technologies.']);
        }

        return ['project' => $project, 'task' => null, 'technology_id' => $data->technologyId];
    }

    /** @throws ValidationException */
    private function validateBasics(User $owner, LogData $data, Project $project, ?Log $existing): void
    {
        $errors = [];

        // BR-04: greater than zero; a person cannot log more than 24 hours on one date.
        $max = (int) config('task-logger.max_log_minutes_per_day');
        if ($data->minutes <= 0) {
            $errors['duration'] = 'The time must be greater than zero.';
        } elseif ($data->minutes > $max) {
            $errors['duration'] = 'A single log cannot be longer than 24 hours.';
        } else {
            $others = Log::query()->where('user_id', $owner->getKey())->whereDate('date', $data->date->toDateString())
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->getKey()))->sum('duration_minutes');

            if ($others + $data->minutes > $max) {
                $errors['duration'] = 'That would be more than 24 hours logged on '.$data->date->toFormattedDateString().' ('.intdiv((int) $others, 60).'h '.((int) $others % 60).'m already logged).';
            }
        }

        // No logging for a date that has not happened yet (the user's own calendar day) [ASSUMED D-39].
        if ($data->date->toDateString() > WorkCalendar::today($owner)->toDateString()) {
            $errors['date'] = 'You cannot log time for a future date.';
        }

        // BR-31: a deactivated client gets no new logs. Editing an existing log is still fine.
        if ($existing === null && ! $project->customer->is_active) {
            $errors['project_id'] = 'This project\'s client is deactivated and cannot receive new logs.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * BR-06: the client's rate for the technology on the log date, copied onto the log.
     *
     * @return array{rate_minor: int, rate_currency: string}
     *
     * @throws ValidationException
     */
    private function snapshotRate(Project $project, int $technologyId, LogData $data): array
    {
        $rate = ClientRates::forDate($project->customer_id, $technologyId, $data->date);

        if ($rate === null) {
            $technology = $project->technologyLines()->with('technology')->where('technology_id', $technologyId)->first()?->technology->name ?? 'this technology';

            throw ValidationException::withMessages([
                'date' => "The client has no rate for {$technology} on {$data->date->toFormattedDateString()}, so this time cannot be logged. Ask an admin to add a rate effective on or before that date.",
            ]);
        }

        return ['rate_minor' => $rate->rate_minor, 'rate_currency' => $project->customer->currency_code];
    }
}
