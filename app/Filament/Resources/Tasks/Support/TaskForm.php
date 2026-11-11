<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Support;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Label;
use App\Domain\Shared\Priority;
use App\Domain\Work\Data\TaskData;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** The task form and its mapping to TaskData (W-03 step 1). One assignee (BR-09); technology and parent follow BR-10 and BR-11. */
final class TaskForm
{
    public static function configure(Schema $schema, ?Task $record): Schema
    {
        $creating = $record === null;
        $canAssign = (bool) auth()->user()?->can('assign', $record ?? new Task);

        return $schema->components([
            Section::make('Task')->columns(2)->schema([
                Select::make('project_id')->label('Project')
                    ->options(fn (): array => Project::query()->with('customer')
                        ->when($creating, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('is_active', true)))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required()->live()->disabled(! $creating)->dehydrated()
                    ->afterStateUpdated(fn (callable $set) => $set('technology_id', null))
                    ->helperText($creating ? 'Projects of active clients only.' : 'The project of an existing task cannot change.'),
                Select::make('technology_id')->label('Technology')
                    ->options(fn (callable $get): array => Project::query()->find((int) $get('project_id'))?->technologyLines()->with('technology')->get()
                        ->mapWithKeys(fn ($l): array => [$l->technology_id => $l->technology->name])->all() ?? [])
                    ->searchable()->required()->helperText('One of the project\'s technologies.'),
                TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->columnSpanFull(),
                Select::make('status')->options(TaskStatus::class)->required()->default(TaskStatus::Backlog),
                Select::make('priority')->options(Priority::class)->required()->default(Priority::Medium),
                TextInput::make('estimated_hours')->label('Estimated hours')->numeric()->minValue(0)->maxValue(999999.99)->default(0)->required(),
                DatePicker::make('due_date'),
                Select::make('assignee_id')->label('Assignee')
                    ->options(fn (): array => User::query()->whereNull('banned_at')->whereNull('anonymized_at')->orderBy('name')->get()
                        ->mapWithKeys(fn (User $u): array => [$u->id => $u->getFilamentName()])->all())
                    ->searchable()->disabled(! $canAssign)->dehydrated()
                    ->helperText('One assignee. Leave empty to keep the task in the backlog.'),
                Select::make('parent_id')->label('Parent task')
                    ->options(fn (callable $get): array => Task::query()->where('project_id', (int) $get('project_id'))
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->helperText('Optional, in the same project.'),
                Select::make('labels')->multiple()
                    ->options(fn (): array => Label::query()->orderBy('name')->pluck('name', 'id')->all())->preload(),
                Toggle::make('force_to_log')->label('Pin in the assignee\'s daily log until closed'),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function fill(Task $task): array
    {
        return [
            ...$task->only(['name', 'description', 'project_id', 'technology_id', 'estimated_hours', 'assignee_id', 'parent_id', 'force_to_log']),
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_date' => $task->due_date?->toDateString(),
            'labels' => $task->labels()->pluck('labels.id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function data(array $data): TaskData
    {
        $status = $data['status'] ?? TaskStatus::Backlog;
        $priority = $data['priority'] ?? Priority::Medium;

        return new TaskData(
            name: (string) $data['name'],
            description: $data['description'] ?? null,
            projectId: (int) $data['project_id'],
            technologyId: (int) $data['technology_id'],
            status: $status instanceof TaskStatus ? $status : TaskStatus::from((string) $status),
            priority: $priority instanceof Priority ? $priority : Priority::from((string) $priority),
            estimatedHours: (string) ($data['estimated_hours'] ?? '0'),
            assigneeId: filled($data['assignee_id'] ?? null) ? (int) $data['assignee_id'] : null,
            parentId: filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null,
            forceToLog: (bool) ($data['force_to_log'] ?? false),
            dueDate: filled($data['due_date'] ?? null) ? CarbonImmutable::parse((string) $data['due_date']) : null,
            labelIds: array_map('intval', (array) ($data['labels'] ?? [])),
        );
    }
}
