<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exports;

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Models\Export;
use App\Filament\Resources\Exports\Pages\ListExports;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Your own exports (BR-33). The file is on the private disk and is downloaded through this screen only. */
final class ExportResource extends Resource
{
    protected static ?string $model = Export::class;

    protected static ?string $slug = 'exports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 30;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('rows')->placeholder('-'),
                TextColumn::make('filters')->label('Filters')->placeholder('-')
                    ->formatStateUsing(fn (mixed $state, Export $record): string => collect($record->filters ?? [])->map(fn ($v, $k): string => "{$k}: {$v}")->implode(', ')),
                TextColumn::make('expires_at')->label('Available until')->dateTime()->placeholder('-'),
                TextColumn::make('error')->limit(60)->placeholder('-')->visible(fn (): bool => Export::query()->where('user_id', auth()->id())->where('status', ExportStatus::Failed)->exists()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('download')->icon(Heroicon::OutlinedArrowDownTray)
                        ->visible(fn (Export $record): bool => (bool) auth()->user()?->can('download', $record))
                        ->action(function (Export $record) {
                            abort_unless(auth()->user()?->can('download', $record), 403);

                            return Storage::disk((string) config('task-logger.exports.disk'))
                                ->download((string) $record->file_path, "{$record->type->value}-".$record->created_at->format('Y-m-d-His').'.csv');
                        }),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListExports::route('/')];
    }
}
