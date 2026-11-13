<?php

declare(strict_types=1);

namespace App\Filament\Resources\Logs\Pages;

use App\Domain\Work\Actions\UpdateLog;
use App\Domain\Work\Models\Log;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Logs\Support\LogForm;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditLog extends EditRecord
{
    protected static string $resource = LogResource::class;

    public function form(Schema $schema): Schema
    {
        /** @var Log $record */
        $record = $this->getRecord();

        return LogForm::configure($schema, $record->user, $record)->statePath('data');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Log $record */
        $record = $this->getRecord();

        return LogForm::fill($record);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Log $record */
        try {
            return app(UpdateLog::class)->handle(auth()->user(), $record, LogForm::data($data));
        } catch (ValidationException $e) {
            ActionErrors::report($this, $e, aliases: ['duration' => 'minutes']);
            throw new Halt;
        }
    }
}
