<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Reports\Actions\BuildFinancials;
use App\Domain\Reports\Data\FinancialsRow;
use App\Domain\Shared\Money;
use App\Filament\Support\ExportAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\AbstractPaginator;
use UnitEnum;

/**
 * D-91: budget, costs and profit per project, for finance and the superadmin. It holds its own permission
 * (`financials.view`), so it shows no rate or salary of its own, only totals per project.
 */
final class Financials extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.financials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Clients & projects';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Financials';

    /** @var array<int, FinancialsRow>|null */
    private ?array $figures = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('viewFinancials', Project::class);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Project::query()->with('customer'))
            ->columns([
                TextColumn::make('name')->label('Project')->searchable()->sortable()->toggleable(),
                TextColumn::make('customer.company_name')->label('Client')->toggleable()
                    ->formatStateUsing(fn (?string $state, Project $record): string => $record->customer->displayName()),
                TextColumn::make('budget_minor')->label('Budget')->toggleable()
                    ->formatStateUsing(fn (mixed $state, Project $record): string => $this->row($record)->budget->format()),
                TextColumn::make('costs')->label('Costs')->toggleable()
                    ->state(fn (Project $record): string => $this->money($this->row($record)->costs())),
                TextColumn::make('employee_costs')->label('Employee costs')->toggleable()
                    ->state(fn (Project $record): string => $this->money($this->row($record)->employeeCosts)),
                TextColumn::make('fixed_costs')->label('Fixed costs')->toggleable()
                    ->state(fn (Project $record): string => $this->row($record)->fixedCosts->format()),
                TextColumn::make('profit')->label('Profit')->toggleable()
                    ->state(fn (Project $record): string => $this->row($record)->profitLabel() ?? '—'),
                TextColumn::make('total_hours')->label('Total hours')->toggleable()
                    ->state(fn (Project $record): string => $this->row($record)->hours()),
                TextColumn::make('end_date')->label('Deadline')->date()->placeholder('—')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('customer')->relationship('customer', 'company_name')->label('Client')
                    ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->displayName()),
                SelectFilter::make('status')->options(ProjectStatus::class),
            ])
            ->paginationMode(PaginationMode::Simple)
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [ExportAction::make(ExportType::Financials)];
    }

    /** The figures of the page being shown, worked out once for all its projects. */
    private function row(Project $project): FinancialsRow
    {
        $records = $this->getTableRecords();
        /** @var iterable<Project> $projects */
        $projects = $records instanceof AbstractPaginator ? $records->getCollection() : $records;
        $this->figures ??= resolve(BuildFinancials::class)->handle(auth()->user(), $projects);

        return $this->figures[$project->getKey()];
    }

    private function money(?Money $money): string
    {
        return $money?->format() ?? '—';
    }
}
