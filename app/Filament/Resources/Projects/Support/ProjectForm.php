<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Support;

use App\Domain\Clients\Data\ProjectData;
use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Enums\ProjectType;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Support\ClientRates;
use App\Domain\Clients\Support\ProjectManagerEligibility;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** The project form and its mapping to ProjectData. D-02: there is no priority. D-04: the budget is shown, never typed. */
final class ProjectForm
{
    public static function configure(Schema $schema, ?Project $record): Schema
    {
        $creating = $record === null;

        $components = [
            Section::make('Project')->columns(2)->schema([
                Select::make('customer_id')->label('Client')
                    ->options(fn (): array => Customer::query()->when($creating, fn ($q) => $q->where('is_active', true))
                        ->orderBy('company_name')->get()->mapWithKeys(fn (Customer $c): array => [$c->id => $c->displayName()])->all())
                    ->searchable()->required()->live()->disabled(! $creating)->dehydrated()
                    ->helperText($creating ? 'Only active clients. Technologies below come from the client\'s rates.' : 'The client of an existing project cannot change.'),
                Select::make('project_type')->label('Type')->options(ProjectType::class)->required(),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('manager_id')->label('Manager')
                    ->options(fn (): array => User::query()->whereIn('id', ProjectManagerEligibility::eligibleIds())->orderBy('name')->get()
                        ->mapWithKeys(fn (User $u): array => [$u->id => $u->getFilamentName()])->all())
                    ->searchable()->required()->helperText('Only users with the admin role can manage a project.'),
                DatePicker::make('start_date')->required()->live()
                    ->helperText('Rates effective on this date price the budget.'),
                DatePicker::make('end_date')->label('Deadline')->afterOrEqual('start_date'),
                Select::make('status')->options(ProjectStatus::class)->required()->default(ProjectStatus::Planning)
                    ->disabled(fn (): bool => ! auth()->user()?->can('changeStatus', $record ?? new Project))->dehydrated(),
                ColorPicker::make('color'),
                Toggle::make('can_log_directly_to_project')->label('Employees can log hours directly to this project (no task needed)'),
                Textarea::make('description')->columnSpanFull(),
                Select::make('members')->label('Members')->multiple()
                    ->options(fn (): array => User::query()->whereNull('banned_at')->orderBy('name')->get()
                        ->mapWithKeys(fn (User $u): array => [$u->id => $u->getFilamentName()])->all())
                    ->searchable()->columnSpanFull()
                    ->disabled(fn (): bool => ! auth()->user()?->can('manageMembers', $record ?? new Project))->dehydrated()
                    ->helperText('Only members can log directly to the project.'),
            ]),
            Section::make('Technologies and estimates')->schema([
                Repeater::make('technologies')->label('')->defaultItems(0)->columns(2)->addActionLabel('Add technology')
                    ->schema([
                        Select::make('technology_id')->label('Technology')
                            ->options(fn (callable $get): array => Technology::query()
                                ->whereIn('id', ClientRates::technologyIds((int) $get('../../customer_id')))->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        TextInput::make('estimated_hours')->label('Estimated hours')->numeric()->minValue(0.01)->maxValue(999999.99)->required(),
                    ]),
            ]),
        ];

        if ($record && auth()->user()?->can('viewBudget', $record)) {
            $components[] = Section::make('Computed budget')->columns(2)->schema([
                TextEntry::make('estimated_hours_total')->label('Estimated hours')->state($record->estimated_hours),
                TextEntry::make('budget_total')->label('Budget (hours x rate + fixed costs)')->state($record->budget()->format()),
            ]);
        }

        return $schema->components($components);
    }

    /** @return array<string, mixed> */
    public static function fill(Project $project): array
    {
        return [
            ...$project->only(['customer_id', 'name', 'description', 'manager_id', 'color', 'can_log_directly_to_project']),
            'project_type' => $project->project_type->value,
            'status' => $project->status->value,
            'start_date' => $project->start_date->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'members' => $project->memberships()->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
            'technologies' => $project->technologyLines()->get()
                ->map(fn ($l): array => ['technology_id' => $l->technology_id, 'estimated_hours' => $l->estimated_hours])->all(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function data(array $data): ProjectData
    {
        $technologies = [];
        foreach ((array) ($data['technologies'] ?? []) as $row) {
            if (filled($row['technology_id'] ?? null)) {
                $technologies[(int) $row['technology_id']] = (string) $row['estimated_hours'];
            }
        }

        $type = $data['project_type'];
        $status = $data['status'] ?? ProjectStatus::Planning;

        return new ProjectData(
            customerId: (int) $data['customer_id'],
            type: $type instanceof ProjectType ? $type : ProjectType::from((string) $type),
            name: (string) $data['name'],
            description: $data['description'] ?? null,
            managerId: (int) $data['manager_id'],
            startDate: CarbonImmutable::parse((string) $data['start_date']),
            endDate: filled($data['end_date'] ?? null) ? CarbonImmutable::parse((string) $data['end_date']) : null,
            canLogDirectly: (bool) ($data['can_log_directly_to_project'] ?? false),
            status: $status instanceof ProjectStatus ? $status : ProjectStatus::from((string) $status),
            color: $data['color'] ?? null,
            technologies: $technologies,
            memberIds: array_map('intval', (array) ($data['members'] ?? [])),
        );
    }
}
