<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Actions\CreateDirectDayOff;
use App\Domain\Leave\Actions\RequestLeave;
use App\Domain\Leave\Data\LeaveRequestData;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Work\Support\WorkCalendar;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Support\LeaveCalendar;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\CalendarInput;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
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
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * The Leave Requests list has two views of the same data: the table (the default) and a month calendar of
 * bank holidays and leave (FR-34, D-44). A click on a calendar day, or a drag across days, opens the request
 * form with those dates filled in; saving goes through the same RequestLeave action as the header button.
 * A click on a request the person may open shows it, with the same decision and cancel buttons as the table.
 *
 * @phpstan-import-type CalendarMonth from LeaveCalendar
 */
final class ManageLeaveRequests extends ManageRecords
{
    /** The month on the calendar as `Y-m`. It comes from the browser, so it is only ever read through CalendarInput::parseMonth(). */
    #[Url(as: 'month')]
    public ?string $calendarMonth = null;

    /** Show only the signed-in person's own leave (and their own country's holidays) on the calendar. */
    #[Url(as: 'mine')]
    public bool $onlyMine = false;

    protected static string $resource = LeaveRequestResource::class;

    /** @var array<int, LeaveRequest|null> The requests opened from the calendar during this request, by id. */
    private array $openedRequests = [];

