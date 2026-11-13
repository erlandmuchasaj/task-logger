<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Support;

use App\Domain\Clients\Models\Project;
use App\Domain\Expenses\Data\ExpenseData;
use App\Domain\Expenses\Data\ExpenseLineData;
use App\Domain\Expenses\Enums\ExpenseCategory;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\IsoCurrency;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** The expense form shared by the Create and Edit pages (D-52). Prices are typed as decimals in the expense's currency. */
final class ExpenseForm
{
    public static function configure(Schema $schema, bool $creating): Schema
    {
        return $schema->components([
            Select::make('user_id')->label('Employee')->required()->searchable()->visible($creating)
                ->options(fn (): array => User::query()->whereNull('anonymized_at')->orderBy('name')->get()
                    ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->getFilamentName()])->all()),
            Select::make('category')->options(ExpenseCategory::class)->required()->native(false),
            TextInput::make('description')->required()->maxLength(255),
            Select::make('project_id')->label('Project (optional)')->searchable()->native(false)
                ->options(fn (): array => Project::query()->orderBy('name')->limit(300)->pluck('name', 'id')->all()),
            TextInput::make('currency')->label('Currency (ISO code)')->required()->length(3)->alpha()->rules([new IsoCurrency])->default('EUR')->extraInputAttributes(['style' => 'text-transform: uppercase']),
            Section::make('Lines')->schema([
                Repeater::make('lines')->required()->minItems(1)->addActionLabel('Add line')->columns(4)->schema([
                    TextInput::make('description')->required()->maxLength(255)->columnSpan(2),
                    DatePicker::make('date')->required()->default(now()),
                    TextInput::make('quantity')->numeric()->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('unit_price')->label('Unit price')->required()->placeholder('12.50')->columnSpan(2),
                ])->defaultItems(1),
            ])->columnSpanFull(),
            FileUpload::make('receipt_path')->label('Receipt (image or PDF)')
                ->disk((string) config('task-logger.expenses.disk'))->directory('receipts')->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('task-logger.expenses.receipt_max_kb'))
                ->columnSpanFull(),
            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ])->columns(2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function data(array $data): ExpenseData
    {
        $category = $data['category'] instanceof ExpenseCategory ? $data['category'] : ExpenseCategory::from((string) $data['category']);

        return new ExpenseData(
            category: $category,
            description: (string) $data['description'],
            currency: (string) $data['currency'],
            lines: array_values(array_map(fn (array $line): ExpenseLineData => new ExpenseLineData(
                (string) ($line['description'] ?? ''),
                (int) ($line['quantity'] ?? 0),
                mb_trim((string) ($line['unit_price'] ?? '')),
                CarbonImmutable::parse((string) ($line['date'] ?? 'now')),
            ), (array) ($data['lines'] ?? []))),
            projectId: filled($data['project_id'] ?? null) ? (int) $data['project_id'] : null,
            notes: $data['notes'] ?? null,
            receiptPath: is_array($data['receipt_path'] ?? null) ? (array_values($data['receipt_path'])[0] ?? null) : ($data['receipt_path'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public static function fill(Expense $expense): array
    {
        $lines = $expense->lines;

        return [
            ...$expense->only(['category', 'description', 'project_id', 'notes', 'receipt_path']),
            'category' => $expense->category->value,
            'currency' => $lines->first()->currency_code ?? 'EUR',
            'lines' => $lines->map(fn ($line): array => [
                'description' => $line->description, 'date' => $line->date->toDateString(), 'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice()->toMajorString(),
            ])->values()->all(),
        ];
    }
}
