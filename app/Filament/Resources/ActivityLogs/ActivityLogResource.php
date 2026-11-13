<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogs;

use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/** The audit trail (BR-32): read-only, activity_logs.view (superadmin). Shows before and after values. */
final class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $slug = 'activity-log';

    protected static ?string $modelLabel = 'activity';

    protected static ?string $pluralModelLabel = 'activity log';

    protected static ?string $navigationLabel = 'Activity log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 30;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['causer', 'impersonator']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable()->label('When'),
                TextColumn::make('event')->badge()->searchable(),
                TextColumn::make('subject_type')->label('Record')
                    ->formatStateUsing(fn (?string $state, ActivityLog $record): string => $state ? Str::headline(class_basename($state))." #{$record->subject_id}" : '-'),
                TextColumn::make('causer.name')->label('By')
                    ->formatStateUsing(fn (?string $state, ActivityLog $record): string => $record->causer instanceof User ? $record->causer->getFilamentName() : 'System'),
                TextColumn::make('impersonator.name')->label('Impersonator')->placeholder('-')
                    ->formatStateUsing(fn (?string $state, ActivityLog $record): ?string => $record->impersonator?->getFilamentName())
                    ->badge()->color('warning'),
                TextColumn::make('client_ip')->label('IP')->placeholder('-')->searchable()->toggleable(),
                TextColumn::make('description')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('impersonated')->label('Impersonated')
                    ->placeholder('All')->trueLabel('Only impersonated')->falseLabel('Not impersonated')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('impersonator_id'),
                        false: fn (Builder $query) => $query->whereNull('impersonator_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('event')->options(fn (): array => ActivityLog::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                SelectFilter::make('subject_type')->label('Record type')
                    ->options(fn (): array => ActivityLog::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')
                        ->mapWithKeys(fn (string $t): array => [$t => Str::headline(class_basename($t))])->all()),
            ])
            ->recordActions([ActionGroup::make([ViewAction::make()])])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $json = fn (mixed $state): string => $state ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-';

        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('event')->badge(),
                TextEntry::make('causer.name')->label('By')
                    ->formatStateUsing(fn (?string $state, ActivityLog $record): string => $record->causer instanceof User ? $record->causer->getFilamentName() : 'System'),
                TextEntry::make('subject_type')->label('Record')
                    ->formatStateUsing(fn (?string $state, ActivityLog $record): string => $state ? Str::headline(class_basename($state))." #{$record->subject_id}" : '-'),
                TextEntry::make('description'),
                TextEntry::make('impersonator.name')->label('Impersonator (who was really acting)')->placeholder('Nobody')->badge()->color('warning')
                    ->formatStateUsing(fn (?string $state, ActivityLog $record): ?string => $record->impersonator?->getFilamentName()),
                TextEntry::make('client_ip')->label('IP address')->placeholder('-'),
                TextEntry::make('user_agent')->label('User agent')->placeholder('-')->columnSpan(2),
            ]),
            Section::make('Changes (before and after)')->schema([
                TextEntry::make('attribute_changes')->hiddenLabel()->formatStateUsing($json)->fontFamily('mono')->extraAttributes(['class' => 'whitespace-pre-wrap']),
            ]),
            Section::make('Other properties')->schema([
                TextEntry::make('properties')->hiddenLabel()->formatStateUsing($json)->fontFamily('mono')->extraAttributes(['class' => 'whitespace-pre-wrap']),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view' => ViewActivityLog::route('/{record}'),
        ];
    }
}
