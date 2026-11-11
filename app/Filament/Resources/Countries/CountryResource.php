<?php

declare(strict_types=1);

namespace App\Filament\Resources\Countries;

use App\Domain\Identity\Permissions;
use App\Domain\MasterData\Enums\Proration;
use App\Domain\MasterData\Models\Country;
use App\Filament\Resources\Countries\Pages\ManageCountries;
use BackedEnum;
use Closure;
use DateTimeZone;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CountryResource extends Resource
{
    protected static ?string $model = Country::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeEuropeAfrica;

    protected static string|UnitEnum|null $navigationGroup = 'Master data';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        $timezones = array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());

        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')
                ->label('Code (ISO 3166-1 alpha-2)')
                ->required()->length(2)->alpha()
                ->dehydrateStateUsing(fn (?string $state) => $state === null ? null : strtoupper($state))
                ->unique(ignoreRecord: true),
            TextInput::make('prefix')->label('Phone prefix')->required()->maxLength(8)->placeholder('+355'),
            Select::make('timezone')->options($timezones)->searchable()->required(),
            TextInput::make('currency_code')
                ->label('Currency (ISO 4217)')
                ->required()->length(3)->alpha()
                ->dehydrateStateUsing(fn (?string $state) => $state === null ? null : strtoupper($state)),
            CheckboxList::make('working_days')
                ->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'])
                ->default(Country::defaultWorkingDays())
                ->required()->columns(4),
            Repeater::make('leavePolicies')
                ->label('Leave policies (PTO and sick leave days per year)')
                ->relationship()
                ->visible(fn (): bool => (bool) auth()->user()?->can(Permissions::COUNTRY_LEAVE_POLICIES_MANAGE))
                ->columnSpanFull()
                ->defaultItems(0)
                ->rules([
                    fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        $rows = is_array($value) ? $value : [];
                        $keys = array_map(
                            fn (mixed $row): string => is_array($row) ? ($row['leave_type_id'] ?? '').'|'.($row['effective_from'] ?? '') : '',
                            $rows,
                        );
                        if (count($keys) !== count(array_unique($keys))) {
                            $fail('Each leave type may have one policy per effective date.');
                        }
                    },
                ])
                ->schema([
                    Select::make('leave_type_id')->relationship('leaveType', 'name')->required(),
                    TextInput::make('days_per_year')->numeric()->minValue(0)->maxValue(365)->step(0.5)->required(),
                    DatePicker::make('effective_from')->required(),
                    Select::make('proration')->options(Proration::class)->default(Proration::ByStartDate)->required(),
                    Toggle::make('carry_over_enabled')->label('Carry over unused days')->default(false)->live(),
                    TextInput::make('carry_over_max_days')->numeric()->minValue(0)->visible(fn ($get) => $get('carry_over_enabled')),
                    TextInput::make('carry_over_expires_after_months')->numeric()->minValue(1)->maxValue(24)->visible(fn ($get) => $get('carry_over_enabled')),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('prefix'),
                TextColumn::make('timezone'),
                TextColumn::make('currency_code'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCountries::route('/')];
    }
}
