<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Domain\Work\Actions\UpdateTask;
use App\Domain\Work\Models\Task;
use App\Filament\Resources\Tasks\Support\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    public function form(Schema $schema): Schema
    {
        /** @var Task $record */
        $record = $this->getRecord();

        return TaskForm::configure($schema, $record)->statePath('data');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Task $record */
        $record = $this->getRecord();

        return TaskForm::fill($record);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Task $record */
        try {
            return app(UpdateTask::class)->handle(auth()->user(), $record, TaskForm::data($data));
        } catch (ValidationException $e) {
            ActionErrors::report($this, $e);
            throw new Halt;
        }
    }
}
