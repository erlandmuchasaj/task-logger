<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankHolidays;

use App\Domain\MasterData\Models\BankHoliday;
use App\Filament\Resources\BankHolidays\Pages\ManageBankHolidays;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * BR-25 / W-07. HR enters a holiday once, on its real date. In a country that moves weekend holidays the
 * observed working day is worked out, not entered (D-62). A fixed-date holiday can repeat every year (D-59).
 */
final class BankHolidayResource extends Resource
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
            DatePicker::make('start_date')->required()->live()
                ->helperText('The real date. A country that moves weekend holidays works out the observed day for you, so do not add it.'),
            DatePicker::make('end_date')->required()->afterOrEqual('start_date')
                ->helperText('Same as start date for a single day.'),
            Toggle::make('repeats_yearly')->label('Repeats every year')->live()
                ->helperText('Same day and month every year, from the year of the start date on. Use it for fixed-date holidays such as 28 November, not for Easter or Eid.')
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if (! $value || blank($get('start_date')) || blank($get('end_date'))) {
                        return;
                    }

                    $start = CarbonImmutable::parse((string) $get('start_date'));
                    $end = CarbonImmutable::parse((string) $get('end_date'));

                    if (($start->month === 2 && $start->day === 29) || ($end->month === 2 && $end->day === 29)) {
                        $fail('A holiday on 29 February cannot repeat every year. Add it again for each leap year.');
                    } elseif ((int) $start->diffInDays($end) >= 365) {
                        $fail('A holiday that repeats every year must be shorter than a year.');
                    }
                }),
            TextInput::make('repeat_until_year')->label('Last year')->integer()
                ->minValue(fn (Get $get): ?int => filled($get('start_date')) ? CarbonImmutable::parse((string) $get('start_date'))->year : null)
                ->maxValue(2100)
                ->visible(fn (Get $get): bool => (bool) $get('repeats_yearly'))
                ->dehydratedWhenHidden()
                ->dehydrateStateUsing(fn (mixed $state, Get $get): ?int => $get('repeats_yearly') && filled($state) ? (int) $state : null)
                ->helperText('Leave empty to repeat with no end.'),
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
                TextColumn::make('repeats_yearly')->label('Repeats')
                    ->formatStateUsing(fn (bool $state, BankHoliday $record): string => match (true) {
                        ! $state => '—',
                        $record->repeat_until_year !== null => 'Every year until '.$record->repeat_until_year,
                        default => 'Every year',
                    }),
            ])
            ->filters([
                SelectFilter::make('country_id')->relationship('country', 'name')->label('Country'),
                TernaryFilter::make('repeats_yearly')->label('Repeats every year'),
            ])
            ->recordActions([ActionGroup::make([EditAction::make(), DeleteAction::make()])])
            ->defaultSort('start_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageBankHolidays::route('/')];
    }
}
