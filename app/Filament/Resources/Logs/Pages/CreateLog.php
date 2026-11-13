<?php

declare(strict_types=1);

namespace App\Filament\Resources\Logs\Pages;

use App\Domain\Work\Actions\CreateLog as CreateLogAction;
use App\Domain\Work\Support\LogChoices;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Logs\Support\LogForm;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateLog extends CreateRecord
{
    protected static string $resource = LogResource::class;

    public function form(Schema $schema): Schema
    {
        return LogForm::configure($schema, auth()->user())->statePath('data');
    }

    /** D-55: ?task={id} (the "Log hours" link on a task and in the assignment email) preselects one of the person's own open tasks. */
    protected function afterFill(): void
    {
        $taskId = request()->integer('task');

        if ($taskId > 0 && array_key_exists($taskId, LogChoices::taskOptions(auth()->user()))) {
            $this->data['direct'] = false;
            $this->data['task_id'] = $taskId;
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreateLogAction::class)->handle(auth()->user(), LogForm::data($data));
        } catch (ValidationException $e) {
            ActionErrors::report($this, $e, aliases: ['duration' => 'minutes']);
            throw new Halt;
        }
    }
}
