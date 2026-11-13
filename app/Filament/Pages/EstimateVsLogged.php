<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Reports\Actions\BuildEstimateReport;
use App\Domain\Reports\Data\EstimateFilters;
use App\Domain\Reports\Data\EstimateReportResult;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * M7 report: estimated against logged hours per project and technology, with logged work valued at the rate of
 * each log (SPEC 1.2). Needs projects.view and logs.view_any; the value columns need clients.view_rates (D-49).
 */
class EstimateVsLogged extends Page
{
    protected string $view = 'filament.pages.estimate-vs-logged';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Estimated vs logged';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public int $page = 1;

    protected ?EstimateReportResult $result = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('viewReport', Project::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->getSchema('form')?->fill(['status' => ProjectStatus::Active->value]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')->label('Client')->native(false)->searchable()->live()
                ->options(fn (): array => Customer::query()->orderBy('company_name')->orderBy('name')->get()->mapWithKeys(fn (Customer $c): array => [$c->getKey() => $c->displayName()])->all())
                ->afterStateUpdated(function (): void {
                    $this->page = 1;
                    $this->data['project_id'] = null;
                }),
            Select::make('project_id')->label('Project')->native(false)->searchable()->live()
                ->options(fn (): array => Project::query()
                    ->when($this->data['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
                    ->orderBy('name')->limit(200)->pluck('name', 'id')->all())
                ->afterStateUpdated(fn () => $this->page = 1),
            Select::make('status')->native(false)->options(ProjectStatus::class)->placeholder('Any status')->live()
                ->afterStateUpdated(fn () => $this->page = 1),
            DatePicker::make('from')->label('Logs from')->live()->afterStateUpdated(fn () => $this->page = 1),
            DatePicker::make('to')->label('Logs to')->live()->afterStateUpdated(fn () => $this->page = 1),
        ])->columns(5)->statePath('data');
    }

    public function previous(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function next(): void
    {
        $this->page++;
    }

    public function filters(): EstimateFilters
    {
        $state = $this->data ?? [];
        $status = $state['status'] ?? null;

        return new EstimateFilters(
            customerId: filled($state['customer_id'] ?? null) ? (int) $state['customer_id'] : null,
            projectId: filled($state['project_id'] ?? null) ? (int) $state['project_id'] : null,
            status: $status instanceof ProjectStatus ? $status : (filled($status) ? ProjectStatus::tryFrom((string) $status) : null),
            from: filled($state['from'] ?? null) ? CarbonImmutable::parse((string) $state['from']) : null,
            to: filled($state['to'] ?? null) ? CarbonImmutable::parse((string) $state['to']) : null,
        );
    }

    /** Computed once per request: the page and the totals come from one pass over the logs. */
    public function result(): EstimateReportResult
    {
        return $this->result ??= app(BuildEstimateReport::class)->build(auth()->user(), $this->filters(), $this->page);
    }
}
