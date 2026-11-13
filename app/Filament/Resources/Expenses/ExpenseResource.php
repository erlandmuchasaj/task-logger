<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses;

use App\Domain\Expenses\Actions\ChangeExpenseStatus;
use App\Domain\Expenses\Actions\DeleteExpense;
use App\Domain\Expenses\Actions\DownloadReceipt;
use App\Domain\Expenses\Enums\ExpenseCategory;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Support\ActionErrors;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
 * Expenses [OPTIONAL] (D-17, D-51, D-52). Only finance and the superadmin hold expenses.* so nobody else sees the
 * menu. Status buttons run ChangeExpenseStatus; drafts can be edited and deleted, nothing else can.
 */
final class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $slug = 'expenses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee', 'project', 'lines']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('employee.name')->label('Employee')
                    ->formatStateUsing(fn (?string $state, Expense $record): string => $record->employee->getFilamentName())
                    ->searchable(['name' => 'users.name']),
                TextColumn::make('category')->badge(),
                TextColumn::make('description')->limit(40),
                TextColumn::make('total')->label('Total')->state(fn (Expense $record): string => $record->total()?->format() ?? '-'),
                TextColumn::make('status')->badge(),
                TextColumn::make('project.name')->label('Project')->placeholder('-')->toggleable(),
                TextColumn::make('created_at')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ExpenseStatus::class),
                SelectFilter::make('category')->options(ExpenseCategory::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->visible(fn (Expense $record): bool => $record->status->isEditable() && (bool) auth()->user()?->can('update', $record)),
                    self::move('submit', 'Submit', ExpenseStatus::Submitted, Heroicon::OutlinedPaperAirplane, 'warning'),
                    self::move('approve', 'Approve', ExpenseStatus::Approved, Heroicon::OutlinedCheck, 'success'),
                    self::move('reject', 'Reject', ExpenseStatus::Rejected, Heroicon::OutlinedNoSymbol, 'danger', withReason: true),
                    self::move('reimburse', 'Mark reimbursed', ExpenseStatus::Reimbursed, Heroicon::OutlinedBanknotes, 'success'),
                    self::move('reopen', 'Back to draft', ExpenseStatus::Draft, Heroicon::OutlinedArrowUturnLeft, 'gray'),
                    Action::make('receipt')->label('Receipt')->icon(Heroicon::OutlinedPaperClip)->color('gray')
                        ->visible(fn (Expense $record): bool => $record->receipt_path !== null && (bool) auth()->user()?->can('view', $record))
                        ->action(function (Expense $record) {
                            try {
                                return resolve(DownloadReceipt::class)->handle(auth()->user(), $record);
                            } catch (ValidationException $validationException) {
                                ActionErrors::notify($validationException);

                                return null;
                            }
                        }),
                    Action::make('delete')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                        ->visible(fn (Expense $record): bool => $record->status->isEditable() && (bool) auth()->user()?->can('delete', $record))
                        ->action(function (Expense $record): void {
                            try {
                                resolve(DeleteExpense::class)->handle(auth()->user(), $record);
                                Notification::make()->title('Expense deleted')->success()->send();
                            } catch (ValidationException $validationException) {
                                ActionErrors::notify($validationException);
                            }
                        }),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    /** One status button, visible only when the move is allowed from the current status and the viewer holds the permission. */
    private static function move(string $name, string $label, ExpenseStatus $to, Heroicon $icon, string $color, bool $withReason = false): Action
    {
        $action = Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(fn (Expense $record): bool => in_array($to, $record->status->next(), true)
                && (bool) auth()->user()?->can('transition', [$record, $to])
                && (! in_array($to, [ExpenseStatus::Approved, ExpenseStatus::Rejected], true) || $record->user_id !== auth()->id()))
            ->action(function (Expense $record, array $data) use ($to): void {
                try {
                    resolve(ChangeExpenseStatus::class)->handle(auth()->user(), $record, $to, $data['reason'] ?? null);
                    Notification::make()->title('Expense '.mb_strtolower($to->getLabel()))->success()->send();
                } catch (ValidationException $validationException) {
                    ActionErrors::notify($validationException);
                }
            });

        return $withReason
            ? $action->schema([Textarea::make('reason')->label('Why is it rejected?')->required()->rows(3)])
            : $action->requiresConfirmation();
    }
}
