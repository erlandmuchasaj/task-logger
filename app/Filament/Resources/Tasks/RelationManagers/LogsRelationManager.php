<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Domain\Identity\Permissions;
use App\Domain\Work\Actions\CreateLog;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Support\WorkCalendar;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Logs\Support\LogForm;
use App\Filament\Support\ActionErrors;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * D-80: the logs of a task. The rows are the ones the Logs screen shows the same person (logs.view_any, otherwise only
 * their own), and rate and amount need clients.view_rates (D-05). "Log time" adds a log for the person who clicks,
 * only on a task assigned to them that is still open, through CreateLog (24-hour cap, locked months, rate snapshot).
 */
final class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Logs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewAny', Log::class);
    }

    /** Filament makes relation managers read-only on View pages; the Log policy decides what is offered instead. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(LogForm::taskLogComponents(auth()->user()));
    }

    public function table(Table $table): Table
    {
        $seesAll = fn (): bool => (bool) auth()->user()?->can(Permissions::LOGS_VIEW_ANY);
        $seesRates = fn (): bool => (bool) auth()->user()?->can(Permissions::CLIENTS_VIEW_RATES);

        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $user = auth()->user();

                return $query->with('user')
                    ->unless($user?->can(Permissions::LOGS_VIEW_ANY), fn (Builder $query) => $query->where('user_id', $user?->getKey()));
            })
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('user.name')->label('Employee')->visible($seesAll)
                    ->formatStateUsing(fn (?string $state, Log $record): string => $record->user->getFilamentName()),
                TextColumn::make('duration_minutes')->label('Time')->sortable()
                    ->formatStateUsing(fn (mixed $state, Log $record): string => $record->duration()),
                TextColumn::make('log_type')->label('Type')->badge(),
                TextColumn::make('description')->limit(40)->placeholder('—'),
                TextColumn::make('rate_minor')->label('Rate/h')->visible($seesRates)
                    ->formatStateUsing(fn (mixed $state, Log $record): string => $record->rate()->format()),
                TextColumn::make('amount')->label('Amount')->visible($seesRates)
                    ->state(fn (Log $record): string => $record->amount()->format()),
            ])
            ->defaultSort('date', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->headerActions([
                CreateAction::make()->label('Log time')->modalHeading('Log time on this task')
                    ->visible(fn (): bool => $this->canLogTime())
                    ->using(function (array $data): Model {
                        /** @var Task $task */
                        $task = $this->getOwnerRecord();

                        try {
                            return resolve(CreateLog::class)->handle(auth()->user(), LogForm::data([...$data, 'direct' => false, 'task_id' => $task->getKey()]));
                        } catch (ValidationException $validationException) {
                            ActionErrors::report($this, $validationException, 'mountedActions.0.data.', ['duration' => 'minutes']);
                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                        ->visible(fn (Log $record): bool => (bool) auth()->user()?->can('update', $record))
                        ->url(fn (Log $record): string => LogResource::getUrl('edit', ['record' => $record])),
                ]),
            ]);
    }

    /** The person adds time to their own open task only; CreateLog repeats this and the date rules. */
    private function canLogTime(): bool
    {
        $user = auth()->user();
        $task = $this->getOwnerRecord();

        return $user !== null
            && $task instanceof Task
            && $task->assignee_id === $user->getKey()
            && ! in_array($task->status, [TaskStatus::Completed, TaskStatus::Cancelled], true)
            && $user->can('createOn', [Log::class, WorkCalendar::today($user)]);
    }
}
