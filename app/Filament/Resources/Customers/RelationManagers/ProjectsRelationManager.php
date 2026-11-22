<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Domain\Clients\Actions\CreateProject;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Support\ProjectForm;
use App\Filament\Support\ActionErrors;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * D-80: the projects of a client, with a Create button that fixes the client. Everything goes through the
 * CreateProject action, so the permission, the rules and the audit are the ones of the Projects screen. The budget
 * column needs clients.view_rates (D-05).
 */
final class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $title = 'Projects';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewAny', Project::class);
    }

    /** Filament makes relation managers read-only on View pages; the Project policy decides what is offered instead. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Customer $customer */
        $customer = $this->getOwnerRecord();

        return ProjectForm::configure($schema, null, $customer);
    }

    public function table(Table $table): Table
    {
        $canSeeBudget = fn (): bool => (bool) auth()->user()?->can('viewBudget', new Project);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'manager']))
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('project_type')->label('Type')->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('manager.name')->label('Manager')
                    ->formatStateUsing(fn (?string $state, Project $record): string => $record->manager->getFilamentName()),
                TextColumn::make('end_date')->label('Deadline')->date()->sortable(),
                TextColumn::make('estimated_hours')->label('Est. hours'),
                TextColumn::make('budget_minor')->label('Budget')->visible($canSeeBudget)
                    ->formatStateUsing(fn (mixed $state, Project $record): string => $record->budget()->format()),
            ])
            ->defaultSort('name')
            ->headerActions([
                CreateAction::make()->label('New project')->modalHeading('New project')->modalWidth(Width::FiveExtraLarge)
                    ->visible(fn (): bool => $this->getOwnerRecord() instanceof Customer && $this->getOwnerRecord()->is_active)
                    ->using(function (array $data): Model {
                        /** @var Customer $customer */
                        $customer = $this->getOwnerRecord();

                        try {
                            return resolve(CreateProject::class)->handle(auth()->user(), ProjectForm::data([...$data, 'customer_id' => $customer->getKey()]));
                        } catch (ValidationException $validationException) {
                            ActionErrors::report($this, $validationException, 'mountedActions.0.data.');
                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('open')->label('View')->icon(Heroicon::OutlinedEye)
                        ->url(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record])),
                    Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                        ->visible(fn (Project $record): bool => (bool) auth()->user()?->can('update', $record))
                        ->url(fn (Project $record): string => ProjectResource::getUrl('edit', ['record' => $record])),
                ]),
            ]);
    }
}
