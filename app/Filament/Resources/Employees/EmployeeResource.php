<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees;

use App\Domain\Identity\Actions\StartImpersonation;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\DeactivateEmployee;
use App\Domain\People\Actions\ReactivateEmployee;
use App\Domain\People\Actions\ResetTwoFactor;
use App\Domain\People\Actions\SendSetPasswordLink;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\RelationManagers\SalariesRelationManager;
use App\Filament\Resources\Employees\Support\EmployeeForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lab404\Impersonate\Services\ImpersonateManager;
use Livewire\Component;
use UnitEnum;

/**
 * Employee management (W-01): the account, skills, private HR record, emergency contacts and salaries.
 * The public Directory is a separate, field-limited resource (BR-26).
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'employees';

    protected static ?string $modelLabel = 'employee';

    protected static ?string $navigationLabel = 'Employees';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['roles', 'department', 'manager']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema; // the form is built per page by EmployeeForm (it depends on the record and the actor)
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Name')
                    ->formatStateUsing(fn (User $record): string => $record->getFilamentName())
                    ->searchable(['name', 'surname', 'email'])->sortable(),
                TextColumn::make('email')->searchable()
                    ->visible(fn (): bool => (bool) auth()->user()?->can('viewHrRecord', new User)),
                TextColumn::make('roles.name')->label('Role')->badge()->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                TextColumn::make('department.name')->label('Department'),
                TextColumn::make('manager.name')->label('Manager')
                    ->formatStateUsing(fn (?string $state, User $record): ?string => $record->manager?->getFilamentName()),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (User $record): string => match (true) {
                        $record->anonymized_at !== null => 'Anonymised',
                        $record->isBanned() => 'Deactivated',
                        default => 'Active',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success', 'Deactivated' => 'warning', default => 'gray',
                    }),
                IconColumn::make('two_factor')->label('2FA')->boolean()
                    ->state(fn (User $record): bool => $record->hasTwoFactorEnabled()),
            ])
            ->filters([
                SelectFilter::make('department')->relationship('department', 'name'),
                SelectFilter::make('role')->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => Str::headline($record->name)),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('resendLink')->label('Resend set-password link')->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn (User $record): bool => (bool) auth()->user()?->can('updateAccount', $record) && ! $record->isBanned())
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        app(SendSetPasswordLink::class)->handle($record);
                        Notification::make()->title('Set-password link sent')->success()->send();
                    }),
                Action::make('deactivate')->icon(Heroicon::OutlinedNoSymbol)->color('warning')
                    ->visible(fn (User $record): bool => (bool) auth()->user()?->can('deactivate', $record) && ! $record->isBanned())
                    ->requiresConfirmation()->modalDescription('The person can no longer sign in. Their history is kept.')
                    ->action(function (User $record): void {
                        try {
                            app(DeactivateEmployee::class)->handle(auth()->user(), $record);
                            Notification::make()->title('Employee deactivated')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
                Action::make('reactivate')->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (User $record): bool => (bool) auth()->user()?->can('deactivate', $record) && $record->isBanned() && $record->anonymized_at === null)
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        app(ReactivateEmployee::class)->handle(auth()->user(), $record);
                        Notification::make()->title('Employee reactivated')->success()->send();
                    }),
                Action::make('impersonate')->label('Impersonate')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->visible(fn (User $record): bool => (bool) auth()->user()?->can('impersonate', $record)
                        && ! $record->is(auth()->user()) && $record->canBeImpersonated() && ! app(ImpersonateManager::class)->isImpersonating())
                    ->requiresConfirmation()->modalDescription('You will see and act as this person. Everything you change is recorded under their name with you as the impersonator.')
                    ->action(function (User $record, Component $livewire): void {
                        try {
                            app(StartImpersonation::class)->handle(auth()->user(), $record);
                            $livewire->redirect('/app');
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
                Action::make('resetTwoFactor')->label('Reset 2FA')->icon(Heroicon::OutlinedShieldExclamation)->color('danger')
                    ->visible(fn (User $record): bool => (bool) auth()->user()?->can('resetTwoFactor', $record) && $record->hasTwoFactorEnabled())
                    ->requiresConfirmation()->modalDescription('Removes their authenticator app and recovery codes. They enrol again at next sign-in if 2FA is required.')
                    ->action(function (User $record): void {
                        app(ResetTwoFactor::class)->handle(auth()->user(), $record);
                        Notification::make()->title('Two-factor authentication reset')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }

    public static function configureForm(Schema $schema, ?User $record, bool $creating): Schema
    {
        return EmployeeForm::configure($schema, $record, $creating);
    }

    public static function getRelations(): array
    {
        return [SalariesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
