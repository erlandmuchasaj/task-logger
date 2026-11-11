<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\RecordSalary;
use App\Domain\People\Models\Salary;
use App\Filament\Resources\Employees\Support\FormMapper;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Salary history (BR-29): append-only. People with salaries.view see it; salaries.manage adds a row.
 * There is no edit or delete.
 */
class SalariesRelationManager extends RelationManager
{
    protected static string $relationship = 'salaries';

    protected static ?string $title = 'Salaries';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewSalaries', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('amount')->label('Amount (per month, major units)')->numeric()->minValue(0)->required(),
            TextInput::make('currency')->label('Currency (ISO 4217)')->length(3)->alpha()->required()
                ->default(function (): ?string {
                    /** @var User $employee */
                    $employee = $this->getOwnerRecord();

                    return FormMapper::currencyOf($employee->country_id);
                })
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
            DatePicker::make('effective_date')->required()->default(now()),
            Textarea::make('description')->label('Note')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->columns([
                TextColumn::make('effective_date')->date()->sortable(),
                TextColumn::make('amount')->label('Amount')->state(fn (Salary $record): string => $record->money()->format()),
                TextColumn::make('status')->badge(),
                TextColumn::make('description')->limit(50),
                TextColumn::make('creator.name')->label('Recorded by')
                    ->formatStateUsing(fn (?string $state, Salary $record): ?string => $record->creator?->getFilamentName()),
                TextColumn::make('created_at')->dateTime()->label('Recorded at'),
            ])
            ->defaultSort('effective_date', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => (bool) auth()->user()?->can('manageSalaries', $this->getOwnerRecord()))
                    ->using(function (array $data): Salary {
                        /** @var User $employee */
                        $employee = $this->getOwnerRecord();

                        try {
                            $salary = FormMapper::salary($data);

                            return app(RecordSalary::class)->handle(auth()->user(), $employee, $salary);
                        } catch (ValidationException $e) {
                            FormMapper::report($this, $e, prefix: 'mountedActions.0.data.');
                            throw new Halt;
                        }
                    }),
            ]);
    }
}
