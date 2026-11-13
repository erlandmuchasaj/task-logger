<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Clients\Actions\UpdateProject;
use App\Domain\Clients\Models\Project;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Support\ProjectForm;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    public function form(Schema $schema): Schema
    {
        /** @var Project $record */
        $record = $this->getRecord();

        return ProjectForm::configure($schema, $record)->statePath('data');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Project $record */
        $record = $this->getRecord();

        return ProjectForm::fill($record);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Project $record */
        try {
            return resolve(UpdateProject::class)->handle(auth()->user(), $record, ProjectForm::data($data));
        } catch (ValidationException $validationException) {
            ActionErrors::report($this, $validationException);
            throw new Halt;
        }
    }
}
