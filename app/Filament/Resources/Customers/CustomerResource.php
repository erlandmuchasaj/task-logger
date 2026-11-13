<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers;

use App\Domain\Clients\Actions\SetCustomerActive;
use App\Domain\Clients\Models\Customer;
use App\Domain\Shared\Priority;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\RatesRelationManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Clients (table `customers`, D-23). Read-only for everyone with clients.view; rates and payment plan need clients.view_rates (D-05). */
final class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'clients';

    protected static ?string $modelLabel = 'client';

    protected static ?string $navigationLabel = 'Clients';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Clients & projects';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'company_name';

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['country', 'paymentPlan']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        $canSeeRates = (bool) auth()->user()?->can('viewRates', Customer::class);

        return $schema->components([
            Section::make('Client')->columns(3)->schema([
                TextEntry::make('company_name')->label('Company')->placeholder('-'),
                TextEntry::make('name')->label('Contact')->formatStateUsing(fn (string $state, Customer $record): string => mb_trim($state.' '.$record->surname)),
                TextEntry::make('priority')->badge(),
                TextEntry::make('email'),
                TextEntry::make('phone')->placeholder('-'),
                TextEntry::make('country.name')->label('Country'),
                TextEntry::make('address')->placeholder('-')->columnSpanFull(),
                TextEntry::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Deactivated')->color(fn (bool $state): string => $state ? 'success' : 'warning'),
            ]),
            Section::make('Commercial')->columns(2)->visible($canSeeRates)->schema([
                TextEntry::make('currency_code')->label('Currency'),
                TextEntry::make('paymentPlan.name')->label('Payment plan')->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $canSeeRates = fn (): bool => (bool) auth()->user()?->can('viewRates', Customer::class);

        return $table
            ->columns([
                ImageColumn::make('avatar_path')->label('')->disk('public')->circular(),
                TextColumn::make('company_name')->label('Client')->placeholder('-')
                    ->formatStateUsing(fn (?string $state, Customer $record): string => $record->displayName())
                    ->searchable(['company_name', 'name', 'surname', 'email'])->sortable(),
                TextColumn::make('country.name')->label('Country'),
                TextColumn::make('priority')->badge()->sortable(),
                TextColumn::make('currency_code')->label('Currency')->visible($canSeeRates),
                TextColumn::make('paymentPlan.name')->label('Payment plan')->visible($canSeeRates),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('priority')->options(Priority::class),
                SelectFilter::make('country')->relationship('country', 'name'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('deactivate')->icon(Heroicon::OutlinedNoSymbol)->color('warning')
                        ->visible(fn (Customer $record): bool => $record->is_active && (bool) auth()->user()?->can('deactivate', $record))
                        ->requiresConfirmation()->modalDescription('No new projects, tasks or logs can be added. History stays.')
                        ->action(function (Customer $record): void {
                            resolve(SetCustomerActive::class)->handle(auth()->user(), $record, false);
                            Notification::make()->title('Client deactivated')->success()->send();
                        }),
                    Action::make('activate')->icon(Heroicon::OutlinedCheckCircle)
                        ->visible(fn (Customer $record): bool => ! $record->is_active && (bool) auth()->user()?->can('activate', $record))
                        ->requiresConfirmation()
                        ->action(function (Customer $record): void {
                            resolve(SetCustomerActive::class)->handle(auth()->user(), $record, true);
                            Notification::make()->title('Client activated')->success()->send();
                        }),
                ]),
            ])
            ->defaultSort('company_name');
    }

    public static function getRelations(): array
    {
        return [RatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
