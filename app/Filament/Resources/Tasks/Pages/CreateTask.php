<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Domain\Work\Actions\CreateTask as CreateTaskAction;
use App\Filament\Resources\Tasks\Support\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    public function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema, null)->statePath('data');
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return resolve(CreateTaskAction::class)->handle(auth()->user(), TaskForm::data($data));
        } catch (ValidationException $validationException) {
            ActionErrors::report($this, $validationException);
            throw new Halt;
        }
    }
}
