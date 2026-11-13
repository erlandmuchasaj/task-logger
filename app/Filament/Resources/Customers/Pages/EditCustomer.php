<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Domain\Clients\Actions\UpdateCustomer;
use App\Domain\Clients\Models\Customer;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Support\CustomerForm;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    public function form(Schema $schema): Schema
    {
        /** @var Customer $record */
        $record = $this->getRecord();

        return CustomerForm::configure($schema, $record)->statePath('data');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Customer $record */
        $record = $this->getRecord();

        return CustomerForm::fill($record);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Customer $record */
        try {
            return resolve(UpdateCustomer::class)->handle(auth()->user(), $record, CustomerForm::data($data, $record));
        } catch (ValidationException $validationException) {
            ActionErrors::report($this, $validationException);
            throw new Halt;
        }
    }
}
