<?php

declare(strict_types=1);

namespace App\Filament\Resources\Directory;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\People\Support\Directory;
use App\Filament\Resources\Directory\Pages\ListDirectory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The employee Directory (D-14, BR-26): the public profile only. Read-only for everyone, including
 * superadmin; the private HR record lives in EmployeeResource.
 */
class DirectoryResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'directory';

    protected static ?string $modelLabel = 'employee';

    protected static ?string $navigationLabel = 'Directory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 10;

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return Directory::query();
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::EMPLOYEES_VIEW_DIRECTORY);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar_path')->label('')->disk('public')->circular(),
                TextColumn::make('name')
                    ->label('Name')
                    ->formatStateUsing(fn (User $record): string => trim($record->name.' '.$record->surname))
                    ->searchable(['name', 'surname'])
                    ->sortable(),
                TextColumn::make('position')->searchable(),
                TextColumn::make('department.name')->label('Department'),
                TextColumn::make('technologies')
                    ->label('Skills')
                    ->state(fn (User $record): array => $record->technologies
                        ->map(fn ($technology): string => "{$technology->name} {$technology->pivot->getAttribute('level')}")
                        ->all())
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('department')->relationship('department', 'name'),
                SelectFilter::make('technology')->relationship('technologies', 'name')->label('Skill'),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return ['index' => ListDirectory::route('/')];
    }
}
