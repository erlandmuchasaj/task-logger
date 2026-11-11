<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankHolidays;

use App\Domain\MasterData\Models\BankHoliday;
use App\Filament\Resources\BankHolidays\Pages\ManageBankHolidays;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * BR-25 / W-07. A holiday on a weekend is not shifted: HR adds the observed Monday as its own
 * entry (D-08).
 */
class BankHolidayResource extends Resource
{
    protected static ?string $model = BankHoliday::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('country_id')->relationship('country', 'name')->searchable()->preload()->required(),
            DatePicker::make('start_date')->required()->live(),
            DatePicker::make('end_date')->required()->afterOrEqual('start_date')
                ->helperText('Same as start date for a single day.'),
            Textarea::make('description')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('country'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('country.name')->sortable(),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date()->sortable(),
            ])
            ->filters([SelectFilter::make('country_id')->relationship('country', 'name')->label('Country')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('start_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageBankHolidays::route('/')];
    }
}
