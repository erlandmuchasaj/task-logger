<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Work\Actions\CreateLog;
use App\Domain\Work\Actions\DeleteLog;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Support\LogChoices;
use App\Domain\Work\Support\WorkCalendar;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Logs\Support\LogForm;
use App\Filament\Support\ActionErrors;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * W-03 step 2 and 3, W-04: the daily log form. Tasks with force_to_log are pinned here every day until
 * they are completed or cancelled (BR-08); submitting the day is never blocked. The checkbox switches
 * between your tasks and the projects you may log to directly (BR-03).
 */
final class MyDay extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected string $view = 'filament.pages.my-day';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'My Day';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permissions::LOGS_CREATE_OWN);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->getSchema('form')?->fill(['date' => $this->today()->toDateString(), 'hours' => 0, 'minutes' => 0, 'log_type' => 'normal_hours']);
    }

    public function form(Schema $schema): Schema
    {
        $components = LogForm::components($this->user());
        $components[0] = DatePicker::make('date')->required()->live()
            ->maxDate(fn (): CarbonImmutable => $this->today())
            ->helperText('Back-dating is allowed until the month is locked.');

        return $schema->components($components)->statePath('data');
    }

    public function save(): void
    {
        $state = $this->getSchema('form')?->getState() ?? [];

        try {
            $log = resolve(CreateLog::class)->handle($this->user(), LogForm::data($state));
        } catch (ValidationException $validationException) {
            ActionErrors::report($this, $validationException, aliases: ['duration' => 'minutes']);

            return;
        }

        $this->getSchema('form')?->fill(['date' => $log->date->toDateString(), 'direct' => $state['direct'] ?? false, 'hours' => 0, 'minutes' => 0, 'log_type' => $log->log_type->value]);
        Notification::make()->title('Time logged: '.$log->duration())->success()->send();
    }

    /** Prefill the form from a pinned task (BR-08). */
    public function useTask(int $taskId): void
    {
        $task = LogChoices::pinnedTasks($this->user())->firstWhere('id', $taskId);

        if ($task instanceof Task) {
            $this->data = [...($this->data ?? []), 'direct' => false, 'task_id' => $task->id, 'project_id' => null, 'technology_id' => null];
        }
    }

    /** @return Collection<int, Task> */
    public function pinnedTasks(): Collection
    {
        return LogChoices::pinnedTasks($this->user());
    }

    public function dayTotalMinutes(): int
    {
        return (int) Log::query()->where('user_id', $this->user()->getKey())->whereDate('date', $this->selectedDate())->sum('duration_minutes');
    }

    public function selectedDate(): string
    {
        return (string) ($this->data['date'] ?? $this->today()->toDateString());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Log::query()->with(['project', 'task', 'technology'])->where('user_id', $this->user()->getKey())->whereDate('date', $this->selectedDate()))
            ->heading('Logged on this day')
            ->columns([
                TextColumn::make('project.name')->label('Project'),
                TextColumn::make('task.name')->label('Task')->placeholder('Direct')->limit(40),
                TextColumn::make('technology.name')->label('Technology'),
                TextColumn::make('duration_minutes')->label('Time')->formatStateUsing(fn (mixed $state, Log $record): string => $record->duration()),
                TextColumn::make('log_type')->label('Type')->badge(),
                TextColumn::make('description')->limit(40),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit')->icon(Heroicon::OutlinedPencilSquare)
                        ->visible(fn (Log $record): bool => (bool) auth()->user()?->can('update', $record))
                        ->url(fn (Log $record): string => LogResource::getUrl('edit', ['record' => $record])),
                    Action::make('delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                        ->visible(fn (Log $record): bool => (bool) auth()->user()?->can('delete', $record))
                        ->requiresConfirmation()
                        ->action(function (Log $record): void {
                            resolve(DeleteLog::class)->handle($this->user(), $record);
                            Notification::make()->title('Log deleted')->success()->send();
                        }),
                ]),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing logged for this day yet');
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function today(): CarbonImmutable
    {
        return WorkCalendar::today($this->user());
    }
}
