<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Actions\CancelLeave;
use App\Domain\Leave\Actions\DecideLeave;
use App\Domain\Leave\Enums\ApprovalDecision;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\LeaveType;
use App\Filament\Resources\LeaveRequests\Pages\ManageLeaveRequests;
use App\Filament\Support\ActionErrors;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
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
 * Leave requests (W-05). People see their own; leave_requests.view_any (superadmin, hr) sees all; a direct
 * manager also sees their reports' requests so the manager stage can work (D-28). The row actions run the
 * two approval stages (D-07) and cancellation; the header actions request leave and record a day off.
 */
class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static ?string $slug = 'leave-requests';

    protected static ?string $modelLabel = 'leave request';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 20;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return static::visibleTo(auth()->user(), parent::getEloquentQuery())->with(['user', 'leaveType', 'approvals']);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function visibleTo(?User $user, Builder $query): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can(Permissions::LEAVE_REQUESTS_VIEW_ANY)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->when($user->can(Permissions::LEAVE_REQUESTS_VIEW_OWN), fn (Builder $q) => $q->where('user_id', $user->getKey()))
                ->when(
                    $user->can(Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS),
                    fn (Builder $q) => $q->orWhereHas('user', fn (Builder $u) => $u->where('manager_id', $user->getKey())),
                );
        });
    }

    public static function canCreate(): bool
    {
        return false; // requests are made with the header actions
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    /** @return list<Component> */
    public static function requestFields(bool $note = false): array
    {
        return [
            Select::make('leave_type_id')->label('Type')->required()
                ->options(fn (): array => LeaveType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                    ->map(fn (string $name): string => ucfirst(str_replace('_', ' ', $name)))->all()),
            DatePicker::make('start_date')->required()->native(false),
            Select::make('start_day_part')->label('First day')->options(DayPart::class)->default(DayPart::Full->value)->required()->native(false),
            DatePicker::make('end_date')->required()->native(false)->afterOrEqual('start_date'),
            Select::make('end_day_part')->label('Last day')->options(DayPart::class)->default(DayPart::Full->value)->required()->native(false)
                ->helperText('Ignored for a single-day request.'),
            Textarea::make('reason')->label($note ? 'Note' : 'Reason')->required($note)->rows(3)->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        $seesOthers = fn (): bool => (bool) (auth()->user()?->can(Permissions::LEAVE_REQUESTS_VIEW_ANY) || auth()->user()?->can(Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS));

        return $table
            ->columns([
                TextColumn::make('user.name')->label('Employee')->visible($seesOthers)
                    ->formatStateUsing(fn (?string $state, LeaveRequest $record): string => $record->user->getFilamentName())
                    ->searchable(['name' => 'users.name']),
                TextColumn::make('leaveType.name')->label('Type')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                TextColumn::make('period')->label('Dates')->state(fn (LeaveRequest $record): string => $record->start_date->isSameDay($record->end_date)
                    ? $record->start_date->format('d M Y').self::part($record->start_day_part)
                    : $record->start_date->format('d M Y').self::part($record->start_day_part).' to '.$record->end_date->format('d M Y').self::part($record->end_day_part)),
                TextColumn::make('days_requested')->label('Days'),
                TextColumn::make('status')->badge()->color(fn (LeaveStatus $state): string => match ($state) {
                    LeaveStatus::Pending => 'warning',
                    LeaveStatus::Approved, LeaveStatus::Taken => 'success',
                    LeaveStatus::Rejected => 'danger',
                    LeaveStatus::Cancelled => 'gray',
                }),
                TextColumn::make('stages')->label('Approvals')->state(fn (LeaveRequest $record): string => collect(ApprovalStage::cases())
                    ->map(fn (ApprovalStage $stage): string => $stage->getLabel().': '.($record->stageDecision($stage)?->decision->getLabel() ?? (($stage === ApprovalStage::Manager && $record->user->manager_id === null) ? 'n/a' : 'waiting')))
                    ->implode(' / ')),
                TextColumn::make('reason')->limit(40)->toggleable(),
                TextColumn::make('review_note')->label('Decision note')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(LeaveStatus::class),
                SelectFilter::make('user_id')->label('Employee')->relationship('user', 'name')->visible($seesOthers),
            ])
            ->recordActions([
                self::decision('managerApprove', 'Approve (manager)', ApprovalStage::Manager, ApprovalDecision::Approved),
                self::decision('managerReject', 'Reject (manager)', ApprovalStage::Manager, ApprovalDecision::Rejected),
                self::decision('hrApprove', 'Approve (HR)', ApprovalStage::Hr, ApprovalDecision::Approved),
                self::decision('hrReject', 'Reject (HR)', ApprovalStage::Hr, ApprovalDecision::Rejected),
                Action::make('cancel')->icon(Heroicon::OutlinedXMark)->color('gray')->requiresConfirmation()
                    ->visible(fn (LeaveRequest $record): bool => in_array($record->status, [LeaveStatus::Pending, LeaveStatus::Approved], true)
                        && (bool) auth()->user()?->can('cancel', $record))
                    ->action(function (LeaveRequest $record): void {
                        try {
                            app(CancelLeave::class)->handle(auth()->user(), $record);
                            Notification::make()->title('Leave cancelled')->success()->send();
                        } catch (ValidationException $e) {
                            ActionErrors::notify($e);
                        }
                    }),
            ])
            ->defaultSort('start_date', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    private static function part(DayPart $part): string
    {
        return $part === DayPart::Full ? '' : ' ('.$part->value.')';
    }

    /** One approval-stage button. Visible only when the stage is open and the viewer may decide it (D-07, BR-20). */
    private static function decision(string $name, string $label, ApprovalStage $stage, ApprovalDecision $decision): Action
    {
        $approving = $decision === ApprovalDecision::Approved;

        $action = Action::make($name)->label($label)
            ->icon($approving ? Heroicon::OutlinedCheck : Heroicon::OutlinedNoSymbol)
            ->color($approving ? 'success' : 'danger')
            ->visible(function (LeaveRequest $record) use ($stage): bool {
                $user = auth()->user();
                $managerDone = $record->stageDecision(ApprovalStage::Manager)?->decision === ApprovalDecision::Approved;

                return $user !== null
                    && $record->status === LeaveStatus::Pending
                    && $record->user_id !== $user->getKey()
                    && $record->stageDecision($stage) === null
                    && ($stage === ApprovalStage::Manager ? $record->user->manager_id !== null : ($managerDone || $record->user->manager_id === null))
                    && $user->can($stage === ApprovalStage::Manager ? 'approveManagerStage' : 'approveHrStage', $record);
            })
            ->action(function (LeaveRequest $record, array $data) use ($stage, $decision): void {
                try {
                    app(DecideLeave::class)->handle(auth()->user(), $record, $stage, $decision, $data['note'] ?? null);
                    Notification::make()->title($decision === ApprovalDecision::Approved ? 'Decision recorded' : 'Request rejected')->success()->send();
                } catch (ValidationException $e) {
                    ActionErrors::notify($e);
                }
            });

        return $approving
            ? $action->requiresConfirmation()
            : $action->schema([Textarea::make('note')->label('Why is it rejected?')->required()->rows(3)]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLeaveRequests::route('/')];
    }
}
