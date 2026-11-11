<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentPlans;

use App\Domain\MasterData\Enums\PaymentPlanType;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Filament\Resources\PaymentPlans\Pages\ManagePaymentPlans;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Client metadata only; no invoice is generated (BR-15). */
class PaymentPlanResource extends Resource
{
    protected static ?string $model = PaymentPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Master data';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('type')->options(PaymentPlanType::class)->required()->live(),
            TextInput::make('days')->numeric()->minValue(0)->maxValue(365)
                ->visible(fn ($get) => $get('type') === PaymentPlanType::DaysAfterInvoice->value)
                ->required(fn ($get) => $get('type') === PaymentPlanType::DaysAfterInvoice->value),
            Textarea::make('description')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('days'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentPlans::route('/')];
    }
}
