<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Domain\Expenses\Actions\UpdateExpense;
use App\Domain\Expenses\Models\Expense;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Support\ExpenseForm;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    public function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema, creating: false)->statePath('data');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Expense $record */
        $record = $this->getRecord();

        return ExpenseForm::fill($record->load('lines'));
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Expense $record */
        try {
            return app(UpdateExpense::class)->handle(auth()->user(), $record, ExpenseForm::data($data));
        } catch (ValidationException $e) {
            ActionErrors::report($this, $e);
            throw new Halt;
        }
    }
}
