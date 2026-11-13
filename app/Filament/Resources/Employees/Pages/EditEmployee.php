<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\UpdateEmployee;
use App\Domain\People\Actions\UpdateHrRecord;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Support\FormMapper;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    public function form(Schema $schema): Schema
    {
        /** @var User $record */
        $record = $this->getRecord();

        return EmployeeResource::configureForm($schema, $record, creating: false)->statePath('data');
    }

    /**
     * Private values are loaded only for viewers who may see the HR record (D-14).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();

        return FormMapper::fill($record, (bool) auth()->user()?->can('viewHrRecord', $record));
    }

    /**
     * Each part is saved through its own action, which re-checks its own permission: an admin or HR
     * user can change the HR record but not the account (employees.update is superadmin only).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $actor = auth()->user();

        try {
            if ($actor?->can('updateAccount', $record)) {
                resolve(UpdateEmployee::class)->handle($actor, $record, FormMapper::employee($data));
            }

            if ($actor?->can('updateHrRecord', $record) && isset($data['profile'])) {
                resolve(UpdateHrRecord::class)->handle($actor, $record, FormMapper::hrRecord($data));
            }
        } catch (ValidationException $validationException) {
            FormMapper::report($this, $validationException);
            throw new Halt;
        }

        return $record->refresh();
    }
}
