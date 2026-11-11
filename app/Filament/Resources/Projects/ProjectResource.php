<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects;

use App\Domain\Clients\Actions\ChangeProjectStatus;
use App\Domain\Clients\Actions\DeleteProject;
use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Models\Project;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\FixedCostsRelationManager;
use App\Filament\Support\ActionErrors;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** Projects: everyone with projects.view reads them; the budget needs clients.view_rates (D-05). */
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $slug = 'projects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Clients & projects';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'manager']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        $canSeeBudget = (bool) auth()->user()?->can('viewBudget', new Project);

        return $schema->components([
            Section::make('Project')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('customer.company_name')->label('Client')
                    ->formatStateUsing(fn (?string $state, Project $record): string => $record->customer->displayName()),
                TextEntry::make('status')->badge(),
                TextEntry::make('project_type')->label('Type')->badge(),
                TextEntry::make('manager.name')->label('Manager')
                    ->formatStateUsing(fn (?string $state, Project $record): string => $record->manager->getFilamentName()),
                TextEntry::make('start_date')->date(),
                TextEntry::make('end_date')->label('Deadline')->date()->placeholder('-'),
                TextEntry::make('can_log_directly_to_project')->label('Direct logging')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Allowed' : 'Tasks only'),
                TextEntry::make('estimated_hours')->label('Estimated hours'),
                TextEntry::make('description')->placeholder('-')->columnSpanFull(),
                TextEntry::make('technology_lines')->label('Technologies')->badge()->columnSpanFull()
                    ->state(fn (Project $record): array => $record->technologyLines()->with('technology')->get()
                        ->map(fn ($l): string => "{$l->technology->name} {$l->estimated_hours}h")->all()),
                TextEntry::make('member_names')->label('Members')->badge()->columnSpanFull()->placeholder('-')
                    ->state(fn (Project $record): array => $record->members()->get()->map(fn ($u): string => $u->getFilamentName())->all()),
            ]),
            Section::make('Budget')->visible($canSeeBudget)->schema([
                TextEntry::make('budget_minor')->label('Budget (hours x rate + fixed costs)')
                    ->formatStateUsing(fn (mixed $state, Project $record): string => $record->budget()->format()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $canSeeBudget = fn (): bool => (bool) auth()->user()?->can('viewBudget', new Project);

        return $table
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label('Client')
                    ->formatStateUsing(fn (?string $state, Project $record): string => $record->customer->displayName()),
                TextColumn::make('project_type')->label('Type')->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('manager.name')->label('Manager')
                    ->formatStateUsing(fn (?string $state, Project $record): string => $record->manager->getFilamentName()),
                TextColumn::make('end_date')->label('Deadline')->date()->sortable(),
                TextColumn::make('estimated_hours')->label('Est. hours')->sortable(),
                TextColumn::make('budget_minor')->label('Budget')->visible($canSeeBudget)->sortable()
                    ->formatStateUsing(fn (mixed $state, Project $record): string => $record->budget()->format()),
                IconColumn::make('can_log_directly_to_project')->label('Direct')->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ProjectStatus::class),
                SelectFilter::make('customer')->relationship('customer', 'company_name')->label('Client'),
                SelectFilter::make('manager')->relationship('manager', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('changeStatus')->label('Change status')->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Project $record): bool => (bool) auth()->user()?->can('changeStatus', $record))
                    ->schema([Select::make('status')->options(ProjectStatus::class)->required()])
                    ->fillForm(fn (Project $record): array => ['status' => $record->status->value])
                    ->action(function (Project $record, array $data): void {
                        app(ChangeProjectStatus::class)->handle(auth()->user(), $record, $data['status'] instanceof ProjectStatus ? $data['status'] : ProjectStatus::from((string) $data['status']));
                        Notification::make()->title('Status changed')->success()->send();
                    }),
                Action::make('delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (Project $record): bool => (bool) auth()->user()?->can('delete', $record))
                    ->requiresConfirmation()
                    ->action(function (Project $record): void {
                        try {
                            app(DeleteProject::class)->handle(auth()->user(), $record);
                            Notification::make()->title('Project deleted')->success()->send();
                        } catch (ValidationException $e) {
                            ActionErrors::notify($e);
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [FixedCostsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