    /** Without bank_holidays.view (the old Leave Calendar's gate) there is no calendar to switch to, so the page shows only the table, as it always did. */
    public function getTabs(): array
    {
        if (! auth()->user()?->can(Permissions::BANK_HOLIDAYS_VIEW)) {
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
            View::make('filament.resources.leave-requests.calendar')->visible(fn (): bool => $this->isCalendarActive()),
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
        return CalendarInput::parseMonth($this->calendarMonth) ?? WorkCalendar::today($this->user())->startOfMonth();
    }

    /** @return CalendarMonth */
    #[Unexposed]
    public function calendar(): array
    {
        return LeaveCalendar::month($this->user(), $this->calendarStart(), $this->onlyMine);
    }

    /**
     * Opened from a calendar day with `mountAction('leaveDetails', {id})`. It stays unavailable for an id that is
     * not a request this person may open: the request is looked up through the same query as the table, never taken
     * from the browser.
     */
    public function leaveDetailsAction(): Action
    {
        return Action::make('leaveDetails')
            ->modalHeading('Leave request')
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(fn (): bool => (bool) auth()->user()?->can('viewAny', LeaveRequest::class))
            ->disabled(fn (array $arguments): bool => ! $this->openedRequest($arguments['id'] ?? null) instanceof LeaveRequest)
            ->record(fn (array $arguments): ?LeaveRequest => $this->openedRequest($arguments['id'] ?? null))
            ->modalContent(fn (LeaveRequest $record): ViewContract => view('filament.resources.leave-requests.details', ['request' => $record]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions(fn (): array => LeaveRequestResource::recordActions(inModal: true));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->requestAction(),
            Action::make('dayOff')->label('Record a day off')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('createForOthers', LeaveRequest::class))
                ->modalDescription('For someone who did not come to work. It is stored as approved and deducted straight away.')
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('user_id')->label('Employee')->required()->searchable()
                        ->options(fn (): array => User::query()->whereNull('anonymized_at')->orderBy('name')->get()
                            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->getFilamentName()])->all()),
                    ...LeaveRequestResource::requestFields(note: true),
                    Toggle::make('override_balance')->label('Allow it to take the balance below zero'),
                ])->columns(2))
                ->action(function (array $data, Action $action): void {
                    try {
                        $employee = User::query()->whereKey($data['user_id'])->firstOrFail();
                        resolve(CreateDirectDayOff::class)->handle(auth()->user(), $employee, $this->input($data), (bool) ($data['override_balance'] ?? false));
                        Notification::make()->title('Day off recorded')->success()->send();
                    } catch (ValidationException $validationException) {
                        ActionErrors::notify($validationException);
                        $action->halt();
                    }
                }),
        ];
    }

    /**
     * Request leave: from the header button the form is empty; from the calendar it opens with `start` and `end`
     * (`Y-m-d`, either way round) filled in. Whatever the dates, the form shows what is worth knowing before
     * saving, and RequestLeave still decides.
     */
    private function requestAction(): Action
    {
        $notices = [];
        $summaries = [];
        $notice = function (Get $get) use (&$notices): string {
            $state = [$get('start_date'), $get('end_date'), $get('start_day_part'), $get('end_day_part')];

            return $notices[serialize($state)] ??= LeaveCalendar::notice($this->user(), ...$state) ?? '';
        };
        $summary = function (Get $get) use (&$summaries): string {
            $state = [$get('leave_type_id'), $get('start_date'), $get('end_date'), $get('start_day_part'), $get('end_day_part')];

            return $summaries[serialize($state)] ??= LeaveCalendar::summary($this->user(), ...$state) ?? '';
        };

        return Action::make('request')->label('Request leave')->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', LeaveRequest::class))
            ->fillForm(function (array $arguments): ?array {
                $start = CalendarInput::parseDate(is_string($arguments['start'] ?? null) ? $arguments['start'] : null);
                $end = CalendarInput::parseDate(is_string($arguments['end'] ?? null) ? $arguments['end'] : null);

                // Not a pair of real dates (or the header button, which has none): the empty form, as it always was.
                if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
                    return null;
                }

                return [
                    'start_date' => min($start, $end)->toDateString(),
                    'end_date' => max($start, $end)->toDateString(),
                    'start_day_part' => DayPart::Full->value,
                    'end_day_part' => DayPart::Full->value,
                ];
            })
            ->schema(fn (Schema $schema): Schema => $schema->components([
                Callout::make('Heads up')->key('dateNotice')->warning()->columnSpanFull()
                    ->description(fn (Get $get): string => $notice($get))
                    ->visible(fn (Get $get): bool => $notice($get) !== ''),
                Callout::make(fn (Get $get): string => $summary($get))->key('daySummary')->info()->columnSpanFull()
                    ->visible(fn (Get $get): bool => $summary($get) !== ''),
                ...LeaveRequestResource::requestFields(live: true),
            ])->columns(2))
            ->action(function (array $data, Action $action): void {
                try {
                    $request = resolve(RequestLeave::class)->handle(auth()->user(), $this->input($data));
                    Notification::make()->title('Leave requested: '.$request->days_requested.' days')->success()->send();
                } catch (ValidationException $validationException) {
                    ActionErrors::notify($validationException);
                    $action->halt();
                }
            });
    }

    /** @param  array<string, mixed>  $data */
    private function input(array $data): LeaveRequestData
    {
        $part = fn (mixed $value): DayPart => $value instanceof DayPart ? $value : DayPart::from((string) $value);

        return new LeaveRequestData(
            (int) $data['leave_type_id'],
            CarbonImmutable::parse((string) $data['start_date']),
            CarbonImmutable::parse((string) $data['end_date']),
            $part($data['start_day_part']),
            $part($data['end_day_part']),
            $data['reason'] ?? null,
        );
    }

    /** The request with that id, when this person may see it (own, a report's for a manager, any for view_any), with what the buttons and the details read. */
    private function openedRequest(mixed $id): ?LeaveRequest
    {
        $id = match (true) {
            is_int($id) => $id,
            is_string($id) && ctype_digit($id) => (int) $id,
            default => 0,
        };

        if ($id < 1) {
            return null;
        }

        if (! array_key_exists($id, $this->openedRequests)) {
            $this->openedRequests[$id] = LeaveRequestResource::visibleTo($this->user(), LeaveRequest::query())
                ->with(['user', 'leaveType', 'approvals.reviewer'])->find($id);
        }

        return $this->openedRequests[$id];
    }

    private function moveCalendar(int $months): void
    {
        $month = $this->calendarStart()->addMonths($months)->format('Y-m');

        // Past the supported range the month stays where it is.
        if (CalendarInput::parseMonth($month) instanceof CarbonImmutable) {
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
