<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Clients\Actions\CreateProject as CreateProjectAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Support\ProjectForm;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    public function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema, null)->statePath('data');
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreateProjectAction::class)->handle(auth()->user(), ProjectForm::data($data));
        } catch (ValidationException $e) {
            ActionErrors::report($this, $e);
            throw new Halt;
        }
    }
}
