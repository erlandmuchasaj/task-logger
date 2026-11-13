<?php

declare(strict_types=1);

namespace App\Filament\Resources\Departments;

use App\Domain\MasterData\Models\Department;
use App\Filament\Resources\Departments\Pages\ManageDepartments;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Master data';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('parent_id')->label('Parent department')
                ->relationship('parent', 'name', fn ($query, ?Department $record) => $record instanceof Department ? $query->whereKeyNot($record->getKey()) : $query)
                ->searchable()->preload(),
            Textarea::make('description')->columnSpanFull(),
            ColorPicker::make('color'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Parent'),
                TextColumn::make('slug')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([ActionGroup::make([EditAction::make(), DeleteAction::make()])])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageDepartments::route('/')];
    }
}
