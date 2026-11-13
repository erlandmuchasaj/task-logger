<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Domain\Expenses\Actions\CreateExpense as CreateExpenseAction;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Support\ExpenseForm;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    public function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema, creating: true)->statePath('data');
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return resolve(CreateExpenseAction::class)->handle(
                auth()->user(),
                User::query()->whereKey($data['user_id'])->firstOrFail(),
                ExpenseForm::data($data),
            );
        } catch (ValidationException $validationException) {
            ActionErrors::report($this, $validationException);
            throw new Halt;
        }
    }
}
