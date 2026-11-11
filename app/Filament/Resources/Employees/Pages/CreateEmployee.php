<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\CreateEmployee as CreateEmployeeAction;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Support\FormMapper;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    public function form(Schema $schema): Schema
    {
        return EmployeeResource::configureForm($schema, null, creating: true)->statePath('data');
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        $withHr = $actor?->can('updateHrRecord', new User) && isset($data['profile']);

        try {
            return app(CreateEmployeeAction::class)->handle(
                $actor,
                FormMapper::employee($data),
                $withHr ? FormMapper::hrRecord($data) : null,
                FormMapper::salary((array) ($data['salary'] ?? [])),
            );
        } catch (ValidationException $e) {
            FormMapper::report($this, $e);
            throw new Halt;
        }
    }
}
