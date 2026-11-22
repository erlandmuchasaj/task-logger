<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Permissions;
use App\Domain\Shared\Priority;
use App\Domain\Work\Actions\CreateTask;
use App\Domain\Work\Actions\UpdateTask;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use App\Filament\Resources\Tasks\Support\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\ActionErrors;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * D-80: the tasks of a project, listed, added and edited in place. The rows are the ones the Tasks screen shows the
 * same person (tasks.view_any, otherwise only their own), and add and edit go through CreateTask and UpdateTask.
 */
final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewAny', Task::class);
    }

    /** Filament makes relation managers read-only on View pages; the Task policy decides what is offered instead. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Project $project */
        $project = $this->getOwnerRecord();
        $record = $schema->getRecord();

        return TaskForm::configure($schema, $record instanceof Task ? $record : null, $project);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $user = auth()->user();

                return $query->with(['technology', 'assignee'])
                    ->unless($user?->can(Permissions::TASKS_VIEW_ANY), fn (Builder $query) => $query->where('assignee_id', $user?->getKey()));
            })
            ->recordUrl(fn (Task $record): ?string => auth()->user()?->can('view', $record) ? TaskResource::getUrl('view', ['record' => $record]) : null)
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->limit(50),
                TextColumn::make('technology.name')->label('Technology'),
                TextColumn::make('assignee.name')->label('Assignee')->placeholder('Unassigned')
                    ->formatStateUsing(fn (?string $state, Task $record): ?string => $record->assignee?->getFilamentName()),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('priority')->badge()->sortable(),
                TextColumn::make('due_date')->date()->sortable()
                    ->color(fn (Task $record): ?string => $record->due_date && $record->due_date->isPast() && ! $record->status->isClosed() ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(TaskStatus::class),
                SelectFilter::make('priority')->options(Priority::class),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                CreateAction::make()->label('New task')->modalHeading('New task')->modalWidth(Width::FourExtraLarge)
                    ->visible(fn (): bool => $this->getOwnerRecord() instanceof Project && $this->getOwnerRecord()->customer->is_active)
                    ->using(function (array $data): Model {
                        /** @var Project $project */
                        $project = $this->getOwnerRecord();

                        try {
                            $task = resolve(CreateTask::class)->handle(auth()->user(), TaskForm::data([...$data, 'project_id' => $project->getKey()]));
                            TaskForm::warnIfOverEstimate($task);

                            return $task;
                        } catch (ValidationException $validationException) {
                            ActionErrors::report($this, $validationException, 'mountedActions.0.data.');
                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->url(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
                        ->visible(fn (Task $record): bool => (bool) auth()->user()?->can('view', $record)),
                    EditAction::make()->modalWidth(Width::FourExtraLarge)
                        ->mutateRecordDataUsing(fn (array $data, Task $record): array => TaskForm::fill($record))
                        ->using(function (Task $record, array $data): Model {
                            try {
                                $task = resolve(UpdateTask::class)->handle(auth()->user(), $record, TaskForm::data($data));
                                TaskForm::warnIfOverEstimate($task);

                                return $task;
                            } catch (ValidationException $validationException) {
                                ActionErrors::report($this, $validationException, 'mountedActions.0.data.');
                                throw new Halt;
                            }
                        }),
                ]),
            ]);
    }
}
