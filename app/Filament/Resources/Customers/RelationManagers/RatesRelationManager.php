<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Domain\Clients\Actions\DeleteClientRate;
use App\Domain\Clients\Actions\RecordClientRate;
use App\Domain\Clients\Actions\UpdateClientRate;
use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Models\Customer;
use App\Domain\MasterData\Models\Technology;
use App\Domain\Shared\Money;
use App\Filament\Support\ActionErrors;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The client's technologies with an hourly rate and the date it applies from (rate history). Visible
 * only with clients.view_rates (D-05); changed only by people who may also update the client.
 */
class RatesRelationManager extends RelationManager
{
    protected static string $relationship = 'rates';

    protected static ?string $title = 'Hourly rates';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewRates', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        /** @var Customer $customer */
        $customer = $this->getOwnerRecord();

        return ! auth()->user()?->can('manageRates', $customer);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('technology_id')->label('Technology')
                ->options(fn (): array => Technology::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->required()->disabledOn('edit'),
            TextInput::make('rate')->label('Rate per hour (major units)')->numeric()->minValue(0)->required(),
            DatePicker::make('effective_from')->required()->default(now()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['technology', 'customer']))
            ->columns([
                TextColumn::make('technology.name')->sortable(),
                TextColumn::make('rate')->label('Rate per hour')->state(fn (ClientTechnologyRate $record): string => $record->money()->format()),
                TextColumn::make('effective_from')->date()->sortable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->headerActions([
                CreateAction::make()->visible(fn (): bool => ! $this->isReadOnly())
                    ->using(function (array $data): Model {
                        /** @var Customer $customer */
                        $customer = $this->getOwnerRecord();

                        try {
                            return app(RecordClientRate::class)->handle(
                                auth()->user(), $customer, (int) $data['technology_id'],
                                self::minor($data['rate'], $customer), CarbonImmutable::parse((string) $data['effective_from']),
                            );
                        } catch (ValidationException $e) {
                            ActionErrors::report($this, $e, 'mountedActions.0.data.', ['rate' => 'rate']);
                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => ! $this->isReadOnly())
                    ->mutateRecordDataUsing(function (array $data, ClientTechnologyRate $record): array {
                        $digits = Money::fractionDigits($record->customer->currency_code);
                        $data['rate'] = number_format($record->rate_minor / (10 ** $digits), $digits, '.', '');

                        return $data;
                    })
                    ->using(function (ClientTechnologyRate $record, array $data): Model {
                        try {
                            return app(UpdateClientRate::class)->handle(
                                auth()->user(), $record, self::minor($data['rate'], $record->customer), CarbonImmutable::parse((string) $data['effective_from']),
                            );
                        } catch (ValidationException $e) {
                            ActionErrors::notify($e);
                            throw new Halt;
                        }
                    }),
                DeleteAction::make()->visible(fn (): bool => ! $this->isReadOnly())
                    ->using(function (ClientTechnologyRate $record): bool {
                        try {
                            app(DeleteClientRate::class)->handle(auth()->user(), $record);

                            return true;
                        } catch (ValidationException $e) {
                            ActionErrors::notify($e);
                            throw new Halt;
                        }
                    }),
            ]);
    }

    private static function minor(mixed $rate, Customer $customer): int
    {
        try {
            return Money::fromMajor((string) $rate, $customer->currency_code)->minor;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rate' => $e->getMessage()]);
        }
    }
}
