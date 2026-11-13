<?php

declare(strict_types=1);

namespace App\Filament\Resources\Logs\Pages;

use App\Domain\Exports\Enums\ExportType;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Work\Actions\CreateLog as CreateLogAction;
use App\Domain\Work\Enums\LogType;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Support\TimesheetLocks;
use App\Domain\Work\Support\WorkCalendar;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Logs\Support\LogCalendar;
use App\Filament\Resources\Logs\Support\LogForm;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\ExportAction;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * The Logs list has two views of the same data: the table (the default) and, for people who can see their
 * own logs, a month calendar of their own logs, bank holidays and leave. A click on a calendar day opens
 * the log modal with that date chosen; saving goes through the same CreateLog action as every other screen.
 *
 * @phpstan-import-type CalendarMonth from LogCalendar
 */
final class ListLogs extends ListRecords
{
    /** The month on the calendar as `Y-m`. It comes from the browser, so it is only ever read through LogCalendar::parseMonth(). */
    #[Url(as: 'month')]
    public ?string $calendarMonth = null;

    protected static string $resource = LogResource::class;

    /** Without logs.view_own there is no calendar to switch to, so the page shows only the table, as it always did. */
    public function getTabs(): array
    {
        if (! auth()->user()?->can(Permissions::LOGS_VIEW_OWN)) {
            return [];
        }

        return [
            'table' => Tab::make('Table')->icon(Heroicon::OutlinedTableCells),
            'calendar' => Tab::make('Calendar')->icon(Heroicon::OutlinedCalendarDays),
        ];
    }

    /**
     * Both views are always in the schema and the active tab decides which one renders, so a tab switch
     * never depends on when the schema was built. The hidden table runs no query.
     */
    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        $hideTable = fn (): bool => $this->isCalendarActive();

        return $schema->components([
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE)->hidden($hideTable),
            EmbeddedTable::make()->hidden($hideTable),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER)->hidden($hideTable),
            View::make('filament.resources.logs.calendar')->visible(fn (): bool => $this->isCalendarActive()),
        ]);
    }

    #[Unexposed]
    public function isCalendarActive(): bool
    {
        return $this->activeTab === 'calendar' && array_key_exists('calendar', $this->getCachedTabs());
    }

    public function previousCalendarMonth(): void
    {
        $this->moveCalendar(-1);
    }

    public function nextCalendarMonth(): void
    {
        $this->moveCalendar(1);
    }

    public function currentCalendarMonth(): void
    {
        $this->calendarMonth = null;
    }

    /** The first day of the month on the calendar: the one in the URL when it is valid, otherwise this month in the user's own calendar. */
    #[Unexposed]
    public function calendarStart(): CarbonImmutable
    {
        return LogCalendar::parseMonth($this->calendarMonth) ?? WorkCalendar::today($this->user())->startOfMonth();
    }

    /** @return CalendarMonth */
    #[Unexposed]
    public function calendar(): array
    {
        return LogCalendar::month($this->user(), $this->calendarStart());
    }

    /**
     * Opened from a calendar day with `mountAction('logTime', {date})`. It stays unavailable for a date that
     * is not a real date, is in the future or is in a locked month; the server checks again when saving.
     */
    public function logTimeAction(): Action
    {
        $user = $this->user();
        $notices = [];
        $notice = function (Get $get) use ($user, &$notices): string {
            $state = $get('date');
            $key = is_scalar($state) ? (string) $state : '';

            return $notices[$key] ??= LogCalendar::notice($user, $state) ?? '';
        };

        return Action::make('logTime')
            ->label('Log time')
            ->modalHeading('Log time')
            ->modalSubmitActionLabel('Log time')
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(fn (): bool => $user->can(Permissions::LOGS_CREATE_OWN))
            ->disabled(function (array $arguments) use ($user): bool {
                $date = LogCalendar::parseDate(is_string($arguments['date'] ?? null) ? $arguments['date'] : null);

                return ! $date instanceof CarbonImmutable || ! LogCalendar::canLogOn($user, $date);
            })
            ->fillForm(function (array $arguments) use ($user): array {
                $date = LogCalendar::parseDate(is_string($arguments['date'] ?? null) ? $arguments['date'] : null);

                return $this->blankEntry(($date ?? WorkCalendar::today($user))->toDateString());
            })
            ->schema(function () use ($user, $notice): array {
                $components = LogForm::components($user);
                // Live, so the warning follows the date when it is changed in the modal (the same swap My Day makes).
                $components[0] = DatePicker::make('date')->required()->live()
                    ->maxDate(fn (): CarbonImmutable => WorkCalendar::today($user))
                    ->helperText('Back-dating is allowed until the month is locked.');

                return [
                    Callout::make('Heads up')->key('dateNotice')->warning()
                        ->description(fn (Get $get): string => $notice($get))
                        ->visible(fn (Get $get): bool => $notice($get) !== ''),
                    ...$components,
                ];
            })
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('logAnother', arguments: ['another' => true])->label('Log & add another'),
            ])
            ->action(function (array $data, array $arguments, Action $action, Schema $schema) use ($user): void {
                try {
                    $logData = LogForm::data($data);

                    // CreateLog refuses a locked month with a 403; say so on the date field instead.
                    if (! $user->can('createOn', [Log::class, $logData->date])) {
                        throw ValidationException::withMessages(['date' => TimesheetLocks::isLocked($logData->date)
                            ? $logData->date->format('F Y').' is locked, so time can no longer be logged for it.'
                            : 'You cannot log time for that date.']);
                    }

                    $log = resolve(CreateLogAction::class)->handle($user, $logData);
                } catch (ValidationException $validationException) {
                    ActionErrors::report($this, $validationException, 'mountedActions.0.data.', ['duration' => 'minutes']);
                    $action->halt();

                    return;
                }

                Notification::make()->title('Time logged: '.$log->duration())->success()->send();

                if ($arguments['another'] ?? false) {
                    $type = $data['log_type'] ?? LogType::NormalHours;
                    $schema->fill($this->blankEntry($logData->date->toDateString(), (bool) ($data['direct'] ?? false), $type instanceof LogType ? $type->value : (string) $type));
                    $schema->dispatchClientSideStateReset();
                    $action->halt();
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [ExportAction::make(ExportType::Logs), CreateAction::make()];
    }

    /** @return array<string, mixed> */
    private function blankEntry(string $date, bool $direct = false, string $type = 'normal_hours'): array
    {
        return ['date' => $date, 'direct' => $direct, 'hours' => 0, 'minutes' => 0, 'log_type' => $type];
    }

    private function moveCalendar(int $months): void
    {
        $month = $this->calendarStart()->addMonths($months)->format('Y-m');

        // Past the supported range the month stays where it is.
        if (LogCalendar::parseMonth($month) instanceof CarbonImmutable) {
            $this->calendarMonth = $month;
        }
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
