<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks;

use App\Domain\Identity\Permissions;
use App\Domain\Shared\Priority;
use App\Domain\Work\Actions\ChangeTaskStatus;
use App\Domain\Work\Actions\DeleteTask;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Support\ActionErrors;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Tasks. People with tasks.view_any (superadmin, admin, assistant) see all tasks; everyone else sees only
 * the tasks assigned to them (D-05, BR-09). The assignee changes the status of their own tasks (BR-35).
 */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $slug = 'tasks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['project', 'technology', 'assignee', 'labels'])
            ->when(! $user?->can(Permissions::TASKS_VIEW_ANY), fn (Builder $query) => $query->where('assignee_id', $user?->getKey()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Task')->columns(3)->schema([
                TextEntry::make('name')->columnSpan(2),
                TextEntry::make('status')->badge(),
                TextEntry::make('project.name')->label('Project'),
                TextEntry::make('technology.name')->label('Technology'),
                TextEntry::make('priority')->badge(),
                TextEntry::make('assignee.name')->label('Assignee')->placeholder('Unassigned')
                    ->formatStateUsing(fn (?string $state, Task $record): ?string => $record->assignee?->getFilamentName()),
                TextEntry::make('estimated_hours')->label('Estimated hours'),
                TextEntry::make('due_date')->date()->placeholder('-'),
                TextEntry::make('labels_list')->label('Labels')->badge()->placeholder('-')
                    ->state(fn (Task $record): array => $record->labels->pluck('name')->all()),
                TextEntry::make('force_to_log')->label('Pinned in daily log')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                TextEntry::make('description')->placeholder('-')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->limit(50),
                TextColumn::make('project.name')->label('Project')->sortable(),
                TextColumn::make('technology.name')->label('Technology'),
                TextColumn::make('assignee.name')->label('Assignee')->placeholder('Unassigned')
                    ->formatStateUsing(fn (?string $state, Task $record): ?string => $record->assignee?->getFilamentName()),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('priority')->badge()->sortable(),
                TextColumn::make('due_date')->date()->sortable()
                    ->color(fn (Task $record): ?string => $record->due_date && $record->due_date->isPast() && ! $record->status->isClosed() ? 'danger' : null),
                TextColumn::make('labels_list')->label('Labels')->badge()->state(fn (Task $record): array => $record->labels->pluck('name')->all()),
            ])
            ->filters([
                SelectFilter::make('status')->options(TaskStatus::class),
                SelectFilter::make('priority')->options(Priority::class),
                SelectFilter::make('project')->relationship('project', 'name'),
                SelectFilter::make('assignee')->relationship('assignee', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('changeStatus')->label('Change status')->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Task $record): bool => (bool) auth()->user()?->can('changeStatus', $record))
                    ->schema([Select::make('status')->options(TaskStatus::class)->required()])
                    ->fillForm(fn (Task $record): array => ['status' => $record->status->value])
                    ->action(function (Task $record, array $data): void {
                        app(ChangeTaskStatus::class)->handle(auth()->user(), $record, $data['status'] instanceof TaskStatus ? $data['status'] : TaskStatus::from((string) $data['status']));
                        Notification::make()->title('Status changed')->success()->send();
                    }),
                Action::make('delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (Task $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->requiresConfirmation()
                    ->action(function (Task $record): void {
                        try {
                            app(DeleteTask::class)->handle(auth()->user(), $record);
                            Notification::make()->title('Task deleted')->success()->send();
                        } catch (ValidationException $e) {
                            ActionErrors::notify($e);
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'view' => ViewTask::route('/{record}'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }
}
