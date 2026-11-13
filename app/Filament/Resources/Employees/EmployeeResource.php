<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees;

use App\Domain\Identity\Actions\StartImpersonation;
use App\Domain\Identity\Enums\EmploymentType;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Identity\Support\TwoFactorPolicy;
use App\Domain\People\Actions\DeactivateEmployee;
use App\Domain\People\Actions\ReactivateEmployee;
use App\Domain\People\Actions\ResetEmployeePassword;
use App\Domain\People\Actions\ResetTwoFactor;
use App\Domain\People\Actions\SendSetPasswordLink;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\RelationManagers\LeaveRequestsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\SalariesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\TimesheetsRelationManager;
use App\Filament\Resources\Employees\Support\EmployeeForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
 * Private fields are gated per column and entry by viewHrRecord and salaries.view (BR-26).
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
        $query = parent::getEloquentQuery()->with(['roles', 'department', 'manager']);

        // The current salary is loaded only for viewers who may see salaries.
        return auth()->user()?->can(Permissions::SALARIES_VIEW) ? $query->with('currentSalary') : $query;
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
                TextColumn::make('roles.name')->label('Role')->badge()
                    ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                TextColumn::make('department.name')->label('Department')->sortable()->toggleable(),
                TextColumn::make('position')->label('Job title')->searchable()->sortable()->toggleable(),
                TextColumn::make('employment_type')->label('Employment type')->badge()->sortable(),
                TextColumn::make('manager.name')->label('Manager')->toggleable()
                    ->formatStateUsing(fn (?string $state, User $record): ?string => $record->manager?->getFilamentName()),
                TextColumn::make('currentSalary.amount_minor')->label('Salary')->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => (bool) auth()->user()?->can(Permissions::SALARIES_VIEW))
                    ->formatStateUsing(fn (User $record): ?string => $record->currentSalary?->money()->format()),
                TextColumn::make('start_date')->label('Hire date')->date()->sortable()->toggleable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (User $record): string => match (true) {
                        $record->anonymized_at !== null => 'Anonymised',
                        $record->isBanned() => 'Deactivated',
                        default => 'Active',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success', 'Deactivated' => 'warning', default => 'gray',
                    }),
                IconColumn::make('two_factor')->label('2FA')->boolean()->toggleable()
                    ->visible(fn (): bool => app(TwoFactorPolicy::class)->isEnabled())
                    ->state(fn (User $record): bool => $record->hasTwoFactorEnabled()),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('department')->relationship('department', 'name')->preload(),
                SelectFilter::make('role')->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => Str::headline($record->name)),
                SelectFilter::make('employment_type')->label('Employment type')->options(EmploymentType::class),
                SelectFilter::make('manager')->relationship('manager', 'name')->searchable()->preload()
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->getFilamentName()),
                TernaryFilter::make('two_factor')->label('Two-factor authentication')
                    ->visible(fn (): bool => app(TwoFactorPolicy::class)->isEnabled())
                    ->trueLabel('Enabled')->falseLabel('Not enabled')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('app_authentication_secret'),
                        false: fn (Builder $query): Builder => $query->whereNull('app_authentication_secret'),
                    ),
                Filter::make('hired')->label('Hire date')
                    ->schema([
                        DatePicker::make('hired_from')->label('Hired from'),
                        DatePicker::make('hired_until')->label('Hired until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['hired_from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('start_date', '>=', $date))
                        ->when($data['hired_until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('start_date', '<=', $date))),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label('View profile')
                        ->modalHeading(fn (User $record): string => $record->getFilamentName())
                        ->slideOver()
                        ->authorize(fn (): bool => (bool) auth()->user()?->can('viewAny', User::class))
                        ->schema(fn (): array => static::profileEntries()),
                    EditAction::make(),
                    Action::make('resendLink')->label('Send reset-password link')->icon(Heroicon::OutlinedEnvelope)
                        ->visible(fn (User $record): bool => (bool) auth()->user()?->can('updateAccount', $record) && ! $record->isBanned())
                        ->requiresConfirmation()
                        ->modalDescription('Emails the person a link to choose a new password. Their current password keeps working until they do.')
                        ->action(function (User $record): void {
                            app(SendSetPasswordLink::class)->handle($record);
                            Notification::make()->title('Reset-password link sent')->success()->send();
                        }),
                    Action::make('resetPassword')->label('Reset password and email it')->icon(Heroicon::OutlinedKey)->color('warning')
                        ->visible(fn (User $record): bool => (bool) auth()->user()?->can('resetPassword', $record)
                            && ! $record->isBanned() && $record->anonymized_at === null)
                        ->requiresConfirmation()
                        ->modalDescription('Their current password stops working at once. A new temporary password is emailed to them, and they are asked to change it after signing in.')
                        ->action(function (User $record): void {
                            try {
                                app(ResetEmployeePassword::class)->handle(auth()->user(), $record);
                                Notification::make()->title('Password reset and emailed')->success()->send();
                            } catch (ValidationException $e) {
                                Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                            }
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
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * The read-only profile shown in the View slide-over. Private HR fields and the salary appear only for
     * viewers holding the matching permission (BR-26); relations are loaded explicitly (no lazy loading).
     *
     * @return array<int, SchemaComponent>
     */
    public static function profileEntries(): array
    {
        $actor = auth()->user();
        $canSeeHr = (bool) $actor?->can('viewHrRecord', new User);
        $canSeeSalary = (bool) $actor?->can(Permissions::SALARIES_VIEW);

        return [
            Section::make('Account')->columns(2)->schema([
                TextEntry::make('full_name')->label('Name')
                    ->state(fn (User $record): string => $record->getFilamentName()),
                TextEntry::make('email')->label('Email')->copyable()->visible($canSeeHr),
                TextEntry::make('role')->label('Role')->badge()
                    ->state(fn (User $record): ?string => ($name = $record->loadMissing('roles')->roles->first()?->getAttribute('name')) ? Str::headline($name) : null),
                TextEntry::make('status')->label('Status')->badge()
                    ->state(fn (User $record): string => match (true) {
                        $record->anonymized_at !== null => 'Anonymised',
                        $record->isBanned() => 'Deactivated',
                        default => 'Active',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success', 'Deactivated' => 'warning', default => 'gray',
                    }),
                TextEntry::make('two_factor')->label('Two-factor authentication')->badge()
                    ->visible(fn (): bool => app(TwoFactorPolicy::class)->isEnabled())
                    ->state(fn (User $record): string => $record->hasTwoFactorEnabled() ? 'Enabled' : 'Not enabled')
                    ->color(fn (string $state): string => $state === 'Enabled' ? 'success' : 'gray'),
                TextEntry::make('password_state')->label('Password')->badge()
                    ->state(fn (User $record): string => $record->must_change_password ? 'Temporary, not yet changed' : 'Set by the person')
                    ->color(fn (User $record): string => $record->must_change_password ? 'warning' : 'success'),
            ]),
            Section::make('Employment')->columns(2)->schema([
                TextEntry::make('department_name')->label('Department')->placeholder('—')
                    ->state(fn (User $record): ?string => $record->loadMissing('department')->department?->name),
                TextEntry::make('position')->label('Job title')->placeholder('—'),
                TextEntry::make('employment_type')->label('Employment type')->badge()->placeholder('—'),
                TextEntry::make('manager_name')->label('Manager')->placeholder('—')
                    ->state(fn (User $record): ?string => $record->loadMissing('manager')->manager?->getFilamentName()),
                TextEntry::make('start_date')->label('Hire date')->date()->placeholder('—'),
                TextEntry::make('salary')->label('Current salary')->placeholder('—')->visible($canSeeSalary)
                    ->state(fn (User $record): ?string => $record->loadMissing('currentSalary')->currentSalary?->money()->format()),
                TextEntry::make('skills')->label('Skills')->badge()->placeholder('—')->columnSpanFull()
                    ->state(fn (User $record): array => $record->loadMissing('technologies')->technologies
                        ->map(fn ($tech): string => $tech->name.' ('.$tech->pivot->getAttribute('level').')')->all()),
            ]),
            Section::make('Contact')->columns(2)->visible($canSeeHr)->schema([
                TextEntry::make('phone')->label('Phone')->placeholder('—')
                    ->state(fn (User $record): ?string => $record->loadMissing('profile')->profile?->phone),
            ]),
        ];
    }

    public static function configureForm(Schema $schema, ?User $record, bool $creating): Schema
    {
        return EmployeeForm::configure($schema, $record, $creating);
    }

    public static function getRelations(): array
    {
        // Shown as tabs on the edit page; the active tab is kept in the URL (?relation=N).
        return [TimesheetsRelationManager::class, LeaveRequestsRelationManager::class, SalariesRelationManager::class];
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
