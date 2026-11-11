<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Domain\Clients\Actions\AddFixedCost;
use App\Domain\Clients\Actions\DeleteFixedCost;
use App\Domain\Clients\Actions\UpdateFixedCost;
use App\Domain\Clients\Data\FixedCostData;
use App\Domain\Clients\Models\FixedCost;
use App\Domain\Clients\Models\Project;
use App\Domain\Shared\Money;
use App\Filament\Support\ActionErrors;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** BR-14: fixed costs for a month, in the client's currency, added to the budget. Perm fixed_costs.manage (superadmin). */
class FixedCostsRelationManager extends RelationManager
{
    protected static string $relationship = 'fixedCosts';

    protected static ?string $title = 'Fixed costs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('manageFixedCosts', $ownerRecord);
    }

    /** Editable on the View screen too, for whoever may manage fixed costs (Filament defaults View pages to read-only). */
    public function isReadOnly(): bool
    {
        /** @var Project $project */
        $project = $this->getOwnerRecord();

        return ! auth()->user()?->can('manageFixedCosts', $project);
    }

    public function form(Schema $schema): Schema
    {
        /** @var Project $project */
        $project = $this->getOwnerRecord();

        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->placeholder('Management'),
            DatePicker::make('date')->label('Month')->required()->default(now())->helperText('Stored as the first day of the month.'),
            TextInput::make('amount')->label("Amount ({$project->customer->currency_code}, major units)")->numeric()->minValue(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('date')->label('Month')->date('F Y')->sortable(),
                TextColumn::make('amount')->state(fn (FixedCost $record): string => $record->money()->format()),
            ])
            ->defaultSort('date', 'desc')
            ->headerActions([
                CreateAction::make()->using(function (array $data): Model {
                    /** @var Project $project */
                    $project = $this->getOwnerRecord();

                    try {
                        return app(AddFixedCost::class)->handle(auth()->user(), $project, self::data($data, $project));
                    } catch (ValidationException $e) {
                        ActionErrors::report($this, $e, 'mountedActions.0.data.', ['currency' => 'amount']);
                        throw new Halt;
                    }
                }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, FixedCost $record): array {
                        $digits = Money::fractionDigits($record->currency_code);
                        $data['amount'] = number_format($record->amount_minor / (10 ** $digits), $digits, '.', '');

                        return $data;
                    })
                    ->using(function (FixedCost $record, array $data): Model {
                        try {
                            return app(UpdateFixedCost::class)->handle(auth()->user(), $record, self::data($data, $record->project));
                        } catch (ValidationException $e) {
                            ActionErrors::notify($e);
                            throw new Halt;
                        }
                    }),
                DeleteAction::make()->using(function (FixedCost $record): bool {
                    app(DeleteFixedCost::class)->handle(auth()->user(), $record);

                    return true;
                }),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    private static function data(array $data, Project $project): FixedCostData
    {
        try {
            $amount = Money::fromMajor((string) $data['amount'], $project->customer->currency_code);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return new FixedCostData((string) $data['name'], CarbonImmutable::parse((string) $data['date']), $amount);
    }
}
